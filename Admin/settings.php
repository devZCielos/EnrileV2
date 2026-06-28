<?php
// ============================================================
//  LGU ENRILE HRMS — Settings Page
//  File: Admin/settings.php
// ============================================================
session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['user_email'])) {
    header('Location: ../Login/login.php'); exit();
}
// Settings is admin-only
if (($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ../Employee/Employee_portal.php'); exit();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';
// P3-9: Schema managed by config/migrations/003_system_settings.sql

// Seed default settings if table is empty
$count = (int)$pdo->query("SELECT COUNT(*) FROM system_settings")->fetchColumn();
if ($count === 0) {
    $defaults = [
        // Organisation
        ['org_name',            'LGU Enrile',                    'organization', 'Organization Name',          'Official name of the LGU',                            'text',     null],
        ['org_address',         'Enrile, Cagayan, Philippines',  'organization', 'Address',                    'Complete office address',                             'textarea',  null],
        ['org_email',           'hrm@lguenrile.gov.ph',          'organization', 'HR Email Address',           'Official HR e-mail shown on documents',               'email',     null],
        ['org_phone',           '',                              'organization', 'Contact Number',             'HR office telephone / mobile number',                 'text',     null],
        ['org_logo_path',       '../images/Enrile.png',          'organization', 'Logo Path',                  'Relative path to the logo image',                     'text',     null],
        // Leave
        ['leave_max_pending_days', '30',                         'leave',        'Pending Request Timeout (days)', 'Auto-expire pending leave requests after N days',  'number',   null],
        ['leave_allow_half_day',   '1',                          'leave',        'Allow Half-Day Leaves',      'Employees can file half-day leave requests',           'toggle',   null],
        ['leave_require_attachment','0',                         'leave',        'Require Attachment',         'Require supporting document for leave requests',       'toggle',   null],
        ['leave_auto_approve',     '0',                          'leave',        'Auto-Approve Leaves',        'Automatically approve leave requests on filing',       'toggle',   null],
        ['leave_credits_reset_month','1',                        'leave',        'Credits Reset Month',        'Month (1–12) when annual leave credits reset',         'number',   null],
        // Contracts
        ['contract_expiry_warn_days','30',                       'contracts',    'Expiry Warning (days)',       'Warn HR when contracts expire within N days',          'number',   null],
        ['contract_auto_archive',   '1',                         'contracts',    'Auto-Archive Expired',       'Automatically archive contracts past their end date',  'toggle',   null],
        // Notifications
        ['notif_email_enabled',  '0',                            'notifications','Email Notifications',        'Send e-mail alerts for leave requests and contracts',  'toggle',   null],
        ['notif_smtp_host',      '',                             'notifications','SMTP Host',                  'e.g. smtp.gmail.com',                                 'text',     null],
        ['notif_smtp_port',      '587',                          'notifications','SMTP Port',                  'Usually 587 (TLS) or 465 (SSL)',                      'number',   null],
        ['notif_smtp_user',      '',                             'notifications','SMTP Username',              'SMTP login e-mail address',                           'email',    null],
        ['notif_smtp_pass',      '',                             'notifications','SMTP Password',              'SMTP login password',                                 'password', null],
        ['notif_from_name',      'LGU Enrile HRMS',              'notifications','Sender Name',                'Name shown in outgoing e-mails',                      'text',     null],
        // Security
        ['sec_session_timeout',  '120',                          'security',     'Session Timeout (minutes)',   'Automatically log out inactive users after N minutes', 'number',   null],
        ['sec_max_login_attempts','5',                           'security',     'Max Login Attempts',         'Lock account after N failed login attempts',           'number',   null],
        ['sec_password_min_len', '8',                            'security',     'Min Password Length',        'Minimum characters required for passwords',            'number',   null],
        ['sec_force_strong_pass','1',                            'security',     'Require Strong Password',    'Require uppercase, number, and symbol in passwords',   'toggle',   null],
        // System
        ['sys_date_format',      'd M Y',                        'system',       'Date Display Format',        'PHP date() format used throughout the system',         'text',     null],
        ['sys_timezone',         'Asia/Manila',                  'system',       'Timezone',                   'PHP timezone for all date/time operations',            'select',   '["Asia/Manila","Asia/Singapore","UTC"]'],
        ['sys_items_per_page',   '10',                           'system',       'Records Per Page',           'Default number of rows shown in list views',           'number',   null],
        ['sys_maintenance_mode', '0',                            'system',       'Maintenance Mode',           'Block all non-admin logins for system maintenance',    'toggle',   null],
    ];
    $ins = $pdo->prepare("INSERT INTO system_settings
        (setting_key, setting_value, setting_group, label, description, input_type, options_json)
        VALUES (?,?,?,?,?,?,?)");
    foreach ($defaults as $r) {
        $ins->execute($r);
    }
}

// ════════════════════════════════════════════════════════════
//  AJAX HANDLER
// ════════════════════════════════════════════════════════════
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action) {
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_verify(true); }
    try {
        switch ($action) {

            // ── LOAD ALL SETTINGS ─────────────────────────────
            case 'load':
                $rows = $pdo->query("
                    SELECT setting_key, setting_value, setting_group,
                           label, description, input_type, options_json
                    FROM system_settings
                    ORDER BY setting_group, setting_id
                ")->fetchAll();
                // Group by setting_group
                $grouped = [];
                foreach ($rows as $r) {
                    $grouped[$r['setting_group']][] = $r;
                }
                echo json_encode(['success' => true, 'data' => $grouped]);
                break;

            // ── SAVE GROUP ────────────────────────────────────
            case 'save_group':
                $group  = $_POST['group'] ?? '';
                $values = $_POST['values'] ?? [];   // key => value pairs
                if (!$group || !is_array($values)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
                    break;
                }
                $stmt = $pdo->prepare("
                    UPDATE system_settings
                    SET setting_value = ?, updated_by = ?
                    WHERE setting_key = ? AND setting_group = ?
                ");
                foreach ($values as $key => $val) {
                    // Sanitise key name
                    if (!preg_match('/^[a-z0-9_]+$/', $key)) continue;
                    // S-2: For password fields submitted as empty, keep the existing DB value
                    if ($val === '') {
                        // Check if this key has input_type=password; if so, skip the update
                        $typeCheck = $pdo->prepare("SELECT input_type FROM system_settings WHERE setting_key = ?");
                        $typeCheck->execute([$key]);
                        if ($typeCheck->fetchColumn() === 'password') continue;
                    }
                    $stmt->execute([$val, (int)$_SESSION['user_id'], $key, $group]);
                }
                echo json_encode(['success' => true, 'message' => ucfirst($group) . ' settings saved.']);
                break;

            // ── GET SINGLE VALUE ──────────────────────────────
            case 'get_value':
                $key  = $_GET['key'] ?? '';
                $row  = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
                $row->execute([$key]);
                $val  = $row->fetchColumn();
                echo json_encode(['success' => true, 'value' => $val]);
                break;

            // ── RESET GROUP TO DEFAULTS ───────────────────────
            case 'reset_group':
                $group = $_POST['group'] ?? '';
                if (!$group) { echo json_encode(['success'=>false,'message'=>'Missing group.']); break; }
                // We simply delete and re-seed is complex; instead just return current values
                // to let the UI re-render — actual default reset would require re-running seed
                echo json_encode(['success' => false, 'message' => 'Reset not implemented — manually re-enter defaults.']);
                break;

            // ── TEST SMTP ─────────────────────────────────────
            case 'test_smtp':
                // Placeholder — real implementation would use PHPMailer or similar
                echo json_encode(['success' => false, 'message' => 'SMTP test requires PHPMailer. Install via Composer to enable.']);
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'DB Error: ' . $e->getMessage()]);
    }
    exit();
}

$adminName  = ucwords($_SESSION['user_name'] ?? 'Administrator');
$roleLabel  = 'Super Admin';
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
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <?= csrf_meta() ?>
  <title>Settings | LGU Enrile HRMS</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../css/admin.css"/>
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
    * { box-sizing:border-box; margin:0; padding:0; }
    body { font-family:'Nunito',sans-serif; background:var(--gray-100); color:var(--secondary); display:flex; min-height:100vh; }

    /* ── Sidebar ── */
    .sidebar { width:var(--sidebar-w); background:linear-gradient(180deg,#2563eb 0%,#1e40af 55%,#22c55e 100%); display:flex; flex-direction:column; position:fixed; top:0; left:0; height:100vh; z-index:200; transition:transform 0.3s; box-shadow:4px 0 28px rgba(0,0,0,0.20); }
    .sidebar-brand { padding:22px 20px 18px; display:flex; align-items:center; gap:12px; border-bottom:1px solid rgba(255,255,255,0.15); }
    .brand-logo { width:44px; height:44px; border-radius:50%; object-fit:cover; border:2.5px solid rgba(255,255,255,0.70); box-shadow:0 2px 8px rgba(0,0,0,0.20); }
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
    .sidebar-footer { padding:14px 12px; border-top:1px solid rgba(255,255,255,0.13); }
    .sidebar-user { display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:var(--radius-sm); background:rgba(255,255,255,0.10); }
    .user-avatar { width:36px; height:36px; border-radius:50%; background:rgba(255,255,255,0.25); border:2px solid rgba(255,255,255,0.50); display:flex; align-items:center; justify-content:center; font-weight:800; color:#fff; font-size:0.80rem; flex-shrink:0; }
    .user-info { flex:1; min-width:0; }
    .user-info strong { display:block; font-size:0.82rem; color:#fff; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .user-info span { font-size:0.68rem; color:rgba(255,255,255,0.52); }
    .logout-btn { display:flex; align-items:center; gap:9px; margin:8px 10px 2px; padding:10px 14px; border-radius:10px; background:rgba(239,68,68,0.12); border:1.5px solid rgba(239,68,68,0.28); color:#fca5a5; font-size:0.845rem; font-weight:700; text-decoration:none; transition:all .2s; cursor:pointer; }
    .logout-btn:hover { background:rgba(239,68,68,0.26); border-color:rgba(239,68,68,0.55); color:#fff; transform:translateY(-1px); }

    /* ── Main ── */
    .main { margin-left:var(--sidebar-w); flex:1; display:flex; flex-direction:column; }
    .topbar { height:var(--topbar-h); background:linear-gradient(135deg,#2563eb 0%,#1e40af 50%,#22c55e 100%); display:flex; align-items:center; padding:0 28px; gap:16px; position:sticky; top:0; z-index:100; box-shadow:0 2px 16px rgba(37,99,235,0.30); }
    .menu-toggle { display:none; background:none; border:none; font-size:1.4rem; cursor:pointer; color:rgba(255,255,255,0.85); padding:4px; }
    .topbar-title { flex:1; }
    .topbar-title h1 { font-size:1.20rem; font-weight:800; color:#fff; font-family:'Playfair Display',serif; }
    .topbar-title p  { font-size:0.76rem; color:rgba(255,255,255,0.70); margin-top:1px; }
    .topbar-actions { display:flex; align-items:center; gap:8px; }
    .topbar-btn { position:relative; width:38px; height:38px; border-radius:50%; border:1px solid rgba(255,255,255,0.25); background:rgba(255,255,255,0.15); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:1rem; color:#fff; transition:all 0.2s; }
    .topbar-btn:hover { background:rgba(255,255,255,0.28); }
    .content { padding:24px 28px; flex:1; }

    /* ── Layout ── */
    .settings-layout { display:grid; grid-template-columns:220px 1fr; gap:24px; align-items:start; }

    /* ── Side Nav ── */
    .settings-sidenav { background:var(--white); border-radius:var(--radius); border:1.5px solid var(--gray-200); box-shadow:var(--shadow-sm); overflow:hidden; position:sticky; top:calc(var(--topbar-h) + 24px); }
    .sidenav-header { padding:14px 18px 12px; background:var(--gray-50); border-bottom:1.5px solid var(--gray-100); }
    .sidenav-header span { font-size:0.70rem; font-weight:800; color:var(--gray-400); text-transform:uppercase; letter-spacing:0.10em; }
    .sidenav-item { display:flex; align-items:center; gap:10px; padding:11px 18px; font-size:0.875rem; font-weight:700; color:var(--gray-600); cursor:pointer; transition:all 0.2s; border-left:3px solid transparent; }
    .sidenav-item:hover { background:var(--gray-50); color:var(--gray-800); }
    .sidenav-item.active { background:#eff6ff; color:var(--primary); border-left-color:var(--primary); }
    .sidenav-icon { font-size:1rem; width:22px; text-align:center; }

    /* ── Settings Panels ── */
    .settings-panel { display:none; }
    .settings-panel.active { display:block; animation:fadeIn 0.3s ease both; }

    .panel { background:var(--white); border-radius:var(--radius); border:1.5px solid var(--gray-200); box-shadow:var(--shadow-sm); margin-bottom:20px; overflow:hidden; }
    .panel-head { display:flex; align-items:center; justify-content:space-between; padding:16px 22px; background:var(--gray-50); border-bottom:1.5px solid var(--gray-100); }
    .panel-head-left { display:flex; align-items:center; gap:10px; }
    .panel-head-icon { width:36px; height:36px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:1.1rem; }
    .panel-head h3 { font-size:0.95rem; font-weight:800; color:var(--gray-800); font-family:'Playfair Display',serif; }
    .panel-head p  { font-size:0.74rem; color:var(--gray-400); margin-top:1px; }
    .panel-body { padding:22px 24px; }

    /* ── Form fields ── */
    .settings-grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
    .setting-field { display:flex; flex-direction:column; gap:5px; }
    .setting-field.full { grid-column:1/-1; }
    .setting-field label { font-size:0.76rem; font-weight:700; color:var(--gray-600); }
    .setting-field .hint { font-size:0.68rem; color:var(--gray-400); margin-top:2px; }
    .setting-field input[type=text],
    .setting-field input[type=number],
    .setting-field input[type=email],
    .setting-field input[type=password],
    .setting-field select,
    .setting-field textarea {
      padding:9px 12px; border:1.5px solid var(--gray-200); border-radius:var(--radius-sm);
      font-family:'Nunito',sans-serif; font-size:0.875rem; color:var(--gray-800);
      background:var(--white); outline:none; transition:border-color 0.2s;
    }
    .setting-field input:focus,
    .setting-field select:focus,
    .setting-field textarea:focus { border-color:var(--primary); box-shadow:0 0 0 3px rgba(37,99,235,0.10); }
    .setting-field textarea { resize:vertical; min-height:72px; line-height:1.6; }

    /* ── Toggle switch ── */
    .toggle-row { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; background:var(--gray-50); border:1.5px solid var(--gray-200); border-radius:var(--radius-sm); }
    .toggle-info strong { font-size:0.875rem; font-weight:700; color:var(--gray-800); display:block; }
    .toggle-info span   { font-size:0.72rem; color:var(--gray-400); }
    .toggle-switch { position:relative; width:44px; height:24px; flex-shrink:0; }
    .toggle-switch input { opacity:0; width:0; height:0; }
    .toggle-slider { position:absolute; cursor:pointer; inset:0; background:var(--gray-200); border-radius:24px; transition:0.3s; }
    .toggle-slider::before { content:''; position:absolute; height:18px; width:18px; left:3px; bottom:3px; background:white; border-radius:50%; transition:0.3s; box-shadow:0 1px 3px rgba(0,0,0,0.20); }
    input:checked + .toggle-slider { background:var(--primary); }
    input:checked + .toggle-slider::before { transform:translateX(20px); }

    /* ── Panel footer ── */
    .panel-footer { display:flex; align-items:center; justify-content:flex-end; gap:10px; padding:14px 24px; border-top:1px solid var(--gray-100); background:var(--gray-50); }

    /* ── Danger zone ── */
    .danger-zone { background:var(--white); border-radius:var(--radius); border:1.5px solid #fca5a5; box-shadow:var(--shadow-sm); overflow:hidden; }
    .danger-head { padding:16px 22px; background:#fff5f5; border-bottom:1.5px solid #fca5a5; display:flex; align-items:center; gap:10px; }
    .danger-head h3 { font-size:0.95rem; font-weight:800; color:#991b1b; font-family:'Playfair Display',serif; }
    .danger-body { padding:22px 24px; display:flex; flex-direction:column; gap:14px; }
    .danger-item { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border:1.5px solid var(--gray-200); border-radius:var(--radius-sm); flex-wrap:wrap; gap:12px; }
    .danger-item-info strong { font-size:0.875rem; font-weight:700; color:var(--gray-800); display:block; }
    .danger-item-info span   { font-size:0.75rem; color:var(--gray-400); }

    /* ── Buttons ── */
    .btn { padding:9px 20px; border-radius:var(--radius-sm); font-family:'Nunito',sans-serif; font-size:0.875rem; font-weight:700; cursor:pointer; transition:all 0.2s; border:none; white-space:nowrap; }
    .btn-primary { background:linear-gradient(135deg,var(--primary),var(--primary-dark)); color:white; box-shadow:0 2px 8px rgba(37,99,235,0.25); }
    .btn-primary:hover { transform:translateY(-1px); box-shadow:0 4px 14px rgba(37,99,235,0.35); }
    .btn-success { background:linear-gradient(135deg,#22c55e,#15803d); color:white; }
    .btn-success:hover { transform:translateY(-1px); }
    .btn-danger  { background:linear-gradient(135deg,#ef4444,#dc2626); color:white; }
    .btn-danger:hover  { transform:translateY(-1px); }
    .btn-cancel  { background:var(--white); border:1.5px solid var(--gray-200); color:var(--gray-600); }
    .btn-cancel:hover  { border-color:var(--gray-400); background:var(--gray-50); }
    .btn-ghost   { background:transparent; border:1.5px solid var(--gray-200); color:var(--gray-600); padding:7px 16px; font-size:0.82rem; }
    .btn-ghost:hover { border-color:var(--primary); color:var(--primary); background:#eff6ff; }
    .btn-sm { padding:6px 14px; font-size:0.80rem; }

    /* ── Info cards at top ── */
    .info-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:14px; margin-bottom:22px; }
    .info-card { background:var(--white); border-radius:var(--radius); padding:16px 18px; border:1.5px solid var(--gray-200); box-shadow:var(--shadow-sm); position:relative; overflow:hidden; }
    .info-card::before { content:''; position:absolute; top:0; left:0; right:0; height:4px; border-radius:var(--radius) var(--radius) 0 0; }
    .info-card.blue::before   { background:linear-gradient(90deg,#2563eb,#60a5fa); }
    .info-card.green::before  { background:linear-gradient(90deg,#22c55e,#86efac); }
    .info-card.purple::before { background:linear-gradient(90deg,#a855f7,#d8b4fe); }
    .info-card.orange::before { background:linear-gradient(90deg,#f59e0b,#fcd34d); }
    .info-card-icon { font-size:1.5rem; margin-bottom:8px; }
    .info-card-val  { font-size:1rem; font-weight:800; color:var(--gray-800); }
    .info-card-lbl  { font-size:0.72rem; color:var(--gray-400); font-weight:600; margin-top:2px; }

    /* ── Password reveal ── */
    .pw-wrap { position:relative; }
    .pw-wrap input { width:100%; padding-right:40px; }
    .pw-toggle { position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; font-size:0.90rem; color:var(--gray-400); padding:2px; }
    .pw-toggle:hover { color:var(--primary); }

    /* ── Maintenance banner ── */
    .maintenance-banner { display:none; background:linear-gradient(135deg,#f59e0b,#d97706); color:white; padding:12px 22px; border-radius:var(--radius-sm); font-size:0.84rem; font-weight:700; margin-bottom:20px; align-items:center; gap:10px; }
    .maintenance-banner.show { display:flex; }

    /* ── Toast ── */
    .toast { position:fixed; bottom:28px; right:28px; padding:12px 20px; border-radius:var(--radius-sm); font-size:0.84rem; font-weight:700; box-shadow:var(--shadow-lg); z-index:900; color:white; animation:fadeIn 0.3s ease both; max-width:340px; }
    .toast.success { background:linear-gradient(135deg,#22c55e,#16a34a); }
    .toast.error   { background:linear-gradient(135deg,#ef4444,#dc2626); }
    .toast.info    { background:linear-gradient(135deg,#2563eb,#1e40af); }

    /* ── Modal ── */
    .modal-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.55); z-index:500; align-items:center; justify-content:center; backdrop-filter:blur(4px); }
    .modal-overlay.open { display:flex; }
    .modal { background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow-lg); width:92%; max-width:440px; animation:modalIn 0.3s cubic-bezier(0.34,1.4,0.64,1) both; }
    .modal-header { display:flex; align-items:center; justify-content:space-between; padding:18px 22px 14px; border-radius:var(--radius) var(--radius) 0 0; }
    .modal-header.red { background:linear-gradient(135deg,#ef4444,#dc2626); }
    .modal-header h3 { font-size:1rem; font-weight:800; color:#fff; font-family:'Playfair Display',serif; }
    .modal-header p  { font-size:0.74rem; color:rgba(255,255,255,0.65); margin-top:2px; }
    .modal-close { width:30px; height:30px; border-radius:50%; background:rgba(255,255,255,0.18); border:none; color:#fff; font-size:1rem; cursor:pointer; display:flex; align-items:center; justify-content:center; }
    .modal-close:hover { background:rgba(255,255,255,0.32); }
    .confirm-body { padding:24px 22px; text-align:center; }
    .confirm-body .ci { font-size:2.4rem; margin-bottom:10px; }
    .confirm-body h4 { font-size:0.95rem; font-weight:800; color:var(--gray-800); margin-bottom:6px; }
    .confirm-body p  { font-size:0.82rem; color:var(--gray-600); line-height:1.6; }
    .modal-footer { display:flex; justify-content:flex-end; gap:10px; padding:12px 22px 16px; border-top:1px solid var(--gray-100); }

    @keyframes fadeIn  { from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);} }
    @keyframes modalIn { from{opacity:0;transform:scale(0.88) translateY(16px);}to{opacity:1;transform:scale(1) translateY(0);} }

    /* ── Responsive ── */
    .overlay-mob { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:150; }
    .overlay-mob.show { display:block; }
    @media(max-width:900px) { .settings-layout { grid-template-columns:1fr; } .settings-sidenav { position:static; } }
    @media(max-width:768px) {
      .sidebar { transform:translateX(-100%); } .sidebar.open { transform:translateX(0); }
      .main { margin-left:0; } .menu-toggle { display:block; }
      .content { padding:14px 12px; } .topbar { padding:0 14px; }
      .settings-grid { grid-template-columns:1fr; }
    }
    @media(max-width:480px) {
      .content { padding:12px 10px; } .topbar { padding:0 12px; } .topbar-title p { display:none; }
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
    <a class="nav-item" href="dashboard.php"><div class="nav-icon">🏠</div>Dashboard</a>
    <div class="nav-label">Employee</div>
    <a class="nav-item" href="employees.php"><div class="nav-icon">👥</div>Employees</a>
    <a class="nav-item" href="departments.php"><div class="nav-icon">🏢</div>Departments</a>
    <div class="nav-label">Leave</div>
    <a class="nav-item" href="leave_requests.php"><div class="nav-icon">📋</div>Leave Requests</a>
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
    <a class="nav-item active" href="settings.php"><div class="nav-icon">⚙️</div>Settings</a>
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
      <h1>Settings</h1>
      <p>System configuration and preferences</p>
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

    <!-- Maintenance banner -->
    <div class="maintenance-banner" id="maintBanner">
      🔧 <span>Maintenance Mode is ON — only administrators can log in.</span>
    </div>

    <!-- System info cards -->
    <div class="info-cards">
      <div class="info-card blue">
        <div class="info-card-icon">🖥️</div>
        <div class="info-card-val" id="icPHP">—</div>
        <div class="info-card-lbl">PHP Version</div>
      </div>
      <div class="info-card green">
        <div class="info-card-icon">🗄️</div>
        <div class="info-card-val" id="icDB">—</div>
        <div class="info-card-lbl">Database</div>
      </div>
      <div class="info-card purple">
        <div class="info-card-icon">🕐</div>
        <div class="info-card-val" id="icTZ">—</div>
        <div class="info-card-lbl">Server Timezone</div>
      </div>
      <div class="info-card orange">
        <div class="info-card-icon">📅</div>
        <div class="info-card-val" id="icDate">—</div>
        <div class="info-card-lbl">Server Date</div>
      </div>
    </div>

    <!-- Settings layout -->
    <div class="settings-layout">

      <!-- Side nav -->
      <div class="settings-sidenav">
        <div class="sidenav-header"><span>Configuration</span></div>
        <div class="sidenav-item active" onclick="switchTab('organization',this)"><span class="sidenav-icon">🏛️</span>Organization</div>
        <div class="sidenav-item" onclick="switchTab('leave',this)"><span class="sidenav-icon">🏖️</span>Leave</div>
        <div class="sidenav-item" onclick="switchTab('contracts',this)"><span class="sidenav-icon">📝</span>Contracts</div>
        <div class="sidenav-item" onclick="switchTab('notifications',this)"><span class="sidenav-icon">🔔</span>Notifications</div>
        <div class="sidenav-item" onclick="switchTab('security',this)"><span class="sidenav-icon">🔒</span>Security</div>
        <div class="sidenav-item" onclick="switchTab('system',this)"><span class="sidenav-icon">⚙️</span>System</div>
        <div class="sidenav-item" onclick="switchTab('danger',this)" style="color:#ef4444;border-top:1px solid var(--gray-100);margin-top:4px;"><span class="sidenav-icon">⚠️</span>Danger Zone</div>
      </div>

      <!-- Panels -->
      <div id="panelsWrap">

        <!-- ══ ORGANIZATION ══ -->
        <div class="settings-panel active" id="panel-organization">
          <div class="panel">
            <div class="panel-head">
              <div class="panel-head-left">
                <div class="panel-head-icon" style="background:#dbeafe">🏛️</div>
                <div><h3>Organization</h3><p>Basic information about the LGU</p></div>
              </div>
            </div>
            <div class="panel-body">
              <div class="settings-grid" id="fields-organization"></div>
            </div>
            <div class="panel-footer">
              <button class="btn btn-cancel btn-sm" onclick="reloadGroup('organization')">↺ Reset</button>
              <button class="btn btn-primary btn-sm" onclick="saveGroup('organization')">💾 Save Changes</button>
            </div>
          </div>
        </div>

        <!-- ══ LEAVE ══ -->
        <div class="settings-panel" id="panel-leave">
          <div class="panel">
            <div class="panel-head">
              <div class="panel-head-left">
                <div class="panel-head-icon" style="background:#dcfce7">🏖️</div>
                <div><h3>Leave Management</h3><p>Rules and behaviour for leave requests</p></div>
              </div>
            </div>
            <div class="panel-body">
              <div class="settings-grid" id="fields-leave"></div>
            </div>
            <div class="panel-footer">
              <button class="btn btn-cancel btn-sm" onclick="reloadGroup('leave')">↺ Reset</button>
              <button class="btn btn-primary btn-sm" onclick="saveGroup('leave')">💾 Save Changes</button>
            </div>
          </div>
        </div>

        <!-- ══ CONTRACTS ══ -->
        <div class="settings-panel" id="panel-contracts">
          <div class="panel">
            <div class="panel-head">
              <div class="panel-head-left">
                <div class="panel-head-icon" style="background:#f3e8ff">📝</div>
                <div><h3>Contracts</h3><p>Contract expiry and archiving rules</p></div>
              </div>
            </div>
            <div class="panel-body">
              <div class="settings-grid" id="fields-contracts"></div>
            </div>
            <div class="panel-footer">
              <button class="btn btn-cancel btn-sm" onclick="reloadGroup('contracts')">↺ Reset</button>
              <button class="btn btn-primary btn-sm" onclick="saveGroup('contracts')">💾 Save Changes</button>
            </div>
          </div>
        </div>

        <!-- ══ NOTIFICATIONS ══ -->
        <div class="settings-panel" id="panel-notifications">
          <div class="panel">
            <div class="panel-head">
              <div class="panel-head-left">
                <div class="panel-head-icon" style="background:#fef3c7">🔔</div>
                <div><h3>Email Notifications</h3><p>SMTP configuration for outgoing emails</p></div>
              </div>
            </div>
            <div class="panel-body">
              <div class="settings-grid" id="fields-notifications"></div>
            </div>
            <div class="panel-footer">
              <button class="btn btn-ghost btn-sm" onclick="testSmtp()">📧 Test SMTP</button>
              <button class="btn btn-cancel btn-sm" onclick="reloadGroup('notifications')">↺ Reset</button>
              <button class="btn btn-primary btn-sm" onclick="saveGroup('notifications')">💾 Save Changes</button>
            </div>
          </div>
        </div>

        <!-- ══ SECURITY ══ -->
        <div class="settings-panel" id="panel-security">
          <div class="panel">
            <div class="panel-head">
              <div class="panel-head-left">
                <div class="panel-head-icon" style="background:#fee2e2">🔒</div>
                <div><h3>Security</h3><p>Login, session, and password policies</p></div>
              </div>
            </div>
            <div class="panel-body">
              <div class="settings-grid" id="fields-security"></div>
            </div>
            <div class="panel-footer">
              <button class="btn btn-cancel btn-sm" onclick="reloadGroup('security')">↺ Reset</button>
              <button class="btn btn-primary btn-sm" onclick="saveGroup('security')">💾 Save Changes</button>
            </div>
          </div>
        </div>

        <!-- ══ SYSTEM ══ -->
        <div class="settings-panel" id="panel-system">
          <div class="panel">
            <div class="panel-head">
              <div class="panel-head-left">
                <div class="panel-head-icon" style="background:var(--gray-100)">⚙️</div>
                <div><h3>System</h3><p>Display, timezone, and maintenance</p></div>
              </div>
            </div>
            <div class="panel-body">
              <div class="settings-grid" id="fields-system"></div>
            </div>
            <div class="panel-footer">
              <button class="btn btn-cancel btn-sm" onclick="reloadGroup('system')">↺ Reset</button>
              <button class="btn btn-primary btn-sm" onclick="saveGroup('system')">💾 Save Changes</button>
            </div>
          </div>
        </div>

        <!-- ══ DANGER ZONE ══ -->
        <div class="settings-panel" id="panel-danger">
          <div class="danger-zone">
            <div class="danger-head">
              <span style="font-size:1.2rem">⚠️</span>
              <h3>Danger Zone</h3>
            </div>
            <div class="danger-body">
              <div class="danger-item">
                <div class="danger-item-info">
                  <strong>Clear All Leave Requests</strong>
                  <span>Permanently delete ALL leave request records. This cannot be undone.</span>
                </div>
                <button class="btn btn-danger btn-sm" onclick="confirmDanger('clear_leave','Clear ALL leave requests? This is permanent.')">🗑️ Clear Leaves</button>
              </div>
              <div class="danger-item">
                <div class="danger-item-info">
                  <strong>Clear Announcement History</strong>
                  <span>Delete all archived and draft announcements.</span>
                </div>
                <button class="btn btn-danger btn-sm" onclick="confirmDanger('clear_announcements','Delete all archived & draft announcements?')">🗑️ Clear Announcements</button>
              </div>
              <div class="danger-item">
                <div class="danger-item-info">
                  <strong>Reset Leave Balances</strong>
                  <span>Set all employee leave balances to zero for the current year.</span>
                </div>
                <button class="btn btn-danger btn-sm" onclick="confirmDanger('reset_balances','Reset ALL leave balances to zero?')">🔄 Reset Balances</button>
              </div>
              <div class="danger-item" style="border-color:#fca5a5;background:#fff5f5;">
                <div class="danger-item-info">
                  <strong style="color:#991b1b">Factory Reset</strong>
                  <span>Delete ALL data except admin accounts. Irreversible.</span>
                </div>
                <button class="btn btn-danger btn-sm" onclick="confirmDanger('factory_reset','FACTORY RESET — delete all data except admin? TYPE CONFIRM TO PROCEED.')">💥 Factory Reset</button>
              </div>
            </div>
          </div>
        </div>

      </div><!-- /panelsWrap -->
    </div><!-- /settings-layout -->
  </div><!-- /content -->
</div><!-- /main -->

<!-- ── Danger confirm modal ── -->
<div class="modal-overlay" id="modalDanger">
  <div class="modal">
    <div class="modal-header red">
      <div><h3>Confirm Dangerous Action</h3><p>This cannot be undone</p></div>
      <button class="modal-close" onclick="closeModal('modalDanger')">✕</button>
    </div>
    <div class="confirm-body">
      <div class="ci">⚠️</div>
      <h4 id="dangerTitle">Are you sure?</h4>
      <p id="dangerText">This action is permanent.</p>
      <input type="hidden" id="dangerAction"/>
    </div>
    <div class="modal-footer">
      <button class="btn btn-cancel" onclick="closeModal('modalDanger')">Cancel</button>
      <button class="btn btn-danger" onclick="executeDanger()">Yes, Proceed</button>
    </div>
  </div>
</div>

<script>
const CSRF_TOKEN = document.querySelector('meta[name=csrf-token]').content;
var API      = './settings.php';
var allData  = {};   // group => [ {setting_key, setting_value, ...}, ... ]

/* ── Boot ─────────────────────────────────────────────────── */
(function init() {
  fillInfoCards();
  loadSettings();
})();

function fillInfoCards() {
  document.getElementById('icPHP').textContent  = "<?= PHP_VERSION ?>";
  document.getElementById('icDB').textContent   = "<?= $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) ?>";
  document.getElementById('icTZ').textContent   = "<?= date_default_timezone_get() ?>";
  document.getElementById('icDate').textContent = "<?= date('d M Y') ?>";
}

/* ── Load all settings ────────────────────────────────────── */
async function loadSettings() {
  try {
    var json = await fetch(API + '?action=load').then(function(r){return r.json();});
    if (!json.success) { showToast('❌ Could not load settings.','error'); return; }
    allData = json.data;
    var groups = Object.keys(allData);
    for (var i = 0; i < groups.length; i++) {
      renderGroup(groups[i], allData[groups[i]]);
    }
    checkMaintenance();
  } catch(e) { showToast('❌ Load error.','error'); console.error(e); }
}

function reloadGroup(group) {
  if (allData[group]) renderGroup(group, allData[group]);
}

/* ── Render a group's fields ──────────────────────────────── */
function renderGroup(group, fields) {
  var container = document.getElementById('fields-' + group);
  if (!container) return;
  container.innerHTML = fields.map(function(f) {
    return buildField(f);
  }).join('');
}

function buildField(f) {
  var id  = 'field_' + f.setting_key;
  var val = f.setting_value || '';

  if (f.input_type === 'toggle') {
    var chk = val === '1' ? 'checked' : '';
    return '<div class="setting-field full">' +
      '<div class="toggle-row">' +
        '<div class="toggle-info"><strong>' + escHtml(f.label) + '</strong><span>' + escHtml(f.description||'') + '</span></div>' +
        '<label class="toggle-switch">' +
          '<input type="checkbox" id="' + id + '" ' + chk + ' onchange="onToggleChange(\'' + f.setting_key + '\')">' +
          '<span class="toggle-slider"></span>' +
        '</label>' +
      '</div>' +
    '</div>';
  }

  if (f.input_type === 'textarea') {
    return '<div class="setting-field full">' +
      '<label for="' + id + '">' + escHtml(f.label) + '</label>' +
      '<textarea id="' + id + '">' + escHtml(val) + '</textarea>' +
      (f.description ? '<span class="hint">' + escHtml(f.description) + '</span>' : '') +
    '</div>';
  }

  if (f.input_type === 'select') {
    var opts = [];
    try { opts = JSON.parse(f.options_json || '[]'); } catch(e) {}
    var optHtml = opts.map(function(o) {
      return '<option value="' + escHtml(o) + '"' + (o===val?' selected':'') + '>' + escHtml(o) + '</option>';
    }).join('');
    return '<div class="setting-field">' +
      '<label for="' + id + '">' + escHtml(f.label) + '</label>' +
      '<select id="' + id + '">' + optHtml + '</select>' +
      (f.description ? '<span class="hint">' + escHtml(f.description) + '</span>' : '') +
    '</div>';
  }

  if (f.input_type === 'password') {
    // S-2: Never pre-fill password value in HTML — submit empty string to keep existing
    var pwPlaceholder = val ? '(saved — leave blank to keep)' : 'Enter password';
    return '<div class="setting-field">' +
      '<label for="' + id + '">' + escHtml(f.label) + '</label>' +
      '<div class="pw-wrap">' +
        '<input type="password" id="' + id + '" value="" placeholder="' + pwPlaceholder + '" autocomplete="new-password"/>' +
        '<button class="pw-toggle" type="button" onclick="togglePw(\'' + id + '\',this)">👁️</button>' +
      '</div>' +
      (f.description ? '<span class="hint">' + escHtml(f.description) + '</span>' : '') +
    '</div>';
  }

  // default: text / number / email
  return '<div class="setting-field">' +
    '<label for="' + id + '">' + escHtml(f.label) + '</label>' +
    '<input type="' + f.input_type + '" id="' + id + '" value="' + escHtml(val) + '"/>' +
    (f.description ? '<span class="hint">' + escHtml(f.description) + '</span>' : '') +
  '</div>';
}

/* ── Toggle password visibility ───────────────────────────── */
function togglePw(id, btn) {
  var inp = document.getElementById(id);
  if (inp.type === 'password') { inp.type = 'text';     btn.textContent = '🙈'; }
  else                         { inp.type = 'password'; btn.textContent = '👁️'; }
}

/* ── On toggle change (maintenance mode check) ────────────── */
function onToggleChange(key) {
  if (key === 'sys_maintenance_mode') checkMaintenance();
}
function checkMaintenance() {
  var el = document.getElementById('field_sys_maintenance_mode');
  var banner = document.getElementById('maintBanner');
  if (el) banner.classList.toggle('show', el.checked);
}

/* ── Save a group ─────────────────────────────────────────── */
async function saveGroup(group) {
  var fields = allData[group] || [];
  var values = {};
  for (var i = 0; i < fields.length; i++) {
    var f  = fields[i];
    var el = document.getElementById('field_' + f.setting_key);
    if (!el) continue;
    values[f.setting_key] = (f.input_type === 'toggle') ? (el.checked ? '1' : '0') : el.value;
  }
  var fd = new FormData();
  fd.append('action', 'save_group');
  fd.append('group',  group);
  for (var key in values) { fd.append('values[' + key + ']', values[key]); }
  try {
    fd.append('csrf_token', CSRF_TOKEN);
    var json = await fetch(API, {method:'POST', body:fd}).then(function(r){return r.json();});
    if (json.success) {
      // Update allData cache
      for (var i = 0; i < fields.length; i++) {
        if (values[fields[i].setting_key] !== undefined) {
          allData[group][i].setting_value = values[fields[i].setting_key];
        }
      }
      showToast('✅ ' + json.message, 'success');
    } else {
      showToast('❌ ' + json.message, 'error');
    }
  } catch(e) { showToast('❌ Save failed.','error'); }
}

/* ── Test SMTP ────────────────────────────────────────────── */
async function testSmtp() {
  try {
    var json = await fetch(API + '?action=test_smtp').then(function(r){return r.json();});
    showToast((json.success ? '✅ ' : 'ℹ️ ') + json.message, json.success ? 'success' : 'info');
  } catch(e) { showToast('❌ Test failed.','error'); }
}

/* ── Tab switching ────────────────────────────────────────── */
function switchTab(group, el) {
  document.querySelectorAll('.settings-panel').forEach(function(p){ p.classList.remove('active'); });
  document.querySelectorAll('.sidenav-item').forEach(function(i){ i.classList.remove('active'); });
  var panel = document.getElementById('panel-' + group);
  if (panel) panel.classList.add('active');
  el.classList.add('active');
}

/* ── Danger zone ──────────────────────────────────────────── */
function confirmDanger(action, text) {
  document.getElementById('dangerAction').value = action;
  document.getElementById('dangerTitle').textContent = 'Confirm Action';
  document.getElementById('dangerText').textContent  = text;
  openModal('modalDanger');
}

async function executeDanger() {
  var action = document.getElementById('dangerAction').value;
  closeModal('modalDanger');
  var fd = new FormData();
  fd.append('action', 'danger_' + action);
  try {
    fd.append('csrf_token', CSRF_TOKEN);
    var json = await fetch(API, {method:'POST', body:fd}).then(function(r){return r.json();});
    showToast((json.success ? '✅ ' : '❌ ') + json.message, json.success ? 'success' : 'error');
  } catch(e) { showToast('❌ Error.','error'); }
}

/* ── Modals ───────────────────────────────────────────────── */
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(function(el) {
  el.addEventListener('click', function(e){ if(e.target===el) el.classList.remove('open'); });
});

/* ── Toast ────────────────────────────────────────────────── */
function showToast(msg, type) {
  document.querySelectorAll('.toast').forEach(function(t){t.remove();});
  var t = document.createElement('div');
  t.className = 'toast ' + (type||'success');
  t.textContent = msg;
  document.body.appendChild(t);
  setTimeout(function(){
    t.style.opacity='0'; t.style.transition='opacity 0.3s';
    setTimeout(function(){t.remove();},300);
  },3200);
}

/* ── Helpers ──────────────────────────────────────────────── */
function escHtml(s) {
  return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}


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