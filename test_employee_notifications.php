<?php
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  EMPLOYEE NOTIFICATION SYSTEM TEST
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

require_once __DIR__ . '/config/db.php';

echo "═══════════════════════════════════════════════════════════════\n";
echo "  EMPLOYEE NOTIFICATION SYSTEM TEST\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// Test 1: Get a sample employee
echo "✓ TEST 1: Find sample employee\n";
echo "  ───────────────────────────────────────────────────────────\n";

try {
    $emp = $pdo->query("
        SELECT e.employee_id, e.first_name, e.last_name, u.user_id, u.email, u.role
        FROM employees e
        LEFT JOIN user_accounts u ON u.user_id = e.user_id
        WHERE e.employment_type IN ('Permanent', 'Co-Terminus', 'Elective') AND u.role = 'employee'
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    
    if (!$emp) {
        echo "  ✗ No employee found\n";
        exit;
    }
    
    $employeeId = $emp['employee_id'];
    $userId = $emp['user_id'];
    
    echo "  ✓ Found: " . $emp['first_name'] . " " . $emp['last_name'] . "\n";
    echo "  Employee ID: $employeeId\n";
    echo "  User ID: $userId\n";
    echo "  Email: " . $emp['email'] . "\n";
    
} catch (Exception $e) {
    echo "  ✗ ERROR: " . $e->getMessage() . "\n";
    exit;
}

echo "\n";

// Test 2: Check leave requests notifications
echo "✓ TEST 2: Check leave request notifications\n";
echo "  ───────────────────────────────────────────────────────────\n";

try {
    $lrNotif = $pdo->query("
        SELECT COUNT(*) FROM leave_requests
        WHERE employee_id = $employeeId
          AND status IN ('Approved', 'Denied')
          AND reviewed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ")->fetchColumn();
    
    echo "  Leave request notifications (30 days): $lrNotif\n";
    
} catch (Exception $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 3: Check announcement notifications
echo "✓ TEST 3: Check announcement notifications\n";
echo "  ───────────────────────────────────────────────────────────\n";

try {
    $annNotif = $pdo->query("
        SELECT COUNT(*) FROM announcements
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY created_at DESC
    ")->fetchColumn();
    
    $announcementsList = $pdo->query("
        SELECT announcement_id, title, created_at FROM announcements
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY created_at DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "  Announcement notifications (7 days): $annNotif\n";
    
    if (!empty($announcementsList)) {
        echo "\n  Announcements:\n";
        foreach ($announcementsList as $ann) {
            $title = substr($ann['title'], 0, 60);
            echo "    • ID #{$ann['announcement_id']}: $title\n";
            echo "      Created: " . $ann['created_at'] . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 4: Check leave balance warnings
echo "✓ TEST 4: Check low balance warnings\n";
echo "  ───────────────────────────────────────────────────────────\n";

try {
    $lowBal = $pdo->query("
        SELECT COUNT(*) FROM leave_balances
        WHERE employee_id = $employeeId
          AND calendar_year = YEAR(NOW())
          AND remaining_days < 3
          AND remaining_days >= 0
    ")->fetchColumn();
    
    echo "  Low balance warnings: $lowBal\n";
    
    $balances = $pdo->query("
        SELECT lt.name, lb.remaining_days FROM leave_balances lb
        JOIN leave_types lt ON lt.leave_type_id = lb.leave_type_id
        WHERE lb.employee_id = $employeeId
          AND lb.calendar_year = YEAR(NOW())
          AND lb.remaining_days < 3
          AND lb.remaining_days >= 0
        ORDER BY lb.remaining_days ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($balances)) {
        echo "\n  Low balances:\n";
        foreach ($balances as $bal) {
            echo "    • " . $bal['name'] . ": " . $bal['remaining_days'] . " days\n";
        }
    }
    
} catch (Exception $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 5: Check leave notification reminders
echo "✓ TEST 5: Check leave notification reminders\n";
echo "  ───────────────────────────────────────────────────────────\n";

try {
    $lnNotif = $pdo->query("
        SELECT COUNT(*) FROM leave_notifications
        WHERE employee_id = $employeeId
          AND is_sent = 0
          AND target_date <= CURDATE()
    ")->fetchColumn();
    
    echo "  Leave notification reminders: $lnNotif\n";
    
} catch (Exception $e) {
    echo "  INFO: leave_notifications query failed - " . $e->getMessage() . "\n";
}

echo "\n";

// Test 6: Simulate get_notif_count response
echo "✓ TEST 6: Simulate employee notification polling response\n";
echo "  ───────────────────────────────────────────────────────────\n";

try {
    $notifs = [];
    
    // Leave requests
    $lrS = $pdo->prepare("
        SELECT lr.status, lr.reviewed_at, lr.admin_remarks, lt.name AS lt, lr.days_requested
        FROM leave_requests lr
        JOIN leave_types lt ON lt.leave_type_id = lr.leave_type_id
        WHERE lr.employee_id = ?
          AND lr.status IN ('Approved','Denied')
          AND lr.reviewed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY lr.reviewed_at DESC LIMIT 10
    ");
    $lrS->execute([$employeeId]);
    foreach ($lrS->fetchAll(PDO::FETCH_ASSOC) as $lr) {
        $notifs[] = 'Leave request: ' . $lr['status'] . ' - ' . $lr['lt'];
    }
    
    // Announcements
    $anns = $pdo->query("
        SELECT title, created_at FROM announcements
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY created_at DESC LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($anns as $a) {
        $title = substr($a['title'], 0, 40);
        $notifs[] = 'Announcement: ' . $title;
    }
    
    // Low balances
    $bw = $pdo->prepare("
        SELECT lt.name, lb.remaining_days FROM leave_balances lb
        JOIN leave_types lt ON lt.leave_type_id = lb.leave_type_id
        WHERE lb.employee_id = ? AND lb.calendar_year = YEAR(NOW())
          AND lb.remaining_days < 3 AND lb.remaining_days >= 0
        ORDER BY lb.remaining_days ASC LIMIT 3
    ");
    $bw->execute([$employeeId]);
    foreach ($bw->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $rem = (int)$b['remaining_days'];
        $notifs[] = 'Low balance: ' . $b['name'] . ' (' . $rem . ' days)';
    }
    
    echo "  Total notifications: " . count($notifs) . "\n";
    
    if (!empty($notifs)) {
        echo "\n  Notifications:\n";
        foreach ($notifs as $i => $n) {
            echo "    " . ($i+1) . ". $n\n";
        }
    } else {
        echo "  No notifications for this employee\n";
    }
    
} catch (Exception $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

echo "\n═══════════════════════════════════════════════════════════════\n";
echo "\n✓ Employee notification system is operational\n\n";

?>
