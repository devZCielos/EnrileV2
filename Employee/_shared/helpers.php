<?php
// ============================================================
//  LGU ENRILE HRMS — Employee Portal Shared Helpers
//  File: Employee/_shared/helpers.php
// ============================================================

// ── Format date to readable format ──
function fmt($dateStr) {
    if (!$dateStr) return '';
    try {
        $dt = new DateTime($dateStr);
        return $dt->format('M d, Y');
    } catch (Exception $e) {
        return $dateStr;
    }
}

// ── Time ago formatter ──
function empTimeAgo($dt) {
    if (!$dt) return '';
    $d = time() - strtotime($dt);
    if ($d < 60) return 'Just now';
    if ($d < 3600) return (int)($d/60).'m ago';
    if ($d < 86400) return (int)($d/3600).'h ago';
    if ($d < 172800) return 'Yesterday';
    return date('M j', strtotime($dt));
}

// ── Get initials from name ──
function getInitials($firstName, $lastName) {
    $first = strtoupper(substr($firstName, 0, 1));
    $last = strtoupper(substr($lastName, 0, 1));
    return $first . $last;
}

// ── Status badge class ──
function getStatusClass($status) {
    $map = [
        'Pending' => 'b-pending',
        'Approved' => 'b-approved',
        'Denied' => 'b-denied',
        'Cancelled' => 'b-cancelled',
    ];
    return $map[$status] ?? 'b-pending';
}

// ── Status emoji icon ──
function getStatusIcon($status) {
    $map = [
        'Pending' => '⏳',
        'Approved' => '✅',
        'Denied' => '✗',
        'Cancelled' => '—',
    ];
    return $map[$status] ?? '';
}

// ── Get avatar background gradient ──
function getAvatarGradient($seed = '') {
    $hues = [
        '#6366f1,#2563eb',  // Blue-Indigo
        '#7c3aed,#a855f7',  // Purple-Violet
        '#ec4899,#f43f5e',  // Pink-Rose
        '#f97316,#ef4444',  // Orange-Red
        '#eab308,#f59e0b',  // Yellow-Amber
        '#10b981,#06b6d4',  // Green-Cyan
    ];
    $index = (strlen($seed) > 0 ? abs(crc32($seed)) : 0) % count($hues);
    return $hues[$index];
}

// ── Parse employment type color ──
function getEmploymentTypeColor($type) {
    $map = [
        'Permanent' => '#6366f1',
        'Contractual' => '#06b6d4',
        'Casual' => '#f59e0b',
        'Job Order' => '#10b981',
    ];
    return $map[$type] ?? '#6366f1';
}

// ── Parse employment type badge ──
function getEmploymentTypeBadge($type) {
    $map = [
        'Permanent' => 'badge-perm',
        'Contractual' => 'badge-cont',
        'Casual' => 'badge-cas',
        'Job Order' => 'badge-jo',
    ];
    return $map[$type] ?? 'badge-perm';
}

// ── Check if feature is available for employment type ──
function isLeaveFeatureAvailable($employmentType) {
    return $employmentType === 'Permanent';
}

// ── Get MIME extension ──
function getMimeExt($mime, $allowedMimes) {
    return $allowedMimes[$mime] ?? '';
}

// ── Get file icon ──
function getFileIcon($type) {
    $map = [
        'pdf' => '📄',
        'doc' => '📘',
        'docx' => '📘',
        'xls' => '📊',
        'xlsx' => '📊',
        'jpg' => '🖼️',
        'png' => '🖼️',
        'gif' => '🖼️',
    ];
    return $map[$type] ?? '📎';
}

// ── Format file size ──
function formatFileSize($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / (1024 * 1024), 1) . ' MB';
}

// ── Sanitize input ──
function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// ── Check document type allowed ──
function isDocumentTypeAllowed($type) {
    $allowed = ['PDS', 'SALN', 'Certificate', 'License', 'Other'];
    return in_array($type, $allowed);
}
?>
