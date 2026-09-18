<?php
/* duel-create.php — crea un duello 1v1 asincrono.
   POST: payload del giocatore A (sfidante). Crea duels/{id}.json con status 'waiting'. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: https://decempionz.com');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'method not allowed']); exit;
}

$duelsDir = __DIR__ . '/duels/';
if (!is_dir($duelsDir)) { @mkdir($duelsDir, 0755, true); }
/* i JSON dei duelli non devono essere leggibili via HTTP diretto */
$ht = $duelsDir . '.htaccess';
if (!file_exists($ht)) { @file_put_contents($ht, "Require all denied\n"); }

/* Log minimale degli errori; rimuove caratteri di controllo per evitare log injection. */
function dcz_log_err($reason) {
    global $duelsDir;
    $safe = preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string)$reason);
    $safe = mb_substr($safe, 0, 300);
    @file_put_contents($duelsDir . '_errors.log', date('c') . ' create ' . $safe . "\n", FILE_APPEND | LOCK_EX);
}

/* Rate limit: 1 creazione ogni 3 secondi per IP. */
$ip = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
$rateDir = sys_get_temp_dir() . '/dcz_duels/';
@mkdir($rateDir, 0755, true);
$rf = $rateDir . $ip . '.tmp';
if (file_exists($rf) && (time() - filemtime($rf)) < 3) {
    dcz_log_err('rate_limit ip=' . $ip);
    http_response_code(429); echo json_encode(['error' => 'too many requests']); exit;
}
touch($rf);

$maxBody = 15000;
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBody) {
    dcz_log_err('payload_too_large content_length=' . (int)$_SERVER['CONTENT_LENGTH']);
    http_response_code(413); echo json_encode(['error' => 'payload too large']); exit;
}
$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > $maxBody) {
    dcz_log_err('payload_too_large len=' . ($raw === false ? -1 : strlen($raw)));
    http_response_code(413); echo json_encode(['error' => 'payload too large']); exit;
}
$data = json_decode($raw, true);
if (!is_array($data)) {
    dcz_log_err('invalid_json raw_len=' . strlen($raw));
    http_response_code(400); echo json_encode(['error' => 'invalid json']); exit;
}

require __DIR__ . '/duel-lib.php';
require_once __DIR__ . '/duel-draft-lib.php';

$validTournaments = ['ucl', 'copa', 'wc'];
if (!in_array($data['tournament'] ?? '', $validTournaments, true)) {
    dcz_log_err('invalid_tournament');
    http_response_code(400); echo json_encode(['error' => 'invalid tournament']); exit;
}

$mode = ($data['mode'] ?? 'classic') === 'dynasty' ? 'dynasty' : 'classic';
$team = dcz_sanitize_team($data['team'] ?? null);
if ($team === null) {
    dcz_log_err('invalid_team');
    http_response_code(400); echo json_encode(['error' => 'invalid team']); exit;
}

$eraId = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($data['eraId'] ?? ''));
if ($mode === 'dynasty') {
    if (empty($team['club']) || empty($team['tmode']) || $team['tmode'] !== $data['tournament']) {
        dcz_log_err('dynasty_scope_invalid');
        http_response_code(400); echo json_encode(['error' => 'invalid dynasty team scope']); exit;
    }
    $datasetOk = dcz_duel_validate_team_dataset($team, $team['tmode'], $team['club']);
} else {
    $datasetOk = dcz_duel_validate_team_dataset($team, $data['tournament']);
}
if (!$datasetOk) {
    dcz_log_err('dataset_team_mismatch');
    http_response_code(400); echo json_encode(['error' => 'invalid team source']); exit;
}

$draftSessionId = preg_replace('/[^a-f0-9]/', '', strtolower((string)($data['draftSessionId'] ?? '')));
if (strlen($draftSessionId) !== 32) {
    dcz_log_err('missing_draft_session');
    http_response_code(400); echo json_encode(['error' => 'verified draft required']); exit;
}
$draftExpect = [
    'role' => 'a',
    'mode' => $mode,
    'tournament' => $data['tournament'],
    'formation' => $team['formation'],
    'tactic' => $team['tactic'],
    'eraId' => $mode === 'dynasty' ? 'dynasty' : $eraId,
    'club' => $mode === 'dynasty' ? $team['club'] : null,
    'duelId' => null,
];
$draftVerified = dcz_draft_verify_completed_session($draftSessionId, $team, $draftExpect);
if (empty($draftVerified['ok'])) {
    /* Idempotenza: se la sessione e' gia' stata consumata da una precedente richiesta
       riuscita ma la risposta e' andata persa, restituisci lo stesso Duel. */
    $existingId = preg_replace('/[^A-Za-z0-9]/', '', (string)($draftVerified['consumedRef'] ?? ''));
    if (($draftVerified['error'] ?? '') === 'draft session already used'
        && strlen($existingId) >= 6 && strlen($existingId) <= 12) {
        $existingFile = $duelsDir . $existingId . '.json';
        $existing = is_file($existingFile) ? json_decode((string)@file_get_contents($existingFile), true) : null;
        if (is_array($existing)
            && (($existing['_draftA']['sessionId'] ?? '') === $draftSessionId)
            && dcz_draft_team_equals_final($team, $existing['a']['players'] ?? [])) {
            echo json_encode([
                'id' => $existingId,
                'url' => 'https://decempionz.com/duel.php?id=' . $existingId,
                'reused' => true,
            ]);
            exit;
        }
    }
    dcz_log_err('draft_proof_failed reason=' . ($draftVerified['error'] ?? 'unknown'));
    http_response_code((int)($draftVerified['code'] ?? 400));
    echo json_encode(['error' => 'draft verification failed']); exit;
}

/* Genera ID unico di 8 caratteri */
do {
    $bytes = random_bytes(6);
    $id = rtrim(strtr(base64_encode($bytes), '+/', 'ab'), '=');
    $id = substr($id, 0, 8);
    $file = $duelsDir . $id . '.json';
} while (file_exists($file));

$payload = [
    'id'         => $id,
    'status'     => 'waiting',
    'mode'       => $mode,
    'tournament' => $data['tournament'],
    'era'        => mb_substr((string)($data['era'] ?? ''), 0, 60),
    'eraId'      => $eraId,
    'lang'       => in_array($data['lang'] ?? 'it', ['it', 'en', 'es'], true) ? $data['lang'] : 'it',
    'a'          => $team,
    'b'          => null,
    'result'     => null,
    'createdAt'  => date('c'),
    'doneAt'     => null,
    '_draftA'     => $draftVerified['proof'],
];

$encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
$written = $encoded === false ? false : file_put_contents($file, $encoded, LOCK_EX);
if ($written === false) {
    dcz_log_err('write_failed file=' . $id);
    http_response_code(500); echo json_encode(['error' => 'write failed']); exit;
}
if (!dcz_draft_consume_session($draftSessionId, $id)) {
    @unlink($file);
    dcz_log_err('draft_consume_failed');
    http_response_code(500); echo json_encode(['error' => 'draft verification commit failed']); exit;
}

dcz_cleanup_old_duels($duelsDir);

echo json_encode([
    'id'  => $id,
    'url' => 'https://decempionz.com/duel.php?id=' . $id,
]);
