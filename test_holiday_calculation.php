<?php
// Standalone regression checks for Employee/_shared/helpers.php.
require_once __DIR__ . '/Employee/_shared/helpers.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE holidays (holiday_date TEXT NOT NULL, is_recurring INTEGER NOT NULL DEFAULT 0)');
$pdo->exec("INSERT INTO holidays (holiday_date, is_recurring) VALUES
    ('2026-01-01', 1),
    ('2026-06-12', 1),
    ('2026-12-31', 0)");

$cases = [
    ['2026-01-05', '2026-01-11', 5, 'weekends are excluded'],
    ['2026-06-10', '2026-06-15', 3, 'June 12 and weekend are excluded'],
    ['2028-06-10', '2028-06-14', 2, 'June 12 repeats in later years'],
    ['2026-12-30', '2027-01-04', 2, 'one-time and recurring holidays work across years'],
];

foreach ($cases as [$dateFrom, $dateTo, $expected, $label]) {
    $actual = countBusinessDays($pdo, $dateFrom, $dateTo);
    if ($actual !== $expected) {
        throw new RuntimeException(sprintf('%s: expected %d, got %d.', $label, $expected, $actual));
    }
}

echo "Holiday calculation tests passed.\n";