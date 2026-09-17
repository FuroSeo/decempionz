<?php
$root = sys_get_temp_dir() . '/dcz-backup-test-' . bin2hex(random_bytes(4));
putenv('DCZ_BACKUP_DIR=' . $root);
require __DIR__ . '/../runtime-backup-lib.php';

function fail_test(string $message): void {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
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

if (dcz_backup_snapshot('daily', 'bad', '{invalid json}', 2, 30) !== false) {
    fail_test('invalid JSON should not be snapshotted');
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
rm_tree($root);

echo "runtime backup test: OK\n";
