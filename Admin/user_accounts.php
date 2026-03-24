<?php
// ============================================================
//  LGU ENRILE HRMS — User Accounts Page
//  File: Admin/user_accounts.php
// ============================================================
session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['user_email'])) {
    header('Location: ../Login/login.php'); exit();
}
$allowedRoles = ['admin'];
if (!in_array($_SESSION['user_role'] ?? '', $allowedRoles)) {
    header('Location: ../Employee/Employee_portal.php'); exit();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';

// ════════════════════════════════════════════════════════════
//  AJAX HANDLER
// ════════════════════════════════════════════════════════════
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action) {
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_verify(true); }

    try {
        switch ($action) {

            // ── LIST ─────────────────────────────────────────
            case 'list':
                $search = '%' . trim($_GET['search'] ?? '') . '%';
                $role   = $_GET['role']   ?? '';
                $status = $_GET['status'] ?? '';

                $where  = ["(ua.email LIKE ? OR CONCAT(e.first_name,' ',e.last_name) LIKE ?)"];
                $params = [$search, $search];

                if ($role)   { $where[] = 'ua.role = ?';      $params[] = $role; }
                if ($status !== '') { $where[] = 'ua.is_active = ?'; $params[] = (int)$status; }

                $wSql = 'WHERE ' . implode(' AND ', $where);

                $page     = max(1, (int)($_GET['page'] ?? 1));
                $pageSize = 10;
                $offset   = ($page - 1) * $pageSize;

                $cntStmt = $pdo->prepare("
                    SELECT COUNT(*) FROM user_accounts ua
                    LEFT JOIN employees e ON e.user_id = ua.user_id
                    $wSql
                ");
                $cntStmt->execute($params);
                $total = (int)$cntStmt->fetchColumn();

                $stmt = $pdo->prepare("
                    SELECT ua.user_id, ua.email, ua.role, ua.is_active,
                           ua.last_login, ua.created_at,
                           e.employee_id, e.first_name, e.last_name,
                           e.position_title, e.employee_code
                    FROM   user_accounts ua
                    LEFT JOIN employees e ON e.user_id = ua.user_id
                    $wSql
                    ORDER  BY ua.created_at DESC
                    LIMIT  $pageSize OFFSET $offset
                ");
                $stmt->execute($params);
                $rows = $stmt->fetchAll();

                // Stats
                $stats = $pdo->query("
                    SELECT
                        COUNT(*)                        AS total,
                        SUM(is_active = 1)              AS active,
                        SUM(is_active = 0)              AS inactive,
                        SUM(role = 'admin')             AS admins,
                        SUM(role = 'hr_staff')          AS hr_staff,
                        SUM(role = 'dept_head')         AS dept_heads,
                        SUM(role = 'employee')          AS employees
                    FROM user_accounts
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

            // ── EMPLOYEES WITHOUT ACCOUNT (for dropdown) ─────
            case 'unlinked_employees':
                $rows = $pdo->query("
                    SELECT e.employee_id,
                           CONCAT(e.first_name,' ',e.last_name) AS full_name,
                           e.employee_code, e.position_title
                    FROM   employees e
                    WHERE  e.user_id IS NULL
                    ORDER  BY e.first_name, e.last_name
                ")->fetchAll();
                echo json_encode(['success' => true, 'data' => $rows]);
                break;

            // ── GET ONE ──────────────────────────────────────
            case 'get':
                $id   = (int)($_GET['id'] ?? 0);
                $stmt = $pdo->prepare("
                    SELECT ua.*, e.employee_id,
                           CONCAT(e.first_name,' ',e.last_name) AS full_name,
                           e.employee_code, e.position_title
                    FROM   user_accounts ua
                    LEFT JOIN employees e ON e.user_id = ua.user_id
                    WHERE  ua.user_id = ?
                ");
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                echo $row
                    ? json_encode(['success' => true, 'data' => $row])
                    : json_encode(['success' => false, 'message' => 'User not found.']);
                break;

            // ── SAVE (add or edit) ────────────────────────────
            case 'save':
                $id          = (int)($_POST['user_id'] ?? 0);
                $email       = trim($_POST['email']       ?? '');
                $role        = trim($_POST['role']        ?? 'employee');
                $employeeId  = (int)($_POST['employee_id'] ?? 0);
                $password    = trim($_POST['password']    ?? '');
                $isActive    = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;

                if (empty($email)) {
                    echo json_encode(['success' => false, 'message' => 'Email is required.']);
                    break;
                }

                // Check email uniqueness
                $chk = $pdo->prepare("SELECT user_id FROM user_accounts WHERE email = ? AND user_id != ?");
                $chk->execute([$email, $id]);
                if ($chk->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Email is already in use.']);
                    break;
                }

                if ($id) {
                    // UPDATE
                    if (!empty($password)) {
                        $hash = password_hash($password, PASSWORD_BCRYPT);
                        $stmt = $pdo->prepare("UPDATE user_accounts SET email=?, role=?, is_active=?, password_hash=?, updated_at=NOW() WHERE user_id=?");
                        $stmt->execute([$email, $role, $isActive, $hash, $id]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE user_accounts SET email=?, role=?, is_active=?, updated_at=NOW() WHERE user_id=?");
                        $stmt->execute([$email, $role, $isActive, $id]);
                    }

                    // Re-link employee if changed
                    // First unlink any employee currently pointing to this user
                    $pdo->prepare("UPDATE employees SET user_id = NULL WHERE user_id = ?")->execute([$id]);
                    if ($employeeId) {
                        $pdo->prepare("UPDATE employees SET user_id = ? WHERE employee_id = ?")->execute([$id, $employeeId]);
                    }

                    echo json_encode(['success' => true, 'message' => 'Account updated successfully.', 'id' => $id]);

                } else {
                    // INSERT — password required for new accounts
                    if (empty($password)) {
                        echo json_encode(['success' => false, 'message' => 'Password is required for new accounts.']);
                        break;
                    }
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("INSERT INTO user_accounts (email, password_hash, role, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
                    $stmt->execute([$email, $hash, $role, $isActive]);
                    $newId = (int)$pdo->lastInsertId();

                    // Link to employee
                    if ($employeeId) {
                        $pdo->prepare("UPDATE employees SET user_id = ? WHERE employee_id = ?")->execute([$newId, $employeeId]);
                    }

                    echo json_encode(['success' => true, 'message' => 'Account created successfully.', 'id' => $newId]);
                }
                break;

            // ── TOGGLE ACTIVE ─────────────────────────────────
            case 'toggle_active':
                $id = (int)($_POST['id'] ?? 0);
                if (!$id) { echo json_encode(['success' => false, 'message' => 'Invalid ID.']); break; }
                $stmt = $pdo->prepare("UPDATE user_accounts SET is_active = NOT is_active, updated_at = NOW() WHERE user_id = ?");
                $stmt->execute([$id]);
                $newStatus = $pdo->prepare("SELECT is_active FROM user_accounts WHERE user_id = ?");
                $newStatus->execute([$id]);
                $active = (int)$newStatus->fetchColumn();
                echo json_encode(['success' => true, 'is_active' => $active, 'message' => $active ? 'Account activated.' : 'Account deactivated.']);
                break;

            // ── RESET PASSWORD ────────────────────────────────
            case 'reset_password':
                $id       = (int)($_POST['id'] ?? 0);
                $password = trim($_POST['password'] ?? '');
                if (!$id || empty($password)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid request.']); break;
                }
                if (strlen($password) < 6) {
                    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']); break;
                }
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $pdo->prepare("UPDATE user_accounts SET password_hash = ?, updated_at = NOW() WHERE user_id = ?")->execute([$hash, $id]);
                echo json_encode(['success' => true, 'message' => 'Password reset successfully.']);
                break;

            // ── DELETE ───────────────────────────────────────
            case 'delete':
                $id = (int)($_POST['id'] ?? 0);
                if (!$id) { echo json_encode(['success' => false, 'message' => 'Invalid ID.']); break; }
                // Prevent self-deletion
                if ($id === (int)$_SESSION['user_id']) {
                    echo json_encode(['success' => false, 'message' => 'You cannot delete your own account.']); break;
                }
                $pdo->prepare("UPDATE employees SET user_id = NULL WHERE user_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM user_accounts WHERE user_id = ?")->execute([$id]);
                echo json_encode(['success' => true, 'message' => 'Account deleted.']);
                break;

            // ── PENDING LEAVE COUNT ───────────────────────────
            case 'pending_count':
                $count = $pdo->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'Pending'")->fetchColumn();
                echo json_encode(['count' => (int)$count]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        }
    } catch (PDOException $e) {
        error_log('[HRMS] user_accounts.php error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A database error occurred.']);
    }
    exit();
}

$adminName  = ucwords($_SESSION['user_name'] ?? 'Administrator');
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
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <?= csrf_meta() ?>
  <title>User Accounts | LGU Enrile HRMS</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../css/admin.css"/>
  <style>
    :root {
      --primary:#2563eb; --primary-dark:#1e40af; --accent:#22c55e;
      --secondary:#0f172a; --sidebar-w:265px; --topbar-h:68px;
      --gray-50:#f8fafc; --gray-100:#f1f5f9; --gray-200:#e2e8f0;
      --gray-400:#94a3b8; --gray-600:#475569; --gray-800:#1e293b;
      --white:#ffffff;
      --shadow-sm:0 1px 4px rgba(0,0,0,0.07);
      --shadow-md:0 4px 18px rgba(0,0,0,0.11);
      --shadow-lg:0 8px 32px rgba(0,0,0,0.16);
      --radius:14px; --radius-sm:9px;
    }
    *{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'Nunito',sans-serif;background:var(--gray-100);color:var(--secondary);display:flex;min-height:100vh;}

    /* SIDEBAR */
    .sidebar{width:var(--sidebar-w);background:linear-gradient(180deg,#2563eb 0%,#1e40af 55%,#22c55e 100%);display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:200;transition:transform 0.3s;box-shadow:4px 0 28px rgba(0,0,0,0.20);}
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

    /* MAIN */
    .main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;}
    .topbar{height:var(--topbar-h);background:linear-gradient(135deg,#2563eb 0%,#1e40af 50%,#22c55e 100%);display:flex;align-items:center;padding:0 28px;gap:16px;position:sticky;top:0;z-index:100;box-shadow:0 2px 16px rgba(37,99,235,0.30);}
    .menu-toggle{display:none;background:none;border:none;font-size:1.4rem;cursor:pointer;color:rgba(255,255,255,0.85);padding:4px;}
    .topbar-title{flex:1;}
    .topbar-title h1{font-size:1.20rem;font-weight:800;color:#fff;font-family:'Playfair Display',serif;}
    .topbar-title p{font-size:0.76rem;color:rgba(255,255,255,0.70);margin-top:1px;}
    .topbar-actions{display:flex;align-items:center;gap:8px;}
    .topbar-btn{position:relative;width:38px;height:38px;border-radius:50%;border:1px solid rgba(255,255,255,0.25);background:rgba(255,255,255,0.15);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:1rem;color:#fff;transition:all 0.2s;}
    .topbar-btn:hover{background:rgba(255,255,255,0.28);}
    .notif-badge{position:absolute;top:-2px;right:-2px;width:16px;height:16px;background:#ef4444;border-radius:50%;font-size:0.58rem;color:white;font-weight:800;display:flex;align-items:center;justify-content:center;border:2px solid rgba(255,255,255,0.3);}

    /* CONTENT */
    .content{padding:24px 28px;flex:1;}

    /* STATS */
    .stats-row{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;}
    .stat-chip{display:flex;align-items:center;gap:8px;padding:8px 16px;background:var(--white);border:1.5px solid var(--gray-200);border-radius:30px;font-size:0.78rem;font-weight:700;color:var(--gray-600);box-shadow:var(--shadow-sm);}
    .stat-chip .dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}
    .stat-chip strong{color:var(--gray-800);font-size:0.95rem;}

    /* TOOLBAR */
    .toolbar{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap;}
    .search-box{display:flex;align-items:center;gap:8px;background:var(--white);border:1.5px solid var(--gray-200);border-radius:var(--radius-sm);padding:9px 14px;flex:1;min-width:200px;max-width:300px;box-shadow:var(--shadow-sm);transition:border-color 0.2s;}
    .search-box:focus-within{border-color:var(--primary);}
    .search-box input{border:none;outline:none;font-family:'Nunito',sans-serif;font-size:0.875rem;width:100%;background:transparent;color:var(--gray-800);}
    .search-box input::placeholder{color:var(--gray-400);}
    .filter-select{padding:9px 12px;border:1.5px solid var(--gray-200);border-radius:var(--radius-sm);font-family:'Nunito',sans-serif;font-size:0.82rem;color:var(--gray-600);background:var(--white);cursor:pointer;box-shadow:var(--shadow-sm);}
    .add-btn{margin-left:auto;display:flex;align-items:center;gap:7px;padding:9px 20px;background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:white;border:none;border-radius:var(--radius-sm);font-family:'Nunito',sans-serif;font-size:0.875rem;font-weight:700;cursor:pointer;transition:all 0.2s;box-shadow:0 2px 8px rgba(37,99,235,0.30);white-space:nowrap;}
    .add-btn:hover{transform:translateY(-2px);box-shadow:0 4px 14px rgba(37,99,235,0.40);}

    /* TABLE */
    .panel{background:var(--white);border-radius:var(--radius);border:1.5px solid var(--gray-200);box-shadow:var(--shadow-sm);overflow:hidden;}
    .table-wrap{overflow-x:auto;}
    table{width:100%;border-collapse:collapse;}
    thead tr{background:var(--gray-50);border-bottom:2px solid var(--gray-200);}
    th{padding:11px 14px;text-align:left;font-size:0.72rem;font-weight:800;color:var(--gray-400);text-transform:uppercase;letter-spacing:0.07em;white-space:nowrap;}
    tbody tr{border-bottom:1px solid var(--gray-100);transition:background 0.15s;cursor:default;}
    tbody tr:hover{background:var(--gray-50);}
    td{padding:12px 14px;font-size:0.84rem;color:var(--gray-600);}
    .user-cell{display:flex;align-items:center;gap:10px;}
    .u-avatar{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:0.78rem;font-weight:800;color:white;flex-shrink:0;}
    .u-name{font-weight:700;color:var(--gray-800);font-size:0.875rem;}
    .u-email{font-size:0.74rem;color:var(--gray-400);}

    /* BADGES */
    .badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:0.70rem;font-weight:700;}
    .badge-admin    {background:#dbeafe;color:#1e40af;}
    .badge-hr       {background:#f3e8ff;color:#6b21a8;}
    .badge-depthead {background:#fef3c7;color:#92400e;}
    .badge-employee {background:#dcfce7;color:#166534;}
    .badge-active   {background:#dcfce7;color:#166534;}
    .badge-inactive {background:#fee2e2;color:#991b1b;}

    /* ACTIONS */
    .row-actions{display:flex;gap:5px;justify-content:center;}
    .icon-btn{width:30px;height:30px;border-radius:7px;border:1.5px solid var(--gray-200);background:var(--white);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:0.82rem;transition:all 0.2s;}
    .icon-btn.edit:hover  {border-color:#f59e0b;background:#fef3c7;}
    .icon-btn.reset:hover {border-color:#2563eb;background:#dbeafe;}
    .icon-btn.toggle:hover{border-color:#22c55e;background:#dcfce7;}
    .icon-btn.delete:hover{border-color:#ef4444;background:#fee2e2;}

    /* PAGINATION */
    .pagination{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-top:1px solid var(--gray-100);flex-wrap:wrap;gap:8px;}
    .page-btns{display:flex;gap:5px;}
    .page-btn{width:32px;height:32px;border-radius:7px;border:1.5px solid var(--gray-200);background:var(--white);font-family:'Nunito',sans-serif;font-size:0.80rem;font-weight:700;cursor:pointer;transition:all 0.2s;color:var(--gray-600);}
    .page-btn:hover{border-color:var(--primary);color:var(--primary);}
    .page-btn.active{background:var(--primary);color:white;border-color:var(--primary);}
    .page-btn:disabled{opacity:0.35;cursor:not-allowed;}

    /* EMPTY */
    .empty-row td{text-align:center;padding:50px;color:var(--gray-400);font-weight:600;}

    /* MODALS */
    .modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:500;align-items:center;justify-content:center;backdrop-filter:blur(4px);}
    .modal-overlay.open{display:flex;}
    .modal{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow-lg);width:92%;max-width:500px;max-height:92vh;overflow-y:auto;animation:modalIn 0.3s cubic-bezier(0.34,1.4,0.64,1) both;}
    .modal.sm{max-width:420px;}
    @keyframes modalIn{from{opacity:0;transform:scale(0.88) translateY(20px);}to{opacity:1;transform:scale(1) translateY(0);}}
    .modal-header{display:flex;align-items:center;justify-content:space-between;padding:20px 24px 16px;background:linear-gradient(135deg,#2563eb,#1e40af);border-radius:var(--radius) var(--radius) 0 0;}
    .modal-header.red{background:linear-gradient(135deg,#ef4444,#dc2626);}
    .modal-header.amber{background:linear-gradient(135deg,#f59e0b,#d97706);}
    .modal-header h3{font-size:1.05rem;font-weight:800;color:#fff;font-family:'Playfair Display',serif;}
    .modal-header p{font-size:0.74rem;color:rgba(255,255,255,0.65);margin-top:2px;}
    .modal-close{width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,0.18);border:none;color:#fff;font-size:1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background 0.2s;flex-shrink:0;}
    .modal-close:hover{background:rgba(255,255,255,0.32);}
    .modal-body{padding:22px 24px;}
    .modal-footer{display:flex;justify-content:flex-end;gap:10px;padding:14px 24px 18px;border-top:1px solid var(--gray-100);}

    /* FORM */
    .form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:14px;}
    .form-group:last-child{margin-bottom:0;}
    .form-group label{font-size:0.76rem;font-weight:700;color:var(--gray-600);}
    .form-group label .req{color:#ef4444;margin-left:2px;}
    .form-group input,.form-group select{padding:9px 12px;border:1.5px solid var(--gray-200);border-radius:var(--radius-sm);font-size:0.875rem;font-family:'Nunito',sans-serif;color:var(--gray-800);background:var(--white);transition:border-color 0.2s;outline:none;}
    .form-group input:focus,.form-group select:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(37,99,235,0.10);}
    .form-group input.err,.form-group select.err{border-color:#ef4444;}
    .form-hint{font-size:0.70rem;color:var(--gray-400);margin-top:2px;}
    .form-section{font-size:0.72rem;font-weight:800;color:var(--gray-400);text-transform:uppercase;letter-spacing:0.10em;padding:8px 0 4px;margin-bottom:6px;border-bottom:1px solid var(--gray-100);}

    /* TOGGLE SWITCH */
    .toggle-wrap{display:flex;align-items:center;gap:10px;}
    .toggle{position:relative;width:42px;height:24px;}
    .toggle input{opacity:0;width:0;height:0;}
    .slider{position:absolute;cursor:pointer;inset:0;background:var(--gray-200);border-radius:24px;transition:0.3s;}
    .slider:before{content:'';position:absolute;width:18px;height:18px;left:3px;bottom:3px;background:white;border-radius:50%;transition:0.3s;}
    input:checked + .slider{background:var(--accent);}
    input:checked + .slider:before{transform:translateX(18px);}

    /* DELETE / RESET MODAL BODY */
    .confirm-body{padding:30px 24px;text-align:center;}
    .confirm-body .conf-icon{font-size:2.8rem;margin-bottom:12px;}
    .confirm-body h4{font-size:1rem;font-weight:800;color:var(--gray-800);margin-bottom:8px;}
    .confirm-body p{font-size:0.84rem;color:var(--gray-600);line-height:1.6;}

    /* BUTTONS */
    .btn{padding:9px 22px;border-radius:var(--radius-sm);font-family:'Nunito',sans-serif;font-size:0.875rem;font-weight:700;cursor:pointer;transition:all 0.2s;border:none;}
    .btn-cancel{background:var(--white);border:1.5px solid var(--gray-200);color:var(--gray-600);}
    .btn-cancel:hover{border-color:var(--gray-400);background:var(--gray-50);}
    .btn-primary{background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:white;box-shadow:0 2px 8px rgba(37,99,235,0.28);}
    .btn-primary:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(37,99,235,0.38);}
    .btn-danger{background:linear-gradient(135deg,#ef4444,#dc2626);color:white;}
    .btn-danger:hover{transform:translateY(-1px);}
    .btn-amber{background:linear-gradient(135deg,#f59e0b,#d97706);color:white;}
    .btn-amber:hover{transform:translateY(-1px);}

    /* TOAST */
    .toast{position:fixed;bottom:28px;right:28px;padding:12px 20px;border-radius:var(--radius-sm);font-size:0.84rem;font-weight:700;box-shadow:var(--shadow-lg);z-index:900;color:white;animation:fadeIn 0.3s ease both;max-width:340px;}
    .toast.success{background:linear-gradient(135deg,#22c55e,#16a34a);}
    .toast.error{background:linear-gradient(135deg,#ef4444,#dc2626);}
    .toast.info{background:linear-gradient(135deg,#2563eb,#1e40af);}
    @keyframes fadeIn{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);}}

    .overlay-mob{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:150;}
    .overlay-mob.show{display:block;}
    @media(max-width:768px){
      .sidebar{transform:translateX(-100%);}
      .sidebar.open{transform:translateX(0);}
      .main{margin-left:0;}
      .menu-toggle{display:block;}
      .content{padding:16px;}
      .topbar{padding:0 16px;}
      .add-btn{margin-left:0;}
    }
  
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
      <a class="nav-item" href="employees.php"><div class="nav-icon">👥</div>Employees</a>
      <a class="nav-item" href="departments.php"><div class="nav-icon">🏢</div>Departments</a>
      <div class="nav-label">Leave</div>
      <a class="nav-item" href="leave_requests.php"><div class="nav-icon">📋</div>Leave Requests<span class="nav-badge" id="pendingBadge"></span></a>
      <a class="nav-item" href="leave_types.php"><div class="nav-icon">📁</div>Leave Types</a>
      <a class="nav-item" href="leave_balances.php"><div class="nav-icon">⚖️</div>Leave Balances</a>
      <div class="nav-label">Documents</div>
      <a class="nav-item" href="documents.php"><div class="nav-icon">📄</div>HR Documents</a>
      <a class="nav-item" href="pds_saln.php"><div class="nav-icon">🆔</div>PDS &amp; SALN</a>
      <a class="nav-item" href="service_records.php"><div class="nav-icon">📜</div>Service Records</a>
      <a class="nav-item" href="contracts.php"><div class="nav-icon">📝</div>Contracts</a>
      <div class="nav-label">System</div>
      <a class="nav-item" href="reports.php"><div class="nav-icon">📊</div>Reports</a>
      <a class="nav-item" href="announcements.php"><div class="nav-icon">📢</div>Announcements</a>
      <a class="nav-item" href="archives.php"><div class="nav-icon">🗄️</div>Archives</a>
      <a class="nav-item active" href="user_accounts.php"><div class="nav-icon">🔐</div>User Accounts</a>
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
        <h1>User Accounts</h1>
        <p>Manage system login accounts and access roles</p>
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

      <!-- STATS -->
      <div class="stats-row">
        <div class="stat-chip"><div class="dot" style="background:#2563eb;"></div>Total&nbsp;<strong id="sc-total">—</strong></div>
        <div class="stat-chip"><div class="dot" style="background:#22c55e;"></div>Active&nbsp;<strong id="sc-active">—</strong></div>
        <div class="stat-chip"><div class="dot" style="background:#ef4444;"></div>Inactive&nbsp;<strong id="sc-inactive">—</strong></div>
        <div class="stat-chip"><div class="dot" style="background:#1e40af;"></div>Admins&nbsp;<strong id="sc-admins">—</strong></div>
        <div class="stat-chip"><div class="dot" style="background:#a855f7;"></div>HR Staff&nbsp;<strong id="sc-hr">—</strong></div>
        <div class="stat-chip"><div class="dot" style="background:#f59e0b;"></div>Dept Heads&nbsp;<strong id="sc-dh">—</strong></div>
        <div class="stat-chip"><div class="dot" style="background:#22c55e;"></div>Employees&nbsp;<strong id="sc-emp">—</strong></div>
      </div>

      <!-- TOOLBAR -->
      <div class="toolbar">
        <div class="search-box">
          <span style="color:var(--gray-400);">🔍</span>
          <input type="text" id="searchInput" placeholder="Search name or email…" oninput="debounceLoad()"/>
        </div>
        <select class="filter-select" id="fRole" onchange="loadAccounts()">
          <option value="">All Roles</option>
          <option value="admin">Admin</option>
          <option value="hr_staff">HR Staff</option>
          <option value="dept_head">Dept Head</option>
          <option value="employee">Employee</option>
        </select>
        <select class="filter-select" id="fStatus" onchange="loadAccounts()">
          <option value="">All Status</option>
          <option value="1">Active</option>
          <option value="0">Inactive</option>
        </select>
        <button class="add-btn" onclick="openAdd()">➕ Add Account</button>
      </div>

      <!-- TABLE -->
      <div class="panel">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>User</th>
                <th>Linked Employee</th>
                <th>Role</th>
                <th>Status</th>
                <th>Last Login</th>
                <th>Created</th>
                <th style="text-align:center;">Actions</th>
              </tr>
            </thead>
            <tbody id="accountTbody">
              <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--gray-400);">Loading…</td></tr>
            </tbody>
          </table>
        </div>
        <div class="pagination">
          <span id="pageInfo" style="color:var(--gray-400);font-weight:600;font-size:0.82rem;"></span>
          <div class="page-btns" id="pageBtns"></div>
        </div>
      </div>

    </div>
  </div>

  <!-- ══ ADD / EDIT MODAL ══ -->
  <div class="modal-overlay" id="modalForm">
    <div class="modal">
      <div class="modal-header">
        <div><h3 id="formTitle">Add Account</h3><p id="formSub">Create a new system login</p></div>
        <button class="modal-close" onclick="closeModal('modalForm')">✕</button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="fId"/>

        <div class="form-section">🔐 Login Details</div>
        <div class="form-group">
          <label>Email Address <span class="req">*</span></label>
          <input type="email" id="fEmail" placeholder="e.g. jdelacruz@lgu-enrile.gov.ph"/>
        </div>
        <div class="form-group">
          <label id="fPasswordLabel">Password <span class="req">*</span></label>
          <input type="password" id="fPassword" placeholder="Min. 6 characters"/>
          <span class="form-hint" id="fPasswordHint"></span>
        </div>
        <div class="form-group">
          <label>Role <span class="req">*</span></label>
          <select id="fRoleInput">
            <option value="employee">Employee</option>
            <option value="hr_staff">HR Staff</option>
            <option value="dept_head">Department Head</option>
            <option value="admin">Admin</option>
          </select>
        </div>
        <div class="form-group">
          <label>Status</label>
          <div class="toggle-wrap">
            <label class="toggle">
              <input type="checkbox" id="fIsActive" checked/>
              <span class="slider"></span>
            </label>
            <span style="font-size:0.82rem;color:var(--gray-600);font-weight:600;">Active</span>
          </div>
        </div>

        <div class="form-section" style="margin-top:10px;">👤 Link to Employee</div>
        <div class="form-group">
          <label>Employee <span style="color:var(--gray-400);font-weight:600;">(optional)</span></label>
          <select id="fEmployee">
            <option value="">— No linked employee —</option>
          </select>
          <span class="form-hint">Only employees without an existing account are shown.</span>
        </div>

      </div>
      <div class="modal-footer">
        <button class="btn btn-cancel" onclick="closeModal('modalForm')">Cancel</button>
        <button class="btn btn-primary" id="saveBtn" onclick="saveAccount()">💾 Save Account</button>
      </div>
    </div>
  </div>

  <!-- ══ RESET PASSWORD MODAL ══ -->
  <div class="modal-overlay" id="modalReset">
    <div class="modal sm">
      <div class="modal-header amber">
        <div><h3>Reset Password</h3><p id="resetSubtitle">Set a new password</p></div>
        <button class="modal-close" onclick="closeModal('modalReset')">✕</button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="resetId"/>
        <div class="form-group">
          <label>New Password <span class="req">*</span></label>
          <input type="password" id="resetPassword" placeholder="Min. 6 characters"/>
        </div>
        <div class="form-group">
          <label>Confirm Password <span class="req">*</span></label>
          <input type="password" id="resetConfirm" placeholder="Re-enter password"/>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-cancel" onclick="closeModal('modalReset')">Cancel</button>
        <button class="btn btn-amber" onclick="confirmReset()">🔑 Reset Password</button>
      </div>
    </div>
  </div>

  <!-- ══ DELETE CONFIRM MODAL ══ -->
  <div class="modal-overlay" id="modalDelete">
    <div class="modal sm">
      <div class="modal-header red">
        <div><h3>Delete Account</h3><p>This cannot be undone</p></div>
        <button class="modal-close" onclick="closeModal('modalDelete')">✕</button>
      </div>
      <div class="confirm-body">
        <div class="conf-icon">⚠️</div>
        <h4>Are you sure?</h4>
        <p id="delText"></p>
      </div>
      <div class="modal-footer">
        <button class="btn btn-cancel" onclick="closeModal('modalDelete')">Cancel</button>
        <button class="btn btn-danger" onclick="confirmDelete()">🗑️ Yes, Delete</button>
      </div>
    </div>
  </div>

  <script>
const CSRF_TOKEN = document.querySelector('meta[name=csrf-token]').content;
  // ════════════════════════════════════════════════════════
  //  STATE
  // ════════════════════════════════════════════════════════
  const COLORS = ['#2563eb','#22c55e','#f59e0b','#ef4444','#a855f7','#06b6d4','#ec4899','#f97316','#6366f1','#14b8a6'];
  const ME = <?= (int)$_SESSION['user_id'] ?>;
  let currentPage   = 1;
  let deleteTarget  = null;
  let debounceTimer = null;

  const avatarColor = str => COLORS[Math.abs([...str].reduce((a,c)=>a+c.charCodeAt(0),0)) % COLORS.length];
  const fmt = d => d ? new Date(d).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'numeric'}) : '—';
  const fmtDt = d => d ? new Date(d).toLocaleString('en-PH',{month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}) : 'Never';

  const roleBadge = r => {
    const map = { admin:'badge-admin', hr_staff:'badge-hr', dept_head:'badge-depthead', employee:'badge-employee' };
    const lbl = { admin:'Admin', hr_staff:'HR Staff', dept_head:'Dept Head', employee:'Employee' };
    return `<span class="badge ${map[r]||'badge-employee'}">${lbl[r]||r}</span>`;
  };

  // ════════════════════════════════════════════════════════
  //  LOAD
  // ════════════════════════════════════════════════════════
  async function loadAccounts(page = currentPage) {
    currentPage = page;
    const params = new URLSearchParams({
      action : 'list',
      page   : currentPage,
      search : document.getElementById('searchInput').value.trim(),
      role   : document.getElementById('fRole').value,
      status : document.getElementById('fStatus').value,
    });
    try {
      const res  = await fetch(`user_accounts.php?${params}`);
      const json = await res.json();
      if (!json.success) { showToast('⚠️ ' + json.message, 'info'); return; }
      renderTable(json.data);
      renderStats(json.stats);
      renderPagination(json.page, json.pages, json.total);
    } catch(e) {
      showToast('❌ Failed to load accounts.', 'error');
    }
  }

  function debounceLoad() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => loadAccounts(1), 300);
  }

  // ════════════════════════════════════════════════════════
  //  RENDER TABLE
  // ════════════════════════════════════════════════════════
  function renderTable(data) {
    const tbody = document.getElementById('accountTbody');
    if (!data.length) {
      tbody.innerHTML = '<tr class="empty-row"><td colspan="7">No accounts found.</td></tr>';
      return;
    }
    tbody.innerHTML = data.map(u => {
      const initials = u.email.slice(0,2).toUpperCase();
      const color    = avatarColor(u.email);
      const empName  = u.first_name ? `${u.first_name} ${u.last_name}` : '—';
      const empSub   = u.position_title || '';
      const isSelf   = u.user_id == ME;

      return `<tr>
        <td>
          <div class="user-cell">
            <div class="u-avatar" style="background:${color};">${initials}</div>
            <div>
              <div class="u-name">${u.email}</div>
              <div class="u-email">ID #${u.user_id}${isSelf ? ' <span style="color:#2563eb;font-weight:800;">(You)</span>' : ''}</div>
            </div>
          </div>
        </td>
        <td>
          ${u.first_name
            ? `<div style="font-weight:700;color:var(--gray-800);font-size:0.82rem;">${empName}</div>
               <div style="font-size:0.72rem;color:var(--gray-400);">${empSub}</div>`
            : '<span style="color:var(--gray-400);font-size:0.80rem;">Not linked</span>'}
        </td>
        <td>${roleBadge(u.role)}</td>
        <td>
          <span class="badge ${u.is_active ? 'badge-active' : 'badge-inactive'}">
            ${u.is_active ? '🟢 Active' : '🔴 Inactive'}
          </span>
        </td>
        <td style="font-size:0.78rem;">${fmtDt(u.last_login)}</td>
        <td style="font-size:0.78rem;">${fmt(u.created_at)}</td>
        <td>
          <div class="row-actions">
            <button class="icon-btn edit"   onclick="editAccount(${u.user_id})"   title="Edit">✏️</button>
            <button class="icon-btn reset"  onclick="openReset(${u.user_id},'${u.email.replace(/'/g,"\\'")}')" title="Reset Password">🔑</button>
            <button class="icon-btn toggle" onclick="toggleActive(${u.user_id}, this)" title="${u.is_active ? 'Deactivate' : 'Activate'}">${u.is_active ? '🔒' : '🔓'}</button>
            ${!isSelf ? `<button class="icon-btn delete" onclick="deleteAccount(${u.user_id},'${u.email.replace(/'/g,"\\'")}')" title="Delete">🗑️</button>` : ''}
          </div>
        </td>
      </tr>`;
    }).join('');
  }

  // ════════════════════════════════════════════════════════
  //  RENDER STATS
  // ════════════════════════════════════════════════════════
  function renderStats(s) {
    document.getElementById('sc-total').textContent   = s.total    || 0;
    document.getElementById('sc-active').textContent  = s.active   || 0;
    document.getElementById('sc-inactive').textContent= s.inactive || 0;
    document.getElementById('sc-admins').textContent  = s.admins   || 0;
    document.getElementById('sc-hr').textContent      = s.hr_staff || 0;
    document.getElementById('sc-dh').textContent      = s.dept_heads|| 0;
    document.getElementById('sc-emp').textContent     = s.employees|| 0;
  }

  // ════════════════════════════════════════════════════════
  //  PAGINATION
  // ════════════════════════════════════════════════════════
  function renderPagination(page, pages, total) {
    const from = total ? (page-1)*10+1 : 0;
    const to   = Math.min(page*10, total);
    document.getElementById('pageInfo').textContent = `Showing ${from}–${to} of ${total} account${total!==1?'s':''}`;
    const pb = document.getElementById('pageBtns');
    pb.innerHTML = '';
    const mk = (label, pg, disabled=false) => {
      const b = document.createElement('button');
      b.className = 'page-btn' + (pg===page?' active':'');
      b.textContent = label; b.disabled = disabled;
      b.onclick = () => loadAccounts(pg);
      pb.appendChild(b);
    };
    mk('‹', page-1, page===1);
    for (let i=1; i<=pages; i++) mk(i, i);
    mk('›', page+1, page>=pages);
  }

  // ════════════════════════════════════════════════════════
  //  LOAD UNLINKED EMPLOYEES FOR DROPDOWN
  // ════════════════════════════════════════════════════════
  async function loadEmployeeDropdown(currentEmployeeId = null) {
    const res  = await fetch('user_accounts.php?action=unlinked_employees');
    const json = await res.json();
    const sel  = document.getElementById('fEmployee');
    sel.innerHTML = '<option value="">— No linked employee —</option>';
    if (json.success) {
      json.data.forEach(e => {
        const opt = document.createElement('option');
        opt.value = e.employee_id;
        opt.textContent = `${e.full_name} (${e.employee_code}) — ${e.position_title || 'No position'}`;
        sel.appendChild(opt);
      });
    }
    // If editing, add the currently linked employee back as an option
    if (currentEmployeeId) {
      const exists = [...sel.options].some(o => o.value == currentEmployeeId);
      if (!exists) {
        const opt = document.createElement('option');
        opt.value = currentEmployeeId;
        opt.textContent = '(Current employee)';
        sel.appendChild(opt);
      }
      sel.value = currentEmployeeId;
    }
  }

  // ════════════════════════════════════════════════════════
  //  ADD
  // ════════════════════════════════════════════════════════
  function openAdd() {
    document.getElementById('formTitle').textContent  = 'Add Account';
    document.getElementById('formSub').textContent    = 'Create a new system login';
    document.getElementById('fId').value              = '';
    document.getElementById('fEmail').value           = '';
    document.getElementById('fPassword').value        = '';
    document.getElementById('fRoleInput').value       = 'employee';
    document.getElementById('fIsActive').checked      = true;
    document.getElementById('fPasswordLabel').innerHTML = 'Password <span class="req">*</span>';
    document.getElementById('fPasswordHint').textContent = '';
    document.getElementById('fEmail').classList.remove('err');
    loadEmployeeDropdown();
    openModal('modalForm');
  }

  // ════════════════════════════════════════════════════════
  //  EDIT
  // ════════════════════════════════════════════════════════
  async function editAccount(id) {
    const res  = await fetch(`user_accounts.php?action=get&id=${id}`);
    const json = await res.json();
    if (!json.success) { showToast('⚠️ ' + json.message, 'info'); return; }
    const u = json.data;

    document.getElementById('formTitle').textContent  = 'Edit Account';
    document.getElementById('formSub').textContent    = `Editing: ${u.email}`;
    document.getElementById('fId').value              = u.user_id;
    document.getElementById('fEmail').value           = u.email       || '';
    document.getElementById('fPassword').value        = '';
    document.getElementById('fRoleInput').value       = u.role        || 'employee';
    document.getElementById('fIsActive').checked      = u.is_active == 1;
    document.getElementById('fPasswordLabel').innerHTML = 'New Password <span style="color:var(--gray-400);font-weight:600;">(optional)</span>';
    document.getElementById('fPasswordHint').textContent = 'Leave blank to keep the current password.';
    document.getElementById('fEmail').classList.remove('err');
    loadEmployeeDropdown(u.employee_id || null);
    openModal('modalForm');
  }

  // ════════════════════════════════════════════════════════
  //  SAVE
  // ════════════════════════════════════════════════════════
  async function saveAccount() {
    const email = document.getElementById('fEmail').value.trim();
    if (!email) {
      document.getElementById('fEmail').classList.add('err');
      showToast('⚠️ Email is required.', 'info'); return;
    }
    document.getElementById('fEmail').classList.remove('err');

    const btn = document.getElementById('saveBtn');
    btn.disabled = true; btn.textContent = '⏳ Saving…';

    const body = new FormData();
    body.append('action',      'save');
    body.append('user_id',     document.getElementById('fId').value);
    body.append('email',       email);
    body.append('password',    document.getElementById('fPassword').value);
    body.append('role',        document.getElementById('fRoleInput').value);
    body.append('is_active',   document.getElementById('fIsActive').checked ? 1 : 0);
    body.append('employee_id', document.getElementById('fEmployee').value);

    try {
      const res  = await fetch('user_accounts.php', { method:'POST', body });
      const json = await res.json();
      if (json.success) {
        showToast('✅ ' + json.message, 'success');
        closeModal('modalForm');
        loadAccounts(currentPage);
      } else {
        showToast('❌ ' + json.message, 'error');
      }
    } catch(e) {
      showToast('❌ Failed to save.', 'error');
    } finally {
      btn.disabled = false; btn.textContent = '💾 Save Account';
    }
  }

  // ════════════════════════════════════════════════════════
  //  TOGGLE ACTIVE
  // ════════════════════════════════════════════════════════
  async function toggleActive(id, btn) {
    const body = new FormData();
    body.append('action', 'toggle_active');
    body.append('id', id);
    try {
      const res  = await fetch('user_accounts.php', { method:'POST', body });
      const json = await res.json();
      if (json.success) {
        showToast(json.is_active ? '🔓 Account activated.' : '🔒 Account deactivated.', json.is_active ? 'success' : 'info');
        loadAccounts(currentPage);
      } else {
        showToast('❌ ' + json.message, 'error');
      }
    } catch(e) {
      showToast('❌ Failed.', 'error');
    }
  }

  // ════════════════════════════════════════════════════════
  //  RESET PASSWORD
  // ════════════════════════════════════════════════════════
  function openReset(id, email) {
    document.getElementById('resetId').value        = id;
    document.getElementById('resetSubtitle').textContent = `For: ${email}`;
    document.getElementById('resetPassword').value  = '';
    document.getElementById('resetConfirm').value   = '';
    openModal('modalReset');
  }

  async function confirmReset() {
    const id  = document.getElementById('resetId').value;
    const pw  = document.getElementById('resetPassword').value;
    const pw2 = document.getElementById('resetConfirm').value;
    if (!pw || pw.length < 6) { showToast('⚠️ Password must be at least 6 characters.', 'info'); return; }
    if (pw !== pw2)            { showToast('⚠️ Passwords do not match.', 'info'); return; }

    const body = new FormData();
    body.append('action',   'reset_password');
    body.append('id',       id);
    body.append('password', pw);
    try {
      const res  = await fetch('user_accounts.php', { method:'POST', body });
      const json = await res.json();
      if (json.success) {
        showToast('🔑 ' + json.message, 'success');
        closeModal('modalReset');
      } else {
        showToast('❌ ' + json.message, 'error');
      }
    } catch(e) {
      showToast('❌ Failed.', 'error');
    }
  }

  // ════════════════════════════════════════════════════════
  //  DELETE
  // ════════════════════════════════════════════════════════
  function deleteAccount(id, email) {
    deleteTarget = id;
    document.getElementById('delText').textContent =
      `You are about to permanently delete the account for "${email}". This cannot be undone.`;
    openModal('modalDelete');
  }

  async function confirmDelete() {
    const body = new FormData();
    body.append('action', 'delete');
    body.append('id',     deleteTarget);
    try {
      const res  = await fetch('user_accounts.php', { method:'POST', body });
      const json = await res.json();
      if (json.success) {
        showToast('🗑️ ' + json.message, 'error');
        closeModal('modalDelete');
        loadAccounts(1);
      } else {
        showToast('❌ ' + json.message, 'error');
      }
    } catch(e) {
      showToast('❌ Delete failed.', 'error');
    }
  }

  // ════════════════════════════════════════════════════════
  //  MODALS / TOAST / SIDEBAR
  // ════════════════════════════════════════════════════════
  function openModal(id)  { document.getElementById(id).classList.add('open'); }
  function closeModal(id) { document.getElementById(id).classList.remove('open'); }
  document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
  });

  function showToast(msg, type='success') {
    document.querySelectorAll('.toast').forEach(t => t.remove());
    const t = document.createElement('div');
    t.className = `toast ${type}`;
    t.textContent = msg;
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

  // ════════════════════════════════════════════════════════
  //  INIT
  // ════════════════════════════════════════════════════════
  async function init() {
    try {
      const lr = await fetch('user_accounts.php?action=pending_count');
      const lj = await lr.json();
      if (lj.count > 0) {
        document.getElementById('pendingBadge').textContent  = lj.count;
        /* topNotifBadge removed */
      }
    } catch(e) {}
    loadAccounts(1);
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