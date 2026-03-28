<?php
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
    header('Location: ../Login/login.php');
    exit();
}
// Allow admin, hr_staff, and dept_head — redirect everyone else to the Employee Portal
if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'hr_staff', 'dept_head'])) {
    header('Location: ../Employee/Employee_portal.php');
    exit();
}
$adminName  = isset($_SESSION['user_name']) ? ucwords($_SESSION['user_name']) : 'Administrator';
$roleLabels = ['admin'=>'Super Admin','hr_staff'=>'HR Staff','dept_head'=>'Department Head'];
$roleLabel  = $roleLabels[$_SESSION['user_role'] ?? 'admin'] ?? 'Administrator';
$initials   = strtoupper(implode('', array_map(fn($w)=>$w[0]??'', array_slice(explode(' ', $adminName), 0, 2))));

// ── DB connection ──────────────────────────────────────────────────────────
$possibleDb = [__DIR__.'/../config/db.php', __DIR__.'/config/db.php'];
foreach ($possibleDb as $p) { if (file_exists($p)) { require_once $p; break; } }

// ── Dashboard data ─────────────────────────────────────────────────────────
$totalEmp      = 0; $pendingLeave = 0; $approvedLeave = 0; $onLeave = 0;
$leaveRows     = []; $activityRows = []; $leaveByType = []; $expiringContracts = 0;
$srRequestRows = []; $pendingSRCount = 0;
$chartApproved = array_fill(1,12,0);
$chartDenied   = array_fill(1,12,0);
$chartPending  = array_fill(1,12,0);

