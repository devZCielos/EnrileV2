<?php
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$_sessionLifetime = 8 * 60 * 60;
if (isset($_SESSION['login_time']) && (time() - strtotime($_SESSION['login_time']) > $_sessionLifetime)) {
    $_isAjax = !empty($_GET['action']) || !empty($_POST['action']);
    session_unset();
    session_destroy();
    if ($_isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Your session has expired. Please log in again.']);
        exit();
    }
    header('Location: ../Login/login.php?error=' . urlencode('Your session has expired. Please log in again.'));
    exit();
}

if (empty($_SESSION['user_id']) || empty($_SESSION['user_email'])) {
    header('Location: ../Login/login.php');
    exit();
}

$allowedRoles = ['admin', 'hr_staff', 'dept_head'];
$currentRole = $_SESSION['user_role'] ?? '';
if (!in_array($currentRole, $allowedRoles, true)) {
    header('Location: ../Employee/Employee_portal.php');
    exit();
}

$canResolve = in_array($currentRole, ['admin', 'hr_staff'], true);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';

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

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($action === 'print_coe') {
  ensureCoeRequestsTable($pdo);
    $coeId = (int)($_GET['coe_request_id'] ?? 0);
    if (!$canResolve) {
        http_response_code(403);
        echo 'Forbidden';
        exit();
    }

    $stmt = $pdo->prepare("SELECT c.*, e.first_name, e.middle_name, e.last_name, e.position_title, e.date_hired, d.name AS department_name
                           FROM coe_requests c
                           JOIN employees e ON e.employee_id = c.employee_id
                           LEFT JOIN departments d ON d.department_id = e.department_id
                           WHERE c.coe_request_id = ? AND c.status = 'Approved' AND c.is_deleted = 0
                           LIMIT 1");
    $stmt->execute([$coeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo 'Approved COE request not found.';
        exit();
    }

    $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    $position = trim((string)($row['position_title'] ?? 'Employee'));
    $department = trim((string)($row['department_name'] ?? 'Municipality of Enrile'));
    $dateHired = !empty($row['date_hired']) ? date('F j, Y', strtotime($row['date_hired'])) : 'N/A';
    $today = date('F j, Y');

    $hrHeadName = 'EMIROSE P. MADRIAGA';
    $hrHeadTitle = 'HRMO III';
    try {
        $hrRow = $pdo->query("SELECT
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
        if (!empty($hrRow['head_name'])) {
            $hrHeadName = strtoupper(trim($hrRow['head_name']));
            $hrHeadTitle = trim((string)($hrRow['head_title'] ?? 'HRMO III'));
        }
    } catch (Exception $e) {
    }

    $dayInt = (int)date('j');
    $daySuffix = 'th';
    if (!in_array($dayInt % 100, [11, 12, 13], true)) {
        $suffixMap = [1 => 'st', 2 => 'nd', 3 => 'rd'];
        $daySuffix = $suffixMap[$dayInt % 10] ?? 'th';
    }
    $issuedDateLong = $dayInt . $daySuffix . ' day of ' . date('F, Y');
    ?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Certificate of Employment</title>
  <style>
    @page { size: A4; margin: 0.45in 0.55in 0.4in 0.55in; }
    body { font-family: Arial, Helvetica, sans-serif; color: #111; margin: 0; background: #fff; }
    .sheet {
      width: 100%;
      min-height: calc(297mm - 0.85in);
      box-sizing: border-box;
      display: flex;
      flex-direction: column;
    }
    .header { text-align: center; }
    .logos { display: flex; align-items: center; justify-content: center; gap: 16px; margin-top: 2px; }
    .logos img { width: 78px; height: 78px; object-fit: contain; }
    .gov-text { text-align: left; line-height: 1.16; }
    .gov-text .a { font-size: 16px; font-weight: 700; color: #111; letter-spacing: .01em; margin-top: 3px; }
    .gov-text .b { font-size: 36px; font-weight: 900; color: #c00000; letter-spacing: .05em; line-height: 1; margin-top: 3px; }
    .line { margin-top: 5px; width: 100%; height: 10px; object-fit: cover; }
    .office { margin-top: 4px; text-align: center; }
    .office .x { font-family: 'Old English Text MT', 'Bookman Old Style', 'Times New Roman', serif; font-size: 42px; font-weight: 700; color: #111; }
    .office .y { font-family: 'Times New Roman', serif; font-size: 15px; color: #a32525; letter-spacing: .05em; margin-top: 0; }
    .title { text-align: center; font-size: 54px; font-weight: 900; color: #e38900; letter-spacing: .05em; margin-top: 12px; }
    .body { margin-top: 30px; font-size: 15px; line-height: 1.48; text-align: justify; }
    .body p { margin: 0 0 16px 0; text-indent: 42px; }
    .body .lead { font-weight: 800; text-indent: 0; margin-bottom: 22px; }
    .body .caps { font-weight: 900; letter-spacing: .01em; }
    .sig { margin-top: 52px; text-align: center; }
    .sig .name { font-size: 16px; font-weight: 800; letter-spacing: .01em; }
    .sig .role { font-size: 14px; margin-top: 1px; }
    .footer-area { margin-top: auto; page-break-inside: avoid; break-inside: avoid; }
    .footer-wrap { margin-top: 0; position: relative; }
    .footer-brand { position: absolute; left: 0; bottom: 12px; width: 54px; height: 54px; object-fit: contain; }
    .footer-text { text-align: center; font-size: 12px; font-weight: 700; margin-bottom: 7px; letter-spacing: .01em; }
    .footer-line { width: 100%; height: 10px; object-fit: cover; }
    .ref { margin: 0 0 8px 0; text-align: right; font-size: 11px; color: #555; }
    .no-print { margin-top: 16px; text-align: right; }
    .no-print button { padding: 8px 16px; border-radius: 8px; border: none; background: #2563eb; color: #fff; font-weight: 700; cursor: pointer; }
    @media print {
      .no-print { display: none; }
      .sheet { width: 100%; min-height: calc(297mm - 0.85in); }
      .footer-area { page-break-inside: avoid; break-inside: avoid; }
    }
  </style>
</head>
<body>
  <div class="sheet">
    <div class="header">
      <div class="logos">
        <img src="../images/Enrile.png" alt="Enrile Seal" />
        <div class="gov-text">
          <div style="font-size:13px;font-weight:700;">Republic of the Philippines</div>
          <div style="font-size:13px;font-weight:700;">Province of Cagayan</div>
          <div style="font-size:13px;font-weight:700;">Municipality of Enrile</div>
          <div class="b">ONE ENRILE</div>
        </div>
        <img src="../images/Peanut.png" alt="LGU Symbol" />
      </div>
      <img class="line" src="../images/line.png" alt="line" />
      <div class="office">
        <div class="x">Office of the Municipal Mayor</div>
        <div class="y">Human Resource Management Unit</div>
      </div>
      <div class="title">CERTIFICATION</div>
    </div>

    <div class="body">
      <p class="lead">TO WHOM IT MAY CONCERN:</p>

      <p>
        <span class="caps">THIS IS TO CERTIFY</span> that as per records available in this office,
        <span class="caps"><?= htmlspecialchars(strtoupper($fullName)) ?></span>, a bona fide employee of the Local Government Unit of Enrile,
        assigned at the <span class="caps"><?= htmlspecialchars($department) ?></span> as
        <span class="caps"><?= htmlspecialchars($position) ?></span>, from <span class="caps"><?= htmlspecialchars($dateHired) ?></span> to present.
      </p>

      <p>
        <span class="caps">IT IS CERTIFIED FURTHER</span> that the above-named individual is known to the undersigned
        to be of good moral character and reputation in the community. Records available in this office show
        that there is no pending administrative case filed against the employee as of this date.
      </p>

      <p>
        This certification is issued upon the request of the above-named employee for
        <span class="caps"><?= htmlspecialchars((string)$row['purpose']) ?></span> and for whatever legal purpose it may serve.
      </p>

      <p>Given this <span class="caps"><?= htmlspecialchars($issuedDateLong) ?></span> at Enrile, Cagayan.</p>
    </div>

    <div class="sig">
      <div class="name"><?= htmlspecialchars($hrHeadName) ?></div>
      <div class="role"><?= htmlspecialchars($hrHeadTitle) ?></div>
    </div>

    <div class="footer-area">
      <div class="ref">Reference No.: COE-<?= (int)$row['coe_request_id'] ?></div>
      <div class="footer-wrap">
        <img class="footer-brand" src="../images/Bagong_Pilipinas.png" alt="Bagong Pilipinas" />
        <div class="footer-text">OHANA: Awan Na Matalan, Awan Nga Kalippanan</div>
        <img class="footer-line" src="../images/line.png" alt="line" />
      </div>
    </div>

    <div class="no-print"><button onclick="window.print()">Print</button></div>
  </div>
</body>
</html>
    <?php
    exit();
}

if ($action) {
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify(true);
    }

    try {
    if (in_array($action, ['list_coe_requests', 'resolve_coe_request', 'pending_count'], true)) {
      ensureCoeRequestsTable($pdo);
    }

        switch ($action) {
            case 'list_coe_requests':
                $status = trim($_GET['status'] ?? 'all');
                $search = trim($_GET['search'] ?? '');
                $where = ['c.is_deleted = 0'];
                $params = [];

                if ($status !== '' && strtolower($status) !== 'all') {
                    $where[] = 'c.status = ?';
                    $params[] = ucfirst(strtolower($status));
                }
                if ($search !== '') {
                    $where[] = "(CONCAT(e.first_name, ' ', e.last_name) LIKE ? OR e.employee_code LIKE ? OR c.purpose LIKE ?)";
                    $s = '%' . $search . '%';
                    $params[] = $s;
                    $params[] = $s;
                    $params[] = $s;
                }

                $sqlWhere = 'WHERE ' . implode(' AND ', $where);
                $stmt = $pdo->prepare("SELECT c.*, e.employee_code, e.first_name, e.middle_name, e.last_name, e.position_title,
                                      d.name AS department_name
                                      FROM coe_requests c
                                      JOIN employees e ON e.employee_id = c.employee_id
                                      LEFT JOIN departments d ON d.department_id = e.department_id
                                      $sqlWhere
                                      ORDER BY FIELD(c.status,'Pending','Approved','Denied','Cancelled'), c.filed_at DESC");
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $countStmt = $pdo->query("SELECT status, COUNT(*) AS cnt FROM coe_requests WHERE is_deleted = 0 GROUP BY status");
                $counts = ['Pending' => 0, 'Approved' => 0, 'Denied' => 0, 'Cancelled' => 0];
                foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                    if (isset($counts[$c['status']])) {
                        $counts[$c['status']] = (int)$c['cnt'];
                    }
                }

                echo json_encode(['success' => true, 'data' => $rows, 'counts' => $counts]);
                break;

            case 'resolve_coe_request':
                if (!$canResolve) {
                    echo json_encode(['success' => false, 'message' => 'Only HR/Admin can resolve COE requests.']);
                    break;
                }

                $coeId = (int)($_POST['coe_request_id'] ?? 0);
                $status = trim($_POST['status'] ?? '');
                $remarks = trim($_POST['admin_remarks'] ?? '');
                if (!in_array($status, ['Approved', 'Denied'], true)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid resolution status.']);
                    break;
                }

                $chk = $pdo->prepare("SELECT coe_request_id FROM coe_requests WHERE coe_request_id = ? AND status = 'Pending' AND is_deleted = 0");
                $chk->execute([$coeId]);
                if (!$chk->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Only pending requests can be resolved.']);
                    break;
                }

                $upd = $pdo->prepare("UPDATE coe_requests
                                      SET status = ?, admin_remarks = ?, reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW()
                                      WHERE coe_request_id = ?");
                $upd->execute([$status, $remarks !== '' ? $remarks : null, (int)$_SESSION['user_id'], $coeId]);
                echo json_encode(['success' => true, 'message' => "COE request {$status}."]);
                break;

            case 'pending_count':
                $cnt = (int)$pdo->query("SELECT COUNT(*) FROM coe_requests WHERE status='Pending' AND is_deleted = 0")->fetchColumn();
                echo json_encode(['success' => true, 'count' => $cnt]);
                break;
        }
    } catch (Throwable $e) {
        error_log('[HRMS] coe_requests.php error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'A server error occurred.']);
    }
    exit();
}

$adminName = ucwords($_SESSION['user_name'] ?? 'Administrator');
$roleLabels = ['admin' => 'Super Admin', 'hr_staff' => 'HR Staff', 'dept_head' => 'Department Head'];
$roleLabel = $roleLabels[$currentRole] ?? 'Admin';
$initials = strtoupper(substr($adminName, 0, 2));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <?= csrf_meta() ?>
  <title>COE Requests - LGU Enrile HRMS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet"/>
  <style>
    :root{--primary:#2563eb;--primary-dark:#1e40af;--accent:#22c55e;--secondary:#0f172a;--sidebar-w:265px;--topbar-h:68px;--gray-50:#f8fafc;--gray-100:#f1f5f9;--gray-200:#e2e8f0;--gray-400:#94a3b8;--gray-600:#475569;--gray-800:#1e293b;--white:#ffffff;--shadow-sm:0 1px 4px rgba(0,0,0,0.07);--shadow-md:0 4px 18px rgba(0,0,0,0.11);--radius:14px;--radius-sm:9px;}
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Nunito',sans-serif;background:var(--gray-100);color:var(--secondary);display:flex;min-height:100vh}
    .sidebar{width:var(--sidebar-w);background:linear-gradient(180deg,#2563eb 0%,#1e40af 55%,#22c55e 100%);display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:200;transition:transform .3s;box-shadow:4px 0 28px rgba(0,0,0,.20)}
    .sidebar-brand{padding:22px 20px 18px;display:flex;align-items:center;gap:12px;border-bottom:1px solid rgba(255,255,255,.15)}
    .brand-logo{width:44px;height:44px;border-radius:50%;object-fit:cover;border:2.5px solid rgba(255,255,255,.70)}
    .brand-text h2{font-family:'Playfair Display',serif;font-size:1rem;color:#fff;line-height:1.2}
    .brand-text p{font-size:.65rem;color:rgba(255,255,255,.60);font-weight:600;letter-spacing:.06em}
    .sidebar-nav{padding:12px 10px;flex:1;overflow-y:auto}
    .nav-label{font-size:.63rem;font-weight:800;color:rgba(255,255,255,.38);text-transform:uppercase;letter-spacing:.16em;padding:10px 10px 5px;margin-top:6px}
    .nav-item{display:flex;align-items:center;gap:11px;padding:9px 12px;border-radius:var(--radius-sm);color:rgba(255,255,255,.75);text-decoration:none;font-size:.875rem;font-weight:600;transition:all .2s;margin-bottom:2px;position:relative}
    .nav-item:hover{background:rgba(255,255,255,.13);color:#fff}
    .nav-item.active{background:rgba(255,255,255,.22);color:#fff}
    .nav-item.active::before{content:'';position:absolute;left:0;top:20%;bottom:20%;width:3px;border-radius:2px;background:#fff}
    .nav-icon{width:30px;height:30px;border-radius:8px;background:rgba(255,255,255,.10);display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0}
    .sidebar-footer{padding:14px 16px;border-top:1px solid rgba(255,255,255,.12)}
    .user-pill{display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.10);border:1.5px solid rgba(255,255,255,.18);border-radius:14px;padding:8px 10px}
    .user-avatar{width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.18);border:2px solid rgba(255,255,255,.40);display:flex;align-items:center;justify-content:center;font-weight:800;color:#fff;font-size:.95rem;flex-shrink:0}
    .user-name{font-size:.90rem;font-weight:800;color:#fff;line-height:1.1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .user-role{font-size:.72rem;color:rgba(255,255,255,.72);font-weight:700;line-height:1.15;margin-top:2px}
    .main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-height:100vh}
    .topbar{height:var(--topbar-h);background:linear-gradient(135deg,#2563eb 0%,#1e40af 50%,#22c55e 100%);display:flex;align-items:center;padding:0 28px;gap:16px;position:sticky;top:0;z-index:100;box-shadow:0 2px 12px rgba(0,0,0,.18)}
    .menu-toggle{display:none;background:none;border:none;font-size:1.4rem;cursor:pointer;color:rgba(255,255,255,0.85);padding:4px}
    .topbar-title{flex:1}
    .topbar-title h1{font-family:'Playfair Display',serif;font-size:1.22rem;color:#fff}
    .topbar-title p{font-size:.72rem;color:rgba(255,255,255,.70)}
    .tb-chip{background:rgba(255,255,255,.15);color:#fff;padding:5px 14px;border-radius:20px;font-size:.72rem;font-weight:700}
    .content{padding:26px 28px;flex:1}
    .stats{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:12px;margin-bottom:16px}
    .stat{background:#fff;border-radius:12px;padding:12px;border:1px solid var(--gray-200);box-shadow:var(--shadow-sm)}
    .stat .k{font-size:.68rem;color:var(--gray-400);font-weight:800;text-transform:uppercase;letter-spacing:.08em}
    .stat .v{font-size:1.18rem;color:var(--gray-800);font-weight:800;margin-top:5px}
    .panel{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow-sm);overflow:hidden}
    .panel-head{padding:16px 20px;background:var(--gray-50);border-bottom:1.5px solid var(--gray-200);display:flex;align-items:center;gap:12px;flex-wrap:wrap}
    .panel-title{font-size:.95rem;font-weight:800;flex:1}
    .sub{font-size:.75rem;color:var(--gray-400);margin-top:2px}
    .bar{padding:14px 18px;border-bottom:1px solid var(--gray-100);display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    .search-box{display:flex;align-items:center;gap:8px;background:var(--white);border:1.5px solid var(--gray-200);border-radius:var(--radius-sm);padding:8px 11px;min-width:280px;max-width:420px;flex:1}
    .search-box:focus-within{border-color:var(--primary)}
    .search-box input{border:none;outline:none;font-family:'Nunito',sans-serif;font-size:.83rem;width:100%;color:var(--gray-800)}
    .btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border:none;border-radius:var(--radius-sm);font-family:'Nunito',sans-serif;font-size:.80rem;font-weight:700;cursor:pointer;transition:all .2s}
    .btn-outline{background:#fff;border:1.5px solid var(--gray-200);color:var(--gray-600)}
    .btn-outline:hover{border-color:var(--primary);color:var(--primary)}
    .tabs{display:flex;gap:8px;flex-wrap:wrap}
    .tab{padding:7px 12px;border:1.5px solid var(--gray-200);border-radius:999px;background:#fff;cursor:pointer;font-size:.78rem;font-weight:800;color:var(--gray-600)}
    .tab.active{background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:#fff;border-color:var(--primary)}
    .tab .tc{opacity:.9}
    .tbl-wrap{overflow-x:auto}
    .tbl{width:100%;border-collapse:collapse;font-size:.82rem}
    .tbl th{background:var(--gray-100);padding:11px 14px;text-align:left;font-size:.69rem;font-weight:800;color:var(--gray-400);text-transform:uppercase;letter-spacing:.07em;border-bottom:1.5px solid var(--gray-200);white-space:nowrap}
    .tbl td{padding:12px 14px;border-bottom:1px solid var(--gray-100);vertical-align:top}
    .tbl tr:hover td{background:#fbfdff}
    .name{font-size:.84rem;font-weight:800;color:var(--gray-800)}
    .muted{font-size:.72rem;color:var(--gray-400)}
    .purpose{font-size:.78rem;color:var(--gray-600);line-height:1.5;max-width:360px;white-space:normal}
    .badge{display:inline-block;padding:4px 8px;border-radius:20px;font-size:.68rem;font-weight:800}
    .pending{background:#fef3c7;color:#92400e}
    .approved{background:#dcfce7;color:#166534}
    .denied{background:#fee2e2;color:#991b1b}
    .cancelled{background:var(--gray-100);color:#64748b}
    .acts{display:flex;gap:6px;flex-wrap:wrap}
    .btn-ok{background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff}
    .btn-no{background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff}
    .btn-print{background:linear-gradient(135deg,#2563eb,#1e40af);color:#fff}
    .empty{padding:48px 20px;text-align:center;color:var(--gray-400)}
    .empty .ic{font-size:2.5rem;opacity:.55;margin-bottom:10px}
    @media(max-width:960px){.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.menu-toggle{display:block}.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.main{margin-left:0}.topbar{padding:0 16px}.content{padding:16px}}
  </style>
</head>
<body>
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <img src="../images/Enrile.png" class="brand-logo" alt="LGU" onerror="this.style.display='none'"/>
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
      <a class="nav-item" href="holidays.php"><div class="nav-icon">📅</div>Holidays</a>
      <div class="nav-label">Documents</div>
      <a class="nav-item" href="documents.php"><div class="nav-icon">📄</div>HR Documents</a>
      <a class="nav-item" href="pds_saln.php"><div class="nav-icon">🪪</div>PDS &amp; SALN</a>
      <a class="nav-item" href="service_records.php"><div class="nav-icon">📜</div>Service Records</a>
      <a class="nav-item active" href="coe_requests.php"><div class="nav-icon">📄</div>COE Requests</a>
      <a class="nav-item" href="contracts.php"><div class="nav-icon">📝</div>Contracts</a>
      <div class="nav-label">System</div>
      <a class="nav-item" href="reports.php"><div class="nav-icon">📊</div>Reports</a>
      <a class="nav-item" href="announcements.php"><div class="nav-icon">📢</div>Announcements</a>
      <a class="nav-item" href="archives.php"><div class="nav-icon">🗄️</div>Archives</a>
      <a class="nav-item" href="user_accounts.php"><div class="nav-icon">🔐</div>User Accounts</a>
      <a class="nav-item" href="settings.php"><div class="nav-icon">⚙️</div>Settings</a>
    </nav>
    <div class="sidebar-footer">
      <div class="user-pill">
        <div class="user-avatar"><?= htmlspecialchars($initials) ?></div>
        <div>
          <div class="user-name"><?= htmlspecialchars($adminName) ?></div>
          <div class="user-role"><?= htmlspecialchars($roleLabel) ?></div>
        </div>
      </div>
    </div>
  </aside>

  <div class="main">
    <div class="topbar">
      <button class="menu-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">☰</button>
      <div class="topbar-title">
        <h1>Certificate of Employment Requests</h1>
        <p>Review, approve, deny, and print employee COE requests</p>
      </div>
      <span class="tb-chip" id="tbDate"></span>
    </div>

    <div class="content">
      <div class="stats">
        <div class="stat"><div class="k">Pending</div><div class="v" id="sc-pending">0</div></div>
        <div class="stat"><div class="k">Approved</div><div class="v" id="sc-approved">0</div></div>
        <div class="stat"><div class="k">Denied</div><div class="v" id="sc-denied">0</div></div>
        <div class="stat"><div class="k">Cancelled</div><div class="v" id="sc-cancelled">0</div></div>
      </div>

      <div class="panel">
        <div class="panel-head">
          <div>
            <div class="panel-title">📄 COE Request Queue</div>
            <div class="sub">Employees can file and track requests; only HR/Admin can resolve and print approved requests.</div>
          </div>
        </div>

        <div class="bar">
          <div class="search-box">
            <span style="color:var(--gray-400);">🔍</span>
            <input id="q" type="text" placeholder="Search employee, code, or purpose" oninput="debouncedLoad()" />
          </div>
          <button class="btn btn-outline" onclick="loadRows()">🔄 Refresh</button>
          <div class="tabs" id="tabs">
            <button class="tab active" data-st="all" onclick="setTab('all', this)">All <span class="tc" id="c-all">0</span></button>
            <button class="tab" data-st="pending" onclick="setTab('pending', this)">Pending <span class="tc" id="c-pending">0</span></button>
            <button class="tab" data-st="approved" onclick="setTab('approved', this)">Approved <span class="tc" id="c-approved">0</span></button>
            <button class="tab" data-st="denied" onclick="setTab('denied', this)">Denied <span class="tc" id="c-denied">0</span></button>
            <button class="tab" data-st="cancelled" onclick="setTab('cancelled', this)">Cancelled <span class="tc" id="c-cancelled">0</span></button>
          </div>
        </div>

        <div class="tbl-wrap">
          <table class="tbl">
            <thead>
              <tr>
                <th>Employee</th>
                <th>Purpose</th>
                <th>Filed</th>
                <th>Status</th>
                <th>HR Note</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="listBody">
              <tr><td colspan="6" style="text-align:center;color:var(--gray-400);padding:26px;">Loading…</td></tr>
            </tbody>
          </table>
          <div id="empty" class="empty" style="display:none;"><div class="ic">📭</div><p>No COE requests found.</p></div>
        </div>
      </div>
    </div>
  </div>

<script>
const API = 'coe_requests.php';
const CSRF_TOKEN = document.querySelector('meta[name=csrf-token]').content;
const CAN_RESOLVE = <?= $canResolve ? 'true' : 'false' ?>;
let curTab = 'all';
let tmr = null;

document.getElementById('tbDate').textContent = new Date().toLocaleDateString('en-PH',{weekday:'short',year:'numeric',month:'short',day:'numeric'});

function badgeCls(st) {
  const s = (st || '').toLowerCase();
  if (s === 'approved') return 'approved';
  if (s === 'denied') return 'denied';
  if (s === 'cancelled') return 'cancelled';
  return 'pending';
}

function esc(v) {
  return String(v ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function setTab(tab, el) {
  curTab = tab;
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  loadRows();
}

function debouncedLoad() {
  clearTimeout(tmr);
  tmr = setTimeout(loadRows, 280);
}

async function resolveRequest(id, status) {
  if (!CAN_RESOLVE) return;
  const remarks = prompt(status === 'Approved' ? 'Optional approval remarks:' : 'Reason for denial (optional):') || '';
  const fd = new FormData();
  fd.append('action', 'resolve_coe_request');
  fd.append('coe_request_id', String(id));
  fd.append('status', status);
  fd.append('admin_remarks', remarks);
  fd.append('csrf_token', CSRF_TOKEN);
  const res = await fetch(API, { method: 'POST', body: fd });
  const json = await res.json();
  if (!json.success) {
    alert(json.message || 'Failed to resolve request.');
    return;
  }
  loadRows();
}

function printRequest(id) {
  if (!CAN_RESOLVE) {
    alert('Only HR/Admin can print COE.');
    return;
  }
  let frame = document.getElementById('coePrintFrame');
  if (!frame) {
    frame = document.createElement('iframe');
    frame.id = 'coePrintFrame';
    frame.style.position = 'fixed';
    frame.style.right = '0';
    frame.style.bottom = '0';
    frame.style.width = '0';
    frame.style.height = '0';
    frame.style.border = '0';
    frame.style.visibility = 'hidden';
    document.body.appendChild(frame);
  }

  frame.onload = function () {
    try {
      frame.contentWindow.focus();
      setTimeout(() => frame.contentWindow.print(), 300);
    } catch (e) {
      alert('Unable to open print preview. Please try again.');
    }
  };

  frame.src = `${API}?action=print_coe&coe_request_id=${id}&_t=${Date.now()}`;
}

async function loadRows() {
  const q = document.getElementById('q').value.trim();
  const list = document.getElementById('listBody');
  const empty = document.getElementById('empty');
  list.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--gray-400);padding:26px;">Loading…</td></tr>';
  empty.style.display = 'none';

  const res = await fetch(`${API}?action=list_coe_requests&status=${encodeURIComponent(curTab)}&search=${encodeURIComponent(q)}`);
  const json = await res.json();
  if (!json.success) {
    list.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#dc2626;padding:26px;">Failed to load records.</td></tr>';
    return;
  }

  const c = json.counts || {};
  document.getElementById('c-pending').textContent = c.Pending || 0;
  document.getElementById('c-approved').textContent = c.Approved || 0;
  document.getElementById('c-denied').textContent = c.Denied || 0;
  document.getElementById('c-cancelled').textContent = c.Cancelled || 0;
  document.getElementById('c-all').textContent = (c.Pending || 0) + (c.Approved || 0) + (c.Denied || 0) + (c.Cancelled || 0);
  document.getElementById('sc-pending').textContent = c.Pending || 0;
  document.getElementById('sc-approved').textContent = c.Approved || 0;
  document.getElementById('sc-denied').textContent = c.Denied || 0;
  document.getElementById('sc-cancelled').textContent = c.Cancelled || 0;

  const rows = json.data || [];
  if (!rows.length) {
    list.innerHTML = '';
    empty.style.display = '';
    return;
  }

  list.innerHTML = rows.map(r => {
    const fullName = `${r.first_name || ''} ${r.middle_name || ''} ${r.last_name || ''}`.replace(/\s+/g, ' ').trim();
    const rem = r.admin_remarks ? `${esc(r.admin_remarks)}` : '<span class="muted">—</span>';
    let actions = '<span class="muted">No actions</span>';
    if (r.status === 'Pending' && CAN_RESOLVE) {
      actions = `<div class="acts"><button class="btn btn-ok" onclick="resolveRequest(${r.coe_request_id}, 'Approved')">Approve</button><button class="btn btn-no" onclick="resolveRequest(${r.coe_request_id}, 'Denied')">Deny</button></div>`;
    } else if (r.status === 'Approved') {
      actions = `<button class="btn btn-print" onclick="printRequest(${r.coe_request_id})">Print COE</button>`;
    }

    const filed = r.filed_at ? new Date(r.filed_at.replace(' ', 'T')).toLocaleDateString('en-PH', { year:'numeric', month:'short', day:'numeric' }) : '—';

    return `<tr>
      <td>
        <div class="name">${esc(fullName)}</div>
        <div class="muted">${esc(r.employee_code || 'N/A')} · ${esc(r.position_title || 'Employee')} · ${esc(r.department_name || 'No Department')}</div>
      </td>
      <td><div class="purpose">${esc(r.purpose)}</div></td>
      <td><span class="muted">${filed}</span></td>
      <td><span class="badge ${badgeCls(r.status)}">${esc(r.status)}</span></td>
      <td><span class="muted" style="color:var(--gray-600)">${rem}</span></td>
      <td>${actions}</td>
    </tr>`;
  }).join('');
}

loadRows();
</script>
</body>
</html>
