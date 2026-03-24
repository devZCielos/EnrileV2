<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Leave Requests | LGU Enrile HRMS</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
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
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Nunito', sans-serif; background: var(--gray-100); color: var(--secondary); display: flex; min-height: 100vh; }

    /* ══ SIDEBAR ══ */
    .sidebar { width:var(--sidebar-w); background:linear-gradient(180deg,#2563eb 0%,#1e40af 55%,#22c55e 100%); display:flex; flex-direction:column; position:fixed; top:0; left:0; height:100vh; z-index:200; transition:transform 0.3s; box-shadow:4px 0 28px rgba(0,0,0,0.20); }
    .sidebar-brand { padding:22px 20px 18px; display:flex; align-items:center; gap:12px; border-bottom:1px solid rgba(255,255,255,0.15); }
    .brand-logo { width:44px; height:44px; border-radius:50%; object-fit:cover; border:2.5px solid rgba(255,255,255,0.70); }
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
    .nav-badge { margin-left:auto; background:#ef4444; color:white; font-size:0.60rem; font-weight:800; padding:2px 6px; border-radius:20px; }
    .sidebar-footer { padding:14px 12px; border-top:1px solid rgba(255,255,255,0.13); }
    .sidebar-user { display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:var(--radius-sm); background:rgba(255,255,255,0.10); }
    .user-avatar { width:36px; height:36px; border-radius:50%; background:rgba(255,255,255,0.25); border:2px solid rgba(255,255,255,0.50); display:flex; align-items:center; justify-content:center; font-weight:800; color:#fff; font-size:0.80rem; flex-shrink:0; }
    .user-info { flex:1; min-width:0; }
    .user-info strong { display:block; font-size:0.82rem; color:#fff; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .user-info span { font-size:0.68rem; color:rgba(255,255,255,0.52); }

    /* ══ MAIN ══ */
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

    /* ══ CONTENT ══ */
    .content { padding:24px 28px; flex:1; }

    /* STAT CARDS */
    .stat-cards { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:22px; }
    .stat-card { background:var(--white); border-radius:var(--radius); padding:18px 20px; border:1.5px solid var(--gray-200); box-shadow:var(--shadow-sm); position:relative; overflow:hidden; animation:fadeIn 0.4s ease both; transition:transform 0.2s, box-shadow 0.2s; }
    .stat-card:hover { transform:translateY(-3px); box-shadow:var(--shadow-md); }
    .stat-card::before { content:''; position:absolute; top:0; left:0; right:0; height:4px; border-radius:var(--radius) var(--radius) 0 0; }
    .stat-card.orange::before { background:linear-gradient(90deg,#f59e0b,#fcd34d); }
    .stat-card.green::before  { background:linear-gradient(90deg,#22c55e,#86efac); }
    .stat-card.red::before    { background:linear-gradient(90deg,#ef4444,#fca5a5); }
    .stat-card.blue::before   { background:linear-gradient(90deg,#2563eb,#60a5fa); }
    .stat-card:nth-child(1){animation-delay:0.05s} .stat-card:nth-child(2){animation-delay:0.10s} .stat-card:nth-child(3){animation-delay:0.15s} .stat-card:nth-child(4){animation-delay:0.20s}
    .stat-card-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
    .stat-icon { width:44px; height:44px; border-radius:11px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; }
    .stat-card.orange .stat-icon { background:#fef3c7; }
    .stat-card.green  .stat-icon { background:#dcfce7; }
    .stat-card.red    .stat-icon { background:#fee2e2; }
    .stat-card.blue   .stat-icon { background:#dbeafe; }
    .stat-value { font-size:2rem; font-weight:800; color:var(--gray-800); line-height:1; margin-bottom:3px; }
    .stat-label { font-size:0.78rem; color:var(--gray-400); font-weight:600; }
    .stat-sub   { font-size:0.70rem; color:var(--gray-400); margin-top:6px; }

    /* TABS */
    .tabs { display:flex; gap:0; background:var(--white); border-radius:var(--radius-sm); border:1.5px solid var(--gray-200); overflow:hidden; box-shadow:var(--shadow-sm); margin-bottom:16px; width:fit-content; }
    .tab-btn { padding:9px 20px; border:none; background:transparent; font-family:'Nunito',sans-serif; font-size:0.84rem; font-weight:700; color:var(--gray-400); cursor:pointer; transition:all 0.2s; display:flex; align-items:center; gap:6px; border-right:1px solid var(--gray-200); white-space:nowrap; }
    .tab-btn:last-child { border-right:none; }
    .tab-btn.active { background:linear-gradient(135deg,var(--primary),var(--primary-dark)); color:white; }
    .tab-btn .count { background:rgba(255,255,255,0.25); padding:1px 6px; border-radius:10px; font-size:0.65rem; font-weight:800; }
    .tab-btn:not(.active) .count { background:var(--gray-100); color:var(--gray-600); }

    /* TOOLBAR */
    .toolbar { display:flex; align-items:center; gap:10px; margin-bottom:16px; flex-wrap:wrap; }
    .search-box { display:flex; align-items:center; gap:8px; background:var(--white); border:1.5px solid var(--gray-200); border-radius:var(--radius-sm); padding:9px 14px; flex:1; min-width:200px; max-width:300px; box-shadow:var(--shadow-sm); transition:border-color 0.2s; }
    .search-box:focus-within { border-color:var(--primary); }
    .search-box input { border:none; outline:none; font-family:'Nunito',sans-serif; font-size:0.875rem; width:100%; background:transparent; color:var(--gray-800); }
    .search-box input::placeholder { color:var(--gray-400); }
    .filter-select { padding:9px 12px; border:1.5px solid var(--gray-200); border-radius:var(--radius-sm); font-family:'Nunito',sans-serif; font-size:0.84rem; color:var(--gray-600); background:var(--white); cursor:pointer; box-shadow:var(--shadow-sm); outline:none; transition:border-color 0.2s; }
    .filter-select:focus { border-color:var(--primary); }
    .add-btn { margin-left:auto; display:flex; align-items:center; gap:7px; padding:9px 20px; background:linear-gradient(135deg,var(--primary),var(--primary-dark)); color:white; border:none; border-radius:var(--radius-sm); font-family:'Nunito',sans-serif; font-size:0.875rem; font-weight:700; cursor:pointer; transition:all 0.2s; box-shadow:0 2px 8px rgba(37,99,235,0.30); white-space:nowrap; }
    .add-btn:hover { transform:translateY(-2px); box-shadow:0 4px 14px rgba(37,99,235,0.40); }

    /* TABLE */
    .panel { background:var(--white); border-radius:var(--radius); border:1.5px solid var(--gray-200); box-shadow:var(--shadow-sm); overflow:hidden; animation:fadeIn 0.4s ease both; }
    .table-wrap { overflow-x:auto; }
    table { width:100%; border-collapse:collapse; font-size:0.83rem; }
    thead tr { background:var(--gray-50); }
    th { padding:11px 16px; text-align:left; font-size:0.69rem; font-weight:800; color:var(--gray-400); text-transform:uppercase; letter-spacing:0.07em; border-bottom:1.5px solid var(--gray-200); white-space:nowrap; cursor:pointer; user-select:none; transition:color 0.2s; }
    th:hover { color:var(--primary); }
    td { padding:13px 16px; border-bottom:1px solid var(--gray-100); color:var(--gray-800); vertical-align:middle; }
    tr:last-child td { border-bottom:none; }
    tbody tr { transition:background 0.15s; }
    tbody tr:hover { background:#f0f6ff; }

    .emp-cell { display:flex; align-items:center; gap:10px; }
    .emp-avatar { width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:0.75rem; font-weight:800; color:white; flex-shrink:0; box-shadow:0 2px 5px rgba(0,0,0,0.14); }
    .emp-name { font-weight:700; font-size:0.875rem; }
    .emp-dept { font-size:0.70rem; color:var(--gray-400); margin-top:1px; }

    /* BADGES */
    .badge { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:20px; font-size:0.70rem; font-weight:700; white-space:nowrap; }
    .b-pending  { background:#fef3c7; color:#92400e; }
    .b-approved { background:#dcfce7; color:#166534; }
    .b-denied   { background:#fee2e2; color:#991b1b; }
    .b-cancelled{ background:#f1f5f9; color:#64748b; }
    .b-vacation  { background:#dbeafe; color:#1e40af; }
    .b-sick      { background:#fef3c7; color:#92400e; }
    .b-emergency { background:#fee2e2; color:#991b1b; }
    .b-maternity { background:#fce7f3; color:#9d174d; }
    .b-paternity { background:#dcfce7; color:#166534; }
    .b-casual    { background:#f3e8ff; color:#6b21a8; }

    .days-pill { display:inline-block; padding:3px 10px; background:var(--gray-100); border-radius:20px; font-size:0.72rem; font-weight:800; color:var(--gray-600); }

    /* ACTION BUTTONS */
    .row-actions { display:flex; gap:5px; align-items:center; }
    .act-btn { padding:5px 11px; border-radius:7px; border:none; font-size:0.72rem; font-weight:700; cursor:pointer; transition:all 0.2s; font-family:'Nunito',sans-serif; white-space:nowrap; }
    .act-btn.approve { background:#dcfce7; color:#166534; }
    .act-btn.approve:hover { background:#22c55e; color:white; }
    .act-btn.deny    { background:#fee2e2; color:#991b1b; }
    .act-btn.deny:hover { background:#ef4444; color:white; }
    .icon-btn { width:30px; height:30px; border-radius:7px; border:1.5px solid var(--gray-200); background:var(--white); cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:0.82rem; transition:all 0.2s; }
    .icon-btn.view:hover { border-color:var(--primary); background:#eff6ff; }
    .processed-label { font-size:0.72rem; color:var(--gray-400); font-weight:700; }

    /* PAGINATION */
    .pagination { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-top:1px solid var(--gray-100); font-size:0.80rem; color:var(--gray-400); flex-wrap:wrap; gap:10px; font-weight:600; }
    .page-btns { display:flex; gap:4px; }
    .page-btn { min-width:32px; height:32px; border-radius:7px; border:1.5px solid var(--gray-200); background:var(--white); cursor:pointer; font-size:0.80rem; font-weight:700; display:flex; align-items:center; justify-content:center; padding:0 8px; transition:all 0.2s; color:var(--gray-600); font-family:'Nunito',sans-serif; }
    .page-btn:hover { border-color:var(--primary); color:var(--primary); }
    .page-btn.active { background:var(--primary); border-color:var(--primary); color:white; }
    .page-btn:disabled { opacity:0.4; cursor:default; }

    /* EMPTY */
    .empty-state { padding:52px 20px; text-align:center; }
    .empty-state .ei { font-size:2.8rem; margin-bottom:12px; opacity:0.5; }
    .empty-state p { font-size:0.875rem; font-weight:600; color:var(--gray-400); }

    /* ══ MODALS ══ */
    .modal-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.55); z-index:500; align-items:center; justify-content:center; backdrop-filter:blur(4px); }
    .modal-overlay.open { display:flex; }
    .modal { background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow-lg); width:92%; max-width:580px; max-height:92vh; overflow-y:auto; animation:modalIn 0.3s cubic-bezier(0.34,1.4,0.64,1) both; }
    .modal.sm { max-width:440px; }
    @keyframes modalIn { from{opacity:0;transform:scale(0.88) translateY(20px);}to{opacity:1;transform:scale(1) translateY(0);} }

    .modal-header { display:flex; align-items:center; justify-content:space-between; padding:20px 24px 16px; background:linear-gradient(135deg,#2563eb,#1e40af); border-radius:var(--radius) var(--radius) 0 0; }
    .modal-header.green { background:linear-gradient(135deg,#22c55e,#15803d); }
    .modal-header.red   { background:linear-gradient(135deg,#ef4444,#dc2626); }
    .modal-header h3 { font-size:1.05rem; font-weight:800; color:#fff; font-family:'Playfair Display',serif; }
    .modal-header p  { font-size:0.74rem; color:rgba(255,255,255,0.65); margin-top:2px; }
    .modal-close { width:32px; height:32px; border-radius:50%; background:rgba(255,255,255,0.18); border:none; color:#fff; font-size:1rem; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:background 0.2s; flex-shrink:0; }
    .modal-close:hover { background:rgba(255,255,255,0.32); }
    .modal-body { padding:22px 24px; }
    .modal-footer { display:flex; justify-content:flex-end; gap:10px; padding:14px 24px 18px; border-top:1px solid var(--gray-100); }

    /* FORM */
    .form-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px; }
    .form-full { grid-column:1/-1; }
    .form-group { display:flex; flex-direction:column; gap:5px; }
    .form-group label { font-size:0.76rem; font-weight:700; color:var(--gray-600); }
    .form-group label .req { color:#ef4444; margin-left:2px; }
    .form-group input,
    .form-group select,
    .form-group textarea { padding:9px 12px; border:1.5px solid var(--gray-200); border-radius:var(--radius-sm); font-size:0.875rem; font-family:'Nunito',sans-serif; color:var(--gray-800); background:var(--white); outline:none; transition:border-color 0.2s; }
    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus { border-color:var(--primary); box-shadow:0 0 0 3px rgba(37,99,235,0.10); }
    .form-group input.err,
    .form-group select.err { border-color:#ef4444; }
    .form-group textarea { resize:vertical; height:80px; }

    /* VIEW DETAIL */
    .detail-hero { background:linear-gradient(135deg,#2563eb 0%,#1e40af 60%,#22c55e 100%); padding:24px; border-radius:var(--radius) var(--radius) 0 0; display:flex; align-items:center; gap:16px; }
    .detail-avatar { width:62px; height:62px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:1.3rem; font-weight:800; color:white; border:3px solid rgba(255,255,255,0.70); box-shadow:0 4px 14px rgba(0,0,0,0.20); flex-shrink:0; }
    .detail-info h3 { font-size:1.05rem; font-weight:800; color:#fff; font-family:'Playfair Display',serif; }
    .detail-info p  { font-size:0.76rem; color:rgba(255,255,255,0.65); margin-top:2px; }
    .detail-tags { display:flex; gap:6px; margin-top:8px; flex-wrap:wrap; }
    .dtag { padding:3px 10px; border-radius:20px; font-size:0.70rem; font-weight:700; background:rgba(255,255,255,0.22); color:#fff; }
    .detail-close { width:32px; height:32px; border-radius:50%; background:rgba(255,255,255,0.18); border:none; color:#fff; font-size:1rem; cursor:pointer; display:flex; align-items:center; justify-content:center; margin-left:auto; align-self:flex-start; }
    .detail-close:hover { background:rgba(255,255,255,0.32); }

    .detail-body { padding:20px 24px; }
    .detail-section { font-size:0.72rem; font-weight:800; color:var(--primary); text-transform:uppercase; letter-spacing:0.10em; margin:16px 0 10px; padding-bottom:5px; border-bottom:1.5px solid #dbeafe; }
    .detail-section:first-child { margin-top:0; }
    .detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
    .detail-field { background:var(--gray-50); border-radius:var(--radius-sm); padding:10px 14px; border:1px solid var(--gray-100); }
    .detail-field label { font-size:0.65rem; font-weight:800; color:var(--gray-400); text-transform:uppercase; letter-spacing:0.07em; display:block; margin-bottom:3px; }
    .detail-field span  { font-size:0.84rem; font-weight:700; color:var(--gray-800); }
    .detail-field.full  { grid-column:1/-1; }

    /* REMARKS BOX */
    .remarks-box { background:#fef3c7; border:1.5px solid #fcd34d; border-radius:var(--radius-sm); padding:12px 14px; margin-top:14px; }
    .remarks-box .remarks-label { font-size:0.70rem; font-weight:800; color:#92400e; text-transform:uppercase; letter-spacing:0.07em; margin-bottom:4px; }
    .remarks-box p { font-size:0.82rem; color:#78350f; line-height:1.55; }

    /* APPROVE/DENY CONFIRM */
    .confirm-body { padding:28px 24px; text-align:center; }
    .confirm-icon { font-size:2.6rem; margin-bottom:12px; }
    .confirm-body h4 { font-size:1rem; font-weight:800; color:var(--gray-800); margin-bottom:6px; }
    .confirm-body p  { font-size:0.84rem; color:var(--gray-600); line-height:1.6; }
    .confirm-reason { width:100%; margin-top:14px; padding:9px 12px; border:1.5px solid var(--gray-200); border-radius:var(--radius-sm); font-size:0.84rem; font-family:'Nunito',sans-serif; color:var(--gray-800); resize:none; height:72px; outline:none; }
    .confirm-reason:focus { border-color:var(--primary); }

    /* BUTTONS */
    .btn { padding:9px 22px; border-radius:var(--radius-sm); font-family:'Nunito',sans-serif; font-size:0.875rem; font-weight:700; cursor:pointer; transition:all 0.2s; border:none; }
    .btn-cancel  { background:var(--white); border:1.5px solid var(--gray-200); color:var(--gray-600); }
    .btn-cancel:hover  { border-color:var(--gray-400); background:var(--gray-50); }
    .btn-primary { background:linear-gradient(135deg,var(--primary),var(--primary-dark)); color:white; box-shadow:0 2px 8px rgba(37,99,235,0.28); }
    .btn-primary:hover { transform:translateY(-1px); box-shadow:0 4px 14px rgba(37,99,235,0.38); }
    .btn-success { background:linear-gradient(135deg,#22c55e,#15803d); color:white; }
    .btn-success:hover { transform:translateY(-1px); }
    .btn-danger  { background:linear-gradient(135deg,#ef4444,#dc2626); color:white; }
    .btn-danger:hover  { transform:translateY(-1px); }

    /* TOAST */
    .toast { position:fixed; bottom:28px; right:28px; padding:12px 20px; border-radius:var(--radius-sm); font-size:0.84rem; font-weight:700; box-shadow:var(--shadow-lg); z-index:900; color:white; animation:fadeIn 0.3s ease both; max-width:340px; }
    .toast.success { background:linear-gradient(135deg,#22c55e,#16a34a); }
    .toast.error   { background:linear-gradient(135deg,#ef4444,#dc2626); }
    .toast.info    { background:linear-gradient(135deg,#2563eb,#1e40af); }

    @keyframes fadeIn { from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);} }

    .overlay-mob { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:150; }
    .overlay-mob.show { display:block; }

    /* ══════════════════════════════
       MOBILE CARD VIEW FOR TABLE
    ══════════════════════════════ */
    .leave-card {
      background: var(--white);
      border-radius: var(--radius-sm);
      border: 1.5px solid var(--gray-200);
      padding: 14px 16px;
      display: none;
      flex-direction: column;
      gap: 10px;
      animation: fadeIn 0.3s ease both;
    }
    .leave-card-top { display:flex; align-items:center; justify-content:space-between; gap:10px; }
    .leave-card-emp { display:flex; align-items:center; gap:10px; flex:1; min-width:0; }
    .leave-card-name { font-weight:800; font-size:0.875rem; color:var(--gray-800); }
    .leave-card-dept { font-size:0.70rem; color:var(--gray-400); }
    .leave-card-meta { display:flex; flex-wrap:wrap; gap:6px; align-items:center; }
    .leave-card-dates { font-size:0.78rem; color:var(--gray-600); display:flex; align-items:center; gap:4px; }
    .leave-card-reason { font-size:0.78rem; color:var(--gray-600); line-height:1.5; padding:8px 10px; background:var(--gray-50); border-radius:7px; border-left:3px solid var(--gray-200); }
    .leave-card-actions { display:flex; gap:8px; padding-top:4px; border-top:1px solid var(--gray-100); }
    .leave-card-actions .act-btn { flex:1; text-align:center; padding:7px 10px; }
    .leave-card-actions .icon-btn { flex:0 0 auto; }

    /* ══════════════════════════
       BREAKPOINTS
    ══════════════════════════ */

    /* Large tablets / small desktops */
    @media(max-width:1100px) {
      .stat-cards { grid-template-columns:repeat(2,1fr); }
      .tabs { flex-wrap:wrap; width:100%; }
      .tab-btn { flex:1; justify-content:center; }
    }

    /* Tablets */
    @media(max-width:900px) {
      .toolbar { gap:8px; }
      .search-box { max-width:100%; flex:1 1 100%; order:-1; }
      .filter-select { flex:1; min-width:120px; }
      .add-btn { width:100%; justify-content:center; }
      /* hide lower-priority table columns */
      th:nth-child(6),  /* Reason */
      td:nth-child(6)   { display:none; }
      th:nth-child(7),  /* Filed On */
      td:nth-child(7)   { display:none; }
    }

    /* Mobile */
    @media(max-width:768px) {
      .sidebar { transform:translateX(-100%); }
      .sidebar.open { transform:translateX(0); }
      .main { margin-left:0; }
      .menu-toggle { display:block; }
      .content { padding:14px 12px; }
      .topbar { padding:0 14px; }
      .topbar-title h1 { font-size:1.05rem; }

      /* stat cards 2-col */
      .stat-cards { grid-template-columns:1fr 1fr; gap:10px; }
      .stat-value { font-size:1.6rem; }

      /* tabs scroll horizontally */
      .tabs { overflow-x:auto; flex-wrap:nowrap; width:100%; -webkit-overflow-scrolling:touch; }
      .tabs::-webkit-scrollbar { display:none; }
      .tab-btn { flex-shrink:0; flex:0 0 auto; font-size:0.80rem; padding:8px 14px; }

      /* toolbar stacks */
      .toolbar { flex-direction:column; align-items:stretch; }
      .search-box { max-width:100%; }
      .filter-select { width:100%; }
      .add-btn { margin-left:0; width:100%; justify-content:center; }

      /* HIDE table, SHOW cards */
      .table-wrap table { display:none; }
      .leave-card { display:flex; }

      /* pagination adjusts */
      .pagination { flex-direction:column; align-items:center; gap:8px; text-align:center; }

      /* modals */
      .modal { width:96%; max-height:96vh; }
      .form-row { grid-template-columns:1fr; }
      .detail-grid { grid-template-columns:1fr; }
      .modal-footer { flex-wrap:wrap; gap:8px; }
      .modal-footer .btn { flex:1; text-align:center; }
    }

    /* Small phones */
    @media(max-width:480px) {
      .stat-cards { grid-template-columns:1fr 1fr; gap:8px; }
      .stat-card { padding:14px 14px; }
      .stat-value { font-size:1.4rem; }
      .stat-label { font-size:0.72rem; }
      .content { padding:12px 10px; }
      .topbar { padding:0 12px; }
      .topbar-title p { display:none; }
      .detail-hero { flex-wrap:wrap; }
      .toast { bottom:16px; right:16px; left:16px; max-width:100%; }
    }
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
      <a class="nav-item" href="admin_dashboard.php"><div class="nav-icon">🏠</div>Dashboard</a>
      <div class="nav-label">Employee</div>
      <a class="nav-item" href="employees.php"><div class="nav-icon">👥</div>Employees</a>
      <a class="nav-item" href="departments.php"><div class="nav-icon">🏢</div>Departments</a>
      <div class="nav-label">Leave</div>
      <a class="nav-item active" href="leave_requests.php"><div class="nav-icon">📋</div>Leave Requests<span class="nav-badge" id="sidebarBadge">5</span></a>
      <a class="nav-item" href="leave_types.php"><div class="nav-icon">📁</div>Leave Types</a>
      <a class="nav-item" href="leave_balances.php"><div class="nav-icon">⚖️</div>Leave Balances</a>
      <div class="nav-label">Documents</div>
      <a class="nav-item" href="documents.php"><div class="nav-icon">📄</div>HR Documents</a>
      <a class="nav-item" href="contracts.php"><div class="nav-icon">📝</div>Contracts</a>
      <div class="nav-label">System</div>
      <a class="nav-item" href="reports.php"><div class="nav-icon">📊</div>Reports</a>
      <a class="nav-item" href="announcements.php"><div class="nav-icon">📢</div>Announcements</a>
      <a class="nav-item" href="user_accounts.php"><div class="nav-icon">🔐</div>User Accounts</a>
      <a class="nav-item" href="settings.php"><div class="nav-icon">⚙️</div>Settings</a>
    </nav>
    <div class="sidebar-footer">
      <div class="sidebar-user">
        <div class="user-avatar">AD</div>
        <div class="user-info"><strong>Administrator</strong><span>Super Admin</span></div>
        <a href="logout.php" style="color:rgba(255,255,255,0.45);font-size:1.05rem;text-decoration:none;margin-left:auto;">🚪</a>
      </div>
    </div>
  </aside>
  <div class="overlay-mob" id="overlayMob" onclick="toggleSidebar()"></div>

  <!-- ══ MAIN ══ -->
  <div class="main">
    <div class="topbar">
      <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
      <div class="topbar-title">
        <h1>Leave Requests</h1>
        <p>Review, approve, and manage employee leave applications</p>
      </div>
      <div class="topbar-actions">
        <button class="topbar-btn">🔔<span class="notif-badge" id="topbarBadge">5</span></button>
        <button class="topbar-btn">💬</button>
        <button class="topbar-btn">👤</button>
      </div>
    </div>

    <div class="content">

      <!-- STAT CARDS -->
      <div class="stat-cards">
        <div class="stat-card orange">
          <div class="stat-card-header"><div class="stat-icon">⏳</div></div>
          <div class="stat-value" id="sc-pending">0</div>
          <div class="stat-label">Pending Requests</div>
          <div class="stat-sub">Awaiting approval</div>
        </div>
        <div class="stat-card green">
          <div class="stat-card-header"><div class="stat-icon">✅</div></div>
          <div class="stat-value" id="sc-approved">0</div>
          <div class="stat-label">Approved</div>
          <div class="stat-sub">This month</div>
        </div>
        <div class="stat-card red">
          <div class="stat-card-header"><div class="stat-icon">✗</div></div>
          <div class="stat-value" id="sc-denied">0</div>
          <div class="stat-label">Denied</div>
          <div class="stat-sub">This month</div>
        </div>
        <div class="stat-card blue">
          <div class="stat-card-header"><div class="stat-icon">📅</div></div>
          <div class="stat-value" id="sc-total">0</div>
          <div class="stat-label">Total Requests</div>
          <div class="stat-sub">All time</div>
        </div>
      </div>

      <!-- TABS -->
      <div class="tabs" id="tabsRow">
        <button class="tab-btn active" onclick="setTab('all')"      id="tab-all">All <span class="count" id="tc-all">0</span></button>
        <button class="tab-btn"        onclick="setTab('pending')"  id="tab-pending">⏳ Pending <span class="count" id="tc-pending">0</span></button>
        <button class="tab-btn"        onclick="setTab('approved')" id="tab-approved">✅ Approved <span class="count" id="tc-approved">0</span></button>
        <button class="tab-btn"        onclick="setTab('denied')"   id="tab-denied">✗ Denied <span class="count" id="tc-denied">0</span></button>
        <button class="tab-btn"        onclick="setTab('cancelled')"id="tab-cancelled">— Cancelled <span class="count" id="tc-cancelled">0</span></button>
      </div>

      <!-- TOOLBAR -->
      <div class="toolbar">
        <div class="search-box">
          <span style="color:var(--gray-400);font-size:1rem;">🔍</span>
          <input type="text" id="searchInput" placeholder="Search employee or leave type…" oninput="applyFilters()"/>
        </div>
        <select class="filter-select" id="fDept" onchange="applyFilters()">
          <option value="">All Departments</option>
          <option>Finance</option><option>Engineering</option><option>Admin Office</option>
          <option>Health Office</option><option>MSWD</option><option>MPDO</option><option>Agriculture</option>
        </select>
        <select class="filter-select" id="fType" onchange="applyFilters()">
          <option value="">All Leave Types</option>
          <option>Vacation</option><option>Sick</option><option>Emergency</option>
          <option>Maternity</option><option>Paternity</option><option>Casual</option>
        </select>
        <select class="filter-select" id="fMonth" onchange="applyFilters()">
          <option value="">All Months</option>
          <option value="01">January</option><option value="02">February</option>
          <option value="03">March</option><option value="04">April</option>
          <option value="05">May</option><option value="06">June</option>
          <option value="07">July</option><option value="08">August</option>
          <option value="09">September</option><option value="10">October</option>
          <option value="11">November</option><option value="12">December</option>
        </select>
        <button class="add-btn" onclick="openAdd()">➕ File Leave Request</button>
      </div>

      <!-- TABLE -->
      <div class="panel">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th onclick="sortBy('name')">Employee ↕</th>
                <th onclick="sortBy('type')">Leave Type ↕</th>
                <th onclick="sortBy('from')">Date From ↕</th>
                <th onclick="sortBy('to')">Date To ↕</th>
                <th onclick="sortBy('days')">Days ↕</th>
                <th>Reason</th>
                <th onclick="sortBy('filed')">Filed On ↕</th>
                <th onclick="sortBy('status')">Status ↕</th>
                <th style="text-align:center;">Actions</th>
              </tr>
            </thead>
            <tbody id="leaveBody"></tbody>
          </table>
          <div class="empty-state" id="emptyState" style="display:none;">
            <div class="ei">📋</div>
            <p>No leave requests found.</p>
          </div>
        </div>
        <div class="pagination">
          <span id="pageInfo"></span>
          <div class="page-btns" id="pageBtns"></div>
        </div>
      </div>

    </div>
  </div>

  <!-- ══ FILE LEAVE MODAL ══ -->
  <div class="modal-overlay" id="modalForm">
    <div class="modal">
      <div class="modal-header">
        <div><h3 id="formTitle">📋 File Leave Request</h3><p id="formSub">Submit a new leave application</p></div>
        <button class="modal-close" onclick="closeModal('modalForm')">✕</button>
      </div>
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group">
            <label>Employee <span class="req">*</span></label>
            <select id="fEmployee">
              <option value="">Select employee</option>
              <option>Maria Reyes</option><option>Juan Dela Cruz</option><option>Ana Lim</option>
              <option>Roberto Santos</option><option>Luisa Cruz</option><option>Carlo Mendoza</option>
              <option>Tina Flores</option><option>Pedro Gomez</option><option>Rosario Villanueva</option>
              <option>Dante Aguilar</option><option>Liza Bautista</option><option>Marco Reyes</option>
            </select>
          </div>
          <div class="form-group">
            <label>Department</label>
            <select id="fDeptF">
              <option value="">Select department</option>
              <option>Finance</option><option>Engineering</option><option>Admin Office</option>
              <option>Health Office</option><option>MSWD</option><option>MPDO</option><option>Agriculture</option>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Leave Type <span class="req">*</span></label>
            <select id="fLeaveType">
              <option value="">Select type</option>
              <option>Vacation</option><option>Sick</option><option>Emergency</option>
              <option>Maternity</option><option>Paternity</option><option>Casual</option>
            </select>
          </div>
          <div class="form-group">
            <label>Number of Days <span class="req">*</span></label>
            <input type="number" id="fDays" min="1" placeholder="e.g. 3"/>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Date From <span class="req">*</span></label>
            <input type="date" id="fFrom"/>
          </div>
          <div class="form-group">
            <label>Date To <span class="req">*</span></label>
            <input type="date" id="fTo"/>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group form-full">
            <label>Reason / Remarks</label>
            <textarea id="fReason" placeholder="State the reason for this leave application…"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-cancel" onclick="closeModal('modalForm')">Cancel</button>
        <button class="btn btn-primary" onclick="saveRequest()">📤 Submit Request</button>
      </div>
    </div>
  </div>

  <!-- ══ VIEW DETAIL MODAL ══ -->
  <div class="modal-overlay" id="modalView">
    <div class="modal">
      <div class="detail-hero">
        <div class="detail-avatar" id="dAvatar"></div>
        <div class="detail-info">
          <h3 id="dName"></h3>
          <p  id="dPos"></p>
          <div class="detail-tags" id="dTags"></div>
        </div>
        <button class="detail-close" onclick="closeModal('modalView')">✕</button>
      </div>
      <div class="detail-body">
        <div class="detail-section">📋 Leave Details</div>
        <div class="detail-grid" id="dDetails"></div>
        <div id="dRemarks"></div>
      </div>
      <div class="modal-footer" id="dFooter"></div>
    </div>
  </div>

  <!-- ══ APPROVE CONFIRM ══ -->
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
        <textarea class="confirm-reason" id="approveRemark" placeholder="Optional remarks / notes for the employee…"></textarea>
      </div>
      <div class="modal-footer">
        <button class="btn btn-cancel" onclick="closeModal('modalApprove')">Cancel</button>
        <button class="btn btn-success" onclick="confirmApprove()">✅ Yes, Approve</button>
      </div>
    </div>
  </div>

  <!-- ══ DENY CONFIRM ══ -->
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
        <textarea class="confirm-reason" id="denyRemark" placeholder="Please state the reason for denial…"></textarea>
      </div>
      <div class="modal-footer">
        <button class="btn btn-cancel" onclick="closeModal('modalDeny')">Cancel</button>
        <button class="btn btn-danger" onclick="confirmDeny()">✗ Yes, Deny</button>
      </div>
    </div>
  </div>

  <script>
    /* ── DATA ── */
    const EMP_COLORS = {
      'Maria Reyes':'#2563eb','Juan Dela Cruz':'#f59e0b','Ana Lim':'#22c55e',
      'Roberto Santos':'#a855f7','Luisa Cruz':'#ef4444','Carlo Mendoza':'#06b6d4',
      'Tina Flores':'#ec4899','Pedro Gomez':'#0ea5e9','Rosario Villanueva':'#84cc16',
      'Dante Aguilar':'#f97316','Liza Bautista':'#6366f1','Marco Reyes':'#14b8a6'
    };
    const EMP_DEPT = {
      'Maria Reyes':'Finance','Juan Dela Cruz':'Engineering','Ana Lim':'Admin Office',
      'Roberto Santos':'Health Office','Luisa Cruz':'MSWD','Carlo Mendoza':'Engineering',
      'Tina Flores':'Finance','Pedro Gomez':'MPDO','Rosario Villanueva':'Health Office',
      'Dante Aguilar':'Agriculture','Liza Bautista':'MSWD','Marco Reyes':'Admin Office'
    };
    const EMP_POS = {
      'Maria Reyes':'Accountant I','Juan Dela Cruz':'Engineer II','Ana Lim':'Administrative Aide VI',
      'Roberto Santos':'Nurse I','Luisa Cruz':'Social Worker I','Carlo Mendoza':'Engineering Aide',
      'Tina Flores':'Bookkeeper','Pedro Gomez':'Planning Officer I','Rosario Villanueva':'Midwife I',
      'Dante Aguilar':'Agricultural Technician','Liza Bautista':'Day Care Worker','Marco Reyes':'Driver'
    };

    let requests = [
      {id:1,  emp:'Maria Reyes',       type:'Vacation',  from:'2025-03-10',to:'2025-03-12',days:3,  reason:'Family vacation trip.',                              filed:'2025-03-05',status:'Pending',  remark:''},
      {id:2,  emp:'Juan Dela Cruz',    type:'Sick',      from:'2025-03-08',to:'2025-03-09',days:2,  reason:'Medical consultation and rest.',                      filed:'2025-03-07',status:'Pending',  remark:''},
      {id:3,  emp:'Ana Lim',           type:'Emergency', from:'2025-03-07',to:'2025-03-07',days:1,  reason:'Family emergency.',                                   filed:'2025-03-07',status:'Pending',  remark:''},
      {id:4,  emp:'Roberto Santos',    type:'Vacation',  from:'2025-03-14',to:'2025-03-18',days:5,  reason:'Rest and recreation.',                                filed:'2025-03-06',status:'Pending',  remark:''},
      {id:5,  emp:'Luisa Cruz',        type:'Maternity', from:'2025-03-15',to:'2025-05-14',days:60, reason:'Maternity leave pursuant to RA 11210.',               filed:'2025-03-01',status:'Pending',  remark:''},
      {id:6,  emp:'Carlo Mendoza',     type:'Sick',      from:'2025-02-20',to:'2025-02-21',days:2,  reason:'Flu and fever.',                                      filed:'2025-02-19',status:'Approved', remark:'Approved. Get well soon!'},
      {id:7,  emp:'Tina Flores',       type:'Vacation',  from:'2025-02-14',to:'2025-02-15',days:2,  reason:'Valentine holiday.',                                  filed:'2025-02-10',status:'Approved', remark:'Approved.'},
      {id:8,  emp:'Pedro Gomez',       type:'Vacation',  from:'2025-02-10',to:'2025-02-11',days:2,  reason:'Personal matters.',                                   filed:'2025-02-07',status:'Approved', remark:'Approved. Enjoy!'},
      {id:9,  emp:'Rosario Villanueva',type:'Sick',      from:'2025-01-22',to:'2025-01-22',days:1,  reason:'Hypertension episode.',                               filed:'2025-01-22',status:'Denied',   remark:'Insufficient leave credits. Please file next time.'},
      {id:10, emp:'Dante Aguilar',     type:'Casual',    from:'2025-01-15',to:'2025-01-17',days:3,  reason:'Personal errands.',                                   filed:'2025-01-13',status:'Denied',   remark:'No available leave balance for casual leave.'},
      {id:11, emp:'Liza Bautista',     type:'Emergency', from:'2025-01-10',to:'2025-01-10',days:1,  reason:'Typhoon damage to property.',                         filed:'2025-01-10',status:'Approved', remark:'Approved.'},
      {id:12, emp:'Marco Reyes',       type:'Vacation',  from:'2025-03-20',to:'2025-03-21',days:2,  reason:'Rest after overtime work.',                           filed:'2025-03-08',status:'Cancelled',remark:''},
      {id:13, emp:'Tina Flores',       type:'Sick',      from:'2025-03-04',to:'2025-03-04',days:1,  reason:'Dengue fever check-up.',                              filed:'2025-03-04',status:'Denied',   remark:'Denied due to important office event.'},
      {id:14, emp:'Pedro Gomez',       type:'Paternity', from:'2025-01-05',to:'2025-01-11',days:7,  reason:'Paternity leave pursuant to RA 8187.',                filed:'2024-12-30',status:'Approved', remark:'Approved. Congratulations!'},
    ];

    let currentTab  = 'all';
    let currentPage = 1;
    const PAGE_SIZE = 8;
    let sortKey = 'filed';
    let sortAsc = false;
    let approveTarget = null;
    let denyTarget    = null;

    /* ── helpers ── */
    const ini = n => n.split(' ').map(w=>w[0]).join('').slice(0,2).toUpperCase();
    const fmt = d => d ? new Date(d+'T00:00:00').toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}) : '—';
    const col = emp => EMP_COLORS[emp] || '#2563eb';

    function typeBadge(t) {
      const map={Vacation:'b-vacation',Sick:'b-sick',Emergency:'b-emergency',Maternity:'b-maternity',Paternity:'b-paternity',Casual:'b-casual'};
      return `<span class="badge ${map[t]||'b-vacation'}">${t}</span>`;
    }
    function statusBadge(s) {
      const map={Pending:'b-pending',Approved:'b-approved',Denied:'b-denied',Cancelled:'b-cancelled'};
      const ico={Pending:'⏳',Approved:'✅',Denied:'✗',Cancelled:'—'};
      return `<span class="badge ${map[s]||'b-pending'}">${ico[s]||''} ${s}</span>`;
    }

    /* ── FILTER + SORT ── */
    function getFiltered() {
      const q    = document.getElementById('searchInput').value.toLowerCase();
      const dept = document.getElementById('fDept').value;
      const type = document.getElementById('fType').value;
      const mo   = document.getElementById('fMonth').value;
      return requests.filter(r => {
        const hay = `${r.emp} ${r.type}`.toLowerCase();
        const empDept = EMP_DEPT[r.emp] || '';
        const matchMo = !mo || r.from.slice(5,7) === mo;
        return (!q||hay.includes(q))
          && (!dept||empDept===dept)
          && (!type||r.type===type)
          && matchMo
          && (currentTab==='all'||r.status.toLowerCase()===currentTab);
      });
    }

    function applyFilters() { currentPage=1; render(); }
    function setTab(t) {
      currentTab=t; currentPage=1;
      document.querySelectorAll('.tab-btn').forEach(b=>b.classList.toggle('active', b.id===`tab-${t}`));
      render();
    }

    /* ── SORT ── */
    function sortBy(key) {
      if(sortKey===key) sortAsc=!sortAsc; else {sortKey=key;sortAsc=true;}
      render();
    }
    function sorted(list) {
      const k = {name:r=>r.emp,type:r=>r.type,from:r=>r.from,to:r=>r.to,days:r=>r.days,filed:r=>r.filed,status:r=>r.status}[sortKey];
      if(!k) return list;
      return [...list].sort((a,b)=>{
        const va=k(a),vb=k(b);
        if(typeof va==='number') return sortAsc?va-vb:vb-va;
        return sortAsc?String(va).localeCompare(String(vb)):String(vb).localeCompare(String(va));
      });
    }

    /* ── RENDER ── */
    function render() {
      const all    = sorted(getFiltered());
      const start  = (currentPage-1)*PAGE_SIZE;
      const page   = all.slice(start, start+PAGE_SIZE);
      const tbody  = document.getElementById('leaveBody');
      const empty  = document.getElementById('emptyState');

      empty.style.display = all.length ? 'none' : 'block';

      /* ── TABLE ROWS ── */
      tbody.innerHTML = page.map(r => {
        const isPending = r.status==='Pending';
        const actions = isPending
          ? `<div class="row-actions">
               <button class="icon-btn view" onclick="viewRequest(${r.id})" title="View Details">👁️</button>
               <button class="act-btn approve" onclick="openApprove(${r.id})">✓ Approve</button>
               <button class="act-btn deny"    onclick="openDeny(${r.id})">✗ Deny</button>
             </div>`
          : `<div class="row-actions">
               <button class="icon-btn view" onclick="viewRequest(${r.id})" title="View Details">👁️</button>
               <span class="processed-label">Processed</span>
             </div>`;
        const shortReason = r.reason.length>38 ? r.reason.slice(0,38)+'…' : r.reason;
        return `
        <tr>
          <td>
            <div class="emp-cell">
              <div class="emp-avatar" style="background:${col(r.emp)};">${ini(r.emp)}</div>
              <div>
                <div class="emp-name">${r.emp}</div>
                <div class="emp-dept">${EMP_DEPT[r.emp]||''}</div>
              </div>
            </div>
          </td>
          <td>${typeBadge(r.type)}</td>
          <td style="font-size:0.80rem;color:var(--gray-600);">${fmt(r.from)}</td>
          <td style="font-size:0.80rem;color:var(--gray-600);">${fmt(r.to)}</td>
          <td><span class="days-pill">${r.days} day${r.days!==1?'s':''}</span></td>
          <td style="font-size:0.78rem;color:var(--gray-600);max-width:160px;">${shortReason}</td>
          <td style="font-size:0.78rem;color:var(--gray-400);">${fmt(r.filed)}</td>
          <td>${statusBadge(r.status)}</td>
          <td style="text-align:center;">${actions}</td>
        </tr>`;
      }).join('');

      /* ── MOBILE CARDS ── */
      // Remove old cards first
      document.querySelectorAll('.leave-card').forEach(c => c.remove());
      const tableWrap = document.querySelector('.table-wrap');
      page.forEach(r => {
        const isPending = r.status === 'Pending';
        const card = document.createElement('div');
        card.className = 'leave-card';
        card.innerHTML = `
          <div class="leave-card-top">
            <div class="leave-card-emp">
              <div class="emp-avatar" style="background:${col(r.emp)};width:38px;height:38px;font-size:0.75rem;">${ini(r.emp)}</div>
              <div>
                <div class="leave-card-name">${r.emp}</div>
                <div class="leave-card-dept">${EMP_DEPT[r.emp]||''}</div>
              </div>
            </div>
            ${statusBadge(r.status)}
          </div>
          <div class="leave-card-meta">
            ${typeBadge(r.type)}
            <span class="days-pill">${r.days} day${r.days!==1?'s':''}</span>
          </div>
          <div class="leave-card-dates">
            📅 ${fmt(r.from)} → ${fmt(r.to)}
          </div>
          <div class="leave-card-reason">${r.reason}</div>
          <div class="leave-card-actions">
            <button class="icon-btn view" onclick="viewRequest(${r.id})" title="View Details" style="flex:0 0 auto;">👁️</button>
            ${isPending
              ? `<button class="act-btn approve" onclick="openApprove(${r.id})" style="flex:1;text-align:center;">✓ Approve</button>
                 <button class="act-btn deny"    onclick="openDeny(${r.id})"    style="flex:1;text-align:center;">✗ Deny</button>`
              : `<span class="processed-label" style="flex:1;text-align:center;line-height:30px;">Processed</span>`
            }
          </div>`;
        tableWrap.appendChild(card);
      });

      /* counts */
      const all2 = requests;
      const pending   = all2.filter(r=>r.status==='Pending').length;
      const approved  = all2.filter(r=>r.status==='Approved').length;
      const denied    = all2.filter(r=>r.status==='Denied').length;
      const cancelled = all2.filter(r=>r.status==='Cancelled').length;

      document.getElementById('sc-pending').textContent  = pending;
      document.getElementById('sc-approved').textContent = approved;
      document.getElementById('sc-denied').textContent   = denied;
      document.getElementById('sc-total').textContent    = all2.length;

      document.getElementById('tc-all').textContent       = all2.length;
      document.getElementById('tc-pending').textContent   = pending;
      document.getElementById('tc-approved').textContent  = approved;
      document.getElementById('tc-denied').textContent    = denied;
      document.getElementById('tc-cancelled').textContent = cancelled;

      /* sidebar + topbar badge */
      document.getElementById('sidebarBadge').textContent = pending;
      document.getElementById('topbarBadge').textContent  = pending;

      /* pagination */
      const total = all.length;
      const pages = Math.ceil(total/PAGE_SIZE);
      const from  = total ? start+1 : 0;
      const to    = Math.min(start+PAGE_SIZE, total);
      document.getElementById('pageInfo').textContent = `Showing ${from}–${to} of ${total} request${total!==1?'s':''}`;
      const pb = document.getElementById('pageBtns');
      pb.innerHTML='';
      const prev=document.createElement('button'); prev.className='page-btn'; prev.textContent='‹'; prev.disabled=currentPage===1; prev.onclick=()=>{currentPage--;render();}; pb.appendChild(prev);
      for(let i=1;i<=pages;i++){const b=document.createElement('button');b.className='page-btn'+(i===currentPage?' active':'');b.textContent=i;b.onclick=()=>{currentPage=i;render();};pb.appendChild(b);}
      const next=document.createElement('button'); next.className='page-btn'; next.textContent='›'; next.disabled=currentPage>=pages; next.onclick=()=>{currentPage++;render();}; pb.appendChild(next);
    }

    /* ── VIEW ── */
    function viewRequest(id) {
      const r = requests.find(x=>x.id===id);
      document.getElementById('dAvatar').textContent = ini(r.emp);
      document.getElementById('dAvatar').style.background = col(r.emp);
      document.getElementById('dName').textContent = r.emp;
      document.getElementById('dPos').textContent  = `${EMP_POS[r.emp]||''} · ${EMP_DEPT[r.emp]||''}`;
      document.getElementById('dTags').innerHTML   = `<span class="dtag">${r.type} Leave</span><span class="dtag">${r.days} Day${r.days!==1?'s':''}</span><span class="dtag">${r.status}</span>`;

      document.getElementById('dDetails').innerHTML = [
        ['Leave Type', typeBadge(r.type)],
        ['Status', statusBadge(r.status)],
        ['Date From', fmt(r.from)],
        ['Date To', fmt(r.to)],
        ['Number of Days', `<span class="days-pill">${r.days} day${r.days!==1?'s':''}</span>`],
        ['Filed On', fmt(r.filed)],
        ['Reason', r.reason, true],
      ].map(([l,v,full])=>`<div class="detail-field${full?' full':''}"><label>${l}</label><span>${v}</span></div>`).join('');

      const rb = document.getElementById('dRemarks');
      rb.innerHTML = r.remark
        ? `<div class="remarks-box"><div class="remarks-label">📝 Admin Remarks</div><p>${r.remark}</p></div>`
        : '';

      const footer = document.getElementById('dFooter');
      if (r.status==='Pending') {
        footer.innerHTML = `
          <button class="btn btn-cancel" onclick="closeModal('modalView')">Close</button>
          <button class="btn btn-danger"  onclick="closeModal('modalView');openDeny(${r.id})">✗ Deny</button>
          <button class="btn btn-success" onclick="closeModal('modalView');openApprove(${r.id})">✅ Approve</button>`;
      } else {
        footer.innerHTML = `<button class="btn btn-cancel" onclick="closeModal('modalView')">Close</button>`;
      }
      openModal('modalView');
    }

    /* ── FILE LEAVE ── */
    function openAdd() {
      ['fEmployee','fDeptF','fLeaveType','fDays','fFrom','fTo','fReason'].forEach(id=>{
        const el=document.getElementById(id); el.value=''; el.classList.remove('err');
      });
      openModal('modalForm');
    }

    function saveRequest() {
      const emp   = document.getElementById('fEmployee').value;
      const type  = document.getElementById('fLeaveType').value;
      const days  = document.getElementById('fDays').value;
      const from  = document.getElementById('fFrom').value;
      const to    = document.getElementById('fTo').value;
      const reason= document.getElementById('fReason').value.trim();
      let valid = true;
      [['fEmployee',emp],['fLeaveType',type],['fDays',days],['fFrom',from],['fTo',to]].forEach(([id,v])=>{
        const el=document.getElementById(id); el.classList.toggle('err',!v); if(!v) valid=false;
      });
      if(!valid){showToast('⚠️ Please fill in all required fields.','info');return;}
      const today = new Date().toISOString().slice(0,10);
      requests.unshift({id:Date.now(),emp,type,from,to,days:parseInt(days),reason:reason||'—',filed:today,status:'Pending',remark:''});
      closeModal('modalForm');
      render();
      showToast(`✅ Leave request for ${emp} submitted.`,'success');
    }

    /* ── APPROVE ── */
    function openApprove(id) {
      const r=requests.find(x=>x.id===id);
      approveTarget=id;
      document.getElementById('approveText').textContent=`Approving ${r.type} Leave for ${r.emp} (${fmt(r.from)} – ${fmt(r.to)}, ${r.days} day${r.days!==1?'s':''}).`;
      document.getElementById('approveRemark').value='';
      openModal('modalApprove');
    }
    function confirmApprove() {
      const r=requests.find(x=>x.id===approveTarget);
      r.status='Approved';
      r.remark=document.getElementById('approveRemark').value.trim()||'Approved.';
      closeModal('modalApprove');
      render();
      showToast(`✅ Leave request for ${r.emp} approved.`,'success');
    }

    /* ── DENY ── */
    function openDeny(id) {
      const r=requests.find(x=>x.id===id);
      denyTarget=id;
      document.getElementById('denyText').textContent=`Denying ${r.type} Leave for ${r.emp} (${fmt(r.from)} – ${fmt(r.to)}, ${r.days} day${r.days!==1?'s':''}).`;
      document.getElementById('denyRemark').value='';
      openModal('modalDeny');
    }
    function confirmDeny() {
      const r=requests.find(x=>x.id===denyTarget);
      r.status='Denied';
      r.remark=document.getElementById('denyRemark').value.trim()||'Request denied.';
      closeModal('modalDeny');
      render();
      showToast(`Request for ${r.emp} has been denied.`,'error');
    }

    /* ── MODALS ── */
    function openModal(id)  { document.getElementById(id).classList.add('open'); }
    function closeModal(id) { document.getElementById(id).classList.remove('open'); }
    document.querySelectorAll('.modal-overlay').forEach(m=>{
      m.addEventListener('click',e=>{ if(e.target===m) m.classList.remove('open'); });
    });

    /* ── TOAST ── */
    function showToast(msg,type='success') {
      document.querySelectorAll('.toast').forEach(t=>t.remove());
      const t=document.createElement('div'); t.className=`toast ${type}`; t.textContent=msg;
      document.body.appendChild(t);
      setTimeout(()=>{t.style.opacity='0';t.style.transition='opacity 0.3s';setTimeout(()=>t.remove(),300);},3200);
    }

    /* ── SIDEBAR ── */
    function toggleSidebar() {
      document.getElementById('sidebar').classList.toggle('open');
      document.getElementById('overlayMob').classList.toggle('show');
    }

    render();
  </script>
</body>
</html>