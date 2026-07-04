<?php
/* duel-result.php — chiusura duello in DUE FASI (la rosa di A resta nascosta finché B non committa).
   POST {id, phase:'team',   team:{rosa di B}}   → status waiting→simulating, ritorna le due rose (per la sim sul client di B)
   POST {id, phase:'result', result:{serie bo3}} → status simulating→done (one-shot) */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: https://decempionz.com');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'method not allowed']); exit;
}

/* Rate limit: 1 invio ogni 5 secondi per IP (le due fasi arrivano in sequenza ravvicinata ma >5s: la sim e l'apertura schermata stanno in mezzo; teniamo margine) */
$ip      = md5($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$rateDir = sys_get_temp_dir() . '/dcz_duels/';
@mkdir($rateDir, 0755, true);
$rf = $rateDir . 'r_' . $ip . '.tmp';
if (file_exists($rf) && (time() - filemtime($rf)) < 5) {
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

$id = preg_replace('/[^a-zA-Z0-9]/', '', (string)($data['id'] ?? ''));
if (strlen($id) < 6 || strlen($id) > 12) {
    http_response_code(400); echo json_encode(['error' => 'invalid id']); exit;
}
$file = __DIR__ . '/duels/' . $id . '.json';
if (!file_exists($file)) {
    http_response_code(404); echo json_encode(['error' => 'not found']); exit;
}

$phase = (string)($data['phase'] ?? '');
if (!in_array($phase, ['team', 'result'])) {
    http_response_code(400); echo json_encode(['error' => 'invalid phase']); exit;
}

/* Lock esclusivo per tutta la transazione (anti doppio-join / doppio-risultato) */
$fp = fopen($file, 'c+');
if (!$fp || !flock($fp, LOCK_EX)) {
    http_response_code(500); echo json_encode(['error' => 'lock failed']); exit;
}
$d = json_decode(stream_get_contents($fp), true);
if (!$d) {
    flock($fp, LOCK_UN); fclose($fp);
    http_response_code(500); echo json_encode(['error' => 'corrupt duel']); exit;
}

function dcz_write_and_close($fp, $d) {
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($d, JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

if ($phase === 'team') {
    if (($d['status'] ?? '') !== 'waiting') {
        flock($fp, LOCK_UN); fclose($fp);
        http_response_code(409); echo json_encode(['error' => 'duel not open', 'status' => $d['status'] ?? '?']); exit;
    }
    $team = dcz_sanitize_team($data['team'] ?? null);
    if ($team === null) {
        flock($fp, LOCK_UN); fclose($fp);
        http_response_code(400); echo json_encode(['error' => 'invalid team']); exit;
    }
    if (($d['mode'] ?? 'classic') === 'dynasty' && empty($team['club'])) {
        flock($fp, LOCK_UN); fclose($fp);
        http_response_code(400); echo json_encode(['error' => 'dynasty duel requires a club']); exit;
    }
    $d['status'] = 'simulating';
    $d['b']      = $team;
    dcz_write_and_close($fp, $d);
    /* solo ORA la rosa di A viene rivelata: B è già vincolato alla sua */
    echo json_encode(['ok' => true, 'a' => $d['a'], 'b' => $d['b']], JSON_UNESCAPED_UNICODE);
    exit;
}

/* phase === 'result' */
if (($d['status'] ?? '') !== 'simulating' || !is_array($d['b'] ?? null)) {
    flock($fp, LOCK_UN); fclose($fp);
    http_response_code(409); echo json_encode(['error' => 'duel not simulating', 'status' => $d['status'] ?? '?']); exit;
}
$result = dcz_sanitize_result($data['result'] ?? null);
if ($result === null) {
    flock($fp, LOCK_UN); fclose($fp);
    http_response_code(400); echo json_encode(['error' => 'invalid result']); exit;
}
$d['status'] = 'done';
$d['result'] = $result;
$d['doneAt'] = date('c');
dcz_write_and_close($fp, $d);

echo json_encode([
    'ok'  => true,
    'url' => 'https://decempionz.com/duel.php?id=' . $id,
]);
