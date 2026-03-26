<?php
// ============================================================
//  LGU ENRILE HRMS — Employees Page
//  File: Admin/employees.php
//  All CRUD operations talk to MySQL via AJAX (fetch API).
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
                        SUM(employment_type   = 'Contractual') AS contractual
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
                        SELECT lt.name, lb.total_credits, lb.used_days, lb.remaining_days
                        FROM   leave_balances lb
                        JOIN   leave_types lt ON lb.leave_type_id = lt.leave_type_id
                        WHERE  lb.employee_id = ? AND lb.calendar_year = YEAR(NOW())
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

                $deptStmt = $pdo->prepare("SELECT department_id FROM departments WHERE name = ? LIMIT 1");
                $deptStmt->execute([trim($_POST['dept'] ?? '')]);
                $deptId = $deptStmt->fetchColumn() ?: null;

                // ── FIX: field keys now match actual DB column names ──
                $fields = [
                    'employee_code'     => trim($_POST['emp_code']    ?? ''),
                    'first_name'        => trim($_POST['first_name']  ?? ''),
                    'last_name'         => trim($_POST['last_name']   ?? ''),
                    'middle_name'       => trim($_POST['middle_name'] ?? ''),
                    'gender'            => $_POST['gender']      ?? 'Male',        // NOT NULL — default to Male
                    'date_of_birth'     => $_POST['dob']         ?: null,
                    'civil_status'      => $_POST['civil']       ?? null,
                    'contact_number'    => trim($_POST['contact']     ?? ''),
                    'email_address'     => trim($_POST['email']       ?? ''),      // FIXED: was 'personal_email'
                    'home_address'      => trim($_POST['address']     ?? ''),      // FIXED: was 'address'
                    'department_id'     => $deptId,
                    'position_title'    => trim($_POST['position']    ?? ''),
                    'employment_type'   => $_POST['emp_type']    ?? 'Permanent',
                    'employment_status' => $_POST['status']      ?? 'Active',
                    'date_hired'        => $_POST['date_hired']  ?: date('Y-m-d'), // NOT NULL — default to today
                    'salary_grade'      => trim($_POST['salary_grade'] ?? ''),
                    'gsis_number'       => trim($_POST['gsis']         ?? ''),
                ];

                if (empty($fields['first_name']) || empty($fields['last_name']) ||
                    empty($fields['employee_code']) || empty($fields['position_title'])) {
                    echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
                    break;
                }

                if ($id) {
                    // Check for duplicate employee_code (excluding current employee)
                    $checkCode = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE employee_code = ? AND employee_id != ?");
                    $checkCode->execute([$fields['employee_code'], $id]);
                    if ($checkCode->fetchColumn() > 0) {
                        echo json_encode(['success' => false, 'message' => 'Employee code already exists. Please use a unique code.']);
                        break;
                    }

                    $setCols = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
                    $stmt    = $pdo->prepare("UPDATE employees SET $setCols WHERE employee_id = ?");
                    $stmt->execute([...array_values($fields), $id]);
                    echo json_encode(['success' => true, 'message' => 'Employee updated successfully.', 'id' => $id]);
                } else {
                    $cols   = implode(', ', array_keys($fields));
                    $marks  = implode(', ', array_fill(0, count($fields), '?'));
                    $stmt   = $pdo->prepare("INSERT INTO employees ($cols) VALUES ($marks)");
                    $stmt->execute(array_values($fields));
                    $newId  = (int)$pdo->lastInsertId();

                    $ltStmt = $pdo->query("SELECT leave_type_id, max_days FROM leave_types WHERE is_active = 1");
                    $lbIns  = $pdo->prepare("
                        INSERT IGNORE INTO leave_balances (employee_id, leave_type_id, calendar_year, total_credits, used_days, remaining_days)
                        VALUES (?, ?, YEAR(NOW()), ?, 0, ?)
                    ");
                    foreach ($ltStmt->fetchAll() as $lt) {
                        $lbIns->execute([$newId, $lt['leave_type_id'], $lt['max_days'], $lt['max_days']]);
                    }

                    echo json_encode(['success' => true, 'message' => 'Employee added successfully.', 'id' => $newId]);
                }
                break;

            // ── DELETE ───────────────────────────────────────
            case 'delete':
                $id = (int)($_POST['id'] ?? 0);
                if (!$id) { echo json_encode(['success' => false, 'message' => 'Invalid ID.']); break; }

                $pdo->prepare("DELETE FROM leave_balances  WHERE employee_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM leave_requests  WHERE employee_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM employees       WHERE employee_id = ?")->execute([$id]);

                echo json_encode(['success' => true, 'message' => 'Employee record deleted.']);
                break;

            // ── DEPARTMENTS LIST (for dropdown) ───────────────
            case 'departments':
                $depts = $pdo->query("SELECT name FROM departments WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
                echo json_encode(['success' => true, 'data' => $depts]);
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        }
    } catch (PDOException $e) {
        error_log('[HRMS] employees.php error: ' . $e->getMessage());
        $errorMsg = 'A database error occurred.';
        if (strpos($e->getMessage(), 'Duplicate entry') !== false || strpos($e->getMessage(), 'UNIQUE') !== false) {
            $errorMsg = '⚠️ Duplicate entry detected. Please check Employee Code or other unique fields.';
        } elseif (strpos($e->getMessage(), 'foreign key') !== false) {
            $errorMsg = '⚠️ Invalid department or other reference. Please try again.';
        }
        echo json_encode(['success' => false, 'message' => $errorMsg]);
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
        <button class="topbar-btn">👤</button>
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
          <option>Permanent</option><option>Contractual</option><option>Casual</option>
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
          <div class="form-group">
            <label>Contact Number</label>
            <input type="text" id="fContact" placeholder="09XX-XXX-XXXX"/>
          </div>
          <div class="form-group">
            <label>Email Address</label>
            <input type="email" id="fEmail" placeholder="email@example.com"/>
          </div>
          <div class="form-group form-full">
            <label>Home Address</label>
            <input type="text" id="fAddress" placeholder="Barangay, Municipality, Province"/>
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
              <option>Permanent</option><option>Contractual</option><option>Casual</option>
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
            <input type="text" id="fSalaryGrade" placeholder="e.g. SG-10"/>
          </div>
          <div class="form-group">
            <label>GSIS / SSS No.</label>
            <input type="text" id="fGsis" placeholder="Government ID No."/>
          </div>
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
        <div class="profile-section-title" style="margin-top:18px;">📅 Leave Balances (<?= date('Y') ?>)</div>
        <div class="lb-row" id="vLeave"></div>
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
  const ini = (fn,ln) => ((fn||'?')[0] + (ln||'?')[0]).toUpperCase();
  const avatarColor = str => COLORS[Math.abs([...str].reduce((a,c)=>a+c.charCodeAt(0),0)) % COLORS.length];

  function statusBadge(s) {
    const map = {Active:'badge-active','On Leave':'badge-onleave',Inactive:'badge-inactive'};
    const ico = {Active:'🟢','On Leave':'🟡',Inactive:'🔴'};
    return `<span class="badge ${map[s]||'badge-active'}">${ico[s]||''} ${s}</span>`;
  }
  function typeBadge(t) {
    const map = {Permanent:'badge-perm',Contractual:'badge-cont',Casual:'badge-cas'};
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
      const av = ini(e.first_name, e.last_name);
      return `
        <tr onclick="viewEmp(${e.employee_id})">
          <td>
            <div class="emp-cell">
              <div class="emp-avatar" style="background:${c};">${av}</div>
              <div>
                <div class="emp-name">${e.first_name} ${e.last_name}</div>
                <div class="emp-id">${e.employee_code}</div>
              </div>
            </div>
          </td>
          <td><span class="badge badge-dept">${e.department || '—'}</span></td>
          <td style="color:var(--gray-600);font-size:0.82rem;">${e.position_title || '—'}</td>
          <td>${typeBadge(e.employment_type)}</td>
          <td style="color:var(--gray-600);font-size:0.82rem;">${e.contact_number || '—'}</td>
          <td style="color:var(--gray-600);font-size:0.82rem;">${fmt(e.date_hired)}</td>
          <td>${statusBadge(e.employment_status)}</td>
          <td onclick="event.stopPropagation()" style="text-align:center;">
            <div class="row-actions" style="justify-content:center;">
              <button class="icon-btn view"   onclick="viewEmp(${e.employee_id})"   title="View">👁️</button>
              <button class="icon-btn edit"   onclick="editEmp(${e.employee_id})"   title="Edit">✏️</button>
              <button class="icon-btn delete" onclick="deleteEmp(${e.employee_id},'${e.first_name} ${e.last_name}','${e.employee_code}')" title="Delete">🗑️</button>
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

  document.querySelectorAll('.stat-chip').forEach(chip => {
    chip.onclick = () => {
      if (chip.hasAttribute('data-filter-all')) {
        document.getElementById('fStatusFilter').value = '';
        document.getElementById('fTypeFilter').value   = '';
      } else if (chip.dataset.status) {
        document.getElementById('fStatusFilter').value = chip.dataset.status;
      } else if (chip.dataset.type) {
        document.getElementById('fTypeFilter').value = chip.dataset.type;
      }
      loadEmployees(1);
    };
  });

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

    const lb = e.leave_balances || [];
    document.getElementById('vLeave').innerHTML = lb.length
      ? lb.map(l => `
          <div class="lb-chip">
            <div class="lb-num">${l.remaining_days}</div>
            <div class="lb-label">${l.name}</div>
          </div>`).join('')
      : '<p style="color:var(--gray-400);font-size:0.82rem;">No leave balances found.</p>';

    openModal('modalView');
  }

  function editFromView() { closeModal('modalView'); editEmp(viewTargetId); }

  function clearForm() {
    ['fId','fFirstName','fLastName','fMiddleName','fContact','fEmail','fAddress',
     'fEmpCode','fPosition','fSalaryGrade','fGsis','fDob','fDateHired'].forEach(id => {
      const el = document.getElementById(id);
      if (el) { el.value = ''; el.classList.remove('err'); }
    });
    ['fGender','fCivil','fDept','fEmpType'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.selectedIndex = 0;
    });
    document.getElementById('fStatus').value = 'Active';
  }

  function openAdd() {
    clearForm();
    document.getElementById('formTitle').textContent = 'Add New Employee';
    document.getElementById('formSub').textContent   = 'Fill in the details below';
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
    document.getElementById('fContact').value     = e.contact_number  || '';
    document.getElementById('fEmail').value       = e.email_address   || '';   // FIXED: was e.personal_email
    document.getElementById('fAddress').value     = e.home_address    || '';   // FIXED: was e.address
    document.getElementById('fEmpCode').value     = e.employee_code  || '';
    document.getElementById('fDept').value        = e.department     || '';
    document.getElementById('fPosition').value    = e.position_title || '';
    document.getElementById('fEmpType').value     = e.employment_type   || '';
    document.getElementById('fStatus').value      = e.employment_status || 'Active';
    document.getElementById('fDateHired').value   = e.date_hired    || '';
    document.getElementById('fSalaryGrade').value = e.salary_grade  || '';
    document.getElementById('fGsis').value        = e.gsis_number   || '';

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
    body.append('contact',      document.getElementById('fContact').value.trim());
    body.append('email',        document.getElementById('fEmail').value.trim());
    body.append('address',      document.getElementById('fAddress').value.trim());
    body.append('emp_code',     document.getElementById('fEmpCode').value.trim());
    body.append('dept',         document.getElementById('fDept').value);
    body.append('position',     document.getElementById('fPosition').value.trim());
    body.append('emp_type',     document.getElementById('fEmpType').value);
    body.append('status',       document.getElementById('fStatus').value);
    body.append('date_hired',   document.getElementById('fDateHired').value);
    body.append('salary_grade', document.getElementById('fSalaryGrade').value.trim());
    body.append('gsis',         document.getElementById('fGsis').value.trim());

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
  }

  init();
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