<?php
// ============================================================
//  LGU ENRILE HRMS — Employee Portal
//  File: Employee/Employee_portal.php
// ============================================================
session_start();

// ── Helper: detect AJAX action request ──
$_isAjax = !empty($_GET['action']) || !empty($_POST['action']);

if (empty($_SESSION['user_id']) || empty($_SESSION['user_email'])) {
    if ($_isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'message'=>'SESSION_MISSING: Not logged in. user_id='.($_SESSION['user_id']??'EMPTY').' role='.($_SESSION['user_role']??'EMPTY')]);
        exit();
    }
    header('Location: ../Login/login.php'); exit();
}
if ($_SESSION['user_role'] !== 'employee' && empty($_SESSION['switched_from_admin'])) {
    if ($_isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'message'=>'WRONG_ROLE: Your role is "'.($_SESSION['user_role']??'EMPTY').'", expected "employee". Check user_accounts table.']);
        exit();
    }
    header('Location: ../Admin/dashboard.php'); exit();
}

// Session timeout — 8 hours
$sessionLifetime = 8 * 60 * 60;
if (isset($_SESSION['login_time'])) {
    if (time() - strtotime($_SESSION['login_time']) > $sessionLifetime) {
        if ($_isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success'=>false,'message'=>'SESSION_EXPIRED: Please log in again.']);
            exit();
        }
        session_unset(); session_destroy();
        header('Location: ../Login/login.php?error=' . urlencode('Your session has expired.')); exit();
    }
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';

// Tables `pds_records` and `saln_records` are defined in lgu_enrile_hrms.sql — no runtime CREATE needed.

// Upload directory
define('UPLOAD_DIR', __DIR__ . '/../uploads/documents/');
define('UPLOAD_URL', '../uploads/documents/');
if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
define('PHOTO_DIR', __DIR__ . '/../uploads/profile_photos/');
define('PHOTO_URL', '../uploads/profile_photos/');
if (!is_dir(PHOTO_DIR)) mkdir(PHOTO_DIR, 0755, true);

$allowedMimes = [
    'application/pdf'  => 'pdf',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
];
$maxFileSize = 10 * 1024 * 1024; // 10 MB

// ════════════════════════════════════════════════════════════
//  AJAX HANDLER
// ════════════════════════════════════════════════════════════
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action) {
    ob_start();
    @ini_set('display_errors', 0);
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_verify(true); }
    $userId = (int)$_SESSION['user_id'];

    try {
        // Resolve employee_id from user_id
        $empRow = $pdo->prepare("SELECT employee_id FROM employees WHERE user_id = ?");
        $empRow->execute([$userId]);
        $employeeId = (int)($empRow->fetchColumn() ?: 0);

        switch ($action) {

            // ── PROFILE ──────────────────────────────────────
            case 'whoami':
                // Diagnostic — shows session linkage
                $allEmp = $pdo->query("SELECT employee_id, CONCAT(first_name,' ',last_name) AS name, user_id, email_address FROM employees ORDER BY employee_id")->fetchAll();
                $allUsr = $pdo->query("SELECT user_id, email, `role` AS user_role FROM user_accounts WHERE `role`='employee' ORDER BY user_id")->fetchAll();
                echo json_encode([
                    'success'      => true,
                    'session_user_id'   => $userId,
                    'resolved_employee_id' => $employeeId,
                    'all_employees' => $allEmp,
                    'employee_users'=> $allUsr,
                ]);
                break;

            case 'profile':
                $stmt = $pdo->prepare("
                    SELECT e.*, d.name AS department_name
                    FROM   employees e
                    LEFT JOIN departments d ON d.department_id = e.department_id
                    WHERE  e.user_id = ?
                ");
                $stmt->execute([$userId]);
                $row = $stmt->fetch();
                if (!$row) {
                    // Try matching by email as fallback
                    $stmt2 = $pdo->prepare("
                        SELECT e.*, d.name AS department_name
                        FROM   employees e
                        LEFT JOIN departments d ON d.department_id = e.department_id
                        JOIN   user_accounts u ON u.user_id = ?
                        WHERE  e.email_address = u.email
                        LIMIT  1
                    ");
                    $stmt2->execute([$userId]);
                    $row = $stmt2->fetch();
                    // If found via email, link the user_id for future logins
                    if ($row) {
                        $pdo->prepare("UPDATE employees SET user_id = ? WHERE employee_id = ?")
                            ->execute([$userId, $row['employee_id']]);
                    }
                }
                if (!$row) {
                    echo json_encode([
                        'success' => false,
                        'message' => 'No employee record is linked to your account (user_id=' . $userId . '). Please contact HR Admin to link your account.',
                        'data'    => null
                    ]);
                } else {
                    echo json_encode(['success' => true, 'data' => $row]);
                }
                break;

            // ── LEAVE BALANCES ────────────────────────────────
            case 'balances':
                // ── CHECK EMPLOYMENT TYPE: Only Permanent can view leave balances ──
                $empTypeCheck = $pdo->prepare("SELECT employment_type FROM employees WHERE employee_id = ?");
                $empTypeCheck->execute([$employeeId]);
                $empTypeRow = $empTypeCheck->fetch();
                $empType = $empTypeRow['employment_type'] ?? 'Unknown';
                if ($empType !== 'Permanent') {
                    echo json_encode(['success' => false, 'message' => "Leave balances are only available to Permanent employees. You are employed as: {$empType}."]); break;
                }
                // ── AUTO-INITIALIZE LEAVE BALANCES FOR NEW EMPLOYEES OR NEW CALENDAR YEAR ──
                $checkBal = $pdo->prepare("SELECT COUNT(*) FROM leave_balances WHERE employee_id = ? AND calendar_year = YEAR(NOW())");
                $checkBal->execute([$employeeId]);
                $hasBalance = (int)($checkBal->fetchColumn() ?: 0) > 0;
                
                if (!$hasBalance && $employeeId) {
                    // Get all active leave types
                    $ltypes = $pdo->query("SELECT leave_type_id, max_days FROM leave_types WHERE is_active = 1")->fetchAll();
                    foreach ($ltypes as $lt) {
                        $pdo->prepare("
                            INSERT INTO leave_balances (employee_id, leave_type_id, calendar_year, total_credits, used_days)
                            VALUES (?, ?, YEAR(NOW()), ?, 0)
                            ON DUPLICATE KEY UPDATE total_credits = VALUES(total_credits)
                        ")->execute([$employeeId, $lt['leave_type_id'], $lt['max_days']]);
                    }
                }
                
                // Get employee gender for filtering
                $empBal = $pdo->prepare("SELECT gender FROM employees WHERE employee_id = ?");
                $empBal->execute([$employeeId]);
                $empBalRow    = $empBal->fetch();
                $balGender    = strtolower($empBalRow['gender'] ?? '');

                $stmt = $pdo->prepare("
                    SELECT lb.balance_id, lb.total_credits, lb.used_days, lb.remaining_days,
                           lt.leave_type_id, lt.name AS type_name, lt.max_days,
                           lt.applicable_gender, lt.require_married
                    FROM   leave_balances lb
                    JOIN   leave_types lt ON lt.leave_type_id = lb.leave_type_id
                    WHERE  lb.employee_id = ? AND lb.calendar_year = YEAR(NOW())
                    ORDER  BY lt.name ASC
                ");
                $stmt->execute([$employeeId]);
                $balances = $stmt->fetchAll();

                // Filter out wrong-gender types; flag married-required ones
                $balances = array_values(array_filter($balances, function($b) use ($balGender) {
                    $ag = $b['applicable_gender'] ?? 'All';
                    if ($ag === 'Male'   && $balGender !== 'male')   return false;
                    if ($ag === 'Female' && $balGender !== 'female') return false;
                    return true;
                }));

                // Calculate pending days for each leave type to account for pending requests
                $pendingStmt = $pdo->prepare("
                    SELECT leave_type_id, COALESCE(SUM(days_requested), 0) AS pending_days
                    FROM leave_requests
                    WHERE employee_id = ? AND status = 'Pending' AND YEAR(filed_at) = YEAR(NOW())
                    GROUP BY leave_type_id
                ");
                $pendingStmt->execute([$employeeId]);
                $pendingByType = [];
                foreach ($pendingStmt->fetchAll() as $row) {
                    $pendingByType[$row['leave_type_id']] = (int)$row['pending_days'];
                }

                foreach ($balances as &$b) {
                    // Add pending days info
                    $leaveTypeId = $b['leave_type_id'];
                    $b['pending_days'] = $pendingByType[$leaveTypeId] ?? 0;
                    
                    // Calculate available days accounting for pending requests
                    $b['available_after_pending'] = max(0, $b['remaining_days'] - $b['pending_days']);

                    $hist = $pdo->prepare("
                        SELECT date_from, date_to, days_requested, status
                        FROM   leave_requests
                        WHERE  employee_id = ? AND leave_type_id = ?
                          AND  status = 'Approved'
                        ORDER  BY date_from DESC
                        LIMIT  3
                    ");
                    $hist->execute([$employeeId, $b['leave_type_id']]);
                    $b['history'] = $hist->fetchAll();
                }
                unset($b);

                echo json_encode(['success' => true, 'data' => $balances]);
                break;

            // ── UPLOAD PROFILE PHOTO ─────────────────────────
            case 'upload_photo':
                if (!$employeeId) { echo json_encode(['success'=>false,'message'=>'Employee not found.']); break; }
                if (empty($_FILES['photo'])) { echo json_encode(['success'=>false,'message'=>'No file received.']); break; }
                $f = $_FILES['photo'];
                if ($f['error'] !== UPLOAD_ERR_OK) { echo json_encode(['success'=>false,'message'=>'Upload error.']); break; }
                if ($f['size'] > 10*1024*1024) { echo json_encode(['success'=>false,'message'=>'Max 10MB.']); break; }
                $fi = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($fi, $f['tmp_name']); finfo_close($fi);
                $imgMimes = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
                if (!array_key_exists($mime, $imgMimes)) { echo json_encode(['success'=>false,'message'=>'Only JPG/PNG/GIF/WEBP allowed.']); break; }
                $ext  = $imgMimes[$mime];
                $name = 'emp_'.$employeeId.'_'.time().'.'.$ext;
                $dest = PHOTO_DIR.$name;
                $old = $pdo->prepare("SELECT profile_photo FROM employees WHERE employee_id=?");
                $old->execute([$employeeId]);
                $oldPhoto = $old->fetchColumn();
                if ($oldPhoto) {
                    $oldPath = __DIR__.'/../'.ltrim(str_replace('../','',$oldPhoto),'/');
                    if (file_exists($oldPath)) @unlink($oldPath);
                }
                if (!move_uploaded_file($f['tmp_name'], $dest)) { echo json_encode(['success'=>false,'message'=>'Save failed.']); break; }
                $photoUrl = PHOTO_URL.$name;
                try { $pdo->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS profile_photo VARCHAR(255) DEFAULT NULL"); } catch(Exception $e) {}
                $pdo->prepare("UPDATE employees SET profile_photo=? WHERE employee_id=?")->execute([$photoUrl,$employeeId]);
                echo json_encode(['success'=>true,'photo_url'=>$photoUrl,'url'=>$photoUrl]);
                break;

            // ── UPDATE PERSONAL INFO ─────────────────────────
            case 'update_personal':
                if (!$employeeId) {
                    echo json_encode(['success'=>false,'message'=>'Employee record not found.']); break;
                }
                $allowed = ['first_name','last_name','middle_name','gender',
                            'date_of_birth','civil_status','home_address',
                            'contact_number','email_address'];
                $sets = []; $vals = [];
                foreach ($allowed as $f) {
                    if (array_key_exists($f, $_POST)) {
                        $sets[] = "$f = ?";
                        $v = trim($_POST[$f] ?? '');
                        $vals[] = ($v === '') ? null : $v;
                    }
                }
                if (empty($sets)) {
                    echo json_encode(['success'=>false,'message'=>'Nothing to update.']); break;
                }
                $vals[] = $employeeId;
                $pdo->prepare("UPDATE employees SET ".implode(',',$sets)." WHERE employee_id = ?")
                    ->execute($vals);
                echo json_encode(['success'=>true,'message'=>'Personal information updated.']);
                break;

            // ── LEAVE REQUESTS ────────────────────────────────
            case 'requests':
                if (!$employeeId) {
                    echo json_encode(['success' => false, 'message' => 'No employee record linked to your account. Please contact HR to link your account.']); break;
                }
                
                // ── CHECK EMPLOYMENT TYPE: Only Permanent can view leave requests ──
                $empTypeCheck = $pdo->prepare("SELECT employment_type FROM employees WHERE employee_id = ?");
                $empTypeCheck->execute([$employeeId]);
                $empTypeRow = $empTypeCheck->fetch();
                $empType = $empTypeRow['employment_type'] ?? 'Unknown';
                if ($empType !== 'Permanent') {
                    echo json_encode(['success' => false, 'message' => "Leave requests are only available to Permanent employees. You are employed as: {$empType}."]); break;
                }
                
                $status = $_GET['status'] ?? 'all';
                $where  = 'lr.employee_id = ?';
                $params = [$employeeId];
                if ($status !== 'all') { $where .= ' AND lr.status = ?'; $params[] = ucfirst($status); }

                $stmt = $pdo->prepare("
                    SELECT lr.*, lt.name AS type_name
                    FROM   leave_requests lr
                    JOIN   leave_types lt ON lt.leave_type_id = lr.leave_type_id
                    WHERE  $where
                    ORDER  BY lr.filed_at DESC
                ");
                $stmt->execute($params);
                $rows = $stmt->fetchAll();

                $counts = $pdo->prepare("
                    SELECT status, COUNT(*) AS cnt
                    FROM   leave_requests WHERE employee_id = ?
                    GROUP  BY status
                ");
                $counts->execute([$employeeId]);
                $statusCounts = ['Pending'=>0,'Approved'=>0,'Denied'=>0,'Cancelled'=>0];
                foreach ($counts->fetchAll() as $sc) {
                    $statusCounts[$sc['status']] = (int)$sc['cnt'];
                }

                ob_clean();
                echo json_encode(['success' => true, 'data' => $rows, 'counts' => $statusCounts]);
                break;

            // ── SUBMIT LEAVE ──────────────────────────────────
            case 'submit_leave':
                if (!$employeeId) {
                    echo json_encode(['success' => false, 'message' => 'Employee record not found.']); break;
                }
                
                // ── CHECK EMPLOYMENT TYPE: Only Permanent can file leave ──
                $empTypeCheck = $pdo->prepare("SELECT employment_type FROM employees WHERE employee_id = ?");
                $empTypeCheck->execute([$employeeId]);
                $empTypeRow = $empTypeCheck->fetch();
                $empType = $empTypeRow['employment_type'] ?? 'Unknown';
                if ($empType !== 'Permanent') {
                    echo json_encode(['success' => false, 'message' => "Only Permanent employees can file leave requests. Your employment type is: {$empType}. Please contact HR for more information."]); break;
                }
                
                $leaveTypeId = (int)($_POST['leave_type_id']    ?? 0);
                $dateFrom    = $_POST['date_from']              ?? '';
                $dateTo      = $_POST['date_to']                ?? '';
                $daysReq     = (int)($_POST['days_requested']   ?? 0);
                $reason      = trim($_POST['reason']            ?? '');

                if (!$leaveTypeId || !$dateFrom || !$dateTo || !$reason || $daysReq < 1) {
                    echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']); break;
                }

                // ── Server-side gender / marital eligibility check ──────────
                $ltCheck = $pdo->prepare("SELECT applicable_gender, require_married FROM leave_types WHERE leave_type_id = ? AND is_active = 1");
                $ltCheck->execute([$leaveTypeId]);
                $ltRow = $ltCheck->fetch();
                if (!$ltRow) {
                    echo json_encode(['success' => false, 'message' => 'Leave type not found or inactive.']); break;
                }
                $empCheck = $pdo->prepare("SELECT gender, civil_status FROM employees WHERE employee_id = ?");
                $empCheck->execute([$employeeId]);
                $empChkRow = $empCheck->fetch();
                $chkGender = strtolower($empChkRow['gender'] ?? '');
                $chkCivil  = strtolower($empChkRow['civil_status'] ?? '');
                if ($ltRow['applicable_gender'] === 'Male'   && $chkGender !== 'male') {
                    echo json_encode(['success' => false, 'message' => 'This leave type is for male employees only.']); break;
                }
                if ($ltRow['applicable_gender'] === 'Female' && $chkGender !== 'female') {
                    echo json_encode(['success' => false, 'message' => 'This leave type is for female employees only.']); break;
                }
                if ((int)$ltRow['require_married'] === 1 && $chkCivil !== 'married') {
                    echo json_encode(['success' => false, 'message' => 'This leave type requires married status. Please update your civil status with HR.']); break;
                }
                // ── End eligibility check ────────────────────────────────────

                // ── Check for past dates ──────────────────────────────────
                $today = (new DateTime())->format('Y-m-d');
                if ($dateFrom < $today) {
                    echo json_encode(['success' => false, 'message' => '🚫 Cannot apply for leave on past dates. Please select today or a future date.']); break;
                }
                
                // ── Check for overlapping leaves (any leave type) ──────────────
                $overlapCheck = $pdo->prepare("
                    SELECT COUNT(*) as cnt FROM leave_requests
                    WHERE employee_id = ? 
                      AND (status = 'Pending' OR status = 'Approved')
                      AND (
                        (date_from <= ? AND date_to >= ?)
                        OR (date_from >= ? AND date_from <= ?)
                      )
                ");
                $overlapCheck->execute([$employeeId, $dateTo, $dateFrom, $dateFrom, $dateTo]);
                $overlapCount = (int)($overlapCheck->fetchColumn() ?: 0);
                if ($overlapCount > 0) {
                    echo json_encode(['success' => false, 'message' => '❌ You cannot apply for leave on the same date as your existing leave request (any leave type).']); break;
                }
                // ── End overlap check ────────────────────────────────────────

                // Get current balance
                $bal = $pdo->prepare("
                    SELECT remaining_days FROM leave_balances
                    WHERE  employee_id = ? AND leave_type_id = ? AND calendar_year = YEAR(NOW())
                ");
                $bal->execute([$employeeId, $leaveTypeId]);
                $remaining = (int)($bal->fetchColumn() ?: 0);
                
                // Get pending days for this leave type
                $pendingDaysStmt = $pdo->prepare("
                    SELECT COALESCE(SUM(days_requested), 0) AS pending_days
                    FROM leave_requests
                    WHERE employee_id = ? AND leave_type_id = ? AND status = 'Pending' AND YEAR(filed_at) = YEAR(NOW())
                ");
                $pendingDaysStmt->execute([$employeeId, $leaveTypeId]);
                $pendingDays = (int)($pendingDaysStmt->fetchColumn() ?: 0);
                
                // Calculate available days after pending requests
                $available = $remaining - $pendingDays;
                
                if ($daysReq > $available) {
                    $msg = $pendingDays > 0
                        ? "Insufficient balance. You have {$remaining} day(s) total, but {$pendingDays} day(s) are pending. Available: {$available} day(s)."
                        : "Insufficient balance. You only have {$remaining} day(s) remaining.";
                    echo json_encode(['success' => false, 'message' => $msg]); break;
                }

                $stmt = $pdo->prepare("
                    INSERT INTO leave_requests
                        (employee_id, leave_type_id, date_from, date_to, days_requested, reason, status, filed_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'Pending', NOW(), NOW())
                ");
                $stmt->execute([$employeeId, $leaveTypeId, $dateFrom, $dateTo, $daysReq, $reason]);
                $requestId = (int)$pdo->lastInsertId();

                // ── CREATE LEAVE NOTIFICATIONS ──────────────────────────────
                // Get leave type details including advance notice requirements
                $ltDetails = $pdo->prepare("SELECT name, advance_notice_days FROM leave_types WHERE leave_type_id = ?");
                $ltDetails->execute([$leaveTypeId]);
                $lt = $ltDetails->fetch();
                
                if ($lt) {
                    $advanceDays = (int)($lt['advance_notice_days'] ?? 0);
                    $leaveTypeName = $lt['name'] ?? 'Leave';
                    
                    // Only create filing deadline notification if advance notice is required (> 0 days)
                    if ($advanceDays > 0) {
                        $filingDeadline = date('Y-m-d', strtotime("-{$advanceDays} days", strtotime($dateFrom)));
                        $today = date('Y-m-d');
                        
                        // Only create notification if the deadline hasn't passed yet
                        if ($filingDeadline >= $today) {
                            $notifStmt = $pdo->prepare("
                                INSERT INTO leave_notifications 
                                    (employee_id, request_id, leave_type_id, notification_type, title, message, target_date, is_sent)
                                VALUES (?, ?, ?, 'FILING_DEADLINE', ?, ?, ?, 0)
                            ");
                            $notifMsg = "Reminder: You filed a {$leaveTypeName} request. The deadline to file was {$advanceDays} day(s) before {$dateFrom}.";
                            $notifStmt->execute([
                                $employeeId,
                                $requestId,
                                $leaveTypeId,
                                "Filing Deadline: {$leaveTypeName}",
                                $notifMsg,
                                $filingDeadline
                            ]);
                        }
                    }
                    
                    // Create leave ending reminder (1 day before leave ends)
                    $endReminder = date('Y-m-d', strtotime('-1 days', strtotime($dateTo)));
                    $today = date('Y-m-d');
                    
                    if ($endReminder >= $today) {
                        $notifStmt = $pdo->prepare("
                            INSERT INTO leave_notifications 
                                (employee_id, request_id, leave_type_id, notification_type, title, message, target_date, is_sent)
                            VALUES (?, ?, ?, 'LEAVE_ENDING', ?, ?, ?, 0)
                        ");
                        $notifMsg = "Your {$leaveTypeName} ends on {$dateTo}. Please prepare to return to work.";
                        $notifStmt->execute([
                            $employeeId,
                            $requestId,
                            $leaveTypeId,
                            "Leave Ending Soon: {$leaveTypeName}",
                            $notifMsg,
                            $endReminder
                        ]);
                    }
                }
                // ── END NOTIFICATION CREATION ───────────────────────────────

                echo json_encode(['success' => true, 'message' => 'Leave request submitted successfully!']);
                break;

            // ── CANCEL LEAVE ──────────────────────────────────
            case 'cancel_leave':
                $reqId = (int)($_POST['request_id'] ?? 0);
                $chk   = $pdo->prepare("SELECT request_id FROM leave_requests WHERE request_id = ? AND employee_id = ? AND status = 'Pending'");
                $chk->execute([$reqId, $employeeId]);
                if (!$chk->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Request not found or cannot be cancelled.']); break;
                }
                $pdo->prepare("UPDATE leave_requests SET status = 'Cancelled', updated_at = NOW() WHERE request_id = ?")->execute([$reqId]);
                echo json_encode(['success' => true, 'message' => 'Leave request cancelled.']);
                break;

            // ── LEAVE TYPES ───────────────────────────────────
            case 'leave_types':
                // ── CHECK EMPLOYMENT TYPE: Only Permanent can access leave types ──
                $empTypeCheck = $pdo->prepare("SELECT employment_type FROM employees WHERE employee_id = ?");
                $empTypeCheck->execute([$employeeId]);
                $empTypeRow = $empTypeCheck->fetch();
                $empType = $empTypeRow['employment_type'] ?? 'Unknown';
                if ($empType !== 'Permanent') {
                    echo json_encode(['success' => false, 'message' => "Leave functionality is only available to Permanent employees. You are employed as: {$empType}.", 'data' => []]); break;
                }
                // Fetch employee's gender for filtering
                $empInfo = $pdo->prepare("SELECT gender FROM employees WHERE employee_id = ?");
                $empInfo->execute([$employeeId]);
                $empRow  = $empInfo->fetch();
                $empGender     = $empRow['gender'] ?? 'Unknown';

                // Get leave types with balances (avoiding duplicate rows from LEFT JOIN)
                $stmt = $pdo->prepare("
                    SELECT lt.leave_type_id, lt.name, lt.max_days,
                           lt.applicable_gender, lt.require_married,
                           COALESCE(lb.remaining_days, 0) AS remaining_days
                    FROM   leave_types lt
                    LEFT JOIN (
                        SELECT leave_type_id, employee_id, remaining_days
                        FROM leave_balances
                        WHERE calendar_year = YEAR(NOW())
                    ) lb ON lb.leave_type_id = lt.leave_type_id
                           AND lb.employee_id = ?
                    WHERE  lt.is_active = 1
                    GROUP BY lt.leave_type_id, lt.name, lt.max_days, lt.applicable_gender, lt.require_married
                    ORDER BY lt.name ASC
                ");
                $stmt->execute([$employeeId]);
                $allTypes = $stmt->fetchAll();

                // Filter: remove leave types that don't apply to this employee's gender
                $result = array_values(array_filter($allTypes, function($lt) use ($empGender) {
                    $ag = $lt['applicable_gender'] ?? 'All';
                    if ($ag === 'Male'   && strtolower($empGender) !== 'male')   return false;
                    if ($ag === 'Female' && strtolower($empGender) !== 'female') return false;
                    return true;
                }));

                echo json_encode([
                    'success'      => true,
                    'data'         => $result,
                    'emp_gender'   => $empGender,
                ]);
                break;

            // ── ANNOUNCEMENTS ─────────────────────────────────
            case 'announcements':
                try {
                    // Check if announcements table exists first
                    $tblCheck = $pdo->query("SHOW TABLES LIKE 'announcements'")->fetchColumn();
                    if (!$tblCheck) {
                        echo json_encode(['success' => true, 'data' => []]);
                        break;
                    }
                    $rows = $pdo->query("
                        SELECT announcement_id, title, body, priority, category AS tag, created_at
                        FROM   announcements
                        WHERE  status = 'Published'
                          AND  (publish_date IS NULL OR publish_date <= CURDATE())
                          AND  (expiry_date IS NULL OR expiry_date >= CURDATE())
                        ORDER BY pinned DESC, created_at DESC
                        LIMIT  10
                    ")->fetchAll(PDO::FETCH_ASSOC);
                    echo json_encode(['success' => true, 'data' => $rows]);
                } catch (PDOException $annEx) {
                    error_log('[HRMS] Announcements error: ' . $annEx->getMessage());
                    echo json_encode(['success' => true, 'data' => []]);
                }
                break;

            // ── MY DOCUMENTS (list) ───────────────────────────
            case 'get_pds':
                // Fetch latest PDS record for employee
                $stmt = $pdo->prepare("SELECT * FROM pds_records WHERE employee_id = ? ORDER BY created_at DESC LIMIT 1");
                $stmt->execute([$employeeId]);
                $row = $stmt->fetch();
                echo json_encode(['success'=>true,'data'=>$row?:null]);
                break;

            case 'save_pds':
                if (!$employeeId) {
                    echo json_encode(['success'=>false,'message'=>"LINK_ERROR: Your user account (user_id=$userId) is not linked to any employee record. Go to Admin → Employees and set the User Account field for your employee profile."]);
                    break;
                }
                $data = json_decode(file_get_contents('php://input'), true) ?? [];
                $empId = $employeeId;
                // Check if record exists
                $existing = $pdo->prepare("SELECT pds_id FROM pds_records WHERE employee_id = ? LIMIT 1");
                $existing->execute([$empId]); $existingId = $existing->fetchColumn();
                $fields = [
                    'personal_info'     => json_encode($data['personal_info']     ?? []),
                    'family_background' => json_encode($data['family_background'] ?? []),
                    'education'         => json_encode($data['education']         ?? []),
                    'civil_service'     => json_encode($data['civil_service']     ?? []),
                    'work_experience'   => json_encode($data['work_experience']   ?? []),
                    'voluntary_work'    => json_encode($data['voluntary_work']    ?? []),
                    'trainings'         => json_encode($data['trainings']         ?? []),
                    'other_info'        => json_encode($data['other_info']        ?? []),
                    'pds_references'    => json_encode($data['references']        ?? []),
                    'pds_character_references' => json_encode($data['character_references'] ?? []),
                    'q4_questions'      => json_encode($data['q4_questions']      ?? []),
                    'updated_at'        => date('Y-m-d H:i:s'),
                ];
                if ($existingId) {
                    $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($fields)));
                    $pdo->prepare("UPDATE pds_records SET $set WHERE pds_id = ?")->execute([...array_values($fields), $existingId]);
                } else {
                    $fields['employee_id'] = $empId;
                    $fields['created_at']  = date('Y-m-d H:i:s');
                    $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($fields)));
                    $phs  = implode(', ', array_fill(0, count($fields), '?'));
                    $pdo->prepare("INSERT INTO pds_records ($cols) VALUES ($phs)")->execute(array_values($fields));
                }
                echo json_encode(['success'=>true,'message'=>"PDS saved successfully. (employee_id=$empId, user_id=$userId)"]);
                break;

            // ── NOTIFICATION POLL ─────────────────────────────────────
            case 'get_notif_count':
                $notifs = []; $nCount = 0;
                if ($employeeId) { try {

                    // ── Leave request status updates (approved OR denied, last 30 days) ──
                    $lrS = $pdo->prepare("SELECT lr.status, lr.reviewed_at, lr.admin_remarks,
                        lt.name AS lt, lr.days_requested
                        FROM leave_requests lr
                        JOIN leave_types lt ON lt.leave_type_id = lr.leave_type_id
                        WHERE lr.employee_id = ?
                          AND lr.status IN ('Approved','Denied')
                          AND lr.reviewed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                        ORDER BY lr.reviewed_at DESC LIMIT 10");
                    $lrS->execute([$employeeId]);
                    foreach ($lrS->fetchAll(PDO::FETCH_ASSOC) as $lr) {
                        $ok   = $lr['status'] === 'Approved';
                        $days = (int)$lr['days_requested'];
                        $body = ($ok ? '✅ Approved: ' : '❌ Denied: ') . 'Your ' . htmlspecialchars($lr['lt'])
                              . ' request (' . $days . ' day' . ($days !== 1 ? 's' : '') . ') was ' . strtolower($lr['status']);
                        if (!$ok && !empty($lr['admin_remarks'])) {
                            $body .= ' — ' . htmlspecialchars($lr['admin_remarks']);
                        }
                        $notifs[] = [
                            'icon'  => $ok ? '✅' : '❌',
                            'color' => $ok ? '#22c55e' : '#ef4444',
                            'title' => 'Leave Request ' . $lr['status'],
                            'body'  => $body,
                            'time'  => $lr['reviewed_at'],
                            'page'  => 'requests',
                            'nid'   => md5($lr['status'] . $lr['lt'] . $days . ($lr['reviewed_at'] ?? '')),
                        ];
                    }

                    // ── Announcements (last 7 days) ───────────────────────────────────
                    try {
                        $anns = $pdo->query("SELECT title, created_at FROM announcements
                            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                            ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($anns as $a) {
                            $notifs[] = [
                                'icon'  => '📢', 'color' => '#2563eb',
                                'title' => 'New Announcement',
                                'body'  => htmlspecialchars($a['title']),
                                'time'  => $a['created_at'], 'page' => 'dashboard',
                                'nid'   => md5('ann' . $a['title'] . ($a['created_at'] ?? '')),
                            ];
                        }
                    } catch (Exception $ex) {}

                    // ── Low leave balance warnings (< 3 days remaining) ───────────────
                    try {
                        $bw = $pdo->prepare("SELECT lt.name, lb.remaining_days FROM leave_balances lb
                            JOIN leave_types lt ON lt.leave_type_id = lb.leave_type_id
                            WHERE lb.employee_id = ? AND lb.calendar_year = YEAR(NOW())
                              AND lb.remaining_days < 3 AND lb.remaining_days >= 0
                            ORDER BY lb.remaining_days ASC LIMIT 3");
                        $bw->execute([$employeeId]);
                        foreach ($bw->fetchAll(PDO::FETCH_ASSOC) as $b) {
                            $rem = (int)$b['remaining_days'];
                            $notifs[] = [
                                'icon'  => '⚠️', 'color' => '#f59e0b',
                                'title' => 'Low Leave Balance',
                                'body'  => htmlspecialchars($b['name']) . ': only ' . $rem . ' day' . ($rem !== 1 ? 's' : '') . ' remaining',
                                'time'  => null, 'page' => 'balances',
                                'nid'   => md5('bal' . $b['name'] . $rem),
                            ];
                        }
                    } catch (Exception $ex) {}

                    // ── Leave deadline & ending reminders (from leave_notifications) ──────
                    try {
                        $lnStmt = $pdo->prepare("
                            SELECT notification_id, notification_type, title, message, target_date, created_at
                            FROM leave_notifications
                            WHERE employee_id = ? AND is_sent = 0
                              AND target_date <= CURDATE()
                            ORDER BY target_date DESC LIMIT 5");
                        $lnStmt->execute([$employeeId]);
                        foreach ($lnStmt->fetchAll(PDO::FETCH_ASSOC) as $ln) {
                            $icon = $ln['notification_type'] === 'LEAVE_ENDING' ? '⏰' : '📋';
                            $color = $ln['notification_type'] === 'LEAVE_ENDING' ? '#f59e0b' : '#8b5cf6';
                            
                            $notifs[] = [
                                'icon'  => $icon,
                                'color' => $color,
                                'title' => htmlspecialchars($ln['title']),
                                'body'  => htmlspecialchars($ln['message']),
                                'time'  => $ln['created_at'],
                                'page'  => 'requests',
                                'nid'   => md5('notif' . $ln['notification_id'] . $ln['created_at']),
                            ];
                        }
                    } catch (Exception $ex) {}

                    // ── Service record request resolved ───────────────────────────────
                    try {
                        $sr = $pdo->prepare("SELECT status, admin_note, resolved_at FROM sr_requests
                            WHERE employee_id = ? AND status IN ('Approved','Denied')
                              AND resolved_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                            ORDER BY resolved_at DESC LIMIT 2");
                        $sr->execute([$employeeId]);
                        foreach ($sr->fetchAll(PDO::FETCH_ASSOC) as $s) {
                            $ok = $s['status'] === 'Approved';
                            $notifs[] = [
                                'icon'  => $ok ? '✅' : '❌', 'color' => $ok ? '#22c55e' : '#ef4444',
                                'title' => 'Service Record ' . $s['status'],
                                'body'  => 'Your service record request was ' . strtolower($s['status']) . (!empty($s['admin_note']) ? ' — ' . htmlspecialchars($s['admin_note']) : ''),
                                'time'  => $s['resolved_at'], 'page' => 'service_record',
                                'nid'   => md5('sr' . $s['status'] . ($s['resolved_at'] ?? '')),
                            ];
                        }
                    } catch (Exception $ex) {}

                    // ── Sort by time desc ─────────────────────────────────────────────
                    usort($notifs, function($a,$b) {
                        if (!$a['time'] && !$b['time']) return 0;
                        if (!$a['time']) return 1;
                        if (!$b['time']) return -1;
                        return strtotime($b['time']) - strtotime($a['time']);
                    });
                    $nCount = count($notifs);

                } catch (Exception $ex) {} }
                echo json_encode(['success'=>true,'count'=>$nCount,'notifications'=>$notifs]);
                break;

            // ── SERVICE RECORD ──────────────────────────────────────
            case 'get_service_record':
                $stmt = $pdo->prepare("SELECT * FROM service_records WHERE employee_id=? ORDER BY date_from ASC, sr_id ASC");
                $stmt->execute([$employeeId]);
                $records = $stmt->fetchAll();
                // Get latest request status
                $req = $pdo->prepare("SELECT * FROM sr_requests WHERE employee_id=? ORDER BY requested_at DESC LIMIT 1");
                $req->execute([$employeeId]);
                $latestReq = $req->fetch();
                $emp = $pdo->prepare("SELECT e.*,d.name AS dept_name FROM employees e LEFT JOIN departments d ON e.department_id=d.department_id WHERE e.employee_id=?");
                $emp->execute([$employeeId]); $empRow = $emp->fetch();
                echo json_encode(['success'=>true,'records'=>$records,'employee'=>$empRow?:null,'latest_request'=>$latestReq?:null]);
                break;

            case 'request_service_record':
                if (!$employeeId) { echo json_encode(['success'=>false,'message'=>'Employee not linked.']); break; }
                // Check if already pending
                $existing = $pdo->prepare("SELECT req_id FROM sr_requests WHERE employee_id=? AND status='Pending'");
                $existing->execute([$employeeId]);
                if ($existing->fetch()) {
                    echo json_encode(['success'=>false,'message'=>'You already have a pending service record request.']); break;
                }
                $purpose = trim($_POST['purpose'] ?? '');
                $pdo->prepare("INSERT INTO sr_requests (employee_id,purpose) VALUES (?,?)")->execute([$employeeId, $purpose]);
                echo json_encode(['success'=>true,'message'=>'Service record request submitted successfully.']);
                break;

            case 'get_sr_requests':
                // Admin: get all pending sr_requests
                $rows = $pdo->query("SELECT r.*, CONCAT(e.first_name,' ',e.last_name) AS emp_name, e.employee_code, d.name AS department
                    FROM sr_requests r JOIN employees e ON e.employee_id=r.employee_id
                    LEFT JOIN departments d ON d.department_id=e.department_id
                    ORDER BY r.requested_at DESC LIMIT 50")->fetchAll();
                echo json_encode(['success'=>true,'data'=>$rows]);
                break;

            case 'resolve_sr_request':
                $reqId  = (int)($_POST['req_id']??0);
                $status = $_POST['status']??'';
                $note   = trim($_POST['admin_note']??'');
                if (!in_array($status,['Approved','Denied'])) { echo json_encode(['success'=>false,'message'=>'Invalid status.']); break; }
                $pdo->prepare("UPDATE sr_requests SET status=?,admin_note=?,resolved_at=NOW() WHERE req_id=?")->execute([$status,$note,$reqId]);
                echo json_encode(['success'=>true,'message'=>"Request $status."]);
                break;

            case 'get_saln':
                $year = (int)($_GET['year'] ?? date('Y'));
                $stmt = $pdo->prepare("SELECT * FROM saln_records WHERE employee_id = ? AND saln_year = ? LIMIT 1");
                $stmt->execute([$employeeId, $year]);
                $row = $stmt->fetch();
                // Get all years filed
                $years = $pdo->prepare("SELECT saln_year FROM saln_records WHERE employee_id = ? ORDER BY saln_year DESC");
                $years->execute([$employeeId]); $yearsList = $years->fetchAll(PDO::FETCH_COLUMN);
                echo json_encode(['success'=>true,'data'=>$row?:null,'years'=>$yearsList]);
                break;

            case 'save_saln':
                if (!$employeeId) {
                    echo json_encode(['success'=>false,'message'=>"LINK_ERROR: Your user account (user_id=$userId) is not linked to any employee record. Go to Admin → Employees and set the User Account field for your employee profile."]);
                    break;
                }
                $data = json_decode(file_get_contents('php://input'), true) ?? [];
                $year = (int)($data['saln_year'] ?? date('Y'));
                $existing = $pdo->prepare("SELECT saln_id FROM saln_records WHERE employee_id = ? AND saln_year = ? LIMIT 1");
                $existing->execute([$employeeId, $year]); $existingId = $existing->fetchColumn();
                $fields = [
                    'as_of_date'          => $data['as_of_date']   ?? null,
                    'filing_type'         => $data['filing_type']  ?? null,
                    'filing_mode'         => $data['filing_mode']  ?? null,
                    'multi_spouse'        => $data['multi_spouse'] ?? null,
                    'declarant_info'      => json_encode($data['declarant_info']      ?? []),
                    'spouse_info'         => json_encode($data['spouse_info']         ?? []),
                    'children_info'       => json_encode($data['children_info']       ?? []),
                    'real_properties'     => json_encode($data['real_properties']     ?? []),
                    'personal_properties' => json_encode($data['personal_properties'] ?? []),
                    'liabilities'         => json_encode($data['liabilities']         ?? []),
                    'biz_interests'       => json_encode($data['biz_interests']       ?? []),
                    'relatives_govt'      => json_encode($data['relatives_govt']      ?? []),
                    'no_biz'              => !empty($data['no_biz']) ? 1 : 0,
                    'no_rel'              => !empty($data['no_rel']) ? 1 : 0,
                    'real_subtotal'       => ($data['real_subtotal']    ?? '') !== '' ? (float)$data['real_subtotal']    : null,
                    'personal_subtotal'   => ($data['personal_subtotal']?? '') !== '' ? (float)$data['personal_subtotal']: null,
                    'total_assets'        => ($data['total_assets']     ?? '') !== '' ? (float)$data['total_assets']     : null,
                    'total_liabilities'   => ($data['total_liabilities']?? '') !== '' ? (float)$data['total_liabilities']: null,
                    'net_worth'           => ($data['net_worth']        ?? '') !== '' ? (float)$data['net_worth']        : null,
                    'gov_id'              => $data['gov_id']     ?? null,
                    'gov_id_no'           => $data['gov_id_no']  ?? null,
                    'gov_id_issued'       => $data['gov_id_issued'] ?? null,
                    'updated_at'          => date('Y-m-d H:i:s'),
                ];
                if ($existingId) {
                    $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
                    $pdo->prepare("UPDATE saln_records SET $set WHERE saln_id = ?")->execute([...array_values($fields), $existingId]);
                } else {
                    $fields['employee_id'] = $employeeId;
                    $fields['saln_year']   = $year;
                    $fields['created_at']  = date('Y-m-d H:i:s');
                    $cols = implode(', ', array_keys($fields));
                    $phs  = implode(', ', array_fill(0, count($fields), '?'));
                    $pdo->prepare("INSERT INTO saln_records ($cols) VALUES ($phs)")->execute(array_values($fields));
                }
                echo json_encode(['success'=>true,'message'=>"SALN saved successfully. (employee_id=$employeeId, user_id=$userId, year=$year)"]);
                break;

            case 'my_documents':
                if (!$employeeId) { echo json_encode(['success'=>false,'message'=>'Employee not found.']); break; }
                $type   = trim($_GET['type']   ?? '');
                $search = trim($_GET['search'] ?? '');
                $where  = ['d.employee_id = ?'];
                $params = [$employeeId];
                if ($type)   { $where[] = 'd.document_type = ?'; $params[] = $type; }
                if ($search) { $where[] = '(d.file_name LIKE ? OR d.document_type LIKE ?)'; $s="%$search%"; $params[]=$s; $params[]=$s; }
                $wSql = 'WHERE ' . implode(' AND ', $where);

                $stmt = $pdo->prepare("
                    SELECT d.document_id, d.document_type, d.file_name, d.file_path, d.file_size, d.uploaded_at,
                           u.email AS uploader_email
                    FROM   documents d
                    LEFT JOIN user_accounts u ON u.user_id = d.uploaded_by
                    $wSql
                    ORDER  BY d.uploaded_at DESC
                ");
                $stmt->execute($params);
                $docs = $stmt->fetchAll();

                // Stats for this employee
                $stats = $pdo->prepare("
                    SELECT COUNT(*) AS total, SUM(file_size) AS total_size,
                           COUNT(DISTINCT document_type) AS types
                    FROM documents WHERE employee_id = ?
                ");
                $stats->execute([$employeeId]);

                echo json_encode(['success'=>true,'data'=>$docs,'stats'=>$stats->fetch()]);
                break;

            // ── UPLOAD DOCUMENT (employee self-upload) ────────
            case 'upload_document':
                if (!$employeeId) { echo json_encode(['success'=>false,'message'=>'Employee record not found.']); break; }
                if (empty($_FILES['file'])) { echo json_encode(['success'=>false,'message'=>'No file uploaded.']); break; }

                $file    = $_FILES['file'];
                $docType = trim($_POST['document_type'] ?? '');
                if (!$docType) { echo json_encode(['success'=>false,'message'=>'Document type is required.']); break; }

                if ($file['error'] !== UPLOAD_ERR_OK) {
                    echo json_encode(['success'=>false,'message'=>'Upload error: '.$file['error']]); break;
                }
                if ($file['size'] > $maxFileSize) {
                    echo json_encode(['success'=>false,'message'=>'File too large. Max 10MB.']); break;
                }

                $finfo    = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                if (!array_key_exists($mimeType, $allowedMimes)) {
                    echo json_encode(['success'=>false,'message'=>'File type not allowed. Accepted: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG.']); break;
                }

                $origName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($file['name']));
                $ext      = $allowedMimes[$mimeType];
                $unique   = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                $destPath = UPLOAD_DIR . $unique;

                if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                    echo json_encode(['success'=>false,'message'=>'Failed to save file. Check folder permissions.']); break;
                }

                $pdo->prepare("
                    INSERT INTO documents (employee_id, document_type, file_name, file_path, file_size, uploaded_by, uploaded_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ")->execute([$employeeId, $docType, $origName, UPLOAD_URL.$unique, $file['size'], $userId]);

                echo json_encode(['success'=>true,'message'=>'Document uploaded successfully.']);
                break;

            // ── DELETE OWN DOCUMENT ───────────────────────────
            case 'delete_document':
                $docId = (int)($_POST['document_id'] ?? 0);
                // Only allow deleting own documents
                $chk   = $pdo->prepare("SELECT file_path FROM documents WHERE document_id = ? AND employee_id = ?");
                $chk->execute([$docId, $employeeId]);
                $row   = $chk->fetch();
                if (!$row) { echo json_encode(['success'=>false,'message'=>'Document not found.']); break; }

                $absPath = __DIR__ . '/' . ltrim(str_replace('../','', $row['file_path']), '/');
                if (file_exists($absPath)) @unlink($absPath);
                $pdo->prepare("DELETE FROM documents WHERE document_id = ?")->execute([$docId]);
                echo json_encode(['success'=>true,'message'=>'Document deleted.']);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        }
    } catch (PDOException $e) {
        $errMsg = $e->getMessage();
        error_log('[HRMS] Employee_portal.php error: ' . $errMsg);
        // Return the actual error in message so debug is easier
        echo json_encode(['success' => false, 'message' => 'DB Error: ' . $errMsg]);
    }
    exit();
}

$displayName = !empty($_SESSION['user_name']) ? ucwords($_SESSION['user_name']) : 'Employee';
$isSwitched  = !empty($_SESSION['switched_from_admin']);
$origRole    = $isSwitched ? ($_SESSION['original_role'] ?? 'admin') : '';
$roleLabels  = ['admin'=>'Administrator','hr_staff'=>'HR Staff','dept_head'=>'Department Head'];
$origRoleLabel = $roleLabels[$origRole] ?? ucfirst($origRole);


?>
<?php
// ── Employee Notifications ───────────────────────────────────────────────
$empNotifications=[]; $empNotifCount=0;
if(isset($pdo)&&isset($employeeId)&&$employeeId){try{
    // Leave status changes (last 30 days)
    $lrS=$pdo->prepare("SELECT lr.status,lr.reviewed_at,lt.name AS lt,lr.days_requested
        FROM leave_requests lr JOIN leave_types lt ON lt.leave_type_id=lr.leave_type_id
        WHERE lr.employee_id=? AND lr.status IN ('Approved','Denied')
        AND lr.reviewed_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) ORDER BY lr.reviewed_at DESC LIMIT 5");
    $lrS->execute([$employeeId]);
    foreach($lrS->fetchAll(PDO::FETCH_ASSOC) as $lr){$ok=$lr['status']==='Approved';
      $empNotifications[]=['icon'=>$ok?'✅':'✗','color'=>$ok?'#22c55e':'#ef4444','title'=>'Leave '.$lr['status'],
        'body'=>'Your '.htmlspecialchars($lr['lt']).' request ('.(int)$lr['days_requested'].' day'.((int)$lr['days_requested']!==1?'s':'').') was '.strtolower($lr['status']),
        'time'=>$lr['reviewed_at'],'page'=>'requests'];}
    // Low balance warnings
    $bw=$pdo->prepare("SELECT lt.name,lb.remaining_days FROM leave_balances lb
        JOIN leave_types lt ON lt.leave_type_id=lb.leave_type_id
        WHERE lb.employee_id=? AND lb.calendar_year=YEAR(NOW()) AND lb.remaining_days<3 AND lb.remaining_days>=0 ORDER BY lb.remaining_days ASC LIMIT 3");
    $bw->execute([$employeeId]);
    foreach($bw->fetchAll(PDO::FETCH_ASSOC) as $b){$empNotifications[]=['icon'=>'⚠️','color'=>'#f59e0b','title'=>'Low Leave Balance',
      'body'=>htmlspecialchars($b['name']).': only '.(int)$b['remaining_days'].' day'.((int)$b['remaining_days']!==1?'s':'').' remaining','time'=>null,'page'=>'balances'];}
    // Recent announcements
    try{$anns=$pdo->query("SELECT title,created_at FROM announcements WHERE status='Published' AND (publish_date IS NULL OR publish_date<=CURDATE()) AND (expiry_date IS NULL OR expiry_date>=CURDATE()) AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) ORDER BY pinned DESC, created_at DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
      foreach($anns as $a){$empNotifications[]=['icon'=>'📢','color'=>'#2563eb','title'=>'Announcement','body'=>htmlspecialchars($a['title']),'time'=>$a['created_at'],'page'=>'dashboard'];}
    }catch(Exception $ex){}
    usort($empNotifications,function($a,$b){if(!$a['time']&&!$b['time'])return 0;if(!$a['time'])return 1;if(!$b['time'])return -1;return strtotime($b['time'])-strtotime($a['time']);});
    $empNotifCount=count($empNotifications);
}catch(Exception $ex){$empNotifCount=0;}}
if(!function_exists('empTimeAgo')){function empTimeAgo($dt){if(!$dt)return '';$d=time()-strtotime($dt);if($d<60)return 'Just now';if($d<3600)return (int)($d/60).'m ago';if($d<86400)return (int)($d/3600).'h ago';if($d<172800)return 'Yesterday';return date('M j',strtotime($dt));}}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <?= csrf_meta() ?>
  <title>Employee Portal | LGU Enrile HRMS</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,700;0,900;1,700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet"/>
  <style>
    :root {
      --primary:#2563eb; --primary-dk:#1e40af; --primary-lt:#eff6ff;
      --accent:#22c55e;  --accent-dk:#15803d;  --accent-lt:#dcfce7;
      --warn:#f59e0b;    --danger:#ef4444;      --bg:#f1f5f9;
      --sidebar-w:250px; --topbar-h:64px;
      --g50:#f8fafc; --g100:#f1f5f9; --g200:#e2e8f0; --g300:#cbd5e1;
      --g400:#94a3b8; --g500:#64748b; --g600:#475569; --g700:#334155; --g800:#1e293b;
      --white:#ffffff;
      --sh-sm:0 1px 4px rgba(0,0,0,0.07); --sh-md:0 4px 20px rgba(0,0,0,0.10); --sh-lg:0 8px 36px rgba(0,0,0,0.14);
      --r:14px; --r-sm:9px;
    }
    *{box-sizing:border-box;margin:0;padding:0;}
    html{scroll-behavior:smooth;}
    body{font-family:'Nunito',sans-serif;background:var(--bg);color:var(--g800);display:flex;min-height:100vh;overflow-x:hidden;}

    /* SIDEBAR */
    .sidebar{width:var(--sidebar-w);background:linear-gradient(165deg,#1e3a8a 0%,#1e40af 30%,#2563eb 65%,#166534 100%);position:fixed;top:0;left:0;height:100vh;display:flex;flex-direction:column;z-index:300;transition:transform .3s cubic-bezier(.4,0,.2,1);box-shadow:6px 0 30px rgba(0,0,0,.22);}
    .sidebar::before{content:'';position:absolute;top:-60px;right:-60px;width:180px;height:180px;border-radius:50%;background:rgba(255,255,255,.04);pointer-events:none;}
    .sidebar::after{content:'';position:absolute;bottom:-40px;left:-40px;width:140px;height:140px;border-radius:50%;background:rgba(255,255,255,.04);pointer-events:none;}
    .sb-brand{padding:20px 18px 14px;display:flex;align-items:center;gap:10px;border-bottom:1px solid rgba(255,255,255,.11);}
    .sb-logo{width:38px;height:38px;border-radius:50%;border:2px solid rgba(255,255,255,.6);object-fit:cover;flex-shrink:0;}
    .sb-brand-text h2{font-family:'Playfair Display',serif;font-size:.92rem;color:#fff;line-height:1.2;}
    .sb-brand-text span{font-size:.60rem;color:rgba(255,255,255,.5);font-weight:700;text-transform:uppercase;letter-spacing:.12em;}
    .sb-user{padding:14px 18px 12px;border-bottom:1px solid rgba(255,255,255,.09);display:flex;align-items:center;gap:10px;}
    .sb-avatar{width:44px;height:44px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.88rem;color:#fff;border:2.5px solid rgba(255,255,255,.55);box-shadow:0 2px 10px rgba(0,0,0,.20);overflow:hidden;}
    .sb-uname{font-size:.82rem;font-weight:800;color:#fff;line-height:1.2;}
    .sb-upos{font-size:.66rem;color:rgba(255,255,255,.52);}
    .sb-status{width:8px;height:8px;border-radius:50%;background:#22c55e;border:2px solid rgba(255,255,255,.4);margin-left:auto;flex-shrink:0;}
    .sb-nav{flex:1;padding:10px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:rgba(255,255,255,.15) transparent;}
    .sb-section{font-size:.58rem;font-weight:800;color:rgba(255,255,255,.30);text-transform:uppercase;letter-spacing:.18em;padding:10px 10px 4px;margin-top:4px;}
    .sb-item{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:var(--r-sm);color:rgba(255,255,255,.70);text-decoration:none;font-size:.845rem;font-weight:600;transition:all .2s;margin-bottom:2px;cursor:pointer;position:relative;border:none;background:none;width:100%;text-align:left;font-family:'Nunito',sans-serif;}
    .sb-item:hover{background:rgba(255,255,255,.12);color:#fff;}
    .sb-item.active{background:rgba(255,255,255,.20);color:#fff;}
    .sb-item.active::before{content:'';position:absolute;left:0;top:22%;bottom:22%;width:3px;border-radius:2px;background:#fff;}
    .sb-icon{width:28px;height:28px;border-radius:7px;background:rgba(255,255,255,.10);display:flex;align-items:center;justify-content:center;font-size:.88rem;flex-shrink:0;transition:background .2s;}
    .sb-item.active .sb-icon,.sb-item:hover .sb-icon{background:rgba(255,255,255,.22);}
    .sb-sub{padding-left:28px;font-size:.80rem;}
    .sb-label{font-size:.60rem;font-weight:800;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.15em;padding:10px 12px 3px;margin-top:4px;}
    /* PDS / SALN form styles */
    .form-page{max-width:860px;margin:0 auto;}
    .form-section-hd{background:linear-gradient(135deg,var(--primary),var(--primary-dk));color:#fff;padding:10px 18px;border-radius:var(--r-sm) var(--r-sm) 0 0;font-size:.80rem;font-weight:800;letter-spacing:.05em;text-transform:uppercase;margin-top:18px;}
    .form-section-body{background:var(--white);border:1.5px solid var(--g200);border-top:none;border-radius:0 0 var(--r-sm) var(--r-sm);padding:18px;}
    .fg2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
    .fg3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;}
    .fg-full{grid-column:1/-1;}
    .fg label{display:block;font-size:.72rem;font-weight:800;color:var(--g600);text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px;}
    .fg input,.fg select,.fg textarea{width:100%;padding:9px 11px;border:1.5px solid var(--g200);border-radius:var(--r-sm);font-family:'Nunito',sans-serif;font-size:.85rem;color:var(--g800);outline:none;transition:border-color .2s;background:var(--white);}
    .fg input:focus,.fg select:focus,.fg textarea:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(37,99,235,.09);}
    .fg textarea{height:70px;resize:vertical;}
    .repeater-row{display:flex;gap:8px;align-items:flex-start;margin-bottom:8px;}
    .repeater-row .fg{flex:1;margin:0;}
    .repeater-row .rem-btn{padding:9px 10px;border:1.5px solid #fecaca;background:#fef2f2;color:#dc2626;border-radius:var(--r-sm);cursor:pointer;font-size:.8rem;flex-shrink:0;margin-top:22px;}
    /* SALN table-based repeater */
    .saln-tbl{width:100%;border-collapse:collapse;font-size:.78rem;margin-bottom:6px;}
    .saln-tbl thead th{background:#f1f5f9;border:1.5px solid var(--g200);padding:6px 10px;font-size:.65rem;font-weight:800;color:var(--g600);text-transform:uppercase;letter-spacing:.05em;text-align:left;white-space:nowrap;}
    .saln-tbl tbody td{border:1.5px solid var(--g200);padding:3px 4px;vertical-align:middle;}
    .saln-tbl tbody input,.saln-tbl tbody select{width:100%;padding:6px 8px;border:1.5px solid var(--g300);border-radius:6px;font-family:'Nunito',sans-serif;font-size:.78rem;color:var(--g800);background:#fff;outline:none;box-sizing:border-box;transition:border-color .2s;}
    .saln-tbl tbody input:focus,.saln-tbl tbody select:focus{border-color:var(--primary);box-shadow:0 0 0 2px rgba(37,99,235,.10);}
    .saln-tbl .del-cell{width:32px;text-align:center;padding:3px;}
    .saln-tbl .del-btn{width:26px;height:26px;border-radius:6px;border:1.5px solid #fecaca;background:#fef2f2;color:#dc2626;cursor:pointer;font-size:.75rem;display:flex;align-items:center;justify-content:center;transition:all .2s;}
    .saln-tbl .del-btn:hover{background:#dc2626;color:#fff;border-color:#dc2626;}
    /* Table-based section inputs — clearly visible borders */
    #pds_edu_list input, #pds_edu_table input,
    #pds_cs_list input, #pds_cs_list select,
    #pds_we_list input, #pds_we_list select,
    #pds_vw_list input, #pds_vw_list select,
    #pds_tr_list input, #pds_tr_list select {
      width:100%; padding:6px 8px; border:1.5px solid #94a3b8 !important;
      border-radius:6px; font-family:'Nunito',sans-serif; font-size:.80rem;
      color:var(--g800); background:#fff; outline:none;
      box-sizing:border-box; transition:border-color .2s, box-shadow .2s;
    }
    #pds_edu_list input:focus, #pds_edu_table input:focus,
    #pds_cs_list input:focus, #pds_cs_list select:focus,
    #pds_we_list input:focus, #pds_we_list select:focus,
    #pds_vw_list input:focus, #pds_vw_list select:focus,
    #pds_tr_list input:focus, #pds_tr_list select:focus {
      border-color:var(--primary) !important;
      box-shadow:0 0 0 3px rgba(37,99,235,.12);
    }
    .rem-btn{padding:6px 9px;border:1.5px solid #fecaca;background:#fef2f2;color:#dc2626;border-radius:var(--r-sm);cursor:pointer;font-size:.8rem;}
    .add-row-btn{align-self:flex-start;display:inline-block;padding:7px 16px;border:1.5px dashed var(--primary);background:transparent;color:var(--primary);border-radius:var(--r-sm);font-family:'Nunito',sans-serif;font-size:.80rem;font-weight:700;cursor:pointer;margin-top:6px;transition:all .2s;}
    .add-row-btn:hover{background:#eff6ff;}
    /* ── OI multi-entry list (items 31-33) ── */
    .oi-list{display:flex;flex-direction:column;gap:6px;min-height:20px;}
    .oi-list:empty::before{content:'No entries yet — click the button to add.';font-size:.75rem;color:#94a3b8;font-style:italic;padding:4px 2px;}
    .oi-row{display:flex;align-items:center;gap:6px;}
    .oi-inp{flex:1;padding:9px 12px;border:1.5px solid var(--g200);border-radius:var(--r-sm);font-family:'Nunito',sans-serif;font-size:.85rem;color:#1e293b;outline:none;transition:border-color .2s;background:var(--white);}
    .oi-inp:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(37,99,235,.09);}
    .oi-inp::placeholder{color:#94a3b8;}
    .oi-del{width:30px;height:30px;flex-shrink:0;border-radius:7px;border:1.5px solid #fecaca;background:#fef2f2;color:#dc2626;cursor:pointer;font-size:.80rem;display:flex;align-items:center;justify-content:center;transition:all .2s;font-weight:700;}
    .oi-del:hover{background:#dc2626;color:#fff;border-color:#dc2626;}
    .save-form-btn{padding:11px 32px;background:linear-gradient(135deg,var(--primary),var(--primary-dk));color:#fff;border:none;border-radius:var(--r-sm);font-family:'Nunito',sans-serif;font-size:.92rem;font-weight:800;cursor:pointer;box-shadow:0 3px 12px rgba(37,99,235,.28);transition:all .2s;}
    .save-form-btn:hover{transform:translateY(-1px);box-shadow:0 5px 18px rgba(37,99,235,.38);}
    .form-status{display:none;padding:10px 16px;border-radius:var(--r-sm);font-size:.83rem;font-weight:700;margin-bottom:14px;}
    .form-status.saved{display:block;background:#f0fdf4;color:#16a34a;border:1.5px solid #bbf7d0;}
    .year-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px;}
    .year-tab{padding:6px 16px;border-radius:20px;border:1.5px solid var(--g200);background:var(--white);font-family:'Nunito',sans-serif;font-size:.78rem;font-weight:700;cursor:pointer;transition:all .2s;}
    .year-tab.active{background:var(--primary);color:#fff;border-color:var(--primary);}
    .year-tab:hover:not(.active){border-color:var(--primary);color:var(--primary);}
    .sb-badge{margin-left:auto;background:#ef4444;color:#fff;font-size:.58rem;font-weight:800;padding:2px 6px;border-radius:20px;display:none;}
    .sb-foot{padding:10px 10px 14px;border-top:1px solid rgba(255,255,255,.09);}
    .sb-logout{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:var(--r-sm);color:rgba(255,255,255,.55);font-size:.845rem;font-weight:600;cursor:pointer;transition:all .2s;text-decoration:none;border:none;background:none;width:100%;font-family:'Nunito',sans-serif;}
    .sb-logout:hover{background:rgba(255,255,255,.10);color:#fff;}

    /* MAIN */
    .main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-height:100vh;}
    .topbar{height:var(--topbar-h);background:linear-gradient(125deg,#1e40af 0%,#2563eb 55%,#22c55e 100%);display:flex;align-items:center;padding:0 26px;gap:14px;position:sticky;top:0;z-index:200;box-shadow:0 2px 18px rgba(37,99,235,.28);}
    .menu-toggle{display:none;background:none;border:none;font-size:1.3rem;color:rgba(255,255,255,.85);cursor:pointer;padding:4px;}
    .tb-left{flex:1;}
    .tb-title{font-family:'Playfair Display',serif;font-size:1.05rem;font-weight:700;color:#fff;}
    .tb-sub{font-size:.70rem;color:rgba(255,255,255,.60);margin-top:1px;}
    .tb-right{display:flex;align-items:center;gap:8px;}
    .tb-chip{font-size:.72rem;color:rgba(255,255,255,.75);font-weight:700;padding:5px 13px;background:rgba(255,255,255,.14);border-radius:20px;border:1px solid rgba(255,255,255,.18);}
    .tb-btn{position:relative;width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.22);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.92rem;color:#fff;transition:background .2s;}
    .tb-btn:hover{background:rgba(255,255,255,.26);}
    .tb-btn .notif{position:absolute;top:-2px;right:-2px;width:14px;height:14px;background:#ef4444;border-radius:50%;font-size:.52rem;color:#fff;font-weight:800;display:none;align-items:center;justify-content:center;border:2px solid rgba(255,255,255,.3);}

    /* PAGE SYSTEM */
    .page{display:none;padding:22px 26px;animation:fadeUp .35s ease both;}
    .page.active{display:block;}
    @keyframes fadeUp{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);}}

    /* SHARED */
    .card{background:var(--white);border-radius:var(--r);border:1.5px solid var(--g200);box-shadow:var(--sh-sm);}
    .card-header{display:flex;align-items:center;justify-content:space-between;padding:14px 20px 12px;border-bottom:1px solid var(--g100);}
    .card-title{font-size:.90rem;font-weight:800;color:var(--g800);}
    .card-sub{font-size:.70rem;color:var(--g400);margin-top:1px;}
    .card-link{font-size:.74rem;color:var(--primary);font-weight:700;cursor:pointer;background:none;border:none;font-family:'Nunito',sans-serif;}
    .badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:.68rem;font-weight:700;white-space:nowrap;}
    .b-pending  {background:#fef3c7;color:#92400e;}
    .b-approved {background:#dcfce7;color:#166534;}
    .b-denied   {background:#fee2e2;color:#991b1b;}
    .b-cancelled{background:#f1f5f9;color:#64748b;}

    /* HERO */
    .hero{background:linear-gradient(130deg,#1e3a8a 0%,#1e40af 35%,#2563eb 68%,#166534 100%);border-radius:var(--r);padding:26px 28px;display:flex;align-items:center;gap:20px;margin-bottom:20px;position:relative;overflow:hidden;box-shadow:var(--sh-md);animation:fadeUp .4s ease both;}
    .hero::before{content:'';position:absolute;right:-50px;top:-50px;width:220px;height:220px;border-radius:50%;background:rgba(255,255,255,.05);}
    .hero-av{width:66px;height:66px;border-radius:50%;background:rgba(255,255,255,.20);border:3px solid rgba(255,255,255,.65);display:flex;align-items:center;justify-content:center;font-size:1.4rem;font-weight:800;color:#fff;flex-shrink:0;box-shadow:0 4px 16px rgba(0,0,0,.22);overflow:hidden;}
    .hero-body h2{font-family:'Playfair Display',serif;font-size:1.25rem;color:#fff;}
    .hero-body p{font-size:.78rem;color:rgba(255,255,255,.65);margin-top:4px;}
    .hero-chips{display:flex;gap:7px;margin-top:10px;flex-wrap:wrap;}
    .hero-chip{padding:3px 12px;border-radius:20px;font-size:.68rem;font-weight:700;background:rgba(255,255,255,.18);color:#fff;}
    .hero-date{margin-left:auto;text-align:right;flex-shrink:0;}
    .hero-date-num{font-size:2.2rem;font-weight:800;color:#fff;line-height:1;}
    .hero-date-str{font-size:.72rem;color:rgba(255,255,255,.60);margin-top:2px;}

    /* BALANCE PILLS */

    /* DASHBOARD GRID */
    .dash-grid{display:grid;grid-template-columns:1fr 320px;gap:18px;}
    .mini-tbl{width:100%;border-collapse:collapse;font-size:.81rem;}
    .mini-tbl th{padding:9px 14px;text-align:left;font-size:.65rem;font-weight:800;color:var(--g400);text-transform:uppercase;letter-spacing:.07em;border-bottom:1.5px solid var(--g200);background:var(--g50);}
    .mini-tbl td{padding:11px 14px;border-bottom:1px solid var(--g100);vertical-align:middle;}
    .mini-tbl tr:last-child td{border-bottom:none;}
    .mini-tbl tbody tr{transition:background .15s;cursor:pointer;}
    .mini-tbl tbody tr:hover{background:var(--g50);}

    /* QUICK ACTIONS */
    .qa-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;padding:14px 16px;}
    .qa-btn{display:flex;flex-direction:column;align-items:center;gap:7px;padding:14px 8px;border-radius:var(--r-sm);border:1.5px solid var(--g200);background:var(--g50);cursor:pointer;transition:all .2s;color:var(--g800);font-family:'Nunito',sans-serif;border-style:none;}
    .qa-btn:hover{border-color:var(--primary);background:var(--primary-lt);transform:translateY(-2px);box-shadow:var(--sh-sm);}
    .qa-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;}
    .qa-lbl{font-size:.72rem;font-weight:700;text-align:center;line-height:1.3;color:var(--g700);}

    /* ANNOUNCEMENTS */
    .ann-item{display:flex;gap:11px;padding:11px 18px;border-bottom:1px solid var(--g100);transition:background .15s;}
    .ann-item:last-child{border-bottom:none;}
    .ann-item:hover{background:var(--g50);}
    .ann-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;margin-top:5px;}
    .ann-strong{font-size:.81rem;font-weight:700;color:var(--g800);}
    .ann-p{font-size:.73rem;color:var(--g600);margin-top:2px;line-height:1.5;}
    .ann-small{font-size:.64rem;color:var(--g400);margin-top:3px;display:block;}
    .ann-tag{display:inline-block;padding:1px 7px;border-radius:5px;font-size:.60rem;font-weight:800;margin-right:4px;}
    .ann-tag.INFO   {background:#dbeafe;color:#1e40af;}
    .ann-tag.URGENT {background:#fee2e2;color:#991b1b;}
    .ann-tag.NOTICE {background:#dcfce7;color:#166534;}
    .ann-tag.WARN   {background:#fef3c7;color:#92400e;}

    /* PROFILE */
    .profile-hero{background:linear-gradient(130deg,#1e3a8a 0%,#1e40af 40%,#2563eb 70%,#166534 100%);border-radius:var(--r);padding:28px;display:flex;align-items:center;gap:20px;margin-bottom:20px;box-shadow:var(--sh-md);animation:fadeUp .4s ease both;}
    .ph-av{width:78px;height:78px;border-radius:50%;background:rgba(255,255,255,.20);border:3px solid rgba(255,255,255,.68);display:flex;align-items:center;justify-content:center;font-size:1.55rem;font-weight:800;color:#fff;flex-shrink:0;overflow:hidden;}
    .ph-info h2{font-family:'Playfair Display',serif;font-size:1.2rem;color:#fff;}
    .ph-info p{font-size:.76rem;color:rgba(255,255,255,.60);margin-top:4px;}
    .ph-chips{display:flex;gap:7px;margin-top:10px;flex-wrap:wrap;}
    .ph-chip{padding:3px 12px;border-radius:20px;font-size:.68rem;font-weight:700;background:rgba(255,255,255,.18);color:#fff;}
    .ph-av{width:78px;height:78px;border-radius:50%;background:rgba(255,255,255,.20);border:3px solid rgba(255,255,255,.68);display:flex;align-items:center;justify-content:center;font-size:1.55rem;font-weight:800;color:#fff;flex-shrink:0;transition:opacity .2s;overflow:hidden;}
    .ph-av:hover{opacity:.85;}
    .ph-av img{width:100%;height:100%;object-fit:cover;border-radius:50%;}
    .ph-edit{padding:8px 18px;background:rgba(255,255,255,.18);border:1.5px solid rgba(255,255,255,.38);border-radius:var(--r-sm);color:#fff;font-family:'Nunito',sans-serif;font-size:.80rem;font-weight:700;cursor:pointer;transition:all .2s;flex-shrink:0;}
    .ph-edit:hover{background:rgba(255,255,255,.30);}
    .ph-edit.editing{background:rgba(239,68,68,.28);border-color:rgba(239,68,68,.55);color:#fff;}
    .profile-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
    .sec-title{font-size:.70rem;font-weight:800;color:var(--primary);text-transform:uppercase;letter-spacing:.10em;margin-bottom:12px;padding-bottom:6px;border-bottom:1.5px solid #dbeafe;}
    .field-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
    .field{background:var(--g50);border-radius:var(--r-sm);padding:10px 14px;border:1px solid var(--g100);}
    .field label{font-size:.63rem;font-weight:800;color:var(--g400);text-transform:uppercase;letter-spacing:.07em;display:block;margin-bottom:3px;}
    .field span{font-size:.83rem;font-weight:700;color:var(--g800);}
    .field input.pf-inp,.field select.pf-inp{width:100%;padding:7px 10px;border:1.5px solid #bfdbfe;border-radius:7px;font-family:'Nunito',sans-serif;font-size:.82rem;color:var(--g800);background:#fff;outline:none;transition:border-color .2s,box-shadow .2s;box-sizing:border-box;}
    .field input.pf-inp:focus,.field select.pf-inp:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(37,99,235,.10);}
    .field.editing{background:#f0f7ff;border:1.5px solid #bfdbfe;}
    .field.full{grid-column:1/-1;}

    /* LEAVE REQUESTS */
    .req-tabs{display:flex;background:var(--white);border-radius:var(--r-sm);border:1.5px solid var(--g200);overflow:hidden;box-shadow:var(--sh-sm);margin-bottom:16px;width:fit-content;max-width:100%;overflow-x:auto;}
    .req-tab{padding:9px 18px;border:none;background:transparent;font-family:'Nunito',sans-serif;font-size:.82rem;font-weight:700;color:var(--g400);cursor:pointer;transition:all .2s;white-space:nowrap;display:flex;align-items:center;gap:6px;border-right:1px solid var(--g200);}
    .req-tab:last-child{border-right:none;}
    .req-tab.active{background:linear-gradient(135deg,var(--primary),var(--primary-dk));color:#fff;}
    .req-tab .tc{background:rgba(255,255,255,.25);padding:1px 6px;border-radius:10px;font-size:.60rem;font-weight:800;}
    .req-tab:not(.active) .tc{background:var(--g100);color:var(--g600);}
    .req-list{display:flex;flex-direction:column;gap:12px;}
    .req-card{background:var(--white);border-radius:var(--r);border:1.5px solid var(--g200);display:flex;align-items:stretch;box-shadow:var(--sh-sm);transition:transform .2s,box-shadow .2s;animation:fadeUp .35s ease both;overflow:hidden;}
    .req-card:hover{transform:translateY(-2px);box-shadow:var(--sh-md);}
    .req-stripe{width:5px;flex-shrink:0;}
    .req-body{flex:1;padding:15px 18px;}
    .req-top{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px;flex-wrap:wrap;}
    .req-title{font-weight:800;font-size:.90rem;color:var(--g800);}
    .req-meta{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:8px;font-size:.77rem;color:var(--g600);}
    .req-reason{font-size:.77rem;color:var(--g600);line-height:1.55;padding:8px 11px;background:var(--g50);border-radius:7px;border-left:3px solid var(--g200);margin-bottom:8px;}
    .req-remark{font-size:.75rem;line-height:1.5;padding:7px 11px;border-radius:7px;margin-bottom:8px;}
    .req-remark.approved{color:#166534;background:#dcfce7;border-left:3px solid #86efac;}
    .req-remark.denied  {color:#991b1b;background:#fee2e2;border-left:3px solid #fca5a5;}
    .req-remark.pending {color:#92400e;background:#fef3c7;border-left:3px solid #fcd34d;}
    .req-footer{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;}
    .req-filed{font-size:.68rem;color:var(--g400);font-weight:600;}
    .cancel-btn{padding:5px 13px;border-radius:6px;border:1.5px solid var(--g200);background:var(--white);font-family:'Nunito',sans-serif;font-size:.72rem;font-weight:700;color:var(--g600);cursor:pointer;transition:all .2s;}
    .cancel-btn:hover{border-color:var(--danger);background:#fee2e2;color:#991b1b;}
    .empty-msg{text-align:center;padding:52px 20px;color:var(--g400);}
    .empty-msg .ei{font-size:2.6rem;margin-bottom:12px;opacity:.45;}
    .empty-msg p{font-size:.875rem;font-weight:600;}

    /* FILE LEAVE */
    .fl-wrap{display:grid;grid-template-columns:1fr 360px;gap:22px;align-items:start;}
    .fl-right{display:flex;flex-direction:column;gap:16px;}
    /* balance list inside right panel */
    .fl-bal-row{display:flex;align-items:center;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--g100);}
    .fl-bal-row:last-child{border-bottom:none;}
    .fl-bal-name{font-size:.80rem;font-weight:700;color:var(--g800);display:flex;align-items:center;gap:6px;}
    .fl-bal-right{text-align:right;}
    .fl-bal-days{font-size:1.05rem;font-weight:800;color:var(--primary);line-height:1;}
    .fl-bal-sub{font-size:.64rem;color:var(--g400);margin-top:1px;}
    .fl-bal-bar{height:5px;border-radius:4px;background:var(--g100);margin-top:6px;overflow:hidden;}
    .fl-bal-fill{height:100%;border-radius:4px;background:linear-gradient(90deg,#2563eb,#22c55e);transition:width .6s ease;}
    .fl-bal-fill.low{background:linear-gradient(90deg,#f59e0b,#ef4444);}
    /* calendar preview */
    .fl-cal-empty{font-size:.78rem;color:var(--g400);text-align:center;padding:12px 0;font-style:italic;}
    .fl-cal-box{text-align:center;}
    .fl-cal-range{font-size:.84rem;font-weight:800;color:var(--g800);margin-bottom:10px;}
    .fl-cal-days-strip{display:flex;gap:5px;flex-wrap:wrap;justify-content:center;}
    .fl-cal-day{width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:800;}
    .fl-cal-day.in-range{background:linear-gradient(135deg,#2563eb,#1e40af);color:#fff;}
    .fl-cal-day.weekend{background:var(--g100);color:var(--g400);}
    .fl-cal-day.today{outline:2px solid var(--primary);outline-offset:1px;}
    .fl-cal-summary{margin-top:10px;font-size:.78rem;color:var(--g600);font-weight:600;}
    /* recent request mini cards */
    .fl-req-item{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--g100);}
    .fl-req-item:last-child{border-bottom:none;}
    .fl-req-dot{width:9px;height:9px;border-radius:50%;flex-shrink:0;}
    .fl-req-info{flex:1;min-width:0;}
    .fl-req-type{font-size:.80rem;font-weight:800;color:var(--g800);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .fl-req-dates{font-size:.68rem;color:var(--g400);margin-top:1px;}
    .fl-req-badge{font-size:.65rem;font-weight:800;padding:3px 8px;border-radius:20px;white-space:nowrap;}
    .fl-req-badge.pending{background:#fef3c7;color:#92400e;}
    .fl-req-badge.approved{background:#dcfce7;color:#166534;}
    .fl-req-badge.rejected{background:#fee2e2;color:#991b1b;}
    .fl-req-badge.cancelled{background:var(--g100);color:var(--g400);}
    .form-header{background:linear-gradient(135deg,#1e40af,#2563eb);border-radius:var(--r) var(--r) 0 0;padding:18px 22px;}
    .form-header h3{font-family:'Playfair Display',serif;font-size:1rem;color:#fff;}
    .form-header p{font-size:.73rem;color:rgba(255,255,255,.62);margin-top:2px;}
    .lt-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px;}
    .lt-opt{padding:12px 10px;border-radius:var(--r-sm);border:1.5px solid var(--g200);background:var(--g50);cursor:pointer;transition:all .2s;text-align:center;}
    .lt-opt:hover{border-color:var(--primary);background:var(--primary-lt);}
    .lt-opt.sel{border-color:var(--primary);background:var(--primary-lt);box-shadow:0 0 0 3px rgba(37,99,235,.12);}
    .lt-icon{font-size:1.3rem;margin-bottom:5px;}
    .lt-name{font-size:.75rem;font-weight:800;color:var(--g800);}
    .lt-avail{font-size:.63rem;color:var(--g400);margin-top:2px;}
    .days-box{display:flex;align-items:center;gap:14px;padding:13px 16px;background:var(--g50);border-radius:var(--r-sm);border:1.5px solid var(--g200);margin-bottom:14px;}
    .days-num{font-size:2rem;font-weight:800;color:var(--primary);line-height:1;}
    .days-lbl{font-size:.77rem;color:var(--g600);font-weight:600;}
    .days-bal{font-size:.70rem;color:var(--g400);margin-top:3px;}
    .fg{display:flex;flex-direction:column;gap:5px;margin-bottom:14px;}
    .fg label{font-size:.74rem;font-weight:700;color:var(--g600);}
    .fg label .req{color:var(--danger);margin-left:2px;}
    .fg input,.fg select,.fg textarea{padding:9px 12px;border:1.5px solid var(--g200);border-radius:var(--r-sm);font-size:.875rem;font-family:'Nunito',sans-serif;color:var(--g800);background:var(--white);outline:none;transition:border-color .2s;}
    .fg input:focus,.fg select:focus,.fg textarea:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(37,99,235,.10);}
    .fg input.err,.fg select.err,.fg textarea.err{border-color:var(--danger);}
    .fg textarea{resize:vertical;height:90px;}
    .fg-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
    .submit-btn{width:100%;padding:12px;background:linear-gradient(135deg,var(--primary),var(--primary-dk));color:#fff;border:none;border-radius:var(--r-sm);font-family:'Nunito',sans-serif;font-size:.95rem;font-weight:800;cursor:pointer;transition:all .2s;box-shadow:0 3px 12px rgba(37,99,235,.32);margin-top:4px;}
    /* 6B details of leave */
    .detail-section{display:none;background:#f0f7ff;border:1.5px solid #bfdbfe;border-radius:var(--r-sm);padding:14px 16px;margin-bottom:14px;}
    .detail-section.show{display:block;animation:fadeUp .3s ease both;}
    .detail-section-title{font-size:.68rem;font-weight:800;color:var(--primary);text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px;padding-bottom:6px;border-bottom:1.5px solid #bfdbfe;}
    .detail-radio-group{display:flex;flex-direction:column;gap:7px;}
    .detail-radio-row{display:flex;align-items:center;gap:8px;font-size:.82rem;font-weight:600;color:var(--g700,#374151);cursor:pointer;}
    .detail-radio-row input[type=radio],.detail-radio-row input[type=checkbox]{width:15px;height:15px;accent-color:var(--primary);cursor:pointer;flex-shrink:0;}
    .detail-extra{margin-top:8px;}
    .detail-extra input[type=text]{width:100%;padding:8px 11px;border:1.5px solid var(--g200);border-radius:var(--r-sm);font-size:.84rem;font-family:'Nunito',sans-serif;color:var(--g800);outline:none;transition:border-color .2s;background:var(--white);}
    .detail-extra input[type=text]:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(37,99,235,.10);}
    .detail-extra label{font-size:.72rem;font-weight:700;color:var(--g600);display:block;margin-bottom:4px;}
    .submit-btn:hover{transform:translateY(-1px);box-shadow:0 5px 18px rgba(37,99,235,.40);}
    .tips{list-style:none;display:flex;flex-direction:column;gap:8px;padding:14px 18px;}
    .tips li{font-size:.79rem;color:var(--g600);display:flex;gap:8px;line-height:1.5;}

    /* LEAVE BALANCES */
    .balances-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;}
    .bal-card{background:var(--white);border-radius:var(--r);border:1.5px solid var(--g200);box-shadow:var(--sh-sm);padding:20px 22px;animation:fadeUp .4s ease both;}
    .bc-top{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:16px;}
    .bc-title{font-size:.90rem;font-weight:800;color:var(--g800);}
    .bc-sub{font-size:.70rem;color:var(--g400);margin-top:2px;}
    .bc-icon{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;}
    .bc-nums{display:flex;gap:12px;margin-bottom:14px;}
    .bc-nb{flex:1;text-align:center;padding:10px 6px;background:var(--g50);border-radius:var(--r-sm);}
    .bc-n{font-size:1.5rem;font-weight:800;line-height:1;margin-bottom:2px;}
    .bc-nl{font-size:.62rem;font-weight:700;color:var(--g400);text-transform:uppercase;letter-spacing:.06em;}
    .bc-bar{height:8px;background:var(--g100);border-radius:8px;overflow:hidden;}
    .bc-fill{height:100%;border-radius:8px;transition:width 1.2s ease;}
    .hist{margin-top:14px;}
    .hist-label{font-size:.65rem;font-weight:800;color:var(--g400);text-transform:uppercase;letter-spacing:.08em;margin-bottom:7px;}
    .hist-row{display:flex;align-items:center;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--g100);font-size:.77rem;}
    .hist-row:last-child{border-bottom:none;}
    .hist-date{font-size:.68rem;color:var(--g400);}
    .hist-none{font-size:.74rem;color:var(--g400);padding-top:5px;}

    /* ══ DOCUMENTS PAGE ══ */
    .doc-stat-row{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px;}
    .doc-stat{background:var(--white);border-radius:var(--r);padding:16px 18px;border:1.5px solid var(--g200);box-shadow:var(--sh-sm);display:flex;align-items:center;gap:14px;animation:fadeUp .35s ease both;}
    .doc-stat-icon{width:44px;height:44px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;}
    .doc-stat-val{font-size:1.6rem;font-weight:800;color:var(--g800);line-height:1;}
    .doc-stat-lbl{font-size:.72rem;color:var(--g400);font-weight:600;margin-top:2px;}

    .doc-toolbar{display:flex;align-items:center;gap:10px;padding:12px 18px;background:var(--g50);border-bottom:1px solid var(--g100);flex-wrap:wrap;}
    .doc-search{display:flex;align-items:center;gap:8px;background:var(--white);border:1.5px solid var(--g200);border-radius:var(--r-sm);padding:8px 12px;flex:1;min-width:180px;max-width:260px;}
    .doc-search:focus-within{border-color:var(--primary);}
    .doc-search input{border:none;outline:none;font-family:'Nunito',sans-serif;font-size:.83rem;width:100%;background:transparent;color:var(--g800);}
    .doc-search input::placeholder{color:var(--g400);}
    .doc-filter{padding:8px 12px;border:1.5px solid var(--g200);border-radius:var(--r-sm);font-family:'Nunito',sans-serif;font-size:.83rem;color:var(--g600);background:var(--white);outline:none;cursor:pointer;}
    .doc-filter:focus{border-color:var(--primary);}
    .doc-upload-btn{margin-left:auto;display:flex;align-items:center;gap:6px;padding:8px 18px;background:linear-gradient(135deg,var(--primary),var(--primary-dk));color:#fff;border:none;border-radius:var(--r-sm);font-family:'Nunito',sans-serif;font-size:.83rem;font-weight:700;cursor:pointer;transition:all .2s;box-shadow:0 2px 8px rgba(37,99,235,.28);white-space:nowrap;}
    .doc-upload-btn:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(37,99,235,.38);}
    .doc-reset-btn{display:flex;align-items:center;gap:6px;padding:8px 16px;background:var(--g100);color:var(--g600);border:1.5px solid var(--g200);border-radius:var(--r-sm);font-family:'Nunito',sans-serif;font-size:.83rem;font-weight:700;cursor:pointer;transition:all .2s;white-space:nowrap;}
    .doc-reset-btn:hover{background:var(--g200);border-color:var(--g300);color:var(--g700);transform:translateY(-1px);}
    .doc-reset-btn:active{transform:translateY(0);}

    .doc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;padding:18px;}
    .doc-card{background:var(--white);border-radius:var(--r);border:1.5px solid var(--g200);box-shadow:var(--sh-sm);overflow:hidden;transition:transform .2s,box-shadow .2s;animation:fadeUp .35s ease both;}
    .doc-card:hover{transform:translateY(-3px);box-shadow:var(--sh-md);}
    .doc-card-top{padding:16px 16px 12px;display:flex;align-items:flex-start;gap:12px;}
    .doc-file-icon{width:46px;height:46px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;}
    .doc-file-name{font-size:.82rem;font-weight:700;color:var(--g800);line-height:1.4;word-break:break-all;}
    .doc-file-size{font-size:.68rem;color:var(--g400);margin-top:3px;}
    .doc-type-badge{display:inline-block;padding:2px 9px;border-radius:6px;font-size:.66rem;font-weight:800;margin-top:5px;}
    .doc-card-foot{display:flex;align-items:center;justify-content:space-between;padding:9px 14px;background:var(--g50);border-top:1px solid var(--g100);}
    .doc-date{font-size:.66rem;color:var(--g400);font-weight:600;}
    .doc-actions{display:flex;gap:5px;}
    .doc-act-btn{width:28px;height:28px;border-radius:7px;border:1.5px solid var(--g200);background:var(--white);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.78rem;transition:all .2s;text-decoration:none;color:inherit;}
    .doc-act-btn.view:hover{border-color:var(--primary);background:var(--primary-lt);}
    .doc-act-btn.dl:hover  {border-color:var(--accent);background:var(--accent-lt);}
    .doc-act-btn.del:hover {border-color:var(--danger);background:#fee2e2;}

    /* UPLOAD MODAL */
    .modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:500;align-items:center;justify-content:center;backdrop-filter:blur(4px);}
    .modal-overlay.open{display:flex;}
    .modal{background:var(--white);border-radius:var(--r);box-shadow:var(--sh-lg);width:92%;max-width:480px;max-height:92vh;overflow-y:auto;animation:modalIn .3s cubic-bezier(.34,1.4,.64,1) both;}
    @keyframes modalIn{from{opacity:0;transform:scale(.88) translateY(20px);}to{opacity:1;transform:scale(1) translateY(0);}}
    .modal-head{padding:18px 22px 14px;background:linear-gradient(135deg,#1e40af,#2563eb);border-radius:var(--r) var(--r) 0 0;display:flex;align-items:center;justify-content:space-between;}
    .modal-head.danger{background:linear-gradient(135deg,#ef4444,#dc2626);}
    .modal-head h3{font-family:'Playfair Display',serif;font-size:1rem;color:#fff;}
    .modal-head p{font-size:.72rem;color:rgba(255,255,255,.62);margin-top:2px;}
    .modal-x{width:30px;height:30px;border-radius:50%;background:rgba(255,255,255,.18);border:none;color:#fff;font-size:.95rem;cursor:pointer;display:flex;align-items:center;justify-content:center;}
    .modal-x:hover{background:rgba(255,255,255,.30);}
    .modal-body{padding:20px 22px;}
    .modal-foot{display:flex;justify-content:flex-end;gap:10px;padding:12px 22px 16px;border-top:1px solid var(--g100);}
    .drop-zone{border:2.5px dashed var(--g200);border-radius:var(--r-sm);padding:26px;text-align:center;cursor:pointer;transition:all .2s;background:var(--g50);margin-bottom:14px;}
    .drop-zone:hover,.drop-zone.over{border-color:var(--primary);background:var(--primary-lt);}
    .drop-zone .dz-ic{font-size:2.2rem;display:block;margin-bottom:8px;}
    .drop-zone p{font-size:.83rem;color:var(--g600);font-weight:600;}
    .drop-zone span{font-size:.70rem;color:var(--g400);}
    .drop-zone input{display:none;}
    .fp-row{display:none;align-items:center;gap:11px;padding:10px 13px;background:var(--g50);border-radius:var(--r-sm);border:1.5px solid var(--g200);margin-bottom:14px;}
    .fp-row.show{display:flex;}
    .fp-ic{font-size:1.5rem;}
    .fp-info{flex:1;min-width:0;}
    .fp-name{font-weight:700;font-size:.82rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .fp-sz{font-size:.70rem;color:var(--g400);}
    .fp-x{background:none;border:none;cursor:pointer;color:var(--g400);font-size:.95rem;}
    .fp-x:hover{color:var(--danger);}
    .prog-wrap{display:none;margin-bottom:14px;}
    .prog-wrap.show{display:block;}
    .prog-bar{height:7px;background:var(--g100);border-radius:20px;overflow:hidden;}
    .prog-fill{height:100%;background:linear-gradient(90deg,var(--primary),#60a5fa);border-radius:20px;width:0%;transition:width .3s;}
    .prog-lbl{font-size:.70rem;color:var(--g400);margin-top:3px;text-align:center;}
    .confirm-body{padding:26px 22px;text-align:center;}
    .confirm-icon{font-size:2.4rem;margin-bottom:10px;}
    .confirm-body h4{font-size:.95rem;font-weight:800;color:var(--g800);margin-bottom:6px;}
    .confirm-body p{font-size:.82rem;color:var(--g600);line-height:1.6;}
    .m-btn{padding:8px 20px;border-radius:var(--r-sm);font-family:'Nunito',sans-serif;font-size:.84rem;font-weight:700;cursor:pointer;transition:all .2s;border:none;}
    .m-btn-cancel{background:var(--white);border:1.5px solid var(--g200);color:var(--g600);}
    .m-btn-cancel:hover{border-color:var(--g400);}
    .m-btn-primary{background:linear-gradient(135deg,var(--primary),var(--primary-dk));color:#fff;}
    .m-btn-primary:hover{transform:translateY(-1px);}
    .m-btn-danger{background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;}
    .m-btn-danger:hover{transform:translateY(-1px);}

    /* TOAST */
    .toast{position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:var(--r-sm);font-size:.83rem;font-weight:700;box-shadow:var(--sh-lg);z-index:999;color:#fff;animation:fadeUp .3s ease both;max-width:340px;}
    .toast.success{background:linear-gradient(135deg,#22c55e,#15803d);}
    .toast.error  {background:linear-gradient(135deg,#ef4444,#dc2626);}
    .toast.info   {background:linear-gradient(135deg,#2563eb,#1e40af);}

    .overlay-mob{display:none;position:fixed;inset:0;background:rgba(0,0,0,.48);z-index:250;}
    .overlay-mob.show{display:block;}

    @media(max-width:1100px){
      .dash-grid{grid-template-columns:1fr;}
      .balances-grid{grid-template-columns:1fr;}
      .profile-grid{grid-template-columns:1fr;}
      .doc-stat-row{grid-template-columns:1fr 1fr 1fr;}
    }

    @media(max-width:768px){
      :root{--sidebar-w:220px;--topbar-h:56px;}
      
      /* Sidebar */
      .sidebar{
        transform:translateX(-100%);
        width:220px;
        z-index:300;
      }
      .sidebar.open{transform:translateX(0);}
      .sb-brand{padding:16px 14px 12px;gap:8px;}
      .sb-logo{width:36px;height:36px;}
      .sb-brand-text h2{font-size:.88rem;}
      .sb-brand-text span{font-size:.56rem;}
      .sb-user{padding:12px 14px 10px;gap:8px;}
      .sb-avatar{width:40px;height:40px;font-size:.80rem;}
      .sb-uname{font-size:.78rem;}
      .sb-upos{font-size:.62rem;}
      .sb-nav{padding:8px;}
      .sb-item{padding:8px 10px;font-size:.80rem;gap:8px;}
      .sb-icon{width:26px;height:26px;font-size:.85rem;}
      .sb-section{font-size:.55rem;padding:8px 8px 3px;}
      .sb-label{font-size:.56rem;padding:8px 8px 2px;}
      .sb-logout{padding:8px 10px;font-size:.80rem;gap:8px;}
      
      /* Topbar */
      .main{margin-left:0;}
      .topbar{
        height:var(--topbar-h);
        padding:0 12px;
        gap:10px;
      }
      .menu-toggle{display:block;font-size:1.2rem;padding:2px;}
      .tb-title{font-size:.95rem;}
      .tb-sub{font-size:.65rem;}
      .tb-chip{display:none;}
      .tb-btn{width:32px;height:32px;font-size:.88rem;}
      .tb-btn .notif{width:12px;height:12px;font-size:.50rem;}
      
      /* Pages */
      .page{padding:14px;}
      
      /* Hero */
      .hero{padding:16px 14px;gap:12px;margin-bottom:14px;}
      .hero-av{width:56px;height:56px;font-size:1.2rem;}
      .hero-body h2{font-size:1.1rem;}
      .hero-body p{font-size:.74rem;}
      .hero-date{display:none;}
      
      /* Dashboard grid */
      .dash-grid{grid-template-columns:1fr;gap:14px;}
      .mini-tbl{font-size:.77rem;}
      .mini-tbl th{padding:7px 10px;font-size:.62rem;}
      .mini-tbl td{padding:9px 10px;}
      
      /* Cards */
      .card{margin-bottom:2px;}
      .card-title{font-size:.87rem;}
      .card-header{padding:12px 16px 10px;}
      
      /* Quick actions */
      .qa-grid{grid-template-columns:1fr 1fr;padding:12px;gap:8px;}
      .qa-btn{padding:12px 6px;gap:6px;}
      .qa-icon{width:36px;height:36px;font-size:1rem;}
      .qa-lbl{font-size:.68rem;}
      
      /* Announcements */
      .ann-item{padding:9px 14px;}
      
      /* Profile */
      .profile-grid{grid-template-columns:1fr;gap:14px;}
      .field-grid{grid-template-columns:1fr;gap:8px;}
      .sec-title{font-size:.68rem;margin-bottom:10px;}
      
      /* Leave requests */
      .req-tabs{width:100%;flex-wrap:nowrap;overflow-x:auto;}
      .req-tab{padding:7px 14px;font-size:.78rem;white-space:nowrap;}
      .req-card{margin-bottom:2px;}
      .req-body{padding:12px 14px;}
      .req-top{gap:6px;}
      .req-title{font-size:.85rem;}
      .req-meta{font-size:.73rem;}
      
      /* File leave */
      .fl-wrap{grid-template-columns:1fr;gap:14px;}
      .fl-right{order:2;}
      .lt-grid{grid-template-columns:repeat(2,1fr);gap:8px;}
      .lt-opt{padding:10px 8px;}
      .form-section-body{padding:14px;}
      .fg{margin-bottom:12px;}
      .fg label{font-size:.70rem;}
      .fg input,.fg select,.fg textarea{padding:8px 10px;font-size:.83rem;}
      .fg textarea{height:65px;}
      .fg-row{grid-template-columns:1fr;gap:10px;}
      
      /* Balances */
      .balances-grid{grid-template-columns:1fr;gap:14px;}
      .bal-card{padding:16px 18px;}
      .bc-nums{gap:8px;}
      
      /* Documents */
      .doc-stat-row{grid-template-columns:1fr;gap:12px;}
      .doc-stat{padding:12px 14px;}
      .doc-toolbar{gap:8px;flex-wrap:wrap;padding:10px 14px;}
      .doc-search{min-width:140px;max-width:100%;flex:1;}
      .doc-upload-btn{margin-left:0;padding:7px 14px;font-size:.80rem;}
      .doc-reset-btn{padding:7px 12px;font-size:.80rem;}
      .doc-grid{grid-template-columns:1fr;gap:12px;padding:12px;}
      .doc-card{margin-bottom:2px;}
      
      /* Modal */
      .modal{width:94%;max-width:100%;}
      .modal-head{padding:14px 18px 10px;}
      .modal-head h3{font-size:.95rem;}
      .modal-body{padding:16px 18px;}
      .drop-zone{padding:20px;margin-bottom:12px;}
      
      /* Forms in modals */
      .fg input,.fg select,.fg textarea{font-size:.83rem;}
    }

    @media(max-width:480px){
      :root{--sidebar-w:180px;--topbar-h:52px;}
      
      html{font-size:13px;}
      
      /* Sidebar */
      .sidebar{width:180px;}
      .sb-brand{padding:12px 10px 8px;gap:6px;}
      .sb-logo{width:32px;height:32px;}
      .sb-brand-text h2{font-size:.80rem;}
      .sb-brand-text span{font-size:.52rem;letter-spacing:.08em;}
      .sb-user{padding:10px 10px 8px;gap:6px;}
      .sb-avatar{width:36px;height:36px;font-size:.75rem;}
      .sb-uname{font-size:.75rem;}
      .sb-upos{font-size:.60rem;}
      .sb-nav{padding:6px;}
      .sb-item{padding:7px 8px;font-size:.75rem;margin-bottom:1px;}
      .sb-icon{width:24px;height:24px;font-size:.80rem;}
      .sb-section{font-size:.52rem;padding:6px 6px 2px;}
      .sb-label{font-size:.52rem;padding:6px 6px 2px;}
      .sb-logout{padding:7px 8px;font-size:.75rem;}
      .sb-badge{font-size:.54rem;}
      
      /* Topbar */
      .topbar{
        padding:0 10px;
        gap:8px;
        height:var(--topbar-h);
      }
      .menu-toggle{font-size:1.1rem;padding:1px;}
      .tb-left{flex:1;}
      .tb-title{font-size:.90rem;font-weight:700;}
      .tb-sub{font-size:.62rem;display:none;}
      .tb-right{gap:6px;}
      .notif-btn-ep{width:32px;height:32px;}
      .notif-cnt-ep{width:14px;height:14px;font-size:.48rem;}
      
      /* General */
      .page{padding:10px;}
      
      /* Hero */
      .hero{
        padding:12px 10px;
        gap:10px;
        border-radius:11px;
        flex-wrap:wrap;
      }
      .hero::before{display:none;}
      .hero-av{width:48px;height:48px;font-size:1rem;}
      .hero-body h2{font-size:1rem;margin-bottom:1px;}
      .hero-body p{font-size:.70rem;margin-top:1px;}
      .hero-chips{gap:5px;margin-top:6px;}
      .hero-chip{padding:2px 10px;font-size:.64rem;}
      
      /* Cards */
      .card{border-radius:10px;margin-bottom:1px;}
      .card-header{padding:10px 12px 8px;gap:6px;flex-wrap:wrap;}
      .card-title{font-size:.82rem;}
      .card-sub{font-size:.65rem;}
      .card-link{font-size:.70rem;}
      
      /* Tables */
      .mini-tbl{font-size:.72rem;}
      .mini-tbl th{padding:6px 8px;font-size:.60rem;}
      .mini-tbl td{padding:8px;}
      .mini-tbl tbody tr{font-size:.72rem;}
      
      /* Dashboard */
      .dash-grid{grid-template-columns:1fr;gap:10px;}
      
      /* Quick actions */
      .qa-grid{grid-template-columns:1fr 1fr;padding:10px;gap:6px;}
      .qa-btn{padding:10px 4px;border-radius:8px;}
      .qa-icon{width:32px;height:32px;font-size:.95rem;border-radius:8px;}
      .qa-lbl{font-size:.65rem;}
      
      /* Announcements */
      .ann-item{padding:7px 10px;gap:8px;}
      .ann-strong{font-size:.77rem;}
      .ann-p{font-size:.70rem;}
      .ann-small{font-size:.62rem;}
      
      /* Profile */
      .profile-hero{
        padding:16px;
        gap:12px;
        border-radius:11px;
      }
      .profile-hero .ph-edit{
        padding:6px 12px;
        font-size:.75rem;
        top:10px;
        right:10px;
      }
      .ph-av{width:56px;height:56px;font-size:1.2rem;border-width:2px;}
      .ph-info h2{font-size:1rem;}
      .ph-info p{font-size:.72rem;}
      .profile-grid{grid-template-columns:1fr;gap:12px;}
      .field-grid{grid-template-columns:1fr;gap:6px;}
      .sec-title{font-size:.65rem;margin-bottom:8px;}
      .field{padding:8px 10px;border-radius:7px;}
      .field label{font-size:.60rem;margin-bottom:2px;}
      .field span{font-size:.80rem;}
      
      /* Leave requests */
      .req-tabs{width:100%;overflow-x:auto;}
      .req-tab{padding:6px 12px;font-size:.74rem;white-space:nowrap;}
      .req-card{border-radius:10px;margin-bottom:1px;}
      .req-body{padding:10px 12px;}
      .req-top{gap:6px;margin-bottom:6px;}
      .req-title{font-size:.82rem;}
      .req-meta{font-size:.70rem;gap:6px;}
      .req-reason{font-size:.72rem;padding:6px 8px;border-radius:6px;margin-bottom:6px;}
      .cancel-btn{padding:4px 10px;font-size:.68rem;border-radius:5px;}
      
      /* File leave */
      .fl-wrap{grid-template-columns:1fr;gap:10px;}
      .fl-right{gap:12px;order:2;}
      .lt-grid{grid-template-columns:1fr 1fr;gap:6px;}
      .lt-opt{padding:8px 6px;border-radius:8px;}
      .lt-icon{font-size:1.1rem;margin-bottom:3px;}
      .lt-name{font-size:.70rem;}
      .lt-avail{font-size:.60rem;}
      .days-box{gap:10px;padding:10px 12px;border-radius:8px;margin-bottom:10px;}
      .days-num{font-size:1.8rem;}
      .days-lbl{font-size:.73rem;}
      .form-header{padding:14px 16px;border-radius:9px 9px 0 0;}
      .form-header h3{font-size:.95rem;}
      .form-header p{font-size:.70rem;}
      .form-section-hd{padding:8px 14px;font-size:.76rem;border-radius:7px 7px 0 0;}
      .form-section-body{padding:12px;border-radius:0 0 7px 7px;}
      .fg{margin-bottom:10px;}
      .fg label{font-size:.68rem;margin-bottom:3px;}
      .fg input,.fg select,.fg textarea{padding:8px 10px;font-size:.82rem;border-radius:6px;}
      .fg textarea{height:60px;}
      .fg-row{grid-template-columns:1fr;gap:8px;}
      .submit-btn{padding:10px;font-size:.90rem;border-radius:7px;margin-top:2px;}
      .detail-section{padding:10px 12px;border-radius:6px;margin-bottom:10px;}
      .detail-section-title{font-size:.65rem;margin-bottom:8px;}
      
      /* Balances */
      .balances-grid{grid-template-columns:1fr;gap:12px;}
      .bal-card{padding:14px 16px;border-radius:10px;}
      .bc-top{margin-bottom:12px;}
      .bc-title{font-size:.85rem;}
      .bc-icon{width:40px;height:40px;font-size:1.1rem;}
      .bc-nums{gap:8px;margin-bottom:10px;}
      .bc-nb{padding:8px 4px;border-radius:6px;}
      .bc-n{font-size:1.3rem;margin-bottom:1px;}
      .bc-nl{font-size:.60rem;}
      .hist-label{font-size:.62rem;margin-bottom:5px;}
      .hist-row{padding:5px 0;font-size:.72rem;}
      
      /* Documents */
      .doc-stat-row{grid-template-columns:1fr;gap:10px;}
      .doc-stat{padding:10px 12px;border-radius:9px;}
      .doc-stat-icon{width:40px;height:40px;font-size:1.1rem;border-radius:8px;}
      .doc-stat-val{font-size:1.4rem;}
      .doc-stat-lbl{font-size:.68rem;margin-top:1px;}
      .doc-toolbar{gap:6px;padding:8px 10px;flex-wrap:wrap;}
      .doc-search{min-width:130px;max-width:100%;padding:7px 10px;border-radius:6px;}
      .doc-search input{font-size:.80rem;}
      .doc-filter{padding:7px 10px;border-radius:6px;font-size:.80rem;}
      .doc-upload-btn{margin-left:0;padding:7px 12px;font-size:.78rem;border-radius:6px;}
      .doc-grid{grid-template-columns:1fr;gap:10px;padding:10px;}
      .doc-card{border-radius:9px;margin-bottom:1px;overflow:hidden;}
      .doc-card-top{padding:12px 12px 10px;gap:10px;}
      .doc-file-icon{width:40px;height:40px;font-size:1.1rem;border-radius:8px;}
      .doc-file-name{font-size:.80rem;}
      .doc-file-size{font-size:.65rem;}
      
      /* Modals */
      .modal{width:96%;max-width:100%;max-height:95vh;border-radius:11px;}
      .modal-head{padding:12px 16px 10px;border-radius:11px 11px 0 0;}
      .modal-head h3{font-size:.90rem;}
      .modal-head p{font-size:.68rem;}
      .modal-x{width:28px;height:28px;font-size:.90rem;}
      .modal-body{padding:14px 16px;}
      .modal-footer{padding:10px 16px;gap:8px;}
      .drop-zone{padding:16px;border-radius:8px;margin-bottom:10px;}
      .drop-zone .dz-ic{font-size:1.8rem;margin-bottom:6px;}
      .drop-zone p{font-size:.80rem;}
      .drop-zone span{font-size:.67rem;}
      
      /* Toast */
      .toast{
        bottom:12px;
        right:8px;
        left:8px;
        padding:10px 14px;
        font-size:.80rem;
        border-radius:8px;
        max-width:none;
      }
      
      /* Modals and other */
      .m-btn{padding:7px 16px;font-size:.80rem;border-radius:6px;}
      .confirm-body{padding:20px 16px;}
      .confirm-icon{font-size:2rem;margin-bottom:8px;}
      .confirm-body h4{font-size:.90rem;margin-bottom:4px;}
      .confirm-body p{font-size:.78rem;}
    }

    @media(max-width:360px){
      html{font-size:12px;}
      
      .sidebar{width:160px;}
      .sb-brand-text h2{font-size:.75rem;}
      .sb-item{font-size:.70rem;padding:6px 6px;}
      .sb-icon{width:22px;height:22px;font-size:.75rem;}
      
      .topbar{padding:0 8px;}
      .page{padding:8px;}
      
      .card-title{font-size:.78rem;}
      .field-grid{grid-template-columns:1fr;}
      .lt-grid{grid-template-columns:1fr;}
    }

    /* Touch device optimizations */
    @media(hover:none) and (pointer:coarse){
      /* Make buttons bigger for touch */
      .sb-item{min-height:40px;padding:6px 8px;}
      .qa-btn{min-height:50px;touch-action:manipulation;}
      .btn,.m-btn,.submit-btn,.cancel-btn{min-height:44px;touch-action:manipulation;}
      .card-link{min-height:40px;padding:6px 8px;display:inline-flex;align-items:center;}
      .req-tab{min-height:40px;}
      
      /* Remove hover effects */
      .hero:hover{transform:none;}
      .req-card:hover{transform:none;}
      .doc-card:hover{transform:none;}
      .qa-btn:hover{transform:none;}
    }

    /* ── Document Preview Modal ── */
    .modal.preview{max-width:860px;width:96%;max-height:96vh;display:flex;flex-direction:column;}
    .preview-body{flex:1;padding:0;display:flex;flex-direction:column;min-height:0;}
    .preview-frame{flex:1;width:100%;border:none;min-height:70vh;background:#1e293b;display:block;}
    .preview-img{max-width:100%;max-height:78vh;object-fit:contain;display:block;margin:0 auto;padding:16px;}
    .preview-unsupported{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:60px 30px;color:var(--g400);text-align:center;min-height:260px;}
    .preview-unsupported .pu-icon{font-size:3rem;margin-bottom:14px;opacity:.5;}
    .preview-unsupported p{font-size:.875rem;font-weight:600;line-height:1.6;}
    .preview-footer{display:flex;align-items:center;justify-content:space-between;padding:12px 22px;border-top:1px solid var(--g100);gap:10px;flex-wrap:wrap;}
    .preview-fname{font-size:.80rem;font-weight:700;color:var(--g600);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:400px;}
    .prev-dl-btn{display:flex;align-items:center;gap:6px;padding:7px 18px;background:linear-gradient(135deg,var(--primary),var(--primary-dk));color:#fff;border:none;border-radius:var(--r-sm);font-family:'Nunito',sans-serif;font-size:.82rem;font-weight:700;cursor:pointer;text-decoration:none;}
    .prev-dl-btn:hover{opacity:.9;}
    /* ── Switch Mode Banner ── */
    .switch-banner{position:sticky;top:var(--topbar-h);z-index:190;background:linear-gradient(135deg,#78350f,#92400e,#b45309);color:#fff;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 26px;font-family:'Nunito',sans-serif;box-shadow:0 3px 14px rgba(0,0,0,.25);flex-wrap:wrap;}
    .switch-banner-left{display:flex;align-items:center;gap:10px;}
    .switch-banner-icon{font-size:1.3rem;animation:pulse 2s infinite;}
    .switch-banner-text strong{display:block;font-size:.88rem;font-weight:800;letter-spacing:.01em;}
    .switch-banner-text span{font-size:.75rem;opacity:.88;font-weight:600;}
    .switch-back-btn{display:flex;align-items:center;gap:7px;padding:8px 18px;background:rgba(255,255,255,.15);border:1.5px solid rgba(255,255,255,.45);border-radius:10px;color:#fff;font-family:'Nunito',sans-serif;font-size:.82rem;font-weight:800;cursor:pointer;text-decoration:none;transition:all .2s;white-space:nowrap;}
    .switch-back-btn:hover{background:rgba(255,255,255,.28);border-color:#fff;transform:translateY(-1px);}
    @keyframes pulse{0%,100%{opacity:1;}50%{opacity:.6;}}
  
    .notif-wrap-ep{position:relative;display:inline-block;}
    .notif-btn-ep{position:relative;width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.22);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.92rem;color:#fff;transition:background .2s;font-family:inherit;}
    .notif-btn-ep:hover{background:rgba(255,255,255,.26);}
    .notif-cnt-ep{position:absolute;top:-2px;right:-2px;min-width:16px;height:16px;background:#ef4444;border-radius:20px;font-size:.50rem;color:#fff;font-weight:800;display:none;align-items:center;justify-content:center;border:2px solid rgba(255,255,255,.3);padding:0 3px;}
    .notif-cnt-ep.show{display:flex;}
    .notif-panel-ep{display:none;position:absolute;top:calc(100%+10px);right:0;background:#fff;border-radius:14px;box-shadow:0 8px 32px rgba(0,0,0,.18);width:320px;max-height:440px;z-index:9999;overflow:hidden;flex-direction:column;}
    .notif-panel-ep.open{display:flex;}
    .np-hd{padding:12px 14px 8px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;background:#f8fafc;flex-shrink:0;}
    .np-hd-title{font-size:.85rem;font-weight:800;color:#1e293b;}
    .np-mark{font-size:.70rem;font-weight:700;color:#2563eb;cursor:pointer;border:none;background:none;font-family:inherit;}
    .np-list{overflow-y:auto;flex:1;}
    .np-item{display:flex;align-items:flex-start;gap:10px;padding:11px 14px;border-bottom:1px solid #f8fafc;cursor:pointer;transition:background .15s;}
    .np-item:hover{background:#f8fafc;} .np-item.unread{background:#eff6ff;}
    .np-ico{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0;}
    .np-body{flex:1;min-width:0;}
    .np-title{font-size:.78rem;font-weight:800;color:#1e293b;margin-bottom:1px;}
    .np-text{font-size:.72rem;color:#475569;line-height:1.35;}
    .np-time{font-size:.64rem;color:#94a3b8;margin-top:2px;}
    .np-empty{text-align:center;padding:36px 16px;color:#94a3b8;}
    .np-empty .ne-icon{font-size:2rem;margin-bottom:6px;}
    .np-empty p{font-size:.80rem;font-weight:600;}
  
    .tb-profile-wrap{position:relative;display:inline-block;}
    .tb-av-ini{font-size:.78rem;font-weight:800;color:#fff;}
  </style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="sb-brand">
    <img src="../images/Enrile.png" alt="LGU" class="sb-logo" onerror="this.style.display='none'"/>
    <div class="sb-brand-text"><h2>LGU Enrile</h2><span>Employee Portal</span></div>
  </div>
  <div class="sb-user">
    <div class="sb-avatar" id="sbAvatar" style="background:linear-gradient(135deg,#2563eb,#22c55e);">??</div>
    <div>
      <div class="sb-uname" id="sbName"><?= htmlspecialchars($displayName) ?></div>
      <div class="sb-upos"  id="sbPos">Loading…</div>
    </div>
    <div class="sb-status" title="Online"></div>
  </div>
  <nav class="sb-nav">
    <div class="sb-section">Navigation</div>
    <button class="sb-item active" onclick="goPage('dashboard',this)"><div class="sb-icon">🏠</div>Dashboard</button>
    <button class="sb-item" onclick="goPage('profile',this)"><div class="sb-icon">👤</div>My Profile</button>
    <button class="sb-item" onclick="goPage('requests',this)"><div class="sb-icon">📋</div>My Leave Requests<span class="sb-badge" id="pendBadge"></span></button>
    <button class="sb-item" onclick="goPage('file',this)"><div class="sb-icon">📝</div>File Leave Request</button>
    <button class="sb-item" onclick="goPage('balances',this)"><div class="sb-icon">⚖️</div>Leave Balances</button>
    <div class="sb-label">Documents</div>
    <button class="sb-item" onclick="goPage('documents',this)"><div class="sb-icon">📁</div>All Documents</button>
    <button class="sb-item sb-sub" onclick="goPage('pds',this)"><div class="sb-icon">📋</div>PDS</button>
    <button class="sb-item sb-sub" onclick="goPage('saln',this)"><div class="sb-icon">📊</div>SALN</button>
    <button class="sb-item sb-sub" onclick="goPage('service_record',this)"><div class="sb-icon">📜</div>Service Record</button>
  </nav>
  <div class="sb-foot">
    <a href="../Landingpage/logout.php" class="sb-logout"><div class="sb-icon">🚪</div>Logout</a>
  </div>
</aside>
<div class="overlay-mob" id="mobOverlay" onclick="toggleSb()"></div>

<!-- MAIN -->
<div class="main">
  <div class="topbar">
    <button class="menu-toggle" onclick="toggleSb()">☰</button>
    <div class="tb-left">
      <div class="tb-title" id="tbTitle">Dashboard</div>
      <div class="tb-sub"   id="tbSub">Welcome back!</div>
    </div>
    <div class="tb-right">
      <div class="tb-chip" id="tbDate"></div>
      <div class="notif-wrap-ep" id="epNotifWrap">
        <button class="notif-btn-ep" onclick="toggleEpNotif()" title="Notifications">
          🔔<span class="notif-cnt-ep <?= $empNotifCount>0?'show':'' ?>" id="epNotifCnt"><?= $empNotifCount?:'' ?></span>
        </button>
        <div class="notif-panel-ep" id="epNotifPanel">
          <div class="np-hd">
            <div class="np-hd-title">🔔 Notifications <span style="font-weight:600;color:#64748b;font-size:.72rem;">(<?= $empNotifCount ?>)</span></div>
            <button class="np-mark" onclick="clearEpNotif()">Mark all read</button>
          </div>
          <div class="np-list" id="epNotifList">
            <?php if(empty($empNotifications)):?>
            <div class="np-empty"><div class="ne-icon">🎉</div><p>All caught up!</p></div>
            <?php else:foreach($empNotifications as $en):?>
            <div class="np-item unread" data-nid="<?=md5(($en['title']??'').($en['body']??'').($en['time']??''))?>" onclick="epNotifClick(this.getAttribute('data-nid'),'<?=htmlspecialchars($en['page'])?>', this)">
              <div class="np-ico" style="background:<?=htmlspecialchars($en['color'])?>22;"><?=$en['icon']?></div>
              <div class="np-body">
                <div class="np-title"><?=htmlspecialchars($en['title'])?></div>
                <div class="np-text"><?=htmlspecialchars($en['body'])?></div>
                <?php if($en['time']):?><div class="np-time"><?=empTimeAgo($en['time'])?></div><?php endif;?>
              </div>
            </div>
            <?php endforeach;endif;?>
          </div>
        </div>
      </div>
      <button class="tb-btn" onclick="cwToggle()" id="cwToggleBtn" style="position:relative;">💬<span id="cwTopbarBadge" style="position:absolute;top:-2px;right:-2px;width:14px;height:14px;background:#ef4444;border-radius:50%;font-size:.52rem;color:#fff;font-weight:800;display:none;align-items:center;justify-content:center;border:2px solid rgba(255,255,255,.3);"></span></button>
    </div>
  </div>

<?php if ($isSwitched): ?>
  <!-- ══ ADMIN SWITCH MODE BANNER ══ -->
  <div class="switch-banner">
    <div class="switch-banner-left">
      <div class="switch-banner-icon">🔄</div>
      <div class="switch-banner-text">
        <strong>You are viewing the Employee Portal as yourself</strong>
        <span>Signed in as <?= htmlspecialchars($origRoleLabel) ?> — <?= htmlspecialchars($displayName) ?>. Leaves filed and documents uploaded here are your personal employee records.</span>
      </div>
    </div>
    <a href="../Admin/switch_back_admin.php" class="switch-back-btn">
      🏛️ Return to Admin Panel
    </a>
  </div>
<?php endif; ?>

  <!-- ══ DASHBOARD ══ -->
  <div class="page active" id="page-dashboard">
    <div class="hero">
      <div class="hero-av" id="heroAv">?</div>
      <div class="hero-body">
        <h2 id="heroGreet">Loading…</h2>
        <p  id="heroSub"></p>
        <div class="hero-chips" id="heroChips"></div>
      </div>
      <div class="hero-date">
        <div class="hero-date-num" id="heroDay"></div>
        <div class="hero-date-str" id="heroMonth"></div>
      </div>
    </div>

    <div class="dash-grid">
      <div class="card">
        <div class="card-header">
          <div><div class="card-title">📋 My Recent Leave Requests</div><div class="card-sub">Latest applications</div></div>
          <button class="card-link" onclick="goPage('requests',document.querySelectorAll('.sb-item')[2])">View all →</button>
        </div>
        <table class="mini-tbl">
          <thead><tr><th>Type</th><th>Duration</th><th>Days</th><th>Status</th></tr></thead>
          <tbody id="recentReqTbody"><tr><td colspan="4" style="text-align:center;padding:24px;color:var(--g400);">Loading…</td></tr></tbody>
        </table>
      </div>

      <div style="display:flex;flex-direction:column;gap:16px;">
        <div class="card">
          <div class="card-header"><div class="card-title">⚡ Quick Actions</div></div>
          <div class="qa-grid">
            <button class="qa-btn" onclick="goPage('file',document.querySelectorAll('.sb-item')[3])" style="border:1.5px solid var(--g200);background:var(--g50);">
              <div class="qa-icon" style="background:#dbeafe;">📝</div><span class="qa-lbl">File Leave</span>
            </button>
            <button class="qa-btn" onclick="goPage('balances',document.querySelectorAll('.sb-item')[4])" style="border:1.5px solid var(--g200);background:var(--g50);">
              <div class="qa-icon" style="background:#dcfce7;">⚖️</div><span class="qa-lbl">Leave Balances</span>
            </button>
            <button class="qa-btn" onclick="goPage('profile',document.querySelectorAll('.sb-item')[1])" style="border:1.5px solid var(--g200);background:var(--g50);">
              <div class="qa-icon" style="background:#f3e8ff;">👤</div><span class="qa-lbl">My Profile</span>
            </button>
            <button class="qa-btn" onclick="goPage('documents',document.querySelectorAll('.sb-item')[5])" style="border:1.5px solid var(--g200);background:var(--g50);">
              <div class="qa-icon" style="background:#fef3c7;">📄</div><span class="qa-lbl">My Documents</span>
            </button>
          </div>
        </div>
        <div class="card">
          <div class="card-header"><div class="card-title">📢 Announcements</div></div>
          <div id="annList"><div style="padding:18px;color:var(--g400);font-size:.82rem;">Loading…</div></div>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ PROFILE ══ -->
  <div class="page" id="page-profile">
    <div class="profile-hero" style="position:relative;">
      <!-- Edit button — absolute top-right corner -->
      <button class="ph-edit" id="btnEditProfile" onclick="toggleEditProfile()" style="position:absolute;top:16px;right:20px;">✏️ Edit Info</button>
      <!-- Avatar with photo upload -->
      <div style="position:relative;flex-shrink:0;">
        <div class="ph-av" id="phAv" style="overflow:hidden;cursor:pointer;" onclick="document.getElementById('photoFileInput').click()" title="Click to change photo">?</div>
        <div style="position:absolute;bottom:0;right:0;width:22px;height:22px;background:#22c55e;border-radius:50%;border:2px solid #fff;display:flex;align-items:center;justify-content:center;font-size:.65rem;cursor:pointer;pointer-events:none;">📷</div>
        <input type="file" id="photoFileInput" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none;" onchange="uploadProfilePhoto(this)"/>
      </div>
      <div class="ph-info">
        <h2 id="phName">Loading…</h2>
        <p  id="phSub"></p>
        <div class="ph-chips" id="phChips"></div>
        <div style="font-size:.65rem;color:rgba(255,255,255,.45);margin-top:5px;">📷 Click avatar to change photo · Max 10MB</div>
      </div>
    </div>
    <div class="profile-grid">
      <div class="card" style="padding:20px 22px;" id="personalCard">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
          <div class="sec-title" style="margin-bottom:0;border-bottom:none;padding-bottom:0;">👤 Personal Information</div>
          <div id="profileEditActions" style="display:none;gap:8px;align-items:center;">
            <button onclick="cancelEditProfile()" style="padding:8px 18px;background:var(--g100);border:1.5px solid var(--g200);border-radius:var(--r-sm);font-family:'Nunito',sans-serif;font-size:.84rem;font-weight:700;color:var(--g600);cursor:pointer;transition:all .2s;" onmouseover="this.style.background='var(--g200)'" onmouseout="this.style.background='var(--g100)'">✕ Cancel</button>
            <button id="piSaveBtn" onclick="saveProfileChanges()" style="padding:8px 20px;background:linear-gradient(135deg,var(--primary),var(--primary-dk));color:#fff;border:none;border-radius:var(--r-sm);font-family:'Nunito',sans-serif;font-size:.84rem;font-weight:700;cursor:pointer;box-shadow:0 2px 10px rgba(37,99,235,.28);transition:all .2s;" onmouseover="this.style.transform='translateY(-1px)';this.style.boxShadow='0 4px 14px rgba(37,99,235,.38)'" onmouseout="this.style.transform='';this.style.boxShadow='0 2px 10px rgba(37,99,235,.28)'">💾 Save Changes</button>
          </div>
        </div>
        <div class="field-grid" id="profilePersonal"></div>
      </div>
      <div class="card" style="padding:20px 22px;">
        <div class="sec-title">💼 Employment Details</div>
        <div class="field-grid" id="profileEmployment"></div>
      </div>
    </div>
  </div>

  <!-- ══ MY LEAVE REQUESTS ══ -->
  <div class="page" id="page-requests">
    <div class="req-tabs">
      <button class="req-tab active" onclick="filterReqs('all',this)">All <span class="tc" id="tc-all">0</span></button>
      <button class="req-tab" onclick="filterReqs('pending',this)">⏳ Pending <span class="tc" id="tc-pending">0</span></button>
      <button class="req-tab" onclick="filterReqs('approved',this)">✅ Approved <span class="tc" id="tc-approved">0</span></button>
      <button class="req-tab" onclick="filterReqs('denied',this)">✗ Denied <span class="tc" id="tc-denied">0</span></button>
      <button class="req-tab" onclick="filterReqs('cancelled',this)">— Cancelled <span class="tc" id="tc-cancelled">0</span></button>
    </div>
    <div class="req-list" id="reqList"></div>
    <div class="empty-msg" id="reqEmpty" style="display:none;"><div class="ei">📋</div><p>No leave requests here.</p></div>
  </div>

  <!-- ══ FILE LEAVE ══ -->
  <div class="page" id="page-file">
    <div class="fl-wrap">

      <!-- LEFT: form -->
      <div>
        <div class="card">
          <div class="form-header">
            <h3>📝 File a Leave Request</h3>
            <p>Submit your application — HR will review within 1–2 working days</p>
          </div>
          <div style="padding:20px 22px;">
            <div class="fg"><label>Select Leave Type <span class="req">*</span></label></div>
            <div class="lt-grid" id="ltGrid"><div style="color:var(--g400);font-size:.82rem;padding:10px;">Loading leave types…</div></div>
            <div class="days-box">
              <div class="days-num" id="daysNum">0</div>
              <div><div class="days-lbl">Days Requested</div><div class="days-bal" id="daysBal">Select a leave type</div></div>
            </div>
            <div class="fg-row">
              <div class="fg"><label>Date From <span class="req">*</span></label><input type="date" id="flFrom" onchange="calcDays()"/></div>
              <div class="fg"><label>Date To <span class="req">*</span></label><input type="date"   id="flTo"   onchange="calcDays()"/></div>
            </div>

            <!-- 6B: Details of Leave (shown based on leave type) -->

            <!-- Vacation / Special Privilege -->
            <div class="detail-section" id="detail-vacation">
              <div class="detail-section-title">📋 Details of Leave — Location</div>
              <div class="detail-radio-group">
                <label class="detail-radio-row">
                  <input type="radio" name="vac_location" id="vac_within_ph" value="Within the Philippines" checked/>
                  Within the Philippines
                </label>
                <label class="detail-radio-row">
                  <input type="radio" name="vac_location" id="vac_abroad" value="Abroad"/>
                  Abroad
                </label>
              </div>
              <div class="detail-extra" id="vac_abroad_detail" style="display:none;">
                <label>Specify Destination</label>
                <input type="text" id="vac_abroad_spec" placeholder="e.g. Japan, USA…"/>
              </div>
            </div>

            <!-- Sick Leave -->
            <div class="detail-section" id="detail-sick">
              <div class="detail-section-title">📋 Details of Leave — Sick Leave</div>
              <div class="detail-radio-group">
                <label class="detail-radio-row">
                  <input type="radio" name="sick_type" id="sick_hospital" value="In Hospital" checked/>
                  In Hospital
                </label>
                <label class="detail-radio-row">
                  <input type="radio" name="sick_type" id="sick_outpatient" value="Out Patient"/>
                  Out Patient
                </label>
              </div>
              <div class="detail-extra" style="margin-top:10px;">
                <label>Specify Illness</label>
                <input type="text" id="sick_illness" placeholder="e.g. Fever, Hypertension…"/>
              </div>
            </div>

            <!-- Special Leave Benefits for Women -->
            <div class="detail-section" id="detail-women">
              <div class="detail-section-title">📋 Details of Leave — Special Leave Benefits for Women</div>
              <div class="detail-extra">
                <label>Specify Illness / Gynecological Disorder</label>
                <input type="text" id="women_illness" placeholder="e.g. Myoma, Endometriosis…"/>
              </div>
            </div>

            <!-- Study Leave -->
            <div class="detail-section" id="detail-study">
              <div class="detail-section-title">📋 Details of Leave — Study Leave</div>
              <div class="detail-radio-group">
                <label class="detail-radio-row">
                  <input type="radio" name="study_purpose" id="study_masters" value="Completion of Master's Degree" checked/>
                  Completion of Master's Degree
                </label>
                <label class="detail-radio-row">
                  <input type="radio" name="study_purpose" id="study_bar" value="BAR/Board Examination Review"/>
                  BAR/Board Examination Review
                </label>
              </div>
            </div>

            <!-- Monetization / Terminal (Other purpose) -->
            <div class="detail-section" id="detail-other">
              <div class="detail-section-title">📋 Other Purpose</div>
              <div class="detail-radio-group">
                <label class="detail-radio-row">
                  <input type="radio" name="other_purpose" id="other_monetization" value="Monetization of Leave Credits" checked/>
                  Monetization of Leave Credits
                </label>
                <label class="detail-radio-row">
                  <input type="radio" name="other_purpose" id="other_terminal" value="Terminal Leave"/>
                  Terminal Leave
                </label>
              </div>
            </div>

            <!-- Others Leave Type -->
            <div class="detail-section" id="detail-others">
              <div class="detail-section-title">📋 Details of Leave — Specify Type</div>
              <div class="detail-extra">
                <label>
                  <input type="checkbox" id="others_leave_confirm" disabled />
                  <span>What kind of leave are you filing? <span class="req">*</span></span>
                </label>
                <input type="text" id="others_leave_spec" placeholder="e.g. Personal, Family emergency, Cultural activity…"/>
              </div>
            </div>

            <div class="fg"><label>Reason / Remarks <span class="req">*</span></label><textarea id="flReason" placeholder="Briefly describe why you are filing this leave…"></textarea></div>
            <button class="submit-btn" onclick="submitLeave()">📤 Submit Leave Request</button>
          </div>
        </div>
      </div>

      <!-- RIGHT: info panel -->
      <div class="fl-right">

        <!-- Filing reminders -->
        <div class="card">
          <div class="card-header" style="padding-bottom:8px;">
            <div class="card-title">💡 Filing Reminders</div>
          </div>
          <ul class="tips">
            <li><span>📅</span>File planned leaves at least <strong>3 working days</strong> in advance.</li>
            <li><span>🏥</span>Sick &amp; Emergency leaves may be filed on the same day.</li>
            <li><span>✅</span>Approval is required from your Dept. Head <em>and</em> HR Admin.</li>
            <li><span>⚖️</span>Check your balance above to avoid denial due to insufficient credits.</li>
            <li><span>📎</span>Supporting documents may be required for medical leaves.</li>
            <li><span>🚫</span>Approved leaves cannot be cancelled — contact HR directly.</li>
          </ul>
        </div>

      </div><!-- /fl-right -->
    </div>
  </div>

  <!-- ══ LEAVE BALANCES ══ -->
  <div class="page" id="page-balances">
    <div class="balances-grid" id="balancesGrid"><div style="color:var(--g400);padding:20px;">Loading…</div></div>
  </div>

  <!-- ══ MY DOCUMENTS ══ -->
  <div class="page" id="page-documents">
    <!-- Stats -->
    <div class="doc-stat-row">
      <div class="doc-stat" style="animation-delay:.05s;">
        <div class="doc-stat-icon" style="background:#dbeafe;">📄</div>
        <div><div class="doc-stat-val" id="ds-total">—</div><div class="doc-stat-lbl">Total Documents</div></div>
      </div>
      <div class="doc-stat" style="animation-delay:.10s;">
        <div class="doc-stat-icon" style="background:#dcfce7;">🗂️</div>
        <div><div class="doc-stat-val" id="ds-types">—</div><div class="doc-stat-lbl">Document Types</div></div>
      </div>
      <div class="doc-stat" style="animation-delay:.15s;">
        <div class="doc-stat-icon" style="background:#fef3c7;">💾</div>
        <div><div class="doc-stat-val" id="ds-size">—</div><div class="doc-stat-lbl">Storage Used</div></div>
      </div>
    </div>

    <!-- Panel -->
    <div class="card">
      <div class="card-header">
        <div><div class="card-title">📁 My Documents</div><div class="card-sub" id="docPanelSub">Your personal HR files</div></div>
        <button class="doc-upload-btn" onclick="openDocUpload()">📤 Upload Document</button>
      </div>
      <div class="doc-toolbar">
        <div class="doc-search">
          <span style="color:var(--g400);">🔍</span>
          <input type="text" id="docSearch" placeholder="Search documents…" oninput="debounceDoc()"/>
        </div>
        <select class="doc-filter" id="docTypeFilter" onchange="loadDocuments()">
          <option value="">All Types</option>
          <option value="Appointment">Appointment</option>
          <option value="Contract">Contract</option>
          <option value="PDS">PDS</option>
          <option value="SALN">SALN</option>
          <option value="Certificate">Certificate</option>
          <option value="Medical Certificate">Medical Certificate</option>
          <option value="Leave Form">Leave Form</option>
          <option value="Payslip">Payslip</option>
          <option value="ID">Government ID</option>
          <option value="Other">Other</option>
        </select>
        <button class="doc-reset-btn" onclick="clearDocFilters()" title="Clear all filters and search">🔁 Reset</button>
      </div>
      <div class="doc-grid" id="docGrid">
        <div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--g400);font-size:.875rem;">Loading…</div>
      </div>
    </div>
  </div>


  <!-- ══ PDS PAGE ══ -->
  <div class="page" id="page-pds">
    <div class="form-page">
      <div id="pds-link-banner" style="display:none;margin-bottom:12px;padding:12px 16px;border-radius:8px;font-size:.82rem;font-weight:700;"></div>
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;flex-wrap:wrap;gap:10px;">
        <div>
          <div style="font-size:1.1rem;font-weight:800;color:var(--g800);">Personal Data Sheet</div>
          <div style="font-size:.74rem;color:var(--g400);margin-top:2px;">CS Form No. 212 · Revised 2025 · Pages 1–4</div>
        </div>
        <div style="display:flex;gap:8px;">
          <button class="save-form-btn" style="background:linear-gradient(135deg,#16a34a,#15803d);" onclick="printPDS()">🖨️ Print PDS</button>
          <button class="save-form-btn" onclick="savePDS()">💾 Save PDS</button>
        </div>
      </div>
      <div style="background:#fffbeb;border:1.5px solid #fde68a;border-radius:var(--r-sm);padding:10px 14px;font-size:.75rem;color:#92400e;margin-bottom:14px;line-height:1.5;">
        ⚠️ <strong>WARNING:</strong> Any misrepresentation made in the Personal Data Sheet shall cause the filing of administrative/criminal case/s against the person concerned. <strong>DO NOT ABBREVIATE.</strong>
      </div>
      <div class="form-status" id="pdsStatus"></div>

      <!-- ── PAGE 1 ── -->
      <!-- I. Personal Information -->
      <div class="form-section-hd">I. Personal Information</div>
      <div class="form-section-body">
        <div class="fg2">
          <div class="fg"><label>1. Surname</label><input type="text" id="pds_last_name" placeholder="e.g. DELA CRUZ"/></div>
          <div class="fg"><label>2. First Name</label><input type="text" id="pds_first_name" placeholder="e.g. JUAN"/></div>
          <div class="fg"><label>Name Extension (Jr., Sr., III)</label><input type="text" id="pds_suffix" placeholder="e.g. JR."/></div>
          <div class="fg"><label>Middle Name</label><input type="text" id="pds_middle_name" placeholder="e.g. SANTOS"/></div>
        </div>
        <div class="fg2" style="margin-top:10px;">
          <div class="fg"><label>3. Date of Birth (mm/dd/yyyy)</label><input type="date" id="pds_dob"/></div>
          <div class="fg"><label>4. Place of Birth</label><input type="text" id="pds_pob"/></div>
          <div class="fg"><label>5. Sex at Birth</label>
            <select id="pds_sex"><option value="">— Select —</option><option>Male</option><option>Female</option></select>
          </div>
          <div class="fg"><label>6. Civil Status</label>
            <select id="pds_civil_status"><option value="">— Select —</option><option>Single</option><option>Married</option><option>Widow/er</option><option>Separated</option><option>Solo Parent</option><option>Others</option></select>
          </div>
          <div class="fg"><label>7. Height (m)</label><input type="text" id="pds_height" placeholder="e.g. 1.65"/></div>
          <div class="fg"><label>8. Weight (kg)</label><input type="text" id="pds_weight" placeholder="e.g. 60"/></div>
          <div class="fg"><label>9. Blood Type</label>
            <select id="pds_blood_type"><option value="">— Select —</option><option>A+</option><option>A-</option><option>B+</option><option>B-</option><option>AB+</option><option>AB-</option><option>O+</option><option>O-</option></select>
          </div>
          <div class="fg"><label>10. UMID ID No.</label><input type="text" id="pds_umid"/></div>
          <div class="fg"><label>11. PAG-IBIG ID No.</label><input type="text" id="pds_pagibig"/></div>
          <div class="fg"><label>12. PhilHealth No.</label><input type="text" id="pds_philhealth"/></div>
          <div class="fg"><label>13. PhilSys Number (PSN)</label><input type="text" id="pds_psn"/></div>
          <div class="fg"><label>14. TIN No.</label><input type="text" id="pds_tin"/></div>
          <div class="fg"><label>15. Agency Employee No.</label><input type="text" id="pds_agency_no"/></div>
        </div>
        <div style="margin-top:10px;">
          <div style="font-size:.72rem;font-weight:800;color:var(--g600);text-transform:uppercase;margin-bottom:6px;">16. Citizenship</div>
          <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">
            <label style="display:flex;align-items:center;gap:6px;font-size:.84rem;font-weight:600;cursor:pointer;">
              <input type="radio" name="pds_citizenship_type" id="pds_cit_filipino" value="Filipino" onchange="toggleDualCit()" checked/> Filipino
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:.84rem;font-weight:600;cursor:pointer;">
              <input type="radio" name="pds_citizenship_type" id="pds_cit_dual" value="Dual Citizenship" onchange="toggleDualCit()"/> Dual Citizenship
            </label>
          </div>
          <div id="dual_cit_detail" style="display:none;">
            <div class="fg2">
              <div class="fg"><label>By: </label>
                <select id="pds_dual_by"><option value="">— Select —</option><option>Birth</option><option>Naturalization</option></select>
              </div>
              <div class="fg"><label>Please indicate country</label><input type="text" id="pds_dual_country"/></div>
            </div>
          </div>
        </div>
        <div style="margin-top:10px;">
          <div style="font-size:.72rem;font-weight:800;color:var(--g600);text-transform:uppercase;margin-bottom:6px;">17. Residential Address</div>
          <div class="fg2">
            <div class="fg"><label>House/Block/Lot No.</label><input type="text" id="pds_res_housenum"/></div>
            <div class="fg"><label>Street</label><input type="text" id="pds_res_street"/></div>
            <div class="fg"><label>Subdivision/Village</label><input type="text" id="pds_res_subdivision"/></div>
            <div class="fg"><label>Barangay</label><input type="text" id="pds_res_barangay"/></div>
            <div class="fg"><label>City/Municipality</label><input type="text" id="pds_res_city"/></div>
            <div class="fg"><label>Province</label><input type="text" id="pds_res_province"/></div>
            <div class="fg"><label>ZIP Code</label><input type="text" id="pds_res_zip"/></div>
          </div>
        </div>
        <div style="margin-top:10px;">
          <div style="font-size:.72rem;font-weight:800;color:var(--g600);text-transform:uppercase;margin-bottom:6px;">18. Permanent Address</div>
          <div style="margin-bottom:8px;">
            <label style="display:flex;align-items:center;gap:6px;font-size:.80rem;font-weight:600;cursor:pointer;">
              <input type="checkbox" id="pds_perm_same" onchange="togglePermSame()"/> Same as Residential Address
            </label>
          </div>
          <div id="perm_addr_fields">
            <div class="fg2">
              <div class="fg"><label>House/Block/Lot No.</label><input type="text" id="pds_perm_housenum"/></div>
              <div class="fg"><label>Street</label><input type="text" id="pds_perm_street"/></div>
              <div class="fg"><label>Subdivision/Village</label><input type="text" id="pds_perm_subdivision"/></div>
              <div class="fg"><label>Barangay</label><input type="text" id="pds_perm_barangay"/></div>
              <div class="fg"><label>City/Municipality</label><input type="text" id="pds_perm_city"/></div>
              <div class="fg"><label>Province</label><input type="text" id="pds_perm_province"/></div>
              <div class="fg"><label>ZIP Code</label><input type="text" id="pds_perm_zip"/></div>
            </div>
          </div>
        </div>
        <div class="fg2" style="margin-top:10px;">
          <div class="fg"><label>19. Telephone No.</label><input type="text" id="pds_tel"/></div>
          <div class="fg"><label>20. Mobile No.</label><input type="text" id="pds_mobile"/></div>
          <div class="fg fg-full"><label>21. E-Mail Address (if any)</label><input type="email" id="pds_email"/></div>
        </div>
      </div>

      <!-- II. Family Background -->
      <div class="form-section-hd">II. Family Background</div>
      <div class="form-section-body">
        <div style="font-size:.76rem;font-weight:800;color:var(--primary);margin-bottom:8px;">22. SPOUSE</div>
        <div class="fg2">
          <div class="fg"><label>Surname</label><input type="text" id="pds_sp_surname"/></div>
          <div class="fg"><label>First Name</label><input type="text" id="pds_sp_firstname"/></div>
          <div class="fg"><label>Name Extension (Jr., Sr.)</label><input type="text" id="pds_sp_ext"/></div>
          <div class="fg"><label>Middle Name</label><input type="text" id="pds_sp_middle"/></div>
          <div class="fg"><label>Occupation</label><input type="text" id="pds_sp_occupation"/></div>
          <div class="fg"><label>Employer/Business Name</label><input type="text" id="pds_sp_employer"/></div>
          <div class="fg fg-full"><label>Business Address</label><input type="text" id="pds_sp_biz_addr"/></div>
          <div class="fg"><label>Telephone No.</label><input type="text" id="pds_sp_tel"/></div>
        </div>
        <div style="font-size:.76rem;font-weight:800;color:var(--primary);margin:14px 0 8px;">23. NAME OF CHILDREN (Write full name and list all)</div>
        <div id="pds_children_list"></div>
        <button class="add-row-btn" onclick="addChild(null, false)">+ Add Child</button>
        <div style="font-size:.76rem;font-weight:800;color:var(--primary);margin:14px 0 8px;">24. FATHER</div>
        <div class="fg3">
          <div class="fg"><label>Surname</label><input type="text" id="pds_fa_surname"/></div>
          <div class="fg"><label>First Name</label><input type="text" id="pds_fa_firstname"/></div>
          <div class="fg"><label>Name Extension</label><input type="text" id="pds_fa_ext"/></div>
          <div class="fg"><label>Middle Name</label><input type="text" id="pds_fa_middle"/></div>
        </div>
        <div style="font-size:.76rem;font-weight:800;color:var(--primary);margin:14px 0 8px;">25. MOTHER'S MAIDEN NAME</div>
        <div class="fg3">
          <div class="fg"><label>Surname</label><input type="text" id="pds_mo_surname"/></div>
          <div class="fg"><label>First Name</label><input type="text" id="pds_mo_firstname"/></div>
          <div class="fg"><label>Middle Name</label><input type="text" id="pds_mo_middle"/></div>
        </div>
      </div>

      <!-- III. Educational Background -->
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <div class="form-section-hd">III. Educational Background (26)</div>
        <button type="button" class="add-row-btn" onclick="if(confirm('Are you sure you want to reset all Education entries? This cannot be undone.')) resetEducation()" style="background:#ef4444;margin:0;">↻ Reset</button>
      </div>
      <div class="form-section-body" style="padding:10px;">
        <p style="font-size:.75rem;color:var(--g600);margin-bottom:10px;">Write in full. Do not abbreviate.</p>
        <div style="overflow-x:auto;">
          <table id="pds_edu_table" style="width:100%;border-collapse:collapse;font-size:.76rem;min-width:700px;">
            <thead>
              <tr style="background:var(--g100);">
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:left;width:14%;">LEVEL</th>
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:left;width:22%;">NAME OF SCHOOL<br><span style="font-weight:400;">(Write in full)</span></th>
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:left;width:18%;">BASIC EDUCATION / DEGREE / COURSE<br><span style="font-weight:400;">(Write in full)</span></th>
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:center;width:8%;">PERIOD: FROM</th>
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:center;width:8%;">PERIOD: TO</th>
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:center;width:10%;">HIGHEST LEVEL / UNITS EARNED<br><span style="font-weight:400;">(if not graduated)</span></th>
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:center;width:8%;">YEAR GRADUATED</th>
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:left;width:12%;">SCHOLARSHIP / ACADEMIC HONORS RECEIVED</th>
                <th style="border:1px solid var(--g200);padding:6px 8px;width:28px;"></th>
              </tr>
            </thead>
            <tbody id="pds_edu_list">
              <tr data-level="Elementary">
                <td style="border:1px solid var(--g200);padding:6px 8px;font-size:.76rem;font-weight:700;color:var(--g700);">Elementary</td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="school" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;" placeholder=""/></td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="degree" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;" placeholder=""/></td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="from" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;text-align:center;" placeholder="YYYY"/></td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="to" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;text-align:center;" placeholder="YYYY"/></td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="units" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;text-align:center;" placeholder=""/></td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="year_grad" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;text-align:center;" placeholder="YYYY"/></td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="honors" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;" placeholder=""/></td>
              <td style="border:1px solid var(--g200);padding:4px;width:28px;"></td>
              </tr>
              <tr data-level="Secondary">
                <td style="border:1px solid var(--g200);padding:6px 8px;font-size:.76rem;font-weight:700;color:var(--g700);">Secondary</td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="school" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;" placeholder=""/></td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="degree" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;" placeholder=""/></td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="from" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;text-align:center;" placeholder="YYYY"/></td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="to" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;text-align:center;" placeholder="YYYY"/></td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="units" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;text-align:center;" placeholder=""/></td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="year_grad" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;text-align:center;" placeholder="YYYY"/></td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="honors" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;" placeholder=""/></td>
              <td style="border:1px solid var(--g200);padding:4px;width:28px;"></td>
              </tr>
              <tr data-level="Vocational/Trade Course">
                <td style="border:1px solid var(--g200);padding:6px 8px;font-size:.76rem;font-weight:700;color:var(--g700);">Vocational /<br>Trade Course</td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="school" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;" placeholder=""/></td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="degree" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;" placeholder=""/></td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="from" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;text-align:center;" placeholder="YYYY"/></td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="to" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;text-align:center;" placeholder="YYYY"/></td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="units" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;text-align:center;" placeholder=""/></td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="year_grad" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;text-align:center;" placeholder="YYYY"/></td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="honors" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;" placeholder=""/></td>
              <td style="border:1px solid var(--g200);padding:4px;width:28px;"></td>
              </tr>
              <tr data-level="College">
                <td style="border:1px solid var(--g200);padding:6px 8px;font-size:.76rem;font-weight:700;color:var(--g700);">College</td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="school" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;" placeholder=""/></td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="degree" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;" placeholder=""/></td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="from" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;text-align:center;" placeholder="YYYY"/></td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="to" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;text-align:center;" placeholder="YYYY"/></td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="units" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;text-align:center;" placeholder=""/></td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="year_grad" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;text-align:center;" placeholder="YYYY"/></td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="honors" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;" placeholder=""/></td>
              <td style="border:1px solid var(--g200);padding:4px;width:28px;"></td>
              </tr>
              <tr data-level="Graduate Studies">
                <td style="border:1px solid var(--g200);padding:6px 8px;font-size:.76rem;font-weight:700;color:var(--g700);">Graduate Studies</td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="school" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;" placeholder=""/></td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="degree" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;" placeholder=""/></td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="from" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;text-align:center;" placeholder="YYYY"/></td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="to" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;text-align:center;" placeholder="YYYY"/></td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="units" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;text-align:center;" placeholder=""/></td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="year_grad" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;text-align:center;" placeholder="YYYY"/></td>
                <td style="border:1px solid var(--g200);padding:4px;"><input type="text" data-field="honors" style="width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;" placeholder=""/></td>
              <td style="border:1px solid var(--g200);padding:4px;width:28px;"></td>
              </tr>
            </tbody>
          </table>
        </div>
        <button class="add-row-btn" onclick="addGradStudy(null, false)">+ Add Graduate Studies</button>
      </div>

      <!-- ── PAGE 2 ── -->
      <!-- IV. Civil Service Eligibility -->
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <div class="form-section-hd">IV. Civil Service Eligibility (27)</div>
        <button type="button" class="add-row-btn" onclick="if(confirm('Are you sure you want to reset all Civil Service Eligibility entries? This cannot be undone.')) resetSection('pds_cs_list')" style="background:#ef4444;margin:0;">↻ Reset</button>
      </div>
      <div class="form-section-body" style="padding:10px;">
        <p style="font-size:.75rem;color:var(--g600);margin-bottom:10px;">CES/CSEE/Career Service/RA 1080 (Board/Bar)/Under Special Laws/Category II/IV Eligibility and Eligibilities for Uniformed Personnel</p>
        <div style="overflow-x:auto;">
          <table style="width:100%;border-collapse:collapse;font-size:.76rem;min-width:650px;">
            <thead>
              <tr style="background:var(--g100);">
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:left;width:22%;">CAREER SERVICE / ELIGIBILITY</th>
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:left;width:12%;">RATING (IF APPLICABLE)</th>
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:left;width:16%;">DATE OF EXAM / CONFERMENT</th>
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:left;width:20%;">PLACE OF EXAM / CONFERMENT</th>
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:left;width:14%;">LICENSE NO.</th>
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:left;width:14%;">LICENSE VALID UNTIL</th>
                <th style="border:1px solid var(--g200);padding:6px 8px;width:32px;"></th>
              </tr>
            </thead>
            <tbody id="pds_cs_list"></tbody>
          </table>
        </div>
        <button class="add-row-btn" onclick="addCS(null, false)">+ Add Eligibility</button>
      </div>

      <!-- V. Work Experience -->
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <div class="form-section-hd">V. Work Experience (28)</div>
        <button type="button" class="add-row-btn" onclick="if(confirm('Are you sure you want to reset all Work Experience entries? This cannot be undone.')) resetSection('pds_we_list')" style="background:#ef4444;margin:0;">↻ Reset</button>
      </div>
      <div class="form-section-body" style="padding:10px;">
        <p style="font-size:.75rem;color:var(--g600);margin-bottom:10px;">Include private employment. Start from your most recent work. Description of duties should be indicated in the attached Work Experience Sheet.</p>
        <div style="overflow-x:auto;">
          <table style="width:100%;border-collapse:collapse;font-size:.76rem;min-width:750px;">
            <thead>
              <tr style="background:var(--g100);">
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:left;width:11%;">INCLUSIVE: FROM</th>
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:left;width:11%;">INCLUSIVE: TO</th>
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:left;width:16%;">POSITION TITLE<br><span style="font-weight:400;">(Write in full)</span></th>
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:left;width:18%;">DEPARTMENT / AGENCY / COMPANY<br><span style="font-weight:400;">(Write in full)</span></th>
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:left;width:9%;">MONTHLY SALARY</th>
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:left;width:9%;">SALARY / JOB / PAY GRADE</th>
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:left;width:13%;">STATUS OF APPOINTMENT</th>
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:center;width:7%;">GOV'T SERVICE (Y/N)</th>
                <th style="border:1px solid var(--g200);padding:6px 8px;width:32px;"></th>
              </tr>
            </thead>
            <tbody id="pds_we_list"></tbody>
          </table>
        </div>
        <button class="add-row-btn" onclick="addWE(null, false)">+ Add Work Experience</button>
      </div>

      <!-- ── PAGE 3 ── -->
      <!-- VI. Voluntary Work -->
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <div class="form-section-hd">VI. Voluntary Work / Involvement in Civic / Non-Government / People / Voluntary Organization/s (29)</div>
        <button type="button" class="add-row-btn" onclick="if(confirm('Are you sure you want to reset all Voluntary Work entries? This cannot be undone.')) resetSection('pds_vw_list')" style="background:#ef4444;margin:0;">↻ Reset</button>
      </div>
      <div class="form-section-body" style="padding:10px;">
        <div style="overflow-x:auto;">
          <table style="width:100%;border-collapse:collapse;font-size:.76rem;min-width:600px;">
            <thead>
              <tr style="background:var(--g100);">
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:left;width:30%;">NAME & ADDRESS OF ORGANIZATION<br><span style="font-weight:400;">(Write in full)</span></th>
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:center;width:12%;">INCLUSIVE: FROM</th>
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:center;width:12%;">INCLUSIVE: TO</th>
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:center;width:10%;">NO. OF HOURS</th>
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:left;width:34%;">POSITION / NATURE OF WORK</th>
                <th style="border:1px solid var(--g200);padding:6px 8px;width:32px;"></th>
              </tr>
            </thead>
            <tbody id="pds_vw_list"></tbody>
          </table>
        </div>
        <button class="add-row-btn" onclick="addVW(null, false)">+ Add Voluntary Work</button>
      </div>

      <!-- VII. L&D / Trainings -->
      <!-- VII. Learning and Development -->
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <div class="form-section-hd">VII. Learning and Development (L&D) Interventions / Training Programs Attended (30)</div>
        <button type="button" class="add-row-btn" onclick="if(confirm('Are you sure you want to reset all Training Program entries? This cannot be undone.')) resetSection('pds_tr_list')" style="background:#ef4444;margin:0;">↻ Reset</button>
      </div>
      <div class="form-section-body" style="padding:10px;">
        <div style="overflow-x:auto;">
          <table style="width:100%;border-collapse:collapse;font-size:.76rem;min-width:650px;">
            <thead>
              <tr style="background:var(--g100);">
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:left;width:28%;">TITLE OF L&D INTERVENTIONS / TRAINING PROGRAMS<br><span style="font-weight:400;">(Write in full)</span></th>
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:center;width:11%;">INCLUSIVE: FROM</th>
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:center;width:11%;">INCLUSIVE: TO</th>
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:center;width:9%;">NO. OF HOURS</th>
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:left;width:13%;">TYPE OF L&D<br><span style="font-weight:400;">(Managerial/ Supervisory/Technical/etc)</span></th>
                <th style="border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:700;text-align:left;width:26%;">CONDUCTED / SPONSORED BY<br><span style="font-weight:400;">(Write in full)</span></th>
                <th style="border:1px solid var(--g200);padding:6px 8px;width:32px;"></th>
              </tr>
            </thead>
            <tbody id="pds_tr_list"></tbody>
          </table>
        </div>
        <button class="add-row-btn" onclick="addTR(null, false)">+ Add Training</button>
      </div>

      <!-- VIII. Other Information -->
      <div class="form-section-hd">VIII. Other Information</div>
      <div class="form-section-body">
        <div class="fg2">

          <!-- 31. Special Skills and Hobbies -->
          <div class="fg fg-full">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
              <label>31. Special Skills and Hobbies</label>
            <button type="button" class="add-row-btn" onclick="if(confirm('Are you sure you want to reset all Skills entries? This cannot be undone.')) oiResetList('pds_skills_list', 'pds_skills_tpl')" style="background:#ef4444;margin:0;">↻ Reset</button>
            </div>
            <div id="pds_skills_list" class="oi-list"></div>
            <template id="pds_skills_tpl">
              <div class="oi-row">
                <input type="text" class="oi-inp" placeholder="e.g. Computer Programming, Public Speaking…"/>
                <button type="button" class="oi-del" onclick="oiDelRow(this)" title="Remove">✕</button>
              </div>
            </template>
            <button type="button" class="add-row-btn" onclick="oiAddRow('pds_skills_list','pds_skills_tpl', null, false)">+ Add Skill</button>
          </div>

          <!-- 32. Non-Academic Distinctions -->
          <div class="fg fg-full">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
              <label>32. Non-Academic Distinctions / Recognition (Write in full)</label>
              <button type="button" class="add-row-btn" onclick="if(confirm('Are you sure you want to reset all Distinctions entries? This cannot be undone.')) oiResetList('pds_distinctions_list', 'pds_distinctions_tpl')" style="background:#ef4444;margin:0;">↻ Reset</button>
            </div>
            <div id="pds_distinctions_list" class="oi-list"></div>
            <template id="pds_distinctions_tpl">
              <div class="oi-row">
                <input type="text" class="oi-inp" placeholder="e.g. Best Employee Award 2024…"/>
                <button type="button" class="oi-del" onclick="oiDelRow(this)" title="Remove">✕</button>
              </div>
            </template>
            <button type="button" class="add-row-btn" onclick="oiAddRow('pds_distinctions_list','pds_distinctions_tpl', null, false)">+ Add Distinction</button>
          </div>

          <!-- 33. Membership -->
          <div class="fg fg-full">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
              <label>33. Membership in Association/Organization (Write in full)</label>
              <button type="button" class="add-row-btn" onclick="if(confirm('Are you sure you want to reset all Membership entries? This cannot be undone.')) oiResetList('pds_memberships_list', 'pds_memberships_tpl')" style="background:#ef4444;margin:0;">↻ Reset</button>
            </div>
            <div id="pds_memberships_list" class="oi-list"></div>
            <template id="pds_memberships_tpl">
              <div class="oi-row">
                <input type="text" class="oi-inp" placeholder="e.g. IPAP Member, GAWAD Kalinga…"/>
                <button type="button" class="oi-del" onclick="oiDelRow(this)" title="Remove">✕</button>
              </div>
            </template>
            <button type="button" class="add-row-btn" onclick="oiAddRow('pds_memberships_list','pds_memberships_tpl', null, false)">+ Add Membership</button>
          </div>

        </div>
      </div>

      <!-- VIII. References -->
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <div class="form-section-hd">VIII. References (General Professional/Non-Character References)</div>
        <button type="button" class="add-row-btn" onclick="if(confirm('Are you sure you want to reset all References? This cannot be undone.')) resetReferences()" style="background:#ef4444;margin:0;">↻ Reset</button>
      </div>
      <div class="form-section-body">
        <div id="pds_ref_list"></div>
        <button class="add-row-btn" onclick="addRef(null, false)">+ Add Reference</button>
        <p style="font-size:.72rem;color:var(--g400);margin-top:8px;">Provide at least 7 professional references with their Office/Residential Address and Contact No./Email.</p>
      </div>

      <!-- ── PAGE 4 ── -->
      <!-- Questions 34–40 -->
      <div class="form-section-hd">Page 4 — Additional Questions (34–40)</div>
      <div class="form-section-body">

        <div style="margin-bottom:14px;">
          <div style="font-size:.80rem;font-weight:700;color:var(--g800);margin-bottom:6px;">34. Are you related by consanguinity or affinity to the appointing or recommending authority, or to the chief of bureau/office or to the person who has immediate supervision over you?</div>
          <div style="display:flex;gap:20px;margin-bottom:6px;">
            <label style="display:flex;align-items:center;gap:6px;font-size:.84rem;cursor:pointer;"><input type="radio" name="q34a" value="Yes" id="q34a_yes"/> a. within the third degree? &nbsp;<strong>YES</strong></label>
            <label style="display:flex;align-items:center;gap:6px;font-size:.84rem;cursor:pointer;"><input type="radio" name="q34a" value="No" id="q34a_no" checked/> <strong>NO</strong></label>
          </div>
          <div style="display:flex;gap:20px;margin-bottom:6px;">
            <label style="display:flex;align-items:center;gap:6px;font-size:.84rem;cursor:pointer;"><input type="radio" name="q34b" value="Yes" id="q34b_yes"/> b. within the fourth degree (for LGU career employees)? &nbsp;<strong>YES</strong></label>
            <label style="display:flex;align-items:center;gap:6px;font-size:.84rem;cursor:pointer;"><input type="radio" name="q34b" value="No" id="q34b_no" checked/> <strong>NO</strong></label>
          </div>
          <div class="fg" style="margin-top:6px;"><label>If YES, give details:</label><input type="text" id="pds_q34_details"/></div>
        </div>

        <div style="margin-bottom:14px;">
          <div style="font-size:.80rem;font-weight:700;color:var(--g800);margin-bottom:6px;">35. Administrative/Criminal Charges</div>
          <div style="display:flex;gap:20px;margin-bottom:4px;">
            <label style="font-size:.82rem;display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="radio" name="q35a" value="Yes" id="q35a_yes"/> a. Ever found guilty of any administrative offense? &nbsp;<strong>YES</strong></label>
            <label style="font-size:.82rem;display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="radio" name="q35a" value="No" id="q35a_no" checked/> <strong>NO</strong></label>
          </div>
          <div class="fg" style="margin-bottom:8px;"><label>If YES, give details:</label><input type="text" id="pds_q35a_details"/></div>
          <div style="display:flex;gap:20px;margin-bottom:4px;">
            <label style="font-size:.82rem;display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="radio" name="q35b" value="Yes" id="q35b_yes"/> b. Been criminally charged before any court? &nbsp;<strong>YES</strong></label>
            <label style="font-size:.82rem;display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="radio" name="q35b" value="No" id="q35b_no" checked/> <strong>NO</strong></label>
          </div>
          <div class="fg2">
            <div class="fg"><label>If YES, give details:</label><input type="text" id="pds_q35b_details"/></div>
            <div class="fg"><label>Date Filed:</label><input type="date" id="pds_q35b_date"/></div>
            <div class="fg fg-full"><label>Status of Case/s:</label><input type="text" id="pds_q35b_status"/></div>
          </div>
        </div>

        <div style="margin-bottom:14px;">
          <div style="display:flex;gap:20px;margin-bottom:4px;">
            <label style="font-size:.82rem;display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="radio" name="q36" value="Yes" id="q36_yes"/> 36. Ever been convicted of any crime or violation? &nbsp;<strong>YES</strong></label>
            <label style="font-size:.82rem;display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="radio" name="q36" value="No" id="q36_no" checked/> <strong>NO</strong></label>
          </div>
          <div class="fg"><label>If YES, give details:</label><input type="text" id="pds_q36_details"/></div>
        </div>

        <div style="margin-bottom:14px;">
          <div style="display:flex;gap:20px;margin-bottom:4px;">
            <label style="font-size:.82rem;display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="radio" name="q37" value="Yes" id="q37_yes"/> 37. Ever been separated from service (resignation/retirement/dismissal/end of term/etc.)? &nbsp;<strong>YES</strong></label>
            <label style="font-size:.82rem;display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="radio" name="q37" value="No" id="q37_no" checked/> <strong>NO</strong></label>
          </div>
          <div class="fg"><label>If YES, give details:</label><input type="text" id="pds_q37_details"/></div>
        </div>

        <div style="margin-bottom:14px;">
          <div style="font-size:.80rem;font-weight:700;color:var(--g800);margin-bottom:6px;">38. Election Candidacy</div>
          <div style="display:flex;gap:20px;margin-bottom:4px;">
            <label style="font-size:.82rem;display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="radio" name="q38a" value="Yes" id="q38a_yes"/> a. Been a candidate in a national or local election held within the last year? &nbsp;<strong>YES</strong></label>
            <label style="font-size:.82rem;display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="radio" name="q38a" value="No" id="q38a_no" checked/> <strong>NO</strong></label>
          </div>
          <div class="fg" style="margin-bottom:8px;"><label>If YES, give details:</label><input type="text" id="pds_q38a_details"/></div>
          <div style="display:flex;gap:20px;margin-bottom:4px;">
            <label style="font-size:.82rem;display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="radio" name="q38b" value="Yes" id="q38b_yes"/> b. Resigned from govt to campaign for a candidate within 3 months before the last election? &nbsp;<strong>YES</strong></label>
            <label style="font-size:.82rem;display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="radio" name="q38b" value="No" id="q38b_no" checked/> <strong>NO</strong></label>
          </div>
          <div class="fg"><label>If YES, give details:</label><input type="text" id="pds_q38b_details"/></div>
        </div>

        <div style="margin-bottom:14px;">
          <div style="display:flex;gap:20px;margin-bottom:4px;">
            <label style="font-size:.82rem;display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="radio" name="q39" value="Yes" id="q39_yes"/> 39. Acquired status of immigrant or permanent resident of another country? &nbsp;<strong>YES</strong></label>
            <label style="font-size:.82rem;display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="radio" name="q39" value="No" id="q39_no" checked/> <strong>NO</strong></label>
          </div>
          <div class="fg"><label>If YES, give details (country):</label><input type="text" id="pds_q39_details"/></div>
        </div>

        <div style="margin-bottom:14px;">
          <div style="font-size:.80rem;font-weight:700;color:var(--g800);margin-bottom:8px;">40. Pursuant to: (a) RA 8371 – Indigenous People; (b) RA 7277 – Persons with Disability; (c) RA 11861 – Solo Parents</div>
          <div class="fg3">
            <div class="fg">
              <label style="display:flex;align-items:center;gap:6px;cursor:pointer;margin-bottom:6px;">
                <input type="checkbox" id="pds_q40a_yes" onchange="toggle40('a')"/> a. Member of any indigenous group?
              </label>
              <input type="text" id="pds_q40a_detail" placeholder="If YES, please specify" style="display:none;"/>
            </div>
            <div class="fg">
              <label style="display:flex;align-items:center;gap:6px;cursor:pointer;margin-bottom:6px;">
                <input type="checkbox" id="pds_q40b_yes" onchange="toggle40('b')"/> b. Person with disability?
              </label>
              <input type="text" id="pds_q40b_detail" placeholder="If YES, specify ID No." style="display:none;"/>
            </div>
            <div class="fg">
              <label style="display:flex;align-items:center;gap:6px;cursor:pointer;margin-bottom:6px;">
                <input type="checkbox" id="pds_q40c_yes" onchange="toggle40('c')"/> c. Solo parent?
              </label>
              <input type="text" id="pds_q40c_detail" placeholder="If YES, specify ID No." style="display:none;"/>
            </div>
          </div>
        </div>
      </div>

      <!-- 41. References -->
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <div class="form-section-hd">41. Character References (Person not related to applicant/appointee)</div>
        <button type="button" class="add-row-btn" onclick="if(confirm('Are you sure you want to reset all Character References? This cannot be undone.')) resetCharacterReferences()" style="background:#ef4444;margin:0;">↻ Reset</button>
      </div>
      <div class="form-section-body">
        <div id="pds_character_ref_list"></div>
        <button class="add-row-btn" onclick="addCharacterRef(null, false)">+ Add Reference</button>
        <p style="font-size:.72rem;color:var(--g400);margin-top:8px;">Provide at least 3 references with their Office/Residential Address and Contact No./Email.</p>
      </div>

      <!-- 42. Government ID -->
      <div class="form-section-hd">42. Government Issued ID</div>
      <div class="form-section-body">
        <div class="fg3">
          <div class="fg fg-full"><label>Government Issued ID (e.g. Passport, GSIS, SSS, PRC, Driver's License)</label>
            <input type="text" id="pds_gov_id_type" placeholder="e.g. Philippine Passport"/>
          </div>
          <div class="fg"><label>ID / License / Passport No.</label><input type="text" id="pds_gov_id_no"/></div>
          <div class="fg"><label>Date / Place of Issuance</label><input type="text" id="pds_gov_id_issuance" placeholder="e.g. Jan 5, 2022 / DFA Manila"/></div>
          <div class="fg"><label>Date Accomplished</label><input type="date" id="pds_date_accomplished"/></div>
        </div>
      </div>

      <div style="text-align:right;margin-top:20px;padding-bottom:30px;display:flex;gap:8px;justify-content:flex-end;">
        <button class="save-form-btn" style="background:linear-gradient(135deg,#16a34a,#15803d);" onclick="printPDS()">🖨️ Print PDS</button>
        <button class="save-form-btn" onclick="savePDS()">💾 Save PDS</button>
      </div>
    </div>
  </div>

    <!-- ══ SALN PAGE ══ -->
  <div class="page" id="page-saln">
    <div class="form-page">

      <!-- Header -->
      <div style="margin-bottom:14px;">
        <div style="font-size:1.15rem;font-weight:800;color:var(--g800);text-transform:uppercase;letter-spacing:.04em;">Sworn Statement of Assets, Liabilities, and Net Worth</div>
        <div style="font-size:.76rem;color:var(--g400);margin-top:3px;">As required by R.A. No. 6713 · 2025 SALN Form · Per CSC Resolution No. 2500632</div>
      </div>

      <!-- Year selector -->
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap;">
        <span style="font-size:.80rem;font-weight:700;color:var(--g600);">Filing Year:</span>
        <div class="year-tabs" id="salnYearTabs"></div>
        <button class="add-row-btn" onclick="addSalnYear()" style="margin-top:0;">+ New Year</button>
      </div>
      <div class="form-status" id="salnStatus"></div>

      <!-- Compliance type -->
      <div class="form-section-hd">Compliance For</div>
      <div class="form-section-body">
        <div class="fg3">
          <div class="fg"><label>Type of Filing</label>
            <select id="saln_filing_type">
              <option value="">— Select —</option>
              <option value="Annual">Annual filing as of December 31</option>
              <option value="Assumption">Assumption of office</option>
              <option value="Exit">Exit</option>
            </select>
          </div>
          <div class="fg"><label>SALN Year / As of Date</label>
            <input type="date" id="saln_as_of"/>
          </div>
        </div>
        <div style="margin-top:10px;">
          <div style="font-size:.74rem;font-weight:700;color:var(--g600);margin-bottom:6px;">Joint or Separate Filing (for spouses who are both public officials/employees)</div>
          <div style="display:flex;gap:20px;flex-wrap:wrap;">
            <label style="display:flex;align-items:center;gap:6px;font-size:.84rem;font-weight:600;cursor:pointer;"><input type="radio" name="saln_filing_mode" value="Joint"/> Joint Filing</label>
            <label style="display:flex;align-items:center;gap:6px;font-size:.84rem;font-weight:600;cursor:pointer;"><input type="radio" name="saln_filing_mode" value="Separate"/> Separate Filing</label>
            <label style="display:flex;align-items:center;gap:6px;font-size:.84rem;font-weight:600;cursor:pointer;"><input type="radio" name="saln_filing_mode" value="NA" checked/> Not Applicable</label>
          </div>
        </div>
      </div>

      <!-- Declarant -->
      <div class="form-section-hd">Declarant</div>
      <div class="form-section-body">
        <div class="fg3">
          <div class="fg"><label>Family Name</label><input type="text" id="saln_dec_lastname"/></div>
          <div class="fg"><label>First Name</label><input type="text" id="saln_dec_firstname"/></div>
          <div class="fg"><label>M.I.</label><input type="text" id="saln_dec_mi" style="max-width:80px;"/></div>
          <div class="fg"><label>Position</label><input type="text" id="saln_dec_position"/></div>
          <div class="fg fg-full"><label>Agency / Office</label><input type="text" id="saln_dec_agency"/></div>
          <div class="fg fg-full"><label>Office Address</label><input type="text" id="saln_dec_office_addr"/></div>
        </div>
      </div>

      <!-- Spouse -->
      <div class="form-section-hd">Spouse</div>
      <div class="form-section-body">
        <div class="fg3">
          <div class="fg"><label>Family Name</label><input type="text" id="saln_sp_lastname"/></div>
          <div class="fg"><label>First Name</label><input type="text" id="saln_sp_firstname"/></div>
          <div class="fg"><label>M.I.</label><input type="text" id="saln_sp_mi" style="max-width:80px;"/></div>
          <div class="fg"><label>Position</label><input type="text" id="saln_sp_position"/></div>
          <div class="fg fg-full"><label>Agency / Office</label><input type="text" id="saln_sp_agency"/></div>
          <div class="fg fg-full"><label>Office Address</label><input type="text" id="saln_sp_office_addr"/></div>
        </div>
        <div style="margin-top:10px;">
          <div style="font-size:.74rem;font-weight:700;color:var(--g600);margin-bottom:4px;">If with multiple marriages, indicate name(s) of spouse(s):</div>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <input type="text" id="saln_multi_spouse" style="flex:1;padding:8px 11px;border:1.5px solid var(--g200);border-radius:var(--r-sm);font-family:'Nunito',sans-serif;font-size:.84rem;" placeholder="Name(s) of spouse(s)"/>
            <label style="display:flex;align-items:center;gap:6px;font-size:.82rem;font-weight:600;white-space:nowrap;cursor:pointer;"><input type="checkbox" id="saln_multi_na"/> Not Applicable</label>
          </div>
        </div>
      </div>

      <!-- Unmarried Children -->
      <div class="form-section-hd">Unmarried Children Below 18 Living in Declarant's Household</div>
      <div class="form-section-body">
        <div id="saln_children_list"></div>
        <button class="add-row-btn" onclick="addSalnChild()">+ Add Child</button>
      </div>

      <!-- 1a. Real Properties -->
      <div class="form-section-hd">Assets — 1a. Real Properties</div>
      <div class="form-section-body">
        <p style="font-size:.74rem;color:var(--g600);margin-bottom:10px;">Including those of the spouse and unmarried children below 18 years of age living in declarant's household.</p>
        <div id="saln_real_list"></div>
        <button class="add-row-btn" onclick="addReal()">+ Add Real Property</button>
        <div class="fg2" style="margin-top:12px;border-top:1.5px solid var(--g100);padding-top:10px;">
          <div class="fg"><label>Subtotal — Real Properties (₱)</label><input type="number" id="saln_real_subtotal" placeholder="0.00" step="0.01" style="background:var(--g50);"/></div>
        </div>
      </div>

      <!-- 1b. Personal Properties -->
      <div class="form-section-hd">Assets — 1b. Personal Properties</div>
      <div class="form-section-body">
        <div id="saln_personal_list"></div>
        <button class="add-row-btn" onclick="addPersonal()">+ Add Personal Property</button>
        <div class="fg2" style="margin-top:12px;border-top:1.5px solid var(--g100);padding-top:10px;">
          <div class="fg"><label>Subtotal — Personal Properties (₱)</label><input type="number" id="saln_personal_subtotal" placeholder="0.00" step="0.01" style="background:var(--g50);"/></div>
          <div class="fg"><label>TOTAL ASSETS (₱) <span style="color:var(--primary);">= Real + Personal</span></label><input type="number" id="saln_total_assets" placeholder="0.00" step="0.01" style="background:#eff6ff;font-weight:800;"/></div>
        </div>
      </div>

      <!-- 2. Liabilities -->
      <div class="form-section-hd">Liabilities</div>
      <div class="form-section-body">
        <div id="saln_liab_list"></div>
        <button class="add-row-btn" onclick="addLiab()">+ Add Liability</button>
        <div class="fg2" style="margin-top:12px;border-top:1.5px solid var(--g100);padding-top:10px;">
          <div class="fg"><label>TOTAL LIABILITIES (₱)</label><input type="number" id="saln_total_liab" placeholder="0.00" step="0.01" style="background:var(--g50);font-weight:800;"/></div>
          <div class="fg"><label>NET WORTH (₱) <span style="color:var(--accent);">= Total Assets − Total Liabilities</span></label><input type="number" id="saln_net_worth" placeholder="0.00" step="0.01" style="background:#f0fdf4;font-weight:800;"/></div>
        </div>
      </div>

      <!-- 3. Business Interests -->
      <div class="form-section-hd">Business Interests and Financial Connections</div>
      <div class="form-section-body">
        <p style="font-size:.74rem;color:var(--g600);margin-bottom:8px;">Of Declarant / Declarant's Spouse / Unmarried Children Below 18 Living in Declarant's Household</p>
        <label style="display:flex;align-items:center;gap:8px;font-size:.84rem;font-weight:600;cursor:pointer;margin-bottom:10px;">
          <input type="checkbox" id="saln_no_biz" onchange="toggleBiz()"/> I/We do not have any business interest or financial connection.
        </label>
        <div id="saln_biz_wrap">
          <div id="saln_biz_list"></div>
          <button class="add-row-btn" onclick="addBiz()">+ Add Business Interest</button>
        </div>
      </div>

      <!-- 4. Relatives in Government -->
      <div class="form-section-hd">Relatives in the Government Service</div>
      <div class="form-section-body">
        <p style="font-size:.74rem;color:var(--g600);margin-bottom:8px;">Within the Fourth Degree of Consanguinity or Affinity. Include also Bilas, Balae and Inso.</p>
        <label style="display:flex;align-items:center;gap:8px;font-size:.84rem;font-weight:600;cursor:pointer;margin-bottom:10px;">
          <input type="checkbox" id="saln_no_rel" onchange="toggleRel()"/> I/We do not know of any relative/s in the government service.
        </label>
        <div id="saln_rel_wrap">
          <div id="saln_rel_list"></div>
          <button class="add-row-btn" onclick="addRel()">+ Add Relative</button>
        </div>
      </div>

      <!-- Government ID for SALN -->
      <div class="form-section-hd">Government Issued ID</div>
      <div class="form-section-body">
        <div class="fg3">
          <div class="fg"><label>Government Issued ID (type)</label><input type="text" id="saln_gov_id" placeholder="e.g. Philippine Passport"/></div>
          <div class="fg"><label>ID No.</label><input type="text" id="saln_gov_id_no"/></div>
          <div class="fg"><label>Date Issued</label><input type="date" id="saln_gov_id_issued"/></div>
        </div>
      </div>

      <div style="text-align:right;margin-top:20px;padding-bottom:30px;display:flex;gap:8px;justify-content:flex-end;">
        <button class="save-form-btn" style="background:linear-gradient(135deg,#16a34a,#15803d);" onclick="printSALN()">🖨️ Print SALN</button>
        <button class="save-form-btn" onclick="saveSALN()">💾 Save SALN</button>
      </div>
    </div>
  </div>

  <!-- ══ SERVICE RECORD PAGE ══ -->
  <div class="page" id="page-service_record">
    <div class="form-page">

      <!-- Request status banner -->
      <div id="srRequestBanner" style="display:none;padding:12px 16px;border-radius:10px;margin-bottom:14px;font-size:.84rem;font-weight:700;border:1.5px solid #fde68a;background:#fffbeb;color:#92400e;"></div>

      <!-- Records section — white card matching form-section style -->
      <div class="form-section-hd">Service Record Entries</div>
      <div class="form-section-body" style="padding:0;min-height:320px;">
        <div id="srContent">
          <div style="text-align:center;padding:60px 20px;color:var(--g400);">
            <div style="font-size:3rem;margin-bottom:12px;">📜</div>
            <div style="font-size:.90rem;font-weight:700;margin-bottom:6px;">No service record entries yet.</div>
            <div style="font-size:.76rem;line-height:1.6;">Your service record will appear here once HR has entered your employment history.<br>Use the <strong>📨 Request Service Record</strong> button below to ask HR to prepare it.</div>
          </div>
        </div>
      </div>

      <!-- Buttons at bottom — same as SALN/PDS -->
      <div style="margin-top:20px;padding-bottom:30px;display:flex;gap:8px;justify-content:flex-end;">
        <button class="save-form-btn" onclick="requestServiceRecord()" id="btnRequestSR" style="background:linear-gradient(135deg,#2563eb,#1e40af);">📨 Request Service Record</button>
        <button class="save-form-btn" onclick="printMySR()" id="btnPrintSR" style="background:linear-gradient(135deg,#16a34a,#15803d);display:none;">🖨️ Print Service Record</button>
      </div>

    </div><!-- /form-page -->
  </div><!-- /page-service_record -->
</div><!-- .main -->
  <!-- Request modal -->
  <div id="srReqModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:800;align-items:center;justify-content:center;backdrop-filter:blur(3px);">
    <div style="background:#fff;border-radius:14px;box-shadow:0 8px 32px rgba(0,0,0,.18);width:92%;max-width:460px;animation:fadeUp .3s ease;">
      <div style="background:linear-gradient(135deg,#2563eb,#1e40af);padding:18px 22px 14px;border-radius:14px 14px 0 0;display:flex;align-items:center;justify-content:space-between;">
        <div>
          <div style="font-size:1rem;font-weight:800;color:#fff;font-family:'Playfair Display',serif;">Request Service Record</div>
          <div style="font-size:.73rem;color:rgba(255,255,255,.65);margin-top:2px;">HR will review and prepare your record.</div>
        </div>
        <button onclick="document.getElementById('srReqModal').style.display='none';" style="width:30px;height:30px;border-radius:50%;background:rgba(255,255,255,.18);border:none;color:#fff;font-size:1rem;cursor:pointer;">✕</button>
      </div>
      <div style="padding:20px 22px;">
        <div style="margin-bottom:14px;">
          <label style="font-size:.72rem;font-weight:800;color:var(--g600);text-transform:uppercase;letter-spacing:.05em;display:block;margin-bottom:5px;">Purpose of Request</label>
          <textarea id="srPurpose" placeholder="e.g. For employment application, scholarship, etc." style="width:100%;padding:9px 12px;border:1.5px solid var(--g200);border-radius:9px;font-family:'Nunito',sans-serif;font-size:.84rem;resize:vertical;min-height:80px;outline:none;" onfocus="this.style.borderColor='#2563eb'" onblur="this.style.borderColor='var(--g200)'"></textarea>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;">
          <button onclick="document.getElementById('srReqModal').style.display='none';" style="padding:9px 20px;border:1.5px solid var(--g200);background:#fff;border-radius:9px;font-family:'Nunito',sans-serif;font-size:.83rem;font-weight:700;cursor:pointer;">Cancel</button>
          <button onclick="submitSRRequest()" style="padding:9px 22px;background:linear-gradient(135deg,#2563eb,#1e40af);color:#fff;border:none;border-radius:9px;font-family:'Nunito',sans-serif;font-size:.83rem;font-weight:700;cursor:pointer;" id="btnSubmitSR">📨 Submit Request</button>
        </div>
      </div>
    </div>
  </div>

<!-- UPLOAD DOCUMENT MODAL -->
<div class="modal-overlay" id="modalDocUpload">
  <div class="modal">
    <div class="modal-head">
      <div><h3>📤 Upload Document</h3><p>Add a new file to your records</p></div>
      <button class="modal-x" onclick="closeModal('modalDocUpload')">✕</button>
    </div>
    <div class="modal-body">
      <div class="fg" style="margin-bottom:14px;">
        <label>Document Type <span style="color:var(--danger);">*</span></label>
        <select id="uploadType" class="doc-filter" style="max-width:100%;width:100%;padding:9px 12px;">
          <option value="">Select type…</option>
          <option value="Appointment">Appointment</option>
          <option value="Contract">Contract</option>
          <option value="PDS">PDS (Personal Data Sheet)</option>
          <option value="SALN">SALN</option>
          <option value="Certificate">Certificate</option>
          <option value="Medical Certificate">Medical Certificate</option>
          <option value="Leave Form">Leave Form</option>
          <option value="Payslip">Payslip</option>
          <option value="ID">Government ID</option>
          <option value="Other">Other</option>
        </select>
      </div>
      <div class="drop-zone" id="docDropZone" onclick="document.getElementById('docFileInput').click()"
           ondragover="dzOver(event)" ondragleave="dzLeave()" ondrop="dzDrop(event)">
        <span class="dz-ic">📂</span>
        <p>Click to browse or drag & drop</p>
        <span>PDF, DOC, DOCX, XLS, XLSX, JPG, PNG — Max 10MB</span>
        <input type="file" id="docFileInput" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif" onchange="docFileSelect(event)"/>
      </div>
      <div class="fp-row" id="docFilePreview">
        <span class="fp-ic" id="docFpIcon"></span>
        <div class="fp-info">
          <div class="fp-name" id="docFpName"></div>
          <div class="fp-sz"  id="docFpSize"></div>
        </div>
        <button class="fp-x" onclick="clearDocFile()">✕</button>
      </div>
      <div class="prog-wrap" id="docProgress">
        <div class="prog-bar"><div class="prog-fill" id="docProgFill"></div></div>
        <div class="prog-lbl" id="docProgLbl">Uploading…</div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="m-btn m-btn-cancel" onclick="closeModal('modalDocUpload')">Cancel</button>
      <button class="m-btn m-btn-primary" id="docUploadBtn" onclick="submitDocUpload()">📤 Upload</button>
    </div>
  </div>
</div>

<!-- DELETE DOCUMENT MODAL -->
<div class="modal-overlay" id="modalDocDelete">
  <div class="modal" style="max-width:400px;">
    <div class="modal-head danger">
      <div><h3>🗑️ Delete Document</h3><p>This cannot be undone</p></div>
      <button class="modal-x" onclick="closeModal('modalDocDelete')">✕</button>
    </div>
    <div class="confirm-body">
      <div class="confirm-icon">⚠️</div>
      <h4>Delete this document?</h4>
      <p id="docDeleteText"></p>
    </div>
    <div class="modal-foot">
      <button class="m-btn m-btn-cancel" onclick="closeModal('modalDocDelete')">Cancel</button>
      <button class="m-btn m-btn-danger" id="docDeleteBtn" onclick="confirmDocDelete()">🗑️ Yes, Delete</button>
    </div>
  </div>
</div>

<!-- DOCUMENT PREVIEW MODAL -->
<div class="modal-overlay" id="modalDocPreview">
  <div class="modal preview">
    <div class="modal-head">
      <div><h3 id="docPreviewTitle">Document Preview</h3><p id="docPreviewSub">Viewing file</p></div>
      <button class="modal-x" onclick="closeDocPreview()">✕</button>
    </div>
    <div class="preview-body" id="docPreviewBody">
      <!-- content injected by JS -->
    </div>
    <div class="preview-footer">
      <span class="preview-fname" id="docPreviewFname"></span>
      <a id="docPreviewDownload" href="#" download class="prev-dl-btn">⬇️ Download</a>
    </div>
  </div>
</div>

  <!-- ══ SERVICE RECORD PAGE ══ -->
  <!-- ══ SERVICE RECORD PAGE ══ -->
  <!-- ══ SERVICE RECORD PAGE ══ -->


  <!-- Request modal (outside page div so it overlays everything) -->
  <div id="srReqModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:800;align-items:center;justify-content:center;backdrop-filter:blur(3px);">
    <div style="background:#fff;border-radius:14px;box-shadow:0 8px 32px rgba(0,0,0,.18);width:92%;max-width:460px;animation:fadeUp .3s ease;">
      <div style="background:linear-gradient(135deg,#2563eb,#1e40af);padding:18px 22px 14px;border-radius:14px 14px 0 0;display:flex;align-items:center;justify-content:space-between;">
        <div>
          <div style="font-size:1rem;font-weight:800;color:#fff;font-family:'Playfair Display',serif;">Request Service Record</div>
          <div style="font-size:.73rem;color:rgba(255,255,255,.65);margin-top:2px;">HR will review and prepare your record.</div>
        </div>
        <button onclick="document.getElementById('srReqModal').style.display='none';" style="width:30px;height:30px;border-radius:50%;background:rgba(255,255,255,.18);border:none;color:#fff;font-size:1rem;cursor:pointer;">✕</button>
      </div>
      <div style="padding:20px 22px;">
        <div style="margin-bottom:14px;">
          <label style="font-size:.72rem;font-weight:800;color:var(--g600);text-transform:uppercase;letter-spacing:.05em;display:block;margin-bottom:5px;">Purpose of Request</label>
          <textarea id="srPurpose" placeholder="e.g. For employment application, scholarship, etc." style="width:100%;padding:9px 12px;border:1.5px solid var(--g200);border-radius:9px;font-family:'Nunito',sans-serif;font-size:.84rem;resize:vertical;min-height:80px;outline:none;" onfocus="this.style.borderColor='#2563eb'" onblur="this.style.borderColor='var(--g200)'"></textarea>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;">
          <button onclick="document.getElementById('srReqModal').style.display='none';" style="padding:9px 20px;border:1.5px solid var(--g200);background:#fff;border-radius:9px;font-family:'Nunito',sans-serif;font-size:.83rem;font-weight:700;cursor:pointer;">Cancel</button>
          <button onclick="submitSRRequest()" style="padding:9px 22px;background:linear-gradient(135deg,#2563eb,#1e40af);color:#fff;border:none;border-radius:9px;font-family:'Nunito',sans-serif;font-size:.83rem;font-weight:700;cursor:pointer;box-shadow:0 3px 10px rgba(37,99,235,.28);" id="btnSubmitSR">📨 Submit Request</button>
        </div>
      </div>
    </div>
  </div>


<script>
const CSRF_TOKEN = document.querySelector('meta[name=csrf-token]').content;
// ════════════════════════════════════════════════════════
//  HELPERS
// ════════════════════════════════════════════════════════
const fmt     = d => d ? new Date(d + (d.length===10?'T00:00:00':'')).toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}) : '—';
const ini     = n => n ? n.split(' ').map(w=>w[0]).join('').slice(0,2).toUpperCase() : '?';
const COLORS  = ['#2563eb','#22c55e','#f59e0b','#ef4444','#a855f7','#06b6d4','#ec4899','#f97316','#6366f1','#14b8a6'];
const acColor = s => COLORS[Math.abs([...s].reduce((a,c)=>a+c.charCodeAt(0),0)) % COLORS.length];
const fmtSize = b => { if(!b) return '0 B'; b=parseInt(b); if(b<1024) return b+' B'; if(b<1048576) return (b/1024).toFixed(1)+' KB'; return (b/1048576).toFixed(2)+' MB'; };

const TYPE_ICONS = {'Vacation':'🏖️','Sick':'🤒','Emergency':'🚨','Maternity':'🤱','Paternity':'👨‍👶','Special':'✨','Forced':'🗓️'};
const TYPE_COLORS = {'Vacation':'#2563eb','Sick':'#f59e0b','Emergency':'#ef4444','Maternity':'#ec4899','Paternity':'#22c55e','Special':'#a855f7','Forced':'#06b6d4'};
const ST_CLS  = {Pending:'b-pending',Approved:'b-approved',Denied:'b-denied',Cancelled:'b-cancelled'};
const ST_ICO  = {Pending:'⏳',Approved:'✅',Denied:'✗',Cancelled:'—'};
const FILE_ICONS = {pdf:'📄',doc:'📝',docx:'📝',xls:'📊',xlsx:'📊',jpg:'🖼️',jpeg:'🖼️',png:'🖼️',gif:'🖼️'};
const DOC_COLORS = {'Appointment':{bg:'#dbeafe',c:'#1e40af'},'Contract':{bg:'#dcfce7',c:'#166534'},'PDS':{bg:'#f3e8ff',c:'#6b21a8'},'SALN':{bg:'#fef3c7',c:'#92400e'},'Certificate':{bg:'#e0f2fe',c:'#0369a1'},'Medical Certificate':{bg:'#fce7f3',c:'#9d174d'},'Leave Form':{bg:'#fef3c7',c:'#92400e'},'Payslip':{bg:'#dcfce7',c:'#166534'},'ID':{bg:'#f1f5f9',c:'#475569'},'Other':{bg:'#f1f5f9',c:'#475569'}};

// ════════════════════════════════════════════════════════
//  STATE
// ════════════════════════════════════════════════════════
let profileData = null, balanceData = [], leaveTypes = [];
let selTypeId = null, selTypeName = '', selTypeBal = 0;
let curReqTab = 'all';
let docFile = null, docDeleteTarget = null;
let docDebounce = null;

// ════════════════════════════════════════════════════════
//  INIT
// ════════════════════════════════════════════════════════
(async function init() {
  const now = new Date();
  document.getElementById('heroDay').textContent   = now.getDate();
  document.getElementById('heroMonth').textContent = now.toLocaleDateString('en-PH',{month:'long',year:'numeric'});
  document.getElementById('tbDate').textContent    = now.toLocaleDateString('en-PH',{weekday:'short',month:'short',day:'numeric',year:'numeric'});

  await loadProfile();
  
  // ── CHECK EMPLOYMENT TYPE AND APPLY RESTRICTIONS ──
  restrictByEmploymentType();
  
  loadBalances();
  loadLeaveTypes();
  loadRecentRequests();
  loadAnnouncements();
  // Poll notifications immediately then every 30 seconds
  try { pollEpNotifications(); } catch(e) { console.warn(e); }
  setInterval(function(){ try { pollEpNotifications(); } catch(e){} }, 30000);
})();

// ════════════════════════════════════════════════════════
//  PAGE NAV
// ════════════════════════════════════════════════════════
const PAGE_META = {
  dashboard:['Dashboard',          'Welcome back!'],
  profile:  ['My Profile',         'Personal & employment information'],
  requests: ['My Leave Requests',  'Track all your leave applications'],
  file:     ['File Leave Request', 'Submit a new leave application'],
  balances: ["Leave Balances",     "Your available credits for CY <?= date('Y') ?>"],
  documents:['My Documents',       'View and manage your HR files'],
  pds:      ['Personal Data Sheet', 'CS Form No. 212 (Revised 2017)'],
  saln:     ['SALN',                'Statement of Assets, Liabilities and Net Worth'],
  service_record: ['Service Record', 'Your government service history'],
};

// ════════════════════════════════════════════════════════
//  EMPLOYMENT TYPE RESTRICTIONS
// ════════════════════════════════════════════════════════
function restrictByEmploymentType() {
  const empType = (window._profileData && window._profileData.employment_type) ? window._profileData.employment_type : 'Permanent';
  const isPermanent = (empType === 'Permanent');
  
  // Store for use in other functions
  window._employmentType = empType;
  
  if (!isPermanent) {
    // Non-permanent employees cannot access leave-related features
    const sidebarItems = document.querySelectorAll('.sb-item');
    sidebarItems.forEach((item, idx) => {
      // Disable: My Leave Requests (index 2), File Leave Request (index 3), Leave Balances (index 4)
      if (idx === 2 || idx === 3 || idx === 4) {
        item.style.opacity = '0.5';
        item.style.cursor = 'not-allowed';
        item.setAttribute('data-disabled', 'true');
        item.onclick = function(e) {
          e.preventDefault();
          e.stopPropagation();
          showToast(`📋 Leave features are only available to Permanent employees.`, 'info');
          return false;
        };
      }
    });
    
    // Disable Quick Action buttons for leave features
    const qaButtons = document.querySelectorAll('.qa-btn');
    if (qaButtons.length >= 2) {
      // File Leave (index 0), Leave Balances (index 1)
      for (let i = 0; i < Math.min(2, qaButtons.length); i++) {
        qaButtons[i].style.opacity = '0.5';
        qaButtons[i].style.cursor = 'not-allowed';
        qaButtons[i].onclick = function(e) {
          e.preventDefault();
          e.stopPropagation();
          showToast(`📋 Leave features are only available to Permanent employees.`, 'info');
          return false;
        };
      }
    }
    
    // Show employment type banner
    showNonPermanentBanner(empType);
  }
}

function showNonPermanentBanner(empType) {
  const topbar = document.querySelector('.topbar');
  if (topbar) {
    const banner = document.createElement('div');
    banner.style.cssText = `
      position: sticky;
      top: var(--topbar-h);
      z-index: 190;
      background: linear-gradient(135deg, #9333ea 0%, #7e22ce 50%, #6b21a8 100%);
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 12px 26px;
      font-family: 'Nunito', sans-serif;
      box-shadow: 0 3px 14px rgba(0,0,0,.25);
      flex-wrap: wrap;
      border-bottom: 2px solid #c084fc;
    `;
    banner.innerHTML = `
      <div style="display:flex;align-items:center;gap:10px;">
        <div style="font-size:1.3rem;animation:pulse 2s infinite;">ℹ️</div>
        <div>
          <strong style="display:block;font-size:.90rem;font-weight:800;letter-spacing:.01em;">📌 Limited Access — ${empType} Employee</strong>
          <span style="font-size:.75rem;opacity:.90;font-weight:600;">You can upload documents, fill PDS/SALN, and request service records. Leave features are available to Permanent employees only.</span>
        </div>
      </div>
    `;
    topbar.parentNode.insertBefore(banner, topbar.nextSibling);
  }
}

function goPage(name, btn) {
  document.querySelectorAll('.page').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.sb-item').forEach(b=>b.classList.remove('active'));
  document.getElementById(`page-${name}`).classList.add('active');
  if (btn) btn.classList.add('active');
  document.getElementById('tbTitle').textContent = PAGE_META[name][0];
  document.getElementById('tbSub').textContent   = PAGE_META[name][1];
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('mobOverlay').classList.remove('show');
  if (name === 'requests')  loadRequests();
  if (name === 'balances')  renderBalancesPage();
  if (name === 'documents') loadDocuments();
  if (name === 'pds')       loadPDS();
  if (name === 'saln')      loadSALN();
  if (name === 'service_record') loadServiceRecord();
  if (name === 'file')      loadFileLeavePanel();
}

// ════════════════════════════════════════════════════════
//  SERVICE RECORD
// ════════════════════════════════════════════════════════
let _srData = null;

async function loadServiceRecord() {
  const cont = document.getElementById('srContent');
  cont.innerHTML = '<div style="text-align:center;padding:40px;color:var(--g400);"><div style="font-size:2.5rem;margin-bottom:10px;">⏳</div><div style="font-weight:600;">Loading…</div></div>';
  try {
    const r = await fetch('Employee_portal.php?action=get_service_record').then(r=>r.json());
    if (!r.success) { cont.innerHTML = '<p style="color:red;padding:20px;">Error loading service record.</p>'; return; }
    _srData = r;
    renderSRPage(r);
  } catch(e) {
    cont.innerHTML = '<p style="color:red;padding:20px;">Network error.</p>';
  }
}

function renderSRPage(r) {
  const records = r.records || [];
  const req     = r.latest_request;
  const cont    = document.getElementById('srContent');
  const banner  = document.getElementById('srRequestBanner');
  const printBtn= document.getElementById('btnPrintSR');

  // Show request status banner
  if (req) {
    banner.style.display = 'block';
    const colors = {Pending:'background:#fffbeb;color:#92400e;border:1.5px solid #fde68a;',
                    Approved:'background:#f0fdf4;color:#166534;border:1.5px solid #bbf7d0;',
                    Denied:'background:#fef2f2;color:#991b1b;border:1.5px solid #fecaca;'};
    const icons = {Pending:'⏳',Approved:'✅',Denied:'✗'};
    banner.style.cssText = 'display:block;padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:.84rem;font-weight:700;' + (colors[req.status]||colors.Pending);
    banner.innerHTML = icons[req.status] + ' Service Record Request — <strong>' + req.status + '</strong>'
      + (req.admin_note ? ' &nbsp;·&nbsp; Note: ' + req.admin_note : '')
      + ' &nbsp;<span style="font-weight:400;font-size:.76rem;">Requested: ' + fmt(req.requested_at) + '</span>';
  } else {
    banner.style.display = 'none';
  }

  // Show print button only if there are records
  printBtn.style.display = records.length ? 'inline-flex' : 'none';

  if (!records.length) {
    cont.innerHTML = `
      <div style="text-align:center;padding:60px 20px;color:var(--g400);">
        <div style="font-size:3rem;margin-bottom:14px;">📜</div>
        <div style="font-size:.92rem;font-weight:700;margin-bottom:6px;">No service record entries yet</div>
        <div style="font-size:.78rem;">Your service record will appear here once HR has entered your employment history.</div>
        <div style="margin-top:18px;font-size:.78rem;color:var(--g400);">You can click <strong>📨 Request Service Record</strong> to ask HR to prepare it.</div>
      </div>`;
    return;
  }

  // Build read-only table
  const emp = r.employee || {};
  const rows = records.map(sr => `
    <tr>
      <td style="white-space:nowrap;font-size:.78rem;">${sr.date_from ? fmt(sr.date_from) : '—'}</td>
      <td style="white-space:nowrap;font-size:.78rem;">${sr.date_to   ? fmt(sr.date_to)   : '—'}</td>
      <td style="font-weight:600;">${sr.designation||'—'}</td>
      <td><span style="padding:2px 8px;border-radius:12px;font-size:.70rem;font-weight:800;background:#eff6ff;color:#1e40af;">${sr.status||'—'}</span></td>
      <td style="text-align:right;font-weight:700;">${sr.salary ? '₱'+parseFloat(sr.salary).toLocaleString('en-PH',{minimumFractionDigits:2}) : '—'}</td>
      <td>${sr.branch||'—'}</td>
      <td>${sr.station||'—'}</td>
      <td style="font-size:.75rem;color:var(--g400);">${sr.lvbs_wo_pay||'—'}</td>
      <td style="white-space:nowrap;font-size:.78rem;">${sr.separation_date ? fmt(sr.separation_date) : '—'}</td>
      <td>${sr.separation_cause||'—'}</td>
    </tr>`).join('');

  cont.innerHTML = `
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:.80rem;">
        <thead>
          <tr>
            <th colspan="2" style="background:#dbeafe;color:#1e40af;border:1px solid #93c5fd;padding:7px 8px;text-align:center;font-size:.70rem;font-weight:800;text-transform:uppercase;">Service Period</th>
            <th colspan="3" style="background:#f3e8ff;color:#6b21a8;border:1px solid #d8b4fe;padding:7px 8px;text-align:center;font-size:.70rem;font-weight:800;text-transform:uppercase;">Record of Appointment</th>
            <th colspan="2" style="background:#dcfce7;color:#166534;border:1px solid #86efac;padding:7px 8px;text-align:center;font-size:.70rem;font-weight:800;text-transform:uppercase;">Office / Entity</th>
            <th rowspan="2" style="background:var(--g100);border:1px solid var(--g200);padding:7px 8px;text-align:center;font-size:.70rem;font-weight:800;text-transform:uppercase;vertical-align:bottom;">LVBS<br>W/O Pay</th>
            <th colspan="2" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;padding:7px 8px;text-align:center;font-size:.70rem;font-weight:800;text-transform:uppercase;">Separation</th>
          </tr>
          <tr>
            <th style="background:var(--g100);border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:800;color:var(--g600);text-align:center;">From</th>
            <th style="background:var(--g100);border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:800;color:var(--g600);text-align:center;">To</th>
            <th style="background:var(--g100);border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:800;color:var(--g600);">Designation</th>
            <th style="background:var(--g100);border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:800;color:var(--g600);text-align:center;">Status</th>
            <th style="background:var(--g100);border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:800;color:var(--g600);text-align:right;">Salary/Annum</th>
            <th style="background:var(--g100);border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:800;color:var(--g600);">Branch</th>
            <th style="background:var(--g100);border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:800;color:var(--g600);">Station/Place</th>
            <th style="background:var(--g100);border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:800;color:var(--g600);text-align:center;">Date</th>
            <th style="background:var(--g100);border:1px solid var(--g200);padding:6px 8px;font-size:.68rem;font-weight:800;color:var(--g600);">Cause</th>
          </tr>
        </thead>
        <tbody>
          ${rows}
        </tbody>
      </table>
    </div>`;
}

function requestServiceRecord() {
  document.getElementById('srPurpose').value = '';
  document.getElementById('srReqModal').style.display = 'flex';
}

async function submitSRRequest() {
  const btn     = document.getElementById('btnSubmitSR');
  const purpose = document.getElementById('srPurpose').value.trim();
  btn.disabled  = true; btn.textContent = '⏳ Submitting…';
  try {
    const fd = new FormData();
    fd.append('action', 'request_service_record');
    fd.append('purpose', purpose);
    fd.append('csrf_token', CSRF_TOKEN);
    const r = await fetch('Employee_portal.php', {method:'POST', body:fd}).then(r=>r.json());
    if (r.success) {
      showToast('✅ ' + r.message, 'success');
      document.getElementById('srReqModal').style.display = 'none';
      await loadServiceRecord();
    } else {
      showToast('❌ ' + (r.message||'Request failed.'), 'error');
    }
  } catch(e) { showToast('❌ Network error.', 'error'); }
  finally { btn.disabled = false; btn.textContent = '📨 Submit Request'; }
}

async function printMySR() {
  if (!_srData) return;
  const emp     = _srData.employee || {};
  const records = _srData.records  || [];
  // Use the same buildAndPrintSR from admin — but we call a local version
  buildAndPrintMySR(emp, records);
}

function buildAndPrintMySR(emp, records) {
  const ROWS_PER_PAGE = 15;
  const totalPages = Math.max(1, Math.ceil(records.length / ROWS_PER_PAGE));

  const fmtD = d => { if(!d) return ''; try { return new Date(d+'T00:00:00').toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'numeric'}); } catch(e){ return d; } };
  const escD = s => s ? String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') : '';

  let pages = '';
  for (let p = 0; p < totalPages; p++) {
    const pageRecs = records.slice(p*ROWS_PER_PAGE, (p+1)*ROWS_PER_PAGE);
    const rows = pageRecs.map(sr => `
      <tr>
        <td>${fmtD(sr.date_from)}</td><td>${fmtD(sr.date_to)}</td>
        <td>${escD(sr.designation)}</td>
        <td style="text-align:center;">${escD(sr.status)}</td>
        <td style="text-align:right;">${sr.salary ? parseFloat(sr.salary).toLocaleString('en-PH',{minimumFractionDigits:2}) : ''}</td>
        <td>${escD(sr.branch)}</td><td>${escD(sr.station)}</td>
        <td style="text-align:center;">${escD(sr.lvbs_wo_pay)}</td>
        <td style="text-align:center;">${fmtD(sr.separation_date)}</td>
        <td>${escD(sr.separation_cause)}</td>
      </tr>`).join('');
    let blanks = '';
    for (let i = pageRecs.length; i < ROWS_PER_PAGE; i++) blanks += '<tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>';

    pages += `<div class="sr-page">
      <table class="hdr-tbl"><tr>
        <td class="logo-cell"><img src="../images/Enrile.png" class="logo-img" onerror="this.style.display='none'"/></td>
        <td class="hdr-main">
          <div class="hdr-republic">Republic of the Philippines</div>
          <div class="hdr-province">Province of Cagayan</div>
          <div class="hdr-muni">Municipality of Enrile</div>
          <div class="hdr-brand">ONE ENRILE</div>
          <div class="hdr-office">Office of the Municipal Mayor</div>
        </td>
      </tr></table>
      <div class="meta-row"><em>GSIS BP No: ${emp.gsis_number||''}</em><span style="margin-left:20pt;"><em>Tax Identification No. ${emp.tin_number||''}</em></span></div>
      <div class="sr-title">S E R V I C E &nbsp;&nbsp; R E C O R D</div>
      <table class="name-tbl"><tr>
        <td class="name-label"><strong>NAME:</strong></td>
        <td class="name-cell name-underline">${escD(emp.last_name)}</td>
        <td class="name-cell name-underline">${escD(emp.first_name)}</td>
        <td class="name-cell name-underline mi-cell">${emp.middle_name ? emp.middle_name[0]+'.' : ''}</td>
        <td class="married-note">(If married, give maiden name)</td>
      </tr><tr>
        <td></td><td class="name-sub">(Surname)</td><td class="name-sub">(First Name)</td><td class="name-sub">(M.I)</td><td></td>
      </tr></table>
      <div class="dob-row">DATE OF BIRTH: <span class="dob-val">${fmtD(emp.date_of_birth)}</span></div>
      <div class="cert-text">THIS IS TO CERTIFY that the above-named employee actually rendered services in the Office as shown in the column below. Each line is supported by appointment papers actually issued by this office and approved by authorities concerned.</div>
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
            <th class="col-hd">From</th><th class="col-hd">To</th>
            <th class="col-hd">Designation</th><th class="col-hd">Status</th>
            <th class="col-hd">Salary per Annum</th>
            <th class="col-hd">Branch</th><th class="col-hd">Station/Place</th>
            <th class="col-hd">Date</th><th class="col-hd">Cause</th>
          </tr>
        </thead>
        <tbody>${rows}${blanks}</tbody>
      </table>
      <div class="footer-cert">Issued in compliance with Executive Order No. 54 dated August 10, 1954 and in accordance with Circular No. 58, dated August 10, 1954 of the system.</div>
      <table class="sig-tbl"><tr>
        <td class="sig-left"><div class="sig-prep">Prepared by:</div><div class="sig-name">EMIROSE P. MADRIAGA</div><div class="sig-pos">HRMO III</div></td>
        <td class="sig-right"><div class="sig-prep">Conforme:</div><div class="sig-name">MIGUEL B. DECENA, JR.</div><div class="sig-pos">Municipal Mayor</div></td>
      </tr></table>
      <div class="page-num">Page ${p+1} of ${totalPages}</div>
    </div>`;
  }

  const css = `
    *{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'Century Gothic','Calibri',sans-serif;font-size:9pt;color:#000;background:#fff;}
    @page{size:legal portrait;margin:12mm 14mm;}
    @media print{.sr-page{page-break-after:always;}.sr-page:last-child{page-break-after:avoid;}}
    .hdr-tbl{width:100%;border-collapse:collapse;margin-bottom:3pt;}
    .logo-cell{width:60pt;text-align:center;vertical-align:middle;padding-right:8pt;}
    .logo-img{width:50pt;height:50pt;object-fit:contain;}
    .hdr-main{text-align:center;vertical-align:middle;}
    .hdr-republic{font-family:Tahoma,sans-serif;font-weight:700;font-size:10pt;}
    .hdr-province{font-family:Tahoma,sans-serif;font-weight:700;font-size:9.5pt;}
    .hdr-muni{font-family:Tahoma,sans-serif;font-weight:700;font-size:10pt;}
    .hdr-brand{font-family:Arial,sans-serif;font-weight:900;font-size:18pt;letter-spacing:2pt;margin:2pt 0;}
    .hdr-office{font-family:Georgia,serif;font-weight:700;font-size:14pt;}
    .meta-row{margin:4pt 0;font-size:8pt;font-style:italic;}
    .sr-title{font-family:'Bookman Old Style','Georgia',serif;font-weight:700;font-size:14pt;text-align:center;margin:6pt 0 5pt;letter-spacing:2pt;}
    .name-tbl{width:100%;border-collapse:collapse;margin-bottom:4pt;}
    .name-label{font-family:'Century Gothic',sans-serif;font-weight:700;font-size:11pt;width:55pt;padding-right:4pt;white-space:nowrap;}
    .name-cell{font-family:'Century Gothic',sans-serif;font-size:11pt;padding:0 6pt;text-align:center;}
    .name-underline{border-bottom:1pt solid #000;min-width:80pt;}
    .mi-cell{max-width:40pt;}
    .name-sub{font-family:Calibri,sans-serif;font-size:10pt;text-align:center;color:#333;padding-top:1pt;}
    .married-note{font-family:'Century Gothic',sans-serif;font-size:10pt;padding-left:6pt;color:#333;white-space:nowrap;}
    .dob-row{font-family:'Century Gothic',sans-serif;font-size:10pt;margin-bottom:3pt;}
    .dob-val{border-bottom:1pt solid #000;min-width:80pt;display:inline-block;padding:0 8pt;}
    .cert-text{font-family:'Century Gothic',sans-serif;font-weight:700;font-size:9.5pt;text-align:justify;margin-bottom:5pt;line-height:1.4;}
    .sr-tbl{width:100%;border-collapse:collapse;font-size:8pt;}
    .grp-hd{background:#d0d0d0;border:1pt solid #000;padding:3pt 4pt;text-align:center;font-family:'Century Gothic',sans-serif;font-weight:700;font-size:8.5pt;}
    .col-hd{background:#e8e8e8;border:1pt solid #000;padding:3pt 4pt;text-align:center;font-family:'Century Gothic',sans-serif;font-weight:700;font-size:8pt;}
    .sr-tbl td{border:1pt solid #000;padding:3pt 4pt;font-family:'Century Gothic',sans-serif;font-size:8pt;min-height:15pt;vertical-align:middle;}
    .footer-cert{font-family:Calibri,sans-serif;font-weight:700;font-size:8.5pt;margin:8pt 0 6pt;line-height:1.4;}
    .sig-tbl{width:100%;border-collapse:collapse;margin-top:4pt;}
    .sig-left{width:55%;vertical-align:top;padding-right:10pt;}
    .sig-right{vertical-align:top;padding-left:10pt;}
    .sig-prep{font-family:'Century Gothic',sans-serif;font-size:10pt;margin-bottom:24pt;}
    .sig-name{font-family:'Century Gothic',sans-serif;font-weight:700;font-size:11pt;border-top:1pt solid #000;padding-top:3pt;}
    .sig-pos{font-family:'Century Gothic',sans-serif;font-size:11pt;}
    .page-num{font-family:'Century Gothic',sans-serif;font-size:10pt;text-align:right;margin-top:6pt;}
    .sr-page{margin-bottom:10pt;}`;

  const w = window.open('','_blank','width=900,height=1100');
  w.document.write(`<!DOCTYPE html><html><head><title>Service Record</title><style>${css}</style></head><body>${pages}</body></html>`);
  w.document.close();
  w.focus();
  setTimeout(() => w.print(), 600);
}



// ── Notification panel ────────────────────────────────────────────────────

// ── Employee notification system (localStorage-persisted) ─────────────────
const EP_NOTIF_KEY = 'hrms_emp_read_notifs';

function getEpReadNotifs() {
  try { return JSON.parse(localStorage.getItem(EP_NOTIF_KEY) || '[]'); }
  catch(e) { return []; }
}
function saveEpReadNotifs(ids) {
  try { localStorage.setItem(EP_NOTIF_KEY, JSON.stringify(ids.slice(-200))); }
  catch(e) {}
}
function applyEpReadState() {
  const read = getEpReadNotifs();
  let unread = 0;
  document.querySelectorAll('.np-item[data-nid]').forEach(el => {
    if (read.includes(el.getAttribute('data-nid'))) {
      el.classList.remove('unread');
    } else {
      unread++;
    }
  });
  const cnt = document.getElementById('epNotifCnt');
  if (cnt) {
    if (unread > 0) { cnt.textContent = unread; cnt.classList.add('show'); }
    else            { cnt.textContent = '';      cnt.classList.remove('show'); }
  }
}

// ── Poll for new notifications every 30 seconds ───────────────────────────


function epFmtTime(dt) {
  if (!dt) return '';
  const d = Math.floor((Date.now() - new Date(dt).getTime()) / 1000);
  if (d < 60)    return 'Just now';
  if (d < 3600)  return Math.floor(d/60) + 'm ago';
  if (d < 86400) return Math.floor(d/3600) + 'h ago';
  return new Date(dt).toLocaleDateString('en-PH',{month:'short',day:'numeric'});
}


// ── Poll for new employee notifications every 30 seconds ────────────────────
async function pollEpNotifications() {
  try {
    const r = await fetch('Employee_portal.php?action=get_notif_count').then(r=>r.json());
    if (!r || !r.success) return;
    const read   = getEpReadNotifs();
    const notifs = r.notifications || [];
    const list   = document.getElementById('epNotifList');
    if (list) {
      if (!notifs.length) {
        list.innerHTML = '<div class="np-empty"><div class="ne-icon">🎉</div><p>All caught up!</p></div>';
      } else {
        list.innerHTML = notifs.map(n => {
          const isRead = read.includes(n.nid);
          return '<div class="np-item '+(isRead?'':' unread')+'" data-nid="'+n.nid+'" onclick="epNotifClick(\''  +n.nid+ '\',\''  +n.page+ '\',this)">'+
            '<div class="np-ico" style="background:'+n.color+'22;">'+n.icon+'</div>'+
            '<div class="np-body"><div class="np-title">'+n.title+'</div>'+
            '<div class="np-text">'+n.body+'</div>'+
            (n.time ? '<div class="np-time">'+epFmtTime(n.time)+'</div>' : '')+
            '</div></div>';
        }).join('');
      }
    }
    const unread = notifs.filter(n => !read.includes(n.nid)).length;
    const cnt = document.getElementById('epNotifCnt');
    if (cnt) {
      if (unread > 0) { cnt.textContent = unread; cnt.classList.add('show'); }
      else            { cnt.textContent = '';     cnt.classList.remove('show'); }
    }
  } catch(e) { console.warn('notif poll:', e); }
}

function epFmtTime(dt) {
  if (!dt) return '';
  try {
    const d = Math.floor((Date.now() - new Date(dt).getTime()) / 1000);
    if (d < 60)    return 'Just now';
    if (d < 3600)  return Math.floor(d/60) + 'm ago';
    if (d < 86400) return Math.floor(d/3600) + 'h ago';
    return new Date(dt).toLocaleDateString('en-PH',{month:'short',day:'numeric'});
  } catch(e){ return ''; }
}

function toggleEpNotif() {
  const p  = document.getElementById('epNotifPanel');
  const dd = document.getElementById('tbDropdown');
  if (dd) dd.classList.remove('open');
  if (p) {
    p.classList.toggle('open');
    if (p.classList.contains('open')) applyEpReadState();
  }
}
function clearEpNotif() {
  const ids = Array.from(document.querySelectorAll('.np-item[data-nid]'))
                   .map(el => el.getAttribute('data-nid'));
  const read = getEpReadNotifs();
  ids.forEach(id => { if (id && !read.includes(id)) read.push(id); });
  saveEpReadNotifs(read);
  document.querySelectorAll('.np-item.unread').forEach(el => el.classList.remove('unread'));
  const cnt = document.getElementById('epNotifCnt');
  if (cnt) { cnt.classList.remove('show'); cnt.textContent = ''; }
  const p = document.getElementById('epNotifPanel');
  if (p) p.classList.remove('open');
}
function epNotifClick(nid, page, el) {
  // Mark this one as read
  const read = getEpReadNotifs();
  if (nid && !read.includes(nid)) { read.push(nid); saveEpReadNotifs(read); }
  el.classList.remove('unread');
  applyEpReadState();
  if (page) {
    const btn = document.querySelector(`.sb-item[onclick*="'${page}'"]`);
    goPage(page, btn);
  }
  const p = document.getElementById('epNotifPanel');
  if (p) p.classList.remove('open');
}
document.addEventListener('click', function(e) {
  const w = document.getElementById('epNotifWrap');
  if (w && !w.contains(e.target)) {
    const p = document.getElementById('epNotifPanel');
    if (p) p.classList.remove('open');
  }
});
document.addEventListener('DOMContentLoaded', applyEpReadState);
function toggleSb() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('mobOverlay').classList.toggle('show');
}

// ════════════════════════════════════════════════════════
//  PROFILE
// ════════════════════════════════════════════════════════
// ── Profile error state ──────────────────────────────────────
function showProfileError(msg) {
  // Hero
  document.getElementById('heroAv').textContent      = '⚠️';
  document.getElementById('heroGreet').textContent   = 'Welcome!';
  document.getElementById('heroSub').textContent     = msg;
  document.getElementById('heroChips').innerHTML     = '<span class="hero-chip" style="background:rgba(239,68,68,.3);">⚠️ Profile unavailable</span>';
  // Sidebar
  document.getElementById('sbPos').textContent       = 'Record not linked';
  // Recent requests fallback
  const tbody = document.getElementById('recentReqTbody');
  if (tbody) tbody.innerHTML = `<tr><td colspan="4" style="text-align:center;padding:24px;color:var(--danger);font-size:.82rem;">⚠️ ${msg}</td></tr>`;
  showToast('⚠️ ' + msg, 'error');
}

async function loadProfile() {
  try {
    const res  = await fetch('Employee_portal.php?action=profile');
    const text = await res.text();

    var json;
    try { json = JSON.parse(text); }
    catch(pe) {
      document.getElementById('heroGreet').textContent = 'Parse Error';
      document.getElementById('heroSub').textContent   = text.substring(0, 200);
      return;
    }

    if (!json.success || !json.data) {
      document.getElementById('heroGreet').textContent = 'Error';
      document.getElementById('heroSub').textContent   = json.message || 'No data';
      return;
    }

    var e = json.data;
    profileData = e;
    window._profileData = e;
    var fullName = (e.first_name || '') + ' ' + (e.last_name || '');
    var initials = fullName.trim().split(' ').map(function(w){return w[0]||'';}).join('').substring(0,2).toUpperCase() || '??';
    var hr = new Date().getHours();
    var greet = hr < 12 ? 'Good morning' : hr < 17 ? 'Good afternoon' : 'Good evening';

    document.getElementById('sbName').textContent    = fullName;
    document.getElementById('sbPos').textContent     = (e.position_title || '') + ' · ' + (e.department_name || '');
    document.getElementById('heroGreet').textContent = greet + ', ' + (e.first_name || '') + '! 👋';
    document.getElementById('heroSub').textContent   = (e.position_title || '') + ' · ' + (e.department_name || '') + ' · ' + (e.employee_code || '');
    document.getElementById('tbSub').textContent     = 'Welcome back, ' + (e.first_name || '') + '!';

    // Helper: set an avatar element to photo or initials
    // Avatar background gradients per element ID
    var AVATAR_BG = {
      'sbAvatar': 'linear-gradient(135deg,#2563eb,#22c55e)',
      'heroAv':   'rgba(255,255,255,.20)',
      'phAv':     'rgba(255,255,255,.20)',
    };
    function setAvatar(el, photoUrl, ini) {
      if (!el) return;
      if (photoUrl) {
        el.innerHTML = '<img src="' + photoUrl + '" alt="Photo" style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;"/>';
        el.style.background = 'transparent';
      } else {
        el.innerHTML = '';
        el.textContent = ini;
        // Restore the original background gradient for this element
        el.style.background = AVATAR_BG[el.id] || 'linear-gradient(135deg,#2563eb,#22c55e)';
      }
    }

    // Apply photo (or initials) to ALL three avatar spots
    setAvatar(document.getElementById('sbAvatar'), e.profile_photo, initials);
    setAvatar(document.getElementById('heroAv'),   e.profile_photo, initials);
    setAvatar(document.getElementById('phAv'),     e.profile_photo, initials);
    document.getElementById('phName').textContent    = fullName;
    document.getElementById('phSub').textContent     = (e.position_title || '') + ' · ' + (e.department_name || '');

    var chips = '<span class="hero-chip">' + (e.employment_type || '—') + '</span>';
    chips    += '<span class="hero-chip">🟢 ' + (e.employment_status || 'Active') + '</span>';
    if (e.date_hired) chips += '<span class="hero-chip">Since ' + (e.date_hired || '').substring(0,10) + '</span>';
    document.getElementById('heroChips').innerHTML = chips;

    var phChips = '<span class="ph-chip">' + (e.employment_type || '—') + '</span>';
    if (e.salary_grade) phChips += '<span class="ph-chip">' + e.salary_grade + '</span>';
    phChips += '<span class="ph-chip">🟢 ' + (e.employment_status || 'Active') + '</span>';
    document.getElementById('phChips').innerHTML = phChips;

    window._profileData = e;
    var personal = [
      {label:'First Name',   key:'first_name',     val:e.first_name,     type:'text'},
      {label:'Last Name',    key:'last_name',       val:e.last_name,      type:'text'},
      {label:'Middle Name',  key:'middle_name',     val:e.middle_name,    type:'text'},
      {label:'Gender',       key:'gender',          val:e.gender,         type:'select', opts:['Male','Female','Other']},
      {label:'Date of Birth',key:'date_of_birth',   val:e.date_of_birth,  type:'date'},
      {label:'Civil Status', key:'civil_status',    val:e.civil_status,   type:'select', opts:['Single','Married','Widowed','Separated','Divorced']},
      {label:'Home Address', key:'home_address',    val:e.home_address,   type:'text',   full:true},
      {label:'Contact No.',  key:'contact_number',  val:e.contact_number, type:'text'},
      {label:'Email',        key:'email_address',   val:e.email_address,  type:'email'}
    ];
    window._personalFields = personal;
    renderPersonalFields(false);

    var employment = [
      ['Employee ID', e.employee_code], ['Department', e.department_name],
      ['Position', e.position_title, true], ['Type', e.employment_type],
      ['Status', e.employment_status], ['Date Hired', e.date_hired],
      ['Salary Grade', e.salary_grade], ['GSIS No.', e.gsis_number]
    ];
    document.getElementById('profileEmployment').innerHTML = employment.map(function(row) {
      return '<div class="field' + (row[2] ? ' full' : '') + '"><label>' + row[0] + '</label><span>' + (row[1] || '—') + '</span></div>';
    }).join('');

  } catch(err) {
    document.getElementById('heroGreet').textContent = 'JS Error: ' + err.message;
    console.error('loadProfile error:', err);
  }
}

// ════════════════════════════════════════════════════════
//  PROFILE EDIT
// ════════════════════════════════════════════════════════
var _profileEditMode = false;

function renderPersonalFields(editMode) {
  var fields = window._personalFields || [];
  document.getElementById('profilePersonal').innerHTML = fields.map(function(f) {
    var val = f.val || '';
    var displayVal = val || '—';
    if (editMode) {
      var inp = '';
      if (f.type === 'select') {
        inp = '<select class="pf-inp" id="pf-' + f.key + '">';
        inp += '<option value="">— Select —</option>';
        f.opts.forEach(function(o) {
          inp += '<option value="' + o + '"' + (o === val ? ' selected' : '') + '>' + o + '</option>';
        });
        inp += '</select>';
      } else {
        var escaped = val.replace(/"/g, '&quot;');
        inp = '<input class="pf-inp" id="pf-' + f.key + '" type="' + f.type + '" value="' + escaped + '"/>';
      }
      return '<div class="field' + (f.full ? ' full' : '') + ' editing"><label>' + f.label + '</label>' + inp + '</div>';
    } else {
      return '<div class="field' + (f.full ? ' full' : '') + '"><label>' + f.label + '</label><span id="pf-' + f.key + '">' + displayVal + '</span></div>';
    }
  }).join('');
}

function toggleEditProfile() {
  if (_profileEditMode) { cancelEditProfile(); return; }
  _profileEditMode = true;
  var btn = document.getElementById('btnEditProfile');
  if (btn) { btn.textContent = '✕ Cancel'; btn.classList.add('editing'); }
  var actions = document.getElementById('profileEditActions');
  if (actions) actions.style.display = 'flex';
  renderPersonalFields(true);
  var first = document.querySelector('#profilePersonal .pf-inp');
  if (first) first.focus();
}

function cancelEditProfile() {
  _profileEditMode = false;
  var btn = document.getElementById('btnEditProfile');
  if (btn) { btn.textContent = '✏️ Edit Info'; btn.classList.remove('editing'); }
  var actions = document.getElementById('profileEditActions');
  if (actions) actions.style.display = 'none';
  renderPersonalFields(false);
}

async function uploadProfilePhoto(input) {
  if (!input.files || !input.files[0]) return;
  var file = input.files[0];
  // Preview immediately
  var phAvEl = document.getElementById('phAv');
  var reader = new FileReader();
  reader.onload = function(ev) {
    var imgHtml = '<img src="' + ev.target.result + '" alt="Photo" style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;"/>';
    phAvEl.innerHTML   = imgHtml;
    var heroAvEl   = document.getElementById('heroAv');
    var sbAvatarEl = document.getElementById('sbAvatar');
    if (heroAvEl)  { heroAvEl.innerHTML   = imgHtml; heroAvEl.style.background   = 'transparent'; }
    if (sbAvatarEl){ sbAvatarEl.innerHTML = imgHtml; sbAvatarEl.style.background  = 'transparent'; }
  };
  reader.readAsDataURL(file);
  if (file.size > 10 * 1024 * 1024) { showToast('❌ Photo must be under 10MB.', 'error'); input.value=''; return; }
  var fd = new FormData();
  fd.append('action', 'upload_photo');
  fd.append('photo', file);
  showToast('📷 Uploading photo…', 'info');
  try {
    fd.append('csrf_token', CSRF_TOKEN);
    var res = await fetch('Employee_portal.php', {method:'POST', body:fd});
    var j   = await res.json();
    if (j.success) {
      showToast('✅ Profile photo updated!', 'success');
      // Update all three avatar spots with the new photo
      var photoUrl = j.photo_url || j.url || '';
      if (photoUrl) {
        var imgHtml = '<img src="' + photoUrl + '" alt="Photo" style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;"/>';
        document.getElementById('phAv').innerHTML    = imgHtml;
        document.getElementById('heroAv').innerHTML  = imgHtml;
        document.getElementById('sbAvatar').innerHTML = imgHtml;
        document.getElementById('heroAv').style.background  = 'transparent';
        document.getElementById('sbAvatar').style.background = 'transparent';
        // Store in profileData for future use
        if (window._profileData) window._profileData.profile_photo = photoUrl;
        // Refresh chat contacts to show updated profile photo
        if (typeof cwLoadContacts === 'function') cwLoadContacts();
      }
    } else {
      showToast('❌ ' + (j.message || 'Upload failed.'), 'error');
      // Revert avatar to initials with correct bg gradients
      var ep = window._profileData || {};
      var fn2 = ((ep.first_name||'') + ' ' + (ep.last_name||'')).trim();
      var ini2 = fn2.split(' ').map(function(w){return w[0]||'';}).join('').substring(0,2).toUpperCase() || '??';
      phAvEl.innerHTML = ''; phAvEl.textContent = ini2; phAvEl.style.background = 'rgba(255,255,255,.20)';
      var heroAvEl2r   = document.getElementById('heroAv');
      var sbAvatarEl2r = document.getElementById('sbAvatar');
      if (heroAvEl2r)  { heroAvEl2r.innerHTML  = ''; heroAvEl2r.textContent  = ini2; heroAvEl2r.style.background  = 'rgba(255,255,255,.20)'; }
      if (sbAvatarEl2r){ sbAvatarEl2r.innerHTML = ''; sbAvatarEl2r.textContent = ini2; sbAvatarEl2r.style.background = 'linear-gradient(135deg,#2563eb,#22c55e)'; }
    }
  } catch(err) {
    showToast('❌ Network error during upload.', 'error');
  }
  input.value = ''; // Reset so same file can be re-selected
}

async function saveProfileChanges() {
  var fields = window._personalFields || [];
  var fd = new FormData();
  fd.append('action', 'update_personal');
  var hasChanges = false;
  fields.forEach(function(f) {
    var inp = document.getElementById('pf-' + f.key);
    if (inp) {
      fd.append(f.key, inp.value.trim());
      if (inp.value.trim() !== (f.val || '')) hasChanges = true;
      f.val = inp.value.trim();
    }
  });
  var saveBtn = document.getElementById('piSaveBtn');
  if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = '⏳ Saving…'; }
  try {
    fd.append('csrf_token', CSRF_TOKEN);
    var res = await fetch('Employee_portal.php', {method:'POST', body:fd});
    var j   = await res.json();
    if (j.success) {
      showToast('✅ Personal information updated!', 'success');
      cancelEditProfile();
      // Re-render hero with new name
      var e = window._profileData || {};
      fields.forEach(function(f) { e[f.key] = f.val; });
      window._profileData = e;
      var fullName = ((e.first_name||'') + ' ' + (e.last_name||'')).trim();
      document.getElementById('phName').textContent = fullName;
      document.getElementById('sbName').textContent = fullName;
      var hr = new Date().getHours();
      document.getElementById('heroGreet').textContent = 'Good ' + (hr<12?'morning':hr<17?'afternoon':'evening') + ', ' + (e.first_name||'') + '! 👋';
      var ini = fullName.split(' ').map(function(w){return w[0]||'';}).join('').substring(0,2).toUpperCase();
      // Only update phAv text if no photo is set
      var phAvEl = document.getElementById('phAv');
      if (!e.profile_photo && !phAvEl.querySelector('img')) { phAvEl.textContent = ini; }
      // heroAv and sbAvatar: preserve photo if set, else update initials
      var heroAvEl  = document.getElementById('heroAv');
      var sbAvatarEl = document.getElementById('sbAvatar');
      if (e.profile_photo) {
        var imgHtml = '<img src="' + e.profile_photo + '" alt="Photo" style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;"/>';
        if (heroAvEl)  { heroAvEl.innerHTML  = imgHtml; heroAvEl.style.background  = 'transparent'; }
        if (sbAvatarEl){ sbAvatarEl.innerHTML = imgHtml; sbAvatarEl.style.background = 'transparent'; }
      } else {
        if (heroAvEl)  { heroAvEl.textContent  = ini; heroAvEl.style.background  = ''; }
        if (sbAvatarEl){ sbAvatarEl.textContent = ini; sbAvatarEl.style.background = ''; }
      }
    } else {
      showToast('❌ ' + (j.message||'Update failed.'), 'error');
    }
  } catch(err) {
    showToast('❌ Network error. Please try again.', 'error');
  } finally {
    if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = '💾 Save Changes'; }
  }
}

// ════════════════════════════════════════════════════════
//  BALANCES
// ════════════════════════════════════════════════════════
async function loadBalances() {
  try {
    const res  = await fetch('Employee_portal.php?action=balances');
    const text = await res.text();
    let json;
    try { json = JSON.parse(text); } catch(e) { console.error('Balances: bad JSON', text.substring(0,200)); return; }
    if (!json.success) return;
    balanceData = json.data;
  } catch(e) {}
}

function renderBalancesPage() {
  const pillColors = ['#2563eb','#f59e0b','#ef4444','#22c55e','#a855f7','#06b6d4'];
  const grid = document.getElementById('balancesGrid');
  if (!balanceData.length) { grid.innerHTML='<p style="color:var(--g400);">No leave balances found.</p>'; return; }
  grid.innerHTML = balanceData.map((b,i)=>{
    const c=pillColors[i%pillColors.length];
    const pct=b.total_credits>0?Math.round((b.used_days/b.total_credits)*100):0;
    const icon=TYPE_ICONS[b.type_name]||'📅';
    const isLocked = !!b.disabled_reason;
    const pending = parseFloat(b.pending_days) || 0;
    const available = parseFloat(b.available_after_pending) || 0;
    const pendingBanner = pending > 0 ? `<div style="background:#dbeafe;border:1px solid #bfdbfe;border-radius:8px;padding:7px 12px;font-size:.73rem;font-weight:700;color:#1e40af;margin-bottom:8px;">⏳ ${pending} pending • ✓ ${available} available</div>` : '';
    const histHtml=b.history&&b.history.length?b.history.map(h=>`<div class="hist-row"><span style="font-weight:700;">${b.type_name} (${h.days_requested} day${h.days_requested!=1?'s':''})</span><span class="hist-date">${fmt(h.date_from)}${h.date_from!==h.date_to?' – '+fmt(h.date_to):''}</span></div>`).join(''):`<div class="hist-none">No ${b.type_name.toLowerCase()} leaves used this year.</div>`;
    const lockBanner = isLocked ? `<div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:7px 12px;font-size:.73rem;font-weight:700;color:#92400e;margin-bottom:8px;">🔒 ${b.disabled_reason}</div>` : '';
    const cardStyle = isLocked ? 'opacity:.6;filter:grayscale(.6);' : '';
    return `<div class="bal-card" style="animation-delay:${i*.05}s;${cardStyle}">${lockBanner}${pendingBanner}<div class="bc-top"><div><div class="bc-title">${isLocked?'🔒':icon} ${b.type_name}</div><div class="bc-sub">CY <?= date('Y') ?> Credits</div></div><div class="bc-icon" style="background:${c}22;">${icon}</div></div><div class="bc-nums"><div class="bc-nb"><div class="bc-n" style="color:var(--primary);">${b.total_credits}</div><div class="bc-nl">Total</div></div><div class="bc-nb"><div class="bc-n" style="color:var(--warn);">${b.used_days}</div><div class="bc-nl">Used</div></div><div class="bc-nb"><div class="bc-n" style="color:var(--accent);">${b.remaining_days}</div><div class="bc-nl">Left</div></div></div><div class="bc-bar"><div class="bc-fill" style="width:${pct}%;background:${c};"></div></div><div class="hist"><div class="hist-label">Recent Usage</div>${histHtml}</div></div>`;
  }).join('');
  const stMap={ok:'<span class="badge b-approved">✅ OK</span>',low:'<span class="badge b-pending">⚠️ Low</span>',empty:'<span class="badge b-denied">❌ Empty</span>',locked:'<span class="badge" style="background:#fef3c7;color:#92400e;">🔒 Locked</span>'};
  document.getElementById('balSummary').innerHTML=balanceData.map(b=>{
    const pending = parseFloat(b.pending_days) || 0;
    const available = parseFloat(b.available_after_pending) || 0;
    if(b.disabled_reason) return `<tr style="opacity:.6;"><td><span class="badge" style="background:#fef3c7;color:#92400e;">🔒 ${b.type_name}</span></td><td>${b.total_credits}</td><td>${b.used_days}</td><td style="font-weight:800;color:var(--g400);">${b.remaining_days}</td><td>${stMap.locked}</td></tr>`;
    const st=available===0?'empty':available<=2?'low':'ok';
    const pendingCol = pending > 0 ? ` (⏳ ${pending} pending)` : '';
    return`<tr><td><span class="badge" style="background:${(TYPE_COLORS[b.type_name]||'#2563eb')}22;color:${TYPE_COLORS[b.type_name]||'#2563eb'};">${b.type_name}</span></td><td>${b.total_credits}</td><td>${b.used_days}</td><td style="font-weight:800;color:var(--accent);">${available}${pendingCol}</td><td>${stMap[st]}</td></tr>`;
  }).join('');
}

// ════════════════════════════════════════════════════════
//  LEAVE TYPES
// ════════════════════════════════════════════════════════
/* ── Load right-panel data when file-leave tab opens ───────── */
async function loadFileLeavePanel() {
  // Load balances into right panel
  try {
    var res  = await fetch('Employee_portal.php?action=balances');
    var json = await res.json();
    if (json.success) renderFlBalances(json.data);
    else console.warn('Failed to load balances:', json.message);
  } catch(e) { console.error('Error loading balances:', e); }

  // Load recent requests into right panel
  try {
    var res2  = await fetch('Employee_portal.php?action=requests&page=1');
    var json2 = await res2.json();
    if (json2.success) renderFlRecent(json2.data);
    else console.warn('Failed to load requests:', json2.message);
  } catch(e) { console.error('Error loading requests:', e); }

  // Reset calendar preview
  renderCalPreview(null, null, 0);
}

async function loadLeaveTypes() {
  try {
    const res  = await fetch('Employee_portal.php?action=leave_types');
    const json = await res.json();
    if (!json.success) return;
    leaveTypes = json.data;
    renderLeaveTypePicker();
  } catch(e) {}
}

function renderLeaveTypePicker() {
  const grid = document.getElementById('ltGrid');
  if (!leaveTypes.length) { grid.innerHTML='<p style="color:var(--g400);">No leave types available for your profile.</p>'; return; }

  // Three buckets:
  // 1. available  — not marriage-locked, has remaining days
  // 2. consumed   — not marriage-locked, but remaining_days === 0
  // 3. locked     — requires married status but employee is single/etc.
  const available = leaveTypes.filter(lt => !lt.disabled_reason && parseInt(lt.remaining_days) > 0);
  const consumed  = leaveTypes.filter(lt => !lt.disabled_reason && parseInt(lt.remaining_days) <= 0);
  const locked    = leaveTypes.filter(lt =>  lt.disabled_reason);

  let firstEnabled = null;
  let html = '';

  // ── 1. Available leave types (fully clickable) ──────────────
  available.forEach((lt, i) => {
    const icon = TYPE_ICONS[lt.name] || '📅';
    if (i === 0) {
      firstEnabled = lt;
      selTypeId   = lt.leave_type_id;
      selTypeName = lt.name;
      selTypeBal  = lt.remaining_days;
      updateDetailSection(lt.name);
    }
    html += `<div class="lt-opt${i===0?' sel':''}" data-id="${lt.leave_type_id}" data-name="${lt.name}" data-bal="${lt.remaining_days}" onclick="pickLT(this)">
      <div class="lt-icon">${icon}</div>
      <div class="lt-name">${lt.name}</div>
      <div class="lt-avail">${lt.remaining_days} day${lt.remaining_days!=1?'s':''} available</div>
    </div>`;
  });

  // ── 2. Consumed leave types (all days used up) ───────────────
  if (consumed.length) {
    html += `<div style="grid-column:1/-1;font-size:.72rem;font-weight:700;color:var(--g400);letter-spacing:.04em;padding:8px 2px 2px;border-top:1px dashed var(--g200);margin-top:4px;">⛔ FULLY CONSUMED — No remaining days</div>`;
    consumed.forEach(lt => {
      const icon = TYPE_ICONS[lt.name] || '📅';
      html += `<div class="lt-opt lt-opt-disabled" title="No remaining days for ${lt.name}" style="cursor:not-allowed;opacity:.4;filter:grayscale(1);pointer-events:none;">
        <div class="lt-icon" style="font-size:1.1rem;">⛔</div>
        <div class="lt-name" style="color:var(--g400);">${lt.name}</div>
        <div class="lt-avail" style="color:#ef4444;font-size:.68rem;font-weight:700;">0 days remaining</div>
      </div>`;
    });
  }

  // ── 3. Marriage-locked leave types ───────────────────────────
  if (locked.length) {
    html += `<div style="grid-column:1/-1;font-size:.72rem;font-weight:700;color:var(--g400);letter-spacing:.04em;padding:8px 2px 2px;border-top:1px dashed var(--g200);margin-top:4px;">🔒 NOT AVAILABLE — Requires Married Status</div>`;
    locked.forEach(lt => {
      const icon = TYPE_ICONS[lt.name] || '📅';
      html += `<div class="lt-opt lt-opt-disabled" title="${lt.disabled_reason}" style="cursor:not-allowed;opacity:.45;filter:grayscale(1);pointer-events:none;">
        <div class="lt-icon" style="font-size:1.1rem;">🔒</div>
        <div class="lt-name" style="color:var(--g400);">${lt.name}</div>
        <div class="lt-avail" style="color:var(--g400);font-size:.68rem;">${lt.disabled_reason}</div>
      </div>`;
    });
  }

  grid.innerHTML = html;

  if (firstEnabled) {
    document.getElementById('daysBal').textContent = `${selTypeBal} days available for ${selTypeName} Leave`;
  } else {
    // No available types — clear selection so submit is blocked
    selTypeId = null; selTypeName = ''; selTypeBal = 0;
    document.getElementById('daysBal').textContent = 'No leave types currently available';
    updateDetailSection('');
  }
}

// ════════════════════════════════════════════════════════
//  RECENT REQUESTS
// ════════════════════════════════════════════════════════
async function loadRecentRequests() {
  try {
    const res  = await fetch('Employee_portal.php?action=requests&status=all');
    const text = await res.text();
    let json;
    try { json = JSON.parse(text); } catch(e) { console.error('Requests: bad JSON', text.substring(0,200)); return; }
    if (!json.success) return;
    const pending = json.counts.Pending||0;
    
    // Only update badge if element exists
    const badge   = document.getElementById('pendBadge');
    if (badge) { badge.textContent=pending; badge.style.display=pending?'inline-block':'none'; }
    
    // Only update notif dot if element exists
    const nd=document.getElementById('notifDot');
    if (nd) { nd.textContent=pending; nd.style.display=pending?'flex':'none'; }
    
    // Only update table if element exists
    const tbody=document.getElementById('recentReqTbody');
    if (!tbody) return; // Element doesn't exist on this page
    
    const recent=json.data.slice(0,3);
    if(!recent.length){tbody.innerHTML='<tr><td colspan="4" style="text-align:center;padding:24px;color:var(--g400);">No requests yet.</td></tr>';return;}
    tbody.innerHTML=recent.map(r=>`<tr onclick="goPage('requests',document.querySelectorAll('.sb-item')[2])"><td><span class="badge" style="background:${(TYPE_COLORS[r.type_name]||'#2563eb')}22;color:${TYPE_COLORS[r.type_name]||'#2563eb'};">${r.type_name}</span></td><td style="font-size:.77rem;color:var(--g600);">${fmt(r.date_from)}${r.date_from!==r.date_to?' – '+fmt(r.date_to):''}</td><td style="font-weight:800;">${r.days_requested}</td><td><span class="badge ${ST_CLS[r.status]||'b-pending'}">${ST_ICO[r.status]||''} ${r.status}</span></td></tr>`).join('');
  } catch(e) {
    console.error('Recent requests error', e);
    const tbody = document.getElementById('recentReqTbody');
    if (tbody) tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:20px;color:var(--g400);">Could not load requests.</td></tr>';
  }
}

// ════════════════════════════════════════════════════════
//  ANNOUNCEMENTS
// ════════════════════════════════════════════════════════
async function loadAnnouncements() {
  try {
    const res  = await fetch('Employee_portal.php?action=announcements');
    const text = await res.text();
    let json;
    try { json = JSON.parse(text); } catch(e) { console.error('Announcements: bad JSON', text.substring(0,200)); return; }
    const list = document.getElementById('annList');
    if(!json.success||!json.data.length){list.innerHTML='<div style="padding:16px 18px;color:var(--g400);font-size:.82rem;">No announcements.</div>';return;}
    const dotColors={'General':'#2563eb','HR Notice':'#1e40af','Policy':'#0369a1','Event':'#7c3aed','Holiday':'#ec4899','Urgent':'#dc2626','Normal':'#22c55e','High':'#f59e0b'};
    list.innerHTML=json.data.map(a=>`<div class="ann-item"><div class="ann-dot" style="background:${dotColors[a.tag]||'#2563eb'};margin-top:5px;"></div><div><div class="ann-strong"><span class="ann-tag ${a.tag}" style="background:${dotColors[a.tag]||'#e0e7ff'};color:#1e3a8a;padding:3px 8px;border-radius:4px;font-size:.70rem;font-weight:700;">${a.tag}</span>&nbsp;${a.title}</div><div class="ann-p">${a.body}</div><small class="ann-small">${fmt(a.created_at)}</small></div></div>`).join('');
  } catch(e) {}
}

// ════════════════════════════════════════════════════════
//  LEAVE REQUESTS PAGE
// ════════════════════════════════════════════════════════
async function loadRequests() {
  try {
    const res  = await fetch(`Employee_portal.php?action=requests&status=${curReqTab}`);
    const json = await res.json();
    if (!json.success) {
      showToast('⚠️ ' + (json.message || 'Could not load requests.'), 'error');
      const list=document.getElementById('reqList'); const empty=document.getElementById('reqEmpty');
      list.innerHTML='';
      empty.style.display='block';
      empty.innerHTML='<div class="ei">⚠️</div><p>Could not load requests: ' + (json.message || 'Unknown error') + '</p>';
      return;
    }
    const all=(json.counts.Pending||0)+(json.counts.Approved||0)+(json.counts.Denied||0)+(json.counts.Cancelled||0);
    document.getElementById('tc-all').textContent=all;
    document.getElementById('tc-pending').textContent=json.counts.Pending||0;
    document.getElementById('tc-approved').textContent=json.counts.Approved||0;
    document.getElementById('tc-denied').textContent=json.counts.Denied||0;
    document.getElementById('tc-cancelled').textContent=json.counts.Cancelled||0;
    const pending=json.counts.Pending||0;
    const badge=document.getElementById('pendBadge'); badge.textContent=pending; badge.style.display=pending?'inline-block':'none';
    const list=document.getElementById('reqList'); const empty=document.getElementById('reqEmpty');
    if(!json.data.length){list.innerHTML='';empty.style.display='block';return;}
    empty.style.display='none';
    list.innerHTML=json.data.map(r=>{
      const remarkCls=r.status==='Approved'?'approved':r.status==='Denied'?'denied':'pending';
      const remarkHtml=r.admin_remarks?`<div class="req-remark ${remarkCls}">💬 HR NOTE:  ${r.admin_remarks}</div>`:'';
      const cancelHtml=r.status==='Pending'?`<button class="cancel-btn" onclick="cancelReq(${r.request_id})">Cancel</button>`:'';
      const color=TYPE_COLORS[r.type_name]||'#2563eb';
      return `<div class="req-card"><div class="req-stripe" style="background:${color};"></div><div class="req-body"><div class="req-top"><div class="req-title"><span class="badge" style="background:${color}22;color:${color};">${r.type_name} Leave</span></div><span class="badge ${ST_CLS[r.status]||'b-pending'}">${ST_ICO[r.status]||''} ${r.status}</span></div><div class="req-meta"><span>📅 ${fmt(r.date_from)} – ${fmt(r.date_to)}</span><span>📆 <strong>${r.days_requested} day${r.days_requested!=1?'s':''}</strong></span></div><div class="req-reason">Reason of Leave: ${r.reason}</div>${remarkHtml}<div class="req-footer"><span class="req-filed">Filed: ${fmt(r.filed_at)}</span>${cancelHtml}</div></div></div>`;
    }).join('');
  } catch(e) {
    showToast('❌ Could not load requests. Network error.', 'error');
    const list=document.getElementById('reqList'); const empty=document.getElementById('reqEmpty');
    list.innerHTML='';
    empty.style.display='block';
    empty.innerHTML='<div class="ei">❌</div><p>Could not load requests. Please check your connection and try again.</p>';
    console.error('loadRequests error:', e);
  }
}

function filterReqs(tab,btn){curReqTab=tab;document.querySelectorAll('.req-tab').forEach(b=>b.classList.remove('active'));btn.classList.add('active');loadRequests();}

async function cancelReq(id) {
  if(!confirm('Cancel this leave request?')) return;
  const body=new FormData(); body.append('action','cancel_leave'); body.append('request_id',id);
  const res=await fetch('Employee_portal.php',{method:'POST',body});
  const json=await res.json();
  if(json.success){showToast('Leave request cancelled.','info');loadRequests();loadRecentRequests();}
  else showToast('❌ '+json.message,'error');
}

// ════════════════════════════════════════════════════════
//  FILE LEAVE
// ════════════════════════════════════════════════════════
function pickLT(el){document.querySelectorAll('.lt-opt').forEach(c=>c.classList.remove('sel'));el.classList.add('sel');selTypeId=parseInt(el.dataset.id);selTypeName=el.dataset.name;selTypeBal=parseInt(el.dataset.bal);document.getElementById('daysBal').textContent=`${selTypeBal} days available for ${selTypeName} Leave`;calcDays();updateDetailSection(selTypeName);}
function calcDays() {
  var f = document.getElementById('flFrom').value;
  var t = document.getElementById('flTo').value;
  if (f && t) {
    var diff = Math.max(0, Math.round((new Date(t) - new Date(f)) / 864e5) + 1);
    document.getElementById('daysNum').textContent = diff;
    renderCalPreview(f, t, diff);
  } else {
    document.getElementById('daysNum').textContent = '0';
    renderCalPreview(null, null, 0);
  }
}

/* ── Populate right-panel balance list ─────────────────────── */
function renderFlBalances(balances) {
  var el = document.getElementById('flBalList');
  if (!el) return;
  if (!balances || !balances.length) {
    el.innerHTML = '<div style="color:var(--g400);font-size:.82rem;padding:8px 0;">No balances found.</div>';
    return;
  }
  el.innerHTML = balances.map(function(b) {
    var rem   = parseFloat(b.remaining_days) || 0;
    var pending = parseFloat(b.pending_days) || 0;
    var available = parseFloat(b.available_after_pending) || 0;
    var total = parseFloat(b.total_days)     || parseFloat(b.max_days) || 1;
    var pct   = Math.min(100, Math.round((rem / total) * 100));
    var low   = pct <= 25 ? ' low' : '';
    var pendingInfo = pending > 0 
      ? '<div style="margin-top:4px;font-size:.70rem;color:var(--g500);">⏳ <strong>' + pending + '</strong> pending • ✓ <strong>' + available + '</strong> available</div>'
      : '';
    return '<div class="fl-bal-row">' +
      '<div style="flex:1;min-width:0;">' +
        '<div class="fl-bal-name">🏷️ ' + escHtml(b.type_name) + '</div>' +
        '<div class="fl-bal-bar"><div class="fl-bal-fill' + low + '" style="width:' + pct + '%"></div></div>' +
        pendingInfo +
      '</div>' +
      '<div class="fl-bal-right">' +
        '<div class="fl-bal-days">' + rem + '</div>' +
        '<div class="fl-bal-sub">of ' + total + ' days</div>' +
      '</div>' +
    '</div>';
  }).join('');
}

/* ── Render calendar date-range preview ─────────────────────── */
function renderCalPreview(from, to, totalDays) {
  var el = document.getElementById('flCalPreview');
  if (!el) return;
  if (!from || !to || totalDays <= 0) {
    el.innerHTML = '<div class="fl-cal-empty">Pick dates on the form to preview your leave period.</div>';
    return;
  }
  var start   = new Date(from);
  var end     = new Date(to);
  var today   = new Date(); today.setHours(0,0,0,0);
  var days    = [];
  var cur     = new Date(start);
  var weekdayCount = 0;
  while (cur <= end) {
    var wd = cur.getDay();
    var isWeekend = (wd === 0 || wd === 6);
    if (!isWeekend) weekdayCount++;
    days.push({ d: new Date(cur), weekend: isWeekend, isToday: cur.getTime() === today.getTime() });
    cur.setDate(cur.getDate() + 1);
    if (days.length > 28) { days.push(null); break; } // cap display
  }
  var opts  = { month: 'short', day: 'numeric' };
  var range = start.toLocaleDateString('en-PH', opts) + ' → ' + end.toLocaleDateString('en-PH', opts);
  var strip = days.map(function(d) {
    if (!d) return '<div style="font-size:.65rem;color:var(--g400);">+more</div>';
    var cls = d.weekend ? 'weekend' : 'in-range';
    if (d.isToday) cls += ' today';
    return '<div class="fl-cal-day ' + cls + '">' + d.d.getDate() + '</div>';
  }).join('');
  el.innerHTML =
    '<div class="fl-cal-box">' +
      '<div class="fl-cal-range">' + range + '</div>' +
      '<div class="fl-cal-days-strip">' + strip + '</div>' +
      '<div class="fl-cal-summary">' + totalDays + ' calendar day' + (totalDays!==1?'s':'') +
        (weekdayCount !== totalDays ? ' &nbsp;·&nbsp; ' + weekdayCount + ' working day' + (weekdayCount!==1?'s':'') : '') +
      '</div>' +
    '</div>';
}

/* ── Render recent requests in right panel ──────────────────── */
function renderFlRecent(requests) {
  var el = document.getElementById('flRecentList');
  if (!el) return;
  var recent = (requests || []).slice(0, 4);
  if (!recent.length) {
    el.innerHTML = '<div style="color:var(--g400);font-size:.82rem;padding:8px 0;">No leave requests yet.</div>';
    return;
  }
  var dotColor = { 'Pending':'#f59e0b', 'Approved':'#22c55e', 'Rejected':'#ef4444', 'Cancelled':'#94a3b8' };
  el.innerHTML = recent.map(function(r) {
    var col = dotColor[r.status] || '#94a3b8';
    var badgeCls = (r.status||'').toLowerCase();
    var fmtDate = function(d) { return d ? new Date(d).toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}) : '—'; };
    return '<div class="fl-req-item">' +
      '<div class="fl-req-dot" style="background:' + col + '"></div>' +
      '<div class="fl-req-info">' +
        '<div class="fl-req-type">' + escHtml(r.leave_type||r.type_name||'Leave') + '</div>' +
        '<div class="fl-req-dates">' + fmtDate(r.date_from) + ' – ' + fmtDate(r.date_to) + ' (' + (r.days_requested||0) + 'd)</div>' +
      '</div>' +
      '<div class="fl-req-badge ' + badgeCls + '">' + escHtml(r.status) + '</div>' +
    '</div>';
  }).join('');
}

/* ── Show/hide 6B detail panels based on selected leave type ── */
function updateDetailSection(typeName) {
  var name = (typeName || '').toLowerCase();
  var sections = ['vacation','sick','women','study','other','others'];
  sections.forEach(function(s) {
    var el = document.getElementById('detail-' + s);
    if (el) el.classList.remove('show');
  });
  if (name.indexOf('vacation') >= 0 || name.indexOf('privilege') >= 0) {
    var el = document.getElementById('detail-vacation');
    if (el) el.classList.add('show');
  } else if (name.indexOf('sick') >= 0) {
    var el = document.getElementById('detail-sick');
    if (el) el.classList.add('show');
  } else if (name.indexOf('special leave') >= 0 || name.indexOf('women') >= 0 || name.indexOf('benefit') >= 0) {
    var el = document.getElementById('detail-women');
    if (el) el.classList.add('show');
  } else if (name.indexOf('study') >= 0) {
    var el = document.getElementById('detail-study');
    if (el) el.classList.add('show');
  } else if (name.indexOf('monetization') >= 0 || name.indexOf('terminal') >= 0) {
    var el = document.getElementById('detail-other');
    if (el) el.classList.add('show');
  } else if (name.indexOf('others') >= 0) {
    var el = document.getElementById('detail-others');
    if (el) el.classList.add('show');
    // Auto-check the Others checkbox when Others leave type is selected
    var chk = document.getElementById('others_leave_confirm');
    if (chk) chk.checked = true;
  } else {
    // Uncheck the Others checkbox when other leave types are selected
    var chk = document.getElementById('others_leave_confirm');
    if (chk) chk.checked = false;
  }
}

/* Show/hide abroad text input */
document.addEventListener('DOMContentLoaded', function() {
  var abroadRadios = document.querySelectorAll('input[name="vac_location"]');
  abroadRadios.forEach(function(r) {
    r.addEventListener('change', function() {
      var extra = document.getElementById('vac_abroad_detail');
      if (extra) extra.style.display = (this.value === 'Abroad') ? 'block' : 'none';
    });
  });
});

/* ── Build detail string to append to reason ───────────────── */
function getLeaveDetail() {
  var name = (selTypeName || '').toLowerCase();
  if (name.indexOf('vacation') >= 0 || name.indexOf('privilege') >= 0) {
    var loc = document.querySelector('input[name="vac_location"]:checked');
    var val = loc ? loc.value : 'Within the Philippines';
    if (val === 'Abroad') {
      var spec = (document.getElementById('vac_abroad_spec').value || '').trim();
      return 'Location: Abroad' + (spec ? ' (' + spec + ')' : '');
    }
    return 'Location: Within the Philippines';
  }
  if (name.indexOf('sick') >= 0) {
    var t = document.querySelector('input[name="sick_type"]:checked');
    var illness = (document.getElementById('sick_illness').value || '').trim();
    return (t ? t.value : 'In Hospital') + (illness ? ' — ' + illness : '');
  }
  if (name.indexOf('special leave') >= 0 || name.indexOf('women') >= 0 || name.indexOf('benefit') >= 0) {
    var ill = (document.getElementById('women_illness').value || '').trim();
    return ill ? 'Illness: ' + ill : '';
  }
  if (name.indexOf('study') >= 0) {
    var p = document.querySelector('input[name="study_purpose"]:checked');
    return p ? p.value : '';
  }
  if (name.indexOf('monetization') >= 0 || name.indexOf('terminal') >= 0) {
    var op = document.querySelector('input[name="other_purpose"]:checked');
    return op ? op.value : '';
  }
  if (name.indexOf('others') >= 0) {
    var spec = (document.getElementById('others_leave_spec').value || '').trim();
    var chk = document.getElementById('others_leave_confirm');
    var checked = chk && chk.checked ? ' ☑' : '';
    return spec ? 'Leave Type: ' + spec + checked : '';
  }
  return '';
}

async function submitLeave() {
  const from=document.getElementById('flFrom').value;const to=document.getElementById('flTo').value;const reason=document.getElementById('flReason').value.trim();const days=parseInt(document.getElementById('daysNum').textContent)||0;
  let ok=true;
  document.getElementById('flFrom').classList.toggle('err',!from); if(!from)ok=false;
  document.getElementById('flTo').classList.toggle('err',!to);if(!to)ok=false;
  document.getElementById('flReason').classList.toggle('err',!reason);if(!reason)ok=false;
  // If "Others" leave type, check if specification is provided
  if(selTypeName && selTypeName.toLowerCase().indexOf('others') >= 0) {
    const spec = (document.getElementById('others_leave_spec').value || '').trim();
    const elem = document.getElementById('others_leave_spec');
    if (!spec) {
      if (elem) elem.classList.add('err');
      ok = false;
    } else if (elem) {
      elem.classList.remove('err');
    }
  }
  if(!ok){showToast('⚠️ Please complete all required fields.','info');return;}
  if(days<1){showToast('⚠️ Date To must be on or after Date From.','info');return;}
  
  // Check if dates are in the past (cannot file leave for past dates)
  const today = new Date();
  today.setHours(0,0,0,0);
  const fromDate = new Date(from);
  fromDate.setHours(0,0,0,0);
  if (fromDate < today) {
    showToast('🚫 Cannot apply for leave on past dates. Please select today or a future date.','error');
    document.getElementById('flFrom').classList.add('err');
    return;
  }
  
  if(days>selTypeBal){showToast(`⚠️ ${days} days exceeds your ${selTypeBal}-day ${selTypeName} balance.`,'error');return;}
  if(!selTypeId){showToast('⚠️ Please select a leave type.','info');return;}
  // Check if selected type is disabled (marriage-restricted)
  const selType = leaveTypes.find(lt => lt.leave_type_id == selTypeId);
  if(selType && selType.disabled_reason){showToast('🔒 ' + selType.disabled_reason,'error');return;}
  // Check if selected type is fully consumed
  if(selType && parseInt(selType.remaining_days) <= 0){showToast('⛔ You have no remaining days for ' + selTypeName + ' this year.','error');return;}
  // Combine detail + reason
  var detail = getLeaveDetail();
  var fullReason = detail ? '[' + detail + '] ' + reason : reason;
  const body=new FormData();body.append('action','submit_leave');body.append('csrf_token',CSRF_TOKEN);body.append('leave_type_id',selTypeId);body.append('date_from',from);body.append('date_to',to);body.append('days_requested',days);body.append('reason',fullReason);
  try {
    const res=await fetch('Employee_portal.php',{method:'POST',body});const json=await res.json();
    if(json.success){showToast('✅ Leave request submitted!','success');document.getElementById('flFrom').value='';document.getElementById('flTo').value='';document.getElementById('flReason').value='';document.getElementById('others_leave_spec').value='';document.getElementById('daysNum').textContent='0';['flFrom','flTo','flReason','others_leave_spec'].forEach(id=>document.getElementById(id).classList.remove('err'));updateDetailSection('');await loadBalances();await loadLeaveTypes();await loadRecentRequests();setTimeout(()=>goPage('requests',document.querySelectorAll('.sb-item')[2]),900);}
    else showToast('❌ '+json.message,'error');
  } catch(e){showToast('❌ Failed.','error');}
}

// ════════════════════════════════════════════════════════
//  DOCUMENTS PAGE
// ════════════════════════════════════════════════════════
async function loadDocuments() {
  const search = document.getElementById('docSearch').value.trim();
  const type   = document.getElementById('docTypeFilter').value;
  const params = new URLSearchParams({ action:'my_documents', search, type });
  const grid   = document.getElementById('docGrid');
  grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--g400);font-size:.875rem;">Loading…</div>';
  try {
    const res  = await fetch(`Employee_portal.php?${params}`);
    const json = await res.json();
    if (!json.success) { showToast('⚠️ '+json.message,'error'); return; }

    // Stats
    const s = json.stats;
    document.getElementById('ds-total').textContent = s.total || 0;
    document.getElementById('ds-types').textContent = s.types || 0;
    document.getElementById('ds-size').textContent  = fmtSize(s.total_size||0);
    document.getElementById('docPanelSub').textContent = `${json.data.length} document(s) on file`;

    if (!json.data.length) {
      grid.innerHTML = `<div style="grid-column:1/-1;text-align:center;padding:52px 20px;color:var(--g400);"><div style="font-size:2.8rem;margin-bottom:12px;opacity:.4;">📁</div><p style="font-size:.875rem;font-weight:600;">No documents found.<br>Click <strong>Upload Document</strong> to add your first file.</p></div>`;
      return;
    }

    grid.innerHTML = json.data.map((d, i) => {
      const ext      = d.file_name.split('.').pop().toLowerCase();
      const icon     = FILE_ICONS[ext] || '📎';
      const dc       = DOC_COLORS[d.document_type] || DOC_COLORS['Other'];
      const date     = d.uploaded_at ? new Date(d.uploaded_at).toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}) : '—';
      return `
        <div class="doc-card" style="animation-delay:${i*.04}s;">
          <div class="doc-card-top">
            <div class="doc-file-icon" style="background:${dc.bg};">${icon}</div>
            <div style="flex:1;min-width:0;">
              <div class="doc-file-name" title="${d.file_name}">${d.file_name}</div>
              <div class="doc-file-size">${fmtSize(d.file_size)}</div>
              <span class="doc-type-badge" style="background:${dc.bg};color:${dc.c};">${d.document_type}</span>
            </div>
          </div>
          <div class="doc-card-foot">
            <div class="doc-date">📅 ${date}</div>
            <div class="doc-actions">
              <a class="doc-act-btn view" href="#" onclick="openDocPreview(${d.document_id},'${d.file_name.replace(/'/g,String.fromCharCode(39))}');return false;" title="View">👁️</a>
              <a class="doc-act-btn dl"   href="../serve_document.php?id=${d.document_id}" download="${d.file_name}" title="Download">⬇️</a>
              <button class="doc-act-btn del" onclick="openDocDelete(${d.document_id},'${d.file_name.replace(/'/g,"\\'")}')" title="Delete">🗑️</button>
            </div>
          </div>
        </div>`;
    }).join('');
  } catch(e) { showToast('❌ Failed to load documents.','error'); }
}

function debounceDoc() { clearTimeout(docDebounce); docDebounce = setTimeout(loadDocuments, 300); }
function clearDocFilters() { 
  document.getElementById('docSearch').value=''; 
  document.getElementById('docTypeFilter').value=''; 
  loadDocuments();
  showToast('✨ Filters cleared. Showing all documents.', 'success');
}

// Upload
function openDocUpload() {
  docFile = null;
  document.getElementById('uploadType').value = '';
  document.getElementById('docFilePreview').classList.remove('show');
  document.getElementById('docProgress').classList.remove('show');
  openModal('modalDocUpload');
}

function dzOver(e)  { e.preventDefault(); document.getElementById('docDropZone').classList.add('over'); }
function dzLeave()  { document.getElementById('docDropZone').classList.remove('over'); }
function dzDrop(e)  { e.preventDefault(); dzLeave(); const f=e.dataTransfer.files[0]; if(f) setDocFile(f); }
function docFileSelect(e) { const f=e.target.files[0]; if(f) setDocFile(f); }

function setDocFile(file) {
  docFile = file;
  const ext = file.name.split('.').pop().toLowerCase();
  document.getElementById('docFpIcon').textContent = FILE_ICONS[ext] || '📎';
  document.getElementById('docFpName').textContent = file.name;
  document.getElementById('docFpSize').textContent = fmtSize(file.size);
  document.getElementById('docFilePreview').classList.add('show');
}

function clearDocFile() { docFile=null; document.getElementById('docFilePreview').classList.remove('show'); document.getElementById('docFileInput').value=''; }

async function submitDocUpload() {
  const docType = document.getElementById('uploadType').value;
  if (!docType)  { showToast('⚠️ Please select a document type.','info'); return; }
  if (!docFile)  { showToast('⚠️ Please select a file.','info'); return; }

  const btn = document.getElementById('docUploadBtn');
  btn.disabled = true; btn.textContent = '⏳ Uploading…';

  const progress = document.getElementById('docProgress');
  const fill     = document.getElementById('docProgFill');
  const lbl      = document.getElementById('docProgLbl');
  progress.classList.add('show');

  let pct = 0;
  const interval = setInterval(()=>{ pct=Math.min(pct+10,85); fill.style.width=pct+'%'; lbl.textContent=`Uploading… ${pct}%`; }, 150);

  const body = new FormData();
  body.append('action',        'upload_document');
  body.append('document_type', docType);
  body.append('file',          docFile);

  try {
    const res  = await fetch('Employee_portal.php',{method:'POST',body});
    clearInterval(interval); fill.style.width='100%'; lbl.textContent='Done!';
    const json = await res.json();
    setTimeout(()=>{
      progress.classList.remove('show');
      if(json.success){ showToast('✅ '+json.message,'success'); closeModal('modalDocUpload'); loadDocuments(); }
      else showToast('❌ '+json.message,'error');
    }, 400);
  } catch(e) { clearInterval(interval); progress.classList.remove('show'); showToast('❌ Upload failed.','error'); }
  finally { btn.disabled=false; btn.textContent='📤 Upload'; }
}

// Delete
function openDocDelete(id, fname) {
  docDeleteTarget = id;
  document.getElementById('docDeleteText').innerHTML = `You are about to permanently delete <strong>${fname}</strong>.<br/>The file will be removed from the server.`;
  openModal('modalDocDelete');
}

async function confirmDocDelete() {
  const btn = document.getElementById('docDeleteBtn');
  btn.disabled=true; btn.textContent='⏳ Deleting…';
  const body=new FormData(); body.append('action','delete_document'); body.append('document_id',docDeleteTarget);
  try {
    const res=await fetch('Employee_portal.php',{method:'POST',body});
    const json=await res.json();
    if(json.success){showToast('🗑️ Document deleted.','info');closeModal('modalDocDelete');loadDocuments();}
    else showToast('❌ '+json.message,'error');
  } catch(e){showToast('❌ Failed.','error');}
  finally{btn.disabled=false;btn.textContent='🗑️ Yes, Delete';}
}

// ════════════════════════════════════════════════════════
//  TOAST / MODAL / SIDEBAR
// ════════════════════════════════════════════════════════
function showToast(msg,type='success'){document.querySelectorAll('.toast').forEach(t=>t.remove());const t=document.createElement('div');t.className=`toast ${type}`;t.textContent=msg;document.body.appendChild(t);setTimeout(()=>{t.style.opacity='0';t.style.transition='opacity .3s';setTimeout(()=>t.remove(),300);},3500);}
function openModal(id){document.getElementById(id).classList.add('open');}
function closeModal(id){document.getElementById(id).classList.remove('open');}
document.querySelectorAll('.modal-overlay').forEach(m=>m.addEventListener('click',e=>{if(e.target===m)m.classList.remove('open');}));

// ── Document Preview ─────────────────────────────────────────
const IMG_EXTS = ['jpg','jpeg','png','gif','webp','bmp'];
const PDF_EXTS = ['pdf'];
const DOC_EXTS = ['doc','docx','xls','xlsx','ppt','pptx'];

function openDocPreview(docId, fileName) {
  const ext     = fileName.split('.').pop().toLowerCase();
  const body    = document.getElementById('docPreviewBody');
  const viewUrl = `../Admin/serve_document.php?id=${docId}&inline=1`;
  const dlUrl   = `../Admin/serve_document.php?id=${docId}`;

  document.getElementById('docPreviewTitle').textContent = 'Document Preview';
  document.getElementById('docPreviewSub').textContent   = fileName;
  document.getElementById('docPreviewFname').textContent = fileName;
  document.getElementById('docPreviewDownload').href     = dlUrl;
  document.getElementById('docPreviewDownload').setAttribute('download', fileName);

  const EXT_ICONS  = {pdf:'📄',doc:'📝',docx:'📝',xls:'📊',xlsx:'📊',ppt:'📋',pptx:'📋'};
  const EXT_LABELS = {pdf:'PDF Document',doc:'Word Document',docx:'Word Document',xls:'Excel Spreadsheet',xlsx:'Excel Spreadsheet',ppt:'PowerPoint',pptx:'PowerPoint'};

  if (PDF_EXTS.includes(ext)) {
    body.innerHTML = `<iframe class="preview-frame" src="${viewUrl}"></iframe>`;
  } else if (IMG_EXTS.includes(ext)) {
    body.innerHTML = `<img class="preview-img" src="${viewUrl}" alt="${fileName}" />`;
  } else {
    const icon  = EXT_ICONS[ext]  || '📎';
    const label = EXT_LABELS[ext] || ext.toUpperCase() + ' File';
    body.innerHTML = `
      <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:52px 30px;text-align:center;min-height:320px;background:#f8fafc;">
        <div style="font-size:4rem;margin-bottom:16px;">${icon}</div>
        <div style="font-size:1rem;font-weight:800;color:#1e293b;margin-bottom:6px;">${label}</div>
        <div style="font-size:.83rem;color:#64748b;max-width:340px;line-height:1.6;margin-bottom:28px;">
          <strong>${fileName}</strong><br/>
          This file type cannot be previewed in the browser.<br/>
          Click <strong>Download</strong> below to open it on your device.
        </div>
        <a href="${dlUrl}" download="${fileName}"
           style="display:inline-flex;align-items:center;gap:8px;padding:11px 28px;background:linear-gradient(135deg,#2563eb,#1e40af);color:#fff;border-radius:9px;font-family:'Nunito',sans-serif;font-size:.88rem;font-weight:800;text-decoration:none;box-shadow:0 3px 12px rgba(37,99,235,.30);">
          ⬇️ Download File
        </a>
      </div>`;
  }

  openModal('modalDocPreview');
}

function closeDocPreview() {
  document.getElementById('docPreviewBody').innerHTML = '';
  closeModal('modalDocPreview');
}

// ════════════════════════════════════════════════════════
//  PDS — Personal Data Sheet (CS Form 212, Revised 2025)
// ════════════════════════════════════════════════════════
function pdsVal(id, val) {
  var el = document.getElementById(id);
  if (!el) return;
  if (el.type === 'radio') return; // handled separately
  if (el.type === 'checkbox') { el.checked = !!val; return; }
  el.value = val || '';
}
function pdsGet(id) {
  var el = document.getElementById(id);
  if (!el) return '';
  if (el.type === 'checkbox') return el.checked;
  return el.value.trim();
}

// ── OI multi-entry list helpers (items 31-33) ────────────────
function oiAddRow(listId, tplId, value, isDefault) {
  var list = document.getElementById(listId);
  var tpl  = document.getElementById(tplId);
  if (!list || !tpl) return;
  var clone = tpl.content.cloneNode(true);
  var inp = clone.querySelector('.oi-inp');
  if (value) inp.value = value;
  // If default row, remove the delete button
  if (isDefault) {
    var delBtn = clone.querySelector('.oi-del');
    if (delBtn) delBtn.remove();
  }
  list.appendChild(clone);
  var inputs = list.querySelectorAll('.oi-inp');
  inputs[inputs.length - 1].focus();
}
function oiDelRow(btn) {
  btn.closest('.oi-row').remove();
}
function oiResetList(listId, tplId) {
  var list = document.getElementById(listId);
  if (!list) return;
  // Clear all inputs in the list
  list.querySelectorAll('.oi-inp').forEach(function(inp) {
    inp.value = '';
  });
  // Remove all rows that have delete buttons (user-added rows)
  list.querySelectorAll('.oi-row').forEach(function(row) {
    var delBtn = row.querySelector('.oi-del');
    if (delBtn) row.remove();
  });
  // Ensure 7 default rows remain
  while (list.children.length < 7) {
    oiAddRow(listId, tplId, null, true);
  }
}
function resetEducation() {
  // Clear all input values in education table
  var table = document.getElementById('pds_edu_table');
  if (table) {
    table.querySelectorAll('tbody input').forEach(function(inp) {
      inp.value = '';
    });
  }
  // Remove user-added graduate studies rows
  var tbody = document.getElementById('pds_edu_list');
  if (tbody) {
    var rows = Array.from(tbody.querySelectorAll('tr[data-level="Graduate Studies"]'));
    rows.forEach(function(row, idx) {
      if (idx > 0) row.remove(); // Keep first default Graduate Studies row
    });
  }
}
function resetSection(listId) {
  // Generic reset for CS, WE, VW, TR sections
  var list = document.getElementById(listId);
  if (!list) return;
  // Clear all inputs
  list.querySelectorAll('input, select').forEach(function(inp) {
    inp.value = '';
  });
  // Remove all user-added rows (rows with delete buttons)
  list.querySelectorAll('tr').forEach(function(row) {
    var delBtn = row.querySelector('.rem-btn');
    if (delBtn) row.remove();
  });
}
function oiGetList(listId) {
  var list = document.getElementById(listId);
  if (!list) return [];
  return Array.from(list.querySelectorAll('.oi-inp'))
    .map(function(i){ return i.value.trim(); })
    .filter(Boolean);
}
function oiLoadList(listId, tplId, arr) {
  var list = document.getElementById(listId);
  if (!list) return;
  list.innerHTML = '';
  (arr || []).forEach(function(val){ if(val) oiAddRow(listId, tplId, val, true); });
}
function pdsRadioGet(name) {
  var el = document.querySelector('input[name="'+name+'"]:checked');
  return el ? el.value : 'No';
}
function pdsRadioSet(name, val) {
  var els = document.querySelectorAll('input[name="'+name+'"]');
  els.forEach(function(el){ el.checked = (el.value === val); });
}
function safeJson(str) {
  try { return str ? JSON.parse(str) : null; } catch(e) { return null; }
}
function toggleDualCit() {
  var dual = document.getElementById('pds_cit_dual').checked;
  document.getElementById('dual_cit_detail').style.display = dual ? 'block' : 'none';
}
function togglePermSame() {
  var same = document.getElementById('pds_perm_same').checked;
  document.getElementById('perm_addr_fields').style.display = same ? 'none' : 'block';
}
function toggle40(letter) {
  var cb = document.getElementById('pds_q40'+letter+'_yes');
  var det = document.getElementById('pds_q40'+letter+'_detail');
  if (det) det.style.display = cb.checked ? 'block' : 'none';
}

async function checkLinkage() {
  try {
    const r = await fetch('Employee_portal.php?action=whoami');
    const j = await r.json();
    const banner = document.getElementById('pds-link-banner');
    if (!banner) return;
    if (!j.success) { banner.style.cssText='display:block;background:#fef2f2;border:1.5px solid #fca5a5;color:#dc2626;'; banner.textContent='⚠️ Error checking account linkage: '+j.message; return; }
    if (!j.resolved_employee_id) {
      banner.style.cssText='display:block;background:#fef2f2;border:1.5px solid #fca5a5;color:#dc2626;padding:12px 16px;border-radius:8px;';
      banner.innerHTML = `⚠️ <strong>Account not linked!</strong> Your login (user_id=${j.session_user_id}) is not connected to an employee record. `+
        `Your PDS/SALN cannot be saved or seen by admin until an admin goes to <strong>Employees → Edit → User Account</strong> and links your account. `+
        `<br><small style="color:#b91c1c;">employee_id resolved = ${j.resolved_employee_id} | user_id = ${j.session_user_id}</small>`;
    } else {
      banner.style.display = 'none'; // Account is linked — no need to show anything
    }
  } catch(e) { console.error('checkLinkage error:', e); }
}

async function loadPDS() {
  checkLinkage();
  const r = await fetch('Employee_portal.php?action=get_pds');
  const j = await r.json();
  if (!j.data) {
    // Pre-fill from profile if available
    ['pds_cs_list','pds_we_list','pds_vw_list','pds_tr_list','pds_ref_list','pds_character_ref_list','pds_children_list','pds_skills_list','pds_distinctions_list','pds_memberships_list'].forEach(id => {
      var el = document.getElementById(id); if (el) el.innerHTML = '';
    });
    addCS(null, true); addWE(null, true); addRef(null, true); addRef(null, true); addRef(null, true); addRef(null, true); addRef(null, true); addRef(null, true); addRef(null, true);
    for (var i = 0; i < 3; i++) addCharacterRef(null, true);
    for (var i = 0; i < 3; i++) {
      oiAddRow('pds_skills_list', 'pds_skills_tpl', null, true);
      oiAddRow('pds_distinctions_list', 'pds_distinctions_tpl', null, true);
      oiAddRow('pds_memberships_list', 'pds_memberships_tpl', null, true);
    }
    return;
  }
  const d = j.data;
  const pi  = safeJson(d.personal_info)     || {};
  const fb  = safeJson(d.family_background) || {};
  const oi  = safeJson(d.other_info)        || {};
  const q4  = safeJson(d.q4_questions)      || {};

  // Personal Info
  ['last_name','first_name','middle_name','suffix','dob','pob','height','weight',
   'umid','pagibig','philhealth','psn','tin','agency_no',
   'res_housenum','res_street','res_subdivision','res_barangay','res_city','res_province','res_zip',
   'perm_housenum','perm_street','perm_subdivision','perm_barangay','perm_city','perm_province','perm_zip',
   'tel','mobile','email','dual_country','dual_by','gov_id_type','gov_id_no','gov_id_issuance','date_accomplished'
  ].forEach(function(k){ pdsVal('pds_'+k, pi[k]); });

  pdsVal('pds_sex', pi.sex);
  pdsVal('pds_civil_status', pi.civil_status);
  pdsVal('pds_blood_type', pi.blood_type);
  if (pi.citizenship === 'Dual Citizenship') {
    document.getElementById('pds_cit_dual').checked = true;
    document.getElementById('dual_cit_detail').style.display = 'block';
  } else {
    document.getElementById('pds_cit_filipino').checked = true;
  }
  if (pi.perm_same) {
    document.getElementById('pds_perm_same').checked = true;
    document.getElementById('perm_addr_fields').style.display = 'none';
  }

  // Family
  ['sp_surname','sp_firstname','sp_ext','sp_middle','sp_occupation','sp_employer','sp_biz_addr','sp_tel',
   'fa_surname','fa_firstname','fa_ext','fa_middle',
   'mo_surname','mo_firstname','mo_middle'
  ].forEach(function(k){ pdsVal('pds_'+k, fb[k]); });

  document.getElementById('pds_children_list').innerHTML = '';
  (fb.children || []).forEach(function(ch){ addChild(ch, true); });
  if (!(fb.children||[]).length) addChild(null, true);

  // Education — populate fixed table rows by matching level
  var eduData = safeJson(d.education) || [];
  var levelMap = {};
  eduData.forEach(function(e){ if(e.level) levelMap[e.level] = e; });
  document.querySelectorAll('#pds_edu_list tr[data-level]').forEach(function(row){
    var level = row.getAttribute('data-level');
    var e = levelMap[level] || {};
    ['school','degree','from','to','units','year_grad','honors'].forEach(function(f){
      var inp = row.querySelector('[data-field="'+f+'"]');
      if(inp) inp.value = e[f] || '';
    });
  });

  // Civil Service
  document.getElementById('pds_cs_list').innerHTML = '';
  (safeJson(d.civil_service) || []).forEach(function(e){ addCS(e, true); });
  if (!document.getElementById('pds_cs_list').children.length) addCS(null, true);

  // Work Experience
  document.getElementById('pds_we_list').innerHTML = '';
  (safeJson(d.work_experience) || []).forEach(function(e){ addWE(e, true); });
  if (!document.getElementById('pds_we_list').children.length) addWE(null, true);

  // Voluntary Work
  document.getElementById('pds_vw_list').innerHTML = '';
  (safeJson(d.voluntary_work) || []).forEach(function(e){ addVW(e, true); });

  // Trainings
  document.getElementById('pds_tr_list').innerHTML = '';
  (safeJson(d.trainings) || []).forEach(function(e){ addTR(e, true); });

  // Other Info
  pdsVal('pds_skills',       oi.skills);
  pdsVal('pds_distinctions', oi.distinctions);
  pdsVal('pds_memberships',  oi.memberships);
  // Load OI lists (support both old string format and new array format)
  oiLoadList('pds_skills_list',       'pds_skills_tpl',       oi.skills_arr       || (oi.skills       ? oi.skills.split('\n').filter(Boolean)       : []));
  oiLoadList('pds_distinctions_list', 'pds_distinctions_tpl', oi.distinctions_arr || (oi.distinctions ? oi.distinctions.split('\n').filter(Boolean) : []));
  oiLoadList('pds_memberships_list',  'pds_memberships_tpl',  oi.memberships_arr  || (oi.memberships  ? oi.memberships.split('\n').filter(Boolean)  : []));
  // Ensure 7 default rows for each OI section
  while (document.getElementById('pds_skills_list').children.length < 7) oiAddRow('pds_skills_list', 'pds_skills_tpl', null, true);
  while (document.getElementById('pds_distinctions_list').children.length < 7) oiAddRow('pds_distinctions_list', 'pds_distinctions_tpl', null, true);
  while (document.getElementById('pds_memberships_list').children.length < 7) oiAddRow('pds_memberships_list', 'pds_memberships_tpl', null, true);

  // Q34–40
  pdsRadioSet('q34a', q4.q34a||'No'); pdsRadioSet('q34b', q4.q34b||'No');
  pdsRadioSet('q35a', q4.q35a||'No'); pdsRadioSet('q35b', q4.q35b||'No');
  pdsRadioSet('q36',  q4.q36 ||'No'); pdsRadioSet('q37',  q4.q37 ||'No');
  pdsRadioSet('q38a', q4.q38a||'No'); pdsRadioSet('q38b', q4.q38b||'No');
  pdsRadioSet('q39',  q4.q39 ||'No');
  pdsVal('pds_q34_details',  q4.q34_details);
  pdsVal('pds_q35a_details', q4.q35a_details); pdsVal('pds_q35b_details', q4.q35b_details);
  pdsVal('pds_q35b_date',    q4.q35b_date);    pdsVal('pds_q35b_status',  q4.q35b_status);
  pdsVal('pds_q36_details',  q4.q36_details);  pdsVal('pds_q37_details',  q4.q37_details);
  pdsVal('pds_q38a_details', q4.q38a_details); pdsVal('pds_q38b_details', q4.q38b_details);
  pdsVal('pds_q39_details',  q4.q39_details);
  ['a','b','c'].forEach(function(l){
    var cb = document.getElementById('pds_q40'+l+'_yes');
    var det = document.getElementById('pds_q40'+l+'_detail');
    if (cb) { cb.checked = !!(q4['q40'+l]); }
    if (det) { det.style.display = q4['q40'+l] ? 'block':'none'; det.value = q4['q40'+l+'_detail']||''; }
  });

  // References (Section VIII)
  document.getElementById('pds_ref_list').innerHTML = '';
  (safeJson(d.pds_references) || []).forEach(function(e){ addRef(e, true); });
  while (document.getElementById('pds_ref_list').children.length < 3) addRef(null, true);

  // Character References (Section 41) — limit to 3 rows maximum
  document.getElementById('pds_character_ref_list').innerHTML = '';
  var charRefs = safeJson(d.pds_character_references) || [];
  // Only load first 3 character references
  charRefs.slice(0, 3).forEach(function(e){ addCharacterRef(e, true); });
  while (document.getElementById('pds_character_ref_list').children.length < 3) addCharacterRef(null, true);
}


async function printPDSFromPortal() {
  const r = await fetch('Employee_portal.php?action=get_pds');
  const j = await r.json();
  if (!j.data) { showToast('❌ No saved PDS found. Please save your PDS first.', 'error'); return; }
  buildAndPrintPDS(j.data, null);
}
async function savePDS() {
  const payload = {
    personal_info: {
      last_name:pdsGet('pds_last_name'), first_name:pdsGet('pds_first_name'),
      middle_name:pdsGet('pds_middle_name'), suffix:pdsGet('pds_suffix'),
      dob:pdsGet('pds_dob'), pob:pdsGet('pds_pob'),
      sex:pdsGet('pds_sex'), civil_status:pdsGet('pds_civil_status'),
      height:pdsGet('pds_height'), weight:pdsGet('pds_weight'), blood_type:pdsGet('pds_blood_type'),
      umid:pdsGet('pds_umid'), pagibig:pdsGet('pds_pagibig'), philhealth:pdsGet('pds_philhealth'),
      psn:pdsGet('pds_psn'), tin:pdsGet('pds_tin'), agency_no:pdsGet('pds_agency_no'),
      citizenship: document.getElementById('pds_cit_dual').checked ? 'Dual Citizenship' : 'Filipino',
      dual_by:pdsGet('pds_dual_by'), dual_country:pdsGet('pds_dual_country'),
      res_housenum:pdsGet('pds_res_housenum'), res_street:pdsGet('pds_res_street'),
      res_subdivision:pdsGet('pds_res_subdivision'), res_barangay:pdsGet('pds_res_barangay'),
      res_city:pdsGet('pds_res_city'), res_province:pdsGet('pds_res_province'), res_zip:pdsGet('pds_res_zip'),
      perm_same: pdsGet('pds_perm_same'),
      perm_housenum:pdsGet('pds_perm_housenum'), perm_street:pdsGet('pds_perm_street'),
      perm_subdivision:pdsGet('pds_perm_subdivision'), perm_barangay:pdsGet('pds_perm_barangay'),
      perm_city:pdsGet('pds_perm_city'), perm_province:pdsGet('pds_perm_province'), perm_zip:pdsGet('pds_perm_zip'),
      tel:pdsGet('pds_tel'), mobile:pdsGet('pds_mobile'), email:pdsGet('pds_email'),
      gov_id_type:pdsGet('pds_gov_id_type'), gov_id_no:pdsGet('pds_gov_id_no'),
      gov_id_issuance:pdsGet('pds_gov_id_issuance'), date_accomplished:pdsGet('pds_date_accomplished'),
    },
    family_background: {
      sp_surname:pdsGet('pds_sp_surname'), sp_firstname:pdsGet('pds_sp_firstname'),
      sp_ext:pdsGet('pds_sp_ext'), sp_middle:pdsGet('pds_sp_middle'),
      sp_occupation:pdsGet('pds_sp_occupation'), sp_employer:pdsGet('pds_sp_employer'),
      sp_biz_addr:pdsGet('pds_sp_biz_addr'), sp_tel:pdsGet('pds_sp_tel'),
      fa_surname:pdsGet('pds_fa_surname'), fa_firstname:pdsGet('pds_fa_firstname'),
      fa_ext:pdsGet('pds_fa_ext'), fa_middle:pdsGet('pds_fa_middle'),
      mo_surname:pdsGet('pds_mo_surname'), mo_firstname:pdsGet('pds_mo_firstname'),
      mo_middle:pdsGet('pds_mo_middle'),
      children: collectRows('pds_children_list', ['name','dob']),
    },
    education:      collectRows('pds_edu_list', ['level','school','degree','from','to','units','year_grad','honors']),
    civil_service:  collectRows('pds_cs_list',  ['eligibility','rating','exam_date','exam_place','license_no','license_validity']),
    work_experience:collectRows('pds_we_list',  ['date_from','date_to','position','dept_agency','monthly_salary','salary_grade','status','govt_service']),
    voluntary_work: collectRows('pds_vw_list',  ['org_name','org_address','date_from','date_to','no_hours','position']),
    trainings:      collectRows('pds_tr_list',  ['title','date_from','date_to','no_hours','ld_type','conducted_by']),
    other_info: {
      skills:pdsGet('pds_skills'), distinctions:pdsGet('pds_distinctions'), memberships:pdsGet('pds_memberships'),
      skills_arr:oiGetList('pds_skills_list'), distinctions_arr:oiGetList('pds_distinctions_list'), memberships_arr:oiGetList('pds_memberships_list'),
    },
    q4_questions: {
      q34a:pdsRadioGet('q34a'), q34b:pdsRadioGet('q34b'), q34_details:pdsGet('pds_q34_details'),
      q35a:pdsRadioGet('q35a'), q35a_details:pdsGet('pds_q35a_details'),
      q35b:pdsRadioGet('q35b'), q35b_details:pdsGet('pds_q35b_details'),
      q35b_date:pdsGet('pds_q35b_date'), q35b_status:pdsGet('pds_q35b_status'),
      q36:pdsRadioGet('q36'),   q36_details:pdsGet('pds_q36_details'),
      q37:pdsRadioGet('q37'),   q37_details:pdsGet('pds_q37_details'),
      q38a:pdsRadioGet('q38a'), q38a_details:pdsGet('pds_q38a_details'),
      q38b:pdsRadioGet('q38b'), q38b_details:pdsGet('pds_q38b_details'),
      q39:pdsRadioGet('q39'),   q39_details:pdsGet('pds_q39_details'),
      q40a:pdsGet('pds_q40a_yes'), q40a_detail:pdsGet('pds_q40a_detail'),
      q40b:pdsGet('pds_q40b_yes'), q40b_detail:pdsGet('pds_q40b_detail'),
      q40c:pdsGet('pds_q40c_yes'), q40c_detail:pdsGet('pds_q40c_detail'),
    },
    references: collectRows('pds_ref_list', ['name','address','tel']),
    character_references: collectRows('pds_character_ref_list', ['name','address','tel']),
  };

  const r = await fetch('Employee_portal.php?action=save_pds', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF_TOKEN}, body:JSON.stringify(payload)
  });
  const j = await r.json();
  const st = document.getElementById('pdsStatus');
  if (j.success) {
    st.className='form-status saved'; st.textContent='✅ '+j.message; st.style.display='block';
    showToast('✅ PDS saved!','success');
    setTimeout(()=>st.style.display='none', 4000);
  } else { showToast('❌ '+j.message,'error'); }
}

// ── Repeater helpers ────────────────────────────────────
function collectRows(containerId, fields) {
  var container = document.getElementById(containerId);
  if (!container) return [];
  // Education table: fixed rows with data-level attribute
  var levelRows = Array.from(container.querySelectorAll('tr[data-level]'));
  if (levelRows.length > 0) {
    return levelRows.map(function(row) {
      var obj = { level: row.getAttribute('data-level') };
      fields.forEach(function(f) {
        if (f === 'level') return;
        var el = row.querySelector('[data-field="'+f+'"]');
        obj[f] = el ? el.value.trim() : '';
      });
      return obj;
    });
  }
  // saln-tbl: only body rows (skip thead)
  var salnTbl = container.querySelector('.saln-tbl');
  if (salnTbl) {
    var tbodyRows = Array.from(salnTbl.querySelectorAll('tbody tr'));
    return tbodyRows.map(function(row) {
      var obj = {};
      fields.forEach(function(f) {
        var el = row.querySelector('[data-field="'+f+'"]');
        obj[f] = el ? el.value.trim() : '';
      });
      return obj;
    });
  }
  // Table-based rows (tbody > tr)
  var trRows = Array.from(container.querySelectorAll('tbody tr'));
  if (trRows.length > 0) {
    return trRows.map(function(row) {
      var obj = {};
      fields.forEach(function(f) {
        var el = row.querySelector('[data-field="'+f+'"]');
        obj[f] = el ? el.value.trim() : '';
      });
      return obj;
    });
  }
  // Fallback: div-based .repeater-row
  return Array.from(container.querySelectorAll('.repeater-row')).map(function(row) {
    var obj = {};
    fields.forEach(function(f) {
      var el = row.querySelector('[data-field="'+f+'"]');
      obj[f] = el ? el.value.trim() : '';
    });
    return obj;
  });
}

function makeRepeaterRow(containerId, fieldDefs, values, isDefault) {
  var row = document.createElement('div');
  row.className = 'repeater-row';
  fieldDefs.forEach(function(fd) {
    var key   = typeof fd === 'string' ? fd : fd.key;
    var label = typeof fd === 'string' ? fd.replace(/_/g,' ').replace(/\b\w/g,function(x){return x.toUpperCase();}) : fd.label;
    var type  = (typeof fd === 'object' && fd.type) ? fd.type : 'text';
    var opts  = (typeof fd === 'object' && fd.options) ? fd.options : null;

    var wrap = document.createElement('div'); wrap.className = 'fg';
    var lbl  = document.createElement('label'); lbl.textContent = label;
    wrap.appendChild(lbl);

    var inp;
    if (opts) {
      inp = document.createElement('select');
      inp.innerHTML = '<option value="">—</option>' + opts.map(function(o){return '<option>'+o+'</option>';}).join('');
    } else {
      inp = document.createElement('input');
      inp.type = type;
    }
    inp.setAttribute('data-field', key);
    inp.value = (values && values[key] !== undefined) ? values[key] : '';
    wrap.appendChild(inp);
    row.appendChild(wrap);
  });
  // Only show delete button if not a default row
  if (!isDefault) {
    var rem = document.createElement('button');
    rem.className = 'rem-btn'; rem.textContent = '✕';
    rem.onclick = function(){ row.remove(); };
    row.appendChild(rem);
  }
  document.getElementById(containerId).appendChild(row);
}

// PDS repeater row definitions
function addChild(v, isDefault) {
  makeRepeaterRow('pds_children_list', [
    {key:'name', label:'Full Name of Child'},
    {key:'dob',  label:'Date of Birth', type:'date'}
  ], v, isDefault);
}
function addRef(v, isDefault) {
  makeRepeaterRow('pds_ref_list', [
    {key:'name',    label:'Name'},
    {key:'address', label:'Office / Residential Address'},
    {key:'tel',     label:'Contact No. / Email'}
  ], v, isDefault);
}
function addCharacterRef(v, isDefault) {
  makeRepeaterRow('pds_character_ref_list', [
    {key:'name',    label:'Name'},
    {key:'address', label:'Office / Residential Address'},
    {key:'tel',     label:'Contact No. / Email'}
  ], v, isDefault);
}
function resetReferences() {
  var list = document.getElementById('pds_ref_list');
  if (!list) return;
  // Clear all inputs
  list.querySelectorAll('input').forEach(function(inp) {
    inp.value = '';
  });
  // Remove all user-added rows (rows with delete buttons)
  list.querySelectorAll('.repeater-row').forEach(function(row) {
    var btn = row.querySelector('.rem-btn');
    if (btn) row.remove();
  });
  // Ensure at least 3 default rows remain
  while (list.children.length < 3) addRef(null, true);
}
function resetCharacterReferences() {
  var list = document.getElementById('pds_character_ref_list');
  if (!list) return;
  // Clear all inputs
  list.querySelectorAll('input').forEach(function(inp) {
    inp.value = '';
  });
  // Remove all user-added rows (rows with delete buttons)
  list.querySelectorAll('.repeater-row').forEach(function(row) {
    var btn = row.querySelector('.rem-btn');
    if (btn) row.remove();
  });
  // Ensure at least 3 default rows remain
  while (list.children.length < 3) addCharacterRef(null, true);
  // Remove excess rows if more than 3 default rows somehow exist
  while (list.children.length > 3) {
    var rows = list.querySelectorAll('.repeater-row');
    if (rows.length > 0) rows[rows.length - 1].remove();
  }
}
function addGradStudy(v, isDefault) {
  // Graduate Studies extra row — same columns as the fixed edu table
  var tbody = document.getElementById('pds_edu_list');
  var inpStyle = 'width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;font-family:Nunito,sans-serif;box-sizing:border-box;';
  var tr = document.createElement('tr');
  tr.setAttribute('data-level','Graduate Studies');
  var fields = [
    {key:'school',    placeholder:'', align:''},
    {key:'degree',    placeholder:'', align:''},
    {key:'from',      placeholder:'YYYY', align:'text-align:center;'},
    {key:'to',        placeholder:'YYYY', align:'text-align:center;'},
    {key:'units',     placeholder:'',     align:'text-align:center;'},
    {key:'year_grad', placeholder:'YYYY', align:'text-align:center;'},
    {key:'honors',    placeholder:'',     align:''},
  ];
  // Label cell
  var tdLabel = document.createElement('td');
  tdLabel.style.cssText = 'border:1px solid var(--g200);padding:6px 8px;font-size:.76rem;font-weight:700;color:var(--g700);white-space:nowrap;';
  tdLabel.textContent = 'Grad. Studies';
  tr.appendChild(tdLabel);
  // Input cells
  fields.forEach(function(f) {
    var td = document.createElement('td');
    td.style.cssText = 'border:1px solid var(--g200);padding:4px;';
    var inp = document.createElement('input');
    inp.type = 'text';
    inp.setAttribute('data-field', f.key);
    inp.style.cssText = inpStyle + f.align;
    inp.placeholder = f.placeholder;
    inp.value = (v && v[f.key]) ? v[f.key] : '';
    td.appendChild(inp);
    tr.appendChild(td);
  });
  // Remove button cell — only show delete button if not a default row
  var tdx = document.createElement('td');
  tdx.style.cssText = 'border:1px solid var(--g200);padding:4px;text-align:center;';
  if (!isDefault) {
    var btn = document.createElement('button');
    btn.className = 'rem-btn'; btn.type = 'button'; btn.textContent = '✕';
    btn.onclick = function(){ tr.remove(); };
    tdx.appendChild(btn);
  }
  tr.appendChild(tdx);
  tbody.appendChild(tr);
}

function addCS(v, isDefault) {
  makeTblRow('pds_cs_list', [
    {key:'eligibility',      type:'text'},
    {key:'rating',           type:'text'},
    {key:'exam_date',        type:'date'},
    {key:'exam_place',       type:'text'},
    {key:'license_no',       type:'text'},
    {key:'license_validity', type:'date'},
  ], v, isDefault);
}
function addWE(v, isDefault) {
  makeTblRow('pds_we_list', [
    {key:'date_from',      type:'date'},
    {key:'date_to',        type:'date'},
    {key:'position',       type:'text'},
    {key:'dept_agency',    type:'text'},
    {key:'monthly_salary', type:'number'},
    {key:'salary_grade',   type:'text'},
    {key:'status',         type:'text'},
    {key:'govt_service',   options:['','Y','N']},
  ], v, isDefault);
}
function makeTblRow(tbodyId, fields, v, isDefault) {
  var tbody = document.getElementById(tbodyId);
  var tr = document.createElement('tr');
  tr.setAttribute('data-is-default', isDefault ? 'true' : 'false');
  var inp_style = 'width:100%;border:1.5px solid #94a3b8;border-radius:6px;padding:6px 8px;font-size:.80rem;box-sizing:border-box;font-family:Nunito,sans-serif;';
  fields.forEach(function(fd) {
    var td = document.createElement('td');
    td.style.cssText = 'border:1px solid var(--g200);padding:4px;';
    var inp;
    if (fd.options) {
      inp = document.createElement('select');
      inp.style.cssText = inp_style;
      inp.innerHTML = '<option value="">—</option>' + fd.options.map(function(o){ return '<option>'+o+'</option>'; }).join('');
    } else {
      inp = document.createElement('input');
      inp.type = fd.type || 'text';
      inp.style.cssText = inp_style;
    }
    inp.setAttribute('data-field', fd.key);
    if (v && v[fd.key] !== undefined) inp.value = v[fd.key];
    td.appendChild(inp);
    tr.appendChild(td);
  });
  var tdx = document.createElement('td');
  tdx.style.cssText = 'border:1px solid var(--g200);padding:4px;text-align:center;';
  // Only show delete button if not a default row
  if (!isDefault) {
    var btn = document.createElement('button');
    btn.className = 'rem-btn'; btn.textContent = '✕';
    btn.onclick = function(){ tr.remove(); };
    tdx.appendChild(btn);
  }
  tr.appendChild(tdx);
  tbody.appendChild(tr);
}
function addVW(v, isDefault) {
  makeTblRow('pds_vw_list', [
    {key:'org_name',  type:'text'},
    {key:'date_from', type:'date'},
    {key:'date_to',   type:'date'},
    {key:'no_hours',  type:'number'},
    {key:'position',  type:'text'},
  ], v, isDefault);
}
function addTR(v, isDefault) {
  makeTblRow('pds_tr_list', [
    {key:'title',        type:'text'},
    {key:'date_from',    type:'date'},
    {key:'date_to',      type:'date'},
    {key:'no_hours',     type:'number'},
    {key:'ld_type',      options:['Managerial','Supervisory','Technical','Foundation','Scholarship','Other']},
    {key:'conducted_by', type:'text'},
  ], v, isDefault);
}


// ════════════════════════════════════════════════════════
//  PDS PRINT — 4-Page Official CS Form 212 (Revised 2025)
// ════════════════════════════════════════════════════════
function printPDS() {
  // Collect all form fields and pass to the official print builder
  const d = {
    personal_info: JSON.stringify({
      last_name:pdsGet('pds_last_name'), first_name:pdsGet('pds_first_name'),
      middle_name:pdsGet('pds_middle_name'), suffix:pdsGet('pds_suffix'),
      dob:pdsGet('pds_dob'), pob:pdsGet('pds_pob'),
      sex:pdsGet('pds_sex'), civil_status:pdsGet('pds_civil_status'),
      height:pdsGet('pds_height'), weight:pdsGet('pds_weight'), blood_type:pdsGet('pds_blood_type'),
      umid:pdsGet('pds_umid'), pagibig:pdsGet('pds_pagibig'), philhealth:pdsGet('pds_philhealth'),
      psn:pdsGet('pds_psn'), tin:pdsGet('pds_tin'), agency_no:pdsGet('pds_agency_no'),
      citizenship: document.getElementById('pds_cit_dual').checked ? 'Dual Citizenship' : 'Filipino',
      dual_by:pdsGet('pds_dual_by'), dual_country:pdsGet('pds_dual_country'),
      res_housenum:pdsGet('pds_res_housenum'), res_street:pdsGet('pds_res_street'),
      res_subdivision:pdsGet('pds_res_subdivision'), res_barangay:pdsGet('pds_res_barangay'),
      res_city:pdsGet('pds_res_city'), res_province:pdsGet('pds_res_province'), res_zip:pdsGet('pds_res_zip'),
      perm_same: document.getElementById('pds_perm_same')?.checked || false,
      perm_housenum:pdsGet('pds_perm_housenum'), perm_street:pdsGet('pds_perm_street'),
      perm_subdivision:pdsGet('pds_perm_subdivision'), perm_barangay:pdsGet('pds_perm_barangay'),
      perm_city:pdsGet('pds_perm_city'), perm_province:pdsGet('pds_perm_province'), perm_zip:pdsGet('pds_perm_zip'),
      tel:pdsGet('pds_tel'), mobile:pdsGet('pds_mobile'), email:pdsGet('pds_email'),
      gov_id_type:pdsGet('pds_gov_id_type'), gov_id_no:pdsGet('pds_gov_id_no'),
      gov_id_issuance:pdsGet('pds_gov_id_issuance'), date_accomplished:pdsGet('pds_date_accomplished'),
    }),
    family_background: JSON.stringify({
      sp_surname:pdsGet('pds_sp_surname'), sp_firstname:pdsGet('pds_sp_firstname'),
      sp_ext:pdsGet('pds_sp_ext'), sp_middle:pdsGet('pds_sp_middle'),
      sp_occupation:pdsGet('pds_sp_occupation'), sp_employer:pdsGet('pds_sp_employer'),
      sp_biz_addr:pdsGet('pds_sp_biz_addr'), sp_tel:pdsGet('pds_sp_tel'),
      fa_surname:pdsGet('pds_fa_surname'), fa_firstname:pdsGet('pds_fa_firstname'),
      fa_ext:pdsGet('pds_fa_ext'), fa_middle:pdsGet('pds_fa_middle'),
      mo_surname:pdsGet('pds_mo_surname'), mo_firstname:pdsGet('pds_mo_firstname'),
      mo_middle:pdsGet('pds_mo_middle'),
      children: collectRows('pds_children_list',['name','dob']),
    }),
    education:      JSON.stringify(collectRows('pds_edu_list',['level','school','degree','from','to','units','year_grad','honors'])),
    civil_service:  JSON.stringify(collectRows('pds_cs_list', ['eligibility','rating','exam_date','exam_place','license_no','license_validity'])),
    work_experience:JSON.stringify(collectRows('pds_we_list', ['date_from','date_to','position','dept_agency','monthly_salary','salary_grade','status','govt_service'])),
    voluntary_work: JSON.stringify(collectRows('pds_vw_list', ['org_name','date_from','date_to','no_hours','position'])),
    trainings:      JSON.stringify(collectRows('pds_tr_list', ['title','date_from','date_to','no_hours','ld_type','conducted_by'])),
    other_info:     JSON.stringify({ skills:pdsGet('pds_skills'), distinctions:pdsGet('pds_distinctions'), memberships:pdsGet('pds_memberships'), skills_arr:oiGetList('pds_skills_list'), distinctions_arr:oiGetList('pds_distinctions_list'), memberships_arr:oiGetList('pds_memberships_list') }),
    q4_questions:   JSON.stringify({
      q34a:pdsRadioGet('q34a'), q34b:pdsRadioGet('q34b'), q34_details:pdsGet('pds_q34_details'),
      q35a:pdsRadioGet('q35a'), q35a_details:pdsGet('pds_q35a_details'),
      q35b:pdsRadioGet('q35b'), q35b_details:pdsGet('pds_q35b_details'),
      q35b_date:pdsGet('pds_q35b_date'), q35b_status:pdsGet('pds_q35b_status'),
      q36:pdsRadioGet('q36'), q36_details:pdsGet('pds_q36_details'),
      q37:pdsRadioGet('q37'), q37_details:pdsGet('pds_q37_details'),
      q38a:pdsRadioGet('q38a'), q38a_details:pdsGet('pds_q38a_details'),
      q38b:pdsRadioGet('q38b'), q38b_details:pdsGet('pds_q38b_details'),
      q39:pdsRadioGet('q39'), q39_details:pdsGet('pds_q39_details'),
      q40a:pdsGet('pds_q40a_yes'), q40a_detail:pdsGet('pds_q40a_detail'),
      q40b:pdsGet('pds_q40b_yes'), q40b_detail:pdsGet('pds_q40b_detail'),
      q40c:pdsGet('pds_q40c_yes'), q40c_detail:pdsGet('pds_q40c_detail'),
    }),
    pds_references: JSON.stringify(collectRows('pds_ref_list',['name','address','tel'])),
    pds_character_references: JSON.stringify(collectRows('pds_character_ref_list',['name','address','tel'])),
  };
  const html = buildPDSPrintHTML(d, null);
  const w = window.open('','_blank','width=960,height=1100');
  w.document.write(html);
  w.document.close();
  w.focus();
  setTimeout(()=>w.print(), 600);
}

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
  const charRefs = safeJson(d.pds_character_references) || [];

  const v    = x => (x===null||x===undefined||x===''||x==='null'||x==='undefined') ? '' : String(x);
  const fmtD = dt => { if(!dt||dt==='0000-00-00') return ''; try{ const x=new Date(dt); return isNaN(x)?dt:(x.getMonth()+1).toString().padStart(2,'0')+'/'+x.getDate().toString().padStart(2,'0')+'/'+x.getFullYear(); }catch(e){return dt||'';} };
  const cb   = val => (val===true||val==='1'||val===1||val==='Yes'||val==='true') ? '☑' : '☐';
  const pad  = (arr,n) => { const a=[...(arr||[])]; while(a.length<n) a.push({}); return a; };

  const resAddr  = [pi.res_housenum,pi.res_street,pi.res_subdivision,pi.res_barangay,pi.res_city,pi.res_province].filter(Boolean).join(', ');
  const permAddr = pi.perm_same ? resAddr : [pi.perm_housenum,pi.perm_street,pi.perm_subdivision,pi.perm_barangay,pi.perm_city,pi.perm_province].filter(Boolean).join(', ');

  const edPad  = pad(ed,5);
  const csPad  = pad(cs,7);
  const wePad  = pad(we,28);
  const vwPad  = pad(vw,7);
  const trPad  = pad(tr,22);
  const charRefPad = pad(charRefs,3);
  const skillsPad = pad((oi.skills_arr&&oi.skills_arr.length?oi.skills_arr:oi.skills?oi.skills.split('\n').filter(Boolean):[]),7);
  const distinctPad = pad((oi.distinctions_arr&&oi.distinctions_arr.length?oi.distinctions_arr:oi.distinctions?oi.distinctions.split('\n').filter(Boolean):[]),7);
  const membershipPad = pad((oi.memberships_arr&&oi.memberships_arr.length?oi.memberships_arr:oi.memberships?oi.memberships.split('\n').filter(Boolean):[]),7);

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
      <!-- Top-left: CS Form No. + Revised label -->
      <td style="border:0.5pt solid #000;padding:2pt 5pt;width:18%;vertical-align:top;">
        <div style="font-size:6.5pt;font-weight:700;font-style:italic;">CS Form No. 212</div>
        <div style="font-size:6pt;font-style:italic;">Revised 2025</div>
      </td>
      <!-- Center: PERSONAL DATA SHEET title -->
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
      ${ed.map(e=>`<tr>
        <td></td>
        <td style="font-size:6pt;font-weight:700;">${v(e.level)}</td>
        <td style="font-size:6.5pt;font-weight:600;">${v(e.school)}</td>
        <td style="font-size:6.5pt;font-weight:600;">${v(e.degree)}</td>
        <td style="font-size:6.5pt;font-weight:600;text-align:center;">${v(e.from)}</td>
        <td style="font-size:6.5pt;font-weight:600;text-align:center;">${v(e.to)}</td>
        <td style="font-size:6.5pt;font-weight:600;text-align:center;">${v(e.units)}</td>
        <td style="font-size:6.5pt;font-weight:600;text-align:center;">${v(e.year_grad)}</td>
        <td style="font-size:6.5pt;font-weight:600;">${v(e.honors)}</td>
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
        <td style="font-size:6.5pt;font-weight:600;">${(e.eligibility ? v(e.eligibility) : '')}</td>
        <td style="font-size:6.5pt;font-weight:600;text-align:center;">${(e.rating ? v(e.rating) : '')}</td>
        <td style="font-size:6.5pt;font-weight:600;text-align:center;">${(e.exam_date ? fmtD(e.exam_date) : '')}</td>
        <td style="font-size:6.5pt;font-weight:600;">${(e.exam_place ? v(e.exam_place) : '')}</td>
        <td style="font-size:6.5pt;font-weight:600;text-align:center;">${(e.license_no ? v(e.license_no) : '')}</td>
        <td style="font-size:6.5pt;font-weight:600;text-align:center;">${(e.license_validity ? fmtD(e.license_validity) : '')}</td>
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
        <td style="font-size:6pt;text-align:center;">${(e.date_from ? fmtD(e.date_from) : '')}</td>
        <td style="font-size:6pt;text-align:center;">${(e.date_to ? fmtD(e.date_to) : '')}</td>
        <td style="font-size:6pt;">${(e.position ? v(e.position) : '')}</td>
        <td style="font-size:6pt;">${(e.dept_agency ? v(e.dept_agency) : '')}</td>
        <td style="font-size:6pt;text-align:right;">${(e.monthly_salary ? '₱'+parseFloat(e.monthly_salary).toLocaleString() : '')}</td>
        <td style="font-size:6pt;text-align:center;">${(e.salary_grade ? v(e.salary_grade) : '')}</td>
        <td style="font-size:6pt;">${(e.status ? v(e.status) : '')}</td>
        <td style="font-size:6pt;text-align:center;">${(e.govt_service ? v(e.govt_service) : '')}</td>
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
        <td style="font-size:6.5pt;font-weight:600;">${(e.org_name ? v(e.org_name) : '')}</td>
        <td style="font-size:6.5pt;font-weight:600;text-align:center;">${(e.date_from ? fmtD(e.date_from) : '')}</td>
        <td style="font-size:6.5pt;font-weight:600;text-align:center;">${(e.date_to ? fmtD(e.date_to) : '')}</td>
        <td style="font-size:6.5pt;font-weight:600;text-align:center;">${(e.no_hours ? v(e.no_hours) : '')}</td>
        <td style="font-size:6.5pt;font-weight:600;">${(e.position ? v(e.position) : '')}</td>
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
        <td style="font-size:6.5pt;font-weight:600;">${(e.title ? v(e.title) : '')}</td>
        <td style="font-size:6.5pt;font-weight:600;text-align:center;">${(e.date_from ? fmtD(e.date_from) : '')}</td>
        <td style="font-size:6.5pt;font-weight:600;text-align:center;">${(e.date_to ? fmtD(e.date_to) : '')}</td>
        <td style="font-size:6.5pt;font-weight:600;text-align:center;">${(e.no_hours ? v(e.no_hours) : '')}</td>
        <td style="font-size:6.5pt;font-weight:600;">${(e.ld_type ? v(e.ld_type) : '')}</td>
        <td style="font-size:6.5pt;font-weight:600;">${(e.conducted_by ? v(e.conducted_by) : '')}</td>
      </tr>`).join('')}
    </tbody>
  </table>
  <div style="font-size:5.5pt;font-style:italic;text-align:right;padding:1pt 2pt;">(Continue on separate sheet if necessary)</div>

  <!-- VIII. Other Information -->
  <table style="margin-top:1pt;"><tr><td colspan="3" class="sec">VIII. Other Information</td></tr></table>
  <table>
    <thead>
      <tr>
        <th class="hdr" style="width:33%;">31. SPECIAL SKILLS and HOBBIES</th>
        <th class="hdr" style="width:33%;">32. NON-ACADEMIC DISTINCTIONS / RECOGNITION<br><span style="font-size:4.5pt;font-weight:400;">(Write in full)</span></th>
        <th class="hdr" style="width:34%;">33. MEMBERSHIP IN ASSOCIATION/ORGANIZATION<br><span style="font-size:4.5pt;font-weight:400;">(Write in full)</span></th>
      </tr>
    </thead>
    <tbody>
      ${Array.from({length:7}).map((_, i)=>`<tr>
        <td style="font-size:6.5pt;font-weight:600;min-height:14pt;height:14pt;vertical-align:top;padding:2pt 2.5pt;">${(typeof skillsPad[i] === 'string' ? skillsPad[i] : '')}</td>
        <td style="font-size:6.5pt;font-weight:600;min-height:14pt;height:14pt;vertical-align:top;padding:2pt 2.5pt;">${(typeof distinctPad[i] === 'string' ? distinctPad[i] : '')}</td>
        <td style="font-size:6.5pt;font-weight:600;min-height:14pt;height:14pt;vertical-align:top;padding:2pt 2.5pt;">${(typeof membershipPad[i] === 'string' ? membershipPad[i] : '')}</td>
      </tr>`).join('')}
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
      ${charRefPad.map(r=>`<tr>
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

function buildAndPrintPDS(d, empRow) {
  const html = buildPDSPrintHTML(d, empRow);
  const w = window.open('', '_blank', 'width=960,height=1100');
  w.document.write(html);
  w.document.close();
  w.focus();
  setTimeout(() => w.print(), 600);
}

// ════════════════════════════════════════════════════════
//  SALN — 2025 Form (CSC Resolution No. 2500632)
// ════════════════════════════════════════════════════════
let salnCurrentYear = new Date().getFullYear();
let salnYears = [];

function saln_v(id, val) {
  var el = document.getElementById(id);
  if (!el) return;
  if (el.type === 'checkbox') { el.checked = !!val; return; }
  el.value = val !== undefined && val !== null ? val : '';
}
function saln_g(id) {
  var el = document.getElementById(id);
  if (!el) return '';
  if (el.type === 'checkbox') return el.checked;
  return el.value.trim();
}
function salnRadioSet(name, val) {
  document.querySelectorAll('input[name="'+name+'"]').forEach(function(el){ el.checked = (el.value === val); });
}
function salnRadioGet(name) {
  var el = document.querySelector('input[name="'+name+'"]:checked');
  return el ? el.value : '';
}
function toggleBiz() {
  var wrap = document.getElementById('saln_biz_wrap');
  if (wrap) wrap.style.display = document.getElementById('saln_no_biz').checked ? 'none' : 'block';
}
function toggleRel() {
  var wrap = document.getElementById('saln_rel_wrap');
  if (wrap) wrap.style.display = document.getElementById('saln_no_rel').checked ? 'none' : 'block';
}
function calcNetWorth() {
  var assets = parseFloat(document.getElementById('saln_total_assets').value) || 0;
  var liab   = parseFloat(document.getElementById('saln_total_liab').value)   || 0;
  document.getElementById('saln_net_worth').value = (assets - liab).toFixed(2);
}

async function loadSALN(year) {
  if (year) salnCurrentYear = year;
  const r = await fetch('Employee_portal.php?action=get_saln&year=' + salnCurrentYear);
  const j = await r.json();
  salnYears = j.years || [];
  renderSalnYearTabs();

  const d = j.data || {};
  const dec  = safeJson(d.spouse_info)    || {};  // reuse spouse_info for declarant
  const decl = safeJson(d.declarant_info) || {};
  const sp   = safeJson(d.spouse_info)    || {};

  // Compliance
  saln_v('saln_filing_type', d.filing_type);
  saln_v('saln_as_of',       d.as_of_date);
  salnRadioSet('saln_filing_mode', d.filing_mode || 'NA');

  // Declarant
  saln_v('saln_dec_lastname',    decl.lastname);
  saln_v('saln_dec_firstname',   decl.firstname);
  saln_v('saln_dec_mi',          decl.mi);
  saln_v('saln_dec_position',    decl.position);
  saln_v('saln_dec_agency',      decl.agency);
  saln_v('saln_dec_office_addr', decl.office_addr);

  // Spouse
  saln_v('saln_sp_lastname',    sp.lastname);
  saln_v('saln_sp_firstname',   sp.firstname);
  saln_v('saln_sp_mi',          sp.mi);
  saln_v('saln_sp_position',    sp.position);
  saln_v('saln_sp_agency',      sp.agency);
  saln_v('saln_sp_office_addr', sp.office_addr);
  saln_v('saln_multi_spouse',   d.multi_spouse);
  saln_v('saln_multi_na',       d.multi_spouse_na);

  // Children
  document.getElementById('saln_children_list').innerHTML = '';
  (safeJson(d.children_info) || []).forEach(function(ch){ addSalnChild(ch); });

  // Assets
  saln_v('saln_real_subtotal',     d.real_subtotal);
  saln_v('saln_personal_subtotal', d.personal_subtotal);
  saln_v('saln_total_assets',      d.total_assets);
  saln_v('saln_total_liab',        d.total_liabilities);
  saln_v('saln_net_worth',         d.net_worth);

  document.getElementById('saln_real_list').innerHTML = '';
  (safeJson(d.real_properties) || []).forEach(function(p){ addReal(p); });

  document.getElementById('saln_personal_list').innerHTML = '';
  (safeJson(d.personal_properties) || []).forEach(function(p){ addPersonal(p); });

  document.getElementById('saln_liab_list').innerHTML = '';
  (safeJson(d.liabilities) || []).forEach(function(l){ addLiab(l); });

  // Business Interests
  var noBiz = !!(safeJson(d.biz_interests) === null && d.no_biz);
  document.getElementById('saln_no_biz').checked = !!d.no_biz;
  document.getElementById('saln_biz_wrap').style.display = d.no_biz ? 'none' : 'block';
  document.getElementById('saln_biz_list').innerHTML = '';
  (safeJson(d.biz_interests) || []).forEach(function(b){ addBiz(b); });

  // Relatives
  document.getElementById('saln_no_rel').checked = !!d.no_rel;
  document.getElementById('saln_rel_wrap').style.display = d.no_rel ? 'none' : 'block';
  document.getElementById('saln_rel_list').innerHTML = '';
  (safeJson(d.relatives_govt) || []).forEach(function(rel){ addRel(rel); });

  // Gov ID
  saln_v('saln_gov_id',        d.gov_id);
  saln_v('saln_gov_id_no',     d.gov_id_no);
  saln_v('saln_gov_id_issued', d.gov_id_issued);

  // Auto-calc net worth listeners
  ['saln_total_assets','saln_total_liab'].forEach(function(id){
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', calcNetWorth);
  });
}

function renderSalnYearTabs() {
  var cur = salnCurrentYear;
  var all = salnYears.includes ? salnYears : Object.values(salnYears);
  if (!all.includes(cur)) all = [cur].concat(all);
  var sorted = all.filter(function(v,i,a){return a.indexOf(v)===i;}).sort(function(a,b){return b-a;});
  document.getElementById('salnYearTabs').innerHTML = sorted.map(function(y){
    return '<button class="year-tab'+(y==cur?' active':'')+'" onclick="loadSALN('+y+')">'+y+'</button>';
  }).join('');
}

function addSalnYear() {
  var y = parseInt(prompt('Enter filing year (e.g. ' + (new Date().getFullYear()) + '):'));
  if (!y || isNaN(y) || y < 2000 || y > 2099) return;
  salnCurrentYear = y;
  if (!salnYears.includes(y)) salnYears.push(y);
  renderSalnYearTabs();
  loadSALN(y);
}

async function saveSALN() {
  var year = parseInt(document.getElementById('saln_as_of').value.substring(0,4)) || salnCurrentYear;
  var payload = {
    saln_year:    year,
    filing_type:  saln_g('saln_filing_type'),
    filing_mode:  salnRadioGet('saln_filing_mode'),
    as_of_date:   saln_g('saln_as_of'),
    multi_spouse: saln_g('saln_multi_spouse'),
    multi_spouse_na: saln_g('saln_multi_na'),
    declarant_info: {
      lastname:    saln_g('saln_dec_lastname'),
      firstname:   saln_g('saln_dec_firstname'),
      mi:          saln_g('saln_dec_mi'),
      position:    saln_g('saln_dec_position'),
      agency:      saln_g('saln_dec_agency'),
      office_addr: saln_g('saln_dec_office_addr'),
    },
    spouse_info: {
      lastname:    saln_g('saln_sp_lastname'),
      firstname:   saln_g('saln_sp_firstname'),
      mi:          saln_g('saln_sp_mi'),
      position:    saln_g('saln_sp_position'),
      agency:      saln_g('saln_sp_agency'),
      office_addr: saln_g('saln_sp_office_addr'),
    },
    children_info:       collectRows('saln_children_list',  ['name','age']),
    real_properties:     collectRows('saln_real_list',      ['description','kind','location','assessed_value','market_value','acq_year','acq_mode','acq_cost']),
    personal_properties: collectRows('saln_personal_list',  ['description','acq_year','acq_cost']),
    liabilities:         collectRows('saln_liab_list',      ['nature','creditor','balance']),
    no_biz:              saln_g('saln_no_biz'),
    biz_interests:       collectRows('saln_biz_list',       ['entity_name','biz_address','nature','date_acquired']),
    no_rel:              saln_g('saln_no_rel'),
    relatives_govt:      collectRows('saln_rel_list',       ['name','relationship','position','agency_address']),
    real_subtotal:       saln_g('saln_real_subtotal'),
    personal_subtotal:   saln_g('saln_personal_subtotal'),
    total_assets:        saln_g('saln_total_assets'),
    total_liabilities:   saln_g('saln_total_liab'),
    net_worth:           saln_g('saln_net_worth'),
    gov_id:              saln_g('saln_gov_id'),
    gov_id_no:           saln_g('saln_gov_id_no'),
    gov_id_issued:       saln_g('saln_gov_id_issued'),
  };

  const r = await fetch('Employee_portal.php?action=save_saln', {
    method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF_TOKEN}, body:JSON.stringify(payload)
  });
  const j = await r.json();
  const st = document.getElementById('salnStatus');
  if (j.success) {
    st.className = 'form-status saved'; st.textContent = '✅ ' + j.message; st.style.display = 'block';
    showToast('✅ SALN saved!', 'success');
    salnCurrentYear = year;
    if (!salnYears.includes(year)) salnYears.push(year);
    renderSalnYearTabs();
    setTimeout(function(){ st.style.display = 'none'; }, 4000);
  } else { showToast('❌ ' + j.message, 'error'); }
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



// ── SALN table-based row helper ───────────────────────────────────────────────
function salnEnsureTable(containerId, cols) {
  var container = document.getElementById(containerId);
  if (!container) return null;
  var tbl = container.querySelector('.saln-tbl');
  if (!tbl) {
    tbl = document.createElement('table');
    tbl.className = 'saln-tbl';
    var thead = document.createElement('thead');
    var tr = document.createElement('tr');
    cols.forEach(function(c) {
      var th = document.createElement('th');
      th.textContent = c.label;
      if (c.w) th.style.width = c.w;
      tr.appendChild(th);
    });
    var thDel = document.createElement('th'); thDel.className='del-cell';
    tr.appendChild(thDel);
    thead.appendChild(tr);
    tbl.appendChild(thead);
    var tbody = document.createElement('tbody');
    tbl.appendChild(tbody);
    container.insertBefore(tbl, container.firstChild);
  }
  return tbl.querySelector('tbody');
}

function salnAddRow(containerId, cols, values) {
  var tbody = salnEnsureTable(containerId, cols);
  if (!tbody) return;
  var tr = document.createElement('tr');
  cols.forEach(function(c) {
    var td = document.createElement('td');
    var inp;
    if (c.options) {
      inp = document.createElement('select');
      inp.innerHTML = '<option value="">—</option>' + c.options.map(function(o){return '<option>'+o+'</option>';}).join('');
    } else {
      inp = document.createElement('input');
      inp.type = c.type || 'text';
      if (c.type === 'number') inp.step = 'any';
    }
    inp.setAttribute('data-field', c.key);
    if (values && values[c.key] !== undefined) inp.value = values[c.key];
    td.appendChild(inp);
    tr.appendChild(td);
  });
  var tdDel = document.createElement('td'); tdDel.className='del-cell';
  var btn = document.createElement('button'); btn.className='del-btn'; btn.type='button'; btn.textContent='✕';
  btn.onclick = function(){ tr.remove(); if(!tbody.rows.length){ var t=tbody.closest('.saln-tbl'); if(t) t.remove(); } };
  tdDel.appendChild(btn); tr.appendChild(tdDel);
  tbody.appendChild(tr);
}

function addSalnChild(v)  {
  salnAddRow('saln_children_list',[
    {key:'name', label:'Name of Child', w:'75%'},
    {key:'age',  label:'Age', type:'number', w:'25%'},
  ], v);
}
function addReal(v) {
  salnAddRow('saln_real_list', [
    {key:'description',    label:'Description'},
    {key:'kind',           label:'Kind'},
    {key:'location',       label:'Exact Location'},
    {key:'assessed_value', label:'Assessed Value (₱)', type:'number'},
    {key:'market_value',   label:'Market Value (₱)', type:'number'},
    {key:'acq_year',       label:'Acq. Year', type:'number', w:'70px'},
    {key:'acq_mode',       label:'Mode', options:['Bought','Inherited','Gift','Others'], w:'90px'},
    {key:'acq_cost',       label:'Acq. Cost (₱)', type:'number'},
  ], v);
}
function addPersonal(v) {
  salnAddRow('saln_personal_list', [
    {key:'description', label:'Description'},
    {key:'acq_year',    label:'Acquisition Year', type:'number', w:'130px'},
    {key:'acq_cost',    label:'Acquisition Cost / Amount (₱)', type:'number', w:'180px'},
  ], v);
}
function addLiab(v) {
  salnAddRow('saln_liab_list', [
    {key:'nature',   label:'Nature'},
    {key:'creditor', label:'Name of Creditor'},
    {key:'balance',  label:'Outstanding Balance (₱)', type:'number', w:'170px'},
  ], v);
}
function addBiz(v) {
  salnAddRow('saln_biz_list', [
    {key:'entity_name',   label:'Name of Entity / Business Enterprise'},
    {key:'biz_address',   label:'Business Address'},
    {key:'nature',        label:'Nature of Interest / Connection'},
    {key:'date_acquired', label:'Date Acquired', type:'date', w:'140px'},
  ], v);
}
function addRel(v) {
  salnAddRow('saln_rel_list', [
    {key:'name',           label:'Name of Relative'},
    {key:'relationship',   label:'Relationship', w:'120px'},
    {key:'position',       label:'Position'},
    {key:'agency_address', label:'Agency / Office & Address'},
  ], v);
}


</script>

  <!-- ══ CHAT WIDGET - ENHANCED UI ══ -->
  <style>
    .cw-wrap{position:fixed;bottom:24px;right:24px;width:420px;height:600px;background:#fff;border-radius:18px;box-shadow:0 20px 60px rgba(0,0,0,.28),0 0 1px rgba(0,0,0,.1);display:none;flex-direction:column;z-index:9999;overflow:hidden;font-family:'Nunito',sans-serif;backstop-filter:blur(10px);}
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
    .cw-online{width:10px;height:10px;border-radius:50%;background:#22c55e;margin-left:auto;flex-shrink:0;box-shadow:0 0 0 3px #fff,0 0 0 4px #22c55e;animation:pulse 2s cubic-bezier(.4,0,.6,1) infinite;}
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
  </style>

  <div class="cw-wrap" id="cwWidget">
    <div class="cw-hd">
      <div class="cw-hd-left">
        <div class="cw-hd-icon">&#x1F4AC;</div>
        <div>
          <div class="cw-hd-title">Messages</div>
          <div class="cw-hd-sub" id="cwHeaderSub">Select HR Staff to chat</div>
        </div>
      </div>
      <button class="cw-hd-close" onclick="cwToggle()" title="Close">&#x2715;</button>
    </div>
    <div class="cw-body">
      <div class="cw-contacts">
        <div class="cw-contacts-hd">HR Staff</div>
        <div class="cw-srch"><input type="text" id="cwSearch" placeholder="&#x1F50D; Search..." oninput="cwFilterContacts()"/></div>
        <div class="cw-contact-list" id="cwContactList"><div class="cw-cempty">Loading...</div></div>
      </div>
      <div class="cw-chat">
        <div class="cw-nosel" id="cwNoSel">
          <div class="cw-nosel-ic">&#x1F4AC;</div>
          <h4>No conversation selected</h4>
          <p>Pick HR Admin or staff<br>from the list to chat</p>
        </div>
        <div id="cwActiveChat" style="display:none;flex:1;flex-direction:column;overflow:hidden;">
          <div class="cw-chat-hd">
            <div class="cw-chat-av" id="cwHdAv"></div>
            <div style="flex:1;min-width:0;">
              <div class="cw-chat-name" id="cwHdName"></div>
              <div class="cw-chat-sub" id="cwHdSub"></div>
            </div>
            <div class="cw-online" title="Active"></div>
          </div>
          <div class="cw-msgs" id="cwMsgs"></div>
          <div class="cw-edit-bar" id="cwEditBar">
            <span>&#x270F;&#xFE0F; Editing message</span>
            <button class="cw-edit-cancel" onclick="cwCancelEdit()">&#x2715; Cancel</button>
          </div>
          <div class="cw-inp-row">
            <textarea class="cw-inp" id="cwInput" rows="1" placeholder="Type a message..." onkeydown="cwHandleKey(event)" oninput="cwAutoResize(this)"></textarea>
            <button class="cw-sendbtn" id="cwSendBtn" onclick="cwSend()">&#x27A4;</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
  const CW_ME     = <?php echo (int)($_SESSION['user_id'] ?? 0); ?>;
  const CW_NAME   = <?php echo json_encode(ucwords($_SESSION['user_name'] ?? 'Employee')); ?>;
  const CW_INIT   = <?php echo json_encode(strtoupper(implode('', array_map(fn($w)=>$w[0]??'', array_slice(explode(' ', ucwords($_SESSION['user_name']??'E')), 0, 2))))); ?>;
  const CW_API    = '../Admin/chat_api.php';
  const CW_COLORS = ['#2563eb','#22c55e','#f59e0b','#ef4444','#a855f7','#06b6d4','#ec4899','#f97316'];

  let cwContacts=[], cwActive=null, cwLastTime=null, cwPollTimer=null, cwContactTimer=null, cwIsOpen=false;
  let cwSendingFlag=false, cwEditId=null;

  const cwColor = n => CW_COLORS[Math.abs([...(n||'?')].reduce((a,c)=>a+c.charCodeAt(0),0))%CW_COLORS.length];
  const cwIni   = n => (n||'?').split(' ').map(w=>w[0]||'').join('').slice(0,2).toUpperCase();
  const cwFmtT  = dt => { if(!dt)return ''; const d=new Date(dt),now=new Date(); return d.toDateString()===now.toDateString()?d.toLocaleTimeString('en-PH',{hour:'numeric',minute:'2-digit'}):d.toLocaleDateString('en-PH',{month:'short',day:'numeric'}); };
  const cwFmtFull = dt => dt?new Date(dt).toLocaleTimeString('en-PH',{hour:'numeric',minute:'2-digit'}):'';
  const cwEsc   = s => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');

  function cwToggle(){
    cwIsOpen=!cwIsOpen;
    document.getElementById('cwWidget').classList.toggle('open',cwIsOpen);
    if(cwIsOpen){cwLoadContacts();clearInterval(cwContactTimer);cwContactTimer=setInterval(cwLoadContacts,10000);}
    else{clearInterval(cwPollTimer);clearInterval(cwContactTimer);}
  }

  async function cwLoadContacts(){
    try{
      const r=await fetch(`${CW_API}?action=contacts`);const j=await r.json();
      if(!j.success)return;cwContacts=j.data;cwRenderContacts(cwContacts);cwUpdateBadge();
    }catch(e){}
  }

  function cwRenderContacts(list){
    const el=document.getElementById('cwContactList');
    if(!list.length){el.innerHTML='<div class="cw-cempty">No HR staff found.</div>';return;}
    const rl={'admin':'Admin','hr_staff':'HR Staff','dept_head':'Dept Head'};
    el.innerHTML=list.map(c=>{
      const isAct=cwActive&&cwActive.user_id==c.user_id;
      const name=c.full_name||c.email||'HR Staff';
      const lastPrev=c.last_message?`<div class="cw-cs" style="font-style:italic;">${c.last_mine?'You: ':''}${c.last_message}</div>`:'';
      const hasPhoto=c.profile_photo&&c.profile_photo.trim()!=='';
      const avatarHtml=hasPhoto?
        `<img src="${cwEsc(c.profile_photo)}" alt="${cwEsc(name)}" style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;border:2px solid rgba(255,255,255,.4);"/>`:
        `<div style="background:${cwColor(name)};width:100%;height:100%;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.70rem;font-weight:800;color:#fff;border:2px solid rgba(255,255,255,.4);">${cwIni(name)}</div>`;
      return `<div class="cw-ci${isAct?' active':''}" onclick="cwOpenChat(${c.user_id})">
        <div class="cw-av" style="position:relative;overflow:hidden;">${avatarHtml}</div>
        <div class="cw-cn" title="${name}">${name}</div>
        <div class="cw-cs">${rl[c.role]||c.role||''}</div>
        ${lastPrev}
        <div class="cw-cs">${cwFmtT(c.last_at)||''}</div>
        ${c.unread>0?`<div class="cw-cu">${c.unread}</div>`:''}
      </div>`;
    }).join('');
  }

  function cwFilterContacts(){const q=document.getElementById('cwSearch').value.toLowerCase();cwRenderContacts(q?cwContacts.filter(c=>(c.full_name||c.email||'').toLowerCase().includes(q)):cwContacts);}

  function cwUpdateBadge(){
    const total=cwContacts.reduce((s,c)=>s+(c.unread||0),0);
    const b=document.getElementById('cwTopbarBadge');b.textContent=total||'';b.style.display=total?'flex':'none';
  }

  async function cwOpenChat(userId){
    cwActive=cwContacts.find(c=>c.user_id==userId)||{user_id:userId};
    cwLastTime=null;cwRenderContacts(cwContacts);cwCancelEdit();
    document.getElementById('cwNoSel').style.display='none';
    const area=document.getElementById('cwActiveChat');
    area.style.cssText='display:flex;flex:1;flex-direction:column;overflow:hidden;';
    const av=document.getElementById('cwHdAv');
    const name=cwActive.full_name||cwActive.email||'HR Staff';
    const hasPhoto=cwActive.profile_photo&&cwActive.profile_photo.trim()!=='';
    if(hasPhoto){
      av.innerHTML=`<img src="${cwEsc(cwActive.profile_photo)}" alt="${cwEsc(name)}" style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;"/>`;
      av.style.background='transparent';
    }else{
      av.innerHTML=cwIni(name);
      av.style.background=cwColor(name);
    }
    document.getElementById('cwHdName').textContent=name;
    const rl={'admin':'Administrator','hr_staff':'HR Staff','dept_head':'Department Head'};
    document.getElementById('cwHdSub').textContent=rl[cwActive.role]||cwActive.role||'HR Staff';
    document.getElementById('cwHeaderSub').textContent=name;
    document.getElementById('cwMsgs').innerHTML='<div style="text-align:center;padding:24px;font-size:.73rem;color:#94a3b8;">Loading...</div>';
    await cwLoadMsgs(true);
    document.getElementById('cwInput').focus();
    clearInterval(cwPollTimer);cwPollTimer=setInterval(()=>cwLoadMsgs(false),3000);
  }

  async function cwLoadMsgs(initial){
    if(!cwActive)return;
    try{
      let url=`${CW_API}?action=messages&with=${cwActive.user_id}`;
      if(!initial&&cwLastTime)url+=`&since_id=${cwLastTime}`;
      const r=await fetch(url);const j=await r.json();if(!j.success)return;
      if(initial){
        cwRenderMsgs(j.data);
        cwLastTime=j.data&&j.data.length?j.data[j.data.length-1].message_id:0;
      } else if(j.data&&j.data.length){
        cwAppendMsgs(j.data);cwLastTime=j.data[j.data.length-1].message_id;cwLoadContacts();
      }
    }catch(e){}
  }

  function cwRenderMsgs(msgs){
    const el=document.getElementById('cwMsgs');
    if(!msgs||!msgs.length){el.innerHTML='<div style="text-align:center;padding:28px 16px;font-size:.75rem;color:#94a3b8;line-height:1.7;">No messages yet.<br>Say hello! &#x1F44B;</div>';return;}
    let html='',ld='';
    msgs.forEach(m=>{
      const ds=new Date(m.sent_at).toLocaleDateString('en-PH',{weekday:'short',month:'short',day:'numeric'});
      if(ds!==ld){html+=`<div class="cw-ddiv"><span>${ds}</span></div>`;ld=ds;}
      html+=cwBuildMsg(m);
    });
    el.innerHTML=html;el.scrollTop=el.scrollHeight;
  }

  function cwAppendMsgs(msgs){
    const el=document.getElementById('cwMsgs');
    let html='';msgs.forEach(m=>html+=cwBuildMsg(m));
    el.insertAdjacentHTML('beforeend',html);el.scrollTop=el.scrollHeight;
  }

  function cwBuildMsg(m){
    const mine=parseInt(m.sender_id)===CW_ME;
    const name=cwActive?cwActive.full_name||cwActive.email||'':'?';
    const unsent=(m.is_unsent==1||m.is_unsent==='1');
    const edited=(m.is_edited==1||m.is_edited==='1')&&!unsent;
    const seen=(mine&&!unsent&&(m.is_read==1||m.is_read==='1'));
    const bubbleCls='cw-bubble'+(unsent?' unsent':'')+(edited?' edited':'');
    const msgText=unsent?'You unsent a message':cwEsc(m.message);
    const actions=mine&&!unsent?
      `<div class="cw-msg-actions">
        <button class="cw-act-btn" onclick="cwStartEdit(${m.message_id},this)" title="Edit">&#x270F;&#xFE0F;</button>
        <button class="cw-act-btn del" onclick="cwUnsend(${m.message_id})" title="Unsend">&#x1F5D1;&#xFE0F;</button>
      </div>`:'';
    const hasPhoto=!mine&&cwActive&&cwActive.profile_photo&&cwActive.profile_photo.trim()!=='';
    const avatarHtml=hasPhoto?
      `<img src="${cwEsc(cwActive.profile_photo)}" alt="${cwEsc(name)}" style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;"/>`:
      `<div style="background:${mine?'#2563eb':cwColor(name)};width:100%;height:100%;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.60rem;font-weight:800;color:#fff;">${mine?CW_INIT:cwIni(name)}</div>`;
    return `<div class="cw-mrow ${mine?'mine':'them'}" data-msgid="${m.message_id}">
      ${actions}
      <div class="cw-mav" style="position:relative;overflow:hidden;">${avatarHtml}</div>
      <div class="cw-msg-wrap">
        <div class="${bubbleCls}" data-raw="${cwEsc(m.message)}">${msgText}</div>
        <div class="cw-mt">${cwFmtFull(m.sent_at)}${seen?'<span class="cw-seen">&#x2713;&#x2713; Seen</span>':''}</div>
      </div>
    </div>`;
  }

  async function cwSend(){
    if(!cwActive||cwSendingFlag)return;
    const inp=document.getElementById('cwInput'),msg=inp.value.trim();if(!msg)return;
    if(cwEditId){cwSubmitEdit(cwEditId,msg);return;}
    cwSendingFlag=true;
    const btn=document.getElementById('cwSendBtn');btn.disabled=true;
    const sentMsg=msg;inp.value='';cwAutoResize(inp);
    try{
      const body=new FormData();body.append('action','send');body.append('to',cwActive.user_id);body.append('message',sentMsg);
      const r=await fetch(CW_API,{method:'POST',body});const j=await r.json();
      if(j.success){cwAppendMsgs([j.data]);cwLastTime=j.data.message_id;cwLoadContacts();}
    }catch(e){}
    finally{cwSendingFlag=false;btn.disabled=false;inp.focus();}
  }

  async function cwStartEdit(msgId,btn){
    const row=document.querySelector(`.cw-mrow[data-msgid="${msgId}"]`);if(!row)return;
    const bubble=row.querySelector('.cw-bubble');if(!bubble)return;
    cwEditId=msgId;
    const raw=bubble.getAttribute('data-raw')||bubble.textContent;
    const inp=document.getElementById('cwInput');
    inp.value=raw.replace(/<br>/g,'\n');cwAutoResize(inp);inp.focus();
    document.getElementById('cwEditBar').classList.add('show');
    document.getElementById('cwSendBtn').innerHTML='&#x2713;';
    document.querySelectorAll('.cw-bubble').forEach(b=>b.style.opacity='0.5');
    bubble.style.opacity='1';bubble.style.outline='2px solid #2563eb';bubble.style.outlineOffset='2px';
  }

  async function cwSubmitEdit(msgId,newMsg){
    cwSendingFlag=true;
    const btn=document.getElementById('cwSendBtn');btn.disabled=true;
    try{
      const body=new FormData();body.append('action','edit');body.append('message_id',msgId);body.append('message',newMsg);
      const r=await fetch(CW_API,{method:'POST',body});const j=await r.json();
      if(j.success){
        const row=document.querySelector(`.cw-mrow[data-msgid="${msgId}"]`);
        if(row){const b=row.querySelector('.cw-bubble');if(b){b.innerHTML=cwEsc(newMsg);b.setAttribute('data-raw',newMsg);b.classList.add('edited');}}
        cwCancelEdit();
      }
    }catch(e){}
    finally{cwSendingFlag=false;btn.disabled=false;}
  }

  function cwCancelEdit(){
    cwEditId=null;
    document.getElementById('cwEditBar').classList.remove('show');
    document.getElementById('cwSendBtn').innerHTML='&#x27A4;';
    document.getElementById('cwInput').value='';
    document.querySelectorAll('.cw-bubble').forEach(b=>{b.style.opacity='';b.style.outline='';b.style.outlineOffset='';});
  }

  async function cwUnsend(msgId){
    if(!confirm('Unsend this message? It will be removed for everyone.'))return;
    const body=new FormData();body.append('action','unsend');body.append('message_id',msgId);
    try{
      const r=await fetch(CW_API,{method:'POST',body});const j=await r.json();
      if(j.success){
        const row=document.querySelector(`.cw-mrow[data-msgid="${msgId}"]`);
        if(row){
          const b=row.querySelector('.cw-bubble');
          if(b){b.innerHTML='You unsent a message';b.className='cw-bubble unsent';b.removeAttribute('data-raw');}
          const acts=row.querySelector('.cw-msg-actions');if(acts)acts.remove();
        }
        cwLoadContacts();
      }
    }catch(e){}
  }

  function cwHandleKey(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();e.stopPropagation();cwSend();}}
  function cwAutoResize(el){el.style.height='auto';el.style.height=Math.min(el.scrollHeight,90)+'px';}

  // ── Notification toast CSS ──────────────────────────────────────
  const cwNotifStyle = document.createElement('style');
  cwNotifStyle.textContent = `
    .cw-notif-toast {
      position: fixed; bottom: 24px; left: 24px; z-index: 99999;
      display: flex; align-items: center; gap: 12px;
      background: #1e293b; color: #fff;
      padding: 13px 18px; border-radius: 14px;
      box-shadow: 0 8px 32px rgba(0,0,0,.28);
      font-family: 'Nunito', sans-serif; font-size: .85rem; font-weight: 700;
      cursor: pointer; max-width: 300px;
      animation: cwNotifIn .35s cubic-bezier(.34,1.4,.64,1) both;
      border-left: 4px solid #2563eb;
    }
    @keyframes cwNotifIn { from{opacity:0;transform:translateY(20px) scale(.92);} to{opacity:1;transform:translateY(0) scale(1);} }
    .cw-notif-toast:hover { background: #334155; }
    .cw-notif-av { width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:800;color:#fff;flex-shrink:0; }
    .cw-notif-body { flex:1;min-width:0; }
    .cw-notif-name { font-size:.82rem;font-weight:800;margin-bottom:2px; }
    .cw-notif-msg  { font-size:.74rem;color:#94a3b8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px; }
    .cw-notif-close { background:none;border:none;color:#64748b;font-size:1rem;cursor:pointer;padding:0;flex-shrink:0; }
    .cw-notif-close:hover { color:#fff; }
  `;
  document.head.appendChild(cwNotifStyle);

  let cwPrevCount = 0, cwNotifTimer = null;

  function cwShowNotifToast(name, message, userId) {
    const old = document.querySelector('.cw-notif-toast');
    if (old) old.remove();
    const col = cwColor(name);
    const ini = cwIni(name);
    const toast = document.createElement('div');
    toast.className = 'cw-notif-toast';
    toast.innerHTML =
      `<div class="cw-notif-av" style="background:${col}">${ini}</div>
       <div class="cw-notif-body">
         <div class="cw-notif-name">${name}</div>
         <div class="cw-notif-msg">${message}</div>
       </div>
       <button class="cw-notif-close" onclick="event.stopPropagation();this.parentNode.remove();">✕</button>`;
    toast.addEventListener('click', () => {
      toast.remove();
      cwToggle();
      if (userId) setTimeout(() => {
        const c = cwContacts.find(c => c.user_id == userId);
        if (c) cwOpenChat(userId);
      }, 400);
    });
    document.body.appendChild(toast);
    clearTimeout(cwNotifTimer);
    cwNotifTimer = setTimeout(() => {
      if (toast.parentNode) {
        toast.style.transition = 'opacity .4s, transform .4s';
        toast.style.opacity = '0'; toast.style.transform = 'translateY(10px)';
        setTimeout(() => { if(toast.parentNode) toast.remove(); }, 400);
      }
    }, 6000);
  }

  // Poll — badge + toast notification
  async function cwPollContacts(){
    try {
      const r = await fetch(`${CW_API}?action=contacts`);
      const j = await r.json();
      if (!j.success) return;
      const totalUnread = j.data.reduce((s,c) => s+(c.unread||0), 0);

      // Update 💬 chat badge only
      const cb = document.getElementById('cwTopbarBadge');
      if (cb) { cb.textContent = totalUnread||''; cb.style.display = totalUnread ? 'flex' : 'none'; }

      // Toast if new messages arrived while widget is closed
      if (!cwIsOpen && totalUnread > cwPrevCount) {
        const sender = j.data.find(c => c.unread > 0);
        if (sender) {
          const name = sender.full_name || sender.email || 'Someone';
          const msg  = sender.last_message || 'Sent you a message';
          cwShowNotifToast(name, msg, sender.user_id);
        }
      }

      if (cwIsOpen) { cwContacts = j.data; cwRenderContacts(cwContacts); }
      cwPrevCount = totalUnread;
    } catch(e) {}
  }
  // Run immediately on page load then every 5s
  cwPollContacts();
  setInterval(cwPollContacts, 5000);
  </script>
</body>
</html>