<?php
// ============================================================
//  LGU ENRILE HRMS — Secure Document Server
//  File: Admin/serve_document.php  (also used by Employee portal)
//
//  Usage:  serve_document.php?id=123&inline=1   → preview in browser
//          serve_document.php?id=123             → force download
// ============================================================
session_start();

if (empty($_SESSION['user_id'])) {
    http_response_code(401); exit('Unauthorized');
}

require_once __DIR__ . '/../config/db.php';

$docId  = (int)($_GET['id'] ?? 0);
$inline = isset($_GET['inline']); // stream inline (preview) vs attachment (download)

if (!$docId) { http_response_code(400); exit('Invalid document ID'); }

try {
    $userId   = (int)$_SESSION['user_id'];
    $userRole = $_SESSION['user_role'] ?? '';

    // Admins / HR staff can see any document; employees only see their own
    if (in_array($userRole, ['admin', 'hr_staff', 'dept_head'])) {
        $stmt = $pdo->prepare("SELECT * FROM documents WHERE document_id = ?");
        $stmt->execute([$docId]);
    } else {
        // Employee: resolve their employee_id first
        $emp = $pdo->prepare("SELECT employee_id FROM employees WHERE user_id = ?");
        $emp->execute([$userId]);
        $employeeId = (int)($emp->fetchColumn() ?: 0);

        $stmt = $pdo->prepare("SELECT * FROM documents WHERE document_id = ? AND employee_id = ?");
        $stmt->execute([$docId, $employeeId]);
    }

    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc) { http_response_code(404); exit('Document not found or access denied'); }

    // Build absolute path — handle multiple stored path formats
    $storedPath = $doc['file_path'];
    // Try direct realpath first
    $absPath = realpath(__DIR__ . '/' . $storedPath);
    // If that fails, try stripping leading ../ and resolving from project root
    if (!$absPath || !file_exists($absPath)) {
        $clean   = ltrim(str_replace(['../', './'], '', $storedPath), '/');
        $absPath = realpath(__DIR__ . '/../' . $clean);
    }
    // Last resort: try from __DIR__ directly
    if (!$absPath || !file_exists($absPath)) {
        $absPath = realpath(dirname(__DIR__) . '/' . ltrim($storedPath, '/'));
    }

    if (!$absPath || !file_exists($absPath)) {
        http_response_code(404); exit('File not found on server');
    }

    // Detect MIME type
    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $absPath);
    finfo_close($finfo);

    // Fallback MIME map
    $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
    $mimeMap = [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
    ];
    if (!$mimeType || $mimeType === 'application/octet-stream') {
        $mimeType = $mimeMap[$ext] ?? 'application/octet-stream';
    }

    $disposition = $inline ? 'inline' : 'attachment';
    $safeFilename = rawurlencode($doc['file_name']);

    header('Content-Type: ' . $mimeType);
    header("Content-Disposition: {$disposition}; filename=\"{$doc['file_name']}\"; filename*=UTF-8''{$safeFilename}");
    header('Content-Length: ' . filesize($absPath));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');

    readfile($absPath);
    exit();

} catch (Exception $e) {
    error_log('[HRMS] serve_document.php: ' . $e->getMessage());
    http_response_code(500);
    exit('Server error');
}