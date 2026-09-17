<?php

declare(strict_types=1);

require_once __DIR__ . '/../draft-storage-lib.php';

function t_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) rrmdir($path); else @unlink($path);
    }
    @rmdir($dir);
}

$root = sys_get_temp_dir() . '/dcz_draft_storage_test_' . bin2hex(random_bytes(5));
mkdir($root, 0755, true);
$now = 2_000_000_000;

try {
    $id = 'Ab12Cd34';
    file_put_contents($root . '/' . $id . '.json', '{"id":"Ab12Cd34"}');
    $token = dcz_draft_create_upload_grant($root, $id, $now);
    t_assert(is_string($token) && preg_match('/^[a-f0-9]{48}$/', $token) === 1, 'grant token must be random hex');
    t_assert(file_exists(dcz_draft_auth_path($root, $id)), 'server-side auth record must exist');
    t_assert(dcz_draft_validate_upload_grant($root, $id, $token, $now + 10), 'valid grant must be accepted');
    t_assert(!dcz_draft_validate_upload_grant($root, $id, str_repeat('0', 48), $now + 10), 'wrong token must be rejected');
    t_assert(!dcz_draft_validate_upload_grant($root, $id, $token, $now + DCZ_DRAFT_UPLOAD_TTL + 1), 'expired grant must be rejected');
    dcz_draft_consume_upload_grant($root, $id);
    t_assert(!file_exists(dcz_draft_auth_path($root, $id)), 'consumed grant must be deleted');
    @unlink($root . '/' . $id . '.json');

    $oldId = 'Old12345';
    file_put_contents($root . '/' . $oldId . '.json', '{"id":"Old12345"}');
    file_put_contents($root . '/' . $oldId . '.jpg', 'jpeg');
    file_put_contents(dcz_draft_auth_path($root, $oldId), '{"tokenHash":"' . str_repeat('a', 64) . '","expiresAt":1}');
    $oldTime = $now - DCZ_DRAFT_RETENTION_SECONDS - 100;
    touch($root . '/' . $oldId . '.json', $oldTime);
    touch($root . '/' . $oldId . '.jpg', $oldTime);
    touch(dcz_draft_auth_path($root, $oldId), $oldTime);

    $recentId = 'New12345';
    file_put_contents($root . '/' . $recentId . '.json', '{"id":"New12345"}');
    file_put_contents($root . '/' . $recentId . '.jpg', 'jpeg');
    touch($root . '/' . $recentId . '.json', $now - 60);
    touch($root . '/' . $recentId . '.jpg', $now - 60);

    $expiredAuthId = 'Tok12345';
    file_put_contents($root . '/' . $expiredAuthId . '.json', '{"id":"Tok12345"}');
    file_put_contents(dcz_draft_auth_path($root, $expiredAuthId), '{"tokenHash":"' . str_repeat('b', 64) . '","expiresAt":' . ($now - DCZ_DRAFT_ORPHAN_GRACE - 10) . '}');
    touch($root . '/' . $expiredAuthId . '.json', $now - 60);

    $orphanId = 'Img12345';
    file_put_contents($root . '/' . $orphanId . '.jpg', 'jpeg');
    touch($root . '/' . $orphanId . '.jpg', $now - DCZ_DRAFT_ORPHAN_GRACE - 10);

    $stats = dcz_draft_cleanup($root, $now);
    t_assert(!file_exists($root . '/' . $oldId . '.json'), 'old draft JSON must be removed');
    t_assert(!file_exists($root . '/' . $oldId . '.jpg'), 'old draft image must be removed with JSON');
    t_assert(!file_exists(dcz_draft_auth_path($root, $oldId)), 'old draft auth must be removed with JSON');
    t_assert(file_exists($root . '/' . $recentId . '.json'), 'recent draft JSON must survive retention');
    t_assert(file_exists($root . '/' . $recentId . '.jpg'), 'recent draft image must survive retention');
    t_assert(file_exists($root . '/' . $expiredAuthId . '.json'), 'draft with expired grant must remain valid');
    t_assert(!file_exists(dcz_draft_auth_path($root, $expiredAuthId)), 'long-expired auth grant must be cleaned');
    t_assert(!file_exists($root . '/' . $orphanId . '.jpg'), 'old orphan image must be cleaned');
    t_assert(($stats['drafts'] ?? 0) === 1, 'cleanup must report one expired draft pair');

    echo "Draft storage tests passed.\n";
} finally {
    rrmdir($root);
}
