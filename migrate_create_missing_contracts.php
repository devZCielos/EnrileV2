<?php
// ============================================================
//  MIGRATION SCRIPT — Auto-Create Missing Contracts
//  File: migrate_create_missing_contracts.php
//  
//  Purpose: Creates contract records for existing Contractual,
//           Job Order, and Casual employees who don't have one yet.
//  
//  Usage: Run once from browser: http://localhost/enrile/migrate_create_missing_contracts.php
// ============================================================

require_once __DIR__ . '/config/db.php';

// Verify PDO connection
if (!isset($pdo)) {
    die('❌ Database connection failed.');
}

try {
    // Find all active Contractual, JO, and Casual employees WITHOUT a contract
    $stmt = $pdo->prepare("
        SELECT e.employee_id, e.employment_type, e.position_title, e.department_id, e.date_hired
        FROM employees e
        WHERE e.employment_type IN ('Contractual', 'Job Order', 'Casual')
        AND e.employment_status = 'Active'
        AND e.employee_id NOT IN (SELECT DISTINCT employee_id FROM contracts)
        ORDER BY e.employee_id
    ");
    $stmt->execute();
    $missingEmployees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($missingEmployees)) {
        echo '<h2>✅ All employees already have contracts!</h2>';
        echo '<p>No missing contracts found.</p>';
        exit;
    }

    // Create contracts for these employees
    $insStmt = $pdo->prepare("
        INSERT INTO contracts (employee_id, contract_type, position_title, department_id, start_date, status, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, 'Active', 1, NOW())
    ");

    $created = 0;
    foreach ($missingEmployees as $emp) {
        try {
            $insStmt->execute([
                $emp['employee_id'],
                $emp['employment_type'],
                $emp['position_title'],
                $emp['department_id'],
                $emp['date_hired']
            ]);
            $created++;
            echo "✅ Created contract for Employee ID {$emp['employee_id']} ({$emp['employment_type']})<br>";
        } catch (Exception $e) {
            echo "❌ Failed for Employee ID {$emp['employee_id']}: " . $e->getMessage() . "<br>";
        }
    }

    echo "<hr>";
    echo "<h2>Migration Complete!</h2>";
    echo "<p><strong>Total contracts created: $created</strong></p>";
    echo "<p><a href='Admin/contracts.php'>View Contracts Page →</a></p>";

} catch (PDOException $e) {
    echo '❌ Database error: ' . $e->getMessage();
}
?>
