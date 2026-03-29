<?php
// ============================================================
//  LGU ENRILE HRMS — Service Records Viewer (Admin)
//  File: Admin/service_records.php
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
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_verify(true); }
    try {
        switch ($action) {

            // ── Auto-create service_records table if needed ──────────────────
            case 'init':
                $pdo->exec("CREATE TABLE IF NOT EXISTS service_records (
                    sr_id            INT AUTO_INCREMENT PRIMARY KEY,
                    employee_id      INT NOT NULL,
                    date_from        DATE NULL,
                    date_to          DATE NULL,
                    designation      VARCHAR(200) NULL,
                    status           VARCHAR(100) NULL,
                    salary           DECIMAL(14,2) NULL,
                    branch           VARCHAR(200) NULL,
                    station          VARCHAR(200) NULL,
                    lvbs_wo_pay      VARCHAR(200) NULL,
                    separation_date  DATE NULL,
                    separation_cause VARCHAR(200) NULL,
                    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_sr_emp (employee_id)
                ) ENGINE=InnoDB");
                echo json_encode(['success'=>true]);
                break;

            // ── List employees ───────────────────────────────────────────────
            case 'list_employees':
                $search = trim($_GET['search'] ?? '');
                $dept   = (int)($_GET['dept'] ?? 0);
                $where  = ["e.employment_status = 'Active'"];
                $params = [];
                if ($search) {
                    $s = '%'.$search.'%';
                    $where[] = "(CONCAT(e.first_name,' ',e.last_name) LIKE ? OR e.employee_code LIKE ?)";
                    $params  = [$s,$s];
                }
                if ($dept) { $where[] = 'e.department_id = ?'; $params[] = $dept; }
                $wSql = 'WHERE '.implode(' AND ',$where);
                $page = max(1,(int)($_GET['page']??1)); $ps = 15; $offset = ($page-1)*$ps;
                $cnt  = $pdo->prepare("SELECT COUNT(*) FROM employees e $wSql");
                $cnt->execute($params); $total = (int)$cnt->fetchColumn();
                // Check if service_records table exists
                $hasSR = false;
                try {
                    $pdo->query("SELECT 1 FROM service_records LIMIT 1");
                    $hasSR = true;
                } catch(Exception $ex) {}
                $srSubQ = $hasSR
                    ? "(SELECT COUNT(*) FROM service_records sr WHERE sr.employee_id=e.employee_id)"
                    : "0";
                $stmt = $pdo->prepare("
                    SELECT e.employee_id, e.employee_code, e.first_name, e.last_name,
                           e.position_title, e.employment_type, e.date_hired,
                           d.name AS department,
                           $srSubQ AS sr_count
                    FROM employees e
                    LEFT JOIN departments d ON e.department_id=d.department_id
                    $wSql ORDER BY e.last_name,e.first_name LIMIT $ps OFFSET $offset");
                $stmt->execute($params);
                echo json_encode(['success'=>true,'data'=>$stmt->fetchAll(),'total'=>$total,'pages'=>(int)ceil($total/$ps),'page'=>$page]);
                break;

            // ── Get service record entries for one employee ──────────────────
            case 'get_records':
                $empId = (int)($_GET['employee_id']??0);
                // Ensure table exists
                $pdo->exec("CREATE TABLE IF NOT EXISTS service_records (
                    sr_id            INT AUTO_INCREMENT PRIMARY KEY,
                    employee_id      INT NOT NULL,
                    date_from        DATE NULL,
                    date_to          DATE NULL,
                    designation      VARCHAR(200) NULL,
                    status           VARCHAR(100) NULL,
                    salary           DECIMAL(14,2) NULL,
                    branch           VARCHAR(200) NULL,
                    station          VARCHAR(200) NULL,
                    lvbs_wo_pay      VARCHAR(200) NULL,
                    separation_date  DATE NULL,
                    separation_cause VARCHAR(200) NULL,
                    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_sr_emp (employee_id)
                ) ENGINE=InnoDB");
                $stmt = $pdo->prepare("SELECT * FROM service_records WHERE employee_id=? ORDER BY record_date ASC, sr_id ASC");
                $stmt->execute([$empId]);
                $rows = $stmt->fetchAll();
                $emp  = $pdo->prepare("SELECT e.*,d.name AS dept_name FROM employees e LEFT JOIN departments d ON e.department_id=d.department_id WHERE e.employee_id=?");
                $emp->execute([$empId]); $empRow = $emp->fetch();
                echo json_encode(['success'=>true,'records'=>$rows,'employee'=>$empRow?:null]);
                break;

            // ── Save (add/edit) a service record entry ───────────────────────
            case 'save_record':
                $srId      = (int)($_POST['sr_id']??0);
                $empId     = (int)($_POST['employee_id']??0);
                if (!$empId) { echo json_encode(['success'=>false,'message'=>'No employee selected.']); break; }
                $fields = [
                    'date_from'        => $_POST['date_from']        ?? null,
                    'date_to'          => $_POST['date_to']          ?? null,
                    'designation'      => trim($_POST['designation']      ?? ''),
                    'status'           => trim($_POST['status']           ?? ''),
                    'salary'           => strlen(trim($_POST['salary']??'')) ? (float)$_POST['salary'] : null,
                    'branch'           => trim($_POST['branch']           ?? ''),
                    'station'          => trim($_POST['station']          ?? ''),
                    'lvbs_wo_pay'      => trim($_POST['lvbs_wo_pay']      ?? ''),
                    'separation_date'  => $_POST['separation_date']  ?? null,
                    'separation_cause' => trim($_POST['separation_cause']  ?? ''),
                ];
                // Clean empty dates
                foreach (['date_from','date_to','separation_date'] as $dk) {
                    if (empty($fields[$dk])) $fields[$dk] = null;
                }
                if ($srId) {
                    // Update
                    $set = implode(', ', array_map(fn($k)=>"`$k`=?", array_keys($fields)));
                    $vals = array_values($fields);
                    $vals[] = $srId;
                    $pdo->prepare("UPDATE service_records SET $set WHERE sr_id=?")->execute($vals);
                    echo json_encode(['success'=>true,'message'=>'Record updated.','sr_id'=>$srId]);
                } else {
                    // Insert
                    $cols = implode(',', array_map(fn($k)=>"`$k`", array_keys($fields)));
                    $phs  = implode(',', array_fill(0, count($fields), '?'));
                    $vals = array_values($fields);
                    $vals = array_merge([$empId], $vals);
                    $pdo->prepare("INSERT INTO service_records (employee_id,$cols) VALUES (?,$phs)")->execute($vals);
                    echo json_encode(['success'=>true,'message'=>'Record added.','sr_id'=>(int)$pdo->lastInsertId()]);
                }
                break;

            // ── Delete a service record entry ────────────────────────────────
            case 'delete_record':
                $srId = (int)($_POST['sr_id']??0);
                if (!$srId) { echo json_encode(['success'=>false,'message'=>'Invalid record.']); break; }
                $pdo->prepare("DELETE FROM service_records WHERE sr_id=?")->execute([$srId]);
                echo json_encode(['success'=>true,'message'=>'Record deleted.']);
                break;

            // ── SR Requests (from employee portal) ───────────────────────────
            case 'get_sr_requests':
                try { $pdo->exec("CREATE TABLE IF NOT EXISTS sr_requests (
                    req_id INT AUTO_INCREMENT PRIMARY KEY, employee_id INT NOT NULL,
                    purpose VARCHAR(300) NULL, status ENUM('Pending','Approved','Denied') DEFAULT 'Pending',
                    admin_note TEXT NULL, requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    resolved_at DATETIME NULL, INDEX idx_srr_emp (employee_id)) ENGINE=InnoDB"); } catch(Exception $ex) {}
                $rows = $pdo->query("SELECT r.*, CONCAT(e.first_name,' ',e.last_name) AS emp_name,
                    e.employee_code, d.name AS department
                    FROM sr_requests r JOIN employees e ON e.employee_id=r.employee_id
                    LEFT JOIN departments d ON d.department_id=e.department_id
                    ORDER BY FIELD(r.status,'Pending','Approved','Denied'), r.requested_at DESC LIMIT 100")->fetchAll();
                echo json_encode(['success'=>true,'data'=>$rows]);
                break;

            case 'resolve_sr_request':
                $reqId  = (int)($_POST['req_id']??0);
                $status = $_POST['status']??'';
                $note   = trim($_POST['admin_note']??'');
                if (!in_array($status,['Approved','Denied'])) { echo json_encode(['success'=>false,'message'=>'Invalid status.']); break; }
                $pdo->prepare("UPDATE sr_requests SET status=?,admin_note=?,resolved_at=NOW() WHERE req_id=?")->execute([$status,$note,$reqId]);
                echo json_encode(['success'=>true,'message'=>"Request {$status}."]);
                break;

            // ── Departments list ─────────────────────────────────────────────
            case 'departments':
                $rows = $pdo->query("SELECT department_id,name FROM departments ORDER BY name")->fetchAll();
                echo json_encode(['success'=>true,'data'=>$rows]);
                break;

            // ── Pending leave badge ──────────────────────────────────────────
            case 'pending_count':
                $c = $pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status='Pending'")->fetchColumn();
                echo json_encode(['count'=>(int)$c]);
                break;
        }
    } catch (PDOException $e) {
        error_log('[HRMS] service_records.php: '.$e->getMessage());
        echo json_encode(['success'=>false,'message'=>'A database error occurred.']);
    }
    exit();
}
?>

<?php
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

// ── Page variables ────────────────────────────────────────────
$adminName  = ucwords($_SESSION['user_name'] ?? 'Administrator');
$roleLabels = ['admin'=>'Super Admin','hr_staff'=>'HR Staff','dept_head'=>'Department Head'];
$roleLabel  = $roleLabels[$_SESSION['user_role']] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <?= csrf_meta() ?>
  <title>Service Records — LGU Enrile HRMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet"/>
  <style>
    :root{--primary:#2563eb;--primary-dark:#1e40af;--accent:#22c55e;--secondary:#0f172a;--sidebar-w:265px;--topbar-h:68px;--gray-50:#f8fafc;--gray-100:#f1f5f9;--gray-200:#e2e8f0;--gray-400:#94a3b8;--gray-600:#475569;--gray-800:#1e293b;--white:#ffffff;--shadow-sm:0 1px 4px rgba(0,0,0,0.07);--shadow-md:0 4px 18px rgba(0,0,0,0.11);--shadow-lg:0 8px 32px rgba(0,0,0,0.16);--radius:14px;--radius-sm:9px;}
    *{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'Nunito',sans-serif;background:var(--gray-100);color:var(--secondary);display:flex;min-height:100vh;}
    /* ── Sidebar ── */
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
    .nav-badge{margin-left:auto;background:#ef4444;color:#fff;font-size:.60rem;font-weight:800;padding:2px 6px;border-radius:20px;}
    .sidebar-footer{padding:14px 16px;border-top:1px solid rgba(255,255,255,.12);}
    .user-pill{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
    .user-avatar{width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.20);display:flex;align-items:center;justify-content:center;font-weight:800;color:#fff;font-size:.85rem;flex-shrink:0;}
    .user-name{font-size:.82rem;font-weight:700;color:#fff;}
    .user-role{font-size:.65rem;color:rgba(255,255,255,.55);text-transform:capitalize;}
    .logout-btn{width:100%;padding:8px 12px;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.25);border-radius:var(--radius-sm);color:#fca5a5;font-family:'Nunito',sans-serif;font-size:.82rem;font-weight:700;cursor:pointer;transition:all .2s;margin-top:4px;}
    .logout-btn:hover{background:rgba(239,68,68,.22);}
    /* ── Main ── */
    .main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-height:100vh;}
    .topbar{height:var(--topbar-h);background:linear-gradient(135deg,#2563eb 0%,#1e40af 50%,#22c55e 100%);display:flex;align-items:center;padding:0 28px;gap:16px;position:sticky;top:0;z-index:100;box-shadow:0 2px 12px rgba(0,0,0,.18);}
    .menu-toggle{display:none;background:none;border:none;font-size:1.4rem;cursor:pointer;color:rgba(255,255,255,0.85);padding:4px;}
    .topbar-title{flex:1;}
    .topbar-title h1{font-family:'Playfair Display',serif;font-size:1.22rem;color:#fff;}
    .topbar-title p{font-size:.72rem;color:rgba(255,255,255,.70);}
    .tb-chip{background:rgba(255,255,255,.15);color:#fff;padding:5px 14px;border-radius:20px;font-size:.72rem;font-weight:700;}
    .topbar-btn{position:relative;width:38px;height:38px;border-radius:50%;border:1px solid rgba(255,255,255,0.25);background:rgba(255,255,255,0.15);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:1rem;color:#fff;transition:all 0.2s;}
    .topbar-btn:hover{background:rgba(255,255,255,0.28);}
    .notif-badge{position:absolute;top:-2px;right:-2px;width:16px;height:16px;background:#ef4444;border-radius:50%;font-size:0.58rem;color:white;font-weight:800;display:none;align-items:center;justify-content:center;border:2px solid rgba(255,255,255,0.3);}
    /* ── Layout ── */
    .content{padding:26px 28px;flex:1;display:grid;grid-template-columns:320px 1fr;gap:20px;align-items:start;}
    /* ── Employee Panel ── */
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
    .db-sr{background:#dcfce7;color:#166534;}
    .db-none{background:var(--gray-100);color:var(--gray-400);}
    /* ── View Panel ── */
    .view-panel{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow-sm);overflow:hidden;}
    .view-panel-head{padding:16px 20px;background:var(--gray-50);border-bottom:1.5px solid var(--gray-200);display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
    .view-panel-title{font-size:.95rem;font-weight:800;flex:1;}
    .view-body{padding:22px;}
    /* ── Section Headers ── */
    .ro-section-hd{background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:#fff;padding:9px 16px;border-radius:var(--radius-sm) var(--radius-sm) 0 0;font-size:.78rem;font-weight:800;letter-spacing:.05em;text-transform:uppercase;margin-top:16px;}
    .ro-section-body{border:1.5px solid var(--gray-200);border-top:none;border-radius:0 0 var(--radius-sm) var(--radius-sm);padding:14px 16px;margin-bottom:2px;}
    .ro-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;}
    .ro-field{margin-bottom:4px;}
    .ro-label{font-size:.65rem;font-weight:800;color:var(--gray-400);text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px;}
    .ro-val{font-size:.85rem;font-weight:600;color:var(--gray-800);padding:6px 10px;background:var(--gray-50);border-radius:6px;min-height:30px;word-break:break-word;}
    .ro-val.empty{color:var(--gray-400);font-style:italic;}
    /* ── Service Record Table ── */
    .sr-table-wrap{overflow-x:auto;margin-top:8px;}
    .sr-table{width:100%;border-collapse:collapse;font-size:.80rem;}
    .sr-table th{background:var(--gray-100);padding:8px 10px;text-align:left;font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:var(--gray-600);border-bottom:2px solid var(--gray-200);white-space:nowrap;}
    .sr-table td{padding:9px 10px;border-bottom:1px solid var(--gray-100);vertical-align:middle;}
    .sr-table tr:last-child td{border-bottom:none;}
    .sr-table tr:hover td{background:var(--gray-50);}
    .sr-table .no-records td{text-align:center;padding:40px;color:var(--gray-400);font-size:.85rem;}
    /* ── Action Buttons ── */
    .btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border:none;border-radius:var(--radius-sm);font-family:'Nunito',sans-serif;font-size:.83rem;font-weight:700;cursor:pointer;transition:all .2s;}
    .btn-primary{background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:#fff;box-shadow:0 3px 10px rgba(37,99,235,.25);}
    .btn-primary:hover{transform:translateY(-1px);box-shadow:0 5px 16px rgba(37,99,235,.35);}
    .btn-green{background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;box-shadow:0 3px 10px rgba(34,197,94,.25);}
    .btn-green:hover{transform:translateY(-1px);}
    .btn-outline{background:var(--white);border:1.5px solid var(--gray-200);color:var(--gray-600);}
    .btn-outline:hover{border-color:var(--primary);color:var(--primary);}
    .btn-danger{background:#fee2e2;color:#dc2626;border:none;}
    .btn-danger:hover{background:#dc2626;color:#fff;}
    .btn-sm{padding:5px 12px;font-size:.76rem;}
    /* ── Add/Edit row inline form ── */
    .form-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;margin-bottom:14px;}
    .fg{display:flex;flex-direction:column;gap:4px;}
    .fg label{font-size:.70rem;font-weight:700;color:var(--gray-600);}
    .fg input,.fg select,.fg textarea{padding:8px 10px;border:1.5px solid var(--gray-200);border-radius:var(--radius-sm);font-family:'Nunito',sans-serif;font-size:.82rem;color:var(--gray-800);background:var(--white);outline:none;transition:border-color .2s;}
    .fg input:focus,.fg select:focus,.fg textarea:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(37,99,235,.08);}
    .fg textarea{resize:vertical;min-height:60px;}
    .fg-full{grid-column:1/-1;}
    /* ── Modal ── */
    .modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:500;align-items:center;justify-content:center;backdrop-filter:blur(3px);}
    .modal-overlay.open{display:flex;}
    .modal{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow-lg);width:92%;max-width:680px;max-height:92vh;overflow-y:auto;animation:modalIn .3s cubic-bezier(.34,1.4,.64,1) both;}
    @keyframes modalIn{from{opacity:0;transform:scale(.88) translateY(20px);}to{opacity:1;transform:scale(1) translateY(0);}}
    .modal-header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px 14px;background:linear-gradient(135deg,#2563eb,#1e40af);border-radius:var(--radius) var(--radius) 0 0;}
    .modal-header h3{font-size:1.05rem;font-weight:800;color:#fff;font-family:'Playfair Display',serif;}
    .modal-header p{font-size:.74rem;color:rgba(255,255,255,.65);margin-top:2px;}
    .modal-close{width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,.18);border:none;color:#fff;font-size:1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .2s;}
    .modal-close:hover{background:rgba(255,255,255,.32);}
    .modal-body{padding:22px;}
    .modal-footer{display:flex;justify-content:flex-end;gap:10px;padding:14px 22px 18px;border-top:1px solid var(--gray-100);}
    /* ── Empty / loading states ── */
    .empty-state{text-align:center;padding:60px 20px;color:var(--gray-400);}
    .empty-state .e-icon{font-size:3rem;margin-bottom:12px;}
    .empty-state p{font-size:.90rem;font-weight:600;}
    .empty-state small{font-size:.78rem;}
    /* ── Summary chips ── */
    .summary-chips{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;}
    .s-chip{padding:8px 16px;border-radius:var(--radius-sm);font-size:.80rem;font-weight:700;display:flex;align-items:center;gap:6px;}
    .s-chip.blue{background:#eff6ff;color:#1e40af;}
    .s-chip.green{background:#f0fdf4;color:#166534;}
    .s-chip.amber{background:#fffbeb;color:#d97706;}
    /* ── Pagination ── */
    .pg-wrap{padding:10px 14px;border-top:1px solid var(--gray-100);display:flex;gap:4px;justify-content:center;}
    .pg-btn{padding:5px 11px;border:1.5px solid var(--gray-200);background:#fff;border-radius:7px;font-size:.76rem;font-weight:700;cursor:pointer;font-family:'Nunito',sans-serif;}
    .pg-btn:hover:not(:disabled){border-color:var(--primary);color:var(--primary);}
    .pg-btn.active{background:var(--primary);color:#fff;border-color:var(--primary);}
    .pg-btn:disabled{opacity:.4;cursor:not-allowed;}
    /* ── Toast ── */
    .toast-wrap{position:fixed;bottom:24px;right:24px;z-index:2000;display:flex;flex-direction:column;gap:8px;}
    .toast{padding:12px 20px;border-radius:10px;color:#fff;font-size:.85rem;font-weight:700;box-shadow:var(--shadow-lg);animation:toastIn .3s ease both;min-width:200px;}
    @keyframes toastIn{from{opacity:0;transform:translateX(30px)}to{opacity:1;transform:translateX(0)}}
    .toast-success{background:linear-gradient(135deg,#22c55e,#16a34a);}
    .toast-error{background:linear-gradient(135deg,#dc2626,#b91c1c);}
    .toast-info{background:linear-gradient(135deg,#2563eb,#1e40af);}
    /* ── Responsive ── */
    @media(max-width:900px){.content{grid-template-columns:1fr;}.emp-panel{position:static;}.emp-list{max-height:260px;}.menu-toggle{display:block;}.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.main{margin-left:0;}.topbar{padding:0 16px;}.content{padding:16px;}}
  </style>
</head>
<body>

<!-- ══ SIDEBAR ══ -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <img src="../images/Enrile.png" class="brand-logo" alt="LGU" onerror="this.style.display='none'"/>
    <div class="brand-text"><h2>LGU Enrile</h2><p>HR Management System</p></div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-label">Main</div>
    <a class="nav-item" href="dashboard.php"><div class="nav-icon">🏠</div>Dashboard</a>
    <div class="nav-label">Employee</div>
    <a class="nav-item" href="employees.php"><div class="nav-icon">👥</div>Employees</a>
    <a class="nav-item" href="departments.php"><div class="nav-icon">🏢</div>Departments</a>
    <div class="nav-label">Leave</div>
    <a class="nav-item" href="leave_requests.php"><div class="nav-icon">📋</div>Leave Requests<span class="nav-badge" id="sidebarBadge" style="display:none;"></span></a>
    <a class="nav-item" href="leave_types.php"><div class="nav-icon">📁</div>Leave Types</a>
    <a class="nav-item" href="leave_balances.php"><div class="nav-icon">⚖️</div>Leave Balances</a>
    <div class="nav-label">Documents</div>
    <a class="nav-item" href="documents.php"><div class="nav-icon">📄</div>HR Documents</a>
    <a class="nav-item" href="pds_saln.php"><div class="nav-icon">🪪</div>PDS &amp; SALN</a>
    <a class="nav-item active" href="service_records.php"><div class="nav-icon">📜</div>Service Records</a>
    <a class="nav-item" href="contracts.php"><div class="nav-icon">📝</div>Contracts</a>
    <div class="nav-label">System</div>
    <a class="nav-item" href="reports.php"><div class="nav-icon">📊</div>Reports</a>
    <a class="nav-item" href="announcements.php"><div class="nav-icon">📢</div>Announcements</a>
    <a class="nav-item" href="archives.php"><div class="nav-icon">🗄️</div>Archives</a>
    <a class="nav-item" href="user_accounts.php"><div class="nav-icon">🔐</div>User Accounts</a>
    <a class="nav-item" href="settings.php"><div class="nav-icon">⚙️</div>Settings</a>
  </nav>
  <div class="sidebar-footer">
    <div class="user-pill">
      <div class="user-avatar"><?= strtoupper(substr($_SESSION['user_name']??'A',0,2)) ?></div>
      <div><div class="user-name"><?= htmlspecialchars($_SESSION['user_name']??'') ?></div><div class="user-role"><?= htmlspecialchars($_SESSION['user_role']??'') ?></div></div>
    </div>
  </div>
</aside>

<!-- ══ MAIN ══ -->
<div class="main">
  <div class="topbar">
    <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">☰</button>
    <div class="topbar-title">
      <h1>Service Records</h1>
      <p>View and manage employee service history</p>
    </div>
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

    <!-- ── Employee List Panel ── -->
    <div class="emp-panel">
      <div class="emp-panel-head">
        <div class="emp-panel-title">👥 Select Employee</div>
        <div class="search-box">
          <span style="color:var(--gray-400);">🔍</span>
          <input type="text" id="empSearch" placeholder="Search name or code…" oninput="debounceSearch()"/>
        </div>
        <select class="filter-select" id="deptFilter" onchange="loadEmployees(1)">
          <option value="">All Departments</option>
        </select>
      </div>
      <div class="emp-list" id="empList">
        <div class="empty-state"><div class="e-icon">⏳</div><p>Loading employees…</p></div>
      </div>
      <div class="pg-wrap" id="empPg"></div>
    </div>

    <!-- ── Service Records View Panel ── -->
    <div class="view-panel" id="viewPanel">
      <div class="view-panel-head">
        <div>
          <div class="view-panel-title" id="vpTitle">📜 Service Records</div>
          <div style="font-size:.75rem;color:var(--gray-400);margin-top:2px;" id="vpSub">Select an employee from the list to view their service records.</div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;" id="vpActions">
          <button class="btn btn-outline" onclick="loadSRRequests()" id="btnRequests" style="display:none;">📨 <span id="reqBadgeTxt">Pending Requests</span></button>
          <button class="btn btn-primary" onclick="openAddModal()" id="btnAdd" style="display:none;">➕ Add Entry</button>
          <button class="btn btn-green" onclick="printServiceRecord()" id="btnPrint" style="display:none;">🖨️ Print</button>
        </div>
      </div>
      <div class="view-body" id="viewBody">
        <div class="empty-state">
          <div class="e-icon">📜</div>
          <p>No employee selected</p>
          <small>Click an employee on the left to view their service records.</small>
        </div>
      </div>
    </div>

  </div><!-- /content -->
</div><!-- /main -->

<!-- ══ ADD/EDIT MODAL ══ -->
<div class="modal-overlay" id="modalForm">
  <div class="modal">
    <div class="modal-header">
      <div>
        <h3 id="modalTitle">Add Service Record Entry</h3>
        <p id="modalSub">Fill in the service record details below.</p>
      </div>
      <button class="modal-close" onclick="closeModal()">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="fSrId" value=""/>
      <p style="font-size:.75rem;color:var(--gray-400);margin-bottom:12px;">Columns follow the official CSC Service Record format.</p>
      <div class="form-row">
        <div class="fg">
          <label>Date From</label>
          <input type="date" id="fDateFrom"/>
        </div>
        <div class="fg">
          <label>Date To <span style="color:var(--gray-400);font-weight:400;">(leave blank if current)</span></label>
          <input type="date" id="fDateTo"/>
        </div>
        <div class="fg">
          <label>Designation / Position Title</label>
          <input type="text" id="fDesignation" placeholder="e.g. Administrative Aide VI"/>
        </div>
        <div class="fg">
          <label>Status / Nature of Appointment</label>
          <select id="fStatus">
            <option value="">— Select —</option>
            <option>Permanent</option>
            <option>Temporary</option>
            <option>Casual</option>
            <option>Contractual</option>
            <option>Job Order</option>
            <option>Co-Terminus</option>
            <option>Elected</option>
            <option>Reappointed</option>
            <option>Promoted</option>
            <option>Transferred</option>
            <option>Reinstated</option>
          </select>
        </div>
        <div class="fg">
          <label>Salary per Annum (₱)</label>
          <input type="number" id="fSalary" placeholder="0.00" step="0.01" min="0"/>
        </div>
        <div class="fg">
          <label>Branch</label>
          <input type="text" id="fBranch" placeholder="e.g. Local Government"/>
        </div>
        <div class="fg">
          <label>Station / Place of Assignment</label>
          <input type="text" id="fStation" placeholder="e.g. Enrile, Cagayan"/>
        </div>
        <div class="fg">
          <label>LVBS W/O Pay <span style="color:var(--gray-400);font-weight:400;">(leave without pay)</span></label>
          <input type="text" id="fLvbs" placeholder="e.g. Jan 1 – Mar 31, 2022"/>
        </div>
        <div class="fg">
          <label>Date of Separation</label>
          <input type="date" id="fSepDate"/>
        </div>
        <div class="fg">
          <label>Cause of Separation</label>
          <input type="text" id="fSepCause" placeholder="e.g. Resigned, Retired, Promoted"/>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal()">Cancel</button>
      <button class="btn btn-primary" id="btnSave" onclick="saveRecord()">💾 Save Record</button>
    </div>
  </div>
</div>

<!-- ══ DELETE CONFIRM MODAL ══ -->
<div class="modal-overlay" id="modalDelete">
  <div class="modal" style="max-width:420px;">
    <div class="modal-header" style="background:linear-gradient(135deg,#dc2626,#b91c1c);">
      <div><h3>Delete Entry</h3><p id="delSub">This action cannot be undone.</p></div>
      <button class="modal-close" onclick="closeDeleteModal()">✕</button>
    </div>
    <div class="modal-body">
      <p style="font-size:.88rem;color:var(--gray-600);line-height:1.6;">Are you sure you want to delete this service record entry? This cannot be undone.</p>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeDeleteModal()">Cancel</button>
      <button class="btn" style="background:#dc2626;color:#fff;" id="btnConfirmDelete" onclick="confirmDelete()">🗑️ Delete</button>
    </div>
  </div>
</div>

<!-- ══ TOAST ══ -->
<div class="toast-wrap" id="toastWrap"></div>

<script>
const CSRF_TOKEN = document.querySelector('meta[name=csrf-token]').content;
const API = 'service_records.php';
let currentEmpId = null;
let currentEmpName = '';
let empPage = 1;
let empSearchTimer = null;
let deleteTarget = null;

// ── Init ───────────────────────────────────────────────────────────────────
(async function init() {
  document.getElementById('tbDate').textContent = new Date().toLocaleDateString('en-PH',{weekday:'short',year:'numeric',month:'short',day:'numeric'});
  await loadDepts();
  await loadEmployees(1);
})();

// ── Departments ────────────────────────────────────────────────────────────
async function loadDepts() {
  const r = await fetch(API + '?action=departments').then(r=>r.json()).catch(()=>({success:false}));
  if (!r.success) return;
  const sel = document.getElementById('deptFilter');
  r.data.forEach(d => {
    const o = document.createElement('option');
    o.value = d.department_id; o.textContent = d.name;
    sel.appendChild(o);
  });
}

// ── Employee List ──────────────────────────────────────────────────────────
function debounceSearch() {
  clearTimeout(empSearchTimer);
  empSearchTimer = setTimeout(() => loadEmployees(1), 350);
}

async function loadEmployees(page) {
  empPage = page || 1;
  const search = document.getElementById('empSearch').value.trim();
  const dept   = document.getElementById('deptFilter').value;
  const r = await fetch(`${API}?action=list_employees&page=${empPage}&search=${encodeURIComponent(search)}&dept=${dept}`)
              .then(r=>r.json()).catch(()=>({success:false}));

  const list = document.getElementById('empList');
  if (!r.success || !r.data.length) {
    list.innerHTML = '<div class="empty-state"><div class="e-icon">🔍</div><p>No employees found.</p></div>';
    document.getElementById('empPg').innerHTML = '';
    return;
  }

  list.innerHTML = r.data.map(e => {
    const ini = (e.first_name[0]+e.last_name[0]).toUpperCase();
    const cnt = parseInt(e.sr_count) || 0;
    const badge = cnt > 0
      ? `<span class="doc-badge db-sr">${cnt} entr${cnt===1?'y':'ies'}</span>`
      : `<span class="doc-badge db-none">No records</span>`;
    const active = e.employee_id == currentEmpId ? ' active' : '';
    return `<div class="emp-row${active}" onclick="selectEmployee(${e.employee_id},'${(e.last_name+', '+e.first_name).replace(/'/g,"\\'")}','${(e.position_title||'').replace(/'/g,"\\'")}','${(e.department||'').replace(/'/g,"\\'")}')">
      <div class="emp-avatar">${ini}</div>
      <div style="flex:1;min-width:0;">
        <div class="emp-name">${e.last_name}, ${e.first_name}</div>
        <div class="emp-sub">${e.position_title||''} · ${e.department||''}</div>
      </div>
      <div class="emp-badges">${badge}</div>
    </div>`;
  }).join('');

  // Pagination
  const pg = document.getElementById('empPg');
  if (r.pages <= 1) { pg.innerHTML = ''; return; }
  let btns = `<button class="pg-btn" onclick="loadEmployees(${empPage-1})" ${empPage<=1?'disabled':''}>‹</button>`;
  for (let i=1;i<=r.pages;i++) btns += `<button class="pg-btn${i===empPage?' active':''}" onclick="loadEmployees(${i})">${i}</button>`;
  btns += `<button class="pg-btn" onclick="loadEmployees(${empPage+1})" ${empPage>=r.pages?'disabled':''}>›</button>`;
  pg.innerHTML = btns;
}

// ── Select Employee ────────────────────────────────────────────────────────
async function selectEmployee(id, name, position, dept) {
  currentEmpId   = id;
  currentEmpName = name;
  // Highlight row
  document.querySelectorAll('.emp-row').forEach(r => r.classList.remove('active'));
  event.currentTarget.classList.add('active');
  // Show header
  document.getElementById('vpTitle').textContent = `📜 ${name}`;
  document.getElementById('vpSub').textContent   = `${position} · ${dept}`;
  document.getElementById('btnAdd').style.display      = 'inline-flex';
  document.getElementById('btnPrint').style.display    = 'inline-flex';
  document.getElementById('btnRequests').style.display = 'inline-flex';
  // Load records
  await loadRecords();
}

// ── Load Service Records ───────────────────────────────────────────────────
async function loadRecords() {
  if (!currentEmpId) return;
  const vb = document.getElementById('viewBody');
  vb.innerHTML = '<div class="empty-state"><div class="e-icon">⏳</div><p>Loading records…</p></div>';

  const r = await fetch(`${API}?action=get_records&employee_id=${currentEmpId}`)
              .then(r=>r.json()).catch(()=>({success:false}));
  if (!r.success) { showToast('Failed to load records.','error'); return; }

  const emp = r.employee || {};
  const records = r.records || [];

  // Summary
  const hired    = emp.date_hired ? fmt(emp.date_hired) : '—';
  const totalYrs = emp.date_hired ? computeYears(emp.date_hired) : '—';

  let html = `
    <div class="summary-chips">
      <div class="s-chip blue">👤 ${emp.employee_code||'—'}</div>
      <div class="s-chip blue">📅 Date Hired: ${hired}</div>
      <div class="s-chip green">⏱ Total Gov't Service: ${totalYrs}</div>
      <div class="s-chip amber">📋 ${records.length} Entr${records.length===1?'y':'ies'}</div>
    </div>

    <div class="ro-section-hd">Service Record History</div>
    <div class="sr-table-wrap">
      <table class="sr-table">
        <thead>
          <tr>
            <th rowspan="2">From</th>
            <th rowspan="2">To</th>
            <th colspan="3" style="text-align:center;background:#dbeafe;color:#1e40af;">Record of Appointment</th>
            <th colspan="2" style="text-align:center;background:#dcfce7;color:#166534;">Office / Entity</th>
            <th rowspan="2">LVBS W/O Pay</th>
            <th colspan="2" style="text-align:center;background:#fee2e2;color:#991b1b;">Separation</th>
            <th rowspan="2" style="text-align:center;">Actions</th>
          </tr>
          <tr>
            <th>Designation</th>
            <th>Status</th>
            <th>Salary per Annum (₱)</th>
            <th>Branch</th>
            <th>Station / Place</th>
            <th>Date</th>
            <th>Cause</th>
          </tr>
        </thead>
        <tbody>`;

  if (!records.length) {
    html += `<tr class="no-records"><td colspan="12">No service record entries yet. Click <strong>➕ Add Entry</strong> to begin.</td></tr>`;
  } else {
    records.forEach(sr => {
      html += `<tr>
        <td style="white-space:nowrap;">${sr.record_date ? fmt(sr.record_date) : '—'}</td>
        <td style="font-weight:600;">${esc(sr.designation)||'—'}</td>
        <td>${esc(sr.department)||'—'}</td>
        <td>${esc(sr.branch)||'—'}</td>
        <td><span style="padding:2px 8px;border-radius:12px;font-size:.70rem;font-weight:800;background:#eff6ff;color:#1e40af;">${esc(sr.status)||'—'}</span></td>
        <td style="text-align:right;font-weight:700;">${sr.salary ? '₱'+parseFloat(sr.salary).toLocaleString('en-PH',{minimumFractionDigits:2}) : '—'}</td>
        <td>${esc(sr.station)||'—'}</td>
        <td>${esc(sr.gov_service)||'—'}</td>
        <td style="white-space:nowrap;">${sr.separation_date ? fmt(sr.separation_date) : '—'}</td>
        <td>${esc(sr.separation_cause)||'—'}</td>
        <td style="font-size:.75rem;color:var(--gray-600);">${esc(sr.remarks)||'—'}</td>
        <td style="white-space:nowrap;text-align:center;">
          <button class="btn btn-outline btn-sm" onclick="openEditModal(${sr.sr_id})" title="Edit">✏️</button>
          <button class="btn btn-danger btn-sm" onclick="openDeleteModal(${sr.sr_id},'${(sr.designation||'this entry').replace(/'/g,"\\'")}')" title="Delete">🗑️</button>
        </td>
      </tr>`;
    });
  }

  html += `</tbody></table></div>`;
  vb.innerHTML = html;
}

// ── Helpers ────────────────────────────────────────────────────────────────
function fmt(d) {
  if (!d) return '—';
  try { return new Date(d+'T00:00:00').toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'numeric'}); }
  catch(e) { return d; }
}
function esc(s) {
  if (!s) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function computeYears(dateHired) {
  if (!dateHired) return '—';
  const start = new Date(dateHired);
  const now   = new Date();
  let years  = now.getFullYear() - start.getFullYear();
  let months = now.getMonth() - start.getMonth();
  if (months < 0) { years--; months += 12; }
  return `${years} yr${years!==1?'s':''} ${months} mo${months!==1?'s':''}`;
}

// ── Add Modal ──────────────────────────────────────────────────────────────
function openAddModal() {
  document.getElementById('modalTitle').textContent = 'Add Service Record Entry';
  document.getElementById('modalSub').textContent   = currentEmpName;
  document.getElementById('fSrId').value       = '';
  document.getElementById('fDateFrom').value   = '';
  document.getElementById('fDateTo').value     = '';
  document.getElementById('fDesignation').value= '';
  document.getElementById('fStatus').value     = '';
  document.getElementById('fSalary').value     = '';
  document.getElementById('fBranch').value     = '';
  document.getElementById('fStation').value    = '';
  document.getElementById('fLvbs').value       = '';
  document.getElementById('fSepDate').value    = '';
  document.getElementById('fSepCause').value   = '';
  document.getElementById('modalForm').classList.add('open');
}

// ── Edit Modal ─────────────────────────────────────────────────────────────
async function openEditModal(srId) {
  // Fetch current data from the table row (already loaded)
  const vb = document.getElementById('viewBody');
  const rows = await fetch(`${API}?action=get_records&employee_id=${currentEmpId}`).then(r=>r.json());
  const sr = (rows.records||[]).find(r=>r.sr_id==srId);
  if (!sr) { showToast('Record not found.','error'); return; }

  document.getElementById('modalTitle').textContent = 'Edit Service Record Entry';
  document.getElementById('modalSub').textContent   = currentEmpName;
  document.getElementById('fSrId').value        = sr.sr_id;
  document.getElementById('fDateFrom').value    = sr.date_from       || '';
  document.getElementById('fDateTo').value      = sr.date_to         || '';
  document.getElementById('fDesignation').value = sr.designation     || '';
  document.getElementById('fStatus').value      = sr.status          || '';
  document.getElementById('fSalary').value      = sr.salary          || '';
  document.getElementById('fBranch').value      = sr.branch          || '';
  document.getElementById('fStation').value     = sr.station         || '';
  document.getElementById('fLvbs').value        = sr.lvbs_wo_pay     || '';
  document.getElementById('fSepDate').value     = sr.separation_date || '';
  document.getElementById('fSepCause').value    = sr.separation_cause|| '';
  document.getElementById('modalForm').classList.add('open');
}

function closeModal() { document.getElementById('modalForm').classList.remove('open'); }

// ── Save Record ────────────────────────────────────────────────────────────
async function saveRecord() {
  const btn = document.getElementById('btnSave');
  btn.disabled = true; btn.textContent = '⏳ Saving…';

  const fd = new FormData();
  fd.append('action',           'save_record');
  fd.append('sr_id',            document.getElementById('fSrId').value);
  fd.append('employee_id',      currentEmpId);
  fd.append('date_from',        document.getElementById('fDateFrom').value);
  fd.append('date_to',          document.getElementById('fDateTo').value);
  fd.append('designation',      document.getElementById('fDesignation').value);
  fd.append('status',           document.getElementById('fStatus').value);
  fd.append('salary',           document.getElementById('fSalary').value);
  fd.append('branch',           document.getElementById('fBranch').value);
  fd.append('station',          document.getElementById('fStation').value);
  fd.append('lvbs_wo_pay',      document.getElementById('fLvbs').value);
  fd.append('separation_date',  document.getElementById('fSepDate').value);
  fd.append('separation_cause', document.getElementById('fSepCause').value);

  try {
    fd.append('csrf_token', CSRF_TOKEN);
    const r = await fetch(API, {method:'POST', body:fd}).then(r=>r.json());
    if (r.success) {
      showToast('✅ ' + r.message, 'success');
      closeModal();
      await loadRecords();
      await loadEmployees(empPage);
    } else {
      showToast('❌ ' + (r.message||'Save failed.'), 'error');
    }
  } catch(e) {
    showToast('❌ Network error.', 'error');
  } finally {
    btn.disabled = false; btn.textContent = '💾 Save Record';
  }
}

// ── Delete ─────────────────────────────────────────────────────────────────
function openDeleteModal(srId, label) {
  deleteTarget = srId;
  document.getElementById('delSub').textContent = `Deleting: "${label}"`;
  document.getElementById('modalDelete').classList.add('open');
}
function closeDeleteModal() {
  document.getElementById('modalDelete').classList.remove('open');
  deleteTarget = null;
}
async function confirmDelete() {
  if (!deleteTarget) return;
  const btn = document.getElementById('btnConfirmDelete');
  btn.disabled = true; btn.textContent = '⏳ Deleting…';

  const fd = new FormData();
  fd.append('action', 'delete_record');
  fd.append('sr_id',  deleteTarget);

  try {
    fd.append('csrf_token', CSRF_TOKEN);
    const r = await fetch(API, {method:'POST', body:fd}).then(r=>r.json());
    if (r.success) {
      showToast('🗑️ ' + r.message, 'success');
      closeDeleteModal();
      await loadRecords();
      await loadEmployees(empPage);
    } else {
      showToast('❌ ' + (r.message||'Delete failed.'), 'error');
    }
  } catch(e) {
    showToast('❌ Network error.', 'error');
  } finally {
    btn.disabled = false; btn.textContent = '🗑️ Delete';
  }
}

// ── Print ──────────────────────────────────────────────────────────────────
async function printServiceRecord() {
  if (!currentEmpId) return;
  const r = await fetch(`${API}?action=get_records&employee_id=${currentEmpId}`).then(r=>r.json());
  if (!r.success) { showToast('Failed to load records.','error'); return; }

  const emp     = r.employee || {};
  const records = r.records  || [];
  buildAndPrintSR(emp, records);
}

function buildSRPageHTML(emp, records, pageNum, totalPages, sliceStart, sliceEnd) {
  const fullName  = `${emp.last_name||''}, ${emp.first_name||''} ${emp.middle_name ? emp.middle_name[0]+'.' : ''}`.trim();
  const surname   = emp.last_name   || '';
  const firstName = emp.first_name  || '';
  const mi        = emp.middle_name ? emp.middle_name[0]+'.' : '';
  const dob       = emp.date_of_birth ? fmtPrint(emp.date_of_birth) : '';
  const gsisBp    = emp.gsis_number   || '';
  const tin       = emp.tin_number    || '';
  const pageRecs  = records.slice(sliceStart, sliceEnd);

  const rows = pageRecs.map(sr => `
    <tr>
      <td>${sr.date_from ? fmtPrint(sr.date_from) : ''}</td>
      <td>${sr.date_to   ? fmtPrint(sr.date_to)   : ''}</td>
      <td>${esc(sr.designation)||''}</td>
      <td style="text-align:center;">${esc(sr.status)||''}</td>
      <td style="text-align:right;">${sr.salary ? parseFloat(sr.salary).toLocaleString('en-PH',{minimumFractionDigits:2}) : ''}</td>
      <td>${esc(sr.branch)||''}</td>
      <td>${esc(sr.station)||''}</td>
      <td style="text-align:center;">${esc(sr.lvbs_wo_pay)||''}</td>
      <td style="text-align:center;">${sr.separation_date ? fmtPrint(sr.separation_date) : ''}</td>
      <td>${esc(sr.separation_cause)||''}</td>
    </tr>`).join('');

  // Fill blank rows to ensure proper table height (min 15 data rows per page)
  const minRows = 15;
  let blankRows = '';
  for (let i = pageRecs.length; i < minRows; i++) {
    blankRows += '<tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>';
  }

  return `
  <div class="sr-page">
    <!-- HEADER -->
    <table class="hdr-tbl">
      <tr>
        <td rowspan="6" class="logo-cell">
          <img src="../images/Enrile.png" alt="LGU" class="logo-img" onerror="this.style.display='none'"/>
        </td>
        <td class="hdr-main">
          <div class="hdr-republic">Republic of the Philippines</div>
          <div class="hdr-province">Province of Cagayan</div>
          <div class="hdr-muni">Municipality of Enrile</div>
          <div class="hdr-brand">ONE ENRILE</div>
          <div class="hdr-office">Office of the Municipal Mayor</div>
        </td>
      </tr>
    </table>

    <!-- GSIS / TIN -->
    <div class="meta-row">
      <div class="meta-item"><em>GSIS BP No: ${gsisBp}</em></div>
      <div class="meta-item"><em>Tax Identification No. ${tin}</em></div>
    </div>

    <!-- TITLE -->
    <div class="sr-title">S E R V I C E &nbsp;&nbsp; R E C O R D</div>

    <!-- NAME -->
    <table class="name-tbl">
      <tr>
        <td class="name-label"><strong>NAME:</strong></td>
        <td class="name-cell name-underline">${surname}</td>
        <td class="name-cell name-underline">${firstName}</td>
        <td class="name-cell name-underline mi-cell">${mi}</td>
        <td class="married-note">(If married, give maiden name)</td>
      </tr>
      <tr>
        <td></td>
        <td class="name-sub">(Surname)</td>
        <td class="name-sub">(First Name)</td>
        <td class="name-sub">(M.I)</td>
        <td></td>
      </tr>
    </table>

    <!-- DATE OF BIRTH -->
    <div class="dob-row">DATE OF BIRTH: <span class="dob-val">${dob}</span>
      <span class="dob-note">(Date herein should be checked from Birth or Baptismal Certificate or some reliable resources.)</span>
    </div>

    <!-- CERTIFICATION -->
    <div class="cert-text">THIS IS TO CERTIFY that the above-named employee actually rendered services in the Office as shown in the column below. Each line is supported by appointment papers actually issued by this office and approved by authorities concerned.</div>

    <!-- SERVICE RECORD TABLE -->
    <table class="sr-tbl">
      <thead>
        <tr>
          <th colspan="2" class="grp-hd">SERVICE</th>
          <th colspan="3" class="grp-hd">RECORD OF APPOINTMENT</th>
          <th colspan="2" class="grp-hd">OFFICE/ENTITY</th>
          <th class="grp-hd" rowspan="2">LVBS<br>W/O PAY</th>
          <th colspan="2" class="grp-hd">SEPARATION</th>
        </tr>
        <tr>
          <th class="col-hd">From</th>
          <th class="col-hd">To</th>
          <th class="col-hd">Designation</th>
          <th class="col-hd">Status</th>
          <th class="col-hd">Salary per Annum</th>
          <th class="col-hd">Branch</th>
          <th class="col-hd">Station/Place</th>
          <th class="col-hd">Date</th>
          <th class="col-hd">Cause</th>
        </tr>
      </thead>
      <tbody>
        ${rows}${blankRows}
      </tbody>
    </table>

    <!-- FOOTER -->
    <div class="footer-cert">
      Issued in compliance with Executive Order No. 54 dated August 10, 1954 and in accordance with Circular No. 58,
      dated August 10, 1954 of the system.
    </div>
    <table class="sig-tbl">
      <tr>
        <td class="sig-left">
          <div class="sig-prep">Prepared by:</div>
          <div class="sig-name">EMIROSE P. MADRIAGA</div>
          <div class="sig-pos">HRMO III</div>
        </td>
        <td class="sig-right">
          <div class="sig-prep">Conforme:</div>
          <div class="sig-name">MIGUEL B. DECENA, JR.</div>
          <div class="sig-pos">Municipal Mayor</div>
        </td>
      </tr>
    </table>
    <div class="page-num">Page ${pageNum} of ${totalPages}</div>
  </div>`;
}

function fmtPrint(d) {
  if (!d) return '';
  try {
    const dt = new Date(d+'T00:00:00');
    return dt.toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'numeric'});
  } catch(e) { return d; }
}

function buildAndPrintSR(emp, records) {
  const ROWS_PER_PAGE = 15;
  const totalPages = Math.max(1, Math.ceil(records.length / ROWS_PER_PAGE));

  let pages = '';
  for (let p = 0; p < totalPages; p++) {
    pages += buildSRPageHTML(emp, records, p+1, totalPages, p*ROWS_PER_PAGE, (p+1)*ROWS_PER_PAGE);
  }

  const css = `
    * { box-sizing:border-box; margin:0; padding:0; }
    body { font-family:'Century Gothic','Calibri',sans-serif; font-size:9pt; color:#000; background:#fff; }
    @page { size:legal portrait; margin:12mm 14mm; }
    @media print { .sr-page { page-break-after:always; } .sr-page:last-child { page-break-after:avoid; } }

    /* ── Header ── */
    .hdr-tbl { width:100%; border-collapse:collapse; margin-bottom:3pt; }
    .logo-cell { width:60pt; text-align:center; vertical-align:middle; padding-right:8pt; }
    .logo-img  { width:50pt; height:50pt; object-fit:contain; }
    .hdr-main  { text-align:center; vertical-align:middle; }
    .hdr-republic { font-family:Tahoma,sans-serif; font-weight:700; font-size:10pt; }
    .hdr-province { font-family:Tahoma,sans-serif; font-weight:700; font-size:9.5pt; }
    .hdr-muni     { font-family:Tahoma,sans-serif; font-weight:700; font-size:10pt; }
    .hdr-brand    { font-family:Arial,sans-serif; font-weight:900; font-size:18pt; letter-spacing:2pt; margin:2pt 0; }
    .hdr-office   { font-family:Georgia,serif; font-weight:700; font-size:14pt; }

    /* ── Meta ── */
    .meta-row { display:flex; gap:20pt; margin:4pt 0; font-family:'Comic Sans MS',cursive; font-size:8pt; font-style:italic; }

    /* ── Title ── */
    .sr-title { font-family:'Bookman Old Style','Georgia',serif; font-weight:700; font-size:14pt;
                text-align:center; margin:6pt 0 5pt; letter-spacing:2pt; }

    /* ── Name ── */
    .name-tbl  { width:100%; border-collapse:collapse; margin-bottom:4pt; }
    .name-label { font-family:'Century Gothic',sans-serif; font-weight:700; font-size:11pt; width:55pt; padding-right:4pt; white-space:nowrap; }
    .name-cell  { font-family:'Century Gothic',sans-serif; font-size:11pt; padding:0 6pt; text-align:center; }
    .name-underline { border-bottom:1pt solid #000; min-width:80pt; }
    .mi-cell { max-width:40pt; }
    .name-sub   { font-family:Calibri,sans-serif; font-size:10pt; text-align:center; color:#333; padding-top:1pt; }
    .married-note { font-family:'Century Gothic',sans-serif; font-size:10pt; padding-left:6pt; color:#333; white-space:nowrap; }

    /* ── DOB ── */
    .dob-row  { font-family:'Century Gothic',sans-serif; font-size:10pt; margin-bottom:3pt; }
    .dob-val  { border-bottom:1pt solid #000; min-width:80pt; display:inline-block; padding:0 8pt; }
    .dob-note { font-size:8.5pt; color:#444; }

    /* ── Cert ── */
    .cert-text { font-family:'Century Gothic',sans-serif; font-weight:700; font-size:9.5pt;
                 text-align:justify; margin-bottom:5pt; line-height:1.4; }

    /* ── Table ── */
    .sr-tbl { width:100%; border-collapse:collapse; font-size:8pt; }
    .grp-hd { background:#d0d0d0; border:1pt solid #000; padding:3pt 4pt;
              text-align:center; font-family:'Century Gothic',sans-serif; font-weight:700; font-size:8.5pt; }
    .col-hd { background:#e8e8e8; border:1pt solid #000; padding:3pt 4pt;
              text-align:center; font-family:'Century Gothic',sans-serif; font-weight:700; font-size:8pt; }
    .sr-tbl td { border:1pt solid #000; padding:3pt 4pt; font-family:'Century Gothic',sans-serif;
                 font-size:8pt; min-height:15pt; vertical-align:middle; }

    /* ── Footer ── */
    .footer-cert { font-family:Calibri,sans-serif; font-weight:700; font-size:8.5pt; margin:8pt 0 6pt; line-height:1.4; }
    .sig-tbl { width:100%; border-collapse:collapse; margin-top:4pt; }
    .sig-left  { width:55%; vertical-align:top; padding-right:10pt; }
    .sig-right { vertical-align:top; padding-left:10pt; }
    .sig-prep  { font-family:'Century Gothic',sans-serif; font-size:10pt; margin-bottom:24pt; }
    .sig-name  { font-family:'Century Gothic',sans-serif; font-weight:700; font-size:11pt; border-top:1pt solid #000; padding-top:3pt; }
    .sig-pos   { font-family:'Century Gothic',sans-serif; font-size:11pt; }
    .page-num  { font-family:'Century Gothic',sans-serif; font-size:10pt; text-align:right; margin-top:6pt; }
    .sr-page   { margin-bottom:10pt; }
  `;

  const w = window.open('','_blank','width=900,height=1100');
  w.document.write(`<!DOCTYPE html><html><head>
    <title>Service Record — ${emp.last_name||''}, ${emp.first_name||''}</title>
    <style>${css}</style>
  </head><body>${pages}</body></html>`);
  w.document.close();
  w.focus();
  setTimeout(() => w.print(), 600);
}


// ── SR Requests management ────────────────────────────────────────────────────
async function loadSRRequests() {
  const vb = document.getElementById('viewBody');
  vb.innerHTML = '<div class="empty-state"><div class="e-icon">⏳</div><p>Loading requests…</p></div>';
  const r = await fetch(`${API}?action=get_sr_requests`).then(r=>r.json()).catch(()=>({success:false}));
  if (!r.success) { showToast('Failed to load requests.','error'); return; }

  if (!r.data.length) {
    vb.innerHTML = '<div class="empty-state"><div class="e-icon">📭</div><p>No service record requests yet.</p></div>';
    return;
  }

  const statusColors = {Pending:'background:#fffbeb;color:#92400e;',Approved:'background:#f0fdf4;color:#166534;',Denied:'background:#fef2f2;color:#991b1b;'};
  const statusIco    = {Pending:'⏳',Approved:'✅',Denied:'✗'};

  vb.innerHTML = `
    <div class="ro-section-hd">📨 Employee Service Record Requests</div>
    <div class="sr-table-wrap">
      <table class="sr-table">
        <thead><tr>
          <th>Employee</th><th>Department</th><th>Purpose</th>
          <th>Requested</th><th>Status</th><th style="text-align:center;">Action</th>
        </tr></thead>
        <tbody>
          ${r.data.map(req => `<tr>
            <td><strong>${req.emp_name}</strong><br><span style="font-size:.70rem;color:var(--gray-400);">${req.employee_code||''}</span></td>
            <td>${esc(req.department||'—')}</td>
            <td style="font-size:.78rem;">${esc(req.purpose||'—')}</td>
            <td style="white-space:nowrap;font-size:.75rem;">${fmt(req.requested_at)}</td>
            <td><span style="padding:3px 10px;border-radius:12px;font-size:.70rem;font-weight:800;${statusColors[req.status]||statusColors.Pending}">${statusIco[req.status]} ${req.status}</span></td>
            <td style="text-align:center;white-space:nowrap;">
              ${req.status==='Pending' ? `
                <button class="btn btn-green btn-sm" onclick="resolveSRReq(${req.req_id},'Approved','${esc(req.emp_name)}')">✅ Approve</button>
                <button class="btn btn-danger btn-sm" onclick="resolveSRReq(${req.req_id},'Denied','${esc(req.emp_name)}')">✗ Deny</button>
              ` : `<span style="font-size:.75rem;color:var(--gray-400);">Resolved ${fmt(req.resolved_at)}</span>`}
            </td>
          </tr>`).join('')}
        </tbody>
      </table>
    </div>`;
}

async function resolveSRReq(reqId, status, empName) {
  const note = status === 'Denied' ? (prompt(`Reason for denying ${empName}'s request (optional):`) ?? '') : '';
  const fd = new FormData();
  fd.append('action',     'resolve_sr_request');
  fd.append('req_id',     reqId);
  fd.append('status',     status);
  fd.append('admin_note', note);
  try {
    fd.append('csrf_token', CSRF_TOKEN);
    const r = await fetch(API, {method:'POST', body:fd}).then(r=>r.json());
    showToast((r.success ? '✅' : '❌') + ' ' + (r.message||'Error'), r.success ? 'success' : 'error');
    if (r.success) await loadSRRequests();
  } catch(e) { showToast('❌ Network error.','error'); }
}

// ── Pending badge ──────────────────────────────────────────────────────────

// ── Modal backdrop close ───────────────────────────────────────────────────
document.querySelectorAll('.modal-overlay').forEach(m => {
  m.addEventListener('click', e => { if (e.target===m) m.classList.remove('open'); });
});

// ── Toast ──────────────────────────────────────────────────────────────────
function showToast(msg, type='info') {
  const w = document.getElementById('toastWrap');
  const t = document.createElement('div');
  t.className = 'toast toast-' + type;
  t.textContent = msg;
  w.appendChild(t);
  setTimeout(() => { t.style.opacity='0'; t.style.transition='opacity .3s'; setTimeout(()=>t.remove(),300); }, 3500);
}

// ── Sidebar toggle ─────────────────────────────────────────────────────────

function toggleProfileDropdown(){
  const dd=document.getElementById('tbDropdown');if(dd)dd.classList.toggle('open');
}
document.addEventListener('click',function(e){
  const w=document.getElementById('tbProfileWrap');
  if(w&&!w.contains(e.target)){const dd=document.getElementById('tbDropdown');if(dd)dd.classList.remove('open');}
});


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

// ── Poll admin notifications every 60 seconds ──────────────────────────────
async function pollAdminNotifications() {
  try {
    const r = await fetch('leave_requests.php?action=pending_count').then(r=>r.json());
    if (typeof r.count === 'undefined') return;
    // Re-fetch full notifications and update panel
    const r2 = await fetch('notif_poll.php').then(r=>r.json()).catch(()=>null);
    if (!r2 || !r2.success) {
      // Fallback: just update the count badge
      const unread = getUnreadCount();
      const cnt = document.getElementById('adminNotifCount');
      if (cnt) {
        if (unread > 0) { cnt.textContent = unread; cnt.classList.add('show'); }
        else            { cnt.textContent = '';     cnt.classList.remove('show'); }
      }
      return;
    }
    const read   = getReadNotifs();
    const notifs = r2.notifications || [];
    // Update the panel list
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
    // Update count
    const unread = notifs.filter(n => !read.includes(n.nid)).length;
    const cnt = document.getElementById('adminNotifCount');
    const hd  = document.getElementById('adminNotifHdCount');
    if (cnt) { cnt.textContent = unread||''; unread ? cnt.classList.add('show') : cnt.classList.remove('show'); }
    if (hd)  { hd.textContent = notifs.length + ' item' + (notifs.length!==1?'s':''); }
  } catch(e) {}
}

function getUnreadCount() {
  const read = getReadNotifs();
  return document.querySelectorAll('.notif-item[data-nid]')
    .length - Array.from(document.querySelectorAll('.notif-item[data-nid]'))
    .filter(el => read.includes(el.getAttribute('data-nid'))).length;
}

function adminFmtTime(dt) {
  if (!dt) return '';
  const d = Math.floor((Date.now() - new Date(dt+'Z').getTime()) / 1000);
  if (isNaN(d)) return '';
  if (d < 60)    return 'Just now';
  if (d < 3600)  return Math.floor(d/60) + 'm ago';
  if (d < 86400) return Math.floor(d/3600) + 'h ago';
  return new Date(dt).toLocaleDateString('en-PH',{month:'short',day:'numeric'});
}

// Start polling
document.addEventListener('DOMContentLoaded', function() {
  setTimeout(pollAdminNotifications, 5000); // first poll after 5s
  setInterval(pollAdminNotifications, 60000); // then every 60s
});

function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
}
</script>
  <!-- ══ CHAT WIDGET ══ -->
  <style>
    /* ── Wrap & animation ── */
    .cw-wrap{position:fixed;bottom:24px;right:24px;width:420px;height:600px;background:#fff;border-radius:18px;box-shadow:0 12px 48px rgba(0,0,0,.22);display:none;flex-direction:column;z-index:9999;overflow:hidden;font-family:'Nunito',sans-serif;}
    .cw-wrap.open{display:flex;animation:cwIn .28s cubic-bezier(.34,1.4,.64,1) both;}
    @keyframes cwIn{from{opacity:0;transform:scale(.90) translateY(20px);}to{opacity:1;transform:scale(1) translateY(0);}}
    /* ── Header ── */
    .cw-hd{background:linear-gradient(135deg,#1e40af,#2563eb,#0ea5e9);padding:14px 16px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
    .cw-hd-left{display:flex;align-items:center;gap:10px;}
    .cw-hd-icon{width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-size:1rem;}
    .cw-hd-title{font-size:.95rem;font-weight:800;color:#fff;}
    .cw-hd-sub{font-size:.63rem;color:rgba(255,255,255,.65);margin-top:1px;}
    .cw-hd-close{width:28px;height:28px;border-radius:50%;background:rgba(255,255,255,.15);border:none;color:#fff;font-size:.85rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .2s;}
    .cw-hd-close:hover{background:rgba(255,255,255,.30);}
    /* ── Body ── */
    .cw-body{display:flex;flex:1;overflow:hidden;}
    /* ── Contact sidebar ── */
    .cw-contacts{width:130px;flex-shrink:0;border-right:1.5px solid #e2e8f0;display:flex;flex-direction:column;background:#f8fafc;}
    .cw-contacts-hd{padding:9px 10px 6px;font-size:.58rem;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.10em;border-bottom:1px solid #e2e8f0;}
    .cw-srch{padding:7px 8px;border-bottom:1px solid #e2e8f0;}
    .cw-srch input{width:100%;padding:5px 9px;border:1.5px solid #e2e8f0;border-radius:8px;font-family:'Nunito',sans-serif;font-size:.68rem;outline:none;color:#1e293b;background:#fff;}
    .cw-srch input:focus{border-color:#2563eb;}
    .cw-clist{flex:1;overflow-y:auto;scrollbar-width:thin;scrollbar-color:#e2e8f0 transparent;}
    .cw-ci{display:flex;flex-direction:column;align-items:center;padding:10px 6px;cursor:pointer;border-bottom:1px solid #f1f5f9;transition:background .15s;position:relative;text-align:center;}
    .cw-ci:hover{background:#eff6ff;}
    .cw-ci.active{background:linear-gradient(135deg,#eff6ff,#dbeafe);border-left:3px solid #2563eb;}
    .cw-av{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.70rem;font-weight:800;color:#fff;margin-bottom:3px;flex-shrink:0;box-shadow:0 2px 6px rgba(0,0,0,.15);}
    .cw-cn{font-size:.63rem;font-weight:700;color:#334155;line-height:1.2;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:112px;}
    .cw-cs{font-size:.56rem;color:#94a3b8;margin-top:1px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:112px;}
    .cw-cu{position:absolute;top:5px;right:4px;background:#ef4444;color:#fff;font-size:.50rem;font-weight:800;padding:1px 5px;border-radius:20px;min-width:14px;text-align:center;}
    .cw-cempty{padding:16px 8px;text-align:center;color:#94a3b8;font-size:.70rem;line-height:1.5;}
    /* ── Chat area ── */
    .cw-chat{flex:1;display:flex;flex-direction:column;overflow:hidden;}
    .cw-chat-hd{padding:11px 14px;border-bottom:1.5px solid #e2e8f0;display:flex;align-items:center;gap:9px;flex-shrink:0;background:#fff;}
    .cw-chat-av{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.62rem;font-weight:800;color:#fff;flex-shrink:0;box-shadow:0 2px 6px rgba(0,0,0,.15);}
    .cw-chat-name{font-size:.82rem;font-weight:800;color:#1e293b;}
    .cw-chat-sub{font-size:.60rem;color:#94a3b8;margin-top:1px;}
    /* ── Online dot ── */
    .cw-online{width:8px;height:8px;border-radius:50%;background:#22c55e;margin-left:auto;flex-shrink:0;box-shadow:0 0 0 2px #fff;}
    /* ── Messages ── */
    .cw-msgs{flex:1;overflow-y:auto;padding:14px 12px;display:flex;flex-direction:column;gap:8px;background:#f8fafc;scrollbar-width:thin;scrollbar-color:#e2e8f0 transparent;}
    .cw-nosel{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#94a3b8;text-align:center;padding:20px;background:#f8fafc;}
    .cw-nosel-ic{font-size:2.8rem;opacity:.25;margin-bottom:10px;}
    .cw-nosel h4{font-size:.85rem;font-weight:800;color:#64748b;margin-bottom:4px;}
    .cw-nosel p{font-size:.72rem;line-height:1.5;}
    /* ── Message rows ── */
    .cw-mrow{display:flex;align-items:flex-end;gap:6px;position:relative;}
    .cw-mrow.mine{flex-direction:row-reverse;}
    .cw-mrow:hover .cw-msg-actions{opacity:1;}
    .cw-mav{width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.52rem;font-weight:800;color:#fff;flex-shrink:0;}
    .cw-msg-wrap{display:flex;flex-direction:column;max-width:80%;}
    .cw-mrow.mine .cw-msg-wrap{align-items:flex-end;}
    .cw-bubble{padding:9px 13px;border-radius:16px;font-size:.80rem;line-height:1.55;word-break:break-word;position:relative;}
    .cw-mrow.mine .cw-bubble{background:linear-gradient(135deg,#2563eb,#1e40af);color:#fff;border-bottom-right-radius:4px;}
    .cw-mrow.them .cw-bubble{background:#fff;color:#1e293b;border:1.5px solid #e2e8f0;border-bottom-left-radius:4px;box-shadow:0 1px 4px rgba(0,0,0,.06);}
    .cw-bubble.edited::after{content:'edited';font-size:.55rem;opacity:.6;margin-left:6px;font-style:italic;}
    .cw-bubble.unsent{background:#f1f5f9 !important;color:#94a3b8 !important;border:1.5px dashed #cbd5e1 !important;font-style:italic;box-shadow:none !important;}
    .cw-mt{font-size:.56rem;color:#94a3b8;margin-top:3px;display:flex;align-items:center;gap:4px;}
    .cw-mrow.mine .cw-mt{justify-content:flex-end;}
    .cw-seen{color:#60a5fa;font-size:.58rem;}
    /* ── Message action buttons (hover) ── */
    .cw-msg-actions{position:absolute;top:-28px;display:flex;gap:3px;opacity:0;transition:opacity .15s;z-index:10;}
    .cw-mrow.mine .cw-msg-actions{right:0;}
    .cw-mrow.them .cw-msg-actions{left:0;}
    .cw-act-btn{width:26px;height:26px;border-radius:8px;background:#fff;border:1.5px solid #e2e8f0;display:flex;align-items:center;justify-content:center;font-size:.72rem;cursor:pointer;transition:all .15s;box-shadow:0 2px 6px rgba(0,0,0,.10);}
    .cw-act-btn:hover{background:#eff6ff;border-color:#2563eb;}
    .cw-act-btn.del:hover{background:#fee2e2;border-color:#ef4444;}
    /* ── Edit mode ── */
    .cw-edit-bar{display:none;padding:6px 12px;background:#eff6ff;border-top:1.5px solid #bfdbfe;font-size:.72rem;color:#1e40af;font-weight:700;align-items:center;gap:8px;flex-shrink:0;}
    .cw-edit-bar.show{display:flex;}
    .cw-edit-cancel{margin-left:auto;background:none;border:none;color:#64748b;cursor:pointer;font-size:.80rem;}
    /* ── Date divider ── */
    .cw-ddiv{text-align:center;font-size:.58rem;color:#94a3b8;font-weight:700;padding:4px 0;position:relative;margin:2px 0;}
    .cw-ddiv::before{content:'';position:absolute;top:50%;left:0;right:0;height:1px;background:#e2e8f0;}
    .cw-ddiv span{background:#f8fafc;padding:0 10px;position:relative;}
    /* ── Input area ── */
    .cw-inp-row{display:flex;gap:8px;padding:10px 12px;background:#fff;border-top:1.5px solid #e2e8f0;align-items:flex-end;flex-shrink:0;}
    .cw-inp{flex:1;padding:9px 13px;border:1.5px solid #e2e8f0;border-radius:20px;font-family:'Nunito',sans-serif;font-size:.82rem;color:#1e293b;resize:none;outline:none;max-height:90px;line-height:1.5;transition:border-color .2s;background:#f8fafc;}
    .cw-inp:focus{border-color:#2563eb;background:#fff;}
    .cw-sendbtn{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#2563eb,#1e40af);color:#fff;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.90rem;flex-shrink:0;transition:transform .15s,box-shadow .15s;box-shadow:0 2px 10px rgba(37,99,235,.35);}
    .cw-sendbtn:hover{transform:scale(1.10);box-shadow:0 4px 14px rgba(37,99,235,.45);}
    .cw-sendbtn:disabled{opacity:.5;cursor:default;transform:none;}
  
    .tb-profile-wrap{position:relative;display:inline-block;}
    .tb-profile-btn{width:38px;height:38px;border-radius:50%;background:rgba(255,255,255,.20);border:2px solid rgba(255,255,255,.55);display:flex;align-items:center;justify-content:center;cursor:pointer;overflow:hidden;flex-shrink:0;transition:all .2s;font-size:.80rem;font-weight:800;color:#fff;}
    .tb-profile-btn:hover{background:rgba(255,255,255,.35);border-color:#fff;}
    .tb-dropdown{display:none;position:absolute;top:calc(100% + 10px);right:0;background:#fff;border-radius:12px;box-shadow:0 8px 28px rgba(0,0,0,.18);min-width:200px;z-index:9999;overflow:hidden;}
    .tb-dropdown.open{display:block;}
    .tb-dd-header{padding:12px 16px 8px;border-bottom:1px solid #f1f5f9;background:#f8fafc;}
    .tb-dd-name{font-size:.86rem;font-weight:800;color:#1e293b;}
    .tb-dd-role{font-size:.70rem;color:#94a3b8;margin-top:1px;}
    .tb-dd-item{display:flex;align-items:center;gap:10px;padding:10px 16px;font-size:.83rem;font-weight:600;color:#475569;cursor:pointer;transition:background .15s;text-decoration:none;border:none;background:none;width:100%;font-family:'Nunito',sans-serif;}
    .tb-dd-item:hover{background:#f1f5f9;color:#1e293b;}
    .tb-dd-item.danger{color:#dc2626;}
    .tb-dd-item.danger:hover{background:#fef2f2;}
    .tb-dd-divider{height:1px;background:#f1f5f9;margin:3px 0;}
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
    var fd=new FormData();fd.append('action','send');fd.append('to',cwAct.user_id);fd.append('message',sentMsg);
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
    var fd=new FormData();fd.append('action','edit');fd.append('message_id',msgId);fd.append('message',newMsg);
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
    var fd=new FormData();fd.append('action','unsend');fd.append('message_id',msgId);
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
  </script>


</body>
</html>