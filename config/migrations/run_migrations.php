<?php
// ============================================================
//  LGU ENRILE HRMS — Database Migration Runner
//  File: config/migrations/run_migrations.php
//
//  Run this script ONCE after a fresh installation or schema update.
//  It executes all *.sql files in this directory in filename order.
//
//  Usage (CLI):  php config/migrations/run_migrations.php
//  Usage (Web):  Only accessible from 127.0.0.1 for safety.
// ============================================================

// ── Web safety guard ──────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
        http_response_code(403);
        exit("Access denied. Run via CLI or localhost only.\n");
    }
}

require_once __DIR__ . '/../db.php'; // provides $pdo

$migrationDir = __DIR__;
$files = glob($migrationDir . '/*.sql');
sort($files);

$ok = 0; $fail = 0;
foreach ($files as $file) {
    $name = basename($file);
    $sql  = file_get_contents($file);
    // Split on statement delimiters (semicolons not inside strings)
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    $fileOk = true;
    foreach ($statements as $stmt) {
        // Remove full-line SQL comments so commented headers do not hide real statements.
        $stmt = preg_replace('/^\s*--.*$/m', '', $stmt);
        $stmt = trim((string)$stmt);
        if ($stmt === '') continue;
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            echo "[FAIL] {$name}: " . $e->getMessage() . "\n";
            $fail++;
            $fileOk = false;
            break;
        }
    }
    if ($fileOk) {
        echo "[OK]   {$name}\n";
        $ok++;
    }
}

echo "\nMigrations complete: {$ok} succeeded, {$fail} failed.\n";
