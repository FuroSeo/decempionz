<?php
/* duel-draft-session.php — server-authoritative card offers for Duel drafts. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: https://decempionz.com');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error'=>'method not allowed']); exit;
}

$maxBody = 8000;
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBody) {
    http_response_code(413); echo json_encode(['error'=>'payload too large']); exit;
}
$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > $maxBody) {
    http_response_code(413); echo json_encode(['error'=>'payload too large']); exit;
}
$data = json_decode($raw, true);
if (!is_array($data)) { http_response_code(400); echo json_encode(['error'=>'invalid json']); exit; }

require_once __DIR__ . '/duel-lib.php';
require_once __DIR__ . '/duel-draft-lib.php';

$action = (string)($data['action'] ?? '');

function dcz_draft_reply($result) {
    $code = (int)($result['code'] ?? 200);
    unset($result['code']);
    http_response_code($code);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'start') {
    /* Start is the only operation rate-limited by IP. Subsequent calls require a 128-bit session id. */
    $ip = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $rateDir = sys_get_temp_dir() . '/dcz_duel_draft/';
    @mkdir($rateDir, 0755, true);
    $rf = $rateDir . 'start_' . $ip . '.tmp';
    $fp = @fopen($rf, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) {
        if ($fp) fclose($fp);
        dcz_draft_reply(['error'=>'internal', 'code'=>500]);
    }
    $last = (int)trim(stream_get_contents($fp));
    if ($last > 0 && time() - $last < 2) {
        flock($fp, LOCK_UN); fclose($fp);
        dcz_draft_reply(['error'=>'too many requests', 'code'=>429]);
    }
    rewind($fp); ftruncate($fp, 0); fwrite($fp, (string)time()); fflush($fp);
    flock($fp, LOCK_UN); fclose($fp);

    $config = dcz_draft_config($data['config'] ?? null);
    if ($config === null) dcz_draft_reply(['error'=>'invalid draft config', 'code'=>400]);

    if ($config['role'] === 'b') {
        $duelFile = __DIR__ . '/duels/' . $config['duelId'] . '.json';
        if (!is_file($duelFile)) dcz_draft_reply(['error'=>'duel not found', 'code'=>404]);
        $duel = json_decode((string)@file_get_contents($duelFile), true);
        if (!is_array($duel)) dcz_draft_reply(['error'=>'corrupt duel', 'code'=>500]);
        if (($duel['status'] ?? '') !== 'waiting') dcz_draft_reply(['error'=>'duel not open', 'code'=>409]);
        $duelMode = ($duel['mode'] ?? 'classic') === 'dynasty' ? 'dynasty' : 'classic';
        if ($config['mode'] !== $duelMode) dcz_draft_reply(['error'=>'duel mode mismatch', 'code'=>400]);
        if ($duelMode === 'classic') {
            if ($config['tournament'] !== ($duel['tournament'] ?? null) || $config['eraId'] !== ($duel['eraId'] ?? null)) {
                dcz_draft_reply(['error'=>'duel scope mismatch', 'code'=>400]);
            }
        }
    }

    $state = dcz_draft_create_session($config);
    if ($state === null) dcz_draft_reply(['error'=>'draft session failed', 'code'=>500]);
    dcz_draft_reply($state);
}

$id = preg_replace('/[^a-f0-9]/', '', strtolower((string)($data['sessionId'] ?? '')));
if (strlen($id) !== 32) dcz_draft_reply(['error'=>'invalid session id', 'code'=>400]);

if ($action === 'state') dcz_draft_reply(dcz_draft_session_state($id));

$versionRaw = $data['version'] ?? null;
if (!is_int($versionRaw) && !(is_string($versionRaw) && preg_match('/^\d+$/', $versionRaw))) {
    dcz_draft_reply(['error'=>'invalid version', 'code'=>400]);
}
$version = (int)$versionRaw;

if ($action === 'pick') {
    $idxRaw = $data['cardIndex'] ?? null;
    if (!is_int($idxRaw) && !(is_string($idxRaw) && preg_match('/^\d+$/', $idxRaw))) {
        dcz_draft_reply(['error'=>'invalid card', 'code'=>400]);
    }
    dcz_draft_reply(dcz_draft_session_action($id, $version, 'pick', (int)$idxRaw));
}
if ($action === 'reroll') dcz_draft_reply(dcz_draft_session_action($id, $version, 'reroll'));

dcz_draft_reply(['error'=>'invalid action', 'code'=>400]);
