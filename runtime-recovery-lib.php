<?php
// Shared runtime recovery primitives.
// This file is an internal library and must not be called directly over HTTP.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'runtime-recovery-lib.php') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/runtime-backup-lib.php';

function dcz_recovery_runtime_root(): string {
    // Test/maintenance override is honored only from CLI so a web request cannot redirect restores.
    if (PHP_SAPI === 'cli') {
        $override = getenv('DCZ_RUNTIME_ROOT');
        if (is_string($override) && trim($override) !== '') {
            return rtrim($override, DIRECTORY_SEPARATOR);
        }
    }
    return __DIR__;
}

function dcz_recovery_target(string $category, string $logicalName): ?string {
    $category = dcz_backup_safe_segment($category);
    $logicalName = dcz_backup_safe_segment($logicalName);
    $root = dcz_recovery_runtime_root();

    return match ($category) {
        'hall-of-fame' => $logicalName === 'main' ? $root . '/hall-of-fame.json' : null,
        'global-stats' => $logicalName === 'main' ? $root . '/global-stats.json' : null,
        'game-counter' => $logicalName === 'main' ? $root . '/game-counter.json' : null,
        'daily' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $logicalName)
            ? $root . '/daily-scores/' . $logicalName . '.json'
            : null,
        'challenge' => preg_match('/^\d{4}-W(?:0[1-9]|[1-4]\d|5[0-3])$/', $logicalName)
            ? $root . '/challenge-scores/' . $logicalName . '.json'
            : null,
        default => null,
    };
}

function dcz_recovery_snapshot_path(string $category, string $logicalName, string $snapshotFile): string {
    $category = dcz_backup_safe_segment($category);
    $logicalName = dcz_backup_safe_segment($logicalName);
    $snapshotFile = basename($snapshotFile);
    return dcz_backup_root()
        . DIRECTORY_SEPARATOR . $category
        . DIRECTORY_SEPARATOR . $logicalName
        . DIRECTORY_SEPARATOR . $snapshotFile;
}

function dcz_recovery_validate_json(string $raw, string $category): bool {
    $data = json_decode($raw, true);
    if (!is_array($data)) return false;

    return match ($category) {
        'hall-of-fame', 'daily', 'challenge' => isset($data['entries']) && is_array($data['entries']),
        'game-counter' => isset($data['total']) && is_numeric($data['total']),
        'global-stats' => isset($data['campaigns']) && is_array($data['campaigns']),
        default => false,
    };
}

function dcz_recovery_verify_snapshot(
    string $category,
    string $logicalName,
    string $snapshotFile
): array {
    $category = dcz_backup_safe_segment($category);
    $logicalName = dcz_backup_safe_segment($logicalName);
    $snapshot = dcz_recovery_snapshot_path($category, $logicalName, $snapshotFile);

    if (!is_file($snapshot)) {
        return ['ok' => false, 'code' => 'snapshot_not_found'];
    }

    $raw = @file_get_contents($snapshot);
    if (!is_string($raw) || !dcz_recovery_validate_json($raw, $category)) {
        return ['ok' => false, 'code' => 'invalid_snapshot'];
    }

    return [
        'ok' => true,
        'category' => $category,
        'logicalName' => $logicalName,
        'snapshot' => basename($snapshot),
        'raw' => $raw,
    ];
}

function dcz_recovery_restore_snapshot(
    string $category,
    string $logicalName,
    string $snapshotFile
): array {
    $verified = dcz_recovery_verify_snapshot($category, $logicalName, $snapshotFile);
    if (empty($verified['ok'])) return $verified;

    $category = (string)$verified['category'];
    $logicalName = (string)$verified['logicalName'];
    $raw = (string)$verified['raw'];

    $target = dcz_recovery_target($category, $logicalName);
    if ($target === null) {
        return ['ok' => false, 'code' => 'invalid_target'];
    }

    $currentRaw = is_file($target) ? @file_get_contents($target) : '';
    if (is_string($currentRaw) && trim($currentRaw) !== '') {
        if (!dcz_recovery_validate_json($currentRaw, $category)) {
            return ['ok' => false, 'code' => 'current_target_invalid'];
        }
        if (!dcz_backup_snapshot($category, $logicalName, $currentRaw)) {
            return ['ok' => false, 'code' => 'pre_restore_snapshot_failed'];
        }
    }

    $targetDir = dirname($target);
    if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        return ['ok' => false, 'code' => 'target_dir_failed'];
    }

    try {
        $suffix = bin2hex(random_bytes(3));
    } catch (Throwable $e) {
        $suffix = substr(hash('sha256', microtime(true) . mt_rand()), 0, 6);
    }
    $tmp = $target . '.restore-' . getmypid() . '-' . $suffix . '.tmp';

    if (@file_put_contents($tmp, $raw, LOCK_EX) === false) {
        return ['ok' => false, 'code' => 'temp_write_failed'];
    }
    @chmod($tmp, 0644);

    if (!@rename($tmp, $target)) {
        @unlink($tmp);
        return ['ok' => false, 'code' => 'replace_failed'];
    }

    return [
        'ok' => true,
        'target' => $target,
        'snapshot' => (string)$verified['snapshot'],
    ];
}
