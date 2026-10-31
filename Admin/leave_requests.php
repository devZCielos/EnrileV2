<?php
// ============================================================
//  LGU ENRILE HRMS — Leave Requests Page
//  File: Admin/leave_requests.php
// ============================================================
session_start();
// ── Session timeout — 8 hours ─────────────────────────────────
$_sessionLifetime = 8 * 60 * 60;
if (isset($_SESSION['login_time']) && (time() - strtotime($_SESSION['login_time']) > $_sessionLifetime)) {
    $_isAjax = !empty($_GET['action']) || !empty($_POST['action']);
    session_unset(); session_destroy();
    if ($_isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>false,'message'=>'Your session has expired. Please log in again.']);
        exit();
    }
    header('Location: ../Login/login.php?error=' . urlencode('Your session has expired. Please log in again.'));
    exit();
}

if (empty($_SESSION['user_id'])) {
    header('Location: ../Login/login.php'); exit();
}
$allowedRoles = ['admin','hr_staff','dept_head'];
if (!in_array($_SESSION['user_role'] ?? '', $allowedRoles)) {
    header('Location: ../Employee/Employee_portal.php'); exit();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';

// Initialize CSRF token in session
csrf_token();

function hrms_column_exists(PDO $pdo, string $table, string $column): bool {
  static $cache = [];
  $key = $table . '.' . $column;
  if (array_key_exists($key, $cache)) {
    return $cache[$key];
  }
  $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
  $q->execute([$table, $column]);
  $cache[$key] = ((int)$q->fetchColumn() > 0);
  return $cache[$key];
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action) {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_verify(true); }
    try {
        switch ($action) {

           case 'stats':
    // Exclude dept-head-only stages so these cards match what's actually shown in the list below.
    $s = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(status IN ('Pending','Pending HR')) AS pending,
            SUM(status='Approved')  AS approved,
            SUM(status='Denied')    AS denied,
            SUM(status='Cancelled') AS cancelled
        FROM leave_requests
        WHERE is_deleted = 0 AND status NOT IN ('Pending Dept Head', 'Denied by Dept Head')
    ")->fetch();
    echo json_encode(['success'=>true,'data'=>$s]);
    break;

           case 'pending_count':
    $c = $pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status='Pending' AND is_deleted = 0")->fetchColumn();
    echo json_encode(['count'=>(int)$c]);
    break;
            case 'list':
                $where  = [];
                $params = [];

                // Always exclude deleted requests
                $where[] = 'lr.is_deleted = 0';
                // Dept-head-only stages never reach Admin/HR, so never show them here.
                $where[] = "lr.status NOT IN ('Pending Dept Head', 'Denied by Dept Head')";

                $tab = $_GET['tab'] ?? 'all';
                $status = $_GET['status'] ?? '';

                // If dropdown status is selected, use it; otherwise use tab
                if (!empty($status)) {
                  $where[]  = 'lr.status = ?';
                  $params[] = $status;
                } elseif ($tab !== 'all') {
                  $where[]  = 'lr.status = ?';
                  $params[] = ucfirst($tab);
                }
                
                if (!empty($_GET['search'])) {
                    $s = '%' . $_GET['search'] . '%';
                    $where[]  = "(CONCAT(e.first_name,' ',e.last_name) LIKE ? OR lt.name LIKE ?)";
                    $params   = array_merge($params, [$s, $s]);
                }
                if (!empty($_GET['dept']))  { $where[] = 'd.name = ?';  $params[] = $_GET['dept']; }
                if (!empty($_GET['type']))  { $where[] = 'lt.name = ?'; $params[] = $_GET['type']; }
                if (!empty($_GET['month'])) { $where[] = 'MONTH(lr.date_from) = ?'; $params[] = (int)$_GET['month']; }

                $wSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';


                $sortMap = [
                  'name'   => 'e.last_name, e.first_name',
                  'type'   => 'lt.name',
                  'from'   => 'lr.date_from',
                  'to'     => 'lr.date_to',
                  'days'   => 'lr.days_requested',
                  'filed'  => 'lr.filed_at',
                  'status' => 'lr.status',
                ];
                $sortCol = $sortMap[$_GET['sort'] ?? ''] ?? 'lr.filed_at';
                $sortDir = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

                // Pending requests (including those awaiting HR review) always float to the top, regardless of filter/sort.
                $orderBy = "ORDER BY (lr.status IN ('Pending', 'Pending HR')) DESC, $sortCol $sortDir";

                $page     = max(1, (int)($_GET['page'] ?? 1));
                $pageSize = 8;
                $offset   = ($page - 1) * $pageSize;

                $cntStmt = $pdo->prepare("
                  SELECT COUNT(*)
                  FROM leave_requests lr
                  JOIN employees   e  ON lr.employee_id   = e.employee_id
                  JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id
                  LEFT JOIN departments d ON e.department_id = d.department_id
                  $wSql
                ");
                $cntStmt->execute($params);
                $total = (int)$cntStmt->fetchColumn();

                $stmt = $pdo->prepare("
                  SELECT lr.request_id, lr.status, lr.date_from, lr.date_to,
                       lr.days_requested, lr.reason, lr.admin_remarks,
                       lr.filed_at, lr.updated_at,
                       lr.reviewed_by, lr.reviewed_at,
                       e.employee_id, e.first_name, e.last_name, e.position_title,
                       lt.leave_type_id, lt.name AS leave_type,
                       COALESCE(lb.remaining_days, 0) AS leave_balance,
                       d.name AS department
                  FROM leave_requests lr
                  JOIN employees   e  ON lr.employee_id   = e.employee_id
                  JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id
                  LEFT JOIN leave_balances lb ON lb.employee_id = lr.employee_id
                    AND lb.leave_type_id = lr.leave_type_id
                    AND lb.calendar_year = YEAR(lr.filed_at)
                  LEFT JOIN departments d ON e.department_id = d.department_id
                  $wSql
                  $orderBy
                  LIMIT $pageSize OFFSET $offset
                ");
                $stmt->execute($params);
                $rows = $stmt->fetchAll();

                $counts = $pdo->query("
                  SELECT status, COUNT(*) AS cnt FROM leave_requests
                  WHERE is_deleted = 0 AND status NOT IN ('Pending Dept Head', 'Denied by Dept Head')
                  GROUP BY status
                ")->fetchAll();
                $tabCounts = ['all'=>0,'pending'=>0,'approved'=>0,'denied'=>0,'cancelled'=>0];
                foreach ($counts as $c) {
                    $tabCounts['all'] += $c['cnt'];
                    $tabCounts[strtolower($c['status'])] = (int)$c['cnt'];
                }

                echo json_encode([
                    'success'    => true,
                    'data'       => $rows,
                    'total'      => $total,
                    'page'       => $page,
                    'pages'      => (int)ceil($total / $pageSize),
                    'tab_counts' => $tabCounts,
                ]);
                break;

            case 'get':
                $id   = (int)($_GET['id'] ?? 0);
              $hasEmpSigCols = hrms_column_exists($pdo, 'employees', 'signature_file_path')
                && hrms_column_exists($pdo, 'employees', 'signature_original_name')
                && hrms_column_exists($pdo, 'employees', 'signature_uploaded_at');
              $hasLeaveSigCols = hrms_column_exists($pdo, 'leave_requests', 'signature_file_path')
                && hrms_column_exists($pdo, 'leave_requests', 'signature_original_name')
                && hrms_column_exists($pdo, 'leave_requests', 'signature_uploaded_at');
              $sigSelect = $hasLeaveSigCols
                ? "lr.signature_file_path, lr.signature_original_name, lr.signature_uploaded_at,"
                : "NULL AS signature_file_path, NULL AS signature_original_name, NULL AS signature_uploaded_at,";
              $employeeSigSelect = $hasEmpSigCols
                ? "e.signature_file_path AS employee_signature_file_path,"
                : "NULL AS employee_signature_file_path,";
              $deptHeadSigSelect = $hasEmpSigCols
                ? "dh.signature_file_path AS dept_head_signature_file_path, dh.signature_uploaded_at AS dept_head_signature_uploaded_at,"
                : "NULL AS dept_head_signature_file_path, NULL AS dept_head_signature_uploaded_at,";
                  $stmt = $pdo->prepare("
                      SELECT lr.request_id, lr.status, lr.date_from, lr.date_to,
                        lr.days_requested, lr.reason, lr.admin_remarks,
                        lr.filed_at, lr.reviewed_by, lr.reviewed_at,
                  $sigSelect
                    $employeeSigSelect
                        $deptHeadSigSelect
                        lr.with_pay,
                        e.first_name,
                        COALESCE(e.middle_name, '')  AS middle_name,
                        e.last_name,
                        COALESCE(e.position_title, '') AS position_title,
                        COALESCE(e.salary_grade, '')   AS salary_grade,
                        COALESCE(e.employment_type,'') AS employment_type,
                        lt.name AS leave_type,
                        lt.leave_type_id,
                        COALESCE(d.name, '')          AS department,
                        COALESCE(CONCAT(dh.first_name, ' ', dh.last_name), d.head_name, '') AS dept_head_name,
                        COALESCE(lb.total_credits, 0) AS vl_total,
                        COALESCE(lb.used_days, 0)     AS vl_used,
                        COALESCE(lb.remaining_days, 0)AS vl_remaining
                      FROM leave_requests lr
                      JOIN employees   e  ON lr.employee_id   = e.employee_id
                      JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id
                      LEFT JOIN departments d   ON e.department_id    = d.department_id
                      LEFT JOIN employees dh     ON dh.employee_id     = d.head_employee_id
                      LEFT JOIN leave_balances lb
                        ON lb.employee_id   = lr.employee_id
                       AND lb.leave_type_id = lr.leave_type_id
                       AND lb.calendar_year = YEAR(lr.date_from)
                      WHERE lr.request_id = ?
                  ");
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if ($row) {
                  $snapshotPath = trim((string)($row['signature_file_path'] ?? ''));
                  if ($snapshotPath !== '') {
                    $snapshotFile = __DIR__ . '/../' . ltrim(str_replace('../', '', $snapshotPath), '/');
                    if (!is_file($snapshotFile)) {
                      $row['signature_file_path'] = $row['employee_signature_file_path'] ?? null;
                    }
                  }
                    // Fetch the HR department head details for 7.A certification
                    if ($hasEmpSigCols) {
                        $hrHeadStmt = $pdo->query("
                            SELECT
                              COALESCE(CONCAT(he.first_name, ' ', he.last_name), d.head_name, '') AS hr_head_name,
                              he.signature_file_path AS hr_head_signature_file_path,
                              he.signature_uploaded_at AS hr_head_signature_uploaded_at
                            FROM departments d
                            LEFT JOIN employees he ON he.employee_id = d.head_employee_id
                            WHERE LOWER(d.name) REGEXP 'human.?resource|hr.?office|hrmo'
                              AND d.is_active = 1
                            ORDER BY d.department_id ASC
                            LIMIT 1
                        ");
                    } else {
                        $hrHeadStmt = $pdo->query("
                            SELECT
                              COALESCE(CONCAT(he.first_name, ' ', he.last_name), d.head_name, '') AS hr_head_name,
                              NULL AS hr_head_signature_file_path,
                              NULL AS hr_head_signature_uploaded_at
                            FROM departments d
                            LEFT JOIN employees he ON he.employee_id = d.head_employee_id
                            WHERE LOWER(d.name) REGEXP 'human.?resource|hr.?office|hrmo'
                              AND d.is_active = 1
                            ORDER BY d.department_id ASC
                            LIMIT 1
                        ");
                    }
                    $hrHeadRow = $hrHeadStmt ? $hrHeadStmt->fetch(PDO::FETCH_ASSOC) : null;
                    $row['hr_head_name'] = trim((string)($hrHeadRow['hr_head_name'] ?? ''));
                    $row['hr_head_signature_file_path'] = $hrHeadRow['hr_head_signature_file_path'] ?? null;
                    $row['hr_head_signature_uploaded_at'] = $hrHeadRow['hr_head_signature_uploaded_at'] ?? null;
                }
                echo json_encode($row
                    ? ['success'=>true,'data'=>$row]
                    : ['success'=>false,'message'=>'Not found.']);
                break;

            case 'save':
                $empId       = (int)($_POST['employee_id']   ?? 0);
                $leaveTypeId = (int)($_POST['leave_type_id'] ?? 0);
                $dateFrom    = $_POST['date_from']   ?? '';
                $dateTo      = $_POST['date_to']     ?? '';
                $days        = (int)($_POST['days']  ?? 0);
                $reason      = trim($_POST['reason'] ?? '');

                if (!$empId || !$leaveTypeId || !$dateFrom || !$dateTo || !$days) {
                    echo json_encode(['success'=>false,'message'=>'Please fill in all required fields.']);
                    break;
                }

                $stmt = $pdo->prepare("
                    INSERT INTO leave_requests
                        (employee_id, leave_type_id, date_from, date_to, days_requested, reason, status, filed_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'Pending', NOW(), NOW())
                ");
                $stmt->execute([$empId, $leaveTypeId, $dateFrom, $dateTo, $days, $reason ?: null]);
                echo json_encode(['success'=>true,'message'=>'Leave request submitted successfully.']);
                break;

            case 'approve':
              if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'hr_staff'], true)) {
                echo json_encode(['success'=>false,'message'=>'Only HR/Admin can approve leave requests.']);
                break;
              }
                $id      = (int)($_POST['id']     ?? 0);
                $remark  = trim($_POST['remark']  ?? '') ?: 'Approved.';
                $adminId = $_SESSION['user_id'];

              try {
                $pdo->beginTransaction();
                $req = $pdo->prepare("SELECT * FROM leave_requests WHERE request_id = ? AND status = 'Pending HR' AND is_deleted = 0 FOR UPDATE");
                $req->execute([$id]);
                $r = $req->fetch();
                if (!$r) {
                  $pdo->rollBack();
                  echo json_encode(['success'=>false,'message'=>'Request must be forwarded by the department head before HR approval.']);
                  break;
                }

                $leaveYear = (int)date('Y', strtotime($r['date_from']));
                $balStmt = $pdo->prepare("SELECT remaining_days FROM leave_balances WHERE employee_id=? AND leave_type_id=? AND calendar_year=? FOR UPDATE");
                $balStmt->execute([$r['employee_id'], $r['leave_type_id'], $leaveYear]);
                $balance = $balStmt->fetchColumn();
                if ($balance === false) {
                  $pdo->rollBack();
                  echo json_encode(['success'=>false,'message'=>'Leave balance record not found; approval aborted.']);
                  break;
                }
                if ((float)$balance < (float)$r['days_requested']) {
                  $pdo->rollBack();
                  echo json_encode(['success'=>false,'message'=>'Insufficient leave balance. Employee has ' . (float)$balance . ' day(s) remaining.']);
                  break;
                }

                $overlapStmt = $pdo->prepare("SELECT COUNT(*) FROM leave_requests WHERE employee_id=? AND request_id!=? AND is_deleted=0 AND status='Approved' AND date_from <= ? AND date_to >= ? FOR UPDATE");
                $overlapStmt->execute([$r['employee_id'], $id, $r['date_to'], $r['date_from']]);
                if ((int)$overlapStmt->fetchColumn() > 0) {
                  $pdo->rollBack();
                  echo json_encode(['success'=>false,'message'=>'Overlap detected: employee already has an approved leave covering these dates.']);
                  break;
                }

                $pdo->prepare("UPDATE leave_requests SET status='Approved', admin_remarks=?, reviewed_by=?, reviewed_at=NOW(), updated_at=NOW() WHERE request_id=?")
                  ->execute([$remark, $adminId, $id]);
                $pdo->prepare("UPDATE leave_balances SET used_days = used_days + ? WHERE employee_id=? AND leave_type_id=? AND calendar_year=?")
                  ->execute([$r['days_requested'], $r['employee_id'], $r['leave_type_id'], $leaveYear]);
                try {
                  $pdo->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, 'APPROVE_LEAVE', ?)")
                    ->execute([$adminId, "Approved leave request #$id"]);
                } catch (PDOException $ignored) {}
                $pdo->commit();
                echo json_encode(['success'=>true,'message'=>'Leave request approved.']);
              } catch (Throwable $approvalError) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                throw $approvalError;
              }
                break;

            case 'deny':
              if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'hr_staff'], true)) {
                echo json_encode(['success'=>false,'message'=>'Only HR/Admin can deny leave requests.']);
                break;
              }
                $id      = (int)($_POST['id']    ?? 0);
                $remark  = trim($_POST['remark'] ?? '') ?: 'Request denied.';
                $adminId = $_SESSION['user_id'];


                $req = $pdo->prepare("SELECT * FROM leave_requests WHERE request_id=? AND status='Pending HR' AND is_deleted=0");
                $req->execute([$id]);
                if (!$req->fetch()) { echo json_encode(['success'=>false,'message'=>'Request must be forwarded by the department head before HR action.']); break; }

                $pdo->prepare("
                    UPDATE leave_requests
                    SET status='Denied', admin_remarks=?, reviewed_by=?, reviewed_at=NOW(), updated_at=NOW()
                    WHERE request_id=?
                ")->execute([$remark, $adminId, $id]);

                try {
                    $pdo->prepare("
                        INSERT INTO activity_logs (user_id, action, description)
                        VALUES (?, 'DENY_LEAVE', ?)
                    ")->execute([$adminId, "Denied leave request #$id"]);
                } catch (PDOException $ignored) {}

                echo json_encode(['success'=>true,'message'=>'Leave request denied.']);
                break;

            case 'cancel':
                $role = $_SESSION['user_role'] ?? '';
                if (!in_array($role, ['admin', 'hr_staff'], true)) {
                    echo json_encode(['success'=>false,'message'=>'Only HR/Admin can cancel approved leave requests.']);
                    break;
                }

                $id      = (int)($_POST['id'] ?? 0);
                $reason  = trim($_POST['reason'] ?? '');
                $adminId = $_SESSION['user_id'];

                if (!$id) {
                    echo json_encode(['success'=>false,'message'=>'Invalid request ID.']);
                    break;
                }
                if ($reason === '') {
                    echo json_encode(['success'=>false,'message'=>'Cancellation reason is required.']);
                    break;
                }

                try {
                    $pdo->beginTransaction();

                    $reqStmt = $pdo->prepare("SELECT request_id, employee_id, leave_type_id, date_from, days_requested FROM leave_requests WHERE request_id = ? AND status = 'Approved' AND is_deleted = 0 FOR UPDATE");
                    $reqStmt->execute([$id]);
                    $r = $reqStmt->fetch();

                    if (!$r) {
                        $pdo->rollBack();
                        echo json_encode(['success'=>false,'message'=>'Only approved leave requests can be cancelled.']);
                        break;
                    }

                    $leaveYear = (int)date('Y', strtotime($r['date_from']));
                    $balStmt = $pdo->prepare("SELECT used_days FROM leave_balances WHERE employee_id = ? AND leave_type_id = ? AND calendar_year = ? FOR UPDATE");
                    $balStmt->execute([$r['employee_id'], $r['leave_type_id'], $leaveYear]);
                    $usedDays = $balStmt->fetchColumn();

                    if ($usedDays === false) {
                        $pdo->rollBack();
                        echo json_encode(['success'=>false,'message'=>'Leave balance record not found; cancellation aborted.']);
                        break;
                    }

                    if ((float)$usedDays < (float)$r['days_requested']) {
                        $pdo->rollBack();
                        echo json_encode(['success'=>false,'message'=>'Cancellation blocked: restoring credits would result in negative used days.']);
                        break;
                    }

                    $pdo->prepare("UPDATE leave_balances SET used_days = used_days - ? WHERE employee_id = ? AND leave_type_id = ? AND calendar_year = ?")
                        ->execute([$r['days_requested'], $r['employee_id'], $r['leave_type_id'], $leaveYear]);

                    $cancelRemark = 'Cancelled: ' . $reason;
                    $pdo->prepare("UPDATE leave_requests SET status='Cancelled', admin_remarks=?, reviewed_by=?, reviewed_at=NOW(), updated_at=NOW() WHERE request_id=?")
                        ->execute([$cancelRemark, $adminId, $id]);

                    // Per current requirement, keep leave_notifications unchanged on cancellation.
                    try {
                        $pdo->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, 'CANCEL_LEAVE', ?)")
                            ->execute([$adminId, "Cancelled leave request #$id and restored {$r['days_requested']} day(s). Reason: $reason"]);
                    } catch (PDOException $ignored) {}

                    $pdo->commit();
                    echo json_encode(['success'=>true,'message'=>'Leave request cancelled and credits restored.']);
                } catch (Throwable $txErr) {
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                    throw $txErr;
                }
                break;

            case 'employees':
                $emps = $pdo->query("
                    SELECT employee_id, CONCAT(first_name,' ',last_name) AS full_name
                    FROM employees WHERE employment_status='Active' ORDER BY last_name, first_name
                ")->fetchAll();
                echo json_encode(['success'=>true,'data'=>$emps]);
                break;

            case 'leave_types':
                $lts = $pdo->query("
                    SELECT leave_type_id, name FROM leave_types ORDER BY name
                ")->fetchAll();
                echo json_encode(['success'=>true,'data'=>$lts]);
                break;

            case 'departments':
                $depts = $pdo->query("SELECT name FROM departments WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
                echo json_encode(['success'=>true,'data'=>$depts]);
                break;

            case 'delete':
                $id = (int)($_POST['id'] ?? 0);
                if (!$id) {
                    echo json_encode(['success'=>false,'message'=>'Invalid request ID.']);
                    break;
                }
                // Soft delete: set is_deleted = 1
                $stmt = $pdo->prepare("UPDATE leave_requests SET is_deleted = 1, updated_at = NOW() WHERE request_id = ?");
                $stmt->execute([$id]);
                if ($stmt->rowCount() > 0) {
                    echo json_encode(['success'=>true,'message'=>'Leave request deleted.']);
                } else {
                    echo json_encode(['success'=>false,'message'=>'Delete failed or already deleted.']);
                }
                break;

            default:
                echo json_encode(['success'=>false,'message'=>'Unknown action.']);
        }
    } catch (PDOException $e) {
        error_log('[HRMS] leave_requests.php: ' . $e->getMessage());
        echo json_encode(['success'=>false,'message'=>'A database error occurred.']);
    }
    exit();
}

$adminName  = ucwords($_SESSION['user_name'] ?? 'Administrator');
$roleLabels = ['admin'=>'Super Admin','hr_staff'=>'HR Staff','dept_head'=>'Department Head'];
$roleLabel  = $roleLabels[$_SESSION['user_role']] ?? 'Admin';
$canCancelApproved = in_array($_SESSION['user_role'] ?? '', ['admin', 'hr_staff'], true);
// ── Mayor / Authorized Official (CS Form 6) ─────────────────────────────────────
$_mayorRow   = null;
try { $_mayorRow = $pdo->query("SELECT first_name, last_name, position_title FROM employees WHERE is_mayor=1 AND employment_status='Active' LIMIT 1")->fetch(PDO::FETCH_ASSOC); } catch (Exception $_e) {}
$mayorName  = $_mayorRow ? 'HON. ' . strtoupper(trim($_mayorRow['first_name'] . ' ' . $_mayorRow['last_name'])) : 'HON. MIGUEL B. DECENA, JR.';
$mayorTitle = $_mayorRow ? ($_mayorRow['position_title'] ?: 'Municipal Mayor') : 'Municipal Mayor';
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
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <?= csrf_meta() ?>
  <title>Leave Requests | LGU Enrile HRMS</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root{--primary:#2563eb;--primary-dark:#1e40af;--accent:#22c55e;--secondary:#0f172a;--sidebar-w:265px;--topbar-h:68px;--gray-50:#f8fafc;--gray-100:#f1f5f9;--gray-200:#e2e8f0;--gray-400:#94a3b8;--gray-600:#475569;--gray-800:#1e293b;--white:#ffffff;--shadow-sm:0 1px 4px rgba(0,0,0,0.07);--shadow-md:0 4px 18px rgba(0,0,0,0.11);--shadow-lg:0 8px 32px rgba(0,0,0,0.16);--radius:14px;--radius-sm:9px;}
    *{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'Nunito',sans-serif;background:var(--gray-100);color:var(--secondary);display:flex;min-height:100vh;}
    .sidebar{width:var(--sidebar-w);background:linear-gradient(180deg,#2563eb 0%,#1e40af 55%,#22c55e 100%);display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:200;transition:transform 0.3s;box-shadow:4px 0 28px rgba(0,0,0,0.20);max-width:100vw;}
    .sidebar-brand{padding:22px 20px 18px;display:flex;align-items:center;gap:12px;border-bottom:1px solid rgba(255,255,255,0.15);}
    .brand-logo{width:44px;height:44px;border-radius:50%;object-fit:cover;border:2.5px solid rgba(255,255,255,0.70);}
    .brand-text h2{font-family:'Playfair Display',serif;font-size:1rem;color:#fff;line-height:1.2;}
    .brand-text span{font-size:0.68rem;color:rgba(255,255,255,0.60);font-weight:700;text-transform:uppercase;letter-spacing:0.10em;}
    .sidebar-nav{flex:1;padding:14px 12px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:rgba(255,255,255,0.20) transparent;}
    .nav-label{font-size:0.63rem;font-weight:800;color:rgba(255,255,255,0.38);text-transform:uppercase;letter-spacing:0.16em;padding:10px 10px 5px;margin-top:6px;}
    .nav-item{display:flex;align-items:center;gap:11px;padding:9px 12px;border-radius:var(--radius-sm);color:rgba(255,255,255,0.75);text-decoration:none;font-size:0.875rem;font-weight:600;transition:all 0.2s;margin-bottom:2px;position:relative;}
    .nav-item:hover{background:rgba(255,255,255,0.13);color:#fff;}
    .nav-item.active{background:rgba(255,255,255,0.22);color:#fff;}
    .nav-item.active::before{content:'';position:absolute;left:0;top:20%;bottom:20%;width:3px;border-radius:2px;background:#fff;}
    .nav-icon{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:0.95rem;background:rgba(255,255,255,0.10);flex-shrink:0;}
    .nav-item.active .nav-icon{background:rgba(255,255,255,0.25);}
    .nav-badge{margin-left:auto;background:#ef4444;color:white;font-size:0.60rem;font-weight:800;padding:2px 6px;border-radius:20px;}
    .sidebar-footer{padding:14px 12px;border-top:1px solid rgba(255,255,255,0.13);}
    .sidebar-user{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:var(--radius-sm);background:rgba(255,255,255,0.10);}
    .user-avatar{width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,0.25);border:2px solid rgba(255,255,255,0.50);display:flex;align-items:center;justify-content:center;font-weight:800;color:#fff;font-size:0.80rem;flex-shrink:0;}
    .user-info{flex:1;min-width:0;}
    .user-info strong{display:block;font-size:0.82rem;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .user-info span{font-size:0.68rem;color:rgba(255,255,255,0.52);}
    .main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;overflow:hidden;}
    .topbar{height:var(--topbar-h);background:linear-gradient(135deg,#2563eb 0%,#1e40af 50%,#22c55e 100%);display:flex;align-items:center;padding:0 20px;gap:12px;position:sticky;top:0;z-index:100;box-shadow:0 2px 16px rgba(37,99,235,0.30);}
    .menu-toggle{display:none;background:none;border:none;font-size:1.4rem;cursor:pointer;color:rgba(255,255,255,0.85);padding:4px;flex-shrink:0;}
    .topbar-title{flex:1;min-width:0;overflow:hidden;}
    .topbar-title h1{font-size:1.20rem;font-weight:800;color:#fff;font-family:'Playfair Display',serif;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .topbar-title p{font-size:0.76rem;color:rgba(255,255,255,0.70);margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .topbar-actions{display:flex;align-items:center;gap:6px;flex-shrink:0;}
    .topbar-btn{position:relative;width:38px;height:38px;border-radius:50%;border:1px solid rgba(255,255,255,0.25);background:rgba(255,255,255,0.15);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:1rem;color:#fff;transition:all 0.2s;}
    .topbar-btn:hover{background:rgba(255,255,255,0.28);}
    .notif-badge{position:absolute;top:-2px;right:-2px;width:16px;height:16px;background:#ef4444;border-radius:50%;font-size:0.58rem;color:white;font-weight:800;display:flex;align-items:center;justify-content:center;border:2px solid rgba(255,255,255,0.3);}
    .content{padding:24px 28px;flex:1;overflow-x:hidden;}
    .stat-cards{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:22px;}
    .stat-card{background:var(--white);border-radius:var(--radius);padding:18px 20px;border:1.5px solid var(--gray-200);box-shadow:var(--shadow-sm);position:relative;overflow:hidden;animation:fadeIn 0.4s ease both;transition:transform 0.2s,box-shadow 0.2s;}
    .stat-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-md);}
    .stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;border-radius:var(--radius) var(--radius) 0 0;}
    .stat-card.orange::before{background:linear-gradient(90deg,#f59e0b,#fcd34d);}
    .stat-card.green::before{background:linear-gradient(90deg,#22c55e,#86efac);}
    .stat-card.red::before{background:linear-gradient(90deg,#ef4444,#fca5a5);}
    .stat-card.blue::before{background:linear-gradient(90deg,#2563eb,#60a5fa);}
    .stat-card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;}
    .stat-icon{width:44px;height:44px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;}
    .stat-card.orange .stat-icon{background:#fef3c7;}
    .stat-card.green  .stat-icon{background:#dcfce7;}
    .stat-card.red    .stat-icon{background:#fee2e2;}
    .stat-card.blue   .stat-icon{background:#dbeafe;}
    .stat-value{font-size:2rem;font-weight:800;color:var(--gray-800);line-height:1;margin-bottom:3px;}
    .stat-label{font-size:0.78rem;color:var(--gray-400);font-weight:600;}
    .stat-sub{font-size:0.70rem;color:var(--gray-400);margin-top:6px;}
    .tabs{display:flex;gap:0;background:var(--white);border-radius:var(--radius-sm);border:1.5px solid var(--gray-200);overflow:hidden;box-shadow:var(--shadow-sm);margin-bottom:16px;width:fit-content;}
    .tab-btn{padding:9px 20px;border:none;background:transparent;font-family:'Nunito',sans-serif;font-size:0.84rem;font-weight:700;color:var(--gray-400);cursor:pointer;transition:all 0.2s;display:flex;align-items:center;gap:6px;border-right:1px solid var(--gray-200);white-space:nowrap;}
    .tab-btn:last-child{border-right:none;}
    .tab-btn.active{background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:white;}
    .tab-btn .count{background:rgba(255,255,255,0.25);padding:1px 6px;border-radius:10px;font-size:0.65rem;font-weight:800;}
    .tab-btn:not(.active) .count{background:var(--gray-100);color:var(--gray-600);}
    .toolbar{display:flex;align-items:center;gap:8px;margin-bottom:16px;flex-wrap:wrap;overflow:visible;}
    .search-box{display:flex;align-items:center;gap:8px;background:var(--white);border:1.5px solid var(--gray-200);border-radius:var(--radius-sm);padding:9px 12px;flex:1 1 auto;min-width:160px;max-width:100%;box-shadow:var(--shadow-sm);transition:border-color 0.2s;}
    .search-box:focus-within{border-color:var(--primary);}
    .search-box input{border:none;outline:none;font-family:'Nunito',sans-serif;font-size:0.875rem;width:100%;background:transparent;color:var(--gray-800);}
    .search-box input::placeholder{color:var(--gray-400);}
    .filter-select{padding:8px 10px;border:1.5px solid var(--gray-200);border-radius:var(--radius-sm);font-family:'Nunito',sans-serif;font-size:0.80rem;color:var(--gray-600);background:var(--white);cursor:pointer;box-shadow:var(--shadow-sm);outline:none;transition:border-color 0.2s;white-space:nowrap;flex:0 1 auto;min-width:100px;}
    .filter-select:focus{border-color:var(--primary);}
    .add-btn{display:flex;align-items:center;justify-content:center;gap:6px;padding:8px 14px;background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:white;border:none;border-radius:var(--radius-sm);font-family:'Nunito',sans-serif;font-size:0.80rem;font-weight:700;cursor:pointer;transition:all 0.2s;box-shadow:0 2px 8px rgba(37,99,235,0.30);white-space:nowrap;flex:0 1 auto;}
    .add-btn:hover{transform:translateY(-2px);box-shadow:0 4px 14px rgba(37,99,235,0.40);}
    .panel{background:var(--white);border-radius:var(--radius);border:1.5px solid var(--gray-200);box-shadow:var(--shadow-sm);overflow:hidden;animation:fadeIn 0.4s ease both;}
    .table-wrap{overflow-x:auto;}
    table{width:100%;border-collapse:collapse;font-size:0.83rem;}
    thead tr{background:var(--gray-50);}
    th{padding:11px 16px;text-align:left;font-size:0.69rem;font-weight:800;color:var(--gray-400);text-transform:uppercase;letter-spacing:0.07em;border-bottom:1.5px solid var(--gray-200);white-space:nowrap;cursor:pointer;-webkit-user-select:none;user-select:none;transition:color 0.2s;}
    th:hover{color:var(--primary);}
    td{padding:13px 16px;border-bottom:1px solid var(--gray-100);color:var(--gray-800);vertical-align:middle;}
    tr:last-child td{border-bottom:none;}
    tbody tr{transition:background 0.15s;}
    tbody tr:hover{background:#f0f6ff;}
    .emp-cell{display:flex;align-items:center;gap:10px;}
    .emp-avatar{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:0.75rem;font-weight:800;color:white;flex-shrink:0;box-shadow:0 2px 5px rgba(0,0,0,0.14);}
    .emp-name{font-weight:700;font-size:0.875rem;}
    .emp-dept{font-size:0.70rem;color:var(--gray-400);margin-top:1px;}
    .badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:0.70rem;font-weight:700;white-space:nowrap;}
    .b-pending{background:#fef3c7;color:#92400e;}
    .b-approved{background:#dcfce7;color:#166534;}
    .b-denied{background:#fee2e2;color:#991b1b;}
    .b-cancelled{background:#f1f5f9;color:#64748b;}
    .b-vacation{background:#dbeafe;color:#1e40af;}
    .b-sick{background:#fef3c7;color:#92400e;}
    .b-emergency{background:#fee2e2;color:#991b1b;}
    .b-maternity{background:#fce7f3;color:#9d174d;}
    .b-paternity{background:#dcfce7;color:#166534;}
    .b-special{background:#f3e8ff;color:#6b21a8;}
    .days-pill{display:inline-block;padding:3px 10px;background:var(--gray-100);border-radius:20px;font-size:0.72rem;font-weight:800;color:var(--gray-600);}
    .row-actions{display:flex;gap:5px;align-items:center;}
    .view-btn{padding:5px 14px;border-radius:7px;border:1.5px solid var(--primary);background:#eff6ff;color:var(--primary);font-family:'Nunito',sans-serif;font-size:.78rem;font-weight:700;cursor:pointer;transition:all .2s;}
    .view-btn:hover{background:var(--primary);color:#fff;}
    .act-btn{padding:5px 11px;border-radius:7px;border:none;font-size:0.72rem;font-weight:700;cursor:pointer;transition:all 0.2s;font-family:'Nunito',sans-serif;white-space:nowrap;}
    .act-btn.approve{background:#dcfce7;color:#166534;}
    .act-btn.approve:hover{background:#22c55e;color:white;}
    .act-btn.deny{background:#fee2e2;color:#991b1b;}
    .act-btn.deny:hover{background:#ef4444;color:white;}
    .act-btn.cancel{background:#fff7ed;color:#9a3412;}
    .act-btn.cancel:hover{background:#f97316;color:#fff;}
    .icon-btn{width:30px;height:30px;border-radius:7px;border:1.5px solid var(--gray-200);background:var(--white);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:0.82rem;transition:all 0.2s;}
    .icon-btn.view:hover{border-color:var(--primary);background:#eff6ff;}
    .processed-label{font-size:0.72rem;color:var(--gray-400);font-weight:700;}
    .pagination{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-top:1px solid var(--gray-100);font-size:0.80rem;color:var(--gray-400);flex-wrap:wrap;gap:10px;font-weight:600;}
    .page-btns{display:flex;gap:4px;}
    .page-btn{min-width:32px;height:32px;border-radius:7px;border:1.5px solid var(--gray-200);background:var(--white);cursor:pointer;font-size:0.80rem;font-weight:700;display:flex;align-items:center;justify-content:center;padding:0 8px;transition:all 0.2s;color:var(--gray-600);font-family:'Nunito',sans-serif;}
    .page-btn:hover{border-color:var(--primary);color:var(--primary);}
    .page-btn.active{background:var(--primary);border-color:var(--primary);color:white;}
    .page-btn:disabled{opacity:0.4;cursor:default;}
    .empty-state{padding:52px 20px;text-align:center;}
    .empty-state .ei{font-size:2.8rem;margin-bottom:12px;opacity:0.5;}
    .empty-state p{font-size:0.875rem;font-weight:600;color:var(--gray-400);}
    .modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:500;align-items:center;justify-content:center;-webkit-backdrop-filter:blur(4px);backdrop-filter:blur(4px);}
    .modal-overlay.open{display:flex;}
    .modal{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow-lg);width:92%;max-width:580px;max-height:92vh;overflow-y:auto;animation:modalIn 0.3s cubic-bezier(0.34,1.4,0.64,1) both;}
    .modal.sm{max-width:440px;}
    @keyframes modalIn{from{opacity:0;transform:scale(0.88) translateY(20px);}to{opacity:1;transform:scale(1) translateY(0);}}
    .modal-header{display:flex;align-items:center;justify-content:space-between;padding:20px 24px 16px;background:linear-gradient(135deg,#2563eb,#1e40af);border-radius:var(--radius) var(--radius) 0 0;}
    .modal-header.green{background:linear-gradient(135deg,#22c55e,#15803d);}
    .modal-header.red{background:linear-gradient(135deg,#ef4444,#dc2626);}
    .modal-header h3{font-size:1.05rem;font-weight:800;color:#fff;font-family:'Playfair Display',serif;}
    .modal-header p{font-size:0.74rem;color:rgba(255,255,255,0.65);margin-top:2px;}
    .modal-close{width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,0.18);border:none;color:#fff;font-size:1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background 0.2s;flex-shrink:0;}
    .modal-close:hover{background:rgba(255,255,255,0.32);}
    .modal-body{padding:22px 24px;}
    .modal-footer{display:flex;justify-content:flex-end;gap:10px;padding:14px 24px 18px;border-top:1px solid var(--gray-100);}
    .form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;}
    .form-full{grid-column:1/-1;}
    .form-group{display:flex;flex-direction:column;gap:5px;}
    .form-group label{font-size:0.76rem;font-weight:700;color:var(--gray-600);}
    .form-group label .req{color:#ef4444;margin-left:2px;}
    .form-group input,.form-group select,.form-group textarea{padding:9px 12px;border:1.5px solid var(--gray-200);border-radius:var(--radius-sm);font-size:0.875rem;font-family:'Nunito',sans-serif;color:var(--gray-800);background:var(--white);outline:none;transition:border-color 0.2s;}
    .form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(37,99,235,0.10);}
    .form-group input.err,.form-group select.err{border-color:#ef4444;}
    .form-group textarea{resize:vertical;height:80px;}
    .detail-hero{background:linear-gradient(135deg,#2563eb 0%,#1e40af 60%,#22c55e 100%);padding:24px;border-radius:var(--radius) var(--radius) 0 0;display:flex;align-items:center;gap:16px;}
    .detail-avatar{width:62px;height:62px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.3rem;font-weight:800;color:white;border:3px solid rgba(255,255,255,0.70);box-shadow:0 4px 14px rgba(0,0,0,0.20);flex-shrink:0;}
    .detail-info h3{font-size:1.05rem;font-weight:800;color:#fff;font-family:'Playfair Display',serif;}
    .detail-info p{font-size:0.76rem;color:rgba(255,255,255,0.65);margin-top:2px;}
    .detail-tags{display:flex;gap:6px;margin-top:8px;flex-wrap:wrap;}
    .dtag{padding:3px 10px;border-radius:20px;font-size:0.70rem;font-weight:700;background:rgba(255,255,255,0.22);color:#fff;}
    .detail-close{width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,0.18);border:none;color:#fff;font-size:1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;margin-left:auto;align-self:flex-start;}
    .detail-close:hover{background:rgba(255,255,255,0.32);}
    .detail-body{padding:20px 24px;}
    .detail-section{font-size:0.72rem;font-weight:800;color:var(--primary);text-transform:uppercase;letter-spacing:0.10em;margin:16px 0 10px;padding-bottom:5px;border-bottom:1.5px solid #dbeafe;}
    .detail-section:first-child{margin-top:0;}
    .detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
    .detail-field{background:var(--gray-50);border-radius:var(--radius-sm);padding:10px 14px;border:1px solid var(--gray-100);}
    .detail-field label{font-size:0.65rem;font-weight:800;color:var(--gray-400);text-transform:uppercase;letter-spacing:0.07em;display:block;margin-bottom:3px;}
    .detail-field span{font-size:0.84rem;font-weight:700;color:var(--gray-800);}
    .detail-field.full{grid-column:1/-1;}
    .remarks-box{background:#fef3c7;border:1.5px solid #fcd34d;border-radius:var(--radius-sm);padding:12px 14px;margin-top:14px;}
    .remarks-box .remarks-label{font-size:0.70rem;font-weight:800;color:#92400e;text-transform:uppercase;letter-spacing:0.07em;margin-bottom:4px;}
    .remarks-box p{font-size:0.82rem;color:#78350f;line-height:1.55;}
    .confirm-body{padding:28px 24px;text-align:center;}
    .confirm-icon{font-size:2.6rem;margin-bottom:12px;}
    .confirm-body h4{font-size:1rem;font-weight:800;color:var(--gray-800);margin-bottom:6px;}
    .confirm-body p{font-size:0.84rem;color:var(--gray-600);line-height:1.6;}
    .confirm-reason{width:100%;margin-top:14px;padding:9px 12px;border:1.5px solid var(--gray-200);border-radius:var(--radius-sm);font-size:0.84rem;font-family:'Nunito',sans-serif;color:var(--gray-800);resize:none;height:72px;outline:none;}
    .confirm-reason:focus{border-color:var(--primary);}
    .btn{padding:9px 22px;border-radius:var(--radius-sm);font-family:'Nunito',sans-serif;font-size:0.875rem;font-weight:700;cursor:pointer;transition:all 0.2s;border:none;}
    .btn-cancel{background:var(--white);border:1.5px solid var(--gray-200);color:var(--gray-600);}
    .btn-cancel:hover{border-color:var(--gray-400);background:var(--gray-50);}
    .btn-primary{background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:white;box-shadow:0 2px 8px rgba(37,99,235,0.28);}
    .btn-primary:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(37,99,235,0.38);}
    .btn-success{background:linear-gradient(135deg,#22c55e,#15803d);color:white;}
    .btn-success:hover{transform:translateY(-1px);}
    .btn-danger{background:linear-gradient(135deg,#ef4444,#dc2626);color:white;}
    .btn-danger:hover{transform:translateY(-1px);}
    .toast{position:fixed;bottom:28px;right:28px;padding:12px 20px;border-radius:var(--radius-sm);font-size:0.84rem;font-weight:700;box-shadow:var(--shadow-lg);z-index:900;color:white;animation:fadeIn 0.3s ease both;max-width:340px;}
    .toast.success{background:linear-gradient(135deg,#22c55e,#16a34a);}
    .toast.error{background:linear-gradient(135deg,#ef4444,#dc2626);}
    .toast.info{background:linear-gradient(135deg,#2563eb,#1e40af);}
    @keyframes fadeIn{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);}}
    .overlay-mob{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:150;}
    .overlay-mob.show{display:block;}
    @media(max-width:1100px){.stat-cards{grid-template-columns:repeat(2,1fr)}.tabs{flex-wrap:wrap;width:100%}.tab-btn{flex:1;justify-content:center;}}
    /* ── CS Form 6 styles ── */
    .csf-toolbar{display:flex;align-items:center;justify-content:space-between;padding:12px 18px;background:var(--gray-50);border-bottom:1.5px solid var(--gray-200);border-radius:var(--radius) var(--radius) 0 0;}
    .btn-ghost-sm{padding:6px 14px;border-radius:7px;border:1.5px solid var(--gray-200);background:var(--white);font-family:'Nunito',sans-serif;font-size:.80rem;font-weight:700;color:var(--gray-600);cursor:pointer;transition:all .2s;}
    .btn-ghost-sm:hover{border-color:var(--primary);color:var(--primary);background:#eff6ff;}
    .csf-paper{font-family:'Times New Roman',Times,serif;font-size:.72rem;color:#000;padding:16px 20px;background:#fff;}
    .csf-header-row{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;margin-bottom:6px;}
    .csf-header-left{font-size:.62rem;line-height:1.6;}
    .csf-header-center{display:flex;align-items:center;gap:10px;justify-content:center;}
    .csf-logo-box{width:58px;height:58px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
    .csf-logo-box img{width:58px;height:58px;object-fit:contain;border-radius:50%;}
    .csf-header-right{text-align:right;font-size:.62rem;}
    .csf-stamp-box{border:1px solid #999;padding:6px 10px;font-size:.60rem;display:inline-block;margin-top:4px;min-width:100px;text-align:center;}
    .csf-title{text-align:center;font-size:1rem;font-weight:700;letter-spacing:.06em;margin:6px 0 8px;text-transform:uppercase;}
    .csf-tbl{width:100%;border-collapse:collapse;margin-bottom:0;}
    .csf-td{border:1px solid #000;padding:4px 6px;vertical-align:top;}
    .csf-section-bar{background:#d0d0d0;border:1px solid #000;border-top:none;padding:3px 8px;font-weight:700;font-size:.75rem;text-align:center;letter-spacing:.04em;}
    .csf-field-label{font-size:.60rem;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:#333;margin-bottom:2px;}
    .csf-field-val{font-size:.78rem;font-weight:600;min-height:16px;}
    .csf-name-cell{padding:3px 6px;}
    .csf-name-sub{font-size:.58rem;text-align:center;color:#555;margin-top:1px;}
    .csf-checklist{display:flex;flex-direction:column;gap:2px;}
    .csf-check-row{display:flex;align-items:flex-start;gap:5px;font-size:.68rem;line-height:1.4;margin-bottom:1px;}
    .csf-chk{display:inline-block;width:11px;height:11px;border:1px solid #000;flex-shrink:0;margin-top:1px;background:#fff;position:relative;}
    .csf-chk.checked::after{content:'✓';position:absolute;top:-3px;left:0;font-size:11px;font-weight:900;color:#000;line-height:1;}
    .csf-chk.csf-chk-x::after{content:'✕';position:absolute;top:-3px;left:0;font-size:10px;font-weight:900;color:#000;line-height:1;}
    .csf-detail-group{margin-bottom:6px;}
    .csf-detail-sub{font-size:.65rem;font-style:italic;font-weight:600;margin-bottom:2px;}
    /* Print styles */
    @media print {
      body > *:not(#printTarget) { display:none !important; }
      .no-print { display:none !important; }
      .modal-overlay { position:static !important; background:none !important; display:block !important; }
      .modal { box-shadow:none !important; max-width:100% !important; width:100% !important; border-radius:0 !important; }
      .csf-paper { padding:0 !important; }
      @page { size:A4; margin:15mm; }
    }

    @media(max-width:768px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.main{margin-left:0}.menu-toggle{display:block}.content{padding:14px 12px}.topbar{padding:0 14px}.stat-cards{grid-template-columns:1fr 1fr;gap:10px}.tabs{overflow-x:auto;flex-wrap:nowrap;width:100%}.tab-btn{flex-shrink:0;font-size:0.80rem;padding:8px 14px}.toolbar{flex-direction:column;align-items:stretch}.search-box{max-width:100%}.filter-select{width:100%}.add-btn{margin-left:0;width:100%;justify-content:center}.pagination{flex-direction:column;align-items:center}.modal{width:96%;max-height:96vh}.form-row{grid-template-columns:1fr}.detail-grid{grid-template-columns:1fr}}
    @media(max-width:480px){.stat-value{font-size:1.4rem}.content{padding:12px 10px}.topbar{padding:0 12px}.topbar-title p{display:none}.toast{bottom:16px;right:16px;left:16px;max-width:100%;}}
    
    /* ── HIGH ZOOM LEVEL HANDLING (110%+) ── */
    @media(min-height:900px){.toolbar{flex-wrap:wrap;gap:6px}.search-box{min-width:140px;flex:1 1 auto}.filter-select{flex:0 1 auto;min-width:90px}.add-btn{flex:0 1 auto;padding:7px 12px;font-size:0.75rem}.content{padding:20px 24px;overflow-x:hidden}.topbar{padding:0 16px;gap:10px}.topbar-title h1{font-size:1rem}}
    @media(min-height:1200px){.toolbar{gap:4px}.search-box{min-width:130px}.filter-select{min-width:85px;padding:6px 9px;font-size:0.75rem}.add-btn{padding:6px 10px;font-size:0.70rem}}
  
    /* ── Logout Button ── */
    .logout-btn {
      display: flex;
      align-items: center;
      gap: 9px;
      margin: 8px 10px 2px;
      padding: 10px 14px;
      border-radius: 10px;
      background: rgba(239,68,68,0.12);
      border: 1.5px solid rgba(239,68,68,0.28);
      color: #fca5a5;
      font-size: .845rem;
      font-weight: 700;
      text-decoration: none;
      transition: all .2s;
      cursor: pointer;
    }
    .logout-btn:hover {
      background: rgba(239,68,68,0.26);
      border-color: rgba(239,68,68,0.55);
      color: #fff;
      transform: translateY(-1px);
    }
    .logout-icon { font-size: 1rem; }

  </style>

</head>
<body>
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
      <a class="nav-item active" href="leave_requests.php"><div class="nav-icon">📋</div>Leave Requests<span class="nav-badge" id="sidebarBadge"></span></a>
      <a class="nav-item" href="leave_types.php"><div class="nav-icon">📁</div>Leave Types</a>
      <a class="nav-item" href="leave_balances.php"><div class="nav-icon">⚖️</div>Leave Balances</a>
      <a class="nav-item" href="holidays.php"><div class="nav-icon">📅</div>Holidays</a>
      <div class="nav-label">Documents</div>
      <a class="nav-item" href="documents.php"><div class="nav-icon">📄</div>HR Documents</a>
    <a class="nav-item" href="pds_saln.php"><div class="nav-icon">🪪</div>PDS & SALN</a>
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

  <div class="main">
    <div class="topbar">
      <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
      <div class="topbar-title">
        <h1>Leave Requests</h1>
        <p>Review, approve, and manage employee leave applications</p>
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
      <div class="stat-cards">
        <div class="stat-card orange"><div class="stat-card-header"><div class="stat-icon">⏳</div></div><div class="stat-value" id="sc-pending">—</div><div class="stat-label">Pending Requests</div><div class="stat-sub">Awaiting approval</div></div>
        <div class="stat-card green"><div class="stat-card-header"><div class="stat-icon">✅</div></div><div class="stat-value" id="sc-approved">—</div><div class="stat-label">Approved</div><div class="stat-sub">All time</div></div>
        <div class="stat-card red"><div class="stat-card-header"><div class="stat-icon">✗</div></div><div class="stat-value" id="sc-denied">—</div><div class="stat-label">Denied</div><div class="stat-sub">All time</div></div>
        <div class="stat-card blue"><div class="stat-card-header"><div class="stat-icon">📅</div></div><div class="stat-value" id="sc-total">—</div><div class="stat-label">Total Requests</div><div class="stat-sub">All time</div></div>
      </div>

      <div class="toolbar">
        <div class="search-box">
          <span style="color:var(--gray-400);font-size:1rem;">🔍</span>
          <input type="text" id="searchInput" aria-label="Search employee or leave type" placeholder="Search employee or leave type…" oninput="debounceLoad()"/>
        </div>
        <select class="filter-select" id="fStatus" title="Filter by status" aria-label="Filter by status" onchange="loadRequests(1)">
          <option value="">All Requests</option>
          <option value="Pending">Pending</option>
          <option value="Approved">Approved</option>
          <option value="Denied">Denied</option>
          <option value="Cancelled">Cancelled</option>
        </select>
        <select class="filter-select" id="fDept" title="Filter by department" aria-label="Filter by department" onchange="loadRequests(1)"><option value="">All Departments</option></select>
        <select class="filter-select" id="fType" title="Filter by leave type" aria-label="Filter by leave type" onchange="loadRequests(1)"><option value="">All Leave Types</option></select>
        <select class="filter-select" id="fMonth" title="Filter by month" aria-label="Filter by month" onchange="loadRequests(1)">
          <option value="">All Months</option>
          <option value="1">January</option><option value="2">February</option><option value="3">March</option>
          <option value="4">April</option><option value="5">May</option><option value="6">June</option>
          <option value="7">July</option><option value="8">August</option><option value="9">September</option>
          <option value="10">October</option><option value="11">November</option><option value="12">December</option>
        </select>
        <button class="add-btn" onclick="resetFilters()" style="background:linear-gradient(135deg,#f59e0b,#d97706);gap:6px;">🔄 Reset Filters</button>
       <!-- <button class="add-btn" onclick="openAdd()">➕ File Leave Request</button>-->
      </div>

      <div class="panel">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th onclick="sortBy('name')">Employee ↕</th>
                <th onclick="sortBy('type')">Leave Type ↕</th>
                <th>Leave Balance</th>
                <th onclick="sortBy('from')">Date Range ↕</th>
                <th onclick="sortBy('days')">Days ↕</th>
                <th>Reason</th>
                <th onclick="sortBy('filed')">Filed On ↕</th>
                <th onclick="sortBy('status')">Status ↕</th>
                <th style="text-align:center;">Actions</th>
              </tr>
            </thead>
            <tbody id="leaveBody">
              <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--gray-400);">Loading…</td></tr>
            </tbody>
          </table>
          <div class="empty-state" id="emptyState" style="display:none;">
            <div class="ei">📋</div><p>No leave requests found.</p>
          </div>
        </div>
        <div class="pagination">
          <span id="pageInfo"></span>
          <div class="page-btns" id="pageBtns"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- FILE LEAVE MODAL -->
  <div class="modal-overlay" id="modalForm">
    <div class="modal">
      <div class="modal-header">
        <div><h3>📋 File Leave Request</h3><p>Submit a new leave application</p></div>
        <button class="modal-close" onclick="closeModal('modalForm')">✕</button>
      </div>
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group"><label for="fEmployee">Employee <span class="req">*</span></label><select id="fEmployee" aria-label="Select employee"><option value="">Select employee</option></select></div>
          <div class="form-group"><label for="fLeaveType">Leave Type <span class="req">*</span></label><select id="fLeaveType" aria-label="Select leave type"><option value="">Select type</option></select></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label for="fFrom">Date From <span class="req">*</span></label><input type="date" id="fFrom" aria-label="Date from"/></div>
          <div class="form-group"><label for="fTo">Date To <span class="req">*</span></label><input type="date" id="fTo" aria-label="Date to"/></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label for="fDays">Number of Days <span class="req">*</span></label><input type="number" id="fDays" min="1" placeholder="e.g. 3" aria-label="Number of days"/></div>
        </div>
        <div class="form-row">
          <div class="form-group form-full"><label for="fReason">Reason / Remarks</label><textarea id="fReason" aria-label="Reason or remarks" placeholder="State the reason for this leave application…"></textarea></div>
        </div>
        <div class="form-row">
          <div class="form-group form-full">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
              <input type="checkbox" id="fOthersConfirm" style="width:16px;height:16px;cursor:pointer;"/>
              <span>Confirm: This is an "Others" leave type with specified details</span>
            </label>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-cancel" onclick="closeModal('modalForm')">Cancel</button>
        <button class="btn btn-primary" id="submitBtn" onclick="saveRequest()">📤 Submit Request</button>
      </div>
    </div>
  </div>

  <!-- VIEW DETAIL MODAL -->
  <!-- CS FORM 6 VIEW MODAL -->
  <div class="modal-overlay" id="modalView">
    <div class="modal" style="max-width:760px;width:96%;">
      <!-- Modal toolbar (not printed) -->
      <div class="csf-toolbar no-print">
        <div style="display:flex;align-items:center;gap:10px;">
          <div id="dAvatar" class="detail-avatar" style="width:36px;height:36px;font-size:.78rem;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;flex-shrink:0;"></div>
          <div>
            <div style="font-size:.92rem;font-weight:800;color:var(--gray-800);" id="dName">—</div>
            <div style="font-size:.72rem;color:var(--gray-400);" id="dPos">—</div>
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;">
          <button class="btn btn-ghost-sm" onclick="printCsForm()">🖨️ Print</button>
          <button class="btn btn-ghost-sm" onclick="closeModal('modalView')">✕ Close</button>
        </div>
      </div>

      <!-- Printable CS Form 6 -->
      <div id="csForm6" class="csf-paper">

        <!-- HEADER: Civil Service Form No. 6, Rev 2020 + LGU Enrile -->
        <table class="csf-tbl" style="border:none;">
          <tr>
            <td style="border:none;width:18%;vertical-align:top;padding:2px 4px;">
              <div style="font-size:.60rem;font-style:italic;font-weight:700;line-height:1.6;">Civil Service Form No. 6<br/>Revised 2020</div>
            </td>
            <td style="border:none;text-align:center;vertical-align:middle;padding:2px 8px;">
              <div style="display:flex;align-items:center;justify-content:center;gap:8px;">
                <div class="csf-logo-box"><img src="../images/Enrile.png" alt="LGU" onerror="this.outerHTML='<div style=&quot;width:46px;height:46px;border-radius:50%;border:1px solid #000;display:flex;align-items:center;justify-content:center;font-size:.48rem;text-align:center;line-height:1.2;&quot;>LGU<br>Enrile</div>'"/></div>
                <div style="text-align:center;line-height:1.55;">
                  <div style="font-size:.68rem;font-weight:700;text-transform:uppercase;">Local Government Unit of Enrile</div>
                  <div style="font-size:.62rem;">Tuguegarao-Santiago National Highway, Barangay 02, Enrile, Cagayan</div>
                </div>
              </div>
            </td>
            <td style="border:none;width:18%;vertical-align:top;text-align:right;padding:2px 4px;">
              <div style="font-size:.60rem;font-style:italic;">ANNEX A</div>
              <div class="csf-stamp-box" style="margin-top:3px;">Stamp of<br/>Date of Receipt</div>
            </td>
          </tr>
        </table>

        <!-- TITLE -->
        <div class="csf-title">APPLICATION FOR LEAVE</div>
        <div style="display:flex;justify-content:space-between;gap:12px;font-size:.62rem;margin:0 0 6px;">
          <div>Reference No.: <span id="cf-ref-no">&nbsp;</span></div>
          <div>Printed On: <span id="cf-printed-at">&nbsp;</span></div>
        </div>

        <!-- ROWS 4–6: 1. Office/Dept  |  2. Name -->
        <table class="csf-tbl">
          <tr>
            <td class="csf-td" style="width:35%;">
              <div class="csf-field-label">1.&nbsp;&nbsp; OFFICE/DEPARTMENT</div>
              <div class="csf-field-val" id="cf-dept">&nbsp;</div>
            </td>
            <td class="csf-td" colspan="3">
              <div class="csf-field-label">2.&nbsp; NAME :</div>
              <div style="display:grid;grid-template-columns:1fr 1fr 1fr;border-top:none;">
                <div class="csf-name-cell">
                  <div class="csf-field-val" id="cf-lastname">&nbsp;</div>
                  <div class="csf-name-sub">(Last)</div>
                </div>
                <div class="csf-name-cell" style="border-left:1px solid #000;">
                  <div class="csf-field-val" id="cf-firstname">&nbsp;</div>
                  <div class="csf-name-sub">(First)</div>
                </div>
                <div class="csf-name-cell" style="border-left:1px solid #000;">
                  <div class="csf-field-val" id="cf-middlename">&nbsp;</div>
                  <div class="csf-name-sub">(Middle)</div>
                </div>
              </div>
            </td>
          </tr>
          <tr>
            <td class="csf-td">
              <div class="csf-field-label">3.&nbsp;&nbsp; DATE OF FILING</div>
              <div class="csf-field-val" id="cf-filed">&nbsp;</div>
            </td>
            <td class="csf-td" colspan="2">
              <div class="csf-field-label">4.&nbsp;&nbsp; POSITION</div>
              <div class="csf-field-val" id="cf-position">&nbsp;</div>
            </td>
            <td class="csf-td" style="width:20%;">
              <div class="csf-field-label">5.&nbsp; SALARY</div>
              <div class="csf-field-val" id="cf-salary">&nbsp;</div>
            </td>
          </tr>
        </table>

        <!-- SECTION 6 HEADER -->
        <div class="csf-section-bar">6.&nbsp; DETAILS OF APPLICATION</div>

        <!-- 6A + 6B -->
        <table class="csf-tbl">
          <tr>
            <td class="csf-td" style="width:50%;vertical-align:top;padding:4px 6px;">
              <div class="csf-field-label" style="margin-bottom:4px;">6.A&nbsp; TYPE OF LEAVE TO BE AVAILED OF</div>
              <div class="csf-checklist">
                <div class="csf-check-row"><span class="csf-chk" id="ck-vl"></span><span>Vacation Leave <em style="font-size:.60rem;">(Sec. 51, Rule XVI, Omnibus Rules Implementing E.O. No. 292)</em></span></div>
                <div class="csf-check-row"><span class="csf-chk" id="ck-ml2"></span><span>Mandatory/Forced Leave <em style="font-size:.60rem;">(Sec. 25, Rule XVI, Omnibus Rules Implementing E.O. No. 292)</em></span></div>
                <div class="csf-check-row"><span class="csf-chk" id="ck-sl"></span><span>Sick Leave <em style="font-size:.60rem;">(Sec. 43, Rule XVI, Omnibus Rules Implementing E.O. No. 292)</em></span></div>
                <div class="csf-check-row"><span class="csf-chk" id="ck-mat"></span><span>Maternity Leave <em style="font-size:.60rem;">(R.A. No. 11210 / IRR issued by CSC, DOLE and SSS)</em></span></div>
                <div class="csf-check-row"><span class="csf-chk" id="ck-pat"></span><span>Paternity Leave <em style="font-size:.60rem;">(R.A. No. 8187 / CSC MC No. 71, s. 1998, as amended)</em></span></div>
                <div class="csf-check-row"><span class="csf-chk" id="ck-spl"></span><span>Special Privilege Leave <em style="font-size:.60rem;">(Sec. 21, Rule XVI, Omnibus Rules Implementing E.O. No. 292)</em></span></div>
                <div class="csf-check-row"><span class="csf-chk" id="ck-solo"></span><span>Solo Parent Leave <em style="font-size:.60rem;">(RA No. 8972 / CSC MC No. 8, s. 2004)</em></span></div>
                <div class="csf-check-row"><span class="csf-chk" id="ck-study"></span><span>Study Leave <em style="font-size:.60rem;">(Sec. 68, Rule XVI, Omnibus Rules Implementing E.O. No. 292)</em></span></div>
                <div class="csf-check-row"><span class="csf-chk" id="ck-vawc"></span><span>10-Day VAWC Leave <em style="font-size:.60rem;">(RA No. 9262 / CSC MC No. 15, s. 2005)</em></span></div>
                <div class="csf-check-row"><span class="csf-chk" id="ck-rehab"></span><span>Rehabilitation Privilege <em style="font-size:.60rem;">(Sec. 55, Rule XVI, Omnibus Rules Implementing E.O. No. 292)</em></span></div>
                <div class="csf-check-row"><span class="csf-chk" id="ck-slbw"></span><span>Special Leave Benefits for Women <em style="font-size:.60rem;">(RA No. 9710 / CSC MC No. 25, s. 2010)</em></span></div>
                <div class="csf-check-row"><span class="csf-chk" id="ck-ecl"></span><span>Special Emergency (Calamity) Leave <em style="font-size:.60rem;">(CSC MC No. 2, s. 2012, as amended)</em></span></div>
                <div class="csf-check-row"><span class="csf-chk" id="ck-adopt"></span><span>Adoption Leave <em style="font-size:.60rem;">(R.A. No. 8552)</em></span></div>
                <div class="csf-check-row">
                  <span class="csf-chk" id="ck-others"></span><em style="font-size:.66rem;">Others:&nbsp;</em>
                  <span id="cf-others-text" style="border-bottom:1px solid #000;flex:1;">&nbsp;</span>
                </div>
              </div>
            </td>
            <td class="csf-td" style="vertical-align:top;padding:4px 6px;">
              <div class="csf-field-label" style="margin-bottom:4px;">6.B&nbsp; DETAILS OF LEAVE</div>
              <div class="csf-detail-group">
                <div class="csf-detail-sub">In case of Vacation/Special Privilege Leave:</div>
                <div class="csf-check-row"><span class="csf-chk" id="cb-within-ph"></span> Within the Philippines <span id="cb-abroad-dest"></span></div>
                <div class="csf-check-row"><span class="csf-chk" id="cb-abroad"></span> Abroad (Specify) <span id="cb-abroad-spec"></span></div>
              </div>
              <div class="csf-detail-group">
                <div class="csf-detail-sub">In case of Sick Leave:</div>
                <div class="csf-check-row"><span class="csf-chk" id="cb-sick-hospital"></span> In Hospital <span id="cb-sick-illness" style="border-bottom:1px solid #000;flex:1;">&nbsp;</span></div>
                <div class="csf-check-row"><span class="csf-chk" id="cb-sick-outpatient"></span> Out Patient (Specify Illness) <span style="border-bottom:1px solid #000;flex:1;">&nbsp;</span></div>
                <div style="border-bottom:1px solid #000;min-height:12px;margin:2px 16px;">&nbsp;</div>
              </div>
              <div class="csf-detail-group">
                <div class="csf-detail-sub">In case of Special Leave Benefits for Women:</div>
                <div style="font-size:.66rem;">(Specify Illness) <span id="cb-women-illness" style="border-bottom:1px solid #000;display:inline-block;min-width:60px;">&nbsp;</span></div>
                <div style="border-bottom:1px solid #000;min-height:12px;margin:2px 0;">&nbsp;</div>
              </div>
              <div class="csf-detail-group">
                <div class="csf-detail-sub">In case of Study Leave:</div>
                <div class="csf-check-row"><span class="csf-chk" id="cb-study-masters"></span> Completion of Master's Degree</div>
                <div class="csf-check-row"><span class="csf-chk" id="cb-study-bar"></span> BAR/Board Examination Review</div>
              </div>
              <div class="csf-detail-group">
                <div class="csf-detail-sub"><em>Other purpose:</em></div>
                <div class="csf-check-row"><span class="csf-chk" id="cb-other-monetization"></span> Monetization of Leave Credits</div>
                <div class="csf-check-row"><span class="csf-chk" id="cb-other-terminal"></span> Terminal Leave</div>
              </div>
            </td>
          </tr>
        </table>

        <!-- 6C + 6D -->
        <table class="csf-tbl">
          <tr>
            <td class="csf-td" style="width:50%;vertical-align:top;padding:4px 6px;">
              <div class="csf-field-label">6.C&nbsp; NUMBER OF WORKING DAYS APPLIED FOR</div>
              <div class="csf-field-val" style="margin:8px 0 4px;" id="cf-days">&nbsp;</div>
              <div class="csf-field-label" style="margin-top:8px;">INCLUSIVE DATES</div>
              <div class="csf-field-val" style="margin-top:4px;" id="cf-dates">&nbsp;</div>
            </td>
            <td class="csf-td" style="vertical-align:top;padding:4px 6px;">
              <div class="csf-field-label">6.D&nbsp; COMMUTATION</div>
              <div class="csf-check-row" style="margin-top:6px;"><span class="csf-chk csf-chk-x" id="cb-not-requested"></span> Not Requested</div>
              <div class="csf-check-row" style="margin-top:3px;"><span class="csf-chk" id="cb-requested"></span> Requested</div>
              <div style="margin-top:22px;padding-top:3px;text-align:center;">
                <div id="cf-applicant-signature-wrap" style="min-height:48px;display:flex;align-items:flex-end;justify-content:center;padding-bottom:3px;">
                  <img id="cf-applicant-signature" alt="Applicant signature" style="max-height:42px;max-width:210px;object-fit:contain;display:none;" />
                </div>
                <div id="cf-applicant-signature-name" style="font-size:.74rem;font-weight:700;letter-spacing:.02em;text-transform:uppercase;min-height:12px;"></div>
                <div style="border-top:1px solid #000;max-width:220px;margin:2px auto 3px;"></div>
                <div style="font-size:.63rem;">(Signature of Applicant)</div>
              </div>
            </td>
          </tr>
        </table>

        <!-- SECTION 7 HEADER -->
        <div class="csf-section-bar">7.&nbsp; DETAILS OF ACTION ON APPLICATION</div>

        <!-- 7A + 7B -->
        <table class="csf-tbl">
          <tr>
            <td class="csf-td" style="width:50%;vertical-align:top;padding:4px 6px;">
              <div class="csf-field-label">7.A&nbsp; CERTIFICATION OF LEAVE CREDITS</div>
              <div style="font-size:.68rem;margin:5px 0 3px;text-align:center;">As of <span id="cf-as-of" style="border-bottom:1px solid #000;min-width:90px;display:inline-block;">&nbsp;</span></div>
              <table style="width:100%;border-collapse:collapse;font-size:.68rem;">
                <tr>
                  <td style="border:1px solid #000;padding:2px 5px;width:42%;"></td>
                  <td style="border:1px solid #000;padding:2px 5px;text-align:center;font-weight:700;width:29%;">Vacation Leave</td>
                  <td style="border:1px solid #000;padding:2px 5px;text-align:center;font-weight:700;width:29%;">Sick Leave</td>
                </tr>
                <tr>
                  <td style="border:1px solid #000;padding:2px 5px;font-style:italic;">Total Earned</td>
                  <td style="border:1px solid #000;padding:2px 5px;text-align:center;" id="cf-vl-total">&nbsp;</td>
                  <td style="border:1px solid #000;padding:2px 5px;text-align:center;" id="cf-sl-total">&nbsp;</td>
                </tr>
                <tr>
                  <td style="border:1px solid #000;padding:2px 5px;font-style:italic;">Less this application</td>
                  <td style="border:1px solid #000;padding:2px 5px;text-align:center;" id="cf-vl-used">&nbsp;</td>
                  <td style="border:1px solid #000;padding:2px 5px;text-align:center;" id="cf-sl-used">&nbsp;</td>
                </tr>
                <tr>
                  <td style="border:1px solid #000;padding:2px 5px;font-style:italic;">Balance</td>
                  <td style="border:1px solid #000;padding:2px 5px;text-align:center;" id="cf-vl-bal">&nbsp;</td>
                  <td style="border:1px solid #000;padding:2px 5px;text-align:center;" id="cf-sl-bal">&nbsp;</td>
                </tr>
              </table>
              <div style="margin-top:24px;text-align:center;">
                <div id="cf-7a-signature-wrap" style="min-height:44px;display:flex;align-items:flex-end;justify-content:center;padding-bottom:2px;">
                  <img id="cf-7a-signature" alt="HR Head signature" style="max-height:38px;max-width:210px;object-fit:contain;display:none;" />
                </div>
                <div id="cf-authorized-officer" style="font-size:.80rem;font-weight:700;letter-spacing:.02em;text-transform:uppercase;">AIREEN G. BERAN, HRMO</div>
                <div style="border-top:1px solid #000;margin-top:4px;padding-top:4px;font-size:.63rem;color:#333;">(Authorized Officer)</div>
              </div>
            </td>
            <td class="csf-td" style="vertical-align:top;padding:4px 6px;">
              <div class="csf-field-label">7.B&nbsp; RECOMMENDATION</div>
              <div class="csf-check-row" style="margin-top:6px;" id="cf-rec-approve">
                <span class="csf-chk" id="cb-rec-approve"></span> For approval
              </div>
              <div class="csf-check-row" style="margin-top:3px;" id="cf-rec-deny">
                <span class="csf-chk" id="cb-rec-deny"></span> For disapproval due to
              </div>
              <div style="font-size:.68rem;min-height:14px;border-bottom:1px solid #000;margin:2px 0;" id="cf-deny-reason">&nbsp;</div>
              <div style="min-height:12px;border-bottom:1px solid #000;margin:2px 0;">&nbsp;</div>
              <div style="min-height:12px;border-bottom:1px solid #000;margin:2px 0;">&nbsp;</div>
              <div style="min-height:12px;border-bottom:1px solid #000;margin:2px 0;">&nbsp;</div>
              <div style="margin-top:24px;text-align:center;">
                <div id="cf-7b-signature-wrap" style="min-height:44px;display:flex;align-items:flex-end;justify-content:center;padding-bottom:2px;">
                  <img id="cf-7b-signature" alt="Department Head signature" style="max-height:38px;max-width:210px;object-fit:contain;display:none;" />
                </div>
                <div id="cf-7b-authorized-officer" style="font-size:.80rem;font-weight:700;letter-spacing:.02em;text-transform:uppercase;"></div>
                <div style="border-top:1px solid #000;margin-top:4px;padding-top:4px;font-size:.63rem;color:#333;">(Authorized Officer)</div>
              </div>
            </td>
          </tr>
        </table>

        <!-- 7C + 7D + AUTHORIZED OFFICIAL (single cell, no inner table borders) -->
        <table class="csf-tbl">
          <tr>
            <td class="csf-td" style="padding:6px 8px 10px;vertical-align:top;">
              <div style="display:grid;grid-template-columns:1fr 1fr;column-gap:24px;">
                <div>
                  <div style="font-size:.80rem;font-weight:400;letter-spacing:0;text-transform:none;">7.C&nbsp; APPROVED FOR:</div>
                  <div style="font-size:.88rem;margin-top:6px;line-height:1.35;padding-left:14px;">
                    <div style="margin-bottom:2px;"><span id="cf-approved-pay" style="display:inline-block;min-width:62px;border-bottom:1px solid #000;white-space:nowrap;overflow:hidden;">&nbsp;</span> days with pay</div>
                    <div style="margin-bottom:2px;"><span id="cf-approved-nopay" style="display:inline-block;min-width:62px;border-bottom:1px solid #000;white-space:nowrap;overflow:hidden;">&nbsp;</span> days without pay</div>
                    <div><span id="cf-approved-others" style="display:inline-block;min-width:62px;border-bottom:1px solid #000;white-space:nowrap;overflow:hidden;">&nbsp;</span> others (Specify)</div>
                  </div>
                </div>
                <div>
                  <div style="font-size:.80rem;font-weight:400;letter-spacing:0;text-transform:none;">7.D&nbsp; DISAPPROVED DUE TO:</div>
                  <div style="font-size:.78rem;margin-top:5px;line-height:1.45;padding-left:18px;">
                    <div style="border-bottom:1px solid #000;height:14px;width:86%;overflow:hidden;"><span id="cf-disapprove-1" style="display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">&nbsp;</span></div>
                    <div style="border-bottom:1px solid #000;height:14px;width:86%;margin-top:1px;overflow:hidden;"><span id="cf-disapprove-2" style="display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">&nbsp;</span></div>
                    <div style="border-bottom:1px solid #000;height:14px;width:86%;margin-top:1px;overflow:hidden;"><span id="cf-disapprove-3" style="display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">&nbsp;</span></div>
                  </div>
                </div>
              </div>

              <div style="padding-top:32px;text-align:center;">
                <div style="display:inline-block;min-width:260px;text-align:center;">
                  <div style="font-size:1.00rem;font-weight:700;letter-spacing:.03em;text-transform:uppercase;"><?= htmlspecialchars($mayorName) ?></div>
                  <div style="font-size:.98rem;font-weight:700;"><?= htmlspecialchars($mayorTitle) ?></div>
                  <div style="border-top:1.5px solid #000;margin-top:4px;padding-top:4px;font-size:.90rem;font-weight:700;">(Authorized Official)</div>
                </div>
              </div>
            </td>
          </tr>
        </table>

      </div><!-- /csForm6 -->

      <!-- Footer action buttons (not printed) -->
      <div class="modal-footer no-print" id="dFooter"></div>
    </div>
  </div>

  <!-- APPROVE CONFIRM -->
  <div class="modal-overlay" id="modalApprove">
    <div class="modal sm">
      <div class="modal-header green">
        <div><h3>✅ Approve Leave</h3><p>Confirm approval of this request</p></div>
        <button class="modal-close" onclick="closeModal('modalApprove')">✕</button>
      </div>
      <div class="confirm-body">
        <div class="confirm-icon">✅</div>
        <h4>Approve this leave request?</h4>
        <p id="approveText"></p>
        <textarea class="confirm-reason" id="approveRemark" aria-label="Approval remarks" placeholder="Optional remarks for the employee…"></textarea>
      </div>
      <div class="modal-footer">
        <button class="btn btn-cancel" onclick="closeModal('modalApprove')">Cancel</button>
        <button class="btn btn-success" id="approveBtn" onclick="confirmApprove()">✅ Yes, Approve</button>
      </div>
    </div>
  </div>

  <!-- DENY CONFIRM -->
  <div class="modal-overlay" id="modalDeny">
    <div class="modal sm">
      <div class="modal-header red">
        <div><h3>✗ Deny Leave</h3><p>Confirm denial of this request</p></div>
        <button class="modal-close" onclick="closeModal('modalDeny')">✕</button>
      </div>
      <div class="confirm-body">
        <div class="confirm-icon">⚠️</div>
        <h4>Deny this leave request?</h4>
        <p id="denyText"></p>
        <textarea class="confirm-reason" id="denyRemark" aria-label="Denial reason" placeholder="Please state the reason for denial…"></textarea>
      </div>
      <div class="modal-footer">
        <button class="btn btn-cancel" onclick="closeModal('modalDeny')">Cancel</button>
        <button class="btn btn-danger" id="denyBtn" onclick="confirmDeny()">✗ Yes, Deny</button>
      </div>
    </div>
  </div>

  <!-- CANCEL CONFIRM -->
  <div class="modal-overlay" id="modalCancel">
    <div class="modal sm">
      <div class="modal-header" style="background:linear-gradient(135deg,#f97316,#ea580c);">
        <div><h3>⛔ Cancel Approved Leave</h3><p>Restore credits and mark request as cancelled</p></div>
        <button class="modal-close" onclick="closeModal('modalCancel')">✕</button>
      </div>
      <div class="confirm-body">
        <div class="confirm-icon">⛔</div>
        <h4>Cancel this approved leave request?</h4>
        <p id="cancelText"></p>
        <textarea class="confirm-reason" id="cancelReason" aria-label="Cancellation reason" placeholder="Required reason (e.g., typhoon/emergency cancellation)…"></textarea>
      </div>
      <div class="modal-footer">
        <button class="btn btn-cancel" onclick="closeModal('modalCancel')">Keep Approved</button>
        <button class="btn" id="cancelBtn" onclick="confirmCancel()" style="background:linear-gradient(135deg,#f97316,#ea580c);color:#fff;">⛔ Yes, Cancel Leave</button>
      </div>
    </div>
  </div>

  <script>
let CSRF_TOKEN = '';
const CAN_CANCEL_APPROVED = <?php echo $canCancelApproved ? 'true' : 'false'; ?>;

// Initialize CSRF token from meta tag with multiple fallbacks
function initCSRFTokenAdmin() {
  let token = document.querySelector('meta[name="csrf-token"]')?.content;
  if (token) {
    CSRF_TOKEN = token;
    console.log('✓ CSRF token loaded from meta tag');
    return token;
  }
  
  // Fallback: search all meta tags
  const allMetas = document.querySelectorAll('meta');
  for (let meta of allMetas) {
    if (meta.getAttribute('name') === 'csrf-token') {
      token = meta.getAttribute('content');
      if (token) {
        CSRF_TOKEN = token;
        console.log('✓ CSRF token found in meta tags');
        return token;
      }
    }
  }
  
  console.warn('⚠ CSRF token not found - will attempt retry');
  return '';
}

// Initialize on script load
CSRF_TOKEN = initCSRFTokenAdmin();

// Retry initialization if not found
if (!CSRF_TOKEN) {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      if (!CSRF_TOKEN) {
        CSRF_TOKEN = initCSRFTokenAdmin();
      }
    });
  }
}

  const COLORS=['#2563eb','#22c55e','#f59e0b','#ef4444','#a855f7','#06b6d4','#ec4899','#0ea5e9','#84cc16','#f97316'];
  let currentTab='all',currentPage=1,sortKey='',sortAsc=false;
  let approveTarget=null,denyTarget=null,cancelTarget=null,debounceTimer=null;

  const fmt   = d => d ? new Date(d.slice(0,10)+'T00:00:00').toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}) : '—';
  function fmtDateRange(from, to) {
    if (!from || !to) return '—';
    const start = new Date(from.slice(0,10) + 'T00:00:00');
    const end = new Date(to.slice(0,10) + 'T00:00:00');
    const month = date => date.toLocaleDateString('en-US', {month:'long'});
    const year = date => date.getFullYear();
    if (year(start) === year(end) && start.getMonth() === end.getMonth()) {
      return `${month(start)} ${start.getDate()}-${end.getDate()} ${year(start)}`;
    }
    if (year(start) === year(end)) {
      return `${month(start)} ${start.getDate()} - ${month(end)} ${end.getDate()} ${year(start)}`;
    }
    return `${month(start)} ${start.getDate()} ${year(start)} - ${month(end)} ${end.getDate()} ${year(end)}`;
  }
  const ini   = n => n.split(' ').slice(0,2).map(w=>w[0]).join('').toUpperCase();
  const aColor= s => COLORS[Math.abs([...s].reduce((a,c)=>a+c.charCodeAt(0),0)) % COLORS.length];

  const TYPE_BADGES = {Vacation:'#dbeafe|#1e40af',Sick:'#fef3c7|#92400e',Emergency:'#fee2e2|#991b1b',Maternity:'#fce7f3|#9d174d',Paternity:'#dcfce7|#166534',Special:'#f3e8ff|#6b21a8'};
  function typeBadge(t){const[bg,col]=(TYPE_BADGES[t]||'#f1f5f9|#475569').split('|');return `<span class="badge" style="background:${bg};color:${col};">${t}</span>`;}
  function statusBadge(s){const map={Pending:'b-pending',Approved:'b-approved',Denied:'b-denied',Cancelled:'b-cancelled'};const ico={Pending:'⏳',Approved:'✅',Denied:'✗',Cancelled:'—'};return `<span class="badge ${map[s]||'b-pending'}">${ico[s]||''} ${s}</span>`;}


  async function loadRequests(page=currentPage){
    currentPage=page;
    const p=new URLSearchParams({action:'list',tab:currentTab,page:currentPage,sort:sortKey,dir:sortAsc?'ASC':'DESC',
      search:document.getElementById('searchInput').value.trim(),
      status:document.getElementById('fStatus').value,
      dept:document.getElementById('fDept').value,
      type:document.getElementById('fType').value,
      month:document.getElementById('fMonth').value});
    try{
      const res=await fetch(`leave_requests.php?${p}`);
      const json=await res.json();
      if(!json.success){showToast('⚠️ '+json.message,'info');return;}
      renderTable(json.data);
      renderPagination(json.page,json.pages,json.total);
      renderTabCounts(json.tab_counts);
    }catch(e){showToast('❌ Failed to load.','error');}
  }

  // Move loadStats to global scope
  async function loadStats(){
    try{
      const res=await fetch('leave_requests.php?action=stats');
      const json=await res.json();
      if(!json.success)return;
      const s=json.data;
      document.getElementById('sc-pending').textContent =s.pending;
      document.getElementById('sc-approved').textContent=s.approved;
      document.getElementById('sc-denied').textContent  =s.denied;
      document.getElementById('sc-total').textContent   =s.total;
      const p=parseInt(s.pending)||0;
      document.getElementById('sidebarBadge').textContent=p||'';
      /* topbarBadge removed */
    }catch(e){}
  }

  function debounceLoad(){clearTimeout(debounceTimer);debounceTimer=setTimeout(()=>loadRequests(1),320);}

  function resetFilters(){
    document.getElementById('searchInput').value='';
    document.getElementById('fStatus').value='';
    document.getElementById('fDept').value='';
    document.getElementById('fType').value='';
    document.getElementById('fMonth').value='';
    loadRequests(1);
  }


function renderTable(rows){
  const tbody=document.getElementById('leaveBody');
  const empty=document.getElementById('emptyState');
  if(!rows.length){tbody.innerHTML='';empty.style.display='block';return;}
  empty.style.display='none';
  tbody.innerHTML=rows.map(r=>{
    const name=`${r.first_name} ${r.last_name}`;
    const c=aColor(r.first_name+r.last_name);
    const av=ini(name);
    window['_r'+r.request_id] = r.reason||'';
    // Always show both View and Delete buttons
    const cancelBtn = (CAN_CANCEL_APPROVED && r.status === 'Approved')
      ? `<button class="act-btn cancel" style="margin-left:4px;" onclick="openCancel(${r.request_id})">Cancel Leave</button>`
      : '';
    const actions = `<div class="row-actions">
      <button class="view-btn" onclick="viewRequest(${r.request_id})">View</button>
      <button class="act-btn deny" style="margin-left:4px;" onclick="confirmDelete(${r.request_id}, '${name.replace(/'/g, "&#39;")}')">Delete</button>
      ${cancelBtn}
    </div>`;
    return `<tr>
      <td><div class="emp-cell"><div class="emp-avatar" style="background:${c};">${av}</div><div><div class="emp-name">${name}</div><div class="emp-dept">${r.department||'—'}</div></div></div></td>
      <td>${typeBadge(r.leave_type)}</td>
      <td><span class="days-pill">${parseFloat(r.leave_balance || 0).toFixed(2)} days</span></td>
      <td style="font-size:0.80rem;color:var(--gray-600);white-space:nowrap;">${fmtDateRange(r.date_from,r.date_to)}</td>
      <td><span class="days-pill">${r.days_requested} day${r.days_requested!=1?'s':''}</span></td>
      <td class="rc-col" data-id="${r.request_id}">${rcBuild(r.reason,r.request_id)}</td>
      <td style="font-size:0.78rem;color:var(--gray-400);">${fmt(r.filed_at)}</td>
      <td>${statusBadge(r.status)}</td>
      <td style="text-align:center;">${actions}</td>
    </tr>`;
  }).join('');
}

// Delete logic for leave requests (must be global)
let deleteTargetId = null;
let deleteTargetName = '';

function confirmDelete(id, name) {
  deleteTargetId = id;
  deleteTargetName = name;
  if (confirm(`Delete leave request for ${name}? This action cannot be undone.\nThe request will be removed from the list.`)) {
    doDeleteRequest();
  }
}

async function doDeleteRequest() {
  if (!deleteTargetId) return;
  const body = new FormData();
  body.append('action', 'delete');
  body.append('id', deleteTargetId);
  try {
    if (!CSRF_TOKEN) CSRF_TOKEN = initCSRFTokenAdmin();
    const res = await fetch('leave_requests.php', {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': CSRF_TOKEN },
      body
    });
    const json = await res.json();
    if (json.success) {
      showToast('✅ Leave request deleted.', 'success');
      loadStats();
      loadRequests(currentPage);
    } else {
      showToast('❌ ' + (json.message || 'Delete failed.'), 'error');
    }
  } catch (e) {
    showToast('❌ Delete failed.', 'error');
  }
  deleteTargetId = null;
  deleteTargetName = '';
}

  function renderTabCounts(tc){
    ['all','pending','approved','denied','cancelled'].forEach(t=>{
      const el=document.getElementById('tc-'+t);if(el)el.textContent=tc[t]||0;
    });
  }

  function renderPagination(page,pages,total){
    const from=total?(page-1)*8+1:0,to=Math.min(page*8,total);
    document.getElementById('pageInfo').textContent=`Showing ${from}–${to} of ${total} request${total!==1?'s':''}`;
    const pb=document.getElementById('pageBtns');pb.innerHTML='';
    const mk=(label,pg,disabled)=>{
      const b=document.createElement('button');b.className='page-btn'+(pg===page?' active':'');
      b.textContent=label;b.disabled=disabled;b.onclick=()=>loadRequests(pg);pb.appendChild(b);
    };
    mk('‹',page-1,page===1);
    for(let i=1;i<=pages;i++)mk(i,i,false);
    mk('›',page+1,page>=pages);
  }

  function setTab(t){
    currentTab=t;currentPage=1;
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.toggle('active',b.id===`tab-${t}`));
    loadRequests(1);
  }

  function sortBy(key){if(sortKey===key)sortAsc=!sortAsc;else{sortKey=key;sortAsc=true;}loadRequests(currentPage);}

  // Leave type name → CS Form 6 checkbox label mapping
  var LEAVE_TYPE_MAP = {
    'Vacation':               'Vacation Leave',
    'Mandatory':              'Mandatory/Forced Leave',
    'Forced':                 'Mandatory/Forced Leave',
    'Sick':                   'Sick Leave',
    'Maternity':              'Maternity Leave',
    'Paternity':              'Paternity Leave',
    'Special Privilege':      'Special Privilege Leave',
    'Solo Parent':            'Solo Parent Leave',
    'Study':                  'Study Leave',
    'VAWC':                   '10-Day VAWC Leave',
    'Rehabilitation':         'Rehabilitation Privilege',
    'Special Leave Benefits': 'Special Leave Benefits for Women',
    'Special Emergency':      'Special Emergency (Calamity) Leave',
    'Adoption':               'Adoption Leave',
  };
  var LEAVE_TYPE_LIST = [
    ['Vacation Leave',                    'Sec. 51, Rule XVI, Omnibus Rules Implementing E.O. No. 292'],
    ['Mandatory/Forced Leave',            'Sec. 25, Rule XVI, Omnibus Rules Implementing E.O. No. 292'],
    ['Sick Leave',                        'Sec. 43, Rule XVI, Omnibus Rules Implementing E.O. No. 292'],
    ['Maternity Leave',                   'R.A. No. 11210 / IRR issued by CSC, DOLE and SSS'],
    ['Paternity Leave',                   'R.A. No. 8187 / CSC MC No. 71, s. 1998, as amended'],
    ['Special Privilege Leave',           'Sec. 21, Rule XVI, Omnibus Rules Implementing E.O. No. 292'],
    ['Solo Parent Leave',                 'RA No. 8972 / CSC MC No. 8, s. 2004'],
    ['Study Leave',                       'Sec. 68, Rule XVI, Omnibus Rules Implementing E.O. No. 292'],
    ['10-Day VAWC Leave',                 'RA No. 9262 / CSC MC No. 15, s. 2005'],
    ['Rehabilitation Privilege',          'Sec. 55, Rule XVI, Omnibus Rules Implementing E.O. No. 292'],
    ['Special Leave Benefits for Women',  'RA No. 9710 / CSC MC No. 25, s. 2010'],
    ['Special Emergency (Calamity) Leave','CSC MC No. 2, s. 2012, as amended'],
    ['Adoption Leave',                    'R.A. No. 8552'],
  ];

  var currentViewId = null;
  const signatureScanCache = new Map();

  async function scanSignatureForPrint(signaturePath) {
    var cleanPath = (signaturePath || '').trim();
    if (!cleanPath) return '';
    if (signatureScanCache.has(cleanPath)) return signatureScanCache.get(cleanPath);

    try {
      var scanned = await new Promise(function(resolve, reject) {
        var img = new Image();
        img.onload = function() {
          try {
            var canvas = document.createElement('canvas');
            canvas.width = img.naturalWidth || img.width;
            canvas.height = img.naturalHeight || img.height;
            var ctx = canvas.getContext('2d', { willReadFrequently: true });
            if (!ctx) throw new Error('Canvas context unavailable.');

            ctx.drawImage(img, 0, 0);
            var frame = ctx.getImageData(0, 0, canvas.width, canvas.height);
            var px = frame.data;

            // Estimate paper/background tone from image corners.
            var w = canvas.width;
            var h = canvas.height;
            var samples = [
              [0, 0],
              [w - 1, 0],
              [0, h - 1],
              [w - 1, h - 1],
              [Math.floor(w * 0.5), 0],
              [Math.floor(w * 0.5), h - 1],
              [0, Math.floor(h * 0.5)],
              [w - 1, Math.floor(h * 0.5)]
            ];
            var bgR = 0, bgG = 0, bgB = 0, bgCount = 0;
            for (var s = 0; s < samples.length; s++) {
              var sx = samples[s][0];
              var sy = samples[s][1];
              var si = (sy * w + sx) * 4;
              bgR += px[si];
              bgG += px[si + 1];
              bgB += px[si + 2];
              bgCount++;
            }
            bgR = Math.round(bgR / Math.max(1, bgCount));
            bgG = Math.round(bgG / Math.max(1, bgCount));
            bgB = Math.round(bgB / Math.max(1, bgCount));

            for (var i = 0; i < px.length; i += 4) {
              var r = px[i];
              var g = px[i + 1];
              var b = px[i + 2];
              var a = px[i + 3];
              if (a === 0) continue;

              var maxCh = Math.max(r, g, b);
              var minCh = Math.min(r, g, b);
              var saturation = maxCh - minCh;
              var lum = 0.299 * r + 0.587 * g + 0.114 * b;
              var deltaBg = Math.abs(r - bgR) + Math.abs(g - bgG) + Math.abs(b - bgB);

              // Remove paper-like background noise, keep only signature strokes.
              if (deltaBg < 52 && saturation < 42 && lum > 135) {
                px[i + 3] = 0;
                continue;
              }

              var edgeStrength = Math.max(0, deltaBg - 14);
              var ink = Math.max(0, Math.min(255, Math.round((232 - lum) * 1.9 + edgeStrength * 2.8)));
              if (ink < 20) {
                px[i + 3] = 0;
                continue;
              }

              // Preserve uploaded pen color and tighten stroke opacity.
              px[i + 3] = Math.max(a, Math.min(255, ink + 18));
            }

            ctx.putImageData(frame, 0, 0);
            resolve(canvas.toDataURL('image/png'));
          } catch (e) {
            reject(e);
          }
        };
        img.onerror = function() { reject(new Error('Failed to load signature image.')); };
        img.src = cleanPath;
      });

      signatureScanCache.set(cleanPath, scanned);
      return scanned;
    } catch (e) {
      // Fallback to the raw image path if scan fails.
      return cleanPath;
    }
  }

  async function setScannedSignature(imgId, signaturePath) {
    var img = document.getElementById(imgId);
    if (!img) return;

    var cleanPath = (signaturePath || '').trim();
    if (!cleanPath) {
      img.removeAttribute('src');
      img.style.display = 'none';
      return;
    }

    var scannedSrc = await scanSignatureForPrint(cleanPath);
    img.src = scannedSrc || cleanPath;
    img.style.display = 'block';
  }

  async function viewRequest(id) {
    currentViewId = id;
    const res  = await fetch('leave_requests.php?action=get&id=' + id);
    const json = await res.json();
    if (!json.success) { showToast('⚠️ ' + json.message, 'info'); return; }
    const r = json.data;

    // Toolbar avatar + name
    const name = r.first_name + ' ' + r.last_name;
    const col  = aColor(name);
    document.getElementById('dAvatar').textContent       = ini(name);
    document.getElementById('dAvatar').style.background  = col;
    document.getElementById('dName').textContent         = name;
    document.getElementById('dPos').textContent          = (r.position_title || '') + (r.department ? ' · ' + r.department : '');

    // Helper: set text
    function set(id, val) {
      var el = document.getElementById(id);
      if (el) el.textContent = val || '—';
    }
    function setLine(id, val) {
      var el = document.getElementById(id);
      if (el) el.textContent = val || '';
    }
    function fmtDate(d) {
      if (!d) return '—';
      return new Date(d).toLocaleDateString('en-PH', {year:'numeric', month:'long', day:'numeric'});
    }
    function fmtDateShort(d) {
      if (!d) return '—';
      return new Date(d).toLocaleDateString('en-PH', {year:'numeric', month:'short', day:'numeric'});
    }

    // Section 1-5
    set('cf-dept',       r.department);
    set('cf-lastname',   r.last_name);
    set('cf-firstname',  r.first_name);
    set('cf-middlename', r.middle_name || '—');
    set('cf-filed',      fmtDate(r.filed_at));
    set('cf-position',   r.position_title);
    set('cf-salary',     r.salary_grade ? 'SG-' + r.salary_grade : '—');

    // 6A — leave type checkboxes (static IDs matching the Excel form)
    var leaveTypeName = (r.leave_type || '').trim();
    var ltLow6a = leaveTypeName.toLowerCase();
    // Map leave type keywords to checkbox IDs
    // leaveMap: keywords → checkbox ID (ordered most-specific first)
    // Covers both DB seed names (short) and full CSC names (long)
    var leaveMap = [
      // Exact / very specific matches first
      ['solo parent',                      'ck-solo'],
      ['special leave benefit',            'ck-slbw'],
      ['special leave for women',          'ck-slbw'],
      ['special leave benefits for women', 'ck-slbw'],
      ['10-day vawc', 'vawc',              'ck-vawc'],
      ['special emergency', 'calamity',    'ck-ecl'],
      ['emergency leave',                  'ck-ecl'],
      ['emergency',                        'ck-ecl'],
      ['mandatory','forced',               'ck-ml2'],
      ['rehabilitation',                   'ck-rehab'],
      ['paternity',                        'ck-pat'],
      ['maternity',                        'ck-mat'],
      ['special privilege',                'ck-spl'],
      // 'Special Leave' (seed code SPL) = Special Privilege Leave
      ['special leave',                    'ck-spl'],
      ['adoption', 'adaption',             'ck-adopt'],
      ['study',                            'ck-study'],
      ['sick',                             'ck-sl'],
      ['vacation',                         'ck-vl'],
    ];
    // Reset all leave type checkboxes
    ['ck-vl','ck-ml2','ck-sl','ck-mat','ck-pat','ck-spl','ck-solo',
     'ck-study','ck-vawc','ck-rehab','ck-slbw','ck-ecl','ck-adopt','ck-others'].forEach(function(id) {
      var el = document.getElementById(id);
      if (el) el.className = 'csf-chk';
    });
    // Clear others text
    var othersEl = document.getElementById('cf-others-text');
    if (othersEl) othersEl.textContent = '';
    // Find matching ID and check it
    var foundCk = false;
    leaveMap.forEach(function(entry) {
      var keywords = entry.slice(0, -1);
      var ckId = entry[entry.length - 1];
      var matches = keywords.some(function(kw) { return ltLow6a.indexOf(kw) >= 0; });
      if (matches && !foundCk) {
        var el = document.getElementById(ckId);
        if (el) { el.className = 'csf-chk checked'; foundCk = true; }
      }
    });

    // Parse detail prefix early (before using it for Others)
    var rawReason = r.reason || '';
    var detailPrefix = '';
    var cleanReason  = rawReason;
    var prefixMatch  = rawReason.match(/^\[([^\]]+)\]\s*/);
    if (prefixMatch) {
      detailPrefix = prefixMatch[1];
      cleanReason  = rawReason.substring(prefixMatch[0].length);
    }

    // If nothing matched, put in "Others"
    if (!foundCk && leaveTypeName) {
      var othersEl2 = document.getElementById('cf-others-text');
      if (othersEl2) {
        // Extract the actual specified leave type from detailPrefix
        // Format: "Leave Type: [specified_type] ☑"
        if (detailPrefix && detailPrefix.indexOf('Leave Type:') >= 0) {
          var specType = detailPrefix.replace(/^Leave Type:\s*Abroad\s*/i, '').replace(/\s*☑\s*$/i, '').trim();
          othersEl2.textContent = specType || leaveTypeName;
        } else {
          othersEl2.textContent = leaveTypeName;
        }
      }
      // Check the "Others" checkbox when Others leave type is used
      var othersChk = document.getElementById('ck-others');
      if (othersChk) othersChk.className = 'csf-chk checked';
    }

    var ltLow = leaveTypeName.toLowerCase();
    var detLow = detailPrefix.toLowerCase();

    // Reset all 6B checkboxes first
    ['cb-within-ph','cb-abroad','cb-sick-hospital','cb-sick-outpatient',
     'cb-study-masters','cb-study-bar','cb-other-monetization','cb-other-terminal'].forEach(function(id) {
      var el = document.getElementById(id);
      if (el) el.className = 'csf-chk';
    });
    // Clear text spans
    ['cb-abroad-spec','cb-sick-illness','cb-women-illness','cb-abroad-dest'].forEach(function(id) {
      var el = document.getElementById(id);
      if (el) el.textContent = '';
    });

    // Vacation / Special Privilege
    if (ltLow.indexOf('vacation') >= 0 || ltLow.indexOf('privilege') >= 0) {
      if (detLow.indexOf('abroad') >= 0) {
        var ab = document.getElementById('cb-abroad');
        if (ab) ab.className = 'csf-chk checked';
        var spec = detailPrefix.replace(/^Location:\s*Abroad\s*/i, '').replace(/^\(|\)$/g,'').trim();
        var sp = document.getElementById('cb-abroad-spec');
        if (sp && spec) sp.textContent = '(' + spec + ')';
      } else {
        var ph = document.getElementById('cb-within-ph');
        if (ph) ph.className = 'csf-chk checked';
      }
    }
    // Sick leave
    else if (ltLow.indexOf('sick') >= 0) {
      var illness = detailPrefix.replace(/^(In Hospital|Out Patient)\s*[—-]?\s*/i, '').trim();
      if (detLow.indexOf('out patient') >= 0 || detLow.indexOf('outpatient') >= 0) {
        var op = document.getElementById('cb-sick-outpatient');
        if (op) op.className = 'csf-chk checked';
      } else {
        var ih = document.getElementById('cb-sick-hospital');
        if (ih) ih.className = 'csf-chk checked';
      }
      var si = document.getElementById('cb-sick-illness');
      if (si && illness) si.textContent = '— ' + illness;
    }
    // Special Leave Benefits for Women
    else if (ltLow.indexOf('women') >= 0 || ltLow.indexOf('special leave') >= 0 || ltLow.indexOf('benefit') >= 0) {
      var ill = detailPrefix.replace(/^Illness:\s*/i, '').trim();
      var wi = document.getElementById('cb-women-illness');
      if (wi) wi.textContent = ill || '___________________';
    }
    // Study leave
    else if (ltLow.indexOf('study') >= 0) {
      if (detLow.indexOf('bar') >= 0 || detLow.indexOf('board') >= 0) {
        var bar = document.getElementById('cb-study-bar');
        if (bar) bar.className = 'csf-chk checked';
      } else {
        var mas = document.getElementById('cb-study-masters');
        if (mas) mas.className = 'csf-chk checked';
      }
    }
    // Monetization / Terminal
    else if (detLow.indexOf('monetization') >= 0) {
      var mon = document.getElementById('cb-other-monetization');
      if (mon) mon.className = 'csf-chk checked';
    }
    else if (detLow.indexOf('terminal') >= 0) {
      var ter = document.getElementById('cb-other-terminal');
      if (ter) ter.className = 'csf-chk checked';
    }

    // 6C — days and dates
    set('cf-days',  r.days_requested + ' day' + (r.days_requested != 1 ? 's' : ''));
    set('cf-dates', fmtDateShort(r.date_from) + ' to ' + fmtDateShort(r.date_to));

    // 6D — applicant e-signature (snapshot from leave request)
    await setScannedSignature('cf-applicant-signature', r.signature_file_path || '');
    var applicantSigName = document.getElementById('cf-applicant-signature-name');
    if (applicantSigName) {
      applicantSigName.textContent = name || '';
    }

    // Reason — show clean version without the [detail] prefix
    set('cf-reason', cleanReason);

    // 7A — leave credits
    set('cf-as-of',   fmtDateShort(r.date_from));
    set('cf-vl-total', r.vl_total   || '—');
    set('cf-vl-used',  r.days_requested || '—');
    set('cf-vl-bal',   r.vl_remaining || '—');

    var statusNorm = (r.status || '').toLowerCase();

    // Header print metadata
    set('cf-ref-no', 'LR-' + String(r.request_id || id).padStart(6, '0'));
    set('cf-printed-at', new Date().toLocaleString());

    // 7B — recommendation based on status
    // 7A — CERTIFICATION OF LEAVE CREDITS: Always HRMO
    var hrmoName = r.hr_head_name && r.hr_head_name.trim() ? r.hr_head_name.trim() : 'HRMO';
    var hrmoLabel = r.hr_head_name && r.hr_head_name.trim() ? r.hr_head_name.trim() : 'HRMO';
    var certOfficer = document.getElementById('cf-authorized-officer');
    if (certOfficer) {
      certOfficer.textContent = hrmoLabel;
      certOfficer.style.textTransform = 'uppercase';
    }
    await setScannedSignature('cf-7a-signature', r.hr_head_signature_file_path || '');

    // 7B — Recommendation: Dept Head input
    var cbApprove = document.getElementById('cb-rec-approve');
    var cbDeny    = document.getElementById('cb-rec-deny');
    var denyReason = document.getElementById('cf-deny-reason');
    if (cbApprove) cbApprove.className = 'csf-chk' + (statusNorm === 'approved' ? ' checked' : '');
    if (cbDeny)    cbDeny.className    = 'csf-chk' + (statusNorm === 'denied'  ? ' checked' : '');
    if (denyReason) denyReason.textContent = (statusNorm === 'denied' && r.dept_head_remarks) ? r.dept_head_remarks : '';

    // 7B — Authorized Officer: Dept Head
    var deptHeadName = r.dept_head_name || '(Authorized Officer)';
    var authOfficer7b = document.getElementById('cf-7b-authorized-officer');
    if (authOfficer7b) {
      authOfficer7b.textContent = deptHeadName;
      authOfficer7b.style.textTransform = 'uppercase';
    }
    await setScannedSignature('cf-7b-signature', r.dept_head_signature_file_path || '');

    // 7D — Disapproved due to: HR/Admin input
    // (Already handled below in fillDisapproveLines with r.admin_remarks)

    // 7C/7D
    function fillDisapproveLines(text) {
      var lines = ['', '', ''];
      if (text) {
        var words = String(text).trim().split(/\s+/);
        var lineIndex = 0;
        words.forEach(function(word) {
          if (lineIndex > 2) return;
          var candidate = lines[lineIndex] ? (lines[lineIndex] + ' ' + word) : word;
          if (candidate.length > 52 && lineIndex < 2) {
            lineIndex += 1;
            lines[lineIndex] = word;
          } else {
            lines[lineIndex] = candidate;
          }
        });
      }
      setLine('cf-disapprove-1', lines[0] || '');
      setLine('cf-disapprove-2', lines[1] || '');
      setLine('cf-disapprove-3', lines[2] || '');
    }

    if (statusNorm === 'approved') {
      // Show days with pay or without pay based on r.with_pay
      if (r.with_pay === 0 || r.with_pay === '0') {
        setLine('cf-approved-pay', '');
        setLine('cf-approved-nopay', String(r.days_requested || ''));
      } else {
        setLine('cf-approved-pay', String(r.days_requested || ''));
        setLine('cf-approved-nopay', '');
      }
      setLine('cf-approved-others', '');
      fillDisapproveLines('');
    } else if (statusNorm === 'denied') {
      setLine('cf-approved-pay', '');
      setLine('cf-approved-nopay', '');
      setLine('cf-approved-others', '');
      fillDisapproveLines(r.admin_remarks || '');
    } else if (statusNorm === 'cancelled') {
      setLine('cf-approved-pay', '');
      setLine('cf-approved-nopay', '');
      setLine('cf-approved-others', '');
      fillDisapproveLines(r.admin_remarks || 'Cancelled leave request.');
    } else {
      setLine('cf-approved-pay', '');
      setLine('cf-approved-nopay', '');
      setLine('cf-approved-others', '');
      fillDisapproveLines('');
    }

    // Footer action buttons
    var closeBtnHtml = '<button class="btn btn-cancel" onclick="closeModal(\'modalView\')">Close</button>';
    var cancelBtnHtml = '';
    if (CAN_CANCEL_APPROVED && statusNorm === 'approved') {
      cancelBtnHtml = '<button class="btn" style="background:linear-gradient(135deg,#f97316,#ea580c);color:#fff;" onclick="closeModal(\'modalView\');openCancel(' + id + ')">⛔ Cancel Leave</button>';
    }
    // Show Approve/Deny if status starts with 'Pending' (case-insensitive)
    var showActions = (r.status || '').toLowerCase().startsWith('pending');
    document.getElementById('dFooter').innerHTML = showActions
      ? closeBtnHtml +
        '<button class="btn btn-danger" onclick="closeModal(\'modalView\');openDeny(' + id + ')">✗ Deny</button>' +
        '<button class="btn btn-success" onclick="closeModal(\'modalView\');openApprove(' + id + ')">✅ Approve</button>'
      : closeBtnHtml + cancelBtnHtml;

    openModal('modalView');
  }

  function printCsForm() {
    var formHtml = document.getElementById('csForm6').innerHTML;
    var w = window.open('', '_blank', 'width=900,height=1000');

    var css = [
      'body{font-family:Arial,sans-serif;font-size:7.5pt;color:#000;margin:0;padding:0;}',
      '@page{size:A4 portrait;margin:8mm 10mm;}',
      '.page-break{page-break-after:always;break-after:page;}',
      /* ── Form page (page 1) ── */
      '.csf-paper{padding:0;}',
      '.csf-header-row{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;margin-bottom:4pt;}',
      '.csf-header-left{font-size:.62rem;line-height:1.5;}',
      '.csf-header-center{display:flex;align-items:center;gap:8pt;justify-content:center;}',
      '.csf-logo-box{width:40pt;height:40pt;display:flex;align-items:center;justify-content:center;flex-shrink:0;}',
      '.csf-logo-box img{width:40pt;height:40pt;object-fit:contain;border-radius:50%;}',
      '.csf-header-right{text-align:right;font-size:.62rem;}',
      '.csf-stamp-box{border:1px solid #999;padding:3pt 6pt;font-size:5.5pt;display:inline-block;margin-top:2pt;min-width:70pt;text-align:center;}',
      '.csf-title{text-align:center;font-size:11pt;font-weight:700;letter-spacing:.05em;margin:3pt 0 5pt;text-transform:uppercase;}',
      '.csf-tbl{width:100%;border-collapse:collapse;}',
      '.csf-td{border:1px solid #000;padding:2pt 4pt;vertical-align:top;}',
      '.csf-section-bar{background:#ccc;border:1px solid #000;border-top:none;padding:2pt 6pt;font-weight:700;font-size:7pt;text-align:center;letter-spacing:.03em;}',
      '.csf-field-label{font-size:5.5pt;font-weight:700;text-transform:uppercase;letter-spacing:.02em;color:#333;margin-bottom:1pt;}',
      '.csf-field-val{font-size:7.5pt;font-weight:600;min-height:10pt;}',
      '.csf-name-cell{padding:2pt 4pt;}',
      '.csf-name-sub{font-size:.5pt;text-align:center;color:#555;margin-top:1pt;}',
      '.csf-checklist{display:flex;flex-direction:column;gap:1pt;}',
      '.csf-check-row{display:flex;align-items:flex-start;gap:3pt;font-size:6.2pt;line-height:1.35;margin-bottom:.5pt;}',
      '.csf-chk{display:inline-block;width:8pt;height:8pt;border:1px solid #000;flex-shrink:0;margin-top:.5pt;background:#fff;position:relative;}',
      '.csf-chk.checked::after{content:"\\2713";position:absolute;top:-1pt;left:.5pt;font-size:8pt;font-weight:900;color:#000;}',
      '.csf-chk.csf-chk-x::after{content:"\\2715";position:absolute;top:-1pt;left:.5pt;font-size:7pt;font-weight:900;color:#000;}',
      '.csf-detail-group{margin-bottom:4pt;}',
      '.csf-detail-sub{font-size:6pt;font-style:italic;font-weight:600;margin-bottom:1pt;}',
      /* ── Instructions page (page 2) ── */
      '.instr-page{font-family:"Times New Roman",Times,serif;font-size:9pt;color:#000;padding:0;line-height:1.2;page-break-inside:avoid;}',
      '.instr-title{text-align:center;font-size:10pt;font-weight:700;border:1.2pt solid #000;padding:3pt;margin-bottom:4pt;letter-spacing:.02em;}',
      '.instr-cols{display:grid;grid-template-columns:1fr 1fr;gap:0 10pt;}',
      '.instr-item{margin-bottom:3pt;}',
      '.instr-item-hd{font-size:9pt;font-weight:700;margin-bottom:1pt;}',
      '.instr-item p{margin:0 0 1pt;font-size:9pt;line-height:1.18;text-align:justify;}',
      '.instr-item ul{margin:1pt 0 0 9pt;padding:0;list-style:disc;}',
      '.instr-item ul li{font-size:9pt;line-height:1.18;margin-bottom:0;}',
      '.instr-footnote{font-size:8.5pt;border-top:1pt solid #000;padding-top:3pt;margin-top:4pt;font-style:italic;line-height:1.15;}',
      /* ── Table layout ── */
      '.csf-tbl{width:100%;border-collapse:collapse;margin-bottom:0;}',
      '.csf-td{border:1px solid #000;padding:2pt 4pt;vertical-align:top;}',
      '.csf-section-bar{background:#c8c8c8;border:1px solid #000;padding:2pt 6pt;font-weight:700;font-size:8pt;text-align:center;letter-spacing:.02em;}',
      '.csf-field-label{font-size:5.5pt;font-weight:700;text-transform:uppercase;letter-spacing:.02em;color:#222;margin-bottom:1pt;}',
      '.csf-field-val{font-size:8pt;font-weight:600;min-height:11pt;}',
      '.csf-name-cell{padding:2pt 4pt;}',
      '.csf-name-sub{font-size:.5pt;text-align:center;color:#555;margin-top:1pt;}',
      '.csf-logo-box{width:42pt;height:42pt;display:flex;align-items:center;justify-content:center;flex-shrink:0;}',
      '.csf-logo-box img{width:42pt;height:42pt;object-fit:contain;border-radius:50%;}',
      '.csf-stamp-box{border:1px solid #888;padding:3pt 5pt;font-size:5pt;text-align:center;min-width:60pt;display:inline-block;line-height:1.4;}',
      '.csf-title{text-align:center;font-size:11pt;font-weight:700;letter-spacing:.05em;margin:3pt 0 4pt;text-transform:uppercase;}',
      '.csf-checklist{display:flex;flex-direction:column;gap:.5pt;}',
      '.csf-check-row{display:flex;align-items:flex-start;gap:3pt;font-size:6.5pt;line-height:1.35;margin-bottom:.5pt;}',
      '.csf-chk{display:inline-block;width:8pt;height:8pt;border:1pt solid #000;flex-shrink:0;margin-top:.5pt;background:#fff;position:relative;}',
      '.csf-chk.checked::after{content:"\\2713";position:absolute;top:-1pt;left:.5pt;font-size:8pt;font-weight:900;color:#000;}',
      '.csf-chk.csf-chk-x::after{content:"\\2715";position:absolute;top:-1pt;left:.5pt;font-size:7pt;font-weight:900;color:#000;}',
      '.csf-detail-group{margin-bottom:4pt;}',
      '.csf-detail-sub{font-size:6pt;font-style:italic;font-weight:600;margin-bottom:1pt;}',
      '#cf-leave-checks .csf-chk,.csf-checklist .csf-chk{display:inline-block;width:8pt;height:8pt;border:1pt solid #000;flex-shrink:0;}'
    ].join('');

    /* ── Instructions & Requirements content ── */
    var instrHtml = [
      '<div class="instr-page">',
      '<div class="instr-title">INSTRUCTIONS AND REQUIREMENTS</div>',
      '<p style="font-size:9pt;margin:0 0 3pt;line-height:1.18;">Application for any type of leave shall be made on this Form and <u><strong>to be accomplished at least in duplicate</strong></u> with documentary requirements, as follows:</p>',
      '<div class="instr-cols">',

      /* LEFT COLUMN */
      '<div>',

      '<div class="instr-item"><div class="instr-item-hd">1. Vacation leave*</div>',
      '<p>It shall be filed five (5) days in advance, whenever possible, of the effective date of such leave. Vacation leave within the Philippines or abroad shall be indicated in the form for purposes of securing travel authority and completing clearance from money and work accountabilities.</p></div>',

      '<div class="instr-item"><div class="instr-item-hd">2. Mandatory/Forced leave</div>',
      '<p>Annual five-day vacation leave shall be forfeited if not taken during the year. In case the scheduled leave has been cancelled in the exigency of the service by the head of agency, it shall no longer be deducted from the accumulated vacation leave. Availment of one (1) day or more Vacation Leave (VL) shall be considered for complying the mandatory/forced leave subject to the conditions under Section 25, Rule XVI of the Omnibus Rules Implementing E.O. No. 292.</p></div>',

      '<div class="instr-item"><div class="instr-item-hd">3. Sick leave*</div><ul>',
      '<li>It shall be filed immediately upon employee\'s return from such leave.</li>',
      '<li>If filed in advance or exceeding five (5) days, application shall be accompanied by a <u>medical certificate</u>. In case medical consultation was not availed of, an <u>affidavit</u> should be executed by an applicant.</li>',
      '</ul></div>',

      '<div class="instr-item"><div class="instr-item-hd">4. Maternity leave* – 105 days</div><ul>',
      '<li>Proof of pregnancy e.g. ultrasound, doctor\'s certificate on the expected date of delivery</li>',
      '<li>Accomplished Notice of Allocation of Maternity Leave Credits (CS Form No. 6a), if needed</li>',
      '<li>Seconded female employees shall enjoy maternity leave with full pay in the recipient agency.</li>',
      '</ul></div>',

      '<div class="instr-item"><div class="instr-item-hd">5. Paternity leave – 7 days</div>',
      '<p>Proof of child\'s delivery e.g. birth certificate, medical certificate and marriage contract</p></div>',

      '<div class="instr-item"><div class="instr-item-hd">6. Special Privilege Leave – 3 days</div>',
      '<p>It shall be filed/approved for at least one (1) week prior to availment, except on emergency cases. Special privilege leave within the Philippines or abroad shall be indicated in the form for purposes of securing travel authority and completing clearance from money and work accountabilities.</p></div>',

      '<div class="instr-item"><div class="instr-item-hd">7. Solo Parent Leave – 7 days</div>',
      '<p>It shall be filed in advance or whenever possible five (5) days before going on such leave with updated Solo Parent Identification Card.</p></div>',

      '<div class="instr-item"><div class="instr-item-hd">8. Study leave* – up to 6 months</div><ul>',
      '<li>Shall meet the agency\'s internal requirements, if any;</li>',
      '<li>Contract between the agency head or authorized representative and the employee concerned.</li>',
      '</ul></div>',

      '<div class="instr-item"><div class="instr-item-hd">9. VAWC leave – 10 days</div><ul>',
      '<li>It shall be filed in advance or immediately upon the woman employee\'s return from such leave.</li>',
      '<li>It shall be accompanied by any of the following supporting documents:<br>a. Barangay Protection Order (BPO) obtained from the barangay;<br>b. Temporary/Permanent Protection Order (TPO/PPO) obtained from the court;<br>c. If the protection order is not yet issued by the barangay or the court, a certification issued by the Punong Barangay/Kagawad or Prosecutor or the Clerk of Court that the application for the BPO, TPO or PPO has been filed with the said office shall be sufficient to support the application for the ten-day leave; or<br>d. In the absence of the BPO/TPO/PPO or the certification, a police report specifying the details of the occurrence of violence on the victim and a medical certificate may be considered, at the discretion of the immediate supervisor of the woman employee concerned.</li>',
      '</ul></div>',

      '</div>', /* end left col */

      /* RIGHT COLUMN */
      '<div>',

      '<div class="instr-item"><div class="instr-item-hd">10. Rehabilitation leave* – up to 6 months</div><ul>',
      '<li>Application shall be made within one (1) week from the time of the accident except when a longer period is warranted.</li>',
      '<li>Letter request supported by relevant reports such as the police report, if any,</li>',
      '<li>Medical certificate on the nature of the injuries, the course of treatment involved, and the need to undergo rest, recuperation, and rehabilitation, as the case may be.</li>',
      '<li>Written concurrence of a government physician should be obtained relative to the recommendation for rehabilitation if the attending physician is a private practitioner, particularly on the duration of the period of rehabilitation.</li>',
      '</ul></div>',

      '<div class="instr-item"><div class="instr-item-hd">11. Special leave benefits for women* – up to 2 months</div><ul>',
      '<li>The application may be filed in advance, that is, at least five (5) days prior to the scheduled date of the gynecological surgery that will be undergone by the employee. In case of emergency, the application for special leave shall be filed immediately upon employee\'s return but during confinement the agency shall be notified of said surgery.</li>',
      '<li>The application shall be accompanied by a medical certificate filled out by the proper medical authorities, e.g. the attending surgeon accompanied by a clinical summary reflecting the gynecological disorder which shall be addressed or was addressed by the said surgery; the histopathological report; the operative technique used for the surgery; the duration of the surgery including the peri-operative period; as well as the employees estimated period of recuperation for the same.</li>',
      '</ul></div>',

      '<div class="instr-item"><div class="instr-item-hd">12. Special Emergency (Calamity) leave – up to 5 days</div><ul>',
      '<li>The special emergency leave can be applied for a maximum of five (5) straight working days or staggered basis within thirty (30) days from the actual occurrence of the natural calamity/disaster. Said privilege shall be enjoyed once a year, not in every instance of calamity or disaster.</li>',
      '<li>The head of office shall take full responsibility for the grant of special emergency leave and verification of the employee\'s eligibility to be granted thereof. Said verification shall include: validation of place of residence based on latest available records of the affected employee; verification that the place of residence is covered in the declaration of calamity area by the proper government agency; and such other proofs as may be necessary.</li>',
      '</ul></div>',

      '<div class="instr-item"><div class="instr-item-hd">13. Monetization of leave credits</div>',
      '<p>Application for monetization of fifty percent (50%) or more of the accumulated leave credits shall be accompanied by letter request to the head of the agency stating the valid and justifiable reasons.</p></div>',

      '<div class="instr-item"><div class="instr-item-hd">14. Terminal leave*</div>',
      '<p>Proof of employee\'s resignation or retirement or separation from the service.</p></div>',


      '<div class="instr-item"><div class="instr-item-hd">15. Adoption Leave</div><ul>',
      '<li>Application for adoption leave shall be filed with an authenticated copy of the Pre-Adoptive Placement Authority issued by the Department of Social Welfare and Development (DSWD).</li>',
      '</ul></div>',

      '</div>', /* end right col */
      '</div>', /* end instr-cols */

      '<div class="instr-footnote">* For leave of absence for thirty (30) calendar days or more and terminal leave, application shall be accompanied by a <u>clearance from money, property and work-related accountabilities</u> (pursuant to CSC Memorandum Circular No. 2, s. 1985).</div>',
      '</div>'
    ].join('');

    w.document.write(
      '<!DOCTYPE html><html><head>' +
      '<title>Application for Leave \u2014 CS Form No. 6</title>' +
      '<style>' + css + '</style>' +
      '</head><body>' +
      /* Page 1: the filled form */
      '<div class="csf-paper page-break">' + formHtml + '</div>' +
      /* Page 2: Instructions */
      instrHtml +
      '</body></html>'
    );
    w.document.close();
    w.focus();

    setTimeout(function() { w.print(); }, 500);
  }
  

  function openAdd(){
    ['fEmployee','fLeaveType','fFrom','fTo','fReason'].forEach(id=>{const el=document.getElementById(id);if(el){el.value='';el.classList.remove('err');}});
    document.getElementById('fDays').value='';
    document.getElementById('fOthersConfirm').checked = false;
    openModal('modalForm');
  }

  async function saveRequest(){
    const fields=[['fEmployee'],['fLeaveType'],['fFrom'],['fTo'],['fDays']];
    let valid=true;
    fields.forEach(([id])=>{const el=document.getElementById(id);if(!el.value){el.classList.add('err');valid=false;}else el.classList.remove('err');});
    if(!valid){showToast('⚠️ Please fill in all required fields.','info');return;}
    const btn=document.getElementById('submitBtn');btn.disabled=true;btn.textContent='⏳ Submitting…';
    const body=new FormData();
    body.append('action','save');
    body.append('employee_id',document.getElementById('fEmployee').value);
    body.append('leave_type_id',document.getElementById('fLeaveType').value);
    body.append('date_from',document.getElementById('fFrom').value);
    body.append('date_to',document.getElementById('fTo').value);
    body.append('days',document.getElementById('fDays').value);
    let reason = document.getElementById('fReason').value.trim();
    // If Others leave type is confirmed, append checkbox indicator
    if (document.getElementById('fOthersConfirm').checked) {
      reason = '[Others Confirmed ☑] ' + reason;
    }
    body.append('reason',reason);
    try{
      if (!CSRF_TOKEN) CSRF_TOKEN = initCSRFTokenAdmin();
      const res=await fetch('leave_requests.php',{method:'POST',headers:{'X-CSRF-TOKEN':CSRF_TOKEN},body});
      const json=await res.json();
      if(json.success){showToast('✅ '+json.message,'success');closeModal('modalForm');loadStats();loadRequests(1);}
      else showToast('❌ '+json.message,'error');
    }catch(e){showToast('❌ Submission failed.','error');}
    finally{btn.disabled=false;btn.textContent='📤 Submit Request';}
  }

  async function openApprove(id){
    const res=await fetch(`leave_requests.php?action=get&id=${id}`);
    const json=await res.json();if(!json.success)return;
    const r=json.data;approveTarget=id;
    document.getElementById('approveText').textContent=`Approving ${r.leave_type} Leave for ${r.first_name} ${r.last_name} (${fmt(r.date_from)} – ${fmt(r.date_to)}, ${r.days_requested} day${r.days_requested!=1?'s':''}).`;
    document.getElementById('approveRemark').value='';
    openModal('modalApprove');
  }

  async function confirmApprove(){
    const btn=document.getElementById('approveBtn');btn.disabled=true;btn.textContent='⏳ Processing…';
    const body=new FormData();body.append('action','approve');body.append('id',approveTarget);body.append('remark',document.getElementById('approveRemark').value.trim());
    try{
      if (!CSRF_TOKEN) CSRF_TOKEN = initCSRFTokenAdmin();
      const res=await fetch('leave_requests.php',{method:'POST',headers:{'X-CSRF-TOKEN':CSRF_TOKEN},body});
      const json=await res.json();
      if(json.success){showToast('✅ Leave request approved.','success');closeModal('modalApprove');loadStats();loadRequests(currentPage);}
      else showToast('❌ '+json.message,'error');
    }catch(e){showToast('❌ Failed.','error');}
    finally{btn.disabled=false;btn.textContent='✅ Yes, Approve';}
  }

  async function openDeny(id){
    const res=await fetch(`leave_requests.php?action=get&id=${id}`);
    const json=await res.json();if(!json.success)return;
    const r=json.data;denyTarget=id;
    document.getElementById('denyText').textContent=`Denying ${r.leave_type} Leave for ${r.first_name} ${r.last_name} (${fmt(r.date_from)} – ${fmt(r.date_to)}, ${r.days_requested} day${r.days_requested!=1?'s':''}).`;
    document.getElementById('denyRemark').value='';
    openModal('modalDeny');
  }

  async function confirmDeny(){
    const btn=document.getElementById('denyBtn');btn.disabled=true;btn.textContent='⏳ Processing…';
    const body=new FormData();body.append('action','deny');body.append('id',denyTarget);body.append('remark',document.getElementById('denyRemark').value.trim());
    try{
      if (!CSRF_TOKEN) CSRF_TOKEN = initCSRFTokenAdmin();
      const res=await fetch('leave_requests.php',{method:'POST',headers:{'X-CSRF-TOKEN':CSRF_TOKEN},body});
      const json=await res.json();
      if(json.success){showToast('Leave request denied.','error');closeModal('modalDeny');loadStats();loadRequests(currentPage);}
      else showToast('❌ '+json.message,'error');
    }catch(e){showToast('❌ Failed.','error');}
    finally{btn.disabled=false;btn.textContent='✗ Yes, Deny';}
  }

  async function openCancel(id){
    if (!CAN_CANCEL_APPROVED) {
      showToast('❌ Only HR/Admin can cancel approved leave requests.','error');
      return;
    }
    const res=await fetch(`leave_requests.php?action=get&id=${id}`);
    const json=await res.json();if(!json.success)return;
    const r=json.data;
    if ((r.status || '').toLowerCase() !== 'approved') {
      showToast('⚠️ Only approved leave requests can be cancelled.','info');
      return;
    }
    cancelTarget=id;
    document.getElementById('cancelText').textContent=`Cancelling ${r.leave_type} Leave for ${r.first_name} ${r.last_name} (${fmt(r.date_from)} – ${fmt(r.date_to)}, ${r.days_requested} day${r.days_requested!=1?'s':''}). Credits will be restored.`;
    document.getElementById('cancelReason').value='';
    openModal('modalCancel');
  }

  async function confirmCancel(){
    const reason = document.getElementById('cancelReason').value.trim();
    if (!reason) {
      showToast('⚠️ Cancellation reason is required.','info');
      return;
    }
    const btn=document.getElementById('cancelBtn');btn.disabled=true;btn.textContent='⏳ Processing…';
    const body=new FormData();body.append('action','cancel');body.append('id',cancelTarget);body.append('reason',reason);
    try{
      if (!CSRF_TOKEN) CSRF_TOKEN = initCSRFTokenAdmin();
      const res=await fetch('leave_requests.php',{method:'POST',headers:{'X-CSRF-TOKEN':CSRF_TOKEN},body});
      const json=await res.json();
      if(json.success){showToast('✅ '+(json.message || 'Leave request cancelled.'),'success');closeModal('modalCancel');loadStats();loadRequests(currentPage);}
      else showToast('❌ '+json.message,'error');
    }catch(e){showToast('❌ Failed.','error');}
    finally{btn.disabled=false;btn.textContent='⛔ Yes, Cancel Leave';}
  }

  function openModal(id){document.getElementById(id).classList.add('open');}
  function closeModal(id){document.getElementById(id).classList.remove('open');}
  document.querySelectorAll('.modal-overlay').forEach(m=>{m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('open');});});
  function showToast(msg,type='success'){document.querySelectorAll('.toast').forEach(t=>t.remove());const t=document.createElement('div');t.className=`toast ${type}`;t.textContent=msg;document.body.appendChild(t);setTimeout(()=>{t.style.opacity='0';t.style.transition='opacity 0.3s';setTimeout(()=>t.remove(),300);},3200);}
  

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
function toggleProfileDropdown(){
  const dd=document.getElementById('tbDropdown');if(dd)dd.classList.toggle('open');
}
document.addEventListener('click',function(e){
  const w=document.getElementById('tbProfileWrap');
  if(w&&!w.contains(e.target)){const dd=document.getElementById('tbDropdown');if(dd)dd.classList.remove('open');}
});

// ── Poll admin notifications every 60 seconds ──────────────────────────────
function dEsc(s) {
  const _d = document.createElement('div');
  _d.textContent = String(s ?? '');
  return _d.innerHTML;
}

async function pollAdminNotifications() {
  try {
    const r2 = await fetch('notif_poll.php').then(r=>r.json()).catch(()=>null);
    if (!r2 || !r2.success) {
      // Fallback: just update the count badge from current DOM state
      const unread = getUnreadCount();
      const cnt = document.getElementById('adminNotifCount');
      if (cnt) {
        if (unread > 0) { cnt.textContent = unread; cnt.classList.add('show'); }
        else            { cnt.textContent = '';      cnt.classList.remove('show'); }
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
          const isRead    = read.includes(n.nid);
          const safeNid   = dEsc(String(n.nid || ''));
          const safeColor = /^#[0-9a-fA-F]{3,6}$/.test(n.color) ? n.color : '#94a3b8';
          const safeLinkRaw = String(n.link || '#');
          const safeLink  = /^[a-zA-Z0-9_./?=&#%-]+$/.test(safeLinkRaw) ? safeLinkRaw : '#';
          return `<a class="notif-item ${isRead?'':'unread'}" href="${safeLink}" data-nid="${safeNid}" onclick="markNotifRead(this.getAttribute('data-nid'));applyReadState();">
            <div class="notif-ico" style="background:${safeColor}22;">${dEsc(n.icon)}</div>
            <div class="notif-body">
              <div class="notif-title">${dEsc(n.title)}</div>
              <div class="notif-text">${dEsc(n.body)}</div>
              ${n.time ? '<div class="notif-time">'+adminFmtTime(dEsc(n.time))+'</div>' : ''}
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

function toggleSidebar(){document.getElementById('sidebar').classList.toggle('open');document.getElementById('overlayMob').classList.toggle('show');}

  async function init(){
    const dr=await fetch('leave_requests.php?action=departments');
    const dj=await dr.json();
    if(dj.success)document.getElementById('fDept').innerHTML+= dj.data.map(d=>`<option>${d}</option>`).join('');

    const lr=await fetch('leave_requests.php?action=leave_types');
    const lj=await lr.json();
    if(lj.success){
      document.getElementById('fType').innerHTML+=lj.data.map(t=>`<option>${t.name}</option>`).join('');
      document.getElementById('fLeaveType').innerHTML+=lj.data.map(t=>`<option value="${t.leave_type_id}">${t.name}</option>`).join('');
    }

    const er=await fetch('leave_requests.php?action=employees');
    const ej=await er.json();
    if(ej.success)document.getElementById('fEmployee').innerHTML+=ej.data.map(e=>`<option value="${e.employee_id}">${e.full_name}</option>`).join('');

    await loadStats();
    await loadRequests(1);

    ['fFrom','fTo'].forEach(id=>{
      document.getElementById(id).addEventListener('change',()=>{
        const f=document.getElementById('fFrom').value,t=document.getElementById('fTo').value;
        if(f&&t){const diff=Math.round((new Date(t)-new Date(f))/(864e5))+1;if(diff>0)document.getElementById('fDays').value=diff;}
      });
    });
  }
  init();

  /* ── Reason helpers ── */
  function rcBuild(txt, id) {
    txt = (txt||'').trim();
    if (!txt) return '<em style="color:#cbd5e1;font-size:.76rem;">—</em>';
    var s = txt.length > 50 ? txt.slice(0,50)+'…' : txt;
    var more = txt.length > 50
      ? '<br><span class="rc-more" onclick="rcOpen('+id+')">…more</span>'
      : '';
    return '<span class="rc-txt" onclick="rcOpen('+id+')">'+s+'</span>'+more;
  }
  function rcOpen(id) {
    var t = window['_r'+id]||'';
    document.getElementById('rcBody').textContent = t;
    document.getElementById('rcModal').classList.add('on');
  }
  function rcClose() {
    document.getElementById('rcModal').classList.remove('on');
  }
  document.addEventListener('keydown',function(e){if(e.key==='Escape')rcClose();});
  </script>

  <style>
    .rc-col{max-width:200px;padding:10px 14px !important;}
    .rc-txt{font-size:.78rem;color:#475569;line-height:1.5;cursor:pointer;display:block;padding:3px 6px;border-radius:5px;}
    .rc-txt:hover{background:#f1f5f9;}
    .rc-more{font-size:.65rem;font-weight:800;color:#2563eb;background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;padding:1px 7px;cursor:pointer;display:inline-block;margin-top:3px;}
    .rc-more:hover{background:#2563eb;color:#fff;}
    #rcModal{display:none;position:fixed;inset:0;background:rgba(15,23,42,.52);z-index:9999;align-items:center;justify-content:center;}
    #rcModal.on{display:flex;}
    #rcBox{background:#fff;border-radius:14px;padding:26px 24px 22px;max-width:420px;width:90%;box-shadow:0 16px 48px rgba(0,0,0,.22);position:relative;}
    #rcX{position:absolute;top:12px;right:12px;width:28px;height:28px;border-radius:50%;background:#f1f5f9;border:none;font-size:.85rem;cursor:pointer;color:#64748b;}
    #rcX:hover{background:#e2e8f0;}
    #rcLbl{font-size:.60rem;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.10em;margin-bottom:10px;}
    #rcBody{font-size:.88rem;color:#1e293b;line-height:1.75;white-space:pre-wrap;word-break:break-word;max-height:320px;overflow-y:auto;}
  </style>

  <div id="rcModal" onclick="if(event.target===this)rcClose()">
    <div id="rcBox">
      <button id="rcX" onclick="rcClose()">&#x2715;</button>
      <div id="rcLbl">&#x1F4CB; Leave Reason / Remarks</div>
      <div id="rcBody"></div>
    </div>
  </div>

  <!-- ══ CHAT WIDGET ══ -->
  <style>
    /* ── Wrap & animation ── */
    .cw-wrap{position:fixed;bottom:24px;right:24px;width:420px;height:600px;background:#fff;border-radius:18px;box-shadow:0 20px 60px rgba(0,0,0,.28),0 0 1px rgba(0,0,0,.1);display:none;flex-direction:column;z-index:9999;overflow:hidden;font-family:'Nunito',sans-serif;-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px);}
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
        .cw-sendbtn{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#2563eb,#1e40af);color:#fff;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.95rem;font-weight:600;position:relative;overflow:hidden;}
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
          .cw-hd-icon{width:36px;height:36px;font-size:.95rem;}
          .cw-hd-close{width:30px;height:30px;font-size:.85rem;}
          .cw-chat-hd{padding:10px 12px;gap:8px;}
          .cw-chat-av{width:32px;height:32px;font-size:.60rem;}
          .cw-chat-name{font-size:.80rem;}
          .cw-chat-sub{font-size:.58rem;}
          .cw-msgs{padding:11px 9px;gap:6px;}
          .cw-mav{width:22px;height:22px;font-size:.50rem;}
          .cw-bubble{padding:9px 12px;font-size:.77rem;line-height:1.4;}
          .cw-inp-row{padding:9px 10px;gap:8px;}
          .cw-inp{font-size:.78rem;padding:8px 12px;}
          .cw-sendbtn{width:36px;height:36px;font-size:.88rem;}
          .cw-edit-bar{padding:7px 10px;font-size:.70rem;gap:6px;}
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
          .cw-mav{width:20px;height:20px;font-size:.44rem;}
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
          .cw-clist{max-height:42vh;padding:5px;}
          .cw-ci{width:58px;padding:5px 2px;}
          .cw-av{width:34px;height:34px;font-size:.58rem;}
          .cw-cn{font-size:.60rem;max-width:54px;}
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
    
        /* Extra Small Phones (380px and below) */
        @media (max-width:380px){
          .cw-clist{max-height:40vh;padding:5px;}
          .cw-ci{width:54px;padding:5px 2px;}
          .cw-av{width:32px;height:32px;font-size:.54rem;}
          .cw-cn{font-size:.56rem;max-width:50px;}
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
    document.getElementById('cwMsgs').innerHTML='<div style="text-align:center;padding:24px;font-size:.73rem;color:#94a3b8;line-height:1.7;">Loading...</div>';
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
      actions='<div class="row-actions">'+
        '<button class="view-btn" onclick="viewRequest('+m.message_id+')">View</button>'+
        '<button class="act-btn deny" style="margin-left:4px;" onclick="confirmDelete('+m.message_id+', \''+name.replace(/'/g, "&#39;")+'\')">Delete</button>'+
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
  </script>

</body>
</html>