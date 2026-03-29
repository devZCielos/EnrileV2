<?php
// ============================================================
//  LGU ENRILE HRMS — Database Connection
//  File: config/db.php
//
//  Usage (from any PHP file in your project):
//    require_once __DIR__ . '/../config/db.php';
//    // $pdo is now available
// ============================================================

// ── Error display — set once here, applies everywhere ────────
// Never show raw PHP errors to the browser (they leak stack
// traces and internal paths). Always log them to the PHP error
// log instead so you can still diagnose problems server-side.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// ── Database credentials ──────────────────────────────────────
// Read from environment variables so credentials are never
// hardcoded in source code or committed to git.
//
// For local XAMPP development, create a file at:
//   C:\xampp\htdocs\enrile\.env
// containing lines like:
//   DB_USER=root
//   DB_PASS=
//
// For production, set these as actual environment variables
// in your server / hosting control panel instead.
//
// The getenv() calls fall back to the XAMPP defaults so the
// system keeps working even without a .env file.
define('DB_HOST',    getenv('DB_HOST')    ?: 'localhost');
define('DB_PORT',    getenv('DB_PORT')    ?: '3306');
define('DB_NAME',    getenv('DB_NAME')    ?: 'lgu_enrile_hrms');
define('DB_USER',    getenv('DB_USER')    ?: 'root');
define('DB_PASS',    getenv('DB_PASS')    ?: '');
define('DB_CHARSET', 'utf8mb4');

// PDO options — strict errors, fetch as associative arrays by default
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
);

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    // In production, log the error instead of displaying it
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    die(json_encode([
        'success' => false,
        'message' => 'Database connection failed. Please contact the system administrator.'
    ]));
}