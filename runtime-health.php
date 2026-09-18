<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

require_once __DIR__ . '/runtime-backup-lib.php';

$status = dcz_backup_status_summary(720); // 30-day freshness signal.
$ok = !empty($status['writable']) && !empty($status['snapshotSeen']);

http_response_code($ok ? 200 : 503);
echo json_encode([
    'ok' => $ok,
    'backup' => [
        'writable' => (bool)$status['writable'],
        'snapshotSeen' => (bool)$status['snapshotSeen'],
        'recent' => (bool)$status['recent'],
    ],
], JSON_UNESCAPED_SLASHES);
