<?php

declare(strict_types=1);

const DCZ_DRAFT_UPLOAD_COOKIE = 'dcz_draft_upload';
const DCZ_DRAFT_UPLOAD_TTL = 900; // 15 minuti
const DCZ_DRAFT_RETENTION_SECONDS = 31536000; // 365 giorni
const DCZ_DRAFT_CLEANUP_INTERVAL = 21600; // 6 ore
const DCZ_DRAFT_ORPHAN_GRACE = 86400; // 24 ore

function dcz_draft_auth_path(string $draftsDir, string $id): string {
    return rtrim($draftsDir, '/\\') . DIRECTORY_SEPARATOR . $id . '.draft-auth';
}

function dcz_draft_create_upload_grant(string $draftsDir, string $id, ?int $now = null): ?string {
    $now ??= time();
    $token = bin2hex(random_bytes(24));
    $record = json_encode([
        'tokenHash' => hash('sha256', $token),
        'expiresAt' => $now + DCZ_DRAFT_UPLOAD_TTL,
    ], JSON_UNESCAPED_SLASHES);
    if ($record === false) return null;

    $authFile = dcz_draft_auth_path($draftsDir, $id);
    $tmp = $authFile . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $record, LOCK_EX) === false) {
        @unlink($tmp);
        return null;
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $authFile)) {
        @unlink($tmp);
        return null;
    }
    return $token;
}

function dcz_draft_validate_upload_grant(string $draftsDir, string $id, string $token, ?int $now = null): bool {
    $now ??= time();
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) return false;

    $authFile = dcz_draft_auth_path($draftsDir, $id);
    $raw = @file_get_contents($authFile);
    if ($raw === false || trim($raw) === '') return false;
    $record = json_decode($raw, true);
    if (!is_array($record)) return false;

    $expiresAt = (int)($record['expiresAt'] ?? 0);
    $expected = (string)($record['tokenHash'] ?? '');
    if ($expiresAt < $now || !preg_match('/^[a-f0-9]{64}$/', $expected)) return false;

    return hash_equals($expected, hash('sha256', $token));
}

function dcz_draft_consume_upload_grant(string $draftsDir, string $id): void {
    @unlink(dcz_draft_auth_path($draftsDir, $id));
}

function dcz_draft_set_upload_cookie(string $id, string $token, ?int $now = null): void {
    $now ??= time();
    setcookie(DCZ_DRAFT_UPLOAD_COOKIE, $id . '.' . $token, [
        'expires' => $now + DCZ_DRAFT_UPLOAD_TTL,
        'path' => '/draft-img.php',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function dcz_draft_clear_upload_cookie(): void {
    setcookie(DCZ_DRAFT_UPLOAD_COOKIE, '', [
        'expires' => time() - 3600,
        'path' => '/draft-img.php',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function dcz_draft_cookie_token_for_id(string $id): ?string {
    $raw = (string)($_COOKIE[DCZ_DRAFT_UPLOAD_COOKIE] ?? '');
    $prefix = $id . '.';
    if (!str_starts_with($raw, $prefix)) return null;
    $token = substr($raw, strlen($prefix));
    return preg_match('/^[a-f0-9]{48}$/', $token) ? $token : null;
}

function dcz_draft_cleanup(string $draftsDir, ?int $now = null): array {
    $now ??= time();
    $draftsDir = rtrim($draftsDir, '/\\') . DIRECTORY_SEPARATOR;
    $stats = ['drafts' => 0, 'orphans' => 0, 'expiredAuth' => 0];
    if (!is_dir($draftsDir)) return $stats;

    $entries = @scandir($draftsDir);
    if (!is_array($entries)) return $stats;

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;

        if (preg_match('/^([A-Za-z0-9]{6,12})\.json$/', $entry, $m)) {
            $id = $m[1];
            $jsonFile = $draftsDir . $entry;
            $mtime = @filemtime($jsonFile);
            if ($mtime !== false && ($now - $mtime) > DCZ_DRAFT_RETENTION_SECONDS) {
                @unlink($jsonFile);
                @unlink($draftsDir . $id . '.jpg');
                @unlink(dcz_draft_auth_path($draftsDir, $id));
                $stats['drafts']++;
            }
            continue;
        }

        if (preg_match('/^([A-Za-z0-9]{6,12})\.draft-auth$/', $entry, $m)) {
            $id = $m[1];
            $authFile = $draftsDir . $entry;
            $raw = @file_get_contents($authFile);
            $record = $raw !== false ? json_decode($raw, true) : null;
            $expiresAt = is_array($record) ? (int)($record['expiresAt'] ?? 0) : 0;
            $jsonExists = file_exists($draftsDir . $id . '.json');
            if (!$jsonExists) {
                $mtime = @filemtime($authFile);
                if ($mtime !== false && ($now - $mtime) > DCZ_DRAFT_ORPHAN_GRACE) {
                    @unlink($authFile);
                    $stats['orphans']++;
                }
            } elseif ($expiresAt > 0 && ($now - $expiresAt) > DCZ_DRAFT_ORPHAN_GRACE) {
                @unlink($authFile);
                $stats['expiredAuth']++;
            }
            continue;
        }

        if (preg_match('/^([A-Za-z0-9]{6,12})\.jpg$/', $entry, $m)) {
            $id = $m[1];
            if (!file_exists($draftsDir . $id . '.json')) {
                $jpgFile = $draftsDir . $entry;
                $mtime = @filemtime($jpgFile);
                if ($mtime !== false && ($now - $mtime) > DCZ_DRAFT_ORPHAN_GRACE) {
                    @unlink($jpgFile);
                    $stats['orphans']++;
                }
            }
        }
    }

    return $stats;
}

function dcz_draft_maybe_cleanup(string $draftsDir, ?int $now = null): void {
    $now ??= time();
    $marker = sys_get_temp_dir() . '/dcz_draft_cleanup_' . hash('sha256', realpath($draftsDir) ?: $draftsDir) . '.tmp';
    $fp = @fopen($marker, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) {
        if ($fp) fclose($fp);
        return;
    }

    $last = (int)trim(stream_get_contents($fp));
    if ($last > 0 && ($now - $last) < DCZ_DRAFT_CLEANUP_INTERVAL) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return;
    }

    dcz_draft_cleanup($draftsDir, $now);
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, (string)$now);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}
