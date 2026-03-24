<?php
// ── Admin Notification Poll ───────────────────────────────────────────────
session_start();
if (empty($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success'=>false,'notifications'=>[],'count'=>0]);
    exit();
}
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

$npNotifs = [];
try {
    // Pending leave requests
    $pr = $pdo->query("SELECT CONCAT(e.first_name,' ',e.last_name) AS emp_name,
        lt.name AS leave_type, lr.days_requested, lr.filed_at
        FROM leave_requests lr JOIN employees e ON e.employee_id=lr.employee_id
        JOIN leave_types lt ON lt.leave_type_id=lr.leave_type_id
        WHERE lr.status='Pending' ORDER BY lr.filed_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pr as $r) {
        $npNotifs[] = [
            'icon'=>'📋','color'=>'#f59e0b','title'=>'Leave Request',
            'body'=>htmlspecialchars($r['emp_name']).' filed '.(int)$r['days_requested'].' day(s) of '.htmlspecialchars($r['leave_type']),
            'time'=>$r['filed_at'],'link'=>'leave_requests.php',
            'nid'=>md5('lr'.$r['emp_name'].$r['leave_type'].(string)$r['days_requested'].($r['filed_at']??'')),
        ];
    }
    // Expiring contracts
    try {
        $ec = $pdo->query("SELECT CONCAT(e.first_name,' ',e.last_name) AS emp_name,
            DATEDIFF(c.end_date,CURDATE()) AS dl FROM contracts c
            JOIN employees e ON e.employee_id=c.employee_id
            WHERE c.status='Active' AND c.end_date IS NOT NULL
            AND DATEDIFF(c.end_date,CURDATE()) BETWEEN 0 AND 30
            ORDER BY c.end_date ASC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($ec as $cx) {
            $dl = (int)$cx['dl'];
            $npNotifs[] = [
                'icon'=>'📝','color'=>'#ef4444','title'=>'Contract Expiring',
                'body'=>htmlspecialchars($cx['emp_name'])."'s contract expires in $dl day".($dl===1?'':'s'),
                'time'=>null,'link'=>'contracts.php',
                'nid'=>md5('ct'.$cx['emp_name'].$dl),
            ];
        }
    } catch(Exception $ex) {}
} catch(Exception $ex) {}

echo json_encode(['success'=>true,'count'=>count($npNotifs),'notifications'=>$npNotifs]);
