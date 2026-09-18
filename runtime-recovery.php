<?php
require_once __DIR__ . '/runtime-recovery-lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function rr_usage(): void {
    fwrite(STDOUT, "Decempionz runtime recovery\n\n");
    fwrite(STDOUT, "Commands:\n");
    fwrite(STDOUT, "  php runtime-recovery.php health\n");
    fwrite(STDOUT, "  php runtime-recovery.php list [category] [logical-name]\n");
    fwrite(STDOUT, "  php runtime-recovery.php verify <category> <logical-name> <snapshot-file>\n");
    fwrite(STDOUT, "  php runtime-recovery.php restore <category> <logical-name> <snapshot-file> --confirm\n");
}

$argv = $_SERVER['argv'] ?? [];
$command = $argv[1] ?? '';

if ($command === 'health') {
    $health = dcz_backup_health();
    fwrite(STDOUT, 'Backup root: ' . $health['root'] . PHP_EOL);
    fwrite(STDOUT, 'Writable: ' . ($health['writable'] ? 'yes' : 'no') . PHP_EOL);
    exit($health['writable'] ? 0 : 2);
}

if ($command === 'list') {
    $base = dcz_backup_root();
    if (!is_dir($base)) {
        fwrite(STDOUT, "No backup directory yet.\n");
        exit(0);
    }

    $category = isset($argv[2]) ? dcz_backup_safe_segment($argv[2]) : null;
    $logical = isset($argv[3]) ? dcz_backup_safe_segment($argv[3]) : null;
    $pattern = $base;
    $pattern .= DIRECTORY_SEPARATOR . ($category !== null ? $category : '*');
    $pattern .= DIRECTORY_SEPARATOR . ($logical !== null ? $logical : '*');
    $pattern .= DIRECTORY_SEPARATOR . '*.json';

    $files = glob($pattern) ?: [];
    sort($files);
    foreach ($files as $file) {
        fwrite(STDOUT, str_replace($base . DIRECTORY_SEPARATOR, '', $file) . PHP_EOL);
    }
    exit(0);
}

if ($command === 'verify' || $command === 'restore') {
    if (count($argv) < 5) {
        rr_usage();
        exit(2);
    }

    $category = dcz_backup_safe_segment((string)$argv[2]);
    $logical = dcz_backup_safe_segment((string)$argv[3]);
    $snapshotFile = (string)$argv[4];

    $verified = dcz_recovery_verify_snapshot($category, $logical, $snapshotFile);
    if (empty($verified['ok'])) {
        $code = (string)($verified['code'] ?? 'verify_failed');
        fwrite(STDERR, "Snapshot verification failed: {$code}.\n");
        exit($code === 'snapshot_not_found' ? 2 : 3);
    }

    fwrite(STDOUT, 'Snapshot valid: ' . $verified['snapshot'] . PHP_EOL);
    if ($command === 'verify') exit(0);

    if (($argv[5] ?? '') !== '--confirm') {
        fwrite(STDERR, "Restore refused: add --confirm after verifying the snapshot.\n");
        exit(4);
    }

    $restored = dcz_recovery_restore_snapshot($category, $logical, $snapshotFile);
    if (empty($restored['ok'])) {
        fwrite(STDERR, 'Restore failed: ' . ($restored['code'] ?? 'unknown') . PHP_EOL);
        exit(5);
    }

    fwrite(STDOUT, 'Restored: ' . $restored['target'] . PHP_EOL);
    exit(0);
}

rr_usage();
exit(2);
