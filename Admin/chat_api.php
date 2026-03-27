<?php
// ============================================================
//  LGU ENRILE HRMS — Chat API
//  File: Admin/chat_api.php
// ============================================================
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success'=>false,'message'=>'Not logged in. Please refresh the page.']);
    exit();
}

$possible = [
    __DIR__ . '/../config/db.php',
    __DIR__ . '/config/db.php',
    __DIR__ . '/../../config/db.php',
];
$dbPath = null;
foreach ($possible as $p) {
    if (file_exists($p)) { $dbPath = $p; break; }
}
if (!$dbPath) {
    echo json_encode(['success'=>false,'message'=>'DB config not found. Path: '.__DIR__]);
    exit();
}
require_once $dbPath;

// Tables `chat_messages` is defined in lgu_enrile_hrms.sql — no runtime CREATE needed.
// If upgrading an existing DB that pre-dates the consolidated schema, run:
//   ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS is_unsent TINYINT(1) NOT NULL DEFAULT 0;
//   ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS is_edited  TINYINT(1) NOT NULL DEFAULT 0;
if (false) { // placeholder so the closing catch block below still has its try partner
}

$me     = (int)$_SESSION['user_id'];
$role   = !empty($_SESSION['switched_from_admin'])
            ? ($_SESSION['original_role'] ?? 'admin')
            : ($_SESSION['user_role']     ?? 'employee');
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if (!$action) { echo json_encode(['success'=>false,'message'=>'No action.']); exit(); }

