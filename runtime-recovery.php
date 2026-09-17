<?php
require_once __DIR__ . '/runtime-backup-lib.php';

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

function rr_target(string $category, string $logicalName): ?string {
    $category = dcz_backup_safe_segment($category);
    $logicalName = dcz_backup_safe_segment($logicalName);
    return match ($category) {
        'hall-of-fame' => $logicalName === 'main' ? __DIR__ . '/hall-of-fame.json' : null,
        'global-stats' => $logicalName === 'main' ? __DIR__ . '/global-stats.json' : null,
        'game-counter' => $logicalName === 'main' ? __DIR__ . '/game-counter.json' : null,
        'daily' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $logicalName) ? __DIR__ . '/daily-scores/' . $logicalName . '.json' : null,
        'challenge' => preg_match('/^\d{4}-W(?:0[1-9]|[1-4]\d|5[0-3])$/', $logicalName) ? __DIR__ . '/challenge-scores/' . $logicalName . '.json' : null,
        default => null,
    };
}

function rr_snapshot_path(string $category, string $logicalName, string $snapshotFile): string {
    $category = dcz_backup_safe_segment($category);
    $logicalName = dcz_backup_safe_segment($logicalName);
    $snapshotFile = basename($snapshotFile);
    return dcz_backup_root() . DIRECTORY_SEPARATOR . $category . DIRECTORY_SEPARATOR . $logicalName . DIRECTORY_SEPARATOR . $snapshotFile;
}

function rr_validate_json(string $raw, string $category): bool {
    $data = json_decode($raw, true);
    if (!is_array($data)) return false;
    return match ($category) {
        'hall-of-fame', 'daily', 'challenge' => isset($data['entries']) && is_array($data['entries']),
        'game-counter' => isset($data['total']) && is_numeric($data['total']),
        'global-stats' => isset($data['campaigns']) && is_array($data['campaigns']),
        default => false,
    };
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
    if ($category !== null) $pattern .= DIRECTORY_SEPARATOR . $category;
    else $pattern .= DIRECTORY_SEPARATOR . '*';
    if ($logical !== null) $pattern .= DIRECTORY_SEPARATOR . $logical;
    else $pattern .= DIRECTORY_SEPARATOR . '*';
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
    $snapshot = rr_snapshot_path($category, $logical, (string)$argv[4]);
    if (!is_file($snapshot)) {
        fwrite(STDERR, "Snapshot not found.\n");
        exit(2);
    }
    $raw = @file_get_contents($snapshot);
    if ($raw === false || !rr_validate_json($raw, $category)) {
        fwrite(STDERR, "Snapshot is not valid for category {$category}.\n");
        exit(3);
    }
    fwrite(STDOUT, 'Snapshot valid: ' . basename($snapshot) . PHP_EOL);
    if ($command === 'verify') exit(0);

    if (($argv[5] ?? '') !== '--confirm') {
        fwrite(STDERR, "Restore refused: add --confirm after verifying the snapshot.\n");
        exit(4);
    }
    $target = rr_target($category, $logical);
    if ($target === null) {
        fwrite(STDERR, "Invalid restore target.\n");
        exit(4);
    }

    $currentRaw = is_file($target) ? @file_get_contents($target) : '';
    if (is_string($currentRaw) && trim($currentRaw) !== '') {
        dcz_backup_snapshot($category, $logical, $currentRaw);
    }

    $targetDir = dirname($target);
    if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        fwrite(STDERR, "Cannot create target directory.\n");
        exit(5);
    }
    $tmp = $target . '.restore-' . getmypid() . '-' . bin2hex(random_bytes(3)) . '.tmp';
    if (@file_put_contents($tmp, $raw, LOCK_EX) === false) {
        fwrite(STDERR, "Cannot write restore temp file.\n");
        exit(5);
    }
    @chmod($tmp, 0644);
    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        fwrite(STDERR, "Cannot replace target file.\n");
        exit(5);
    }
    fwrite(STDOUT, 'Restored: ' . $target . PHP_EOL);
    exit(0);
}

rr_usage();
exit(2);
