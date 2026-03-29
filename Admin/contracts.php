<?php
// ============================================================
//  LGU ENRILE HRMS — Contracts Page
//  File: Admin/contracts.php
// ============================================================
session_start();
// ── Session timeout — 8 hours ─────────────────────────────────
$_sessionLifetime = 8 * 60 * 60;
if (isset($_SESSION['login_time']) && (time() - strtotime($_SESSION['login_time']) > $_sessionLifetime)) {
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
if (empty($_SESSION['user_id']) || empty($_SESSION['user_email'])) {
    header('Location: ../Login/login.php'); exit();
}
$allowedRoles = ['admin', 'hr_staff', 'dept_head'];
if (!in_array($_SESSION['user_role'] ?? '', $allowedRoles)) {
    header('Location: ../Employee/Employee_portal.php'); exit();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';

$pdo->exec("CREATE TABLE IF NOT EXISTS contracts (
    contract_id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    contract_type ENUM('Permanent','Contractual','Casual','Job Order','Co-Terminus') NOT NULL DEFAULT 'Contractual',
    contract_number VARCHAR(100) DEFAULT NULL,
    position_title VARCHAR(150) NOT NULL,
    department_id INT DEFAULT NULL,
    salary_grade VARCHAR(50) DEFAULT NULL,
    monthly_salary DECIMAL(12,2) DEFAULT NULL,
    start_date DATE NOT NULL,
    end_date DATE DEFAULT NULL,
    status ENUM('Active','Expired','Terminated','Renewed') NOT NULL DEFAULT 'Active',
    remarks TEXT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_employee_id (employee_id),
    INDEX idx_department_id (department_id),
    INDEX idx_status (status),
    INDEX idx_end_date (end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($action) {
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { csrf_verify(true); }
    try {
        switch ($action) {
            case 'list':
                $where = []; $params = [];
                if (!empty($_GET['search'])) { $s='%'.$_GET['search'].'%'; $where[]="(CONCAT(e.first_name,' ',e.last_name) LIKE ? OR c.contract_number LIKE ? OR e.employee_code LIKE ?)"; $params=array_merge($params,[$s,$s,$s]); }
                if (!empty($_GET['status'])) { $where[]='c.status = ?'; $params[]=$_GET['status']; }
                if (!empty($_GET['type']))   { $where[]='c.contract_type = ?'; $params[]=$_GET['type']; }
                if (!empty($_GET['dept']))   { $where[]='d.name = ?'; $params[]=$_GET['dept']; }
                $pdo->exec("UPDATE contracts SET status='Expired' WHERE end_date IS NOT NULL AND end_date < CURDATE() AND status='Active'");
                $wSql = $where ? 'WHERE '.implode(' AND ',$where) : '';
                $page=max(1,(int)($_GET['page']??1)); $pageSize=10; $offset=($page-1)*$pageSize;
                $sortMap=['name'=>'e.last_name, e.first_name','start'=>'c.start_date','end'=>'c.end_date','status'=>'c.status','type'=>'c.contract_type'];
                $sortCol=$sortMap[$_GET['sort']??'']??'c.start_date';
                $sortDir=strtoupper($_GET['dir']??'DESC')==='ASC'?'ASC':'DESC';
                $cnt=$pdo->prepare("SELECT COUNT(*) FROM contracts c JOIN employees e ON c.employee_id=e.employee_id LEFT JOIN departments d ON c.department_id=d.department_id $wSql");
                $cnt->execute($params); $total=(int)$cnt->fetchColumn();
                $stmt=$pdo->prepare("SELECT c.*,e.first_name,e.last_name,e.employee_code,d.name AS department_name,DATEDIFF(c.end_date,CURDATE()) AS days_remaining FROM contracts c JOIN employees e ON c.employee_id=e.employee_id LEFT JOIN departments d ON c.department_id=d.department_id $wSql ORDER BY $sortCol $sortDir LIMIT $pageSize OFFSET $offset");
                $stmt->execute($params); $rows=$stmt->fetchAll();
                $stats=$pdo->query("SELECT COUNT(*) AS total,SUM(status='Active') AS active,SUM(status='Expired') AS expired,SUM(status='Terminated') AS cnt_terminated,SUM(status='Renewed') AS renewed,SUM(status='Active' AND end_date IS NOT NULL AND DATEDIFF(end_date,CURDATE()) BETWEEN 0 AND 30) AS expiring_soon FROM contracts")->fetch();
                echo json_encode(['success'=>true,'data'=>$rows,'total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/$pageSize),'stats'=>$stats]);
                break;
            case 'get':
                $id=(int)($_GET['id']??0);
                $stmt=$pdo->prepare("SELECT c.*,e.first_name,e.last_name,e.employee_code,e.employment_type,e.employment_status,d.name AS department_name FROM contracts c JOIN employees e ON c.employee_id=e.employee_id LEFT JOIN departments d ON c.department_id=d.department_id WHERE c.contract_id=?");
                $stmt->execute([$id]); $row=$stmt->fetch();
                echo $row?json_encode(['success'=>true,'data'=>$row]):json_encode(['success'=>false,'message'=>'Contract not found.']);
                break;
            case 'employees':
                $stmt=$pdo->query("SELECT e.employee_id,e.employee_code,e.first_name,e.last_name,e.position_title,d.name AS department_name FROM employees e LEFT JOIN departments d ON e.department_id=d.department_id WHERE e.employment_status='Active' ORDER BY e.last_name,e.first_name");
                echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]); break;
            case 'departments':
                $stmt=$pdo->query("SELECT department_id,name FROM departments ORDER BY name");
                echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]); break;
            case 'save':
                $id=(int)($_POST['contract_id']??0); $empId=(int)($_POST['employee_id']??0);
                $ds=$pdo->prepare("SELECT department_id FROM departments WHERE name=? LIMIT 1"); $ds->execute([trim($_POST['department']??'')]); $deptId=$ds->fetchColumn()?:null;
                if(!$deptId&&!empty($_POST['department_id'])) $deptId=(int)$_POST['department_id'];
                $fields=['employee_id'=>$empId,'contract_type'=>$_POST['contract_type']??'Contractual','contract_number'=>trim($_POST['contract_number']??'')?:null,'position_title'=>trim($_POST['position_title']??''),'department_id'=>$deptId,'salary_grade'=>trim($_POST['salary_grade']??'')?:null,'monthly_salary'=>($_POST['monthly_salary']??'')!==''?(float)$_POST['monthly_salary']:null,'start_date'=>$_POST['start_date']?:null,'end_date'=>$_POST['end_date']?:null,'status'=>$_POST['status']??'Active','remarks'=>trim($_POST['remarks']??'')?:null,'created_by'=>(int)$_SESSION['user_id']];
                if(!$fields['employee_id']||empty($fields['position_title'])||empty($fields['start_date'])){echo json_encode(['success'=>false,'message'=>'Employee, position, and start date are required.']);break;}
                if($id){$set=implode(', ',array_map(fn($k)=>"$k = ?",array_keys($fields)));$pdo->prepare("UPDATE contracts SET $set WHERE contract_id=?")->execute([...array_values($fields),$id]);echo json_encode(['success'=>true,'message'=>'Contract updated successfully.','id'=>$id]);}
                else{$cols=implode(', ',array_keys($fields));$marks=implode(', ',array_fill(0,count($fields),'?'));$pdo->prepare("INSERT INTO contracts ($cols) VALUES ($marks)")->execute(array_values($fields));echo json_encode(['success'=>true,'message'=>'Contract added successfully.','id'=>(int)$pdo->lastInsertId()]);}
                break;
            case 'renew':
                $id=(int)($_POST['contract_id']??0);
                if(!$id){echo json_encode(['success'=>false,'message'=>'Invalid contract.']);break;}
                $pdo->prepare("UPDATE contracts SET status='Renewed' WHERE contract_id=?")->execute([$id]);
                $old=$pdo->prepare("SELECT * FROM contracts WHERE contract_id=?");$old->execute([$id]);$o=$old->fetch();
                $pdo->prepare("INSERT INTO contracts (employee_id,contract_type,contract_number,position_title,department_id,salary_grade,monthly_salary,start_date,end_date,status,remarks,created_by) VALUES (?,?,?,?,?,?,?,?,?,'Active',?,?)")->execute([$o['employee_id'],$o['contract_type'],trim($_POST['new_contract_number']??'')?:null,$o['position_title'],$o['department_id'],$o['salary_grade'],$o['monthly_salary'],$_POST['new_start_date']??date('Y-m-d'),$_POST['new_end_date']?:null,trim($_POST['remarks']??'')?:null,(int)$_SESSION['user_id']]);
                echo json_encode(['success'=>true,'message'=>'Contract renewed successfully.','id'=>(int)$pdo->lastInsertId()]); break;
            case 'delete':
                $id=(int)($_POST['id']??0);
                if(!$id){echo json_encode(['success'=>false,'message'=>'Invalid ID.']);break;}
                $pdo->prepare("DELETE FROM contracts WHERE contract_id=?")->execute([$id]);
                echo json_encode(['success'=>true,'message'=>'Contract deleted.']); break;
            case 'history':
                $empId=(int)($_GET['employee_id']??0);
                $stmt=$pdo->prepare("SELECT c.*,d.name AS department_name FROM contracts c LEFT JOIN departments d ON c.department_id=d.department_id WHERE c.employee_id=? ORDER BY c.start_date DESC");
                $stmt->execute([$empId]); echo json_encode(['success'=>true,'data'=>$stmt->fetchAll()]); break;
            default: echo json_encode(['success'=>false,'message'=>'Unknown action.']);
        }
    } catch(PDOException $e) { echo json_encode(['success'=>false,'message'=>'DB Error: '.$e->getMessage()]); }
    exit();
}

$adminName  = ucwords($_SESSION['user_name'] ?? 'Administrator');
$roleLabels = ['admin'=>'Super Admin','hr_staff'=>'HR Staff','dept_head'=>'Department Head'];
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
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <?= csrf_meta() ?>
  <title>Contracts | LGU Enrile HRMS</title>
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
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Nunito', sans-serif; background: var(--gray-100); color: var(--secondary); display: flex; min-height: 100vh; }

    /* ── Sidebar ── */
    .sidebar { width: var(--sidebar-w); background: linear-gradient(180deg,#2563eb 0%,#1e40af 55%,#22c55e 100%); display: flex; flex-direction: column; position: fixed; top:0; left:0; height:100vh; z-index:200; transition:transform 0.3s; box-shadow:4px 0 28px rgba(0,0,0,0.20); }
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
    .nav-badge { margin-left:auto; background:#ef4444; color:white; font-size:0.60rem; font-weight:800; padding:2px 6px; border-radius:20px; }
    .sidebar-footer { padding:14px 12px; border-top:1px solid rgba(255,255,255,0.13); }
    .sidebar-user { display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:var(--radius-sm); background:rgba(255,255,255,0.10); }
    .user-avatar { width:36px; height:36px; border-radius:50%; background:rgba(255,255,255,0.25); border:2px solid rgba(255,255,255,0.50); display:flex; align-items:center; justify-content:center; font-weight:800; color:#fff; font-size:0.80rem; flex-shrink:0; }
    .user-info { flex:1; min-width:0; }
    .user-info strong { display:block; font-size:0.82rem; color:#fff; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .user-info span { font-size:0.68rem; color:rgba(255,255,255,0.52); }
    .logout-btn { display:flex; align-items:center; gap:9px; margin:8px 10px 2px; padding:10px 14px; border-radius:10px; background:rgba(239,68,68,0.12); border:1.5px solid rgba(239,68,68,0.28); color:#fca5a5; font-size:0.845rem; font-weight:700; text-decoration:none; transition:all .2s; cursor:pointer; }
    .logout-btn:hover { background:rgba(239,68,68,0.26); border-color:rgba(239,68,68,0.55); color:#fff; transform:translateY(-1px); }
    .logout-icon { font-size:1rem; }

    /* ── Main layout ── */
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
    .content { padding:24px 28px; flex:1; }

    /* ── Stat cards ── */
    .stat-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:16px; margin-bottom:22px; }
    .stat-card { background:var(--white); border-radius:var(--radius); padding:18px 20px; border:1.5px solid var(--gray-200); box-shadow:var(--shadow-sm); position:relative; overflow:hidden; transition:transform 0.2s,box-shadow 0.2s; animation:fadeIn 0.4s ease both; }
    .stat-card:hover { transform:translateY(-3px); box-shadow:var(--shadow-md); }
    .stat-card::before { content:''; position:absolute; top:0; left:0; right:0; height:4px; border-radius:var(--radius) var(--radius) 0 0; }
    .stat-card.blue::before   { background:linear-gradient(90deg,#2563eb,#60a5fa); }
    .stat-card.green::before  { background:linear-gradient(90deg,#22c55e,#86efac); }
    .stat-card.red::before    { background:linear-gradient(90deg,#ef4444,#fca5a5); }
    .stat-card.orange::before { background:linear-gradient(90deg,#f59e0b,#fcd34d); }
    .stat-card.purple::before { background:linear-gradient(90deg,#a855f7,#d8b4fe); }
    .stat-card.gray::before   { background:linear-gradient(90deg,#94a3b8,#cbd5e1); }
    .stat-card-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
    .stat-icon { width:44px; height:44px; border-radius:11px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; }
    .stat-card.blue   .stat-icon { background:#dbeafe; }
    .stat-card.green  .stat-icon { background:#dcfce7; }
    .stat-card.red    .stat-icon { background:#fee2e2; }
    .stat-card.orange .stat-icon { background:#fef3c7; }
    .stat-card.purple .stat-icon { background:#f3e8ff; }
    .stat-card.gray   .stat-icon { background:#f1f5f9; }
    .stat-value { font-size:2rem; font-weight:800; color:var(--gray-800); line-height:1; margin-bottom:3px; }
    .stat-label { font-size:0.78rem; color:var(--gray-400); font-weight:600; }

    /* ── Expiring alert ── */
    .expiring-alert { display:none; align-items:center; gap:10px; background:#fffbeb; border:1.5px solid #fbbf24; border-radius:var(--radius-sm); padding:12px 16px; margin-bottom:16px; font-size:0.875rem; color:#92400e; font-weight:600; animation:fadeIn 0.3s ease; }
    .expiring-alert.show { display:flex; }

    /* ── Toolbar ── */
    .toolbar { display:flex; align-items:center; gap:10px; margin-bottom:16px; flex-wrap:wrap; }
    .search-box { display:flex; align-items:center; gap:8px; background:var(--white); border:1.5px solid var(--gray-200); border-radius:var(--radius-sm); padding:9px 14px; flex:1; min-width:200px; max-width:320px; box-shadow:var(--shadow-sm); transition:border-color 0.2s; }
    .search-box:focus-within { border-color:var(--primary); }
    .search-box input { border:none; outline:none; font-family:'Nunito',sans-serif; font-size:0.875rem; width:100%; background:transparent; color:var(--gray-800); }
    .search-box input::placeholder { color:var(--gray-400); }
    .filter-select { padding:9px 12px; border:1.5px solid var(--gray-200); border-radius:var(--radius-sm); font-family:'Nunito',sans-serif; font-size:0.84rem; color:var(--gray-600); background:var(--white); cursor:pointer; box-shadow:var(--shadow-sm); outline:none; transition:border-color 0.2s; }
    .filter-select:focus { border-color:var(--primary); }
    .add-btn { margin-left:auto; display:flex; align-items:center; gap:7px; padding:9px 20px; background:linear-gradient(135deg,var(--primary),var(--primary-dark)); color:white; border:none; border-radius:var(--radius-sm); font-family:'Nunito',sans-serif; font-size:0.875rem; font-weight:700; cursor:pointer; transition:all 0.2s; box-shadow:0 2px 8px rgba(37,99,235,0.30); white-space:nowrap; }
    .add-btn:hover { transform:translateY(-2px); box-shadow:0 4px 14px rgba(37,99,235,0.40); }

    /* ── Panel / Table ── */
    .panel { background:var(--white); border-radius:var(--radius); border:1.5px solid var(--gray-200); box-shadow:var(--shadow-sm); overflow:hidden; animation:fadeIn 0.4s ease both; }
    .table-wrap { overflow-x:auto; }
    table { width:100%; border-collapse:collapse; font-size:0.83rem; }
    thead tr { background:var(--gray-50); }
    th { padding:11px 16px; text-align:left; font-size:0.69rem; font-weight:800; color:var(--gray-400); text-transform:uppercase; letter-spacing:0.07em; border-bottom:1.5px solid var(--gray-200); white-space:nowrap; }
    td { padding:13px 16px; border-bottom:1px solid var(--gray-100); color:var(--gray-800); vertical-align:middle; }
    tr:last-child td { border-bottom:none; }
    tbody tr { transition:background 0.15s; }
    tbody tr:hover { background:#f0f6ff; }

    /* ── Employee cell ── */
    .emp-cell { display:flex; align-items:center; gap:10px; }
    .emp-avatar { width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:0.75rem; font-weight:800; color:white; flex-shrink:0; box-shadow:0 2px 5px rgba(0,0,0,0.14); }
    .emp-name { font-weight:700; font-size:0.875rem; color:var(--gray-800); }
    .emp-code { font-size:0.70rem; color:var(--gray-400); margin-top:1px; }

    /* ── Badges ── */
    .badge { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:20px; font-size:0.70rem; font-weight:700; white-space:nowrap; }
    .b-active     { background:#dcfce7; color:#166534; }
    .b-expired    { background:#fee2e2; color:#991b1b; }
    .b-terminated { background:#f1f5f9; color:#64748b; }
    .b-renewed    { background:#f3e8ff; color:#6b21a8; }
    .b-permanent   { background:#dbeafe; color:#1e40af; }
    .b-contractual { background:#fce7f3; color:#9d174d; }
    .b-casual      { background:#dcfce7; color:#166534; }
    .b-joborder    { background:#fff7ed; color:#c2410c; }
    .b-coterminus  { background:#f5f3ff; color:#5b21b6; }

    /* ── Days pill ── */
    .days-pill { display:inline-block; padding:3px 10px; border-radius:20px; font-size:0.72rem; font-weight:800; }
    .dp-safe   { background:#dcfce7; color:#166534; }
    .dp-warn   { background:#fef3c7; color:#92400e; }
    .dp-danger { background:#fee2e2; color:#991b1b; }
    .dp-none   { color:var(--gray-400); font-size:0.78rem; font-weight:600; }

    /* ── Row actions ── */
    .row-actions { display:flex; gap:5px; align-items:center; justify-content:center; }
    .icon-btn { width:30px; height:30px; border-radius:7px; border:1.5px solid var(--gray-200); background:var(--white); cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:0.82rem; transition:all 0.2s; }
    .icon-btn.edit:hover     { border-color:#f59e0b; background:#fef3c7; }
    .icon-btn.renew:hover    { border-color:#22c55e; background:#dcfce7; }
    .icon-btn.history:hover  { border-color:#2563eb; background:#dbeafe; }
    .icon-btn.delete:hover   { border-color:#ef4444; background:#fee2e2; }

    /* ── Pagination ── */
    .pagination { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-top:1px solid var(--gray-100); font-size:0.80rem; color:var(--gray-400); flex-wrap:wrap; gap:10px; font-weight:600; }
    .page-btns { display:flex; gap:4px; }
    .page-btn { min-width:32px; height:32px; border-radius:7px; border:1.5px solid var(--gray-200); background:var(--white); cursor:pointer; font-size:0.80rem; font-weight:700; display:flex; align-items:center; justify-content:center; padding:0 8px; transition:all 0.2s; color:var(--gray-600); font-family:'Nunito',sans-serif; }
    .page-btn:hover { border-color:var(--primary); color:var(--primary); }
    .page-btn.active { background:var(--primary); border-color:var(--primary); color:white; }
    .page-btn:disabled { opacity:0.4; cursor:default; }

    /* ── Empty state ── */
    .empty-state { padding:52px 20px; text-align:center; }
    .empty-state .ei { font-size:2.8rem; margin-bottom:12px; opacity:0.5; }
    .empty-state p { font-size:0.875rem; font-weight:600; color:var(--gray-400); }

    /* ── Modals ── */
    .modal-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.55); z-index:500; align-items:center; justify-content:center; backdrop-filter:blur(4px); }
    .modal-overlay.open { display:flex; }
    .modal { background:var(--white); border-radius:var(--radius); box-shadow:var(--shadow-lg); width:92%; max-width:620px; max-height:92vh; overflow-y:auto; animation:modalIn 0.3s cubic-bezier(0.34,1.4,0.64,1) both; }
    .modal.sm { max-width:440px; }
    .modal.md { max-width:540px; }
    .modal.lg { max-width:700px; }
    @keyframes modalIn { from{opacity:0;transform:scale(0.88) translateY(20px);}to{opacity:1;transform:scale(1) translateY(0);} }
    .modal-header { display:flex; align-items:center; justify-content:space-between; padding:20px 24px 16px; background:linear-gradient(135deg,#2563eb,#1e40af); border-radius:var(--radius) var(--radius) 0 0; }
    .modal-header.green  { background:linear-gradient(135deg,#22c55e,#15803d); }
    .modal-header.red    { background:linear-gradient(135deg,#ef4444,#dc2626); }
    .modal-header.amber  { background:linear-gradient(135deg,#f59e0b,#d97706); }
    .modal-header h3 { font-size:1.05rem; font-weight:800; color:#fff; font-family:'Playfair Display',serif; }
    .modal-header p  { font-size:0.74rem; color:rgba(255,255,255,0.65); margin-top:2px; }
    .modal-close { width:32px; height:32px; border-radius:50%; background:rgba(255,255,255,0.18); border:none; color:#fff; font-size:1rem; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:background 0.2s; flex-shrink:0; }
    .modal-close:hover { background:rgba(255,255,255,0.32); }
    .modal-body { padding:22px 24px; }
    .modal-footer { display:flex; justify-content:flex-end; gap:10px; padding:14px 24px 18px; border-top:1px solid var(--gray-100); }

    /* ── Form elements ── */
    .form-section { font-size:0.72rem; font-weight:800; color:var(--primary); text-transform:uppercase; letter-spacing:0.10em; margin:16px 0 10px; padding-bottom:5px; border-bottom:1.5px solid #dbeafe; }
    .form-section:first-child { margin-top:0; }
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
    .form-group textarea { resize:vertical; height:80px; }

    /* ── Buttons ── */
    .btn { padding:9px 22px; border-radius:var(--radius-sm); font-family:'Nunito',sans-serif; font-size:0.875rem; font-weight:700; cursor:pointer; transition:all 0.2s; border:none; }
    .btn-cancel  { background:var(--white); border:1.5px solid var(--gray-200); color:var(--gray-600); }
    .btn-cancel:hover { border-color:var(--gray-400); background:var(--gray-50); }
    .btn-primary { background:linear-gradient(135deg,var(--primary),var(--primary-dark)); color:white; box-shadow:0 2px 8px rgba(37,99,235,0.28); }
    .btn-primary:hover { transform:translateY(-1px); box-shadow:0 4px 14px rgba(37,99,235,0.38); }
    .btn-success { background:linear-gradient(135deg,#22c55e,#15803d); color:white; }
    .btn-success:hover { transform:translateY(-1px); }
    .btn-danger  { background:linear-gradient(135deg,#ef4444,#dc2626); color:white; }
    .btn-danger:hover { transform:translateY(-1px); }

    /* ── Delete confirm ── */
    .confirm-body { padding:28px 24px; text-align:center; }
    .confirm-icon { font-size:2.6rem; margin-bottom:12px; }
    .confirm-body h4 { font-size:1rem; font-weight:800; color:var(--gray-800); margin-bottom:6px; }
    .confirm-body p  { font-size:0.84rem; color:var(--gray-600); line-height:1.6; }

    /* ── Contract history detail hero ── */
    .detail-hero { background:linear-gradient(135deg,#2563eb 0%,#1e40af 60%,#22c55e 100%); padding:22px 24px; border-radius:var(--radius) var(--radius) 0 0; display:flex; align-items:center; gap:16px; }
    .detail-avatar { width:54px; height:54px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:1.2rem; font-weight:800; color:white; border:3px solid rgba(255,255,255,0.70); box-shadow:0 4px 14px rgba(0,0,0,0.20); flex-shrink:0; }
    .detail-info h3 { font-size:1.05rem; font-weight:800; color:#fff; font-family:'Playfair Display',serif; }
    .detail-info p  { font-size:0.76rem; color:rgba(255,255,255,0.65); margin-top:2px; }
    .detail-close { width:32px; height:32px; border-radius:50%; background:rgba(255,255,255,0.18); border:none; color:#fff; font-size:1rem; cursor:pointer; display:flex; align-items:center; justify-content:center; margin-left:auto; align-self:flex-start; transition:background 0.2s; }
    .detail-close:hover { background:rgba(255,255,255,0.32); }
    .detail-body { padding:18px 24px; }
    .detail-section { font-size:0.72rem; font-weight:800; color:var(--primary); text-transform:uppercase; letter-spacing:0.10em; margin:14px 0 8px; padding-bottom:5px; border-bottom:1.5px solid #dbeafe; }
    .detail-section:first-child { margin-top:0; }

    /* History timeline items */
    .hist-item { background:var(--gray-50); border-radius:var(--radius-sm); border:1.5px solid var(--gray-200); padding:14px 16px; margin-bottom:10px; position:relative; }
    .hist-item:last-child { margin-bottom:0; }
    .hist-item.active     { border-left:4px solid #22c55e; }
    .hist-item.expired    { border-left:4px solid #ef4444; }
    .hist-item.renewed    { border-left:4px solid #a855f7; }
    .hist-item.terminated { border-left:4px solid #94a3b8; }
    .hist-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px; }
    .hist-type { font-weight:800; font-size:0.875rem; color:var(--gray-800); }
    .hist-num  { font-size:0.72rem; color:var(--gray-400); margin-top:2px; }
    .hist-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(120px,1fr)); gap:8px; }
    .hist-field { background:var(--white); border-radius:7px; padding:8px 10px; border:1px solid var(--gray-100); }
    .hist-field label { font-size:0.62rem; font-weight:800; color:var(--gray-400); text-transform:uppercase; letter-spacing:0.07em; display:block; margin-bottom:2px; }
    .hist-field span  { font-size:0.80rem; font-weight:700; color:var(--gray-800); }
    .hist-remarks { background:#fef3c7; border:1px solid #fcd34d; border-radius:7px; padding:8px 10px; margin-top:8px; font-size:0.78rem; color:#78350f; line-height:1.5; }

    /* ── Toast ── */
    .toast { position:fixed; bottom:28px; right:28px; padding:12px 20px; border-radius:var(--radius-sm); font-size:0.84rem; font-weight:700; box-shadow:var(--shadow-lg); z-index:900; color:white; animation:fadeIn 0.3s ease both; max-width:340px; }
    .toast.success { background:linear-gradient(135deg,#22c55e,#16a34a); }
    .toast.error   { background:linear-gradient(135deg,#ef4444,#dc2626); }
    .toast.info    { background:linear-gradient(135deg,#2563eb,#1e40af); }

    @keyframes fadeIn { from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);} }

    /* ── Responsive ── */
    .overlay-mob { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:150; }
    .overlay-mob.show { display:block; }
    @media(max-width:1100px) { .stat-cards { grid-template-columns:repeat(3,1fr); } }
    @media(max-width:768px) {
      .sidebar { transform:translateX(-100%); }
      .sidebar.open { transform:translateX(0); }
      .main { margin-left:0; }
      .menu-toggle { display:block; }
      .content { padding:14px 12px; }
      .topbar { padding:0 14px; }
      .stat-cards { grid-template-columns:repeat(2,1fr); gap:10px; }
      .toolbar { flex-direction:column; align-items:stretch; }
      .search-box { max-width:100%; }
      .filter-select { width:100%; }
      .add-btn { margin-left:0; width:100%; justify-content:center; }
      .pagination { flex-direction:column; align-items:center; }
      .form-row { grid-template-columns:1fr; }
      .hist-grid { grid-template-columns:1fr 1fr; }
    }
    @media(max-width:480px) {
      .stat-cards { grid-template-columns:1fr 1fr; }
      .content { padding:12px 10px; }
      .topbar { padding:0 12px; }
      .topbar-title p { display:none; }
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
      <a class="nav-item" href="pds_saln.php"><div class="nav-icon">🆔</div>PDS &amp; SALN</a>
      <a class="nav-item" href="service_records.php"><div class="nav-icon">📜</div>Service Records</a>
      <a class="nav-item active" href="contracts.php"><div class="nav-icon">📝</div>Contracts</a>
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
        <h1>Contracts</h1>
        <p>Manage employee employment contracts</p>
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

      <!-- Stat Cards -->
      <div class="stat-cards">
        <div class="stat-card blue">
          <div class="stat-card-header">
            <div class="stat-icon">📑</div>
          </div>
          <div class="stat-value" id="stTotal">—</div>
          <div class="stat-label">Total Contracts</div>
        </div>
        <div class="stat-card green">
          <div class="stat-card-header">
            <div class="stat-icon">✅</div>
          </div>
          <div class="stat-value" id="stActive">—</div>
          <div class="stat-label">Active</div>
        </div>
        <div class="stat-card red">
          <div class="stat-card-header">
            <div class="stat-icon">⛔</div>
          </div>
          <div class="stat-value" id="stExpired">—</div>
          <div class="stat-label">Expired</div>
        </div>
        <div class="stat-card orange">
          <div class="stat-card-header">
            <div class="stat-icon">⏳</div>
          </div>
          <div class="stat-value" id="stExpiring">—</div>
          <div class="stat-label">Expiring in 30 Days</div>
        </div>
        <div class="stat-card purple">
          <div class="stat-card-header">
            <div class="stat-icon">🔄</div>
          </div>
          <div class="stat-value" id="stRenewed">—</div>
          <div class="stat-label">Renewed</div>
        </div>
        <div class="stat-card gray">
          <div class="stat-card-header">
            <div class="stat-icon">🚫</div>
          </div>
          <div class="stat-value" id="stTerminated">—</div>
          <div class="stat-label">Terminated</div>
        </div>
      </div>

      <!-- Expiring Alert -->
      <div class="expiring-alert" id="expiringAlert">
        ⚠️ <span id="expiringAlertText"></span>
      </div>

      <!-- Toolbar -->
      <div class="toolbar">
        <div class="search-box">
          <span style="color:var(--gray-400);font-size:1rem;">🔍</span>
          <input type="text" id="searchInput" placeholder="Search name, code, contract no…" oninput="debounceLoad()"/>
        </div>
        <select class="filter-select" id="filterStatus" onchange="loadContracts()">
          <option value="">All Statuses</option>
          <option value="Active">Active</option>
          <option value="Expired">Expired</option>
          <option value="Renewed">Renewed</option>
          <option value="Terminated">Terminated</option>
        </select>
        <select class="filter-select" id="filterType" onchange="loadContracts()">
          <option value="">All Types</option>
          <option value="Permanent">Permanent</option>
          <option value="Contractual">Contractual</option>
          <option value="Casual">Casual</option>
          <option value="Job Order">Job Order</option>
          <option value="Co-Terminus">Co-Terminus</option>
        </select>
        <select class="filter-select" id="filterDept" onchange="loadContracts()">
          <option value="">All Departments</option>
        </select>
        <button class="add-btn" onclick="exportContractsCSV()" style="background:linear-gradient(135deg,#16a34a,#15803d);">📥 Export CSV</button>
        <button class="add-btn" onclick="openAddModal()">➕ Add Contract</button>
      </div>

      <!-- Table Panel -->
      <div class="panel">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Employee</th>
                <th>Contract No.</th>
                <th>Type</th>
                <th>Position</th>
                <th>Start Date</th>
                <th>End Date</th>
                <th>Days Left</th>
                <th>Status</th>
                <th style="text-align:center">Actions</th>
              </tr>
            </thead>
            <tbody id="contractsTbody">
              <tr><td colspan="9"><div class="empty-state"><div class="ei">⏳</div><p>Loading contracts…</p></div></td></tr>
            </tbody>
          </table>
        </div>
        <div class="pagination" id="paginationWrap">
          <span id="pageInfo" style="color:var(--gray-400);font-weight:600;"></span>
          <div class="page-btns" id="pageBtns"></div>
        </div>
      </div>

    </div><!-- /content -->
  </div><!-- /main -->

  <!-- ══ ADD / EDIT MODAL ══ -->
  <div class="modal-overlay" id="modalContract">
    <div class="modal">
      <div class="modal-header">
        <div>
          <h3 id="modalTitle">Add Contract</h3>
          <p id="modalSub">Fill in the contract details below</p>
        </div>
        <button class="modal-close" onclick="closeModal('modalContract')">✕</button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="fId"/>

        <div class="form-section">👤 Employee & Contract Type</div>
        <div class="form-row">
          <div class="form-group form-full">
            <label>Employee <span class="req">*</span></label>
            <select id="fEmp" onchange="fillDefaults()">
              <option value="">— Select Employee —</option>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Contract Type <span class="req">*</span></label>
            <select id="fType">
              <option value="Contractual">Contractual</option>
              <option value="Permanent">Permanent</option>
              <option value="Casual">Casual</option>
              <option value="Job Order">Job Order</option>
              <option value="Co-Terminus">Co-Terminus</option>
            </select>
          </div>
          <div class="form-group">
            <label>Contract Number</label>
            <input type="text" id="fNo" placeholder="e.g. CON-2026-001"/>
          </div>
        </div>

        <div class="form-section">🏢 Position & Department</div>
        <div class="form-row">
          <div class="form-group form-full">
            <label>Position Title <span class="req">*</span></label>
            <input type="text" id="fPos" placeholder="e.g. Administrative Aide V"/>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Department</label>
            <select id="fDept"><option value="">— Select Department —</option></select>
          </div>
          <div class="form-group">
            <label>Salary Grade</label>
            <input type="text" id="fSG" placeholder="e.g. SG-10"/>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group form-full">
            <label>Monthly Salary (₱)</label>
            <input type="number" id="fSal" placeholder="0.00" min="0" step="0.01"/>
          </div>
        </div>

        <div class="form-section">📅 Contract Period</div>
        <div class="form-row">
          <div class="form-group">
            <label>Start Date <span class="req">*</span></label>
            <input type="date" id="fStart"/>
          </div>
          <div class="form-group">
            <label>End Date <span style="color:var(--gray-400);font-weight:600;">(blank = no end)</span></label>
            <input type="date" id="fEnd"/>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Status</label>
            <select id="fStat">
              <option value="Active">Active</option>
              <option value="Expired">Expired</option>
              <option value="Terminated">Terminated</option>
              <option value="Renewed">Renewed</option>
            </select>
          </div>
          <div class="form-group">
            <label>&nbsp;</label>
            <!-- spacer -->
          </div>
        </div>
        <div class="form-row">
          <div class="form-group form-full">
            <label>Remarks</label>
            <textarea id="fRem" placeholder="Optional notes or conditions…"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-cancel" onclick="closeModal('modalContract')">Cancel</button>
        <button class="btn btn-primary" onclick="saveContract()">💾 Save Contract</button>
      </div>
    </div>
  </div>

  <!-- ══ RENEW MODAL ══ -->
  <div class="modal-overlay" id="modalRenew">
    <div class="modal md">
      <div class="modal-header green">
        <div>
          <h3>🔄 Renew Contract</h3>
          <p>Current contract will be marked Renewed and a new one created</p>
        </div>
        <button class="modal-close" onclick="closeModal('modalRenew')">✕</button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="rId"/>
        <div class="form-section">📅 New Contract Period</div>
        <div class="form-row">
          <div class="form-group">
            <label>New Start Date <span class="req">*</span></label>
            <input type="date" id="rStart"/>
          </div>
          <div class="form-group">
            <label>New End Date</label>
            <input type="date" id="rEnd"/>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group form-full">
            <label>New Contract Number</label>
            <input type="text" id="rNo" placeholder="e.g. CON-2026-002"/>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group form-full">
            <label>Remarks</label>
            <textarea id="rRem" placeholder="Reason for renewal…"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-cancel" onclick="closeModal('modalRenew')">Cancel</button>
        <button class="btn btn-success" onclick="submitRenew()">🔄 Confirm Renewal</button>
      </div>
    </div>
  </div>

  <!-- ══ HISTORY MODAL ══ -->
  <div class="modal-overlay" id="modalHistory">
    <div class="modal lg">
      <div class="detail-hero" id="histHero">
        <div class="detail-avatar" id="histAvatar"></div>
        <div class="detail-info">
          <h3 id="histName">Contract History</h3>
          <p id="histSub"></p>
        </div>
        <button class="detail-close" onclick="closeModal('modalHistory')">✕</button>
      </div>
      <div class="detail-body" id="histBody">
        <div class="empty-state"><div class="ei">⏳</div><p>Loading…</p></div>
      </div>
    </div>
  </div>

  <!-- ══ DELETE CONFIRM ══ -->
  <div class="modal-overlay" id="modalDelete">
    <div class="modal sm">
      <div class="modal-header red">
        <div><h3>Delete Contract</h3><p>This cannot be undone</p></div>
        <button class="modal-close" onclick="closeModal('modalDelete')">✕</button>
      </div>
      <div class="confirm-body">
        <div class="confirm-icon">⚠️</div>
        <h4>Are you sure?</h4>
        <p>You are about to permanently delete this contract record. All associated data will be lost.</p>
        <input type="hidden" id="delId"/>
      </div>
      <div class="modal-footer">
        <button class="btn btn-cancel" onclick="closeModal('modalDelete')">Cancel</button>
        <button class="btn btn-danger"  onclick="confirmDelete()">🗑️ Yes, Delete</button>
      </div>
    </div>
  </div>

  <script>
const CSRF_TOKEN = document.querySelector('meta[name=csrf-token]').content;
  var API = './contracts.php';
  var currentPage = 1;
  var empList  = [];
  var deptList = [];
  var debounceTimer;
  var COLORS = ['#2563eb','#22c55e','#f59e0b','#ef4444','#a855f7','#06b6d4','#ec4899','#0ea5e9','#84cc16','#f97316','#6366f1','#14b8a6'];

  function avatarColor(str) {
    var n = 0;
    for (var i = 0; i < (str||'').length; i++) n += (str||'').charCodeAt(i);
    return COLORS[Math.abs(n) % COLORS.length];
  }
  function fmt(d) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-PH', {year:'numeric',month:'short',day:'numeric'});
  }

  // ── Init ─────────────────────────────────────────────────────
  (async function init() {
    await loadDropdowns();
    loadContracts();
  })();

  async function loadDropdowns() {
    try {
      var er = await fetch(API + '?action=employees').then(function(r){return r.json();});
      var dr = await fetch(API + '?action=departments').then(function(r){return r.json();});
      if (er.success) empList  = er.data;
      if (dr.success) deptList = dr.data;

      document.getElementById('fEmp').innerHTML = '<option value="">— Select Employee —</option>' +
        empList.map(function(e){
          return '<option value="' + e.employee_id + '">' + e.last_name + ', ' + e.first_name + ' (' + (e.employee_code||'—') + ')</option>';
        }).join('');

      document.getElementById('fDept').innerHTML = '<option value="">— Select Department —</option>' +
        deptList.map(function(d){return '<option value="' + d.department_id + '">' + d.name + '</option>';}).join('');

      document.getElementById('filterDept').innerHTML = '<option value="">All Departments</option>' +
        deptList.map(function(d){return '<option value="' + d.name + '">' + d.name + '</option>';}).join('');
    } catch(e) { console.error('Dropdown error', e); }
  }

  function fillDefaults() {
    var id = parseInt(document.getElementById('fEmp').value);
    if (!id) return;
    var e = empList.find(function(x){return x.employee_id === id;});
    if (!e) return;
    document.getElementById('fPos').value = e.position_title || '';
    var opt = Array.from(document.getElementById('fDept').options).find(function(o){return o.text === (e.department_name||'');});
    if (opt) document.getElementById('fDept').value = opt.value;
  }

  // ── Load contracts ───────────────────────────────────────────
  async function loadContracts(page) {
    if (page) currentPage = page;
    var params = new URLSearchParams({
      action: 'list', page: currentPage,
      search: document.getElementById('searchInput').value.trim(),
      status: document.getElementById('filterStatus').value,
      type:   document.getElementById('filterType').value,
      dept:   document.getElementById('filterDept').value,
    });
    try {
      var json = await fetch(API + '?' + params).then(function(r){return r.json();});
      if (!json.success) { showTableError(json.message); return; }
      renderStats(json.stats);
      renderTable(json.data, json.total);
      renderPagination(json.page, json.pages, json.total);
    } catch(e) { showTableError('Connection error: ' + e.message); }
  }

  function debounceLoad() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(function(){loadContracts(1);}, 320);
  }

  function renderStats(s) {
    if (!s) return;
    document.getElementById('stTotal').textContent     = s.total || 0;
    document.getElementById('stActive').textContent    = s.active || 0;
    document.getElementById('stExpired').textContent   = s.expired || 0;
    document.getElementById('stExpiring').textContent  = s.expiring_soon || 0;
    document.getElementById('stRenewed').textContent   = s.renewed || 0;
    document.getElementById('stTerminated').textContent= s.cnt_terminated || 0;
    var n = parseInt(s.expiring_soon || 0);
    var al = document.getElementById('expiringAlert');
    if (n > 0) {
      document.getElementById('expiringAlertText').textContent =
        n + ' contract' + (n > 1 ? 's are' : ' is') + ' expiring within 30 days. Please review and renew as needed.';
      al.classList.add('show');
    } else {
      al.classList.remove('show');
    }
  }

  function renderTable(rows, total) {
    var tbody = document.getElementById('contractsTbody');
    if (!rows || !rows.length) {
      tbody.innerHTML = '<tr><td colspan="9"><div class="empty-state"><div class="ei">📝</div><p>No contracts found matching your search.</p></div></td></tr>';
      return;
    }
    tbody.innerHTML = rows.map(function(c) {
      var fullName = c.last_name + ', ' + c.first_name;
      var initials = ((c.first_name||'').charAt(0) + (c.last_name||'').charAt(0)).toUpperCase();
      var color    = avatarColor(c.employee_code || c.last_name);
      var typeBadge = typeClass(c.contract_type);
      var statusBadge = statusClass(c.status);
      return '<tr>' +
        '<td><div class="emp-cell">' +
          '<div class="emp-avatar" style="background:' + color + '">' + initials + '</div>' +
          '<div><div class="emp-name">' + fullName + '</div><div class="emp-code">' + (c.employee_code||'') + '</div></div>' +
        '</div></td>' +
        '<td style="font-weight:700;color:var(--primary);font-size:0.82rem;">' + (c.contract_number || '<span style="color:var(--gray-400)">—</span>') + '</td>' +
        '<td><span class="badge ' + typeBadge + '">' + c.contract_type + '</span></td>' +
        '<td style="font-size:0.82rem;color:var(--gray-600);max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + (c.position_title||'') + '">' + (c.position_title||'—') + '</td>' +
        '<td style="font-size:0.80rem;color:var(--gray-600);">' + fmt(c.start_date) + '</td>' +
        '<td style="font-size:0.80rem;color:var(--gray-600);">' + (c.end_date ? fmt(c.end_date) : '<span style="color:var(--gray-400)">—</span>') + '</td>' +
        '<td>' + daysPill(c.days_remaining, c.end_date, c.status) + '</td>' +
        '<td><span class="badge ' + statusBadge + '">' + c.status + '</span></td>' +
        '<td><div class="row-actions">' +
          '<button class="icon-btn edit"    title="Edit"    onclick="openEditModal(' + c.contract_id + ')">✏️</button>' +
          '<button class="icon-btn renew"   title="Renew"   onclick="openRenewModal(' + c.contract_id + ')">🔄</button>' +
          '<button class="icon-btn history" title="History" onclick="openHistory(' + c.employee_id + ',\'' + fullName.replace(/'/g,'') + '\',\'' + color + '\')">📋</button>' +
          '<button class="icon-btn delete"  title="Delete"  onclick="openDeleteModal(' + c.contract_id + ')">🗑️</button>' +
        '</div></td>' +
      '</tr>';
    }).join('');
  }

  function typeClass(t) {
    var m = {'Permanent':'b-permanent','Contractual':'b-contractual','Casual':'b-casual','Job Order':'b-joborder','Co-Terminus':'b-coterminus'};
    return m[t] || 'b-contractual';
  }
  function statusClass(s) {
    var m = {'Active':'b-active','Expired':'b-expired','Terminated':'b-terminated','Renewed':'b-renewed'};
    return m[s] || 'b-terminated';
  }
  function daysPill(days, endDate, status) {
    if (status !== 'Active' || !endDate) return '<span class="dp-none">—</span>';
    var d = parseInt(days);
    if (isNaN(d) || d < 0) return '<span class="days-pill dp-danger">Overdue</span>';
    if (d <= 7)  return '<span class="days-pill dp-danger">' + d + ' days</span>';
    if (d <= 30) return '<span class="days-pill dp-warn">'   + d + ' days</span>';
    return '<span class="days-pill dp-safe">' + d + ' days</span>';
  }

  // ── Pagination ───────────────────────────────────────────────
  function renderPagination(page, pages, total) {
    var from = total ? (page-1)*10+1 : 0;
    var to   = Math.min(page*10, total);
    document.getElementById('pageInfo').textContent = 'Showing ' + from + '–' + to + ' of ' + total + ' contract' + (total !== 1 ? 's' : '');
    var pb = document.getElementById('pageBtns');
    pb.innerHTML = '';
    function mkBtn(label, pg, disabled) {
      var b = document.createElement('button');
      b.className = 'page-btn' + (pg === page ? ' active' : '');
      b.textContent = label;
      b.disabled = !!disabled;
      b.onclick = function(){loadContracts(pg);};
      pb.appendChild(b);
    }
    mkBtn('‹', page-1, page === 1);
    for (var i = Math.max(1,page-2); i <= Math.min(pages,page+2); i++) mkBtn(i, i);
    mkBtn('›', page+1, page >= pages);
  }

  function showTableError(msg) {
    document.getElementById('contractsTbody').innerHTML =
      '<tr><td colspan="9"><div class="empty-state"><div class="ei">⚠️</div><p>' + msg + '</p></div></td></tr>';
  }

  // ── Add Modal ────────────────────────────────────────────────
  function openAddModal() {
    document.getElementById('modalTitle').textContent = 'Add Contract';
    document.getElementById('modalSub').textContent   = 'Fill in the contract details below';
    document.getElementById('fId').value    = '';
    document.getElementById('fEmp').value   = '';
    document.getElementById('fType').value  = 'Contractual';
    document.getElementById('fNo').value    = '';
    document.getElementById('fPos').value   = '';
    document.getElementById('fDept').value  = '';
    document.getElementById('fSG').value    = '';
    document.getElementById('fSal').value   = '';
    document.getElementById('fStart').value = new Date().toISOString().substring(0,10);
    document.getElementById('fEnd').value   = '';
    document.getElementById('fStat').value  = 'Active';
    document.getElementById('fRem').value   = '';
    openModal('modalContract');
  }

  async function openEditModal(id) {
    try {
      var json = await fetch(API + '?action=get&id=' + id).then(function(r){return r.json();});
      if (!json.success) { showToast('⚠️ Could not load contract.', 'info'); return; }
      var c = json.data;
      document.getElementById('modalTitle').textContent = 'Edit Contract';
      document.getElementById('modalSub').textContent   = 'Editing: ' + c.first_name + ' ' + c.last_name;
      document.getElementById('fId').value    = c.contract_id;
      document.getElementById('fEmp').value   = c.employee_id;
      document.getElementById('fType').value  = c.contract_type;
      document.getElementById('fNo').value    = c.contract_number  || '';
      document.getElementById('fPos').value   = c.position_title   || '';
      document.getElementById('fDept').value  = c.department_id    || '';
      document.getElementById('fSG').value    = c.salary_grade     || '';
      document.getElementById('fSal').value   = c.monthly_salary   || '';
      document.getElementById('fStart').value = (c.start_date||'').substring(0,10);
      document.getElementById('fEnd').value   = (c.end_date  ||'').substring(0,10);
      document.getElementById('fStat').value  = c.status;
      document.getElementById('fRem').value   = c.remarks || '';
      openModal('modalContract');
    } catch(e) { showToast('❌ Error: ' + e.message, 'error'); }
  }

  async function saveContract() {
    var emp = document.getElementById('fEmp').value;
    var pos = document.getElementById('fPos').value.trim();
    var sd  = document.getElementById('fStart').value;
    if (!emp) { showToast('⚠️ Please select an employee.', 'info'); return; }
    if (!pos) { showToast('⚠️ Position title is required.', 'info'); return; }
    if (!sd)  { showToast('⚠️ Start date is required.', 'info'); return; }

    var fd = new FormData();
    fd.append('action',          'save');
    fd.append('contract_id',     document.getElementById('fId').value);
    fd.append('employee_id',     emp);
    fd.append('contract_type',   document.getElementById('fType').value);
    fd.append('contract_number', document.getElementById('fNo').value);
    fd.append('position_title',  pos);
    fd.append('department_id',   document.getElementById('fDept').value);
    fd.append('salary_grade',    document.getElementById('fSG').value);
    fd.append('monthly_salary',  document.getElementById('fSal').value);
    fd.append('start_date',      sd);
    fd.append('end_date',        document.getElementById('fEnd').value);
    fd.append('status',          document.getElementById('fStat').value);
    fd.append('remarks',         document.getElementById('fRem').value);

    try {
      fd.append('csrf_token', CSRF_TOKEN);
      var json = await fetch(API, {method:'POST',body:fd}).then(function(r){return r.json();});
      if (json.success) {
        closeModal('modalContract');
        loadContracts();
        showToast('✅ ' + json.message, 'success');
      } else { showToast('❌ ' + json.message, 'error'); }
    } catch(e) { showToast('❌ Save failed: ' + e.message, 'error'); }
  }

  // ── Renew ────────────────────────────────────────────────────
  function openRenewModal(id) {
    document.getElementById('rId').value    = id;
    document.getElementById('rStart').value = new Date().toISOString().substring(0,10);
    document.getElementById('rEnd').value   = '';
    document.getElementById('rNo').value    = '';
    document.getElementById('rRem').value   = '';
    openModal('modalRenew');
  }

  async function submitRenew() {
    var sd = document.getElementById('rStart').value;
    if (!sd) { showToast('⚠️ New start date is required.', 'info'); return; }
    var fd = new FormData();
    fd.append('action',              'renew');
    fd.append('contract_id',         document.getElementById('rId').value);
    fd.append('new_start_date',      sd);
    fd.append('new_end_date',        document.getElementById('rEnd').value);
    fd.append('new_contract_number', document.getElementById('rNo').value);
    fd.append('remarks',             document.getElementById('rRem').value);
    try {
      fd.append('csrf_token', CSRF_TOKEN);
      var json = await fetch(API, {method:'POST',body:fd}).then(function(r){return r.json();});
      if (json.success) {
        closeModal('modalRenew');
        loadContracts();
        showToast('✅ Contract renewed successfully!', 'success');
      } else { showToast('❌ ' + json.message, 'error'); }
    } catch(e) { showToast('❌ Renew failed: ' + e.message, 'error'); }
  }

  // ── History ──────────────────────────────────────────────────
  async function openHistory(empId, name, color) {
    var initials = name.split(',').map(function(p){return (p.trim()||'').charAt(0);}).join('').toUpperCase();
    document.getElementById('histAvatar').textContent      = initials;
    document.getElementById('histAvatar').style.background = color || '#2563eb';
    document.getElementById('histName').textContent        = name;
    document.getElementById('histSub').textContent         = 'Full contract history';
    document.getElementById('histBody').innerHTML          = '<div class="empty-state"><div class="ei">⏳</div><p>Loading…</p></div>';
    openModal('modalHistory');

    try {
      var json = await fetch(API + '?action=history&employee_id=' + empId).then(function(r){return r.json();});
      if (!json.success || !json.data.length) {
        document.getElementById('histBody').innerHTML = '<div class="empty-state"><div class="ei">📄</div><p>No contracts found for this employee.</p></div>';
        return;
      }
      document.getElementById('histSub').textContent = json.data.length + ' contract record' + (json.data.length !== 1 ? 's' : '') + ' found';
      document.getElementById('histBody').innerHTML =
        '<div class="detail-section">Contract Timeline</div>' +
        json.data.map(function(c) {
          var cls = (c.status||'').toLowerCase();
          return '<div class="hist-item ' + cls + '">' +
            '<div class="hist-header">' +
              '<div>' +
                '<div class="hist-type">' + c.contract_type + '</div>' +
                '<div class="hist-num">' + (c.contract_number || 'No contract number') + '</div>' +
              '</div>' +
              '<span class="badge ' + statusClass(c.status) + '">' + c.status + '</span>' +
            '</div>' +
            '<div class="hist-grid">' +
              '<div class="hist-field"><label>Position</label><span>' + (c.position_title||'—') + '</span></div>' +
              '<div class="hist-field"><label>Department</label><span>' + (c.department_name||'—') + '</span></div>' +
              '<div class="hist-field"><label>Start Date</label><span>' + fmt(c.start_date) + '</span></div>' +
              '<div class="hist-field"><label>End Date</label><span>' + (c.end_date ? fmt(c.end_date) : 'No end date') + '</span></div>' +
              '<div class="hist-field"><label>Salary Grade</label><span>' + (c.salary_grade||'—') + '</span></div>' +
              (c.monthly_salary ? '<div class="hist-field"><label>Monthly</label><span>₱' + parseFloat(c.monthly_salary).toLocaleString() + '</span></div>' : '') +
            '</div>' +
            (c.remarks ? '<div class="hist-remarks">💬 ' + c.remarks + '</div>' : '') +
          '</div>';
        }).join('');
    } catch(e) {
      document.getElementById('histBody').innerHTML = '<div class="empty-state"><div class="ei">⚠️</div><p>Error loading history.</p></div>';
    }
  }

  // ── Delete ───────────────────────────────────────────────────
  function openDeleteModal(id) {
    document.getElementById('delId').value = id;
    openModal('modalDelete');
  }

  async function confirmDelete() {
    var fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id',     document.getElementById('delId').value);
    try {
      fd.append('csrf_token', CSRF_TOKEN);
      var json = await fetch(API, {method:'POST',body:fd}).then(function(r){return r.json();});
      if (json.success) {
        closeModal('modalDelete');
        loadContracts();
        showToast('🗑️ Contract deleted.', 'error');
      } else { showToast('❌ ' + json.message, 'error'); }
    } catch(e) { showToast('❌ Delete failed: ' + e.message, 'error'); }
  }

  // ── Helpers ──────────────────────────────────────────────────
  function openModal(id)  { document.getElementById(id).classList.add('open'); }
  function closeModal(id) { document.getElementById(id).classList.remove('open'); }
  document.querySelectorAll('.modal-overlay').forEach(function(el) {
    el.addEventListener('click', function(e) { if (e.target === el) el.classList.remove('open'); });
  });

  function showToast(msg, type) {
    document.querySelectorAll('.toast').forEach(function(t){t.remove();});
    var t = document.createElement('div');
    t.className = 'toast ' + (type || 'success');
    t.innerHTML = msg;
    document.body.appendChild(t);
    setTimeout(function() {
      t.style.opacity = '0';
      t.style.transition = 'opacity 0.3s';
      setTimeout(function(){t.remove();}, 300);
    }, 3200);
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
  
// ════════════════════════════════════════════════════
//  CSV EXPORT
// ════════════════════════════════════════════════════
async function exportContractsCSV() {
  try {
    const status = document.getElementById('filterStatus').value;
    const type   = document.getElementById('filterType').value;
    const dept   = document.getElementById('filterDept').value;
    const search = document.getElementById('searchInput').value;
    const params = new URLSearchParams({ action:'list', status, type, dept, search, page:1, pageSize:1000 });
    const res    = await fetch(API + '?' + params);
    const json   = await res.json();
    if (!json.success || !json.data.length) { showToast('⚠️ No contracts to export.', 'info'); return; }

    const rows = [['Employee','Employee Code','Department','Contract No.','Contract Type','Start Date','End Date','Status','Days Remaining']];
    json.data.forEach(function(c) {
      rows.push([
        (c.first_name||'') + ' ' + (c.last_name||''),
        c.employee_code||'',
        c.department_name||'',
        c.contract_no||'',
        c.contract_type||'',
        c.start_date||'',
        c.end_date||'',
        c.status||'',
        c.days_remaining != null ? c.days_remaining : 'N/A'
      ]);
    });

    const csv  = rows.map(function(row) {
      return row.map(function(v) {
        v = String(v == null ? '' : v).replace(/"/g, '""');
        return /[",

]/.test(v) ? '"' + v + '"' : v;
      }).join(',');
    }).join('
');

    const blob = new Blob(['﻿' + csv], { type:'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url; a.download = 'contracts_export.csv'; a.click();
    URL.revokeObjectURL(url);
    showToast('✅ Exported ' + json.data.length + ' contracts.', 'success');
  } catch(e) {
    showToast('❌ Export failed: ' + e.message, 'error');
  }
}
</script>
</body>
</html>