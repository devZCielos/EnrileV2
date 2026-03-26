<?php
// ============================================================
//  LGU ENRILE HRMS — Reports Page
//  File: Admin/reports.php
// ============================================================
session_start();
// ── Session timeout — 8 hours ─────────────────────────────────
$_sessionLifetime = 8 * 60 * 60;
if (isset($_SESSION['login_time']) && (time() - strtotime($_SESSION['login_time']) > $_sessionLifetime)) {
    $_isAjax = !empty($_GET['action']) || !empty($_POST['action']);
    session_unset(); session_destroy();
    if ($_isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'message'=>'SESSION_EXPIRED: Your session has expired. Please log in again.']);
        exit();
    }
    header('Location: ../Login/login.php?error=' . urlencode('Your session has expired. Please log in again.'));
    exit();
}

if (empty($_SESSION['user_id']) || empty($_SESSION['user_email'])) {
    header('Location: ../Login/login.php'); exit();
}
$allowedRoles = ['admin', 'hr_staff', 'dept_head'];
if (!in_array($_SESSION['user_role'] ?? '', $allowedRoles)) {
    header('Location: ../Employee/Employee_portal.php'); exit();
}

require_once __DIR__ . '/../config/db.php';

// ════════════════════════════════════════════════════════════
//  AJAX HANDLER
// ════════════════════════════════════════════════════════════
$action = $_GET['action'] ?? '';

if ($action) {
    header('Content-Type: application/json');
    try {
        switch ($action) {

            case 'overview':
                $employees = $pdo->query("
                    SELECT COUNT(*) AS total,
                        SUM(employment_status = 'Active')      AS active,
                        SUM(employment_status = 'Inactive')    AS inactive,
                        SUM(employment_status = 'On Leave')    AS on_leave,
                        SUM(employment_type   = 'Permanent')   AS permanent,
                        SUM(employment_type   = 'Contractual') AS contractual,
                        SUM(employment_type   = 'Casual')      AS casual,
                        SUM(employment_type   = 'Job Order')   AS job_order,
                        SUM(gender = 'Male')   AS male,
                        SUM(gender = 'Female') AS female
                    FROM employees
                ")->fetch();

                $leave = $pdo->query("
                    SELECT COUNT(*) AS total,
                        SUM(status = 'Pending')  AS pending,
                        SUM(status = 'Approved') AS approved,
                        SUM(status = 'Rejected') AS rejected,
                        SUM(days_requested) AS total_days_taken
                    FROM leave_requests
                    WHERE YEAR(filed_at) = YEAR(CURDATE())
                ")->fetch();

                $contracts = $pdo->query("
                    SELECT COUNT(*) AS total,
                        SUM(status = 'Active')  AS active,
                        SUM(status = 'Expired') AS expired,
                        SUM(status = 'Active' AND end_date IS NOT NULL
                            AND DATEDIFF(end_date, CURDATE()) BETWEEN 0 AND 30) AS expiring_soon
                    FROM contracts
                ")->fetch();

                $departments = $pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn();

                echo json_encode(['success'=>true,'employees'=>$employees,'leave'=>$leave,
                                  'contracts'=>$contracts,'departments'=>$departments]);
                break;

            case 'by_department':
                $stmt = $pdo->query("
                    SELECT COALESCE(d.name,'Unassigned') AS department,
                        COUNT(*) AS total,
                        SUM(e.employment_status='Active')   AS active,
                        SUM(e.employment_status='Inactive') AS inactive,
                        SUM(e.gender='Male')   AS male,
                        SUM(e.gender='Female') AS female
                    FROM employees e
                    LEFT JOIN departments d ON e.department_id=d.department_id
                    GROUP BY d.department_id, d.name ORDER BY total DESC
                ");
                echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]);
                break;

            case 'by_type':
                $stmt = $pdo->query("
                    SELECT employment_type AS label, COUNT(*) AS value
                    FROM employees GROUP BY employment_type ORDER BY value DESC
                ");
                echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]);
                break;

            case 'leave_by_type':
                $year = (int)($_GET['year'] ?? date('Y'));
                $stmt = $pdo->prepare("
                    SELECT lt.name AS leave_type,
                        COUNT(lr.leave_request_id) AS requests,
                        SUM(lr.total_days) AS total_days,
                        SUM(lr.status='Approved') AS approved,
                        SUM(lr.status='Rejected') AS rejected,
                        SUM(lr.status='Pending')  AS pending
                    FROM leave_types lt
                    LEFT JOIN leave_requests lr ON lt.leave_type_id=lr.leave_type_id
                        AND YEAR(lr.date_filed)=?
                    GROUP BY lt.leave_type_id, lt.name ORDER BY total_days DESC
                ");
                $stmt->execute([$year]);
                echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]);
                break;

            case 'leave_monthly':
                $year = (int)($_GET['year'] ?? date('Y'));
                $stmt = $pdo->prepare("
                    SELECT MONTH(date_filed) AS month_num, MONTHNAME(date_filed) AS month_name,
                        COUNT(*) AS requests, SUM(total_days) AS days,
                        SUM(status='Approved') AS approved, SUM(status='Rejected') AS rejected
                    FROM leave_requests WHERE YEAR(date_filed)=?
                    GROUP BY MONTH(date_filed), MONTHNAME(date_filed) ORDER BY month_num
                ");
                $stmt->execute([$year]);
                echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]);
                break;

            case 'top_leave':
                $year = (int)($_GET['year'] ?? date('Y'));
                $stmt = $pdo->prepare("
                    SELECT CONCAT(e.first_name,' ',e.last_name) AS employee,
                        e.employee_code, COALESCE(d.name,'—') AS department,
                        COUNT(lr.leave_request_id) AS requests, SUM(lr.total_days) AS total_days
                    FROM leave_requests lr
                    JOIN employees e ON lr.employee_id=e.employee_id
                    LEFT JOIN departments d ON e.department_id=d.department_id
                    WHERE YEAR(lr.date_filed)=? AND lr.status='Approved'
                    GROUP BY lr.employee_id ORDER BY total_days DESC LIMIT 10
                ");
                $stmt->execute([$year]);
                echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]);
                break;

            case 'contracts_expiring':
                $days = (int)($_GET['days'] ?? 30);
                $stmt = $pdo->prepare("
                    SELECT CONCAT(e.first_name,' ',e.last_name) AS employee,
                        e.employee_code, COALESCE(d.name,'—') AS department,
                        c.contract_type, c.position_title, c.end_date,
                        DATEDIFF(c.end_date, CURDATE()) AS days_remaining
                    FROM contracts c
                    JOIN employees e ON c.employee_id=e.employee_id
                    LEFT JOIN departments d ON c.department_id=d.department_id
                    WHERE c.status='Active' AND c.end_date IS NOT NULL
                        AND DATEDIFF(c.end_date, CURDATE()) BETWEEN 0 AND ?
                    ORDER BY c.end_date ASC
                ");
                $stmt->execute([$days]);
                echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]);
                break;

            case 'headcount':
                $stmt = $pdo->query("
                    SELECT YEAR(date_hired) AS yr, COUNT(*) AS hired
                    FROM employees WHERE date_hired IS NOT NULL
                    GROUP BY YEAR(date_hired) ORDER BY yr ASC LIMIT 10
                ");
                echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]);
                break;

            case 'balance_summary':
                $year = (int)($_GET['year'] ?? date('Y'));
                $stmt = $pdo->prepare("
                    SELECT lt.name AS leave_type,
                        SUM(lb.total_credits)   AS total_credits,
                        SUM(lb.used_days)        AS used_days,
                        SUM(lb.remaining_days)   AS remaining_days,
                        COUNT(DISTINCT lb.employee_id) AS employees
                    FROM leave_balances lb
                    JOIN leave_types lt ON lb.leave_type_id=lt.leave_type_id
                    WHERE lb.calendar_year=?
                    GROUP BY lt.leave_type_id, lt.name ORDER BY used_days DESC
                ");
                $stmt->execute([$year]);
                echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]);
                break;
            default:
                echo json_encode(['success'=>false,'message'=>'Unknown action.']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success'=>false,'message'=>'DB Error: '.$e->getMessage()]);
    }
    exit();
}

