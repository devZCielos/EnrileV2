<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/_shared/helpers.php';
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

function hasPermanentLikeAccess($employmentType): bool {
  return isLeaveFeatureAvailable($employmentType);
}

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
// ...existing code continues...
// ============================================================
//  LGU ENRILE HRMS — Employee Portal
//  File: Employee/Employee_portal.php
// ============================================================
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// ── Helper: detect AJAX action request ──
$_isAjax = !empty($_GET['action']) || !empty($_POST['action']);

if (empty($_SESSION['user_id']) || empty($_SESSION['user_email'])) {
    if ($_isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'message'=>'Not logged in. Please refresh the page.']);
        exit();
    }
    header('Location: ../Login/login.php'); exit();
}
// Allow both 'employee' and 'dept_head' roles to use the Employee Portal
if (!in_array($_SESSION['user_role'], ['employee', 'dept_head']) && empty($_SESSION['switched_from_admin'])) {
  if ($_isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['success'=>false,'message'=>'Access denied.']);
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
// ...existing code...

// Tables `pds_records` and `saln_records` are defined in lgu_enrile_hrms.sql — no runtime CREATE needed.

// COE table bootstrap for environments where migration 009 has not been applied yet.
function ensureCoeRequestsTable(PDO $pdo): void {
    static $checked = false;
    if ($checked) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS coe_requests (
      coe_request_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      employee_id    INT UNSIGNED NOT NULL,
      purpose        TEXT NOT NULL,
      status         ENUM('Pending','Approved','Denied','Cancelled') NOT NULL DEFAULT 'Pending',
      admin_remarks  TEXT NULL,
      reviewed_by    INT UNSIGNED NULL,
      reviewed_at    TIMESTAMP NULL,
      is_deleted     TINYINT(1) NOT NULL DEFAULT 0,
      filed_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_coe_req_emp FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE,
      CONSTRAINT fk_coe_req_reviewer FOREIGN KEY (reviewed_by) REFERENCES user_accounts(user_id) ON DELETE SET NULL,
      INDEX idx_coe_status (status),
      INDEX idx_coe_employee_filed (employee_id, filed_at),
      INDEX idx_coe_deleted_status (is_deleted, status)
    ) ENGINE=InnoDB");
    $checked = true;
}

// Upload directory
if (!defined('UPLOAD_DIR')) define('UPLOAD_DIR', __DIR__ . '/../uploads/documents/');
if (!defined('UPLOAD_URL')) define('UPLOAD_URL', '../uploads/documents/');
if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
if (!defined('PHOTO_DIR')) define('PHOTO_DIR', __DIR__ . '/../uploads/profile_photos/');
if (!defined('PHOTO_URL')) define('PHOTO_URL', '../uploads/profile_photos/');
if (!is_dir(PHOTO_DIR)) mkdir(PHOTO_DIR, 0755, true);
if (!defined('LEAVE_SIG_DIR')) define('LEAVE_SIG_DIR', __DIR__ . '/../uploads/leave_signatures/');
if (!defined('LEAVE_SIG_URL')) define('LEAVE_SIG_URL', '../uploads/leave_signatures/');
if (!is_dir(LEAVE_SIG_DIR)) mkdir(LEAVE_SIG_DIR, 0755, true);

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
  $signatureMimes = [
    'image/png'  => 'png',
    'image/jpeg' => 'jpg',
  ];
$maxFileSize = 10 * 1024 * 1024; // 10 MB

// ════════════════════════════════════════════════════════════
//  AJAX HANDLER
// ════════════════════════════════════════════════════════════
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action) {
    ob_start();
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_verify(true); }
    $userId = (int)$_SESSION['user_id'];

    try {
      if (in_array($action, ['coe_requests', 'submit_coe_request', 'edit_coe_request', 'cancel_coe_request'], true)) {
        ensureCoeRequestsTable($pdo);
      }

        // Resolve employee_id from direct link first.
        $empRow = $pdo->prepare("SELECT employee_id FROM employees WHERE user_id = ?");
        $empRow->execute([$userId]);
        $employeeId = (int)($empRow->fetchColumn() ?: 0);

        // Fallback: use user_accounts.employee_id then back-link employees.user_id.
        if (!$employeeId) {
          $uaEmp = $pdo->prepare("SELECT employee_id FROM user_accounts WHERE user_id = ? LIMIT 1");
          $uaEmp->execute([$userId]);
          $linkedEmpId = (int)($uaEmp->fetchColumn() ?: 0);
          if ($linkedEmpId) {
            $employeeId = $linkedEmpId;
            $pdo->prepare("UPDATE employees SET user_id = ? WHERE employee_id = ? AND (user_id IS NULL OR user_id = 0)")
              ->execute([$userId, $employeeId]);
          }
        }

        switch ($action) {
          case 'holiday_calendar':
            $stmt = $pdo->query("SELECT holiday_date, description, is_recurring FROM holidays ORDER BY holiday_date ASC");
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

          // ── SESSION / LINKAGE CHECK ─────────────────────
          case 'whoami':
            echo json_encode([
              'success' => true,
              'session_user_id' => $userId,
              'session_user_email' => $_SESSION['user_email'] ?? null,
              'session_user_role' => $_SESSION['user_role'] ?? null,
              'resolved_employee_id' => $employeeId ?: null,
            ]);
            break;

          // ── DEPT HEAD: View pending leave requests in their department ──
          case 'dept_head_pending_leaves':
            if ($_SESSION['user_role'] !== 'dept_head') {
              echo json_encode(['success' => false, 'message' => 'Access denied.']); break;
            }
            // Get department_id of dept head
            $stmt = $pdo->prepare("SELECT department_id FROM employees WHERE user_id = ?");
            $stmt->execute([$userId]);
            $deptId = $stmt->fetchColumn();
            if (!$deptId) {
              echo json_encode(['success' => false, 'message' => 'No department assigned.']); break;
            }
            // Get all relevant leave requests for employees in this department (pending, approved, denied)
            $query = "
              SELECT lr.*, lt.name AS type_name, e.first_name, e.last_name,
                     d.name AS department,
                     COALESCE(lb.remaining_days, 0) AS leave_balance
              FROM leave_requests lr
              JOIN employees e ON e.employee_id = lr.employee_id
              JOIN leave_types lt ON lt.leave_type_id = lr.leave_type_id
              LEFT JOIN departments d ON d.department_id = e.department_id
              LEFT JOIN leave_balances lb ON lb.employee_id = lr.employee_id
                AND lb.leave_type_id = lr.leave_type_id
                AND lb.calendar_year = YEAR(lr.filed_at)
              WHERE e.department_id = ?
                AND lr.status IN ('Pending Dept Head', 'Pending HR', 'Denied by Dept Head')
              ORDER BY CASE WHEN lr.status = 'Pending Dept Head' THEN 0 ELSE 1 END ASC, lr.filed_at ASC
            ";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$deptId]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
            break;

          // ── DEPT HEAD: Approve leave request ──
          case 'dept_head_approve_leave':
            if ($_SESSION['user_role'] !== 'dept_head') {
              echo json_encode(['success' => false, 'message' => 'Access denied.']); break;
            }
            $leaveId = (int)($_POST['leave_id'] ?? 0);
            $remarks = trim($_POST['remarks'] ?? '');
            // Get department_id of dept head
            $stmt = $pdo->prepare("SELECT department_id FROM employees WHERE user_id = ?");
            $stmt->execute([$userId]);
            $deptId = $stmt->fetchColumn();
            // Check if leave belongs to this department and is pending dept head
            $stmt = $pdo->prepare("
              SELECT lr.request_id
              FROM leave_requests lr
              JOIN employees e ON e.employee_id = lr.employee_id
              WHERE lr.request_id = ? AND e.department_id = ? AND lr.status = 'Pending Dept Head'
            ");
            $stmt->execute([$leaveId, $deptId]);
            if (!$stmt->fetch()) {
              echo json_encode(['success' => false, 'message' => 'Invalid leave request.']); break;
            }
            // Approve: set status, dept_head_id, remarks, action date
            $pdo->prepare("
              UPDATE leave_requests
              SET status = 'Pending HR', dept_head_id = ?, dept_head_remarks = ?, dept_head_action_date = NOW()
              WHERE request_id = ?
            ")->execute([$userId, $remarks, $leaveId]);
            echo json_encode(['success' => true, 'message' => 'Leave request approved and forwarded to HR/Admin.']);
            break;

          // ── DEPT HEAD: Deny leave request ──
          case 'dept_head_deny_leave':
            if ($_SESSION['user_role'] !== 'dept_head') {
              echo json_encode(['success' => false, 'message' => 'Access denied.']); break;
            }
            $leaveId = (int)($_POST['leave_id'] ?? 0);
            $remarks = trim($_POST['remarks'] ?? '');
            // Get department_id of dept head
            $stmt = $pdo->prepare("SELECT department_id FROM employees WHERE user_id = ?");
            $stmt->execute([$userId]);
            $deptId = $stmt->fetchColumn();
            // Check if leave belongs to this department and is pending dept head
            $stmt = $pdo->prepare("
              SELECT lr.request_id
              FROM leave_requests lr
              JOIN employees e ON e.employee_id = lr.employee_id
              WHERE lr.request_id = ? AND e.department_id = ? AND lr.status = 'Pending Dept Head'
            ");
            $stmt->execute([$leaveId, $deptId]);
            if (!$stmt->fetch()) {
              echo json_encode(['success' => false, 'message' => 'Invalid leave request.']); break;
            }
            // Deny: set status, dept_head_id, remarks, action date
            $pdo->prepare("
              UPDATE leave_requests
              SET status = 'Denied by Dept Head', dept_head_id = ?, dept_head_remarks = ?, dept_head_action_date = NOW()
              WHERE request_id = ?
            ")->execute([$userId, $remarks, $leaveId]);
            echo json_encode(['success' => true, 'message' => 'Leave request denied.']);
            break;

            // ── PROFILE ──────────────────────────────────────


            case 'profile':
              $hasEmpSigCols = hrms_column_exists($pdo, 'employees', 'signature_file_path')
                && hrms_column_exists($pdo, 'employees', 'signature_original_name')
                && hrms_column_exists($pdo, 'employees', 'signature_uploaded_at');
              $sigSelect = $hasEmpSigCols
                ? "e.signature_file_path, e.signature_original_name, e.signature_uploaded_at"
                : "NULL AS signature_file_path, NULL AS signature_original_name, NULL AS signature_uploaded_at";
                $stmt = $pdo->prepare("
                SELECT e.*, d.name AS department_name,
                     $sigSelect
                    FROM   employees e
                    LEFT JOIN departments d ON d.department_id = e.department_id
                    WHERE  e.user_id = ?
                ");
                $stmt->execute([$userId]);
                $row = $stmt->fetch();
                if (!$row) {
                  // Fallback 1: link through user_accounts.employee_id
                  $stmtUa = $pdo->prepare(" 
                        SELECT e.*, d.name AS department_name,
                          $sigSelect
                    FROM   user_accounts u
                    JOIN   employees e   ON e.employee_id = u.employee_id
                    LEFT JOIN departments d ON d.department_id = e.department_id
                    WHERE  u.user_id = ?
                    LIMIT  1
                  ");
                  $stmtUa->execute([$userId]);
                  $row = $stmtUa->fetch();
                  if ($row) {
                    $pdo->prepare("UPDATE employees SET user_id = ? WHERE employee_id = ? AND (user_id IS NULL OR user_id = 0)")
                      ->execute([$userId, $row['employee_id']]);
                  }
                }
                if (!$row) {
                    // Try matching by email as fallback
                    $stmt2 = $pdo->prepare("
                         SELECT e.*, d.name AS department_name,
                           $sigSelect
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
                // ── CHECK EMPLOYMENT TYPE: Permanent-like types can view leave balances ──
                $empTypeCheck = $pdo->prepare("SELECT employment_type FROM employees WHERE employee_id = ?");
                $empTypeCheck->execute([$employeeId]);
                $empTypeRow = $empTypeCheck->fetch();
                $empType = $empTypeRow['employment_type'] ?? 'Unknown';
                if (!hasPermanentLikeAccess($empType)) {
                  echo json_encode(['success' => false, 'message' => "Leave balances are available to Permanent, Co-Terminus, and Elective employees only. You are employed as: {$empType}."]); break;
                }
                // ── AUTO-INITIALIZE LEAVE BALANCES FOR NEW CALENDAR YEAR ONLY ──
                // Only auto-create for the new year if the employee already had balances last year,
                // preserving admin-assigned leave type selections.
                $checkBal = $pdo->prepare("SELECT COUNT(*) FROM leave_balances WHERE employee_id = ? AND calendar_year = YEAR(NOW())");
                $checkBal->execute([$employeeId]);
                $hasBalance = (int)($checkBal->fetchColumn() ?: 0) > 0;
                
                if (!$hasBalance && $employeeId) {
                    // Carry forward the leave types from the previous year (preserves admin selection)
                    $prevYear = $pdo->prepare("
                        SELECT lb.leave_type_id, lt.max_days
                        FROM   leave_balances lb
                        JOIN   leave_types lt ON lt.leave_type_id = lb.leave_type_id AND lt.is_active = 1
                        WHERE  lb.employee_id = ? AND lb.calendar_year = YEAR(NOW()) - 1
                    ");
                    $prevYear->execute([$employeeId]);
                    $prevTypes = $prevYear->fetchAll();
                    
                    if ($prevTypes) {
                        // New year: carry forward only the previously-assigned leave types
                        foreach ($prevTypes as $lt) {
                            $pdo->prepare("
                                INSERT INTO leave_balances (employee_id, leave_type_id, calendar_year, total_credits, used_days)
                                VALUES (?, ?, YEAR(NOW()), ?, 0)
                                ON DUPLICATE KEY UPDATE total_credits = VALUES(total_credits)
                            ")->execute([$employeeId, $lt['leave_type_id'], $lt['max_days']]);
                        }
                    } else {
                        // Truly new employee with no history — use all active types as fallback
                        $ltypes = $pdo->query("SELECT leave_type_id, max_days FROM leave_types WHERE is_active = 1")->fetchAll();
                        foreach ($ltypes as $lt) {
                            $pdo->prepare("
                                INSERT INTO leave_balances (employee_id, leave_type_id, calendar_year, total_credits, used_days)
                                VALUES (?, ?, YEAR(NOW()), ?, 0)
                                ON DUPLICATE KEY UPDATE total_credits = VALUES(total_credits)
                            ")->execute([$employeeId, $lt['leave_type_id'], $lt['max_days']]);
                        }
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
                // P3-9: profile_photo column managed by config/migrations/004_service_records.sql
                $pdo->prepare("UPDATE employees SET profile_photo=? WHERE employee_id=?")->execute([$photoUrl,$employeeId]);
                echo json_encode(['success'=>true,'photo_url'=>$photoUrl,'url'=>$photoUrl]);
                break;

              // ── UPLOAD PROFILE SIGNATURE (PNG/JPG) ─────────
              case 'upload_signature':
                if (!$employeeId) { echo json_encode(['success'=>false,'message'=>'Employee not found.']); break; }
                if (!hrms_column_exists($pdo, 'employees', 'signature_file_path')
                  || !hrms_column_exists($pdo, 'employees', 'signature_original_name')
                  || !hrms_column_exists($pdo, 'employees', 'signature_uploaded_at')) {
                  echo json_encode(['success'=>false,'message'=>'System update required: run latest database migrations first.']);
                  break;
                }
                if (empty($_FILES['signature'])) { echo json_encode(['success'=>false,'message'=>'No signature file received.']); break; }

                $f = $_FILES['signature'];
                if ($f['error'] !== UPLOAD_ERR_OK) { echo json_encode(['success'=>false,'message'=>'Upload error.']); break; }
                if ($f['size'] > 5 * 1024 * 1024) { echo json_encode(['success'=>false,'message'=>'Signature must be under 5MB.']); break; }

                $fi = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($fi, $f['tmp_name']);
                finfo_close($fi);
                if (!array_key_exists($mime, $signatureMimes)) {
                  echo json_encode(['success'=>false,'message'=>'Only PNG and JPG signatures are allowed.']);
                  break;
                }

                $ext = $signatureMimes[$mime];
                $name = 'sig_emp_' . $employeeId . '_' . time() . '.' . $ext;
                $dest = LEAVE_SIG_DIR . $name;

                $oldSigStmt = $pdo->prepare("SELECT signature_file_path FROM employees WHERE employee_id = ?");
                $oldSigStmt->execute([$employeeId]);
                $oldSig = $oldSigStmt->fetchColumn();
                if ($oldSig) {
                  $usedByLeave = false;
                  if (hrms_column_exists($pdo, 'leave_requests', 'signature_file_path')) {
                    $usageStmt = $pdo->prepare("SELECT 1 FROM leave_requests WHERE signature_file_path = ? LIMIT 1");
                    $usageStmt->execute([$oldSig]);
                    $usedByLeave = (bool)$usageStmt->fetchColumn();
                  }
                  if (!$usedByLeave) {
                    $oldPath = __DIR__ . '/../' . ltrim(str_replace('../', '', $oldSig), '/');
                    if (file_exists($oldPath)) { @unlink($oldPath); }
                  }
                }

                if (!move_uploaded_file($f['tmp_name'], $dest)) {
                  echo json_encode(['success'=>false,'message'=>'Failed to save signature.']);
                  break;
                }

                $sigUrl = LEAVE_SIG_URL . $name;
                $origName = basename((string)$f['name']);
                $pdo->prepare("UPDATE employees SET signature_file_path = ?, signature_original_name = ?, signature_uploaded_at = NOW() WHERE employee_id = ?")
                  ->execute([$sigUrl, $origName, $employeeId]);

                echo json_encode(['success'=>true, 'signature_url'=>$sigUrl, 'signature_name'=>$origName]);
                break;

            // ── UPDATE PERSONAL INFO ─────────────────────────
            case 'update_personal':
                if (!$employeeId) {
                    echo json_encode(['success'=>false,'message'=>'Employee record not found.']); break;
                }
                $allowed = ['first_name','last_name','middle_name','suffix_name','gender',
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
                // H-7 — Validate email format and uniqueness
                if (array_key_exists('email_address', $_POST) && !empty(trim($_POST['email_address'] ?? ''))) {
                    $newEmailVal = trim($_POST['email_address']);
                    if (!filter_var($newEmailVal, FILTER_VALIDATE_EMAIL)) {
                        echo json_encode(['success'=>false,'message'=>'Please enter a valid email address.']); break;
                    }
                    $emailDup = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE email_address = ? AND employee_id != ?');
                    $emailDup->execute([$newEmailVal, $employeeId]);
                    if ((int)$emailDup->fetchColumn() > 0) {
                        echo json_encode(['success'=>false,'message'=>'This email address is already used by another employee.']); break;
                    }
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
                
                // ── CHECK EMPLOYMENT TYPE: Permanent-like types can view leave requests ──
                $empTypeCheck = $pdo->prepare("SELECT employment_type FROM employees WHERE employee_id = ?");
                $empTypeCheck->execute([$employeeId]);
                $empTypeRow = $empTypeCheck->fetch();
                $empType = $empTypeRow['employment_type'] ?? 'Unknown';
                if (!hasPermanentLikeAccess($empType)) {
                  echo json_encode(['success' => false, 'message' => "Leave requests are available to Permanent, Co-Terminus, and Elective employees only. You are employed as: {$empType}."]); break;
                }
                
                $status = $_GET['status'] ?? 'all';
                $where  = 'lr.employee_id = ?';
                $params = [$employeeId];
                $pendingStatuses = ['Pending', 'Pending Dept Head', 'Pending HR'];

                $leaveTypeId = (int)($_GET['leave_type_id'] ?? 0);
                if ($leaveTypeId > 0) {
                  $where .= ' AND lr.leave_type_id = ?';
                  $params[] = $leaveTypeId;
                }

                $dateMode = $_GET['date_mode'] ?? '';
                $dateValue = trim($_GET['date_value'] ?? '');
                if ($dateMode !== '' || $dateValue !== '') {
                  $rangeStart = null;
                  $rangeEnd = null;
                  if ($dateMode === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateValue)) {
                    [$year, $month, $day] = array_map('intval', explode('-', $dateValue));
                    if (checkdate($month, $day, $year)) {
                      $rangeStart = $dateValue;
                      $rangeEnd = $dateValue;
                    }
                  } elseif (
                    $dateMode === 'week'
                    && preg_match('/^\d{4}-W\d{2}$/', $dateValue)
                    && (int)substr($dateValue, 6, 2) >= 1
                    && (int)substr($dateValue, 6, 2) <= 53
                  ) {
                    $weekStart = new DateTimeImmutable($dateValue . '-1');
                    $rangeStart = $weekStart->format('Y-m-d');
                    $rangeEnd = $weekStart->modify('+6 days')->format('Y-m-d');
                  } elseif ($dateMode === 'month' && preg_match('/^\d{4}-\d{2}$/', $dateValue)) {
                    [$year, $month] = array_map('intval', explode('-', $dateValue));
                    if (checkdate($month, 1, $year)) {
                      $monthStart = new DateTimeImmutable($dateValue . '-01');
                      $rangeStart = $monthStart->format('Y-m-d');
                      $rangeEnd = $monthStart->modify('last day of this month')->format('Y-m-d');
                    }
                  } elseif ($dateMode === 'year' && preg_match('/^\d{4}$/', $dateValue) && (int)$dateValue >= 1900) {
                    $rangeStart = $dateValue . '-01-01';
                    $rangeEnd = $dateValue . '-12-31';
                  }
                  if (!$rangeStart || !$rangeEnd) {
                    echo json_encode(['success' => false, 'message' => 'Please provide a valid date filter.']); break;
                  }
                  $where .= ' AND lr.date_from <= ? AND lr.date_to >= ?';
                  $params[] = $rangeEnd;
                  $params[] = $rangeStart;
                }

                $filteredWhere = $where;
                $filteredParams = $params;
                if ($status !== 'all') {
                  if (strtolower($status) === 'pending') {
                    $where .= ' AND lr.status IN (?,?,?)';
                    array_push($params, ...$pendingStatuses);
                  } else {
                    $where .= ' AND lr.status = ?';
                    $params[] = ucfirst($status);
                  }
                }

                // H-10 — Paginate (default 200/page to prevent unbounded response)
                $prPerPage = max(1, min(200, (int)($_GET['per_page'] ?? 200)));
                $prPage    = max(1, (int)($_GET['page'] ?? 1));
                $prOffset  = ($prPage - 1) * $prPerPage;
                $cntLr = $pdo->prepare("SELECT COUNT(*) FROM leave_requests lr JOIN leave_types lt ON lt.leave_type_id = lr.leave_type_id WHERE $where");
                $cntLr->execute($params);
                $prTotal = (int)$cntLr->fetchColumn();
                $stmt = $pdo->prepare("
                  SELECT lr.*, lt.name AS type_name
                  FROM   leave_requests lr
                  JOIN   leave_types lt ON lt.leave_type_id = lr.leave_type_id
                  WHERE  $where
                  ORDER  BY lr.filed_at DESC
                  LIMIT  $prPerPage OFFSET $prOffset
                ");
                $stmt->execute($params);
                $rows = $stmt->fetchAll();

                // Count logic: group all pending-like statuses under 'Pending'
                $counts = $pdo->prepare("
                  SELECT lr.status, COUNT(*) AS cnt
                  FROM   leave_requests lr
                  JOIN   leave_types lt ON lt.leave_type_id = lr.leave_type_id
                  WHERE  $filteredWhere
                  GROUP  BY status
                ");
                $counts->execute($filteredParams);
                $statusCounts = ['Pending'=>0,'Approved'=>0,'Denied'=>0,'Cancelled'=>0];
                foreach ($counts->fetchAll() as $sc) {
                  if (in_array($sc['status'], $pendingStatuses)) {
                    $statusCounts['Pending'] += (int)$sc['cnt'];
                  } elseif (isset($statusCounts[$sc['status']])) {
                    $statusCounts[$sc['status']] = (int)$sc['cnt'];
                  }
                }

                $typeOptions = $pdo->prepare("
                  SELECT DISTINCT lt.leave_type_id, lt.name
                  FROM leave_requests lr
                  JOIN leave_types lt ON lt.leave_type_id = lr.leave_type_id
                  WHERE lr.employee_id = ?
                  ORDER BY lt.name
                ");
                $typeOptions->execute([$employeeId]);

                ob_clean();
                echo json_encode([
                  'success' => true,
                  'data' => $rows,
                  'counts' => $statusCounts,
                  'total' => $prTotal,
                  'page' => $prPage,
                  'per_page' => $prPerPage,
                  'type_options' => $typeOptions->fetchAll(),
                ]);
                break;

              // ── COE REQUESTS ────────────────────────────────
              case 'coe_requests':
                if (!$employeeId) {
                  echo json_encode(['success' => false, 'message' => 'No employee record linked to your account. Please contact HR to link your account.']); break;
                }

                $status = $_GET['status'] ?? 'all';
                $where  = 'employee_id = ? AND is_deleted = 0';
                $params = [$employeeId];
                if ($status !== 'all') {
                  $where .= ' AND status = ?';
                  $params[] = ucfirst(strtolower($status));
                }

                $stmt = $pdo->prepare("SELECT * FROM coe_requests WHERE $where ORDER BY filed_at DESC, coe_request_id DESC");
                $stmt->execute($params);
                $rows = $stmt->fetchAll();

                $countsStmt = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM coe_requests WHERE employee_id = ? AND is_deleted = 0 GROUP BY status");
                $countsStmt->execute([$employeeId]);
                $statusCounts = ['Pending'=>0,'Approved'=>0,'Denied'=>0,'Cancelled'=>0];
                foreach ($countsStmt->fetchAll() as $sc) {
                  if (isset($statusCounts[$sc['status']])) {
                    $statusCounts[$sc['status']] = (int)$sc['cnt'];
                  }
                }

                echo json_encode(['success'=>true,'data'=>$rows,'counts'=>$statusCounts]);
                break;

              case 'submit_coe_request':
                if (!$employeeId) {
                  echo json_encode(['success' => false, 'message' => 'Employee record not found.']); break;
                }
                $purpose = trim($_POST['purpose'] ?? '');
                if ($purpose === '') {
                  echo json_encode(['success'=>false,'message'=>'Purpose is required.']); break;
                }
                $pdo->prepare("INSERT INTO coe_requests (employee_id, purpose, status, filed_at, updated_at) VALUES (?, ?, 'Pending', NOW(), NOW())")
                  ->execute([$employeeId, $purpose]);
                echo json_encode(['success'=>true,'message'=>'Certificate of Employment request submitted.']);
                break;

              case 'edit_coe_request':
                if (!$employeeId) {
                  echo json_encode(['success' => false, 'message' => 'Employee record not found.']); break;
                }
                $coeId = (int)($_POST['coe_request_id'] ?? 0);
                $purpose = trim($_POST['purpose'] ?? '');
                if (!$coeId || $purpose === '') {
                  echo json_encode(['success'=>false,'message'=>'Missing request details.']); break;
                }
                $chk = $pdo->prepare("SELECT coe_request_id FROM coe_requests WHERE coe_request_id = ? AND employee_id = ? AND status = 'Pending' AND is_deleted = 0");
                $chk->execute([$coeId, $employeeId]);
                if (!$chk->fetch()) {
                  echo json_encode(['success'=>false,'message'=>'Only pending requests can be edited.']); break;
                }
                $pdo->prepare("UPDATE coe_requests SET purpose = ?, updated_at = NOW() WHERE coe_request_id = ?")
                  ->execute([$purpose, $coeId]);
                echo json_encode(['success'=>true,'message'=>'COE request updated.']);
                break;

              case 'cancel_coe_request':
                if (!$employeeId) {
                  echo json_encode(['success' => false, 'message' => 'Employee record not found.']); break;
                }
                $coeId = (int)($_POST['coe_request_id'] ?? 0);
                $chk = $pdo->prepare("SELECT coe_request_id FROM coe_requests WHERE coe_request_id = ? AND employee_id = ? AND status = 'Pending' AND is_deleted = 0");
                $chk->execute([$coeId, $employeeId]);
                if (!$chk->fetch()) {
                  echo json_encode(['success'=>false,'message'=>'Only pending requests can be cancelled.']); break;
                }
                $pdo->prepare("UPDATE coe_requests SET status = 'Cancelled', updated_at = NOW() WHERE coe_request_id = ?")
                  ->execute([$coeId]);
                echo json_encode(['success'=>true,'message'=>'COE request cancelled.']);
                break;

            // ── SUBMIT LEAVE ──────────────────────────────────
            case 'submit_leave':
                if (!$employeeId) {
                    echo json_encode(['success' => false, 'message' => 'Employee record not found.']); break;
                }
                
                // ── CHECK EMPLOYMENT TYPE: Permanent-like types can file leave ──
                $empTypeCheck = $pdo->prepare("SELECT employment_type FROM employees WHERE employee_id = ?");
                $empTypeCheck->execute([$employeeId]);
                $empTypeRow = $empTypeCheck->fetch();
                $empType = $empTypeRow['employment_type'] ?? 'Unknown';
                if (!hasPermanentLikeAccess($empType)) {
                  echo json_encode(['success' => false, 'message' => "Only Permanent, Co-Terminus, and Elective employees can file leave requests. Your employment type is: {$empType}. Please contact HR for more information."]); break;
                }
                
                $leaveTypeId = (int)($_POST['leave_type_id'] ?? 0);
                $dateFrom    = $_POST['date_from']            ?? '';
                $dateTo      = $_POST['date_to']              ?? '';
                $reason      = trim($_POST['reason']          ?? '');

                if (!$leaveTypeId || !$dateFrom || !$dateTo || !$reason) {
                    echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']); break;
                }
                // H-1 — Validate date order
                if ($dateFrom > $dateTo) {
                    echo json_encode(['success' => false, 'message' => 'Start date cannot be after end date.']); break;
                }

                // P2-4 — Compute business days server-side (excludes weekends + holidays)
                $daysReq = countBusinessDays($pdo, $dateFrom, $dateTo);
                if ($daysReq < 1) {
                    echo json_encode(['success' => false, 'message' => 'The selected date range contains no working days (weekends/holidays only).']); break;
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
                $ltNameStmt = $pdo->prepare("SELECT name FROM leave_types WHERE leave_type_id = ? LIMIT 1");
                $ltNameStmt->execute([$leaveTypeId]);
                $leaveTypeName = strtolower((string)($ltNameStmt->fetchColumn() ?: ''));
                $isSickLeave = (strpos($leaveTypeName, 'sick') !== false);

                if (!$isSickLeave && $dateFrom < $today) {
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

                // Determine if leave is with pay or without pay
                $withPay = ($daysReq <= $available) ? 1 : 0;

                if (!hrms_column_exists($pdo, 'employees', 'signature_file_path')
                  || !hrms_column_exists($pdo, 'leave_requests', 'signature_file_path')
                  || !hrms_column_exists($pdo, 'leave_requests', 'signature_original_name')
                  || !hrms_column_exists($pdo, 'leave_requests', 'signature_uploaded_at')) {
                  echo json_encode(['success' => false, 'message' => 'System update required: run latest database migrations first.']);
                  break;
                }

                // Require profile e-signature before filing leave
                $sigStmt = $pdo->prepare("SELECT signature_file_path, signature_original_name FROM employees WHERE employee_id = ?");
                $sigStmt->execute([$employeeId]);
                $sigRow = $sigStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $sigPath = trim((string)($sigRow['signature_file_path'] ?? ''));
                $sigName = trim((string)($sigRow['signature_original_name'] ?? ''));
                if ($sigPath === '') {
                    echo json_encode(['success' => false, 'message' => 'Please upload your e-signature in My Profile before filing leave.']);
                    break;
                }

                $stmt = $pdo->prepare("
                  INSERT INTO leave_requests
                    (employee_id, leave_type_id, date_from, date_to, days_requested, with_pay, reason, signature_file_path, signature_original_name, signature_uploaded_at, status, filed_at, updated_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'Pending Dept Head', NOW(), NOW())
                ");
                $stmt->execute([$employeeId, $leaveTypeId, $dateFrom, $dateTo, $daysReq, $withPay, $reason, $sigPath, $sigName !== '' ? $sigName : null]);
                $requestId = (int)$pdo->lastInsertId();

                // Prepare notification message for response
                $leavePayMsg = $withPay ? 'Leave request submitted successfully!' : 'Leave request submitted successfully! Note: This leave will be WITHOUT PAY as your leave credits are insufficient.';

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

                echo json_encode(['success' => true, 'message' => $leavePayMsg, 'with_pay' => $withPay]);
                break;

            // ── CANCEL LEAVE ──────────────────────────────────
            case 'cancel_leave':
                $reqId = (int)($_POST['request_id'] ?? 0);
                $pendingLike = [
                  'Pending', 'Pending Dept Head', 'Pending HR'
                ];
                $inClause = implode(',', array_fill(0, count($pendingLike), '?'));
                $chk = $pdo->prepare("SELECT request_id FROM leave_requests WHERE request_id = ? AND employee_id = ? AND status IN ($inClause)");
                $chk->execute(array_merge([$reqId, $employeeId], $pendingLike));
                if (!$chk->fetch()) {
                  echo json_encode(['success' => false, 'message' => 'Request not found or cannot be cancelled.']); break;
                }
                $pdo->prepare("UPDATE leave_requests SET status = 'Cancelled', updated_at = NOW() WHERE request_id = ?")->execute([$reqId]);
                echo json_encode(['success' => true, 'message' => 'Leave request cancelled.']);
                break;

            // ── LEAVE TYPES ───────────────────────────────────
            case 'leave_types':
                // ── CHECK EMPLOYMENT TYPE: Permanent-like types can access leave types ──
                $empTypeCheck = $pdo->prepare("SELECT employment_type FROM employees WHERE employee_id = ?");
                $empTypeCheck->execute([$employeeId]);
                $empTypeRow = $empTypeCheck->fetch();
                $empType = $empTypeRow['employment_type'] ?? 'Unknown';
                if (!hasPermanentLikeAccess($empType)) {
                  echo json_encode(['success' => false, 'message' => "Leave functionality is available to Permanent, Co-Terminus, and Elective employees only. You are employed as: {$empType}.", 'data' => []]); break;
                }
                // Fetch employee's gender for filtering
                $empInfo = $pdo->prepare("SELECT gender FROM employees WHERE employee_id = ?");
                $empInfo->execute([$employeeId]);
                $empRow  = $empInfo->fetch();
                $empGender     = $empRow['gender'] ?? 'Unknown';

                // Get leave types with balances — only types assigned to this employee
                $stmt = $pdo->prepare("
                    SELECT lt.leave_type_id, lt.name, lt.max_days,
                           lt.applicable_gender, lt.require_married,
                           lb.remaining_days
                    FROM   leave_types lt
                    JOIN   leave_balances lb ON lb.leave_type_id = lt.leave_type_id
                           AND lb.employee_id = ?
                           AND lb.calendar_year = YEAR(NOW())
                    WHERE  lt.is_active = 1
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
                    // P2-11 — Filter by audience and department
                    $annEmpInfo = $pdo->prepare("SELECT employment_type, department_id FROM employees WHERE employee_id=?");
                    $annEmpInfo->execute([$employeeId]);
                    $annEmpRow  = $annEmpInfo->fetch();
                    $annEmpType = $annEmpRow['employment_type'] ?? 'Permanent';
                    $annEmpDept = (int)($annEmpRow['department_id'] ?? 0);

                    $annStmt = $pdo->prepare("
                        SELECT announcement_id, title, body, priority, category AS tag, created_at
                        FROM   announcements
                        WHERE  status = 'Published'
                          AND  (publish_date IS NULL OR publish_date <= CURDATE())
                          AND  (expiry_date IS NULL OR expiry_date >= CURDATE())
                          AND  (
                              audience = 'All'
                              OR audience = ?
                              OR (audience = 'Department' AND (department_id IS NULL OR department_id = ?))
                          )
                        ORDER BY pinned DESC, created_at DESC
                        LIMIT  10
                    ");
                    $annStmt->execute([$annEmpType, $annEmpDept]);
                    $rows = $annStmt->fetchAll(PDO::FETCH_ASSOC);
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
                $empSig = null;
                if (hrms_column_exists($pdo, 'employees', 'signature_file_path')) {
                    $sg = $pdo->prepare("SELECT signature_file_path FROM employees WHERE employee_id = ?");
                    $sg->execute([$employeeId]);
                    $empSig = ['signature_file_path' => $sg->fetchColumn() ?: null];
                }
                echo json_encode(['success'=>true,'data'=>$row?:null,'employee'=>$empSig]);
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

                    // ── Leave request progress updates (last 30 days) ──
                    $lrS = $pdo->prepare("SELECT lr.request_id, lr.status, lr.filed_at, lr.reviewed_at,
                      lr.admin_remarks, lr.dept_head_action_date, lr.dept_head_remarks,
                      lt.name AS lt, lr.days_requested
                        FROM leave_requests lr
                        JOIN leave_types lt ON lt.leave_type_id = lr.leave_type_id
                        WHERE lr.employee_id = ?
                        AND lr.status IN ('Pending Dept Head','Pending HR','Approved','Denied','Denied by Dept Head')
                        AND (lr.filed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                        OR lr.dept_head_action_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                        OR lr.reviewed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))
                      ORDER BY GREATEST(lr.filed_at, COALESCE(lr.dept_head_action_date, lr.filed_at), COALESCE(lr.reviewed_at, lr.filed_at)) DESC LIMIT 10");
                    $lrS->execute([$employeeId]);
                    foreach ($lrS->fetchAll(PDO::FETCH_ASSOC) as $lr) {
                        $days = (int)$lr['days_requested'];
                      $requestLabel = 'Your ' . htmlspecialchars($lr['lt']) . ' request (' . $days . ' day' . ($days !== 1 ? 's' : '') . ')';
                      $events = [];
                      if (!empty($lr['filed_at']) && strtotime($lr['filed_at']) >= strtotime('-30 days')) {
                          $filingBody = $requestLabel . ' was submitted.';
                          if ($lr['status'] === 'Pending Dept Head') {
                            $filingBody = $requestLabel . ' was submitted and is awaiting Department Head review.';
                          }
                          $events[] = ['title' => 'Leave Request Submitted', 'body' => $filingBody, 'time' => $lr['filed_at'], 'icon' => '📋', 'color' => '#f59e0b'];
                        }
                      if (!empty($lr['dept_head_action_date']) && strtotime($lr['dept_head_action_date']) >= strtotime('-30 days')) {
                        $headDenied = $lr['status'] === 'Denied by Dept Head';
                        $headStatus = $headDenied ? 'Denied' : 'Approved';
                        $headBody = $requestLabel . ($headDenied ? ' was denied by your Department Head.' : ' was approved by your Department Head and forwarded to HR.');
                        if ($headDenied && !empty($lr['dept_head_remarks'])) {
                          $headBody .= ' — ' . htmlspecialchars($lr['dept_head_remarks']);
                        }
                        $events[] = ['title' => 'Department Head ' . $headStatus, 'body' => $headBody, 'time' => $lr['dept_head_action_date'], 'icon' => $headDenied ? '❌' : '✅', 'color' => $headDenied ? '#ef4444' : '#22c55e'];
                      }
                      if (in_array($lr['status'], ['Approved', 'Denied'], true) && !empty($lr['reviewed_at']) && strtotime($lr['reviewed_at']) >= strtotime('-30 days')) {
                        $hrApproved = $lr['status'] === 'Approved';
                        $hrBody = $requestLabel . ' was ' . strtolower($lr['status']) . ' by HR.';
                        if (!$hrApproved && !empty($lr['admin_remarks'])) {
                          $hrBody .= ' — ' . htmlspecialchars($lr['admin_remarks']);
                        }
                        $events[] = ['title' => 'HR ' . $lr['status'] . ' Leave', 'body' => $hrBody, 'time' => $lr['reviewed_at'], 'icon' => $hrApproved ? '✅' : '❌', 'color' => $hrApproved ? '#22c55e' : '#ef4444'];
                      }
                      foreach ($events as $event) {
                        $event['page'] = 'requests';
                        $event['nid'] = md5($event['title'] . $event['body'] . $event['time']);
                        $notifs[] = $event;
                      }
                    }

                    // ── Announcements (last 7 days) ───────────────────────────────────
                    try {
                        $anns = $pdo->query("SELECT title, created_at FROM announcements
                            WHERE status = 'Published'
                              AND (publish_date IS NULL OR publish_date <= CURDATE())
                              AND (expiry_date IS NULL OR expiry_date >= CURDATE())
                              AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
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

                    // ── Employee's contract expiry ────────────────────────────────────
                    try {
                      $contracts = $pdo->prepare("SELECT contract_id, end_date, DATEDIFF(end_date, CURDATE()) AS days_left
                        FROM contracts
                        WHERE employee_id = ? AND status = 'Active' AND end_date IS NOT NULL
                          AND DATEDIFF(end_date, CURDATE()) BETWEEN 0 AND 30
                        ORDER BY end_date ASC LIMIT 3");
                      $contracts->execute([$employeeId]);
                      foreach ($contracts->fetchAll(PDO::FETCH_ASSOC) as $contract) {
                        $daysLeft = (int)$contract['days_left'];
                        $notifs[] = [
                          'icon' => '📝', 'color' => '#ef4444', 'title' => 'Contract Expiring',
                          'body' => 'Your contract expires in ' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's'),
                          'time' => $contract['end_date'], 'page' => 'profile',
                          'nid' => md5('contract' . $contract['contract_id'] . $contract['end_date']),
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
                              AND read_at IS NULL
                            ORDER BY target_date DESC LIMIT 5");
                        $lnStmt->execute([$employeeId]);
                        $lnRows = $lnStmt->fetchAll(PDO::FETCH_ASSOC);
                        // Mark fetched records as delivered (is_sent=1)
                        $lnIds = array_column($lnRows, 'notification_id');
                        if ($lnIds) {
                            $ph = implode(',', array_fill(0, count($lnIds), '?'));
                            $pdo->prepare("UPDATE leave_notifications SET is_sent=1 WHERE notification_id IN ($ph)")->execute($lnIds);
                        }
                        foreach ($lnRows as $ln) {
                            $icon = $ln['notification_type'] === 'LEAVE_ENDING' ? '⏰' : '📋';
                            $color = $ln['notification_type'] === 'LEAVE_ENDING' ? '#f59e0b' : '#8b5cf6';
                            $notifs[] = [
                                'icon'  => $icon,
                                'color' => $color,
                                'title' => htmlspecialchars($ln['title']),
                                'body'  => htmlspecialchars($ln['message']),
                                'time'  => $ln['created_at'],
                                'page'  => 'requests',
                                'db_id' => (int)$ln['notification_id'],
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

                      // ── Certificate of Employment request resolved ────────────────────
                      try {
                        $coe = $pdo->prepare("SELECT status, admin_remarks, reviewed_at FROM coe_requests
                          WHERE employee_id = ? AND is_deleted = 0 AND status IN ('Approved','Denied')
                            AND reviewed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                          ORDER BY reviewed_at DESC LIMIT 5");
                        $coe->execute([$employeeId]);
                        foreach ($coe->fetchAll(PDO::FETCH_ASSOC) as $request) {
                          $ok = $request['status'] === 'Approved';
                          $notifs[] = [
                            'icon' => $ok ? '✅' : '❌', 'color' => $ok ? '#22c55e' : '#ef4444',
                            'title' => 'COE Request ' . $request['status'],
                            'body' => 'Your Certificate of Employment request was ' . strtolower($request['status']) . (!empty($request['admin_remarks']) ? ' — ' . htmlspecialchars($request['admin_remarks']) : ''),
                            'time' => $request['reviewed_at'], 'page' => 'coe',
                            'nid' => md5('coe' . $request['status'] . ($request['reviewed_at'] ?? '')),
                          ];
                        }
                      } catch (Exception $ex) {}

                      // ── Documents made available to this employee ─────────────────────
                      try {
                        $docs = $pdo->prepare("SELECT document_id, document_type, file_name, uploaded_at FROM documents
                          WHERE employee_id = ? AND uploaded_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                          ORDER BY uploaded_at DESC LIMIT 5");
                        $docs->execute([$employeeId]);
                        foreach ($docs->fetchAll(PDO::FETCH_ASSOC) as $document) {
                          $notifs[] = [
                            'icon' => '📁', 'color' => '#0891b2', 'title' => 'Document Available',
                            'body' => htmlspecialchars($document['document_type']) . ': ' . htmlspecialchars($document['file_name']),
                            'time' => $document['uploaded_at'], 'page' => 'documents',
                            'nid' => md5('doc' . $document['document_id'] . ($document['uploaded_at'] ?? '')),
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

            // ── MARK LEAVE NOTIFICATION AS READ ──────────────────────
            case 'mark_notif_read':
                $nid = (int)($_POST['notification_id'] ?? 0);
                if ($nid && $employeeId) {
                    $pdo->prepare("UPDATE leave_notifications SET read_at=NOW(), is_sent=1 WHERE notification_id=? AND employee_id=?")
                        ->execute([$nid, $employeeId]);
                }
                echo json_encode(['success'=>true]);
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
                // C-2: Admin/HR only
                if (!in_array($_SESSION['user_role'], ['admin', 'hr_staff']) && empty($_SESSION['switched_from_admin'])) {
                    echo json_encode(['success' => false, 'message' => 'Access denied.']); break;
                }
                // Admin: get all pending sr_requests
                $rows = $pdo->query("SELECT r.*, CONCAT(e.first_name,' ',e.last_name) AS emp_name, e.employee_code, d.name AS department
                    FROM sr_requests r JOIN employees e ON e.employee_id=r.employee_id
                    LEFT JOIN departments d ON d.department_id=e.department_id
                    ORDER BY r.requested_at DESC LIMIT 50")->fetchAll();
                echo json_encode(['success'=>true,'data'=>$rows]);
                break;

            case 'resolve_sr_request':
                // C-2: Admin/HR only
                if (!in_array($_SESSION['user_role'], ['admin', 'hr_staff']) && empty($_SESSION['switched_from_admin'])) {
                    echo json_encode(['success' => false, 'message' => 'Access denied.']); break;
                }
                $reqId  = (int)($_POST['req_id']??0);
                $status = $_POST['status']??'';
                $note   = trim($_POST['admin_note']??'');
                if (!in_array($status,['Approved','Denied'])) { echo json_encode(['success'=>false,'message'=>'Invalid status.']); break; }
                $pdo->prepare("UPDATE sr_requests SET status=?,admin_note=?,resolved_at=NOW() WHERE req_id=?")->execute([$status,$note,$reqId]);
                echo json_encode(['success'=>true,'message'=>"Request $status."]);
                break;

            case 'get_saln':
                $empTypeCheck = $pdo->prepare("SELECT employment_type FROM employees WHERE employee_id = ?");
                $empTypeCheck->execute([$employeeId]);
                $empType = $empTypeCheck->fetchColumn() ?: 'Unknown';
                if (!hasPermanentLikeAccess($empType)) {
                  echo json_encode(['success'=>false,'message'=>'SALN is only available to Permanent, Co-Terminus, and Elective employees.']); break;
                }
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
                $empTypeCheck = $pdo->prepare("SELECT employment_type FROM employees WHERE employee_id = ?");
                $empTypeCheck->execute([$employeeId]);
                $empType = $empTypeCheck->fetchColumn() ?: 'Unknown';
                if (!hasPermanentLikeAccess($empType)) {
                  echo json_encode(['success'=>false,'message'=>'SALN is only available to Permanent, Co-Terminus, and Elective employees.']); break;
                }
                if (!$employeeId) {
                    echo json_encode(['success'=>false,'message'=>"LINK_ERROR: Your user account (user_id=$userId) is not linked to any employee record. Go to Admin → Employees and set the User Account field for your employee profile."]);
                    break;
                }
                $data = json_decode(file_get_contents('php://input'), true) ?? [];
                $year = (int)($data['saln_year'] ?? date('Y'));
                $existing = $pdo->prepare("SELECT saln_id FROM saln_records WHERE employee_id = ? AND saln_year = ? LIMIT 1");
                $existing->execute([$employeeId, $year]); $existingId = $existing->fetchColumn();

                // Compute SALN financial totals server-side from row data.
                $snRealAssets = array_sum(array_map(
                  fn($r) => (float)($r['market_value'] ?? $r['assessed_value'] ?? 0),
                  $data['real_properties'] ?? []
                ));
                $snPersonalAssets = array_sum(array_map(
                  fn($r) => (float)($r['acq_cost'] ?? 0),
                  $data['personal_properties'] ?? []
                ));
                $snLiabilities = array_sum(array_map(
                  fn($r) => (float)($r['balance'] ?? 0),
                  $data['liabilities'] ?? []
                ));

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
                    'real_subtotal'       => $snRealAssets,
                    'personal_subtotal'   => $snPersonalAssets,
                    'total_assets'        => $snRealAssets + $snPersonalAssets,
                    'total_liabilities'   => $snLiabilities,
                    'net_worth'           => ($snRealAssets + $snPersonalAssets) - $snLiabilities,
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

            // P4-1 — Annex B (AS-1) / Annex C (AS-2) additional sheets
            case 'save_saln_annex':
                $empTypeCheck = $pdo->prepare("SELECT employment_type FROM employees WHERE employee_id = ?");
                $empTypeCheck->execute([$employeeId]);
                $empType = $empTypeCheck->fetchColumn() ?: 'Unknown';
                if (!hasPermanentLikeAccess($empType)) {
                  echo json_encode(['success'=>false,'message'=>'SALN is only available to Permanent, Co-Terminus, and Elective employees.']); break;
                }
                if (!$employeeId) {
                    echo json_encode(['success'=>false,'message'=>'Your user account is not linked to any employee record.']);
                    break;
                }
                $data  = json_decode(file_get_contents('php://input'), true) ?? [];
                $which = $data['which'] ?? '';
                if (!in_array($which, ['annex_b','annex_c'], true)) {
                    echo json_encode(['success'=>false,'message'=>'Invalid annex.']); break;
                }
                $year = (int)($data['saln_year'] ?? date('Y'));
                $prefix = $which === 'annex_b' ? 'as1' : 'as2';

                $real = $data['real_properties']     ?? [];
                $pers = $data['personal_properties'] ?? [];
                $liab = $data['liabilities']          ?? [];
                $biz  = $data['biz_interests']        ?? [];

                $fields = [
                    "{$prefix}_real_properties"     => json_encode($real),
                    "{$prefix}_personal_properties" => json_encode($pers),
                    "{$prefix}_liabilities"         => json_encode($liab),
                    "{$prefix}_biz_interests"       => json_encode($biz),
                    'updated_at'                    => date('Y-m-d H:i:s'),
                ];
                // Annex B (AS-1) additionally tracks its own subtotal/total columns.
                if ($which === 'annex_b') {
                    $realTotal = array_sum(array_map(fn($r) => (float)($r['market_value'] ?? $r['assessed_value'] ?? 0), $real));
                    $persTotal = array_sum(array_map(fn($r) => (float)($r['acq_cost'] ?? 0), $pers));
                    $liabTotal = array_sum(array_map(fn($r) => (float)($r['balance'] ?? 0), $liab));
                    $fields['as1_real_subtotal']     = $realTotal;
                    $fields['as1_personal_subtotal'] = $persTotal;
                    $fields['as1_total_assets']      = $realTotal + $persTotal;
                    $fields['as1_total_liabilities'] = $liabTotal;
                } else {
                    $fields['as2_name_info'] = json_encode($data['name_info'] ?? []);
                }

                $existing = $pdo->prepare("SELECT saln_id FROM saln_records WHERE employee_id = ? AND saln_year = ? LIMIT 1");
                $existing->execute([$employeeId, $year]); $existingId = $existing->fetchColumn();

                if ($existingId) {
                    $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
                    $pdo->prepare("UPDATE saln_records SET $set WHERE saln_id = ?")->execute([...array_values($fields), $existingId]);
                } else {
                    $fields['employee_id'] = $employeeId;
                    $fields['saln_year']   = $year;
                    $fields['created_at']  = date('Y-m-d H:i:s');
                    $cols = implode(',', array_keys($fields));
                    $phs  = implode(',', array_fill(0, count($fields), '?'));
                    $pdo->prepare("INSERT INTO saln_records ($cols) VALUES ($phs)")->execute(array_values($fields));
                }
                echo json_encode(['success'=>true,'message'=>ucfirst(str_replace('_',' ',$which)).' saved successfully.']);
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
// ── Resolve employment type for sidebar/page-level checks ──
$_empTypeRow = $pdo->prepare("SELECT employment_type FROM employees WHERE user_id = ?");
$_empTypeRow->execute([(int)$_SESSION['user_id']]);
$employmentType = $_empTypeRow->fetchColumn() ?: 'Permanent';


?>
<?php
// ── Employee Notifications ───────────────────────────────────────────────
$empNotifications=[]; $empNotifCount=0;
if(isset($pdo)&&isset($employeeId)&&$employeeId){try{
    // Leave request progress (last 30 days)
    $lrS=$pdo->prepare("SELECT lr.request_id,lr.status,lr.filed_at,lr.reviewed_at,lr.admin_remarks,
        lr.dept_head_action_date,lr.dept_head_remarks,lt.name AS lt,lr.days_requested
        FROM leave_requests lr JOIN leave_types lt ON lt.leave_type_id=lr.leave_type_id
        WHERE lr.employee_id=? AND lr.status IN ('Pending Dept Head','Pending HR','Approved','Denied','Denied by Dept Head')
        AND (lr.filed_at>=DATE_SUB(NOW(),INTERVAL 30 DAY) OR lr.dept_head_action_date>=DATE_SUB(NOW(),INTERVAL 30 DAY) OR lr.reviewed_at>=DATE_SUB(NOW(),INTERVAL 30 DAY))
        ORDER BY GREATEST(lr.filed_at,COALESCE(lr.dept_head_action_date,lr.filed_at),COALESCE(lr.reviewed_at,lr.filed_at)) DESC LIMIT 5");
    $lrS->execute([$employeeId]);
    foreach($lrS->fetchAll(PDO::FETCH_ASSOC) as $lr){
      $requestLabel='Your '.htmlspecialchars($lr['lt']).' request ('.(int)$lr['days_requested'].' day'.((int)$lr['days_requested']!==1?'s':'').')';
      $events=[];
      if(!empty($lr['filed_at'])&&strtotime($lr['filed_at'])>=strtotime('-30 days')){
        $body=$requestLabel.' was submitted.';
        if($lr['status']==='Pending Dept Head')$body=$requestLabel.' was submitted and is awaiting Department Head review.';
        $events[]=['icon'=>'📋','color'=>'#f59e0b','title'=>'Leave Request Submitted','body'=>$body,'time'=>$lr['filed_at']];
      }
      if(!empty($lr['dept_head_action_date'])&&strtotime($lr['dept_head_action_date'])>=strtotime('-30 days')){
        $headDenied=$lr['status']==='Denied by Dept Head';$headStatus=$headDenied?'Denied':'Approved';
        $body=$requestLabel.($headDenied?' was denied by your Department Head.':' was approved by your Department Head and forwarded to HR.');
        if($headDenied&&!empty($lr['dept_head_remarks']))$body.=' — '.htmlspecialchars($lr['dept_head_remarks']);
        $events[]=['icon'=>$headDenied?'❌':'✅','color'=>$headDenied?'#ef4444':'#22c55e','title'=>'Department Head '.$headStatus,'body'=>$body,'time'=>$lr['dept_head_action_date']];
      }
      if(in_array($lr['status'],['Approved','Denied'],true)&&!empty($lr['reviewed_at'])&&strtotime($lr['reviewed_at'])>=strtotime('-30 days')){
        $hrApproved=$lr['status']==='Approved';$body=$requestLabel.' was '.strtolower($lr['status']).' by HR.';
        if(!$hrApproved&&!empty($lr['admin_remarks']))$body.=' — '.htmlspecialchars($lr['admin_remarks']);
        $events[]=['icon'=>$hrApproved?'✅':'❌','color'=>$hrApproved?'#22c55e':'#ef4444','title'=>'HR '.$lr['status'].' Leave','body'=>$body,'time'=>$lr['reviewed_at']];
      }
      foreach($events as $event){$event['page']='requests';$empNotifications[]=$event;}
    }
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
    /* ── Dept Head Stat Cards ── */
.stat-card{background:var(--white);border-radius:var(--r);padding:18px 20px;border:1.5px solid var(--g200);box-shadow:var(--sh-sm);position:relative;overflow:hidden;animation:fadeUp 0.4s ease both;transition:transform 0.2s,box-shadow 0.2s;}
.stat-card:hover{transform:translateY(-3px);box-shadow:var(--sh-md);}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;border-radius:var(--r) var(--r) 0 0;}
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
.stat-value{font-size:2rem;font-weight:800;color:var(--g800);line-height:1;margin-bottom:3px;}
.stat-label{font-size:.78rem;color:var(--g400);font-weight:600;}
.stat-sub{font-size:.70rem;color:var(--g400);margin-top:6px;}

    .saln-table-scroll {
      width: 100%;
      overflow-x: auto;
      overflow-y: visible;
      -webkit-overflow-scrolling: touch;
      margin-bottom: 18px;
    }
    .saln-tbl {
      width: 100%;
    }
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
    .sb-item:disabled{opacity:.40;cursor:not-allowed;pointer-events:none;}
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
    .saln-tbl{border-collapse:collapse;font-size:.78rem;margin-bottom:6px;}
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
    .page{display:none;padding:22px 26px;width:100%;box-sizing:border-box;}
    .page.active{display:block;animation:pageFadeIn .35s ease both;}
    @keyframes pageFadeIn{from{opacity:0;}to{opacity:1;}}
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
    .recent-requests-card{display:flex;flex-direction:column;}
    .mini-tbl{width:100%;border-collapse:collapse;font-size:.81rem;}
    .mini-tbl th{padding:9px 14px;text-align:left;font-size:.65rem;font-weight:800;color:var(--g400);text-transform:uppercase;letter-spacing:.07em;border-bottom:1.5px solid var(--g200);background:var(--g50);}
    .mini-tbl td{padding:11px 14px;border-bottom:1px solid var(--g100);vertical-align:middle;}
    .mini-tbl tr:last-child td{border-bottom:none;}
    .mini-tbl tbody tr{transition:background .15s;cursor:pointer;}
    .mini-tbl tbody tr:hover{background:var(--g50);}
    .recent-req-pagination{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:auto;padding:12px 14px;border-top:1px solid var(--g100);font-size:.72rem;font-weight:700;color:var(--g400);flex-wrap:wrap;}
    .recent-req-page-buttons{display:flex;gap:4px;}
    .recent-req-page-btn{min-width:30px;height:30px;padding:0 8px;border:1.5px solid var(--g200);border-radius:7px;background:#fff;color:var(--g600);font:700 .75rem 'Nunito',sans-serif;cursor:pointer;}
    .recent-req-page-btn:hover:not(:disabled),.recent-req-page-btn.active{border-color:var(--primary);background:var(--primary);color:#fff;}
    .recent-req-page-btn:disabled{cursor:default;opacity:.4;}

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
    .req-filters{display:flex;align-items:end;gap:10px;margin-bottom:16px;padding:12px 14px;background:var(--white);border:1.5px solid var(--g200);border-radius:var(--r-sm);box-shadow:var(--sh-sm);flex-wrap:wrap;}
    .req-filter-field{display:flex;flex-direction:column;gap:4px;min-width:150px;flex:1;}
    .req-filter-field label{font-size:.64rem;font-weight:800;color:var(--g400);letter-spacing:.06em;text-transform:uppercase;}
    .req-filter-field select,.req-filter-field input{width:100%;box-sizing:border-box;padding:8px 10px;border:1.5px solid var(--g200);border-radius:7px;background:#fff;color:var(--g700);font:700 .78rem 'Nunito',sans-serif;outline:none;}
    .req-filter-field select:focus,.req-filter-field input:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(37,99,235,.10);}
    .req-filter-reset{height:34px;padding:0 12px;border:1.5px solid var(--g200);border-radius:7px;background:#fff;color:var(--g600);font:700 .76rem 'Nunito',sans-serif;cursor:pointer;}
    .req-filter-reset:hover{border-color:var(--primary);color:var(--primary);}
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
    .req-pagination{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:16px;padding:12px 14px;border:1.5px solid var(--g200);border-radius:var(--r-sm);background:var(--white);box-shadow:var(--sh-sm);font-size:.74rem;font-weight:700;color:var(--g400);flex-wrap:wrap;}
    .req-page-buttons{display:flex;gap:4px;}
    .req-page-btn{min-width:30px;height:30px;padding:0 8px;border:1.5px solid var(--g200);border-radius:7px;background:#fff;color:var(--g600);font:700 .75rem 'Nunito',sans-serif;cursor:pointer;}
    .req-page-btn:hover:not(:disabled),.req-page-btn.active{border-color:var(--primary);background:var(--primary);color:#fff;}
    .req-page-btn:disabled{cursor:default;opacity:.4;}

    /* FILE LEAVE */
    .fl-wrap{display:flex;flex-direction:column;gap:18px;}
    .fl-right{display:flex;flex-direction:column;gap:16px;}
    .fl-form-card{border:1px solid var(--g200);border-radius:16px;overflow:hidden;box-shadow:var(--shadow-sm);background:#fff;}
    .fl-reminder-card{border:1px solid var(--g200);border-radius:16px;overflow:hidden;box-shadow:var(--shadow-sm);background:#fff;}
    .fl-form-card .form-header{border-radius:0;}
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
    .holiday-calendar{margin-top:16px;padding:14px;border:1px solid var(--g200);border-radius:10px;background:var(--g50);}
    .holiday-calendar-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px;}
    .holiday-calendar-title{font-size:.78rem;font-weight:800;color:var(--g800);}
    .holiday-calendar-key{display:flex;align-items:center;gap:5px;color:var(--g500);font-size:.65rem;font-weight:700;}
    .holiday-calendar-key i{width:8px;height:8px;border-radius:50%;background:#16a34a;display:block;}
    .holiday-months{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;}
    .holiday-month{min-width:0;background:#fff;border:1px solid var(--g200);border-radius:8px;padding:8px;}
    .holiday-month-name{text-align:center;font-size:.7rem;font-weight:800;color:var(--g700);margin-bottom:6px;}
    .holiday-weekdays,.holiday-days{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;text-align:center;}
    .holiday-weekdays span{font-size:.54rem;color:var(--g400);font-weight:800;}
    .holiday-day{height:22px;display:grid;place-items:center;border-radius:5px;font-size:.62rem;color:var(--g700);position:relative;cursor:default;}
    .holiday-day.weekend{color:var(--g400);}.holiday-day.selected{background:#dbeafe;color:#1e40af;font-weight:800;}.holiday-day.holiday{background:#dcfce7;color:#166534;font-weight:800;cursor:help;}.holiday-day.holiday::after{content:'';position:absolute;bottom:2px;width:3px;height:3px;border-radius:50%;background:#16a34a;}
    .holiday-calendar-note{font-size:.66rem;color:var(--g500);line-height:1.45;margin:10px 2px 0;}
    .leave-date-input{display:flex;align-items:center;border:1.5px solid var(--g200);border-radius:var(--r-sm);background:#fff;transition:border-color .2s,box-shadow .2s;}
    .leave-date-input:focus-within{border-color:var(--primary);box-shadow:0 0 0 3px rgba(37,99,235,.1);}
    .leave-date-input input{min-width:0;flex:1;border:0!important;background:transparent!important;box-shadow:none!important;}
    .leave-date-input input.err{border-radius:var(--r-sm);background:#fef2f2!important;}
    .leave-date-trigger{width:38px;height:36px;border:0;border-left:1px solid var(--g200);background:transparent;color:var(--g600);cursor:pointer;font-size:.9rem;}
    .leave-date-trigger:hover{color:var(--primary);background:var(--primary-lt);}.leave-date-help{margin:8px 0 0;color:var(--g500);font-size:.7rem;line-height:1.45;}
    .leave-date-picker{margin-top:14px;padding:14px;background:#fff;border:2px solid var(--g200);border-radius:12px;box-shadow:var(--shadow-sm);}
    .leave-date-picker-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px;}.leave-date-picker-title{font-size:.82rem;font-weight:800;color:var(--g800);}
    .leave-date-picker-nav{display:flex;gap:5px;}.leave-date-picker-nav button{width:30px;height:30px;border:1px solid var(--g200);border-radius:6px;background:#fff;color:var(--g600);font-weight:800;cursor:pointer;}.leave-date-picker-nav button:hover{background:var(--primary-lt);color:var(--primary);border-color:var(--primary);}
    .leave-date-picker-clear{min-width:90px;height:36px;padding:0 18px;border:1px solid var(--primary);border-radius:8px;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;font-weight:800;font-size:.95rem;cursor:pointer;box-shadow:0 2px 6px rgba(37,99,235,.18);transition:all .18s ease;}.leave-date-picker-clear:hover{background:linear-gradient(135deg,#1d4ed8,#1e40af);transform:translateY(-1px);box-shadow:0 4px 10px rgba(37,99,235,.2);}
    .leave-date-picker-grid{display:grid;grid-template-columns:1fr;gap:10px;}.leave-picker-month{border:1px solid var(--g200);border-radius:10px;padding:10px;min-width:0;width:100%;background:#fff;}.leave-picker-month-title{text-align:center;color:var(--g700);font-size:.72rem;font-weight:800;margin-bottom:8px;}.leave-picker-weekdays,.leave-picker-days{display:grid;grid-template-columns:repeat(7,1fr);gap:3px;text-align:center;}.leave-picker-weekdays span{font-size:.56rem;font-weight:800;color:var(--g400);padding:3px 0;}.leave-picker-day{height:31px;border:1px solid var(--g200);border-radius:6px;background:transparent;color:var(--g700);font:700 .68rem 'Nunito',sans-serif;cursor:pointer;position:relative;display:flex;align-items:center;justify-content:center;}.leave-picker-day:hover:not(:disabled){background:#dbeafe;color:#1e40af;}.leave-picker-day.weekend{color:var(--g400);cursor:not-allowed;}.leave-picker-day.holiday{background:#dcfce7;color:#166534;cursor:not-allowed;}.leave-picker-day.holiday::after{content:'';position:absolute;bottom:2px;left:50%;width:3px;height:3px;margin-left:-1.5px;background:#16a34a;border-radius:50%;}.leave-picker-day.conflict{background:#fecaca;color:#991b1b;cursor:not-allowed;}.leave-picker-day.conflict::after{content:'';position:absolute;bottom:2px;left:50%;width:3px;height:3px;margin-left:-1.5px;background:#dc2626;border-radius:50%;}.leave-picker-day.in-range:not(.holiday):not(.weekend):not(.conflict){background:#dbeafe;color:#1e40af;}.leave-picker-day.start-date:not(.holiday):not(.weekend):not(.conflict),.leave-picker-day.end-date:not(.holiday):not(.weekend):not(.conflict){background:linear-gradient(135deg,#2563eb,#1e40af);color:#fff;}.leave-picker-day:disabled{opacity:1;}.leave-picker-day.empty{pointer-events:none;border:none;background:transparent;}.leave-date-picker-foot{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:12px;color:var(--g500);font-size:.66rem;}.leave-date-picker-key{display:flex;gap:10px;flex-wrap:wrap;}.leave-date-picker-key span{display:flex;align-items:center;gap:4px;}.leave-date-picker-key i{width:9px;height:9px;border-radius:3px;display:block;background:#dcfce7;border:1px solid #16a34a;}.leave-date-picker-key i.weekend{background:var(--g100);border-color:var(--g300);}
    .fl-cal-box{text-align:center;}
    .fl-cal-range{font-size:.84rem;font-weight:800;color:var(--g800);margin-bottom:10px;}
    .fl-cal-days-strip{display:flex;gap:5px;flex-wrap:wrap;justify-content:center;}
    .fl-cal-day{width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:800;}
    .fl-cal-day.in-range{background:linear-gradient(135deg,#2563eb,#1e40af);color:#fff;}
    .fl-cal-day.weekend{background:var(--g100);color:var(--g400);}
    .fl-cal-day.holiday{background:#dcfce7;color:#166534;}
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

    /* SIGNATURE CROP MODAL */
    .sig-crop-modal{max-width:880px;width:96%;}
    .sig-crop-body{padding:16px 18px 18px;}
    .sig-crop-grid{display:grid;grid-template-columns:minmax(0,1.9fr) minmax(0,1fr);gap:14px;align-items:start;}
    .sig-canvas-wrap{background:#f8fafc;border:1.5px solid var(--g200);border-radius:10px;padding:10px;}
    .sig-canvas-hint{font-size:.68rem;color:var(--g500);margin-bottom:7px;}
    .sig-canvas{width:100%;height:320px;border-radius:8px;background:#fff;border:1px solid var(--g200);display:block;cursor:grab;touch-action:none;}
    .sig-canvas.dragging{cursor:grabbing;}
    .sig-crop-side{background:#f8fafc;border:1.5px solid var(--g200);border-radius:10px;padding:10px;}
    .sig-preview-box{height:110px;border:1px dashed var(--g300);border-radius:8px;background:#fff;display:flex;align-items:center;justify-content:center;padding:8px;margin-bottom:10px;}
    .sig-preview-box img{max-width:100%;max-height:100%;object-fit:contain;display:block;}
    .sig-zoom-label{font-size:.70rem;font-weight:700;color:var(--g600);margin-bottom:6px;}
    .sig-zoom-range{width:100%;accent-color:#2563eb;}
    .sig-zoom-value{font-size:.68rem;color:var(--g500);margin-top:4px;text-align:right;}
    @media(max-width:768px){
      .sig-crop-grid{grid-template-columns:1fr;}
      .sig-canvas{height:250px;}
    }

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
      .leave-date-picker-grid{grid-template-columns:1fr;}.leave-date-picker{max-height:70vh;overflow-y:auto;}
      
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
      /* --- ANNEX B TABLES: Prevent input overlap and improve alignment --- */
      .saln-tbl {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        margin-bottom: 18px;
        background: #f8fafc;
        table-layout: fixed;
      }
      .saln-tbl th, .saln-tbl td {
        border: 1px solid #e2e8f0;
        padding: 8px 6px;
        text-align: left;
        vertical-align: middle;
        background: #f8fafc;
        font-size: 13px;
        word-break: break-word;
        white-space: normal;
      }
      .saln-tbl th {
        font-weight: 700;
        color: #334155;
        background: #f1f5f9;
        font-size: 11px;
        word-break: break-word;
        white-space: normal;
        max-width: 140px;
        line-height: 1.2;
        overflow-wrap: break-word;
        hyphens: auto;
      }
      .saln-tbl th span {
        display: block;
        font-weight: normal;
        font-size: 10px;
        line-height: 1.15;
        margin-top: 2px;
        max-width: 140px;
        white-space: normal;
        word-break: break-word;
        overflow-wrap: break-word;
        hyphens: auto;
      }
      .saln-tbl input[type="text"],
      .saln-tbl input[type="number"] {
        width: 100%;
        min-width: 0;
        box-sizing: border-box;
        padding: 7px 10px;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        font-size: 13px;
        background: #fff;
        outline: none;
        transition: border-color 0.2s;
        margin: 0;
        display: block;
      }
      .saln-tbl input[type="text"]:focus,
      .saln-tbl input[type="number"]:focus {
        border-color: #2563eb;
        background: #f0f7ff;
      }
      /* Responsive fix for smaller screens */
      @media (max-width: 1100px) {
        .saln-tbl th, .saln-tbl td {
          font-size: 11px;
          padding: 6px 3px;
        }
        .saln-tbl input[type="text"],
        .saln-tbl input[type="number"] {
          font-size: 12px;
          padding: 6px 7px;
        }
      }
      @media (max-width: 800px) {
        .saln-tbl, .saln-tbl thead, .saln-tbl tbody, .saln-tbl tr, .saln-tbl th, .saln-tbl td {
          display: block;
          width: 100%;
        }
        .saln-tbl tr {
          margin-bottom: 10px;
        }
        .saln-tbl th {
          background: #e0e7ef;
          font-size: 12px;
          padding: 7px 5px;
        }
        .saln-tbl td {
          padding: 5px 3px;
        }
      }
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
    <?php if ($_SESSION['user_role'] === 'dept_head'): ?>
      <button class="sb-item" onclick="goPage('dept_head_leaves',this)"><div class="sb-icon">🗂️</div>Dept Head Leave Approval<span class="sb-badge" id="dhPendBadge"></span></button>
    <?php endif; ?>
    <div class="sb-label">Documents</div>
    <button class="sb-item" onclick="goPage('documents',this)"><div class="sb-icon">📁</div>All Documents</button>
    <button class="sb-item sb-sub" onclick="goPage('pds',this)"><div class="sb-icon">📋</div>PDS</button>
    <?php if (hasPermanentLikeAccess($employmentType)): ?>
    <button class="sb-item sb-sub" onclick="goPage('saln',this)"><div class="sb-icon">📊</div>SALN(Annex A)</button>
    <?php else: ?>
    <button class="sb-item sb-sub" disabled title="SALN is only available to Permanent, Co-Terminus, and Elective employees"><div class="sb-icon">📊</div>SALN</button>
    <?php endif; ?>
    <button class="sb-item sb-sub annex-link" onclick="goPage('annex_b',this)"><div class="sb-icon">🅱️</div>SALN Annex B</button>
    <button class="sb-item sb-sub annex-link" onclick="goPage('annex_c',this)"><div class="sb-icon">🇨</div>SALN Annex C</button>
    <button class="sb-item sb-sub" onclick="goPage('service_record',this)"><div class="sb-icon">📜</div>Service Record</button>
    <button class="sb-item sb-sub" onclick="goPage('coe',this)"><div class="sb-icon">📄</div>Certificate of Employment</button>
  </nav>
    
    <!-- Main content area only; sidebar card removed -->
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
                <div class="np-text"><?=$en['body']?></div>
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
      <div class="card recent-requests-card">
        <div class="card-header">
          <div><div class="card-title">📋 My Recent Leave Requests</div><div class="card-sub">Latest applications</div></div>
          <button class="card-link" onclick="goPage('requests',document.querySelectorAll('.sb-item')[2])">View all →</button>
        </div>
        <table class="mini-tbl">
          <thead><tr><th>Type</th><th>Duration</th><th>Days</th><th>Status</th></tr></thead>
          <tbody id="recentReqTbody"><tr><td colspan="4" style="text-align:center;padding:24px;color:var(--g400);">Loading…</td></tr></tbody>
        </table>
        <div class="recent-req-pagination" id="recentReqPagination" hidden>
          <span id="recentReqPageInfo"></span>
          <div class="recent-req-page-buttons" id="recentReqPageButtons"></div>
        </div>
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
        <div style="margin-bottom:14px;padding:12px;border:1px dashed var(--g200);border-radius:10px;background:#f8fafc;">
          <div style="font-size:.78rem;font-weight:800;color:var(--g700);margin-bottom:6px;">✍️ Leave E-Signature (PNG/JPG)</div>
          <div id="sigStatusText" style="font-size:.73rem;color:var(--g500);margin-bottom:8px;">No signature uploaded.</div>
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <button type="button" id="btnUploadSignature" onclick="document.getElementById('signatureFileInput').click()" style="padding:8px 14px;background:linear-gradient(135deg,var(--primary),var(--primary-dk));color:#fff;border:none;border-radius:8px;font-size:.78rem;font-weight:700;cursor:pointer;">📎 Upload Signature</button>
            <input type="file" id="signatureFileInput" accept="image/png,image/jpeg" style="display:none;" onchange="openSignatureCropper(this)" />
            <span id="sigFileName" style="font-size:.72rem;color:var(--g500);"></span>
          </div>
          <div style="font-size:.68rem;color:var(--g400);margin-top:8px;">This saved signature is required for leave filing and will be used in the printed leave form.</div>
          <div id="sigCropQuickPreviewWrap" style="display:none;margin-top:10px;">
            <div style="font-size:.66rem;color:var(--g500);margin-bottom:5px;">Latest crop preview</div>
            <img id="sigCropQuickPreview" alt="Signature crop preview" style="display:block;max-width:240px;max-height:70px;object-fit:contain;background:#fff;border:1px dashed var(--g200);border-radius:6px;padding:4px;" />
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
    <div class="req-filters">
      <div class="req-filter-field">
        <label for="reqStatusFilter">Status</label>
        <select id="reqStatusFilter" onchange="filterRequestStatus()">
          <option value="all">All</option>
          <option value="pending">Pending</option>
          <option value="approved">Approved</option>
          <option value="denied">Denied</option>
          <option value="cancelled">Cancelled</option>
        </select>
      </div>
      <div class="req-filter-field">
        <label for="reqTypeFilter">Leave type</label>
        <select id="reqTypeFilter" onchange="applyRequestFilters()">
          <option value="">All leave types</option>
        </select>
      </div>
      <div class="req-filter-field">
        <label for="reqDateMode">Date period</label>
        <select id="reqDateMode" onchange="updateRequestDateInput()">
          <option value="">Any date</option>
          <option value="date">Specific date</option>
          <option value="week">Week</option>
          <option value="month">Month</option>
          <option value="year">Year</option>
        </select>
      </div>
      <div class="req-filter-field" id="reqDateValueField" hidden>
        <label for="reqDateValue" id="reqDateValueLabel">Date</label>
        <input id="reqDateValue" type="date" onchange="applyRequestFilters()">
      </div>
      <button type="button" class="req-filter-reset" onclick="clearRequestFilters()">Reset filters</button>
    </div>
    <div class="req-list" id="reqList"></div>
    <div class="empty-msg" id="reqEmpty" style="display:none;"><div class="ei">📋</div><p>No leave requests here.</p></div>
    <div class="req-pagination" id="reqPagination" hidden>
      <span id="reqPageInfo"></span>
      <div class="req-page-buttons" id="reqPageButtons"></div>
    </div>
  </div>

  <!-- ══ FILE LEAVE ══ -->
  <div class="page" id="page-file">
    <div class="fl-wrap">
      <div class="fl-reminder-card">
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

      <div class="fl-form-card">
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
          <input type="hidden" id="flFrom"/>
          <input type="hidden" id="flTo"/>
          <div class="fg-row leave-date-fields">
            <div class="fg"><label for="flFromDisplay">Date From <span class="req">*</span></label><div class="leave-date-input"><input type="text" id="flFromDisplay" placeholder="mm/dd/yyyy" inputmode="numeric" autocomplete="off" aria-describedby="leaveDateHelp"/><button type="button" class="leave-date-trigger" data-picker-target="from" aria-label="Choose leave start date" title="Choose date">▣</button></div></div>
            <div class="fg"><label for="flToDisplay">Date To <span class="req">*</span></label><div class="leave-date-input"><input type="text" id="flToDisplay" placeholder="mm/dd/yyyy" inputmode="numeric" autocomplete="off" aria-describedby="leaveDateHelp"/><button type="button" class="leave-date-trigger" data-picker-target="to" aria-label="Choose leave end date" title="Choose date">▣</button></div></div>
          </div>
          <p class="leave-date-help" id="leaveDateHelp">Weekends and declared holidays are unavailable and do not use leave credits.</p>
          <div class="leave-date-picker" id="leaveDatePicker" hidden></div>

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
          <div style="margin-top:10px;padding:10px 12px;border:1px solid #bfdbfe;background:#eff6ff;border-radius:9px;font-size:.74rem;color:#1e40af;line-height:1.5;">
            <strong>Signature Required:</strong> Leave submission uses your saved profile e-signature (PNG/JPG). If not set, upload it first in <strong>My Profile</strong>.
          </div>
          <button class="submit-btn" onclick="submitLeave()">📤 Submit Leave Request</button>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ LEAVE BALANCES ══ -->
  <div class="page" id="page-balances">
    <div class="form-header">
      <h3>⚖️ Leave Balances</h3>
      <p>View your available leave credits and usage history</p>
    </div>
    <div id="balancesGrid" class="balances-grid"></div>
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
    <div class="page" id="page-saln" style="position:relative;z-index:2;">
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

        <!-- Compliance For -->
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

    <!-- ══ ANNEX B PAGE ══ -->
    <div class="page" id="page-annex_b" style="position:relative;z-index:2;">
      <div class="form-page">
        <h2>Annex B — Declarant's Assets, Liabilities and Net Worth (Sheet AS-1)</h2>
        <div style="margin-bottom:10px;font-size:.92rem;">Use this sheet for additional properties and liabilities of the declarant.</div>
        <div class="fg"><label>As of:</label><input type="text" placeholder="e.g. December 31, 2025" aria-label="As of date"/></div>
        <div class="form-section-hd">1. ASSETS</div>
        <div class="form-section-body">
          <div style="font-weight:700;margin-bottom:6px;">a. Real Properties</div>
          <div class="saln-table-scroll">
            <table class="saln-tbl" style="min-width:1100px;">
            <thead style="background:#f1f5f9;">
              <tr>
                <th style="min-width:170px;">Description<br><span style="font-weight:normal;font-size:5pt;">(e.g. lot, house and lot, condominium and improvements)</span></th>
                <th style="min-width:120px;">Kind<br><span style="font-weight:normal;font-size:5pt;">(e.g. residential, commercial, industrial, agricultural, and mixed use)</span></th>
                <th style="min-width:140px;">Exact Location</th>
                <th style="min-width:120px;">Assessed Value</th>
                <th style="min-width:140px;">Current Fair Market Value</th>
                <th style="min-width:110px;">Acquisition Year</th>
                <th style="min-width:120px;">Acquisition Mode</th>
                <th style="min-width:140px;">Acquisition Cost</th>
                <th class="del-cell" style="border:none;background:transparent;"></th>
              </tr>
            </thead>
            <tbody id="annexB-real-tbody">
              <tr>
                <td><input type="text" placeholder="e.g. House and Lot" aria-label="Description" style="width:100%;min-width:140px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="e.g. Residential" aria-label="Kind" style="width:100%;min-width:100px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="e.g. 123 Main St." aria-label="Exact Location" style="width:100%;min-width:120px;box-sizing:border-box;"/></td>
                <td><input type="number" placeholder="e.g. 1000000" aria-label="Assessed Value" min="0" step="any" style="width:100%;min-width:100px;box-sizing:border-box;"/></td>
                <td><input type="number" placeholder="e.g. 1200000" aria-label="Current Fair Market Value" min="0" step="any" style="width:100%;min-width:120px;box-sizing:border-box;"/></td>
                <td><input type="number" placeholder="e.g. 2025" aria-label="Acquisition Year" min="1900" max="2100" style="width:100%;min-width:90px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="e.g. Purchase" aria-label="Acquisition Mode" style="width:100%;min-width:100px;box-sizing:border-box;"/></td>
                <td><input type="number" placeholder="e.g. 950000" aria-label="Acquisition Cost" min="0" step="any" style="width:100%;min-width:120px;box-sizing:border-box;"/></td>
                <td class="del-cell"><button type="button" class="del-btn" onclick="annexBRemoveRow(this)" title="Remove row">&#x2715;</button></td>
              </tr>
              <tr>
                <td><input type="text"  aria-label="Description" style="width:100%;min-width:140px;box-sizing:border-box;"/></td>
                <td><input type="text"  aria-label="Kind" style="width:100%;min-width:100px;box-sizing:border-box;"/></td>
                <td><input type="text"  aria-label="Exact Location" style="width:100%;min-width:120px;box-sizing:border-box;"/></td>
                <td><input type="number"  aria-label="Assessed Value" min="0" step="any" style="width:100%;min-width:100px;box-sizing:border-box;"/></td>
                <td><input type="number"  aria-label="Current Fair Market Value" min="0" step="any" style="width:100%;min-width:120px;box-sizing:border-box;"/></td>
                <td><input type="number"  aria-label="Acquisition Year" min="1900" max="2100" style="width:100%;min-width:90px;box-sizing:border-box;"/></td>
                <td><input type="text"  aria-label="Acquisition Mode" style="width:100%;min-width:100px;box-sizing:border-box;"/></td>
                <td><input type="number"  aria-label="Acquisition Cost" min="0" step="any" style="width:100%;min-width:120px;box-sizing:border-box;"/></td>
                <td class="del-cell"><button type="button" class="del-btn" onclick="annexBRemoveRow(this)" title="Remove row">&#x2715;</button></td>
              </tr>
              <tr>
                <td><input type="text" placeholder="" aria-label="Description" style="width:100%;min-width:140px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="" aria-label="Kind" style="width:100%;min-width:100px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="" aria-label="Exact Location" style="width:100%;min-width:120px;box-sizing:border-box;"/></td>
                <td><input type="number" placeholder="" aria-label="Assessed Value" min="0" step="any" style="width:100%;min-width:100px;box-sizing:border-box;"/></td>
                <td><input type="number" placeholder="" aria-label="Current Fair Market Value" min="0" step="any" style="width:100%;min-width:120px;box-sizing:border-box;"/></td>
                <td><input type="number" placeholder="" aria-label="Acquisition Year" min="1900" max="2100" style="width:100%;min-width:90px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="" aria-label="Acquisition Mode" style="width:100%;min-width:100px;box-sizing:border-box;"/></td>
                <td><input type="number" placeholder="" aria-label="Acquisition Cost" min="0" step="any" style="width:100%;min-width:120px;box-sizing:border-box;"/></td>
                <td class="del-cell"><button type="button" class="del-btn" onclick="annexBRemoveRow(this)" title="Remove row">&#x2715;</button></td>
              </tr>
              <tr>
                <td><input type="text" placeholder="" aria-label="Description" style="width:100%;min-width:140px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="" aria-label="Kind" style="width:100%;min-width:100px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="" aria-label="Exact Location" style="width:100%;min-width:120px;box-sizing:border-box;"/></td>
                <td><input type="number" placeholder="" aria-label="Assessed Value" min="0" step="any" style="width:100%;min-width:100px;box-sizing:border-box;"/></td>
                <td><input type="number" placeholder="" aria-label="Current Fair Market Value" min="0" step="any" style="width:100%;min-width:120px;box-sizing:border-box;"/></td>
                <td><input type="number" placeholder="" aria-label="Acquisition Year" min="1900" max="2100" style="width:100%;min-width:90px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="" aria-label="Acquisition Mode" style="width:100%;min-width:100px;box-sizing:border-box;"/></td>
                <td><input type="number" placeholder="" aria-label="Acquisition Cost" min="0" step="any" style="width:100%;min-width:120px;box-sizing:border-box;"/></td>
                <td class="del-cell"><button type="button" class="del-btn" onclick="annexBRemoveRow(this)" title="Remove row">&#x2715;</button></td>
              </tr>
            </tbody>
            </table>
          </div>
          <button type="button" onclick="annexBAddRow('real')" style="margin-top:6px;padding:4px 14px;background:#2563eb;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:.82rem;">+ Add Real Property</button>

          <div style="font-weight:700;margin:10px 0 6px;">b. Personal Properties</div>
          <div class="saln-table-scroll">
            <table class="saln-tbl" style="width:100%;">
            <thead><tr><th>Description</th><th>Value</th><th>Remarks</th><th class="del-cell" style="border:none;background:transparent;"></th></tr></thead>
            <tbody id="annexB-personal-tbody">
              <tr><td><input type="text" placeholder="e.g. Jewelry" aria-label="Description"/></td><td><input type="number" placeholder="e.g. 50000" aria-label="Value" min="0" step="any"/></td><td><input type="text" placeholder="e.g. Gift from family" aria-label="Remarks"/></td><td class="del-cell"><button type="button" class="del-btn" onclick="annexBRemoveRow(this)" title="Remove row">&#x2715;</button></td></tr>
              <tr><td><input type="text" placeholder="e.g. Vehicle" aria-label="Description"/></td><td><input type="number" placeholder="e.g. 300000" aria-label="Value" min="0" step="any"/></td><td><input type="text" placeholder="e.g. Car loan" aria-label="Remarks"/></td><td class="del-cell"><button type="button" class="del-btn" onclick="annexBRemoveRow(this)" title="Remove row">&#x2715;</button></td></tr>
              <tr><td><input type="text"  aria-label="Description"/></td><td><input type="number"  aria-label="Value" min="0" step="any"/></td><td><input type="text"  aria-label="Remarks"/></td><td class="del-cell"><button type="button" class="del-btn" onclick="annexBRemoveRow(this)" title="Remove row">&#x2715;</button></td></tr>
              <tr><td><input type="text"  aria-label="Description"/></td><td><input type="number"  aria-label="Value" min="0" step="any"/></td><td><input type="text"  aria-label="Remarks"/></td><td class="del-cell"><button type="button" class="del-btn" onclick="annexBRemoveRow(this)" title="Remove row">&#x2715;</button></td></tr>
            </tbody>
            </table>
          </div>
          <button type="button" onclick="annexBAddRow('personal')" style="margin-top:6px;padding:4px 14px;background:#2563eb;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:.82rem;">+ Add Personal Property</button>
        </div>
        <div class="form-section-hd">2. LIABILITIES</div>
        <div class="form-section-body">
          <div class="saln-table-scroll">
            <table class="saln-tbl" style="width:100%;">
            <thead><tr><th>Description</th><th>Amount</th><th>Remarks</th><th class="del-cell" style="border:none;background:transparent;"></th></tr></thead>
            <tbody id="annexB-liab-tbody">
              <tr><td><input type="text" placeholder="e.g. Bank Loan" aria-label="Description"/></td><td><input type="number" placeholder="e.g. 100000" aria-label="Amount" min="0" step="any"/></td><td><input type="text" placeholder="e.g. For car purchase" aria-label="Remarks"/></td><td class="del-cell"><button type="button" class="del-btn" onclick="annexBRemoveRow(this)" title="Remove row">&#x2715;</button></td></tr>
              <tr><td><input type="text" placeholder="e.g. Credit Card" aria-label="Description"/></td><td><input type="number" placeholder="e.g. 20000" aria-label="Amount" min="0" step="any"/></td><td><input type="text" placeholder="e.g. Personal expenses" aria-label="Remarks"/></td><td class="del-cell"><button type="button" class="del-btn" onclick="annexBRemoveRow(this)" title="Remove row">&#x2715;</button></td></tr>
            </tbody>
            </table>
          </div>
          <button type="button" onclick="annexBAddRow('liab')" style="margin-top:6px;padding:4px 14px;background:#2563eb;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:.82rem;">+ Add Liability</button>
        </div>
        <div class="form-section-hd">3. BUSINESS INTERESTS AND FINANCIAL CONNECTIONS</div>
        <div class="form-section-body">
          <div class="saln-table-scroll">
            <table class="saln-tbl" style="min-width:820px;">
            <thead style="background:#f1f5f9;">
              <tr>
                <th style="min-width:200px;">NAME OF ENTITY/BUSINESS ENTERPRISE</th>
                <th style="min-width:180px;">BUSINESS ADDRESS</th>
                <th style="min-width:220px;">NATURE OF BUSINESS INTEREST &/OR FINANCIAL CONNECTION</th>
                <th style="min-width:180px;">DATE OF ACQUISITION OF INTEREST OR CONNECTION</th>
                <th class="del-cell" style="border:none;background:transparent;"></th>
              </tr>
            </thead>
            <tbody id="annexB-biz-tbody">
              <tr>
                <td><input type="text" placeholder="e.g. ABC Corp." aria-label="Name of Entity/Business Enterprise" style="width:100%;min-width:180px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="e.g. Manila" aria-label="Business Address" style="width:100%;min-width:150px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="e.g. Shareholder" aria-label="Nature of Business Interest" style="width:100%;min-width:200px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="e.g. 2022-05-01" aria-label="Date of Acquisition" style="width:100%;min-width:140px;box-sizing:border-box;"/></td>
                <td class="del-cell"><button type="button" class="del-btn" onclick="annexBRemoveRow(this)" title="Remove row">&#x2715;</button></td>
              </tr>
              <tr>
                <td><input type="text" placeholder="e.g. XYZ Lending" aria-label="Name of Entity/Business Enterprise" style="width:100%;min-width:180px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="e.g. Tuguegarao" aria-label="Business Address" style="width:100%;min-width:150px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="e.g. Investor" aria-label="Nature of Business Interest" style="width:100%;min-width:200px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="e.g. 2023-01-15" aria-label="Date of Acquisition" style="width:100%;min-width:140px;box-sizing:border-box;"/></td>
                <td class="del-cell"><button type="button" class="del-btn" onclick="annexBRemoveRow(this)" title="Remove row">&#x2715;</button></td>
              </tr>
              <tr>
                <td><input type="text" placeholder="" aria-label="Name of Entity/Business Enterprise" style="width:100%;min-width:180px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="" aria-label="Business Address" style="width:100%;min-width:150px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="" aria-label="Nature of Business Interest" style="width:100%;min-width:200px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="" aria-label="Date of Acquisition" style="width:100%;min-width:140px;box-sizing:border-box;"/></td>
                <td class="del-cell"><button type="button" class="del-btn" onclick="annexBRemoveRow(this)" title="Remove row">&#x2715;</button></td>
              </tr>
            </tbody>
            </table>
          </div>
          <button type="button" onclick="annexBAddRow('biz')" style="margin-top:6px;padding:4px 14px;background:#2563eb;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:.82rem;">+ Add Business Interest</button>
        </div>
      </div>
      <div style="text-align:right;margin-top:20px;padding-bottom:30px;display:flex;gap:8px;justify-content:flex-end;">
        <button class="save-form-btn" style="background:linear-gradient(135deg,#16a34a,#15803d);" onclick="printAnnex('annex_b')">🖨️ Print Annex B</button>
        <button class="save-form-btn" onclick="saveAnnex('annex_b')">💾 Save Annex B</button>
      </div>
    </div>

    <!-- ══ ANNEX C PAGE ══ -->
    <div class="page" id="page-annex_c">
      <div class="form-page">
        <h2>Annex C — Spouse and Children's Assets, Liabilities and Net Worth (Sheet AS-2)</h2>
        <div style="margin-bottom:10px;font-size:.92rem;">Additional sheet/s for the exclusive properties of the declarant's spouse and unmarried children below eighteen (18) years of age living in declarant's household.</div>
        <div class="fg"><label>As of:</label><input type="text" placeholder="e.g. December 31, 2025"/></div>
        <div class="form-section-hd">1. ASSETS</div>
        <div class="form-section-body">
          <div style="font-weight:700;margin-bottom:6px;">a. Real Properties</div>
          <div class="saln-table-scroll">
            <table class="saln-tbl" style="min-width:1100px;">
            <thead style="background:#f1f5f9;">
              <tr>
                <th style="min-width:170px;">Description<br><span style="font-weight:normal;font-size:5pt;">(e.g. lot, house and lot, condominium and improvements)</span></th>
                <th style="min-width:120px;">Kind<br><span style="font-weight:normal;font-size:5pt;">(e.g. residential, commercial, industrial, agricultural, and mixed use)</span></th>
                <th style="min-width:140px;">Exact Location</th>
                <th style="min-width:120px;">Assessed Value</th>
                <th style="min-width:140px;">Current Fair Market Value</th>
                <th style="min-width:110px;">Acquisition Year</th>
                <th style="min-width:120px;">Acquisition Mode</th>
                <th style="min-width:140px;">Acquisition Cost</th>
                <th class="del-cell" style="border:none;background:transparent;"></th>
              </tr>
            </thead>
            <tbody id="annexC-real-tbody">
              <tr>
                <td><input type="text" placeholder="e.g. House and Lot" aria-label="Description" style="width:100%;min-width:140px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="e.g. Residential" aria-label="Kind" style="width:100%;min-width:100px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="e.g. 123 Main St." aria-label="Exact Location" style="width:100%;min-width:120px;box-sizing:border-box;"/></td>
                <td><input type="number" placeholder="e.g. 1000000" aria-label="Assessed Value" min="0" step="any" style="width:100%;min-width:100px;box-sizing:border-box;"/></td>
                <td><input type="number" placeholder="e.g. 1200000" aria-label="Current Fair Market Value" min="0" step="any" style="width:100%;min-width:120px;box-sizing:border-box;"/></td>
                <td><input type="number" placeholder="e.g. 2025" aria-label="Acquisition Year" min="1900" max="2100" style="width:100%;min-width:90px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="e.g. Purchase" aria-label="Acquisition Mode" style="width:100%;min-width:100px;box-sizing:border-box;"/></td>
                <td><input type="number" placeholder="e.g. 950000" aria-label="Acquisition Cost" min="0" step="any" style="width:100%;min-width:120px;box-sizing:border-box;"/></td>
                <td class="del-cell"><button type="button" class="del-btn" onclick="annexBRemoveRow(this)" title="Remove row">&#x2715;</button></td>
              </tr>
              <tr>
                <td><input type="text"  aria-label="Description" style="width:100%;min-width:140px;box-sizing:border-box;"/></td>
                <td><input type="text"  aria-label="Kind" style="width:100%;min-width:100px;box-sizing:border-box;"/></td>
                <td><input type="text"  aria-label="Exact Location" style="width:100%;min-width:120px;box-sizing:border-box;"/></td>
                <td><input type="number"  aria-label="Assessed Value" min="0" step="any" style="width:100%;min-width:100px;box-sizing:border-box;"/></td>
                <td><input type="number"  aria-label="Current Fair Market Value" min="0" step="any" style="width:100%;min-width:120px;box-sizing:border-box;"/></td>
                <td><input type="number"  aria-label="Acquisition Year" min="1900" max="2100" style="width:100%;min-width:90px;box-sizing:border-box;"/></td>
                <td><input type="text"  aria-label="Acquisition Mode" style="width:100%;min-width:100px;box-sizing:border-box;"/></td>
                <td><input type="number"  aria-label="Acquisition Cost" min="0" step="any" style="width:100%;min-width:120px;box-sizing:border-box;"/></td>
                <td class="del-cell"><button type="button" class="del-btn" onclick="annexBRemoveRow(this)" title="Remove row">&#x2715;</button></td>
              </tr>
              <tr>
                <td><input type="text" placeholder="" aria-label="Description" style="width:100%;min-width:140px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="" aria-label="Kind" style="width:100%;min-width:100px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="" aria-label="Exact Location" style="width:100%;min-width:120px;box-sizing:border-box;"/></td>
                <td><input type="number" placeholder="" aria-label="Assessed Value" min="0" step="any" style="width:100%;min-width:100px;box-sizing:border-box;"/></td>
                <td><input type="number" placeholder="" aria-label="Current Fair Market Value" min="0" step="any" style="width:100%;min-width:120px;box-sizing:border-box;"/></td>
                <td><input type="number" placeholder="" aria-label="Acquisition Year" min="1900" max="2100" style="width:100%;min-width:90px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="" aria-label="Acquisition Mode" style="width:100%;min-width:100px;box-sizing:border-box;"/></td>
                <td><input type="number" placeholder="" aria-label="Acquisition Cost" min="0" step="any" style="width:100%;min-width:120px;box-sizing:border-box;"/></td>
                <td class="del-cell"><button type="button" class="del-btn" onclick="annexBRemoveRow(this)" title="Remove row">&#x2715;</button></td>
              </tr>
              <tr>
                <td><input type="text" placeholder="" aria-label="Description" style="width:100%;min-width:140px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="" aria-label="Kind" style="width:100%;min-width:100px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="" aria-label="Exact Location" style="width:100%;min-width:120px;box-sizing:border-box;"/></td>
                <td><input type="number" placeholder="" aria-label="Assessed Value" min="0" step="any" style="width:100%;min-width:100px;box-sizing:border-box;"/></td>
                <td><input type="number" placeholder="" aria-label="Current Fair Market Value" min="0" step="any" style="width:100%;min-width:120px;box-sizing:border-box;"/></td>
                <td><input type="number" placeholder="" aria-label="Acquisition Year" min="1900" max="2100" style="width:100%;min-width:90px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="" aria-label="Acquisition Mode" style="width:100%;min-width:100px;box-sizing:border-box;"/></td>
                <td><input type="number" placeholder="" aria-label="Acquisition Cost" min="0" step="any" style="width:100%;min-width:120px;box-sizing:border-box;"/></td>
                <td class="del-cell"><button type="button" class="del-btn" onclick="annexBRemoveRow(this)" title="Remove row">&#x2715;</button></td>
              </tr>
            </tbody>
            </table>
          </div>
          <button type="button" onclick="annexCAddRow('real')" style="margin-top:6px;padding:4px 14px;background:#2563eb;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:.82rem;">+ Add Real Property</button>

          <div style="font-weight:700;margin:10px 0 6px;">b. Personal Properties</div>
          <div class="saln-table-scroll">
            <table class="saln-tbl" style="width:100%;">
            <thead><tr><th>Description</th><th>Value</th><th>Remarks</th><th class="del-cell" style="border:none;background:transparent;"></th></tr></thead>
            <tbody id="annexC-personal-tbody">
              <tr><td><input type="text" placeholder="e.g. Jewelry" aria-label="Description"/></td><td><input type="number" placeholder="e.g. 50000" aria-label="Value" min="0" step="any"/></td><td><input type="text" placeholder="e.g. Gift from family" aria-label="Remarks"/></td><td class="del-cell"><button type="button" class="del-btn" onclick="annexBRemoveRow(this)" title="Remove row">&#x2715;</button></td></tr>
              <tr><td><input type="text" placeholder="e.g. Vehicle" aria-label="Description"/></td><td><input type="number" placeholder="e.g. 300000" aria-label="Value" min="0" step="any"/></td><td><input type="text" placeholder="e.g. Car loan" aria-label="Remarks"/></td><td class="del-cell"><button type="button" class="del-btn" onclick="annexBRemoveRow(this)" title="Remove row">&#x2715;</button></td></tr>
              <tr><td><input type="text"  aria-label="Description"/></td><td><input type="number"  aria-label="Value" min="0" step="any"/></td><td><input type="text"  aria-label="Remarks"/></td><td class="del-cell"><button type="button" class="del-btn" onclick="annexBRemoveRow(this)" title="Remove row">&#x2715;</button></td></tr>
              <tr><td><input type="text"  aria-label="Description"/></td><td><input type="number"  aria-label="Value" min="0" step="any"/></td><td><input type="text"  aria-label="Remarks"/></td><td class="del-cell"><button type="button" class="del-btn" onclick="annexBRemoveRow(this)" title="Remove row">&#x2715;</button></td></tr>
            </tbody>
            </table>
          </div>
          <button type="button" onclick="annexCAddRow('personal')" style="margin-top:6px;padding:4px 14px;background:#2563eb;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:.82rem;">+ Add Personal Property</button>
        </div>
        <div class="form-section-hd">2. LIABILITIES</div>
        <div class="form-section-body">
          <div class="saln-table-scroll">
            <table class="saln-tbl" style="width:100%;">
            <thead><tr><th>Description</th><th>Amount</th><th>Remarks</th><th class="del-cell" style="border:none;background:transparent;"></th></tr></thead>
            <tbody id="annexC-liab-tbody">
              <tr><td><input type="text" placeholder="e.g. Bank Loan" aria-label="Description"/></td><td><input type="number" placeholder="e.g. 100000" aria-label="Amount" min="0" step="any"/></td><td><input type="text" placeholder="e.g. For car purchase" aria-label="Remarks"/></td><td class="del-cell"><button type="button" class="del-btn" onclick="annexBRemoveRow(this)" title="Remove row">&#x2715;</button></td></tr>
              <tr><td><input type="text" placeholder="e.g. Credit Card" aria-label="Description"/></td><td><input type="number" placeholder="e.g. 20000" aria-label="Amount" min="0" step="any"/></td><td><input type="text" placeholder="e.g. Personal expenses" aria-label="Remarks"/></td><td class="del-cell"><button type="button" class="del-btn" onclick="annexBRemoveRow(this)" title="Remove row">&#x2715;</button></td></tr>
            </tbody>
            </table>
          </div>
          <button type="button" onclick="annexCAddRow('liab')" style="margin-top:6px;padding:4px 14px;background:#2563eb;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:.82rem;">+ Add Liability</button>
        </div>
        <div class="form-section-hd">3. BUSINESS INTERESTS AND FINANCIAL CONNECTIONS</div>
        <div class="form-section-body">
          <div class="saln-table-scroll">
            <table class="saln-tbl" style="min-width:820px;">
            <thead style="background:#f1f5f9;">
              <tr>
                <th style="min-width:200px;">NAME OF ENTITY/BUSINESS ENTERPRISE</th>
                <th style="min-width:180px;">BUSINESS ADDRESS</th>
                <th style="min-width:220px;">NATURE OF BUSINESS INTEREST &/OR FINANCIAL CONNECTION</th>
                <th style="min-width:180px;">DATE OF ACQUISITION OF INTEREST OR CONNECTION</th>
                <th class="del-cell" style="border:none;background:transparent;"></th>
              </tr>
            </thead>
            <tbody id="annexC-biz-tbody">
              <tr>
                <td><input type="text" placeholder="e.g. ABC Corp." aria-label="Name of Entity/Business Enterprise" style="width:100%;min-width:180px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="e.g. Manila" aria-label="Business Address" style="width:100%;min-width:150px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="e.g. Shareholder" aria-label="Nature of Business Interest" style="width:100%;min-width:200px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="e.g. 2022-05-01" aria-label="Date of Acquisition" style="width:100%;min-width:140px;box-sizing:border-box;"/></td>
                <td class="del-cell"><button type="button" class="del-btn" onclick="annexBRemoveRow(this)" title="Remove row">&#x2715;</button></td>
              </tr>
              <tr>
                <td><input type="text" placeholder="e.g. XYZ Lending" aria-label="Name of Entity/Business Enterprise" style="width:100%;min-width:180px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="e.g. Tuguegarao" aria-label="Business Address" style="width:100%;min-width:150px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="e.g. Investor" aria-label="Nature of Business Interest" style="width:100%;min-width:200px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="e.g. 2023-01-15" aria-label="Date of Acquisition" style="width:100%;min-width:140px;box-sizing:border-box;"/></td>
                <td class="del-cell"><button type="button" class="del-btn" onclick="annexBRemoveRow(this)" title="Remove row">&#x2715;</button></td>
              </tr>
              <tr>
                <td><input type="text" placeholder="" aria-label="Name of Entity/Business Enterprise" style="width:100%;min-width:180px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="" aria-label="Business Address" style="width:100%;min-width:150px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="" aria-label="Nature of Business Interest" style="width:100%;min-width:200px;box-sizing:border-box;"/></td>
                <td><input type="text" placeholder="" aria-label="Date of Acquisition" style="width:100%;min-width:140px;box-sizing:border-box;"/></td>
                <td class="del-cell"><button type="button" class="del-btn" onclick="annexBRemoveRow(this)" title="Remove row">&#x2715;</button></td>
              </tr>
            </tbody>
            </table>
          </div>
          <button type="button" onclick="annexCAddRow('biz')" style="margin-top:6px;padding:4px 14px;background:#2563eb;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:.82rem;">+ Add Business Interest</button>
        </div>
      </div>
      <div style="text-align:right;margin-top:20px;padding-bottom:30px;display:flex;gap:8px;justify-content:flex-end;">
        <button class="save-form-btn" style="background:linear-gradient(135deg,#16a34a,#15803d);" onclick="printAnnex('annex_c')">🖨️ Print Annex C</button>
        <button class="save-form-btn" onclick="saveAnnex('annex_c')">💾 Save Annex C</button>
      </div>
    </div>

  <!-- ══ Dept Head Leave Approval ══ -->
  <div class="page" id="page-dept_head_leaves">
    <!-- Stat cards -->
    <div class="stat-cards" id="dhStatCards" style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:22px;">
      <div class="stat-card orange"><div class="stat-card-header"><div class="stat-icon">⏳</div></div><div class="stat-value" id="dh-sc-pending">—</div><div class="stat-label">Pending Approval</div><div class="stat-sub">Awaiting your review</div></div>
      <div class="stat-card green"><div class="stat-card-header"><div class="stat-icon">✅</div></div><div class="stat-value" id="dh-sc-approved">—</div><div class="stat-label">Forwarded to HR</div><div class="stat-sub">Approved by you</div></div>
      <div class="stat-card red"><div class="stat-card-header"><div class="stat-icon">✗</div></div><div class="stat-value" id="dh-sc-denied">—</div><div class="stat-label">Denied</div><div class="stat-sub">Denied by you</div></div>
      <div class="stat-card blue"><div class="stat-card-header"><div class="stat-icon">📅</div></div><div class="stat-value" id="dh-sc-total">—</div><div class="stat-label">Total Requests</div><div class="stat-sub">All time</div></div>
    </div>
   <!-- Toolbar -->
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
      <div style="display:flex;align-items:center;gap:8px;background:#fff;border:1.5px solid var(--g200);border-radius:var(--r-sm);padding:9px 12px;flex:1 1 auto;min-width:160px;box-shadow:var(--sh-sm);transition:border-color .2s;" onfocusin="this.style.borderColor='var(--primary)'" onfocusout="this.style.borderColor='var(--g200)'">
        <span style="color:var(--g400);font-size:1rem;">🔍</span>
        <input type="text" id="dhSearch" placeholder="Search employee or leave type…" oninput="dhDebounce()" style="border:none;outline:none;font-family:'Nunito',sans-serif;font-size:.875rem;width:100%;background:transparent;color:var(--g800);"/>
      </div>
      <select id="dhStatusFilter" onchange="loadDeptHeadLeaves(1)" style="padding:8px 10px;border:1.5px solid var(--g200);border-radius:var(--r-sm);font-family:'Nunito',sans-serif;font-size:.80rem;color:var(--g600);background:#fff;cursor:pointer;box-shadow:var(--sh-sm);outline:none;transition:border-color .2s;flex:0 1 auto;min-width:100px;" onfocus="this.style.borderColor='var(--primary)'" onblur="this.style.borderColor='var(--g200)'">
        <option value="">All Requests</option>
        <option value="Pending Dept Head">Pending</option>
        <option value="Pending HR">Forwarded to HR</option>
        <option value="Denied by Dept Head">Denied</option>
      </select>
      <select id="dhDeptFilter" onchange="loadDeptHeadLeaves(1)" style="padding:8px 10px;border:1.5px solid var(--g200);border-radius:var(--r-sm);font-family:'Nunito',sans-serif;font-size:.80rem;color:var(--g600);background:#fff;cursor:pointer;box-shadow:var(--sh-sm);outline:none;transition:border-color .2s;flex:0 1 auto;min-width:100px;" onfocus="this.style.borderColor='var(--primary)'" onblur="this.style.borderColor='var(--g200)'">
        <option value="">All Departments</option>
      </select>
      <select id="dhTypeFilter" onchange="loadDeptHeadLeaves(1)" style="padding:8px 10px;border:1.5px solid var(--g200);border-radius:var(--r-sm);font-family:'Nunito',sans-serif;font-size:.80rem;color:var(--g600);background:#fff;cursor:pointer;box-shadow:var(--sh-sm);outline:none;transition:border-color .2s;flex:0 1 auto;min-width:100px;" onfocus="this.style.borderColor='var(--primary)'" onblur="this.style.borderColor='var(--g200)'">
        <option value="">All Leave Types</option>
      </select>
      <select id="dhMonthFilter" onchange="loadDeptHeadLeaves(1)" style="padding:8px 10px;border:1.5px solid var(--g200);border-radius:var(--r-sm);font-family:'Nunito',sans-serif;font-size:.80rem;color:var(--g600);background:#fff;cursor:pointer;box-shadow:var(--sh-sm);outline:none;transition:border-color .2s;flex:0 1 auto;min-width:100px;" onfocus="this.style.borderColor='var(--primary)'" onblur="this.style.borderColor='var(--g200)'">
        <option value="">All Months</option>
        <option value="1">January</option><option value="2">February</option><option value="3">March</option>
        <option value="4">April</option><option value="5">May</option><option value="6">June</option>
        <option value="7">July</option><option value="8">August</option><option value="9">September</option>
        <option value="10">October</option><option value="11">November</option><option value="12">December</option>
      </select>
      <button onclick="dhResetFilters()" style="display:flex;align-items:center;gap:6px;padding:8px 14px;background:linear-gradient(135deg,#f59e0b,#d97706);color:white;border:none;border-radius:var(--r-sm);font-family:'Nunito',sans-serif;font-size:.80rem;font-weight:700;cursor:pointer;transition:all .2s;box-shadow:0 2px 8px rgba(245,158,11,.30);white-space:nowrap;flex:0 1 auto;">🔄 Reset Filters</button>
    </div>
    <!-- Table panel -->
    <div class="panel card" style="overflow:hidden;">
      <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:.83rem;">
          <thead>
            <tr style="background:var(--g50);">
              <th style="padding:11px 16px;text-align:left;font-size:.69rem;font-weight:800;color:var(--g400);text-transform:uppercase;letter-spacing:.07em;border-bottom:1.5px solid var(--g200);white-space:nowrap;">Employee</th>
              <th style="padding:11px 16px;text-align:left;font-size:.69rem;font-weight:800;color:var(--g400);text-transform:uppercase;letter-spacing:.07em;border-bottom:1.5px solid var(--g200);white-space:nowrap;">Leave Type</th>
              <th style="padding:11px 16px;text-align:left;font-size:.69rem;font-weight:800;color:var(--g400);text-transform:uppercase;letter-spacing:.07em;border-bottom:1.5px solid var(--g200);white-space:nowrap;">Leave Balance</th>
              <th style="padding:11px 16px;text-align:left;font-size:.69rem;font-weight:800;color:var(--g400);text-transform:uppercase;letter-spacing:.07em;border-bottom:1.5px solid var(--g200);white-space:nowrap;">Date From</th>
              <th style="padding:11px 16px;text-align:left;font-size:.69rem;font-weight:800;color:var(--g400);text-transform:uppercase;letter-spacing:.07em;border-bottom:1.5px solid var(--g200);white-space:nowrap;">Date To</th>
              <th style="padding:11px 16px;text-align:left;font-size:.69rem;font-weight:800;color:var(--g400);text-transform:uppercase;letter-spacing:.07em;border-bottom:1.5px solid var(--g200);white-space:nowrap;">Days</th>
              <th style="padding:11px 16px;text-align:left;font-size:.69rem;font-weight:800;color:var(--g400);text-transform:uppercase;letter-spacing:.07em;border-bottom:1.5px solid var(--g200);">Reason</th>
              <th style="padding:11px 16px;text-align:left;font-size:.69rem;font-weight:800;color:var(--g400);text-transform:uppercase;letter-spacing:.07em;border-bottom:1.5px solid var(--g200);white-space:nowrap;">Filed On</th>
              <th style="padding:11px 16px;text-align:center;font-size:.69rem;font-weight:800;color:var(--g400);text-transform:uppercase;letter-spacing:.07em;border-bottom:1.5px solid var(--g200);white-space:nowrap;">Actions</th>
            </tr>
          </thead>
          <tbody id="dhTableBody">
            <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--g400);">Loading…</td></tr>
          </tbody>
        </table>
        <div id="dhEmptyState" style="display:none;padding:52px 20px;text-align:center;">
          <div style="font-size:2.8rem;margin-bottom:12px;opacity:.5;">📋</div>
          <p style="font-size:.875rem;font-weight:600;color:var(--g400);">No pending leave requests in your department.</p>
        </div>
      </div>
      <!-- Pagination -->
      <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-top:1px solid var(--g100);font-size:.80rem;color:var(--g400);flex-wrap:wrap;gap:10px;font-weight:600;">
        <span id="dhPageInfo"></span>
        <div style="display:flex;gap:4px;" id="dhPageBtns"></div>
      </div>
    </div>
    <!-- Approve Modal -->
    <div id="dhModalApprove" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:800;align-items:center;justify-content:center;backdrop-filter:blur(4px);">
      <div style="background:#fff;border-radius:14px;box-shadow:0 8px 32px rgba(0,0,0,.18);width:92%;max-width:440px;animation:fadeUp .3s ease;">
        <div style="background:linear-gradient(135deg,#22c55e,#15803d);padding:18px 22px 14px;border-radius:14px 14px 0 0;display:flex;align-items:center;justify-content:space-between;">
          <div><div style="font-size:1rem;font-weight:800;color:#fff;font-family:'Playfair Display',serif;">✅ Approve Leave</div><div style="font-size:.73rem;color:rgba(255,255,255,.65);margin-top:2px;">Confirm approval and forward to HR</div></div>
          <button onclick="document.getElementById('dhModalApprove').style.display='none';" style="width:30px;height:30px;border-radius:50%;background:rgba(255,255,255,.18);border:none;color:#fff;font-size:1rem;cursor:pointer;">✕</button>
        </div>
        <div style="padding:20px 22px 10px;text-align:center;">
          <div style="font-size:2.4rem;margin-bottom:8px;">✅</div>
          <h4 style="font-size:.95rem;font-weight:800;color:var(--g800);margin-bottom:6px;">Approve this leave request?</h4>
          <p id="dhApproveText" style="font-size:.82rem;color:var(--g600);line-height:1.6;margin-bottom:10px;"></p>
          <textarea id="dhApproveRemark" placeholder="Optional remarks for HR…" style="width:100%;padding:9px 12px;border:1.5px solid var(--g200);border-radius:9px;font-family:'Nunito',sans-serif;font-size:.84rem;resize:none;height:68px;outline:none;"></textarea>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;padding:10px 22px 14px;border-top:1px solid var(--g100);">
          <button onclick="document.getElementById('dhModalApprove').style.display='none';" style="padding:9px 20px;border:1.5px solid var(--g200);background:#fff;border-radius:9px;font-family:'Nunito',sans-serif;font-size:.83rem;font-weight:700;cursor:pointer;">Cancel</button>
          <button id="dhApproveBtn" onclick="dhConfirmApprove()" style="padding:9px 22px;background:linear-gradient(135deg,#22c55e,#15803d);color:#fff;border:none;border-radius:9px;font-family:'Nunito',sans-serif;font-size:.83rem;font-weight:700;cursor:pointer;">✅ Yes, Approve</button>
        </div>
      </div>
    </div>
    <!-- Deny Modal -->
    <div id="dhModalDeny" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:800;align-items:center;justify-content:center;backdrop-filter:blur(4px);">
      <div style="background:#fff;border-radius:14px;box-shadow:0 8px 32px rgba(0,0,0,.18);width:92%;max-width:440px;animation:fadeUp .3s ease;">
        <div style="background:linear-gradient(135deg,#ef4444,#dc2626);padding:18px 22px 14px;border-radius:14px 14px 0 0;display:flex;align-items:center;justify-content:space-between;">
          <div><div style="font-size:1rem;font-weight:800;color:#fff;font-family:'Playfair Display',serif;">✗ Deny Leave</div><div style="font-size:.73rem;color:rgba(255,255,255,.65);margin-top:2px;">Confirm denial of this request</div></div>
          <button onclick="document.getElementById('dhModalDeny').style.display='none';" style="width:30px;height:30px;border-radius:50%;background:rgba(255,255,255,.18);border:none;color:#fff;font-size:1rem;cursor:pointer;">✕</button>
        </div>
        <div style="padding:20px 22px 10px;text-align:center;">
          <div style="font-size:2.4rem;margin-bottom:8px;">⚠️</div>
          <h4 style="font-size:.95rem;font-weight:800;color:var(--g800);margin-bottom:6px;">Deny this leave request?</h4>
          <p id="dhDenyText" style="font-size:.82rem;color:var(--g600);line-height:1.6;margin-bottom:10px;"></p>
          <textarea id="dhDenyRemark" placeholder="Please state the reason for denial…" style="width:100%;padding:9px 12px;border:1.5px solid var(--g200);border-radius:9px;font-family:'Nunito',sans-serif;font-size:.84rem;resize:none;height:68px;outline:none;"></textarea>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;padding:10px 22px 14px;border-top:1px solid var(--g100);">
          <button onclick="document.getElementById('dhModalDeny').style.display='none';" style="padding:9px 20px;border:1.5px solid var(--g200);background:#fff;border-radius:9px;font-family:'Nunito',sans-serif;font-size:.83rem;font-weight:700;cursor:pointer;">Cancel</button>
          <button id="dhDenyBtn" onclick="dhConfirmDeny()" style="padding:9px 22px;background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;border:none;border-radius:9px;font-family:'Nunito',sans-serif;font-size:.83rem;font-weight:700;cursor:pointer;">✗ Yes, Deny</button>
        </div>
      </div>
    </div>
  </div>

  <!-- ══ Service Record ══ -->
  <div class="page" id="page-service_record">
    <div style="max-width: 860px; margin: 18px auto 0 auto;">
      <div class="card" style="padding:0 0 32px 0; border-radius:16px;">
        <!-- Blue header banner -->
        <div style="background:linear-gradient(90deg,#2563eb,#3576f6);border-radius:16px 16px 0 0;padding:22px 28px 18px 28px;display:flex;align-items:center;gap:16px;box-shadow:0 2px 8px rgba(37,99,235,0.07);">
          <span style="font-size:2.1rem;line-height:1;">📋</span>
          <div>
            <div style="font-size:1.32rem;font-weight:800;color:#fff;font-family:'Playfair Display',serif;letter-spacing:-.5px;">Service Record History</div>
            <div style="font-size:.98rem;color:#dbeafe;font-weight:500;margin-top:2px;">View your official employment record as maintained by HR</div>
          </div>
        </div>
        <div id="srRequestBanner" style="display:none;padding:12px 16px;border-radius:10px;margin:18px 18px 0 18px;font-size:.84rem;font-weight:700;border:1.5px solid #fde68a;background:#fffbeb;color:#92400e;"></div>
        <div id="srContent" style="overflow-x:auto;padding:0 12px 18px 12px;box-sizing:border-box;width:100%;">
          <!-- The table will be injected here by JS if entries exist -->
        </div>
      </div>
      <div style="margin-top:20px;padding-bottom:30px;display:flex;gap:8px;justify-content:flex-end;">
        <button class="save-form-btn" onclick="viewMySR()" id="btnViewSR" style="background:linear-gradient(135deg,#16a34a,#15803d);display:none;">👁️ View SR Form</button>
        <button class="save-form-btn" onclick="requestServiceRecord()" id="btnRequestSR" style="background:linear-gradient(135deg,#2563eb,#1e40af);">📨 Request Service Record</button>
      </div>
    </div>
  </div>

  <!-- ══ CERTIFICATE OF EMPLOYMENT ══ -->
  <div class="page" id="page-coe">
    <div style="max-width: 980px; margin: 10px auto 0 auto;">
      <div class="card" style="padding:0; overflow:hidden;">
        <div style="background:linear-gradient(135deg,#0f766e,#0d9488);padding:18px 22px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
          <div>
            <div style="font-size:1.02rem;font-weight:800;color:#fff;font-family:'Playfair Display',serif;">📄 Certificate of Employment Request</div>
            <div style="font-size:.76rem;color:rgba(255,255,255,.75);margin-top:2px;">Submit and track your COE request. Printing is handled by HR/Admin.</div>
          </div>
          <button onclick="openCoeModal()" style="padding:9px 14px;border:none;border-radius:9px;background:#fff;color:#0f766e;font-family:'Nunito',sans-serif;font-size:.80rem;font-weight:800;cursor:pointer;">+ New COE Request</button>
        </div>

        <div style="padding:16px 18px;">
          <div class="req-tabs" style="margin-bottom:12px;">
            <button class="req-tab active" onclick="filterCoeReqs('all',this)">All <span class="tc" id="coe-tc-all">0</span></button>
            <button class="req-tab" onclick="filterCoeReqs('pending',this)">⏳ Pending <span class="tc" id="coe-tc-pending">0</span></button>
            <button class="req-tab" onclick="filterCoeReqs('approved',this)">✅ Approved <span class="tc" id="coe-tc-approved">0</span></button>
            <button class="req-tab" onclick="filterCoeReqs('denied',this)">✗ Denied <span class="tc" id="coe-tc-denied">0</span></button>
            <button class="req-tab" onclick="filterCoeReqs('cancelled',this)">— Cancelled <span class="tc" id="coe-tc-cancelled">0</span></button>
          </div>

          <div id="coeList" class="req-list"></div>
          <div class="empty-msg" id="coeEmpty" style="display:none;"><div class="ei">📄</div><p>No COE requests found.</p></div>
        </div>
      </div>
    </div>
  </div>
</div>
<!-- .main -->

<!-- COE REQUEST MODAL -->
<div class="modal-overlay" id="modalCoeRequest">
  <div class="modal" style="max-width:520px;">
    <div class="modal-head">
      <div><h3 id="coeModalTitle">📄 New COE Request</h3><p>Tell HR/Admin why you need your COE</p></div>
      <button class="modal-x" onclick="closeModal('modalCoeRequest')">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="coeEditId" value="" />
      <div class="fg" style="margin-bottom:8px;">
        <label>Purpose <span style="color:var(--danger);">*</span></label>
        <textarea id="coePurpose" placeholder="e.g. For employment application, visa requirements, loan processing" style="width:100%;min-height:110px;padding:10px 12px;border:1.5px solid var(--g200);border-radius:9px;font-family:'Nunito',sans-serif;font-size:.84rem;outline:none;resize:vertical;"></textarea>
      </div>
      <p style="font-size:.72rem;color:var(--g500);line-height:1.45;">Note: Employees can file, edit pending, and cancel pending COE requests. Printing of approved COE is available to HR/Admin only.</p>
    </div>
    <div class="modal-foot">
      <button class="m-btn m-btn-cancel" onclick="closeModal('modalCoeRequest')">Cancel</button>
      <button class="m-btn m-btn-primary" id="btnSaveCoe" onclick="submitCoeRequest()">📨 Submit Request</button>
    </div>
  </div>
</div>

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

<!-- SIGNATURE CROP MODAL -->
<div class="modal-overlay" id="modalSignatureCrop">
  <div class="modal sig-crop-modal">
    <div class="modal-head">
      <div><h3>✂️ Crop E-Signature</h3><p>Adjust and preview before uploading</p></div>
      <button class="modal-x" onclick="closeSignatureCropper()">✕</button>
    </div>
    <div class="sig-crop-body">
      <div class="sig-crop-grid">
        <div class="sig-canvas-wrap">
          <div class="sig-canvas-hint">Drag to position your signature inside the highlighted crop area.</div>
          <canvas id="sigCropCanvas" class="sig-canvas" width="720" height="320"></canvas>
        </div>
        <div class="sig-crop-side">
          <div style="font-size:.74rem;font-weight:800;color:var(--g700);margin-bottom:8px;">Preview</div>
          <div class="sig-preview-box">
            <img id="sigCropPreview" alt="Cropped signature preview" />
          </div>
          <div class="sig-zoom-label">Zoom</div>
          <input id="sigCropZoom" class="sig-zoom-range" type="range" min="100" max="300" value="100" oninput="onSignatureCropZoom(this.value)" />
          <div class="sig-zoom-value" id="sigCropZoomValue">100%</div>
          <div style="font-size:.68rem;color:var(--g500);margin-top:9px;line-height:1.5;">Tip: Keep the signature strokes centered and avoid large blank margins.</div>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <button class="m-btn m-btn-cancel" onclick="closeSignatureCropper()">Cancel</button>
      <button class="m-btn m-btn-primary" id="sigCropUploadBtn" onclick="confirmSignatureCropUpload()">✅ Use Crop & Upload</button>
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

  <!-- Service Record viewer modal (employee view-only) -->
  <div id="srViewModal" onclick="if(event.target===this) closeSRViewModal();" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.62);z-index:810;align-items:center;justify-content:center;backdrop-filter:blur(3px);padding:16px;">
    <div style="background:#fff;border-radius:14px;box-shadow:0 16px 42px rgba(2,6,23,.35);width:min(1180px,96vw);height:min(92vh,900px);display:flex;flex-direction:column;overflow:hidden;">
      <div style="background:#f8fafc;border-bottom:1px solid #e2e8f0;padding:10px 14px;display:flex;align-items:center;justify-content:space-between;gap:10px;">
        <div style="display:flex;align-items:center;gap:10px;min-width:0;">
          <div id="srViewInitials" style="width:34px;height:34px;border-radius:50%;background:#0ea5e9;color:#fff;font-size:.82rem;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;">SR</div>
          <div style="min-width:0;">
            <div id="srViewName" style="font-size:.92rem;font-weight:800;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Service Record</div>
            <div id="srViewMeta" style="font-size:.74rem;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Official SR Form Preview</div>
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;">
          <button type="button" onclick="closeSRViewModal()" style="padding:7px 12px;border:1px solid #cbd5e1;background:#fff;border-radius:8px;color:#334155;font-size:.80rem;font-weight:700;cursor:pointer;">Close</button>
        </div>
      </div>
      <div style="flex:1;background:#e2e8f0;padding:8px;overflow:auto;">
        <iframe id="srViewFrame" title="Service Record Form" style="width:100%;height:100%;min-height:520px;border:none;border-radius:10px;background:#fff;box-shadow:0 10px 24px rgba(15,23,42,.16);"></iframe>
      </div>
    </div>
  </div>

<!-- ══ Shared Print Utilities (Official CS Form 212) ══ -->
<script src="../config/print-utilities.js?v=<?= time(); ?>"></script>
<?php
$_epMayorRow   = null;
try { $_epMayorRow = $pdo->query("SELECT first_name, last_name, position_title FROM employees WHERE is_mayor=1 AND employment_status='Active' LIMIT 1")->fetch(PDO::FETCH_ASSOC); } catch (Exception $_e) {}
$_epMayorName  = $_epMayorRow ? strtoupper(trim($_epMayorRow['first_name'].' '.$_epMayorRow['last_name'])) : 'MIGUEL B. DECENA, JR.';
$_epMayorTitle = $_epMayorRow ? ($_epMayorRow['position_title'] ?: 'Municipal Mayor') : 'Municipal Mayor';

$_epHrRow = null;
try {
  $_epHrRow = $pdo->query("SELECT
      COALESCE(NULLIF(TRIM(CONCAT(COALESCE(h.first_name,''), ' ', COALESCE(h.last_name,''))), ''), NULLIF(TRIM(d.head_name), '')) AS head_name,
      COALESCE(NULLIF(TRIM(h.position_title), ''), 'HRMO III') AS head_title
    FROM departments d
    LEFT JOIN employees h
      ON h.employee_id = d.head_employee_id
     AND h.employment_status = 'Active'
    WHERE d.is_active = 1
      AND LOWER(d.name) REGEXP 'human.?resource|hr.?office|hrmo'
    ORDER BY d.department_id ASC
    LIMIT 1")->fetch(PDO::FETCH_ASSOC);
} catch (Exception $_e) {}
if (empty($_epHrRow['head_name'])) {
  try {
    $_epHrRow = $pdo->query("SELECT
        CONCAT(first_name, ' ', last_name) AS head_name,
        COALESCE(NULLIF(TRIM(position_title), ''), 'HRMO III') AS head_title
      FROM employees
      WHERE employment_status = 'Active'
        AND LOWER(position_title) REGEXP 'hr.?head|human.?resource|hrmo|hrm'
      ORDER BY employee_id ASC
      LIMIT 1")->fetch(PDO::FETCH_ASSOC);
  } catch (Exception $_e) {}
}
$_epHrHeadName  = (!empty($_epHrRow['head_name']))  ? strtoupper(trim($_epHrRow['head_name']))  : 'EMIROSE P. MADRIAGA';
$_epHrHeadTitle = (!empty($_epHrRow['head_title'])) ? trim($_epHrRow['head_title']) : 'HRMO III';
?>
<script>
var SR_MAYOR_NAME  = <?= json_encode($_epMayorName) ?>;
var SR_MAYOR_TITLE = <?= json_encode($_epMayorTitle) ?>;
var SR_HR_HEAD_NAME  = <?= json_encode($_epHrHeadName) ?>;
var SR_HR_HEAD_TITLE = <?= json_encode($_epHrHeadTitle) ?>;
</script>
<script src="../config/service_record_print_template.js?v=<?= @filemtime(__DIR__ . '/../config/service_record_print_template.js') ?: time(); ?>"></script>

<script>
// Toggle annex links based on active page
var annexVisible = false;
function toggleAnnexLinks(activePage, isToggle) {
  var annexLinks = document.querySelectorAll('.annex-link');
  if (activePage === "saln") {
    if (isToggle) annexVisible = !annexVisible;
    annexLinks.forEach(function(btn){ btn.style.display = annexVisible ? "flex" : "none"; });
  } else if (["annex_a","annex_b","annex_c"].includes(activePage)) {
    annexVisible = true;
    annexLinks.forEach(function(btn){ btn.style.display = "flex"; });
  } else {
    annexVisible = false;
    annexLinks.forEach(function(btn){ btn.style.display = "none"; });
  }
}

// Patch goPage to toggle annex links
if (!window._originalGoPage) {
  window._originalGoPage = window.goPage;
  window.goPage = function(name, btn) {
    toggleAnnexLinks(name, name === "saln");
    if (window._originalGoPage) return window._originalGoPage.apply(this, arguments);
  }
}

// On page load, set annex links visibility
document.addEventListener('DOMContentLoaded', function() {
  var active = document.querySelector('.sb-item.active');
  var page = active && active.getAttribute('onclick') ? active.getAttribute('onclick').match(/goPage\('([^']+)'/) : null;
  toggleAnnexLinks(page ? page[1] : 'dashboard', false);
});

// Patch goPage to toggle annex links
var _goPage = window.goPage;
window.goPage = function(name, btn) {
  toggleAnnexLinks(name);
  if (_goPage) return _goPage.apply(this, arguments);
}

// On page load, set annex links visibility
document.addEventListener('DOMContentLoaded', function() {
  var active = document.querySelector('.sb-item.active');
  var page = active && active.getAttribute('onclick') ? active.getAttribute('onclick').match(/goPage\('([^']+)'/) : null;
  toggleAnnexLinks(page ? page[1] : 'dashboard');
});
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
const REQUESTS_PER_PAGE = 8;
let requestPage = 1;
let curCoeTab = 'all';
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
    // Poll leave requests immediately then every 30 seconds
    try { pollLeaveRequests(); } catch(e) { console.warn(e); }
    setInterval(function(){ try { pollLeaveRequests(); } catch(e){} }, 30000);
    // Poll dept-head pending count badge immediately then every 30 seconds
    try { updateDhBadge(); } catch(e) {}
    setInterval(function(){ try { updateDhBadge(); } catch(e){} }, 30000);
})();

// ════════════════════════════════════════════════════════
//  PAGE NAV
// ════════════════════════════════════════════════════════
const PAGE_META = {
  dashboard:['Dashboard',          'Welcome back!'],
  profile:  ['My Profile',         'Personal & employment information'],
  requests: ['My Leave Requests',  'Track all your leave applications'],
  coe:      ['Certificate of Employment', 'Submit and monitor your COE requests'],
  file:     ['File Leave Request', 'Submit a new leave application'],
  balances: ["Leave Balances",     "Your available credits for CY <?= date('Y') ?>"],
  documents:['My Documents',       'View and manage your HR files'],
  pds:      ['Personal Data Sheet', 'CS Form No. 212 (Revised 2025)'],
  saln:     ['SALN',                'Statement of Assets, Liabilities and Net Worth'],
  service_record: ['Service Record', 'Your government service history'],
  dept_head_leaves: ['Dept Head Leave Approval', 'Approve or deny leave requests from your department'],
};

// ════════════════════════════════════════════════════════
//  EMPLOYMENT TYPE RESTRICTIONS
// ════════════════════════════════════════════════════════
function restrictByEmploymentType() {
  const empType = (window._profileData && window._profileData.employment_type) ? window._profileData.employment_type : 'Permanent';
  const normalized = String(empType || '').toLowerCase().replace(/[^a-z]/g, '');
  const hasPermanentLikeAccess = (normalized === 'permanent' || normalized === 'coterminus' || normalized === 'elective');
  
  // Store for use in other functions
  window._employmentType = empType;
  
  if (!hasPermanentLikeAccess) {
    // Non permanent-like employees cannot access leave-related features
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
          showToast(`📋 Leave features are only available to Permanent, Co-Terminus, and Elective employees.`, 'info');
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
          showToast(`📋 Leave features are only available to Permanent, Co-Terminus, and Elective employees.`, 'info');
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
          <span style="font-size:.75rem;opacity:.90;font-weight:600;">You can upload documents, fill PDS/SALN, and request service records. Leave features are available to Permanent, Co-Terminus, and Elective employees only.</span>
        </div>
      </div>
    `;
    topbar.parentNode.insertBefore(banner, topbar.nextSibling);
  }
}

function goPage(name, btn) {
  document.querySelectorAll('.page').forEach(p => {
    p.classList.remove('active');
    p.style.display = 'none';
  });
  document.querySelectorAll('.sb-item').forEach(b => b.classList.remove('active'));
  var pageDiv = document.getElementById(`page-${name}`);
  if (pageDiv) {
    pageDiv.classList.add('active');
    pageDiv.style.display = '';
  }
  if (btn) btn.classList.add('active');
  if (PAGE_META[name]) {
    document.getElementById('tbTitle').textContent = PAGE_META[name][0];
    document.getElementById('tbSub').textContent   = PAGE_META[name][1];
  }
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('mobOverlay').classList.remove('show');
  if (name === 'requests')  loadRequests();
  if (name === 'coe')       loadCoeRequests();
  if (name === 'balances')  renderBalancesPage();
  if (name === 'documents') loadDocuments();
  if (name === 'pds')       loadPDS();
  if (name === 'saln')      loadSALN();
  if (name === 'service_record') loadServiceRecord();
  if (name === 'file')      loadFileLeavePanel();
  if (name === 'annex_b')  loadAnnex('annex_b');
  if (name === 'annex_c')  loadAnnex('annex_c');
  if (name === 'dept_head_leaves') loadDeptHeadLeaves();

  // Always open selected page from the top.
  window.scrollTo({ top: 0, left: 0, behavior: 'auto' });
  var mainPane = document.querySelector('.main');
  if (mainPane) mainPane.scrollTop = 0;
}
// ════════════════════════════════════════════════════════
//  DEPT HEAD LEAVE APPROVAL
// ════════════════════════════════════════════════════════
let dhCurrentPage = 1;
const dhPageSize  = 8;
let dhAllRows     = [];
let dhApproveTarget = null, dhDenyTarget = null, dhDebounceTimer = null;

function dhDebounce() { clearTimeout(dhDebounceTimer); dhDebounceTimer = setTimeout(() => loadDeptHeadLeaves(1), 320); }
function dhResetFilters() {
  document.getElementById('dhSearch').value = '';
  document.getElementById('dhStatusFilter').value = '';
  document.getElementById('dhDeptFilter').value = '';
  document.getElementById('dhTypeFilter').value = '';
  document.getElementById('dhMonthFilter').value = '';
  loadDeptHeadLeaves(1);
}

async function loadDeptHeadLeaves(page) {
  if (page) dhCurrentPage = page;
  const tbody = document.getElementById('dhTableBody');
  const empty = document.getElementById('dhEmptyState');
  tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:40px;color:var(--g400);">Loading…</td></tr>';
  empty.style.display = 'none';

  try {
    const res = await fetch('Employee_portal.php?action=dept_head_pending_leaves').then(r => r.json());
    if (!res.success) {
      tbody.innerHTML = `<tr><td colspan="9" style="text-align:center;padding:40px;color:#ef4444;">${res.message||'Failed to load.'}</td></tr>`;
      return;
    }

    // Client-side filter
    const search = (document.getElementById('dhSearch').value || '').toLowerCase();
    const status = document.getElementById('dhStatusFilter').value;
    const dept   = document.getElementById('dhDeptFilter').value;
    const type   = document.getElementById('dhTypeFilter').value;
    const month  = document.getElementById('dhMonthFilter').value;
    let rows = res.data || [];

    if (search) rows = rows.filter(r =>
      (r.first_name + ' ' + r.last_name).toLowerCase().includes(search) ||
      (r.type_name || '').toLowerCase().includes(search)
    );
    if (status) rows = rows.filter(r => r.status === status);
    if (dept)   rows = rows.filter(r => (r.department || '') === dept);
    if (type)   rows = rows.filter(r => (r.type_name  || '') === type);
    if (month)  rows = rows.filter(r => {
      const d = new Date(r.date_from);
      return (d.getMonth() + 1) == parseInt(month);
    });

    dhAllRows = rows;

    // Populate dept and type dropdowns once from the full data
    const allData = res.data || [];
    const deptSel = document.getElementById('dhDeptFilter');
    const typeSel = document.getElementById('dhTypeFilter');
    if (deptSel.options.length <= 1) {
      [...new Set(allData.map(r => r.department).filter(Boolean))].sort().forEach(d => {
        const o = document.createElement('option'); o.value = d; o.textContent = d; deptSel.appendChild(o);
      });
    }
    if (typeSel.options.length <= 1) {
      [...new Set(allData.map(r => r.type_name).filter(Boolean))].sort().forEach(t => {
        const o = document.createElement('option'); o.value = t; o.textContent = t; typeSel.appendChild(o);
      });
    }
    // Stat counts (from full unfiltered data)
    const all = res.data || [];
    const pendingCount = all.filter(r => r.status === 'Pending Dept Head').length;
    const approvedCount = all.filter(r => r.status === 'Pending HR').length;
    const deniedCount = all.filter(r => r.status === 'Denied by Dept Head').length;
    document.getElementById('dh-sc-pending').textContent  = pendingCount;
    document.getElementById('dh-sc-approved').textContent = approvedCount;
    document.getElementById('dh-sc-denied').textContent   = deniedCount;
    document.getElementById('dh-sc-total').textContent    = all.length;
    // Update sidebar badge
    const dhBadge = document.getElementById('dhPendBadge');
    if (dhBadge) { dhBadge.textContent = pendingCount || ''; dhBadge.style.display = pendingCount ? 'inline-block' : 'none'; }

    // Pagination
    const total  = rows.length;
    const pages  = Math.max(1, Math.ceil(total / dhPageSize));
    dhCurrentPage = Math.min(dhCurrentPage, pages);
    const offset = (dhCurrentPage - 1) * dhPageSize;
    const paged  = rows.slice(offset, offset + dhPageSize);

    // Page info
    const from = total ? offset + 1 : 0;
    const to   = Math.min(offset + dhPageSize, total);
    document.getElementById('dhPageInfo').textContent = `Showing ${from}–${to} of ${total} request${total !== 1 ? 's' : ''}`;

    // Page buttons
    const pb = document.getElementById('dhPageBtns');
    pb.innerHTML = '';
    const mkBtn = (label, pg, disabled) => {
      const b = document.createElement('button');
      b.style.cssText = `min-width:32px;height:32px;border-radius:7px;border:1.5px solid ${pg === dhCurrentPage ? 'var(--primary)' : 'var(--g200)'};background:${pg === dhCurrentPage ? 'var(--primary)' : '#fff'};cursor:${disabled ? 'default' : 'pointer'};font-size:.80rem;font-weight:700;display:inline-flex;align-items:center;justify-content:center;padding:0 8px;color:${pg === dhCurrentPage ? '#fff' : 'var(--g600)'};font-family:'Nunito',sans-serif;opacity:${disabled ? '.4' : '1'};`;
      b.textContent = label; b.disabled = disabled;
      b.onclick = () => loadDeptHeadLeaves(pg);
      pb.appendChild(b);
    };
    mkBtn('‹', dhCurrentPage - 1, dhCurrentPage === 1);
    for (let i = 1; i <= pages; i++) mkBtn(i, i, false);
    mkBtn('›', dhCurrentPage + 1, dhCurrentPage >= pages);

    if (!paged.length) {
      tbody.innerHTML = '';
      empty.style.display = 'block';
      return;
    }

    const COLORS = ['#2563eb','#22c55e','#f59e0b','#ef4444','#a855f7','#06b6d4','#ec4899','#f97316'];
    const aColor = s => COLORS[Math.abs([...s].reduce((a,c) => a + c.charCodeAt(0), 0)) % COLORS.length];
    const ini    = n => n.split(' ').slice(0,2).map(w => w[0]).join('').toUpperCase();
    const fmtD   = d => d ? new Date(d.slice(0,10)+'T00:00:00').toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}) : '—';

    const TYPE_COLORS = {Vacation:'#2563eb',Sick:'#f59e0b',Emergency:'#ef4444',Maternity:'#ec4899',Paternity:'#22c55e',Special:'#a855f7'};

    tbody.innerHTML = paged.map(r => {
      const name   = `${r.first_name} ${r.last_name}`;
      const col    = aColor(name);
      const tc     = TYPE_COLORS[r.type_name] || '#2563eb';
      const reason = (r.reason || '').length > 48 ? r.reason.slice(0, 48) + '…' : (r.reason || '—');
      // Button logic
      let approveBtn = '', denyBtn = '';
      if (r.status === 'Pending Dept Head') {
        approveBtn = `<button onclick="dhOpenApprove(${r.request_id},'${escapeHtml(name)}','${r.type_name}','${fmtD(r.date_from)}','${fmtD(r.date_to)}',${r.days_requested})"
          style="padding:5px 11px;border-radius:7px;border:none;font-size:.72rem;font-weight:700;cursor:pointer;background:#dcfce7;color:#166534;font-family:'Nunito',sans-serif;transition:all .2s;"
          onmouseover="this.style.background='#22c55e';this.style.color='#fff';" onmouseout="this.style.background='#dcfce7';this.style.color='#166534';">✅ Approve</button>`;
        denyBtn = `<button onclick="dhOpenDeny(${r.request_id},'${escapeHtml(name)}','${r.type_name}','${fmtD(r.date_from)}','${fmtD(r.date_to)}',${r.days_requested})"
          style="padding:5px 11px;border-radius:7px;border:none;font-size:.72rem;font-weight:700;cursor:pointer;background:#fee2e2;color:#991b1b;font-family:'Nunito',sans-serif;transition:all .2s;"
          onmouseover="this.style.background='#ef4444';this.style.color='#fff';" onmouseout="this.style.background='#fee2e2';this.style.color='#991b1b';">✗ Deny</button>`;
      } else if (r.status === 'Pending HR' || r.status === 'Approved') {
        approveBtn = `<button disabled style="padding:5px 11px;border-radius:7px;border:none;font-size:.72rem;font-weight:700;background:#dcfce7;color:#b6e4c6;opacity:.6;cursor:not-allowed;font-family:'Nunito',sans-serif;">✅ Approve</button>`;
      } else if (r.status === 'Denied by Dept Head' || r.status === 'Denied') {
        denyBtn = `<button disabled style="padding:5px 11px;border-radius:7px;border:none;font-size:.72rem;font-weight:700;background:#fee2e2;color:#fca5a5;opacity:.6;cursor:not-allowed;font-family:'Nunito',sans-serif;">✗ Deny</button>`;
      }
      return `<tr style="transition:background .15s;" onmouseover="this.style.background='#f0f6ff'" onmouseout="this.style.background=''">
        <td style="padding:13px 16px;border-bottom:1px solid var(--g100);vertical-align:middle;">
          <div style="display:flex;align-items:center;gap:10px;">
            <div style="width:36px;height:36px;border-radius:50%;background:${col};display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:800;color:#fff;flex-shrink:0;box-shadow:0 2px 5px rgba(0,0,0,.14);">${ini(name)}</div>
            <div>
              <div style="font-weight:700;font-size:.875rem;color:var(--g800);">${name}</div>
              <div style="font-size:.70rem;color:var(--g400);margin-top:1px;">${r.department || '—'}</div>
            </div>
          </div>
        </td>
        <td style="padding:13px 16px;border-bottom:1px solid var(--g100);vertical-align:middle;">
          <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:.70rem;font-weight:700;background:${tc}22;color:${tc};">${r.type_name}</span>
        </td>
        <td style="padding:13px 16px;border-bottom:1px solid var(--g100);vertical-align:middle;white-space:nowrap;">
          <span style="display:inline-block;padding:3px 10px;background:var(--g100);border-radius:20px;font-size:.72rem;font-weight:800;color:var(--g600);">${parseFloat(r.leave_balance || 0).toFixed(2)} days</span>
        </td>
        <td style="padding:13px 16px;border-bottom:1px solid var(--g100);font-size:.80rem;color:var(--g600);vertical-align:middle;white-space:nowrap;">${fmtD(r.date_from)}</td>
        <td style="padding:13px 16px;border-bottom:1px solid var(--g100);font-size:.80rem;color:var(--g600);vertical-align:middle;white-space:nowrap;">${fmtD(r.date_to)}</td>
        <td style="padding:13px 16px;border-bottom:1px solid var(--g100);vertical-align:middle;">
          <span style="display:inline-block;padding:3px 10px;background:var(--g100);border-radius:20px;font-size:.72rem;font-weight:800;color:var(--g600);">${r.days_requested} day${r.days_requested != 1 ? 's' : ''}</span>
        </td>
        <td style="padding:13px 16px;border-bottom:1px solid var(--g100);font-size:.78rem;color:var(--g600);max-width:180px;vertical-align:middle;">${escapeHtml(reason)}</td>
        <td style="padding:13px 16px;border-bottom:1px solid var(--g100);font-size:.78rem;color:var(--g400);vertical-align:middle;white-space:nowrap;">${fmtD(r.filed_at)}</td>
        <td style="padding:13px 16px;border-bottom:1px solid var(--g100);text-align:center;vertical-align:middle;">
          <div style="display:flex;gap:5px;align-items:center;justify-content:center;">
            ${approveBtn}
            ${denyBtn}
          </div>
        </td>
      </tr>`;
    }).join('');
  } catch(e) {
    tbody.innerHTML = `<tr><td colspan="9" style="text-align:center;padding:40px;color:#ef4444;">Network error loading leave requests.</td></tr>`;
  }
}

function dhOpenApprove(id, name, type, from, to, days) {
  dhApproveTarget = id;
  document.getElementById('dhApproveText').textContent = `Approving ${type} Leave for ${name} (${from} – ${to}, ${days} day${days != 1 ? 's' : ''}). This will be forwarded to HR for final approval.`;
  document.getElementById('dhApproveRemark').value = '';
  document.getElementById('dhModalApprove').style.display = 'flex';
}

function dhOpenDeny(id, name, type, from, to, days) {
  dhDenyTarget = id;
  document.getElementById('dhDenyText').textContent = `Denying ${type} Leave for ${name} (${from} – ${to}, ${days} day${days != 1 ? 's' : ''}).`;
  document.getElementById('dhDenyRemark').value = '';
  document.getElementById('dhModalDeny').style.display = 'flex';
}

async function dhConfirmApprove() {
  const btn = document.getElementById('dhApproveBtn');
  btn.disabled = true; btn.textContent = '⏳ Processing…';
  const fd = new FormData();
  fd.append('action', 'dept_head_approve_leave');
  fd.append('leave_id', dhApproveTarget);
  fd.append('remarks', document.getElementById('dhApproveRemark').value.trim());
  fd.append('csrf_token', CSRF_TOKEN);
  try {
    const r = await fetch('Employee_portal.php', { method: 'POST', body: fd }).then(r => r.json());
    if (r.success) {
      showToast('✅ ' + r.message, 'success');
      document.getElementById('dhModalApprove').style.display = 'none';
      loadDeptHeadLeaves(dhCurrentPage);
    } else {
      showToast('❌ ' + (r.message || 'Action failed.'), 'error');
    }
  } catch(e) { showToast('❌ Network error.', 'error'); }
  finally { btn.disabled = false; btn.textContent = '✅ Yes, Approve'; }
}

async function dhConfirmDeny() {
  const btn = document.getElementById('dhDenyBtn');
  btn.disabled = true; btn.textContent = '⏳ Processing…';
  const fd = new FormData();
  fd.append('action', 'dept_head_deny_leave');
  fd.append('leave_id', dhDenyTarget);
  fd.append('remarks', document.getElementById('dhDenyRemark').value.trim());
  fd.append('csrf_token', CSRF_TOKEN);
  try {
    const r = await fetch('Employee_portal.php', { method: 'POST', body: fd }).then(r => r.json());
    if (r.success) {
      showToast('Leave request denied.', 'error');
      document.getElementById('dhModalDeny').style.display = 'none';
      loadDeptHeadLeaves(dhCurrentPage);
    } else {
      showToast('❌ ' + (r.message || 'Action failed.'), 'error');
    }
  } catch(e) { showToast('❌ Network error.', 'error'); }
  finally { btn.disabled = false; btn.textContent = '✗ Yes, Deny'; }
}

function escapeHtml(str) {
  return String(str || '').replace(/[&<>"']/g, m => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m]));
}

// Fetch and update the dept-head pending badge without rendering the table
async function updateDhBadge() {
  const badge = document.getElementById('dhPendBadge');
  if (!badge) return;
  try {
    const j = await fetch('Employee_portal.php?action=dept_head_pending_leaves').then(r => r.json());
    if (j.success) {
      const count = (j.data || []).filter(r => r.status === 'Pending Dept Head').length;
      badge.textContent = count || '';
      badge.style.display = count ? 'inline-block' : 'none';
    }
  } catch(e) {}
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
  const viewBtn = document.getElementById('btnViewSR');

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

  if (viewBtn) viewBtn.style.display = records.length ? 'inline-flex' : 'none';

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
  const escSR = v => String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  const rows = records.map((sr, idx) => {
    const branchRaw = String(sr.branch || '').trim();
    const branchNorm = branchRaw.toLowerCase().replace(/[^a-z]/g, '');
    const branchDisp = branchNorm === 'localgovernmentunit' ? 'LGU' : (branchRaw || '—');
    const rowBg = idx % 2 === 0 ? '#f9fafb' : '#fff';
    const baseTd = 'border:1px solid var(--g200);padding:5px 6px;vertical-align:middle;line-height:1.3;';
    // Show 'Present' if no to-date
    const toDate = sr.date_to ? fmt(sr.date_to) : '<span style="color:#22c55e;font-weight:700;">Present</span>';
    return `
    <tr style="background:${rowBg};">
      <td style="${baseTd}white-space:nowrap;font-size:.77rem;text-align:center;">${sr.date_from ? fmt(sr.date_from) : '—'}</td>
      <td style="${baseTd}white-space:nowrap;font-size:.77rem;text-align:center;">${toDate}</td>
      <td style="${baseTd}font-weight:600;word-break:break-word;">${escSR(sr.designation||'—')}</td>
      <td style="${baseTd}text-align:center;"><span style="display:inline-block;padding:3px 9px;border-radius:12px;font-size:.70rem;font-weight:800;background:#eff6ff;color:#1e40af;">${escSR(sr.status||'—')}</span></td>
      <td style="${baseTd}text-align:right;font-weight:700;white-space:nowrap;">${sr.salary ? '₱'+parseFloat(sr.salary).toLocaleString('en-PH',{minimumFractionDigits:2}) : '—'}</td>
      <td style="${baseTd}word-break:break-word;">${escSR(branchDisp)}</td>
      <td style="${baseTd}word-break:break-word;">${escSR(sr.station||'—')}</td>
      <td style="${baseTd}font-size:.75rem;color:var(--g500);text-align:center;">${escSR(sr.lvbs_wo_pay||'—')}</td>
      <td style="${baseTd}white-space:nowrap;font-size:.77rem;text-align:center;">${sr.separation_date ? fmt(sr.separation_date) : '—'}</td>
      <td style="${baseTd}word-break:break-word;">${escSR(sr.separation_cause||'—')}</td>
    </tr>`;
  }).join('');

  cont.innerHTML = `
    <div style="overflow-x:auto;border:1px solid var(--g200);border-top:none;border-radius:0 0 12px 12px;">
      <table style="width:100%;min-width:1120px;border-collapse:collapse;table-layout:fixed;font-size:.80rem;background:#fff;">
        <colgroup>
          <col style="width:90px"><col style="width:90px"><col style="width:180px"><col style="width:100px"><col style="width:110px"><col style="width:120px"><col style="width:120px"><col style="width:90px"><col style="width:90px"><col style="width:120px">
        </colgroup>
        <thead style="position:sticky;top:0;z-index:2;background:#f1f5f9;">
          <tr>
            <th colspan="2" style="background:#dbeafe;color:#1e40af;border:1px solid #93c5fd;padding:6px 6px;text-align:center;font-size:.70rem;font-weight:800;text-transform:uppercase;">Service</th>
            <th colspan="3" style="background:#f3e8ff;color:#6b21a8;border:1px solid #d8b4fe;padding:6px 6px;text-align:center;font-size:.70rem;font-weight:800;text-transform:uppercase;">Record of Appointment</th>
            <th colspan="2" style="background:#dcfce7;color:#166534;border:1px solid #86efac;padding:6px 6px;text-align:center;font-size:.70rem;font-weight:800;text-transform:uppercase;">Office / Entity</th>
            <th rowspan="2" style="background:var(--g100);border:1px solid var(--g200);padding:6px 6px;text-align:center;font-size:.70rem;font-weight:800;text-transform:uppercase;vertical-align:bottom;">LVBS<br>W/O Pay</th>
            <th colspan="2" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;padding:6px 6px;text-align:center;font-size:.70rem;font-weight:800;text-transform:uppercase;">Separation</th>
          </tr>
          <tr>
            <th style="background:var(--g100);border:1px solid var(--g200);padding:5px 6px;font-size:.68rem;font-weight:800;color:var(--g600);text-align:center;">From</th>
            <th style="background:var(--g100);border:1px solid var(--g200);padding:5px 6px;font-size:.68rem;font-weight:800;color:var(--g600);text-align:center;">To</th>
            <th style="background:var(--g100);border:1px solid var(--g200);padding:5px 6px;font-size:.68rem;font-weight:800;color:var(--g600);">Designation</th>
            <th style="background:var(--g100);border:1px solid var(--g200);padding:5px 6px;font-size:.68rem;font-weight:800;color:var(--g600);text-align:center;">Status</th>
            <th style="background:var(--g100);border:1px solid var(--g200);padding:5px 6px;font-size:.68rem;font-weight:800;color:var(--g600);text-align:right;">Salary/Annum</th>
            <th style="background:var(--g100);border:1px solid var(--g200);padding:5px 6px;font-size:.68rem;font-weight:800;color:var(--g600);">Branch</th>
            <th style="background:var(--g100);border:1px solid var(--g200);padding:5px 6px;font-size:.68rem;font-weight:800;color:var(--g600);">Station/Place</th>
            <th style="background:var(--g100);border:1px solid var(--g200);padding:5px 6px;font-size:.68rem;font-weight:800;color:var(--g600);text-align:center;">Date</th>
            <th style="background:var(--g100);border:1px solid var(--g200);padding:5px 6px;font-size:.68rem;font-weight:800;color:var(--g600);">Cause</th>
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

function viewMySR() {
  if (!_srData) {
    showToast('ℹ️ Please wait until your service record is loaded.', 'info');
    return;
  }

  const emp = _srData.employee || {};
  const records = _srData.records || [];
  if (!records.length) {
    showToast('ℹ️ No service record entries available yet.', 'info');
    return;
  }

  if (window.SRPrintTemplate && typeof window.SRPrintTemplate.renderHTML === 'function') {
    const modal = document.getElementById('srViewModal');
    const frame = document.getElementById('srViewFrame');
    const fullName = [emp.first_name || '', emp.middle_name || '', emp.last_name || ''].join(' ').replace(/\s+/g, ' ').trim() || 'Employee';
    const initials = ((emp.first_name || '').charAt(0) + (emp.last_name || '').charAt(0)).toUpperCase() || 'SR';
    const meta = [emp.position_title || '', emp.department_name || ''].filter(Boolean).join(' · ') || 'Official SR Form Preview';

    const nameEl = document.getElementById('srViewName');
    const metaEl = document.getElementById('srViewMeta');
    const iniEl  = document.getElementById('srViewInitials');
    if (nameEl) nameEl.textContent = fullName;
    if (metaEl) metaEl.textContent = meta;
    if (iniEl) iniEl.textContent = initials;

    frame.srcdoc = window.SRPrintTemplate.renderHTML(emp, records);
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    return;
  }

  // Fallback preview if shared SR template is unavailable.
  const esc = v => String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  const f = d => d ? new Date(d + (String(d).length === 10 ? 'T00:00:00' : '')).toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}) : '—';
  const rows = records.map((sr, i) => `
    <tr style="background:${i % 2 === 0 ? '#f9fafb' : '#fff'};">
      <td>${f(sr.date_from)}</td>
      <td>${sr.date_to ? f(sr.date_to) : 'Present'}</td>
      <td>${esc(sr.designation || '—')}</td>
      <td>${esc(sr.status || '—')}</td>
      <td>${sr.salary ? '₱' + parseFloat(sr.salary).toLocaleString('en-PH',{minimumFractionDigits:2}) : '—'}</td>
      <td>${esc(sr.branch || '—')}</td>
      <td>${esc(sr.station || '—')}</td>
      <td>${esc(sr.lvbs_wo_pay || '—')}</td>
      <td>${f(sr.separation_date)}</td>
      <td>${esc(sr.separation_cause || '—')}</td>
    </tr>`).join('');

  const w = window.open('', '_blank', 'width=1200,height=900');
  w.document.write(`<!DOCTYPE html><html><head><title>Service Record Form Preview</title>
    <style>
      body{font-family:Arial,sans-serif;margin:18px;color:#0f172a;}
      .head{margin-bottom:10px}
      .head h2{margin:0 0 4px;font-size:22px}
      .head p{margin:0;font-size:13px;color:#475569}
      table{width:100%;border-collapse:collapse;font-size:12px}
      th,td{border:1px solid #cbd5e1;padding:6px 7px;vertical-align:top}
      thead th{background:#f1f5f9;text-align:left}
    </style>
  </head><body>
    <div class="head">
      <h2>Service Record Form Preview</h2>
      <p>${esc((emp.last_name || '') + ', ' + (emp.first_name || '') + ' ' + (emp.middle_name || ''))}</p>
    </div>
    <table>
      <thead><tr><th>From</th><th>To</th><th>Designation</th><th>Status</th><th>Salary/Annum</th><th>Branch</th><th>Station/Place</th><th>LVBS W/O Pay</th><th>Separation Date</th><th>Cause</th></tr></thead>
      <tbody>${rows}</tbody>
    </table>
  </body></html>`);
  w.document.close();
  w.focus();
}

function closeSRViewModal() {
  const modal = document.getElementById('srViewModal');
  const frame = document.getElementById('srViewFrame');
  if (modal) modal.style.display = 'none';
  if (frame) frame.srcdoc = '';
  document.body.style.overflow = '';
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
          return '<div class="np-item '+(isRead?'':' unread')+'" data-nid="'+n.nid+'" data-dbid="'+(n.db_id||'')+'" onclick="epNotifClick(\''  +n.nid+ '\',\''  +n.page+ '\',this)">'+
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

// ── Poll for latest leave requests/statuses every 30 seconds ───────────────
async function pollLeaveRequests() {
  const reqPage = document.getElementById('page-requests');
  if (!reqPage || !reqPage.classList.contains('active')) return;
  await loadRequests();
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
  // Mark any DB-backed (leave_notifications) items as read server-side
  document.querySelectorAll('.np-item[data-dbid]').forEach(el => {
    const dbid = el.getAttribute('data-dbid');
    if (dbid) {
      fetch('Employee_portal.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=mark_notif_read&notification_id=' + encodeURIComponent(dbid) }).catch(()=>{});
    }
  });
  document.querySelectorAll('.np-item.unread').forEach(el => el.classList.remove('unread'));
  const cnt = document.getElementById('epNotifCnt');
  if (cnt) { cnt.classList.remove('show'); cnt.textContent = ''; }
  const p = document.getElementById('epNotifPanel');
  if (p) p.classList.remove('open');
}
function epNotifClick(nid, page, el) {
  // Mark this one as read in localStorage
  const read = getEpReadNotifs();
  if (nid && !read.includes(nid)) { read.push(nid); saveEpReadNotifs(read); }
  el.classList.remove('unread');
  // Mark as read server-side if it came from leave_notifications DB
  const dbid = el.getAttribute('data-dbid');
  if (dbid) {
    fetch('Employee_portal.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: 'action=mark_notif_read&notification_id=' + encodeURIComponent(dbid) }).catch(()=>{});
  }
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
    var fullName = ((e.first_name || '') + ' ' + (e.last_name || '') + (e.suffix_name ? (' ' + e.suffix_name) : '')).trim();
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
    renderSignatureStatus(e);
    var personal = [
      {label:'First Name',   key:'first_name',     val:e.first_name,     type:'text'},
      {label:'Last Name',    key:'last_name',       val:e.last_name,      type:'text'},
      {label:'Middle Name',  key:'middle_name',     val:e.middle_name,    type:'text'},
      {label:'Suffix Name',  key:'suffix_name',     val:e.suffix_name,    type:'text'},
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
      var fn2 = ((ep.first_name||'') + ' ' + (ep.last_name||'') + (ep.suffix_name ? (' ' + ep.suffix_name) : '')).trim();
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

function renderSignatureStatus(profile) {
  var statusEl = document.getElementById('sigStatusText');
  var fileEl = document.getElementById('sigFileName');
  if (!statusEl || !fileEl) return;

  if (!profile || !profile.signature_file_path) {
    statusEl.textContent = 'No signature uploaded.';
    statusEl.style.color = 'var(--g500)';
    fileEl.textContent = '';
    return;
  }

  var when = profile.signature_uploaded_at
    ? new Date(profile.signature_uploaded_at).toLocaleString('en-PH', { month:'short', day:'numeric', year:'numeric', hour:'2-digit', minute:'2-digit' })
    : 'recently';
  statusEl.textContent = 'Signature saved (' + when + ').';
  statusEl.style.color = '#166534';
  fileEl.textContent = profile.signature_original_name || 'signature.png';
}

var sigCropState = {
  img: null,
  originalName: 'signature.png',
  zoomPercent: 100,
  minScale: 1,
  scale: 1,
  offsetX: 0,
  offsetY: 0,
  cropX: 0,
  cropY: 0,
  cropW: 0,
  cropH: 0,
  drawX: 0,
  drawY: 0,
  drawW: 0,
  drawH: 0,
  dragging: false,
  lastX: 0,
  lastY: 0
};

function openSignatureCropper(input) {
  if (!input || !input.files || !input.files[0]) return;
  var file = input.files[0];
  if (!['image/png', 'image/jpeg'].includes(file.type)) {
    showToast('❌ Only PNG or JPG signatures are allowed.', 'error');
    input.value = '';
    return;
  }
  if (file.size > 5 * 1024 * 1024) {
    showToast('❌ Signature must be under 5MB.', 'error');
    input.value = '';
    return;
  }

  var reader = new FileReader();
  reader.onload = function(ev) {
    var img = new Image();
    img.onload = function() {
      sigCropState.img = img;
      sigCropState.originalName = file.name || 'signature.png';
      sigCropState.zoomPercent = 100;
      sigCropState.offsetX = 0;
      sigCropState.offsetY = 0;
      initSignatureCropViewport();
      bindSignatureCropCanvasEvents();
      openModal('modalSignatureCrop');
      renderSignatureCropCanvas();
    };
    img.onerror = function() { showToast('❌ Failed to read signature image.', 'error'); };
    img.src = ev.target.result;
  };
  reader.readAsDataURL(file);
  input.value = '';
}

function closeSignatureCropper() {
  closeModal('modalSignatureCrop');
  sigCropState.dragging = false;
}

function initSignatureCropViewport() {
  var canvas = document.getElementById('sigCropCanvas');
  var zoom = document.getElementById('sigCropZoom');
  var zoomText = document.getElementById('sigCropZoomValue');
  if (!canvas || !sigCropState.img) return;

  var targetH = Math.round(canvas.clientHeight || 320);
  var targetW = Math.round(canvas.clientWidth || 720);
  if (targetW < 320) targetW = 320;
  if (targetH < 180) targetH = 180;
  canvas.width = targetW;
  canvas.height = targetH;

  var cropW = canvas.width - 64;
  var cropH = Math.round(cropW / 4);
  var maxCropH = canvas.height - 56;
  if (cropH > maxCropH) {
    cropH = maxCropH;
    cropW = Math.round(cropH * 4);
  }
  sigCropState.cropW = cropW;
  sigCropState.cropH = cropH;
  sigCropState.cropX = Math.round((canvas.width - cropW) / 2);
  sigCropState.cropY = Math.round((canvas.height - cropH) / 2);

  sigCropState.minScale = Math.max(
    sigCropState.cropW / sigCropState.img.naturalWidth,
    sigCropState.cropH / sigCropState.img.naturalHeight
  );
  sigCropState.scale = sigCropState.minScale;
  sigCropState.offsetX = 0;
  sigCropState.offsetY = 0;
  sigCropState.zoomPercent = 100;

  if (zoom) zoom.value = '100';
  if (zoomText) zoomText.textContent = '100%';
}

function clampSignatureCropOffsets() {
  var rangeX = Math.max(0, (sigCropState.drawW - sigCropState.cropW) / 2);
  var rangeY = Math.max(0, (sigCropState.drawH - sigCropState.cropH) / 2);
  sigCropState.offsetX = Math.max(-rangeX, Math.min(rangeX, sigCropState.offsetX));
  sigCropState.offsetY = Math.max(-rangeY, Math.min(rangeY, sigCropState.offsetY));
}

function renderSignatureCropCanvas() {
  var canvas = document.getElementById('sigCropCanvas');
  if (!canvas || !sigCropState.img) return;
  var ctx = canvas.getContext('2d');
  if (!ctx) return;

  var img = sigCropState.img;
  sigCropState.drawW = img.naturalWidth * sigCropState.scale;
  sigCropState.drawH = img.naturalHeight * sigCropState.scale;
  clampSignatureCropOffsets();

  sigCropState.drawX = (canvas.width - sigCropState.drawW) / 2 + sigCropState.offsetX;
  sigCropState.drawY = (canvas.height - sigCropState.drawH) / 2 + sigCropState.offsetY;

  ctx.clearRect(0, 0, canvas.width, canvas.height);
  ctx.fillStyle = '#ffffff';
  ctx.fillRect(0, 0, canvas.width, canvas.height);
  ctx.drawImage(img, sigCropState.drawX, sigCropState.drawY, sigCropState.drawW, sigCropState.drawH);

  ctx.save();
  ctx.fillStyle = 'rgba(15,23,42,0.38)';
  ctx.beginPath();
  ctx.rect(0, 0, canvas.width, canvas.height);
  ctx.rect(sigCropState.cropX, sigCropState.cropY, sigCropState.cropW, sigCropState.cropH);
  ctx.fill('evenodd');
  ctx.restore();

  ctx.strokeStyle = '#ffffff';
  ctx.lineWidth = 2;
  ctx.strokeRect(sigCropState.cropX + 0.5, sigCropState.cropY + 0.5, sigCropState.cropW - 1, sigCropState.cropH - 1);

  updateSignatureCropPreview();
}

function getSignatureCanvasPoint(evt) {
  var canvas = document.getElementById('sigCropCanvas');
  if (!canvas) return { x: 0, y: 0 };
  var rect = canvas.getBoundingClientRect();
  var sx = canvas.width / rect.width;
  var sy = canvas.height / rect.height;
  return {
    x: (evt.clientX - rect.left) * sx,
    y: (evt.clientY - rect.top) * sy
  };
}

function bindSignatureCropCanvasEvents() {
  var canvas = document.getElementById('sigCropCanvas');
  if (!canvas || canvas.dataset.boundSigCrop === '1') return;
  canvas.dataset.boundSigCrop = '1';

  canvas.addEventListener('pointerdown', function(evt) {
    if (!sigCropState.img) return;
    var p = getSignatureCanvasPoint(evt);
    var inside = p.x >= sigCropState.cropX && p.x <= (sigCropState.cropX + sigCropState.cropW)
      && p.y >= sigCropState.cropY && p.y <= (sigCropState.cropY + sigCropState.cropH);
    if (!inside) return;
    sigCropState.dragging = true;
    sigCropState.lastX = p.x;
    sigCropState.lastY = p.y;
    canvas.classList.add('dragging');
    canvas.setPointerCapture(evt.pointerId);
  });

  canvas.addEventListener('pointermove', function(evt) {
    if (!sigCropState.dragging) return;
    var p = getSignatureCanvasPoint(evt);
    sigCropState.offsetX += (p.x - sigCropState.lastX);
    sigCropState.offsetY += (p.y - sigCropState.lastY);
    sigCropState.lastX = p.x;
    sigCropState.lastY = p.y;
    renderSignatureCropCanvas();
  });

  function stopDrag() {
    sigCropState.dragging = false;
    canvas.classList.remove('dragging');
  }
  canvas.addEventListener('pointerup', stopDrag);
  canvas.addEventListener('pointercancel', stopDrag);
  canvas.addEventListener('pointerleave', stopDrag);
}

function onSignatureCropZoom(val) {
  if (!sigCropState.img) return;
  var zoom = Math.max(100, Math.min(300, parseInt(val, 10) || 100));
  sigCropState.zoomPercent = zoom;
  sigCropState.scale = sigCropState.minScale * (zoom / 100);
  var zoomText = document.getElementById('sigCropZoomValue');
  if (zoomText) zoomText.textContent = zoom + '%';
  renderSignatureCropCanvas();
}

function updateSignatureCropPreview() {
  var imgEl = document.getElementById('sigCropPreview');
  if (!imgEl || !sigCropState.img) return;
  var tmp = document.createElement('canvas');
  tmp.width = 520;
  tmp.height = 130;
  var tctx = tmp.getContext('2d');
  if (!tctx) return;

  var sx = ((sigCropState.cropX - sigCropState.drawX) / sigCropState.drawW) * sigCropState.img.naturalWidth;
  var sy = ((sigCropState.cropY - sigCropState.drawY) / sigCropState.drawH) * sigCropState.img.naturalHeight;
  var sw = (sigCropState.cropW / sigCropState.drawW) * sigCropState.img.naturalWidth;
  var sh = (sigCropState.cropH / sigCropState.drawH) * sigCropState.img.naturalHeight;

  tctx.fillStyle = '#ffffff';
  tctx.fillRect(0, 0, tmp.width, tmp.height);
  tctx.drawImage(sigCropState.img, sx, sy, sw, sh, 0, 0, tmp.width, tmp.height);
  imgEl.src = tmp.toDataURL('image/png');
}

function getCroppedSignatureBlob() {
  if (!sigCropState.img) return Promise.resolve(null);
  var out = document.createElement('canvas');
  out.width = 1200;
  out.height = 300;
  var octx = out.getContext('2d');
  if (!octx) return Promise.resolve(null);

  var sx = ((sigCropState.cropX - sigCropState.drawX) / sigCropState.drawW) * sigCropState.img.naturalWidth;
  var sy = ((sigCropState.cropY - sigCropState.drawY) / sigCropState.drawH) * sigCropState.img.naturalHeight;
  var sw = (sigCropState.cropW / sigCropState.drawW) * sigCropState.img.naturalWidth;
  var sh = (sigCropState.cropH / sigCropState.drawH) * sigCropState.img.naturalHeight;

  if (sw <= 0 || sh <= 0) return Promise.resolve(null);
  octx.fillStyle = '#ffffff';
  octx.fillRect(0, 0, out.width, out.height);
  octx.drawImage(sigCropState.img, sx, sy, sw, sh, 0, 0, out.width, out.height);

  return new Promise(function(resolve) {
    out.toBlob(function(blob) { resolve(blob); }, 'image/png', 0.98);
  });
}

async function confirmSignatureCropUpload() {
  if (!sigCropState.img) return;
  var btn = document.getElementById('sigCropUploadBtn');
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Uploading…'; }

  try {
    var blob = await getCroppedSignatureBlob();
    if (!blob) {
      showToast('❌ Failed to crop signature.', 'error');
      return;
    }

    var base = (sigCropState.originalName || 'signature').replace(/\.[^.]+$/, '');
    var uploadFile = new File([blob], base + '.png', { type: 'image/png' });
    var ok = await uploadProfileSignatureFile(uploadFile);
    if (ok) {
      var quickWrap = document.getElementById('sigCropQuickPreviewWrap');
      var quickImg = document.getElementById('sigCropQuickPreview');
      var cropPreview = document.getElementById('sigCropPreview');
      if (quickWrap && quickImg && cropPreview && cropPreview.src) {
        quickImg.src = cropPreview.src;
        quickWrap.style.display = 'block';
      }
      closeSignatureCropper();
    }
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = '✅ Use Crop & Upload'; }
  }
}

async function uploadProfileSignatureFile(file) {
  if (!file) return false;
  var fd = new FormData();
  fd.append('action', 'upload_signature');
  fd.append('signature', file);
  fd.append('csrf_token', CSRF_TOKEN);

  showToast('✍️ Uploading signature…', 'info');
  try {
    var res = await fetch('Employee_portal.php', { method: 'POST', body: fd });
    var j = await res.json();
    if (!j.success) {
      showToast('❌ ' + (j.message || 'Signature upload failed.'), 'error');
      return false;
    }

    showToast('✅ Signature saved to your profile.', 'success');
    if (window._profileData) {
      window._profileData.signature_file_path = j.signature_url || '';
      window._profileData.signature_original_name = j.signature_name || file.name;
      window._profileData.signature_uploaded_at = new Date().toISOString().slice(0, 19).replace('T', ' ');
      renderSignatureStatus(window._profileData);
    }
    return true;
  } catch (e) {
    showToast('❌ Network error while uploading signature.', 'error');
    return false;
  }
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
      var fullName = ((e.first_name||'') + ' ' + (e.last_name||'') + (e.suffix_name ? (' ' + e.suffix_name) : '')).trim();
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
  
  const cardsHtml = balanceData.map((b,i)=>{
    const c=pillColors[i%pillColors.length];
    const pct=b.total_credits>0?Math.round((b.used_days/b.total_credits)*100):0;
    const icon=TYPE_ICONS[b.type_name]||'📅';
    const isLocked = !!b.disabled_reason;
    const hasNoLeaves = parseFloat(b.remaining_days) <= 0;
    const pending = parseFloat(b.pending_days) || 0;
    const available = parseFloat(b.available_after_pending) || 0;
    const pendingAvailBanner = (pending > 0 || available > 0) ? `<div style="background:#dbeafe;border:1px solid #bfdbfe;border-radius:6px;padding:6px 10px;font-size:.73rem;font-weight:700;color:#1e40af;margin-top:12px;margin-bottom:8px;text-align:center;">⏳ ${pending} pending • ✓ ${available} available</div>` : '';
    const histHtml=b.history&&b.history.length?b.history.map(h=>`<div class="hist-row"><span style="font-weight:700;">${b.type_name} (${h.days_requested} day${h.days_requested!=1?'s':''})</span><span class="hist-date">${fmt(h.date_from)}${h.date_from!==h.date_to?' – '+fmt(h.date_to):''}</span></div>`).join(''):`<div class="hist-none">No ${b.type_name.toLowerCase()} leaves used this year.</div>`;
    const lockBanner = isLocked ? `<div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:7px 12px;font-size:.73rem;font-weight:700;color:#92400e;margin-bottom:8px;">🔒 ${b.disabled_reason}</div>` : '';
    const cardStyle = (isLocked || hasNoLeaves) ? 'opacity:.6;filter:grayscale(.6);' : '';
    // Always enable the + button unless locked, to allow filing even with zero balance (for Without Pay)
    const fileButton = !isLocked
      ? `<button class="file-btn" onclick="fileLeaveForType('${b.type_name}',${b.leave_type_id})" title="File ${b.type_name} request" style="background:none;border:none;cursor:pointer;font-size:2rem;padding:0;color:${c};transition:all .2s;hover:opacity:.8;">➕</button>`
      : '';
    return `<div class="bal-card" style="animation-delay:${i*.05}s;${cardStyle}">${lockBanner}<div class="bc-top"><div><div class="bc-title">${isLocked?'🔒':icon} ${b.type_name}</div><div class="bc-sub">CY <?= date('Y') ?> Credits</div></div><div class="bc-icon" style="background:${c}22;display:flex;align-items:center;justify-content:center;">${fileButton || icon}</div></div><div class="bc-nums"><div class="bc-nb"><div class="bc-n" style="color:var(--primary);">${b.total_credits}</div><div class="bc-nl">Total</div></div><div class="bc-nb"><div class="bc-n" style="color:var(--warn);">${b.used_days}</div><div class="bc-nl">Used</div></div><div class="bc-nb"><div class="bc-n" style="color:var(--accent);">${b.remaining_days}</div><div class="bc-nl">Left</div></div></div><div class="bc-bar"><div class="bc-fill" style="width:${pct}%;background:${c};"></div></div>${pendingAvailBanner}<div class="hist"><div class="hist-label">Recent Usage</div>${histHtml}</div></div>`;
  }).join('');
  
  grid.innerHTML = cardsHtml;
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

// ── Navigate to file leave and pre-select a leave type ────
function fileLeaveForType(typeName, typeId) {
  // Navigate to file leave page
  const btn = document.querySelector('.sb-item[onclick*="\'file\'"]');
  goPage('file', btn);
  
  // Find and click the corresponding leave type card
  setTimeout(() => {
    const cards = document.querySelectorAll('.lt-opt');
    for (let card of cards) {
      if (parseInt(card.dataset.id) === typeId) {
        // Remove previous selection
        document.querySelectorAll('.lt-opt').forEach(c => c.classList.remove('sel'));
        // Add selection to this card
        card.classList.add('sel');
        // Update global state
        pickLT(card);
        // Scroll to the selected card
        card.scrollIntoView({ behavior: 'smooth', block: 'center' });
        break;
      }
    }
  }, 200);
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
    html += `<div style="grid-column:1/-1;font-size:.72rem;font-weight:700;color:var(--g400);letter-spacing:.04em;padding:8px 2px 2px;border-top:1px dashed var(--g200);margin-top:4px;">⛔ FULLY CONSUMED — No remaining days (You may still file as WITHOUT PAY)</div>`;
    consumed.forEach(lt => {
      const icon = TYPE_ICONS[lt.name] || '📅';
      html += `<div class="lt-opt lt-opt-consumed" data-id="${lt.leave_type_id}" data-name="${lt.name}" data-bal="0" onclick="pickLT(this)" title="No remaining days for ${lt.name} (will be filed as WITHOUT PAY)">
        <div class="lt-icon" style="font-size:1.1rem;color:#ef4444;">⛔</div>
        <div class="lt-name" style="color:#ef4444;">${lt.name}</div>
        <div class="lt-avail" style="color:#ef4444;font-size:.68rem;font-weight:700;">0 days remaining (Without Pay)</div>
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
const RECENT_REQUESTS_PER_PAGE = 10;
let recentRequestsPage = 1;

async function loadRecentRequests(page = recentRequestsPage) {
  recentRequestsPage = Math.max(1, page);
  try {
    const params = new URLSearchParams({
      action: 'requests',
      status: 'all',
      page: recentRequestsPage,
      per_page: RECENT_REQUESTS_PER_PAGE
    });
    const res  = await fetch(`Employee_portal.php?${params}`);
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

    const totalPages = Math.max(1, Math.ceil((json.total || 0) / RECENT_REQUESTS_PER_PAGE));
    if (recentRequestsPage > totalPages) {
      return loadRecentRequests(totalPages);
    }

    const recent = json.data || [];
    const pagination = document.getElementById('recentReqPagination');
    if(!recent.length){
      tbody.innerHTML='<tr><td colspan="4" style="text-align:center;padding:24px;color:var(--g400);">No requests yet.</td></tr>';
      if (pagination) pagination.hidden = true;
      return;
    }
    tbody.innerHTML=recent.map(r=>`<tr onclick="goPage('requests',document.querySelectorAll('.sb-item')[2])"><td><span class="badge" style="background:${(TYPE_COLORS[r.type_name]||'#2563eb')}22;color:${TYPE_COLORS[r.type_name]||'#2563eb'};">${r.type_name}</span></td><td style="font-size:.77rem;color:var(--g600);">${fmt(r.date_from)}${r.date_from!==r.date_to?' – '+fmt(r.date_to):''}</td><td style="font-weight:800;">${r.days_requested}</td><td><span class="badge ${ST_CLS[r.status]||'b-pending'}">${ST_ICO[r.status]||''} ${r.status}</span></td></tr>`).join('');

    if (pagination) {
      const pageInfo = document.getElementById('recentReqPageInfo');
      const pageButtons = document.getElementById('recentReqPageButtons');
      const from = (recentRequestsPage - 1) * RECENT_REQUESTS_PER_PAGE + 1;
      const to = from + recent.length - 1;
      pageInfo.textContent = `Showing ${from}–${to} of ${json.total} requests`;
      pageButtons.innerHTML = '';

      const addPageButton = (label, targetPage, disabled = false, active = false) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `recent-req-page-btn${active ? ' active' : ''}`;
        button.textContent = label;
        button.disabled = disabled;
        button.setAttribute('aria-label', label === '‹' ? 'Previous page' : label === '›' ? 'Next page' : `Page ${label}`);
        button.onclick = () => loadRecentRequests(targetPage);
        pageButtons.appendChild(button);
      };

      addPageButton('‹', recentRequestsPage - 1, recentRequestsPage === 1);
      const firstPage = Math.max(1, recentRequestsPage - 2);
      const lastPage = Math.min(totalPages, recentRequestsPage + 2);
      if (firstPage > 1) addPageButton('1', 1);
      if (firstPage > 2) addPageButton('…', 0, true);
      for (let pageNumber = firstPage; pageNumber <= lastPage; pageNumber++) {
        addPageButton(String(pageNumber), pageNumber, false, pageNumber === recentRequestsPage);
      }
      if (lastPage < totalPages - 1) addPageButton('…', 0, true);
      if (lastPage < totalPages) addPageButton(String(totalPages), totalPages);
      addPageButton('›', recentRequestsPage + 1, recentRequestsPage === totalPages);
      pagination.hidden = totalPages <= 1;
    }
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
function renderRequestCards(rows) {
  return rows.map(r=>{
    const remarkCls=r.status==='Approved'?'approved':r.status==='Denied'?'denied':'pending';
    const remarkHtml=r.admin_remarks?`<div class="req-remark ${remarkCls}">💬 HR NOTE:  ${r.admin_remarks}</div>`:'';
    const pendingLike = ['Pending', 'Pending Dept Head', 'Pending HR'];
    const cancelHtml = pendingLike.includes(r.status) ? `<button class="cancel-btn" onclick="cancelReq(${r.request_id})">Cancel</button>` : '';
    const color=TYPE_COLORS[r.type_name]||'#2563eb';
    return `<div class="req-card"><div class="req-stripe" style="background:${color};"></div><div class="req-body"><div class="req-top"><div class="req-title"><span class="badge" style="background:${color}22;color:${color};">${r.type_name} Leave</span></div><span class="badge ${ST_CLS[r.status]||'b-pending'}">${ST_ICO[r.status]||''} ${r.status}</span></div><div class="req-meta"><span>📅 ${fmt(r.date_from)} – ${fmt(r.date_to)}</span><span>📆 <strong>${r.days_requested} day${r.days_requested!=1?'s':''}</strong></span></div><div class="req-reason">Reason of Leave: ${r.reason}</div>${remarkHtml}<div class="req-footer"><span class="req-filed">Filed: ${fmt(r.filed_at)}</span>${cancelHtml}</div></div></div>`;
  }).join('');
}

function populateRequestTypeFilter(types) {
  const select = document.getElementById('reqTypeFilter');
  if (!select || select.dataset.loaded === 'true') return;
  types.forEach(type => {
    const option = document.createElement('option');
    option.value = type.leave_type_id;
    option.textContent = type.name;
    select.appendChild(option);
  });
  select.dataset.loaded = 'true';
}

function updateRequestDateInput() {
  const mode = document.getElementById('reqDateMode').value;
  const field = document.getElementById('reqDateValueField');
  const label = document.getElementById('reqDateValueLabel');
  const input = document.getElementById('reqDateValue');
  const configurations = {
    date: { type: 'date', label: 'Specific date' },
    week: { type: 'week', label: 'Week' },
    month: { type: 'month', label: 'Month' },
    year: { type: 'number', label: 'Year' }
  };
  const configuration = configurations[mode];
  field.hidden = !configuration;
  input.value = '';
  if (configuration) {
    input.type = configuration.type;
    input.placeholder = configuration.type === 'number' ? 'YYYY' : '';
    input.min = configuration.type === 'number' ? '1900' : '';
    input.max = configuration.type === 'number' ? '9999' : '';
    input.step = configuration.type === 'number' ? '1' : '';
    label.textContent = configuration.label;
  }
  applyRequestFilters();
}

function applyRequestFilters() {
  requestPage = 1;
  loadRequests();
}

function clearRequestFilters() {
  document.getElementById('reqStatusFilter').value = 'all';
  document.getElementById('reqTypeFilter').value = '';
  document.getElementById('reqDateMode').value = '';
  document.getElementById('reqDateValue').value = '';
  document.getElementById('reqDateValueField').hidden = true;
  curReqTab = 'all';
  applyRequestFilters();
}

function updateRequestStatusFilter(counts) {
  const select = document.getElementById('reqStatusFilter');
  if (!select) return;
  const total = (counts.Pending || 0) + (counts.Approved || 0) + (counts.Denied || 0) + (counts.Cancelled || 0);
  const labels = {
    all: 'All',
    pending: 'Pending',
    approved: 'Approved',
    denied: 'Denied',
    cancelled: 'Cancelled'
  };
  const values = {
    all: total,
    pending: counts.Pending || 0,
    approved: counts.Approved || 0,
    denied: counts.Denied || 0,
    cancelled: counts.Cancelled || 0
  };
  Object.keys(labels).forEach(status => {
    const option = select.querySelector(`option[value="${status}"]`);
    if (option) option.textContent = `${labels[status]} (${values[status]})`;
  });
}

function filterRequestStatus() {
  curReqTab = document.getElementById('reqStatusFilter').value;
  requestPage = 1;
  loadRequests();
}

function renderRequestPagination(total) {
  const pagination = document.getElementById('reqPagination');
  const pages = Math.max(1, Math.ceil(total / REQUESTS_PER_PAGE));
  if (!pagination) return pages;

  const info = document.getElementById('reqPageInfo');
  const buttons = document.getElementById('reqPageButtons');
  const from = total ? (requestPage - 1) * REQUESTS_PER_PAGE + 1 : 0;
  const to = Math.min(requestPage * REQUESTS_PER_PAGE, total);
  info.textContent = `Showing ${from}–${to} of ${total} request${total !== 1 ? 's' : ''}`;
  buttons.innerHTML = '';

  const addButton = (label, targetPage, disabled = false, active = false) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `req-page-btn${active ? ' active' : ''}`;
    button.textContent = label;
    button.disabled = disabled;
    button.setAttribute('aria-label', label === '‹' ? 'Previous page' : label === '›' ? 'Next page' : `Page ${label}`);
    button.onclick = () => { requestPage = targetPage; loadRequests(); };
    buttons.appendChild(button);
  };

  addButton('‹', requestPage - 1, requestPage === 1);
  const firstPage = Math.max(1, requestPage - 2);
  const lastPage = Math.min(pages, requestPage + 2);
  if (firstPage > 1) addButton('1', 1);
  if (firstPage > 2) addButton('…', 0, true);
  for (let pageNumber = firstPage; pageNumber <= lastPage; pageNumber++) {
    addButton(String(pageNumber), pageNumber, false, pageNumber === requestPage);
  }
  if (lastPage < pages - 1) addButton('…', 0, true);
  if (lastPage < pages) addButton(String(pages), pages);
  addButton('›', requestPage + 1, requestPage === pages);
  pagination.hidden = pages <= 1;
  return pages;
}

async function loadRequests() {
  try {
    const params = new URLSearchParams({
      action: 'requests',
      status: curReqTab,
      page: requestPage,
      per_page: REQUESTS_PER_PAGE
    });
    const typeId = document.getElementById('reqTypeFilter').value;
    const dateMode = document.getElementById('reqDateMode').value;
    const dateValue = document.getElementById('reqDateValue').value;
    if (typeId) params.set('leave_type_id', typeId);
    if (dateMode && dateValue) {
      params.set('date_mode', dateMode);
      params.set('date_value', dateValue);
    }
    const res  = await fetch(`Employee_portal.php?${params}`);
    const json = await res.json();
    if (!json.success) {
      showToast('⚠️ ' + (json.message || 'Could not load requests.'), 'error');
      const list=document.getElementById('reqList'); const empty=document.getElementById('reqEmpty');
      list.innerHTML='';
      empty.style.display='block';
      empty.innerHTML='<div class="ei">⚠️</div><p>Could not load requests: ' + (json.message || 'Unknown error') + '</p>';
      return;
    }
    populateRequestTypeFilter(json.type_options || []);
    updateRequestStatusFilter(json.counts);
    const pending=json.counts.Pending||0;
    const badge=document.getElementById('pendBadge'); badge.textContent=pending; badge.style.display=pending?'inline-block':'none';
    const list=document.getElementById('reqList'); const empty=document.getElementById('reqEmpty');
    const pages = Math.max(1, Math.ceil((json.total || 0) / REQUESTS_PER_PAGE));
    if (requestPage > pages) {
      requestPage = pages;
      return loadRequests();
    }
    renderRequestPagination(json.total || 0);
    if(!json.data.length){list.innerHTML='';empty.style.display='block';return;}
    empty.style.display='none';
    list.innerHTML=renderRequestCards(json.data);
  } catch(e) {
    showToast('❌ Could not load requests. Network error.', 'error');
    const list=document.getElementById('reqList'); const empty=document.getElementById('reqEmpty');
    list.innerHTML='';
    empty.style.display='block';
    empty.innerHTML='<div class="ei">❌</div><p>Could not load requests. Please check your connection and try again.</p>';
    console.error('loadRequests error:', e);
  }
}

async function cancelReq(id) {
  if(!confirm('Cancel this leave request?')) return;
  const body=new FormData(); body.append('action','cancel_leave'); body.append('request_id',id); body.append('csrf_token',CSRF_TOKEN);
  const res=await fetch('Employee_portal.php',{method:'POST',body});
  const json=await res.json();
  if(json.success){showToast('Leave request cancelled.','info');loadRequests();loadRecentRequests();}
  else showToast('❌ '+json.message,'error');
}

// ════════════════════════════════════════════════════════
//  COE REQUESTS PAGE
// ════════════════════════════════════════════════════════
function filterCoeReqs(tab, btn) {
  curCoeTab = tab;
  document.querySelectorAll('#page-coe .req-tab').forEach(b => b.classList.remove('active'));
  if (btn) btn.classList.add('active');
  loadCoeRequests();
}

function openCoeModal(id = null, purpose = '') {
  const isEdit = id !== null && id !== undefined;
  document.getElementById('coeEditId').value = isEdit ? String(id) : '';
  document.getElementById('coePurpose').value = purpose || '';
  document.getElementById('coeModalTitle').textContent = isEdit ? '✏️ Edit COE Request' : '📄 New COE Request';
  document.getElementById('btnSaveCoe').textContent = isEdit ? '💾 Save Changes' : '📨 Submit Request';
  openModal('modalCoeRequest');
}

async function submitCoeRequest() {
  const coeId = (document.getElementById('coeEditId').value || '').trim();
  const purpose = (document.getElementById('coePurpose').value || '').trim();
  if (!purpose) {
    showToast('❌ Purpose is required.', 'error');
    return;
  }

  const btn = document.getElementById('btnSaveCoe');
  const oldText = btn.textContent;
  btn.disabled = true;
  btn.textContent = coeId ? '⏳ Saving…' : '⏳ Submitting…';
  try {
    const body = new FormData();
    body.append('action', coeId ? 'edit_coe_request' : 'submit_coe_request');
    body.append('purpose', purpose);
    body.append('csrf_token', CSRF_TOKEN);
    if (coeId) body.append('coe_request_id', coeId);

    const res = await fetch('Employee_portal.php', { method: 'POST', body });
    const json = await res.json();
    if (!json.success) {
      showToast('❌ ' + (json.message || 'Unable to save request.'), 'error');
      return;
    }
    showToast(coeId ? 'COE request updated.' : 'COE request submitted.', 'success');
    closeModal('modalCoeRequest');
    loadCoeRequests();
  } catch (e) {
    showToast('❌ Failed to save COE request.', 'error');
  } finally {
    btn.disabled = false;
    btn.textContent = oldText;
  }
}

async function cancelCoeRequest(id) {
  if (!confirm('Cancel this COE request?')) return;
  try {
    const body = new FormData();
    body.append('action', 'cancel_coe_request');
    body.append('coe_request_id', String(id));
    body.append('csrf_token', CSRF_TOKEN);
    const res = await fetch('Employee_portal.php', { method: 'POST', body });
    const json = await res.json();
    if (!json.success) {
      showToast('❌ ' + (json.message || 'Unable to cancel request.'), 'error');
      return;
    }
    showToast('COE request cancelled.', 'info');
    loadCoeRequests();
  } catch (e) {
    showToast('❌ Failed to cancel COE request.', 'error');
  }
}

async function loadCoeRequests() {
  const list = document.getElementById('coeList');
  const empty = document.getElementById('coeEmpty');
  if (!list || !empty) return;

  list.innerHTML = '<div class="card" style="padding:18px;color:var(--g400);font-size:.82rem;">Loading COE requests…</div>';
  empty.style.display = 'none';

  try {
    const res = await fetch(`Employee_portal.php?action=coe_requests&status=${encodeURIComponent(curCoeTab)}`);
    const json = await res.json();
    if (!json.success) {
      list.innerHTML = '';
      empty.style.display = 'block';
      empty.innerHTML = '<div class="ei">⚠️</div><p>' + (json.message || 'Could not load COE requests.') + '</p>';
      return;
    }

    const counts = json.counts || {};
    const total = (counts.Pending || 0) + (counts.Approved || 0) + (counts.Denied || 0) + (counts.Cancelled || 0);
    document.getElementById('coe-tc-all').textContent = total;
    document.getElementById('coe-tc-pending').textContent = counts.Pending || 0;
    document.getElementById('coe-tc-approved').textContent = counts.Approved || 0;
    document.getElementById('coe-tc-denied').textContent = counts.Denied || 0;
    document.getElementById('coe-tc-cancelled').textContent = counts.Cancelled || 0;

    const esc = v => String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    const rows = json.data || [];
    if (!rows.length) {
      list.innerHTML = '';
      empty.style.display = 'block';
      return;
    }

    list.innerHTML = rows.map(r => {
      const isPending = r.status === 'Pending';
      const actionHtml = isPending
        ? `<div style="display:flex;gap:8px;flex-wrap:wrap;">
             <button class="cancel-btn" onclick="openCoeModal(${r.coe_request_id}, ${JSON.stringify(r.purpose || '')})">Edit</button>
             <button class="cancel-btn" onclick="cancelCoeRequest(${r.coe_request_id})">Cancel</button>
           </div>`
        : '<span style="font-size:.70rem;color:var(--g400);font-weight:700;">No actions</span>';

      const remarkHtml = r.admin_remarks
        ? `<div class="req-remark ${r.status === 'Denied' ? 'denied' : (r.status === 'Approved' ? 'approved' : 'pending')}">💬 HR NOTE: ${esc(r.admin_remarks)}</div>`
        : '';

      return `<div class="req-card">
        <div class="req-stripe" style="background:#0f766e;"></div>
        <div class="req-body">
          <div class="req-top">
            <div class="req-title"><span class="badge" style="background:#0f766e22;color:#0f766e;">Certificate of Employment</span></div>
            <span class="badge ${ST_CLS[r.status] || 'b-pending'}">${ST_ICO[r.status] || ''} ${esc(r.status)}</span>
          </div>
          <div class="req-reason">Purpose: ${esc(r.purpose)}</div>
          ${remarkHtml}
          <div class="req-footer">
            <span class="req-filed">Filed: ${fmt(r.filed_at)}</span>
            ${actionHtml}
          </div>
        </div>
      </div>`;
    }).join('');
  } catch (e) {
    list.innerHTML = '';
    empty.style.display = 'block';
    empty.innerHTML = '<div class="ei">❌</div><p>Could not load COE requests right now.</p>';
  }
}

// ════════════════════════════════════════════════════════
//  FILE LEAVE
// ════════════════════════════════════════════════════════
function pickLT(el){document.querySelectorAll('.lt-opt').forEach(c=>c.classList.remove('sel'));el.classList.add('sel');selTypeId=parseInt(el.dataset.id);selTypeName=el.dataset.name;selTypeBal=parseInt(el.dataset.bal);document.getElementById('daysBal').textContent=`${selTypeBal} days available for ${selTypeName} Leave`;calcDays();updateDetailSection(selTypeName);}
let leaveHolidays = new Map();
let leaveConflictDates = new Set();
const leavePicker = { from: null, to: null, view: new Date(new Date().getFullYear(), new Date().getMonth(), 1), target: 'from' };

function toLocalDate(dateString) { return new Date(dateString + 'T00:00:00'); }
function dateKey(date) { return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0'); }
function sameDate(left, right) { return left && right && dateKey(left) === dateKey(right); }
function isLeaveHoliday(date) { return leaveHolidays.has(dateKey(date)) || leaveHolidays.has('R-' + dateKey(date).slice(5)); }
function holidayName(date) { return leaveHolidays.get(dateKey(date)) || leaveHolidays.get('R-' + dateKey(date).slice(5)) || ''; }
function isLeaveConflictDate(date) { return leaveConflictDates.has(dateKey(date)); }
function escapeHolidayTooltip(value) { return String(value || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
function isUnavailableLeaveDate(date) { return date.getDay() === 0 || date.getDay() === 6 || isLeaveHoliday(date) || isLeaveConflictDate(date); }
function formatLeaveDisplay(date) { return date ? String(date.getMonth() + 1).padStart(2, '0') + '/' + String(date.getDate()).padStart(2, '0') + '/' + date.getFullYear() : ''; }

async function loadExistingLeaveDates() {
  try {
    const response = await fetch('Employee_portal.php?action=requests&status=all');
    const json = await response.json();
    if (!json || !json.success || !Array.isArray(json.data)) return;

    const activeStatuses = ['Pending', 'Pending Dept Head', 'Pending HR', 'Approved'];
    leaveConflictDates = new Set();
    json.data.forEach((row) => {
      if (!activeStatuses.includes(String(row.status || ''))) return;
      const start = row.date_from ? new Date(row.date_from + 'T00:00:00') : null;
      const end = row.date_to ? new Date(row.date_to + 'T00:00:00') : null;
      if (!start || !end || end < start) return;
      const cursor = new Date(start);
      while (cursor <= end) {
        leaveConflictDates.add(dateKey(cursor));
        cursor.setDate(cursor.getDate() + 1);
      }
    });
    renderLeaveDatePicker();
  } catch (error) {
    console.warn('Could not load existing leave dates for the calendar.', error);
  }
}

function syncLeavePickerInputs() {
  document.getElementById('flFrom').value = leavePicker.from ? dateKey(leavePicker.from) : '';
  document.getElementById('flTo').value = leavePicker.to ? dateKey(leavePicker.to) : '';
  document.getElementById('flFromDisplay').value = formatLeaveDisplay(leavePicker.from);
  document.getElementById('flToDisplay').value = formatLeaveDisplay(leavePicker.to);
}

function renderLeaveDatePicker() {
  const picker = document.getElementById('leaveDatePicker');
  if (!picker) return;
  const weekdays = ['S','M','T','W','T','F','S'];
  const month = new Date(leavePicker.view.getFullYear(), leavePicker.view.getMonth(), 1);
  const blankDays = new Date(month.getFullYear(), month.getMonth(), 1).getDay();
  const totalDays = new Date(month.getFullYear(), month.getMonth() + 1, 0).getDate();
  let days = Array(blankDays).fill('<span class="leave-picker-day empty"></span>');
  for (let day = 1; day <= totalDays; day++) {
    const date = new Date(month.getFullYear(), month.getMonth(), day);
    const weekend = date.getDay() === 0 || date.getDay() === 6;
    const holiday = isLeaveHoliday(date);
    const conflict = isLeaveConflictDate(date);
    const classes = ['leave-picker-day'];
    if (weekend) classes.push('weekend');
    if (holiday) classes.push('holiday');
    if (conflict) classes.push('conflict');
    if (leavePicker.from && leavePicker.to && date >= leavePicker.from && date <= leavePicker.to) classes.push('in-range');
    if (sameDate(date, leavePicker.from)) classes.push('start-date');
    if (sameDate(date, leavePicker.to)) classes.push('end-date');
    const note = conflict ? 'Already filed for leave' : (holiday ? holidayName(date) : (weekend ? 'Weekend - not chargeable to leave credits' : 'Select ' + formatLeaveDisplay(date)));
    const unavailable = weekend || holiday || conflict;
    const ariaDate = formatLeaveDisplay(date) + (conflict ? ': already filed for leave' : holiday ? ': ' + note : weekend ? ': weekend' : '');
    days.push(`<button type="button" class="${classes.join(' ')}" data-date="${dateKey(date)}" title="${escapeHolidayTooltip(note)}" aria-label="${escapeHolidayTooltip(ariaDate)}" ${unavailable ? 'disabled' : ''}>${day}</button>`);
  }
  const monthHtml = `<div class="leave-picker-month"><div class="leave-picker-month-title">${month.toLocaleDateString('en-PH',{month:'long',year:'numeric'})}</div><div class="leave-picker-weekdays">${weekdays.map(day => `<span>${day}</span>`).join('')}</div><div class="leave-picker-days">${days.join('')}</div></div>`;
  picker.innerHTML = `<div class="leave-date-picker-head"><div class="leave-date-picker-title">Choose leave dates</div><div class="leave-date-picker-nav"><button type="button" data-leave-nav="prev" aria-label="Previous month" title="Previous month">‹</button><button type="button" data-leave-nav="next" aria-label="Next month" title="Next month">›</button></div></div><div class="leave-date-picker-grid">${monthHtml}</div><div class="leave-date-picker-foot"><div class="leave-date-picker-key"><span><i></i> Holiday</span><span><i class="weekend"></i> Weekend</span></div><button type="button" class="leave-date-picker-clear" id="leavePickerClear">Clear</button></div>`;
  picker.querySelectorAll('[data-date]').forEach(button => button.addEventListener('click', () => selectLeavePickerDate(toLocalDate(button.dataset.date))));
  picker.querySelectorAll('[data-leave-nav]').forEach(button => button.addEventListener('click', () => { leavePicker.view.setMonth(leavePicker.view.getMonth() + (button.dataset.leaveNav === 'prev' ? -1 : 1)); renderLeaveDatePicker(); }));
  const clearBtn = document.getElementById('leavePickerClear');
  if (clearBtn) clearBtn.addEventListener('click', clearLeavePicker);
}

function selectLeavePickerDate(date) {
  if (isLeaveConflictDate(date)) {
    showToast('⚠️ This date is already covered by an existing leave request.', 'info');
    return;
  }
  if (!leavePicker.from || leavePicker.to) { leavePicker.from = date; leavePicker.to = null; leavePicker.target = 'to'; }
  else if (date < leavePicker.from) { leavePicker.to = leavePicker.from; leavePicker.from = date; }
  else { leavePicker.to = date; }
  syncLeavePickerInputs(); calcDays(); renderLeaveDatePicker();
}

function clearLeavePicker() {
  leavePicker.from = null;
  leavePicker.to = null;
  leavePicker.target = 'from';
  leavePicker.view = new Date(new Date().getFullYear(), new Date().getMonth(), 1);

  ['flFrom','flTo','flFromDisplay','flToDisplay'].forEach((id) => {
    const field = document.getElementById(id);
    if (!field) return;
    field.value = '';
    field.classList.remove('err');
  });

  const daysEl = document.getElementById('daysNum');
  if (daysEl) daysEl.textContent = '0';

  if (typeof calcDays === 'function') calcDays();
  renderCalPreview(null, null, 0);
  renderLeaveDatePicker();
}
function parseLeaveDisplay(value) { const match = String(value).trim().match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/); if (!match) return null; const date = new Date(Number(match[3]), Number(match[1]) - 1, Number(match[2])); return date.getFullYear() === Number(match[3]) && date.getMonth() === Number(match[1]) - 1 && date.getDate() === Number(match[2]) ? date : null; }
function updateLeavePickerFromDisplay(target) { const input = document.getElementById(target === 'from' ? 'flFromDisplay' : 'flToDisplay'); const date = parseLeaveDisplay(input.value); input.classList.toggle('err', input.value !== '' && (!date || isUnavailableLeaveDate(date))); if (!date || isUnavailableLeaveDate(date)) return; if (target === 'from') leavePicker.from = date; else leavePicker.to = date; if (leavePicker.from && leavePicker.to && leavePicker.to < leavePicker.from) [leavePicker.from, leavePicker.to] = [leavePicker.to, leavePicker.from]; leavePicker.view = new Date((leavePicker.from || date).getFullYear(), (leavePicker.from || date).getMonth(), 1); syncLeavePickerInputs(); calcDays(); renderLeaveDatePicker(); }

function openLeaveDatePicker(target) { leavePicker.target = target; const picker = document.getElementById('leaveDatePicker'); picker.hidden = false; const date = target === 'from' ? leavePicker.from : leavePicker.to; if (date) leavePicker.view = new Date(date.getFullYear(), date.getMonth(), 1); renderLeaveDatePicker(); }

function initLeaveDatePicker() { document.querySelectorAll('.leave-date-trigger').forEach(button => button.addEventListener('click', () => openLeaveDatePicker(button.dataset.pickerTarget))); ['from','to'].forEach(target => document.getElementById(target === 'from' ? 'flFromDisplay' : 'flToDisplay').addEventListener('change', () => updateLeavePickerFromDisplay(target))); renderLeaveDatePicker(); }

async function loadLeaveHolidays() {
  try {
    const response = await fetch('Employee_portal.php?action=holiday_calendar');
    const json = await response.json();
    if (!json.success) throw new Error('Holiday calendar unavailable');
    leaveHolidays = new Map();
    json.data.forEach(holiday => {
      if (!holiday.holiday_date) return;
      leaveHolidays.set(holiday.holiday_date, holiday.description);
      leaveHolidays.set('R-' + holiday.holiday_date.slice(5), holiday.description);
    });
    renderLeaveDatePicker();
  } catch (error) {
    showToast('Holiday markings are unavailable. Leave totals will still be verified when submitted.', 'info');
  }
}

function calcDays() {
  var f = document.getElementById('flFrom').value;
  var t = document.getElementById('flTo').value;
  if (f && t) {
    // Count only weekdays (Mon–Fri); weekends are excluded from leave day counts
    var start = toLocalDate(f);
    var end   = toLocalDate(t);
    var days  = 0;
    var cur   = new Date(start);
    while (cur <= end) {
      var dow = cur.getDay(); // 0=Sun, 6=Sat
      if (dow !== 0 && dow !== 6 && !isLeaveHoliday(cur)) days++;
      cur.setDate(cur.getDate() + 1);
    }
    days = Math.max(0, days);
    document.getElementById('daysNum').textContent = days;
    renderCalPreview(f, t, days);
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
    var isHoliday = isLeaveHoliday(cur);
    if (!isWeekend && !isHoliday) weekdayCount++;
    days.push({ d: new Date(cur), weekend: isWeekend, holiday: isHoliday, isToday: cur.getTime() === today.getTime() });
    cur.setDate(cur.getDate() + 1);
    if (days.length > 28) { days.push(null); break; } // cap display
  }
  var opts  = { month: 'short', day: 'numeric' };
  var range = start.toLocaleDateString('en-PH', opts) + ' → ' + end.toLocaleDateString('en-PH', opts);
  var strip = days.map(function(d) {
    if (!d) return '<div style="font-size:.65rem;color:var(--g400);">+more</div>';
    var cls = d.holiday ? 'holiday' : (d.weekend ? 'weekend' : 'in-range');
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
  initLeaveDatePicker();
  loadLeaveHolidays();
  loadExistingLeaveDates();
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
  var p = window._profileData || {};
  if (!p.signature_file_path) {
    showToast('⚠️ Please upload your e-signature in My Profile before filing leave.', 'info');
    goPage('profile', document.querySelectorAll('.sb-item')[1]);
    return;
  }

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
  
  // Past-date filing is allowed for sick leave only.
  const today = new Date();
  today.setHours(0,0,0,0);
  const fromDate = new Date(from);
  fromDate.setHours(0,0,0,0);
  const selectedTypeName = ((selTypeName || '') + '').toLowerCase();
  const isSickLeave = selectedTypeName.indexOf('sick') >= 0;
  if (!isSickLeave && fromDate < today) {
    showToast('🚫 Cannot apply for leave on past dates. Please select today or a future date.','error');
    document.getElementById('flFrom').classList.add('err');
    return;
  }
  
  // Allow filing as WITHOUT PAY if balance is 0 (fully consumed)
  if(days>selTypeBal && selTypeBal > 0){
    showToast(`⚠️ ${days} days exceeds your ${selTypeBal}-day ${selTypeName} balance.`,'error');
    return;
  }
  if(!selTypeId){showToast('⚠️ Please select a leave type.','info');return;}
  // Check if selected type is disabled (marriage-restricted)
  const selType = leaveTypes.find(lt => lt.leave_type_id == selTypeId);
  if(selType && selType.disabled_reason){showToast('🔒 ' + selType.disabled_reason,'error');return;}
  // Do not block fully consumed leave types; allow filing as WITHOUT PAY
  // Combine detail + reason
  var detail = getLeaveDetail();
  var fullReason = detail ? '[' + detail + '] ' + reason : reason;
  const body=new FormData();body.append('action','submit_leave');body.append('csrf_token',CSRF_TOKEN);body.append('leave_type_id',selTypeId);body.append('date_from',from);body.append('date_to',to);body.append('days_requested',days);body.append('reason',fullReason);
  try {
    const res=await fetch('Employee_portal.php',{method:'POST',body});const json=await res.json();
    if(json.success){showToast('✅ Leave request submitted!','success');clearLeavePicker();document.getElementById('flReason').value='';document.getElementById('others_leave_spec').value='';document.getElementById('daysNum').textContent='0';['flFrom','flTo','flFromDisplay','flToDisplay','flReason','others_leave_spec'].forEach(id=>document.getElementById(id).classList.remove('err'));updateDetailSection('');await loadBalances();await loadLeaveTypes();await loadRecentRequests();await loadExistingLeaveDates();setTimeout(()=>goPage('requests',document.querySelectorAll('.sb-item')[2]),900);}
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
  body.append('csrf_token',    CSRF_TOKEN);

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
  const body=new FormData(); body.append('action','delete_document'); body.append('document_id',docDeleteTarget); body.append('csrf_token',CSRF_TOKEN);
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
  if (!isDefault) {
    var inputs = list.querySelectorAll('.oi-inp');
    if (inputs.length) inputs[inputs.length - 1].focus();
  }
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
  try {
    const r = await fetch('Employee_portal.php?action=get_pds');
    const j = await r.json();
    if (!j.success || !j.data) { 
      showToast('❌ No saved PDS found. Please save your PDS first.', 'error'); 
      return; 
    }
    // Pass employee data if available
    const emp = j.employee || null;
    buildAndPrintPDS(j.data, emp);
  } catch(e) {
    showToast('❌ Error loading PDS: ' + e.message, 'error');
  }
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
  // Print from currently displayed form data (for unsaved changes)
  fetchAndPrintPDS('form');
}

// Helper to print either from form data or saved database data
async function fetchAndPrintPDS(source = 'saved') {
  try {
    let html;
    if (source === 'form') {
      // Collect from current form
      const d = collectPDSFormData();
      let savedRec = null;
      try {
        const sr = await fetch('Employee_portal.php?action=get_pds');
        savedRec = await sr.json();
      } catch(e) {}
      if (savedRec && savedRec.data) {
        d.updated_at = savedRec.data.updated_at;
        d.created_at = savedRec.data.created_at;
      }
      html = buildPDSPrintHTML(d, await withScannedSignature((savedRec && savedRec.employee) || null));
      if (!html || html.length < 100) {
        showToast('❌ Error generating PDS: No HTML output', 'error');
        return;
      }
    } else {
      // Fetch from database
      const r = await fetch('Employee_portal.php?action=get_pds');
      const j = await r.json();
      if (!j.success || !j.data) { 
        showToast('❌ No saved PDS found.', 'error'); 
        return; 
      }
      html = buildPDSPrintHTML(j.data, j.employee || null);
      if (!html || html.length < 100) {
        showToast('❌ Error generating PDS from saved data', 'error');
        return;
      }
    }
    const w = window.open('', '_blank', 'width=960,height=1100');
    w.document.write(html);
    w.document.close();
    w.focus();
    setTimeout(() => w.print(), 600);
  } catch(e) {
    showToast('❌ Print error: ' + e.message, 'error');
    console.error('PDS Print Error:', e);
  }
}

// Collect all PDS form data into structured object for printing
function collectPDSFormData() {
  try {
    // Collect education, civil service, work experience, voluntary work, trainings
    const edu = collectRows('pds_edu_list', ['level','school','degree','honors']) || [];
    const civ = collectRows('pds_cs_list', ['eligibility','rating','exam_date','exam_place','license_no','license_validity']) || [];
    const we  = collectRows('pds_we_list', ['date_from','date_to','position','dept_agency','salary_grade','status','govt_service','monthly_salary']) || [];
    const vw  = collectRows('pds_vw_list', ['org_name','date_from','date_to','no_hours','position']) || [];
    const tr  = collectRows('pds_tr_list', ['title','date_from','date_to','no_hours','ld_type','conducted_by']) || [];
    
    // Collect other information lists
    const skills = oiGetList('pds_skills_list') || [];
    const disting = oiGetList('pds_distinctions_list') || [];
    const membr = oiGetList('pds_memberships_list') || [];
    
    // Collect Q34-Q40 (integrity questions)
    const q4 = collectRows('pds_q_list', ['question','answer','details']) || [];
    
    // Collect references
    const refs = collectRows('pds_ref_list', ['name','address','tel']) || [];
    
    // Build data object for print
    const d = {
      personal_info: JSON.stringify({
        last_name:pdsGet('pds_last_name'), 
        first_name:pdsGet('pds_first_name'),
        middle_name:pdsGet('pds_middle_name'), 
        suffix:pdsGet('pds_suffix'),
        dob:pdsGet('pds_dob'), 
        pob:pdsGet('pds_pob'),
        sex:pdsGet('pds_sex'), 
        civil_status:pdsGet('pds_civil_status'),
        height:pdsGet('pds_height'), 
        weight:pdsGet('pds_weight'), 
        blood_type:pdsGet('pds_blood_type'),
        umid:pdsGet('pds_umid'), 
        pagibig:pdsGet('pds_pagibig'), 
        philhealth:pdsGet('pds_philhealth'),
        psn:pdsGet('pds_psn'), 
        tin:pdsGet('pds_tin'), 
        agency_no:pdsGet('pds_agency_no'),
        citizenship: pdsRadioGet('pds_citizenship') === 'Dual' ? 'Dual Citizenship' : 'Filipino',
        dual_by:pdsGet('pds_dual_by'), 
        dual_country:pdsGet('pds_dual_country'),
        res_housenum:pdsGet('pds_res_housenum'), 
        res_street:pdsGet('pds_res_street'),
        res_subdivision:pdsGet('pds_res_subdivision'), 
        res_barangay:pdsGet('pds_res_barangay'),
        res_city:pdsGet('pds_res_city'), 
        res_province:pdsGet('pds_res_province'), 
        res_zip:pdsGet('pds_res_zip'),
        perm_same: document.getElementById('pds_perm_same')?.checked || false,
        perm_housenum:pdsGet('pds_perm_housenum'), 
        perm_street:pdsGet('pds_perm_street'),
        perm_subdivision:pdsGet('pds_perm_subdivision'), 
        perm_barangay:pdsGet('pds_perm_barangay'),
        perm_city:pdsGet('pds_perm_city'), 
        perm_province:pdsGet('pds_perm_province'), 
        perm_zip:pdsGet('pds_perm_zip'),
        tel:pdsGet('pds_tel'), 
        mobile:pdsGet('pds_mobile'), 
        email:pdsGet('pds_email'),
        gov_id_type:pdsGet('pds_gov_id_type'), 
        gov_id_no:pdsGet('pds_gov_id_no'),
      }),
      family_background: JSON.stringify({
        sp_surname:pdsGet('pds_sp_surname'), 
        sp_firstname:pdsGet('pds_sp_firstname'),
        sp_ext:pdsGet('pds_sp_ext'), 
        sp_middle:pdsGet('pds_sp_middle'),
        sp_occupation:pdsGet('pds_sp_occupation'), 
        sp_employer:pdsGet('pds_sp_employer'),
        sp_biz_addr:pdsGet('pds_sp_biz_addr'), 
        sp_tel:pdsGet('pds_sp_tel'),
        fa_surname:pdsGet('pds_fa_surname'), 
        fa_firstname:pdsGet('pds_fa_firstname'),
        fa_ext:pdsGet('pds_fa_ext'), 
        fa_middle:pdsGet('pds_fa_middle'),
        mo_surname:pdsGet('pds_mo_surname'), 
        mo_firstname:pdsGet('pds_mo_firstname'),
        mo_middle:pdsGet('pds_mo_middle'),
      }),
      education: JSON.stringify(edu),
      civil_service: JSON.stringify(civ),
      work_experience: JSON.stringify(we),
      voluntary_work: JSON.stringify(vw),
      trainings: JSON.stringify(tr),
      other_info: JSON.stringify({ 
        skills_arr: skills, 
        distinctions_arr: disting, 
        memberships_arr: membr 
      }),
      q4_questions: JSON.stringify(q4),
      pds_references: JSON.stringify(refs),
    };
    
    return d;
  } catch(e) {
    console.error('Error collecting PDS form data:', e);
    showToast('❌ Error collecting form data: ' + e.message, 'error');
    return {};
  }
}


function buildAndPrintPDS(d, empRow) {
  withScannedSignature(empRow).then(function(emp) {
  const html = buildPDSPrintHTML(d, emp);
  const w = window.open('', '_blank', 'width=960,height=1100');
  w.document.write(html);
  w.document.close();
  w.focus();
  setTimeout(() => w.print(), 600);
  });
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
  // Fix: Don't set invalid date values
  if (el.type === 'date') {
    if (!val || val === '0000-00-00' || val === '1970-01-01') {
      el.value = '';
    } else {
      el.value = val;
    }
    return;
  }
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

function salnNum(v) {
  var n = parseFloat((v === undefined || v === null ? '' : String(v)).replace(/,/g, ''));
  return isNaN(n) ? 0 : n;
}

function recalcSalnTotals() {
  var realRows = Array.from(document.querySelectorAll('#saln_real_list .saln-tbl tbody tr'));
  var personalRows = Array.from(document.querySelectorAll('#saln_personal_list .saln-tbl tbody tr'));
  var liabRows = Array.from(document.querySelectorAll('#saln_liab_list .saln-tbl tbody tr'));

  var realSubtotal = realRows.reduce(function(sum, tr) {
    var marketEl = tr.querySelector('[data-field="market_value"]');
    var assessedEl = tr.querySelector('[data-field="assessed_value"]');
    var market = salnNum(marketEl ? marketEl.value : 0);
    var assessed = salnNum(assessedEl ? assessedEl.value : 0);
    return sum + (market || assessed);
  }, 0);

  var personalSubtotal = personalRows.reduce(function(sum, tr) {
    var costEl = tr.querySelector('[data-field="acq_cost"]');
    return sum + salnNum(costEl ? costEl.value : 0);
  }, 0);

  var totalLiabilities = liabRows.reduce(function(sum, tr) {
    var balEl = tr.querySelector('[data-field="balance"]');
    return sum + salnNum(balEl ? balEl.value : 0);
  }, 0);

  var totalAssets = realSubtotal + personalSubtotal;
  var netWorth = totalAssets - totalLiabilities;

  var realSubtotalEl = document.getElementById('saln_real_subtotal');
  var personalSubtotalEl = document.getElementById('saln_personal_subtotal');
  var totalAssetsEl = document.getElementById('saln_total_assets');
  var totalLiabEl = document.getElementById('saln_total_liab');
  var netWorthEl = document.getElementById('saln_net_worth');

  if (realSubtotalEl) realSubtotalEl.value = realSubtotal.toFixed(2);
  if (personalSubtotalEl) personalSubtotalEl.value = personalSubtotal.toFixed(2);
  if (totalAssetsEl) totalAssetsEl.value = totalAssets.toFixed(2);
  if (totalLiabEl) totalLiabEl.value = totalLiabilities.toFixed(2);
  if (netWorthEl) netWorthEl.value = netWorth.toFixed(2);
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

  recalcSalnTotals();
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
  recalcSalnTotals();

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

// ════════════════════════════════════════════════════════
//  ANNEX B / C — Row Add / Remove
// ════════════════════════════════════════════════════════
function annexBRemoveRow(btn) {
  var tbody = btn.closest('tbody');
  var rows = tbody.querySelectorAll('tr');
  if (rows.length <= 1) { showToast('At least one row is required.', 'info'); return; }
  btn.closest('tr').remove();
}

function annexBAddRow(type) {
  var tbody, html;
  var rmBtn = '<td class="del-cell"><button type="button" class="del-btn" onclick="annexBRemoveRow(this)" title="Remove row">&#x2715;</button></td>';
  if (type === 'real') {
    tbody = document.getElementById('annexB-real-tbody');
    html = '<tr>'
      + '<td><input type="text" aria-label="Description" style="width:100%;min-width:140px;box-sizing:border-box;"/></td>'
      + '<td><input type="text" aria-label="Kind" style="width:100%;min-width:100px;box-sizing:border-box;"/></td>'
      + '<td><input type="text" aria-label="Exact Location" style="width:100%;min-width:120px;box-sizing:border-box;"/></td>'
      + '<td><input type="number" aria-label="Assessed Value" min="0" step="any" style="width:100%;min-width:100px;box-sizing:border-box;"/></td>'
      + '<td><input type="number" aria-label="Current Fair Market Value" min="0" step="any" style="width:100%;min-width:120px;box-sizing:border-box;"/></td>'
      + '<td><input type="number" aria-label="Acquisition Year" min="1900" max="2100" style="width:100%;min-width:90px;box-sizing:border-box;"/></td>'
      + '<td><input type="text" aria-label="Acquisition Mode" style="width:100%;min-width:100px;box-sizing:border-box;"/></td>'
      + '<td><input type="number" aria-label="Acquisition Cost" min="0" step="any" style="width:100%;min-width:120px;box-sizing:border-box;"/></td>'
      + rmBtn + '</tr>';
  } else if (type === 'personal') {
    tbody = document.getElementById('annexB-personal-tbody');
    html = '<tr>'
      + '<td><input type="text" aria-label="Description"/></td>'
      + '<td><input type="number" aria-label="Value" min="0" step="any"/></td>'
      + '<td><input type="text" aria-label="Remarks"/></td>'
      + rmBtn + '</tr>';
  } else if (type === 'liab') {
    tbody = document.getElementById('annexB-liab-tbody');
    html = '<tr>'
      + '<td><input type="text" aria-label="Description"/></td>'
      + '<td><input type="number" aria-label="Amount" min="0" step="any"/></td>'
      + '<td><input type="text" aria-label="Remarks"/></td>'
      + rmBtn + '</tr>';
  } else if (type === 'biz') {
    tbody = document.getElementById('annexB-biz-tbody');
    html = '<tr>'
      + '<td><input type="text" aria-label="Name of Entity/Business Enterprise" style="width:100%;min-width:180px;box-sizing:border-box;"/></td>'
      + '<td><input type="text" aria-label="Business Address" style="width:100%;min-width:150px;box-sizing:border-box;"/></td>'
      + '<td><input type="text" aria-label="Nature of Business Interest" style="width:100%;min-width:200px;box-sizing:border-box;"/></td>'
      + '<td><input type="text" aria-label="Date of Acquisition" style="width:100%;min-width:140px;box-sizing:border-box;"/></td>'
      + rmBtn + '</tr>';
  }
  if (tbody && html) {
    tbody.insertAdjacentHTML('beforeend', html);
    tbody.lastElementChild.querySelector('input').focus();
  }
}

function annexCAddRow(type) {
  var tbody, html;
  var rmBtn = '<td class="del-cell"><button type="button" class="del-btn" onclick="annexBRemoveRow(this)" title="Remove row">&#x2715;</button></td>';
  if (type === 'real') {
    tbody = document.getElementById('annexC-real-tbody');
    html = '<tr>'
      + '<td><input type="text" aria-label="Description" style="width:100%;min-width:140px;box-sizing:border-box;"/></td>'
      + '<td><input type="text" aria-label="Kind" style="width:100%;min-width:100px;box-sizing:border-box;"/></td>'
      + '<td><input type="text" aria-label="Exact Location" style="width:100%;min-width:120px;box-sizing:border-box;"/></td>'
      + '<td><input type="number" aria-label="Assessed Value" min="0" step="any" style="width:100%;min-width:100px;box-sizing:border-box;"/></td>'
      + '<td><input type="number" aria-label="Current Fair Market Value" min="0" step="any" style="width:100%;min-width:120px;box-sizing:border-box;"/></td>'
      + '<td><input type="number" aria-label="Acquisition Year" min="1900" max="2100" style="width:100%;min-width:90px;box-sizing:border-box;"/></td>'
      + '<td><input type="text" aria-label="Acquisition Mode" style="width:100%;min-width:100px;box-sizing:border-box;"/></td>'
      + '<td><input type="number" aria-label="Acquisition Cost" min="0" step="any" style="width:100%;min-width:120px;box-sizing:border-box;"/></td>'
      + rmBtn + '</tr>';
  } else if (type === 'personal') {
    tbody = document.getElementById('annexC-personal-tbody');
    html = '<tr>'
      + '<td><input type="text" aria-label="Description"/></td>'
      + '<td><input type="number" aria-label="Value" min="0" step="any"/></td>'
      + '<td><input type="text" aria-label="Remarks"/></td>'
      + rmBtn + '</tr>';
  } else if (type === 'liab') {
    tbody = document.getElementById('annexC-liab-tbody');
    html = '<tr>'
      + '<td><input type="text" aria-label="Description"/></td>'
      + '<td><input type="number" aria-label="Amount" min="0" step="any"/></td>'
      + '<td><input type="text" aria-label="Remarks"/></td>'
      + rmBtn + '</tr>';
  } else if (type === 'biz') {
    tbody = document.getElementById('annexC-biz-tbody');
    html = '<tr>'
      + '<td><input type="text" aria-label="Name of Entity/Business Enterprise" style="width:100%;min-width:180px;box-sizing:border-box;"/></td>'
      + '<td><input type="text" aria-label="Business Address" style="width:100%;min-width:150px;box-sizing:border-box;"/></td>'
      + '<td><input type="text" aria-label="Nature of Business Interest" style="width:100%;min-width:200px;box-sizing:border-box;"/></td>'
      + '<td><input type="text" aria-label="Date of Acquisition" style="width:100%;min-width:140px;box-sizing:border-box;"/></td>'
      + rmBtn + '</tr>';
  }
  if (tbody && html) {
    tbody.insertAdjacentHTML('beforeend', html);
    tbody.lastElementChild.querySelector('input').focus();
  }
}

// ════════════════════════════════════════════════════════
//  ANNEX B / C — Save & Print
// ════════════════════════════════════════════════════════
function saveAnnex(which) {
  const label  = which === 'annex_b' ? 'Annex B' : 'Annex C';
  const prefix = which === 'annex_b' ? 'annexB'  : 'annexC';

  const rowsOf = (tbodyId, mapFn) => {
    const tbody = document.getElementById(tbodyId);
    if (!tbody) return [];
    return [...tbody.querySelectorAll('tr')]
      .map(tr => mapFn([...tr.querySelectorAll('input, select')].map(inp => inp.value.trim())))
      .filter(row => Object.values(row).some(v => v !== ''));
  };

  const payload = {
    which,
    saln_year: salnCurrentYear,
    real_properties:     rowsOf(prefix + '-real-tbody',     c => ({description:c[0], kind:c[1], location:c[2], assessed_value:c[3], market_value:c[4], acq_year:c[5], acq_mode:c[6], acq_cost:c[7]})),
    // Personal/Liabilities sheets only have 3 columns (Description/Value|Amount/Remarks); mapped positionally to match the print layout.
    personal_properties: rowsOf(prefix + '-personal-tbody', c => ({description:c[0], acq_year:c[1], acq_cost:c[2]})),
    liabilities:         rowsOf(prefix + '-liab-tbody',      c => ({nature:c[0], creditor:c[1], balance:c[2]})),
    biz_interests:       rowsOf(prefix + '-biz-tbody',       c => ({entity_name:c[0], biz_address:c[1], nature:c[2], date_acquired:c[3]})),
  };

  try { localStorage.setItem('hrms_' + which, JSON.stringify({ saved_at: new Date().toISOString(), data: payload })); } catch(e) {}

  fetch('Employee_portal.php?action=save_saln_annex', {
    method: 'POST', headers: {'Content-Type':'application/json','X-CSRF-TOKEN':CSRF_TOKEN}, body: JSON.stringify(payload)
  }).then(r => r.json()).then(j => {
    showToast(j.success ? ('✅ ' + label + ' saved!') : ('❌ ' + (j.message || 'Could not save ' + label + '.')), j.success ? 'success' : 'error');
  }).catch(() => showToast('❌ Could not save ' + label + '.', 'error'));
}

function loadAnnex(which) {
  const page = document.getElementById('page-' + which);
  if (!page) return;
  const prefix = which === 'annex_b' ? 'annexB' : 'annexC';
  const safeJsonLocal = s => { try { return s ? JSON.parse(s) : null; } catch(e) { return null; } };

  const fillTable = (tbodyId, rows, cols, addFn, addType) => {
    const tbody = document.getElementById(tbodyId);
    if (!tbody || !rows || !rows.length) return;
    while (tbody.children.length < rows.length) addFn(addType);
    [...tbody.querySelectorAll('tr')].forEach((tr, ri) => {
      const row = rows[ri] || {};
      [...tr.querySelectorAll('input, select')].forEach((inp, ci) => {
        if (row[cols[ci]] !== undefined) inp.value = row[cols[ci]];
      });
    });
  };

  fetch('Employee_portal.php?action=get_saln&year=' + salnCurrentYear).then(r => r.json()).then(j => {
    const d = j.data || {};
    const p = which === 'annex_b' ? 'as1' : 'as2';
    const real = safeJsonLocal(d[p + '_real_properties']);
    const pers = safeJsonLocal(d[p + '_personal_properties']);
    const liab = safeJsonLocal(d[p + '_liabilities']);
    const biz  = safeJsonLocal(d[p + '_biz_interests']);

    if (!real && !pers && !liab && !biz) { loadAnnexFromLocal(which); return; }

    const addFn = which === 'annex_b' ? annexBAddRow : annexCAddRow;
    fillTable(prefix + '-real-tbody',     real, ['description','kind','location','assessed_value','market_value','acq_year','acq_mode','acq_cost'], addFn, 'real');
    fillTable(prefix + '-personal-tbody', pers, ['description','acq_year','acq_cost'], addFn, 'personal');
    fillTable(prefix + '-liab-tbody',     liab, ['nature','creditor','balance'], addFn, 'liab');
    fillTable(prefix + '-biz-tbody',      biz,  ['entity_name','biz_address','nature','date_acquired'], addFn, 'biz');
  }).catch(() => loadAnnexFromLocal(which));
}

// Fallback for data saved before server-side persistence existed.
function loadAnnexFromLocal(which) {
  const page = document.getElementById('page-' + which);
  if (!page) return;
  try {
    const saved = JSON.parse(localStorage.getItem('hrms_' + which) || 'null');
    if (!saved || !saved.data) return;
    const data = saved.data;
    const asOf = page.querySelector('.fg input[type="text"]');
    if (asOf && data.as_of) asOf.value = data.as_of;
    page.querySelectorAll('.saln-table-scroll table').forEach((tbl, ti) => {
      const rows = data['table_' + ti] || [];
      tbl.querySelectorAll('tbody tr').forEach((tr, ri) => {
        const row = rows[ri] || {};
        tr.querySelectorAll('input, select').forEach((inp, ci) => {
          if (row['col_' + ci] !== undefined) inp.value = row['col_' + ci];
        });
      });
    });
  } catch(e) {}
}


function printAnnex(which) {

  // Collect all rows from a table element directly
  const getRowsFromTable = (tbl, cols, minRows) => {
    const domRows = tbl ? [...tbl.querySelectorAll('tbody tr')] : [];
    const count = Math.max(minRows, domRows.length);
    return Array(count).fill(0).map((_,i) =>
      Array(cols).fill(0).map((_,j) => {
        const cell = domRows[i]?.children[j];
        if (!cell) return '';
        const inp = cell.querySelector('input,select');
        return inp ? (inp.value || '') : (cell.textContent.trim() || '');
      })
    );
  };

  // Get the Nth table inside a page div (1-based), searching all saln-table-scroll wrappers
  const getNthTable = (page, n) => {
    const scrolls = [...page.querySelectorAll('.saln-table-scroll')];
    const scroll = scrolls[n - 1];
    return scroll ? scroll.querySelector('table') : null;
  };

  const fmtM = x => {
    if (x === '' || x === null || x === undefined) return '';
    const n = parseFloat(String(x).replace(/[^0-9.-]/g,''));
    return isNaN(n) ? x : '₱' + n.toLocaleString('en-PH',{minimumFractionDigits:2});
  };
  const toNum = x => {
    const n = parseFloat(String(x === undefined || x === null ? '' : x).replace(/[^0-9.-]/g, ''));
    return isNaN(n) ? 0 : n;
  };

  // ── SHARED CSS — matches official form exactly ───────────────
  const sharedCss = `
    *{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'Arial Narrow',Arial,sans-serif;font-size:7pt;color:#000;background:#fff;}
    @page{size:8.5in 13in;margin:12mm 15mm 10mm 15mm;}
    @media print{body{margin:0;} .no-print{display:none;}}
    table{border-collapse:collapse;width:100%;}
    td,th{border:0.5pt solid #000;padding:2pt 3pt;font-size:6.5pt;vertical-align:middle;}
    .ref{text-align:right;font-size:6pt;line-height:1.6;margin-bottom:6pt;}
    .main-title{font-size:10pt;font-weight:700;text-transform:uppercase;text-align:center;letter-spacing:.02em;}
    .as-of-line{text-align:center;font-size:7pt;margin-top:2pt;}
    .italic-note{text-align:center;font-size:7pt;font-style:italic;margin-top:3pt;margin-bottom:8pt;}
    .double-rule{border-top:2pt double #000;border-bottom:0.5pt solid #000;height:3pt;margin:8pt 0 6pt 0;}
    .assets-title{font-size:8pt;font-weight:700;text-align:center;text-decoration:underline;text-transform:uppercase;margin-bottom:4pt;}
    .section-num{font-size:7.5pt;font-weight:700;margin-bottom:2pt;}
    .section-sub{font-size:7pt;font-weight:700;font-style:italic;margin-bottom:3pt;margin-left:12pt;}
    .col-hd{background:#c8c8c8;font-weight:700;font-size:6pt;text-align:center;vertical-align:middle;padding:2pt 2pt;}
    .data-row td{height:22pt;vertical-align:middle;}
    .subtotal-row td{border:none;text-align:right;font-size:7pt;font-weight:700;padding:2pt 4pt;}
    .total-row td{border:none;text-align:right;font-size:7.5pt;font-weight:700;padding:1pt 4pt;text-transform:uppercase;}
    .footer-area{display:flex;justify-content:space-between;align-items:flex-end;margin-top:10pt;}
    .page-num{font-size:8pt;font-style:italic;}
    .sig-line{font-size:7pt;font-style:italic;border-top:0.5pt solid #000;padding-top:2pt;min-width:130pt;text-align:center;}
    .name-block{margin-bottom:8pt;}
    .name-row td{border:none;border-bottom:0.5pt solid #000;padding:1pt 3pt;font-size:7pt;min-height:14pt;}
    .name-lbl{font-size:5.5pt;text-align:center;color:#000;padding-top:1pt;}
  `;

  // ── ANNEX B — Sheet AS-1 (Declarant) ────────────────────────
  if (which === 'annex_b') {
    const page = document.getElementById('page-annex_b');
    if (!page) return;

    const real = getRowsFromTable(getNthTable(page, 1), 8, 4);
    const pers = getRowsFromTable(getNthTable(page, 2), 3, 5);
    const liab = getRowsFromTable(getNthTable(page, 3), 3, 5);
    const biz  = getRowsFromTable(getNthTable(page, 4), 4, 3);

    const annexBRealSubtotal = real.reduce((sum, r) => {
      const marketRaw = String(r[4] ?? '').trim();
      const assessedRaw = String(r[3] ?? '').trim();
      if (marketRaw !== '') return sum + toNum(marketRaw);
      if (assessedRaw !== '') return sum + toNum(assessedRaw);
      return sum;
    }, 0);
    const annexBPersonalSubtotal = pers.reduce((sum, r) => sum + toNum(r[2]), 0);
    const annexBTotalAssets = annexBRealSubtotal + annexBPersonalSubtotal;
    const annexBTotalLiabilities = liab.reduce((sum, r) => sum + toNum(r[2]), 0);

    // Get declarant name fields if available
    const getVal = (sel) => (page.querySelector(sel)||{}).value || '';

    const realRows = real.map(r => `<tr class="data-row">
      <td style="width:15%;">${r[0]||''}&nbsp;</td>
      <td style="width:12%;">${r[1]||''}&nbsp;</td>
      <td style="width:14%;">${r[2]||''}&nbsp;</td>
      <td style="width:9%;text-align:right;">${fmtM(r[3])}&nbsp;</td>
      <td style="width:10%;text-align:right;">${fmtM(r[4])}&nbsp;</td>
      <td style="width:8%;text-align:center;">${r[5]||''}&nbsp;</td>
      <td style="width:9%;">${r[6]||''}&nbsp;</td>
      <td style="width:9%;text-align:right;">${fmtM(r[7])}&nbsp;</td>
    </tr>`).join('');

    const persRows = pers.map(r => `<tr class="data-row">
      <td style="width:62%;">${r[0]||''}&nbsp;</td>
      <td style="width:18%;text-align:center;">${r[1]||''}&nbsp;</td>
      <td style="width:20%;text-align:right;">${fmtM(r[2])}&nbsp;</td>
    </tr>`).join('');

    const liabRows = liab.map(r => `<tr class="data-row">
      <td style="width:30%;">${r[0]||''}&nbsp;</td>
      <td style="width:43%;">${r[1]||''}&nbsp;</td>
      <td style="width:27%;text-align:right;">${fmtM(r[2])}&nbsp;</td>
    </tr>`).join('');

    const bizRows = biz.map(r => `<tr class="data-row">
      <td style="width:27%;">${r[0]||''}&nbsp;</td>
      <td style="width:23%;">${r[1]||''}&nbsp;</td>
      <td style="width:29%;">${r[2]||''}&nbsp;</td>
      <td style="width:21%;text-align:center;">${r[3]||''}&nbsp;</td>
    </tr>`).join('');

    const html = `<!DOCTYPE html>
<html><head><meta charset="UTF-8"/>
<title>Annex B — Sheet AS-1 (Declarant)</title>
<style>${sharedCss}</style>
</head><body>

<div class="ref">
  SALN Form AS-1 (Declarant)<br>
  Per CSC Resolution No. 2500632<br>
  Promulgated on 25 June 2025
</div>

<div class="main-title">Sworn Statement of Assets, Liabilities and Net Worth</div>
<div class="as-of-line">As of <span style="display:inline-block;border-bottom:0.5pt solid #000;min-width:160pt;">&nbsp;</span></div>
<div class="italic-note">(Additional sheet/s for the declarant)</div>

<!-- NAME / POSITION block -->
<table style="margin-bottom:6pt;" class="name-block">
  <tr>
    <td style="border:none;width:7%;padding:2pt 0;font-size:7pt;font-weight:700;vertical-align:bottom;">NAME:</td>
    <td style="border:none;width:43%;padding:0;vertical-align:bottom;">
      <table style="width:100%;border-collapse:collapse;">
        <tr class="name-row">
          <td style="width:40%;">&nbsp;</td>
          <td style="border-left:none;width:40%;">&nbsp;</td>
          <td style="border-left:none;width:20%;">&nbsp;</td>
        </tr>
        <tr>
          <td class="name-lbl" style="border:none;">(Family Name)</td>
          <td class="name-lbl" style="border:none;">(First Name)</td>
          <td class="name-lbl" style="border:none;">(M.I.)</td>
        </tr>
      </table>
    </td>
    <td style="border:none;width:8%;padding:2pt 4pt;font-size:7pt;font-weight:700;vertical-align:bottom;">POSITION:</td>
    <td style="border:none;border-bottom:0.5pt solid #000;width:42%;vertical-align:bottom;">&nbsp;</td>
  </tr>
  <tr>
    <td style="border:none;" colspan="2"></td>
    <td style="border:none;padding:2pt 4pt;font-size:7pt;font-weight:700;vertical-align:bottom;">AGENCY/OFFICE:</td>
    <td style="border:none;border-bottom:0.5pt solid #000;vertical-align:bottom;">&nbsp;</td>
  </tr>
</table>

<div class="double-rule"></div>

<div class="assets-title">Assets, Liabilities and Net Worth</div>

<!-- 1. ASSETS -->
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
  <tbody>${realRows}</tbody>
</table>
<table style="margin-bottom:8pt;"><tr class="subtotal-row">
  <td colspan="8">Subtotal: <span style="display:inline-block;border-bottom:0.5pt solid #000;min-width:100pt;">${fmtM(annexBRealSubtotal)}&nbsp;</span></td>
</tr></table>

<div class="section-sub">b.&nbsp;&nbsp;Personal Properties</div>
<table style="margin-bottom:0;">
  <thead>
    <tr>
      <th class="col-hd" style="width:62%;">DESCRIPTION</th>
      <th class="col-hd" style="width:18%;">ACQUISITION YEAR</th>
      <th class="col-hd" style="width:20%;">ACQUISITION<br>COST/ AMOUNT</th>
    </tr>
  </thead>
  <tbody>${persRows}</tbody>
</table>
<table style="margin-bottom:8pt;">
  <tr class="subtotal-row"><td colspan="3">Subtotal: <span style="display:inline-block;border-bottom:0.5pt solid #000;min-width:100pt;">${fmtM(annexBPersonalSubtotal)}&nbsp;</span></td></tr>
  <tr class="total-row"><td colspan="3">Total Assets: <span style="display:inline-block;border-bottom:2pt solid #000;min-width:100pt;">${fmtM(annexBTotalAssets)}&nbsp;</span></td></tr>
</table>

<!-- 2. LIABILITIES -->
<div class="section-num">2.&nbsp;&nbsp;LIABILITIES</div>
<table style="margin-bottom:0;">
  <thead>
    <tr>
      <th class="col-hd" style="width:30%;">NATURE</th>
      <th class="col-hd" style="width:43%;">NAME OF CREDITORS</th>
      <th class="col-hd" style="width:27%;">OUTSTANDING BALANCE</th>
    </tr>
  </thead>
  <tbody>${liabRows}</tbody>
</table>
<table style="margin-bottom:4pt;"><tr class="total-row">
  <td colspan="3">Total Liabilities: <span style="display:inline-block;border-bottom:0.5pt solid #000;min-width:100pt;">${fmtM(annexBTotalLiabilities)}&nbsp;</span></td>
</tr></table>

<div class="double-rule"></div>

<!-- BUSINESS INTERESTS -->
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
  <tbody>${bizRows}</tbody>
</table>

<div class="footer-area">
  <div class="page-num"><em>Page ___ of ___</em></div>
  <div class="sig-line">Signature/Initial of Declarant</div>
</div>

</body></html>`;

    const w = window.open('', '_blank', 'width=900,height=1100');
    w.document.write(html);
    w.document.close();
    w.focus();
    setTimeout(() => w.print(), 500);
    return;
  }

  // ── ANNEX C — Sheet AS-2 (Spouse & Children) ────────────────
  if (which === 'annex_c') {
    const page = document.getElementById('page-annex_c');
    if (!page) return;

    const real = getRowsFromTable(getNthTable(page, 1), 8, 4);
    const pers = getRowsFromTable(getNthTable(page, 2), 3, 5);
    const liab = getRowsFromTable(getNthTable(page, 3), 3, 5);
    const biz  = getRowsFromTable(getNthTable(page, 4), 4, 3);

    const annexCRealSubtotal = real.reduce((sum, r) => {
      const marketRaw = String(r[4] ?? '').trim();
      const assessedRaw = String(r[3] ?? '').trim();
      if (marketRaw !== '') return sum + toNum(marketRaw);
      if (assessedRaw !== '') return sum + toNum(assessedRaw);
      return sum;
    }, 0);
    const annexCPersonalSubtotal = pers.reduce((sum, r) => sum + toNum(r[2]), 0);
    const annexCTotalAssets = annexCRealSubtotal + annexCPersonalSubtotal;
    const annexCTotalLiabilities = liab.reduce((sum, r) => sum + toNum(r[2]), 0);

    const realRows = real.map(r => `<tr class="data-row">
      <td style="width:15%;">${r[0]||''}&nbsp;</td>
      <td style="width:12%;">${r[1]||''}&nbsp;</td>
      <td style="width:14%;">${r[2]||''}&nbsp;</td>
      <td style="width:9%;text-align:right;">${fmtM(r[3])}&nbsp;</td>
      <td style="width:10%;text-align:right;">${fmtM(r[4])}&nbsp;</td>
      <td style="width:8%;text-align:center;">${r[5]||''}&nbsp;</td>
      <td style="width:9%;">${r[6]||''}&nbsp;</td>
      <td style="width:9%;text-align:right;">${fmtM(r[7])}&nbsp;</td>
    </tr>`).join('');

    const persRows = pers.map(r => `<tr class="data-row">
      <td style="width:62%;">${r[0]||''}&nbsp;</td>
      <td style="width:18%;text-align:center;">${r[1]||''}&nbsp;</td>
      <td style="width:20%;text-align:right;">${fmtM(r[2])}&nbsp;</td>
    </tr>`).join('');

    const liabRows = liab.map(r => `<tr class="data-row">
      <td style="width:30%;">${r[0]||''}&nbsp;</td>
      <td style="width:43%;">${r[1]||''}&nbsp;</td>
      <td style="width:27%;text-align:right;">${fmtM(r[2])}&nbsp;</td>
    </tr>`).join('');

    const bizRows = biz.map(r => `<tr class="data-row">
      <td style="width:27%;">${r[0]||''}&nbsp;</td>
      <td style="width:23%;">${r[1]||''}&nbsp;</td>
      <td style="width:29%;">${r[2]||''}&nbsp;</td>
      <td style="width:21%;text-align:center;">${r[3]||''}&nbsp;</td>
    </tr>`).join('');

    const html = `<!DOCTYPE html>
<html><head><meta charset="UTF-8"/>
<title>Annex C — Sheet AS-2 (Spouse &amp; Children)</title>
<style>${sharedCss}</style>
</head><body>

<div class="ref">
  2025 SALN Form AS-2 (Spouse &amp; Children)<br>
  Per CSC Resolution No. 2500632<br>
  Promulgated on 25 June 2025
</div>

<div class="main-title">Statement of Assets, Liabilities and Net Worth</div>
<div class="as-of-line">As of <span style="display:inline-block;border-bottom:0.5pt solid #000;min-width:160pt;">&nbsp;</span></div>
<div class="italic-note">(Additional sheet/s for the exclusive properties of the declarant's spouse and unmarried children<br>below eighteen (18) years of age living in declarant's household)</div>

<!-- NAME / POSITION block -->
<table style="margin-bottom:6pt;" class="name-block">
  <tr>
    <td style="border:none;width:7%;padding:2pt 0;font-size:7pt;font-weight:700;vertical-align:bottom;">NAME:</td>
    <td style="border:none;width:43%;padding:0;vertical-align:bottom;">
      <table style="width:100%;border-collapse:collapse;">
        <tr class="name-row">
          <td style="width:40%;">&nbsp;</td>
          <td style="border-left:none;width:40%;">&nbsp;</td>
          <td style="border-left:none;width:20%;">&nbsp;</td>
        </tr>
        <tr>
          <td class="name-lbl" style="border:none;">(Family Name)</td>
          <td class="name-lbl" style="border:none;">(First Name)</td>
          <td class="name-lbl" style="border:none;">(M.I.)</td>
        </tr>
      </table>
    </td>
    <td style="border:none;width:8%;padding:2pt 4pt;font-size:7pt;font-weight:700;vertical-align:bottom;">POSITION:</td>
    <td style="border:none;border-bottom:0.5pt solid #000;width:42%;vertical-align:bottom;">&nbsp;</td>
  </tr>
  <tr>
    <td style="border:none;" colspan="2"></td>
    <td style="border:none;padding:2pt 4pt;font-size:7pt;font-weight:700;vertical-align:bottom;">AGENCY/OFFICE:</td>
    <td style="border:none;border-bottom:0.5pt solid #000;vertical-align:bottom;">&nbsp;</td>
  </tr>
</table>

<div class="double-rule"></div>

<div class="assets-title">Assets, Liabilities and Net Worth</div>

<!-- 1. ASSETS -->
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
  <tbody>${realRows}</tbody>
</table>
<table style="margin-bottom:8pt;"><tr class="subtotal-row">
  <td colspan="8">Subtotal: <span style="display:inline-block;border-bottom:0.5pt solid #000;min-width:100pt;">${fmtM(annexCRealSubtotal)}&nbsp;</span></td>
</tr></table>

<div class="section-sub">b.&nbsp;&nbsp;Personal Properties</div>
<table style="margin-bottom:0;">
  <thead>
    <tr>
      <th class="col-hd" style="width:62%;">DESCRIPTION</th>
      <th class="col-hd" style="width:18%;">ACQUISITION YEAR</th>
      <th class="col-hd" style="width:20%;">ACQUISITION<br>COST/ AMOUNT</th>
    </tr>
  </thead>
  <tbody>${persRows}</tbody>
</table>
<table style="margin-bottom:8pt;">
  <tr class="subtotal-row"><td colspan="3">Subtotal: <span style="display:inline-block;border-bottom:0.5pt solid #000;min-width:100pt;">${fmtM(annexCPersonalSubtotal)}&nbsp;</span></td></tr>
  <tr class="total-row"><td colspan="3">Total Assets: <span style="display:inline-block;border-bottom:2pt solid #000;min-width:100pt;">${fmtM(annexCTotalAssets)}&nbsp;</span></td></tr>
</table>

<!-- 2. LIABILITIES -->
<div class="section-num">2.&nbsp;&nbsp;LIABILITIES</div>
<table style="margin-bottom:0;">
  <thead>
    <tr>
      <th class="col-hd" style="width:30%;">NATURE</th>
      <th class="col-hd" style="width:43%;">NAME OF CREDITORS</th>
      <th class="col-hd" style="width:27%;">OUTSTANDING BALANCE</th>
    </tr>
  </thead>
  <tbody>${liabRows}</tbody>
</table>
<table style="margin-bottom:4pt;"><tr class="total-row">
  <td colspan="3">Total Liabilities: <span style="display:inline-block;border-bottom:0.5pt solid #000;min-width:100pt;">${fmtM(annexCTotalLiabilities)}&nbsp;</span></td>
</tr></table>

<div class="double-rule"></div>

<!-- BUSINESS INTERESTS -->
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
  <tbody>${bizRows}</tbody>
</table>

<div class="footer-area">
  <div class="page-num"><em>Page ___ of ___</em></div>
  <div class="sig-line">Signature/Initial of Declarant</div>
</div>

</body></html>`;

    const w = window.open('', '_blank', 'width=900,height=1100');
    w.document.write(html);
    w.document.close();
    w.focus();
    setTimeout(() => w.print(), 500);
    return;
  }

  // fallback for other annexes
  return;
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

  const dec = sj(d.declarant_info)      || {};
  const sp  = sj(d.spouse_info)         || {};
  const ch  = sj(d.children_info)       || [];
  const rp  = sj(d.real_properties)     || [];
  const pp  = sj(d.personal_properties) || [];
  const lb  = sj(d.liabilities)         || [];
  const biz = sj(d.biz_interests)       || [];
  const rel = sj(d.relatives_govt)      || [];

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

  // td helpers — no background, plain border like official form
  const C  = (txt,s) => `<td style="border:0.5pt solid #000;padding:1.5pt 3pt;font-size:6.5pt;vertical-align:middle;${s||''}">${v(txt)}&nbsp;</td>`;
  const H  = (txt,s) => `<td style="border:0.5pt solid #000;padding:2pt 3pt;font-size:6pt;font-weight:700;text-align:center;vertical-align:middle;${s||''}">${txt}</td>`;

  // Real property rows — min 4
  const realRows = padR(rp, 4, p => `<tr style="height:18pt;">
    ${C(p.description,'width:18%;font-size:6.5pt;')}${C(p.kind,'width:12%;')}${C(p.location,'width:16%;')}
    ${C(fmtM(p.assessed_value),'width:10%;text-align:right;')}${C(fmtM(p.market_value),'width:10%;text-align:right;')}
    ${C(p.acq_year,'width:8%;text-align:center;')}${C(p.acq_mode,'width:10%;')}${C(fmtM(p.acq_cost),'width:10%;text-align:right;')}
  </tr>`);

  // Personal property rows — min 6
  const persRows = padR(pp, 6, p => `<tr style="height:16pt;">
    ${C(p.description,'width:65%;')}${C(p.acq_year,'width:17%;text-align:center;')}${C(fmtM(p.acq_cost),'width:18%;text-align:right;')}
  </tr>`);

  // Liability rows — min 5
  const liabRows = padR(lb, 5, l => `<tr style="height:14pt;">
    ${C(l.nature,'width:34%;')}${C(l.creditor,'width:41%;')}${C(fmtM(l.balance),'width:25%;text-align:right;')}
  </tr>`);

  // Business interest rows — min 3
  const bizRows = padR(d.no_biz?[]:biz, 3, b => `<tr style="height:14pt;">
    ${C(b.entity_name,'width:27%;')}${C(b.biz_address,'width:26%;')}${C(b.nature,'width:28%;')}${C(fmtD(b.date_acquired),'width:19%;text-align:center;')}
  </tr>`);

  // Relatives rows — min 5
  const relRows = padR(d.no_rel?[]:rel, 5, r => `<tr style="height:14pt;">
    ${C(r.name,'width:24%;')}${C(r.relationship,'width:16%;')}${C(r.position,'width:24%;')}${C(r.agency_address,'width:36%;')}
  </tr>`);

  // Children rows — min 3
  const childRows = padR(ch, 3, c => `<tr style="height:16pt;">
    <td style="border:0.5pt solid #000;padding:1.5pt 3pt;font-size:7pt;width:76%;">${v(c.name)}&nbsp;</td>
    <td style="border:0.5pt solid #000;padding:1.5pt 3pt;font-size:7pt;text-align:center;width:24%;">${v(c.age)}&nbsp;</td>
  </tr>`);

  const css = `
    *{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'Arial Narrow',Arial,sans-serif;font-size:7pt;color:#000;background:#fff;}
    @page{size:8.5in 13in;margin:12mm 14mm 10mm 14mm;}
    @media print{body{margin:0;} .no-print{display:none;}}
    table{border-collapse:collapse;width:100%;}
    .section-title{font-size:8pt;font-weight:700;text-align:center;text-decoration:underline;text-transform:uppercase;letter-spacing:.03em;}
    .page-footer{text-align:center;font-size:7.5pt;font-style:italic;margin-top:6pt;}
  `;

  const html = `<!DOCTYPE html>
<html><head>
  <meta charset="UTF-8"/>
  <title>SALN ${v(d.saln_year)} — ${v(dec.lastname)}, ${v(dec.firstname)}</title>
  <style>${css}</style>
</head><body>

<!-- top-right reference -->
<div style="text-align:right;font-size:6pt;margin-bottom:4pt;line-height:1.6;">
  2025 SALN Form<br>Per CSC Resolution No. 2500632<br>Promulgated on 25 June 2025
</div>

<!-- TITLE -->
<div style="text-align:center;margin-bottom:6pt;">
  <div style="font-size:10pt;font-weight:700;text-transform:uppercase;letter-spacing:.04em;">Sworn Statement of Assets, Liabilities, and Net Worth</div>
  <div style="font-size:7pt;">(As required by R.A. No. 6713)</div>
</div>

<!-- COMPLIANCE FOR -->
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

<!-- DOUBLE HORIZONTAL RULE -->
<div style="border-top:2pt double #000;border-bottom:0.5pt solid #000;height:3pt;margin-bottom:4pt;"></div>

<!-- DECLARANT / SPOUSE BLOCK -->
<table style="margin-bottom:4pt;">
  <tr>
    <!-- DECLARANT left -->
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
    <!-- POSITION / AGENCY right -->
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
    <!-- SPOUSE left -->
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
    <!-- SPOUSE POSITION right -->
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

<!-- JOINT/SEPARATE FILING -->
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

<!-- DOUBLE RULE -->
<div style="border-top:2pt double #000;border-bottom:0.5pt solid #000;height:3pt;margin-bottom:6pt;"></div>

<!-- UNMARRIED CHILDREN -->
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

<!-- DOUBLE RULE -->
<div style="border-top:2pt double #000;border-bottom:0.5pt solid #000;height:3pt;margin-bottom:6pt;"></div>

<!-- ASSETS HEADER -->
<div style="font-size:8pt;font-weight:700;text-align:center;text-decoration:underline;text-transform:uppercase;margin-bottom:2pt;">Assets, Liabilities and Net Worth<sup style="font-size:5.5pt;">ii</sup></div>
<div style="font-size:6pt;text-align:center;font-style:italic;margin-bottom:4pt;">(Including those of the spouse and unmarried children below eighteen (18) years of age living in declarant's household)<sup style="font-size:5pt;">iii</sup></div>

<!-- REAL PROPERTIES -->
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

<!-- PERSONAL PROPERTIES -->
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

<!-- page footer page 1 -->
<div style="display:flex;justify-content:space-between;align-items:flex-end;margin-top:8pt;">
  <div style="font-size:7.5pt;font-style:italic;">Page 1 of ___</div>
  <div style="font-size:7pt;font-style:italic;border-top:0.5pt solid #000;padding-top:2pt;min-width:120pt;text-align:center;">Signature/Initial of Declarant</div>
</div>

<!-- PAGE BREAK -->
<div style="page-break-after:always;"></div>

<!-- PAGE 2 -->

<!-- LIABILITIES -->
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

<!-- BUSINESS INTERESTS -->
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

<!-- RELATIVES IN GOVT -->
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

<!-- CERTIFICATION TEXT -->
<div style="font-size:7pt;line-height:1.8;text-align:justify;margin-bottom:8pt;padding:0 4pt;">
  &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;I hereby certify that these&nbsp; are true and correct statements&nbsp; of my assets,&nbsp; liabilities, net worth, business interests&nbsp; and financial connections, including those&nbsp; of my spouse and unmarried children below eighteen (18)&nbsp; years of age living in my household,&nbsp; and that to the best of my knowledge, the above-enumerated are names&nbsp; of my relatives&nbsp; in the government&nbsp; within the fourth civil degree of consanguinity or affinity.
</div>
<div style="font-size:7pt;line-height:1.8;text-align:justify;margin-bottom:14pt;padding:0 4pt;">
  &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;I hereby authorize the Ombudsman&nbsp; or his/her duly authorized representative to obtain and secure from all appropriate government agencies, including the Bureau of Internal Revenue such documents that may show my assets, liabilities, net worth, business interests and financial connections, to include those of my spouse and unmarried children below 18 years of age living with me&nbsp; in my household covering previous years to include the year&nbsp; I first&nbsp; assumed office in government.
</div>

<!-- DATE -->
<div style="font-size:7pt;margin-bottom:18pt;">
  Date:&nbsp;&nbsp;&nbsp;&nbsp;<span style="display:inline-block;border-bottom:0.5pt solid #000;min-width:150pt;">&nbsp;</span>
</div>

<!-- SIGNATURE BLOCK -->
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

<!-- SUBSCRIBED AND SWORN -->
<div style="font-size:7pt;line-height:1.8;margin-bottom:18pt;text-align:justify;">
  &nbsp;&nbsp;&nbsp;<strong>SUBSCRIBED AND SWORN</strong> to before me this <span style="display:inline-block;border-bottom:0.5pt solid #000;min-width:22pt;text-align:center;">&nbsp;</span> day of <span style="display:inline-block;border-bottom:0.5pt solid #000;min-width:40pt;">&nbsp;</span>, affiant exhibiting to me the above-stated government-issued identification card.
</div>

<div style="margin-top:4pt;width:44%;text-align:center;margin-left:auto;margin-right:auto;">
  <div style="border-top:0.5pt solid #000;padding-top:3pt;font-size:7pt;font-style:italic;">(Person Administering Oath)</div>
</div>

<!-- FOOTNOTES -->
<div style="margin-top:18pt;border-top:0.5pt solid #000;padding-top:4pt;">
  <div style="font-size:5.5pt;line-height:1.7;">
    <sup>1</sup> Position, Agency, and Address shall only be declared if the spouse is a public official or employee.<br>
    <sup>ii</sup> Additional sheets may be used by the declarant, if necessary.<br>
    <sup>iii</sup> Capital or paraphernal assets, and liabilities of the declarant's spouse, and properties of children below 18 years of age and living in the declarant's household shall be disclosed using the additional sheets provided.<br>
    <sup>iv</sup> <em>Balae</em> refers to the parent of one's son or daughter-in-law; <em>Bilas</em> refers to a brother-in-law's wife or sister-in-law's husband; <em>Inso</em> refers to the appellation for the wife of an elder brother or male cousin.
  </div>
</div>

<!-- page footer page 2 -->
<div style="text-align:center;margin-top:8pt;font-size:7.5pt;font-style:italic;">Page 2 of ___</div>

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
      if (c.w) { th.style.minWidth = c.w; th.style.whiteSpace = 'nowrap'; }
      tr.appendChild(th);
    });
    var thDel = document.createElement('th'); thDel.className='del-cell';
    tr.appendChild(thDel);
    thead.appendChild(tr);
    tbl.appendChild(thead);
    var tbody = document.createElement('tbody');
    tbl.appendChild(tbody);
    // Set explicit width/min-width so scroll container knows when to show scrollbar
    var totalW = cols.reduce(function(s,c){ return s + (c.w ? parseInt(c.w) : 120); }, 0) + 40;
    if (totalW > 700) {
      tbl.style.minWidth = totalW + 'px';
    } else {
      tbl.style.width = '100%';
    }
    var scroll = document.createElement('div');
    scroll.className = 'saln-table-scroll';
    scroll.appendChild(tbl);
    container.insertBefore(scroll, container.firstChild);
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
    inp.addEventListener('input', recalcSalnTotals);
    if (c.w) { td.style.minWidth = c.w; inp.style.width = '100%'; inp.style.boxSizing = 'border-box'; }
    td.appendChild(inp);
    tr.appendChild(td);
  });
  var tdDel = document.createElement('td'); tdDel.className='del-cell';
  var btn = document.createElement('button'); btn.className='del-btn'; btn.type='button'; btn.textContent='✕';
  btn.onclick = function(){ tr.remove(); if(!tbody.rows.length){ var t=tbody.closest('.saln-tbl'); if(t) t.remove(); } recalcSalnTotals(); };
  tdDel.appendChild(btn); tr.appendChild(tdDel);
  tbody.appendChild(tr);
  recalcSalnTotals();
}

function addSalnChild(v)  {
  salnAddRow('saln_children_list',[
    {key:'name', label:'Name of Child', w:'75%'},
    {key:'age',  label:'Age', type:'number', w:'25%'},
  ], v);
}
function addReal(v) {
  salnAddRow('saln_real_list', [
    {key:'description',    label:'Description',              w:'160px'},
    {key:'kind',           label:'Kind',                     w:'110px'},
    {key:'location',       label:'Exact Location',           w:'150px'},
    {key:'assessed_value', label:'Assessed Value (₱)',       type:'number', w:'150px'},
    {key:'market_value',   label:'Market Value (₱)',         type:'number', w:'150px'},
    {key:'acq_year',       label:'Acq. Year',                type:'number', w:'90px'},
    {key:'acq_mode',       label:'Mode', options:['Bought','Inherited','Gift','Others'], w:'110px'},
    {key:'acq_cost',       label:'Acq. Cost (₱)',            type:'number', w:'140px'},
  ], v);
}
function addPersonal(v) {
  salnAddRow('saln_personal_list', [
    {key:'description', label:'Description',                    w:'200px'},
    {key:'acq_year',    label:'Acquisition Year',  type:'number', w:'130px'},
    {key:'acq_cost',    label:'Acquisition Cost / Amount (₱)', type:'number', w:'220px'},
  ], v);
}
function addLiab(v) {
  salnAddRow('saln_liab_list', [
    {key:'nature',   label:'Nature',            w:'160px'},
    {key:'creditor', label:'Name of Creditor',  w:'200px'},
    {key:'balance',  label:'Outstanding Balance (₱)', type:'number', w:'220px'},
  ], v);
}
function addBiz(v) {
  salnAddRow('saln_biz_list', [
    {key:'entity_name',   label:'Name of Entity / Business Enterprise', w:'220px'},
    {key:'biz_address',   label:'Business Address',                     w:'180px'},
    {key:'nature',        label:'Nature of Interest / Connection',      w:'220px'},
    {key:'date_acquired', label:'Date Acquired', type:'date',           w:'150px'},
  ], v);
}
function addRel(v) {
  salnAddRow('saln_rel_list', [
    {key:'name',           label:'Name of Relative',          w:'180px'},
    {key:'relationship',   label:'Relationship',              w:'130px'},
    {key:'position',       label:'Position',                  w:'160px'},
    {key:'agency_address', label:'Agency / Office & Address', w:'220px'},
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
      const body=new FormData();body.append('action','send');body.append('to',cwActive.user_id);body.append('message',sentMsg);body.append('csrf_token',CSRF_TOKEN);
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
      const body=new FormData();body.append('action','edit');body.append('message_id',msgId);body.append('message',newMsg);body.append('csrf_token',CSRF_TOKEN);
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
    const body=new FormData();body.append('action','unsend');body.append('message_id',msgId);body.append('csrf_token',CSRF_TOKEN);
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