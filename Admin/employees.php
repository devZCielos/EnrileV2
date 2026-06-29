<?php
// ============================================================
//  LGU ENRILE HRMS — Employees Page
//  File: Admin/employees.php
//  All CRUD operations talk to MySQL via AJAX (fetch API).
// ============================================================
session_start();

// ── Session timeout — 8 hours ─────────────────────────────────
$_sessionLifetime = 8 * 60 * 60;
$_loginTime = isset($_SESSION['login_time'])
    ? (is_numeric($_SESSION['login_time']) ? (int)$_SESSION['login_time'] : (int)strtotime($_SESSION['login_time']))
    : 0;
if ($_loginTime && (time() - $_loginTime > $_sessionLifetime)) {
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

// ── Session guard ─────────────────────────────────────────────
if (empty($_SESSION['user_id']) || empty($_SESSION['user_email'])) {
    header('Location: ../Login/login.php'); exit();
}
$allowedRoles = ['admin', 'hr_staff', 'dept_head'];
if (!in_array($_SESSION['user_role'] ?? '', $allowedRoles)) {
    header('Location: ../Employee/Employee_portal.php'); exit();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';

// ── Auto-migration: add is_mayor column if it doesn't exist yet ──────────
try { $pdo->query("SELECT is_mayor FROM employees LIMIT 0"); }
catch (PDOException $_e) {
    $pdo->exec("ALTER TABLE employees ADD COLUMN is_mayor TINYINT(1) NOT NULL DEFAULT 0");
}

// ════════════════════════════════════════════════════════════
//  AJAX HANDLER  — ?action=...
//  Returns JSON and exits before any HTML is rendered.
// ════════════════════════════════════════════════════════════
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action) {
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_verify(true); }

    try {
        switch ($action) {

            // ── LIST ──────────────────────────────────────────
            case 'list':
                $where  = [];
                $params = [];

                if (!empty($_GET['search'])) {
                    $s = '%' . $_GET['search'] . '%';
                    $where[]  = "(CONCAT(e.first_name,' ',e.last_name) LIKE ? OR e.employee_code LIKE ? OR e.position_title LIKE ?)";
                    $params   = array_merge($params, [$s, $s, $s]);
                }
                if (!empty($_GET['dept']))   { $where[] = 'd.name = ?';                    $params[] = $_GET['dept']; }
                if (!empty($_GET['status'])) { $where[] = 'e.employment_status = ?';       $params[] = $_GET['status']; }
                if (!empty($_GET['type']))   { $where[] = 'e.employment_type = ?';         $params[] = $_GET['type']; }

                $wSql  = $where ? 'WHERE ' . implode(' AND ', $where) : '';

                $sortMap = [
                    'name'   => 'e.last_name, e.first_name',
                    'dept'   => 'd.name',
                    'type'   => 'e.employment_type',
                    'status' => 'e.employment_status',
                    'hired'  => 'e.date_hired',
                ];
                $sortCol = $sortMap[$_GET['sort'] ?? ''] ?? 'e.last_name, e.first_name';
                $sortDir = strtoupper($_GET['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

                $page     = max(1, (int)($_GET['page'] ?? 1));
                $pageSize = 8;
                $offset   = ($page - 1) * $pageSize;

                $cntStmt = $pdo->prepare("
                    SELECT COUNT(*) FROM employees e
                    LEFT JOIN departments d ON e.department_id = d.department_id
                    $wSql
                ");
                $cntStmt->execute($params);
                $total = (int)$cntStmt->fetchColumn();

                // FIXED: use correct DB column names (email_address, home_address)
                $stmt = $pdo->prepare("
                    SELECT e.employee_id, e.employee_code, e.first_name, e.last_name,
                           e.middle_name, e.gender, e.date_of_birth, e.civil_status,
                           e.contact_number, e.email_address, e.home_address,
                           d.name AS department, e.position_title,
                           e.employment_type, e.employment_status,
                           e.date_hired, e.salary_grade, e.gsis_number
                    FROM   employees e
                    LEFT JOIN departments d ON e.department_id = d.department_id
                    $wSql
                    ORDER BY $sortCol $sortDir
                    LIMIT $pageSize OFFSET $offset
                ");
                $stmt->execute($params);
                $rows = $stmt->fetchAll();

                $stats = $pdo->query("
                    SELECT
                        COUNT(*) AS total,
                        SUM(employment_status = 'Active')    AS active,
                        SUM(employment_status = 'On Leave')  AS on_leave,
                        SUM(employment_status = 'Inactive')  AS inactive,
                        SUM(employment_type   = 'Permanent') AS permanent,
                        SUM(employment_type   = 'Contractual') AS contractual,
                        SUM(employment_type   = 'Casual') AS casual,
                        SUM(employment_type   = 'Job Order') AS job_order
                    FROM employees
                ")->fetch();

                echo json_encode([
                    'success' => true,
                    'data'    => $rows,
                    'total'   => $total,
                    'page'    => $page,
                    'pages'   => (int)ceil($total / $pageSize),
                    'stats'   => $stats,
                ]);
                break;

            // ── GET ONE (for edit / view) ─────────────────────
            case 'get':
                $id   = (int)($_GET['id'] ?? 0);
                $stmt = $pdo->prepare("
                    SELECT e.*, d.name AS department
                    FROM   employees e
                    LEFT JOIN departments d ON e.department_id = d.department_id
                    WHERE  e.employee_id = ?
                ");
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if ($row) {
                    $lb = $pdo->prepare("
                    SELECT lt.leave_type_id, lt.name, lt.code, lt.applicable_gender,
                               lb.total_credits, lb.used_days, lb.remaining_days
                        FROM   leave_balances lb
                        JOIN   leave_types lt ON lb.leave_type_id = lt.leave_type_id
                        WHERE  lb.employee_id = ? AND lb.calendar_year = YEAR(NOW())
                        ORDER  BY lt.name ASC
                    ");
                    $lb->execute([$id]);
                    $row['leave_balances'] = $lb->fetchAll();
                    echo json_encode(['success' => true, 'data' => $row]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Employee not found.']);
                }
                break;

            // ── SAVE (add or edit) ────────────────────────────
            case 'save':
                $id = (int)($_POST['employee_id'] ?? 0);

              $contactInPost = array_key_exists('contact', $_POST);
              $emailInPost   = array_key_exists('email', $_POST);
              $addressInPost = array_key_exists('address', $_POST);

                $deptStmt = $pdo->prepare("SELECT department_id FROM departments WHERE name = ? LIMIT 1");
                $deptStmt->execute([trim($_POST['dept'] ?? '')]);
                $deptId = $deptStmt->fetchColumn() ?: null;

                // Require a valid department if one was submitted
                if (!empty($_POST['dept']) && $deptId === false) {
                    echo json_encode(['success' => false, 'message' => 'Invalid department selected.']);
                    break;
                }

                // ── If designating as mayor, clear the flag from all other employees first ──
                if (!empty($_POST['is_mayor'])) {
                    $pdo->exec("UPDATE employees SET is_mayor = 0 WHERE is_mayor = 1");
                }

                // ── FIX: field keys now match actual DB column names ──
                $fields = [
                    'employee_code'     => trim($_POST['emp_code']    ?? ''),
                    'first_name'        => trim($_POST['first_name']  ?? ''),
                    'last_name'         => trim($_POST['last_name']   ?? ''),
                    'middle_name'       => trim($_POST['middle_name'] ?? ''),
                    'gender'            => $_POST['gender']      ?? 'Male',        // NOT NULL — default to Male
                    'date_of_birth'     => $_POST['dob']         ?: null,
                    'civil_status'      => $_POST['civil']       ?? null,
                  'contact_number'    => $contactInPost ? trim($_POST['contact'] ?? '') : null,
                  'email_address'     => $emailInPost   ? trim($_POST['email']   ?? '') : null,
                  'home_address'      => $addressInPost ? trim($_POST['address'] ?? '') : null,
                    'department_id'     => $deptId,
                    'position_title'    => trim($_POST['position']    ?? ''),
                    'employment_type'   => $_POST['emp_type']    ?? 'Permanent',
                    'employment_status' => $_POST['status']      ?? 'Active',
                    'date_hired'        => $_POST['date_hired']  ?: date('Y-m-d'), // NOT NULL — default to today
                    'salary_grade'      => trim($_POST['salary_grade'] ?? ''),
                    'gsis_number'       => trim($_POST['gsis']         ?? ''),
                    'is_mayor'          => !empty($_POST['is_mayor']) ? 1 : 0,
                ];

                if (empty($fields['first_name']) || empty($fields['last_name']) ||
                    empty($fields['employee_code']) || empty($fields['position_title'])) {
                    echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
                    break;
                }

                // Salary grade should be numeric 1-33.
                if ($fields['salary_grade'] !== '') {
                  $sg = (int)$fields['salary_grade'];
                  if ((string)$sg !== $fields['salary_grade'] || $sg < 1 || $sg > 33) {
                    echo json_encode(['success' => false, 'message' => 'Salary grade must be between 1 and 33.']);
                    break;
                  }
                }

                // Email format validation
                if ($emailInPost && !empty($fields['email_address']) && !filter_var($fields['email_address'], FILTER_VALIDATE_EMAIL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid email address format.']);
                    break;
                }

                // New employees must provide an email because account creation is automatic.
                if (!$id && (!$emailInPost || empty($fields['email_address']))) {
                  echo json_encode(['success' => false, 'message' => 'Email address is required when adding an employee.']);
                  break;
                }

                // Input length validation
                $lengthLimits = [
                    'first_name' => 100, 'last_name' => 100, 'middle_name' => 100,
                    'employee_code' => 50, 'position_title' => 150,
                    'contact_number' => 30, 'email_address' => 191,
                    'home_address' => 500, 'salary_grade' => 30, 'gsis_number' => 50,
                ];
                foreach ($lengthLimits as $field => $maxLen) {
                    if (strlen($fields[$field] ?? '') > $maxLen) {
                        echo json_encode(['success' => false, 'message' => ucfirst(str_replace('_', ' ', $field)) . ' is too long (max ' . $maxLen . ' characters).']);
                        break 2;
                    }
                }

                // Do not overwrite employee-managed personal contact details when admin form omits them.
                if ($id) {
                  if (!$contactInPost) unset($fields['contact_number']);
                  if (!$emailInPost)   unset($fields['email_address']);
                  if (!$addressInPost) unset($fields['home_address']);
                }

                // Validate and decode leave_selection
                $leaveSelection = null;
                if (isset($_POST['leave_selection'])) {
                    $decoded = json_decode($_POST['leave_selection'], true);
                    if (!is_array($decoded)) {
                        echo json_encode(['success' => false, 'message' => 'Invalid leave selection data.']);
                        break;
                    }
                    // Ensure all keys are positive integers and values are non-negative numbers
                    $leaveSelection = [];
                    foreach ($decoded as $k => $v) {
                        $k = (int)$k;
                        $v = max(0, (int)$v);
                        if ($k > 0) $leaveSelection[$k] = $v;
                    }
                }

                if ($id) {
                    $setCols = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
                    $stmt    = $pdo->prepare("UPDATE employees SET $setCols WHERE employee_id = ?");
                    $stmt->execute([...array_values($fields), $id]);

                    // P2-9 — Keep user_accounts.email in sync with employees.email_address
                    if ($emailInPost && !empty($fields['email_address'])) {
                        $pdo->prepare("
                            UPDATE user_accounts ua
                            JOIN employees e ON e.user_id = ua.user_id
                            SET ua.email = ?
                            WHERE e.employee_id = ?
                        ")->execute([$fields['email_address'], $id]);
                    }
                    // ── Auto-create contract if employment_type is Contractual/JO/Casual ──
                    if (in_array($fields['employment_type'], ['Contractual', 'Job Order', 'Casual'])) {
                        // Atomic insert: only inserts if no contract exists (avoids race condition)
                        $pdo->prepare("
                            INSERT INTO contracts (employee_id, contract_type, position_title, department_id, start_date, status, created_by)
                            SELECT ?, ?, ?, ?, ?, 'Active', ?
                            FROM DUAL
                            WHERE NOT EXISTS (SELECT 1 FROM contracts WHERE employee_id = ? LIMIT 1)
                        ")->execute([$id, $fields['employment_type'], $fields['position_title'], $deptId, $fields['date_hired'], (int)$_SESSION['user_id'], $id]);
                    }

                        // Update current-year leave entitlements when admin edits leave selection
                        if (is_array($leaveSelection)) {
                          $year = (int)date('Y');
                          // Fetch max_days for capping
                          $ltMaxMap = $pdo->query("SELECT leave_type_id, max_days FROM leave_types")->fetchAll(PDO::FETCH_KEY_PAIR);
                          $upsert = $pdo->prepare("
                            INSERT INTO leave_balances (employee_id, leave_type_id, calendar_year, total_credits, used_days)
                            VALUES (?, ?, ?, ?, 0)
                            ON DUPLICATE KEY UPDATE total_credits = VALUES(total_credits)
                          ");

                          $selectedIds = [];
                          foreach ($leaveSelection as $ltId => $days) {
                            $ltId = (int)$ltId;
                            if (!$ltId) continue;
                            // Cap days at max_days for this leave type
                            $maxDays = isset($ltMaxMap[$ltId]) ? (int)$ltMaxMap[$ltId] : PHP_INT_MAX;
                            $days = min(max(0, (int)$days), $maxDays);
                            $selectedIds[] = $ltId;
                            $upsert->execute([$id, $ltId, $year, $days]);
                          }

                          if ($selectedIds) {
                            $marks = implode(',', array_fill(0, count($selectedIds), '?'));
                            $del = $pdo->prepare("DELETE FROM leave_balances WHERE employee_id = ? AND calendar_year = ? AND leave_type_id NOT IN ($marks)");
                            $del->execute(array_merge([$id, $year], $selectedIds));
                          } else {
                            $del = $pdo->prepare("DELETE FROM leave_balances WHERE employee_id = ? AND calendar_year = ?");
                            $del->execute([$id, $year]);
                          }
                        }
                    
                    echo json_encode(['success' => true, 'message' => 'Employee updated successfully.', 'id' => $id]);
                } else {
                    // Prevent duplicate emails across employee records and user accounts.
                    $dupEmp = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE email_address = ?");
                    $dupEmp->execute([$fields['email_address']]);
                    if ((int)$dupEmp->fetchColumn() > 0) {
                      echo json_encode(['success' => false, 'message' => 'Email is already used by another employee.']);
                      break;
                    }
                    $dupUa = $pdo->prepare("SELECT COUNT(*) FROM user_accounts WHERE email = ?");
                    $dupUa->execute([$fields['email_address']]);
                    if ((int)$dupUa->fetchColumn() > 0) {
                      echo json_encode(['success' => false, 'message' => 'Email is already used by an existing user account.']);
                      break;
                    }

                    $pdo->beginTransaction();

                    $cols   = implode(', ', array_keys($fields));
                    $marks  = implode(', ', array_fill(0, count($fields), '?'));
                    $stmt   = $pdo->prepare("INSERT INTO employees ($cols) VALUES ($marks)");
                    $stmt->execute(array_values($fields));
                    $newId  = (int)$pdo->lastInsertId();

                    // ── Auto-create contract for Contractual/JO/Casual employees ──
                    if (in_array($fields['employment_type'], ['Contractual', 'Job Order', 'Casual'])) {
                        $cIns = $pdo->prepare("
                            INSERT INTO contracts (employee_id, contract_type, position_title, department_id, start_date, status, created_by)
                            VALUES (?, ?, ?, ?, ?, 'Active', ?)
                        ");
                        $cIns->execute([$newId, $fields['employment_type'], $fields['position_title'], $deptId, $fields['date_hired'], (int)$_SESSION['user_id']]);
                    }

                    $ltStmt = $pdo->query("SELECT leave_type_id, max_days FROM leave_types");
                    $lbIns  = $pdo->prepare("
                        INSERT IGNORE INTO leave_balances (employee_id, leave_type_id, calendar_year, total_credits, used_days)
                        VALUES (?, ?, YEAR(NOW()), ?, 0)
                    ");

                    if (is_array($leaveSelection)) {
                        foreach ($leaveSelection as $ltId => $days) {
                            $ltId = (int)$ltId;
                            $days = max(0, (int)$days);
                            if (!$ltId) continue;
                            $lbIns->execute([$newId, $ltId, $days]);
                        }
                    } else {
                        foreach ($ltStmt->fetchAll() as $lt) {
                            $lbIns->execute([$newId, $lt['leave_type_id'], $lt['max_days']]);
                        }
                    }

                    // Auto-create employee user account.
                    $defaultPassword = $fields['employee_code'];
                    $passHash = password_hash($defaultPassword, PASSWORD_BCRYPT);
                    $uaIns = $pdo->prepare("INSERT INTO user_accounts (email, password_hash, role, is_active, created_at, updated_at) VALUES (?, ?, 'employee', 1, NOW(), NOW())");
                    $uaIns->execute([$fields['email_address'], $passHash]);
                    $newUserId = (int)$pdo->lastInsertId();

                    // Link employee to the new user account.
                    $pdo->prepare("UPDATE employees SET user_id = ? WHERE employee_id = ?")->execute([$newUserId, $newId]);
                    $pdo->prepare("UPDATE user_accounts SET must_change_password = 1 WHERE user_id = ?")->execute([$newUserId]);

                    $pdo->commit();

                    echo json_encode([
                      'success' => true,
                      'message' => 'Employee added successfully. User account was created with default password equal to employee code.',
                      'id' => $newId
                    ]);
                }
                break;

            // ── DELETE ───────────────────────────────────────
            case 'delete':
                $id = (int)($_POST['id'] ?? 0);
                if (!$id) { echo json_encode(['success' => false, 'message' => 'Invalid ID.']); break; }

                // Remove all related records before deleting the employee
                $pdo->prepare("DELETE FROM leave_balances  WHERE employee_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM leave_requests  WHERE employee_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM contracts       WHERE employee_id = ?")->execute([$id]);
                // Nullify service_records (may have FK) rather than hard-delete
                $pdo->prepare("DELETE FROM service_records WHERE employee_id = ?")->execute([$id]);
                // Disassociate user account before deleting employee
                $pdo->prepare("UPDATE user_accounts ua JOIN employees e ON e.user_id = ua.user_id SET ua.employee_id = NULL WHERE e.employee_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM employees       WHERE employee_id = ?")->execute([$id]);

                echo json_encode(['success' => true, 'message' => 'Employee record deleted.']);
                break;

            // ── DEPARTMENTS LIST (for dropdown) ───────────────
            case 'departments':
                $depts = $pdo->query("SELECT name FROM departments WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
                echo json_encode(['success' => true, 'data' => $depts]);
                break;

            // ── GENERATE NEXT EMPLOYEE CODE ───────────────────
            case 'next_employee_code':
                // Order by the numeric part so we always get the highest existing code
                $stmt = $pdo->query("
                    SELECT employee_code FROM employees
                    WHERE employee_code REGEXP '^EMP-[0-9]+$'
                    ORDER BY CAST(SUBSTRING(employee_code, 5) AS UNSIGNED) DESC
                    LIMIT 1
                ");
                $lastCode = $stmt->fetchColumn();

                if ($lastCode && preg_match('/EMP-(\d+)/', $lastCode, $m)) {
                    $nextCode = 'EMP-' . str_pad((int)$m[1] + 1, 3, '0', STR_PAD_LEFT);
                } else {
                    $nextCode = 'EMP-001';
                }

                echo json_encode(['success' => true, 'code' => $nextCode]);
                break;

            // ── LEAVE TYPES LIST (for add employee form) ──────
            case 'leave_types':
                $lts = $pdo->query("
                    SELECT leave_type_id, name, code, max_days, applicable_gender
                    FROM   leave_types
                    ORDER  BY name ASC
                ")->fetchAll();
                echo json_encode(['success' => true, 'data' => $lts]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        }
    } catch (PDOException $e) {
      if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
      }
        error_log('[HRMS] employees.php error: ' . $e->getMessage());
        // Return a specific message for common constraint violations
        if (strpos($e->getMessage(), 'employee_code') !== false && strpos($e->getMessage(), '1062') !== false) {
            echo json_encode(['success' => false, 'message' => 'Employee code already exists. Please use a different code.']);
      } elseif (strpos($e->getMessage(), 'email') !== false && strpos($e->getMessage(), '1062') !== false) {
        echo json_encode(['success' => false, 'message' => 'Email is already in use.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'A database error occurred.']);
        }
    }
    exit();
}

// ── Page variables ────────────────────────────────────────────
$adminName = ucwords($_SESSION['user_name'] ?? 'Administrator');
$roleLabels = ['admin' => 'Super Admin', 'hr_staff' => 'HR Staff', 'dept_head' => 'Department Head'];
$roleLabel  = $roleLabels[$_SESSION['user_role']] ?? 'Admin';
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
  <?= csrf_meta() ?>
  <title>Employees | LGU Enrile HRMS</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../css/admin.css" />
  <link rel="stylesheet" href="../css/employees.css" />
  <style>
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
      <a class="nav-item active" href="employees.php"><div class="nav-icon">👥</div>Employees</a>
      <a class="nav-item" href="departments.php"><div class="nav-icon">🏢</div>Departments</a>
      <div class="nav-label">Leave</div>
      <a class="nav-item" href="leave_requests.php"><div class="nav-icon">📋</div>Leave Requests<span class="nav-badge" id="pendingBadge"></span></a>
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
        <h1>Employees</h1>
        <p>Manage all LGU Enrile employee records</p>
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

      <!-- STAT CHIPS -->
      <div class="stats-row" id="statsRow">
        <div class="stat-chip" data-filter-all><div class="dot" style="background:#2563eb;"></div>Total: &nbsp;<strong id="sc-total">—</strong></div>
        <div class="stat-chip" data-status="Active"><div class="dot" style="background:#22c55e;"></div>Active: &nbsp;<strong id="sc-active">—</strong></div>
        <div class="stat-chip" data-status="On Leave"><div class="dot" style="background:#f59e0b;"></div>On Leave: &nbsp;<strong id="sc-onleave">—</strong></div>
        <div class="stat-chip" data-status="Inactive"><div class="dot" style="background:#ef4444;"></div>Inactive: &nbsp;<strong id="sc-inactive">—</strong></div>
        <div class="stat-chip" data-type="Permanent"><div class="dot" style="background:#6366f1;"></div>Permanent: &nbsp;<strong id="sc-perm">—</strong></div>
        <div class="stat-chip" data-type="Contractual"><div class="dot" style="background:#06b6d4;"></div>Contractual: &nbsp;<strong id="sc-cont">—</strong></div>
        <div class="stat-chip" data-type="Casual"><div class="dot" style="background:#f59e0b;"></div>Casual: &nbsp;<strong id="sc-cas">—</strong></div>
        <div class="stat-chip" data-type="Job Order"><div class="dot" style="background:#10b981;"></div>Job Order: &nbsp;<strong id="sc-jo">—</strong></div>
      </div>

      <!-- TOOLBAR -->
      <div class="toolbar">
        <div class="search-box">
          <span style="color:var(--gray-400);font-size:1rem;">🔍</span>
          <input type="text" id="searchInput" placeholder="Search name, ID, position…" oninput="debounceLoad()"/>
        </div>
        <select class="filter-select" id="fDeptFilter" onchange="loadEmployees()">
          <option value="">All Departments</option>
        </select>
        <select class="filter-select" id="fStatusFilter" onchange="loadEmployees()">
          <option value="">All Status</option>
          <option>Active</option><option>On Leave</option><option>Inactive</option>
        </select>
        <select class="filter-select" id="fTypeFilter" onchange="loadEmployees()">
          <option value="">All Types</option>
          <option>Permanent</option><option>Contractual</option><option>Casual</option><option>Job Order</option>
        </select>
        <button class="add-btn" onclick="openAdd()">➕ Add Employee</button>
      </div>

      <!-- TABLE PANEL -->
      <div class="panel">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th onclick="sortBy('name')">Employee <span class="sort-icon" id="sn">↕</span></th>
                <th onclick="sortBy('dept')">Department <span class="sort-icon" id="sd">↕</span></th>
                <th>Position</th>
                <th onclick="sortBy('type')">Type <span class="sort-icon" id="st">↕</span></th>
                <th>Contact</th>
                <th onclick="sortBy('hired')">Date Hired <span class="sort-icon" id="sh">↕</span></th>
                <th onclick="sortBy('status')">Status <span class="sort-icon" id="ss">↕</span></th>
                <th style="text-align:center;">Actions</th>
              </tr>
            </thead>
            <tbody id="empTbody">
              <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--gray-400);">Loading…</td></tr>
            </tbody>
          </table>
          <div class="empty-state" id="emptyState" style="display:none;">
            <div class="empty-icon">🔍</div>
            <p>No employees found matching your search.</p>
          </div>
        </div>
        <div class="pagination">
          <span id="pageInfo" style="color:var(--gray-400);font-weight:600;"></span>
          <div class="page-btns" id="pageBtns"></div>
        </div>
      </div>

    </div>
  </div>

  <!-- ══ ADD / EDIT MODAL ══ -->
  <div class="modal-overlay" id="modalForm">
    <div class="modal" style="width:92%;max-width:620px;max-height:92vh;overflow-y:auto;">
      <div class="modal-header">
        <div><h3 id="formTitle">Add New Employee</h3><p id="formSub">Fill in the details below</p></div>
        <button class="modal-close" onclick="closeModal('modalForm')">✕</button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="fId"/>

        <div class="form-section">👤 Personal Information</div>
        <div class="form-grid">
          <div class="form-group">
            <label>First Name <span class="req">*</span></label>
            <input type="text" id="fFirstName" placeholder="e.g. Maria"/>
          </div>
          <div class="form-group">
            <label>Last Name <span class="req">*</span></label>
            <input type="text" id="fLastName" placeholder="e.g. Reyes"/>
          </div>
          <div class="form-group">
            <label>Middle Name</label>
            <input type="text" id="fMiddleName" placeholder="e.g. Santos"/>
          </div>
          <div class="form-group">
            <label>Gender</label>
            <select id="fGender">
              <option value="">Select</option>
              <option>Male</option><option>Female</option>
            </select>
          </div>
          <div class="form-group">
            <label>Date of Birth</label>
            <input type="date" id="fDob"/>
          </div>
          <div class="form-group">
            <label>Civil Status</label>
            <select id="fCivil">
              <option value="">Select</option>
              <option>Single</option><option>Married</option><option>Widowed</option><option>Separated</option>
            </select>
          </div>
          <div class="form-group form-full">
            <label>Email Address <span class="req">*</span></label>
            <input type="email" id="fEmail" placeholder="email@example.com"/>
          </div>
        </div>

        <div class="form-section">💼 Employment Details</div>
        <div class="form-grid">
          <div class="form-group">
            <label>Employee Code <span class="req">*</span></label>
            <input type="text" id="fEmpCode" placeholder="e.g. EMP-013"/>
          </div>
          <div class="form-group">
            <label>Department <span class="req">*</span></label>
            <select id="fDept">
              <option value="">Select department</option>
            </select>
          </div>
          <div class="form-group form-full">
            <label>Position / Designation <span class="req">*</span></label>
            <input type="text" id="fPosition" placeholder="e.g. Administrative Aide VI"/>
          </div>
          <div class="form-group">
            <label>Employment Type <span class="req">*</span></label>
            <select id="fEmpType">
              <option value="">Select type</option>
              <option>Permanent</option><option>Contractual</option><option>Casual</option><option>Job Order</option>
            </select>
          </div>
          <div class="form-group">
            <label>Status</label>
            <select id="fStatus">
              <option>Active</option><option>On Leave</option><option>Inactive</option>
            </select>
          </div>
          <div class="form-group">
            <label>Date Hired</label>
            <input type="date" id="fDateHired"/>
          </div>
          <div class="form-group">
            <label>Salary Grade</label>
            <select id="fSalaryGrade">
              <option value="">Select grade</option>
              <?php for ($sg = 1; $sg <= 33; $sg++): ?>
                <option value="<?= $sg ?>"><?= $sg ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="form-group">
            <label>GSIS / SSS No.</label>
            <input type="text" id="fGsis" placeholder="Government ID No."/>
          </div>
          <div class="form-group form-full" style="grid-column:1/-1;margin-top:4px;">
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;padding:10px 12px;border:1.5px solid var(--gray-200);border-radius:var(--radius-sm);background:var(--gray-50);">
              <input type="checkbox" id="fIsMayor" style="width:16px;height:16px;cursor:pointer;accent-color:var(--primary);flex-shrink:0;"/>
              <span style="font-weight:700;color:var(--secondary);font-size:.875rem;">&#11088; Designate as Municipal Mayor <small style="font-weight:600;color:var(--gray-400);font-size:.75rem;">(name will appear on leave &amp; service record forms)</small></span>
            </label>
          </div>
        </div>

        <div id="fLeaveSection" style="display:none;">
          <div class="form-section">📅 Leave Entitlements</div>
          <p style="font-size:.78rem;color:var(--gray-400);margin:0 0 10px;">Select the leave types this employee can avail and set the number of days for each.</p>
          <div style="display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-bottom:8px;">
            <button type="button" onclick="setAllLeaves(true)"  style="font-size:.72rem;color:#2563eb;background:none;border:none;cursor:pointer;font-weight:700;padding:2px 0;">✔ Select All</button>
            <button type="button" onclick="setAllLeaves(false)" style="font-size:.72rem;color:#64748b;background:none;border:none;cursor:pointer;font-weight:700;padding:2px 0;">✖ Deselect All</button>
          </div>
          <div id="fLeaveTypes" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:8px;"></div>
        </div>

      </div>
      <div class="modal-footer">
        <button class="btn btn-cancel" onclick="closeModal('modalForm')">Cancel</button>
        <button class="btn btn-primary" id="saveBtn" onclick="saveEmployee()">💾 Save Employee</button>
      </div>
    </div>
  </div>

  <!-- ══ VIEW PROFILE MODAL ══ -->
  <div class="modal-overlay" id="modalView">
    <div class="modal" style="width:92%;max-width:600px;max-height:92vh;overflow-y:auto;">
      <div class="profile-hero">
        <div class="profile-avatar-lg" id="vAvatar"></div>
        <div class="profile-info">
          <h3 id="vName"></h3>
          <p  id="vPos"></p>
          <div class="profile-tags" id="vTags"></div>
        </div>
        <button class="profile-close-btn" onclick="closeModal('modalView')">✕</button>
      </div>
      <div class="profile-body">
        <div class="profile-section-title">👤 Personal Information</div>
        <div class="profile-grid" id="vPersonal"></div>
        <div class="profile-section-title" style="margin-top:18px;">💼 Employment Details</div>
        <div class="profile-grid" id="vEmployment"></div>
        <div class="profile-section-title" style="margin-top:18px;">
          📅 Leave Balances (<span id="vLeaveYear"><?= date('Y') ?></span>)
          <span id="vLeaveGenderTag" style="margin-left:8px;font-size:.70rem;font-weight:700;padding:2px 10px;border-radius:20px;vertical-align:middle;display:none;"></span>
        </div>
        <div id="vLeave"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-cancel" onclick="closeModal('modalView')">Close</button>
        <button class="btn btn-primary" onclick="editFromView()">✏️ Edit Employee</button>
      </div>
    </div>
  </div>

  <!-- ══ DELETE CONFIRM MODAL ══ -->
  <div class="modal-overlay" id="modalDelete">
    <div class="modal sm">
      <div class="modal-header red">
        <div><h3>Delete Employee</h3><p>This cannot be undone</p></div>
        <button class="modal-close" onclick="closeModal('modalDelete')">✕</button>
      </div>
      <div class="delete-body">
        <div class="del-icon">⚠️</div>
        <h4>Are you sure?</h4>
        <p id="delConfirmText"></p>
      </div>
      <div class="modal-footer">
        <button class="btn btn-cancel" onclick="closeModal('modalDelete')">Cancel</button>
        <button class="btn btn-danger"  onclick="confirmDelete()">🗑️ Yes, Delete</button>
      </div>
    </div>
  </div>

  <script>
const CSRF_TOKEN = document.querySelector('meta[name=csrf-token]').content;
  const COLORS = ['#2563eb','#22c55e','#f59e0b','#ef4444','#a855f7','#06b6d4','#ec4899','#0ea5e9','#84cc16','#f97316','#6366f1','#14b8a6'];
  let currentPage  = 1;
  let sortKey      = 'name';
  let sortAsc      = true;
  let viewTargetId = null;
  let deleteTargetId = null;
  let debounceTimer  = null;

  const fmt = d => d ? new Date(d).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'numeric'}) : '—';
  const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  const ini = (fn,ln) => ((fn||'?')[0] + (ln||'?')[0]).toUpperCase();
  const avatarColor = str => COLORS[Math.abs([...str].reduce((a,c)=>a+c.charCodeAt(0),0)) % COLORS.length];

  function statusBadge(s) {
    const map = {Active:'badge-active','On Leave':'badge-onleave',Inactive:'badge-inactive'};
    const ico = {Active:'🟢','On Leave':'🟡',Inactive:'🔴'};
    return `<span class="badge ${map[s]||'badge-active'}">${ico[s]||''} ${s}</span>`;
  }
  function typeBadge(t) {
    const map = {Permanent:'badge-perm',Contractual:'badge-cont',Casual:'badge-cas','Job Order':'badge-jo'};
    return `<span class="badge ${map[t]||'badge-perm'}">${t}</span>`;
  }

  async function loadEmployees(page = currentPage) {
    currentPage = page;
    const params = new URLSearchParams({
      action : 'list',
      page   : currentPage,
      sort   : sortKey,
      dir    : sortAsc ? 'ASC' : 'DESC',
      search : document.getElementById('searchInput').value.trim(),
      dept   : document.getElementById('fDeptFilter').value,
      status : document.getElementById('fStatusFilter').value,
      type   : document.getElementById('fTypeFilter').value,
    });

    try {
      const res  = await fetch(`employees.php?${params}`);
      const json = await res.json();
      if (!json.success) { showToast('⚠️ ' + json.message, 'info'); return; }
      renderTable(json);
      renderStats(json.stats);
      renderPagination(json.page, json.pages, json.total);
    } catch(e) {
      showToast('❌ Failed to load employees.', 'error');
    }
  }

  function debounceLoad() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => loadEmployees(1), 320);
  }

  function renderTable(json) {
    const tbody = document.getElementById('empTbody');
    const empty = document.getElementById('emptyState');

    if (!json.data.length) {
      tbody.innerHTML = '';
      empty.style.display = 'block';
      return;
    }
    empty.style.display = 'none';
    tbody.innerHTML = json.data.map(e => {
      const c  = avatarColor(e.employee_code || e.last_name);
      const av = esc(ini(e.first_name, e.last_name));
      return `
        <tr onclick="viewEmp(${e.employee_id})">
          <td>
            <div class="emp-cell">
              <div class="emp-avatar" style="background:${esc(c)};">${av}</div>
              <div>
                <div class="emp-name">${esc(e.first_name)} ${esc(e.last_name)}</div>
                <div class="emp-id">${esc(e.employee_code)}</div>
              </div>
            </div>
          </td>
          <td><span class="badge badge-dept">${esc(e.department) || '—'}</span></td>
          <td style="color:var(--gray-600);font-size:0.82rem;">${esc(e.position_title) || '—'}</td>
          <td>${typeBadge(esc(e.employment_type))}</td>
          <td style="color:var(--gray-600);font-size:0.82rem;">${esc(e.contact_number) || '—'}</td>
          <td style="color:var(--gray-600);font-size:0.82rem;">${fmt(e.date_hired)}</td>
          <td>${statusBadge(esc(e.employment_status))}</td>
          <td onclick="event.stopPropagation()" style="text-align:center;">
            <div class="row-actions" style="justify-content:center;">
              <button class="icon-btn view"   onclick="viewEmp(${e.employee_id})"   title="View">👁️</button>
              <button class="icon-btn edit"   onclick="editEmp(${e.employee_id})"   title="Edit">✏️</button>
              <button class="icon-btn delete"
                data-empid="${e.employee_id}"
                data-empname="${esc(e.first_name)} ${esc(e.last_name)}"
                data-empcode="${esc(e.employee_code)}"
                onclick="deleteEmp(this.dataset.empid, this.dataset.empname, this.dataset.empcode)"
                title="Delete">🗑️</button>
            </div>
          </td>
        </tr>`;
    }).join('');
  }

  function renderStats(s) {
    document.getElementById('sc-total').textContent    = s.total;
    document.getElementById('sc-active').textContent   = s.active;
    document.getElementById('sc-onleave').textContent  = s.on_leave;
    document.getElementById('sc-inactive').textContent = s.inactive;
    document.getElementById('sc-perm').textContent     = s.permanent;
    document.getElementById('sc-cont').textContent     = s.contractual;
    document.getElementById('sc-cas').textContent      = s.casual;
    document.getElementById('sc-jo').textContent       = s.job_order;
    updateChipHighlights();
  }

  function renderPagination(page, pages, total) {
    const from = total ? (page-1)*8+1 : 0;
    const to   = Math.min(page*8, total);
    document.getElementById('pageInfo').textContent = `Showing ${from}–${to} of ${total} employee${total!==1?'s':''}`;

    const pb = document.getElementById('pageBtns');
    pb.innerHTML = '';
    const mkBtn = (label, pg, disabled=false) => {
      const b = document.createElement('button');
      b.className = 'page-btn' + (pg===page?' active':'');
      b.textContent = label;
      b.disabled = disabled;
      b.onclick = () => loadEmployees(pg);
      pb.appendChild(b);
    };
    mkBtn('‹', page-1, page===1);
    for (let i=1; i<=pages; i++) mkBtn(i, i);
    mkBtn('›', page+1, page>=pages);
  }

  function sortBy(key) {
    if (sortKey===key) sortAsc=!sortAsc; else { sortKey=key; sortAsc=true; }
    ['name','dept','type','hired','status'].forEach(k => {
      const el = document.getElementById('s'+k[0]);
      if (el) el.textContent = sortKey===k ? (sortAsc?'↑':'↓') : '↕';
    });
    loadEmployees(1);
  }

  function updateChipHighlights() {
    const sf = document.getElementById('fStatusFilter').value;
    const tf = document.getElementById('fTypeFilter').value;
    document.querySelectorAll('.stat-chip').forEach(chip => {
      chip.classList.remove('chip-active');
      if (chip.hasAttribute('data-filter-all') && !sf && !tf) chip.classList.add('chip-active');
      if (chip.dataset.status && chip.dataset.status===sf)    chip.classList.add('chip-active');
      if (chip.dataset.type   && chip.dataset.type===tf)      chip.classList.add('chip-active');
    });
  }

  // Use event delegation to avoid duplicate handlers
  const statsRow = document.getElementById('statsRow');
  if (statsRow) {
    statsRow.onclick = (e) => {
      const chip = e.target.closest('.stat-chip');
      if (!chip) return;

      if (chip.hasAttribute('data-filter-all')) {
        document.getElementById('fStatusFilter').value = '';
        document.getElementById('fTypeFilter').value   = '';
      } else if (chip.dataset.status) {
        // Toggle status, but clear type filter (mutual exclusivity)
        const statusFilterEl = document.getElementById('fStatusFilter');
        const newVal = (statusFilterEl.value === chip.dataset.status) ? '' : chip.dataset.status;
        statusFilterEl.value = newVal;
        document.getElementById('fTypeFilter').value = ''; // Clear type filter
      } else if (chip.dataset.type) {
        // Toggle type, but clear status filter (mutual exclusivity)
        const typeFilterEl = document.getElementById('fTypeFilter');
        const newVal = (typeFilterEl.value === chip.dataset.type) ? '' : chip.dataset.type;
        typeFilterEl.value = newVal;
        document.getElementById('fStatusFilter').value = ''; // Clear status filter
      }
      
      updateChipHighlights();
      loadEmployees(1);
    };
  }

  async function viewEmp(id) {
    viewTargetId = id;
    const res  = await fetch(`employees.php?action=get&id=${id}`);
    const json = await res.json();
    if (!json.success) { showToast('⚠️ ' + json.message,'info'); return; }
    const e = json.data;
    const c = avatarColor(e.employee_code || e.last_name);

    document.getElementById('vAvatar').textContent        = ini(e.first_name, e.last_name);
    document.getElementById('vAvatar').style.background   = c;
    document.getElementById('vName').textContent          = `${e.first_name} ${e.middle_name ? e.middle_name+' ' : ''}${e.last_name}`;
    document.getElementById('vPos').textContent           = `${e.position_title} · ${e.department || '—'}`;
    document.getElementById('vTags').innerHTML            =
      `<span class="profile-tag">${e.employee_code}</span><span class="profile-tag">${e.employment_type}</span><span class="profile-tag">${e.employment_status}</span>`;

    // FIXED: use email_address and home_address to match DB columns
    const pInfo = [
      ['Gender', e.gender], ['Date of Birth', fmt(e.date_of_birth)],
      ['Civil Status', e.civil_status], ['Contact No.', e.contact_number],
      ['Email', e.email_address], ['Address', e.home_address],
    ];
    document.getElementById('vPersonal').innerHTML =
      pInfo.map(([l,v]) => `<div class="profile-field"><label>${l}</label><span>${v||'—'}</span></div>`).join('');

    const eInfo = [
      ['Employee Code', e.employee_code], ['Department', e.department],
      ['Position', e.position_title], ['Employment Type', e.employment_type],
      ['Date Hired', fmt(e.date_hired)], ['Salary Grade', e.salary_grade],
      ['GSIS / SSS No.', e.gsis_number], ['Status', e.employment_status],
    ];
    document.getElementById('vEmployment').innerHTML =
      eInfo.map(([l,v]) => `<div class="profile-field"><label>${l}</label><span>${v||'—'}</span></div>`).join('');

    const lb     = e.leave_balances || [];
    const gender = (e.gender || '').toLowerCase(); // 'male', 'female', or ''

    // Show gender tag in section header
    const genderTag = document.getElementById('vLeaveGenderTag');
    if (gender === 'male') {
      genderTag.textContent = '👨 Male';
      genderTag.style.display = 'inline';
      genderTag.style.background = '#dbeafe';
      genderTag.style.color = '#1e40af';
    } else if (gender === 'female') {
      genderTag.textContent = '👩 Female';
      genderTag.style.display = 'inline';
      genderTag.style.background = '#fce7f3';
      genderTag.style.color = '#9d174d';
    } else {
      genderTag.style.display = 'none';
    }

    // Filter: hide leaves that don't apply to this employee's gender
    const filtered = lb.filter(l => {
      const ag = (l.applicable_gender || 'All');
      if (ag === 'All') return true;
      if (ag === 'Male'   && gender === 'male')   return true;
      if (ag === 'Female' && gender === 'female') return true;
      return false;
    });

    const vLeaveEl = document.getElementById('vLeave');
    if (!filtered.length) {
      vLeaveEl.innerHTML = '<p style="color:var(--gray-400);font-size:0.82rem;padding:8px 0;">No leave balances found for this year.</p>';
    } else {
      // Group: universal leaves + gender-specific leaves
      const universal = filtered.filter(l => (l.applicable_gender || 'All') === 'All');
      const specific  = filtered.filter(l => (l.applicable_gender || 'All') !== 'All');

      const renderCard = l => {
        const rem  = parseFloat(l.remaining_days)  || 0;
        const used = parseFloat(l.used_days)        || 0;
        const tot  = parseFloat(l.total_credits)    || 0;
        const pct  = tot > 0 ? Math.min(100, Math.round((used / tot) * 100)) : 0;
        const isLow = rem <= 3 && tot > 0;
        const isOut = rem <= 0 && tot > 0;
        const barColor = isOut ? '#ef4444' : isLow ? '#f59e0b' : '#22c55e';
        const isGenderSpecific = (l.applicable_gender || 'All') !== 'All';
        const gBadge = isGenderSpecific
          ? `<span style="position:absolute;top:8px;right:8px;font-size:.55rem;font-weight:800;padding:1px 6px;border-radius:20px;background:${l.applicable_gender==='Female'?'#fce7f3':'#dbeafe'};color:${l.applicable_gender==='Female'?'#9d174d':'#1e40af'};">${l.applicable_gender==='Female'?'♀':'♂'}</span>`
          : '';
        return `
          <div style="position:relative;background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:12px 14px;display:flex;flex-direction:column;gap:6px;${isLow&&!isOut?'border-color:#fbbf24;':isOut?'border-color:#fca5a5;':''}">
            ${gBadge}
            <div style="font-size:.72rem;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.04em;line-height:1.3;padding-right:${isGenderSpecific?'28px':'0'};">${l.name}</div>
            <div style="display:flex;align-items:baseline;gap:6px;">
              <span style="font-size:1.55rem;font-weight:900;color:${isOut?'#ef4444':isLow?'#f59e0b':'#2563eb'};line-height:1;">${rem}</span>
              <span style="font-size:.68rem;color:#94a3b8;font-weight:600;">/ ${tot} days</span>
            </div>
            <div style="height:4px;background:#f1f5f9;border-radius:4px;overflow:hidden;">
              <div style="height:100%;width:${pct}%;background:${barColor};border-radius:4px;transition:width .4s;"></div>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:.62rem;color:#94a3b8;font-weight:600;">
              <span>${used} used</span>
              <span>${rem <= 0 ? '<span style="color:#ef4444;">Exhausted</span>' : isLow ? '<span style="color:#f59e0b;">Low</span>' : '<span style="color:#22c55e;">Available</span>'}</span>
            </div>
          </div>`;
      };

      let html = '';

      if (universal.length) {
        html += `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin-top:6px;">
          ${universal.map(renderCard).join('')}
        </div>`;
      }

      if (specific.length) {
        const specLabel = gender === 'female' ? '👩 Female-Specific Leaves' : '👨 Male-Specific Leaves';
        html += `<div style="margin-top:14px;">
          <div style="font-size:.68rem;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px;">${specLabel}</div>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;">
            ${specific.map(renderCard).join('')}
          </div>
        </div>`;
      }

      vLeaveEl.innerHTML = html;
    }

    openModal('modalView');
  }

  function editFromView() { closeModal('modalView'); editEmp(viewTargetId); }

  let _cachedLeaveTypes = null;

  async function loadLeaveTypes(selectedLeaves = null) {
    const container = document.getElementById('fLeaveTypes');
    container.innerHTML = '<p style="color:var(--gray-400);font-size:.78rem;grid-column:1/-1;">Loading leave types…</p>';
    try {
      if (!_cachedLeaveTypes) {
        const res  = await fetch('employees.php?action=leave_types');
        const json = await res.json();
        if (!json.success || !json.data.length) {
          container.innerHTML = '<p style="color:var(--gray-400);font-size:.78rem;grid-column:1/-1;">No leave types configured.</p>';
          return;
        }
        _cachedLeaveTypes = json.data;
      }
      const json = { success: true, data: _cachedLeaveTypes };
      if (!json.success || !json.data.length) {
        container.innerHTML = '<p style="color:var(--gray-400);font-size:.78rem;grid-column:1/-1;">No leave types configured.</p>';
        return;
      }
      const hasCustomSelection = !!(selectedLeaves && Object.keys(selectedLeaves).length);
      container.innerHTML = json.data.map(lt => {
        const leaveId = String(lt.leave_type_id);
        const hasExisting = selectedLeaves && Object.prototype.hasOwnProperty.call(selectedLeaves, leaveId);
        const existingDays = hasExisting ? (parseInt(selectedLeaves[leaveId], 10) || 0) : null;
        const checked = hasCustomSelection ? hasExisting : true;
        const dayValue = existingDays !== null ? existingDays : lt.max_days;
        const gBadge = (lt.applicable_gender && lt.applicable_gender !== 'All')
          ? `<span style="font-size:.56rem;font-weight:800;padding:1px 6px;border-radius:20px;background:${lt.applicable_gender==='Female'?'#fce7f3':'#dbeafe'};color:${lt.applicable_gender==='Female'?'#9d174d':'#1e40af'};">${lt.applicable_gender==='Female'?'♀ Female':'♂ Male'}</span>`
          : '';
        return `
          <label class="leave-card" style="display:flex;flex-direction:column;gap:8px;background:${checked?'#eff6ff':'#f8fafc'};border:1.5px solid ${checked?'#2563eb':'#e2e8f0'};border-radius:10px;padding:12px 14px;cursor:pointer;transition:border-color .18s,background .18s;">
            <div style="display:flex;align-items:center;gap:8px;">
              <input type="checkbox" data-id="${lt.leave_type_id}" ${checked ? 'checked' : ''}
                onchange="const c=this.closest('.leave-card');c.style.borderColor=this.checked?'#2563eb':'#e2e8f0';c.style.background=this.checked?'#eff6ff':'#f8fafc';"
                style="width:15px;height:15px;accent-color:#2563eb;cursor:pointer;flex-shrink:0;">
              <span style="font-size:.78rem;font-weight:700;color:#1e293b;flex:1;line-height:1.3;">${lt.name}</span>
              ${gBadge}
            </div>
            <div style="display:flex;align-items:center;gap:6px;padding-left:23px;">
              <input type="number" id="leave_days_${lt.leave_type_id}" value="${dayValue}" min="0" max="999"
                style="width:68px;padding:4px 8px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:.80rem;font-family:'Nunito',sans-serif;font-weight:700;color:#1e293b;outline:none;"
                onfocus="this.style.borderColor='#2563eb'" onblur="this.style.borderColor='#e2e8f0'">
              <span style="font-size:.74rem;color:#64748b;font-weight:600;">days / year</span>
            </div>
          </label>`;
      }).join('');
    } catch(err) {
      _cachedLeaveTypes = null;
      container.innerHTML = '<p style="color:#ef4444;font-size:.78rem;grid-column:1/-1;">Failed to load leave types.</p>';
    }
  }

  function setAllLeaves(checked) {
    document.querySelectorAll('#fLeaveTypes .leave-card input[type=checkbox]').forEach(cb => {
      cb.checked = checked;
      const card = cb.closest('.leave-card');
      card.style.borderColor = checked ? '#2563eb' : '#e2e8f0';
      card.style.background  = checked ? '#eff6ff' : '#f8fafc';
    });
  }

  function clearForm() {
    ['fId','fFirstName','fLastName','fMiddleName','fEmail',
     'fEmpCode','fPosition','fSalaryGrade','fGsis','fDob','fDateHired'].forEach(id => {
      const el = document.getElementById(id);
      if (el) { el.value = ''; el.classList.remove('err'); }
    });
    ['fGender','fCivil','fDept','fEmpType'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.selectedIndex = 0;
    });
    document.getElementById('fStatus').value = 'Active';
    document.getElementById('fIsMayor').checked = false;
    document.getElementById('fLeaveSection').style.display = 'none';
    document.getElementById('fLeaveTypes').innerHTML = '';
  }

  function openAdd() {
    clearForm();
    document.getElementById('formTitle').textContent = 'Add New Employee';
    document.getElementById('formSub').textContent   = 'Fill in the details below';
    
    // Auto-generate next employee code
    (async function() {
      try {
        const res = await fetch('employees.php?action=next_employee_code');
        const json = await res.json();
        if (json.success) {
          document.getElementById('fEmpCode').value = json.code;
        }
      } catch(e) {
        // Silently fail - user can enter code manually if needed
      }
    })();

    openModal('modalForm');
  }

  async function editEmp(id) {
    const res  = await fetch(`employees.php?action=get&id=${id}`);
    const json = await res.json();
    if (!json.success) { showToast('⚠️ ' + json.message, 'info'); return; }
    const e = json.data;

    clearForm();
    document.getElementById('formTitle').textContent = 'Edit Employee';
    document.getElementById('formSub').textContent   = `Editing: ${e.first_name} ${e.last_name}`;

    document.getElementById('fId').value          = e.employee_id;
    document.getElementById('fFirstName').value   = e.first_name    || '';
    document.getElementById('fLastName').value    = e.last_name     || '';
    document.getElementById('fMiddleName').value  = e.middle_name   || '';
    document.getElementById('fGender').value      = e.gender        || '';
    document.getElementById('fDob').value         = e.date_of_birth || '';
    document.getElementById('fCivil').value       = e.civil_status  || '';
    document.getElementById('fEmail').value       = e.email_address || '';
    document.getElementById('fEmpCode').value     = e.employee_code  || '';
    document.getElementById('fDept').value        = e.department     || '';
    document.getElementById('fPosition').value    = e.position_title || '';
    document.getElementById('fEmpType').value     = e.employment_type   || '';
    document.getElementById('fStatus').value      = e.employment_status || 'Active';
    document.getElementById('fDateHired').value   = e.date_hired    || '';
    const sgRaw = String(e.salary_grade || '').trim();
    const sgNum = sgRaw.startsWith('SG-') ? sgRaw.substring(3) : sgRaw;
    document.getElementById('fSalaryGrade').value = (/^\d+$/.test(sgNum) ? sgNum : '');
    document.getElementById('fGsis').value        = e.gsis_number   || '';
    document.getElementById('fIsMayor').checked   = !!parseInt(e.is_mayor || 0);

    const leaveSelection = {};
    (e.leave_balances || []).forEach(lb => {
      if (lb.leave_type_id !== undefined && lb.leave_type_id !== null) {
        leaveSelection[String(lb.leave_type_id)] = parseInt(lb.total_credits, 10) || 0;
      }
    });
    loadLeaveTypes(leaveSelection);

    openModal('modalForm');
  }

  async function saveEmployee() {
    const required = ['fFirstName','fLastName','fEmpCode','fPosition','fEmpType'];
    let valid = true;
    required.forEach(id => {
      const el = document.getElementById(id);
      if (!el.value.trim()) { el.classList.add('err'); valid = false; }
      else el.classList.remove('err');
    });
    if (!valid) { showToast('⚠️ Please fill in all required fields.','info'); return; }

    const isAddMode = !document.getElementById('fId').value;
    const emailVal = document.getElementById('fEmail').value.trim();
    if (isAddMode && !emailVal) {
      showToast('⚠️ Email address is required when adding an employee.','info');
      document.getElementById('fEmail').classList.add('err');
      return;
    }
    document.getElementById('fEmail').classList.remove('err');

    const btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.textContent = '⏳ Saving…';

    const body = new FormData();
    body.append('action',       'save');
    body.append('employee_id',  document.getElementById('fId').value);
    body.append('first_name',   document.getElementById('fFirstName').value.trim());
    body.append('last_name',    document.getElementById('fLastName').value.trim());
    body.append('middle_name',  document.getElementById('fMiddleName').value.trim());
    body.append('gender',       document.getElementById('fGender').value);
    body.append('dob',          document.getElementById('fDob').value);
    body.append('civil',        document.getElementById('fCivil').value);
    body.append('email',        document.getElementById('fEmail').value.trim());
    body.append('emp_code',     document.getElementById('fEmpCode').value.trim());
    body.append('dept',         document.getElementById('fDept').value);
    body.append('position',     document.getElementById('fPosition').value.trim());
    body.append('emp_type',     document.getElementById('fEmpType').value);
    body.append('status',       document.getElementById('fStatus').value);
    body.append('date_hired',   document.getElementById('fDateHired').value);
    body.append('salary_grade', document.getElementById('fSalaryGrade').value);
    body.append('gsis',         document.getElementById('fGsis').value.trim());
    body.append('is_mayor',     document.getElementById('fIsMayor').checked ? '1' : '0');

    // Collect leave entitlements when leave cards are loaded in the modal
    const leaveSectionEl = document.getElementById('fLeaveSection');
    const leaveCardEls = document.querySelectorAll('#fLeaveTypes .leave-card input[type=checkbox]');
    if (leaveSectionEl && leaveSectionEl.style.display !== 'none' && leaveCardEls.length) {
      const leaveSelection = {};
      document.querySelectorAll('#fLeaveTypes .leave-card input[type=checkbox]:checked').forEach(cb => {
        const tid = cb.dataset.id;
        const dEl = document.getElementById('leave_days_' + tid);
        leaveSelection[tid] = dEl ? (parseInt(dEl.value) || 0) : 0;
      });
      body.append('leave_selection', JSON.stringify(leaveSelection));
    }

    try {
      body.append('csrf_token', CSRF_TOKEN);
      const res  = await fetch('employees.php', { method:'POST', body });
      const json = await res.json();
      if (json.success) {
        showToast('✅ ' + json.message, 'success');
        closeModal('modalForm');
        loadEmployees(currentPage);
      } else {
        showToast('❌ ' + json.message, 'error');
      }
    } catch(e) {
      showToast('❌ Failed to save. Please try again.', 'error');
    } finally {
      btn.disabled = false;
      btn.textContent = '💾 Save Employee';
    }
  }

  function deleteEmp(id, name, code) {
    deleteTargetId = id;
    document.getElementById('delConfirmText').textContent =
      `You are about to permanently delete the record of ${name} (${code}). This cannot be undone.`;
    openModal('modalDelete');
  }

  async function confirmDelete() {
    const body = new FormData();
    body.append('action', 'delete');
    body.append('id',     deleteTargetId);
    try {
      body.append('csrf_token', CSRF_TOKEN);
      const res  = await fetch('employees.php', { method:'POST', body });
      const json = await res.json();
      if (json.success) {
        showToast('🗑️ ' + json.message, 'error');
        closeModal('modalDelete');
        loadEmployees(1);
      } else {
        showToast('❌ ' + json.message, 'error');
      }
    } catch(e) {
      showToast('❌ Delete failed.', 'error');
    }
  }

  function openModal(id)  { document.getElementById(id).classList.add('open'); }
  function closeModal(id) { document.getElementById(id).classList.remove('open'); }
  document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', e => { if (e.target===m) m.classList.remove('open'); });
  });

  function showToast(msg, type='success') {
    document.querySelectorAll('.toast').forEach(t => t.remove());
    const t = document.createElement('div');
    t.className = `toast ${type}`;
    t.innerHTML = msg;
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity='0'; t.style.transition='opacity 0.3s'; setTimeout(()=>t.remove(),300); }, 3200);
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

  async function init() {
    const res  = await fetch('employees.php?action=departments');
    const json = await res.json();
    if (json.success) {
      const opts = json.data.map(d => `<option>${d}</option>`).join('');
      document.getElementById('fDeptFilter').innerHTML += opts;
      document.getElementById('fDept').innerHTML       += opts;
    }

    try {
      const lr = await fetch('leave_requests.php?action=pending_count');
      const lj = await lr.json();
      if (lj.count > 0) {
        document.getElementById('pendingBadge').textContent  = lj.count;
        /* topNotifBadge removed */
      }
    } catch(e) { /* badge is optional */ }

    loadEmployees(1);
    updateChipHighlights();
  }

  init();
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
  </script>

</body>
</html>