if (isset($pdo)) {
    try {
        // Total active employees
        $totalEmp = (int)$pdo->query(
            "SELECT COUNT(*) FROM employees WHERE employment_status='Active' OR employment_status IS NULL"
        )->fetchColumn();

        // Pending leave requests count
        $pendingLeave = (int)$pdo->query(
            "SELECT COUNT(*) FROM leave_requests WHERE status='Pending'"
        )->fetchColumn();

        // Approved leaves this month (by date_from)
        $approvedLeave = (int)$pdo->query(
            "SELECT COUNT(*) FROM leave_requests
             WHERE status='Approved'
               AND MONTH(date_from)=MONTH(NOW()) AND YEAR(date_from)=YEAR(NOW())"
        )->fetchColumn();

        // Employees currently on leave today
        $onLeave = (int)$pdo->query(
            "SELECT COUNT(*) FROM leave_requests
             WHERE status='Approved' AND date_from<=CURDATE() AND date_to>=CURDATE()"
        )->fetchColumn();

        // Pending leave requests table rows (latest 10)
        $leaveRows = $pdo->query("
            SELECT lr.request_id, lr.status, lr.date_from, lr.date_to,
                   lr.days_requested, lr.filed_at,
                   lt.name AS leave_type_name,
                   e.first_name, e.last_name,
                   e.position_title,
                   COALESCE(d.name,'') AS department
            FROM leave_requests lr
            JOIN employees   e  ON lr.employee_id   = e.employee_id
            JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id
            LEFT JOIN departments d ON d.department_id = e.department_id
            WHERE lr.status = 'Pending'
            ORDER BY lr.filed_at DESC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Leave decisions — query leave_requests directly (no activity_logs dependency)
        $activityRows = $pdo->query("
            SELECT
                lr.request_id, lr.status, lr.date_from, lr.date_to,
                lr.days_requested, lr.reviewed_at,
                lt.name AS leave_type,
                CONCAT(emp.first_name,' ',emp.last_name) AS employee_name,
                COALESCE(d.name,'')  AS department,
                COALESCE(CONCAT(adm.first_name,' ',adm.last_name), admUa.email, 'Admin') AS actor_name
            FROM leave_requests lr
            JOIN employees      emp   ON emp.employee_id   = lr.employee_id
            JOIN leave_types    lt    ON lt.leave_type_id  = lr.leave_type_id
            LEFT JOIN departments d   ON d.department_id   = emp.department_id
            LEFT JOIN user_accounts admUa ON admUa.user_id = lr.reviewed_by
            LEFT JOIN employees     adm   ON adm.user_id   = lr.reviewed_by
            WHERE lr.status IN ('Approved','Denied')
              AND lr.reviewed_at IS NOT NULL
            ORDER BY lr.reviewed_at DESC
            LIMIT 50
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Monthly chart data
        $chartRaw = $pdo->query("
            SELECT MONTH(date_from) AS mo, status, COUNT(*) AS cnt
            FROM leave_requests
            WHERE YEAR(date_from)=YEAR(NOW())
            GROUP BY mo, status ORDER BY mo
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($chartRaw as $row) {
            $mo = (int)$row['mo'];
            if ($row['status']==='Approved') $chartApproved[$mo] += (int)$row['cnt'];
            elseif ($row['status']==='Denied') $chartDenied[$mo]  += (int)$row['cnt'];
            elseif ($row['status']==='Pending') $chartPending[$mo] += (int)$row['cnt'];
        }

        // Leave by type for donut chart + bars
        $leaveByType = $pdo->query("
            SELECT lt.name, lt.code,
                   COUNT(lr.request_id) AS total
            FROM leave_types lt
            LEFT JOIN leave_requests lr
                ON lr.leave_type_id = lt.leave_type_id
                AND YEAR(lr.date_from) = YEAR(NOW())
            WHERE lt.is_active = 1
            GROUP BY lt.leave_type_id, lt.name, lt.code
            ORDER BY total DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Expiring contracts count
        $expiringContracts = 0;
        try {
            $expiringContracts = (int)$pdo->query("
                SELECT COUNT(*) FROM contracts
                WHERE status='Active' AND end_date IS NOT NULL
                AND DATEDIFF(end_date, CURDATE()) BETWEEN 0 AND 30
            ")->fetchColumn();
        } catch (Exception $ex) {}

        // Pending service record requests
        $pendingSRCount = (int)$pdo->query(
            "SELECT COUNT(*) FROM sr_requests WHERE status='Pending'"
        )->fetchColumn();
        
        $srRequestRows = $pdo->query("
            SELECT sr.req_id, sr.purpose, sr.requested_at,
                   e.first_name, e.last_name,
                   e.position_title,
                   COALESCE(d.name,'') AS department
            FROM sr_requests sr
            JOIN employees e ON sr.employee_id = e.employee_id
            LEFT JOIN departments d ON d.department_id = e.department_id
            WHERE sr.status = 'Pending'
            ORDER BY sr.requested_at DESC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        error_log('[Dashboard] '.$e->getMessage());
    }
}

// Helper: initials from name
function dashIni($name) {
    $words = array_filter(explode(' ', $name));
    return strtoupper(implode('', array_map(fn($w)=>$w[0]??'', array_slice($words,0,2))));
}
// Helper: avatar color palette
$avColors = ['#2563eb','#22c55e','#f59e0b','#ef4444','#a855f7','#06b6d4','#ec4899','#f97316'];
function dashColor($name, $colors) {
    $sum = array_sum(array_map('ord', str_split($name ?: '?')));
    return $colors[$sum % count($colors)];
}
// Helper: leave type badge colors
function leaveTypeBadge($type) {
    $map = [
        'vacation'=>['#dbeafe','#1e40af'], 'sick'=>['#fef3c7','#92400e'],
        'emergency'=>['#dcfce7','#166534'], 'maternity'=>['#fce7f3','#9d174d'],
        'paternity'=>['#dcfce7','#166534'], 'special'=>['#f3e8ff','#6b21a8'],
    ];
    $key = strtolower($type);
    foreach ($map as $k=>$v) { if (strpos($key,$k)!==false) return $v; }
    return ['#dbeafe','#1e40af'];
}
// Helper: activity icon+color by action/module
function activityMeta($action, $module) {
    $a = strtolower($action.' '.$module);
    if (strpos($a,'approved')!==false)                                          return ['green', '✅', 'Leave Approved'];
    if (strpos($a,'denied')!==false||strpos($a,'rejected')!==false)             return ['red',   '✗',  'Leave Denied'];
    if (strpos($a,'leave')!==false&&(strpos($a,'filed')!==false||strpos($a,'request')!==false)) return ['orange','📋','Leave Request Filed'];
    if (strpos($a,'leave')!==false)                                             return ['orange','📋', 'Leave Action'];
    if (strpos($a,'employee')!==false&&strpos($a,'add')!==false)                return ['blue',  '👤', 'New Employee Added'];
    if (strpos($a,'employee')!==false)                                          return ['blue',  '👤', 'Employee Updated'];
    if (strpos($a,'document')!==false||strpos($a,'upload')!==false)             return ['blue',  '📄', 'Document Uploaded'];
    if (strpos($a,'account')!==false||strpos($a,'user')!==false)               return ['green', '🔐', 'Account Action'];
    if (strpos($a,'login')!==false)                                             return ['blue',  '🔑', 'User Login'];
    if (strpos($a,'logout')!==false)                                            return ['blue',  '🔑', 'User Logout'];
    if (strpos($a,'setting')!==false)                                           return ['orange','⚙️', 'Settings Updated'];
    if (strpos($a,'announce')!==false)                                          return ['blue',  '📢', 'Announcement Posted'];
    if (strpos($a,'department')!==false)                                        return ['blue',  '🏢', 'Department Updated'];
    if (strpos($a,'contract')!==false)                                          return ['blue',  '📝', 'Contract Action'];
    if (strpos($a,'password')!==false||strpos($a,'reset')!==false)             return ['orange','🔑', 'Password Reset'];
    return ['blue', '📌', ucwords(str_replace(['-','_'], ' ', $action))];
}
function timeAgo($dt) {
    if (!$dt) return '';
    $diff = time() - strtotime($dt);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return (int)($diff/60).'m ago';
    if ($diff < 86400) return (int)($diff/3600).'h ago';
    if ($diff < 172800) return 'Yesterday';
    return date('M j', strtotime($dt));
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Dashboard | LGU Enrile HRMS</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
  <style>
    :root {
      --primary:       #2563eb;
      --primary-dark:  #1e40af;
      --primary-light: #3b82f6;
      --accent:        #22c55e;
      --accent-dark:   #15803d;
      --accent-light:  #86efac;
      --secondary:     #0f172a;
      --sidebar-w:     265px;
      --topbar-h:      68px;
      --gray-50:       #f8fafc;
      --gray-100:      #f1f5f9;
      --gray-200:      #e2e8f0;
      --gray-400:      #94a3b8;
      --gray-600:      #475569;
      --gray-800:      #1e293b;
      --white:         #ffffff;
      --shadow-sm:     0 1px 4px rgba(0,0,0,0.07);
      --shadow-md:     0 4px 18px rgba(0,0,0,0.11);
      --shadow-lg:     0 8px 32px rgba(0,0,0,0.16);
      --radius:        14px;
      --radius-sm:     9px;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Nunito', sans-serif;
      background: var(--gray-100);
      color: var(--secondary);
      display: flex;
      min-height: 100vh;
    }

    /* ══════════════════════════════════
       SIDEBAR — gradient matching login
    ══════════════════════════════════ */
    .sidebar {
      width: var(--sidebar-w);
      background: linear-gradient(180deg, #2563eb 0%, #1e40af 55%, #22c55e 100%);
      display: flex;
      flex-direction: column;
      position: fixed;
      top: 0; left: 0;
      height: 100vh;
      z-index: 200;
      transition: transform 0.3s ease;
      box-shadow: 4px 0 28px rgba(0,0,0,0.20);
    }

    .sidebar-brand {
      padding: 22px 20px 18px;
      display: flex;
      align-items: center;
      gap: 12px;
      border-bottom: 1px solid rgba(255,255,255,0.15);
    }

    .brand-logo {
      width: 44px; height: 44px;
      border-radius: 50%;
      object-fit: cover;
      border: 2.5px solid rgba(255,255,255,0.70);
      box-shadow: 0 2px 8px rgba(0,0,0,0.20);
    }

    /* fallback circle if logo missing */
    .brand-logo-fallback {
      width: 44px; height: 44px;
      border-radius: 50%;
      background: rgba(255,255,255,0.20);
      border: 2.5px solid rgba(255,255,255,0.70);
      display: flex; align-items: center; justify-content: center;
      font-size: 1.2rem; flex-shrink: 0;
    }

    .brand-text h2 {
      font-family: 'Playfair Display', serif;
      font-size: 1rem; color: #fff; line-height: 1.2;
    }
    .brand-text span {
      font-size: 0.68rem; color: rgba(255,255,255,0.60);
      font-weight: 700; text-transform: uppercase; letter-spacing: 0.10em;
    }

    .sidebar-nav {
      flex: 1; padding: 14px 12px;
      overflow-y: auto; scrollbar-width: thin;
      scrollbar-color: rgba(255,255,255,0.20) transparent;
    }

    .nav-label {
      font-size: 0.63rem; font-weight: 800;
      color: rgba(255,255,255,0.38);
      text-transform: uppercase; letter-spacing: 0.16em;
      padding: 10px 10px 5px; margin-top: 6px;
    }

    .nav-item {
      display: flex; align-items: center; gap: 11px;
      padding: 9px 12px;
      border-radius: var(--radius-sm);
      color: rgba(255,255,255,0.75);
      text-decoration: none;
      font-size: 0.875rem; font-weight: 600;
      transition: all 0.2s; cursor: pointer;
      margin-bottom: 2px; position: relative;
    }
    .nav-item:hover { background: rgba(255,255,255,0.13); color: #fff; }
    .nav-item.active {
      background: rgba(255,255,255,0.22);
      color: #fff;
      box-shadow: 0 2px 10px rgba(0,0,0,0.15);
    }
    .nav-item.active::before {
      content: '';
      position: absolute; left: 0; top: 20%; bottom: 20%;
      width: 3px; border-radius: 2px;
      background: #fff;
    }

    .nav-icon {
      width: 30px; height: 30px; border-radius: 8px;
      display: flex; align-items: center; justify-content: center;
      font-size: 0.95rem;
      background: rgba(255,255,255,0.10);
      flex-shrink: 0;
    }
    .nav-item.active .nav-icon { background: rgba(255,255,255,0.25); }

    .nav-badge {
      margin-left: auto; background: #ef4444; color: white;
      font-size: 0.60rem; font-weight: 800;
      padding: 2px 6px; border-radius: 20px;
    }

    .sidebar-footer {
      padding: 14px 12px;
      border-top: 1px solid rgba(255,255,255,0.13);
    }
    .sidebar-user {
      display: flex; align-items: center; gap: 10px;
      padding: 10px 12px; border-radius: var(--radius-sm);
      background: rgba(255,255,255,0.10);
    }
    .user-avatar {
      width: 36px; height: 36px; border-radius: 50%;
      background: rgba(255,255,255,0.25);
      border: 2px solid rgba(255,255,255,0.50);
      display: flex; align-items: center; justify-content: center;
      font-weight: 800; color: #fff; font-size: 0.80rem; flex-shrink: 0;
    }
    .user-info { flex: 1; min-width: 0; }
    .user-info strong { display: block; font-size: 0.82rem; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .user-info span { font-size: 0.68rem; color: rgba(255,255,255,0.52); }
    .sb-switch-btn {
      display: flex; align-items: center; gap: 9px;
      padding: 9px 12px; border-radius: var(--radius-sm);
      background: rgba(234,179,8,.12); border: 1.5px solid rgba(234,179,8,.40);
      color: #fef08a; font-size: .845rem; font-weight: 700;
      text-decoration: none; transition: all .2s; margin-bottom: 6px;
    }
    .sb-switch-btn:hover { background: rgba(234,179,8,.26); color: #fff; }
    .sb-logout-btn {
      display: flex; align-items: center; gap: 9px;
      padding: 9px 12px; border-radius: var(--radius-sm);
      background: rgba(239,68,68,.12); border: 1.5px solid rgba(239,68,68,.28);
      color: #fca5a5; font-size: .845rem; font-weight: 700;
      text-decoration: none; transition: all .2s;
    }
    .sb-logout-btn:hover { background: rgba(239,68,68,.26); color: #fff; }

    /* ══════════════════════════════════
       MAIN
    ══════════════════════════════════ */
    .main { margin-left: var(--sidebar-w); flex: 1; display: flex; flex-direction: column; min-height: 100vh; }

    /* TOPBAR */
    .topbar {
      height: var(--topbar-h);
      background: linear-gradient(135deg, #2563eb 0%, #1e40af 50%, #22c55e 100%);
      display: flex; align-items: center;
      padding: 0 28px; gap: 16px;
      position: sticky; top: 0; z-index: 100;
      box-shadow: 0 2px 16px rgba(37,99,235,0.30);
    }

    .topbar-title { flex: 1; }
    .topbar-title h1 {
      font-size: 1.20rem; font-weight: 800; color: #fff;
      font-family: 'Playfair Display', serif;
    }
    .topbar-title p { font-size: 0.76rem; color: rgba(255,255,255,0.70); margin-top: 1px; }

    .topbar-actions { display: flex; align-items: center; gap: 8px; }

    .topbar-date {
      font-size: 0.76rem; color: rgba(255,255,255,0.80);
      font-weight: 700; padding: 6px 14px;
      background: rgba(255,255,255,0.15);
      border-radius: 20px; border: 1px solid rgba(255,255,255,0.20);
    }

    .topbar-btn {
      position: relative; width: 38px; height: 38px; border-radius: 50%;
      border: 1px solid rgba(255,255,255,0.25);
      background: rgba(255,255,255,0.15);
      display: flex; align-items: center; justify-content: center;
      cursor: pointer; font-size: 1rem; transition: all 0.2s; color: #fff;
    }
    .topbar-btn:hover { background: rgba(255,255,255,0.28); }
    .topbar-btn .badge {
      position: absolute; top: -2px; right: -2px;
      width: 16px; height: 16px; background: #ef4444;
      border-radius: 50%; font-size: 0.58rem; color: white;
      font-weight: 800; display: flex; align-items: center; justify-content: center;
      border: 2px solid rgba(255,255,255,0.3);
    }

    /* CONTENT */
    .content { padding: 32px 36px; flex: 1; }

    /* ══════════════════════════════════
       SUMMARY CARDS
    ══════════════════════════════════ */
    .cards-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 20px;
      margin-bottom: 28px;
    }

    .card {
      background: var(--white);
      border-radius: var(--radius);
      padding: 24px;
      box-shadow: 0 2px 8px rgba(30,41,59,0.08);
      border: 1px solid var(--gray-200);
      position: relative; overflow: hidden;
      animation: cardIn 0.5s ease both;
      transition: transform 0.28s cubic-bezier(0.4,0,0.2,1), box-shadow 0.28s cubic-bezier(0.4,0,0.2,1);
      cursor: default;
    }
    .card:hover { transform: translateY(-6px); box-shadow: 0 12px 24px rgba(37,99,235,0.12); }
    .card:nth-child(1) { animation-delay: 0.05s; }
    .card:nth-child(2) { animation-delay: 0.10s; }
    .card:nth-child(3) { animation-delay: 0.15s; }
    .card:nth-child(4) { animation-delay: 0.20s; }

    /* gradient top stripe */
    .card::before {
      content: ''; position: absolute; top: 0; left: 0; right: 0;
      height: 4px; border-radius: var(--radius) var(--radius) 0 0;
    }
    .card.blue::before   { background: linear-gradient(90deg,#2563eb,#60a5fa); }
    .card.green::before  { background: linear-gradient(90deg,#22c55e,#86efac); }
    .card.orange::before { background: linear-gradient(90deg,#f59e0b,#fcd34d); }
    .card.red::before    { background: linear-gradient(90deg,#ef4444,#fca5a5); }

    /* subtle bg glow */
    .card::after {
      content: ''; position: absolute;
      right: -20px; bottom: -20px;
      width: 90px; height: 90px;
      border-radius: 50%; opacity: 0.06;
    }
    .card.blue::after   { background: #2563eb; }
    .card.green::after  { background: #22c55e; }
    .card.orange::after { background: #f59e0b; }
    .card.red::after    { background: #ef4444; }

    .card-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 18px; }

    .card-icon {
      width: 52px; height: 52px; border-radius: 14px;
      display: flex; align-items: center; justify-content: center; font-size: 1.6rem;
      box-shadow: 0 2px 6px rgba(0,0,0,0.08);
    }
    .card.blue   .card-icon { background: #dbeafe; }
    .card.green  .card-icon { background: #dcfce7; }
    .card.orange .card-icon { background: #fef3c7; }
    .card.red    .card-icon { background: #fee2e2; }

    .card-trend {
      font-size: 0.70rem; font-weight: 800;
      padding: 3px 9px; border-radius: 20px;
    }
    .card-trend.up   { background: #dcfce7; color: #166534; }
    .card-trend.warn { background: #fef3c7; color: #92400e; }
    .card-trend.down { background: #fee2e2; color: #991b1b; }

    .card-value { font-size: 2.4rem; font-weight: 900; color: var(--gray-800); line-height: 1; margin-bottom: 6px; letter-spacing: -0.5px; }
    .card-label { font-size: 0.81rem; color: var(--gray-500); font-weight: 600; letter-spacing: 0.02em; }

    /* ══════════════════════════════════
       GRID ROWS
    ══════════════════════════════════ */
    .row-2 { display: grid; grid-template-columns: 1fr 340px; gap: 20px; margin-bottom: 26px; }
    .row-3 { display: grid; grid-template-columns: 1fr 1fr 340px; gap: 20px; margin-bottom: 26px; }
    .row-4 { display: grid; grid-template-columns: 1fr 345px; gap: 20px; }

    /* ══════════════════════════════════
       PANEL (card wrapper)
    ══════════════════════════════════ */
    .panel {
      background: var(--white);
      border-radius: var(--radius);
      border: 1px solid var(--gray-200);
      box-shadow: 0 2px 8px rgba(30,41,59,0.08);
      overflow: hidden;
      animation: cardIn 0.5s 0.22s ease both;
      transition: box-shadow 0.3s ease, border-color 0.3s ease;
    }
    .panel:hover { box-shadow: 0 8px 16px rgba(30,41,59,0.12); }

    .panel-header {
      display: flex; align-items: center; justify-content: space-between;
      padding: 22px 24px 18px;
      border-bottom: 1.5px solid var(--gray-100);
      background: linear-gradient(135deg, rgba(37,99,235,0.02), rgba(34,197,94,0.02));
    }
    .panel-title { font-size: 0.98rem; font-weight: 900; color: var(--gray-800); letter-spacing: -0.3px; }
    .panel-sub   { font-size: 0.75rem; color: var(--gray-500); margin-top: 3px; font-weight: 500; }
    .panel-action {
      font-size: 0.78rem; color: var(--primary); font-weight: 700;
      text-decoration: none; cursor: pointer; transition: all 0.2s;
      padding: 4px 8px;
      border-radius: 6px;
    }
    .panel-action:hover { color: var(--primary-dark); background: rgba(37,99,235,0.08); }

    /* CHART */
    .chart-wrap { padding: 20px 24px; }

    /* ══════════════════════════════════
       QUICK ACTIONS
    ══════════════════════════════════ */
    .quick-actions {
      padding: 14px 14px;
      display: grid; grid-template-columns: 1fr 1fr; gap: 9px;
    }

    .qa-btn {
      display: flex; flex-direction: column; align-items: center; gap: 7px;
      padding: 14px 8px; border-radius: var(--radius-sm);
      border: 1.5px solid var(--gray-200);
      background: var(--gray-50);
      cursor: pointer; transition: all 0.2s;
      text-decoration: none; color: var(--gray-800);
    }
    .qa-btn:hover {
      border-color: var(--primary); background: #eff6ff;
      transform: translateY(-2px); box-shadow: var(--shadow-sm);
    }
    .qa-icon {
      width: 38px; height: 38px; border-radius: 10px;
      display: flex; align-items: center; justify-content: center; font-size: 1.1rem;
    }
    .qa-btn span { font-size: 0.72rem; font-weight: 700; text-align: center; line-height: 1.3; }

    /* ══════════════════════════════════
       LEAVE REQUESTS TABLE
    ══════════════════════════════════ */
    .table-wrap { overflow-x: auto; }

    table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }

    thead tr { background: var(--gray-50); }
    th {
      padding: 10px 15px; text-align: left;
      font-size: 0.69rem; font-weight: 800; color: var(--gray-400);
      text-transform: uppercase; letter-spacing: 0.07em;
      border-bottom: 1px solid var(--gray-200); white-space: nowrap;
    }
    td {
      padding: 12px 15px; border-bottom: 1px solid var(--gray-100);
      color: var(--gray-800); vertical-align: middle;
    }
    tr:last-child td { border-bottom: none; }
    tbody tr:hover { background: var(--gray-50); }

    .emp-cell { display: flex; align-items: center; gap: 10px; }
    .emp-avatar {
      width: 36px; height: 36px; border-radius: 50%;
      background: linear-gradient(135deg, var(--primary), #60a5fa);
      display: flex; align-items: center; justify-content: center;
      font-size: 0.78rem; font-weight: 800; color: white; flex-shrink: 0;
      box-shadow: 0 2px 6px rgba(37,99,235,0.2);
    }
    .emp-name { font-weight: 800; font-size: 0.86rem; }
    .emp-dept { font-size: 0.72rem; color: var(--gray-600); }

    .badge-status {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 6px 12px; border-radius: 22px;
      font-size: 0.72rem; font-weight: 800; white-space: nowrap;
      box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    }
    .badge-status.pending  { background: #fef3c7; color: #92400e; }
    .badge-status.approved { background: #dcfce7; color: #166534; }
    .badge-status.denied   { background: #fee2e2; color: #991b1b; }

    .badge-leave {
      padding: 5px 11px; border-radius: 8px;
      font-size: 0.72rem; font-weight: 800;
      background: #dbeafe; color: #1e40af;
      box-shadow: 0 1px 3px rgba(30,58,175,0.15);
    }

    .action-btns { display: flex; gap: 7px; }
    .act-btn {
      padding: 7px 12px; border-radius: 7px; border: none;
      font-size: 0.74rem; font-weight: 700; cursor: pointer; transition: all 0.25s cubic-bezier(0.4,0,0.2,1);
      box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    }
    .act-btn.approve { background: #dcfce7; color: #166534; }
    .act-btn.approve:hover { background: #22c55e; color: white; transform: translateY(-2px); box-shadow: 0 4px 10px rgba(34,197,94,0.2); }
    .act-btn.deny    { background: #fee2e2; color: #991b1b; }
    .act-btn.deny:hover { background: #ef4444; color: white; transform: translateY(-2px); box-shadow: 0 4px 10px rgba(239,68,68,0.2); }

    /* ══════════════════════════════════
       ACTIVITY LOG
    ══════════════════════════════════ */
    .activity-list { padding: 16px 24px; max-height: 420px; overflow-y: auto; }

    .activity-item {
      display: flex; gap: 14px;
      padding: 16px 0; border-bottom: 1.5px solid var(--gray-100);
      animation: cardIn 0.4s ease both;
      transition: background-color 0.2s;
    }
    .activity-item:hover { background: rgba(37,99,235,0.02); }
    .activity-item:last-child { border-bottom: none; }

    .av {
      width: 42px; height: 42px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 0.90rem; flex-shrink: 0; font-weight: 800; color: white;
      box-shadow: 0 2px 6px rgba(0,0,0,0.12);
    }
    .act-dot {
      width: 36px; height: 36px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 0.90rem; flex-shrink: 0; margin-top: 2px;
      box-shadow: 0 1px 4px rgba(0,0,0,0.08);
    }
    .act-dot.green  { background: #dcfce7; }
    .act-dot.blue   { background: #dbeafe; }
    .act-dot.orange { background: #fef3c7; }
    .act-dot.red    { background: #fee2e2; }

    .ai-body { flex: 1; }
    .ai-title { font-size: 0.85rem; font-weight: 800; color: var(--gray-800); display: block; line-height: 1.3; }
    .ai-sub { font-size: 0.75rem; color: var(--gray-600); margin-top: 3px; line-height: 1.4; }
    .ai-meta { font-size: 0.70rem; color: var(--gray-500); margin-top: 5px; font-weight: 500; }
    .act-text { flex: 1; }
    .act-text strong { font-size: 0.84rem; font-weight: 800; color: var(--gray-800); display: block; }
    .act-text p { font-size: 0.75rem; color: var(--gray-600); margin-top: 3px; }
    .act-time { font-size: 0.69rem; color: var(--gray-500); white-space: nowrap; margin-top: 4px; }

    /* ══════════════════════════════════
       ANNOUNCEMENTS
    ══════════════════════════════════ */
    .announce-list { padding: 16px 24px; }

    .announce-item {
      display: flex; gap: 14px;
      padding: 15px 0; border-bottom: 1.5px solid var(--gray-100);
      transition: background-color 0.2s;
    }
    .announce-item:hover { background: rgba(37,99,235,0.02); }
    .announce-item:last-child { border-bottom: none; }

    .announce-dot {
      width: 10px; height: 10px; border-radius: 50%;
      flex-shrink: 0; margin-top: 5px;
    }
    .announce-dot.blue   { background: var(--primary); }
    .announce-dot.green  { background: var(--accent); }
    .announce-dot.orange { background: #f59e0b; }
    .announce-dot.red    { background: #ef4444; }

    .announce-body { flex: 1; }
    .announce-title { font-size: 0.86rem; font-weight: 800; color: var(--gray-800); }
    .announce-text  { font-size: 0.76rem; color: var(--gray-600); margin-top: 4px; line-height: 1.5; }
    .announce-meta  { font-size: 0.70rem; color: var(--gray-500); margin-top: 6px; font-weight: 500; }

    .announce-tag {
      display: inline-block; padding: 4px 9px; border-radius: 6px;
      font-size: 0.66rem; font-weight: 800; margin-right: 5px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    }
    .announce-tag.info    { background: #dbeafe; color: #1e40af; }
    .announce-tag.success { background: #dcfce7; color: #166534; }
    .announce-tag.warning { background: #fef3c7; color: #92400e; }
    .announce-tag.urgent  { background: #fee2e2; color: #991b1b; }

    /* ADD ANNOUNCEMENT FORM */
    .announce-form {
      padding: 18px 24px 20px;
      border-top: 1.5px solid var(--gray-100);
      background: linear-gradient(135deg, rgba(37,99,235,0.02), rgba(34,197,94,0.02));
    }
    .announce-form textarea {
      width: 100%; padding: 12px 14px;
      border: 1.5px solid var(--gray-200); border-radius: var(--radius-sm);
      font-size: 0.82rem; font-family: 'Nunito', sans-serif;
      resize: none; height: 70px;
      transition: border-color 0.2s, box-shadow 0.2s;
      background: white;
    }
    .announce-form textarea:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
    .announce-form-actions { display: flex; gap: 10px; margin-top: 12px; align-items: center; }
    .announce-form select {
      flex: 1; padding: 9px 12px;
      border: 1.5px solid var(--gray-200); border-radius: var(--radius-sm);
      font-size: 0.78rem; font-family: 'Nunito', sans-serif;
      color: var(--gray-700); background: white;
      cursor: pointer; transition: border-color 0.2s, box-shadow 0.2s;
    }
    .announce-form select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
    .post-btn {
      padding: 10px 20px;
      background: linear-gradient(135deg, var(--primary), var(--primary-dark));
      color: white; border: none; border-radius: var(--radius-sm);
      font-size: 0.80rem; font-weight: 700; font-family: 'Nunito', sans-serif;
      cursor: pointer; transition: all 0.3s cubic-bezier(0.4,0,0.2,1); white-space: nowrap;
      box-shadow: 0 2px 8px rgba(37,99,235,0.25);
    }
    .post-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(37,99,235,0.35); }
    .post-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(37,99,235,0.35); }

    /* LEAVE TYPE MINI CHART */
    .mini-chart-wrap { padding: 20px 24px; }
    .leave-type-bars { display: flex; flex-direction: column; gap: 12px; }
    .lbar-row { display: flex; align-items: center; gap: 10px; }
    .lbar-label { font-size: 0.75rem; font-weight: 700; color: var(--gray-600); width: 80px; flex-shrink: 0; }
    .lbar-track { flex: 1; height: 8px; background: var(--gray-100); border-radius: 20px; overflow: hidden; }
    .lbar-fill { height: 100%; border-radius: 20px; transition: width 1s ease; }
    .lbar-count { font-size: 0.75rem; font-weight: 800; color: var(--gray-800); width: 24px; text-align: right; flex-shrink: 0; }

    /* ══════════════════════════════════
       ANIMATIONS
    ══════════════════════════════════ */
    @keyframes cardIn {
      from { opacity: 0; transform: translateY(16px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    /* MOBILE SIDEBAR */
    .menu-toggle {
      display: none; background: none; border: none;
      font-size: 1.4rem; cursor: pointer; color: rgba(255,255,255,0.85); padding: 4px;
    }
    .overlay {
      display: none; position: fixed; inset: 0;
      background: rgba(0,0,0,0.45); z-index: 150;
    }
    .overlay.show { display: block; }

    @media(max-width: 1280px) {
      .row-3 { grid-template-columns: 1fr 1fr; }
      .row-3 > :last-child { grid-column: 1 / -1; }
    }
    @media(max-width: 1100px) {
      .cards-grid { grid-template-columns: repeat(2,1fr); }
      .row-2 { grid-template-columns: 1fr; }
      .row-3 { grid-template-columns: 1fr; }
      .row-4 { grid-template-columns: 1fr; }
    }
    @media(max-width: 768px) {
      .sidebar { transform: translateX(-100%); }
      .sidebar.open { transform: translateX(0); }
      .main { margin-left: 0; }
      .menu-toggle { display: block; }
      .content { padding: 16px; }
      .topbar { padding: 0 16px; }
      .topbar-date { display: none; }
    }
    @media(max-width: 480px) {
      .cards-grid { grid-template-columns: 1fr; }
    }

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
      <img src="../images/Enrile.png" alt="LGU Logo" class="brand-logo"
           onerror="this.outerHTML='<div class=\'brand-logo-fallback\'>🏛️</div>'"/>
      <div class="brand-text">
        <h2>LGU Enrile</h2>
        <span>HR Management System</span>
      </div>
    </div>

    <nav class="sidebar-nav">
    <div class="nav-label">Main</div>
    <a class="nav-item active" href="dashboard.php"><div class="nav-icon">🏠</div>Dashboard</a>
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
    <a class="nav-item" href="archives.php"><div class="nav-icon">🗄️</div>Archives</a>
    <a class="nav-item" href="user_accounts.php"><div class="nav-icon">🔐</div>User Accounts</a>
    <a class="nav-item" href="settings.php"><div class="nav-icon">⚙️</div>Settings</a>
    </nav>
    <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="user-avatar"><?= htmlspecialchars($initials) ?></div>
      <div class="user-info">
        <strong><?= htmlspecialchars($adminName) ?></strong>
        <span><?= htmlspecialchars($roleLabel) ?></span>
      </div>
    </div>
  </div>
  </aside>

  <div class="overlay" id="overlay" onclick="toggleSidebar()"></div>

  <!-- ══ MAIN ══ -->
  <div class="main">

    <!-- TOPBAR -->
    <div class="topbar">
      <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
      <div class="topbar-title">
        <h1>Admin Dashboard</h1>
        <p>Welcome back, <?= htmlspecialchars($adminName) ?> 👋</p>
      </div>
      <div class="topbar-actions">
        <div class="topbar-date" id="currentDate"></div>
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
        <button class="topbar-btn" title="Messages" onclick="cwToggle()" style="position:relative;">💬<span id="cwBadge" style="position:absolute;top:-2px;right:-2px;width:16px;height:16px;background:#ef4444;border-radius:50%;font-size:.55rem;color:#fff;font-weight:800;display:none;align-items:center;justify-content:center;border:2px solid rgba(255,255,255,.3);"></span></button>
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

    <!-- CONTENT -->
    <div class="content">

      <!-- ROW 1 — SUMMARY CARDS -->
      <div class="cards-grid">
        <div class="card blue">
          <div class="card-header">
            <div class="card-icon">👥</div>
            <span class="card-trend up">↑ +3 this month</span>
          </div>
          <div class="card-value"><?= $totalEmp ?></div>
          <div class="card-label">Total Employees</div>
        </div>
        <div class="card orange">
          <div class="card-header">
            <div class="card-icon">⏳</div>
            <span class="card-trend warn">⚠ Action needed</span>
          </div>
          <div class="card-value"><?= $pendingLeave ?></div>
          <div class="card-label">Pending Leave Requests</div>
        </div>
        <div class="card green">
          <div class="card-header">
            <div class="card-icon">✅</div>
            <span class="card-trend up">This month</span>
          </div>
          <div class="card-value"><?= $approvedLeave ?></div>
          <div class="card-label">Approved Leaves</div>
        </div>
        <div class="card red">
          <div class="card-header">
            <div class="card-icon">📅</div>
            <span class="card-trend down">Today</span>
          </div>
          <div class="card-value"><?= $onLeave ?></div>
          <div class="card-label">Employees on Leave</div>
        </div>
      </div>

      <!-- ROW 2 — CHART + QUICK ACTIONS -->
      <div class="row-2">

        <!-- Leave Chart -->
        <div class="panel">
          <div class="panel-header">
            <div>
              <div class="panel-title">📈 Leave Requests Overview</div>
              <div class="panel-sub">Monthly breakdown — 2025</div>
            </div>
            <a class="panel-action" href="reports.php">View full report →</a>
          </div>
          <div class="chart-wrap">
            <div style="position:relative;height:260px;"><canvas id="leaveChart"></canvas></div>
          </div>
        </div>

        <!-- Pending Service Record Requests -->
        <div class="panel">
          <div class="panel-header">
            <div>
              <div class="panel-title">📋 Pending Service Record Requests</div>
              <div class="panel-sub"><?= $pendingSRCount ?> pending request<?= $pendingSRCount!==1?'s':'' ?></div>
            </div>
          </div>
          <div class="activity-list">
            <?php if (empty($srRequestRows)): ?>
              <div style="padding:20px;text-align:center;color:#999;">
                <div style="font-size:2rem;margin-bottom:8px;">✓</div>
                <div>No pending requests</div>
              </div>
            <?php else: ?>
              <?php foreach ($srRequestRows as $sr): ?>
                <div class="activity-item">
                  <div class="av" style="background:<?= dashColor($sr['first_name'].' '.$sr['last_name'], $avColors) ?>;color:#fff;font-size:12px;font-weight:700;"><?= dashIni($sr['first_name'].' '.$sr['last_name']) ?></div>
                  <div class="ai-body">
                    <div class="ai-title"><?= htmlspecialchars($sr['first_name'].' '.$sr['last_name']) ?></div>
                    <div class="ai-sub"><?= htmlspecialchars($sr['purpose']??'No purpose specified'); ?></div>
                    <div class="ai-meta"><?= htmlspecialchars($sr['position_title']??'') ?> • <?= htmlspecialchars($sr['department']??'') ?> • <?= date('M d, Y', strtotime($sr['requested_at']??'')) ?></div>
                  </div>
                </div>
              <?php endforeach ?>
            <?php endif ?>
          </div>
        </div>

      </div>

      <!-- ROW 3 — LEAVE TYPE BREAKDOWN + ANNOUNCEMENTS -->
      <div class="row-2" style="margin-bottom:22px;">

        <!-- Leave Type breakdown -->
        <div class="panel">
          <div class="panel-header">
            <div>
              <div class="panel-title">🗂️ Leave Type Breakdown</div>
              <div class="panel-sub">Total approved leaves by type — 2025</div>
            </div>
            <a class="panel-action" href="leave_types.php">Manage →</a>
          </div>
          <div class="mini-chart-wrap">
            <div style="margin-bottom:16px;">
             <div style="position:relative;height:280px;"><canvas id="donutChart"></canvas></div>
            </div>
<div class="leave-type-bars">
              <?php
                $barColors = [
                  'linear-gradient(90deg,#2563eb,#60a5fa)',
                  'linear-gradient(90deg,#f59e0b,#fcd34d)',
                  'linear-gradient(90deg,#ef4444,#fca5a5)',
                  'linear-gradient(90deg,#a855f7,#d8b4fe)',
                  'linear-gradient(90deg,#22c55e,#86efac)',
                  'linear-gradient(90deg,#06b6d4,#67e8f9)',
                  'linear-gradient(90deg,#ec4899,#f9a8d4)',
                  'linear-gradient(90deg,#f97316,#fdba74)',
                ];
                $maxLeaveCount = max(1, max(array_column($leaveByType ?: [['total'=>1]], 'total')));
                if (empty($leaveByType)) {
                  echo '<div style="font-size:.75rem;color:#94a3b8;padding:8px 0;text-align:center;">No leave data for this year yet.</div>';
                } else {
                  foreach (array_slice($leaveByType, 0, 6) as $i => $lt) {
                    $pct   = round(($lt['total'] / $maxLeaveCount) * 100);
                    $color = $barColors[$i % count($barColors)];
                    $short = mb_strlen($lt['name']) > 10 ? mb_substr($lt['name'], 0, 10).'…' : $lt['name'];
                    echo '<div class="lbar-row">'
                       . '<div class="lbar-label" title="' . htmlspecialchars($lt['name']) . '">' . htmlspecialchars($short) . '</div>'
                       . '<div class="lbar-track"><div class="lbar-fill" style="width:' . $pct . '%;background:' . $color . ';"></div></div>'
                       . '<div class="lbar-count">' . (int)$lt['total'] . '</div>'
                       . '</div>';
                  }
                }
              ?>
            </div>
          </div>
        </div>

        <!-- Announcements -->
        <div class="panel">
          <div class="panel-header">
            <div>
              <div class="panel-title">📢 Announcements & Notices</div>
              <div class="panel-sub">Posted by HR Admin</div>
            </div>
            <a class="panel-action" href="announcements.php">See all →</a>
          </div>
          <div class="announce-list" id="announceList">
            <div class="announce-item">
              <div class="announce-dot blue"></div>
              <div class="announce-body">
                <div class="announce-title">
                  <span class="announce-tag info">INFO</span>
                  Leave Filing Deadline
                </div>
                <div class="announce-text">All leave requests for April must be submitted by March 28, 2025. Late submissions will not be accommodated.</div>
                <div class="announce-meta">Posted by Admin · March 5, 2025</div>
              </div>
            </div>
            <div class="announce-item">
              <div class="announce-dot red"></div>
              <div class="announce-body">
                <div class="announce-title">
                  <span class="announce-tag urgent">URGENT</span>
                  Updated Leave Policy
                </div>
                <div class="announce-text">Effective April 1, the CSC-revised sick leave rules apply. HR staff must review the updated guidelines.</div>
                <div class="announce-meta">Posted by Admin · March 3, 2025</div>
              </div>
            </div>
            <div class="announce-item">
              <div class="announce-dot green"></div>
              <div class="announce-body">
                <div class="announce-title">
                  <span class="announce-tag success">NOTICE</span>
                  System Maintenance
                </div>
                <div class="announce-text">The HR system will be under maintenance on March 10, 2025 from 6PM–9PM. Please plan accordingly.</div>
                <div class="announce-meta">Posted by Admin · March 1, 2025</div>
              </div>
            </div>
            <div class="announce-item">
              <div class="announce-dot orange"></div>
              <div class="announce-body">
                <div class="announce-title">
                  <span class="announce-tag warning">REMINDER</span>
                  Performance Evaluation
                </div>
                <div class="announce-text">Q1 performance evaluations are due by March 31. Department heads must submit all ratings on time.</div>
                <div class="announce-meta">Posted by Admin · Feb 28, 2025</div>
              </div>
            </div>
          </div>
          <div class="announce-form">
            <textarea id="announceInput" placeholder="Write a new announcement…"></textarea>
            <div class="announce-form-actions">
              <select id="announceType">
                <option value="info">INFO</option>
                <option value="success">NOTICE</option>
                <option value="warning">REMINDER</option>
                <option value="urgent">URGENT</option>
              </select>
              <button class="post-btn" onclick="postAnnouncement()">📢 Post</button>
            </div>
          </div>
        </div>

      </div>

      <!-- ROW 4 — LEAVE DECISION LOG -->
      <style>
        .lact-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px;padding:18px 20px;}
        .lact-card{background:var(--white);border:1.5px solid var(--gray-200);border-radius:var(--radius);padding:16px 18px;display:flex;flex-direction:column;gap:10px;transition:box-shadow .2s;position:relative;overflow:hidden;}
        .lact-card:hover{box-shadow:var(--shadow-md);}
        .lact-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px;border-radius:var(--radius) 0 0 var(--radius);}
        .lact-card.approved::before{background:linear-gradient(180deg,#22c55e,#86efac);}
        .lact-card.denied::before{background:linear-gradient(180deg,#ef4444,#fca5a5);}
        .lact-top{display:flex;align-items:center;gap:10px;}
        .lact-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:.70rem;font-weight:800;letter-spacing:.03em;}
        .lact-badge.approved{background:#dcfce7;color:#166534;}
        .lact-badge.denied{background:#fee2e2;color:#991b1b;}
        .lact-emp-av{width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:800;color:#fff;flex-shrink:0;box-shadow:0 2px 6px rgba(0,0,0,.13);}
        .lact-emp-info{flex:1;min-width:0;}
        .lact-emp-name{font-size:.85rem;font-weight:800;color:var(--gray-800);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .lact-emp-dept{font-size:.68rem;color:var(--gray-400);margin-top:1px;}
        .lact-divider{height:1px;background:var(--gray-100);}
        .lact-details{display:flex;gap:8px;flex-wrap:wrap;}
        .lact-chip{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:7px;background:var(--gray-50);border:1px solid var(--gray-200);font-size:.70rem;font-weight:700;color:var(--gray-600);}
        .lact-footer{display:flex;align-items:center;justify-content:space-between;margin-top:2px;}
        .lact-admin{font-size:.68rem;color:var(--gray-400);}
        .lact-time{font-size:.66rem;color:var(--gray-400);font-weight:700;}
        .lact-empty{grid-column:1/-1;text-align:center;padding:40px 20px;color:var(--gray-400);}
        .lact-empty-ic{font-size:2.4rem;opacity:.25;margin-bottom:10px;}
        .lact-empty p{font-size:.80rem;font-weight:600;}
      </style>
      <div style="margin-bottom:22px;">
        <div class="panel">
          <div class="panel-header">
            <div>
              <div class="panel-title">✅ Leave Request Decisions</div>
              <div class="panel-sub">Approved &amp; denied leave requests by admin</div>
            </div>
            <div style="display:flex;align-items:center;gap:10px;">
              <span id="actRefreshDot" title="Live updates" style="width:8px;height:8px;border-radius:50%;background:#22c55e;display:inline-block;box-shadow:0 0 0 3px rgba(34,197,94,.18);"></span>
              <a class="panel-action" href="leave_requests.php">View all requests →</a>
            </div>
          </div>
          <div class="lact-grid" id="activityLog">
            <?php if (empty($activityRows)): ?>
            <div class="lact-empty">
              <div class="lact-empty-ic">📋</div>
              <p>No leave decisions yet.<br>Approved or denied requests will appear here.</p>
            </div>
            <?php else: foreach ($activityRows as $ar):
              $isApproved = ($ar['status'] === 'Approved');
              $statusCls  = $isApproved ? 'approved' : 'denied';
              $statusLbl  = $isApproved ? '✓ Approved' : '✗ Denied';
              $empName    = htmlspecialchars($ar['employee_name'] ?? 'Employee');
              $empIni     = dashIni($empName);
              $empCol     = dashColor($empName, $avColors);
              $dept       = htmlspecialchars($ar['department'] ?? '');
              $ltype      = htmlspecialchars($ar['leave_type'] ?? 'Leave');
              $days       = $ar['days_requested'] ? $ar['days_requested'].' '.((int)$ar['days_requested']===1?'day':'days') : '';
              $dfrom      = !empty($ar['date_from']) ? date('M j', strtotime($ar['date_from'])) : '';
              $dto        = !empty($ar['date_to'])   ? date('M j', strtotime($ar['date_to']))   : '';
              $dateRange  = ($dfrom && $dto && $dfrom!==$dto) ? "$dfrom – $dto" : ($dfrom ?: '');
              $actor      = htmlspecialchars($ar['actor_name'] ?? 'Admin');
              $atime      = timeAgo($ar['reviewed_at'] ?? '');
            ?>
            <div class="lact-card <?= $statusCls ?>">
              <div class="lact-top">
                <div class="lact-emp-av" style="background:<?= $empCol ?>;"><?= $empIni ?></div>
                <div class="lact-emp-info">
                  <div class="lact-emp-name"><?= $empName ?></div>
                  <?php if ($dept): ?><div class="lact-emp-dept"><?= $dept ?></div><?php endif; ?>
                </div>
                <span class="lact-badge <?= $statusCls ?>"><?= $statusLbl ?></span>
              </div>
              <div class="lact-divider"></div>
              <div class="lact-details">
                <?php if ($ltype): ?><span class="lact-chip">📁 <?= $ltype ?></span><?php endif; ?>
                <?php if ($dateRange): ?><span class="lact-chip">📅 <?= $dateRange ?></span><?php endif; ?>
                <?php if ($days): ?><span class="lact-chip">⏱ <?= $days ?></span><?php endif; ?>
              </div>
              <div class="lact-footer">
                <span class="lact-admin">by <?= $actor ?></span>
                <span class="lact-time"><?= $atime ?></span>
              </div>
            </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </div>
    </div><!-- .content -->
  </div><!-- .main -->

  <script>
    // Date
    const d = new Date();
    document.getElementById('currentDate').textContent =
      d.toLocaleDateString('en-PH', { weekday:'short', year:'numeric', month:'short', day:'numeric' });

    // Auto-refresh activity every 30s
    function refreshActivity() {
      fetch('dashboard_activity.php')
        .then(function(r){ return r.json(); })
        .then(function(j){
          if (!j.success || !j.html) return;
          var log = document.getElementById('activityLog');
          if (log) log.innerHTML = j.html;
          var dot = document.getElementById('actRefreshDot');
          if (dot){ dot.style.background='#2563eb'; setTimeout(function(){ dot.style.background='#22c55e'; }, 600); }
        }).catch(function(){});
    }
    setInterval(refreshActivity, 30000);

    // Sidebar toggle
    

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
      document.getElementById('overlay').classList.toggle('show');
    }

    // Leave bar chart
    const ctx1 = document.getElementById('leaveChart').getContext('2d');
    new Chart(ctx1, {
      type: 'bar',
      data: {
        labels: ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'],
        datasets: [
          { label: 'Approved', data: <?= json_encode(array_values($chartApproved)) ?>, backgroundColor: 'rgba(34,197,94,0.78)', borderRadius: 6 },
          { label: 'Denied',   data: <?= json_encode(array_values($chartDenied))   ?>, backgroundColor: 'rgba(239,68,68,0.68)', borderRadius: 6 },
          { label: 'Pending',  data: <?= json_encode(array_values($chartPending))  ?>, backgroundColor: 'rgba(245,158,11,0.68)', borderRadius: 6 },
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position:'top', labels: { font:{family:'Nunito',size:11}, usePointStyle:true, pointStyleWidth:8 } },
          tooltip: { titleFont:{family:'Nunito'}, bodyFont:{family:'Nunito'} }
        },
        scales: {
          x: { grid:{display:false}, ticks:{font:{family:'Nunito',size:11}} },
          y: { grid:{color:'#f1f5f9'}, ticks:{font:{family:'Nunito',size:11}, stepSize:5} }
        }
      }
    });

    // Leave type donut chart
    const ctx2 = document.getElementById('donutChart').getContext('2d');
    new Chart(ctx2, {
      type: 'doughnut',
      data: (function() {
        var ltData = <?= json_encode(array_values($leaveByType)) ?>;
        var colors = ['#3b82f6','#f59e0b','#ef4444','#a855f7','#22c55e','#06b6d4','#ec4899','#f97316'];
        var labels = ltData.map(function(r){ return r.name; });
        var values = ltData.map(function(r){ return parseInt(r.total)||0; });
        var bgs    = labels.map(function(_,i){ return colors[i % colors.length]; });
        if (!values.some(function(v){ return v > 0; })) {
          labels = ['No data yet']; values = [1]; bgs = ['#e2e8f0'];
        }
        return { labels: labels, datasets: [{ data: values, backgroundColor: bgs, borderWidth: 2, borderColor: '#fff', hoverOffset: 6 }] };
      })(),
      options: {
        responsive: true, cutout: '68%',
        maintainAspectRatio: false,
        plugins: {
          legend: { position:'right', labels:{ font:{family:'Nunito',size:11}, usePointStyle:true, pointStyleWidth:8 } },
          tooltip: { titleFont:{family:'Nunito'}, bodyFont:{family:'Nunito'} }
        }
      }
    });

    // Leave approve / deny
    function processLeave(btn, action, requestId) {
      var row = btn.closest('tr');
      var empName = row.querySelector('.emp-name') ? row.querySelector('.emp-name').textContent : 'Employee';
      var statusCell  = document.getElementById('lstatus-' + requestId) || row.querySelector('.badge-status');
      var actionsCell = document.getElementById('lact-'    + requestId) || row.querySelector('.action-btns');

      // Optimistic UI
      if (action === 'approve') {
        statusCell.className = 'badge-status approved'; statusCell.textContent = '✓ Approved';
      } else {
        statusCell.className = 'badge-status denied';   statusCell.textContent = '✗ Denied';
      }
      actionsCell.innerHTML = '<span style="font-size:0.72rem;color:var(--gray-400);font-weight:700;">Processing…</span>';

      // Call backend if we have a real ID
      if (requestId) {
        var fd = new FormData();
        fd.append('action', action === 'approve' ? 'approve_leave' : 'deny_leave');
        fd.append('request_id', requestId);
        fetch('leave_requests.php', { method: 'POST', body: fd })
          .then(function(r){ return r.json(); })
          .then(function(j){
            actionsCell.innerHTML = '<span style="font-size:0.72rem;color:var(--gray-400);font-weight:700;">Processed</span>';
            if (action === 'approve') addActivity('green','✅','Leave Approved', empName + ' — leave approved');
            else addActivity('red','✗','Leave Denied', empName + ' — leave denied');
          })
          .catch(function(){
            actionsCell.innerHTML = '<span style="font-size:0.72rem;color:var(--gray-400);font-weight:700;">Processed</span>';
            if (action === 'approve') addActivity('green','✅','Leave Approved', empName + ' — leave approved');
            else addActivity('red','✗','Leave Denied', empName + ' — leave denied');
          });
      } else {
        actionsCell.innerHTML = '<span style="font-size:0.72rem;color:var(--gray-400);font-weight:700;">Processed</span>';
        if (action === 'approve') addActivity('green','✅','Leave Approved', empName + ' — leave approved');
        else addActivity('red','✗','Leave Denied', empName + ' — leave denied');
      }
    }

    // Add to activity log
    function addActivity(color, icon, title, desc) {
      const log = document.getElementById('activityLog');
      const now = 'Just now';
      const item = document.createElement('div');
      item.className = 'activity-item';
      item.style.animation = 'cardIn 0.3s ease both';
      item.innerHTML = `<div class="act-dot ${color}">${icon}</div>
        <div class="act-text"><strong>${title}</strong><p>${desc}</p></div>
        <div class="act-time">${now}</div>`;
      log.prepend(item);
    }

    // Clear activity
    function clearActivity() {
      document.getElementById('activityLog').innerHTML =
        '<p style="text-align:center;color:var(--gray-400);font-size:0.80rem;padding:20px;">No recent activity.</p>';
    }

    // Post announcement
    function postAnnouncement() {
      const input = document.getElementById('announceInput');
      const type  = document.getElementById('announceType').value;
      const text  = input.value.trim();
      if (!text) return;

      const typeLabels = { info:'INFO', success:'NOTICE', warning:'REMINDER', urgent:'URGENT' };
      const dotColors  = { info:'blue', success:'green', warning:'orange', urgent:'red' };
      const today = new Date().toLocaleDateString('en-PH', {month:'short', day:'numeric', year:'numeric'});

      const item = document.createElement('div');
      item.className = 'announce-item';
      item.style.animation = 'cardIn 0.3s ease both';
      item.innerHTML = `
        <div class="announce-dot ${dotColors[type]}"></div>
        <div class="announce-body">
          <div class="announce-title">
            <span class="announce-tag ${type}">${typeLabels[type]}</span>
            New Announcement
          </div>
          <div class="announce-text">${text}</div>
          <div class="announce-meta">Posted by Admin · ${today}</div>
        </div>`;

      document.getElementById('announceList').prepend(item);
      input.value = '';
      addActivity('blue', '📢', 'Announcement Posted', text.substring(0, 50) + (text.length > 50 ? '…' : ''));
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
  
function toggleProfileDropdown() {
  document.getElementById('tbDropdown').classList.toggle('open');
}
document.addEventListener('click', function(e) {
  var wrap = document.getElementById('tbProfileWrap');
  if (wrap && !wrap.contains(e.target)) {
    var dd = document.getElementById('tbDropdown');
    if (dd) dd.classList.remove('open');
  }
});
</script>
</body>
</html>