try {
    switch ($action) {

        case 'contacts':
            $isAdmin = in_array($role, ['admin','hr_staff','dept_head']);
            if ($isAdmin) {
                $stmt = $pdo->prepare("
                    SELECT u.user_id, u.email, u.role,
                           COALESCE(CONCAT(e.first_name,' ',e.last_name), u.email) AS full_name,
                           COALESCE(e.position_title,'') AS position_title,
                           COALESCE(d.name,'') AS department,
                           COALESCE(e.profile_photo,'') AS profile_photo
                    FROM user_accounts u
                    LEFT JOIN employees   e ON e.user_id=u.user_id
                    LEFT JOIN departments d ON d.department_id=e.department_id
                    WHERE u.role='employee' AND u.user_id!=?
                    ORDER BY e.last_name, e.first_name");
                $stmt->execute([$me]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT u.user_id, u.email, u.role,
                           COALESCE(CONCAT(e.first_name,' ',e.last_name), u.email) AS full_name,
                           COALESCE(e.position_title,'') AS position_title,
                           COALESCE(d.name,'') AS department,
                           COALESCE(e.profile_photo,'') AS profile_photo
                    FROM user_accounts u
                    LEFT JOIN employees   e ON e.user_id=u.user_id
                    LEFT JOIN departments d ON d.department_id=e.department_id
                    WHERE u.role IN ('admin','hr_staff','dept_head') AND u.user_id!=?
                    ORDER BY u.role, e.last_name");
                $stmt->execute([$me]);
            }
            $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $ids = array_column($contacts,'user_id');
            $unreadMap = $lastMap = [];
            if (!empty($ids)) {
                $in = implode(',', array_map('intval',$ids));
                $rows = $pdo->query("SELECT sender_id,COUNT(*) AS cnt FROM chat_messages WHERE sender_id IN($in) AND receiver_id=$me AND is_read=0 GROUP BY sender_id")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) $unreadMap[(int)$r['sender_id']]=(int)$r['cnt'];
                $rows = $pdo->query("SELECT m.* FROM chat_messages m INNER JOIN (SELECT MAX(message_id) AS mid FROM chat_messages WHERE (sender_id=$me AND receiver_id IN($in)) OR (sender_id IN($in) AND receiver_id=$me) GROUP BY LEAST(sender_id,receiver_id),GREATEST(sender_id,receiver_id)) t ON m.message_id=t.mid")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) { $other=((int)$r['sender_id']===$me)?(int)$r['receiver_id']:(int)$r['sender_id']; $lastMap[$other]=$r; }
            }
            foreach ($contacts as &$c) {
                $uid=(int)$c['user_id']; $lm=$lastMap[$uid]??null;
                $c['unread']=(int)($unreadMap[$uid]??0);
                $c['last_message']=$lm?mb_strimwidth($lm['message'],0,40,'…'):null;
                $c['last_at']=$lm?$lm['sent_at']:null;
                $c['last_mine']=$lm?((int)$lm['sender_id']===$me):false;
            } unset($c);
            usort($contacts,function($a,$b){ if($b['unread']!==$a['unread'])return $b['unread']-$a['unread']; return strcmp($b['last_at']??'',$a['last_at']??''); });
            echo json_encode(['success'=>true,'data'=>$contacts]);
            break;

        case 'messages':
            $with=(int)($_GET['with']??0); $since=(int)($_GET['since_id']??0);
            if(!$with){echo json_encode(['success'=>false,'message'=>'Missing contact.']);break;}
            $params=[$me,$with,$with,$me]; $sinceSQL='';
            if($since){$sinceSQL='AND message_id > ?';$params[]=$since;}
            $rows=$pdo->prepare("SELECT message_id,sender_id,receiver_id,message,is_read,sent_at FROM chat_messages WHERE((sender_id=? AND receiver_id=?)OR(sender_id=? AND receiver_id=?)) $sinceSQL ORDER BY sent_at ASC LIMIT 300");
            $rows->execute($params);
            $pdo->prepare("UPDATE chat_messages SET is_read=1 WHERE sender_id=? AND receiver_id=? AND is_read=0")->execute([$with,$me]);
            echo json_encode(['success'=>true,'data'=>$rows->fetchAll(PDO::FETCH_ASSOC),'server_time'=>date('Y-m-d H:i:s')]);
            break;

        case 'send':
            $to=(int)($_POST['to']??0); $msg=trim($_POST['message']??'');
            if(!$to||$msg===''){echo json_encode(['success'=>false,'message'=>'Empty message.']);break;}
            $chk=$pdo->prepare("SELECT user_id FROM user_accounts WHERE user_id=?"); $chk->execute([$to]);
            if(!$chk->fetch()){echo json_encode(['success'=>false,'message'=>'Recipient not found.']);break;}
            $pdo->prepare("INSERT INTO chat_messages(sender_id,receiver_id,message,sent_at)VALUES(?,?,?,NOW())")->execute([$me,$to,$msg]);
            $id=$pdo->lastInsertId(); $row=$pdo->prepare("SELECT * FROM chat_messages WHERE message_id=?"); $row->execute([$id]);
            echo json_encode(['success'=>true,'data'=>$row->fetch(PDO::FETCH_ASSOC)]);
            break;

        case 'unread_count':
            $cnt=$pdo->prepare("SELECT COUNT(*) FROM chat_messages WHERE receiver_id=? AND is_read=0"); $cnt->execute([$me]);
            echo json_encode(['success'=>true,'count'=>(int)$cnt->fetchColumn()]);
            break;

        case 'edit':
            $msgId  = (int)($_POST['message_id'] ?? 0);
            $newMsg = trim($_POST['message'] ?? '');
            if(!$msgId || $newMsg===''){echo json_encode(['success'=>false,'message'=>'Invalid.']);break;}
            // Only sender can edit, only non-unsent
            $chk=$pdo->prepare("SELECT sender_id,is_unsent FROM chat_messages WHERE message_id=?");
            $chk->execute([$msgId]);$row=$chk->fetch(PDO::FETCH_ASSOC);
            if(!$row||$row['is_unsent']){echo json_encode(['success'=>false,'message'=>'Cannot edit.']);break;}
            if((int)$row['sender_id']!==$me){echo json_encode(['success'=>false,'message'=>'Not your message.']);break;}
            $pdo->prepare("UPDATE chat_messages SET message=?,is_edited=1 WHERE message_id=?")->execute([$newMsg,$msgId]);
            echo json_encode(['success'=>true]);
            break;

        case 'unsend':
            $msgId=(int)($_POST['message_id']??0);
            if(!$msgId){echo json_encode(['success'=>false,'message'=>'Invalid.']);break;}
            $chk=$pdo->prepare("SELECT sender_id FROM chat_messages WHERE message_id=?");
            $chk->execute([$msgId]);$row=$chk->fetch(PDO::FETCH_ASSOC);
            if(!$row||(int)$row['sender_id']!==$me){echo json_encode(['success'=>false,'message'=>'Not your message.']);break;}
            $pdo->prepare("UPDATE chat_messages SET is_unsent=1,message='[unsent]' WHERE message_id=?")->execute([$msgId]);
            echo json_encode(['success'=>true]);
            break;

        default:
            echo json_encode(['success'=>false,'message'=>"Unknown action: $action"]);
    }
} catch (PDOException $e) {
    error_log('[HRMS Chat] '.$e->getMessage());
    echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]);
}
exit();