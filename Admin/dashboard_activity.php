<?php
session_start();
header('Content-Type: application/json');
if (empty($_SESSION['user_email'])) { echo json_encode(['success'=>false]); exit(); }

$possibleDb = [__DIR__.'/../config/db.php', __DIR__.'/config/db.php'];
foreach ($possibleDb as $p) { if (file_exists($p)) { require_once $p; break; } }
if (!isset($pdo)) { echo json_encode(['success'=>false]); exit(); }

function dashIni($n){$w=array_filter(explode(' ',$n));return strtoupper(implode('',array_map(fn($x)=>$x[0]??'',array_slice($w,0,2))));}
$avColors=['#2563eb','#22c55e','#f59e0b','#ef4444','#a855f7','#06b6d4','#ec4899','#f97316'];
function dashColor($n,$c){$s=array_sum(array_map('ord',str_split($n?:'?')));return $c[$s%count($c)];}
function timeAgo($dt){
    if(!$dt)return '';
    $d=time()-strtotime($dt);
    if($d<60)    return 'Just now';
    if($d<3600)  return (int)($d/60).'m ago';
    if($d<86400) return (int)($d/3600).'h ago';
    if($d<172800)return 'Yesterday';
    return date('M j, Y',strtotime($dt));
}

try {
    $rows = $pdo->query("
        SELECT
            lr.request_id, lr.status, lr.date_from, lr.date_to,
            lr.days_requested, lr.reviewed_at,
            lt.name AS leave_type,
            CONCAT(emp.first_name,' ',emp.last_name) AS employee_name,
            COALESCE(d.name,'') AS department,
            COALESCE(CONCAT(adm.first_name,' ',adm.last_name), admUa.email, 'Admin') AS actor_name
        FROM leave_requests lr
        JOIN employees      emp   ON emp.employee_id   = lr.employee_id
        JOIN leave_types    lt    ON lt.leave_type_id  = lr.leave_type_id
        LEFT JOIN departments   d     ON d.department_id  = emp.department_id
        LEFT JOIN user_accounts admUa ON admUa.user_id    = lr.reviewed_by
        LEFT JOIN employees     adm   ON adm.user_id      = lr.reviewed_by
        WHERE lr.status IN ('Approved','Denied')
          AND lr.reviewed_at IS NOT NULL
        ORDER BY lr.reviewed_at DESC
        LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        $html = '<div class="lact-empty"><div class="lact-empty-ic">📋</div><p>No leave decisions yet.<br>Approved or denied requests will appear here.</p></div>';
        echo json_encode(['success'=>true,'html'=>$html]); exit();
    }

    $html = '';
    foreach ($rows as $ar) {
        $isApp   = $ar['status']==='Approved';
        $sCls    = $isApp ? 'approved' : 'denied';
        $sLbl    = $isApp ? '&#x2713; Approved' : '&#x2717; Denied';
        $empName = htmlspecialchars($ar['employee_name'] ?? 'Employee');
        $empIni  = dashIni($empName);
        $empCol  = dashColor($empName, $avColors);
        $dept    = htmlspecialchars($ar['department'] ?? '');
        $ltype   = htmlspecialchars($ar['leave_type'] ?? 'Leave');
        $days    = $ar['days_requested'] ? $ar['days_requested'].' '.((int)$ar['days_requested']===1?'day':'days') : '';
        $dfrom   = !empty($ar['date_from']) ? date('M j', strtotime($ar['date_from'])) : '';
        $dto     = !empty($ar['date_to'])   ? date('M j', strtotime($ar['date_to']))   : '';
        $range   = ($dfrom&&$dto&&$dfrom!==$dto) ? "$dfrom &ndash; $dto" : $dfrom;
        $actor   = htmlspecialchars($ar['actor_name'] ?? 'Admin');
        $atime   = timeAgo($ar['reviewed_at'] ?? '');

        $html .= '<div class="lact-card '.$sCls.'">'
               .'<div class="lact-top">'
               .'<div class="lact-emp-av" style="background:'.$empCol.';">'.$empIni.'</div>'
               .'<div class="lact-emp-info">'
               .'<div class="lact-emp-name">'.$empName.'</div>'
               .($dept?'<div class="lact-emp-dept">'.$dept.'</div>':'')
               .'</div>'
               .'<span class="lact-badge '.$sCls.'">'.$sLbl.'</span>'
               .'</div>'
               .'<div class="lact-divider"></div>'
               .'<div class="lact-details">'
               .($ltype?'<span class="lact-chip">&#x1F4C1; '.$ltype.'</span>':'')
               .($range?'<span class="lact-chip">&#x1F4C5; '.$range.'</span>':'')
               .($days ?'<span class="lact-chip">&#x23F1; '.$days.'</span>':'')
               .'</div>'
               .'<div class="lact-footer">'
               .'<span class="lact-admin">by '.$actor.'</span>'
               .'<span class="lact-time">'.$atime.'</span>'
               .'</div>'
               .'</div>';
    }
    echo json_encode(['success'=>true,'html'=>$html]);

} catch (Exception $e) {
    error_log('[dashboard_activity] '.$e->getMessage());
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
exit();