<?php
// ============================================================
//  LGU ENRILE HRMS — PDS & SALN Viewer (Admin)
//  File: Admin/pds_saln.php
// ============================================================
session_start();
// ── Session timeout — 8 hours ─────────────────────────────────
$_sessionLifetime = 8 * 60 * 60;
if (isset($_SESSION['login_time']) && (time() - strtotime($_SESSION['login_time']) > $_sessionLifetime)) {
    $_isAjax = !empty($_GET['action']) || !empty($_POST['action']);
    session_unset(); session_destroy();
    if ($_isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'message'=>'Your session has expired. Please log in again.']);
        exit();
    }
    header('Location: ../Login/login.php?error=' . urlencode('Your session has expired. Please log in again.'));
    exit();
}
if (empty($_SESSION['user_id']) || empty($_SESSION['user_email'])) {
    header('Location: ../Login/login.php'); exit();
}
$allowedRoles = ['admin','hr_staff','dept_head'];
if (!in_array($_SESSION['user_role'] ?? '', $allowedRoles)) {
    header('Location: ../Employee/Employee_portal.php'); exit();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';

$action = $_GET['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    try {
        switch ($action) {

            case 'list_employees':
                $search      = trim($_GET['search'] ?? '');
                $dept        = (int)($_GET['dept'] ?? 0);
                // P3-10: include archived employees so their PDS/SALN records remain accessible
                $inclArchived = !empty($_GET['include_archived']);
                $where  = $inclArchived ? [] : ["e.employment_status = 'Active'"];
                $params = [];
                if ($search) {
                    $s = '%'.$search.'%';
                    $where[] = "(CONCAT(e.first_name,' ',e.last_name) LIKE ? OR e.employee_code LIKE ?)";
                    $params  = [$s,$s];
                }
                if ($dept) { $where[] = 'e.department_id = ?'; $params[] = $dept; }
                $wSql = count($where) ? 'WHERE '.implode(' AND ',$where) : '';
                $page = max(1,(int)($_GET['page']??1)); $ps = 15; $offset = ($page-1)*$ps;
                $cnt  = $pdo->prepare("SELECT COUNT(*) FROM employees e $wSql");
                $cnt->execute($params); $total = (int)$cnt->fetchColumn();
                $stmt = $pdo->prepare("
                    SELECT e.employee_id, e.employee_code, e.first_name, e.last_name,
                           e.position_title, e.employment_type, e.employment_status,
                           d.name AS department,
                           (SELECT COUNT(*) FROM pds_records p WHERE p.employee_id=e.employee_id) AS has_pds,
                           (SELECT COUNT(*) FROM saln_records s WHERE s.employee_id=e.employee_id) AS saln_count
                    FROM employees e
                    LEFT JOIN departments d ON e.department_id=d.department_id
                    $wSql ORDER BY e.last_name,e.first_name LIMIT $ps OFFSET $offset");
                $stmt->execute($params);
                echo json_encode(['success'=>true,'data'=>$stmt->fetchAll(),'total'=>$total,'pages'=>(int)ceil($total/$ps),'page'=>$page]);
                break;

            case 'get_pds':
                $empId = (int)($_GET['employee_id']??0);
                $stmt  = $pdo->prepare("SELECT * FROM pds_records WHERE employee_id=? ORDER BY updated_at DESC LIMIT 1");
                $stmt->execute([$empId]); $row = $stmt->fetch();
                // also get employee basic info
                $emp = $pdo->prepare("SELECT e.*,d.name AS department FROM employees e LEFT JOIN departments d ON e.department_id=d.department_id WHERE e.employee_id=?");
                $emp->execute([$empId]); $empRow = $emp->fetch();
                echo json_encode(['success'=>true,'data'=>$row?:null,'employee'=>$empRow?:null]);
                break;

            case 'get_saln':
                $empId = (int)($_GET['employee_id']??0);
                $year  = (int)($_GET['year']??0);
                $years = $pdo->prepare("SELECT saln_year FROM saln_records WHERE employee_id=? ORDER BY saln_year DESC");
                $years->execute([$empId]); $yearsList = $years->fetchAll(PDO::FETCH_COLUMN);
                if (!$year && $yearsList) $year = $yearsList[0];
                $stmt  = $pdo->prepare("SELECT * FROM saln_records WHERE employee_id=? AND saln_year=? LIMIT 1");
                $stmt->execute([$empId,$year]); $row = $stmt->fetch();
                $emp   = $pdo->prepare("SELECT e.*,d.name AS department FROM employees e LEFT JOIN departments d ON e.department_id=d.department_id WHERE e.employee_id=?");
                $emp->execute([$empId]); $empRow = $emp->fetch();
                echo json_encode(['success'=>true,'data'=>$row?:null,'employee'=>$empRow?:null,'years'=>$yearsList]);
                break;

            case 'departments':
                $rows = $pdo->query("SELECT department_id,name FROM departments ORDER BY name")->fetchAll();
                echo json_encode(['success'=>true,'data'=>$rows]);
                break;

            // P3-3 — Admin-side PDS update
            case 'update_pds':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'POST required.']); break; }
                csrf_verify(true);
                $empId = (int)($_GET['employee_id'] ?? 0);
                if (!$empId) { echo json_encode(['success'=>false,'message'=>'Invalid employee.']); break; }
                $data = json_decode(file_get_contents('php://input'), true) ?? [];
                $allowed = ['personal_info','family_background','education','civil_service',
                            'work_experience','voluntary_work','trainings','other_info',
                            'q4_questions','pds_references'];
                $sets = []; $vals = [];
                foreach ($allowed as $col) {
                    if (array_key_exists($col, $data)) {
                        $sets[] = "$col = ?";
                        $vals[] = is_array($data[$col]) ? json_encode($data[$col]) : $data[$col];
                    }
                }
                if (empty($sets)) { echo json_encode(['success'=>false,'message'=>'No fields provided.']); break; }

                $exists = $pdo->prepare("SELECT pds_id FROM pds_records WHERE employee_id=?");
                $exists->execute([$empId]);
                if ($exists->fetchColumn()) {
                    $vals[] = $empId;
                    $pdo->prepare("UPDATE pds_records SET ".implode(', ',$sets)." WHERE employee_id=?")->execute($vals);
                } else {
                    $vals[] = $empId;
                    $pdo->prepare("INSERT INTO pds_records (".implode(',',$allowed).",employee_id) VALUES (".rtrim(str_repeat('?,',count($allowed)),',').",?)")->execute($vals);
                }
                $pdo->prepare("INSERT INTO activity_logs (user_id,action,module,details,ip_address,created_at)
                    VALUES (?,?,?,?,?,NOW())")->execute([
                    $_SESSION['user_id'], 'update_pds', 'PDS/SALN',
                    "Admin updated PDS for employee_id=$empId",
                    $_SERVER['REMOTE_ADDR'] ?? null,
                ]);
                echo json_encode(['success'=>true,'message'=>'PDS updated.']);
                break;

            // P3-3 — Admin-side SALN update
            case 'update_saln':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'message'=>'POST required.']); break; }
                csrf_verify(true);
                $empId = (int)($_GET['employee_id'] ?? 0);
                $saln_year = (int)($_GET['saln_year'] ?? date('Y'));
                if (!$empId) { echo json_encode(['success'=>false,'message'=>'Invalid employee.']); break; }
                $data = json_decode(file_get_contents('php://input'), true) ?? [];
                $allowed = ['as_of_date','filing_type','filing_mode','declarant_info','spouse_info',
                            'children_info','real_properties','personal_properties','liabilities',
                            'biz_interests','relatives_govt','no_biz','no_rel',
                            'real_subtotal','personal_subtotal','total_assets','total_liabilities','net_worth'];
                $sets = []; $vals = [];
                foreach ($allowed as $col) {
                    if (array_key_exists($col, $data)) {
                        $sets[] = "$col = ?";
                        $vals[] = is_array($data[$col]) ? json_encode($data[$col]) : $data[$col];
                    }
                }
                if (empty($sets)) { echo json_encode(['success'=>false,'message'=>'No fields provided.']); break; }

                $exists = $pdo->prepare("SELECT saln_id FROM saln_records WHERE employee_id=? AND saln_year=?");
                $exists->execute([$empId, $saln_year]);
                if ($exists->fetchColumn()) {
                    $vals[] = $empId; $vals[] = $saln_year;
                    $pdo->prepare("UPDATE saln_records SET ".implode(', ',$sets)." WHERE employee_id=? AND saln_year=?")->execute($vals);
                } else {
                    $vals[] = $empId; $vals[] = $saln_year;
                    $colList = implode(',', $allowed) . ',employee_id,saln_year';
                    $placeholders = rtrim(str_repeat('?,', count($allowed)), ',') . ',?,?';
                    $pdo->prepare("INSERT INTO saln_records ($colList) VALUES ($placeholders)")->execute($vals);
                }
                $pdo->prepare("INSERT INTO activity_logs (user_id,action,module,details,ip_address,created_at)
                    VALUES (?,?,?,?,?,NOW())")->execute([
                    $_SESSION['user_id'], 'update_saln', 'PDS/SALN',
                    "Admin updated SALN for employee_id=$empId year=$saln_year",
                    $_SERVER['REMOTE_ADDR'] ?? null,
                ]);
                echo json_encode(['success'=>true,'message'=>'SALN updated.']);
                break;
        }
    } catch (PDOException $e) {
        error_log('[HRMS] pds_saln.php: '.$e->getMessage());
        echo json_encode(['success'=>false,'message'=>'A database error occurred.']);
    }
    exit();
}
?>

