#!/usr/bin/env php
<?php
/**
 * Leave Notification System - Setup & Verification Script
 * 
 * Usage: php verify_leave_notifications.php [--create-test-data]
 * 
 * This script verifies the leave notification system is properly installed
 * and optionally creates test data for verification.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Colors for terminal output
$colors = [
    'red'     => "\033[91m",
    'green'   => "\033[92m",
    'yellow'  => "\033[93m",
    'blue'    => "\033[94m",
    'magenta' => "\033[95m",
    'cyan'    => "\033[96m",
    'white'   => "\033[97m",
    'reset'   => "\033[0m",
];

function clog($color, $text) {
    global $colors;
    echo $colors[$color] . $text . $colors['reset'] . "\n";
}

function log_ok($msg) { clog('green', "✓ " . $msg); }
function log_err($msg) { clog('red', "✗ " . $msg); }
function log_warn($msg) { clog('yellow', "⚠ " . $msg); }
function log_info($msg) { clog('cyan', "ℹ " . $msg); }

clog('magenta', "\n╔════════════════════════════════════════════════════════╗");
clog('magenta', "║ Leave Notification System - Setup & Verification      ║");
clog('magenta', "╚════════════════════════════════════════════════════════╝\n");

// Connect to database
try {
    require_once __DIR__ . '/config/db.php';
    log_ok("Database connection successful");
} catch (Exception $e) {
    log_err("Database connection failed: " . $e->getMessage());
    exit(1);
}

// ════════════════════════════════════════════════════════════════
//  1. CHECK TABLE STRUCTURE
// ════════════════════════════════════════════════════════════════
clog('cyan', "\n[1] Checking table structure...\n");

// Check leave_types table
$tableCheck = $pdo->query("DESC leave_types")->fetchAll(PDO::FETCH_ASSOC);
$cols = array_column($tableCheck, 'Field');

$requiredCols = ['applicable_gender', 'require_married', 'advance_notice_days'];
$missingCols = [];

foreach ($requiredCols as $col) {
    if (in_array($col, $cols)) {
        log_ok("leave_types.{$col}");
    } else {
        log_err("leave_types.{$col} - MISSING");
        $missingCols[] = $col;
    }
}

if (!empty($missingCols)) {
    log_warn("Missing columns detected. Run migration:");
    clog('white', "  mysql -u user -p'password' lgu_enrile_hrms < config/migration_leave_notifications.sql");
}

// Check leave_notifications table
try {
    $pdo->query("DESC leave_notifications");
    log_ok("leave_notifications table exists");
} catch (Exception $e) {
    log_err("leave_notifications table - MISSING");
    log_warn("Run migration to create table");
}

// ════════════════════════════════════════════════════════════════
//  2. CHECK LEAVE TYPE CONFIGURATIONS
// ════════════════════════════════════════════════════════════════
clog('cyan', "\n[2] Checking leave type configurations...\n");

$leafTypes = $pdo->query("
    SELECT code, name, advance_notice_days, applicable_gender
    FROM leave_types
    WHERE is_active = 1
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($leafTypes)) {
    log_warn("No active leave types found");
} else {
    $vcCount = 0;
    foreach ($leafTypes as $lt) {
        $adv = $lt['advance_notice_days'] ?? 'NULL';
        $gender = $lt['applicable_gender'] ?? 'All';
        $status = ($adv !== 'NULL' && $adv != '') ? "✓" : "?";
        echo "  {$status} " . str_pad($lt['code'], 6) . " | " . str_pad($lt['name'], 30) 
             . " | Advance: " . str_pad($adv . " days", 8) . " | Gender: {$gender}\n";
        if ($adv !== 'NULL' && $adv != '') $vcCount++;
    }
    log_info(($vcCount) . " of " . count($leafTypes) . " leave types configured");
}

// ════════════════════════════════════════════════════════════════
//  3. CHECK EXISTING NOTIFICATIONS
// ════════════════════════════════════════════════════════════════
clog('cyan', "\n[3] Checking existing notifications...\n");

try {
    $notifCounts = $pdo->query("
        SELECT notification_type, COUNT(*) as cnt, SUM(is_sent) as sent
        FROM leave_notifications
        GROUP BY notification_type
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($notifCounts)) {
        log_info("No notifications exist yet");
    } else {
        foreach ($notifCounts as $nc) {
            $sent = $nc['sent'] ?? 0;
            $unsent = $nc['cnt'] - $sent;
            echo "  • " . str_pad($nc['notification_type'], 20) . ": " . $nc['cnt'] . " total (" . $sent . " sent, " . $unsent . " pending)\n";
        }
    }
} catch (Exception $e) {
    log_warn("Could not fetch notification stats: " . $e->getMessage());
}

// ════════════════════════════════════════════════════════════════
//  4. TEST CRON JOB
// ════════════════════════════════════════════════════════════════
clog('cyan', "\n[4] Testing cron job...\n");

$cronFile = __DIR__ . '/config/cron_leave_notifications.php';
if (file_exists($cronFile)) {
    log_ok("Cron file exists: config/cron_leave_notifications.php");
    
    // Try to run it
    ob_start();
    try {
        include $cronFile;
        $output = ob_get_clean();
        log_ok("Cron job executed successfully");
        if (!empty($output)) {
            echo "  " . str_replace("\n", "\n  ", trim($output)) . "\n";
        }
    } catch (Exception $e) {
        ob_end_clean();
        log_err("Cron job failed: " . $e->getMessage());
    }
} else {
    log_err("Cron file missing: config/cron_leave_notifications.php");
}

// ════════════════════════════════════════════════════════════════
//  5. CREATE TEST DATA (if requested)
// ════════════════════════════════════════════════════════════════

if (in_array('--create-test-data', $argv)) {
    clog('cyan', "\n[5] Creating test data...\n");
    
    try {
        // Get first active employee
        $empStmt = $pdo->query("SELECT employee_id FROM employees WHERE employment_status = 'Active' LIMIT 1");
        $emp = $empStmt->fetch();
        
        if (!$emp) {
            log_warn("No active employees found for test data");
        } else {
            $empId = $emp['employee_id'];
            log_ok("Using employee ID: {$empId}");
            
            // Get Vacation Leave type
            $vlStmt = $pdo->query("SELECT leave_type_id FROM leave_types WHERE code = 'VL'");
            $vl = $vlStmt->fetch();
            
            if (!$vl) {
                log_err("Vacation Leave type not found");
            } else {
                $vlId = $vl['leave_type_id'];
                $dateFrom = date('Y-m-d', strtotime('+15 days'));
                $dateTo = date('Y-m-d', strtotime('+20 days'));
                
                // Create test leave request
                $insertStmt = $pdo->prepare("
                    INSERT INTO leave_requests 
                    (employee_id, leave_type_id, date_from, date_to, days_requested, reason, status, filed_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'Approved', NOW())
                ");
                $insertStmt->execute([
                    $empId,
                    $vlId,
                    $dateFrom,
                    $dateTo,
                    5,
                    'Test leave request for notification verification'
                ]);
                
                $requestId = $pdo->lastInsertId();
                log_ok("Created test leave request ID: {$requestId}");
                log_info("  From: {$dateFrom}, To: {$dateTo}");
                
                // Run cron to generate notifications
                ob_start();
                include __DIR__ . '/config/cron_leave_notifications.php';
                ob_end_clean();
                
                // Check if notifications were created
                $notifStmt = $pdo->prepare("
                    SELECT COUNT(*) as cnt FROM leave_notifications
                    WHERE request_id = ?
                ");
                $notifStmt->execute([$requestId]);
                $notifCount = $notifStmt->fetchColumn();
                
                if ($notifCount > 0) {
                    log_ok("Created {$notifCount} notifications for test leave");
                } else {
                    log_warn("No notifications created (may be outside target dates)");
                }
            }
        }
    } catch (Exception $e) {
        log_err("Failed to create test data: " . $e->getMessage());
    }
}

// ════════════════════════════════════════════════════════════════
//  SUMMARY
// ════════════════════════════════════════════════════════════════
clog('magenta', "\n╔════════════════════════════════════════════════════════╗");
clog('magenta', "║ Setup & Verification Complete                         ║");
clog('magenta', "╚════════════════════════════════════════════════════════╝");

clog('cyan', "\nNext Steps:");
echo "  1. Review LEAVE_NOTIFICATIONS_README.md for complete documentation\n";
echo "  2. Configure cron job (see README for setup instructions)\n";
echo "  3. Test in employee portal: Submit a leave request\n";
echo "  4. Run: php verify_leave_notifications.php --create-test-data\n";
echo "  5. Check employee portal for notifications\n\n";

?>
