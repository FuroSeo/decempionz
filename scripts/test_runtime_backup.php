<?php
$root = sys_get_temp_dir() . '/dcz-backup-test-' . bin2hex(random_bytes(4));
$runtimeRoot = sys_get_temp_dir() . '/dcz-runtime-test-' . bin2hex(random_bytes(4));
putenv('DCZ_BACKUP_DIR=' . $root);
putenv('DCZ_RUNTIME_ROOT=' . $runtimeRoot);

require __DIR__ . '/../runtime-recovery-lib.php';

function fail_test(string $message): void {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function rm_tree(string $path): void {
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $child = $path . DIRECTORY_SEPARATOR . $name;
        if (is_dir($child)) rm_tree($child); else @unlink($child);
    }
    @rmdir($path);
}

$payloads = [
    json_encode(['entries' => [['id' => 1]]]),
    json_encode(['entries' => [['id' => 2]]]),
    json_encode(['entries' => [['id' => 3]]]),
];
foreach ($payloads as $payload) {
    if (!dcz_backup_snapshot('daily', '2026-09-17', $payload, 2, 30)) fail_test('snapshot failed');
    usleep(10000);
}

$files = glob($root . '/daily/2026-09-17/*.json') ?: [];
if (count($files) !== 2) fail_test('retention count mismatch');

$health = dcz_backup_health();
if (empty($health['writable'])) fail_test('backup health not writable');

$status = dcz_backup_status_summary(1);
if (empty($status['writable']) || empty($status['snapshotSeen']) || empty($status['recent'])) {
    fail_test('backup status summary mismatch');
}

if (dcz_backup_snapshot('daily', 'bad', '{invalid json}', 2, 30) !== false) {
    fail_test('invalid JSON should not be snapshotted');
}

/* Full restore-on-copy exercise using the same recovery primitives as production CLI. */
if (!@mkdir($runtimeRoot, 0755, true) && !is_dir($runtimeRoot)) {
    fail_test('cannot create fake runtime root');
}
$target = $runtimeRoot . '/game-counter.json';
$current = json_encode(['total' => 999]);
$wanted = json_encode(['total' => 321]);
if (@file_put_contents($target, $current, LOCK_EX) === false) {
    fail_test('cannot create fake current target');
}

if (!dcz_backup_snapshot('game-counter', 'main', $wanted, 5, 30)) {
    fail_test('cannot create restore source snapshot');
}
$counterSnapshots = glob($root . '/game-counter/main/*.json') ?: [];
if (count($counterSnapshots) !== 1) fail_test('restore source snapshot count mismatch');
$restoreFile = basename($counterSnapshots[0]);

$verified = dcz_recovery_verify_snapshot('game-counter', 'main', $restoreFile);
if (empty($verified['ok'])) fail_test('restore snapshot verification failed');

$restored = dcz_recovery_restore_snapshot('game-counter', 'main', $restoreFile);
if (empty($restored['ok'])) {
    fail_test('restore failed: ' . ($restored['code'] ?? 'unknown'));
}

$afterRaw = @file_get_contents($target);
$after = is_string($afterRaw) ? json_decode($afterRaw, true) : null;
if (!is_array($after) || (int)($after['total'] ?? -1) !== 321) {
    fail_test('restored target content mismatch');
}

$counterSnapshots = glob($root . '/game-counter/main/*.json') ?: [];
if (count($counterSnapshots) < 2) {
    fail_test('pre-restore safety snapshot was not created');
}

$foundPreRestore = false;
foreach ($counterSnapshots as $snapshot) {
    $raw = @file_get_contents($snapshot);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($decoded) && (int)($decoded['total'] ?? -1) === 999) {
        $foundPreRestore = true;
        break;
    }
}
if (!$foundPreRestore) fail_test('pre-restore target state not recoverable');

/* A corrupt current target must still be recoverable, but its raw bytes are quarantined first. */
$corruptRaw = '{broken-json';
if (@file_put_contents($target, $corruptRaw, LOCK_EX) === false) {
    fail_test('cannot create corrupt target fixture');
}
$restoredCorrupt = dcz_recovery_restore_snapshot('game-counter', 'main', $restoreFile);
if (empty($restoredCorrupt['ok'])) {
    fail_test('restore over corrupt target failed: ' . ($restoredCorrupt['code'] ?? 'unknown'));
}
$afterCorruptRaw = @file_get_contents($target);
$afterCorrupt = is_string($afterCorruptRaw) ? json_decode($afterCorruptRaw, true) : null;
if (!is_array($afterCorrupt) || (int)($afterCorrupt['total'] ?? -1) !== 321) {
    fail_test('corrupt target was not restored');
}
$quarantine = glob($root . '/game-counter/main/*.corrupt') ?: [];
if (!$quarantine) fail_test('corrupt target was not quarantined');
$quarantinedRaw = @file_get_contents($quarantine[0]);
if ($quarantinedRaw !== $corruptRaw) fail_test('quarantined corrupt bytes mismatch');

rm_tree($runtimeRoot);
rm_tree($root);

echo "runtime backup + restore test: OK\n";
