<?php
// ============================================================
//  LGU ENRILE HRMS — PDS & SALN Viewer (Admin)
//  File: Admin/pds_saln.php
// ============================================================
session_start();
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
                $stmt = $pdo->prepare("
                    SELECT e.employee_id, e.employee_code, e.first_name, e.last_name,
                           e.position_title, e.employment_type, d.name AS department,
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
    .user-pill{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
    .user-avatar{width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.20);display:flex;align-items:center;justify-content:center;font-weight:800;color:#fff;font-size:.85rem;flex-shrink:0;}
    .user-name{font-size:.82rem;font-weight:700;color:#fff;}
    .user-role{font-size:.65rem;color:rgba(255,255,255,.55);text-transform:capitalize;}
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
    .view-panel{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow-sm);overflow:hidden;}
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
    @media(max-width:900px){.content{grid-template-columns:1fr;}.emp-panel{position:static;}.emp-list{max-height:260px;}.menu-toggle{display:block;}.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.main{margin-left:0;}.topbar{padding:0 16px;}.content{padding:16px;}}

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
    <a class="nav-item" href="service_records.php"><div class="nav-icon">📜</div>Service Records</a>
    <a class="nav-item" href="contracts.php"><div class="nav-icon">📝</div>Contracts</a>
    <div class="nav-label">System</div>
    <a class="nav-item" href="reports.php"><div class="nav-icon">📊</div>Reports</a>
    <a class="nav-item" href="announcements.php"><div class="nav-icon">📢</div>Announcements</a>
    <a class="nav-item" href="archives.php"><div class="nav-icon">🗄️</div>Archives</a>
    <a class="nav-item" href="user_accounts.php"><div class="nav-icon">🔐</div>User Accounts</a>
    <a class="nav-item" href="settings.php"><div class="nav-icon">⚙️</div>Settings</a>
  </nav>
  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="user-avatar"><?= strtoupper(substr($_SESSION['user_name']??'A',0,2)) ?></div>
      <div class="user-info">
        <strong><?= htmlspecialchars($_SESSION['user_name']??'') ?></strong>
        <span><?= htmlspecialchars($_SESSION['user_role']??'') ?></span>
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
    @media print{.top-bar{display:none;}.view-wrap{padding:8px;}}
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
  if(typeof buildSALNPrintHTML === 'undefined') { console.error('buildSALNPrintHTML not loaded'); return; }
  buildSALNPrintHTML(d, emp, true);
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

  const dec = sj(d.declarant_info)      || {};
  const sp  = sj(d.spouse_info)         || {};
  const ch  = sj(d.children_info)       || [];
  const rp  = sj(d.real_properties)     || [];
  const pp  = sj(d.personal_properties) || [];
  const lb  = sj(d.liabilities)         || [];
  const biz = sj(d.biz_interests)       || [];
  const rel = sj(d.relatives_govt)      || [];

  const isAssumption = d.filing_type==='Assumption';
  const isAnnual     = d.filing_type==='Annual';
  const isExit       = d.filing_type==='Exit';
  const isJoint      = d.filing_mode==='Joint';
  const isSeparate   = d.filing_mode==='Separate';
  const isNA         = !isJoint && !isSeparate;

  const C  = (txt,s) => `<td style="border:0.5pt solid #000;padding:1.5pt 3pt;font-size:6.5pt;vertical-align:middle;${s||''}">${v(txt)}&nbsp;</td>`;
  const H  = (txt,s) => `<td style="border:0.5pt solid #000;padding:1.5pt 3pt;font-size:5.5pt;font-weight:700;background:#e0e0e0;vertical-align:middle;${s||''}">${txt}</td>`;
  const ul = (val,w) => `<span style="display:inline-block;min-width:${w||'70pt'};border-bottom:0.5pt solid #000;font-size:7pt;font-weight:700;padding-bottom:0.5pt;">${v(val)}&nbsp;</span>`;

  // Real property rows — min 4
  const realRows = padR(rp, 4, p => `<tr style="height:13pt;">
    ${C(p.description,'width:17%;')}${C(p.kind,'width:12%;')}${C(p.location,'width:14%;')}
    ${C(fmtM(p.assessed_value),'width:9%;text-align:right;')}${C(fmtM(p.market_value),'width:9%;text-align:right;')}
    ${C(p.acq_year,'width:7%;text-align:center;')}${C(p.acq_mode,'width:9%;')}${C(fmtM(p.acq_cost),'width:9%;text-align:right;')}
  </tr>`);

  // Personal property rows — min 6
  const persRows = padR(pp, 6, p => `<tr style="height:12pt;">
    ${C(p.description,'width:60%;')}${C(p.acq_year,'width:18%;text-align:center;')}${C(fmtM(p.acq_cost),'width:22%;text-align:right;')}
  </tr>`);

  // Liability rows — min 5
  const liabRows = padR(lb, 5, l => `<tr style="height:12pt;">
    ${C(l.nature,'width:35%;')}${C(l.creditor,'width:40%;')}${C(fmtM(l.balance),'width:25%;text-align:right;')}
  </tr>`);

  // Business interest rows — min 3
  const bizRows = padR(d.no_biz?[]:biz, 3, b => `<tr style="height:12pt;">
    ${C(b.entity_name,'width:28%;')}${C(b.biz_address,'width:25%;')}${C(b.nature,'width:28%;')}${C(fmtD(b.date_acquired),'width:19%;text-align:center;')}
  </tr>`);

  // Relatives rows — min 5
  const relRows = padR(d.no_rel?[]:rel, 5, r => `<tr style="height:12pt;">
    ${C(r.name,'width:25%;')}${C(r.relationship,'width:15%;')}${C(r.position,'width:25%;')}${C(r.agency_address,'width:35%;')}
  </tr>`);

  // Children rows — min 3
  const childRows = padR(ch, 3, c => `<tr style="height:13pt;">
    <td style="border:0.5pt solid #000;padding:1.5pt 3pt;font-size:7pt;width:75%;">${v(c.name)}&nbsp;</td>
    <td style="border:0.5pt solid #000;padding:1.5pt 3pt;font-size:7pt;text-align:center;width:25%;">${v(c.age)}&nbsp;</td>
  </tr>`);

  const css = `
    *{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'Arial Narrow',Arial,sans-serif;font-size:7pt;color:#000;background:#fff;}
    @page{size:8.5in 13in;margin:5mm 6mm;}
    @media print{body{margin:0;}}
    table{border-collapse:collapse;width:100%;}
    .page-title{text-align:center;font-size:9.5pt;font-weight:700;text-transform:uppercase;letter-spacing:.04em;}
    .page-sub{text-align:center;font-size:6.5pt;}
    .hdr{background:#e0e0e0;font-size:5.5pt;font-weight:700;text-align:center;padding:1.5pt 2pt;border:0.5pt solid #000;vertical-align:middle;}
    .lbl{font-size:5pt;font-weight:700;text-transform:uppercase;color:#444;}
    .val{font-size:7pt;font-weight:700;border-bottom:0.4pt solid #555;min-height:9pt;display:block;}
    .yn{display:inline-block;border:0.5pt solid #000;width:7pt;height:7pt;text-align:center;font-size:6pt;line-height:7pt;margin-right:1pt;}
    .underline{border-bottom:0.5pt solid #000;display:inline-block;min-width:50pt;}
  `;

  const html = `<!DOCTYPE html>
<html><head>
  <meta charset="UTF-8"/>
  <title>SALN ${v(d.saln_year)} — ${v(dec.lastname)}, ${v(dec.firstname)}</title>
  <style>${css}</style>
</head><body>

<!-- ══ HEADER ══ -->
<div style="text-align:center;margin-bottom:3pt;">
  <div style="font-size:6.5pt;color:#555;">2025 SALN Form &nbsp;·&nbsp; Per CSC Resolution No. 2500632 &nbsp;·&nbsp; Promulgated 25 June 2025</div>
  <div class="page-title" style="margin-top:1pt;">Sworn Statement of Assets, Liabilities, and Net Worth</div>
  <div class="page-sub">(As required by R.A. No. 6713)</div>
</div>

<!-- ══ COMPLIANCE FOR ══ -->
<table style="margin-bottom:2pt;">
  <tr>
    <td style="border:0.5pt solid #000;padding:2pt 4pt;font-size:6.5pt;width:18%;font-weight:700;">COMPLIANCE FOR:</td>
    <td style="border:0.5pt solid #000;padding:2pt 5pt;font-size:6.5pt;">
      <span class="yn">${cb(isAssumption)}</span> Assumption of office as of ${isAssumption?ul(fmtD(d.as_of_date),'80pt'):'________________________________'}
      &nbsp;&nbsp;&nbsp;
      <span class="yn">${cb(isAnnual)}</span> Annual filing as of December 31, ${isAnnual?`<strong>${v(d.saln_year)}</strong>`:'_________'}
      &nbsp;&nbsp;&nbsp;
      <span class="yn">${cb(isExit)}</span> Exit as of ${isExit?ul(fmtD(d.as_of_date),'80pt'):'________________________________'}
    </td>
  </tr>
</table>

<!-- ══ JOINT / SEPARATE FILING ══ -->
<table style="margin-bottom:2pt;">
  <tr>
    <td style="border:0.5pt solid #000;padding:2pt 5pt;font-size:6pt;">
      SPOUSES WHO ARE BOTH PUBLIC OFFICIALS/EMPLOYEES MAY FILE THE SALN JOINTLY OR SEPARATELY. THE DECLARANT SHALL CHECK THE APPROPRIATE BOX:
      &nbsp;&nbsp; <span class="yn">${cb(isJoint)}</span> <strong>Joint Filing</strong>
      &nbsp;&nbsp; <span class="yn">${cb(isSeparate)}</span> <strong>Separate Filing</strong>
      &nbsp;&nbsp; <span class="yn">${cb(isNA)}</span> <strong>Not Applicable</strong>
    </td>
  </tr>
  <tr>
    <td style="border:0.5pt solid #000;border-top:none;padding:2pt 5pt;font-size:6pt;">
      IF WITH MULTIPLE MARRIAGES, INDICATE NAME(S) OF SPOUSES, OTHERWISE CHECK "NOT APPLICABLE":
      &nbsp;&nbsp; ${d.multi_spouse_na?`<span class="yn">☑</span> Not Applicable`:ul(v(d.multi_spouse),'200pt')}
    </td>
  </tr>
</table>

<!-- ══ DECLARANT / SPOUSE ══ -->
<table style="margin-bottom:2pt;">
  <tr>
    <td style="border:0.5pt solid #000;width:50%;padding:0;vertical-align:top;">
      <table style="width:100%;border-collapse:collapse;">
        <tr><td colspan="2" style="border-bottom:0.5pt solid #000;padding:1.5pt 3pt;font-size:6pt;font-weight:700;background:#f0f0f0;">DECLARANT:</td></tr>
        <tr>
          <td style="border-bottom:0.5pt solid #000;padding:1.5pt 3pt;width:35%;">
            <div class="lbl">(Family Name)</div><div class="val">${v(dec.lastname)}</div>
          </td>
          <td style="border-bottom:0.5pt solid #000;border-left:0.5pt solid #000;padding:1.5pt 3pt;width:35%;">
            <div class="lbl">(First Name)</div><div class="val">${v(dec.firstname)}</div>
          </td>
          <td style="border-bottom:0.5pt solid #000;border-left:0.5pt solid #000;padding:1.5pt 3pt;width:10%;">
            <div class="lbl">(M.I.)</div><div class="val">${v(dec.mi)}</div>
          </td>
          <td style="border-bottom:0.5pt solid #000;border-left:0.5pt solid #000;padding:1.5pt 3pt;">
            <div class="lbl">Position</div><div class="val" style="font-size:6.5pt;">${v(dec.position)}</div>
          </td>
        </tr>
        <tr>
          <td colspan="2" style="padding:1.5pt 3pt;">
            <div class="lbl">Agency/Office</div><div style="font-size:6.5pt;font-weight:600;min-height:9pt;">${v(dec.agency)}</div>
          </td>
          <td colspan="2" style="border-left:0.5pt solid #000;padding:1.5pt 3pt;">
            <div class="lbl">Office Address</div><div style="font-size:6.5pt;font-weight:600;min-height:9pt;">${v(dec.office_addr)}</div>
          </td>
        </tr>
      </table>
    </td>
    <td style="border:0.5pt solid #000;border-left:none;width:50%;padding:0;vertical-align:top;">
      <table style="width:100%;border-collapse:collapse;">
        <tr><td colspan="2" style="border-bottom:0.5pt solid #000;padding:1.5pt 3pt;font-size:6pt;font-weight:700;background:#f0f0f0;">SPOUSE:</td></tr>
        <tr>
          <td style="border-bottom:0.5pt solid #000;padding:1.5pt 3pt;width:35%;">
            <div class="lbl">(Family Name)</div><div class="val">${v(sp.lastname)}</div>
          </td>
          <td style="border-bottom:0.5pt solid #000;border-left:0.5pt solid #000;padding:1.5pt 3pt;width:35%;">
            <div class="lbl">(First Name)</div><div class="val">${v(sp.firstname)}</div>
          </td>
          <td style="border-bottom:0.5pt solid #000;border-left:0.5pt solid #000;padding:1.5pt 3pt;width:10%;">
            <div class="lbl">(M.I.)</div><div class="val">${v(sp.mi)}</div>
          </td>
          <td style="border-bottom:0.5pt solid #000;border-left:0.5pt solid #000;padding:1.5pt 3pt;">
            <div class="lbl">Position</div><div class="val" style="font-size:6.5pt;">${v(sp.position)}</div>
          </td>
        </tr>
        <tr>
          <td colspan="2" style="padding:1.5pt 3pt;">
            <div class="lbl">Agency/Office</div><div style="font-size:6.5pt;font-weight:600;min-height:9pt;">${v(sp.agency)}</div>
          </td>
          <td colspan="2" style="border-left:0.5pt solid #000;padding:1.5pt 3pt;">
            <div class="lbl">Office Address</div><div style="font-size:6.5pt;font-weight:600;min-height:9pt;">${v(sp.office_addr)}</div>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>

<!-- ══ CHILDREN ══ -->
<table style="margin-bottom:2pt;">
  <tr>
    <th colspan="2" style="border:0.5pt solid #000;padding:2pt 5pt;font-size:6.5pt;font-weight:700;text-align:left;background:#f0f0f0;">
      UNMARRIED CHILDREN BELOW EIGHTEEN (18) YEARS OF AGE LIVING IN DECLARANT'S HOUSEHOLD
    </th>
  </tr>
  <tr>
    ${H('NAME OF CHILD','width:75%;text-align:left;')}
    ${H('AGE','width:25%;text-align:center;')}
  </tr>
  ${childRows}
</table>

<!-- ══ ASSETS HEADER ══ -->
<table style="margin-bottom:0;">
  <tr>
    <td style="border:0.5pt solid #000;padding:2pt 5pt;font-size:7pt;font-weight:700;text-transform:uppercase;">
      Assets, Liabilities and Net Worth
      <span style="font-size:6pt;font-weight:400;">(Including those of the spouse and unmarried children below 18 years of age living in declarant's household)</span>
    </td>
  </tr>
</table>

<!-- ══ REAL PROPERTIES ══ -->
<table style="margin-bottom:0;">
  <tr>
    <td colspan="8" style="border:0.5pt solid #000;border-top:none;padding:1.5pt 4pt;font-size:6.5pt;font-weight:700;">
      1. &nbsp; ASSETS &nbsp;&nbsp; a. &nbsp; Real Properties
    </td>
  </tr>
  <tr>
    ${H('DESCRIPTION<br><span style="font-weight:400;font-size:5pt;">(e.g. lot, house and lot, condominium)</span>','width:17%;')}
    ${H('KIND<br><span style="font-weight:400;font-size:5pt;">(residential, commercial, agricultural)</span>','width:12%;')}
    ${H('EXACT LOCATION<br><span style="font-weight:400;font-size:5pt;">(per Tax Declaration)</span>','width:14%;')}
    ${H('ASSESSED VALUE<br><span style="font-weight:400;font-size:5pt;">(per Tax Dec.)</span>','width:9%;text-align:center;')}
    ${H('CURRENT FAIR MARKET VALUE','width:9%;text-align:center;')}
    <td style="border:0.5pt solid #000;padding:0;width:19%;background:#e0e0e0;vertical-align:top;">
      <div style="text-align:center;font-size:5.5pt;font-weight:700;padding:1.5pt 2pt;border-bottom:0.5pt solid #000;">ACQUISITION</div>
      <table style="width:100%;border-collapse:collapse;"><tr>
        <td style="border-right:0.5pt solid #000;padding:1pt;font-size:5.5pt;font-weight:700;text-align:center;width:33%;">YEAR</td>
        <td style="border-right:0.5pt solid #000;padding:1pt;font-size:5.5pt;font-weight:700;text-align:center;width:34%;">MODE</td>
        <td style="padding:1pt;font-size:5.5pt;font-weight:700;text-align:center;width:33%;">COST</td>
      </tr></table>
    </td>
  </tr>
  ${realRows}
  <tr style="background:#f5f5f5;">
    <td colspan="5" style="border:0.5pt solid #000;padding:2pt 4pt;font-size:6.5pt;font-weight:700;text-align:right;">Subtotal:</td>
    <td style="border:0.5pt solid #000;padding:2pt 4pt;font-size:7pt;font-weight:700;text-align:right;">${fmtM(d.real_subtotal)}&nbsp;</td>
    <td style="border:0.5pt solid #000;padding:2pt 4pt;font-size:6.5pt;font-weight:700;text-align:right;">Subtotal:</td>
    <td style="border:0.5pt solid #000;padding:2pt 4pt;font-size:7pt;font-weight:700;text-align:right;">${fmtM(d.real_subtotal)}&nbsp;</td>
  </tr>
</table>

<!-- ══ PERSONAL PROPERTIES ══ -->
<table style="margin-bottom:0;">
  <tr>
    <td colspan="3" style="border:0.5pt solid #000;border-top:none;padding:1.5pt 4pt;font-size:6.5pt;font-weight:700;">
      b. &nbsp; Personal Properties
    </td>
  </tr>
  <tr>
    ${H('DESCRIPTION','width:60%;')}
    ${H('ACQUISITION YEAR','width:18%;text-align:center;')}
    ${H('ACQUISITION COST/AMOUNT','width:22%;text-align:center;')}
  </tr>
  ${persRows}
  <tr style="background:#f5f5f5;">
    <td colspan="2" style="border:0.5pt solid #000;padding:2pt 4pt;font-size:6.5pt;font-weight:700;text-align:right;">Subtotal:</td>
    <td style="border:0.5pt solid #000;padding:2pt 4pt;font-size:7pt;font-weight:700;text-align:right;">${fmtM(d.personal_subtotal)}&nbsp;</td>
  </tr>
  <tr style="background:#dde8f0;">
    <td colspan="2" style="border:0.5pt solid #000;padding:2pt 4pt;font-size:7pt;font-weight:700;text-align:right;text-transform:uppercase;">Total Assets:</td>
    <td style="border:0.5pt solid #000;padding:2pt 4pt;font-size:7.5pt;font-weight:800;text-align:right;">${fmtM(d.total_assets)}&nbsp;</td>
  </tr>
</table>

<!-- ══ LIABILITIES ══ -->
<table style="margin-bottom:0;">
  <tr>
    <td colspan="3" style="border:0.5pt solid #000;border-top:none;padding:1.5pt 4pt;font-size:6.5pt;font-weight:700;text-transform:uppercase;">
      Liabilities
    </td>
  </tr>
  <tr>
    ${H('NATURE','width:35%;')}
    ${H('NAME OF CREDITORS','width:40%;')}
    ${H('OUTSTANDING BALANCE','width:25%;text-align:center;')}
  </tr>
  ${liabRows}
  <tr style="background:#f5f5f5;">
    <td colspan="2" style="border:0.5pt solid #000;padding:2pt 4pt;font-size:6.5pt;font-weight:700;text-align:right;text-transform:uppercase;">Total Liabilities:</td>
    <td style="border:0.5pt solid #000;padding:2pt 4pt;font-size:7pt;font-weight:700;text-align:right;">${fmtM(d.total_liabilities)}&nbsp;</td>
  </tr>
  <tr style="background:#dde8f0;">
    <td colspan="2" style="border:0.5pt solid #000;padding:2pt 4pt;font-size:7pt;font-weight:700;text-transform:uppercase;">Net Worth (Total Assets less Total Liabilities) =</td>
    <td style="border:0.5pt solid #000;padding:2pt 4pt;font-size:7.5pt;font-weight:800;text-align:right;">${fmtM(d.net_worth)}&nbsp;</td>
  </tr>
</table>

<!-- ══ BUSINESS INTERESTS ══ -->
<table style="margin-bottom:0;margin-top:3pt;">
  <tr>
    <td colspan="4" style="border:0.5pt solid #000;padding:1.5pt 4pt;font-size:6.5pt;font-weight:700;text-transform:uppercase;">
      Business Interests and Financial Connections
      <span style="font-weight:400;font-size:5.5pt;">(of Declarant / Declarant's Spouse / Unmarried Children Below 18 in Declarant's Household)</span>
    </td>
  </tr>
  ${d.no_biz
    ? `<tr><td colspan="4" style="border:0.5pt solid #000;border-top:none;padding:3pt 5pt;font-size:6.5pt;"><span class="yn">☑</span> I/We do not have any business interest or financial connection.</td></tr>`
    : `<tr><td colspan="4" style="border:0.5pt solid #000;border-top:none;padding:2pt 4pt;font-size:6pt;"><span class="yn">☐</span> I/We do not have any business interest or financial connection.</td></tr>
       <tr>${H('NAME OF ENTITY/BUSINESS ENTERPRISE','width:28%;')}${H('BUSINESS ADDRESS','width:25%;')}${H('NATURE OF BUSINESS INTEREST / FINANCIAL CONNECTION','width:28%;')}${H('DATE ACQUIRED','width:19%;text-align:center;')}</tr>
       ${bizRows}`}
</table>

<!-- ══ RELATIVES IN GOVT ══ -->
<table style="margin-bottom:3pt;margin-top:3pt;">
  <tr>
    <td colspan="4" style="border:0.5pt solid #000;padding:1.5pt 4pt;font-size:6.5pt;font-weight:700;text-transform:uppercase;">
      Relatives in the Government Service
      <span style="font-weight:400;font-size:5.5pt;">(Within the Fourth Degree of Consanguinity or Affinity. Include also Bilas, Balae and Inso.)</span>
    </td>
  </tr>
  ${d.no_rel
    ? `<tr><td colspan="4" style="border:0.5pt solid #000;border-top:none;padding:3pt 5pt;font-size:6.5pt;"><span class="yn">☑</span> I/We do not know of any relative/s in the government service.</td></tr>`
    : `<tr><td colspan="4" style="border:0.5pt solid #000;border-top:none;padding:2pt 4pt;font-size:6pt;"><span class="yn">☐</span> I/We do not know of any relative/s in the government service.</td></tr>
       <tr>${H('NAME OF RELATIVE','width:25%;')}${H('RELATIONSHIP','width:15%;')}${H('POSITION','width:25%;')}${H('AGENCY/OFFICE AND ADDRESS','width:35%;')}</tr>
       ${relRows}`}
</table>

<!-- ══ DECLARATION ══ -->
<table style="margin-bottom:3pt;">
  <tr>
    <td style="border:0.5pt solid #000;padding:3pt 5pt;font-size:6pt;line-height:1.6;">
      I hereby certify that these are true and correct statements of my assets, liabilities, net worth, business interests and financial connections, including those of my spouse and unmarried children below eighteen (18) years of age living in my household, and that to the best of my knowledge, the above-enumerated are names of my relatives in the government within the fourth civil degree of consanguinity or affinity.
    </td>
  </tr>
  <tr>
    <td style="border:0.5pt solid #000;border-top:none;padding:3pt 5pt;font-size:6pt;line-height:1.6;">
      I hereby authorize the Ombudsman or his/her duly authorized representative to obtain and secure from all appropriate government agencies, including the Bureau of Internal Revenue, such documents that may show my assets, liabilities, net worth, business interests and financial connections, to include those of my spouse and unmarried children below 18 years of age living with me in my household.
    </td>
  </tr>
</table>

<!-- ══ SIGNATURE / GOV ID AREA ══ -->
<table style="margin-bottom:3pt;">
  <tr>
    <td style="border:0.5pt solid #000;width:50%;padding:0;vertical-align:top;">
      <table style="width:100%;border-collapse:collapse;">
        <tr><td colspan="2" style="height:32pt;padding:2pt 4pt;font-size:6pt;color:#666;vertical-align:bottom;">(wet signature/e-signature/digital certificate)</td></tr>
        <tr><td colspan="2" style="border-top:0.5pt solid #000;padding:2pt 4pt;font-size:6.5pt;font-weight:700;text-align:center;">Signature of Declarant</td></tr>
        <tr>
          <td style="border-top:0.5pt solid #000;padding:2pt 4pt;font-size:6pt;">
            <div style="font-size:5.5pt;color:#666;">Government Issued ID:</div>
            <div style="font-size:7pt;font-weight:700;border-bottom:0.4pt solid #999;min-height:11pt;">${v(d.gov_id)}&nbsp;</div>
          </td>
          <td style="border-top:0.5pt solid #000;border-left:0.5pt solid #000;padding:2pt 4pt;font-size:6pt;">
            <div style="font-size:5.5pt;color:#666;">ID No.:</div>
            <div style="font-size:7pt;font-weight:700;border-bottom:0.4pt solid #999;min-height:11pt;">${v(d.gov_id_no)}&nbsp;</div>
          </td>
        </tr>
        <tr>
          <td colspan="2" style="border-top:0.5pt solid #000;padding:2pt 4pt;font-size:6pt;">
            <div style="font-size:5.5pt;color:#666;">Date Issued:</div>
            <div style="font-size:7pt;font-weight:700;border-bottom:0.4pt solid #999;min-height:11pt;">${v(d.gov_id_issued)?fmtD(d.gov_id_issued):''}&nbsp;</div>
          </td>
        </tr>
      </table>
    </td>
    <td style="border:0.5pt solid #000;border-left:none;width:50%;padding:0;vertical-align:top;">
      <table style="width:100%;border-collapse:collapse;">
        <tr><td colspan="2" style="height:32pt;padding:2pt 4pt;font-size:6pt;color:#666;vertical-align:bottom;">(wet signature/e-signature/digital certificate)</td></tr>
        <tr><td colspan="2" style="border-top:0.5pt solid #000;padding:2pt 4pt;font-size:6.5pt;font-weight:700;text-align:center;">Signature of Declarant</td></tr>
        <tr>
          <td style="border-top:0.5pt solid #000;padding:2pt 4pt;font-size:6pt;">
            <div style="font-size:5.5pt;color:#666;">Government Issued ID:</div>
            <div style="font-size:7pt;font-weight:700;border-bottom:0.4pt solid #999;min-height:11pt;">${v(d.gov_id)}&nbsp;</div>
          </td>
          <td style="border-top:0.5pt solid #000;border-left:0.5pt solid #000;padding:2pt 4pt;font-size:6pt;">
            <div style="font-size:5.5pt;color:#666;">ID No.:</div>
            <div style="font-size:7pt;font-weight:700;border-bottom:0.4pt solid #999;min-height:11pt;">${v(d.gov_id_no)}&nbsp;</div>
          </td>
        </tr>
        <tr>
          <td colspan="2" style="border-top:0.5pt solid #000;padding:2pt 4pt;font-size:6pt;">
            <div style="font-size:5.5pt;color:#666;">Date Issued:</div>
            <div style="font-size:7pt;font-weight:700;border-bottom:0.4pt solid #999;min-height:11pt;">${v(d.gov_id_issued)?fmtD(d.gov_id_issued):''}&nbsp;</div>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>

<!-- ══ OATH ══ -->
<table>
  <tr>
    <td style="border:0.5pt solid #000;padding:3pt 5pt;font-size:6pt;line-height:1.8;">
      SUBSCRIBED AND SWORN to before me this <span class="underline">&nbsp;</span> day of <span class="underline">&nbsp;</span>,
      affiant exhibiting to me the above-stated government-issued identification card.
      <br/><br/>
      <div style="margin-top:4pt;border-top:0.5pt solid #000;width:40%;padding-top:2pt;font-size:6pt;text-align:center;">(wet signature/e-signature/digital certificate except for notary public)</div>
      <div style="border-top:0.5pt solid #000;width:40%;padding-top:2pt;font-size:6.5pt;font-weight:700;text-align:center;margin-top:2pt;">Person Administering Oath</div>
    </td>
  </tr>
</table>

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
function buildPDSPrintHTML(d, empRow) {
  const pi  = safeJson(d.personal_info)     || {};
  const fb  = safeJson(d.family_background) || {};
  const ed  = safeJson(d.education)         || [];
  const cs  = safeJson(d.civil_service)     || [];
  const we  = safeJson(d.work_experience)   || [];
  const vw  = safeJson(d.voluntary_work)    || [];
  const tr  = safeJson(d.trainings)         || [];
  const oi  = safeJson(d.other_info)        || {};
  const q4  = safeJson(d.q4_questions)      || {};
  const refs = safeJson(d.pds_references || d['references']) || [];

  const v    = x => (x===null||x===undefined||x===''||x==='null'||x==='undefined') ? '' : String(x);
  const fmtD = dt => { if(!dt||dt==='0000-00-00') return ''; try{ const x=new Date(dt); return isNaN(x)?dt:(x.getMonth()+1).toString().padStart(2,'0')+'/'+x.getDate().toString().padStart(2,'0')+'/'+x.getFullYear(); }catch(e){return dt||'';} };
  const cb   = val => (val===true||val==='1'||val===1||val==='Yes'||val==='true') ? '☑' : '☐';
  const pad  = (arr,n) => { const a=[...(arr||[])]; while(a.length<n) a.push({}); return a; };

  const resAddr  = [pi.res_housenum,pi.res_street,pi.res_subdivision,pi.res_barangay,pi.res_city,pi.res_province].filter(Boolean).join(', ');
  const permAddr = pi.perm_same ? resAddr : [pi.perm_housenum,pi.perm_street,pi.perm_subdivision,pi.perm_barangay,pi.perm_city,pi.perm_province].filter(Boolean).join(', ');

  const edPad  = pad(ed,5);
  const csPad  = pad(cs,4);
  const wePad  = pad(we,9);
  const vwPad  = pad(vw,5);
  const trPad  = pad(tr,5);
  const refPad = pad(refs,3);

  // ── Shared CSS ─────────────────────────────────────────────────
  const css = `
    *{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'Arial Narrow',Arial,sans-serif;font-size:6.5pt;color:#000;background:#fff;}
    @page{size:A4 portrait;margin:5mm 6mm;}
    @media print{body{margin:0;}}
    table{border-collapse:collapse;width:100%;}
    td,th{border:0.5pt solid #000;padding:1.5pt 2.5pt;vertical-align:top;font-size:6.5pt;}
    .lbl{font-size:5pt;font-weight:700;text-transform:uppercase;color:#444;display:block;line-height:1.1;margin-bottom:0.5pt;}
    .val{font-size:7pt;font-weight:700;min-height:9pt;display:block;padding:0.5pt 0;word-break:break-word;}
    .sec{background:#000;color:#fff;font-size:6pt;font-weight:700;text-transform:uppercase;letter-spacing:.08em;padding:2pt 4pt;}
    .hdr{background:#e0e0e0;font-size:6pt;font-weight:700;text-align:center;padding:1.5pt 2pt;}
    .page{padding:0;page-break-after:always;}
    .page:last-child{page-break-after:auto;}
    .sig-box{height:20pt;border-top:0.5pt solid #000;margin-top:2pt;}
    .pg-num{font-size:5.5pt;font-style:italic;text-align:right;margin-top:2pt;}
    .yn{display:inline-block;border:0.5pt solid #000;width:7pt;height:7pt;text-align:center;font-size:6pt;line-height:7pt;margin-right:1pt;}
    .indent{padding-left:6pt;}
    .photo{border:0.5pt solid #000;width:100%;height:50pt;display:flex;align-items:center;justify-content:center;font-size:5.5pt;color:#666;text-align:center;}
    .thumb{border:0.5pt solid #000;width:100%;height:50pt;display:flex;align-items:center;justify-content:center;font-size:5pt;color:#666;text-align:center;}
  `;

  // ── Page header (used on each page) ─────────────────────────────
  const pageHdr = `
  <table style="margin-bottom:1pt;border-collapse:collapse;width:100%;">
    <tr>
      <td style="border:0.5pt solid #000;padding:2pt 5pt;width:18%;vertical-align:top;">
        <div style="font-size:6.5pt;font-weight:700;font-style:italic;">CS Form No. 212</div>
        <div style="font-size:6pt;font-style:italic;">Revised 2025</div>
      </td>
      <td style="border:0.5pt solid #000;border-left:none;padding:3pt 5pt;text-align:center;vertical-align:middle;">
        <div style="font-size:14pt;font-weight:900;text-transform:uppercase;letter-spacing:.04em;font-family:Arial,sans-serif;">Personal Data Sheet</div>
      </td>
    </tr>
  </table>
  <table style="margin-bottom:1pt;border-collapse:collapse;width:100%;">
    <tr>
      <td style="border:0.5pt solid #000;padding:2pt 4pt;font-size:5.5pt;line-height:1.5;" colspan="12">
        <strong><em>WARNING: Any misrepresentation made in the Personal Data Sheet and the Work Experience Sheet shall cause the filing of administrative/criminal case/s against the person concerned.</em></strong><br>
        <strong><em>READ THE ATTACHED GUIDE TO FILLING OUT THE PERSONAL DATA SHEET (PDS) BEFORE ACCOMPLISHING THE PDS FORM.</em></strong><br>
        Print legibly if accomplished through own handwriting. Tick appropriate boxes <span style="border:0.5pt solid #000;display:inline-block;width:7pt;height:7pt;vertical-align:middle;font-size:6pt;text-align:center;">✓</span> and use separate sheet if necessary. Indicate N/A if not applicable. &nbsp;<strong>DO NOT ABBREVIATE.</strong>
      </td>
    </tr>
  </table>`;

  // ── PAGE 1 ──────────────────────────────────────────────────────
  const page1 = `<div class="page">
  ${pageHdr}
  <!-- I. Personal Information -->
  <table><tr><td colspan="12" class="sec">I. Personal Information</td></tr></table>

  <!-- Row 1: Surname / First Name / Name Ext -->
  <table>
    <tr>
      <td style="width:4%;"><span class="lbl">1.</span></td>
      <td style="width:28%;"><span class="lbl">Surname</span><span class="val">${v(pi.last_name)}</span></td>
      <td style="width:4%;"><span class="lbl">2.</span></td>
      <td style="width:28%;"><span class="lbl">First Name</span><span class="val">${v(pi.first_name)}</span></td>
      <td style="width:18%;"><span class="lbl">Name Extension (Jr., Sr.)</span><span class="val">${v(pi.suffix)}</span></td>
      <td rowspan="6" style="width:18%;padding:2pt;">
        <span class="lbl" style="margin-bottom:2pt;display:block;">16. Citizenship</span>
        <div style="margin:1pt 0;"><span class="yn">${pi.citizenship==='Filipino'||!pi.citizenship?'✓':''}</span><span style="font-size:6pt;"> Filipino</span></div>
        <div style="margin:1pt 0;"><span class="yn">${pi.citizenship==='Dual Citizenship'?'✓':''}</span><span style="font-size:6pt;"> Dual Citizenship</span></div>
        <div style="font-size:5pt;margin-top:3pt;">If dual citizenship, indicate details:</div>
        <div style="font-size:5pt;margin-top:1pt;">By:</div>
        <div style="font-size:6.5pt;font-weight:700;border-bottom:0.4pt solid #000;min-height:8pt;">${v(pi.dual_by)}</div>
        <div style="font-size:5pt;margin-top:2pt;">Country:</div>
        <div style="font-size:6.5pt;font-weight:700;border-bottom:0.4pt solid #000;min-height:8pt;">${v(pi.dual_country)}</div>
      </td>
    </tr>
    <tr>
      <td></td>
      <td><span class="lbl">Middle Name</span><span class="val">${v(pi.middle_name)}</span></td>
      <td></td><td colspan="2"></td>
    </tr>
    <tr>
      <td><span class="lbl">3.</span></td>
      <td><span class="lbl">Date of Birth (mm/dd/yyyy)</span><span class="val">${fmtD(pi.dob)}</span></td>
      <td><span class="lbl">5.</span></td>
      <td colspan="2">
        <span class="lbl">Sex at Birth</span>
        <span style="font-size:6pt;"><span class="yn">${pi.sex==='Male'?'✓':''}</span> Male &nbsp; <span class="yn">${pi.sex==='Female'?'✓':''}</span> Female</span>
      </td>
    </tr>
    <tr>
      <td><span class="lbl">4.</span></td>
      <td><span class="lbl">Place of Birth</span><span class="val">${v(pi.pob)}</span></td>
      <td><span class="lbl">6.</span></td>
      <td colspan="2">
        <span class="lbl">Civil Status</span>
        <div style="font-size:5.5pt;line-height:1.7;">
          <span class="yn">${pi.civil_status==='Single'?'✓':''}</span> Single &nbsp;
          <span class="yn">${pi.civil_status==='Married'?'✓':''}</span> Married &nbsp;
          <span class="yn">${pi.civil_status==='Widow/er'?'✓':''}</span> Widow/er<br>
          <span class="yn">${pi.civil_status==='Separated'?'✓':''}</span> Separated &nbsp;
          <span class="yn">${pi.civil_status==='Solo Parent'?'✓':''}</span> Solo Parent &nbsp;
          <span class="yn">${pi.civil_status==='Others'?'✓':''}</span> Others
        </div>
      </td>
    </tr>
    <tr>
      <td><span class="lbl">7.</span></td>
      <td><span class="lbl">Height (m)</span><span class="val">${v(pi.height)}</span></td>
      <td><span class="lbl">10.</span></td>
      <td colspan="2"><span class="lbl">UMID ID No.</span><span class="val">${v(pi.umid)}</span></td>
    </tr>
    <tr>
      <td><span class="lbl">8.</span></td>
      <td><span class="lbl">Weight (kg)</span><span class="val">${v(pi.weight)}</span></td>
      <td><span class="lbl">11.</span></td>
      <td colspan="2"><span class="lbl">PAG-IBIG ID No.</span><span class="val">${v(pi.pagibig)}</span></td>
    </tr>
  </table>

  <!-- Row: Blood type, IDs, Residential Address -->
  <table>
    <tr>
      <td style="width:4%;"><span class="lbl">9.</span></td>
      <td style="width:22%;"><span class="lbl">Blood Type</span><span class="val">${v(pi.blood_type)}</span></td>
      <td style="width:4%;"><span class="lbl">12.</span></td>
      <td style="width:22%;"><span class="lbl">PhilHealth No.</span><span class="val">${v(pi.philhealth)}</span></td>
      <td style="width:48%;" rowspan="5" valign="top">
        <span class="lbl">17. Residential Address</span>
        <table style="border:none;margin-top:1pt;"><tr>
          <td style="border:none;padding:0 2pt 0 0;width:50%;"><span class="lbl">House/Block/Lot No.</span><span class="val" style="font-size:6.5pt;">${v(pi.res_housenum)}</span></td>
          <td style="border:none;padding:0;width:50%;"><span class="lbl">Street</span><span class="val" style="font-size:6.5pt;">${v(pi.res_street)}</span></td>
        </tr><tr>
          <td style="border:none;padding:0 2pt 0 0;"><span class="lbl">Subdivision/Village</span><span class="val" style="font-size:6.5pt;">${v(pi.res_subdivision)}</span></td>
          <td style="border:none;padding:0;"><span class="lbl">Barangay</span><span class="val" style="font-size:6.5pt;">${v(pi.res_barangay)}</span></td>
        </tr><tr>
          <td style="border:none;padding:0 2pt 0 0;"><span class="lbl">City/Municipality</span><span class="val" style="font-size:6.5pt;">${v(pi.res_city)}</span></td>
          <td style="border:none;padding:0;"><span class="lbl">Province</span><span class="val" style="font-size:6.5pt;">${v(pi.res_province)}</span></td>
        </tr><tr>
          <td colspan="2" style="border:none;padding:0;"><span class="lbl">ZIP Code</span><span class="val" style="font-size:6.5pt;">${v(pi.res_zip)}</span></td>
        </tr></table>
      </td>
    </tr>
    <tr>
      <td><span class="lbl">13.</span></td>
      <td><span class="lbl">PhilSys No. (PSN)</span><span class="val">${v(pi.psn)}</span></td>
      <td><span class="lbl">19.</span></td>
      <td><span class="lbl">Telephone No.</span><span class="val">${v(pi.tel)}</span></td>
    </tr>
    <tr>
      <td><span class="lbl">14.</span></td>
      <td><span class="lbl">TIN No.</span><span class="val">${v(pi.tin)}</span></td>
      <td><span class="lbl">20.</span></td>
      <td><span class="lbl">Mobile No.</span><span class="val">${v(pi.mobile)}</span></td>
    </tr>
    <tr>
      <td colspan="4"><span class="lbl">15. Agency Employee No.</span><span class="val">${v(pi.agency_no)}</span></td>
    </tr>
    <tr>
      <td colspan="4"><span class="lbl">21. E-Mail Address (if any)</span><span class="val">${v(pi.email)}</span></td>
    </tr>
  </table>

  <!-- Permanent Address -->
  <table style="margin-top:0;">
    <tr>
      <td style="width:4%;font-size:6pt;font-weight:700;">18.</td>
      <td colspan="3">
        <span class="lbl">Permanent Address ${pi.perm_same?'(Same as Residential)':''}</span>
        <table style="border:none;"><tr>
          <td style="border:none;padding:0 2pt 0 0;width:25%;"><span class="lbl">House/Block/Lot No.</span><span class="val" style="font-size:6.5pt;">${v(pi.perm_same?pi.res_housenum:pi.perm_housenum)}</span></td>
          <td style="border:none;padding:0 2pt 0 0;width:25%;"><span class="lbl">Street</span><span class="val" style="font-size:6.5pt;">${v(pi.perm_same?pi.res_street:pi.perm_street)}</span></td>
          <td style="border:none;padding:0 2pt 0 0;width:25%;"><span class="lbl">Subdivision/Village</span><span class="val" style="font-size:6.5pt;">${v(pi.perm_same?pi.res_subdivision:pi.perm_subdivision)}</span></td>
          <td style="border:none;padding:0;width:25%;"><span class="lbl">Barangay</span><span class="val" style="font-size:6.5pt;">${v(pi.perm_same?pi.res_barangay:pi.perm_barangay)}</span></td>
        </tr><tr>
          <td style="border:none;padding:0 2pt 0 0;"><span class="lbl">City/Municipality</span><span class="val" style="font-size:6.5pt;">${v(pi.perm_same?pi.res_city:pi.perm_city)}</span></td>
          <td style="border:none;padding:0 2pt 0 0;"><span class="lbl">Province</span><span class="val" style="font-size:6.5pt;">${v(pi.perm_same?pi.res_province:pi.perm_province)}</span></td>
          <td colspan="2" style="border:none;padding:0;"><span class="lbl">ZIP Code</span><span class="val" style="font-size:6.5pt;">${v(pi.perm_same?pi.res_zip:pi.perm_zip)}</span></td>
        </tr></table>
      </td>
    </tr>
  </table>

  <!-- II. Family Background -->
  <table style="margin-top:1pt;"><tr><td colspan="12" class="sec">II. Family Background</td></tr></table>
  <table>
    <tr>
      <td style="width:4%;font-size:6pt;font-weight:700;">22.</td>
      <td style="width:46%;" valign="top">
        <table style="border:none;">
          <tr><td style="border:none;padding:0;"><span class="lbl">Spouse's Surname</span><span class="val">${v(fb.sp_surname)}</span></td></tr>
          <tr><td style="border:none;padding:0;"><span class="lbl">First Name</span><span class="val">${v(fb.sp_firstname)}</span></td></tr>
          <tr><td style="border:none;padding:0;"><span class="lbl">Name Extension (Jr., Sr.)</span><span class="val">${v(fb.sp_ext)}</span></td></tr>
          <tr><td style="border:none;padding:0;"><span class="lbl">Middle Name</span><span class="val">${v(fb.sp_middle)}</span></td></tr>
          <tr><td style="border:none;padding:0;"><span class="lbl">Occupation</span><span class="val">${v(fb.sp_occupation)}</span></td></tr>
          <tr><td style="border:none;padding:0;"><span class="lbl">Employer/Business Name</span><span class="val">${v(fb.sp_employer)}</span></td></tr>
          <tr><td style="border:none;padding:0;"><span class="lbl">Business Address</span><span class="val">${v(fb.sp_biz_addr)}</span></td></tr>
          <tr><td style="border:none;padding:0;"><span class="lbl">Telephone No.</span><span class="val">${v(fb.sp_tel)}</span></td></tr>
        </table>
      </td>
      <td style="width:4%;font-size:6pt;font-weight:700;">23.</td>
      <td style="width:46%;" valign="top">
        <span class="lbl">Name of Children (Write full name and list all)</span>
        <table style="margin-top:1pt;">
          <tr>
            <th class="hdr" style="width:70%;font-size:5.5pt;text-align:left;padding:1pt 2pt;">NAME OF CHILD</th>
            <th class="hdr" style="width:30%;font-size:5.5pt;text-align:center;padding:1pt 2pt;">DATE OF BIRTH<br><span style="font-size:4.5pt;">(mm/dd/yyyy)</span></th>
          </tr>
          ${pad(fb.children||[],8).map(c=>`<tr>
            <td style="font-size:6.5pt;font-weight:600;height:9pt;">${v(c.name)}</td>
            <td style="font-size:6.5pt;font-weight:600;text-align:center;">${fmtD(c.dob)}</td>
          </tr>`).join('')}
        </table>
      </td>
    </tr>
  </table>

  <!-- Father / Mother -->
  <table style="margin-top:0;">
    <tr>
      <td style="width:4%;font-size:6pt;font-weight:700;">24.</td>
      <td style="width:46%;">
        <span class="lbl">Father's Surname</span><span class="val">${v(fb.fa_surname)}</span>
        <span class="lbl" style="margin-top:1pt;display:block;">First Name</span><span class="val">${v(fb.fa_firstname)}</span>
        <span class="lbl" style="margin-top:1pt;display:block;">Name Extension</span><span class="val">${v(fb.fa_ext)}</span>
        <span class="lbl" style="margin-top:1pt;display:block;">Middle Name</span><span class="val">${v(fb.fa_middle)}</span>
      </td>
      <td style="width:4%;font-size:6pt;font-weight:700;">25.</td>
      <td style="width:46%;">
        <span class="lbl">Mother's Maiden Name — Surname</span><span class="val">${v(fb.mo_surname)}</span>
        <span class="lbl" style="margin-top:1pt;display:block;">First Name</span><span class="val">${v(fb.mo_firstname)}</span>
        <span class="lbl" style="margin-top:1pt;display:block;">Middle Name</span><span class="val">${v(fb.mo_middle)}</span>
      </td>
    </tr>
  </table>

  <!-- III. Educational Background -->
  <table style="margin-top:1pt;"><tr><td colspan="9" class="sec">III. Educational Background</td></tr></table>
  <table>
    <thead>
      <tr>
        <th class="hdr" style="width:3%;">26.</th>
        <th class="hdr" style="width:12%;">LEVEL</th>
        <th class="hdr" style="width:22%;">NAME OF SCHOOL<br><span style="font-size:4.5pt;font-weight:400;">(Write in full)</span></th>
        <th class="hdr" style="width:20%;">BASIC EDUCATION/DEGREE/COURSE<br><span style="font-size:4.5pt;font-weight:400;">(Write in full)</span></th>
        <th class="hdr" style="width:8%;">PERIOD<br>From</th>
        <th class="hdr" style="width:8%;">PERIOD<br>To</th>
        <th class="hdr" style="width:10%;">HIGHEST LEVEL/UNITS EARNED<br><span style="font-size:4.5pt;font-weight:400;">(if not graduated)</span></th>
        <th class="hdr" style="width:8%;">YEAR GRADUATED</th>
        <th class="hdr" style="width:9%;">SCHOLARSHIP/ACADEMIC HONORS</th>
      </tr>
    </thead>
    <tbody>
      ${['Elementary','Secondary','Vocational / Trade Course','College','Graduate Studies'].map((lvl,i)=>`<tr>
        <td></td>
        <td style="font-size:6pt;font-weight:700;">${lvl}</td>
        <td style="font-size:6.5pt;font-weight:600;">${v((edPad[i]||{}).school)}</td>
        <td style="font-size:6.5pt;font-weight:600;">${v((edPad[i]||{}).degree)}</td>
        <td style="font-size:6.5pt;font-weight:600;text-align:center;">${v((edPad[i]||{}).from)}</td>
        <td style="font-size:6.5pt;font-weight:600;text-align:center;">${v((edPad[i]||{}).to)}</td>
        <td style="font-size:6.5pt;font-weight:600;text-align:center;">${v((edPad[i]||{}).units)}</td>
        <td style="font-size:6.5pt;font-weight:600;text-align:center;">${v((edPad[i]||{}).year_grad)}</td>
        <td style="font-size:6.5pt;font-weight:600;">${v((edPad[i]||{}).honors)}</td>
      </tr>`).join('')}
    </tbody>
  </table>
  <table style="margin-top:1pt;"><tr>
    <td style="width:50%;border:none;"><div class="sig-box"></div><div style="font-size:5.5pt;text-align:center;">SIGNATURE &nbsp; (wet signature/e-signature/digital certificate)</div></td>
    <td style="width:50%;border:none;padding-left:10pt;"><div class="sig-box"></div><div style="font-size:5.5pt;text-align:center;">DATE</div></td>
  </tr></table>
  <div class="pg-num">CS FORM 212 (Revised 2025), Page 1 of 4</div>
  </div>`;

  // ── PAGE 2 ──────────────────────────────────────────────────────
  const page2 = `<div class="page">
  ${pageHdr}
  <!-- IV. Civil Service Eligibility -->
  <table><tr><td colspan="7" class="sec">IV. Civil Service Eligibility</td></tr></table>
  <table>
    <thead>
      <tr>
        <th class="hdr" style="width:3%;">27.</th>
        <th class="hdr" style="width:35%;">CAREER SERVICE / RA 1080 (BOARD/BAR) / UNDER SPECIAL LAWS / CES / CSEE / ELIGIBILITY</th>
        <th class="hdr" style="width:10%;">RATING<br><span style="font-weight:400;font-size:4.5pt;">(If Applicable)</span></th>
        <th class="hdr" style="width:14%;">DATE OF EXAMINATION / CONFERMENT</th>
        <th class="hdr" style="width:20%;">PLACE OF EXAMINATION / CONFERMENT</th>
        <th class="hdr" style="width:10%;">LICENSE NO.<br><span style="font-weight:400;font-size:4.5pt;">(if applicable)</span></th>
        <th class="hdr" style="width:8%;">LICENSE VALID UNTIL</th>
      </tr>
    </thead>
    <tbody>
      ${csPad.map(e=>`<tr>
        <td></td>
        <td style="font-size:6.5pt;font-weight:600;">${v(e.eligibility)}</td>
        <td style="font-size:6.5pt;font-weight:600;text-align:center;">${v(e.rating)}</td>
        <td style="font-size:6.5pt;font-weight:600;text-align:center;">${fmtD(e.exam_date)}</td>
        <td style="font-size:6.5pt;font-weight:600;">${v(e.exam_place)}</td>
        <td style="font-size:6.5pt;font-weight:600;text-align:center;">${v(e.license_no)}</td>
        <td style="font-size:6.5pt;font-weight:600;text-align:center;">${fmtD(e.license_validity)}</td>
      </tr>`).join('')}
    </tbody>
  </table>
  <div style="font-size:5.5pt;font-style:italic;text-align:right;padding:1pt 2pt;">(Continue on separate sheet if necessary)</div>

  <!-- V. Work Experience -->
  <table style="margin-top:1pt;"><tr><td colspan="9" class="sec">V. Work Experience</td></tr></table>
  <table><tr><td colspan="9" style="font-size:5.5pt;font-style:italic;padding:1.5pt 3pt;border-top:none;">
    (Include private employment. Start from your most recent work.) Description of duties should be indicated in the attached Work Experience Sheet.
  </td></tr></table>
  <table>
    <thead>
      <tr>
        <th class="hdr" style="width:3%;">28.</th>
        <th class="hdr" style="width:9%;">INCLUSIVE DATES<br>From</th>
        <th class="hdr" style="width:9%;">INCLUSIVE DATES<br>To</th>
        <th class="hdr" style="width:22%;">POSITION TITLE<br><span style="font-size:4.5pt;font-weight:400;">(Write in full/Do not abbreviate)</span></th>
        <th class="hdr" style="width:24%;">DEPT/AGENCY/OFFICE/COMPANY<br><span style="font-size:4.5pt;font-weight:400;">(Write in full/Do not abbreviate)</span></th>
        <th class="hdr" style="width:9%;">MONTHLY SALARY</th>
        <th class="hdr" style="width:10%;">SALARY/JOB/PAY GRADE<br><span style="font-size:4.5pt;font-weight:400;">(Format: SG/Step)</span></th>
        <th class="hdr" style="width:8%;">STATUS OF APPOINTMENT</th>
        <th class="hdr" style="width:6%;">GOV'T SERVICE (Y/N)</th>
      </tr>
    </thead>
    <tbody>
      ${wePad.map(e=>`<tr>
        <td></td>
        <td style="font-size:6pt;text-align:center;">${fmtD(e.date_from)}</td>
        <td style="font-size:6pt;text-align:center;">${fmtD(e.date_to)}</td>
        <td style="font-size:6pt;">${v(e.position)}</td>
        <td style="font-size:6pt;">${v(e.dept_agency)}</td>
        <td style="font-size:6pt;text-align:right;">${e.monthly_salary?'₱'+parseFloat(e.monthly_salary).toLocaleString():''}</td>
        <td style="font-size:6pt;text-align:center;">${v(e.salary_grade)}</td>
        <td style="font-size:6pt;">${v(e.status)}</td>
        <td style="font-size:6pt;text-align:center;">${v(e.govt_service)}</td>
      </tr>`).join('')}
    </tbody>
  </table>
  <div style="font-size:5.5pt;font-style:italic;text-align:right;padding:1pt 2pt;">(Continue on separate sheet if necessary)</div>
  <table style="margin-top:1pt;"><tr>
    <td style="width:50%;border:none;"><div class="sig-box"></div><div style="font-size:5.5pt;text-align:center;">SIGNATURE &nbsp; (wet signature/e-signature/digital certificate)</div></td>
    <td style="width:50%;border:none;padding-left:10pt;"><div class="sig-box"></div><div style="font-size:5.5pt;text-align:center;">DATE</div></td>
  </tr></table>
  <div class="pg-num">CS FORM 212 (Revised 2025), Page 2 of 4</div>
  </div>`;

  // ── PAGE 3 ──────────────────────────────────────────────────────
  const page3 = `<div class="page">
  ${pageHdr}
  <!-- VI. Voluntary Work -->
  <table><tr><td colspan="6" class="sec">VI. Voluntary Work or Involvement in Civic / Non-Government / People / Voluntary Organization/s</td></tr></table>
  <table>
    <thead>
      <tr>
        <th class="hdr" style="width:3%;">29.</th>
        <th class="hdr" style="width:35%;">NAME &amp; ADDRESS OF ORGANIZATION<br><span style="font-size:4.5pt;font-weight:400;">(Write in full)</span></th>
        <th class="hdr" style="width:12%;">INCLUSIVE DATES<br>From</th>
        <th class="hdr" style="width:12%;">INCLUSIVE DATES<br>To</th>
        <th class="hdr" style="width:10%;">NUMBER OF HOURS</th>
        <th class="hdr" style="width:28%;">POSITION / NATURE OF WORK</th>
      </tr>
    </thead>
    <tbody>
      ${vwPad.map(e=>`<tr>
        <td></td>
        <td style="font-size:6.5pt;font-weight:600;">${v(e.org_name)}</td>
        <td style="font-size:6.5pt;font-weight:600;text-align:center;">${fmtD(e.date_from)}</td>
        <td style="font-size:6.5pt;font-weight:600;text-align:center;">${fmtD(e.date_to)}</td>
        <td style="font-size:6.5pt;font-weight:600;text-align:center;">${v(e.no_hours)}</td>
        <td style="font-size:6.5pt;font-weight:600;">${v(e.position)}</td>
      </tr>`).join('')}
    </tbody>
  </table>
  <div style="font-size:5.5pt;font-style:italic;text-align:right;padding:1pt 2pt;">(Continue on separate sheet if necessary)</div>

  <!-- VII. L&D / Trainings -->
  <table style="margin-top:1pt;"><tr><td colspan="7" class="sec">VII. Learning and Development (L&amp;D) Interventions / Training Programs Attended</td></tr></table>
  <table>
    <thead>
      <tr>
        <th class="hdr" style="width:3%;">30.</th>
        <th class="hdr" style="width:32%;">TITLE OF L&amp;D INTERVENTIONS / TRAINING PROGRAMS<br><span style="font-size:4.5pt;font-weight:400;">(Write in full)</span></th>
        <th class="hdr" style="width:11%;">INCLUSIVE DATES<br>From</th>
        <th class="hdr" style="width:11%;">INCLUSIVE DATES<br>To</th>
        <th class="hdr" style="width:8%;">NUMBER OF HOURS</th>
        <th class="hdr" style="width:14%;">TYPE OF L&amp;D<br><span style="font-size:4.5pt;font-weight:400;">(Managerial/Supervisory/Technical/etc.)</span></th>
        <th class="hdr" style="width:21%;">CONDUCTED / SPONSORED BY<br><span style="font-size:4.5pt;font-weight:400;">(Write in full)</span></th>
      </tr>
    </thead>
    <tbody>
      ${trPad.map(e=>`<tr>
        <td></td>
        <td style="font-size:6.5pt;font-weight:600;">${v(e.title)}</td>
        <td style="font-size:6.5pt;font-weight:600;text-align:center;">${fmtD(e.date_from)}</td>
        <td style="font-size:6.5pt;font-weight:600;text-align:center;">${fmtD(e.date_to)}</td>
        <td style="font-size:6.5pt;font-weight:600;text-align:center;">${v(e.no_hours)}</td>
        <td style="font-size:6.5pt;font-weight:600;">${v(e.ld_type)}</td>
        <td style="font-size:6.5pt;font-weight:600;">${v(e.conducted_by)}</td>
      </tr>`).join('')}
    </tbody>
  </table>
  <div style="font-size:5.5pt;font-style:italic;text-align:right;padding:1pt 2pt;">(Continue on separate sheet if necessary)</div>

  <!-- VIII. Other Information -->
  <table style="margin-top:1pt;"><tr><td colspan="6" class="sec">VIII. Other Information</td></tr></table>
  <table>
    <thead>
      <tr>
        <th class="hdr" style="width:3%;">31.</th>
        <th class="hdr" style="width:30%;">SPECIAL SKILLS AND HOBBIES</th>
        <th class="hdr" style="width:3%;">32.</th>
        <th class="hdr" style="width:30%;">NON-ACADEMIC DISTINCTIONS / RECOGNITION<br><span style="font-size:4.5pt;font-weight:400;">(Write in full)</span></th>
        <th class="hdr" style="width:3%;">33.</th>
        <th class="hdr" style="width:31%;">MEMBERSHIP IN ASSOCIATION / ORGANIZATION<br><span style="font-size:4.5pt;font-weight:400;">(Write in full)</span></th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td></td>
        <td style="font-size:6.5pt;font-weight:600;min-height:40pt;vertical-align:top;">${v(oi.skills)}</td>
        <td></td>
        <td style="font-size:6.5pt;font-weight:600;vertical-align:top;">${v(oi.distinctions)}</td>
        <td></td>
        <td style="font-size:6.5pt;font-weight:600;vertical-align:top;">${v(oi.memberships)}</td>
      </tr>
    </tbody>
  </table>
  <div style="font-size:5.5pt;font-style:italic;text-align:right;padding:1pt 2pt;">(Continue on separate sheet if necessary)</div>
  <table style="margin-top:1pt;"><tr>
    <td style="width:50%;border:none;"><div class="sig-box"></div><div style="font-size:5.5pt;text-align:center;">SIGNATURE &nbsp; (wet signature/e-signature/digital certificate)</div></td>
    <td style="width:50%;border:none;padding-left:10pt;"><div class="sig-box"></div><div style="font-size:5.5pt;text-align:center;">DATE</div></td>
  </tr></table>
  <div class="pg-num">CS FORM 212 (Revised 2025), Page 3 of 4</div>
  </div>`;

  // ── PAGE 4 ──────────────────────────────────────────────────────
  const ynRow = (num, label, yesKey, detKey, extraHtml) => `
    <tr>
      <td style="width:3%;font-size:6pt;font-weight:700;text-align:center;vertical-align:top;padding-top:3pt;">${num}</td>
      <td style="font-size:6pt;line-height:1.4;padding:2pt 3pt;">${label}</td>
      <td style="width:9%;text-align:center;padding:3pt 2pt;vertical-align:top;">
        <span class="yn">${cb(q4[yesKey])}</span><span style="font-size:5.5pt;">YES</span>
      </td>
      <td style="width:9%;text-align:center;padding:3pt 2pt;vertical-align:top;">
        <span class="yn">${cb(q4[yesKey]==='No'||(q4[yesKey]!=='Yes'&&q4[yesKey]!==true&&q4[yesKey]!=='1'&&q4[yesKey]!==1))}</span><span style="font-size:5.5pt;">NO</span>
      </td>
    </tr>
    ${detKey?`<tr><td></td><td colspan="3" style="font-size:5.5pt;padding:1pt 3pt;border-top:none;">
      If YES, give details: <span style="font-size:6.5pt;font-weight:700;border-bottom:0.4pt solid #000;display:inline-block;min-width:200pt;">${v(q4[detKey])}</span>
    </td></tr>`:''}
    ${extraHtml||''}`;

  const page4 = `<div class="page">
  ${pageHdr}
  <table>
    <thead>
      <tr>
        <th class="hdr" style="width:3%;">#</th>
        <th class="hdr">QUESTION</th>
        <th class="hdr" style="width:9%;text-align:center;">YES</th>
        <th class="hdr" style="width:9%;text-align:center;">NO</th>
      </tr>
    </thead>
    <tbody>
      ${ynRow('34a','Are you related by consanguinity or affinity to the appointing or recommending authority, or to the chief of bureau or office or to the person who has immediate supervision over you in the Office, Bureau or Department where you will be apppointed, <strong>within the third degree</strong>?','q34a','q34_details')}
      ${ynRow('34b','Are you related by consanguinity or affinity to the appointing or recommending authority within the <strong>fourth degree</strong> (for Local Government Unit — Career Employees)?','q34b','')}
      ${ynRow('35a','Have you ever been found guilty of any <strong>administrative offense</strong>?','q35a','q35a_details')}
      ${ynRow('35b','Have you been <strong>criminally charged</strong> before any court?','q35b','',`<tr><td></td><td colspan="3" style="font-size:5.5pt;padding:1pt 3pt;border-top:none;">
        If YES — Charges: <span style="font-size:6.5pt;font-weight:700;border-bottom:0.4pt solid #000;display:inline-block;min-width:100pt;">${v(q4.q35b_details)}</span>
        &nbsp; Date Filed: <span style="font-size:6.5pt;font-weight:700;border-bottom:0.4pt solid #000;display:inline-block;min-width:60pt;">${fmtD(q4.q35b_date)}</span>
        &nbsp; Status: <span style="font-size:6.5pt;font-weight:700;border-bottom:0.4pt solid #000;display:inline-block;min-width:70pt;">${v(q4.q35b_status)}</span>
      </td></tr>`)}
      ${ynRow('36','Have you ever been <strong>convicted of any crime</strong> or violation of any law, decree, ordinance or regulation by any court or tribunal?','q36','q36_details')}
      ${ynRow('37','Have you ever been <strong>separated from the service</strong> in any of the following modes: resignation, retirement, dropped from the rolls, dismissal, termination, end of term, finished contract or phased out (abolition) in the public or private sector?','q37','q37_details')}
      ${ynRow('38a','Have you ever been a <strong>candidate in a national or local election</strong> held within the last year (except Barangay election)?','q38a','q38a_details')}
      ${ynRow('38b','Have you <strong>resigned from the government service</strong> during the three (3)-month period before the last election to promote/actively campaign for a national or local candidate?','q38b','q38b_details')}
      ${ynRow('39','Have you acquired the status of an <strong>immigrant or permanent resident of another country</strong>?','q39','q39_details')}
      <tr>
        <td style="font-size:6pt;font-weight:700;text-align:center;vertical-align:top;padding-top:3pt;">40</td>
        <td colspan="3" style="font-size:6pt;padding:2pt 3pt;">
          Pursuant to: (a) Indigenous People's Act (RA 8371); (b) Magna Carta for Disabled Persons (RA 7277, as amended); and (c) Expanded Solo Parents Welfare Act (RA 11861):
          <table style="border:none;margin-top:2pt;">
            <tr>
              <td style="border:none;font-size:5.5pt;width:55%;">a. Are you a member of any <strong>indigenous group</strong>?</td>
              <td style="border:none;"><span class="yn">${cb(q4.q40a)}</span><span style="font-size:5.5pt;">YES</span></td>
              <td style="border:none;"><span class="yn">${cb(!q4.q40a)}</span><span style="font-size:5.5pt;">NO</span></td>
              <td style="border:none;font-size:5.5pt;">If YES: <span style="font-size:6.5pt;font-weight:700;border-bottom:0.4pt solid #000;display:inline-block;min-width:60pt;">${v(q4.q40a_detail)}</span></td>
            </tr>
            <tr>
              <td style="border:none;font-size:5.5pt;">b. Are you a <strong>person with disability</strong>?</td>
              <td style="border:none;"><span class="yn">${cb(q4.q40b)}</span><span style="font-size:5.5pt;">YES</span></td>
              <td style="border:none;"><span class="yn">${cb(!q4.q40b)}</span><span style="font-size:5.5pt;">NO</span></td>
              <td style="border:none;font-size:5.5pt;">If YES, ID No.: <span style="font-size:6.5pt;font-weight:700;border-bottom:0.4pt solid #000;display:inline-block;min-width:60pt;">${v(q4.q40b_detail)}</span></td>
            </tr>
            <tr>
              <td style="border:none;font-size:5.5pt;">c. Are you a <strong>solo parent</strong>?</td>
              <td style="border:none;"><span class="yn">${cb(q4.q40c)}</span><span style="font-size:5.5pt;">YES</span></td>
              <td style="border:none;"><span class="yn">${cb(!q4.q40c)}</span><span style="font-size:5.5pt;">NO</span></td>
              <td style="border:none;font-size:5.5pt;">If YES, ID No.: <span style="font-size:6.5pt;font-weight:700;border-bottom:0.4pt solid #000;display:inline-block;min-width:60pt;">${v(q4.q40c_detail)}</span></td>
            </tr>
          </table>
        </td>
      </tr>
    </tbody>
  </table>

  <!-- 41. References -->
  <table style="margin-top:1pt;"><tr><td colspan="4" class="sec">41. References</td></tr></table>
  <table>
    <thead>
      <tr>
        <th class="hdr" style="width:3%;"></th>
        <th class="hdr" style="width:32%;">NAME</th>
        <th class="hdr" style="width:42%;">OFFICE / RESIDENTIAL ADDRESS</th>
        <th class="hdr" style="width:23%;">CONTACT NO. AND/OR EMAIL</th>
      </tr>
    </thead>
    <tbody>
      ${refPad.map(r=>`<tr>
        <td></td>
        <td style="font-size:6.5pt;font-weight:600;min-height:11pt;">${v(r.name)}</td>
        <td style="font-size:6.5pt;font-weight:600;">${v(r.address)}</td>
        <td style="font-size:6.5pt;font-weight:600;">${v(r.tel)}</td>
      </tr>`).join('')}
    </tbody>
  </table>

  <!-- 42. Declaration + Signature -->
  <table style="margin-top:1pt;">
    <tr>
      <td style="width:3%;font-size:6pt;font-weight:700;">42.</td>
      <td style="font-size:5.5pt;line-height:1.5;">
        I declare under oath that I have personally accomplished this Personal Data Sheet which is a true, correct and complete statement pursuant to the provisions of pertinent laws, rules and regulations of the Republic of the Philippines. I authorize the agency head/authorized representative to verify/validate the contents stated herein. I agree that any misrepresentation made in this document and its attachments shall cause the filing of administrative/criminal case/s against me.
      </td>
    </tr>
  </table>

  <!-- Signature / Photo / Thumbmark -->
  <table style="margin-top:1pt;">
    <tr>
      <td style="width:55%;padding:3pt;" valign="top">
        <div style="font-size:5.5pt;margin-bottom:2pt;">Government Issued ID (i.e. Passport, GSIS, SSS, PRC, Driver's License, etc.)</div>
        <div style="font-size:5.5pt;">ID Type: <span style="font-size:6.5pt;font-weight:700;border-bottom:0.4pt solid #000;display:inline-block;min-width:120pt;">${v(pi.gov_id_type)}</span></div>
        <div style="font-size:5.5pt;margin-top:2pt;">ID/License No.: <span style="font-size:6.5pt;font-weight:700;border-bottom:0.4pt solid #000;display:inline-block;min-width:100pt;">${v(pi.gov_id_no)}</span></div>
        <div style="font-size:5.5pt;margin-top:2pt;">Date/Place of Issuance: <span style="font-size:6.5pt;font-weight:700;border-bottom:0.4pt solid #000;display:inline-block;min-width:100pt;">${v(pi.gov_id_issuance)}</span></div>
        <div style="margin-top:10pt;height:22pt;border-top:0.5pt solid #000;"></div>
        <div style="font-size:5.5pt;text-align:center;">SIGNATURE &nbsp; (wet signature/e-signature/digital certificate)</div>
        <div style="font-size:5.5pt;margin-top:2pt;">Date Accomplished: <span style="border-bottom:0.4pt solid #000;display:inline-block;min-width:80pt;">${v(pi.date_accomplished)}</span></div>
      </td>
      <td style="width:27%;padding:3pt;text-align:center;vertical-align:top;">
        <div class="photo">PHOTO<br><span style="font-size:5pt;">(passport size)</span></div>
        <div style="font-size:5pt;margin-top:2pt;">Signature (Sign inside the box)</div>
        <div style="height:16pt;border:0.5pt solid #000;margin-top:2pt;"></div>
      </td>
      <td style="width:18%;padding:3pt;text-align:center;vertical-align:top;">
        <div class="thumb">RIGHT<br>THUMBMARK</div>
      </td>
    </tr>
  </table>

  <!-- Oath -->
  <table style="margin-top:1pt;">
    <tr>
      <td style="font-size:5.5pt;line-height:1.5;padding:3pt;">
        SUBSCRIBED AND SWORN to before me this _________ day of ___________________, affiant exhibiting his/her validly issued government ID as indicated above.
        <div style="margin-top:8pt;border-top:0.5pt solid #000;width:45%;padding-top:1pt;text-align:center;font-size:5.5pt;">(wet signature/e-signature/digital certificate)</div>
        <div style="font-size:5.5pt;text-align:left;margin-top:1pt;padding-left:0;">Person Administering Oath</div>
      </td>
    </tr>
  </table>
  <div class="pg-num">CS FORM 212 (Revised 2025), Page 4 of 4</div>
  </div>`;

  return `<!DOCTYPE html><html><head>
    <meta charset="UTF-8"/>
    <title>PDS — CS Form 212 (Revised 2025)</title>
    <style>${css}</style>
  </head><body>
    ${page1}${page2}${page3}${page4}
  </body></html>`;
}

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

</body>
</html>