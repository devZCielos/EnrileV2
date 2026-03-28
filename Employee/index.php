<?php
// ============================================================
//  LGU ENRILE HRMS — Employee Portal Main
//  File: Employee/index.php
// ============================================================
require_once __DIR__ . '/_shared/auth.php';
require_once __DIR__ . '/_shared/helpers.php';

$displayName = !empty($_SESSION['user_name']) ? ucwords($_SESSION['user_name']) : 'Employee';
$isSwitched  = !empty($_SESSION['switched_from_admin']);
$origRole    = $isSwitched ? ($_SESSION['original_role'] ?? 'admin') : '';
$roleLabels  = ['admin'=>'Administrator','hr_staff'=>'HR Staff','dept_head'=>'Department Head'];
$origRoleLabel = $roleLabels[$origRole] ?? ucfirst($origRole);

// Employee Notifications (loaded inline)
$empNotifications = [];
$empNotifCount = 0;
if (isset($pdo) && isset($employeeId) && $employeeId) {
    try {
        // Leave status changes (last 30 days)
        $lrS = $pdo->prepare("SELECT lr.status, lr.reviewed_at, lt.name AS lt, lr.days_requested
            FROM leave_requests lr JOIN leave_types lt ON lt.leave_type_id = lr.leave_type_id
            WHERE lr.employee_id = ? AND lr.status IN ('Approved','Denied')
            AND lr.reviewed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY lr.reviewed_at DESC LIMIT 5");
        $lrS->execute([$employeeId]);
        foreach ($lrS->fetchAll(PDO::FETCH_ASSOC) as $lr) {
            $ok = $lr['status'] === 'Approved';
            $empNotifications[] = [
                'icon' => $ok ? '✅' : '✗',
                'color' => $ok ? '#22c55e' : '#ef4444',
                'title' => 'Leave ' . $lr['status'],
                'body' => 'Your ' . htmlspecialchars($lr['lt']) . ' request (' . (int)$lr['days_requested'] . ' day' . ((int)$lr['days_requested'] !== 1 ? 's' : '') . ') was ' . strtolower($lr['status']),
                'time' => $lr['reviewed_at'],
                'page' => 'requests'
            ];
        }
        $empNotifCount = count($empNotifications);
    } catch (Exception $ex) {
        $empNotifCount = 0;
    }
}
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
    
    .toast{position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:var(--r-sm);font-size:.83rem;font-weight:700;box-shadow:var(--sh-lg);z-index:999;color:#fff;animation:fadeUp .3s ease both;max-width:340px;}
    .toast.success{background:linear-gradient(135deg,#22c55e,#15803d);}
    .toast.error{background:linear-gradient(135deg,#ef4444,#dc2626);}
    .toast.info{background:linear-gradient(135deg,#2563eb,#1e40af);}

    .overlay-mob{display:none;position:fixed;inset:0;background:rgba(0,0,0,.48);z-index:250;}
    .overlay-mob.show{display:block;}

    /* RESPONSIVE */
    @media(max-width:768px){
      .sidebar{transform:translateX(-100%);width:220px;z-index:300;}
      .sidebar.open{transform:translateX(0);}
      .main{margin-left:0;}
      .menu-toggle{display:block;}
      .page{padding:14px;}
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
      <div class="sb-upos" id="sbPos">Loading…</div>
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
<div class="overlay-mob" id="mobOverlay" onclick="toggleSidebar()"></div>

<!-- MAIN -->
<div class="main">
  <div class="topbar">
    <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
    <div class="tb-left">
      <div class="tb-title" id="tbTitle">Dashboard</div>
      <div class="tb-sub" id="tbSub">Welcome back!</div>
    </div>
    <div class="tb-right">
      <div class="tb-chip" id="tbDate"></div>
      <button class="tb-btn" onclick="toggleNotifications()" id="notifBtn" title="Notifications">
        🔔<span id="notifBadge" class="notif <?= $empNotifCount > 0 ? 'show' : '' ?>"></span>
      </button>
      <button class="tb-btn" onclick="cwToggle()" id="cwToggleBtn" title="Chat">💬</button>
    </div>
  </div>

  <?php if ($isSwitched): ?>
  <div style="position:sticky;top:var(--topbar-h);z-index:190;background:linear-gradient(135deg,#78350f,#92400e,#b45309);color:#fff;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 26px;font-family:'Nunito',sans-serif;box-shadow:0 3px 14px rgba(0,0,0,.25);flex-wrap:wrap;">
    <div style="display:flex;align-items:center;gap:10px;">
      <div style="font-size:1.3rem;">🔄</div>
      <div>
        <strong style="display:block;font-size:.90rem;font-weight:800;">You are viewing the Employee Portal as yourself</strong>
        <span style="font-size:.75rem;opacity:.90;font-weight:600;">Signed in as <?= htmlspecialchars($origRoleLabel) ?></span>
      </div>
    </div>
    <a href="../Admin/switch_back_admin.php" style="display:flex;align-items:center;gap:7px;padding:8px 18px;background:rgba(255,255,255,.15);border:1.5px solid rgba(255,255,255,.45);border-radius:10px;color:#fff;font-family:'Nunito',sans-serif;font-size:.82rem;font-weight:800;cursor:pointer;text-decoration:none;transition:all .2s;white-space:nowrap;">
      🏛️ Return to Admin Panel
    </a>
  </div>
  <?php endif; ?>

  <!-- PAGE CONTAINERS -->
  <div class="page active" id="page-dashboard"></div>
  <div class="page" id="page-profile"></div>
  <div class="page" id="page-requests"></div>
  <div class="page" id="page-file"></div>
  <div class="page" id="page-balances"></div>
  <div class="page" id="page-documents"></div>
  <div class="page" id="page-pds"></div>
  <div class="page" id="page-saln"></div>
  <div class="page" id="page-service_record"></div>
</div>

<script>
const CSRF_TOKEN = '<?= csrf_token() ?>';
const EMPLOYMENT_TYPE = '<?= $employmentType ?>';

// ── PAGE NAVIGATION ──
const PAGE_META = {
  dashboard: ['Dashboard', 'Welcome back!'],
  profile: ['My Profile', 'Personal & employment information'],
  requests: ['My Leave Requests', 'Track all your leave applications'],
  file: ['File Leave Request', 'Submit a new leave application'],
  balances: ['Leave Balances', 'Your available credits for CY <?= date('Y') ?>'],
  documents: ['My Documents', 'View and manage your HR files'],
  pds: ['Personal Data Sheet', 'CS Form No. 212 (Revised 2017)'],
  saln: ['SALN', 'Statement of Assets, Liabilities and Net Worth'],
  service_record: ['Service Record', 'Your government service history'],
};

function goPage(name, btn) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.sb-item').forEach(b => b.classList.remove('active'));
  document.getElementById(`page-${name}`).classList.add('active');
  if (btn) btn.classList.add('active');
  document.getElementById('tbTitle').textContent = PAGE_META[name][0];
  document.getElementById('tbSub').textContent = PAGE_META[name][1];
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('mobOverlay').classList.remove('show');
  
  // Load page content
  loadPageContent(name);
}

function loadPageContent(pageName) {
  const pageContainer = document.getElementById(`page-${pageName}`);
  if (!pageContainer || pageContainer.innerHTML.trim() !== '') return;
  
  // Load from individual page files
  fetch(`${pageName}.php?action=render`)
    .then(r => r.text())
    .then(html => {
      pageContainer.innerHTML = html;
      // Execute any scripts in the loaded HTML
      const scripts = pageContainer.querySelectorAll('script');
      scripts.forEach(script => {
        eval(script.textContent);
      });
    })
    .catch(err => console.error(`Failed to load ${pageName}:`, err));
}

function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('mobOverlay').classList.toggle('show');
}

function toggleNotifications() {
  // Will be implemented per page
}

function showToast(message, type = 'info') {
  const toast = document.createElement('div');
  toast.className = `toast ${type}`;
  toast.textContent = message;
  document.body.appendChild(toast);
  setTimeout(() => toast.remove(), 3000);
}

function cwToggle() {
  console.log('Chat toggle');
}

// Initialize on load
document.addEventListener('DOMContentLoaded', function() {
  const now = new Date();
  document.getElementById('tbDate').textContent = now.toLocaleDateString('en-PH', {
    weekday: 'short',
    month: 'short',
    day: 'numeric',
    year: 'numeric'
  });
  
  // Load dashboard on startup
  goPage('dashboard', document.querySelector('.sb-item'));
});
</script>
</body>
</html>