$adminName   = ucwords($_SESSION['user_name'] ?? 'Administrator');
$roleLabels  = ['admin'=>'Super Admin','hr_staff'=>'HR Staff','dept_head'=>'Department Head'];
$roleLabel   = $roleLabels[$_SESSION['user_role']] ?? 'Admin';
$currentYear = date('Y');
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Reports | LGU Enrile HRMS</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../css/admin.css"/>
  <style>
    /* ══════════════════════════════════════════════
       CSS VARIABLES & RESET  (matches other pages)
    ══════════════════════════════════════════════ */
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
    * { box-sizing:border-box; margin:0; padding:0; }
    body { font-family:'Nunito',sans-serif; background:var(--gray-100); color:var(--secondary); display:flex; min-height:100vh; }

    /* ══════════════════════════════════════════════
       SIDEBAR  (identical to all other pages)
    ══════════════════════════════════════════════ */
    .sidebar { width:var(--sidebar-w); background:linear-gradient(180deg,#2563eb 0%,#1e40af 55%,#22c55e 100%); display:flex; flex-direction:column; position:fixed; top:0; left:0; height:100vh; z-index:200; transition:transform 0.3s; box-shadow:4px 0 28px rgba(0,0,0,0.20); }
    .sidebar-brand { padding:22px 20px 18px; display:flex; align-items:center; gap:12px; border-bottom:1px solid rgba(255,255,255,0.15); }
    .brand-logo { width:44px; height:44px; border-radius:50%; object-fit:cover; border:2.5px solid rgba(255,255,255,0.70); box-shadow:0 2px 8px rgba(0,0,0,0.20); }
    .brand-text h2 { font-family:'Playfair Display',serif; font-size:1rem; color:#fff; line-height:1.2; }
    .brand-text span { font-size:0.68rem; color:rgba(255,255,255,0.60); font-weight:700; text-transform:uppercase; letter-spacing:0.10em; }
    .sidebar-nav { flex:1; padding:14px 12px; overflow-y:auto; scrollbar-width:thin; scrollbar-color:rgba(255,255,255,0.20) transparent; }
    .nav-label { font-size:0.63rem; font-weight:800; color:rgba(255,255,255,0.38); text-transform:uppercase; letter-spacing:0.16em; padding:10px 10px 5px; margin-top:6px; }
    .nav-item { display:flex; align-items:center; gap:11px; padding:9px 12px; border-radius:var(--radius-sm); color:rgba(255,255,255,0.75); text-decoration:none; font-size:0.875rem; font-weight:600; transition:all 0.2s; margin-bottom:2px; position:relative; }
    .nav-item:hover { background:rgba(255,255,255,0.13); color:#fff; }
    .nav-item.active { background:rgba(255,255,255,0.22); color:#fff; }
    .nav-item.active::before { content:''; position:absolute; left:0; top:20%; bottom:20%; width:3px; border-radius:2px; background:#fff; }
    .nav-icon { width:30px; height:30px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:0.95rem; background:rgba(255,255,255,0.10); flex-shrink:0; }
    .nav-item.active .nav-icon { background:rgba(255,255,255,0.25); }
    .sidebar-footer { padding:14px 12px; border-top:1px solid rgba(255,255,255,0.13); }
    .sidebar-user { display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:var(--radius-sm); background:rgba(255,255,255,0.10); }
    .user-avatar { width:36px; height:36px; border-radius:50%; background:rgba(255,255,255,0.25); border:2px solid rgba(255,255,255,0.50); display:flex; align-items:center; justify-content:center; font-weight:800; color:#fff; font-size:0.80rem; flex-shrink:0; }
    .user-info { flex:1; min-width:0; }
    .user-info strong { display:block; font-size:0.82rem; color:#fff; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .user-info span { font-size:0.68rem; color:rgba(255,255,255,0.52); }
    .logout-btn { display:flex; align-items:center; gap:9px; margin:8px 10px 2px; padding:10px 14px; border-radius:10px; background:rgba(239,68,68,0.12); border:1.5px solid rgba(239,68,68,0.28); color:#fca5a5; font-size:0.845rem; font-weight:700; text-decoration:none; transition:all .2s; cursor:pointer; }
    .logout-btn:hover { background:rgba(239,68,68,0.26); border-color:rgba(239,68,68,0.55); color:#fff; transform:translateY(-1px); }
    .logout-icon { font-size:1rem; }

    /* ══════════════════════════════════════════════
       MAIN LAYOUT  (identical to all other pages)
    ══════════════════════════════════════════════ */
    .main { margin-left:var(--sidebar-w); flex:1; display:flex; flex-direction:column; }
    .topbar { height:var(--topbar-h); background:linear-gradient(135deg,#2563eb 0%,#1e40af 50%,#22c55e 100%); display:flex; align-items:center; padding:0 28px; gap:16px; position:sticky; top:0; z-index:100; box-shadow:0 2px 16px rgba(37,99,235,0.30); }
    .menu-toggle { display:none; background:none; border:none; font-size:1.4rem; cursor:pointer; color:rgba(255,255,255,0.85); padding:4px; }
    .topbar-title { flex:1; }
    .topbar-title h1 { font-size:1.20rem; font-weight:800; color:#fff; font-family:'Playfair Display',serif; }
    .topbar-title p  { font-size:0.76rem; color:rgba(255,255,255,0.70); margin-top:1px; }
    .topbar-actions { display:flex; align-items:center; gap:8px; }
    .topbar-btn { position:relative; width:38px; height:38px; border-radius:50%; border:1px solid rgba(255,255,255,0.25); background:rgba(255,255,255,0.15); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:1rem; color:#fff; transition:all 0.2s; }
    .topbar-btn:hover { background:rgba(255,255,255,0.28); }
    .notif-badge { position:absolute; top:-2px; right:-2px; width:16px; height:16px; background:#ef4444; border-radius:50%; font-size:0.58rem; color:white; font-weight:800; display:flex; align-items:center; justify-content:center; border:2px solid rgba(255,255,255,0.3); }
    .content { padding:24px 28px; flex:1; }

    /* Print button lives in topbar */
    .btn-print { display:flex; align-items:center; gap:7px; padding:8px 16px; background:rgba(255,255,255,0.15); border:1.5px solid rgba(255,255,255,0.28); border-radius:var(--radius-sm); font-family:'Nunito',sans-serif; font-size:0.84rem; font-weight:700; color:#fff; cursor:pointer; transition:all 0.2s; white-space:nowrap; }
    .btn-print:hover { background:rgba(255,255,255,0.28); transform:translateY(-1px); }
    .btn-print:hover { background:rgba(255,255,255,0.28); }

    /* ══════════════════════════════════════════════
       STAT CARDS  (same pattern as leave_requests)
    ══════════════════════════════════════════════ */
    .stat-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:16px; margin-bottom:22px; }
    .stat-card { background:var(--white); border-radius:var(--radius); padding:18px 20px; border:1.5px solid var(--gray-200); box-shadow:var(--shadow-sm); position:relative; overflow:hidden; transition:transform 0.2s,box-shadow 0.2s; animation:fadeIn 0.4s ease both; }
    .stat-card:hover { transform:translateY(-3px); box-shadow:var(--shadow-md); }
    .stat-card::before { content:''; position:absolute; top:0; left:0; right:0; height:4px; border-radius:var(--radius) var(--radius) 0 0; }
    .stat-card.blue::before   { background:linear-gradient(90deg,#2563eb,#60a5fa); }
    .stat-card.green::before  { background:linear-gradient(90deg,#22c55e,#86efac); }
    .stat-card.orange::before { background:linear-gradient(90deg,#f59e0b,#fcd34d); }
    .stat-card.red::before    { background:linear-gradient(90deg,#ef4444,#fca5a5); }
    .stat-card.purple::before { background:linear-gradient(90deg,#a855f7,#d8b4fe); }
    .stat-card.sky::before    { background:linear-gradient(90deg,#0ea5e9,#7dd3fc); }
    .stat-card-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
    .stat-icon { width:44px; height:44px; border-radius:11px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; }
    .stat-card.blue   .stat-icon { background:#dbeafe; }
    .stat-card.green  .stat-icon { background:#dcfce7; }
    .stat-card.orange .stat-icon { background:#fef3c7; }
    .stat-card.red    .stat-icon { background:#fee2e2; }
    .stat-card.purple .stat-icon { background:#f3e8ff; }
    .stat-card.sky    .stat-icon { background:#e0f2fe; }
    .stat-value { font-size:2rem; font-weight:800; color:var(--gray-800); line-height:1; margin-bottom:3px; }
    .stat-label { font-size:0.78rem; color:var(--gray-400); font-weight:600; }
    .stat-sub   { font-size:0.70rem; color:var(--gray-400); margin-top:6px; }

    /* ══════════════════════════════════════════════
       PANELS  (white cards with gray-50 header)
    ══════════════════════════════════════════════ */
    .reports-wrap { display:flex; flex-direction:column; gap:20px; }
    .two-col { display:grid; grid-template-columns:1fr 1fr; gap:20px; }

    .panel { background:var(--white); border-radius:var(--radius); border:1.5px solid var(--gray-200); box-shadow:var(--shadow-sm); overflow:hidden; animation:fadeIn 0.4s ease both; }
    .panel-head { display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-bottom:1.5px solid var(--gray-100); background:var(--gray-50); flex-wrap:wrap; gap:10px; }
    .panel-head h3 { font-size:0.93rem; font-weight:800; color:var(--gray-800); margin:0; font-family:'Playfair Display',serif; }
    .panel-body { padding:18px 20px; }
    .panel-body.no-pad { padding:0; }

    /* ══════════════════════════════════════════════
       YEAR SELECT & TABS
    ══════════════════════════════════════════════ */
    .year-select { padding:6px 12px; border:1.5px solid var(--gray-200); border-radius:var(--radius-sm); font-family:'Nunito',sans-serif; font-size:0.82rem; font-weight:700; color:var(--gray-600); background:var(--white); outline:none; cursor:pointer; transition:border-color 0.2s; }
    .year-select:focus { border-color:var(--primary); }

    .tabs { display:flex; background:var(--white); border-radius:var(--radius-sm); border:1.5px solid var(--gray-200); overflow:hidden; width:fit-content; }
    .tab-btn { padding:6px 16px; border:none; background:transparent; font-family:'Nunito',sans-serif; font-size:0.80rem; font-weight:700; color:var(--gray-400); cursor:pointer; transition:all 0.2s; border-right:1px solid var(--gray-200); white-space:nowrap; }
    .tab-btn:last-child { border-right:none; }
    .tab-btn.active { background:linear-gradient(135deg,var(--primary),var(--primary-dark)); color:white; }

    /* ══════════════════════════════════════════════
       BAR CHART
    ══════════════════════════════════════════════ */
    .bar-chart { display:flex; flex-direction:column; gap:11px; }
    .bar-row { display:flex; align-items:center; gap:10px; }
    .bar-label { font-size:0.78rem; color:var(--gray-600); font-weight:600; width:140px; flex-shrink:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .bar-track { flex:1; background:var(--gray-100); border-radius:8px; height:22px; overflow:hidden; }
    .bar-fill  { height:100%; border-radius:8px; transition:width 0.7s cubic-bezier(0.4,0,0.2,1); min-width:2px; }
    .bar-val   { font-size:0.78rem; font-weight:800; color:var(--gray-600); width:36px; text-align:right; flex-shrink:0; }

    /* ══════════════════════════════════════════════
       SPARKLINE BAR CHART
    ══════════════════════════════════════════════ */
    .sparkline-wrap { display:flex; align-items:flex-end; gap:5px; height:130px; padding-bottom:22px; position:relative; }
    .spark-col { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; gap:3px; height:100%; position:relative; padding-bottom:18px; }
    .spark-val { font-size:0.63rem; font-weight:700; white-space:nowrap; }
    .spark-bar { width:75%; border-radius:3px 3px 0 0; transition:height 0.5s ease; min-height:3px; }
    .spark-lbl { position:absolute; bottom:0; left:50%; transform:translateX(-50%); font-size:0.63rem; color:var(--gray-400); white-space:nowrap; }

    /* ══════════════════════════════════════════════
       SVG DONUT
    ══════════════════════════════════════════════ */
    .donut-wrap { display:flex; align-items:center; gap:24px; flex-wrap:wrap; }
    .donut-legend { display:flex; flex-direction:column; gap:9px; flex:1; }
    .donut-item { display:flex; align-items:center; gap:8px; font-size:0.82rem; color:var(--gray-600); }
    .donut-dot  { width:11px; height:11px; border-radius:3px; flex-shrink:0; }
    .donut-pct  { margin-left:auto; padding-left:12px; font-size:0.82rem; font-weight:800; color:var(--gray-800); }

    /* ══════════════════════════════════════════════
       TABLES
    ══════════════════════════════════════════════ */
    .rep-table { width:100%; border-collapse:collapse; font-size:0.83rem; }
    .rep-table thead tr { background:var(--gray-50); }
    .rep-table thead th { padding:10px 16px; text-align:left; font-size:0.69rem; font-weight:800; color:var(--gray-400); text-transform:uppercase; letter-spacing:0.07em; border-bottom:1.5px solid var(--gray-200); white-space:nowrap; }
    .rep-table tbody tr { border-bottom:1px solid var(--gray-100); transition:background 0.15s; }
    .rep-table tbody tr:last-child { border-bottom:none; }
    .rep-table tbody tr:hover { background:#f0f6ff; }
    .rep-table td { padding:11px 16px; color:var(--gray-800); vertical-align:middle; }
    .rep-table td.num { font-weight:700; text-align:right; }

    /* ══════════════════════════════════════════════
       BADGES
    ══════════════════════════════════════════════ */
    .badge { display:inline-flex; align-items:center; padding:3px 10px; border-radius:20px; font-size:0.70rem; font-weight:700; white-space:nowrap; }
    .badge-green  { background:#dcfce7; color:#166534; }
    .badge-red    { background:#fee2e2; color:#991b1b; }
    .badge-amber  { background:#fef3c7; color:#92400e; }
    .badge-blue   { background:#dbeafe; color:#1e40af; }
    .badge-purple { background:#f3e8ff; color:#6b21a8; }
    .badge-gray   { background:var(--gray-100); color:var(--gray-600); }

    /* ══════════════════════════════════════════════
       DAYS PILL (same style as contracts page)
    ══════════════════════════════════════════════ */
    .days-pill { display:inline-block; padding:3px 10px; border-radius:20px; font-size:0.72rem; font-weight:800; }
    .dp-safe   { background:#dcfce7; color:#166534; }
    .dp-warn   { background:#fef3c7; color:#92400e; }
    .dp-danger { background:#fee2e2; color:#991b1b; }

    /* ══════════════════════════════════════════════
       MEDAL RANK
    ══════════════════════════════════════════════ */
    .rank-num { font-size:0.78rem; font-weight:800; color:var(--gray-400); }

    /* ══════════════════════════════════════════════
       EMPTY STATE
    ══════════════════════════════════════════════ */
    .empty-rep { text-align:center; padding:32px 20px; color:var(--gray-400); font-size:0.875rem; font-weight:600; }

    /* ══════════════════════════════════════════════
       TOAST
    ══════════════════════════════════════════════ */
    .toast { position:fixed; bottom:28px; right:28px; padding:12px 20px; border-radius:var(--radius-sm); font-size:0.84rem; font-weight:700; box-shadow:var(--shadow-lg); z-index:900; color:white; animation:fadeIn 0.3s ease both; max-width:340px; }
    .toast.success { background:linear-gradient(135deg,#22c55e,#16a34a); }
    .toast.error   { background:linear-gradient(135deg,#ef4444,#dc2626); }

    @keyframes fadeIn { from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);} }

    /* ══════════════════════════════════════════════
       RESPONSIVE
    ══════════════════════════════════════════════ */
    .overlay-mob { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:150; }
    .overlay-mob.show { display:block; }

    @media(max-width:1100px) { .stat-cards { grid-template-columns:repeat(3,1fr); } }
    @media(max-width:900px)  { .two-col { grid-template-columns:1fr; } }
    @media(max-width:768px)  {
      .sidebar { transform:translateX(-100%); }
      .sidebar.open { transform:translateX(0); }
      .main { margin-left:0; }
      .menu-toggle { display:block; }
      .content { padding:14px 12px; }
      .topbar { padding:0 14px; }
      .stat-cards { grid-template-columns:repeat(2,1fr); gap:10px; }
    }
    @media(max-width:480px)  {
      .stat-cards { grid-template-columns:1fr 1fr; }
      .content { padding:12px 10px; }
      .topbar { padding:0 12px; }
      .topbar-title p { display:none; }
      .btn-print span { display:none; }
      .toast { bottom:16px; right:16px; left:16px; max-width:100%; }
    }

    /* ══════════════════════════════════════════════
       PRINT
    ══════════════════════════════════════════════ */
    @media print {
      .sidebar, .topbar, .btn-print, .overlay-mob { display:none !important; }
      .main { margin:0 !important; }
      .panel { box-shadow:none !important; border:1px solid var(--gray-200) !important; break-inside:avoid; }
      .stat-card { box-shadow:none !important; }
      .year-select, .tabs { display:none !important; }
    }
  </style>
</head>
<body>

<!-- ══ SIDEBAR ══ -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <img src="../images/Enrile.png" alt="LGU Logo" class="brand-logo" onerror="this.style.display='none'"/>
    <div class="brand-text"><h2>LGU Enrile</h2><span>HR Management System</span></div>
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
    <a class="nav-item" href="pds_saln.php"><div class="nav-icon">🆔</div>PDS &amp; SALN</a>
    <a class="nav-item" href="service_records.php"><div class="nav-icon">📜</div>Service Records</a>
    <a class="nav-item" href="contracts.php"><div class="nav-icon">📝</div>Contracts</a>
    <div class="nav-label">System</div>
    <a class="nav-item active" href="reports.php"><div class="nav-icon">📊</div>Reports</a>
    <a class="nav-item" href="announcements.php"><div class="nav-icon">📢</div>Announcements</a>
    <a class="nav-item" href="archives.php"><div class="nav-icon">🗄️</div>Archives</a>
    <a class="nav-item" href="user_accounts.php"><div class="nav-icon">🔐</div>User Accounts</a>
    <a class="nav-item" href="settings.php"><div class="nav-icon">⚙️</div>Settings</a>
  </nav>
  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="user-avatar"><?= strtoupper(substr($adminName,0,2)) ?></div>
      <div class="user-info">
        <strong><?= htmlspecialchars($adminName) ?></strong>
        <span><?= htmlspecialchars($roleLabel) ?></span>
      </div>
    </div>
  </div>
</aside>
<div class="overlay-mob" id="overlayMob" onclick="toggleSidebar()"></div>

<!-- ══ MAIN ══ -->
<div class="main">
  <div class="topbar">
    <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
    <div class="topbar-title">
      <h1>Reports</h1>
      <p>HR analytics and workforce summaries</p>
    </div>
    <div class="topbar-actions">
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
      <button class="btn-print" onclick="window.print()">🖨️ <span>Print Report</span></button>
      <div class="tb-profile-wrap" id="tbProfileWrap">
        <div class="tb-profile-btn" onclick="toggleProfileDropdown()" title="Account"><?= strtoupper(substr($adminName,0,2)) ?></div>
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
    <div class="reports-wrap">

      <!-- ══ OVERVIEW STAT CARDS ══ -->
      <div class="stat-cards">
        <div class="stat-card blue">
          <div class="stat-card-header"><div class="stat-icon">👥</div></div>
          <div class="stat-value" id="ovTotalEmp">—</div>
          <div class="stat-label">Total Employees</div>
          <div class="stat-sub" id="ovEmpSub">Loading…</div>
        </div>
        <div class="stat-card green">
          <div class="stat-card-header"><div class="stat-icon">✅</div></div>
          <div class="stat-value" id="ovActive">—</div>
          <div class="stat-label">Active Employees</div>
          <div class="stat-sub" id="ovActiveSub">Loading…</div>
        </div>
        <div class="stat-card sky">
          <div class="stat-card-header"><div class="stat-icon">🏢</div></div>
          <div class="stat-value" id="ovDepts">—</div>
          <div class="stat-label">Departments</div>
          <div class="stat-sub">Organizational units</div>
        </div>
        <div class="stat-card orange">
          <div class="stat-card-header"><div class="stat-icon">📋</div></div>
          <div class="stat-value" id="ovLeaveReq">—</div>
          <div class="stat-label">Leave Requests <?= $currentYear ?></div>
          <div class="stat-sub" id="ovLeaveSub">Loading…</div>
        </div>
        <div class="stat-card red">
          <div class="stat-card-header"><div class="stat-icon">⚠️</div></div>
          <div class="stat-value" id="ovExpiring">—</div>
          <div class="stat-label">Expiring Contracts</div>
          <div class="stat-sub">Within 30 days</div>
        </div>
        <div class="stat-card purple">
          <div class="stat-card-header"><div class="stat-icon">📝</div></div>
          <div class="stat-value" id="ovContracts">—</div>
          <div class="stat-label">Active Contracts</div>
          <div class="stat-sub" id="ovContractSub">Loading…</div>
        </div>
      </div>

      <!-- ══ ROW 1: By Department + Employment Type ══ -->
      <div class="two-col">

        <div class="panel">
          <div class="panel-head">
            <h3>👥 Employees by Department</h3>
          </div>
          <div class="panel-body">
            <div class="bar-chart" id="deptChart">
              <p class="empty-rep">Loading…</p>
            </div>
          </div>
        </div>

        <div class="panel">
          <div class="panel-head">
            <h3>📋 Employment Type Breakdown</h3>
          </div>
          <div class="panel-body">
            <div class="donut-wrap" id="typeDonut">
              <p class="empty-rep">Loading…</p>
            </div>
          </div>
        </div>

      </div>

      <!-- ══ ROW 2: Leave by Month ══ -->
      <div class="panel">
        <div class="panel-head">
          <h3>📅 Leave Requests by Month</h3>
          <select class="year-select" id="leaveMonthYear" onchange="loadLeaveMonthly()">
            <?php for ($y = $currentYear; $y >= $currentYear - 4; $y--): ?>
              <option value="<?= $y ?>"><?= $y ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="panel-body">
          <div class="sparkline-wrap" id="monthlyChart">
            <p class="empty-rep" style="width:100%">Loading…</p>
          </div>
        </div>
      </div>

      <!-- ══ ROW 3: Leave by Type + Balance Summary ══ -->
      <div class="two-col">

        <div class="panel">
          <div class="panel-head">
            <h3>🏖️ Leave by Type</h3>
            <select class="year-select" id="leaveTypeYear" onchange="loadLeaveByType()">
              <?php for ($y = $currentYear; $y >= $currentYear - 4; $y--): ?>
                <option value="<?= $y ?>"><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="panel-body no-pad">
            <table class="rep-table" id="leaveTypeTable">
              <thead>
                <tr>
                  <th>Leave Type</th>
                  <th style="text-align:right">Requests</th>
                  <th style="text-align:right">Days Taken</th>
                  <th style="text-align:right">Approved</th>
                </tr>
              </thead>
              <tbody><tr><td colspan="4" class="empty-rep">Loading…</td></tr></tbody>
            </table>
          </div>
        </div>

        <div class="panel">
          <div class="panel-head">
            <h3>⚖️ Leave Balance Summary</h3>
            <select class="year-select" id="balYear" onchange="loadBalanceSummary()">
              <?php for ($y = $currentYear; $y >= $currentYear - 4; $y--): ?>
                <option value="<?= $y ?>"><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="panel-body no-pad">
            <table class="rep-table" id="balSummaryTable">
              <thead>
                <tr>
                  <th>Leave Type</th>
                  <th style="text-align:right">Credits</th>
                  <th style="text-align:right">Used</th>
                  <th style="text-align:right">Remaining</th>
                </tr>
              </thead>
              <tbody><tr><td colspan="4" class="empty-rep">Loading…</td></tr></tbody>
            </table>
          </div>
        </div>

      </div>

      <!-- ══ ROW 4: Top Leave Takers ══ -->
      <div class="panel">
        <div class="panel-head">
          <h3>🏆 Top Leave Takers</h3>
          <select class="year-select" id="topLeaveYear" onchange="loadTopLeave()">
            <?php for ($y = $currentYear; $y >= $currentYear - 4; $y--): ?>
              <option value="<?= $y ?>"><?= $y ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="panel-body no-pad">
          <table class="rep-table" id="topLeaveTable">
            <thead>
              <tr>
                <th style="width:40px">#</th>
                <th>Employee</th>
                <th>Department</th>
                <th style="text-align:right">Requests</th>
                <th style="text-align:right">Days Taken</th>
              </tr>
            </thead>
            <tbody><tr><td colspan="5" class="empty-rep">Loading…</td></tr></tbody>
          </table>
        </div>
      </div>

      <!-- ══ ROW 5: Contracts Expiring ══ -->
      <div class="panel">
        <div class="panel-head">
          <h3>⚠️ Contracts Expiring Soon</h3>
          <div class="tabs" id="expiryTabs">
            <button class="tab-btn active" onclick="loadExpiringContracts(30,this)">30 days</button>
            <button class="tab-btn" onclick="loadExpiringContracts(60,this)">60 days</button>
            <button class="tab-btn" onclick="loadExpiringContracts(90,this)">90 days</button>
          </div>
        </div>
        <div class="panel-body no-pad">
          <table class="rep-table" id="expiryTable">
            <thead>
              <tr>
                <th>Employee</th>
                <th>Department</th>
                <th>Position</th>
                <th>Type</th>
                <th style="text-align:right">End Date</th>
                <th style="text-align:right">Days Left</th>
              </tr>
            </thead>
            <tbody><tr><td colspan="6" class="empty-rep">Loading…</td></tr></tbody>
          </table>
        </div>
      </div>

      <!-- ══ ROW 6: Headcount Trend ══ -->
      <div class="panel">
        <div class="panel-head">
          <h3>📈 Headcount Trend — Hired per Year</h3>
        </div>
        <div class="panel-body">
          <div class="sparkline-wrap" id="headcountChart">
            <p class="empty-rep" style="width:100%">Loading…</p>
          </div>
        </div>
      </div>

    </div><!-- /reports-wrap -->
  </div><!-- /content -->
</div><!-- /main -->

<script>
var API    = './reports.php';
var COLORS = ['#2563eb','#22c55e','#f59e0b','#ef4444','#0ea5e9','#a855f7','#14b8a6','#f97316','#ec4899','#84cc16'];

/* ── Init ──────────────────────────────────────────────────── */
(async function init() {
  loadOverview();
  loadDeptChart();
  loadTypeDonut();
  loadLeaveMonthly();
  loadLeaveByType();
  loadTopLeave();
  loadBalanceSummary();
  loadExpiringContracts(30, document.querySelector('#expiryTabs .tab-btn'));
  loadHeadcount();
})();

async function fetchJSON(url) {
  var res = await fetch(API + url);
  return res.json();
}
function fmt(d) {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'numeric'});
}

/* ── Overview ──────────────────────────────────────────────── */
async function loadOverview() {
  try {
    var json = await fetchJSON('?action=overview');
    if (!json.success) return;
    var e = json.employees, l = json.leave, c = json.contracts;
    document.getElementById('ovTotalEmp').textContent    = e.total || 0;
    document.getElementById('ovActive').textContent      = e.active || 0;
    document.getElementById('ovDepts').textContent       = json.departments || 0;
    document.getElementById('ovLeaveReq').textContent    = l.total || 0;
    document.getElementById('ovExpiring').textContent    = c.expiring_soon || 0;
    document.getElementById('ovContracts').textContent   = c.active || 0;
    document.getElementById('ovEmpSub').textContent      = (e.male||0) + ' male · ' + (e.female||0) + ' female';
    document.getElementById('ovActiveSub').textContent   = (e.permanent||0) + ' perm · ' + (e.contractual||0) + ' contract';
    document.getElementById('ovLeaveSub').textContent    = (l.approved||0) + ' approved · ' + (l.pending||0) + ' pending';
    document.getElementById('ovContractSub').textContent = (c.expired||0) + ' expired';
  } catch(err) { console.error('overview', err); }
}

/* ── Dept bar chart ────────────────────────────────────────── */
async function loadDeptChart() {
  try {
    var json = await fetchJSON('?action=by_department');
    var el   = document.getElementById('deptChart');
    if (!json.success || !json.data.length) { el.innerHTML = '<p class="empty-rep">No department data.</p>'; return; }
    var max = Math.max.apply(null, json.data.map(function(r){ return parseInt(r.total); }));
    el.innerHTML = json.data.map(function(r, i) {
      var pct = max > 0 ? Math.round(r.total / max * 100) : 0;
      return '<div class="bar-row">' +
        '<div class="bar-label" title="' + r.department + '">' + r.department + '</div>' +
        '<div class="bar-track"><div class="bar-fill" style="width:' + pct + '%;background:' + COLORS[i % COLORS.length] + '"></div></div>' +
        '<div class="bar-val">' + r.total + '</div>' +
      '</div>';
    }).join('');
  } catch(err) { console.error('deptChart', err); }
}

/* ── Donut chart ───────────────────────────────────────────── */
async function loadTypeDonut() {
  try {
    var json = await fetchJSON('?action=by_type');
    var el   = document.getElementById('typeDonut');
    if (!json.success || !json.data.length) { el.innerHTML = '<p class="empty-rep">No data.</p>'; return; }
    var total = json.data.reduce(function(s,r){ return s + parseInt(r.value); }, 0);
    var R = 50, cx = 60, cy = 60, C = 2 * Math.PI * R, offset = 0;
    var slices = json.data.map(function(r, i) {
      var pct  = total > 0 ? parseInt(r.value) / total : 0;
      var dash = pct * C, gap = C - dash;
      var s = '<circle cx="' + cx + '" cy="' + cy + '" r="' + R + '" fill="none"' +
              ' stroke="' + COLORS[i % COLORS.length] + '" stroke-width="20"' +
              ' stroke-dasharray="' + dash.toFixed(2) + ' ' + gap.toFixed(2) + '"' +
              ' stroke-dashoffset="' + (-(offset * C)).toFixed(2) + '"' +
              ' transform="rotate(-90 ' + cx + ' ' + cy + ')"/>';
      offset += pct;
      return s;
    }).join('');
    var svg = '<svg width="120" height="120" viewBox="0 0 120 120" style="flex-shrink:0">' +
      '<circle cx="60" cy="60" r="50" fill="none" stroke="var(--gray-100)" stroke-width="20"/>' +
      slices +
      '<text x="60" y="57" text-anchor="middle" font-size="16" font-weight="800" fill="var(--gray-800)" font-family="Nunito,sans-serif">' + total + '</text>' +
      '<text x="60" y="70" text-anchor="middle" font-size="9" fill="var(--gray-400)" font-family="Nunito,sans-serif">TOTAL</text>' +
    '</svg>';
    var legend = '<div class="donut-legend">' + json.data.map(function(r, i) {
      var pct = total > 0 ? Math.round(parseInt(r.value) / total * 100) : 0;
      return '<div class="donut-item">' +
        '<div class="donut-dot" style="background:' + COLORS[i % COLORS.length] + '"></div>' +
        '<span>' + r.label + '</span>' +
        '<span class="donut-pct">' + r.value + ' <span style="font-size:0.68rem;color:var(--gray-400);font-weight:600">(' + pct + '%)</span></span>' +
      '</div>';
    }).join('') + '</div>';
    el.innerHTML = svg + legend;
  } catch(err) { console.error('typeDonut', err); }
}

/* ── Monthly leave sparkline ───────────────────────────────── */
async function loadLeaveMonthly() {
  var year = document.getElementById('leaveMonthYear').value;
  try {
    var json = await fetchJSON('?action=leave_monthly&year=' + year);
    var wrap = document.getElementById('monthlyChart');
    if (!json.success || !json.data.length) {
      wrap.innerHTML = '<p class="empty-rep" style="width:100%">No leave data for ' + year + '.</p>';
      return;
    }
    var months  = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var dataMap = {};
    json.data.forEach(function(r){ dataMap[parseInt(r.month_num)] = r; });
    var maxDays = 1;
    json.data.forEach(function(r){ if (parseInt(r.days||0) > maxDays) maxDays = parseInt(r.days); });
    wrap.innerHTML = months.map(function(m, i) {
      var r    = dataMap[i + 1];
      var days = r ? (parseInt(r.days) || 0) : 0;
      var pct  = maxDays > 0 ? Math.round(days / maxDays * 100) : 0;
      return '<div class="spark-col">' +
        '<div class="spark-val" style="color:var(--primary)">' + (days || '') + '</div>' +
        '<div class="spark-bar" style="height:' + Math.max(pct, days > 0 ? 5 : 1) + '%;background:' + (days > 0 ? 'var(--primary)' : 'var(--gray-200)') + '"></div>' +
        '<span class="spark-lbl">' + m + '</span>' +
      '</div>';
    }).join('');
  } catch(err) { console.error('leaveMonthly', err); }
}

/* ── Leave by type table ───────────────────────────────────── */
async function loadLeaveByType() {
  var year  = document.getElementById('leaveTypeYear').value;
  var tbody = document.querySelector('#leaveTypeTable tbody');
  try {
    var json = await fetchJSON('?action=leave_by_type&year=' + year);
    if (!json.success || !json.data.length) {
      tbody.innerHTML = '<tr><td colspan="4" class="empty-rep">No leave data for ' + year + '.</td></tr>';
      return;
    }
    tbody.innerHTML = json.data.map(function(r) {
      return '<tr>' +
        '<td style="font-weight:600">' + r.leave_type + '</td>' +
        '<td class="num">' + (r.requests || 0) + '</td>' +
        '<td class="num">' + (parseFloat(r.total_days)||0).toFixed(1) + '</td>' +
        '<td class="num"><span class="badge badge-green">' + (r.approved || 0) + '</span></td>' +
      '</tr>';
    }).join('');
  } catch(err) { console.error('leaveByType', err); }
}

/* ── Balance summary table ─────────────────────────────────── */
async function loadBalanceSummary() {
  var year  = document.getElementById('balYear').value;
  var tbody = document.querySelector('#balSummaryTable tbody');
  try {
    var json = await fetchJSON('?action=balance_summary&year=' + year);
    if (!json.success || !json.data.length) {
      tbody.innerHTML = '<tr><td colspan="4" class="empty-rep">No balance data for ' + year + '.</td></tr>';
      return;
    }
    tbody.innerHTML = json.data.map(function(r) {
      return '<tr>' +
        '<td style="font-weight:600">' + r.leave_type + '</td>' +
        '<td class="num">' + (parseFloat(r.total_credits)||0).toFixed(1) + '</td>' +
        '<td class="num" style="color:#ef4444;font-weight:700">' + (parseFloat(r.used_days)||0).toFixed(1) + '</td>' +
        '<td class="num" style="color:#22c55e;font-weight:700">' + (parseFloat(r.remaining_days)||0).toFixed(1) + '</td>' +
      '</tr>';
    }).join('');
  } catch(err) { console.error('balanceSummary', err); }
}

/* ── Top leave takers ──────────────────────────────────────── */
async function loadTopLeave() {
  var year  = document.getElementById('topLeaveYear').value;
  var tbody = document.querySelector('#topLeaveTable tbody');
  try {
    var json = await fetchJSON('?action=top_leave&year=' + year);
    if (!json.success || !json.data.length) {
      tbody.innerHTML = '<tr><td colspan="5" class="empty-rep">No approved leave data for ' + year + '.</td></tr>';
      return;
    }
    tbody.innerHTML = json.data.map(function(r, i) {
      var medal = i === 0 ? '🥇' : i === 1 ? '🥈' : i === 2 ? '🥉' : '<span class="rank-num">' + (i+1) + '</span>';
      return '<tr>' +
        '<td style="text-align:center">' + medal + '</td>' +
        '<td><div style="font-weight:700;color:var(--gray-800)">' + r.employee + '</div>' +
            '<div style="font-size:0.70rem;color:var(--gray-400)">' + (r.employee_code||'') + '</div></td>' +
        '<td><span class="badge badge-blue">' + r.department + '</span></td>' +
        '<td class="num">' + r.requests + '</td>' +
        '<td class="num"><strong style="color:var(--primary);font-size:0.93rem">' + (parseFloat(r.total_days)||0).toFixed(1) + '</strong></td>' +
      '</tr>';
    }).join('');
  } catch(err) { console.error('topLeave', err); }
}

/* ── Contracts expiring ────────────────────────────────────── */
async function loadExpiringContracts(days, btn) {
  document.querySelectorAll('#expiryTabs .tab-btn').forEach(function(b){ b.classList.remove('active'); });
  if (btn) btn.classList.add('active');
  var tbody = document.querySelector('#expiryTable tbody');
  try {
    var json = await fetchJSON('?action=contracts_expiring&days=' + days);
    if (!json.success || !json.data.length) {
      tbody.innerHTML = '<tr><td colspan="6" class="empty-rep">✅ No contracts expiring within ' + days + ' days.</td></tr>';
      return;
    }
    tbody.innerHTML = json.data.map(function(r) {
      var d   = parseInt(r.days_remaining);
      var cls = d <= 7 ? 'dp-danger' : d <= 30 ? 'dp-warn' : 'dp-safe';
      return '<tr>' +
        '<td><div style="font-weight:700;color:var(--gray-800)">' + r.employee + '</div>' +
            '<div style="font-size:0.70rem;color:var(--gray-400)">' + (r.employee_code||'') + '</div></td>' +
        '<td>' + r.department + '</td>' +
        '<td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:0.80rem;color:var(--gray-600)" title="' + r.position_title + '">' + r.position_title + '</td>' +
        '<td><span class="badge badge-gray">' + r.contract_type + '</span></td>' +
        '<td class="num" style="font-size:0.80rem">' + fmt(r.end_date) + '</td>' +
        '<td class="num"><span class="days-pill ' + cls + '">' + d + 'd</span></td>' +
      '</tr>';
    }).join('');
  } catch(err) { console.error('expiringContracts', err); }
}

/* ── Headcount sparkline ───────────────────────────────────── */
async function loadHeadcount() {
  var wrap = document.getElementById('headcountChart');
  try {
    var json = await fetchJSON('?action=headcount');
    if (!json.success || !json.data.length) {
      wrap.innerHTML = '<p class="empty-rep" style="width:100%">No headcount data.</p>';
      return;
    }
    var maxH = Math.max.apply(null, json.data.map(function(r){ return parseInt(r.hired); }));
    wrap.innerHTML = json.data.map(function(r) {
      var pct = maxH > 0 ? Math.round(parseInt(r.hired) / maxH * 100) : 0;
      return '<div class="spark-col">' +
        '<div class="spark-val" style="color:var(--accent)">' + r.hired + '</div>' +
        '<div class="spark-bar" style="height:' + Math.max(pct,4) + '%;background:var(--accent)"></div>' +
        '<span class="spark-lbl">' + r.yr + '</span>' +
      '</div>';
    }).join('');
  } catch(err) { console.error('headcount', err); }
}



/* ── Sidebar toggle ────────────────────────────────────────── */
function showToast(msg, type) {
  var t=document.createElement('div');
  t.style.cssText='position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:9px;font-size:.84rem;font-weight:700;color:#fff;z-index:9999;animation:fadeIn .3s ease;box-shadow:0 4px 18px rgba(0,0,0,.18);';
  t.style.background=type==='success'?'#22c55e':'#ef4444';
  t.textContent=msg;
  document.body.appendChild(t);
  setTimeout(function(){t.style.opacity='0';t.style.transition='opacity .3s';setTimeout(function(){t.remove();},300);},3500);
}


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
  document.getElementById('overlayMob').classList.toggle('show');
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