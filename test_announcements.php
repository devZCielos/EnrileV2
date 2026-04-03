<?php
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
//  ANNOUNCEMENTS FUNCTIONALITY TEST
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

require_once __DIR__ . '/config/db.php';

echo "═══════════════════════════════════════════════════════════════\n";
echo "  ANNOUNCEMENTS SYSTEM TEST\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// Test 1: Check if announcements table exists and has required columns
echo "✓ TEST 1: Check announcements table structure\n";
echo "  ───────────────────────────────────────────────────────────\n";

try {
    $tblCheck = $pdo->query("SHOW TABLES LIKE 'announcements'")->fetchColumn();
    if (!$tblCheck) {
        echo "  ✗ FAIL: Announcements table does not exist\n";
    } else {
        echo "  ✓ PASS: Announcements table exists\n";
        
        // Check columns
        $cols = $pdo->query("SHOW COLUMNS FROM announcements")->fetchAll(PDO::FETCH_COLUMN);
        echo "  Available columns: " . implode(', ', $cols) . "\n\n";
        
        // Check for required columns
        $required = ['announcement_id', 'title', 'body', 'status', 'created_at'];
        $missing = array_diff($required, $cols);
        if (!empty($missing)) {
            echo "  ✗ MISSING COLUMNS: " . implode(', ', $missing) . "\n";
        } else {
            echo "  ✓ All required columns present\n";
        }
    }
} catch (Exception $e) {
    echo "  ✗ ERROR: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 2: Check existing announcements
echo "✓ TEST 2: Check existing announcements\n";
echo "  ───────────────────────────────────────────────────────────\n";

try {
    $count = $pdo->query("SELECT COUNT(*) FROM announcements")->fetchColumn();
    echo "  Total announcements: $count\n";
    
    $published = $pdo->query("SELECT COUNT(*) FROM announcements WHERE status = 'Published'")->fetchColumn();
    echo "  Published announcements: $published\n";
    
    $drafts = $pdo->query("SELECT COUNT(*) FROM announcements WHERE status = 'Draft'")->fetchColumn();
    echo "  Draft announcements: $drafts\n";
    
    // Show recent announcements
    $recent = $pdo->query("
        SELECT announcement_id, title, status, publish_date, expiry_date, created_at
        FROM announcements
        ORDER BY created_at DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($recent)) {
        echo "\n  Recent announcements:\n";
        foreach ($recent as $ann) {
            $status = $ann['status'] ?? 'Unknown';
            $title = substr($ann['title'], 0, 50);
            $created = $ann['created_at'] ?? 'N/A';
            echo "    • ID #{$ann['announcement_id']}: [$status] $title... ($created)\n";
        }
    } else {
        echo "  No announcements found.\n";
    }
} catch (Exception $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 3: Test announcement visibility query (what employees see)
echo "✓ TEST 3: Test visibility query (Employee view)\n";
echo "  ───────────────────────────────────────────────────────────\n";

try {
    $visible = $pdo->query("
        SELECT announcement_id, title, category AS tag, status, created_at
        FROM announcements
        WHERE status = 'Published'
          AND (publish_date IS NULL OR publish_date <= CURDATE())
          AND (expiry_date IS NULL OR expiry_date >= CURDATE())
        ORDER BY pinned DESC, created_at DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $visibleCount = count($visible);
    echo "  Visible announcements for employees: $visibleCount\n";
    
    if ($visibleCount > 0) {
        echo "\n  Announcements visible to employees:\n";
        foreach ($visible as $ann) {
            $title = substr($ann['title'], 0, 50);
            $tag = $ann['tag'] ?? 'N/A';
            echo "    • [$tag] $title\n";
        }
    }
} catch (Exception $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 4: Test notification query (what employees see in notifications)
echo "✓ TEST 4: Test notification polling query\n";
echo "  ───────────────────────────────────────────────────────────\n";

try {
    $notifAnns = $pdo->query("
        SELECT title, created_at FROM announcements
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY created_at DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $notifCount = count($notifAnns);
    echo "  Announcements in 7-day window: $notifCount\n";
    
    if ($notifCount > 0) {
        echo "\n  Announcements that would appear in notifications:\n";
        foreach ($notifAnns as $ann) {
            $title = substr($ann['title'], 0, 50);
            $created = $ann['created_at'];
            $diff = time() - strtotime($created);
            $daysAgo = floor($diff / 86400);
            echo "    • $title ($daysAgo days ago)\n";
        }
    }
} catch (Exception $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 5: Create a test announcement
echo "✓ TEST 5: Test creating announcement\n";
echo "  ───────────────────────────────────────────────────────────\n";

try {
    $testTitle = "Test Announcement - " . date('Y-m-d H:i:s');
    $testBody = "This is a test announcement to verify system functionality.";
    
    $stmt = $pdo->prepare("
        INSERT INTO announcements (title, body, status, publish_date, category, priority, audience, created_at)
        VALUES (?, ?, 'Published', CURDATE(), 'General', 'Normal', 'All', NOW())
    ");
    
    $stmt->execute([$testTitle, $testBody]);
    $newId = $pdo->lastInsertId();
    
    echo "  ✓ Test announcement created successfully\n";
    echo "  ID: $newId\n";
    echo "  Title: $testTitle\n";
    echo "  Body: $testBody\n";
    
} catch (Exception $e) {
    echo "  ✗ ERROR: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 6: Verify notification system has leave_notifications table for additional notifications
echo "✓ TEST 6: Check leave notifications table\n";
echo "  ───────────────────────────────────────────────────────────\n";

try {
    $tblCheck = $pdo->query("SHOW TABLES LIKE 'leave_notifications'")->fetchColumn();
    if (!$tblCheck) {
        echo "  ℹ INFO: leave_notifications table does not exist\n";
    } else {
        echo "  ✓ leave_notifications table exists\n";
    }
} catch (Exception $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 7: Summary
echo "═══════════════════════════════════════════════════════════════\n";
echo "  SUMMARY\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

try {
    $totalAnn = $pdo->query("SELECT COUNT(*) FROM announcements")->fetchColumn();
    $pubAnn = $pdo->query("SELECT COUNT(*) FROM announcements WHERE status = 'Published'")->fetchColumn();
    $visibleAnn = $pdo->query("
        SELECT COUNT(*) FROM announcements
        WHERE status = 'Published'
          AND (publish_date IS NULL OR publish_date <= CURDATE())
          AND (expiry_date IS NULL OR expiry_date >= CURDATE())
    ")->fetchColumn();
    
    echo "  Total announcements in system: $totalAnn\n";
    echo "  Published announcements: $pubAnn\n";
    echo "  Currently visible to employees: $visibleAnn\n";
    echo "\n  ✓ Announcement system is operational\n";
    
} catch (Exception $e) {
    echo "  ✗ ERROR: " . $e->getMessage() . "\n";
}

echo "\n═══════════════════════════════════════════════════════════════\n\n";

?>
