<?php
// ============================================================
//  LGU ENRILE HRMS — Archives Page
//  File: Admin/archives.php
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
$allowedRoles = ['admin', 'hr_staff'];
if (!in_array($_SESSION['user_role'] ?? '', $allowedRoles)) {
    header('Location: ../Employee/Employee_portal.php'); exit();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';
// P3-9: Schema managed by config/migrations/001_archive_log.sql

// ── JSON API ───────────────────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_verify(true); }
    try {
        switch ($action) {

            // ── Stats ─────────────────────────────────────────
            case 'stats':
                $sep  = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE employment_status='Separated' OR archived_at IS NOT NULL")->fetchColumn();
                $expC = (int)$pdo->query("SELECT COUNT(*) FROM contracts WHERE status IN ('Expired','Terminated')")->fetchColumn();
                $denL = (int)$pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status='denied'")->fetchColumn();
                $logs = (int)$pdo->query("SELECT COUNT(*) FROM archive_log")->fetchColumn();
                echo json_encode(['success'=>true,'data'=>[
                    'employees'=>$sep,'contracts'=>$expC,'leave_requests'=>$denL,'logs'=>$logs
                ]]);
                break;

            // ── Archived / Separated Employees ────────────────
            case 'list_employees':
                $where = ["(e.employment_status='Separated' OR e.archived_at IS NOT NULL)"];
                $params = [];
                if (!empty($_GET['search'])) {
                    $s = '%'.$_GET['search'].'%';
                    $where[] = "(CONCAT(e.first_name,' ',e.last_name) LIKE ? OR e.employee_code LIKE ? OR e.position_title LIKE ?)";
                    $params = array_merge($params, [$s, $s, $s]);
                }
                if (!empty($_GET['dept'])) { $where[] = 'd.department_id = ?'; $params[] = (int)$_GET['dept']; }
                $wSql = 'WHERE '.implode(' AND ', $where);
                $page = max(1,(int)($_GET['page']??1)); $ps = 12; $offset = ($page-1)*$ps;
                $total = (int)$pdo->prepare("SELECT COUNT(*) FROM employees e LEFT JOIN departments d ON e.department_id=d.department_id $wSql")->execute($params) ? $pdo->prepare("SELECT COUNT(*) FROM employees e LEFT JOIN departments d ON e.department_id=d.department_id $wSql")->execute($params) : 0;
                $cnt = $pdo->prepare("SELECT COUNT(*) FROM employees e LEFT JOIN departments d ON e.department_id=d.department_id $wSql");
                $cnt->execute($params); $total = (int)$cnt->fetchColumn();
                $stmt = $pdo->prepare("SELECT e.employee_id,e.employee_code,e.first_name,e.last_name,e.position_title,e.employment_type,e.employment_status,e.date_hired,e.date_separated,e.archived_at,e.archive_reason,d.name AS department FROM employees e LEFT JOIN departments d ON e.department_id=d.department_id $wSql ORDER BY COALESCE(e.archived_at,e.date_separated) DESC LIMIT $ps OFFSET $offset");
                $stmt->execute($params);
                echo json_encode(['success'=>true,'data'=>$stmt->fetchAll(),'total'=>$total,'pages'=>(int)ceil($total/$ps),'page'=>$page]);
                break;

            // ── Expired / Terminated Contracts ────────────────
            case 'list_contracts':
                $where = ["c.status IN ('Expired','Terminated')"];
                $params = [];
                if (!empty($_GET['search'])) {
                    $s = '%'.$_GET['search'].'%';
                    $where[] = "(CONCAT(e.first_name,' ',e.last_name) LIKE ? OR c.contract_number LIKE ?)";
                    $params = array_merge($params, [$s, $s]);
                }
                if (!empty($_GET['status'])) { $where[] = 'c.status = ?'; $params[] = $_GET['status']; }
                $wSql = 'WHERE '.implode(' AND ', $where);
                $page = max(1,(int)($_GET['page']??1)); $ps = 12; $offset = ($page-1)*$ps;
                $cnt = $pdo->prepare("SELECT COUNT(*) FROM contracts c JOIN employees e ON c.employee_id=e.employee_id LEFT JOIN departments d ON c.department_id=d.department_id $wSql");
                $cnt->execute($params); $total = (int)$cnt->fetchColumn();
                $stmt = $pdo->prepare("SELECT c.contract_id,c.contract_number,c.contract_type,c.position_title,c.start_date,c.end_date,c.status,c.monthly_salary,c.remarks,e.first_name,e.last_name,e.employee_code,d.name AS department FROM contracts c JOIN employees e ON c.employee_id=e.employee_id LEFT JOIN departments d ON c.department_id=d.department_id $wSql ORDER BY c.end_date DESC LIMIT $ps OFFSET $offset");
                $stmt->execute($params);
                echo json_encode(['success'=>true,'data'=>$stmt->fetchAll(),'total'=>$total,'pages'=>(int)ceil($total/$ps),'page'=>$page]);
                break;

            // ── Denied / Cancelled Leave Requests ─────────────
            case 'list_leaves':
                $where = ["lr.status IN ('denied','cancelled')"];
                $params = [];
                if (!empty($_GET['search'])) {
                    $s = '%'.$_GET['search'].'%';
                    $where[] = "(CONCAT(e.first_name,' ',e.last_name) LIKE ? OR lt.name LIKE ?)";
                    $params = array_merge($params, [$s, $s]);
                }
                if (!empty($_GET['status'])) { $where[] = 'lr.status = ?'; $params[] = $_GET['status']; }
                $wSql = 'WHERE '.implode(' AND ', $where);
                $page = max(1,(int)($_GET['page']??1)); $ps = 12; $offset = ($page-1)*$ps;
                $cnt = $pdo->prepare("SELECT COUNT(*) FROM leave_requests lr JOIN employees e ON lr.employee_id=e.employee_id JOIN leave_types lt ON lr.leave_type_id=lt.leave_type_id $wSql");
                $cnt->execute($params); $total = (int)$cnt->fetchColumn();
                $stmt = $pdo->prepare("SELECT lr.request_id,lr.status,lr.date_from,lr.date_to,lr.days_requested,lr.reason,lr.admin_remarks,lr.filed_at,lt.name AS leave_type,e.first_name,e.last_name,e.employee_code FROM leave_requests lr JOIN employees e ON lr.employee_id=e.employee_id JOIN leave_types lt ON lr.leave_type_id=lt.leave_type_id $wSql ORDER BY lr.filed_at DESC LIMIT $ps OFFSET $offset");
                $stmt->execute($params);
                echo json_encode(['success'=>true,'data'=>$stmt->fetchAll(),'total'=>$total,'pages'=>(int)ceil($total/$ps),'page'=>$page]);
                break;

            // ── Archive log ───────────────────────────────────
            case 'list_logs':
                $page = max(1,(int)($_GET['page']??1)); $ps = 15; $offset = ($page-1)*$ps;
                $total = (int)$pdo->query("SELECT COUNT(*) FROM archive_log")->fetchColumn();
                $stmt = $pdo->prepare("SELECT al.*,CONCAT(u.first_name,' ',u.last_name) AS performed_by_name FROM archive_log al LEFT JOIN employees u ON al.performed_by=u.user_id ORDER BY al.performed_at DESC LIMIT $ps OFFSET $offset");
                $stmt->execute();
                echo json_encode(['success'=>true,'data'=>$stmt->fetchAll(),'total'=>$total,'pages'=>(int)ceil($total/$ps),'page'=>$page]);
                break;

            // ── Archive employee (mark separated) ─────────────
            case 'archive_employee':
                $id = (int)($_POST['id']??0);
                $reason = trim($_POST['reason']??'');
                if (!$id) { echo json_encode(['success'=>false,'message'=>'Invalid ID.']); break; }
                $pdo->prepare("UPDATE employees SET employment_status='Separated', archived_at=NOW(), archive_reason=?, date_separated=COALESCE(date_separated,CURDATE()) WHERE employee_id=?")->execute([$reason?:null, $id]);
                $pdo->prepare("INSERT INTO archive_log (record_type,record_id,action,reason,performed_by) VALUES ('employee',?,?,?,?)")->execute([$id,'archived',$reason?:null,(int)$_SESSION['user_id']]);
                echo json_encode(['success'=>true,'message'=>'Employee archived successfully.']);
                break;

            // ── Restore employee ──────────────────────────────
            case 'restore_employee':
                $id = (int)($_POST['id']??0);
                if (!$id) { echo json_encode(['success'=>false,'message'=>'Invalid ID.']); break; }
                $pdo->prepare("UPDATE employees SET employment_status='Active', archived_at=NULL, archive_reason=NULL, date_separated=NULL WHERE employee_id=?")->execute([$id]);
                $pdo->prepare("INSERT INTO archive_log (record_type,record_id,action,reason,performed_by) VALUES ('employee',?,'restored',?,?)")->execute([$id,'Restored to active',(int)$_SESSION['user_id']]);
                echo json_encode(['success'=>true,'message'=>'Employee restored to Active.']);
                break;

            // ── Departments dropdown ──────────────────────────
            case 'departments':
                $rows = $pdo->query("SELECT department_id,name FROM departments ORDER BY name")->fetchAll();
                echo json_encode(['success'=>true,'data'=>$rows]);
                break;
            default:
                echo json_encode(['success'=>false,'message'=>'Unknown action.']);
        }
    } catch (PDOException $e) {
        error_log('[HRMS] archives.php: '.$e->getMessage());
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
  <title>Archives — LGU Enrile HRMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet"/>
  <style>
    :root {
      --primary:      #2563eb;
      --primary-dark: #1e40af;
      --accent:       #22c55e;
      --secondary:    #0f172a;
      --sidebar-w:    265px;
      --topbar-h:     68px;
      --gray-50:      #f8fafc;
      --gray-100:     #f1f5f9;
      --gray-200:     #e2e8f0;
      --gray-400:     #94a3b8;
      --gray-600:     #475569;
      --gray-800:     #1e293b;
      --white:        #ffffff;
      --shadow-sm:    0 1px 4px rgba(0,0,0,0.07);
      --shadow-md:    0 4px 18px rgba(0,0,0,0.11);
      --shadow-lg:    0 8px 32px rgba(0,0,0,0.16);
      --radius:       14px;
      --radius-sm:    9px;
    }
    *{ box-sizing:border-box; margin:0; padding:0; }
    body{ font-family:'Nunito',sans-serif; background:var(--gray-100); color:var(--secondary); display:flex; min-height:100vh; }

    /* ── Sidebar ── */
    .sidebar{ width:var(--sidebar-w); background:linear-gradient(180deg,#2563eb 0%,#1e40af 55%,#22c55e 100%); display:flex; flex-direction:column; position:fixed; top:0; left:0; height:100vh; z-index:200; transition:transform .3s; box-shadow:4px 0 28px rgba(0,0,0,.20); }
    .sidebar-brand{ padding:22px 20px 18px; display:flex; align-items:center; gap:12px; border-bottom:1px solid rgba(255,255,255,.15); }
    .brand-logo{ width:44px; height:44px; border-radius:50%; object-fit:cover; border:2.5px solid rgba(255,255,255,.70); }
    .brand-text h2{ font-family:'Playfair Display',serif; font-size:1rem; color:#fff; line-height:1.2; }
    .brand-text p{ font-size:.65rem; color:rgba(255,255,255,.60); font-weight:600; letter-spacing:.06em; }
    .sidebar-nav{ padding:12px 10px; flex:1; overflow-y:auto; }
    .nav-label{ font-size:.63rem; font-weight:800; color:rgba(255,255,255,.38); text-transform:uppercase; letter-spacing:.16em; padding:10px 10px 5px; margin-top:6px; }
    .nav-item{ display:flex; align-items:center; gap:11px; padding:9px 12px; border-radius:var(--radius-sm); color:rgba(255,255,255,.75); text-decoration:none; font-size:.875rem; font-weight:600; transition:all .2s; margin-bottom:2px; position:relative; }
    .nav-item:hover{ background:rgba(255,255,255,.13); color:#fff; }
    .nav-item.active{ background:rgba(255,255,255,.22); color:#fff; }
    .nav-item.active::before{ content:''; position:absolute; left:0; top:20%; bottom:20%; width:3px; border-radius:2px; background:#fff; }
    .nav-item.active .nav-icon{ background:rgba(255,255,255,.25); }
    .nav-icon{ width:30px; height:30px; border-radius:8px; background:rgba(255,255,255,.10); display:flex; align-items:center; justify-content:center; font-size:.95rem; flex-shrink:0; }
    .sidebar-footer{ padding:14px 16px; border-top:1px solid rgba(255,255,255,.12); }
    .user-pill{ display:flex; align-items:center; gap:10px; margin-bottom:10px; }
    .user-avatar{ width:36px; height:36px; border-radius:50%; background:rgba(255,255,255,.20); display:flex; align-items:center; justify-content:center; font-weight:800; color:#fff; font-size:.85rem; flex-shrink:0; }
    .user-name{ font-size:.82rem; font-weight:700; color:#fff; }
    .user-role{ font-size:.65rem; color:rgba(255,255,255,.55); text-transform:capitalize; }
    .logout-btn{ width:100%; padding:8px 12px; background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.25); border-radius:var(--radius-sm); color:#fca5a5; font-family:'Nunito',sans-serif; font-size:.82rem; font-weight:700; cursor:pointer; transition:all .2s; }
    .logout-btn:hover{ background:rgba(239,68,68,.22); }

    /* ── Layout ── */
    .main{ margin-left:var(--sidebar-w); flex:1; display:flex; flex-direction:column; min-height:100vh; }
    .topbar{ height:var(--topbar-h); background:linear-gradient(135deg,#2563eb 0%,#1e40af 50%,#22c55e 100%); display:flex; align-items:center; padding:0 28px; gap:16px; position:sticky; top:0; z-index:100; box-shadow:0 2px 12px rgba(0,0,0,.18); }
    .topbar-btn{ position:relative; width:38px; height:38px; border-radius:50%; border:1px solid rgba(255,255,255,0.25); background:rgba(255,255,255,0.15); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:1rem; color:#fff; transition:all 0.2s; }
    .topbar-btn:hover{ background:rgba(255,255,255,0.28); }
    .notif-badge{ position:absolute; top:-2px; right:-2px; width:16px; height:16px; background:#ef4444; border-radius:50%; font-size:0.58rem; color:white; font-weight:800; display:none; align-items:center; justify-content:center; border:2px solid rgba(255,255,255,0.3); }
    .menu-toggle{ display:none; background:none; border:none; color:#fff; font-size:1.5rem; cursor:pointer; }
    .topbar-title{ flex:1; }
    .topbar-title h1{ font-family:'Playfair Display',serif; font-size:1.22rem; color:#fff; }
    .topbar-title p{ font-size:.72rem; color:rgba(255,255,255,.70); }
    .tb-chip{ background:rgba(255,255,255,.15); color:#fff; padding:5px 14px; border-radius:20px; font-size:.72rem; font-weight:700; }
    .content{ padding:26px 28px; flex:1; }

    /* ── Stat Cards ── */
    .stat-row{ display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:26px; }
    .stat-card{ background:#fff; border-radius:var(--radius); padding:20px 22px; box-shadow:var(--shadow-sm); position:relative; overflow:hidden; transition:transform .2s,box-shadow .2s; }
    .stat-card:hover{ transform:translateY(-2px); box-shadow:var(--shadow-md); }
    .stat-card::before{ content:''; position:absolute; top:0; left:0; right:0; height:4px; }
    .stat-card.c-blue::before{ background:linear-gradient(90deg,#2563eb,#60a5fa); }
    .stat-card.c-amber::before{ background:linear-gradient(90deg,#f59e0b,#fbbf24); }
    .stat-card.c-red::before{ background:linear-gradient(90deg,#ef4444,#f87171); }
    .stat-card.c-purple::before{ background:linear-gradient(90deg,#7c3aed,#a78bfa); }
    .stat-icon{ width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.15rem; margin-bottom:12px; }
    .c-blue .stat-icon{ background:#eff6ff; }
    .c-amber .stat-icon{ background:#fffbeb; }
    .c-red .stat-icon{ background:#fef2f2; }
    .c-purple .stat-icon{ background:#f5f3ff; }
    .stat-val{ font-size:1.75rem; font-weight:800; line-height:1; margin-bottom:4px; }
    .c-blue .stat-val{ color:#2563eb; }
    .c-amber .stat-val{ color:#d97706; }
    .c-red .stat-val{ color:#dc2626; }
    .c-purple .stat-val{ color:#7c3aed; }
    .stat-label{ font-size:.78rem; font-weight:700; color:var(--gray-600); }

    /* ── Tabs ── */
    .tab-bar{ display:flex; gap:4px; background:#fff; border-radius:var(--radius); padding:6px; box-shadow:var(--shadow-sm); margin-bottom:22px; }
    .tab-btn{ flex:1; padding:9px 14px; border:none; border-radius:10px; background:transparent; font-family:'Nunito',sans-serif; font-size:.82rem; font-weight:700; color:var(--gray-600); cursor:pointer; transition:all .2s; display:flex; align-items:center; justify-content:center; gap:6px; }
    .tab-btn:hover{ background:var(--gray-100); color:var(--secondary); }
    .tab-btn.active{ background:linear-gradient(135deg,var(--primary),var(--primary-dark)); color:#fff; box-shadow:0 3px 10px rgba(37,99,235,.30); }

    /* ── Panel ── */
    .panel{ background:#fff; border-radius:var(--radius); box-shadow:var(--shadow-sm); overflow:hidden; }
    .panel-head{ padding:16px 20px; background:var(--gray-50); border-bottom:1.5px solid var(--gray-200); display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
    .panel-title{ font-size:.95rem; font-weight:800; flex:1; }
    .panel-body{ padding:20px; }

    /* ── Filters ── */
    .filter-bar{ display:flex; gap:10px; flex-wrap:wrap; }
    .search-box{ position:relative; flex:1; min-width:200px; }
    .search-box input{ width:100%; padding:9px 12px 9px 36px; border:1.5px solid var(--gray-200); border-radius:var(--radius-sm); font-family:'Nunito',sans-serif; font-size:.85rem; outline:none; transition:border-color .2s; }
    .search-box input:focus{ border-color:var(--primary); }
    .search-box .s-icon{ position:absolute; left:11px; top:50%; transform:translateY(-50%); color:var(--gray-400); font-size:.9rem; }
    .filter-select{ padding:9px 12px; border:1.5px solid var(--gray-200); border-radius:var(--radius-sm); font-family:'Nunito',sans-serif; font-size:.83rem; font-weight:600; background:#fff; outline:none; cursor:pointer; }
    .filter-select:focus{ border-color:var(--primary); }

    /* ── Table ── */
    .tbl-wrap{ overflow-x:auto; margin-top:16px; }
    table{ width:100%; border-collapse:collapse; }
    thead th{ background:var(--gray-50); padding:10px 14px; font-size:.72rem; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:var(--gray-600); border-bottom:2px solid var(--gray-200); white-space:nowrap; }
    tbody tr{ border-bottom:1px solid var(--gray-100); transition:background .15s; }
    tbody tr:hover{ background:var(--gray-50); }
    tbody td{ padding:11px 14px; font-size:.84rem; vertical-align:middle; }
    .emp-cell{ display:flex; align-items:center; gap:10px; }
    .emp-avatar{ width:34px; height:34px; border-radius:50%; background:linear-gradient(135deg,var(--primary),var(--primary-dark)); color:#fff; display:flex; align-items:center; justify-content:center; font-size:.75rem; font-weight:800; flex-shrink:0; }
    .emp-name{ font-weight:700; font-size:.86rem; }
    .emp-code{ font-size:.72rem; color:var(--gray-400); }

    /* ── Badges ── */
    .badge{ padding:3px 10px; border-radius:20px; font-size:.70rem; font-weight:800; display:inline-block; }
    .badge-red{ background:#fef2f2; color:#dc2626; }
    .badge-amber{ background:#fffbeb; color:#d97706; }
    .badge-gray{ background:var(--gray-100); color:var(--gray-600); }
    .badge-blue{ background:#eff6ff; color:#2563eb; }
    .badge-green{ background:#f0fdf4; color:#16a34a; }

    /* ── Action buttons ── */
    .row-actions{ display:flex; gap:6px; }
    .btn-sm{ padding:5px 13px; border-radius:7px; border:none; font-family:'Nunito',sans-serif; font-size:.75rem; font-weight:700; cursor:pointer; transition:all .2s; }
    .btn-restore{ background:#f0fdf4; color:#16a34a; border:1.5px solid #bbf7d0; }
    .btn-restore:hover{ background:#16a34a; color:#fff; }
    .btn-view{ background:#eff6ff; color:#2563eb; border:1.5px solid #bfdbfe; }
    .btn-view:hover{ background:#2563eb; color:#fff; }
    .btn-archive{ background:#fef2f2; color:#dc2626; border:1.5px solid #fecaca; }
    .btn-archive:hover{ background:#dc2626; color:#fff; }

    /* ── Pagination ── */
    .pagination{ display:flex; align-items:center; justify-content:space-between; margin-top:16px; flex-wrap:wrap; gap:10px; }
    .pg-info{ font-size:.78rem; color:var(--gray-600); font-weight:600; }
    .pg-btns{ display:flex; gap:4px; }
    .pg-btn{ padding:6px 12px; border:1.5px solid var(--gray-200); background:#fff; border-radius:7px; font-size:.78rem; font-weight:700; cursor:pointer; transition:all .2s; font-family:'Nunito',sans-serif; }
    .pg-btn:hover:not(:disabled){ border-color:var(--primary); color:var(--primary); }
    .pg-btn.active{ background:var(--primary); color:#fff; border-color:var(--primary); }
    .pg-btn:disabled{ opacity:.4; cursor:not-allowed; }

    /* ── Empty state ── */
    .empty-state{ text-align:center; padding:48px 20px; color:var(--gray-400); }
    .empty-state .e-icon{ font-size:2.6rem; margin-bottom:10px; }
    .empty-state p{ font-size:.88rem; font-weight:600; }

    /* ── Log table ── */
    .log-action-archived{ color:#dc2626; font-weight:700; }
    .log-action-restored{ color:#16a34a; font-weight:700; }
    .log-action-deleted{ color:#7c3aed; font-weight:700; }

    /* ── Modals ── */
    .modal-overlay{ display:none; position:fixed; inset:0; background:rgba(15,23,42,.45); z-index:1000; align-items:center; justify-content:center; padding:16px; }
    .modal-overlay.open{ display:flex; }
    .modal{ background:#fff; border-radius:var(--radius); box-shadow:var(--shadow-lg); width:100%; max-width:480px; animation:slideUp .25s ease both; }
    @keyframes slideUp{ from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }
    .modal-header{ padding:18px 22px; border-radius:var(--radius) var(--radius) 0 0; display:flex; align-items:center; gap:12px; }
    .modal-header.hd-blue{ background:linear-gradient(135deg,var(--primary),var(--primary-dark)); color:#fff; }
    .modal-header.hd-red{ background:linear-gradient(135deg,#dc2626,#b91c1c); color:#fff; }
    .modal-header.hd-green{ background:linear-gradient(135deg,#16a34a,#15803d); color:#fff; }
    .modal-title{ font-size:1rem; font-weight:800; }
    .modal-body{ padding:22px; }
    .modal-footer{ padding:14px 22px; border-top:1.5px solid var(--gray-100); display:flex; justify-content:flex-end; gap:10px; }
    .form-group{ margin-bottom:14px; }
    .form-group label{ display:block; font-size:.78rem; font-weight:800; color:var(--gray-600); text-transform:uppercase; letter-spacing:.05em; margin-bottom:6px; }
    .form-group textarea,.form-group input{ width:100%; padding:9px 12px; border:1.5px solid var(--gray-200); border-radius:var(--radius-sm); font-family:'Nunito',sans-serif; font-size:.88rem; outline:none; transition:border-color .2s; }
    .form-group textarea{ height:80px; resize:vertical; }
    .form-group textarea:focus,.form-group input:focus{ border-color:var(--primary); }
    .detail-row{ display:flex; justify-content:space-between; padding:7px 0; border-bottom:1px solid var(--gray-100); font-size:.84rem; }
    .detail-row:last-child{ border-bottom:none; }
    .detail-label{ font-weight:700; color:var(--gray-600); }
    .detail-val{ font-weight:600; text-align:right; max-width:60%; }
    .btn{ padding:9px 20px; border-radius:var(--radius-sm); border:none; font-family:'Nunito',sans-serif; font-size:.85rem; font-weight:700; cursor:pointer; transition:all .2s; }
    .btn-cancel{ background:var(--gray-100); color:var(--gray-600); }
    .btn-cancel:hover{ background:var(--gray-200); }
    .btn-primary{ background:linear-gradient(135deg,var(--primary),var(--primary-dark)); color:#fff; box-shadow:0 3px 10px rgba(37,99,235,.28); }
    .btn-danger{ background:linear-gradient(135deg,#dc2626,#b91c1c); color:#fff; box-shadow:0 3px 10px rgba(220,38,38,.28); }
    .btn-success{ background:linear-gradient(135deg,#16a34a,#15803d); color:#fff; box-shadow:0 3px 10px rgba(22,163,74,.28); }

    /* ── Toast ── */
    .toast-wrap{ position:fixed; bottom:24px; right:24px; z-index:2000; display:flex; flex-direction:column; gap:8px; }
    .toast{ padding:12px 20px; border-radius:10px; color:#fff; font-size:.85rem; font-weight:700; box-shadow:var(--shadow-lg); animation:toastIn .3s ease both; min-width:220px; }
    @keyframes toastIn{ from{opacity:0;transform:translateX(30px)} to{opacity:1;transform:translateX(0)} }
    .toast-success{ background:linear-gradient(135deg,#16a34a,#15803d); }
    .toast-error{ background:linear-gradient(135deg,#dc2626,#b91c1c); }
    .toast-info{ background:linear-gradient(135deg,#2563eb,#1e40af); }

    @media(max-width:900px){
      .stat-row{ grid-template-columns:repeat(2,1fr); }
      .tab-btn span.tab-text{ display:none; }
    }
    @media(max-width:640px){
      .sidebar{ transform:translateX(-100%); }
      .sidebar.open{ transform:translateX(0); }
      .main{ margin-left:0; }
      .menu-toggle{ display:block; }
      .topbar{ padding:0 16px; }
      .content{ padding:16px; }
      .stat-row{ grid-template-columns:1fr 1fr; }
    }
  </style>
</head>
<body>

<!-- ══ SIDEBAR ══ -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <img src="../images/Enrile.png" class="brand-logo" alt="LGU Enrile"/>
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
    <a class="nav-item" href="pds_saln.php"><div class="nav-icon">🪪</div>PDS & SALN</a>
    <a class="nav-item" href="service_records.php"><div class="nav-icon">📜</div>Service Records</a>
    <a class="nav-item" href="contracts.php"><div class="nav-icon">📝</div>Contracts</a>
    <div class="nav-label">System</div>
    <a class="nav-item" href="reports.php"><div class="nav-icon">📊</div>Reports</a>
    <a class="nav-item" href="announcements.php"><div class="nav-icon">📢</div>Announcements</a>
    <a class="nav-item active" href="archives.php"><div class="nav-icon">🗄️</div>Archives</a>
    <a class="nav-item" href="user_accounts.php"><div class="nav-icon">🔐</div>User Accounts</a>
    <a class="nav-item" href="settings.php"><div class="nav-icon">⚙️</div>Settings</a>
  </nav>
  <div class="sidebar-footer">
    <div class="user-pill">
      <div class="user-avatar"><?= strtoupper(substr($_SESSION['user_name']??'A',0,2)) ?></div>
      <div><div class="user-name"><?= htmlspecialchars($_SESSION['user_name']??'Admin') ?></div><div class="user-role"><?= htmlspecialchars($_SESSION['user_role']??'') ?></div></div>
    </div>
  </div>
</aside>

<!-- ══ MAIN ══ -->
<div class="main">

  <!-- Topbar -->
  <div class="topbar">
    <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">☰</button>
    <div class="topbar-title">
      <h1>Archives</h1>
      <p>View and manage archived records</p>
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

    <!-- Stat Cards -->
    <div class="stat-row">
      <div class="stat-card c-blue">
        <div class="stat-icon">👥</div>
        <div class="stat-val" id="statEmployees">—</div>
        <div class="stat-label">Separated Employees</div>
      </div>
      <div class="stat-card c-amber">
        <div class="stat-icon">📝</div>
        <div class="stat-val" id="statContracts">—</div>
        <div class="stat-label">Expired / Terminated Contracts</div>
      </div>
      <div class="stat-card c-red">
        <div class="stat-icon">📋</div>
        <div class="stat-val" id="statLeaves">—</div>
        <div class="stat-label">Denied / Cancelled Leaves</div>
      </div>
      <div class="stat-card c-purple">
        <div class="stat-icon">🗒️</div>
        <div class="stat-val" id="statLogs">—</div>
        <div class="stat-label">Archive Log Entries</div>
      </div>
    </div>

    <!-- Tabs -->
    <div class="tab-bar">
      <button class="tab-btn active" id="tab-emp"    onclick="switchTab('employees')">👥 <span class="tab-text">Employees</span></button>
      <button class="tab-btn"        id="tab-con"    onclick="switchTab('contracts')">📝 <span class="tab-text">Contracts</span></button>
      <button class="tab-btn"        id="tab-leave"  onclick="switchTab('leaves')">📋 <span class="tab-text">Leave Requests</span></button>
      <button class="tab-btn"        id="tab-log"    onclick="switchTab('logs')">🗒️ <span class="tab-text">Activity Log</span></button>
    </div>

    <!-- ── Employees Tab ── -->
    <div id="pane-employees" class="pane">
      <div class="panel">
        <div class="panel-head">
          <div class="panel-title">🗄️ Separated / Archived Employees</div>
          <div class="filter-bar">
            <div class="search-box">
              <span class="s-icon">🔍</span>
              <input type="text" id="empSearch" placeholder="Search name, code…" oninput="debouncedEmp()"/>
            </div>
            <select class="filter-select" id="empDept" onchange="loadEmployees()">
              <option value="">All Departments</option>
            </select>
          </div>
        </div>
        <div class="panel-body">
          <div class="tbl-wrap">
            <table>
              <thead>
                <tr>
                  <th>Employee</th>
                  <th>Position</th>
                  <th>Department</th>
                  <th>Type</th>
                  <th>Date Hired</th>
                  <th>Date Separated</th>
                  <th>Reason</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="empTbody"><tr><td colspan="8" class="empty-state"><div class="e-icon">⏳</div><p>Loading…</p></td></tr></tbody>
            </table>
          </div>
          <div class="pagination" id="empPag"></div>
        </div>
      </div>
    </div>

    <!-- ── Contracts Tab ── -->
    <div id="pane-contracts" class="pane" style="display:none;">
      <div class="panel">
        <div class="panel-head">
          <div class="panel-title">📝 Expired / Terminated Contracts</div>
          <div class="filter-bar">
            <div class="search-box">
              <span class="s-icon">🔍</span>
              <input type="text" id="conSearch" placeholder="Search name, contract no…" oninput="debouncedCon()"/>
            </div>
            <select class="filter-select" id="conStatus" onchange="loadContracts()">
              <option value="">All Statuses</option>
              <option value="Expired">Expired</option>
              <option value="Terminated">Terminated</option>
            </select>
          </div>
        </div>
        <div class="panel-body">
          <div class="tbl-wrap">
            <table>
              <thead>
                <tr>
                  <th>Employee</th>
                  <th>Contract No.</th>
                  <th>Type</th>
                  <th>Position</th>
                  <th>Department</th>
                  <th>Start Date</th>
                  <th>End Date</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="conTbody"><tr><td colspan="9" class="empty-state"><div class="e-icon">⏳</div><p>Loading…</p></td></tr></tbody>
            </table>
          </div>
          <div class="pagination" id="conPag"></div>
        </div>
      </div>
    </div>

    <!-- ── Leave Requests Tab ── -->
    <div id="pane-leaves" class="pane" style="display:none;">
      <div class="panel">
        <div class="panel-head">
          <div class="panel-title">📋 Denied / Cancelled Leave Requests</div>
          <div class="filter-bar">
            <div class="search-box">
              <span class="s-icon">🔍</span>
              <input type="text" id="lvSearch" placeholder="Search name, leave type…" oninput="debouncedLv()"/>
            </div>
            <select class="filter-select" id="lvStatus" onchange="loadLeaves()">
              <option value="">All</option>
              <option value="denied">Denied</option>
              <option value="cancelled">Cancelled</option>
            </select>
          </div>
        </div>
        <div class="panel-body">
          <div class="tbl-wrap">
            <table>
              <thead>
                <tr>
                  <th>Employee</th>
                  <th>Leave Type</th>
                  <th>Date From</th>
                  <th>Date To</th>
                  <th>Days</th>
                  <th>Filed On</th>
                  <th>Status</th>
                  <th>Remarks</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody id="lvTbody"><tr><td colspan="9" class="empty-state"><div class="e-icon">⏳</div><p>Loading…</p></td></tr></tbody>
            </table>
          </div>
          <div class="pagination" id="lvPag"></div>
        </div>
      </div>
    </div>

    <!-- ── Activity Log Tab ── -->
    <div id="pane-logs" class="pane" style="display:none;">
      <div class="panel">
        <div class="panel-head">
          <div class="panel-title">🗒️ Archive Activity Log</div>
        </div>
        <div class="panel-body">
          <div class="tbl-wrap">
            <table>
              <thead>
                <tr>
                  <th>Date & Time</th>
                  <th>Record Type</th>
                  <th>Record ID</th>
                  <th>Action</th>
                  <th>Reason</th>
                  <th>Performed By</th>
                </tr>
              </thead>
              <tbody id="logTbody"><tr><td colspan="6" class="empty-state"><div class="e-icon">⏳</div><p>Loading…</p></td></tr></tbody>
            </table>
          </div>
          <div class="pagination" id="logPag"></div>
        </div>
      </div>
    </div>

  </div><!-- /content -->
</div><!-- /main -->

<!-- ══ MODAL: View Employee ══ -->
<div class="modal-overlay" id="modalViewEmp">
  <div class="modal">
    <div class="modal-header hd-blue">
      <span style="font-size:1.2rem;">👤</span>
      <span class="modal-title">Employee Details</span>
    </div>
    <div class="modal-body" id="modalViewEmpBody"></div>
    <div class="modal-footer">
      <button class="btn btn-cancel" onclick="closeModal('modalViewEmp')">Close</button>
      <button class="btn btn-success" id="btnRestoreEmp" onclick="confirmRestore()">♻️ Restore Employee</button>
    </div>
  </div>
</div>

<!-- ══ MODAL: Archive Employee ══ -->
<div class="modal-overlay" id="modalArchiveEmp">
  <div class="modal">
    <div class="modal-header hd-red">
      <span style="font-size:1.2rem;">🗄️</span>
      <span class="modal-title">Archive Employee</span>
    </div>
    <div class="modal-body">
      <p style="font-size:.88rem;color:var(--gray-600);margin-bottom:16px;">This will mark the employee as <strong>Separated</strong> and move them to the archive. You can restore them at any time.</p>
      <div class="form-group">
        <label>Reason for Archiving</label>
        <textarea id="archiveReason" placeholder="e.g. Resigned, Retired, End of contract…"></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-cancel" onclick="closeModal('modalArchiveEmp')">Cancel</button>
      <button class="btn btn-danger" onclick="doArchiveEmployee()">🗄️ Archive Employee</button>
    </div>
  </div>
</div>

<!-- ══ MODAL: View Contract ══ -->
<div class="modal-overlay" id="modalViewCon">
  <div class="modal" style="max-width:540px;">
    <div class="modal-header hd-blue">
      <span style="font-size:1.2rem;">📝</span>
      <span class="modal-title">Contract Details</span>
    </div>
    <div class="modal-body" id="modalViewConBody"></div>
    <div class="modal-footer">
      <button class="btn btn-cancel" onclick="closeModal('modalViewCon')">Close</button>
    </div>
  </div>
</div>

<!-- ══ MODAL: View Leave ══ -->
<div class="modal-overlay" id="modalViewLeave">
  <div class="modal" style="max-width:500px;">
    <div class="modal-header hd-blue">
      <span style="font-size:1.2rem;">📋</span>
      <span class="modal-title">Leave Request Details</span>
    </div>
    <div class="modal-body" id="modalViewLeaveBody"></div>
    <div class="modal-footer">
      <button class="btn btn-cancel" onclick="closeModal('modalViewLeave')">Close</button>
    </div>
  </div>
</div>

<!-- Toast container -->
<div class="toast-wrap" id="toastWrap"></div>

<script>
const CSRF_TOKEN = document.querySelector('meta[name=csrf-token]').content;
// ══ Helpers ════════════════════════════════════════════════
function fmtDate(d){ if(!d) return '—'; const dt=new Date(d); return dt.toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}); }
function fmtDateTime(d){ if(!d) return '—'; const dt=new Date(d); return dt.toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'})+' '+dt.toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit'}); }
function showToast(msg,type='info'){
  const w=document.getElementById('toastWrap');
  const t=document.createElement('div');
  t.className='toast toast-'+(type==='success'?'success':type==='error'?'error':'info');
  t.textContent=msg; w.appendChild(t);
  setTimeout(()=>t.remove(),3500);
}
function openModal(id){ document.getElementById(id).classList.add('open'); }
function closeModal(id){ document.getElementById(id).classList.remove('open'); }
function initials(f,l){ return ((f||'?')[0]+(l||'?')[0]).toUpperCase(); }
function debounce(fn,ms){ let t; return function(...a){ clearTimeout(t); t=setTimeout(()=>fn(...a),ms); }; }

// ══ State ══════════════════════════════════════════════════
let empPage=1, conPage=1, lvPage=1, logPage=1;
let currentEmpId=null;
let currentTab='employees';

// ══ Tab switch ════════════════════════════════════════════
function switchTab(name){
  currentTab=name;
  document.querySelectorAll('.pane').forEach(p=>p.style.display='none');
  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  document.getElementById('pane-'+name).style.display='';
  document.getElementById('tab-'+(name==='employees'?'emp':name==='contracts'?'con':name==='leaves'?'leave':'log')).classList.add('active');
  if(name==='employees'&&document.getElementById('empTbody').children[0]?.children[0]?.textContent==='Loading…') loadEmployees();
  if(name==='contracts') loadContracts();
  if(name==='leaves') loadLeaves();
  if(name==='logs') loadLogs();
}

// ══ Stats ═════════════════════════════════════════════════
async function loadStats(){
  const r=await fetch('archives.php?action=stats');
  const j=await r.json();
  if(j.success){
    document.getElementById('statEmployees').textContent=j.data.employees;
    document.getElementById('statContracts').textContent=j.data.contracts;
    document.getElementById('statLeaves').textContent=j.data.leave_requests;
    document.getElementById('statLogs').textContent=j.data.logs;
  }
}

// ══ Departments dropdown ══════════════════════════════════
async function loadDepts(){
  const r=await fetch('archives.php?action=departments');
  const j=await r.json();
  if(!j.success) return;
  const sel=document.getElementById('empDept');
  j.data.forEach(d=>{ const o=document.createElement('option'); o.value=d.department_id; o.textContent=d.name; sel.appendChild(o); });
}

// ══ EMPLOYEES ════════════════════════════════════════════
async function loadEmployees(page){
  if(page) empPage=page;
  const search=document.getElementById('empSearch').value;
  const dept=document.getElementById('empDept').value;
  const r=await fetch(`archives.php?action=list_employees&page=${empPage}&search=${encodeURIComponent(search)}&dept=${dept}`);
  const j=await r.json();
  const tbody=document.getElementById('empTbody');
  if(!j.success||!j.data.length){
    tbody.innerHTML='<tr><td colspan="8"><div class="empty-state"><div class="e-icon">🗄️</div><p>No archived employees found.</p></div></td></tr>';
    document.getElementById('empPag').innerHTML=''; return;
  }
  tbody.innerHTML=j.data.map(e=>`
    <tr>
      <td><div class="emp-cell">
        <div class="emp-avatar">${initials(e.first_name,e.last_name)}</div>
        <div><div class="emp-name">${e.last_name}, ${e.first_name}</div><div class="emp-code">${e.employee_code||'—'}</div></div>
      </div></td>
      <td>${e.position_title||'—'}</td>
      <td>${e.department||'—'}</td>
      <td><span class="badge badge-gray">${e.employment_type||'—'}</span></td>
      <td>${fmtDate(e.date_hired)}</td>
      <td>${fmtDate(e.date_separated||e.archived_at)}</td>
      <td style="max-width:140px;font-size:.78rem;color:var(--gray-600);">${e.archive_reason||'—'}</td>
      <td><div class="row-actions">
        <button class="btn-sm btn-view"    onclick="viewEmployee(${JSON.stringify(e).replace(/"/g,'&quot;')})">View</button>
        <button class="btn-sm btn-restore" onclick="quickRestore(${e.employee_id})">♻️ Restore</button>
      </div></td>
    </tr>`).join('');
  renderPag('empPag', j.page, j.pages, loadEmployees);
}
const debouncedEmp=debounce(()=>loadEmployees(1),350);

function viewEmployee(e){
  currentEmpId=e.employee_id;
  document.getElementById('modalViewEmpBody').innerHTML=`
    <div class="detail-row"><span class="detail-label">Name</span><span class="detail-val">${e.last_name}, ${e.first_name}</span></div>
    <div class="detail-row"><span class="detail-label">Employee Code</span><span class="detail-val">${e.employee_code||'—'}</span></div>
    <div class="detail-row"><span class="detail-label">Position</span><span class="detail-val">${e.position_title||'—'}</span></div>
    <div class="detail-row"><span class="detail-label">Department</span><span class="detail-val">${e.department||'—'}</span></div>
    <div class="detail-row"><span class="detail-label">Employment Type</span><span class="detail-val">${e.employment_type||'—'}</span></div>
    <div class="detail-row"><span class="detail-label">Status</span><span class="detail-val"><span class="badge badge-red">${e.employment_status}</span></span></div>
    <div class="detail-row"><span class="detail-label">Date Hired</span><span class="detail-val">${fmtDate(e.date_hired)}</span></div>
    <div class="detail-row"><span class="detail-label">Date Separated</span><span class="detail-val">${fmtDate(e.date_separated||e.archived_at)}</span></div>
    <div class="detail-row"><span class="detail-label">Archive Reason</span><span class="detail-val">${e.archive_reason||'—'}</span></div>`;
  openModal('modalViewEmp');
}

function confirmRestore(){
  if(!currentEmpId) return;
  if(confirm('Restore this employee to Active status?')) doRestore(currentEmpId);
}
async function quickRestore(id){
  if(!confirm('Restore this employee to Active status?')) return;
  doRestore(id);
}
async function doRestore(id){
  const body=new FormData(); body.append('action','restore_employee'); body.append('id',id);
  const r=await fetch('archives.php',{method:'POST',body}); const j=await r.json();
  if(j.success){ showToast('✅ '+j.message,'success'); closeModal('modalViewEmp'); loadEmployees(); loadStats(); }
  else showToast('❌ '+j.message,'error');
}

// Archive from employees page (called externally via URL param or via button)
let archiveEmpId=null;
function openArchiveModal(id){ archiveEmpId=id; document.getElementById('archiveReason').value=''; openModal('modalArchiveEmp'); }
async function doArchiveEmployee(){
  const reason=document.getElementById('archiveReason').value.trim();
  if(!archiveEmpId) return;
  const body=new FormData(); body.append('action','archive_employee'); body.append('id',archiveEmpId); body.append('reason',reason);
  const r=await fetch('archives.php',{method:'POST',body}); const j=await r.json();
  if(j.success){ showToast('✅ '+j.message,'success'); closeModal('modalArchiveEmp'); loadEmployees(); loadStats(); }
  else showToast('❌ '+j.message,'error');
}

// ══ CONTRACTS ════════════════════════════════════════════
async function loadContracts(page){
  if(page) conPage=page;
  const search=document.getElementById('conSearch').value;
  const status=document.getElementById('conStatus').value;
  const r=await fetch(`archives.php?action=list_contracts&page=${conPage}&search=${encodeURIComponent(search)}&status=${status}`);
  const j=await r.json();
  const tbody=document.getElementById('conTbody');
  if(!j.success||!j.data.length){
    tbody.innerHTML='<tr><td colspan="9"><div class="empty-state"><div class="e-icon">📝</div><p>No expired or terminated contracts found.</p></div></td></tr>';
    document.getElementById('conPag').innerHTML=''; return;
  }
  tbody.innerHTML=j.data.map(c=>`
    <tr>
      <td><div class="emp-cell">
        <div class="emp-avatar">${initials(c.first_name,c.last_name)}</div>
        <div><div class="emp-name">${c.last_name}, ${c.first_name}</div><div class="emp-code">${c.employee_code||'—'}</div></div>
      </div></td>
      <td>${c.contract_number||'—'}</td>
      <td><span class="badge badge-blue">${c.contract_type}</span></td>
      <td>${c.position_title||'—'}</td>
      <td>${c.department||'—'}</td>
      <td>${fmtDate(c.start_date)}</td>
      <td>${fmtDate(c.end_date)}</td>
      <td><span class="badge ${c.status==='Expired'?'badge-amber':'badge-red'}">${c.status}</span></td>
      <td><button class="btn-sm btn-view" onclick='viewContract(${JSON.stringify(c)})'>View</button></td>
    </tr>`).join('');
  renderPag('conPag', j.page, j.pages, loadContracts);
}
const debouncedCon=debounce(()=>loadContracts(1),350);

function viewContract(c){
  document.getElementById('modalViewConBody').innerHTML=`
    <div class="detail-row"><span class="detail-label">Employee</span><span class="detail-val">${c.last_name}, ${c.first_name}</span></div>
    <div class="detail-row"><span class="detail-label">Employee Code</span><span class="detail-val">${c.employee_code||'—'}</span></div>
    <div class="detail-row"><span class="detail-label">Contract No.</span><span class="detail-val">${c.contract_number||'—'}</span></div>
    <div class="detail-row"><span class="detail-label">Contract Type</span><span class="detail-val">${c.contract_type}</span></div>
    <div class="detail-row"><span class="detail-label">Position</span><span class="detail-val">${c.position_title||'—'}</span></div>
    <div class="detail-row"><span class="detail-label">Department</span><span class="detail-val">${c.department||'—'}</span></div>
    <div class="detail-row"><span class="detail-label">Monthly Salary</span><span class="detail-val">${c.monthly_salary?'₱'+parseFloat(c.monthly_salary).toLocaleString():'—'}</span></div>
    <div class="detail-row"><span class="detail-label">Start Date</span><span class="detail-val">${fmtDate(c.start_date)}</span></div>
    <div class="detail-row"><span class="detail-label">End Date</span><span class="detail-val">${fmtDate(c.end_date)}</span></div>
    <div class="detail-row"><span class="detail-label">Status</span><span class="detail-val"><span class="badge ${c.status==='Expired'?'badge-amber':'badge-red'}">${c.status}</span></span></div>
    <div class="detail-row"><span class="detail-label">Remarks</span><span class="detail-val">${c.remarks||'—'}</span></div>`;
  openModal('modalViewCon');
}

// ══ LEAVE REQUESTS ════════════════════════════════════════
async function loadLeaves(page){
  if(page) lvPage=page;
  const search=document.getElementById('lvSearch').value;
  const status=document.getElementById('lvStatus').value;
  const r=await fetch(`archives.php?action=list_leaves&page=${lvPage}&search=${encodeURIComponent(search)}&status=${status}`);
  const j=await r.json();
  const tbody=document.getElementById('lvTbody');
  if(!j.success||!j.data.length){
    tbody.innerHTML='<tr><td colspan="9"><div class="empty-state"><div class="e-icon">📋</div><p>No denied or cancelled leave requests found.</p></div></td></tr>';
    document.getElementById('lvPag').innerHTML=''; return;
  }
  tbody.innerHTML=j.data.map(l=>`
    <tr>
      <td><div class="emp-cell">
        <div class="emp-avatar">${initials(l.first_name,l.last_name)}</div>
        <div><div class="emp-name">${l.last_name}, ${l.first_name}</div><div class="emp-code">${l.employee_code||'—'}</div></div>
      </div></td>
      <td>${l.leave_type}</td>
      <td>${fmtDate(l.date_from)}</td>
      <td>${fmtDate(l.date_to)}</td>
      <td><strong>${l.days_requested}</strong></td>
      <td>${fmtDate(l.filed_at)}</td>
      <td><span class="badge ${l.status==='denied'?'badge-red':'badge-gray'}">${l.status.charAt(0).toUpperCase()+l.status.slice(1)}</span></td>
      <td style="max-width:140px;font-size:.78rem;color:var(--gray-600);">${l.admin_remarks||'—'}</td>
      <td><button class="btn-sm btn-view" onclick='viewLeave(${JSON.stringify(l)})'>View</button></td>
    </tr>`).join('');
  renderPag('lvPag', j.page, j.pages, loadLeaves);
}
const debouncedLv=debounce(()=>loadLeaves(1),350);

function viewLeave(l){
  document.getElementById('modalViewLeaveBody').innerHTML=`
    <div class="detail-row"><span class="detail-label">Employee</span><span class="detail-val">${l.last_name}, ${l.first_name}</span></div>
    <div class="detail-row"><span class="detail-label">Leave Type</span><span class="detail-val">${l.leave_type}</span></div>
    <div class="detail-row"><span class="detail-label">Date From</span><span class="detail-val">${fmtDate(l.date_from)}</span></div>
    <div class="detail-row"><span class="detail-label">Date To</span><span class="detail-val">${fmtDate(l.date_to)}</span></div>
    <div class="detail-row"><span class="detail-label">Days Requested</span><span class="detail-val">${l.days_requested}</span></div>
    <div class="detail-row"><span class="detail-label">Filed On</span><span class="detail-val">${fmtDate(l.filed_at)}</span></div>
    <div class="detail-row"><span class="detail-label">Status</span><span class="detail-val"><span class="badge ${l.status==='denied'?'badge-red':'badge-gray'}">${l.status.charAt(0).toUpperCase()+l.status.slice(1)}</span></span></div>
    <div class="detail-row"><span class="detail-label">Reason</span><span class="detail-val">${l.reason||'—'}</span></div>
    <div class="detail-row"><span class="detail-label">Admin Remarks</span><span class="detail-val">${l.admin_remarks||'—'}</span></div>`;
  openModal('modalViewLeave');
}

// ══ ACTIVITY LOG ══════════════════════════════════════════
async function loadLogs(page){
  if(page) logPage=page;
  const r=await fetch(`archives.php?action=list_logs&page=${logPage}`);
  const j=await r.json();
  const tbody=document.getElementById('logTbody');
  if(!j.success||!j.data.length){
    tbody.innerHTML='<tr><td colspan="6"><div class="empty-state"><div class="e-icon">🗒️</div><p>No activity log entries yet.</p></div></td></tr>';
    document.getElementById('logPag').innerHTML=''; return;
  }
  tbody.innerHTML=j.data.map(l=>`
    <tr>
      <td>${fmtDateTime(l.performed_at)}</td>
      <td><span class="badge badge-blue">${l.record_type}</span></td>
      <td>#${l.record_id}</td>
      <td><span class="log-action-${l.action}">${l.action.charAt(0).toUpperCase()+l.action.slice(1)}</span></td>
      <td style="max-width:180px;font-size:.80rem;">${l.reason||'—'}</td>
      <td>${l.performed_by_name||'System'}</td>
    </tr>`).join('');
  renderPag('logPag', j.page, j.pages, loadLogs);
}

// ══ Pagination renderer ══════════════════════════════════
function renderPag(elId, page, pages, fn){
  const el=document.getElementById(elId);
  if(pages<=1){ el.innerHTML=''; return; }
  let html=`<span class="pg-info">Page ${page} of ${pages}</span><div class="pg-btns">`;
  html+=`<button class="pg-btn" onclick="${fn.name}(${page-1})" ${page<=1?'disabled':''}>‹ Prev</button>`;
  for(let i=Math.max(1,page-2);i<=Math.min(pages,page+2);i++){
    html+=`<button class="pg-btn${i===page?' active':''}" onclick="${fn.name}(${i})">${i}</button>`;
  }
  html+=`<button class="pg-btn" onclick="${fn.name}(${page+1})" ${page>=pages?'disabled':''}>Next ›</button></div>`;
  el.innerHTML=html;
}

// ══ Init ══════════════════════════════════════════════════
document.getElementById('tbDate').textContent=new Date().toLocaleDateString('en-PH',{weekday:'short',month:'short',day:'numeric',year:'numeric'});
loadStats();
loadDepts();
loadEmployees();
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
</script>

</body>
</html>