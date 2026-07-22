<?php
// ============================================================
//  LGU ENRILE HRMS — Reports Page  (Enhanced)
//  File: Admin/reports.php
// ============================================================
session_start();
require_once __DIR__ . '/../config/auth.php';
require_admin_auth(['admin', 'hr_staff', 'dept_head']);
require_once __DIR__ . '/../config/db.php';

// ════════════════════════════════════════════════════════════
//  AJAX HANDLER
// ════════════════════════════════════════════════════════════
$action = $_GET['action'] ?? '';

if ($action) {
    header('Content-Type: application/json');
    try {
        switch ($action) {

            // ── OVERVIEW STATS ─────────────────────────────────────────
            case 'overview':
                $employees = $pdo->query("
                    SELECT COUNT(*) AS total,
                        SUM(employment_status = 'Active')      AS active,
                        SUM(employment_status = 'Inactive')    AS inactive,
                        SUM(employment_status = 'On Leave')    AS on_leave,
                        SUM(employment_type   = 'Permanent')   AS permanent,
                        SUM(employment_type   = 'Co-Terminus') AS co_terminus,
                        SUM(employment_type   = 'Elective')    AS elective,
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
                        SUM(status = 'Denied')   AS rejected,
                        SUM(days_requested)      AS total_days_taken
                    FROM leave_requests
                    WHERE YEAR(filed_at) = YEAR(CURDATE())
                ")->fetch();

                $departments = $pdo->query("SELECT COUNT(*) FROM departments WHERE is_active=1")->fetchColumn();

                echo json_encode(['success'=>true,'employees'=>$employees,'leave'=>$leave,'departments'=>$departments]);
                break;

            // ── EMPLOYEE ROSTER REPORT ─────────────────────────────────
            case 'employee_roster':
                $dept   = $_GET['dept']   ?? '';
                $type   = $_GET['type']   ?? '';
                $status = $_GET['status'] ?? '';
                $search = $_GET['search'] ?? '';

                $where  = ['1=1'];
                $params = [];

                if ($dept)   { $where[] = 'e.department_id = ?';   $params[] = $dept; }
                if ($type)   { $where[] = 'e.employment_type = ?'; $params[] = $type; }
                if ($status) { $where[] = 'e.employment_status = ?'; $params[] = $status; }
                if ($search) {
                    $where[] = "(CONCAT(e.first_name,' ',e.last_name) LIKE ? OR e.employee_code LIKE ? OR e.position_title LIKE ?)";
                    $s = "%$search%";
                    $params[] = $s; $params[] = $s; $params[] = $s;
                }

                $wSql = 'WHERE ' . implode(' AND ', $where);
                $stmt = $pdo->prepare("
                    SELECT e.employee_id, e.employee_code,
                        CONCAT(e.first_name,' ',COALESCE(e.middle_name,''),' ',e.last_name) AS full_name,
                        e.first_name, e.last_name,
                        e.gender, e.position_title,
                        e.employment_type, e.employment_status,
                        COALESCE(d.name,'—') AS department,
                        e.date_hired,
                        e.contact_number, e.email_address
                    FROM employees e
                    LEFT JOIN departments d ON e.department_id = d.department_id
                    $wSql
                    ORDER BY e.last_name, e.first_name
                ");
                $stmt->execute($params);
                $rows = $stmt->fetchAll();

                // Summary counts
                $summary_stmt = $pdo->prepare("
                    SELECT
                        COUNT(*) AS total,
                        SUM(e.employment_type='Permanent')   AS permanent,
                        SUM(e.employment_type='Co-Terminus') AS co_terminus,
                        SUM(e.employment_type='Elective')    AS elective,
                        SUM(e.employment_type='Job Order')   AS job_order,
                        SUM(e.employment_type='Casual')      AS casual,
                        SUM(e.employment_type='Contractual') AS contractual,
                        SUM(e.employment_status='Active')    AS active,
                        SUM(e.employment_status='Inactive')  AS inactive
                    FROM employees e
                    LEFT JOIN departments d ON e.department_id = d.department_id
                    $wSql
                ");
                $summary_stmt->execute($params);
                $summary = $summary_stmt->fetch();

                echo json_encode(['success'=>true,'data'=>$rows,'summary'=>$summary]);
                break;

            // ── DEPARTMENTS LIST ───────────────────────────────────────
            case 'departments_list':
                $stmt = $pdo->query("SELECT department_id, name FROM departments WHERE is_active=1 ORDER BY name");
                echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]);
                break;

            // ── LEAVE REQUESTS REPORT ──────────────────────────────────
            case 'leave_report':
                $dept       = $_GET['dept']      ?? '';
                $status     = $_GET['status']    ?? 'Approved';
                $leave_type = $_GET['leave_type'] ?? '';
                $period     = $_GET['period']    ?? 'year';  // week, month, year
                if (!in_array($period, ['week','month','year','custom'], true)) $period = 'year';
                $year       = (int)($_GET['year'] ?? date('Y'));
                $month      = (int)($_GET['month'] ?? date('n'));
                $search     = $_GET['search']    ?? '';

                // H-9: dept_head scoped to their own department
                if (($_SESSION['user_role'] ?? '') === 'dept_head') {
                    $dhStmt = $pdo->prepare('SELECT department_id FROM employees WHERE user_id = ? LIMIT 1');
                    $dhStmt->execute([(int)$_SESSION['user_id']]);
                    $dept = (string)($dhStmt->fetchColumn() ?: '');
                }

                $where  = ['1=1', 'lr.is_deleted = 0']; // P2-7: exclude soft-deleted requests
                $params = [];
                // H-8: Fix missing if for 'week' period (was parse error)
                if ($period === 'week') {
                    $where[] = 'lr.filed_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)';
                } elseif ($period === 'month') {
                    $where[] = 'YEAR(lr.filed_at) = ? AND MONTH(lr.filed_at) = ?';
                    $params[] = $year; $params[] = $month;
                } elseif ($period === 'year') {
                    $where[] = 'YEAR(lr.filed_at) = ?';
                    $params[] = $year;
                }
                // custom range
                if ($_GET['date_from'] ?? '') { $where[] = 'lr.filed_at >= ?'; $params[] = $_GET['date_from']; }
                if ($_GET['date_to']   ?? '') { $where[] = 'lr.filed_at <= ?'; $params[] = $_GET['date_to'].' 23:59:59'; }

                if ($dept)       { $where[] = 'e.department_id = ?';   $params[] = $dept; }
                if ($status)     { $where[] = 'lr.status = ?';          $params[] = $status; }
                if ($leave_type) { $where[] = 'lr.leave_type_id = ?';  $params[] = $leave_type; }
                if ($search) {
                    $where[] = "(CONCAT(e.first_name,' ',e.last_name) LIKE ? OR e.employee_code LIKE ?)";
                    $s = "%$search%";
                    $params[] = $s; $params[] = $s;
                }

                $wSql = 'WHERE ' . implode(' AND ', $where);
                $stmt = $pdo->prepare("
                    SELECT
                        lr.request_id,
                        e.employee_code,
                        CONCAT(e.first_name,' ',e.last_name) AS employee_name,
                        COALESCE(d.name,'—')                  AS department,
                        e.employment_type,
                        lt.name                               AS leave_type,
                        lt.code                               AS leave_code,
                        lr.date_from,
                        lr.date_to,
                        lr.days_requested,
                        lr.status,
                        lr.filed_at,
                        lr.reviewed_at,
                        CONCAT(ua.email)                      AS reviewed_by_email,
                        COALESCE(
                            (SELECT CONCAT(e2.first_name,' ',e2.last_name)
                             FROM employees e2
                             WHERE e2.user_id = lr.reviewed_by LIMIT 1),
                            ua.email, '—'
                        )                                     AS approved_by,
                        COALESCE(
                            (SELECT lb.remaining_days
                             FROM leave_balances lb
                             WHERE lb.employee_id = e.employee_id
                               AND lb.leave_type_id = lr.leave_type_id
                               AND lb.calendar_year = YEAR(lr.filed_at)
                             LIMIT 1), 0
                        )                                     AS credits_left,
                        lr.admin_remarks
                    FROM leave_requests lr
                    JOIN employees   e  ON lr.employee_id   = e.employee_id
                    JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id
                    LEFT JOIN departments   d  ON e.department_id  = d.department_id
                    LEFT JOIN user_accounts ua ON lr.reviewed_by   = ua.user_id
                    $wSql
                    ORDER BY lr.filed_at DESC
                ");
                $stmt->execute($params);
                $rows = $stmt->fetchAll();

                // Summary (re-use same WHERE params — both queries must stay in sync)
                $sum_stmt = $pdo->prepare("
                    SELECT COUNT(*) AS total,
                        SUM(lr.days_requested) AS total_days,
                        SUM(lr.status='Approved') AS approved,
                        SUM(lr.status='Denied')   AS denied,
                        SUM(lr.status='Pending')  AS pending,
                        SUM(lr.status='Cancelled') AS cancelled
                    FROM leave_requests lr
                    JOIN employees e ON lr.employee_id = e.employee_id
                    LEFT JOIN departments d ON e.department_id = d.department_id
                    $wSql
                ");
                $sum_stmt->execute($params);
                $summary = $sum_stmt->fetch();

                // P3-2 — Pagination (bypassed when print=1 is passed)
                $totalRows = count($rows);
                if (empty($_GET['print'])) {
                    $perPage = max(1, min(500, (int)($_GET['per_page'] ?? 50)));
                    $page    = max(1, (int)($_GET['page'] ?? 1));
                    $rows    = array_slice($rows, ($page - 1) * $perPage, $perPage);
                } else {
                    $perPage = $totalRows ?: 1;
                    $page    = 1;
                }

                echo json_encode([
                    'success'    => true,
                    'data'       => $rows,
                    'summary'    => $summary,
                    'pagination' => [
                        'total'    => $totalRows,
                        'page'     => $page,
                        'per_page' => $perPage,
                        'pages'    => (int)ceil($totalRows / $perPage),
                    ],
                ]);
                break;

            // ── LEAVE TYPES LIST ───────────────────────────────────────
            case 'leave_types_list':
                $stmt = $pdo->query("SELECT leave_type_id, name, code FROM leave_types WHERE is_active=1 ORDER BY name");
                echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]);
                break;

            // ── DEPT BAR CHART ─────────────────────────────────────────
            case 'by_department':
                $stmt = $pdo->query("
                    SELECT COALESCE(d.name,'Unassigned') AS department,
                        COUNT(*) AS total,
                        SUM(e.employment_type='Permanent')   AS permanent,
                        SUM(e.employment_type='Co-Terminus') AS co_terminus,
                        SUM(e.employment_type='Elective')    AS elective,
                        SUM(e.employment_type='Job Order')   AS job_order,
                        SUM(e.employment_type='Casual')      AS casual,
                        SUM(e.employment_type='Contractual') AS contractual,
                        SUM(e.employment_status='Active')    AS active,
                        SUM(e.employment_status='Inactive')  AS inactive
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

            case 'leave_monthly':
                $year = (int)($_GET['year'] ?? date('Y'));
                $stmt = $pdo->prepare("
                    SELECT MONTH(filed_at) AS month_num, MONTHNAME(filed_at) AS month_name,
                        COUNT(*) AS requests, SUM(days_requested) AS days,
                        SUM(status='Approved') AS approved, SUM(status='Denied') AS rejected
                    FROM leave_requests WHERE YEAR(filed_at)=?
                    GROUP BY MONTH(filed_at), MONTHNAME(filed_at) ORDER BY month_num
                ");
                $stmt->execute([$year]);
                echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]);
                break;

            // ── CSV EXPORT OF LEAVE REPORT (P3-1) ─────────────────────
            case 'export_csv':
                $dept       = $_GET['dept']       ?? '';
                $status     = $_GET['status']     ?? 'Approved';
                $leave_type = $_GET['leave_type'] ?? '';
                $period     = $_GET['period']     ?? 'year';
                $year       = (int)($_GET['year']  ?? date('Y'));
                $month      = (int)($_GET['month'] ?? date('n'));
                $search     = $_GET['search']     ?? '';

                // H-9: dept_head scoped to their own department
                if (($_SESSION['user_role'] ?? '') === 'dept_head') {
                    $dhStmt2 = $pdo->prepare('SELECT department_id FROM employees WHERE user_id = ? LIMIT 1');
                    $dhStmt2->execute([(int)$_SESSION['user_id']]);
                    $dept = (string)($dhStmt2->fetchColumn() ?: '');
                }

                $where  = ['1=1', 'lr.is_deleted = 0'];
                $params = [];

                if ($period === 'week') {
                    $where[] = 'lr.filed_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)';
                } elseif ($period === 'month') {
                    $where[] = 'YEAR(lr.filed_at) = ? AND MONTH(lr.filed_at) = ?';
                    $params[] = $year; $params[] = $month;
                } elseif ($period === 'year') {
                    $where[] = 'YEAR(lr.filed_at) = ?';
                    $params[] = $year;
                }
                if ($_GET['date_from'] ?? '') { $where[] = 'lr.filed_at >= ?'; $params[] = $_GET['date_from']; }
                if ($_GET['date_to']   ?? '') { $where[] = 'lr.filed_at <= ?'; $params[] = $_GET['date_to'].' 23:59:59'; }
                if ($dept)       { $where[] = 'e.department_id = ?';  $params[] = $dept; }
                if ($status)     { $where[] = 'lr.status = ?';         $params[] = $status; }
                if ($leave_type) { $where[] = 'lr.leave_type_id = ?'; $params[] = $leave_type; }
                if ($search) {
                    $where[] = "(CONCAT(e.first_name,' ',e.last_name) LIKE ? OR e.employee_code LIKE ?)";
                    $s = "%$search%"; $params[] = $s; $params[] = $s;
                }

                $wSql = 'WHERE ' . implode(' AND ', $where);
                $csvStmt = $pdo->prepare("
                    SELECT
                        lr.request_id,
                        e.employee_code,
                        CONCAT(e.first_name,' ',e.last_name)  AS employee_name,
                        COALESCE(d.name,'—')                   AS department,
                        e.employment_type,
                        lt.name                                AS leave_type,
                        lr.date_from, lr.date_to,
                        lr.days_requested,
                        lr.status,
                        lr.filed_at,
                        COALESCE(
                            (SELECT CONCAT(e2.first_name,' ',e2.last_name)
                             FROM employees e2
                             WHERE e2.user_id = lr.reviewed_by LIMIT 1),
                            ua.email,'—'
                        )                                      AS approved_by,
                        lr.admin_remarks
                    FROM leave_requests lr
                    JOIN employees   e  ON lr.employee_id   = e.employee_id
                    JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id
                    LEFT JOIN departments   d  ON e.department_id = d.department_id
                    LEFT JOIN user_accounts ua ON lr.reviewed_by  = ua.user_id
                    $wSql
                    ORDER BY lr.filed_at DESC
                ");
                $csvStmt->execute($params);
                $csvRows = $csvStmt->fetchAll();

                // Stream CSV — no buffering
                while (ob_get_level()) { ob_end_clean(); }
                header('Content-Type: text/csv; charset=utf-8');
                $filename = 'leave_report_' . date('Ymd_His') . '.csv';
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Cache-Control: no-cache, no-store, must-revalidate');

                $out = fopen('php://output', 'w');
                // BOM for Excel UTF-8 compatibility
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['#','Employee Code','Employee Name','Department','Employment Type',
                               'Leave Type','Date From','Date To','Days','Status',
                               'Filed At','Approved By','Remarks']);
                $n = 1;
                foreach ($csvRows as $r) {
                    fputcsv($out, [
                        $n++,
                        $r['employee_code'], $r['employee_name'], $r['department'],
                        $r['employment_type'], $r['leave_type'],
                        $r['date_from'], $r['date_to'], $r['days_requested'],
                        $r['status'], $r['filed_at'], $r['approved_by'], $r['admin_remarks'],
                    ]);
                }
                fclose($out);
                exit();

            // ── CSV EXPORT OF EMPLOYEE ROSTER ─────────────────────────────────
            case 'export_csv_employees':
                $dept   = $_GET['dept']   ?? '';
                $type   = $_GET['type']   ?? '';
                $status = $_GET['status'] ?? '';
                $search = $_GET['search'] ?? '';
                $where  = ['1=1'];
                $params = [];
                if ($dept)   { $where[] = 'e.department_id = ?';     $params[] = $dept; }
                if ($type)   { $where[] = 'e.employment_type = ?';   $params[] = $type; }
                if ($status) { $where[] = 'e.employment_status = ?'; $params[] = $status; }
                if ($search) {
                    $where[] = "(CONCAT(e.first_name,' ',e.last_name) LIKE ? OR e.employee_code LIKE ? OR e.position_title LIKE ?)";
                    $s = "%$search%"; $params[] = $s; $params[] = $s; $params[] = $s;
                }
                $wSql = 'WHERE ' . implode(' AND ', $where);
                $empCsvStmt = $pdo->prepare("
                    SELECT e.employee_code,
                        CONCAT(e.first_name,' ',COALESCE(e.middle_name,''),' ',e.last_name) AS full_name,
                        COALESCE(d.name,'—') AS department,
                        e.position_title, e.employment_type,
                        e.employment_status, e.gender, e.date_hired,
                        e.contact_number, e.email_address
                    FROM employees e
                    LEFT JOIN departments d ON e.department_id = d.department_id
                    $wSql
                    ORDER BY e.last_name, e.first_name
                ");
                $empCsvStmt->execute($params);
                $empCsvRows = $empCsvStmt->fetchAll();
                while (ob_get_level()) { ob_end_clean(); }
                header('Content-Type: text/csv; charset=utf-8');
                $empFilename = 'employee_roster_' . date('Ymd_His') . '.csv';
                header('Content-Disposition: attachment; filename="' . $empFilename . '"');
                header('Cache-Control: no-cache, no-store, must-revalidate');
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['#','Employee Code','Full Name','Department','Position Title',
                               'Employment Type','Status','Gender','Date Hired','Contact','Email']);
                $n = 1;
                foreach ($empCsvRows as $r) {
                    $empType = $r['employment_type'] === 'Contractual' ? 'COS/Contractual' : $r['employment_type'];
                    fputcsv($out, [
                        $n++,
                        $r['employee_code'],
                        trim(preg_replace('/\s+/', ' ', $r['full_name'])),
                        $r['department'], $r['position_title'],
                        $empType, $r['employment_status'], $r['gender'],
                        $r['date_hired'], $r['contact_number'], $r['email_address'],
                    ]);
                }
                fclose($out);
                exit();

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
$currentMonth= date('n');
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

    /* ── SIDEBAR ── */
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

    /* ── MAIN LAYOUT ── */
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
    .btn-print { display:flex; align-items:center; gap:7px; padding:8px 16px; background:rgba(255,255,255,0.15); border:1.5px solid rgba(255,255,255,0.28); border-radius:var(--radius-sm); font-family:'Nunito',sans-serif; font-size:0.84rem; font-weight:700; color:#fff; cursor:pointer; transition:all 0.2s; white-space:nowrap; }
    .btn-print:hover { background:rgba(255,255,255,0.28); transform:translateY(-1px); }

    /* ── REPORT TABS ── */
    .report-tabs { display:flex; gap:4px; background:var(--white); border-radius:var(--radius); padding:6px; border:1.5px solid var(--gray-200); box-shadow:var(--shadow-sm); margin-bottom:20px; flex-wrap:wrap; }
    .rtab { flex:1; min-width:140px; padding:10px 16px; border:none; border-radius:var(--radius-sm); font-family:'Nunito',sans-serif; font-size:0.85rem; font-weight:700; color:var(--gray-400); cursor:pointer; transition:all 0.25s; display:flex; align-items:center; justify-content:center; gap:7px; background:transparent; }
    .rtab:hover { background:var(--gray-100); color:var(--gray-600); }
    .rtab.active { background:linear-gradient(135deg,var(--primary),var(--primary-dark)); color:#fff; box-shadow:0 2px 10px rgba(37,99,235,0.25); }

    /* ── STAT CARDS ── */
    .stat-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:14px; margin-bottom:20px; }
    .stat-card { background:var(--white); border-radius:var(--radius); padding:16px 18px; border:1.5px solid var(--gray-200); box-shadow:var(--shadow-sm); position:relative; overflow:hidden; transition:transform 0.2s,box-shadow 0.2s; animation:fadeIn 0.4s ease both; }
    .stat-card:hover { transform:translateY(-3px); box-shadow:var(--shadow-md); }
    .stat-card::before { content:''; position:absolute; top:0; left:0; right:0; height:4px; border-radius:var(--radius) var(--radius) 0 0; }
    .stat-card.blue::before   { background:linear-gradient(90deg,#2563eb,#60a5fa); }
    .stat-card.green::before  { background:linear-gradient(90deg,#22c55e,#86efac); }
    .stat-card.orange::before { background:linear-gradient(90deg,#f59e0b,#fcd34d); }
    .stat-card.red::before    { background:linear-gradient(90deg,#ef4444,#fca5a5); }
    .stat-card.purple::before { background:linear-gradient(90deg,#a855f7,#d8b4fe); }
    .stat-card.sky::before    { background:linear-gradient(90deg,#0ea5e9,#7dd3fc); }
    .stat-card.teal::before   { background:linear-gradient(90deg,#14b8a6,#5eead4); }
    .stat-card-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
    .stat-icon { width:40px; height:40px; border-radius:11px; display:flex; align-items:center; justify-content:center; font-size:1.1rem; }
    .stat-card.blue   .stat-icon { background:#dbeafe; }
    .stat-card.green  .stat-icon { background:#dcfce7; }
    .stat-card.orange .stat-icon { background:#fef3c7; }
    .stat-card.red    .stat-icon { background:#fee2e2; }
    .stat-card.purple .stat-icon { background:#f3e8ff; }
    .stat-card.sky    .stat-icon { background:#e0f2fe; }
    .stat-card.teal   .stat-icon { background:#ccfbf1; }
    .stat-value { font-size:1.8rem; font-weight:800; color:var(--gray-800); line-height:1; margin-bottom:3px; }
    .stat-label { font-size:0.76rem; color:var(--gray-400); font-weight:600; }
    .stat-sub   { font-size:0.68rem; color:var(--gray-400); margin-top:5px; }

    /* ── FILTER BAR ── */
    .filter-bar { background:var(--white); border-radius:var(--radius); padding:16px 20px; border:1.5px solid var(--gray-200); box-shadow:var(--shadow-sm); margin-bottom:18px; display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; }
    .filter-group { display:flex; flex-direction:column; gap:4px; min-width:150px; flex:1; }
    .filter-label { font-size:0.70rem; font-weight:800; color:var(--gray-400); text-transform:uppercase; letter-spacing:0.07em; }
    .filter-control { padding:8px 12px; border:1.5px solid var(--gray-200); border-radius:var(--radius-sm); font-family:'Nunito',sans-serif; font-size:0.82rem; font-weight:600; color:var(--gray-800); background:var(--white); outline:none; cursor:pointer; transition:border-color 0.2s; }
    .filter-control:focus { border-color:var(--primary); box-shadow:0 0 0 3px rgba(37,99,235,0.10); }
    .filter-actions { display:flex; gap:8px; align-items:flex-end; flex-shrink:0; }
    .btn-filter { padding:8px 20px; border:none; border-radius:var(--radius-sm); font-family:'Nunito',sans-serif; font-size:0.84rem; font-weight:700; cursor:pointer; transition:all 0.2s; }
    .btn-apply { background:linear-gradient(135deg,var(--primary),var(--primary-dark)); color:#fff; box-shadow:0 2px 8px rgba(37,99,235,0.25); }
    .btn-apply:hover { transform:translateY(-1px); box-shadow:0 4px 14px rgba(37,99,235,0.35); }
    .btn-reset { background:var(--gray-100); color:var(--gray-600); border:1.5px solid var(--gray-200); }
    .btn-reset:hover { background:var(--gray-200); }
    .btn-export { background:linear-gradient(135deg,#22c55e,#16a34a); color:#fff; border:none; box-shadow:0 2px 8px rgba(34,197,94,0.25); }
    .btn-export:hover { transform:translateY(-1px); box-shadow:0 4px 14px rgba(34,197,94,0.35); }

    /* Screen-only: hide print-only table */
    .print-only-table { display:none; }

    /* ── PANEL ── */
    .panel { background:var(--white); border-radius:var(--radius); border:1.5px solid var(--gray-200); box-shadow:var(--shadow-sm); overflow:hidden; animation:fadeIn 0.4s ease both; margin-bottom:20px; }
    .panel-head { display:flex; justify-content:space-between; align-items:center; padding:14px 20px; border-bottom:1.5px solid var(--gray-100); background:var(--gray-50); flex-wrap:wrap; gap:10px; }
    .panel-head h3 { font-size:0.93rem; font-weight:800; color:var(--gray-800); margin:0; font-family:'Playfair Display',serif; }
    .panel-head-actions { display:flex; gap:8px; align-items:center; }
    .panel-body { padding:18px 20px; }
    .panel-body.no-pad { padding:0; }

    /* ── TABLES ── */
    .rep-table-wrap { overflow-x:auto; }
    .rep-table { width:100%; border-collapse:collapse; font-size:0.83rem; }
    .rep-table thead tr { background:var(--gray-50); }
    .rep-table thead th { padding:10px 14px; text-align:left; font-size:0.67rem; font-weight:800; color:var(--gray-400); text-transform:uppercase; letter-spacing:0.07em; border-bottom:1.5px solid var(--gray-200); white-space:nowrap; }
    .rep-table thead th.num { text-align:right; }
    .rep-table tbody tr { border-bottom:1px solid var(--gray-100); transition:background 0.15s; }
    .rep-table tbody tr:last-child { border-bottom:none; }
    .rep-table tbody tr:hover { background:#f0f6ff; }
    .rep-table td { padding:10px 14px; color:var(--gray-800); vertical-align:middle; }
    .rep-table td.num { font-weight:700; text-align:right; }

    /* ── BADGES ── */
    .badge { display:inline-flex; align-items:center; padding:3px 9px; border-radius:20px; font-size:0.68rem; font-weight:700; white-space:nowrap; }
    .badge-green  { background:#dcfce7; color:#166534; }
    .badge-red    { background:#fee2e2; color:#991b1b; }
    .badge-amber  { background:#fef3c7; color:#92400e; }
    .badge-blue   { background:#dbeafe; color:#1e40af; }
    .badge-purple { background:#f3e8ff; color:#6b21a8; }
    .badge-gray   { background:var(--gray-100); color:var(--gray-600); }
    .badge-teal   { background:#ccfbf1; color:#0f766e; }
    .badge-sky    { background:#e0f2fe; color:#0369a1; }

    /* ── BAR CHART ── */
    .bar-chart { display:flex; flex-direction:column; gap:10px; }
    .bar-row { display:flex; align-items:center; gap:10px; }
    .bar-label { font-size:0.76rem; color:var(--gray-600); font-weight:600; width:150px; flex-shrink:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .bar-track { flex:1; background:var(--gray-100); border-radius:8px; height:22px; overflow:hidden; }
    .bar-fill  { height:100%; border-radius:8px; transition:width 0.7s cubic-bezier(0.4,0,0.2,1); min-width:2px; }
    .bar-val   { font-size:0.76rem; font-weight:800; color:var(--gray-600); width:36px; text-align:right; flex-shrink:0; }

    /* ── SPARKLINE ── */
    .sparkline-wrap { display:flex; align-items:flex-end; gap:4px; height:130px; padding-bottom:22px; position:relative; }
    .spark-col { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; gap:2px; height:100%; position:relative; padding-bottom:18px; }
    .spark-val { font-size:0.60rem; font-weight:700; }
    .spark-bar { width:75%; border-radius:3px 3px 0 0; transition:height 0.5s ease; min-height:3px; }
    .spark-lbl { position:absolute; bottom:0; left:50%; transform:translateX(-50%); font-size:0.60rem; color:var(--gray-400); white-space:nowrap; }

    /* ── DONUT ── */
    .donut-wrap { display:flex; align-items:center; gap:22px; flex-wrap:wrap; }
    .donut-legend { display:flex; flex-direction:column; gap:8px; flex:1; }
    .donut-item { display:flex; align-items:center; gap:8px; font-size:0.80rem; color:var(--gray-600); }
    .donut-dot  { width:11px; height:11px; border-radius:3px; flex-shrink:0; }
    .donut-pct  { margin-left:auto; padding-left:10px; font-size:0.80rem; font-weight:800; color:var(--gray-800); }

    /* ── TWO-COL ── */
    .two-col { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px; }

    /* ── LOADING / EMPTY ── */
    .empty-rep { text-align:center; padding:30px 20px; color:var(--gray-400); font-size:0.875rem; font-weight:600; }
    .loading-row td { text-align:center; padding:24px; color:var(--gray-400); font-size:0.84rem; }

    /* ── YEAR/PERIOD CONTROLS ── */
    .ctrl-select { padding:6px 10px; border:1.5px solid var(--gray-200); border-radius:var(--radius-sm); font-family:'Nunito',sans-serif; font-size:0.80rem; font-weight:700; color:var(--gray-600); background:var(--white); outline:none; cursor:pointer; }
    .ctrl-select:focus { border-color:var(--primary); }

    /* ── SECTION VIEWS ── */
    .report-section { display:none; }
    .report-section.active { display:block; }

    /* ── RESULT SUMMARY BAR ── */
    .result-bar { display:flex; gap:18px; padding:12px 18px; background:linear-gradient(135deg,#eff6ff,#f0fdf4); border-radius:var(--radius-sm); border:1.5px solid #bfdbfe; margin-bottom:16px; flex-wrap:wrap; align-items:center; }
    .result-item { display:flex; flex-direction:column; align-items:center; }
    .result-val  { font-size:1.3rem; font-weight:800; color:var(--gray-800); line-height:1; }
    .result-lbl  { font-size:0.66rem; font-weight:700; color:var(--gray-400); text-transform:uppercase; letter-spacing:0.07em; }

    /* ── TOAST ── */
    .toast { position:fixed; bottom:28px; right:28px; padding:12px 20px; border-radius:var(--radius-sm); font-size:0.84rem; font-weight:700; box-shadow:var(--shadow-lg); z-index:900; color:white; animation:fadeIn 0.3s ease both; }
    .toast.success { background:linear-gradient(135deg,#22c55e,#16a34a); }
    .toast.error   { background:linear-gradient(135deg,#ef4444,#dc2626); }

    @keyframes fadeIn { from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);} }

    /* ── RESPONSIVE ── */
    .overlay-mob { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:150; }
    .overlay-mob.show { display:block; }
    @media(max-width:900px) { .two-col { grid-template-columns:1fr; } }
    @media(max-width:768px) {
      .sidebar { transform:translateX(-100%); }
      .sidebar.open { transform:translateX(0); }
      .main { margin-left:0; }
      .menu-toggle { display:block; }
      .content { padding:14px 12px; }
      .topbar { padding:0 14px; }
      .stat-cards { grid-template-columns:repeat(2,1fr); gap:10px; }
      .filter-bar { gap:10px; }
      .filter-group { min-width:130px; }
    }
    @media(max-width:480px) {
      .stat-cards { grid-template-columns:1fr 1fr; }
      .btn-print span { display:none; }
      .rtab { font-size:0.76rem; padding:8px 10px; gap:4px; }
    }

    /* ══════════════════════════════════════
       PRINT STYLES — Clean B&W Text Output
    ══════════════════════════════════════ */
    @page { size: A4 landscape; margin: 15mm; }
    @media print {
      /* ── Hide all UI chrome ── */
      .sidebar, .topbar, .overlay-mob,
      .report-tabs, .filter-bar, .result-bar,
      .panel-head-actions, .btn-filter, .btn-print, .btn-export,
      .stat-cards, .two-col, .sparkline-wrap,
      .bar-chart, .donut-wrap, .ctrl-select,
      .no-print { display:none !important; }

      /* ── Layout reset ── */
      body   { background:#fff !important; font-family:Arial,sans-serif !important; font-size:10pt !important; color:#000 !important; display:block !important; }
      .main  { margin:0 !important; display:block !important; }
      .content { padding:0 !important; }

      /* ── Only the active section prints; inactive sections are hidden ── */
      .report-section        { display:none !important; }
      .report-section.active { display:block !important; }

      /* ── Print header ── */
      .print-header { display:block !important; text-align:center; margin-bottom:14px; padding-bottom:10px; border-bottom:2px solid #000; }
      .print-header h2 { font-size:14pt !important; font-weight:bold; color:#000 !important; margin-bottom:3px; }
      .print-header p  { font-size:9pt !important; color:#333 !important; }

      /* ── Panel: no shadow, no radius ── */
      .panel { box-shadow:none !important; border:1px solid #bbb !important; border-radius:0 !important; margin-bottom:10px; break-inside:auto; }
      .panel-head { background:#efefef !important; border-bottom:1px solid #bbb !important; padding:6px 10px !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
      .panel-head h3 { font-size:10pt !important; color:#000 !important; }
      .panel-body { padding:0 !important; }

      /* ── Overview print-only summary table ── */
      .print-only-table { display:table !important; width:100%; border-collapse:collapse; font-size:10pt; margin:10px 0 14px; }

      /* ── Data tables: plain B&W ── */
      .rep-table { width:100%; border-collapse:collapse; font-size:9pt; }
      .rep-table-wrap { overflow:visible !important; }
      .rep-table thead tr { background:#e0e0e0 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
      .rep-table thead th {
        background:#e0e0e0 !important;
        color:#000 !important;
        font-size:8pt !important;
        font-weight:bold !important;
        padding:5px 7px !important;
        border:1px solid #999 !important;
        text-transform:uppercase;
        -webkit-print-color-adjust:exact;
        print-color-adjust:exact;
      }
      .rep-table tbody td {
        padding:4px 7px !important;
        border:1px solid #ccc !important;
        color:#000 !important;
        font-size:9pt !important;
        font-weight:normal !important;
        background:#fff !important;
        white-space:normal !important;
      }
      .rep-table tbody tr:nth-child(even) td {
        background:#f5f5f5 !important;
        -webkit-print-color-adjust:exact;
        print-color-adjust:exact;
      }
      .rep-table tbody tr:hover td { background:inherit !important; }
      .rep-table td.num { text-align:right !important; font-weight:bold !important; }

      /* ── Badges → plain bold text, no color fill ── */
      .badge {
        background:none !important;
        color:#000 !important;
        border:none !important;
        border-radius:0 !important;
        padding:0 !important;
        font-size:inherit !important;
        font-weight:bold !important;
        display:inline !important;
      }
    }

    /* Print-only header (screen: hidden) */
    .print-header { display:none; text-align:center; margin-bottom:20px; padding-bottom:12px; border-bottom:2px solid #e2e8f0; }
    .print-header h2 { font-family:'Playfair Display',serif; font-size:1.3rem; color:#1e293b; margin-bottom:4px; }
    .print-header p  { font-size:0.80rem; color:#64748b; }

    /* Notification/profile (reuse from other pages) */
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
    .notif-panel{display:none;position:absolute;top:calc(100% + 10px);right:0;background:#fff;border-radius:14px;box-shadow:0 8px 32px rgba(0,0,0,.18);width:320px;max-height:460px;z-index:9999;overflow:hidden;flex-direction:column;}
    .notif-panel.open{display:flex;}
    .notif-hd{padding:12px 14px 8px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;background:#f8fafc;}
    .notif-hd-title{font-size:.86rem;font-weight:800;color:#1e293b;}
    .notif-mark-all{font-size:.70rem;font-weight:700;color:#2563eb;cursor:pointer;border:none;background:none;font-family:inherit;}
    .notif-list{overflow-y:auto;flex:1;}
    .notif-item{display:flex;align-items:flex-start;gap:10px;padding:10px 14px;border-bottom:1px solid #f8fafc;cursor:pointer;transition:background .15s;text-decoration:none;}
    .notif-item:hover{background:#f8fafc;}
    .notif-item.unread{background:#eff6ff;}
    .notif-ico{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0;}
    .notif-body{flex:1;min-width:0;}
    .notif-title{font-size:.78rem;font-weight:800;color:#1e293b;margin-bottom:2px;}
    .notif-text{font-size:.72rem;color:#475569;line-height:1.4;}
    .notif-time{font-size:.64rem;color:#94a3b8;margin-top:2px;}
    .notif-empty{text-align:center;padding:32px 16px;color:#94a3b8;}
    .notif-empty .ne-icon{font-size:2rem;margin-bottom:6px;}
    .notif-empty p{font-size:.80rem;font-weight:600;}
    .notif-footer{padding:9px 14px;border-top:1px solid #f1f5f9;text-align:center;}
    .notif-footer a{font-size:.76rem;font-weight:700;color:#2563eb;text-decoration:none;}
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
    <a class="nav-item" href="coe_requests.php"><div class="nav-icon">📄</div>COE Requests</a>
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
      <p>HR analytics, employee roster &amp; leave summaries</p>
    </div>
    <div class="topbar-actions">
      <!-- Notifications -->
      <div class="notif-wrap" id="adminNotifWrap">
        <button class="notif-btn" onclick="toggleNotifPanel()" title="Notifications">
          🔔<span class="notif-count <?= $notifCount > 0 ? 'show' : '' ?>" id="adminNotifCount"><?= $notifCount ?: '' ?></span>
        </button>
        <div class="notif-panel" id="adminNotifPanel">
          <div class="notif-hd">
            <div class="notif-hd-title">🔔 Notifications</div>
            <button class="notif-mark-all" onclick="clearNotifications()">Mark all read</button>
          </div>
          <div class="notif-list" id="adminNotifList">
            <?php if(empty($notifications)):?>
            <div class="notif-empty"><div class="ne-icon">🔔</div><p>All caught up!</p></div>
            <?php else: foreach($notifications as $n):?>
            <a class="notif-item unread" href="<?=htmlspecialchars($n['link'])?>" data-nid="<?=md5(($n['title']??'').($n['body']??'').($n['time']??''))?>">
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
      <!-- Profile -->
      <div class="tb-profile-wrap" id="tbProfileWrap">
        <div class="tb-profile-btn" onclick="toggleProfileDropdown()"><?= strtoupper(substr($adminName,0,2)) ?></div>
        <div class="tb-dropdown" id="tbDropdown">
          <div class="tb-dd-header">
            <div class="tb-dd-name"><?= htmlspecialchars($adminName) ?></div>
            <div class="tb-dd-role"><?= htmlspecialchars($roleLabel) ?></div>
          </div>
          <a href="switch_to_employee.php" class="tb-dd-item" style="color:#d97706;">🔄 Switch to Employee</a>
          <div class="tb-dd-divider"></div>
          <a href="../Landingpage/logout.php" class="tb-dd-item danger" onclick="return confirm('Log out?')">🚪 Logout</a>
        </div>
      </div>
      <!-- Print -->
      <button class="btn-print" onclick="printReport()">🖨️ <span>Print Report</span></button>
    </div>
  </div>

  <div class="content">

    <!-- ══ REPORT TABS ══ -->
    <div class="report-tabs no-print">
      <button class="rtab active" id="tab-overview"  onclick="switchTab('overview')">📊 Overview</button>
      <button class="rtab"        id="tab-employees" onclick="switchTab('employees')">👥 Employee Roster</button>
      <button class="rtab"        id="tab-leaves"    onclick="switchTab('leaves')">📋 Leave Requests</button>
    </div>

    <!-- ══════════════════════════════════════════
         SECTION 1: OVERVIEW
    ══════════════════════════════════════════ -->
    <div class="report-section active" id="overviewSection">
      <div class="print-header">
        <h2>LGU Enrile HR Management System</h2>
        <p id="ovPrintDate">Overview Report — Generated: <?= date('F j, Y g:i A') ?></p>
      </div>

      <!-- Print-only overview summary table (hidden on screen; shown when printing) -->
      <table class="print-only-table rep-table" id="ovPrintTable">
        <thead>
          <tr>
            <th>Metric</th>
            <th style="text-align:right">Count</th>
            <th>Metric</th>
            <th style="text-align:right">Count</th>
          </tr>
        </thead>
        <tbody id="ovPrintTbody">
          <tr><td colspan="4" style="text-align:center;padding:10px;color:#999">Loading overview data…</td></tr>
        </tbody>
      </table>

      <!-- Overview Stat Cards -->
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
        <div class="stat-card orange">
          <div class="stat-card-header"><div class="stat-icon">🏛️</div></div>
          <div class="stat-value" id="ovPermanent">—</div>
          <div class="stat-label">Permanent</div>
          <div class="stat-sub">Regular positions</div>
        </div>
        <div class="stat-card purple">
          <div class="stat-card-header"><div class="stat-icon">📋</div></div>
          <div class="stat-value" id="ovJO">—</div>
          <div class="stat-label">Job Order</div>
          <div class="stat-sub" id="ovJOSub">Loading…</div>
        </div>
        <div class="stat-card sky">
          <div class="stat-card-header"><div class="stat-icon">📄</div></div>
          <div class="stat-value" id="ovCasual">—</div>
          <div class="stat-label">Casual</div>
          <div class="stat-sub">Casual employees</div>
        </div>
        <div class="stat-card teal">
          <div class="stat-card-header"><div class="stat-icon">🔄</div></div>
          <div class="stat-value" id="ovCOS">—</div>
          <div class="stat-label">COS / Contractual</div>
          <div class="stat-sub">Contract of Service</div>
        </div>
        <div class="stat-card red">
          <div class="stat-card-header"><div class="stat-icon">📋</div></div>
          <div class="stat-value" id="ovLeaveApproved">—</div>
          <div class="stat-label">Approved Leaves <?= $currentYear ?></div>
          <div class="stat-sub" id="ovLeaveSub">Loading…</div>
        </div>
        <div class="stat-card blue" style="--card-top:#8b5cf6">
          <div class="stat-card-header"><div class="stat-icon">🏢</div></div>
          <div class="stat-value" id="ovDepts">—</div>
          <div class="stat-label">Departments</div>
          <div class="stat-sub">Active units</div>
        </div>
      </div>

      <!-- By Department + Employment Type -->
      <div class="two-col">
        <div class="panel">
          <div class="panel-head"><h3>👥 Employees by Department</h3></div>
          <div class="panel-body">
            <div class="bar-chart" id="deptChart"><p class="empty-rep">Loading…</p></div>
          </div>
        </div>
        <div class="panel">
          <div class="panel-head"><h3>📋 Employment Type Breakdown</h3></div>
          <div class="panel-body">
            <div class="donut-wrap" id="typeDonut"><p class="empty-rep">Loading…</p></div>
          </div>
        </div>
      </div>

      <!-- Monthly Leave Chart -->
      <div class="panel">
        <div class="panel-head">
          <h3>📅 Leave Requests by Month</h3>
          <select class="ctrl-select" id="leaveMonthYear" onchange="loadLeaveMonthly()">
            <?php for($y=$currentYear;$y>=$currentYear-4;$y--): ?>
              <option value="<?=$y?>"><?=$y?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="panel-body">
          <div class="sparkline-wrap" id="monthlyChart"><p class="empty-rep" style="width:100%">Loading…</p></div>
        </div>
      </div>
    </div><!-- /overviewSection -->

    <!-- ══════════════════════════════════════════
         SECTION 2: EMPLOYEE ROSTER
    ══════════════════════════════════════════ -->
    <div class="report-section" id="employeeSection">
      <div class="print-header">
        <h2>LGU Enrile — Employee Roster</h2>
        <p id="empPrintMeta">Generated: <?= date('F j, Y g:i A') ?></p>
      </div>

      <!-- Filter Bar -->
      <div class="filter-bar no-print">
        <div class="filter-group">
          <div class="filter-label">Department</div>
          <select class="filter-control" id="empDept">
            <option value="">All Departments</option>
          </select>
        </div>
        <div class="filter-group">
          <div class="filter-label">Employment Type</div>
          <select class="filter-control" id="empType">
            <option value="">All Types</option>
            <option value="Permanent">Permanent</option>
            <option value="Co-Terminus">Co-Terminus</option>
            <option value="Elective">Elective</option>
            <option value="Job Order">Job Order</option>
            <option value="Casual">Casual</option>
            <option value="Contractual">COS / Contractual</option>
          </select>
        </div>
        <div class="filter-group">
          <div class="filter-label">Status</div>
          <select class="filter-control" id="empStatus">
            <option value="">All Status</option>
            <option value="Active">Active</option>
            <option value="On Leave">On Leave</option>
            <option value="Inactive">Inactive</option>
          </select>
        </div>
        <div class="filter-group">
          <div class="filter-label">Search</div>
          <input type="text" class="filter-control" id="empSearch" placeholder="Name, Code, Position…" onkeydown="if(event.key==='Enter') loadEmployeeRoster()">
        </div>
        <div class="filter-actions">
          <button class="btn-filter btn-apply" onclick="loadEmployeeRoster()">🔍 Filter</button>
          <button class="btn-filter btn-reset" onclick="resetEmpFilters()">↺ Reset</button>
          <button class="btn-filter btn-export" onclick="exportEmployeeCSV()">📥 Export CSV</button>
        </div>
      </div>

      <!-- Summary Badges -->
      <div class="result-bar" id="empResultBar" style="display:none">
        <div class="result-item"><span class="result-val" id="empSumTotal">0</span><span class="result-lbl">Total</span></div>
        <div style="width:1px;background:#bfdbfe;align-self:stretch;"></div>
        <div class="result-item"><span class="result-val" id="empSumPerm">0</span><span class="result-lbl">Permanent</span></div>
        <div class="result-item"><span class="result-val" id="empSumCoterm">0</span><span class="result-lbl">Co-Terminus</span></div>
        <div class="result-item"><span class="result-val" id="empSumElective">0</span><span class="result-lbl">Elective</span></div>
        <div class="result-item"><span class="result-val" id="empSumJO">0</span><span class="result-lbl">Job Order</span></div>
        <div class="result-item"><span class="result-val" id="empSumCasual">0</span><span class="result-lbl">Casual</span></div>
        <div class="result-item"><span class="result-val" id="empSumCOS">0</span><span class="result-lbl">COS</span></div>
        <div style="width:1px;background:#bfdbfe;align-self:stretch;"></div>
        <div class="result-item"><span class="result-val" id="empSumActive" style="color:#22c55e">0</span><span class="result-lbl">Active</span></div>
        <div class="result-item"><span class="result-val" id="empSumInactive" style="color:#ef4444">0</span><span class="result-lbl">Inactive</span></div>
      </div>

      <!-- Employee Table -->
      <div class="panel">
        <div class="panel-head">
          <h3>👥 Employee Roster</h3>
          <div class="panel-head-actions no-print">
            <span id="empCount" style="font-size:0.78rem;color:var(--gray-400);font-weight:700"></span>
          </div>
        </div>
        <div class="panel-body no-pad">
          <div class="rep-table-wrap">
            <table class="rep-table" id="empTable">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Employee Code</th>
                  <th>Full Name</th>
                  <th>Department</th>
                  <th>Position</th>
                  <th>Type</th>
                  <th>Status</th>
                  <th>Gender</th>
                  <th>Date Hired</th>
                  <th>Contact</th>
                </tr>
              </thead>
              <tbody id="empTbody">
                <tr><td colspan="10" class="loading-row">Click <strong>Filter</strong> or wait for default load…</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div><!-- /employeeSection -->

    <!-- ══════════════════════════════════════════
         SECTION 3: LEAVE REQUESTS REPORT
    ══════════════════════════════════════════ -->
    <div class="report-section" id="leavesSection">
      <div class="print-header">
        <h2>LGU Enrile — Leave Requests Report</h2>
        <p id="leavePrintMeta">Generated: <?= date('F j, Y g:i A') ?></p>
      </div>

      <!-- Filter Bar -->
      <div class="filter-bar no-print">
        <!-- Period selector -->
        <div class="filter-group" style="min-width:120px">
          <div class="filter-label">Period</div>
          <select class="filter-control" id="lvPeriod" onchange="togglePeriodControls()">
            <option value="week">This Week</option>
            <option value="month">By Month</option>
            <option value="year" selected>By Year</option>
            <option value="custom">Custom Range</option>
          </select>
        </div>
        <div class="filter-group" id="lvYearGroup">
          <div class="filter-label">Year</div>
          <select class="filter-control" id="lvYear">
            <?php for($y=$currentYear;$y>=$currentYear-4;$y--): ?>
              <option value="<?=$y?>"><?=$y?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="filter-group" id="lvMonthGroup" style="display:none">
          <div class="filter-label">Month</div>
          <select class="filter-control" id="lvMonth">
            <?php $months=['January','February','March','April','May','June','July','August','September','October','November','December'];
            foreach($months as $i=>$m): ?>
              <option value="<?=$i+1?>" <?=$i+1==$currentMonth?'selected':''?>><?=$m?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-group" id="lvDateFromGroup" style="display:none">
          <div class="filter-label">Date From</div>
          <input type="date" class="filter-control" id="lvDateFrom">
        </div>
        <div class="filter-group" id="lvDateToGroup" style="display:none">
          <div class="filter-label">Date To</div>
          <input type="date" class="filter-control" id="lvDateTo">
        </div>
        <div class="filter-group">
          <div class="filter-label">Department</div>
          <select class="filter-control" id="lvDept">
            <option value="">All Departments</option>
          </select>
        </div>
        <div class="filter-group">
          <div class="filter-label">Status</div>
          <select class="filter-control" id="lvStatus">
            <option value="Approved" selected>Approved</option>
            <option value="Pending">Pending</option>
            <option value="Denied">Denied</option>
            <option value="Cancelled">Cancelled</option>
            <option value="">All Status</option>
          </select>
        </div>
        <div class="filter-group">
          <div class="filter-label">Leave Type</div>
          <select class="filter-control" id="lvType">
            <option value="">All Types</option>
          </select>
        </div>
        <div class="filter-group">
          <div class="filter-label">Search Employee</div>
          <input type="text" class="filter-control" id="lvSearch" placeholder="Name or Code…" onkeydown="if(event.key==='Enter') loadLeaveReport()">
        </div>
        <div class="filter-actions">
          <button class="btn-filter btn-apply" onclick="loadLeaveReport()">🔍 Filter</button>
          <button class="btn-filter btn-reset" onclick="resetLeaveFilters()">↺ Reset</button>
          <button class="btn-filter btn-export" onclick="exportLeaveCSV()">📥 Export CSV</button>
        </div>
      </div>

      <!-- Leave Summary Badges -->
      <div class="result-bar" id="lvResultBar" style="display:none">
        <div class="result-item"><span class="result-val" id="lvSumTotal">0</span><span class="result-lbl">Total</span></div>
        <div style="width:1px;background:#bfdbfe;align-self:stretch;"></div>
        <div class="result-item"><span class="result-val" id="lvSumApproved" style="color:#22c55e">0</span><span class="result-lbl">Approved</span></div>
        <div class="result-item"><span class="result-val" id="lvSumPending" style="color:#f59e0b">0</span><span class="result-lbl">Pending</span></div>
        <div class="result-item"><span class="result-val" id="lvSumDenied" style="color:#ef4444">0</span><span class="result-lbl">Denied</span></div>
        <div class="result-item"><span class="result-val" id="lvSumDays" style="color:#2563eb">0</span><span class="result-lbl">Total Days</span></div>
      </div>

      <!-- Leave Table -->
      <div class="panel">
        <div class="panel-head">
          <h3>📋 Leave Requests</h3>
          <div class="panel-head-actions no-print">
            <span id="lvCount" style="font-size:0.78rem;color:var(--gray-400);font-weight:700"></span>
          </div>
        </div>
        <div class="panel-body no-pad">
          <div class="rep-table-wrap">
            <table class="rep-table" id="lvTable">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Employee</th>
                  <th>Department</th>
                  <th>Type</th>
                  <th>Leave Type</th>
                  <th>Date From</th>
                  <th>Date To</th>
                  <th class="num">Days</th>
                  <th>Status</th>
                  <th>Filed</th>
                  <th>Approved Date</th>
                  <th class="num">Credits Left</th>
                  <th>Approved By</th>
                </tr>
              </thead>
              <tbody id="lvTbody">
                <tr><td colspan="13" class="loading-row">Use the filters above and click <strong>Filter</strong> to load data.</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div><!-- /leavesSection -->

  </div><!-- /content -->
</div><!-- /main -->

<script>
var API    = './reports.php';
var COLORS = ['#2563eb','#22c55e','#f59e0b','#ef4444','#0ea5e9','#a855f7','#14b8a6','#f97316','#ec4899','#84cc16'];
var depts  = [];
var leaveTypes = [];

// ────────────────────────────────────────────────────────────
//  INIT
// ────────────────────────────────────────────────────────────
(function init() {
  loadDropdowns();
  loadOverview();
  loadDeptChart();
  loadTypeDonut();
  loadLeaveMonthly();
  // Default-load employee roster
  setTimeout(function(){ loadEmployeeRoster(); }, 300);
})();

async function fetchJSON(url) {
  var res = await fetch(API + url);
  return res.json();
}
function fmt(d) {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'numeric'});
}
function fmtDT(d) {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'numeric',hour:'numeric',minute:'2-digit'});
}

// ────────────────────────────────────────────────────────────
//  LOAD DROPDOWNS (departments, leave types)
// ────────────────────────────────────────────────────────────
async function loadDropdowns() {
  try {
    var dj = await fetchJSON('?action=departments_list');
    if (dj.success) {
      depts = dj.data;
      ['empDept','lvDept'].forEach(function(id) {
        var sel = document.getElementById(id);
        depts.forEach(function(d) {
          var opt = document.createElement('option');
          opt.value = d.department_id;
          opt.textContent = d.name;
          sel.appendChild(opt);
        });
      });
    }
    var lj = await fetchJSON('?action=leave_types_list');
    if (lj.success) {
      leaveTypes = lj.data;
      var sel = document.getElementById('lvType');
      leaveTypes.forEach(function(lt) {
        var opt = document.createElement('option');
        opt.value = lt.leave_type_id;
        opt.textContent = lt.name + ' (' + lt.code + ')';
        sel.appendChild(opt);
      });
    }
  } catch(e) { console.error('loadDropdowns', e); }
}

// ────────────────────────────────────────────────────────────
//  TAB SWITCHING
// ────────────────────────────────────────────────────────────
function switchTab(tab) {
  ['overview','employees','leaves'].forEach(function(t) {
    document.getElementById('tab-' + (t==='employees'?'employees':t==='leaves'?'leaves':'overview')).classList.remove('active');
    var sectionId = t === 'overview' ? 'overviewSection' : t === 'employees' ? 'employeeSection' : 'leavesSection';
    document.getElementById(sectionId).classList.remove('active');
  });
  var tabId = tab === 'overview' ? 'tab-overview' : tab === 'employees' ? 'tab-employees' : 'tab-leaves';
  var secId = tab === 'overview' ? 'overviewSection' : tab === 'employees' ? 'employeeSection' : 'leavesSection';
  document.getElementById(tabId).classList.add('active');
  document.getElementById(secId).classList.add('active');
}

// ────────────────────────────────────────────────────────────
//  PRINT — Fetch all rows first, then open print dialog
// ────────────────────────────────────────────────────────────
async function printReport() {
  var activeSection = document.querySelector('.report-section.active');
  if (!activeSection) { window.print(); return; }
  var secId = activeSection.id;

  // Overview: data already loaded into print-only table by loadOverview()
  if (secId === 'overviewSection') {
    document.getElementById('ovPrintDate').textContent =
      'Overview Report — Generated: ' + new Date().toLocaleString('en-PH');
    window.print();
    return;
  }

  var btn = document.querySelector('.btn-print');
  var origHTML = btn ? btn.innerHTML : '';
  if (btn) { btn.innerHTML = '⏳ <span>Preparing…</span>'; btn.disabled = true; }

  try {
    if (secId === 'employeeSection') {
      // Employee roster: no server-side pagination — fetch all cleanly for print
      var dept   = document.getElementById('empDept').value;
      var type   = document.getElementById('empType').value;
      var status = document.getElementById('empStatus').value;
      var search = document.getElementById('empSearch').value.trim();
      var params = '?action=employee_roster';
      if (dept)   params += '&dept='   + encodeURIComponent(dept);
      if (type)   params += '&type='   + encodeURIComponent(type);
      if (status) params += '&status=' + encodeURIComponent(status);
      if (search) params += '&search=' + encodeURIComponent(search);
      var j = await fetchJSON(params);
      if (j.success && j.data.length) {
        document.getElementById('empTbody').innerHTML = j.data.map(function(r, i) {
          var empType = r.employment_type === 'Contractual' ? 'COS/Contractual' : r.employment_type;
          var pos = (r.position_title||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');
          return '<tr>'+
            '<td>'+(i+1)+'</td>'+
            '<td>'+r.employee_code+'</td>'+
            '<td>'+r.full_name.replace(/\s+/g,' ').trim()+'</td>'+
            '<td>'+r.department+'</td>'+
            '<td>'+pos+'</td>'+
            '<td>'+empType+'</td>'+
            '<td>'+r.employment_status+'</td>'+
            '<td>'+r.gender+'</td>'+
            '<td>'+fmt(r.date_hired)+'</td>'+
            '<td>'+(r.contact_number||r.email_address||'—')+'</td>'+
          '</tr>';
        }).join('');
      }
    } else if (secId === 'leavesSection') {
      // Leave report: pass print=1 to bypass server-side pagination
      var period = document.getElementById('lvPeriod').value;
      var year   = document.getElementById('lvYear').value;
      var month  = document.getElementById('lvMonth').value;
      var dept   = document.getElementById('lvDept').value;
      var status = document.getElementById('lvStatus').value;
      var type   = document.getElementById('lvType').value;
      var search = document.getElementById('lvSearch').value.trim();
      var dfrom  = document.getElementById('lvDateFrom').value;
      var dto    = document.getElementById('lvDateTo').value;
      var params = '?action=leave_report&print=1&period=' + period;
      if (year)   params += '&year='       + year;
      if (month)  params += '&month='      + month;
      if (dept)   params += '&dept='       + encodeURIComponent(dept);
      if (status) params += '&status='     + encodeURIComponent(status);
      if (type)   params += '&leave_type=' + encodeURIComponent(type);
      if (search) params += '&search='     + encodeURIComponent(search);
      if (dfrom)  params += '&date_from='  + encodeURIComponent(dfrom);
      if (dto)    params += '&date_to='    + encodeURIComponent(dto);
      var j = await fetchJSON(params);
      if (j.success && j.data.length) {
        document.getElementById('lvTbody').innerHTML = j.data.map(function(r, i) {
          var empType = r.employment_type === 'Contractual' ? 'COS/Contractual' : r.employment_type;
          var credits = parseFloat(r.credits_left||0).toFixed(1);
          return '<tr>'+
            '<td>'+(i+1)+'</td>'+
            '<td>'+r.employee_name+' / '+r.employee_code+'</td>'+
            '<td>'+r.department+'</td>'+
            '<td>'+empType+'</td>'+
            '<td>'+r.leave_type+'</td>'+
            '<td>'+fmt(r.date_from)+'</td>'+
            '<td>'+fmt(r.date_to)+'</td>'+
            '<td class="num">'+parseFloat(r.days_requested||0).toFixed(1)+'</td>'+
            '<td>'+r.status+'</td>'+
            '<td>'+fmt(r.filed_at)+'</td>'+
            '<td>'+(r.reviewed_at?fmt(r.reviewed_at):'—')+'</td>'+
            '<td class="num">'+credits+'</td>'+
            '<td>'+(r.approved_by||'—')+'</td>'+
          '</tr>';
        }).join('');
        document.getElementById('leavePrintMeta').textContent =
          document.getElementById('leavePrintMeta').textContent + ' | ' + j.data.length + ' total records';
      }
    }
    setTimeout(function() {
      window.print();
      if (btn) { btn.innerHTML = origHTML; btn.disabled = false; }
      // Restore paginated view after the print dialog closes
      if (secId === 'leavesSection') loadLeaveReport();
      else if (secId === 'employeeSection') loadEmployeeRoster();
    }, 200);
  } catch(err) {
    console.error('printReport', err);
    if (btn) { btn.innerHTML = origHTML; btn.disabled = false; }
    window.print();
  }
}

// ────────────────────────────────────────────────────────────
//  OVERVIEW: Load Stats
// ────────────────────────────────────────────────────────────
async function loadOverview() {
  try {
    var j = await fetchJSON('?action=overview');
    if (!j.success) return;
    var e = j.employees, l = j.leave;
    document.getElementById('ovTotalEmp').textContent    = e.total || 0;
    document.getElementById('ovActive').textContent      = e.active || 0;
    document.getElementById('ovPermanent').textContent   = e.permanent || 0;
    document.getElementById('ovJO').textContent          = e.job_order || 0;
    document.getElementById('ovCasual').textContent      = e.casual || 0;
    document.getElementById('ovCOS').textContent         = e.contractual || 0;
    document.getElementById('ovLeaveApproved').textContent = l.approved || 0;
    document.getElementById('ovDepts').textContent       = j.departments || 0;
    document.getElementById('ovEmpSub').textContent      = (e.male||0)+' male · '+(e.female||0)+' female';
    document.getElementById('ovActiveSub').textContent   = (e.on_leave||0)+' on leave · '+(e.inactive||0)+' inactive';
    document.getElementById('ovJOSub').textContent       = 'Co-Terminus: '+(e.co_terminus||0)+' · Elective: '+(e.elective||0);
    document.getElementById('ovLeaveSub').textContent    = (l.pending||0)+' pending · '+(l.total||0)+' total';
    // Populate print-only overview summary table
    var pt = document.getElementById('ovPrintTbody');
    if (pt) {
      pt.innerHTML = [
        ['Total Employees',  e.total||0,    'Active Departments',  j.departments||0],
        ['Active',           e.active||0,   'On Leave',            e.on_leave||0],
        ['Inactive',         e.inactive||0, 'Male',                e.male||0],
        ['Permanent',        e.permanent||0,'Female',              e.female||0],
        ['Co-Terminus',      e.co_terminus||0,'Elective',          e.elective||0],
        ['Job Order',        e.job_order||0,'Leave Requests ('+new Date().getFullYear()+')', l.total||0],
        ['Casual',           e.casual||0,   'Approved Leaves',     l.approved||0],
        ['COS / Contractual',e.contractual||0,'Pending Leaves',    l.pending||0],
      ].map(function(row) {
        return '<tr>'+
          '<td style="padding:5px 8px">'+row[0]+'</td>'+
          '<td style="padding:5px 8px;text-align:right;font-weight:bold">'+row[1]+'</td>'+
          '<td style="padding:5px 8px">'+row[2]+'</td>'+
          '<td style="padding:5px 8px;text-align:right;font-weight:bold">'+row[3]+'</td>'+
        '</tr>';
      }).join('');
      document.getElementById('ovPrintDate').textContent =
        'Overview Report — Generated: ' + new Date().toLocaleString('en-PH');
    }
  } catch(e) { console.error('overview', e); }
}

// ────────────────────────────────────────────────────────────
//  OVERVIEW: Department Bar Chart
// ────────────────────────────────────────────────────────────
async function loadDeptChart() {
  try {
    var j  = await fetchJSON('?action=by_department');
    var el = document.getElementById('deptChart');
    if (!j.success || !j.data.length) { el.innerHTML='<p class="empty-rep">No data.</p>'; return; }
    var max = Math.max.apply(null, j.data.map(function(r){ return parseInt(r.total); }));
    el.innerHTML = j.data.map(function(r,i) {
      var pct = max>0 ? Math.round(r.total/max*100) : 0;
      return '<div class="bar-row">'+
        '<div class="bar-label" title="'+r.department+'">'+r.department+'</div>'+
        '<div class="bar-track"><div class="bar-fill" style="width:'+pct+'%;background:'+COLORS[i%COLORS.length]+'"></div></div>'+
        '<div class="bar-val">'+r.total+'</div>'+
      '</div>';
    }).join('');
  } catch(e) { console.error('deptChart', e); }
}

// ────────────────────────────────────────────────────────────
//  OVERVIEW: Donut Chart
// ────────────────────────────────────────────────────────────
async function loadTypeDonut() {
  try {
    var j  = await fetchJSON('?action=by_type');
    var el = document.getElementById('typeDonut');
    if (!j.success || !j.data.length) { el.innerHTML='<p class="empty-rep">No data.</p>'; return; }
    var total = j.data.reduce(function(s,r){ return s+parseInt(r.value); },0);
    var R=50, cx=60, cy=60, C=2*Math.PI*R, offset=0;
    var slices = j.data.map(function(r,i) {
      var pct=total>0?parseInt(r.value)/total:0;
      var dash=pct*C, gap=C-dash;
      var s='<circle cx="'+cx+'" cy="'+cy+'" r="'+R+'" fill="none"'+
        ' stroke="'+COLORS[i%COLORS.length]+'" stroke-width="20"'+
        ' stroke-dasharray="'+dash.toFixed(2)+' '+gap.toFixed(2)+'"'+
        ' stroke-dashoffset="'+(-(offset*C)).toFixed(2)+'"'+
        ' transform="rotate(-90 '+cx+' '+cy+')"/>';
      offset+=pct;
      return s;
    }).join('');
    var svg='<svg width="120" height="120" viewBox="0 0 120 120" style="flex-shrink:0">'+
      '<circle cx="60" cy="60" r="50" fill="none" stroke="var(--gray-100)" stroke-width="20"/>'+
      slices+
      '<text x="60" y="57" text-anchor="middle" font-size="16" font-weight="800" fill="var(--gray-800)" font-family="Nunito,sans-serif">'+total+'</text>'+
      '<text x="60" y="70" text-anchor="middle" font-size="9" fill="var(--gray-400)" font-family="Nunito,sans-serif">TOTAL</text>'+
    '</svg>';
    var legend='<div class="donut-legend">'+j.data.map(function(r,i){
      var pct=total>0?Math.round(parseInt(r.value)/total*100):0;
      // Rename Contractual -> COS/Contractual
      var label = r.label === 'Contractual' ? 'COS/Contractual' : r.label;
      return '<div class="donut-item">'+
        '<div class="donut-dot" style="background:'+COLORS[i%COLORS.length]+'"></div>'+
        '<span>'+label+'</span>'+
        '<span class="donut-pct">'+r.value+' <span style="font-size:.66rem;color:var(--gray-400);font-weight:600">('+pct+'%)</span></span>'+
      '</div>';
    }).join('')+'</div>';
    el.innerHTML = svg + legend;
  } catch(e) { console.error('typeDonut', e); }
}

// ────────────────────────────────────────────────────────────
//  OVERVIEW: Monthly Leave Sparkline
// ────────────────────────────────────────────────────────────
async function loadLeaveMonthly() {
  var year = document.getElementById('leaveMonthYear').value;
  var wrap = document.getElementById('monthlyChart');
  try {
    var j = await fetchJSON('?action=leave_monthly&year='+year);
    if (!j.success || !j.data.length) {
      wrap.innerHTML='<p class="empty-rep" style="width:100%">No leave data for '+year+'.</p>'; return;
    }
    var months=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var dataMap={};
    j.data.forEach(function(r){ dataMap[parseInt(r.month_num)]=r; });
    var maxD=1;
    j.data.forEach(function(r){ if(parseInt(r.days||0)>maxD) maxD=parseInt(r.days); });
    wrap.innerHTML = months.map(function(m,i) {
      var r=dataMap[i+1];
      var days=r?(parseInt(r.days)||0):0;
      var pct=maxD>0?Math.round(days/maxD*100):0;
      return '<div class="spark-col">'+
        '<div class="spark-val" style="color:var(--primary)">'+(days||'')+'</div>'+
        '<div class="spark-bar" style="height:'+Math.max(pct,days>0?5:1)+'%;background:'+(days>0?'var(--primary)':'var(--gray-200)')+'"></div>'+
        '<span class="spark-lbl">'+m+'</span>'+
      '</div>';
    }).join('');
  } catch(e) { console.error('leaveMonthly', e); }
}

// ────────────────────────────────────────────────────────────
//  EMPLOYEE ROSTER
// ────────────────────────────────────────────────────────────
async function loadEmployeeRoster() {
  var tbody  = document.getElementById('empTbody');
  var dept   = document.getElementById('empDept').value;
  var type   = document.getElementById('empType').value;
  var status = document.getElementById('empStatus').value;
  var search = document.getElementById('empSearch').value.trim();

  tbody.innerHTML = '<tr><td colspan="10" class="loading-row">⏳ Loading employees…</td></tr>';

  var params = '?action=employee_roster';
  if (dept)   params += '&dept='   + encodeURIComponent(dept);
  if (type)   params += '&type='   + encodeURIComponent(type);
  if (status) params += '&status=' + encodeURIComponent(status);
  if (search) params += '&search=' + encodeURIComponent(search);

  try {
    var j = await fetchJSON(params);
    if (!j.success) { tbody.innerHTML='<tr><td colspan="10" class="loading-row">Error loading data.</td></tr>'; return; }

    // Update summary
    var s = j.summary;
    document.getElementById('empSumTotal').textContent   = s.total   || 0;
    document.getElementById('empSumPerm').textContent    = s.permanent || 0;
    document.getElementById('empSumCoterm').textContent  = s.co_terminus || 0;
    document.getElementById('empSumElective').textContent= s.elective || 0;
    document.getElementById('empSumJO').textContent      = s.job_order || 0;
    document.getElementById('empSumCasual').textContent  = s.casual   || 0;
    document.getElementById('empSumCOS').textContent     = s.contractual || 0;
    document.getElementById('empSumActive').textContent  = s.active   || 0;
    document.getElementById('empSumInactive').textContent= s.inactive || 0;
    document.getElementById('empResultBar').style.display = 'flex';
    document.getElementById('empCount').textContent = (j.data.length) + ' records';

    // Update print meta
    var filters = [];
    if (dept)   filters.push('Dept: '+document.getElementById('empDept').options[document.getElementById('empDept').selectedIndex].text);
    if (type)   filters.push('Type: '+type);
    if (status) filters.push('Status: '+status);
    if (search) filters.push('Search: "'+search+'"');
    document.getElementById('empPrintMeta').textContent =
      'Generated: ' + new Date().toLocaleString('en-PH') +
      (filters.length ? ' | Filters: ' + filters.join(', ') : ' | All Employees');

    if (!j.data.length) {
      tbody.innerHTML='<tr><td colspan="10" class="loading-row">No employees found matching the filters.</td></tr>';
      return;
    }

    var typeBadge = { 'Permanent':'badge-blue', 'Co-Terminus':'badge-purple', 'Elective':'badge-teal', 'Job Order':'badge-purple', 'Casual':'badge-amber', 'Contractual':'badge-teal' };
    var statusBadge = { 'Active':'badge-green', 'Inactive':'badge-red', 'On Leave':'badge-amber', 'Resigned':'badge-gray', 'Retired':'badge-gray' };

    tbody.innerHTML = j.data.map(function(r, i) {
      var tb = typeBadge[r.employment_type] || 'badge-gray';
      var sb = statusBadge[r.employment_status] || 'badge-gray';
      var empType = r.employment_type === 'Contractual' ? 'COS/Contractual' : r.employment_type;
      return '<tr>'+
        '<td style="color:var(--gray-400);font-size:.75rem;font-weight:700">'+(i+1)+'</td>'+
        '<td style="font-family:monospace;font-size:.80rem;color:var(--gray-600)">'+r.employee_code+'</td>'+
        '<td><div style="font-weight:700;color:var(--gray-800)">'+r.full_name.replace(/\s+/g,' ').trim()+'</div></td>'+
        '<td style="font-size:.80rem;color:var(--gray-600)">'+r.department+'</td>'+
        '<td style="font-size:.79rem;color:var(--gray-600);max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="'+(r.position_title||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;')+'">'+(r.position_title||'')+'</td>'+
        '<td><span class="badge '+tb+'">'+empType+'</span></td>'+
        '<td><span class="badge '+sb+'">'+r.employment_status+'</span></td>'+
        '<td style="font-size:.80rem">'+r.gender+'</td>'+
        '<td style="font-size:.80rem;white-space:nowrap">'+fmt(r.date_hired)+'</td>'+
        '<td style="font-size:.76rem;color:var(--gray-600)">'+( r.contact_number||r.email_address||'—')+'</td>'+
      '</tr>';
    }).join('');
  } catch(e) {
    tbody.innerHTML='<tr><td colspan="10" class="loading-row">Error: '+e.message+'</td></tr>';
    console.error('loadEmployeeRoster', e);
  }
}

function resetEmpFilters() {
  document.getElementById('empDept').value   = '';
  document.getElementById('empType').value   = '';
  document.getElementById('empStatus').value = '';
  document.getElementById('empSearch').value = '';
  loadEmployeeRoster();
}

// ────────────────────────────────────────────────────────────
//  LEAVE REPORT
// ────────────────────────────────────────────────────────────
function togglePeriodControls() {
  var period = document.getElementById('lvPeriod').value;
  document.getElementById('lvYearGroup').style.display   = (period==='year'||period==='month') ? '' : 'none';
  document.getElementById('lvMonthGroup').style.display  = (period==='month') ? '' : 'none';
  document.getElementById('lvDateFromGroup').style.display = (period==='custom') ? '' : 'none';
  document.getElementById('lvDateToGroup').style.display   = (period==='custom') ? '' : 'none';
}

async function loadLeaveReport() {
  var tbody  = document.getElementById('lvTbody');
  var period = document.getElementById('lvPeriod').value;
  var year   = document.getElementById('lvYear').value;
  var month  = document.getElementById('lvMonth').value;
  var dept   = document.getElementById('lvDept').value;
  var status = document.getElementById('lvStatus').value;
  var type   = document.getElementById('lvType').value;
  var search = document.getElementById('lvSearch').value.trim();
  var dfrom  = document.getElementById('lvDateFrom').value;
  var dto    = document.getElementById('lvDateTo').value;

  // Validate custom date range
  if (period === 'custom' && dfrom && dto && dfrom > dto) {
    showToast('Date From must be before or equal to Date To.', 'error');
    return;
  }

  tbody.innerHTML = '<tr><td colspan="13" class="loading-row">⏳ Loading leave requests…</td></tr>';

  var params = '?action=leave_report&period=' + period;
  if (year)   params += '&year='      + year;
  if (month)  params += '&month='     + month;
  if (dept)   params += '&dept='      + encodeURIComponent(dept);
  if (status) params += '&status='    + encodeURIComponent(status);
  if (type)   params += '&leave_type='+ encodeURIComponent(type);
  if (search) params += '&search='    + encodeURIComponent(search);
  if (dfrom)  params += '&date_from=' + encodeURIComponent(dfrom);
  if (dto)    params += '&date_to='   + encodeURIComponent(dto);

  try {
    var j = await fetchJSON(params);
    if (!j.success) { tbody.innerHTML='<tr><td colspan="13" class="loading-row">Error loading data.</td></tr>'; return; }

    // Update summary
    var s = j.summary;
    document.getElementById('lvSumTotal').textContent   = s.total   || 0;
    document.getElementById('lvSumApproved').textContent= s.approved || 0;
    document.getElementById('lvSumPending').textContent = s.pending  || 0;
    document.getElementById('lvSumDenied').textContent  = s.denied   || 0;
    document.getElementById('lvSumDays').textContent    = parseFloat(s.total_days||0).toFixed(1);
    document.getElementById('lvResultBar').style.display= 'flex';
    document.getElementById('lvCount').textContent = (j.data.length) + ' records';

    // Update print meta
    var periodLabel = {'week':'This Week','month':'Month '+month+'/'+year,'year':'Year '+year,'custom':'Custom Range'}[period]||period;
    var filters = [periodLabel];
    if (dept)   { var dname=document.getElementById('lvDept').options[document.getElementById('lvDept').selectedIndex].text; if(dname!='All Departments') filters.push(dname); }
    if (status) filters.push('Status: '+status);
    if (search) filters.push('Search: "'+search+'"');
    document.getElementById('leavePrintMeta').textContent =
      'Generated: ' + new Date().toLocaleString('en-PH') + ' | ' + filters.join(' | ');

    if (!j.data.length) {
      tbody.innerHTML='<tr><td colspan="13" class="loading-row">No leave requests found matching the filters.</td></tr>';
      return;
    }

    var statusBadge = { 'Approved':'badge-green','Denied':'badge-red','Pending':'badge-amber','Cancelled':'badge-gray' };
    var typeBadge   = { 'Permanent':'badge-blue','Co-Terminus':'badge-purple','Elective':'badge-teal','Job Order':'badge-purple','Casual':'badge-amber','Contractual':'badge-teal' };

    tbody.innerHTML = j.data.map(function(r, i) {
      var sb  = statusBadge[r.status] || 'badge-gray';
      var tb  = typeBadge[r.employment_type] || 'badge-gray';
      var empType = r.employment_type === 'Contractual' ? 'COS/Contractual' : r.employment_type;
      var credits = parseFloat(r.credits_left||0).toFixed(1);
      var credColor = parseFloat(r.credits_left||0) <= 0 ? 'color:#ef4444;font-weight:800' :
                      parseFloat(r.credits_left||0) <= 3 ? 'color:#f59e0b;font-weight:800' : 'color:#22c55e;font-weight:800';
      return '<tr>'+
        '<td style="color:var(--gray-400);font-size:.73rem;font-weight:700">'+(i+1)+'</td>'+
        '<td>'+
          '<div style="font-weight:700;color:var(--gray-800);font-size:.83rem">'+r.employee_name+'</div>'+
          '<div style="font-size:.68rem;color:var(--gray-400)">'+r.employee_code+'</div>'+
        '</td>'+
        '<td style="font-size:.79rem">'+r.department+'</td>'+
        '<td><span class="badge '+tb+'" style="font-size:.64rem">'+empType+'</span></td>'+
        '<td><span class="badge badge-blue" style="font-size:.64rem">'+r.leave_code+'</span> <span style="font-size:.78rem;color:var(--gray-600)">'+r.leave_type+'</span></td>'+
        '<td style="font-size:.79rem;white-space:nowrap">'+fmt(r.date_from)+'</td>'+
        '<td style="font-size:.79rem;white-space:nowrap">'+fmt(r.date_to)+'</td>'+
        '<td class="num"><strong style="color:var(--primary)">'+parseFloat(r.days_requested||0).toFixed(1)+'</strong></td>'+
        '<td><span class="badge '+sb+'">'+r.status+'</span></td>'+
        '<td style="font-size:.76rem;white-space:nowrap">'+fmt(r.filed_at)+'</td>'+
        '<td style="font-size:.76rem;white-space:nowrap">'+(r.reviewed_at?fmt(r.reviewed_at):'—')+'</td>'+
        '<td class="num"><span style="'+credColor+'">'+credits+'</span></td>'+
        '<td style="font-size:.76rem;color:var(--gray-600)">'+( r.approved_by||'—')+'</td>'+
      '</tr>';
    }).join('');
  } catch(e) {
    tbody.innerHTML='<tr><td colspan="13" class="loading-row">Error: '+e.message+'</td></tr>';
    console.error('loadLeaveReport', e);
  }
}

function resetLeaveFilters() {
  document.getElementById('lvPeriod').value = 'year';
  document.getElementById('lvYear').value   = '<?= $currentYear ?>';
  document.getElementById('lvMonth').value  = '<?= $currentMonth ?>';
  document.getElementById('lvDept').value   = '';
  document.getElementById('lvStatus').value = 'Approved';
  document.getElementById('lvType').value   = '';
  document.getElementById('lvSearch').value = '';
  document.getElementById('lvDateFrom').value = '';
  document.getElementById('lvDateTo').value   = '';
  togglePeriodControls();
  loadLeaveReport();
}

// ────────────────────────────────────────────────────────────
//  CSV EXPORTS
// ────────────────────────────────────────────────────────────
function exportLeaveCSV() {
  var period = document.getElementById('lvPeriod').value;
  var year   = document.getElementById('lvYear').value;
  var month  = document.getElementById('lvMonth').value;
  var dept   = document.getElementById('lvDept').value;
  var status = document.getElementById('lvStatus').value;
  var type   = document.getElementById('lvType').value;
  var search = document.getElementById('lvSearch').value.trim();
  var dfrom  = document.getElementById('lvDateFrom').value;
  var dto    = document.getElementById('lvDateTo').value;
  var url = API + '?action=export_csv&period=' + period;
  if (year)   url += '&year='       + year;
  if (month)  url += '&month='      + month;
  if (dept)   url += '&dept='       + encodeURIComponent(dept);
  if (status) url += '&status='     + encodeURIComponent(status);
  if (type)   url += '&leave_type=' + encodeURIComponent(type);
  if (search) url += '&search='     + encodeURIComponent(search);
  if (dfrom)  url += '&date_from='  + encodeURIComponent(dfrom);
  if (dto)    url += '&date_to='    + encodeURIComponent(dto);
  window.location.href = url;
}

function exportEmployeeCSV() {
  var dept   = document.getElementById('empDept').value;
  var type   = document.getElementById('empType').value;
  var status = document.getElementById('empStatus').value;
  var search = document.getElementById('empSearch').value.trim();
  var url = API + '?action=export_csv_employees';
  if (dept)   url += '&dept='   + encodeURIComponent(dept);
  if (type)   url += '&type='   + encodeURIComponent(type);
  if (status) url += '&status=' + encodeURIComponent(status);
  if (search) url += '&search=' + encodeURIComponent(search);
  window.location.href = url;
}

function showToast(msg, type) {
  var t = document.createElement('div');
  t.className = 'toast ' + (type || 'success');
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(function() {
    t.style.opacity = '0'; t.style.transition = 'opacity 0.4s';
    setTimeout(function() { t.remove(); }, 400);
  }, 3200);
}

// ────────────────────────────────────────────────────────────
//  UTILITY
// ────────────────────────────────────────────────────────────
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlayMob').classList.toggle('show');
}

function toggleProfileDropdown() {
  var dd=document.getElementById('tbDropdown');
  if(dd) dd.classList.toggle('open');
}
document.addEventListener('click',function(e){
  var w=document.getElementById('tbProfileWrap');
  if(w&&!w.contains(e.target)){ var dd=document.getElementById('tbDropdown'); if(dd) dd.classList.remove('open'); }
});

function toggleNotifPanel() {
  var p=document.getElementById('adminNotifPanel');
  if(p) p.classList.toggle('open');
}
document.addEventListener('click',function(e){
  var w=document.getElementById('adminNotifWrap');
  if(w&&!w.contains(e.target)){ var p=document.getElementById('adminNotifPanel'); if(p) p.classList.remove('open'); }
});

function clearNotifications() {
  document.querySelectorAll('.notif-item.unread').forEach(function(el){ el.classList.remove('unread'); });
  var cnt=document.getElementById('adminNotifCount');
  if(cnt){ cnt.classList.remove('show'); cnt.textContent=''; }
  var p=document.getElementById('adminNotifPanel');
  if(p) p.classList.remove('open');
}
</script>
</body>
</html>
