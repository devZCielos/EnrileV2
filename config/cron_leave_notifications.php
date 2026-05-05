<?php
/**
 * Leave Notification Cron Job
 * 
 * This script should be run daily (preferably early morning) to:
 * 1. Mark notifications as sent if their target_date <= TODAY
 * 2. Generate any new notifications for upcoming leaves
 * 
 * Add to crontab:
 * 0 6 * * * php /path/to/enrile/config/cron_leave_notifications.php
 */

// Prevent direct web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

// Include database configuration
require_once __DIR__ . '/db.php';

try {
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 days'));
    
    // ──────────────────────────────────────────────────────────────
    // STEP 1: Mark notifications as sent if target_date <= today
    // ──────────────────────────────────────────────────────────────
    $updateStmt = $pdo->prepare("
        UPDATE leave_notifications
        SET is_sent = 1, updated_at = NOW()
        WHERE is_sent = 0 AND target_date <= ? AND target_date != DATE '0000-00-00'
    ");
    $updateStmt->execute([$today]);
    $updatedCount = $updateStmt->rowCount();
    
    // ──────────────────────────────────────────────────────────────
    // STEP 2: Generate filing deadline notifications for new approvals
    // ──────────────────────────────────────────────────────────────
    // When a leave is APPROVED, create a filing deadline notification
    // if one doesn't already exist and advance_notice_days > 0
    $approvedStmt = $pdo->prepare("
        SELECT lr.request_id, lr.employee_id, lr.leave_type_id, lr.date_from, lr.status,
               lt.advance_notice_days, lt.name AS type_name
        FROM leave_requests lr
        JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id
        WHERE lr.status = 'Approved'
          AND lr.date_from >= ?
          AND lt.advance_notice_days > 0
          AND NOT EXISTS (
              SELECT 1 FROM leave_notifications ln
              WHERE ln.request_id = lr.request_id
                AND ln.notification_type = 'FILING_DEADLINE'
          )
    ");
    $approvedStmt->execute([$tomorrow]);
    $approvedLeaves = $approvedStmt->fetchAll();
    
    foreach ($approvedLeaves as $leave) {
        $advanceDays = (int)$leave['advance_notice_days'];
        $dateFrom = $leave['date_from'];
        $filingDeadline = date('Y-m-d', strtotime("-{$advanceDays} days", strtotime($dateFrom)));
        
        // Only create if deadline hasn't passed
        if ($filingDeadline >= $today) {
            $notifStmt = $pdo->prepare("
                INSERT INTO leave_notifications 
                    (employee_id, request_id, leave_type_id, notification_type, title, message, target_date, is_sent)
                VALUES (?, ?, ?, 'FILING_DEADLINE', ?, ?, ?, 0)
                ON DUPLICATE KEY UPDATE updated_at = NOW()
            ");
            $notifMsg = "You have an approved {$leave['type_name']} scheduled for {$dateFrom}. File your leave by {$filingDeadline}.";
            $notifStmt->execute([
                $leave['employee_id'],
                $leave['request_id'],
                $leave['leave_type_id'],
                "File by: {$leave['type_name']}",
                $notifMsg,
                $filingDeadline
            ]);
        }
    }
    
    // ──────────────────────────────────────────────────────────────
    // STEP 3: Generate leave ending reminders for active leaves
    // ──────────────────────────────────────────────────────────────
    // For approved leaves in progress, create a reminder 1 day before they end
    $activeStmt = $pdo->prepare("
        SELECT lr.request_id, lr.employee_id, lr.leave_type_id, lr.date_from, lr.date_to, 
               lt.name AS type_name
        FROM leave_requests lr
        JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id
        WHERE lr.status = 'Approved'
          AND lr.date_from <= ?
          AND lr.date_to >= ?
          AND NOT EXISTS (
              SELECT 1 FROM leave_notifications ln
              WHERE ln.request_id = lr.request_id
                AND ln.notification_type = 'LEAVE_ENDING'
          )
    ");
    $activeStmt->execute([$today, $today]);
    $activeLeaves = $activeStmt->fetchAll();
    
    foreach ($activeLeaves as $leave) {
        $endReminder = date('Y-m-d', strtotime('-1 days', strtotime($leave['date_to'])));
        
        // Only create if reminder date hasn't passed
        if ($endReminder >= $today) {
            $notifStmt = $pdo->prepare("
                INSERT INTO leave_notifications 
                    (employee_id, request_id, leave_type_id, notification_type, title, message, target_date, is_sent)
                VALUES (?, ?, ?, 'LEAVE_ENDING', ?, ?, ?, 0)
                ON DUPLICATE KEY UPDATE updated_at = NOW()
            ");
            $notifMsg = "Your {$leave['type_name']} ends on {$leave['date_to']}. Please prepare to return to work.";
            $notifStmt->execute([
                $leave['employee_id'],
                $leave['request_id'],
                $leave['leave_type_id'],
                "Returning Soon: {$leave['type_name']}",
                $notifMsg,
                $endReminder
            ]);
        }
    }
    
    // ──────────────────────────────────────────────────────────────
    // STEP 4 (H-5): Auto-expire contracts whose end_date has passed
    // ──────────────────────────────────────────────────────────────
    $expiredContracts = $pdo->exec(
        "UPDATE contracts SET status='Expired' WHERE end_date IS NOT NULL AND end_date < CURDATE() AND status='Active'"
    );

    // Log successful execution
    error_log("[" . date('Y-m-d H:i:s') . "] Leave notification cron completed. Marked {$updatedCount} notifications as sent.");
    echo "✓ Leave notification cron completed successfully.\n";
    echo "  - Marked {$updatedCount} notifications as sent.\n";
    echo "  - Processed approved leaves for filing deadline notifications.\n";
    echo "  - Processed active leaves for ending reminders.\n";
    
} catch (Exception $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] Leave notification cron ERROR: " . $e->getMessage());
    echo "✗ Error running leave notification cron: " . $e->getMessage() . "\n";
    exit(1);
}
?>
