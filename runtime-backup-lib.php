<?php
// Runtime snapshot helper for durable server-side JSON state.
// Backups live outside public_html by default: dirname(__DIR__)/decempionz-runtime-backups.

function dcz_backup_root(): string {
    $override = getenv('DCZ_BACKUP_DIR');
    if (is_string($override) && trim($override) !== '') {
        return rtrim($override, DIRECTORY_SEPARATOR);
    }
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'decempionz-runtime-backups';
}

function dcz_backup_safe_segment(string $value): string {
    $safe = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $value);
    $safe = trim((string)$safe, '._-');
    return $safe !== '' ? substr($safe, 0, 120) : 'main';
}

function dcz_backup_snapshot(
    string $category,
    string $logicalName,
    string $rawJson,
    int $maxVersions = 30,
    int $maxAgeDays = 90
): bool {
    if (trim($rawJson) === '') return true;

    $decoded = json_decode($rawJson, true);
    if (!is_array($decoded)) {
        error_log('Decempionz backup skipped: source JSON is invalid.');
        return false;
    }

    $category = dcz_backup_safe_segment($category);
    $logicalName = dcz_backup_safe_segment($logicalName);
    $dir = dcz_backup_root() . DIRECTORY_SEPARATOR . $category . DIRECTORY_SEPARATOR . $logicalName;

    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        error_log('Decempionz backup unavailable: cannot create ' . $dir);
        return false;
    }

    $stamp = gmdate('Ymd_His');
    try {
        $suffix = bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $suffix = substr(hash('sha256', microtime(true) . mt_rand()), 0, 8);
    }
    $path = $dir . DIRECTORY_SEPARATOR . $stamp . '_' . $suffix . '.json';
    $written = @file_put_contents($path, $rawJson, LOCK_EX);
    if ($written === false) {
        error_log('Decempionz backup unavailable: cannot write ' . $path);
        return false;
    }
    @chmod($path, 0600);

    dcz_backup_prune($dir, max(1, $maxVersions), max(1, $maxAgeDays));
    return true;
}

function dcz_backup_prune(string $dir, int $maxVersions, int $maxAgeDays): void {
    $files = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
    if (!$files) return;

    $cutoff = time() - ($maxAgeDays * 86400);
    foreach ($files as $file) {
        $mtime = @filemtime($file);
        if ($mtime !== false && $mtime < $cutoff) @unlink($file);
    }

    $files = glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
    usort($files, static function ($a, $b) {
        return ((int)@filemtime($b)) <=> ((int)@filemtime($a));
    });
    foreach (array_slice($files, $maxVersions) as $file) @unlink($file);
}

function dcz_backup_health(): array {
    $root = dcz_backup_root();
    if (is_dir($root)) {
        return ['root' => $root, 'writable' => is_writable($root)];
    }
    $parent = dirname($root);
    return ['root' => $root, 'writable' => is_dir($parent) && is_writable($parent)];
}
function dcz_backup_status_summary(int $recentHours = 720): array {
    $health = dcz_backup_health();
    $root = (string)$health['root'];
    $snapshotSeen = false;
    $newest = null;

    if (is_dir($root)) {
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'json') continue;
                $snapshotSeen = true;
                $mtime = $file->getMTime();
                if ($newest === null || $mtime > $newest) $newest = $mtime;
            }
        } catch (Throwable $e) {
            error_log('Decempionz backup health scan failed: ' . $e->getMessage());
        }
    }

    $recent = $newest !== null && $newest >= (time() - max(1, $recentHours) * 3600);
    return [
        'writable' => !empty($health['writable']),
        'snapshotSeen' => $snapshotSeen,
        'recent' => $recent,
    ];
}