<?php
// ── Admin name/role for topbar ───────────────────────────────────────────
$adminName  = ucwords($_SESSION['user_name'] ?? ($_SESSION['user_email'] ?? 'Admin'));
$roleLabels = ['admin'=>'Super Admin','hr_staff'=>'HR Staff','dept_head'=>'Department Head'];
$roleLabel  = $roleLabels[$_SESSION['user_role'] ?? ''] ?? ucfirst($_SESSION['user_role'] ?? 'Admin');
// ── Notifications (topbar bell) ──────────────────────────────────────────
$notifCount = 0; $notifications = [];
if (isset($pdo)) { try {
    $pendingReqs = $pdo->query("SELECT lr.request_id, CONCAT(e.first_name,' ',e.last_name) AS emp_name,
        lt.name AS leave_type, lr.days_requested, lr.filed_at
        FROM leave_requests lr JOIN employees e ON e.employee_id=lr.employee_id
        JOIN leave_types lt ON lt.leave_type_id=lr.leave_type_id
        WHERE lr.status='Pending' ORDER BY lr.filed_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pendingReqs as $r) { $notifications[]=['icon'=>'📋','color'=>'#f59e0b','title'=>'Leave Request',
        'body'=>htmlspecialchars($r['emp_name']).' filed '.(int)$r['days_requested'].' day(s) of '.htmlspecialchars($r['leave_type']),
        'time'=>$r['filed_at'],'link'=>'leave_requests.php']; }
    try { $expC=$pdo->query("SELECT CONCAT(e.first_name,' ',e.last_name) AS emp_name,
        DATEDIFF(c.end_date,CURDATE()) AS dl FROM contracts c JOIN employees e ON e.employee_id=c.employee_id
        WHERE c.status='Active' AND c.end_date IS NOT NULL AND DATEDIFF(c.end_date,CURDATE()) BETWEEN 0 AND 30
        ORDER BY c.end_date ASC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        foreach($expC as $c){$dl=(int)$c['dl'];$notifications[]=['icon'=>'📝','color'=>'#ef4444','title'=>'Contract Expiring',
        'body'=>htmlspecialchars($c['emp_name'])."'s contract expires in $dl day".($dl===1?'':'s'),'time'=>null,'link'=>'contracts.php'];}
    } catch(Exception $ex){}
    $notifCount=count($notifications);
} catch(Exception $ex){ $notifCount=0; } }
if (!function_exists('timeAgo')){function timeAgo($dt){if(!$dt)return '';$d=time()-strtotime($dt);if($d<60)return 'Just now';if($d<3600)return (int)($d/60).'m ago';if($d<86400)return (int)($d/3600).'h ago';if($d<172800)return 'Yesterday';return date('M j',strtotime($dt));}}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <?= csrf_meta() ?>
  <title>PDS & SALN Viewer — LGU Enrile HRMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet"/>
  <style>
    :root{--primary:#2563eb;--primary-dark:#1e40af;--accent:#22c55e;--secondary:#0f172a;--sidebar-w:265px;--topbar-h:68px;--gray-50:#f8fafc;--gray-100:#f1f5f9;--gray-200:#e2e8f0;--gray-400:#94a3b8;--gray-600:#475569;--gray-800:#1e293b;--white:#ffffff;--shadow-sm:0 1px 4px rgba(0,0,0,0.07);--shadow-md:0 4px 18px rgba(0,0,0,0.11);--shadow-lg:0 8px 32px rgba(0,0,0,0.16);--radius:14px;--radius-sm:9px;}
    *{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'Nunito',sans-serif;background:var(--gray-100);color:var(--secondary);display:flex;min-height:100vh;}
    .sidebar{width:var(--sidebar-w);background:linear-gradient(180deg,#2563eb 0%,#1e40af 55%,#22c55e 100%);display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:200;transition:transform .3s;box-shadow:4px 0 28px rgba(0,0,0,.20);}
    .sidebar-brand{padding:22px 20px 18px;display:flex;align-items:center;gap:12px;border-bottom:1px solid rgba(255,255,255,.15);}
    .brand-logo{width:44px;height:44px;border-radius:50%;object-fit:cover;border:2.5px solid rgba(255,255,255,.70);}
    .brand-text h2{font-family:'Playfair Display',serif;font-size:1rem;color:#fff;line-height:1.2;}
    .brand-text p{font-size:.65rem;color:rgba(255,255,255,.60);font-weight:600;letter-spacing:.06em;}
    .sidebar-nav{padding:12px 10px;flex:1;overflow-y:auto;}
    .nav-label{font-size:.63rem;font-weight:800;color:rgba(255,255,255,.38);text-transform:uppercase;letter-spacing:.16em;padding:10px 10px 5px;margin-top:6px;}
    .nav-item{display:flex;align-items:center;gap:11px;padding:9px 12px;border-radius:var(--radius-sm);color:rgba(255,255,255,.75);text-decoration:none;font-size:.875rem;font-weight:600;transition:all .2s;margin-bottom:2px;position:relative;}
    .nav-item:hover{background:rgba(255,255,255,.13);color:#fff;}
    .nav-item.active{background:rgba(255,255,255,.22);color:#fff;}
    .nav-item.active::before{content:'';position:absolute;left:0;top:20%;bottom:20%;width:3px;border-radius:2px;background:#fff;}
    .nav-item.active .nav-icon{background:rgba(255,255,255,.25);}
    .nav-icon{width:30px;height:30px;border-radius:8px;background:rgba(255,255,255,.10);display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0;}
    .sidebar-footer{padding:14px 16px;border-top:1px solid rgba(255,255,255,.12);}
    .user-pill{display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.10);border:1.5px solid rgba(255,255,255,.18);border-radius:14px;padding:8px 10px;box-shadow:inset 0 1px 0 rgba(255,255,255,.10);}
    .user-avatar{width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.18);border:2px solid rgba(255,255,255,.40);display:flex;align-items:center;justify-content:center;font-weight:800;color:#fff;font-size:.95rem;flex-shrink:0;}
    .user-name{font-size:.90rem;font-weight:800;color:#fff;line-height:1.1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .user-role{font-size:.72rem;color:rgba(255,255,255,.72);font-weight:700;text-transform:none;line-height:1.15;margin-top:2px;}
    .logout-btn{width:100%;padding:8px 12px;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.25);border-radius:var(--radius-sm);color:#fca5a5;font-family:'Nunito',sans-serif;font-size:.82rem;font-weight:700;cursor:pointer;transition:all .2s;}
    .logout-btn:hover{background:rgba(239,68,68,.22);}
    .main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-height:100vh;}
    .topbar{height:var(--topbar-h);background:linear-gradient(135deg,#2563eb 0%,#1e40af 50%,#22c55e 100%);display:flex;align-items:center;padding:0 28px;gap:16px;position:sticky;top:0;z-index:100;box-shadow:0 2px 12px rgba(0,0,0,.18);}
    .menu-toggle{display:none;background:none;border:none;color:#fff;font-size:1.5rem;cursor:pointer;}
    .topbar-title{flex:1;}
    .topbar-title h1{font-family:'Playfair Display',serif;font-size:1.22rem;color:#fff;}
    .topbar-title p{font-size:.72rem;color:rgba(255,255,255,.70);}
    .tb-chip{background:rgba(255,255,255,.15);color:#fff;padding:5px 14px;border-radius:20px;font-size:.72rem;font-weight:700;}
    .topbar-btn{position:relative;width:38px;height:38px;border-radius:50%;border:1px solid rgba(255,255,255,0.25);background:rgba(255,255,255,0.15);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:1rem;color:#fff;transition:all 0.2s;}
    .topbar-btn:hover{background:rgba(255,255,255,0.28);}
    .notif-badge{position:absolute;top:-2px;right:-2px;width:16px;height:16px;background:#ef4444;border-radius:50%;font-size:0.58rem;color:white;font-weight:800;display:none;align-items:center;justify-content:center;border:2px solid rgba(255,255,255,0.3);}
    .menu-toggle{display:none;background:none;border:none;font-size:1.4rem;cursor:pointer;color:rgba(255,255,255,0.85);padding:4px;}
    .content{padding:26px 28px;flex:1;display:grid;grid-template-columns:320px 1fr;gap:20px;align-items:start;}
    /* Employee list panel */
    .emp-panel{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow-sm);overflow:hidden;position:sticky;top:calc(var(--topbar-h) + 16px);}
    .emp-panel-head{padding:14px 16px;background:var(--gray-50);border-bottom:1.5px solid var(--gray-200);}
    .emp-panel-title{font-size:.92rem;font-weight:800;margin-bottom:10px;}
    .search-box{display:flex;align-items:center;gap:8px;background:var(--white);border:1.5px solid var(--gray-200);border-radius:var(--radius-sm);padding:8px 11px;margin-bottom:8px;}
    .search-box:focus-within{border-color:var(--primary);}
    .search-box input{border:none;outline:none;font-family:'Nunito',sans-serif;font-size:.83rem;width:100%;color:var(--gray-800);}
    .filter-select{width:100%;padding:8px 11px;border:1.5px solid var(--gray-200);border-radius:var(--radius-sm);font-family:'Nunito',sans-serif;font-size:.82rem;color:var(--gray-600);background:var(--white);outline:none;}
    .filter-select:focus{border-color:var(--primary);}
    .emp-list{max-height:calc(100vh - 260px);overflow-y:auto;}
    .emp-row{display:flex;align-items:center;gap:10px;padding:11px 14px;border-bottom:1px solid var(--gray-100);cursor:pointer;transition:background .15s;}
    .emp-row:hover{background:var(--gray-50);}
    .emp-row.active{background:#eff6ff;border-left:3px solid var(--primary);}
    .emp-avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:#fff;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:800;flex-shrink:0;}
    .emp-name{font-size:.85rem;font-weight:700;color:var(--gray-800);}
    .emp-sub{font-size:.71rem;color:var(--gray-400);}
    .emp-badges{display:flex;gap:4px;margin-left:auto;flex-shrink:0;}
    .doc-badge{padding:2px 7px;border-radius:10px;font-size:.62rem;font-weight:800;}
    .db-pds{background:#f3e8ff;color:#6b21a8;}
    .db-saln{background:#fef3c7;color:#92400e;}
    .db-none{background:var(--gray-100);color:var(--gray-400);}
    /* Viewer panel */
    .view-panel{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow-sm);overflow:visible;}
    .view-panel-head{padding:16px 20px;background:var(--gray-50);border-bottom:1.5px solid var(--gray-200);display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
    .view-panel-title{font-size:.95rem;font-weight:800;flex:1;}
    .tab-bar{display:flex;gap:4px;background:var(--white);border-radius:var(--radius);padding:5px;box-shadow:var(--shadow-sm);margin-bottom:20px;}
    .tab-btn{flex:1;padding:9px 14px;border:none;border-radius:10px;background:transparent;font-family:'Nunito',sans-serif;font-size:.83rem;font-weight:700;color:var(--gray-600);cursor:pointer;transition:all .2s;}
    .tab-btn:hover{background:var(--gray-100);}
    .tab-btn.active{background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:#fff;box-shadow:0 3px 10px rgba(37,99,235,.28);}
    .view-body{padding:22px;}
    /* Read-only field display */
    .ro-section-hd{background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:#fff;padding:9px 16px;border-radius:var(--radius-sm) var(--radius-sm) 0 0;font-size:.78rem;font-weight:800;letter-spacing:.05em;text-transform:uppercase;margin-top:16px;}
    .ro-section-body{border:1.5px solid var(--gray-200);border-top:none;border-radius:0 0 var(--radius-sm) var(--radius-sm);padding:14px 16px;margin-bottom:2px;}
    .ro-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;}
    .ro-field{margin-bottom:4px;}
    .ro-label{font-size:.65rem;font-weight:800;color:var(--gray-400);text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px;}
    .ro-val{font-size:.85rem;font-weight:600;color:var(--gray-800);padding:6px 10px;background:var(--gray-50);border-radius:6px;min-height:30px;word-break:break-word;}
    .ro-val.empty{color:var(--gray-400);font-style:italic;}
    .ro-full{grid-column:1/-1;}
    /* Repeater table */
    .ro-table{width:100%;border-collapse:collapse;font-size:.80rem;margin-top:8px;}
    .ro-table th{background:var(--gray-100);padding:7px 10px;text-align:left;font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:var(--gray-600);border-bottom:2px solid var(--gray-200);}
    .ro-table td{padding:8px 10px;border-bottom:1px solid var(--gray-100);vertical-align:top;}
    .ro-table tr:last-child td{border-bottom:none;}
    .ro-table tr:hover td{background:var(--gray-50);}
    /* Empty state */
    .empty-state{text-align:center;padding:60px 20px;color:var(--gray-400);}
    .empty-state .e-icon{font-size:3rem;margin-bottom:12px;}
    .empty-state p{font-size:.90rem;font-weight:600;}
    .empty-state small{font-size:.78rem;}
    /* Badges */
    .badge{padding:3px 10px;border-radius:20px;font-size:.70rem;font-weight:800;display:inline-block;}
    .badge-purple{background:#f3e8ff;color:#6b21a8;}
    .badge-amber{background:#fffbeb;color:#d97706;}
    .badge-green{background:#f0fdf4;color:#16a34a;}
    .badge-gray{background:var(--gray-100);color:var(--gray-600);}
    /* Print btn */
    .print-btn{padding:9px 20px;background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:#fff;border:none;border-radius:var(--radius-sm);font-family:'Nunito',sans-serif;font-size:.85rem;font-weight:700;cursor:pointer;transition:all .2s;box-shadow:0 3px 10px rgba(37,99,235,.25);}
    .print-btn:hover{transform:translateY(-1px);box-shadow:0 5px 16px rgba(37,99,235,.35);}
    .view-btn{padding:9px 20px;background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border:none;border-radius:var(--radius-sm);font-family:'Nunito',sans-serif;font-size:.85rem;font-weight:700;cursor:pointer;transition:all .2s;box-shadow:0 3px 10px rgba(22,163,74,.25);}
    .view-btn:hover{transform:translateY(-1px);box-shadow:0 5px 16px rgba(22,163,74,.35);}
    .year-tabs{display:flex;gap:5px;flex-wrap:wrap;}
    .year-tab{padding:5px 14px;border-radius:20px;border:1.5px solid var(--gray-200);background:var(--white);font-family:'Nunito',sans-serif;font-size:.76rem;font-weight:700;cursor:pointer;transition:all .2s;}
    .year-tab.active{background:var(--primary);color:#fff;border-color:var(--primary);}
    .year-tab:hover:not(.active){border-color:var(--primary);color:var(--primary);}
    .saln-annex-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 12px;position:sticky;top:calc(var(--topbar-h) + 10px);z-index:25;background:rgba(255,255,255,.97);backdrop-filter:blur(2px);padding:8px 0;border-bottom:1px solid var(--gray-100);}
    .saln-annex-tab{padding:7px 14px;border-radius:20px;border:1.5px solid var(--gray-200);background:#fff;font-family:'Nunito',sans-serif;font-size:.84rem;font-weight:800;color:var(--gray-800);cursor:pointer;transition:all .2s;}
    .saln-annex-tab.active{background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:#fff;border-color:var(--primary);box-shadow:0 3px 10px rgba(37,99,235,.25);}
    .saln-annex-tab:hover:not(.active){border-color:var(--primary);color:var(--primary);}
    .saln-annex-pane{display:none;}
    .saln-annex-pane.active{display:block;}
    /* Toast */
    .toast-wrap{position:fixed;bottom:24px;right:24px;z-index:2000;display:flex;flex-direction:column;gap:8px;}
    .toast{padding:12px 20px;border-radius:10px;color:#fff;font-size:.85rem;font-weight:700;box-shadow:var(--shadow-lg);animation:toastIn .3s ease both;min-width:200px;}
    @keyframes toastIn{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:translateX(0)}}
    .toast-info{background:linear-gradient(135deg,#2563eb,#1e40af);}
    .toast-error{background:linear-gradient(135deg,#dc2626,#b91c1c);}
    /* Pagination */
    .pg-wrap{padding:10px 14px;border-top:1px solid var(--gray-100);display:flex;gap:4px;justify-content:center;}
    .pg-btn{padding:5px 11px;border:1.5px solid var(--gray-200);background:#fff;border-radius:7px;font-size:.76rem;font-weight:700;cursor:pointer;font-family:'Nunito',sans-serif;}
    .pg-btn:hover:not(:disabled){border-color:var(--primary);color:var(--primary);}
    .pg-btn.active{background:var(--primary);color:#fff;border-color:var(--primary);}
    .pg-btn:disabled{opacity:.4;cursor:not-allowed;}
    @media(max-width:900px){.content{grid-template-columns:1fr;}.emp-panel{position:static;}.emp-list{max-height:260px;}.menu-toggle{display:block;}.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.main{margin-left:0;}.topbar{padding:0 16px;}.content{padding:16px;}.saln-annex-tabs{top:calc(var(--topbar-h) + 6px);}}

    .tb-profile-wrap{position:relative;display:inline-block;}
    .tb-profile-btn{width:38px;height:38px;border-radius:50%;background:rgba(255,255,255,.20);border:2px solid rgba(255,255,255,.55);display:flex;align-items:center;justify-content:center;cursor:pointer;overflow:hidden;flex-shrink:0;transition:all .2s;font-size:.80rem;font-weight:800;color:#fff;}
    .tb-profile-btn:hover{background:rgba(255,255,255,.35);border-color:#fff;}
    .tb-profile-btn img{width:100%;height:100%;object-fit:cover;border-radius:50%;}
    .tb-dropdown{display:none;position:absolute;top:calc(100% + 10px);right:0;background:#fff;border-radius:12px;box-shadow:0 8px 28px rgba(0,0,0,.18);min-width:200px;z-index:9999;overflow:hidden;animation:fadeIn .2s ease;}
    .tb-dropdown.open{display:block;}
    .tb-dd-header{padding:12px 16px 8px;border-bottom:1px solid #f1f5f9;background:#f8fafc;}
    .tb-dd-name{font-size:.86rem;font-weight:800;color:#1e293b;}
    .tb-dd-role{font-size:.70rem;color:#94a3b8;margin-top:1px;}
    .tb-dd-item{display:flex;align-items:center;gap:10px;padding:10px 16px;font-size:.83rem;font-weight:600;color:#475569;cursor:pointer;transition:background .15s;text-decoration:none;border:none;background:none;width:100%;font-family:'Nunito',sans-serif;}
    .tb-dd-item:hover{background:#f1f5f9;color:#1e293b;}
    .tb-dd-item.danger{color:#dc2626;}
    .tb-dd-item.danger:hover{background:#fef2f2;}
    .tb-dd-divider{height:1px;background:#f1f5f9;margin:3px 0;}
  </style>
</head>
<body>

<!-- ══ SIDEBAR ══ -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <img src="../images/Enrile.png" class="brand-logo" alt="LGU"/>
    <div class="brand-text"><h2>LGU Enrile</h2><p>HR Management System</p></div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-label">Main</div>
    <a class="nav-item" href="dashboard.php"><div class="nav-icon">🏠</div>Dashboard</a>
    <div class="nav-label">Employee</div>
    <a class="nav-item" href="employees.php"><div class="nav-icon">👥</div>Employees</a>
    <a class="nav-item" href="departments.php"><div class="nav-icon">🏢</div>Departments</a>
    <div class="nav-label">Leave</div>
    <a class="nav-item" href="leave_requests.php"><div class="nav-icon">📋</div>Leave Requests</a>
    <a class="nav-item" href="leave_types.php"><div class="nav-icon">📁</div>Leave Types</a>
    <a class="nav-item" href="leave_balances.php"><div class="nav-icon">⚖️</div>Leave Balances</a>
    <div class="nav-label">Documents</div>
    <a class="nav-item" href="documents.php"><div class="nav-icon">📄</div>HR Documents</a>
    <a class="nav-item active" href="pds_saln.php"><div class="nav-icon">🪪</div>PDS & SALN</a>
    <a class="nav-item" href="service_records.php"><div class="nav-icon">📜</div>Service Records</a>    <a class="nav-item" href="coe_requests.php"><div class="nav-icon">📄</div>COE Requests</a>

    <a class="nav-item" href="contracts.php"><div class="nav-icon">📝</div>Contracts</a>
    <div class="nav-label">System</div>
    <a class="nav-item" href="reports.php"><div class="nav-icon">📊</div>Reports</a>
    <a class="nav-item" href="announcements.php"><div class="nav-icon">📢</div>Announcements</a>
    <a class="nav-item" href="archives.php"><div class="nav-icon">🗄️</div>Archives</a>
    <a class="nav-item" href="user_accounts.php"><div class="nav-icon">🔐</div>User Accounts</a>
    <a class="nav-item" href="settings.php"><div class="nav-icon">⚙️</div>Settings</a>
  </nav>
  <div class="sidebar-footer">
    <div class="user-pill" style="margin-bottom:0;">
      <div class="user-avatar"><?= strtoupper(substr($_SESSION['user_name']??'A',0,2)) ?></div>
      <div>
        <div class="user-name"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?></div>
        <div class="user-role"><?= htmlspecialchars($roleLabel) ?></div>
      </div>
    </div>
  </div>
</aside>

<!-- ══ MAIN ══ -->
<div class="main">
  <div class="topbar">
    <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">☰</button>
    <div class="topbar-title"><h1>PDS & SALN Viewer</h1><p>Read-only access to employee submitted forms</p></div>
    <span class="tb-chip" id="tbDate"></span>
    <div style="display:flex;align-items:center;gap:8px;margin-left:auto;">
              <div class="notif-wrap" id="adminNotifWrap">
          <button class="notif-btn" onclick="toggleNotifPanel()" title="Notifications">
            🔔<span class="notif-count <?= $notifCount > 0 ? 'show' : '' ?>" id="adminNotifCount"><?= $notifCount ?: '' ?></span>
          </button>
          <div class="notif-panel" id="adminNotifPanel">
            <div class="notif-hd">
              <div><div class="notif-hd-title">🔔 Notifications</div>
              <div class="notif-hd-count"><?= $notifCount ?> item<?= $notifCount!==1?'s':'' ?></div></div>
              <button class="notif-mark-all" onclick="clearNotifications()">Mark all read</button>
            </div>
            <div class="notif-list" id="adminNotifList">
              <?php if(empty($notifications)):?>
              <div class="notif-empty"><div class="ne-icon">🔔</div><p>All caught up!</p></div>
              <?php else: foreach($notifications as $n):?>
              <a class="notif-item unread" href="<?=htmlspecialchars($n['link'])?>" data-nid="<?=md5(($n['title']??'').($n['body']??'').($n['time']??''))?>" onclick="markNotifRead(this.getAttribute('data-nid'));applyReadState();">
                <div class="notif-ico" style="background:<?=htmlspecialchars($n['color'])?>22;"><?=$n['icon']?></div>
                <div class="notif-body">
                  <div class="notif-title"><?=htmlspecialchars($n['title'])?></div>
                  <div class="notif-text"><?=htmlspecialchars($n['body'])?></div>
                  <?php if($n['time']):?><div class="notif-time"><?=timeAgo($n['time'])?></div><?php endif;?>
                </div>
              </a>
              <?php endforeach; endif;?>
            </div>
            <div class="notif-footer"><a href="leave_requests.php">View all leave requests →</a></div>
          </div>
        </div>
      <button class="topbar-btn" onclick="cwToggle()" style="position:relative;">💬<span id="cwBadge" style="position:absolute;top:-2px;right:-2px;width:16px;height:16px;background:#ef4444;border-radius:50%;font-size:.55rem;color:#fff;font-weight:800;display:none;align-items:center;justify-content:center;border:2px solid rgba(255,255,255,.3);"></span></button>
        <div class="tb-profile-wrap" id="tbProfileWrap">
          <div class="tb-profile-btn" onclick="toggleProfileDropdown()" title="Account" id="tbProfileBtn"><?= strtoupper(substr($adminName,0,2)) ?></div>
          <div class="tb-dropdown" id="tbDropdown">
            <div class="tb-dd-header">
              <div class="tb-dd-name"><?= htmlspecialchars($adminName) ?></div>
              <div class="tb-dd-role"><?= htmlspecialchars($roleLabel) ?></div>
            </div>
            <a href="switch_to_employee.php" class="tb-dd-item" style="color:#d97706;">🔄 Switch to Employee Account</a>
            <div class="tb-dd-divider"></div>
            <a href="../Landingpage/logout.php" class="tb-dd-item danger" onclick="return confirm('Log out?')">🚪 Logout</a>
          </div>
        </div>

    </div>
  </div>

  <div class="content">

    <!-- ── Employee List ── -->
    <div class="emp-panel">
      <div class="emp-panel-head">
        <div class="emp-panel-title">👥 Select Employee</div>
        <div class="search-box">
          <span style="color:var(--gray-400);">🔍</span>
          <input type="text" id="empSearch" placeholder="Search name or code…" oninput="debounceEmp()"/>
        </div>
        <select class="filter-select" id="deptFilter" onchange="loadEmployees(1)">
          <option value="">All Departments</option>
        </select>
      </div>
      <div class="emp-list" id="empList">
        <div class="empty-state"><div class="e-icon">⏳</div><p>Loading…</p></div>
      </div>
      <div class="pg-wrap" id="empPag"></div>
    </div>

    <!-- ── Viewer ── -->
    <div class="view-panel" id="viewPanel">
      <div class="empty-state" style="padding:80px 20px;" id="viewEmpty">
        <div class="e-icon">👈</div>
        <p>Select an employee to view their PDS or SALN</p>
        <small>Forms are read-only. Use the Print button to generate a printable copy.</small>
      </div>
      <div id="viewContent" style="display:none;">
        <div class="view-panel-head">
          <div>
            <div class="view-panel-title" id="viewEmpName">—</div>
            <div style="font-size:.75rem;color:var(--gray-400);margin-top:2px;" id="viewEmpSub">—</div>
          </div>
          <button class="print-btn" id="printBtn" onclick="printForm()">🖨️ Print</button>
         <!-- <button class="view-btn" id="viewBtn" onclick="viewForm()">👁️ View</button>-->
        </div>
        <div class="view-body">
          <div class="tab-bar">
            <button class="tab-btn active" id="tabPds" onclick="switchTab('pds')">📋 Personal Data Sheet</button>
            <button class="tab-btn" id="tabSaln" onclick="switchTab('saln')">📊 SALN</button>
          </div>

          <!-- PDS View -->
          <div id="panePds"></div>

          <!-- SALN View -->
          <div id="paneSaln" style="display:none;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap;">
              <span style="font-size:.80rem;font-weight:700;color:var(--gray-600);">Filing Year:</span>
              <div class="year-tabs" id="salnYearTabs"></div>
            </div>
            <div id="salnContent"></div>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>

<div class="toast-wrap" id="toastWrap"></div>

<script>
const CSRF_TOKEN = document.querySelector('meta[name=csrf-token]').content;
// ── Helpers ────────────────────────────────────────────────
function fmtDate(d){ if(!d||d==='0000-00-00') return '—'; try{ return new Date(d).toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}); }catch(e){return d;} }
function fmt(v){ return (v===null||v===undefined||v===''||v==='null') ? '—' : v; }
function fmtMoney(v){ if(!v&&v!==0) return '—'; return '₱'+parseFloat(v).toLocaleString('en-PH',{minimumFractionDigits:2}); }
function safeJson(s){ try{ return s?JSON.parse(s):null; }catch(e){ return null; } }
function initials(f,l){ return ((f||'?')[0]+(l||'?')[0]).toUpperCase(); }

function showToast(msg,type='info'){ const w=document.getElementById('toastWrap'); const t=document.createElement('div'); t.className='toast toast-'+type; t.textContent=msg; w.appendChild(t); setTimeout(()=>t.remove(),3500); }
function debounce(fn,ms){ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a),ms); }; }
function ro(label,val,full){ return `<div class="ro-field${full?' ro-full':''}"><div class="ro-label">${label}</div><div class="ro-val${!val||val==='—'?' empty':''}">${val||'—'}</div></div>`; }
function roYN(val){ return val==='Yes'||val===true||val===1||val==='1'?'<span class="badge badge-purple">YES</span>':'<span class="badge badge-gray">NO</span>'; }

// ── State ──────────────────────────────────────────────────
let currentEmpId = null;
let currentTab   = 'pds';
let currentSalnYear = null;
let empPage = 1;

// ── Load departments ───────────────────────────────────────
async function loadDepts(){
  const r = await fetch('pds_saln.php?action=departments');
  const j = await r.json();
  if(!j.success) return;
  const sel = document.getElementById('deptFilter');
  j.data.forEach(d=>{ const o=document.createElement('option'); o.value=d.department_id; o.textContent=d.name; sel.appendChild(o); });
}

// ── Load employee list ─────────────────────────────────────
async function loadEmployees(page){
  if(page) empPage=page;
  const search = document.getElementById('empSearch').value;
  const dept   = document.getElementById('deptFilter').value;
  const r = await fetch(`pds_saln.php?action=list_employees&page=${empPage}&search=${encodeURIComponent(search)}&dept=${dept}`);
  const j = await r.json();
  const list = document.getElementById('empList');
  if(!j.success||!j.data.length){
    list.innerHTML='<div class="empty-state" style="padding:30px;"><div class="e-icon">👥</div><p>No employees found</p></div>';
    document.getElementById('empPag').innerHTML=''; return;
  }
  list.innerHTML = j.data.map(e=>`
    <div class="emp-row${e.employee_id==currentEmpId?' active':''}" onclick="selectEmployee(${e.employee_id},'${e.last_name}, ${e.first_name}','${e.position_title||''}','${e.department||''}')">
      <div class="emp-avatar">${initials(e.first_name,e.last_name)}</div>
      <div style="flex:1;min-width:0;">
        <div class="emp-name">${e.last_name}, ${e.first_name}</div>
        <div class="emp-sub">${e.position_title||'—'} · ${e.department||'—'}</div>
      </div>
      <div class="emp-badges">
        ${e.has_pds>0?'<span class="doc-badge db-pds">PDS</span>':'<span class="doc-badge db-none">No PDS</span>'}
        ${e.saln_count>0?`<span class="doc-badge db-saln">SALN×${e.saln_count}</span>`:'<span class="doc-badge db-none">No SALN</span>'}
      </div>
    </div>`).join('');
  // Pagination
  const pg = document.getElementById('empPag');
  if(j.pages<=1){ pg.innerHTML=''; return; }
  let html='';
  html+=`<button class="pg-btn" onclick="loadEmployees(${empPage-1})" ${empPage<=1?'disabled':''}>‹</button>`;
  for(let i=Math.max(1,empPage-1);i<=Math.min(j.pages,empPage+1);i++)
    html+=`<button class="pg-btn${i===empPage?' active':''}" onclick="loadEmployees(${i})">${i}</button>`;
  html+=`<button class="pg-btn" onclick="loadEmployees(${empPage+1})" ${empPage>=j.pages?'disabled':''}>›</button>`;
  pg.innerHTML=html;
}
const debounceEmp = debounce(()=>loadEmployees(1),300);

// ── Select employee ────────────────────────────────────────
function selectEmployee(id, name, position, dept){
  currentEmpId = id;
  document.getElementById('viewEmpty').style.display='none';
  document.getElementById('viewContent').style.display='block';
  document.getElementById('viewEmpName').textContent = name;
  document.getElementById('viewEmpSub').textContent  = `${position} · ${dept}`;
  document.querySelectorAll('.emp-row').forEach(r=>r.classList.remove('active'));
  event.currentTarget.classList.add('active');
  if(currentTab==='pds') loadPDSView();
  else loadSALNView();
}

function switchTab(tab){
  currentTab = tab;
  document.getElementById('tabPds').classList.toggle('active', tab==='pds');
  document.getElementById('tabSaln').classList.toggle('active', tab==='saln');
  document.getElementById('panePds').style.display  = tab==='pds'  ? 'block':'none';
  document.getElementById('paneSaln').style.display = tab==='saln' ? 'block':'none';
  if(!currentEmpId) return;
  if(tab==='pds') loadPDSView();
  else loadSALNView();
}

function adminSalnShowAnnex(which){
  const paneIds = { a: 'admin_saln_annex_a', as1: 'admin_saln_annex_as1', as2: 'admin_saln_annex_as2' };
  Object.keys(paneIds).forEach((k)=>{
    const pane = document.getElementById(paneIds[k]);
    if (pane) pane.classList.toggle('active', k === which);
  });
  document.querySelectorAll('#salnContent .saln-annex-tab').forEach((btn)=>{
    btn.classList.toggle('active', btn.getAttribute('data-annex') === which);
  });
}

// ── PDS View ───────────────────────────────────────────────
async function loadPDSView(){
  const pane = document.getElementById('panePds');
  pane.innerHTML = '<div class="empty-state"><div class="e-icon">⏳</div><p>Loading PDS…</p></div>';
  const r = await fetch(`pds_saln.php?action=get_pds&employee_id=${currentEmpId}`);
  const j = await r.json();
  if(!j.success || !j.data){
    pane.innerHTML='<div class="empty-state"><div class="e-icon">📋</div><p>No PDS on file</p><small>Employee has not submitted their PDS yet.</small></div>';
    return;
  }
  const d  = j.data;
  const pi = safeJson(d.personal_info)     || {};
  const fb = safeJson(d.family_background) || {};
  const ed = safeJson(d.education)         || [];
  const cs = safeJson(d.civil_service)     || [];
  const we = safeJson(d.work_experience)   || [];
  const vw = safeJson(d.voluntary_work)    || [];
  const tr = safeJson(d.trainings)         || [];
  const oi = safeJson(d.other_info)        || {};
  const q4 = safeJson(d.q4_questions)      || {};
  const refs= safeJson(d.pds_references)   || [];

  const v  = x => (x===null||x===undefined||x===''||x==='null') ? '—' : String(x);
  const fD = dt => { if(!dt||dt==='0000-00-00') return '—'; try{ const x=new Date(dt); return isNaN(x)?dt:x.toLocaleDateString('en-PH',{month:'2-digit',day:'2-digit',year:'numeric'}); }catch(e){return dt;} };
  const cb = val => (val===true||val==='1'||val===1||val==='Yes') ? '☑' : '☐';
  const sec = t => `<div style="background:linear-gradient(135deg,#2563eb,#1e40af);color:#fff;padding:6px 14px;font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;margin-top:12px;">${t}</div>`;
  const fld = (l,val,full) => `<div class="ro-field${full?' ro-full':''}"><div class="ro-label">${l}</div><div class="ro-val${!val||val==='—'?' empty':''}">${val||'—'}</div></div>`;
  const tRow = (cells) => `<tr>${cells.map(([t,v,a])=>`<td style="border:1px solid var(--gray-200);padding:5px 8px;font-size:.78rem;vertical-align:top;${a?'text-align:'+a+';':''}">${v||'—'}</td>`).join('')}</tr>`;
  const thd = cols => `<thead><tr>${cols.map(([t,w])=>`<th style="border:1px solid var(--gray-200);padding:5px 8px;font-size:.68rem;font-weight:800;text-transform:uppercase;background:#f1f5f9;${w?'width:'+w+';':''}">${t}</th>`).join('')}</tr></thead>`;

  const resAddr = [pi.res_housenum,pi.res_street,pi.res_subdivision,pi.res_barangay,pi.res_city,pi.res_province].filter(Boolean).join(', ')||'—';
  const permAddr = pi.perm_same ? resAddr : [pi.perm_housenum,pi.perm_street,pi.perm_subdivision,pi.perm_barangay,pi.perm_city,pi.perm_province].filter(Boolean).join(', ')||'—';

  let html = `<div style="background:#fff;border:1px solid var(--gray-200);border-radius:var(--radius-sm);overflow:hidden;">`;

  // ── I. Personal Information ──
  html += sec('I. Personal Information');
  html += `<div class="ro-section-body"><div class="ro-grid">
    ${fld('1. Surname', v(pi.last_name))}
    ${fld('2. First Name', v(pi.first_name))}
    ${fld('Name Extension', v(pi.suffix))}
    ${fld('Middle Name', v(pi.middle_name))}
    ${fld('3. Date of Birth', fD(pi.dob))}
    ${fld('4. Place of Birth', v(pi.pob))}
    ${fld('5. Sex at Birth', v(pi.sex))}
    ${fld('6. Civil Status', v(pi.civil_status))}
    ${fld('7. Height (m)', v(pi.height))}
    ${fld('8. Weight (kg)', v(pi.weight))}
    ${fld('9. Blood Type', v(pi.blood_type))}
    ${fld('10. UMID ID No.', v(pi.umid))}
    ${fld('11. PAG-IBIG ID No.', v(pi.pagibig))}
    ${fld('12. PhilHealth No.', v(pi.philhealth))}
    ${fld('13. PhilSys No. (PSN)', v(pi.psn))}
    ${fld('14. TIN No.', v(pi.tin))}
    ${fld('15. Agency Employee No.', v(pi.agency_no))}
    ${fld('16. Citizenship', v(pi.citizenship)+(pi.dual_by?` — By: ${pi.dual_by}, Country: ${v(pi.dual_country)}` : ''))}
    ${fld('17. Residential Address', resAddr, true)}
    ${fld('18. Permanent Address', permAddr, true)}
    ${fld('19. Telephone No.', v(pi.tel))}
    ${fld('20. Mobile No.', v(pi.mobile))}
    ${fld('21. E-Mail Address', v(pi.email))}
  </div></div>`;

  // ── II. Family Background ──
  html += sec('II. Family Background');
  html += `<div class="ro-section-body">
    <div style="font-size:.76rem;font-weight:800;color:var(--primary);margin-bottom:8px;">22. Spouse</div>
    <div class="ro-grid">
      ${fld('Surname', v(fb.sp_surname))} ${fld('First Name', v(fb.sp_firstname))}
      ${fld('Name Extension', v(fb.sp_ext))} ${fld('Middle Name', v(fb.sp_middle))}
      ${fld('Occupation', v(fb.sp_occupation))} ${fld('Employer/Business', v(fb.sp_employer))}
      ${fld('Business Address', v(fb.sp_biz_addr), true)} ${fld('Telephone No.', v(fb.sp_tel))}
    </div>
    <div style="font-size:.76rem;font-weight:800;color:var(--primary);margin:12px 0 6px;">23. Children</div>
    ${(fb.children||[]).length ? `<table style="width:100%;border-collapse:collapse;">
      ${thd([['Name of Child','70%'],['Date of Birth','30%']])}
      <tbody>${(fb.children||[]).map(c=>tRow([['',v(c.name)],['',fD(c.dob),'center']])).join('')}</tbody>
    </table>` : '<div style="font-size:.80rem;color:var(--gray-400);">No children listed.</div>'}
    <div style="font-size:.76rem;font-weight:800;color:var(--primary);margin:12px 0 6px;">24. Father</div>
    <div class="ro-grid">
      ${fld('Surname', v(fb.fa_surname))} ${fld('First Name', v(fb.fa_firstname))}
      ${fld('Name Extension', v(fb.fa_ext))} ${fld('Middle Name', v(fb.fa_middle))}
    </div>
    <div style="font-size:.76rem;font-weight:800;color:var(--primary);margin:12px 0 6px;">25. Mother's Maiden Name</div>
    <div class="ro-grid">
      ${fld('Surname', v(fb.mo_surname))} ${fld('First Name', v(fb.mo_firstname))}
      ${fld('Middle Name', v(fb.mo_middle))}
    </div>
  </div>`;

  // ── III. Educational Background ──
  html += sec('III. Educational Background (26)');
  html += `<div class="ro-section-body" style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;min-width:650px;">
    ${thd([['Level','14%'],['School','22%'],['Degree/Course','18%'],['From','8%'],['To','8%'],['Highest Level/Units','10%'],['Year Grad','8%'],['Honors','12%']])}
    <tbody>${(ed||[]).map(e=>tRow([
      ['',v(e.level)],['',v(e.school)],['',v(e.degree)],['',v(e.from),'center'],
      ['',v(e.to),'center'],['',v(e.units),'center'],['',v(e.year_grad),'center'],['',v(e.honors)]
    ])).join('')}</tbody>
  </table></div>`;

  // ── IV. Civil Service Eligibility ──
  html += sec('IV. Civil Service Eligibility (27)');
  html += `<div class="ro-section-body" style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;min-width:600px;">
    ${thd([['Eligibility','35%'],['Rating','10%'],['Exam Date','12%'],['Place','18%'],['License No.','12%'],['Valid Until','13%']])}
    <tbody>${cs.length ? cs.map(e=>tRow([
      ['',v(e.eligibility)],['',v(e.rating),'center'],['',fD(e.exam_date),'center'],
      ['',v(e.exam_place)],['',v(e.license_no),'center'],['',fD(e.license_validity),'center']
    ])).join('') : `<tr><td colspan="6" style="padding:10px;text-align:center;color:var(--gray-400);font-size:.80rem;">No eligibility on file</td></tr>`}</tbody>
  </table></div>`;

  // ── V. Work Experience ──
  html += sec('V. Work Experience (28)');
  html += `<div class="ro-section-body" style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;min-width:700px;">
    ${thd([['From','9%'],['To','9%'],['Position','20%'],['Department/Agency','22%'],['Monthly Salary','10%'],['SG/Step','8%'],['Status','10%'],['Govt?','6%']])}
    <tbody>${we.length ? we.map(e=>tRow([
      ['',fD(e.date_from),'center'],['',fD(e.date_to),'center'],['',v(e.position)],
      ['',v(e.dept_agency)],['',e.monthly_salary?'₱'+parseFloat(e.monthly_salary).toLocaleString():'—','right'],
      ['',v(e.salary_grade),'center'],['',v(e.status)],['',v(e.govt_service),'center']
    ])).join('') : `<tr><td colspan="8" style="padding:10px;text-align:center;color:var(--gray-400);font-size:.80rem;">No work experience on file</td></tr>`}</tbody>
  </table></div>`;

  // ── VI. Voluntary Work ──
  html += sec('VI. Voluntary Work (29)');
  html += `<div class="ro-section-body" style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;">
    ${thd([['Organization Name & Address','35%'],['From','12%'],['To','12%'],['No. of Hours','10%'],['Position/Nature','31%']])}
    <tbody>${vw.length ? vw.map(e=>tRow([
      ['',v(e.org_name)],['',fD(e.date_from),'center'],['',fD(e.date_to),'center'],
      ['',v(e.no_hours),'center'],['',v(e.position)]
    ])).join('') : `<tr><td colspan="5" style="padding:10px;text-align:center;color:var(--gray-400);font-size:.80rem;">None on file</td></tr>`}</tbody>
  </table></div>`;

  // ── VII. Trainings ──
  html += sec('VII. L&D / Training Programs (30)');
  html += `<div class="ro-section-body" style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;">
    ${thd([['Title','32%'],['From','10%'],['To','10%'],['Hours','7%'],['Type','13%'],['Conducted By','28%']])}
    <tbody>${tr.length ? tr.map(e=>tRow([
      ['',v(e.title)],['',fD(e.date_from),'center'],['',fD(e.date_to),'center'],
      ['',v(e.no_hours),'center'],['',v(e.ld_type)],['',v(e.conducted_by)]
    ])).join('') : `<tr><td colspan="6" style="padding:10px;text-align:center;color:var(--gray-400);font-size:.80rem;">None on file</td></tr>`}</tbody>
  </table></div>`;

  // ── VIII. Other Information ──
  html += sec('VIII. Other Information');
  html += `<div class="ro-section-body"><div class="ro-grid">
    ${fld('31. Special Skills & Hobbies', v(oi.skills), true)}
    ${fld('32. Non-Academic Distinctions/Recognition', v(oi.distinctions), true)}
    ${fld('33. Membership in Associations/Organizations', v(oi.memberships), true)}
  </div></div>`;

  // ── Q4 Questions ──
  html += sec('Questions (34–40)');
  const qrow = (num, label, key, detKey) => `
    <div style="display:flex;gap:10px;align-items:flex-start;padding:6px 0;border-bottom:1px solid var(--gray-100);">
      <div style="font-size:.72rem;font-weight:800;color:var(--gray-600);width:28px;flex-shrink:0;">${num}</div>
      <div style="flex:1;font-size:.78rem;color:var(--gray-700);">${label}</div>
      <div style="font-size:.80rem;font-weight:800;width:48px;text-align:center;flex-shrink:0;">${cb(q4[key])}&nbsp;${q4[key]==='Yes'||q4[key]===true?'YES':'NO'}</div>
    </div>
    ${detKey&&q4[detKey]?`<div style="font-size:.74rem;color:var(--gray-600);padding:2px 38px 6px;"><em>Details:</em> ${v(q4[detKey])}</div>`:''}`;

  html += `<div class="ro-section-body">
    ${qrow('34a','Related by consanguinity/affinity to appointing authority (3rd degree)?','q34a','q34_details')}
    ${qrow('34b','Related within 4th degree (LGU-Career Employees)?','q34b','')}
    ${qrow('35a','Ever found guilty of any administrative offense?','q35a','q35a_details')}
    ${qrow('35b','Ever been criminally charged before any court?','q35b','q35b_details')}
    ${qrow('36','Ever convicted of any crime or violation?','q36','q36_details')}
    ${qrow('37','Ever separated from service (resignation, retirement, dropped from rolls, etc.)?','q37','q37_details')}
    ${qrow('38a','Candidate in a national/local election in the last year?','q38a','q38a_details')}
    ${qrow('38b','Resigned from govt to actively campaign for a candidate?','q38b','q38b_details')}
    ${qrow('39','Acquired immigrant/permanent resident status of another country?','q39','q39_details')}
  </div>`;

  // ── References ──
  html += sec('References (41)');
  html += `<div class="ro-section-body" style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;">
    ${thd([['Name','30%'],['Office/Residential Address','45%'],['Contact No./Email','25%']])}
    <tbody>${refs.length ? refs.map(r=>tRow([['',v(r.name)],['',v(r.address)],['',v(r.tel)]])).join('')
      : `<tr><td colspan="3" style="padding:10px;text-align:center;color:var(--gray-400);font-size:.80rem;">No references listed</td></tr>`}</tbody>
  </table></div>`;

  html += `<div style="padding:10px 14px;font-size:.70rem;color:var(--gray-400);text-align:right;border-top:1px solid var(--gray-100);">
    Last saved: ${fmt(d.updated_at)} &nbsp;·&nbsp; Read-only — employee submitted
  </div></div>`;

  pane.innerHTML = html;
}

// ── SALN View ──────────────────────────────────────────────
async function loadSALNView(year){
  const sc = document.getElementById('salnContent');
  const yt = document.getElementById('salnYearTabs');
  sc.innerHTML = '<div class="empty-state"><div class="e-icon">⏳</div><p>Loading SALN…</p></div>';
  const r = await fetch(`pds_saln.php?action=get_saln&employee_id=${currentEmpId}&year=${year||''}`);
  const j = await r.json();
  // Build year tabs
  if(j.years && j.years.length){
    yt.innerHTML = j.years.map(y=>`<button class="year-tab${y==(year||j.years[0])?' active':''}" onclick="loadSALNView(${y})">${y}</button>`).join('');
    currentSalnYear = year || j.years[0];
  } else {
    yt.innerHTML = '';
    currentSalnYear = null;
  }
  if(!j.success || !j.data){
    sc.innerHTML='<div class="empty-state"><div class="e-icon">📊</div><p>No SALN on file</p><small>Employee has not submitted a SALN yet.</small></div>';
    return;
  }
  const d   = j.data;
  const sj  = s => { try{ return s?JSON.parse(s):null; }catch(e){return null;} };
  const dec = sj(d.declarant_info)      || {};
  const sp  = sj(d.spouse_info)         || {};
  const ch  = sj(d.children_info)       || [];
  const rp  = sj(d.real_properties)     || [];
  const pp  = sj(d.personal_properties) || [];
  const lb  = sj(d.liabilities)         || [];
  const biz = sj(d.biz_interests)       || [];
  const rel = sj(d.relatives_govt)      || [];
  const as1Rp = sj(d.as1_real_properties)     || [];
  const as1Pp = sj(d.as1_personal_properties) || [];
  const as1Lb = sj(d.as1_liabilities)         || [];
  const as1Biz= sj(d.as1_biz_interests)       || [];
  const as2Nm = sj(d.as2_name_info)           || {};
  const as2Rp = sj(d.as2_real_properties)     || [];
  const as2Pp = sj(d.as2_personal_properties) || [];
  const as2Lb = sj(d.as2_liabilities)         || [];
  const as2Biz= sj(d.as2_biz_interests)       || [];

  const v    = x => (x===null||x===undefined||x===''||x==='null') ? '—' : String(x);
  const fmtM = x => { if(!x&&x!==0) return '—'; const n=parseFloat(x); return isNaN(n)?'—':'₱'+n.toLocaleString('en-PH',{minimumFractionDigits:2}); };
  const fD   = dt => { if(!dt||dt==='0000-00-00') return '—'; try{ const x=new Date(dt); return isNaN(x)?dt:(x.getMonth()+1).toString().padStart(2,'0')+'/'+x.getDate().toString().padStart(2,'0')+'/'+x.getFullYear(); }catch(e){return dt;} };
  const cb   = val => (val===true||val==='1'||val===1||val==='Yes') ? '☑' : '☐';
  const sec  = t => `<div style="background:linear-gradient(135deg,#1e3a5f,#2563eb);color:#fff;padding:6px 14px;font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;margin-top:12px;">${t}</div>`;
  const lrow = (l,val) => `<tr><td style="width:38%;padding:4px 8px;font-size:.72rem;font-weight:700;color:var(--gray-600);border-bottom:1px solid var(--gray-100);">${l}</td><td style="padding:4px 8px;font-size:.78rem;border-bottom:1px solid var(--gray-100);">${v(val)}</td></tr>`;
  const thd  = cols => `<thead><tr>${cols.map(([t,w])=>`<th style="border:1px solid var(--gray-200);padding:5px 8px;font-size:.66rem;font-weight:800;text-transform:uppercase;background:#f1f5f9;${w?'width:'+w+';':''}">${t}</th>`).join('')}</tr></thead>`;
  const tRow = cells => `<tr>${cells.map(([,val,a])=>`<td style="border:1px solid var(--gray-200);padding:5px 8px;font-size:.78rem;vertical-align:top;${a?'text-align:'+a+';':''}">${val||'—'}</td>`).join('')}</tr>`;

  const isAssumption = d.filing_type==='Assumption';
  const isAnnual     = d.filing_type==='Annual';
  const isExit       = d.filing_type==='Exit';
  const isJoint      = d.filing_mode==='Joint';
  const isSeparate   = d.filing_mode==='Separate';

  let html = `<div style="background:#fff;border:1px solid var(--gray-200);border-radius:var(--radius-sm);overflow:hidden;">`;
  html += `<div style="background:linear-gradient(135deg,#1e3a5f,#2563eb);color:#fff;padding:12px 16px;text-align:center;">
    <div style="font-size:.95rem;font-weight:800;text-transform:uppercase;">Sworn Statement of Assets, Liabilities, and Net Worth</div>
    <div style="font-size:.70rem;opacity:.80;margin-top:2px;">As required by R.A. No. 6713 · 2025 SALN Form · Per CSC Resolution No. 2500632</div>
    <div style="font-size:.82rem;font-weight:700;margin-top:4px;">Filing Year: ${v(d.saln_year)}</div>
  </div>`;

  // Removed SALN annex tab bar for admin side

  html += `<div class="saln-annex-pane active" id="admin_saln_annex_a" style="padding:0 12px 10px;">`;

  // Compliance
  html += sec('Compliance');
  html += `<div style="border:1px solid var(--gray-200);padding:8px 14px;font-size:.78rem;line-height:2;">
    ${cb(isAssumption)} Assumption of office as of <strong>${isAssumption?fD(d.as_of_date):'—'}</strong> &nbsp;&nbsp;
    ${cb(isAnnual)} Annual filing as of December 31, <strong>${isAnnual?v(d.saln_year):'—'}</strong> &nbsp;&nbsp;
    ${cb(isExit)} Exit as of <strong>${isExit?fD(d.as_of_date):'—'}</strong>
    <br>
    <strong>Filing mode:</strong> &nbsp;
    ${cb(isJoint)} Joint Filing &nbsp;&nbsp;
    ${cb(isSeparate)} Separate Filing &nbsp;&nbsp;
    ${cb(!isJoint&&!isSeparate)} Not Applicable
    ${d.multi_spouse?`<br><strong>Multiple marriages — Spouse(s):</strong> ${v(d.multi_spouse)}`:''}
  </div>`;

  // Declarant & Spouse
  html += sec('Declarant & Spouse');
  html += `<div style="display:grid;grid-template-columns:1fr 1fr;gap:0;border:1px solid var(--gray-200);">
    <div style="padding:8px 12px;border-right:1px solid var(--gray-200);">
      <div style="font-size:.72rem;font-weight:800;color:#2563eb;text-transform:uppercase;margin-bottom:6px;padding-bottom:4px;border-bottom:2px solid #2563eb;">Declarant</div>
      <table style="width:100%;border-collapse:collapse;">${lrow('Family Name',dec.lastname)}${lrow('First Name',dec.firstname)}${lrow('M.I.',dec.mi)}${lrow('Position',dec.position)}${lrow('Agency/Office',dec.agency)}${lrow('Office Address',dec.office_addr)}</table>
    </div>
    <div style="padding:8px 12px;">
      <div style="font-size:.72rem;font-weight:800;color:var(--gray-600);text-transform:uppercase;margin-bottom:6px;padding-bottom:4px;border-bottom:2px solid var(--gray-400);">Spouse</div>
      <table style="width:100%;border-collapse:collapse;">${lrow('Family Name',sp.lastname)}${lrow('First Name',sp.firstname)}${lrow('M.I.',sp.mi)}${lrow('Position',sp.position)}${lrow('Agency/Office',sp.agency)}${lrow('Office Address',sp.office_addr)}</table>
    </div>
  </div>`;

  // Children
  html += sec('Unmarried Children Below 18');
  html += `<div style="border:1px solid var(--gray-200);padding:8px 12px;">
    ${ch.length ? `<table style="width:100%;border-collapse:collapse;">${thd([['Name of Child','75%'],['Age','25%']])}<tbody>${ch.map(c=>tRow([['',c.name],['',c.age,'center']])).join('')}</tbody></table>`
    : '<div style="font-size:.80rem;color:var(--gray-400);">None listed.</div>'}</div>`;

  // Assets
  html += sec('1. Assets — a. Real Properties');
  html += `<div style="border:1px solid var(--gray-200);padding:8px 12px;overflow-x:auto;">
    ${rp.length ? `<table style="width:100%;border-collapse:collapse;min-width:600px;">
      ${thd([['Description','17%'],['Kind','12%'],['Exact Location','14%'],['Assessed Value','9%'],['Market Value','9%'],['Acq. Year','7%'],['Mode','9%'],['Acq. Cost','9%']])}
      <tbody>
        ${rp.map(p=>tRow([['',p.description],['',p.kind],['',p.location],['',fmtM(p.assessed_value),'right'],['',fmtM(p.market_value),'right'],['',p.acq_year,'center'],['',p.acq_mode],['',fmtM(p.acq_cost),'right']])).join('')}
        <tr style="background:#f0f9ff;"><td colspan="7" style="border:1px solid var(--gray-200);padding:4px 8px;font-weight:700;text-align:right;font-size:.72rem;">Subtotal:</td><td style="border:1px solid var(--gray-200);padding:4px 8px;font-weight:800;text-align:right;">${fmtM(d.real_subtotal)}</td></tr>
      </tbody></table>` : '<div style="font-size:.80rem;color:var(--gray-400);">No real properties declared.</div>'}
  </div>`;

  html += sec('b. Personal Properties');
  html += `<div style="border:1px solid var(--gray-200);border-top:none;padding:8px 12px;overflow-x:auto;">
    ${pp.length ? `<table style="width:100%;border-collapse:collapse;">
      ${thd([['Description','60%'],['Acquisition Year','20%'],['Acquisition Cost','20%']])}
      <tbody>
        ${pp.map(p=>tRow([['',p.description],['',p.acq_year,'center'],['',fmtM(p.acq_cost),'right']])).join('')}
        <tr style="background:#f0f9ff;"><td colspan="2" style="border:1px solid var(--gray-200);padding:4px 8px;font-weight:700;text-align:right;font-size:.72rem;">Subtotal:</td><td style="border:1px solid var(--gray-200);padding:4px 8px;font-weight:800;text-align:right;">${fmtM(d.personal_subtotal)}</td></tr>
      </tbody></table>` : '<div style="font-size:.80rem;color:var(--gray-400);">No personal properties declared.</div>'}
  </div>`;

  // Totals
  html += `<div style="display:grid;grid-template-columns:1fr 1fr 1fr;border:1px solid var(--gray-200);border-top:none;">
    <div style="padding:10px 14px;border-right:1px solid var(--gray-200);"><div style="font-size:.65rem;font-weight:700;color:var(--gray-500);text-transform:uppercase;">Total Assets</div><div style="font-size:.96rem;font-weight:800;color:#2563eb;margin-top:3px;">${fmtM(d.total_assets)}</div></div>
    <div style="padding:10px 14px;border-right:1px solid var(--gray-200);"><div style="font-size:.65rem;font-weight:700;color:var(--gray-500);text-transform:uppercase;">Total Liabilities</div><div style="font-size:.96rem;font-weight:800;color:#dc2626;margin-top:3px;">${fmtM(d.total_liabilities)}</div></div>
    <div style="padding:10px 14px;background:#f0fdf4;"><div style="font-size:.65rem;font-weight:700;color:var(--gray-500);text-transform:uppercase;">Net Worth</div><div style="font-size:.96rem;font-weight:800;color:#16a34a;margin-top:3px;">${fmtM(d.net_worth)}</div></div>
  </div>`;

  // Liabilities
  html += sec('2. Liabilities');
  html += `<div style="border:1px solid var(--gray-200);padding:8px 12px;">
    ${lb.length ? `<table style="width:100%;border-collapse:collapse;">${thd([['Nature','35%'],['Name of Creditors','40%'],['Outstanding Balance','25%']])}
      <tbody>${lb.map(l=>tRow([['',l.nature],['',l.creditor],['',fmtM(l.balance),'right']])).join('')}
        <tr style="background:#f0f9ff;"><td colspan="2" style="border:1px solid var(--gray-200);padding:4px 8px;font-weight:700;text-align:right;font-size:.72rem;">Total:</td><td style="border:1px solid var(--gray-200);padding:4px 8px;font-weight:800;text-align:right;">${fmtM(d.total_liabilities)}</td></tr>
      </tbody></table>` : '<div style="font-size:.80rem;color:var(--gray-400);">No liabilities declared.</div>'}
  </div>`;

  // Business Interests
  html += sec('Business Interests & Financial Connections');
  html += `<div style="border:1px solid var(--gray-200);padding:8px 12px;">
    ${d.no_biz ? '<div style="font-size:.80rem;font-weight:700;">☑ I/We do not have any business interest or financial connection.</div>'
    : biz.length ? `<table style="width:100%;border-collapse:collapse;">${thd([['Entity/Business','28%'],['Business Address','25%'],['Nature','28%'],['Date Acquired','19%']])}
      <tbody>${biz.map(b=>tRow([['',b.entity_name],['',b.biz_address],['',b.nature],['',fD(b.date_acquired),'center']])).join('')}</tbody></table>`
    : '<div style="font-size:.80rem;color:var(--gray-400);">None declared.</div>'}
  </div>`;

  // Relatives in Govt
  html += sec('Relatives in Government Service');
  html += `<div style="border:1px solid var(--gray-200);padding:8px 12px;">
    ${d.no_rel ? '<div style="font-size:.80rem;font-weight:700;">☑ I/We do not know of any relative/s in the government service.</div>'
    : rel.length ? `<table style="width:100%;border-collapse:collapse;">${thd([['Name','25%'],['Relationship','15%'],['Position','25%'],['Agency/Office & Address','35%']])}
      <tbody>${rel.map(r=>tRow([['',r.name],['',r.relationship],['',r.position],['',r.agency_address]])).join('')}</tbody></table>`
    : '<div style="font-size:.80rem;color:var(--gray-400);">None declared.</div>'}
  </div>`;

  // Gov ID
  html += sec('Government Issued ID');
  html += `<div style="border:1px solid var(--gray-200);padding:8px 14px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;">
    <div><div style="font-size:.65rem;font-weight:700;color:var(--gray-500);text-transform:uppercase;margin-bottom:2px;">Government Issued ID</div><div style="font-size:.84rem;font-weight:700;">${v(d.gov_id)}</div></div>
    <div><div style="font-size:.65rem;font-weight:700;color:var(--gray-500);text-transform:uppercase;margin-bottom:2px;">ID Number</div><div style="font-size:.84rem;font-weight:700;">${v(d.gov_id_no)}</div></div>
    <div><div style="font-size:.65rem;font-weight:700;color:var(--gray-500);text-transform:uppercase;margin-bottom:2px;">Date Issued</div><div style="font-size:.84rem;font-weight:700;">${fD(d.gov_id_issued)}</div></div>
  </div>`;

  html += `</div>`;

  // Annex B: AS-1
  html += `<div class="saln-annex-pane" id="admin_saln_annex_as1" style="padding:0 12px 10px;">`;
  html += sec('Annex B - AS-1 (Declarant Additional Sheet)');
  html += `<div style="border:1px solid var(--gray-200);padding:8px 12px;">
    <div style="font-size:.78rem;margin-bottom:8px;"><strong>Declarant:</strong> ${v([dec.lastname, dec.firstname, dec.mi].filter(Boolean).join(', '))} · <strong>Position:</strong> ${v(dec.position)} · <strong>Agency:</strong> ${v(dec.agency)}</div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;margin-bottom:10px;">
      <div><div style="font-size:.65rem;font-weight:700;color:var(--gray-500);text-transform:uppercase;">Real Subtotal</div><div style="font-size:.84rem;font-weight:700;">${fmtM(d.as1_real_subtotal)}</div></div>
      <div><div style="font-size:.65rem;font-weight:700;color:var(--gray-500);text-transform:uppercase;">Personal Subtotal</div><div style="font-size:.84rem;font-weight:700;">${fmtM(d.as1_personal_subtotal)}</div></div>
      <div><div style="font-size:.65rem;font-weight:700;color:var(--gray-500);text-transform:uppercase;">Total Assets</div><div style="font-size:.84rem;font-weight:700;">${fmtM(d.as1_total_assets)}</div></div>
      <div><div style="font-size:.65rem;font-weight:700;color:var(--gray-500);text-transform:uppercase;">Total Liabilities</div><div style="font-size:.84rem;font-weight:700;">${fmtM(d.as1_total_liabilities)}</div></div>
    </div>
    <div style="font-size:.72rem;font-weight:800;color:#2563eb;margin:8px 0 4px;">Real Properties (AS-1)</div>
    ${as1Rp.length ? `<table style="width:100%;border-collapse:collapse;">${thd([['Description','17%'],['Kind','12%'],['Exact Location','14%'],['Assessed Value','9%'],['Market Value','9%'],['Acq. Year','7%'],['Mode','9%'],['Acq. Cost','9%']])}<tbody>${as1Rp.map(p=>tRow([['',p.description],['',p.kind],['',p.location],['',fmtM(p.assessed_value),'right'],['',fmtM(p.market_value),'right'],['',p.acq_year,'center'],['',p.acq_mode],['',fmtM(p.acq_cost),'right']])).join('')}</tbody></table>` : '<div style="font-size:.80rem;color:var(--gray-400);">No AS-1 real properties declared.</div>'}
    <div style="font-size:.72rem;font-weight:800;color:#2563eb;margin:10px 0 4px;">Personal Properties (AS-1)</div>
    ${as1Pp.length ? `<table style="width:100%;border-collapse:collapse;">${thd([['Description','60%'],['Acquisition Year','20%'],['Acquisition Cost','20%']])}<tbody>${as1Pp.map(p=>tRow([['',p.description],['',p.acq_year,'center'],['',fmtM(p.acq_cost),'right']])).join('')}</tbody></table>` : '<div style="font-size:.80rem;color:var(--gray-400);">No AS-1 personal properties declared.</div>'}
    <div style="font-size:.72rem;font-weight:800;color:#2563eb;margin:10px 0 4px;">Liabilities (AS-1)</div>
    ${as1Lb.length ? `<table style="width:100%;border-collapse:collapse;">${thd([['Nature','35%'],['Name of Creditors','40%'],['Outstanding Balance','25%']])}<tbody>${as1Lb.map(l=>tRow([['',l.nature],['',l.creditor],['',fmtM(l.balance),'right']])).join('')}</tbody></table>` : '<div style="font-size:.80rem;color:var(--gray-400);">No AS-1 liabilities declared.</div>'}
    <div style="font-size:.72rem;font-weight:800;color:#2563eb;margin:10px 0 4px;">Business Interests (AS-1)</div>
    ${as1Biz.length ? `<table style="width:100%;border-collapse:collapse;">${thd([['Entity/Business','28%'],['Business Address','25%'],['Nature','28%'],['Date Acquired','19%']])}<tbody>${as1Biz.map(b=>tRow([['',b.entity_name],['',b.biz_address],['',b.nature],['',fD(b.date_acquired),'center']])).join('')}</tbody></table>` : '<div style="font-size:.80rem;color:var(--gray-400);">No AS-1 business interests declared.</div>'}
  </div>`;

  html += `</div>`;

  // Annex C: AS-2
  html += `<div class="saln-annex-pane" id="admin_saln_annex_as2" style="padding:0 12px 10px;">`;
  html += sec('Annex C - AS-2 (Spouse & Children Additional Sheet)');
  html += `<div style="border:1px solid var(--gray-200);padding:8px 12px;">
    <div style="font-size:.78rem;margin-bottom:8px;"><strong>Name:</strong> ${v(as2Nm.full_name || [sp.lastname, sp.firstname, sp.mi].filter(Boolean).join(', '))} · <strong>Position:</strong> ${v(as2Nm.position)} · <strong>Agency:</strong> ${v(as2Nm.agency)}</div>
    <div style="font-size:.72rem;font-weight:800;color:#2563eb;margin:8px 0 4px;">Real Properties (AS-2)</div>
    ${as2Rp.length ? `<table style="width:100%;border-collapse:collapse;">${thd([['Description','17%'],['Kind','12%'],['Exact Location','14%'],['Assessed Value','9%'],['Market Value','9%'],['Acq. Year','7%'],['Mode','9%'],['Acq. Cost','9%']])}<tbody>${as2Rp.map(p=>tRow([['',p.description],['',p.kind],['',p.location],['',fmtM(p.assessed_value),'right'],['',fmtM(p.market_value),'right'],['',p.acq_year,'center'],['',p.acq_mode],['',fmtM(p.acq_cost),'right']])).join('')}</tbody></table>` : '<div style="font-size:.80rem;color:var(--gray-400);">No AS-2 real properties declared.</div>'}
    <div style="font-size:.72rem;font-weight:800;color:#2563eb;margin:10px 0 4px;">Personal Properties (AS-2)</div>
    ${as2Pp.length ? `<table style="width:100%;border-collapse:collapse;">${thd([['Description','60%'],['Acquisition Year','20%'],['Acquisition Cost','20%']])}<tbody>${as2Pp.map(p=>tRow([['',p.description],['',p.acq_year,'center'],['',fmtM(p.acq_cost),'right']])).join('')}</tbody></table>` : '<div style="font-size:.80rem;color:var(--gray-400);">No AS-2 personal properties declared.</div>'}
    <div style="font-size:.72rem;font-weight:800;color:#2563eb;margin:10px 0 4px;">Liabilities (AS-2)</div>
    ${as2Lb.length ? `<table style="width:100%;border-collapse:collapse;">${thd([['Nature','35%'],['Name of Creditors','40%'],['Outstanding Balance','25%']])}<tbody>${as2Lb.map(l=>tRow([['',l.nature],['',l.creditor],['',fmtM(l.balance),'right']])).join('')}</tbody></table>` : '<div style="font-size:.80rem;color:var(--gray-400);">No AS-2 liabilities declared.</div>'}
    <div style="font-size:.72rem;font-weight:800;color:#2563eb;margin:10px 0 4px;">Business Interests (AS-2)</div>
    ${as2Biz.length ? `<table style="width:100%;border-collapse:collapse;">${thd([['Entity/Business','28%'],['Business Address','25%'],['Nature','28%'],['Date Acquired','19%']])}<tbody>${as2Biz.map(b=>tRow([['',b.entity_name],['',b.biz_address],['',b.nature],['',fD(b.date_acquired),'center']])).join('')}</tbody></table>` : '<div style="font-size:.80rem;color:var(--gray-400);">No AS-2 business interests declared.</div>'}
  </div>`;

  html += `</div>`;

  html += `<div style="padding:10px 14px;font-size:.70rem;color:var(--gray-400);text-align:right;border-top:1px solid var(--gray-100);">
    Last saved: ${fmt(d.updated_at)} · Read-only — employee submitted
  </div></div>`;

  sc.innerHTML = html;
}


// ── Print ──────────────────────────────────────────────────
function printForm(){
  if(!currentEmpId){ showToast('Select an employee first.','error'); return; }
  if(currentTab==='pds'){
    fetch('pds_saln.php?action=get_pds&employee_id='+currentEmpId)
      .then(r=>r.json()).then(j=>{
        if(!j.data){ showToast('No PDS data found.','error'); return; }
        printPDS(j.data, j.employee);
      });
  } else {
    fetch('pds_saln.php?action=get_saln&employee_id='+currentEmpId+'&year='+(currentSalnYear||''))
      .then(r=>r.json()).then(j=>{
        if(!j.success||!j.data){ showToast('No SALN data to print.','error'); return; }
        buildSALNPrint(j.data, j.employee);
      });
  }
}

function viewForm(){
  if(!currentEmpId){ showToast('Select an employee first.','error'); return; }
  const empName = document.getElementById('viewEmpName').textContent;
  const empSub  = document.getElementById('viewEmpSub').textContent;

  const isPds = currentTab === 'pds';
  const content = isPds
    ? document.getElementById('panePds')?.innerHTML
    : document.getElementById('salnContent')?.innerHTML;

  if(!content || content.includes('Loading') || content.includes('No PDS on file') || content.includes('No SALN on file')){
    showToast('No data loaded yet.','error'); return;
  }

  const title = isPds
    ? `Personal Data Sheet \u2014 ${empName}`
    : `SALN ${currentSalnYear||''} \u2014 ${empName}`;
  const headerGrad = isPds
    ? 'linear-gradient(135deg,#2563eb,#1e40af)'
    : 'linear-gradient(135deg,#1e3a5f,#2563eb)';

  // Full CSS needed to render the read-only viewer correctly
  const viewCSS = `
    *{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'Nunito',sans-serif;background:#f1f5f9;color:#1e293b;padding:0;}
    :root{
      --primary:#2563eb;--primary-dark:#1e40af;--gray-50:#f8fafc;--gray-100:#f1f5f9;
      --gray-200:#e2e8f0;--gray-400:#94a3b8;--gray-500:#64748b;--gray-600:#475569;
      --gray-700:#334155;--gray-800:#1e293b;--white:#fff;--radius:14px;--radius-sm:9px;
    }
    /* ── top bar ── */
    .top-bar{background:${headerGrad};color:#fff;padding:14px 24px;display:flex;justify-content:space-between;align-items:center;gap:12px;position:sticky;top:0;z-index:10;box-shadow:0 2px 12px rgba(0,0,0,.18);}
    .top-bar h2{font-size:1.05rem;font-weight:800;}
    .top-bar p{font-size:.75rem;opacity:.80;margin-top:2px;}
    .top-bar-actions{display:flex;gap:8px;flex-shrink:0;}
    .top-btn{background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.30);color:#fff;padding:7px 16px;border-radius:7px;font-family:'Nunito',sans-serif;font-weight:700;cursor:pointer;font-size:.82rem;transition:background .2s;}
    .top-btn:hover{background:rgba(255,255,255,.32);}
    .top-btn.print{background:rgba(255,255,255,.28);}
    /* ── content wrapper ── */
    .view-wrap{padding:24px;max-width:960px;margin:0 auto;}
    /* ── section headers ── */
    div[style*="background:linear-gradient(135deg,#2563eb"],
    div[style*="background:linear-gradient(135deg,#1e3a5f"]{border-radius:6px 6px 0 0 !important;}
    /* ── read-only field grid ── */
    .ro-section-body{border:1.5px solid var(--gray-200);border-top:none;border-radius:0 0 var(--radius-sm) var(--radius-sm);padding:14px 16px;margin-bottom:2px;background:#fff;}
    .ro-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;}
    .ro-field{margin-bottom:4px;}
    .ro-label{font-size:.65rem;font-weight:800;color:var(--gray-400);text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px;}
    .ro-val{font-size:.85rem;font-weight:600;color:var(--gray-800);padding:6px 10px;background:var(--gray-50);border-radius:6px;min-height:30px;word-break:break-word;}
    .ro-val.empty{color:var(--gray-400);font-style:italic;}
    .ro-full{grid-column:1/-1;}
    /* ── tables inside viewer ── */
    table{border-collapse:collapse;width:100%;}
    th{background:var(--gray-100);padding:7px 10px;text-align:left;font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:var(--gray-600);border:1px solid var(--gray-200);}
    td{padding:7px 10px;border:1px solid var(--gray-200);font-size:.78rem;vertical-align:top;}
    tr:hover td{background:var(--gray-50);}
    /* ── badges ── */
    .badge{padding:3px 10px;border-radius:20px;font-size:.70rem;font-weight:800;display:inline-block;}
    /* ── question rows ── */
    div[style*="border-bottom:1px solid var(--gray-100)"]{padding:6px 0;}
    @media print{.top-bar{display:none;}.view-wrap{padding:8px;}.saln-annex-tabs{display:none !important;}}
  `;

  const w = window.open('','_blank','width=1080,height=860');
  w.document.write(`<!DOCTYPE html><html><head>
    <meta charset="UTF-8"/>
    <title>${title}</title>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet"/>
    <style>${viewCSS}</style>
  </head><body>
    <div class="top-bar">
      <div>
        <h2>${title}</h2>
        <p>${empSub}</p>
      </div>
      <div class="top-bar-actions">
        <button class="top-btn print" onclick="window.print()">🖨️ Print</button>
        <button class="top-btn" onclick="window.close()">✕ Close</button>
      </div>
    </div>
    <div class="view-wrap">${content}</div>
  </body></html>`);
  w.document.close();
}

function buildSALNPrint(d, emp){
  const content = document.getElementById('salnContent') ? document.getElementById('salnContent').innerHTML : '';
  if(!content){ showToast('No SALN content to print.','error'); return; }
  const empName = document.getElementById('viewEmpName') ? document.getElementById('viewEmpName').textContent : 'Employee';
  const title = `SALN ${currentSalnYear||''} - ${empName}`;
  const w = window.open('','_blank','width=1080,height=860');
  w.document.write(`<!DOCTYPE html><html><head>
    <meta charset="UTF-8"/>
    <title>${title}</title>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet"/>
    <style>
      body{font-family:'Nunito',sans-serif;background:#fff;color:#111;padding:18px;}
      @page{size:A4 portrait;margin:10mm;}
      table{border-collapse:collapse;width:100%;}
      .no-print{position:sticky;top:0;background:#fff;padding-bottom:8px;margin-bottom:8px;}
      @media print{.no-print{display:none;}.saln-annex-tabs{display:none !important;}}
    </style>
  </head><body>
    <div class="no-print"><button onclick="window.print()" style="padding:8px 14px;border:none;background:#1e40af;color:#fff;border-radius:8px;cursor:pointer;">Print</button> <button onclick="window.close()" style="padding:8px 14px;border:none;background:#e2e8f0;color:#111;border-radius:8px;cursor:pointer;">Close</button></div>
    ${content}
  </body></html>`);
  w.document.close();
}

// ── SALN Print ─────────────────────────────────────────
function printSALN() {
  buildSALNPrintHTML({
    filing_type:  saln_g('saln_filing_type'),
    filing_mode:  salnRadioGet('saln_filing_mode'),
    saln_year:    salnCurrentYear,
    as_of_date:   saln_g('saln_as_of'),
    multi_spouse: saln_g('saln_multi_spouse'),
    multi_spouse_na: saln_g('saln_multi_na'),
    declarant_info:      JSON.stringify({
      lastname:saln_g('saln_dec_lastname'), firstname:saln_g('saln_dec_firstname'), mi:saln_g('saln_dec_mi'),
      position:saln_g('saln_dec_position'), agency:saln_g('saln_dec_agency'), office_addr:saln_g('saln_dec_office_addr'),
    }),
    spouse_info:         JSON.stringify({
      lastname:saln_g('saln_sp_lastname'), firstname:saln_g('saln_sp_firstname'), mi:saln_g('saln_sp_mi'),
      position:saln_g('saln_sp_position'), agency:saln_g('saln_sp_agency'), office_addr:saln_g('saln_sp_office_addr'),
    }),
    children_info:       JSON.stringify(collectRows('saln_children_list',  ['name','age'])),
    real_properties:     JSON.stringify(collectRows('saln_real_list',      ['description','kind','location','assessed_value','market_value','acq_year','acq_mode','acq_cost'])),
    personal_properties: JSON.stringify(collectRows('saln_personal_list',  ['description','acq_year','acq_cost'])),
    liabilities:         JSON.stringify(collectRows('saln_liab_list',      ['nature','creditor','balance'])),
    no_biz:   saln_g('saln_no_biz'),
    biz_interests:       JSON.stringify(collectRows('saln_biz_list',       ['entity_name','biz_address','nature','date_acquired'])),
    no_rel:   saln_g('saln_no_rel'),
    relatives_govt:      JSON.stringify(collectRows('saln_rel_list',       ['name','relationship','position','agency_address'])),
    real_subtotal:    saln_g('saln_real_subtotal'),
    personal_subtotal:saln_g('saln_personal_subtotal'),
    total_assets:     saln_g('saln_total_assets'),
    total_liabilities:saln_g('saln_total_liab'),
    net_worth:        saln_g('saln_net_worth'),
    gov_id:           saln_g('saln_gov_id'),
    gov_id_no:        saln_g('saln_gov_id_no'),
    gov_id_issued:    saln_g('saln_gov_id_issued'),
  }, null, true);
}

function buildSALNPrintHTML(d, emp, openPrint) {
  const sj   = s => { try{ return s?JSON.parse(s):null; }catch(e){return null;} };
  const v    = x => (x===null||x===undefined||x===''||x==='null') ? '' : String(x);
  const fmtD = dt => { if(!dt||dt==='0000-00-00') return ''; try{ const x=new Date(dt); return isNaN(x)?dt:(x.getMonth()+1).toString().padStart(2,'0')+'/'+x.getDate().toString().padStart(2,'0')+'/'+x.getFullYear(); }catch(e){return dt||'';} };
  const fmtM = x => { if(x===''||x===null||x===undefined) return ''; const n=parseFloat(x); return isNaN(n)?'':'₱'+n.toLocaleString('en-PH',{minimumFractionDigits:2}); };
  const padR = (arr,min,fn) => { const n=Math.max(min,(arr||[]).length); let h=''; for(let i=0;i<n;i++) h+=fn((arr||[])[i]||{}); return h; };
  const cb   = checked => (checked===true||checked==='1'||checked===1||checked==='Yes') ? '☑' : '☐';

  const nVal = x => {
    const n = parseFloat(String(x === undefined || x === null ? '' : x).replace(/[^0-9.-]/g, ''));
    return isNaN(n) ? 0 : n;
  };
  const hasRows = rows => Array.isArray(rows) && rows.some(row => Object.values(row || {}).some(value => String(value ?? '').trim() !== ''));
  const hasAnnexData = (rows, extra = []) => hasRows(rows) || extra.some(value => String(value ?? '').trim() !== '');
  const dec = sj(d.declarant_info)      || {};
  const sp  = sj(d.spouse_info)         || {};
  const ch  = sj(d.children_info)       || [];
  const rp  = sj(d.real_properties)     || [];
  const pp  = sj(d.personal_properties) || [];
  const lb  = sj(d.liabilities)         || [];
  const biz = sj(d.biz_interests)       || [];
  const rel = sj(d.relatives_govt)      || [];
  const as1Rp = sj(d.as1_real_properties)     || [];
  const as1Pp = sj(d.as1_personal_properties) || [];
  const as1Lb = sj(d.as1_liabilities)         || [];
  const as1Biz= sj(d.as1_biz_interests)       || [];
  const as2Nm = sj(d.as2_name_info)           || {};
  const as2Rp = sj(d.as2_real_properties)     || [];
  const as2Pp = sj(d.as2_personal_properties) || [];
  const as2Lb = sj(d.as2_liabilities)         || [];
  const as2Biz= sj(d.as2_biz_interests)       || [];

  const derivedRealSubtotal = rp.reduce((sum, p) => sum + (nVal(p.market_value) || nVal(p.assessed_value)), 0);
  const derivedPersonalSubtotal = pp.reduce((sum, p) => sum + nVal(p.acq_cost), 0);
  const derivedTotalLiabilities = lb.reduce((sum, l) => sum + nVal(l.balance), 0);
  const realSubtotal = (d.real_subtotal !== '' && d.real_subtotal !== null && d.real_subtotal !== undefined) ? nVal(d.real_subtotal) : derivedRealSubtotal;
  const personalSubtotal = (d.personal_subtotal !== '' && d.personal_subtotal !== null && d.personal_subtotal !== undefined) ? nVal(d.personal_subtotal) : derivedPersonalSubtotal;
  const totalAssets = (d.total_assets !== '' && d.total_assets !== null && d.total_assets !== undefined) ? nVal(d.total_assets) : (realSubtotal + personalSubtotal);
  const totalLiabilities = (d.total_liabilities !== '' && d.total_liabilities !== null && d.total_liabilities !== undefined) ? nVal(d.total_liabilities) : derivedTotalLiabilities;
  const netWorth = (d.net_worth !== '' && d.net_worth !== null && d.net_worth !== undefined) ? nVal(d.net_worth) : (totalAssets - totalLiabilities);

  const isAssumption = d.filing_type==='Assumption';
  const isAnnual     = d.filing_type==='Annual';
  const isExit       = d.filing_type==='Exit';
  const isJoint      = d.filing_mode==='Joint';
  const isSeparate   = d.filing_mode==='Separate';
  const isNA         = !isJoint && !isSeparate;

  const C  = (txt,s) => `<td style="border:0.5pt solid #000;padding:1.5pt 3pt;font-size:6.5pt;vertical-align:middle;${s||''}">${v(txt)}&nbsp;</td>`;
  const H  = (txt,s) => `<td style="border:0.5pt solid #000;padding:2pt 3pt;font-size:6pt;font-weight:700;text-align:center;vertical-align:middle;${s||''}">${txt}</td>`;

  const realRows = padR(rp, 4, p => `<tr style="height:18pt;">
    ${C(p.description,'width:18%;font-size:6.5pt;')}${C(p.kind,'width:12%;')}${C(p.location,'width:16%;')}
    ${C(fmtM(p.assessed_value),'width:10%;text-align:right;')}${C(fmtM(p.market_value),'width:10%;text-align:right;')}
    ${C(p.acq_year,'width:8%;text-align:center;')}${C(p.acq_mode,'width:10%;')}${C(fmtM(p.acq_cost),'width:10%;text-align:right;')}
  </tr>`);
  const persRows = padR(pp, 6, p => `<tr style="height:16pt;">
    ${C(p.description,'width:65%;')}${C(p.acq_year,'width:17%;text-align:center;')}${C(fmtM(p.acq_cost),'width:18%;text-align:right;')}
  </tr>`);
  const liabRows = padR(lb, 5, l => `<tr style="height:14pt;">
    ${C(l.nature,'width:34%;')}${C(l.creditor,'width:41%;')}${C(fmtM(l.balance),'width:25%;text-align:right;')}
  </tr>`);
  const bizRows = padR(d.no_biz?[]:biz, 3, b => `<tr style="height:14pt;">
    ${C(b.entity_name,'width:27%;')}${C(b.biz_address,'width:26%;')}${C(b.nature,'width:28%;')}${C(fmtD(b.date_acquired),'width:19%;text-align:center;')}
  </tr>`);
  const relRows = padR(d.no_rel?[]:rel, 5, r => `<tr style="height:14pt;">
    ${C(r.name,'width:24%;')}${C(r.relationship,'width:16%;')}${C(r.position,'width:24%;')}${C(r.agency_address,'width:36%;')}
  </tr>`);
  const childRows = padR(ch, 3, c => `<tr style="height:16pt;">
    <td style="border:0.5pt solid #000;padding:1.5pt 3pt;font-size:7pt;width:76%;">${v(c.name)}&nbsp;</td>
    <td style="border:0.5pt solid #000;padding:1.5pt 3pt;font-size:7pt;text-align:center;width:24%;">${v(c.age)}&nbsp;</td>
  </tr>`);

  const sharedCss = `
    *{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'Arial Narrow',Arial,sans-serif;font-size:7pt;color:#000;background:#fff;}
    @page{size:8.5in 13in;margin:12mm 14mm 10mm 14mm;}
    @media print{body{margin:0;} .no-print{display:none;}}
    table{border-collapse:collapse;width:100%;}
    .section-title{font-size:8pt;font-weight:700;text-align:center;text-decoration:underline;text-transform:uppercase;letter-spacing:.03em;}
    .page-footer{text-align:center;font-size:7.5pt;font-style:italic;margin-top:6pt;}
    .ref{text-align:right;font-size:6pt;margin-bottom:4pt;line-height:1.6;}
    .main-title{text-align:center;font-size:10pt;font-weight:700;text-transform:uppercase;letter-spacing:.04em;}
    .as-of-line{text-align:center;font-size:7pt;margin-top:2pt;}
    .italic-note{text-align:center;font-size:6pt;font-style:italic;margin:3pt 0 6pt;line-height:1.5;}
    .double-rule{border-top:2pt double #000;border-bottom:0.5pt solid #000;height:3pt;margin-bottom:6pt;}
    .assets-title{font-size:8pt;font-weight:700;text-align:center;text-decoration:underline;text-transform:uppercase;margin-bottom:2pt;}
    .section-num{font-size:7pt;font-weight:700;margin-bottom:2pt;}
    .section-sub{font-size:7pt;font-weight:700;margin:4pt 0 2pt;}
    .col-hd{border:0.5pt solid #000;padding:2pt 3pt;font-size:6pt;font-weight:700;text-align:center;vertical-align:middle;background:#c8c8c8;}
    .data-row td{border:0.5pt solid #000;padding:1.5pt 3pt;font-size:6.5pt;vertical-align:middle;}
    .subtotal-row td,.total-row td{border:none;padding:2pt 0;font-size:7pt;font-weight:700;text-align:right;}
    .footer-area{display:flex;justify-content:space-between;align-items:flex-end;margin-top:12pt;}
    .page-num{font-size:7.5pt;font-style:italic;}
    .sig-line{font-size:7pt;font-style:italic;border-top:0.5pt solid #000;padding-top:2pt;min-width:140pt;text-align:center;}
    .name-row td{border-bottom:0.5pt solid #000;padding:1.5pt 2pt;height:14pt;font-size:7pt;}
    .name-lbl{font-size:5.5pt;padding-top:1pt;text-align:center;}
  `;

  const buildAnnexSection = (title, refText, noteHtml, nameHtml, position, agency, real, pers, liab, bizItems, realSubtotalValue, personalSubtotalValue, totalAssetsValue, totalLiabilitiesValue) => {
    const annexRealRows = real.map(r => `<tr class="data-row">
      <td style="width:15%;">${v(r.description)}&nbsp;</td>
      <td style="width:12%;">${v(r.kind)}&nbsp;</td>
      <td style="width:14%;">${v(r.location)}&nbsp;</td>
      <td style="width:9%;text-align:right;">${fmtM(r.assessed_value)}&nbsp;</td>
      <td style="width:10%;text-align:right;">${fmtM(r.market_value)}&nbsp;</td>
      <td style="width:8%;text-align:center;">${v(r.acq_year)}&nbsp;</td>
      <td style="width:9%;">${v(r.acq_mode)}&nbsp;</td>
      <td style="width:9%;text-align:right;">${fmtM(r.acq_cost)}&nbsp;</td>
    </tr>`).join('');
    const annexPersRows = pers.map(r => `<tr class="data-row">
      <td style="width:62%;">${v(r.description)}&nbsp;</td>
      <td style="width:18%;text-align:center;">${v(r.acq_year)}&nbsp;</td>
      <td style="width:20%;text-align:right;">${fmtM(r.acq_cost)}&nbsp;</td>
    </tr>`).join('');
    const annexLiabRows = liab.map(r => `<tr class="data-row">
      <td style="width:30%;">${v(r.nature)}&nbsp;</td>
      <td style="width:43%;">${v(r.creditor)}&nbsp;</td>
      <td style="width:27%;text-align:right;">${fmtM(r.balance)}&nbsp;</td>
    </tr>`).join('');
    const annexBizRows = bizItems.map(r => `<tr class="data-row">
      <td style="width:27%;">${v(r.entity_name)}&nbsp;</td>
      <td style="width:23%;">${v(r.biz_address)}&nbsp;</td>
      <td style="width:29%;">${v(r.nature)}&nbsp;</td>
      <td style="width:21%;text-align:center;">${fmtD(r.date_acquired)}&nbsp;</td>
    </tr>`).join('');

    return `
<div style="page-break-before:always;"></div>
<div class="ref">${refText}</div>
<div class="main-title">${title}</div>
<div class="as-of-line">As of <span style="display:inline-block;border-bottom:0.5pt solid #000;min-width:160pt;">&nbsp;</span></div>
<div class="italic-note">${noteHtml}</div>
<table style="margin-bottom:6pt;" class="name-block">
  <tr>
    <td style="border:none;width:7%;padding:2pt 0;font-size:7pt;font-weight:700;vertical-align:bottom;">NAME:</td>
    <td style="border:none;width:43%;padding:0;vertical-align:bottom;">${nameHtml}</td>
    <td style="border:none;width:8%;padding:2pt 4pt;font-size:7pt;font-weight:700;vertical-align:bottom;">POSITION:</td>
    <td style="border:none;border-bottom:0.5pt solid #000;width:42%;vertical-align:bottom;">${v(position)}&nbsp;</td>
  </tr>
  <tr>
    <td style="border:none;" colspan="2"></td>
    <td style="border:none;padding:2pt 4pt;font-size:7pt;font-weight:700;vertical-align:bottom;">AGENCY/OFFICE:</td>
    <td style="border:none;border-bottom:0.5pt solid #000;vertical-align:bottom;">${v(agency)}&nbsp;</td>
  </tr>
</table>
<div class="double-rule"></div>
<div class="assets-title">Assets, Liabilities and Net Worth</div>
<div class="section-num">1.&nbsp;&nbsp;ASSETS</div>
<div class="section-sub">a.&nbsp;&nbsp;Real Properties</div>
<table style="margin-bottom:4pt;">
  <thead>
    <tr>
      <th class="col-hd" style="width:15%;">DESCRIPTION<br><span style="font-weight:400;font-size:5pt;">(e.g. lot, house and<br>lot, condominium<br>and improvements)</span></th>
      <th class="col-hd" style="width:12%;">KIND<br><span style="font-weight:400;font-size:5pt;">(e.g. residential,<br>commercial, industrial,<br>agricultural and mixed<br>use)</span></th>
      <th class="col-hd" style="width:14%;">EXACT<br>LOCATION</th>
      <th class="col-hd" style="width:9%;">ASSESSED<br>VALUE</th>
      <th class="col-hd" style="width:10%;">CURRENT FAIR<br>MARKET VALUE<br><span style="font-weight:400;font-size:5pt;">(As found in the Tax Declaration of<br>Real Property)</span></th>
      <td colspan="2" style="padding:0;width:17%;background:#c8c8c8;vertical-align:top;border:0.5pt solid #000;">
        <div style="text-align:center;font-size:6pt;font-weight:700;padding:2pt;border-bottom:0.5pt solid #000;">ACQUISITION</div>
        <table style="width:100%;border-collapse:collapse;"><tr>
          <td style="border-right:0.5pt solid #000;padding:2pt 1pt;font-size:5.5pt;font-weight:700;text-align:center;width:50%;background:#c8c8c8;">YEAR</td>
          <td style="padding:2pt 1pt;font-size:5.5pt;font-weight:700;text-align:center;width:50%;background:#c8c8c8;">MODE</td>
        </tr></table>
      </td>
      <th class="col-hd" style="width:9%;">ACQUISITION<br>COST</th>
    </tr>
  </thead>
  <tbody>${annexRealRows}</tbody>
</table>
<table style="margin-bottom:8pt;"><tr class="subtotal-row"><td colspan="8">Subtotal: <span style="display:inline-block;border-bottom:0.5pt solid #000;min-width:100pt;">${fmtM(realSubtotalValue)}&nbsp;</span></td></tr></table>
<div class="section-sub">b.&nbsp;&nbsp;Personal Properties</div>
<table style="margin-bottom:0;">
  <thead>
    <tr>
      <th class="col-hd" style="width:62%;">DESCRIPTION</th>
      <th class="col-hd" style="width:18%;">ACQUISITION YEAR</th>
      <th class="col-hd" style="width:20%;">ACQUISITION<br>COST/ AMOUNT</th>
    </tr>
  </thead>
  <tbody>${annexPersRows}</tbody>
</table>
<table style="margin-bottom:8pt;">
  <tr class="subtotal-row"><td colspan="3">Subtotal: <span style="display:inline-block;border-bottom:0.5pt solid #000;min-width:100pt;">${fmtM(personalSubtotalValue)}&nbsp;</span></td></tr>
  <tr class="total-row"><td colspan="3">Total Assets: <span style="display:inline-block;border-bottom:2pt solid #000;min-width:100pt;">${fmtM(totalAssetsValue)}&nbsp;</span></td></tr>
</table>
<div class="section-num">2.&nbsp;&nbsp;LIABILITIES</div>
<table style="margin-bottom:0;">
  <thead>
    <tr>
      <th class="col-hd" style="width:30%;">NATURE</th>
      <th class="col-hd" style="width:43%;">NAME OF CREDITORS</th>
      <th class="col-hd" style="width:27%;">OUTSTANDING BALANCE</th>
    </tr>
  </thead>
  <tbody>${annexLiabRows}</tbody>
</table>
<table style="margin-bottom:4pt;"><tr class="total-row"><td colspan="3">Total Liabilities: <span style="display:inline-block;border-bottom:0.5pt solid #000;min-width:100pt;">${fmtM(totalLiabilitiesValue)}&nbsp;</span></td></tr></table>
<div class="double-rule"></div>
<div style="font-size:8pt;font-weight:700;text-align:center;text-decoration:underline;text-transform:uppercase;margin-bottom:4pt;">Business Interests and Financial Connections</div>
<table style="margin-bottom:16pt;">
  <thead>
    <tr>
      <th class="col-hd" style="width:27%;">NAME OF ENTITY/BUSINESS<br>ENTERPRISE</th>
      <th class="col-hd" style="width:23%;">BUSINESS ADDRESS</th>
      <th class="col-hd" style="width:29%;">NATURE OF BUSINESS<br>INTEREST &amp;/OR FINANCIAL<br>CONNECTION</th>
      <th class="col-hd" style="width:21%;">DATE OF ACQUISITION OF<br>INTEREST OR CONNECTION</th>
    </tr>
  </thead>
  <tbody>${annexBizRows}</tbody>
</table>
<div class="footer-area">
  <div class="page-num"><em>Page ___ of ___</em></div>
  <div class="sig-line">Signature/Initial of Declarant</div>
</div>`;
  };

  const buildNameTable = (lastName, firstName, middleInitial) => `
    <table style="width:100%;border-collapse:collapse;">
      <tr class="name-row">
        <td style="width:40%;">${v(lastName)}&nbsp;</td>
        <td style="border-left:none;width:40%;">${v(firstName)}&nbsp;</td>
        <td style="border-left:none;width:20%;">${v(middleInitial)}&nbsp;</td>
      </tr>
      <tr>
        <td class="name-lbl" style="border:none;">(Family Name)</td>
        <td class="name-lbl" style="border:none;">(First Name)</td>
        <td class="name-lbl" style="border:none;">(M.I.)</td>
      </tr>
    </table>`;

  const annexBRealSubtotal = (d.as1_real_subtotal !== '' && d.as1_real_subtotal !== null && d.as1_real_subtotal !== undefined)
    ? nVal(d.as1_real_subtotal)
    : as1Rp.reduce((sum, p) => sum + (nVal(p.market_value) || nVal(p.assessed_value)), 0);
  const annexBPersonalSubtotal = (d.as1_personal_subtotal !== '' && d.as1_personal_subtotal !== null && d.as1_personal_subtotal !== undefined)
    ? nVal(d.as1_personal_subtotal)
    : as1Pp.reduce((sum, p) => sum + nVal(p.acq_cost), 0);
  const annexBTotalAssets = (d.as1_total_assets !== '' && d.as1_total_assets !== null && d.as1_total_assets !== undefined)
    ? nVal(d.as1_total_assets)
    : annexBRealSubtotal + annexBPersonalSubtotal;
  const annexBTotalLiabilities = (d.as1_total_liabilities !== '' && d.as1_total_liabilities !== null && d.as1_total_liabilities !== undefined)
    ? nVal(d.as1_total_liabilities)
    : as1Lb.reduce((sum, l) => sum + nVal(l.balance), 0);

  const annexCRealSubtotal = (d.as2_real_subtotal !== '' && d.as2_real_subtotal !== null && d.as2_real_subtotal !== undefined)
    ? nVal(d.as2_real_subtotal)
    : as2Rp.reduce((sum, p) => sum + (nVal(p.market_value) || nVal(p.assessed_value)), 0);
  const annexCPersonalSubtotal = (d.as2_personal_subtotal !== '' && d.as2_personal_subtotal !== null && d.as2_personal_subtotal !== undefined)
    ? nVal(d.as2_personal_subtotal)
    : as2Pp.reduce((sum, p) => sum + nVal(p.acq_cost), 0);
  const annexCTotalAssets = (d.as2_total_assets !== '' && d.as2_total_assets !== null && d.as2_total_assets !== undefined)
    ? nVal(d.as2_total_assets)
    : annexCRealSubtotal + annexCPersonalSubtotal;
  const annexCTotalLiabilities = (d.as2_total_liabilities !== '' && d.as2_total_liabilities !== null && d.as2_total_liabilities !== undefined)
    ? nVal(d.as2_total_liabilities)
    : as2Lb.reduce((sum, l) => sum + nVal(l.balance), 0);

  const includeAnnexB = hasAnnexData(as1Rp, [d.as1_real_subtotal, d.as1_personal_subtotal, d.as1_total_assets, d.as1_total_liabilities])
    || hasAnnexData(as1Pp) || hasAnnexData(as1Lb) || hasAnnexData(as1Biz);
  const includeAnnexC = hasAnnexData(as2Rp, [d.as2_real_subtotal, d.as2_personal_subtotal, d.as2_total_assets, d.as2_total_liabilities, as2Nm.full_name, as2Nm.position, as2Nm.agency])
    || hasAnnexData(as2Pp) || hasAnnexData(as2Lb) || hasAnnexData(as2Biz);

  let annexSections = '';
  if (includeAnnexB) {
    annexSections += buildAnnexSection(
      'Sworn Statement of Assets, Liabilities and Net Worth',
      'SALN Form AS-1 (Declarant)<br>Per CSC Resolution No. 2500632<br>Promulgated on 25 June 2025',
      '(Additional sheet/s for the declarant)',
      buildNameTable(dec.lastname, dec.firstname, dec.mi),
      dec.position,
      dec.agency,
      as1Rp,
      as1Pp,
      as1Lb,
      as1Biz,
      annexBRealSubtotal,
      annexBPersonalSubtotal,
      annexBTotalAssets,
      annexBTotalLiabilities
    );
  }
  if (includeAnnexC) {
    const annexCName = {
      last: as2Nm.lastname || sp.lastname || '',
      first: as2Nm.firstname || sp.firstname || '',
      mi: as2Nm.mi || sp.mi || ''
    };
    if (!annexCName.last && !annexCName.first && !annexCName.mi && as2Nm.full_name) {
      annexCName.last = as2Nm.full_name;
    }
    annexSections += buildAnnexSection(
      'Statement of Assets, Liabilities and Net Worth',
      '2025 SALN Form AS-2 (Spouse &amp; Children)<br>Per CSC Resolution No. 2500632<br>Promulgated on 25 June 2025',
      '(Additional sheet/s for the exclusive properties of the declarant\'s spouse and unmarried children<br>below eighteen (18) years of age living in declarant\'s household)',
      buildNameTable(annexCName.last, annexCName.first, annexCName.mi),
      as2Nm.position || sp.position,
      as2Nm.agency || sp.agency,
      as2Rp,
      as2Pp,
      as2Lb,
      as2Biz,
      annexCRealSubtotal,
      annexCPersonalSubtotal,
      annexCTotalAssets,
      annexCTotalLiabilities
    );
  }

  const html = `<!DOCTYPE html>
<html><head>
  <meta charset="UTF-8"/>
  <title>SALN ${v(d.saln_year)} — ${v(dec.lastname)}, ${v(dec.firstname)}</title>
  <style>${sharedCss}</style>
</head><body>

<div class="ref">
  2025 SALN Form<br>Per CSC Resolution No. 2500632<br>Promulgated on 25 June 2025
</div>

<div style="text-align:center;margin-bottom:6pt;">
  <div style="font-size:10pt;font-weight:700;text-transform:uppercase;letter-spacing:.04em;">Sworn Statement of Assets, Liabilities, and Net Worth</div>
  <div style="font-size:7pt;">(As required by R.A. No. 6713)</div>
</div>

<table style="margin-bottom:4pt;">
  <tr>
    <td colspan="3" style="border:none;padding:0 0 2pt 0;font-size:7.5pt;font-weight:700;text-align:center;text-decoration:underline;">COMPLIANCE FOR:</td>
  </tr>
  <tr>
    <td style="border:none;padding:1pt 6pt;font-size:7pt;width:34%;">
      <span style="display:inline-block;border:0.5pt solid #000;width:8pt;height:8pt;text-align:center;line-height:7.5pt;font-size:7pt;vertical-align:middle;">${cb(isAssumption)}</span>
      &nbsp;<em>Assumption of office as of</em> ___________
    </td>
    <td style="border:none;padding:1pt 6pt;font-size:7pt;width:33%;text-align:center;">
      <span style="display:inline-block;border:0.5pt solid #000;width:8pt;height:8pt;text-align:center;line-height:7.5pt;font-size:7pt;vertical-align:middle;">${cb(isAnnual)}</span>
      &nbsp;<em>Annual filing as of December 31,</em> ${isAnnual?`<strong>${v(d.saln_year)}</strong>`:'______'}
    </td>
    <td style="border:none;padding:1pt 6pt;font-size:7pt;width:33%;text-align:right;">
      <span style="display:inline-block;border:0.5pt solid #000;width:8pt;height:8pt;text-align:center;line-height:7.5pt;font-size:7pt;vertical-align:middle;">${cb(isExit)}</span>
      &nbsp;<em>Exit as of</em> ___________
    </td>
  </tr>
</table>

<div style="border-top:2pt double #000;border-bottom:0.5pt solid #000;height:3pt;margin-bottom:4pt;"></div>

<table style="margin-bottom:4pt;">
  <tr>
    <td style="border:0.5pt solid #000;width:55%;padding:4pt 5pt;vertical-align:top;">
      <div style="font-size:6.5pt;font-weight:700;margin-bottom:4pt;">DECLARANT:</div>
      <table style="border-collapse:collapse;width:100%;margin-bottom:4pt;">
        <tr>
          <td style="border-bottom:0.5pt solid #000;padding-bottom:1pt;width:40%;font-size:7pt;">${v(dec.lastname)}&nbsp;</td>
          <td style="border-bottom:0.5pt solid #000;padding-bottom:1pt;padding-left:6pt;width:40%;font-size:7pt;">${v(dec.firstname)}&nbsp;</td>
          <td style="border-bottom:0.5pt solid #000;padding-bottom:1pt;padding-left:4pt;width:20%;font-size:7pt;">${v(dec.mi)}&nbsp;</td>
        </tr>
        <tr>
          <td style="font-size:5.5pt;padding-top:1pt;text-align:center;">(Family Name)</td>
          <td style="font-size:5.5pt;padding-top:1pt;text-align:center;padding-left:6pt;">(First Name)</td>
          <td style="font-size:5.5pt;padding-top:1pt;text-align:center;padding-left:4pt;">(M.I.)</td>
        </tr>
      </table>
    </td>
    <td style="border:0.5pt solid #000;border-left:none;width:45%;padding:4pt 5pt;vertical-align:top;">
      <div style="font-size:6.5pt;font-weight:700;margin-bottom:2pt;">POSITION:</div>
      <div style="border-bottom:0.5pt solid #000;min-height:10pt;font-size:7pt;margin-bottom:3pt;">${v(dec.position)}&nbsp;</div>
      <div style="font-size:6.5pt;font-weight:700;margin-bottom:2pt;">AGENCY/OFFICE:</div>
      <div style="border-bottom:0.5pt solid #000;min-height:10pt;font-size:7pt;margin-bottom:3pt;">${v(dec.agency)}&nbsp;</div>
      <div style="font-size:6.5pt;font-weight:700;margin-bottom:2pt;">OFFICE ADDRESS:</div>
      <div style="border-bottom:0.5pt solid #000;min-height:10pt;font-size:7pt;">${v(dec.office_addr)}&nbsp;</div>
    </td>
  </tr>
  <tr>
    <td style="border:0.5pt solid #000;border-top:none;width:55%;padding:4pt 5pt;vertical-align:top;">
      <div style="font-size:6.5pt;font-weight:700;margin-bottom:4pt;">SPOUSE:</div>
      <table style="border-collapse:collapse;width:100%;margin-bottom:4pt;">
        <tr>
          <td style="border-bottom:0.5pt solid #000;padding-bottom:1pt;width:40%;font-size:7pt;">${v(sp.lastname)}&nbsp;</td>
          <td style="border-bottom:0.5pt solid #000;padding-bottom:1pt;padding-left:6pt;width:40%;font-size:7pt;">${v(sp.firstname)}&nbsp;</td>
          <td style="border-bottom:0.5pt solid #000;padding-bottom:1pt;padding-left:4pt;width:20%;font-size:7pt;">${v(sp.mi)}&nbsp;</td>
        </tr>
        <tr>
          <td style="font-size:5.5pt;padding-top:1pt;text-align:center;">(Family Name)</td>
          <td style="font-size:5.5pt;padding-top:1pt;text-align:center;padding-left:6pt;">(First Name)</td>
          <td style="font-size:5.5pt;padding-top:1pt;text-align:center;padding-left:4pt;">(M.I.)</td>
        </tr>
      </table>
    </td>
    <td style="border:0.5pt solid #000;border-left:none;border-top:none;width:45%;padding:4pt 5pt;vertical-align:top;">
      <div style="font-size:6.5pt;font-weight:700;margin-bottom:2pt;">POSITION:</div>
      <div style="border-bottom:0.5pt solid #000;min-height:10pt;font-size:7pt;margin-bottom:3pt;">${v(sp.position)}&nbsp;</div>
      <div style="font-size:6.5pt;font-weight:700;margin-bottom:2pt;">AGENCY/OFFICE:<sup style="font-size:5pt;">1</sup></div>
      <div style="border-bottom:0.5pt solid #000;min-height:10pt;font-size:7pt;margin-bottom:3pt;">${v(sp.agency)}&nbsp;</div>
      <div style="font-size:6.5pt;font-weight:700;margin-bottom:2pt;">OFFICE ADDRESS:</div>
      <div style="border-bottom:0.5pt solid #000;min-height:10pt;font-size:7pt;">${v(sp.office_addr)}&nbsp;</div>
    </td>
  </tr>
</table>

<div style="font-size:6.5pt;font-weight:700;margin-bottom:3pt;line-height:1.6;">
  SPOUSES, WHO ARE BOTH PUBLIC OFFICIALS OR EMPLOYEES, MAY FILE THE SALN JOINTLY OR SEPARATELY.<br>
  THE DECLARANT SHALL CHECK THE APPROPRIATE BOX<br>
  <span style="font-weight:400;font-style:italic;">
    &nbsp;&nbsp;&nbsp;
    <span style="display:inline-block;border:0.5pt solid #000;width:8pt;height:8pt;text-align:center;line-height:7.5pt;font-size:7pt;vertical-align:middle;">${cb(isJoint)}</span>
    &nbsp;Joint Filing
    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
    <span style="display:inline-block;border:0.5pt solid #000;width:8pt;height:8pt;text-align:center;line-height:7.5pt;font-size:7pt;vertical-align:middle;">${cb(isSeparate)}</span>
    &nbsp;Separate Filing
    &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
    <span style="display:inline-block;border:0.5pt solid #000;width:8pt;height:8pt;text-align:center;line-height:7.5pt;font-size:7pt;vertical-align:middle;">${cb(isNA)}</span>
    &nbsp;Not Applicable
  </span>
</div>

<div style="font-size:6.5pt;font-weight:700;margin-bottom:6pt;line-height:1.6;">
  IF WITH MULTIPLE MARRIAGES, INDICATE NAME(S) OF SPOUSES, OTHERWISE CHECK THE "NOT APPLICABLE" BOX.<br>
  <span style="display:inline-block;border-bottom:0.5pt solid #000;min-width:220pt;font-size:7pt;font-weight:400;">${d.multi_spouse_na?'':v(d.multi_spouse)}&nbsp;</span>
  &nbsp;&nbsp;&nbsp;
  <span style="display:inline-block;border:0.5pt solid #000;width:8pt;height:8pt;text-align:center;line-height:7.5pt;font-size:7pt;vertical-align:middle;">${cb(d.multi_spouse_na)}</span>
  &nbsp;<em style="font-weight:400;">Not Applicable</em>
</div>

<div style="border-top:2pt double #000;border-bottom:0.5pt solid #000;height:3pt;margin-bottom:6pt;"></div>

<table style="margin-bottom:6pt;">
  <tr>
    <td colspan="2" style="border:0.5pt solid #000;padding:2pt 5pt;font-size:7pt;font-weight:700;text-align:center;text-decoration:underline;text-transform:uppercase;">
      Unmarried Children Below Eighteen (18) Years of Age Living in Declarant's Household
    </td>
  </tr>
  <tr>
    ${H('NAME OF CHILD','width:76%;text-align:center;')}
    ${H('AGE','width:24%;text-align:center;')}
  </tr>
  ${childRows}
</table>

<div style="border-top:2pt double #000;border-bottom:0.5pt solid #000;height:3pt;margin-bottom:6pt;"></div>

<div style="font-size:8pt;font-weight:700;text-align:center;text-decoration:underline;text-transform:uppercase;margin-bottom:2pt;">Assets, Liabilities and Net Worth<sup style="font-size:5.5pt;">ii</sup></div>
<div style="font-size:6pt;text-align:center;font-style:italic;margin-bottom:4pt;">(Including those of the spouse and unmarried children below eighteen (18) years of age living in declarant's household)<sup style="font-size:5pt;">iii</sup></div>

<div style="font-size:7pt;font-weight:700;margin-bottom:2pt;">1.&nbsp;&nbsp;ASSETS</div>
<div style="font-size:7pt;font-weight:700;margin-bottom:2pt;">&nbsp;&nbsp;&nbsp;&nbsp;a.&nbsp;&nbsp;Real Properties</div>
<table style="margin-bottom:0;">
  <tr>
    ${H('DESCRIPTION<br><span style="font-weight:400;font-size:5pt;">(e.g. lot, house and<br>lot, condominium,<br>and improvements)</span>','width:18%;')}
    ${H('KIND<br><span style="font-weight:400;font-size:5pt;">(e.g. residential,<br>commercial, industrial,<br>agricultural and mixed<br>use)</span>','width:12%;')}
    ${H('EXACT<br>LOCATION','width:16%;')}
    ${H('ASSESSED<br>VALUE','width:10%;')}
    ${H('CURRENT FAIR<br>MARKET VALUE<br><span style="font-weight:400;font-size:5pt;">(As found in the Tax Declaration of<br>Real Property, if available)</span>','width:10%;')}
    <td colspan="3" style="border:0.5pt solid #000;padding:0;width:28%;vertical-align:top;">
      <div style="text-align:center;font-size:6pt;font-weight:700;padding:2pt;border-bottom:0.5pt solid #000;">ACQUISITION</div>
      <table style="width:100%;border-collapse:collapse;"><tr>
        <td style="border-right:0.5pt solid #000;padding:2pt 1pt;font-size:5.5pt;font-weight:700;text-align:center;width:33%;">YEAR</td>
        <td style="border-right:0.5pt solid #000;padding:2pt 1pt;font-size:5.5pt;font-weight:700;text-align:center;width:33%;">MODE</td>
        <td style="padding:2pt 1pt;font-size:5.5pt;font-weight:700;text-align:center;width:34%;">ACQUISITION<br>COST</td>
      </tr></table>
    </td>
  </tr>
  ${realRows}
  <tr>
    <td colspan="7" style="border:0.5pt solid #000;padding:2pt 4pt;font-size:6.5pt;font-weight:700;text-align:right;">Subtotal:</td>
    <td style="border:0.5pt solid #000;padding:2pt 4pt;font-size:7pt;font-weight:700;text-align:right;border-bottom:2pt solid #000;">${fmtM(realSubtotal)}&nbsp;</td>
  </tr>
</table>

<div style="font-size:7pt;font-weight:700;margin-top:4pt;margin-bottom:2pt;">&nbsp;&nbsp;&nbsp;&nbsp;b.&nbsp;&nbsp;Personal Properties</div>
<table style="margin-bottom:0;">
  <tr>
    ${H('DESCRIPTION','width:65%;')}
    ${H('ACQUISITION YEAR','width:17%;')}
    ${H('ACQUISITION<br>COST/ AMOUNT','width:18%;')}
  </tr>
  ${persRows}
  <tr>
    <td colspan="2" style="border:0.5pt solid #000;padding:2pt 4pt;font-size:6.5pt;font-weight:700;text-align:right;">Subtotal:</td>
    <td style="border:0.5pt solid #000;padding:2pt 4pt;font-size:7pt;font-weight:700;text-align:right;border-bottom:2pt solid #000;">${fmtM(personalSubtotal)}&nbsp;</td>
  </tr>
  <tr>
    <td colspan="2" style="border:0.5pt solid #000;border-top:none;padding:2pt 4pt;font-size:7pt;font-weight:700;text-align:right;text-transform:uppercase;">Total Assets:</td>
    <td style="border:0.5pt solid #000;border-top:none;padding:2pt 4pt;font-size:7.5pt;font-weight:800;text-align:right;border-bottom:2pt solid #000;">${fmtM(totalAssets)}&nbsp;</td>
  </tr>
</table>

<div style="display:flex;justify-content:space-between;align-items:flex-end;margin-top:8pt;">
  <div style="font-size:7.5pt;font-style:italic;">Page 1 of ___</div>
  <div style="font-size:7pt;font-style:italic;border-top:0.5pt solid #000;padding-top:2pt;min-width:120pt;text-align:center;">Signature/Initial of Declarant</div>
</div>

<div style="page-break-after:always;"></div>

<div style="font-size:7pt;font-weight:700;margin-bottom:2pt;">2.&nbsp;&nbsp;LIABILITIES</div>
<table style="margin-bottom:0;">
  <tr>
    ${H('NATURE','width:34%;')}
    ${H('NAME OF CREDITORS','width:41%;')}
    ${H('OUTSTANDING BALANCE','width:25%;')}
  </tr>
  ${liabRows}
  <tr>
    <td colspan="2" style="border:0.5pt solid #000;padding:2pt 4pt;font-size:7pt;font-weight:700;text-align:right;text-transform:uppercase;">Total Liabilities:</td>
    <td style="border:0.5pt solid #000;padding:2pt 4pt;font-size:7.5pt;font-weight:800;text-align:right;border-bottom:2pt solid #000;">${fmtM(totalLiabilities)}&nbsp;</td>
  </tr>
  <tr>
    <td colspan="2" style="border:0.5pt solid #000;border-top:none;padding:2pt 4pt;font-size:7pt;font-weight:700;text-transform:uppercase;">Net Worth: Total Assets less Total Liabilities =</td>
    <td style="border:0.5pt solid #000;border-top:none;padding:2pt 4pt;font-size:7.5pt;font-weight:800;text-align:right;border-bottom:2.5pt double #000;">${fmtM(netWorth)}&nbsp;</td>
  </tr>
</table>

<div style="margin-top:10pt;margin-bottom:2pt;">
  <div class="section-title">Business Interests and Financial Connections</div>
  <div style="font-size:6pt;text-align:center;font-style:italic;">(of Declarant / Declarant's spouse/ Unmarried Children Below Eighteen (18) years of Age Living in Declarant's Household)</div>
</div>
<div style="font-size:7pt;margin-bottom:3pt;">
  <span style="display:inline-block;border:0.5pt solid #000;width:8pt;height:8pt;text-align:center;line-height:7.5pt;font-size:7pt;vertical-align:middle;">${cb(d.no_biz)}</span>
  &nbsp;<em>I/ We do not have any business interest or financial connection.</em>
</div>
<table style="margin-bottom:10pt;">
  <tr>
    ${H('NAME OF ENTITY/BUSINESS<br>ENTERPRISE','width:27%;')}
    ${H('BUSINESS ADDRESS','width:26%;')}
    ${H('NATURE OF BUSINESS<br>INTEREST &amp;/OR FINANCIAL<br>CONNECTION','width:28%;')}
    ${H('DATE OF ACQUISITION OF<br>INTEREST OR CONNECTION','width:19%;')}
  </tr>
  ${bizRows}
</table>

<div style="margin-bottom:2pt;">
  <div class="section-title">Relatives in the Government Service</div>
  <div style="font-size:6pt;text-align:center;font-style:italic;">(Within the Fourth Degree of Consanguinity or Affinity. Include also Bilas, Balae and Inso<sup>iv</sup>)</div>
</div>
<div style="font-size:7pt;margin-bottom:3pt;">
  <span style="display:inline-block;border:0.5pt solid #000;width:8pt;height:8pt;text-align:center;line-height:7.5pt;font-size:7pt;vertical-align:middle;">${cb(d.no_rel)}</span>
  &nbsp;<em>I/ We do not know of any relative/ s in the government service</em>
</div>
<table style="margin-bottom:10pt;">
  <tr>
    ${H('NAME OF RELATIVE','width:24%;')}
    ${H('RELATIONSHIP','width:16%;')}
    ${H('POSITION','width:24%;')}
    ${H('NAME OF AGENCY/OFFICE AND ADDRESS','width:36%;')}
  </tr>
  ${relRows}
</table>

<div style="font-size:7pt;line-height:1.8;text-align:justify;margin-bottom:8pt;padding:0 4pt;">
  &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;I hereby certify that these&nbsp; are true and correct statements&nbsp; of my assets,&nbsp; liabilities, net worth, business interests&nbsp; and financial connections, including those&nbsp; of my spouse and unmarried children below eighteen (18)&nbsp; years of age living in my household,&nbsp; and that to the best of my knowledge, the above-enumerated are names&nbsp; of my relatives&nbsp; in the government&nbsp; within the fourth civil degree of consanguinity or affinity.
</div>
<div style="font-size:7pt;line-height:1.8;text-align:justify;margin-bottom:14pt;padding:0 4pt;">
  &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;I hereby authorize the Ombudsman&nbsp; or his/her duly authorized representative to obtain and secure from all appropriate government agencies, including the Bureau of Internal Revenue such documents that may show my assets, liabilities, net worth, business interests and financial connections, to include those of my spouse and unmarried children below 18 years of age living with me&nbsp; in my household covering previous years to include the year&nbsp; I first&nbsp; assumed office in government.
</div>

<div style="font-size:7pt;margin-bottom:18pt;">
  Date:&nbsp;&nbsp;&nbsp;&nbsp;<span style="display:inline-block;border-bottom:0.5pt solid #000;min-width:150pt;">&nbsp;</span>
</div>

<table style="margin-bottom:8pt;">
  <tr>
    <td style="border:none;width:50%;padding:0 8pt 0 0;vertical-align:bottom;">
      <div style="border-top:0.5pt solid #000;padding-top:3pt;font-size:7pt;font-style:italic;text-align:center;">Signature of Declarant</div>
      <div style="margin-top:6pt;font-size:7pt;">Government Issued ID:&nbsp; <span style="display:inline-block;border-bottom:0.5pt solid #000;min-width:120pt;">&nbsp;</span></div>
      <div style="margin-top:4pt;font-size:7pt;">ID No.:&nbsp; <span style="display:inline-block;border-bottom:0.5pt solid #000;min-width:140pt;">${v(d.gov_id_no)}&nbsp;</span></div>
      <div style="margin-top:4pt;font-size:7pt;">Date Issued:&nbsp; <span style="display:inline-block;border-bottom:0.5pt solid #000;min-width:130pt;">${v(d.gov_id_issued)?fmtD(d.gov_id_issued):''}&nbsp;</span></div>
    </td>
    <td style="border:none;width:50%;padding:0 0 0 8pt;vertical-align:bottom;">
      <div style="border-top:0.5pt solid #000;padding-top:3pt;font-size:7pt;font-style:italic;text-align:center;">Signature of Declarant</div>
      <div style="margin-top:6pt;font-size:7pt;">Government Issued ID:&nbsp; <span style="display:inline-block;border-bottom:0.5pt solid #000;min-width:120pt;">&nbsp;</span></div>
      <div style="margin-top:4pt;font-size:7pt;">ID No.:&nbsp; <span style="display:inline-block;border-bottom:0.5pt solid #000;min-width:140pt;">&nbsp;</span></div>
      <div style="margin-top:4pt;font-size:7pt;">Date Issued:&nbsp; <span style="display:inline-block;border-bottom:0.5pt solid #000;min-width:130pt;">&nbsp;</span></div>
    </td>
  </tr>
</table>

<div style="font-size:7pt;line-height:1.8;margin-bottom:18pt;text-align:justify;">
  &nbsp;&nbsp;&nbsp;<strong>SUBSCRIBED AND SWORN</strong> to before me this <span style="display:inline-block;border-bottom:0.5pt solid #000;min-width:22pt;text-align:center;">&nbsp;</span> day of <span style="display:inline-block;border-bottom:0.5pt solid #000;min-width:40pt;">&nbsp;</span>, affiant exhibiting to me the above-stated government-issued identification card.
</div>

<div style="margin-top:4pt;width:44%;text-align:center;margin-left:auto;margin-right:auto;">
  <div style="border-top:0.5pt solid #000;padding-top:3pt;font-size:7pt;font-style:italic;">(Person Administering Oath)</div>
</div>

<div style="margin-top:18pt;border-top:0.5pt solid #000;padding-top:4pt;">
  <div style="font-size:5.5pt;line-height:1.7;">
    <sup>1</sup> Position, Agency, and Address shall only be declared if the spouse is a public official or employee.<br>
    <sup>ii</sup> Additional sheets may be used by the declarant, if necessary.<br>
    <sup>iii</sup> Capital or paraphernal assets, and liabilities of the declarant's spouse, and properties of children below 18 years of age and living in the declarant's household shall be disclosed using the additional sheets provided.<br>
    <sup>iv</sup> <em>Balae</em> refers to the parent of one's son or daughter-in-law; <em>Bilas</em> refers to a brother-in-law's wife or sister-in-law's husband; <em>Inso</em> refers to the appellation for the wife of an elder brother or male cousin.
  </div>
</div>

<div style="text-align:center;margin-top:8pt;font-size:7.5pt;font-style:italic;">Page 2 of ___</div>
${annexSections}
</body></html>`;

  if (openPrint) {
    const w = window.open('','_blank','width=900,height=1100');
    w.document.write(html);
    w.document.close();
    w.focus();
    setTimeout(()=>w.print(), 600);
  }
  return html;
}


// ── Init ───────────────────────────────────────────────────
document.getElementById('tbDate').textContent = new Date().toLocaleDateString('en-PH',{weekday:'short',month:'short',day:'numeric',year:'numeric'});
loadDepts();
loadEmployees(1);
// ════════════════════════════════════════════════════════
//  PDS PRINT — Official CS Form 212 (Revised 2025) Layout
// ════════════════════════════════════════════════════════

function printPDS(d, empRow) {
  const html = buildPDSPrintHTML(d, empRow);
  const w = window.open('', '_blank', 'width=960,height=1100');
  w.document.write(html);
  w.document.close();
  w.focus();
  setTimeout(() => w.print(), 600);
}

</script>
  <!-- ══ CHAT WIDGET ══ -->
  <style>
    /* ── Wrap & animation ── */
    .cw-wrap{position:fixed;bottom:24px;right:24px;width:420px;height:600px;background:#fff;border-radius:18px;box-shadow:0 20px 60px rgba(0,0,0,.28),0 0 1px rgba(0,0,0,.1);display:none;flex-direction:column;z-index:9999;overflow:hidden;font-family:'Nunito',sans-serif;backdrop-filter:blur(10px);}
        .cw-wrap.open{display:flex;animation:cwIn .3s cubic-bezier(.34,1.4,.64,1) both;}
        @keyframes cwIn{0%{opacity:0;transform:scale(.85) translateY(30px);filter:blur(6px);}100%{opacity:1;transform:scale(1) translateY(0);filter:blur(0);}}
        
        /* ──── HEADER ──── */
        .cw-hd{background:linear-gradient(135deg,#1e40af 0%,#2563eb 50%,#22c55e 100%);padding:16px 18px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;position:relative;overflow:hidden;}
        .cw-hd::before{content:'';position:absolute;inset:0;background:linear-gradient(90deg,transparent,rgba(255,255,255,.05),transparent);animation:shimmerAnim 3s infinite;}
        @keyframes shimmerAnim{0%{transform:translateX(-100%);}100%{transform:translateX(100%);}}
        .cw-hd-left{display:flex;align-items:center;gap:12px;position:relative;z-index:1;}
        .cw-hd-icon{width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:1.1rem;box-shadow:0 4px 12px rgba(0,0,0,.15),inset 0 1px 2px rgba(255,255,255,.3);}
        .cw-hd-title{font-size:.98rem;font-weight:800;color:#fff;letter-spacing:-.5px;}
        .cw-hd-sub{font-size:.62rem;color:rgba(255,255,255,.75);margin-top:2px;font-weight:500;}
        .cw-hd-close{width:32px;height:32px;border-radius:8px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);color:#fff;font-size:.9rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .2s;position:relative;z-index:1;}
        .cw-hd-close:hover{background:rgba(255,255,255,.25);transform:rotate(90deg);box-shadow:0 2px 8px rgba(0,0,0,.1);}
        .cw-hd-close:focus-visible{outline:2px solid #fff;outline-offset:2px;}
        
        /* ──── BODY LAYOUT ──── */
        .cw-body{display:flex;flex:1;overflow:hidden;background:#fafbfc;}
        .cw-contacts{width:130px;flex-shrink:0;border-right:1px solid #e5e7eb;display:flex;flex-direction:column;background:linear-gradient(to bottom,#ffffff,#f9fafb);overflow:hidden;}
        .cw-contacts-hd{padding:11px 10px;font-size:.56rem;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.12em;border-bottom:1px solid #e5e7eb;background:#ffffff;}
        .cw-srch{padding:8px;border-bottom:1px solid #e5e7eb;background:#fff;}
        .cw-srch input{width:100%;padding:6px 10px;border:1.5px solid #e5e7eb;border-radius:8px;font-family:'Nunito',sans-serif;font-size:.68rem;outline:none;color:#1e293b;background:#f3f4f6;transition:all .2s;font-weight:500;}
        .cw-srch input:focus{border-color:#2563eb;background:#fff;box-shadow:0 0 0 3px rgba(37,99,235,.1);}
        .cw-srch input::placeholder{color:#a1afc9;}
        .cw-clist{flex:1;overflow-y:auto;scrollbar-width:thin;scrollbar-color:#d1d5db transparent;padding:4px 0;}
        
        /* ──── CONTACT ITEMS ──── */
        .cw-ci{display:flex;flex-direction:column;align-items:center;padding:10px 6px;cursor:pointer;border:2px solid transparent;transition:all .2s ease;position:relative;text-align:center;border-radius:10px;margin:2px 4px;}
        .cw-ci:hover{background:#f0f4f8;border-color:#e5e7eb;}
        .cw-ci.active{background:linear-gradient(135deg,#dbeafe 0%,#eff6ff 100%);border-color:#2563eb;box-shadow:0 0 0 1px #2563eb inset,0 2px 6px rgba(37,99,235,.1);}
        .cw-av{width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:800;color:#fff;margin-bottom:4px;flex-shrink:0;box-shadow:0 2px 8px rgba(0,0,0,.12);border:2px solid rgba(255,255,255,.4);position:relative;}
        .cw-av::after{content:'';position:absolute;inset:0;border-radius:50%;background:radial-gradient(circle at 70% 30%,rgba(255,255,255,.3),transparent);}
        .cw-cn{font-size:.63rem;font-weight:700;color:#1e293b;line-height:1.2;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:112px;}
        .cw-cs{font-size:.54rem;color:#64748b;margin-top:2px;font-weight:500;}
        .cw-cu{position:absolute;top:2px;right:2px;background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;font-size:.48rem;font-weight:800;padding:2px 5px;border-radius:10px;min-width:16px;text-align:center;box-shadow:0 2px 4px rgba(0,0,0,.15);}
        .cw-cempty{padding:20px 8px;text-align:center;color:#94a3b8;font-size:.70rem;line-height:1.6;}
        
        /* ──── CHAT AREA ──── */
        .cw-chat{flex:1;display:flex;flex-direction:column;overflow:hidden;background:#fafbfc;}
        .cw-chat-hd{padding:12px 14px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;gap:10px;flex-shrink:0;background:linear-gradient(to bottom,#ffffff,#f9fafb);box-shadow:0 1px 3px rgba(0,0,0,.03);}
        .cw-chat-av{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.66rem;font-weight:800;color:#fff;flex-shrink:0;box-shadow:0 2px 8px rgba(0,0,0,.12);border:2px solid rgba(255,255,255,.4);}
        .cw-chat-name{font-size:.85rem;font-weight:800;color:#0f172a;letter-spacing:-.3px;}
        .cw-chat-sub{font-size:.60rem;color:#64748b;margin-top:2px;font-weight:500;}
        .cw-chat-del{width:28px;height:28px;border-radius:8px;border:1.5px solid #fecaca;background:#fff;color:#dc2626;display:none;align-items:center;justify-content:center;cursor:pointer;transition:all .15s;box-shadow:0 1px 4px rgba(0,0,0,.08);font-size:.82rem;}
    .cw-chat-del:hover{background:#fef2f2;border-color:#ef4444;box-shadow:0 2px 8px rgba(239,68,68,.18);}
    .cw-chat-del:focus-visible{outline:2px solid #ef4444;outline-offset:2px;}
    .cw-online{width:10px;height:10px;border-radius:50%;background:#22c55e;flex-shrink:0;box-shadow:0 0 0 3px #fff,0 0 0 4px #22c55e;animation:pulse 2s cubic-bezier(.4,0,.6,1) infinite;}
        @keyframes pulse{0%,100%{opacity:1;}50%{opacity:.8;}}
        
        /* ──── NO SELECTION STATE ──── */
        .cw-nosel{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#94a3b8;text-align:center;padding:24px;background:linear-gradient(135deg,#fafbfc 0%,#f0f4f8 100%);}
        .cw-nosel-ic{font-size:3.2rem;margin-bottom:12px;animation:float 3s ease-in-out infinite;}
        @keyframes float{0%,100%{transform:translateY(0px);}50%{transform:translateY(-8px);}}
        .cw-nosel h4{font-size:.88rem;font-weight:800;color:#334155;margin-bottom:6px;}
        .cw-nosel p{font-size:.74rem;line-height:1.6;color:#64748b;}
        
        /* ──── MESSAGE AREA ──── */
        .cw-msgs{flex:1;overflow-y:auto;padding:14px 12px;display:flex;flex-direction:column;gap:8px;background:#fafbfc;scrollbar-width:thin;scrollbar-color:#d1d5db transparent;}
        .cw-msgs::-webkit-scrollbar{width:6px;}
        .cw-msgs::-webkit-scrollbar-track{background:transparent;}
        .cw-msgs::-webkit-scrollbar-thumb{background:#d1d5db;border-radius:3px;}
        .cw-mrow{display:flex;align-items:flex-end;gap:6px;position:relative;margin-bottom:2px;animation:msgSlide .3s ease-out;}
        @keyframes msgSlide{from{opacity:0;transform:translateY(12px);filter:blur(3px);}to{opacity:1;transform:translateY(0);filter:blur(0);}}
        .cw-mrow.mine{flex-direction:row-reverse;}
        .cw-mrow:hover .cw-msg-actions{opacity:1;}
        .cw-mav{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.54rem;font-weight:800;color:#fff;flex-shrink:0;box-shadow:0 1px 4px rgba(0,0,0,.1);}
        .cw-msg-wrap{display:flex;flex-direction:column;max-width:80%;}
        .cw-mrow.mine .cw-msg-wrap{align-items:flex-end;}
        .cw-bubble{padding:10px 14px;border-radius:16px;font-size:.81rem;line-height:1.5;word-break:break-word;transition:all .2s;}
        .cw-mrow.mine .cw-bubble{background:linear-gradient(135deg,#2563eb 0%,#1e40af 100%);color:#fff;border-bottom-right-radius:3px;box-shadow:0 2px 8px rgba(37,99,235,.25);}
        .cw-mrow.mine .cw-bubble:hover{box-shadow:0 4px 12px rgba(37,99,235,.35);transform:translateY(-1px);}
        .cw-mrow.them .cw-bubble{background:#fff;color:#1e293b;border:1.5px solid #e5e7eb;border-bottom-left-radius:3px;box-shadow:0 1px 3px rgba(0,0,0,.05);}
        .cw-mrow.them .cw-bubble:hover{background:#f9fafb;border-color:#d1d5db;}
        .cw-bubble.edited::after{content:' ✎ edited';font-size:.54rem;opacity:.6;font-style:italic;font-weight:500;}
        .cw-bubble.unsent{background:#e5e7eb !important;color:#64748b !important;border:1.5px dashed #b4b8c0 !important;font-style:italic;box-shadow:none !important;opacity:.7;}
        .cw-mt{font-size:.56rem;color:#94a3b8;margin-top:4px;display:flex;align-items:center;gap:4px;font-weight:500;}
        .cw-mrow.mine .cw-mt{justify-content:flex-end;}
        .cw-seen{color:#60a5fa;font-size:.60rem;font-weight:600;}
        
        /* ──── MESSAGE ACTIONS ──── */
        .cw-msg-actions{position:absolute;top:-34px;display:flex;gap:4px;opacity:0;transition:opacity .15s;z-index:10;}
        .cw-mrow.mine .cw-msg-actions{right:0;}
        .cw-mrow.them .cw-msg-actions{left:0;}
        .cw-act-btn{width:28px;height:28px;border-radius:8px;background:#fff;border:1.5px solid #e5e7eb;display:flex;align-items:center;justify-content:center;font-size:.74rem;cursor:pointer;transition:all .15s;box-shadow:0 2px 6px rgba(0,0,0,.1);font-weight:600;}
        .cw-act-btn:hover{background:#eff6ff;border-color:#2563eb;box-shadow:0 2px 8px rgba(37,99,235,.2);transform:scale(1.05);}
        .cw-act-btn:focus-visible{outline:2px solid #2563eb;outline-offset:2px;}
        .cw-act-btn.del:hover{background:#fee2e2;border-color:#ef4444;box-shadow:0 2px 8px rgba(239,68,68,.2);}
        
        /* ──── EDIT BAR ──── */
        .cw-edit-bar{display:none;padding:8px 12px;background:linear-gradient(135deg,#eff6ff,#dbeafe);border-top:2px solid #bfdbfe;border-bottom:1px solid #bfdbfe;font-size:.74rem;color:#1e40af;font-weight:700;align-items:center;gap:8px;flex-shrink:0;}
        .cw-edit-bar.show{display:flex;animation:slideDown .2s ease-out;}
        @keyframes slideDown{from{opacity:0;transform:translateY(-8px);}to{opacity:1;transform:translateY(0);}}
        .cw-edit-cancel{margin-left:auto;background:none;border:none;color:#64748b;cursor:pointer;font-size:.82rem;font-weight:600;transition:color .2s;}
        .cw-edit-cancel:hover{color:#ef4444;}
        .cw-ddiv{text-align:center;font-size:.57rem;color:#94a3b8;font-weight:700;padding:6px 0;position:relative;margin:4px 0;}
        .cw-ddiv::before{content:'';position:absolute;top:50%;left:0;right:0;height:1px;background:linear-gradient(90deg,transparent,#e5e7eb,transparent);}
        .cw-ddiv span{background:#fafbfc;padding:0 10px;position:relative;display:inline-block;}
        
        /* ──── INPUT AREA ──── */
        .cw-inp-row{display:flex;gap:10px;padding:12px 12px;background:#fff;border-top:1px solid #e5e7eb;align-items:flex-end;flex-shrink:0;box-shadow:0 -1px 3px rgba(0,0,0,.02);}
        .cw-inp{flex:1;padding:10px 14px;border:1.5px solid #e5e7eb;border-radius:20px;font-family:'Nunito',sans-serif;font-size:.83rem;color:#1e293b;resize:none;outline:none;max-height:90px;line-height:1.5;transition:all .2s;background:#f9fafb;font-weight:500;}
        .cw-inp:focus{border-color:#2563eb;background:#fff;box-shadow:0 0 0 3px rgba(37,99,235,.1);}
        .cw-inp:disabled{background:#f3f4f6;color:#94a3b8;cursor:not-allowed;}
        .cw-inp::placeholder{color:#94a3b8;}
        .cw-sendbtn{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#2563eb,#1e40af);color:#fff;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0;transition:all .2s;box-shadow:0 4px 12px rgba(37,99,235,.35);font-weight:600;position:relative;overflow:hidden;}
        .cw-sendbtn::before{content:'';position:absolute;inset:0;background:radial-gradient(circle at 30% 30%,rgba(255,255,255,.3),transparent);opacity:0;}
        .cw-sendbtn:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(37,99,235,.45);}
        .cw-sendbtn:active{transform:none;box-shadow:0 2px 8px rgba(37,99,235,.25);}
        .cw-sendbtn:focus-visible{outline:2px solid #2563eb;outline-offset:3px;}
        .cw-sendbtn:disabled{opacity:.5;cursor:not-allowed;transform:none;box-shadow:none;}
    
        /* ════════════════════════════════════════════════════════
           RESPONSIVE DESIGN FOR CHAT WIDGET
           ════════════════════════════════════════════════════════ */
    
        /* Tablet Landscape (1280px and below) */
        @media (max-width:1280px){
          .cw-wrap{width:380px;height:560px;}
          .cw-contacts{width:120px;}
          .cw-av{width:36px;height:36px;font-size:.66rem;}
          .cw-cn{font-size:.61rem;max-width:110px;}
          .cw-cs{font-size:.54rem;max-width:110px;}
          .cw-chat-name{font-size:.81rem;}
          .cw-chat-sub{font-size:.59rem;}
        }
    
        /* Tablet Portrait & iPad (768px and below) */
        @media (max-width:768px){
          .cw-wrap{width:340px;height:520px;bottom:16px;right:16px;box-shadow:0 16px 48px rgba(0,0,0,.25);}
          .cw-contacts{width:110px;}
          .cw-contacts-hd{font-size:.55rem;padding:10px 8px;}
          .cw-av{width:32px;height:32px;font-size:.60rem;margin-bottom:3px;}
          .cw-cn{font-size:.59rem;max-width:100px;}
          .cw-cs{font-size:.53rem;max-width:100px;}
          .cw-hd{padding:14px 14px;}
          .cw-hd-title{font-size:.92rem;}
          .cw-hd-sub{font-size:.60rem;}
          .cw-hd-icon{width:36px;height:36px;font-size:1rem;}
          .cw-hd-close{width:30px;height:30px;font-size:.85rem;}
          .cw-chat-hd{padding:10px 12px;gap:8px;}
          .cw-chat-av{width:32px;height:32px;font-size:.60rem;}
          .cw-chat-name{font-size:.79rem;}
          .cw-chat-sub{font-size:.58rem;}
          .cw-msgs{padding:11px 9px;gap:6px;}
          .cw-mav{width:22px;height:22px;font-size:.50rem;}
          .cw-bubble{padding:9px 12px;font-size:.77rem;}
          .cw-inp-row{padding:9px 10px;gap:8px;}
          .cw-inp{font-size:.78rem;padding:8px 12px;}
          .cw-sendbtn{width:36px;height:36px;font-size:.88rem;}
          .cw-edit-bar{padding:7px 10px;font-size:.70rem;}
          .cw-act-btn{width:26px;height:26px;font-size:.70rem;}
          .cw-srch input{font-size:.67rem;padding:5px 9px;}
        }
    
        /* Large Phones (600px and below) */
        @media (max-width:600px){
          .cw-wrap{width:100%;height:100%;max-width:100vw;max-height:100vh;bottom:0;right:0;border-radius:0;position:fixed !important;box-shadow:none;}
          .cw-wrap.open{animation:cwInMobile .32s cubic-bezier(.34,1.4,.64,1) both;}
          @keyframes cwInMobile{0%{opacity:0;transform:translateY(100%);filter:blur(8px);}100%{opacity:1;transform:translateY(0);filter:blur(0);}}
          .cw-body{flex-direction:column-reverse;}
          .cw-contacts{width:100%;height:auto;border-right:none;border-top:1px solid #e5e7eb;flex-shrink:0;max-height:48vh;background:#fff;}
          .cw-contacts-hd{display:none;}
          .cw-srch{display:none;}
          .cw-clist{max-height:48vh;display:flex;gap:8px;padding:8px;overflow-x:auto;overflow-y:hidden;border-bottom:1px solid #e5e7eb;background:#fff;scroll-behavior:smooth;}
          .cw-clist::-webkit-scrollbar{height:4px;}
          .cw-clist::-webkit-scrollbar-track{background:transparent;}
          .cw-clist::-webkit-scrollbar-thumb{background:#d1d5db;border-radius:2px;}
          .cw-ci{flex:none;width:72px;height:auto;padding:8px 4px;border:none;border-radius:10px;background:#f3f4f6;flex-shrink:0;}
          .cw-ci:hover{background:#eff6ff;}
          .cw-ci.active{background:#dbeafe;border:none;border-top:3px solid #2563eb;box-shadow:0 0 0 1px #2563eb inset;}
          .cw-av{width:42px;height:42px;font-size:.70rem;margin-bottom:5px;}
          .cw-cn{font-size:.63rem;max-width:68px;overflow:visible;white-space:normal;margin-bottom:2px;}
          .cw-cs{display:none;}
          .cw-nosel{padding:16px;}
          .cw-nosel-ic{font-size:2.4rem;margin-bottom:10px;}
          .cw-nosel h4{font-size:.82rem;}
          .cw-nosel p{font-size:.70rem;}
          .cw-hd{padding:12px 14px;gap:10px;}
          .cw-hd-left{gap:10px;}
          .cw-hd-icon{width:36px;height:36px;font-size:.95rem;}
          .cw-hd-title{font-size:.90rem;}
          .cw-hd-sub{font-size:.59rem;}
          .cw-hd-close{width:32px;height:32px;font-size:.88rem;}
          .cw-chat-hd{padding:10px 12px;gap:8px;}
          .cw-chat-av{width:32px;height:32px;font-size:.60rem;}
          .cw-chat-name{font-size:.80rem;}
          .cw-chat-sub{font-size:.58rem;}
          .cw-online{width:10px;height:10px;}
          .cw-msgs{padding:10px 8px;gap:6px;flex:1;overflow-y:auto;}
          .cw-mrow{gap:5px;margin-bottom:1px;}
          .cw-mav{width:20px;height:20px;font-size:.48rem;}
          .cw-msg-wrap{max-width:85%;}
          .cw-bubble{padding:8px 11px;font-size:.75rem;line-height:1.4;}
          .cw-mt{font-size:.55rem;margin-top:3px;}
          .cw-msg-actions{top:-30px;gap:3px;}
          .cw-act-btn{width:24px;height:24px;font-size:.66rem;}
          .cw-ddiv{font-size:.55rem;padding:4px 0;margin:2px 0;}
          .cw-edit-bar{padding:6px 10px;font-size:.68rem;gap:6px;}
          .cw-inp-row{padding:8px 10px;gap:8px;min-height:50px;}
          .cw-inp{padding:8px 11px;font-size:.76rem;max-height:80px;min-height:36px;}
          .cw-sendbtn{width:38px;height:38px;font-size:.86rem;min-width:38px;min-height:38px;}
        }
    
        /* Standard Phones (480px and below) */
        @media (max-width:480px){
          .cw-clist{max-height:42vh;padding:6px;}
          .cw-ci{width:64px;padding:6px 3px;}
          .cw-av{width:38px;height:38px;font-size:.64rem;}
          .cw-cn{font-size:.60rem;max-width:60px;}
          .cw-msgs{padding:8px 6px;gap:5px;}
          .cw-bubble{padding:7px 10px;font-size:.73rem;}
          .cw-mav{width:18px;height:18px;font-size:.44rem;}
          .cw-inp-row{padding:7px 8px;min-height:46px;}
          .cw-inp{padding:7px 10px;font-size:.74rem;min-height:34px;}
          .cw-sendbtn{width:36px;height:36px;font-size:.82rem;min-width:36px;min-height:36px;}
          .cw-chat-hd{padding:9px 11px;}
          .cw-chat-av{width:30px;height:30px;font-size:.56rem;}
          .cw-chat-name{font-size:.76rem;}
          .cw-chat-sub{font-size:.55rem;}
          .cw-hd-icon{width:32px;height:32px;font-size:.90rem;}
          .cw-hd-title{font-size:.86rem;}
          .cw-msg-wrap{max-width:88%;}
          .cw-nosel-ic{font-size:2rem;}
        }
    
        /* Extra Small Phones (380px and below) */
        @media (max-width:380px){
          .cw-clist{max-height:40vh;padding:5px;}
          .cw-ci{width:58px;padding:5px 2px;}
          .cw-av{width:34px;height:34px;font-size:.58rem;}
          .cw-cn{font-size:.56rem;max-width:54px;}
          .cw-bubble{padding:6px 9px;font-size:.70rem;line-height:1.3;}
          .cw-mt{font-size:.53rem;margin-top:2px;}
          .cw-inp{font-size:.72rem;padding:6px 10px;}
          .cw-sendbtn{width:34px;height:34px;font-size:.80rem;min-width:34px;min-height:34px;}
          .cw-chat-hd{padding:8px 10px;gap:6px;}
          .cw-chat-av{width:28px;height:28px;font-size:.52rem;}
          .cw-chat-name{font-size:.72rem;}
          .cw-chat-sub{font-size:.52rem;}
          .cw-nosel{padding:12px;}
          .cw-msg-wrap{max-width:90%;}
          .cw-hd{padding:10px 12px;}
          .cw-hd-close{width:28px;height:28px;font-size:.82rem;}
        }
    
        /* ════════════════════════════════════════════════════════
           TOUCH DEVICE OPTIMIZATIONS
           ════════════════════════════════════════════════════════ */
        @media (hover:none) and (pointer:coarse){
          .cw-ci:hover{background:#f3f4f6;}
          .cw-ci.active:hover{background:#dbeafe;}
          .cw-act-btn:hover{background:#fff;border-color:#e5e7eb;transform:none;}
          .cw-sendbtn:hover{transform:none;}
          .cw-sendbtn:active{transform:scale(.92);}
          .cw-mrow:hover .cw-msg-actions{opacity:0.8;}
          .cw-inp:focus{background:#fff;}
          .cw-hd-close{min-height:44px;min-width:44px;}
          .cw-sendbtn{min-height:44px;min-width:44px;}
          .cw-inp{font-size:16px;padding:12px 14px;}
        }
    
        /* ════════════════════════════════════════════════════════
           ACCESSIBILITY - KEYBOARD NAVIGATION
           ════════════════════════════════════════════════════════ */
        .cw-ci:focus-visible,.cw-clist:focus-visible{outline:2px solid #2563eb;outline-offset:2px;}
        .cw-act-btn:focus-visible{outline:2px solid #2563eb;outline-offset:1px;}
        .cw-inp:focus-visible{outline:none;}
        @media (prefers-reduced-motion:reduce){
          .cw-wrap.open,.cw-edit-bar.show,.cw-mrow,.cw-act-btn,.cw-sendbtn,.cw-hd-close{animation:none;transition:none;}
          .cw-hd::before{animation:none;}
          .cw-online{animation:none;}
          .cw-nosel-ic{animation:none;}
        }

  
    .notif-wrap{position:relative;display:inline-block;}
    .notif-btn{position:relative;width:38px;height:38px;border-radius:50%;border:1px solid rgba(255,255,255,.25);background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:1rem;color:#fff;transition:all .2s;}
    .notif-btn:hover{background:rgba(255,255,255,.28);}
    .notif-count{position:absolute;top:-4px;right:-4px;min-width:18px;height:18px;background:#ef4444;border-radius:20px;font-size:.58rem;color:#fff;font-weight:800;display:none;align-items:center;justify-content:center;border:2px solid transparent;padding:0 4px;}
    .notif-count.show{display:flex;}
    .notif-panel{display:none;position:absolute;top:calc(100% + 10px);right:0;background:#fff;border-radius:14px;box-shadow:0 8px 32px rgba(0,0,0,.18);width:340px;max-height:480px;z-index:9999;overflow:hidden;flex-direction:column;}
    .notif-panel.open{display:flex;}
    .notif-hd{padding:14px 16px 10px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;background:#f8fafc;flex-shrink:0;}
    .notif-hd-title{font-size:.88rem;font-weight:800;color:#1e293b;}
    .notif-hd-count{font-size:.72rem;font-weight:700;color:#64748b;}
    .notif-mark-all{font-size:.72rem;font-weight:700;color:#2563eb;cursor:pointer;border:none;background:none;font-family:inherit;}
    .notif-mark-all:hover{text-decoration:underline;}
    .notif-list{overflow-y:auto;flex:1;}
    .notif-item{display:flex;align-items:flex-start;gap:11px;padding:12px 16px;border-bottom:1px solid #f8fafc;cursor:pointer;transition:background .15s;text-decoration:none;}
    .notif-item:hover{background:#f8fafc;}
    .notif-item.unread{background:#eff6ff;}
    .notif-item.unread:hover{background:#dbeafe;}
    .notif-ico{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;}
    .notif-body{flex:1;min-width:0;}
    .notif-title{font-size:.80rem;font-weight:800;color:#1e293b;margin-bottom:2px;}
    .notif-text{font-size:.74rem;color:#475569;line-height:1.4;}
    .notif-time{font-size:.66rem;color:#94a3b8;margin-top:3px;}
    .notif-empty{text-align:center;padding:40px 20px;color:#94a3b8;}
    .notif-empty .ne-icon{font-size:2.2rem;margin-bottom:8px;}
    .notif-empty p{font-size:.82rem;font-weight:600;}
    .notif-footer{padding:10px 16px;border-top:1px solid #f1f5f9;text-align:center;flex-shrink:0;}
    .notif-footer a{font-size:.78rem;font-weight:700;color:#2563eb;text-decoration:none;}
  </style>

  <div class="cw-wrap" id="cwWrap">
    <!-- Header -->
    <div class="cw-hd">
      <div class="cw-hd-left">
        <div class="cw-hd-icon">&#x1F4AC;</div>
        <div>
          <div class="cw-hd-title">Messages</div>
          <div class="cw-hd-sub" id="cwHdSub">Select someone to chat</div>
        </div>
      </div>
      <button class="cw-hd-close" onclick="cwToggle()" title="Close">&#x2715;</button>
    </div>
    <div class="cw-body">
      <!-- Contacts -->
      <div class="cw-contacts">
        <div class="cw-contacts-hd">Employees</div>
        <div class="cw-srch"><input type="text" id="cwSrch" placeholder="&#x1F50D; Search..." oninput="cwFilter()"/></div>
        <div class="cw-clist" id="cwClist"><div class="cw-cempty">Loading...</div></div>
      </div>
      <!-- Chat -->
      <div class="cw-chat">
        <div class="cw-nosel" id="cwNoSel">
          <div class="cw-nosel-ic">&#x1F4AC;</div>
          <h4>No conversation selected</h4>
          <p>Pick an employee from<br>the list to start chatting</p>
        </div>
        <div id="cwChatArea" style="display:none;flex:1;flex-direction:column;overflow:hidden;">
          <!-- Chat header -->
          <div class="cw-chat-hd">
            <div class="cw-chat-av" id="cwChatAv"></div>
            <div style="flex:1;min-width:0;">
              <div class="cw-chat-name" id="cwChatName"></div>
              <div class="cw-chat-sub" id="cwChatSub"></div>
            </div>
            <button class="cw-chat-del" id="cwChatDelBtn" onclick="cwDeleteConversation()" title="Delete conversation">&#x1F5D1;&#xFE0F;</button>
            <div class="cw-online" title="Active"></div>
          </div>
          <!-- Messages -->
          <div class="cw-msgs" id="cwMsgs"></div>
          <!-- Edit bar -->
          <div class="cw-edit-bar" id="cwEditBar">
            <span>&#x270F;&#xFE0F; Editing message</span>
            <button class="cw-edit-cancel" onclick="cwCancelEdit()">&#x2715; Cancel</button>
          </div>
          <!-- Input -->
          <div class="cw-inp-row">
            <textarea class="cw-inp" id="cwInp" rows="1" placeholder="Type a message..." onkeydown="cwKey(event)" oninput="cwResize(this)"></textarea>
            <button class="cw-sendbtn" id="cwSendBtn" onclick="cwSend()" title="Send">&#x27A4;</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
  var CW_ME   = <?php echo (int)($_SESSION['user_id'] ?? 0); ?>;
  var CW_INIT = <?php echo json_encode(strtoupper(substr($adminName ?? 'AD', 0, 2))); ?>;
  var CW_API  = 'chat_api.php';
  var CW_COLS = ['#2563eb','#22c55e','#f59e0b','#ef4444','#a855f7','#06b6d4','#ec4899','#f97316'];

  var cwCons=[], cwAct=null, cwLastT=null, cwPoll=null, cwConT=null, cwOpen=false;
  var cwSending=false, cwEditId=null;

  function cwCol(n){return CW_COLS[Math.abs([...(n||'?')].reduce(function(a,c){return a+c.charCodeAt(0);},0))%CW_COLS.length];}
  function cwIni(n){return (n||'?').split(' ').map(function(w){return w[0]||'';}).join('').slice(0,2).toUpperCase();}
  function cwFmtS(dt){if(!dt)return '';var d=new Date(dt),now=new Date();return d.toDateString()===now.toDateString()?d.toLocaleTimeString('en-PH',{hour:'numeric',minute:'2-digit'}):d.toLocaleDateString('en-PH',{month:'short',day:'numeric'});}
  function cwFmtL(dt){return dt?new Date(dt).toLocaleTimeString('en-PH',{hour:'numeric',minute:'2-digit'}):'';}
  function cwEsc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');}

  
// ── Poll admin notifications every 60 seconds ──────────────────────────────
async function pollAdminNotifications() {
  try {
    const r = await fetch('notif_poll.php').then(r=>r.json()).catch(()=>null);
    if (!r || !r.success) return;
    const read   = getReadNotifs();
    const notifs = r.notifications || [];
    const list = document.getElementById('adminNotifList');
    if (list) {
      if (!notifs.length) {
        list.innerHTML = '<div class="notif-empty"><div class="ne-icon">🔔</div><p>All caught up!</p></div>';
      } else {
        list.innerHTML = notifs.map(n => {
          const isRead = read.includes(n.nid);
          return `<a class="notif-item ${isRead?'':'unread'}" href="${n.link}" data-nid="${n.nid}" onclick="markNotifRead(this.getAttribute('data-nid'));applyReadState();">
            <div class="notif-ico" style="background:${n.color}22;">${n.icon}</div>
            <div class="notif-body">
              <div class="notif-title">${n.title}</div>
              <div class="notif-text">${n.body}</div>
              ${n.time ? '<div class="notif-time">'+adminFmtTime(n.time)+'</div>' : ''}
            </div>
          </a>`;
        }).join('');
      }
    }
    const unread = notifs.filter(n => !read.includes(n.nid)).length;
    const cnt = document.getElementById('adminNotifCount');
    const hd  = document.getElementById('adminNotifHdCount');
    if (cnt) { cnt.textContent = unread||''; unread ? cnt.classList.add('show') : cnt.classList.remove('show'); }
    if (hd)  { hd.textContent = notifs.length + ' item' + (notifs.length!==1?'s':''); }
  } catch(e) {}
}
function adminFmtTime(dt) {
  if (!dt) return '';
  try {
    const d = Math.floor((Date.now() - new Date(dt).getTime()) / 1000);
    if (d < 60) return 'Just now';
    if (d < 3600) return Math.floor(d/60) + 'm ago';
    if (d < 86400) return Math.floor(d/3600) + 'h ago';
    return new Date(dt).toLocaleDateString('en-PH',{month:'short',day:'numeric'});
  } catch(e) { return ''; }
}
document.addEventListener('DOMContentLoaded', function() {
  setTimeout(pollAdminNotifications, 5000);
  setInterval(pollAdminNotifications, 60000);
});

function cwToggle(){
    cwOpen=!cwOpen;
    document.getElementById('cwWrap').classList.toggle('open',cwOpen);
    if(cwOpen){cwLoadCons();clearInterval(cwConT);cwConT=setInterval(cwLoadCons,10000);}
    else{clearInterval(cwPoll);clearInterval(cwConT);}
  }

  function cwLoadCons(){
    fetch(CW_API+'?action=contacts')
      .then(function(r){return r.json();})
      .then(function(j){if(!j.success)return;cwCons=j.data;cwRenderCons(cwCons);cwUpdBadge();})
      .catch(function(){});
  }

  function cwRenderCons(list){
    var el=document.getElementById('cwClist');
    if(!list.length){el.innerHTML='<div class="cw-cempty">No employees found.</div>';return;}
    el.innerHTML=list.map(function(c){
      var isAct=cwAct&&cwAct.user_id==c.user_id;
      var name=c.full_name||c.email||'Employee';
      var lastPrev=c.last_message?'<div class="cw-cs" style="font-style:italic;">'+(c.last_mine?'You: ':'')+c.last_message+'</div>':'';
      return '<div class="cw-ci'+(isAct?' active':'')+'" onclick="cwOpenChat('+c.user_id+')">'+
        '<div class="cw-av" style="background:'+cwCol(name)+'">'+cwIni(name)+'</div>'+
        '<div class="cw-cn" title="'+name+'">'+name+'</div>'+
        '<div class="cw-cs">'+cwFmtS(c.last_at)+'</div>'+
        lastPrev+
        (c.unread>0?'<div class="cw-cu">'+c.unread+'</div>':'')+
      '</div>';
    }).join('');
  }

  function cwFilter(){
    var q=document.getElementById('cwSrch').value.toLowerCase();
    cwRenderCons(q?cwCons.filter(function(c){return (c.full_name||c.email||'').toLowerCase().indexOf(q)>=0;}):cwCons);
  }

  function cwUpdBadge(){
    var total=cwCons.reduce(function(s,c){return s+(c.unread||0);},0);
    var b=document.getElementById('cwBadge');
    b.textContent=total||'';b.style.display=total?'flex':'none';
  }

  function cwOpenChat(userId){
    cwAct=cwCons.find(function(c){return c.user_id==userId;})||{user_id:userId};
    cwLastT=null;cwRenderCons(cwCons);cwCancelEdit();
    document.getElementById('cwNoSel').style.display='none';
    var area=document.getElementById('cwChatArea');
    area.style.display='flex';area.style.flex='1';area.style.flexDirection='column';area.style.overflow='hidden';
    var av=document.getElementById('cwChatAv');
    var name=cwAct.full_name||cwAct.email||'Employee';
    av.textContent=cwIni(name);av.style.background=cwCol(name);
    document.getElementById('cwChatName').textContent=name;
    document.getElementById('cwChatSub').textContent=cwAct.position_title||cwAct.department||'Employee';
    document.getElementById('cwHdSub').textContent=name;
    var delBtn=document.getElementById('cwChatDelBtn');
    if(delBtn)delBtn.style.display='inline-flex';
    document.getElementById('cwMsgs').innerHTML='<div style="text-align:center;padding:24px;font-size:.73rem;color:#94a3b8;">Loading...</div>';
    cwLoadMsgs(true);
    document.getElementById('cwInp').focus();
    clearInterval(cwPoll);cwPoll=setInterval(function(){cwLoadMsgs(false);},3000);
  }

  function cwLoadMsgs(initial){
    if(!cwAct)return;
    var url=CW_API+'?action=messages&with='+cwAct.user_id;
    if(!initial&&cwLastT)url+='&since_id='+cwLastT;
    fetch(url)
      .then(function(r){return r.json();})
      .then(function(j){
        if(!j.success)return;
        if(initial){
          cwRenderMsgs(j.data);
          cwLastT=j.data&&j.data.length?j.data[j.data.length-1].message_id:0;
        } else if(j.data&&j.data.length){
          cwAppendMsgs(j.data);
          cwLastT=j.data[j.data.length-1].message_id;
          cwLoadCons();
        }
        // Update seen indicators on existing bubbles
        if(initial&&j.data){
          j.data.forEach(function(m){
            if(parseInt(m.sender_id)===CW_ME&&(m.is_read==1||m.is_read==='1')){
              cwMarkSeen(m.message_id);
            }
          });
        }
      }).catch(function(){});
  }

  function cwMarkSeen(msgId){
    var row=document.querySelector('.cw-mrow[data-msgid="'+msgId+'"]');
    if(!row)return;
    var mt=row.querySelector('.cw-mt');
    if(mt&&!mt.querySelector('.cw-seen')){
      var s=document.createElement('span');
      s.className='cw-seen';s.innerHTML='&#x2713;&#x2713; Seen';
      mt.appendChild(s);
    }
  }

  function cwRenderMsgs(msgs){
    var el=document.getElementById('cwMsgs');
    if(!msgs||!msgs.length){el.innerHTML='<div style="text-align:center;padding:28px 16px;font-size:.75rem;color:#94a3b8;line-height:1.7;">No messages yet.<br>Say hello! &#x1F44B;</div>';return;}
    var html='',ld='';
    msgs.forEach(function(m){
      var ds=new Date(m.sent_at).toLocaleDateString('en-PH',{weekday:'short',month:'short',day:'numeric'});
      if(ds!==ld){html+='<div class="cw-ddiv"><span>'+ds+'</span></div>';ld=ds;}
      html+=cwBuildMsg(m);
    });
    el.innerHTML=html;el.scrollTop=el.scrollHeight;
  }

  function cwAppendMsgs(msgs){
    var el=document.getElementById('cwMsgs');
    var html='';msgs.forEach(function(m){html+=cwBuildMsg(m);});
    el.insertAdjacentHTML('beforeend',html);el.scrollTop=el.scrollHeight;
  }

  function cwBuildMsg(m){
    var mine=parseInt(m.sender_id)===CW_ME;
    var name=cwAct?cwAct.full_name||cwAct.email||'':'?';
    var unsent=(m.is_unsent==1||m.is_unsent==='1');
    var edited =(m.is_edited==1||m.is_edited==='1')&&!unsent;
    var seen   =(mine&&!unsent&&(m.is_read==1||m.is_read==='1'));
    var bubbleCls='cw-bubble'+(unsent?' unsent':'')+(edited?' edited':'');
    var msgText=unsent?'You unsent a message':cwEsc(m.message);

    var actions='';
    if(mine&&!unsent){
      actions='<div class="cw-msg-actions">'+
        '<button class="cw-act-btn" onclick="cwStartEdit('+m.message_id+',this)" title="Edit">&#x270F;&#xFE0F;</button>'+
        '<button class="cw-act-btn del" onclick="cwUnsend('+m.message_id+')" title="Unsend">&#x1F5D1;&#xFE0F;</button>'+
      '</div>';
    }

    return '<div class="cw-mrow '+(mine?'mine':'them')+'" data-msgid="'+m.message_id+'">'+
      actions+
      '<div class="cw-mav" style="background:'+(mine?'#2563eb':cwCol(name))+'">'+( mine?CW_INIT:cwIni(name))+'</div>'+
      '<div class="cw-msg-wrap">'+
        '<div class="'+bubbleCls+'" data-raw="'+cwEsc(m.message)+'">'+msgText+'</div>'+
        '<div class="cw-mt">'+cwFmtL(m.sent_at)+(seen?'<span class="cw-seen">&#x2713;&#x2713; Seen</span>':'')+'</div>'+
      '</div>'+
    '</div>';
  }

  // ── Send ─────────────────────────────────────────────────────
  function cwSend(){
    if(!cwAct||cwSending)return;
    var inp=document.getElementById('cwInp'),msg=inp.value.trim();if(!msg)return;
    // Edit mode
    if(cwEditId){cwSubmitEdit(cwEditId,msg);return;}
    cwSending=true;
    var btn=document.getElementById('cwSendBtn');btn.disabled=true;
    var sentMsg=msg;inp.value='';cwResize(inp);
    var fd=new FormData();fd.append('action','send');fd.append('to',cwAct.user_id);fd.append('message',sentMsg);fd.append('csrf_token',CSRF_TOKEN);
    fd.append('csrf_token', CSRF_TOKEN);
    fetch(CW_API,{method:'POST',body:fd})
      .then(function(r){return r.json();})
      .then(function(j){
        if(j.success){cwAppendMsgs([j.data]);cwLastT=j.data.message_id;cwLoadCons();}
      })
      .catch(function(){})
      .finally(function(){cwSending=false;btn.disabled=false;inp.focus();});
  }

  // ── Edit ─────────────────────────────────────────────────────
  function cwStartEdit(msgId,btn){
    var row=document.querySelector('.cw-mrow[data-msgid="'+msgId+'"]');
    if(!row)return;
    var bubble=row.querySelector('.cw-bubble');
    if(!bubble)return;
    cwEditId=msgId;
    var raw=bubble.getAttribute('data-raw')||bubble.textContent;
    var inp=document.getElementById('cwInp');
    inp.value=raw.replace(/<br>/g,'\n');
    cwResize(inp);inp.focus();
    document.getElementById('cwEditBar').classList.add('show');
    document.getElementById('cwSendBtn').innerHTML='&#x2713;';
    // Highlight the bubble being edited
    document.querySelectorAll('.cw-bubble').forEach(function(b){b.style.opacity='0.5';});
    bubble.style.opacity='1';bubble.style.outline='2px solid #2563eb';bubble.style.outlineOffset='2px';
  }

  function cwSubmitEdit(msgId,newMsg){
    cwSending=true;
    var btn=document.getElementById('cwSendBtn');btn.disabled=true;
    var fd=new FormData();fd.append('action','edit');fd.append('message_id',msgId);fd.append('message',newMsg);fd.append('csrf_token',CSRF_TOKEN);
    fd.append('csrf_token', CSRF_TOKEN);
    fetch(CW_API,{method:'POST',body:fd})
      .then(function(r){return r.json();})
      .then(function(j){
        if(j.success){
          var row=document.querySelector('.cw-mrow[data-msgid="'+msgId+'"]');
          if(row){
            var bubble=row.querySelector('.cw-bubble');
            if(bubble){bubble.innerHTML=cwEsc(newMsg);bubble.setAttribute('data-raw',newMsg);bubble.classList.add('edited');}
          }
          cwCancelEdit();
        }
      })
      .catch(function(){})
      .finally(function(){cwSending=false;btn.disabled=false;});
  }

  function cwCancelEdit(){
    cwEditId=null;
    document.getElementById('cwEditBar').classList.remove('show');
    document.getElementById('cwSendBtn').innerHTML='&#x27A4;';
    document.getElementById('cwInp').value='';
    document.querySelectorAll('.cw-bubble').forEach(function(b){b.style.opacity='';b.style.outline='';b.style.outlineOffset='';});
  }

  // ── Unsend ───────────────────────────────────────────────────
  function cwUnsend(msgId){
    if(!confirm('Unsend this message? It will be removed for everyone.'))return;
    var fd=new FormData();fd.append('action','unsend');fd.append('message_id',msgId);fd.append('csrf_token',CSRF_TOKEN);
    fd.append('csrf_token', CSRF_TOKEN);
    fetch(CW_API,{method:'POST',body:fd})
      .then(function(r){return r.json();})
      .then(function(j){
        if(j.success){
          var row=document.querySelector('.cw-mrow[data-msgid="'+msgId+'"]');
          if(row){
            var bubble=row.querySelector('.cw-bubble');
            if(bubble){
              bubble.innerHTML='You unsent a message';
              bubble.className='cw-bubble unsent';
              bubble.removeAttribute('data-raw');
            }
            var actions=row.querySelector('.cw-msg-actions');
            if(actions)actions.remove();
          }
          cwLoadCons();
        }
      }).catch(function(){});
  }

  
  function cwDeleteConversation(){
    if(!cwAct||!cwAct.user_id)return;
    var name=cwAct.full_name||cwAct.email||'this employee';
    if(!confirm('Delete entire conversation with '+name+'? This cannot be undone.'))return;
    var fd=new FormData();fd.append('action','delete_conversation');fd.append('with',cwAct.user_id);
    fd.append('csrf_token', CSRF_TOKEN);
    fetch(CW_API,{method:'POST',body:fd})
      .then(function(r){return r.json();})
      .then(function(j){
        if(!j.success){alert(j.message||'Failed to delete conversation.');return;}
        cwCancelEdit();
        document.getElementById('cwMsgs').innerHTML='<div style="text-align:center;padding:28px 16px;font-size:.75rem;color:#94a3b8;line-height:1.7;">Conversation deleted.</div>';
        cwLastT=0;
        cwLoadCons();
      })
      .catch(function(){alert('Failed to delete conversation.');});
  }
  function cwKey(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();e.stopPropagation();cwSend();}}
  function cwResize(el){el.style.height='auto';el.style.height=Math.min(el.scrollHeight,90)+'px';}

  // ── Chat notification toast CSS ──────────────────────────────
  var _ns = document.createElement('style');
  _ns.textContent = `
    .cw-notif-toast {
      position:fixed;bottom:24px;left:24px;z-index:99999;
      display:flex;align-items:center;gap:12px;
      background:#1e293b;color:#fff;
      padding:13px 18px;border-radius:14px;
      box-shadow:0 8px 32px rgba(0,0,0,.28);
      font-family:'Nunito',sans-serif;font-size:.85rem;font-weight:700;
      cursor:pointer;max-width:300px;
      animation:_cwni .35s cubic-bezier(.34,1.4,.64,1) both;
      border-left:4px solid #2563eb;
    }
    @keyframes _cwni{from{opacity:0;transform:translateY(20px) scale(.92);}to{opacity:1;transform:translateY(0) scale(1);}}
    .cw-notif-toast:hover{background:#334155;}
    .cw-nt-av{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:800;color:#fff;flex-shrink:0;}
    .cw-nt-body{flex:1;min-width:0;}
    .cw-nt-name{font-size:.82rem;font-weight:800;margin-bottom:2px;}
    .cw-nt-msg{font-size:.74rem;color:#94a3b8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px;}
    .cw-nt-x{background:none;border:none;color:#64748b;font-size:1rem;cursor:pointer;padding:0;flex-shrink:0;}
    .cw-nt-x:hover{color:#fff;}
  `;
  document.head.appendChild(_ns);

  var cwPrevCount = 0, cwNotifTimer = null;

  function cwShowToast(name, msg, userId) {
    var old = document.querySelector('.cw-notif-toast');
    if (old) old.remove();
    var colors = ['#2563eb','#22c55e','#f59e0b','#ef4444','#a855f7','#06b6d4'];
    var col = colors[Math.abs([...(name||'?')].reduce(function(a,c){return a+c.charCodeAt(0);},0)) % colors.length];
    var ini = (name||'?').split(' ').map(function(w){return w[0]||'';}).join('').slice(0,2).toUpperCase();
    var t = document.createElement('div');
    t.className = 'cw-notif-toast';
    t.innerHTML =
      '<div class="cw-nt-av" style="background:'+col+'">'+ini+'</div>'+
      '<div class="cw-nt-body"><div class="cw-nt-name">'+name+'</div><div class="cw-nt-msg">'+msg+'</div></div>'+
      '<button class="cw-nt-x" onclick="event.stopPropagation();this.parentNode.remove();">&#x2715;</button>';
    t.addEventListener('click', function(){
      t.remove();
      if (!cwOpen) cwToggle();
      if (userId) setTimeout(function(){ var c=cwCons.find(function(x){return x.user_id==userId;}); if(c) cwOpenChat(userId); }, 350);
    });
    document.body.appendChild(t);
    clearTimeout(cwNotifTimer);
    cwNotifTimer = setTimeout(function(){
      if (t.parentNode){ t.style.transition='opacity .4s,transform .4s'; t.style.opacity='0'; t.style.transform='translateY(10px)'; setTimeout(function(){if(t.parentNode)t.remove();},400); }
    }, 6000);
  }

  // Poll every 5s — update 💬 badge + show toast on new messages
  function cwPollUnread(){
    fetch(CW_API+'?action=contacts')
      .then(function(r){return r.json();})
      .then(function(j){
        if (!j.success) return;
        var total = j.data.reduce(function(s,c){return s+(c.unread||0);},0);

        // Always update badge on 💬 button
        var b = document.getElementById('cwBadge');
        if (b){ b.textContent=total||''; b.style.display=total?'flex':'none'; }

        // Toast when new message arrives and widget is closed
        if (!cwOpen && total > cwPrevCount) {
          var sender = j.data.find(function(c){return c.unread>0;});
          if (sender) cwShowToast(sender.full_name||sender.email||'Employee', sender.last_message||'Sent you a message', sender.user_id);
        }

        // Refresh contact list if widget is open
        if (cwOpen){ cwCons=j.data; cwRenderCons(cwCons); }
        cwPrevCount = total;
      }).catch(function(){});
  }
  // Run immediately on page load then every 5s
  cwPollUnread();
  setInterval(cwPollUnread, 5000);
  

// ── Notification system (localStorage-persisted read state) ────────────────
const NOTIF_STORAGE_KEY = 'hrms_admin_read_notifs';

function getReadNotifs() {
  try { return JSON.parse(localStorage.getItem(NOTIF_STORAGE_KEY) || '[]'); }
  catch(e) { return []; }
}
function saveReadNotifs(ids) {
  try {
    // Keep only last 200 to avoid bloat
    localStorage.setItem(NOTIF_STORAGE_KEY, JSON.stringify(ids.slice(-200)));
  } catch(e) {}
}
function markNotifRead(id) {
  const read = getReadNotifs();
  if (!read.includes(id)) { read.push(id); saveReadNotifs(read); }
}
function markAllNotifsRead() {
  const ids = Array.from(document.querySelectorAll('.notif-item[data-nid]'))
                   .map(el => el.getAttribute('data-nid'));
  const read = getReadNotifs();
  ids.forEach(id => { if (!read.includes(id)) read.push(id); });
  saveReadNotifs(read);
}

function applyReadState() {
  const read = getReadNotifs();
  let unread = 0;
  document.querySelectorAll('.notif-item[data-nid]').forEach(el => {
    const id = el.getAttribute('data-nid');
    if (read.includes(id)) {
      el.classList.remove('unread');
    } else {
      unread++;
    }
  });
  const cnt = document.getElementById('adminNotifCount');
  if (cnt) {
    if (unread > 0) { cnt.textContent = unread; cnt.classList.add('show'); }
    else            { cnt.textContent = '';      cnt.classList.remove('show'); }
  }
}

function toggleNotifPanel() {
  const p  = document.getElementById('adminNotifPanel');
  const dd = document.getElementById('tbDropdown');
  if (dd) dd.classList.remove('open');
  if (p) {
    p.classList.toggle('open');
    // Re-apply read state every time panel opens (syncs across tabs)
    if (p.classList.contains('open')) applyReadState();
  }
}

function clearNotifications() {
  // Save all notification IDs as read in localStorage
  const ids = Array.from(document.querySelectorAll('.notif-item[data-nid]'))
                   .map(el => el.getAttribute('data-nid'));
  const read = getReadNotifs();
  ids.forEach(id => { if (id && !read.includes(id)) read.push(id); });
  saveReadNotifs(read);
  // Update DOM
  document.querySelectorAll('.notif-item.unread').forEach(el => el.classList.remove('unread'));
  const cnt = document.getElementById('adminNotifCount');
  if (cnt) { cnt.classList.remove('show'); cnt.textContent = ''; }
  const p = document.getElementById('adminNotifPanel');
  if (p) p.classList.remove('open');
}

document.addEventListener('click', function(e) {
  const w = document.getElementById('adminNotifWrap');
  if (w && !w.contains(e.target)) {
    const p = document.getElementById('adminNotifPanel');
    if (p) p.classList.remove('open');
  }
});

// Apply read state on page load
document.addEventListener('DOMContentLoaded', applyReadState);
function toggleProfileDropdown() {
  const dd = document.getElementById('tbDropdown');
  if (dd) dd.classList.toggle('open');
}
document.addEventListener('click', function(e) {
  const w = document.getElementById('tbProfileWrap');
  if (w && !w.contains(e.target)) {
    const dd = document.getElementById('tbDropdown');
    if (dd) dd.classList.remove('open');
  }
}, true);

</script>

<script src="../config/print-utilities.js?v=<?= time(); ?>"></script>

</body>
</html>