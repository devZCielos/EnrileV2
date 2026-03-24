<?php
// ============================================================
//  LGU ENRILE HRMS — Admin Dashboard
//  Session guard: only admin, hr_staff, and dept_head allowed
// ============================================================
session_start();

// 1. Must be logged in
if (empty($_SESSION['user_id']) || empty($_SESSION['user_email'])) {
    header('Location: ../Login/login.php');
    exit();
}

// 2. Must have an admin-level role
$allowedRoles = ['admin', 'hr_staff', 'dept_head'];
if (!in_array($_SESSION['user_role'] ?? '', $allowedRoles)) {
    // Employees who somehow land here go back to their portal
    header('Location: ../Employee/Employee_portal.php');
    exit();
}

// 3. Session timeout — log out after 8 hours of inactivity
$sessionLifetime = 8 * 60 * 60; // 8 hours in seconds
if (isset($_SESSION['login_time'])) {
    $idle = time() - strtotime($_SESSION['login_time']);
    if ($idle > $sessionLifetime) {
        session_unset();
        session_destroy();
        header('Location: ../Login/login.php?error=' . urlencode('Your session has expired. Please log in again.'));
        exit();
    }
}

// Display name & role label for the sidebar
$adminName  = !empty($_SESSION['user_name']) ? ucwords($_SESSION['user_name']) : 'Administrator';
$roleLabels = [
    'admin'     => 'Super Admin',
    'hr_staff'  => 'HR Staff',
    'dept_head' => 'Department Head',
];
$roleLabel = $roleLabels[$_SESSION['user_role']] ?? 'Admin';
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
    .content { padding: 24px 28px; flex: 1; }

    /* ══════════════════════════════════
       SUMMARY CARDS
    ══════════════════════════════════ */
    .cards-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 18px;
      margin-bottom: 22px;
    }

    .card {
      background: var(--white);
      border-radius: var(--radius);
      padding: 20px 20px 18px;
      box-shadow: var(--shadow-sm);
      border: 1px solid var(--gray-200);
      position: relative; overflow: hidden;
      animation: cardIn 0.5s ease both;
      transition: transform 0.22s, box-shadow 0.22s;
      cursor: default;
    }
    .card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); }
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

    .card-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 14px; }

    .card-icon {
      width: 46px; height: 46px; border-radius: 12px;
      display: flex; align-items: center; justify-content: center; font-size: 1.3rem;
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

    .card-value { font-size: 2.1rem; font-weight: 800; color: var(--gray-800); line-height: 1; margin-bottom: 4px; }
    .card-label { font-size: 0.78rem; color: var(--gray-400); font-weight: 600; }

    /* ══════════════════════════════════
       GRID ROWS
    ══════════════════════════════════ */
    .row-2 { display: grid; grid-template-columns: 1fr 330px; gap: 18px; margin-bottom: 22px; }
    .row-3 { display: grid; grid-template-columns: 1fr 1fr 330px; gap: 18px; margin-bottom: 22px; }
    .row-4 { display: grid; grid-template-columns: 1fr 340px; gap: 18px; }

    /* ══════════════════════════════════
       PANEL (card wrapper)
    ══════════════════════════════════ */
    .panel {
      background: var(--white);
      border-radius: var(--radius);
      border: 1px solid var(--gray-200);
      box-shadow: var(--shadow-sm);
      overflow: hidden;
      animation: cardIn 0.5s 0.22s ease both;
    }

    .panel-header {
      display: flex; align-items: center; justify-content: space-between;
      padding: 16px 20px 13px;
      border-bottom: 1px solid var(--gray-100);
    }
    .panel-title { font-size: 0.92rem; font-weight: 800; color: var(--gray-800); }
    .panel-sub   { font-size: 0.72rem; color: var(--gray-400); margin-top: 1px; }
    .panel-action {
      font-size: 0.76rem; color: var(--primary); font-weight: 700;
      text-decoration: none; cursor: pointer; transition: color 0.2s;
    }
    .panel-action:hover { color: var(--primary-dark); }

    /* CHART */
    .chart-wrap { padding: 16px 20px; }

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
      width: 32px; height: 32px; border-radius: 50%;
      background: linear-gradient(135deg, var(--primary), #60a5fa);
      display: flex; align-items: center; justify-content: center;
      font-size: 0.70rem; font-weight: 800; color: white; flex-shrink: 0;
    }
    .emp-name { font-weight: 700; font-size: 0.82rem; }
    .emp-dept { font-size: 0.68rem; color: var(--gray-400); }

    .badge-status {
      display: inline-flex; align-items: center; gap: 4px;
      padding: 3px 9px; border-radius: 20px;
      font-size: 0.68rem; font-weight: 700; white-space: nowrap;
    }
    .badge-status.pending  { background: #fef3c7; color: #92400e; }
    .badge-status.approved { background: #dcfce7; color: #166534; }
    .badge-status.denied   { background: #fee2e2; color: #991b1b; }

    .badge-leave {
      padding: 2px 8px; border-radius: 6px;
      font-size: 0.68rem; font-weight: 700;
      background: #dbeafe; color: #1e40af;
    }

    .action-btns { display: flex; gap: 5px; }
    .act-btn {
      padding: 4px 9px; border-radius: 6px; border: none;
      font-size: 0.70rem; font-weight: 700; cursor: pointer; transition: all 0.2s;
    }
    .act-btn.approve { background: #dcfce7; color: #166534; }
    .act-btn.approve:hover { background: #22c55e; color: white; }
    .act-btn.deny    { background: #fee2e2; color: #991b1b; }
    .act-btn.deny:hover { background: #ef4444; color: white; }

    /* ══════════════════════════════════
       ACTIVITY LOG
    ══════════════════════════════════ */
    .activity-list { padding: 10px 16px; max-height: 320px; overflow-y: auto; }

    .activity-item {
      display: flex; gap: 11px;
      padding: 11px 0; border-bottom: 1px solid var(--gray-100);
      animation: cardIn 0.4s ease both;
    }
    .activity-item:last-child { border-bottom: none; }

    .act-dot {
      width: 32px; height: 32px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 0.85rem; flex-shrink: 0; margin-top: 1px;
    }
    .act-dot.green  { background: #dcfce7; }
    .act-dot.blue   { background: #dbeafe; }
    .act-dot.orange { background: #fef3c7; }
    .act-dot.red    { background: #fee2e2; }

    .act-text { flex: 1; }
    .act-text strong { font-size: 0.80rem; font-weight: 700; color: var(--gray-800); display: block; }
    .act-text p { font-size: 0.72rem; color: var(--gray-400); margin-top: 1px; }
    .act-time { font-size: 0.66rem; color: var(--gray-400); white-space: nowrap; margin-top: 2px; }

    /* ══════════════════════════════════
       ANNOUNCEMENTS
    ══════════════════════════════════ */
    .announce-list { padding: 10px 16px; }

    .announce-item {
      display: flex; gap: 12px;
      padding: 12px 0; border-bottom: 1px solid var(--gray-100);
    }
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
    .announce-title { font-size: 0.82rem; font-weight: 700; color: var(--gray-800); }
    .announce-text  { font-size: 0.72rem; color: var(--gray-600); margin-top: 2px; line-height: 1.5; }
    .announce-meta  { font-size: 0.66rem; color: var(--gray-400); margin-top: 4px; }

    .announce-tag {
      display: inline-block; padding: 1px 7px; border-radius: 5px;
      font-size: 0.62rem; font-weight: 800; margin-right: 4px;
    }
    .announce-tag.info    { background: #dbeafe; color: #1e40af; }
    .announce-tag.success { background: #dcfce7; color: #166534; }
    .announce-tag.warning { background: #fef3c7; color: #92400e; }
    .announce-tag.urgent  { background: #fee2e2; color: #991b1b; }

    /* ADD ANNOUNCEMENT FORM */
    .announce-form {
      padding: 12px 16px 14px;
      border-top: 1px solid var(--gray-100);
      background: var(--gray-50);
    }
    .announce-form textarea {
      width: 100%; padding: 9px 12px;
      border: 1.5px solid var(--gray-200); border-radius: var(--radius-sm);
      font-size: 0.80rem; font-family: 'Nunito', sans-serif;
      resize: none; height: 60px;
      transition: border-color 0.2s;
      background: white;
    }
    .announce-form textarea:focus { outline: none; border-color: var(--primary); }
    .announce-form-actions { display: flex; gap: 8px; margin-top: 8px; align-items: center; }
    .announce-form select {
      flex: 1; padding: 7px 10px;
      border: 1.5px solid var(--gray-200); border-radius: var(--radius-sm);
      font-size: 0.76rem; font-family: 'Nunito', sans-serif;
      color: var(--gray-600); background: white;
      cursor: pointer;
    }
    .announce-form select:focus { outline: none; border-color: var(--primary); }
    .post-btn {
      padding: 7px 16px;
      background: linear-gradient(135deg, var(--primary), var(--primary-dark));
      color: white; border: none; border-radius: var(--radius-sm);
      font-size: 0.76rem; font-weight: 700; font-family: 'Nunito', sans-serif;
      cursor: pointer; transition: all 0.2s; white-space: nowrap;
    }
    .post-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(37,99,235,0.35); }

    /* LEAVE TYPE MINI CHART */
    .mini-chart-wrap { padding: 16px 20px; }
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
      <a class="nav-item active" href="dashboard.php">
        <div class="nav-icon">🏠</div> Dashboard
      </a>

      <div class="nav-label">Employee</div>
      <a class="nav-item" href="employees.php">
        <div class="nav-icon">👥</div> Employees
      </a>
      <!--<a class="nav-item" href="add_employee.php">
        <div class="nav-icon">➕</div> Add Employee
      </a>-->
      <a class="nav-item" href="departments.php">
        <div class="nav-icon">🏢</div> Departments
      </a>

      <div class="nav-label">Leave</div>
      <a class="nav-item" href="leave_requests.php">
        <div class="nav-icon">📋</div> Leave Requests
        <span class="nav-badge">5</span>
      </a>
      <a class="nav-item" href="leave_types.php">
        <div class="nav-icon">📁</div> Leave Types
      </a>
      <a class="nav-item" href="leave_balances.php">
        <div class="nav-icon">⚖️</div> Leave Balances
      </a>

      <div class="nav-label">Documents</div>
      <a class="nav-item" href="documents.php">
        <div class="nav-icon">📄</div> HR Documents
      </a>
      <a class="nav-item" href="contracts.php">
        <div class="nav-icon">📝</div> Contracts
      </a>

      <div class="nav-label">System</div>
      <a class="nav-item" href="reports.php">
        <div class="nav-icon">📊</div> Reports
      </a>
      <a class="nav-item" href="announcements.php">
        <div class="nav-icon">📢</div> Announcements
      </a>
      <a class="nav-item" href="user_accounts.php">
        <div class="nav-icon">🔐</div> User Accounts
      </a>
      <a class="nav-item" href="settings.php">
        <div class="nav-icon">⚙️</div> Settings
      </a>
    </nav>

    <div class="sidebar-footer">
      <div class="sidebar-user">
        <div class="user-avatar">AD</div>
        <div class="user-info">
          <strong><?= htmlspecialchars($adminName) ?></strong>
          <span><?= htmlspecialchars($roleLabel) ?></span>
        </div>
        <a href="../Landingpage/logout.php" title="Logout"
           style="color:rgba(255,255,255,0.45);font-size:1.05rem;text-decoration:none;margin-left:auto;">🚪</a>
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
        <button class="topbar-btn" title="Notifications">
          🔔 <span class="badge">5</span>
        </button>
        <button class="topbar-btn" title="Messages">💬</button>
        <button class="topbar-btn" title="Profile">👤</button>
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
          <div class="card-value">48</div>
          <div class="card-label">Total Employees</div>
        </div>
        <div class="card orange">
          <div class="card-header">
            <div class="card-icon">⏳</div>
            <span class="card-trend warn">⚠ Action needed</span>
          </div>
          <div class="card-value">5</div>
          <div class="card-label">Pending Leave Requests</div>
        </div>
        <div class="card green">
          <div class="card-header">
            <div class="card-icon">✅</div>
            <span class="card-trend up">This month</span>
          </div>
          <div class="card-value">12</div>
          <div class="card-label">Approved Leaves</div>
        </div>
        <div class="card red">
          <div class="card-header">
            <div class="card-icon">📅</div>
            <span class="card-trend down">Today</span>
          </div>
          <div class="card-value">3</div>
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
            <canvas id="leaveChart" height="95"></canvas>
          </div>
        </div>

        <!-- Quick Actions -->
        <div class="panel">
          <div class="panel-header">
            <div>
              <div class="panel-title">⚡ Quick Actions</div>
              <div class="panel-sub">Frequently used shortcuts</div>
            </div>
          </div>
          <div class="quick-actions">
            <a class="qa-btn" href="add_employee.php">
              <div class="qa-icon" style="background:#dbeafe;">➕</div>
              <span>Add Employee</span>
            </a>
            <a class="qa-btn" href="leave_requests.php">
              <div class="qa-icon" style="background:#fef3c7;">📋</div>
              <span>Leave Requests</span>
            </a>
            <a class="qa-btn" href="documents.php">
              <div class="qa-icon" style="background:#dcfce7;">📄</div>
              <span>Upload Document</span>
            </a>
            <a class="qa-btn" href="reports.php">
              <div class="qa-icon" style="background:#f3e8ff;">📊</div>
              <span>Generate Report</span>
            </a>
            <a class="qa-btn" href="user_accounts.php">
              <div class="qa-icon" style="background:#fee2e2;">🔐</div>
              <span>Manage Accounts</span>
            </a>
            <a class="qa-btn" href="announcements.php">
              <div class="qa-icon" style="background:#e0f2fe;">📢</div>
              <span>Post Notice</span>
            </a>
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
              <canvas id="donutChart" height="130"></canvas>
            </div>
            <div class="leave-type-bars">
              <div class="lbar-row">
                <div class="lbar-label">Vacation</div>
                <div class="lbar-track"><div class="lbar-fill" style="width:72%;background:linear-gradient(90deg,#2563eb,#60a5fa);"></div></div>
                <div class="lbar-count">36</div>
              </div>
              <div class="lbar-row">
                <div class="lbar-label">Sick</div>
                <div class="lbar-track"><div class="lbar-fill" style="width:48%;background:linear-gradient(90deg,#f59e0b,#fcd34d);"></div></div>
                <div class="lbar-count">24</div>
              </div>
              <div class="lbar-row">
                <div class="lbar-label">Emergency</div>
                <div class="lbar-track"><div class="lbar-fill" style="width:22%;background:linear-gradient(90deg,#ef4444,#fca5a5);"></div></div>
                <div class="lbar-count">11</div>
              </div>
              <div class="lbar-row">
                <div class="lbar-label">Maternity</div>
                <div class="lbar-track"><div class="lbar-fill" style="width:14%;background:linear-gradient(90deg,#a855f7,#d8b4fe);"></div></div>
                <div class="lbar-count">7</div>
              </div>
              <div class="lbar-row">
                <div class="lbar-label">Paternity</div>
                <div class="lbar-track"><div class="lbar-fill" style="width:8%;background:linear-gradient(90deg,#22c55e,#86efac);"></div></div>
                <div class="lbar-count">4</div>
              </div>
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

      <!-- ROW 4 — LEAVE REQUESTS TABLE + ACTIVITY LOG -->
      <div class="row-4">

        <!-- Leave Requests Table -->
        <div class="panel">
          <div class="panel-header">
            <div>
              <div class="panel-title">📋 Pending Leave Requests</div>
              <div class="panel-sub">Awaiting your approval</div>
            </div>
            <a class="panel-action" href="leave_requests.php">See all →</a>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Employee</th>
                  <th>Type</th>
                  <th>Duration</th>
                  <th>Days</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody id="leaveTable">
                <tr>
                  <td><div class="emp-cell"><div class="emp-avatar">MR</div><div><div class="emp-name">Maria Reyes</div><div class="emp-dept">Finance</div></div></div></td>
                  <td><span class="badge-leave">Vacation</span></td>
                  <td style="font-size:0.76rem;color:var(--gray-600);">Mar 10–12</td>
                  <td style="font-weight:800;">3</td>
                  <td><span class="badge-status pending">⏳ Pending</span></td>
                  <td><div class="action-btns"><button class="act-btn approve" onclick="processLeave(this,'approve')">✓</button><button class="act-btn deny" onclick="processLeave(this,'deny')">✗</button></div></td>
                </tr>
                <tr>
                  <td><div class="emp-cell"><div class="emp-avatar" style="background:linear-gradient(135deg,#f59e0b,#fcd34d);">JD</div><div><div class="emp-name">Juan Dela Cruz</div><div class="emp-dept">Engineering</div></div></div></td>
                  <td><span class="badge-leave" style="background:#fef3c7;color:#92400e;">Sick</span></td>
                  <td style="font-size:0.76rem;color:var(--gray-600);">Mar 8–9</td>
                  <td style="font-weight:800;">2</td>
                  <td><span class="badge-status pending">⏳ Pending</span></td>
                  <td><div class="action-btns"><button class="act-btn approve" onclick="processLeave(this,'approve')">✓</button><button class="act-btn deny" onclick="processLeave(this,'deny')">✗</button></div></td>
                </tr>
                <tr>
                  <td><div class="emp-cell"><div class="emp-avatar" style="background:linear-gradient(135deg,#22c55e,#86efac);">AL</div><div><div class="emp-name">Ana Lim</div><div class="emp-dept">Admin Office</div></div></div></td>
                  <td><span class="badge-leave" style="background:#dcfce7;color:#166534;">Emergency</span></td>
                  <td style="font-size:0.76rem;color:var(--gray-600);">Mar 7</td>
                  <td style="font-weight:800;">1</td>
                  <td><span class="badge-status pending">⏳ Pending</span></td>
                  <td><div class="action-btns"><button class="act-btn approve" onclick="processLeave(this,'approve')">✓</button><button class="act-btn deny" onclick="processLeave(this,'deny')">✗</button></div></td>
                </tr>
                <tr>
                  <td><div class="emp-cell"><div class="emp-avatar" style="background:linear-gradient(135deg,#a855f7,#d8b4fe);">RS</div><div><div class="emp-name">Roberto Santos</div><div class="emp-dept">Health Office</div></div></div></td>
                  <td><span class="badge-leave">Vacation</span></td>
                  <td style="font-size:0.76rem;color:var(--gray-600);">Mar 14–18</td>
                  <td style="font-weight:800;">5</td>
                  <td><span class="badge-status pending">⏳ Pending</span></td>
                  <td><div class="action-btns"><button class="act-btn approve" onclick="processLeave(this,'approve')">✓</button><button class="act-btn deny" onclick="processLeave(this,'deny')">✗</button></div></td>
                </tr>
                <tr>
                  <td><div class="emp-cell"><div class="emp-avatar" style="background:linear-gradient(135deg,#ef4444,#fca5a5);">LC</div><div><div class="emp-name">Luisa Cruz</div><div class="emp-dept">MSWD</div></div></div></td>
                  <td><span class="badge-leave" style="background:#fce7f3;color:#9d174d;">Maternity</span></td>
                  <td style="font-size:0.76rem;color:var(--gray-600);">Mar 15–May 15</td>
                  <td style="font-weight:800;">60</td>
                  <td><span class="badge-status pending">⏳ Pending</span></td>
                  <td><div class="action-btns"><button class="act-btn approve" onclick="processLeave(this,'approve')">✓</button><button class="act-btn deny" onclick="processLeave(this,'deny')">✗</button></div></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Activity Log -->
        <div class="panel">
          <div class="panel-header">
            <div>
              <div class="panel-title">🕐 Recent Activity</div>
              <div class="panel-sub">Latest system events</div>
            </div>
            <a class="panel-action" onclick="clearActivity()" style="cursor:pointer;">Clear</a>
          </div>
          <div class="activity-list" id="activityLog">
            <div class="activity-item"><div class="act-dot green">✅</div><div class="act-text"><strong>Leave Approved</strong><p>Pedro Gomez — Vacation Leave (2 days)</p></div><div class="act-time">2m ago</div></div>
            <div class="activity-item"><div class="act-dot blue">👤</div><div class="act-text"><strong>New Employee Added</strong><p>Carlo Mendoza — Engineering Dept.</p></div><div class="act-time">18m ago</div></div>
            <div class="activity-item"><div class="act-dot orange">📋</div><div class="act-text"><strong>Leave Request Filed</strong><p>Maria Reyes — Vacation Leave</p></div><div class="act-time">1h ago</div></div>
            <div class="activity-item"><div class="act-dot red">✗</div><div class="act-text"><strong>Leave Denied</strong><p>Tina Flores — Sick Leave (1 day)</p></div><div class="act-time">2h ago</div></div>
            <div class="activity-item"><div class="act-dot blue">📄</div><div class="act-text"><strong>Document Uploaded</strong><p>Contract — Ana Lim updated</p></div><div class="act-time">3h ago</div></div>
            <div class="activity-item"><div class="act-dot green">🔐</div><div class="act-text"><strong>Account Created</strong><p>New HR Staff account: jcruz</p></div><div class="act-time">Yesterday</div></div>
            <div class="activity-item"><div class="act-dot orange">⚙️</div><div class="act-text"><strong>Settings Updated</strong><p>Leave policy modified by Admin</p></div><div class="act-time">Yesterday</div></div>
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

    // Sidebar toggle
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
          { label: 'Approved', data: [8,5,12,9,14,7,10,6,11,8,9,13], backgroundColor: 'rgba(34,197,94,0.78)', borderRadius: 6 },
          { label: 'Denied',   data: [2,1,3,2,1,3,2,1,2,3,1,2],     backgroundColor: 'rgba(239,68,68,0.68)', borderRadius: 6 },
          { label: 'Pending',  data: [1,2,5,0,2,1,0,3,1,0,2,1],     backgroundColor: 'rgba(245,158,11,0.68)', borderRadius: 6 },
        ]
      },
      options: {
        responsive: true,
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
      data: {
        labels: ['Vacation','Sick','Emergency','Maternity','Paternity'],
        datasets: [{
          data: [36, 24, 11, 7, 4],
          backgroundColor: ['#3b82f6','#f59e0b','#ef4444','#a855f7','#22c55e'],
          borderWidth: 2, borderColor: '#fff',
          hoverOffset: 6
        }]
      },
      options: {
        responsive: true, cutout: '68%',
        plugins: {
          legend: { position:'right', labels:{ font:{family:'Nunito',size:11}, usePointStyle:true, pointStyleWidth:8 } },
          tooltip: { titleFont:{family:'Nunito'}, bodyFont:{family:'Nunito'} }
        }
      }
    });

    // Leave approve / deny
    function processLeave(btn, action) {
      const row = btn.closest('tr');
      const statusCell = row.querySelector('.badge-status');
      const actionsCell = row.querySelector('.action-btns');
      if (action === 'approve') {
        statusCell.className = 'badge-status approved';
        statusCell.textContent = '✓ Approved';
        addActivity('green', '✅', 'Leave Approved', row.querySelector('.emp-name').textContent + ' — leave approved');
      } else {
        statusCell.className = 'badge-status denied';
        statusCell.textContent = '✗ Denied';
        addActivity('red', '✗', 'Leave Denied', row.querySelector('.emp-name').textContent + ' — leave denied');
      }
      actionsCell.innerHTML = '<span style="font-size:0.72rem;color:var(--gray-400);font-weight:700;">Processed</span>';
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
</body>
</html>