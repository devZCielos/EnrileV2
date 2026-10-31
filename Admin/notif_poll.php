<?php
// ── Admin Notification Poll ───────────────────────────────────────────────
session_start();
if (empty($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['admin', 'hr_staff', 'dept_head'], true)) {
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
    // Pending Certificate of Employment requests
    try {
        $coe = $pdo->query("SELECT CONCAT(e.first_name,' ',e.last_name) AS emp_name, c.purpose, c.filed_at
            FROM coe_requests c JOIN employees e ON e.employee_id=c.employee_id
            WHERE c.status='Pending' AND c.is_deleted=0 ORDER BY c.filed_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($coe as $request) {
            $npNotifs[] = [
                'icon'=>'📄','color'=>'#2563eb','title'=>'COE Request',
                'body'=>htmlspecialchars($request['emp_name']).' requested a Certificate of Employment'.(!empty($request['purpose']) ? ': '.htmlspecialchars($request['purpose']) : ''),
                'time'=>$request['filed_at'],'link'=>'coe_requests.php',
                'nid'=>md5('coe'.$request['emp_name'].($request['filed_at'] ?? '')),
            ];
        }
    } catch (Exception $ex) {}
    // Pending service record requests
    try {
        $serviceRequests = $pdo->query("SELECT CONCAT(e.first_name,' ',e.last_name) AS emp_name, r.purpose, r.requested_at
            FROM sr_requests r JOIN employees e ON e.employee_id=r.employee_id
            WHERE r.status='Pending' ORDER BY r.requested_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($serviceRequests as $request) {
            $npNotifs[] = [
                'icon'=>'📜','color'=>'#7c3aed','title'=>'Service Record Request',
                'body'=>htmlspecialchars($request['emp_name']).' requested a service record'.(!empty($request['purpose']) ? ': '.htmlspecialchars($request['purpose']) : ''),
                'time'=>$request['requested_at'],'link'=>'service_records.php',
                'nid'=>md5('sr'.$request['emp_name'].($request['requested_at'] ?? '')),
            ];
        }
    } catch (Exception $ex) {}
    // Employee-submitted documents and updated PDS/SALN records
    try {
        $documents = $pdo->query("SELECT CONCAT(e.first_name,' ',e.last_name) AS emp_name, d.document_type, d.file_name, d.uploaded_at
            FROM documents d JOIN employees e ON e.employee_id=d.employee_id
            ORDER BY d.uploaded_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($documents as $document) {
            $npNotifs[] = [
                'icon'=>'📁','color'=>'#0891b2','title'=>'Document Uploaded',
                'body'=>htmlspecialchars($document['emp_name']).' uploaded '.htmlspecialchars($document['document_type']).': '.htmlspecialchars($document['file_name']),
                'time'=>$document['uploaded_at'],'link'=>'documents.php',
                'nid'=>md5('doc'.$document['emp_name'].$document['file_name'].($document['uploaded_at'] ?? '')),
            ];
        }
    } catch (Exception $ex) {}
    try {
        $pdsRecords = $pdo->query("SELECT CONCAT(e.first_name,' ',e.last_name) AS emp_name, p.updated_at
            FROM pds_records p JOIN employees e ON e.employee_id=p.employee_id
            ORDER BY p.updated_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($pdsRecords as $record) {
            $npNotifs[] = [
                'icon'=>'📝','color'=>'#0f766e','title'=>'PDS/SALN Updated',
                'body'=>htmlspecialchars($record['emp_name']).' updated their PDS or SALN record',
                'time'=>$record['updated_at'],'link'=>'pds_saln.php',
                'nid'=>md5('pds'.$record['emp_name'].($record['updated_at'] ?? '')),
            ];
        }
    } catch (Exception $ex) {}
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
