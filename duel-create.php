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
/* i JSON dei duelli non devono essere leggibili via HTTP diretto:
   finche' il duello e' 'waiting' la rosa di A resta nascosta (la serve solo duel-join.php) */
$ht = $duelsDir . '.htaccess';
if (!file_exists($ht)) { @file_put_contents($ht, "Require all denied\n"); }

/* Rate limit: 1 creazione ogni 20 secondi per IP */
$ip      = md5($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$rateDir = sys_get_temp_dir() . '/dcz_duels/';
@mkdir($rateDir, 0755, true);
$rf = $rateDir . $ip . '.tmp';
if (file_exists($rf) && (time() - filemtime($rf)) < 20) {
    http_response_code(429); echo json_encode(['error' => 'too many requests']); exit;
}
touch($rf);

$raw = file_get_contents('php://input');
if (strlen($raw) > 15000) {
    http_response_code(413); echo json_encode(['error' => 'payload too large']); exit;
}
$data = json_decode($raw, true);
if (!$data) { http_response_code(400); echo json_encode(['error' => 'invalid json']); exit; }

require __DIR__ . '/duel-lib.php';

$validTournaments = ['ucl', 'copa', 'wc'];
if (!in_array($data['tournament'] ?? '', $validTournaments)) {
    http_response_code(400); echo json_encode(['error' => 'invalid tournament']); exit;
}

$team = dcz_sanitize_team($data['team'] ?? null);
if ($team === null) {
    http_response_code(400); echo json_encode(['error' => 'invalid team']); exit;
}

$mode = ($data['mode'] ?? 'classic') === 'dynasty' ? 'dynasty' : 'classic';
if ($mode === 'dynasty' && empty($team['club'])) {
    http_response_code(400); echo json_encode(['error' => 'dynasty duel requires a club']); exit;
}

/* Genera ID unico di 8 caratteri */
do {
    $bytes = random_bytes(6);
    $id    = rtrim(strtr(base64_encode($bytes), '+/', 'ab'), '=');
    $id    = substr($id, 0, 8);
    $file  = $duelsDir . $id . '.json';
} while (file_exists($file));

$payload = [
    'id'         => $id,
    'status'     => 'waiting',
    'mode'       => $mode,
    'tournament' => $data['tournament'],
    'era'        => mb_substr((string)($data['era'] ?? ''), 0, 60),
    'eraId'      => preg_replace('/[^a-zA-Z0-9_]/', '', (string)($data['eraId'] ?? '')),
    'lang'       => in_array($data['lang'] ?? 'it', ['it', 'en', 'es']) ? $data['lang'] : 'it',
    'a'          => $team,
    'b'          => null,
    'result'     => null,
    'createdAt'  => date('c'),
    'doneAt'     => null,
];

file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE), LOCK_EX);

echo json_encode([
    'id'  => $id,
    'url' => 'https://decempionz.com/duel.php?id=' . $id,
]);
