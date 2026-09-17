<?php
/* duel-result.php — chiusura duello in due fasi.
   1) B committa la rosa; il server genera e custodisce la serie autoritativa.
   2) Il client segnala la fine del proprio flusso; il server ignora il risultato client
      e pubblica esclusivamente la serie già generata lato server. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: https://decempionz.com');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'method not allowed']); exit;
}

$maxBody = 15000;
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBody) {
    http_response_code(413); echo json_encode(['error' => 'payload too large']); exit;
}
$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > $maxBody) {
    http_response_code(413); echo json_encode(['error' => 'payload too large']); exit;
}
$data = json_decode($raw, true);
if (!is_array($data)) { http_response_code(400); echo json_encode(['error' => 'invalid json']); exit; }

/* Validare la fase PRIMA di usarla in qualunque path/nome file temporaneo. */
$phase = (string)($data['phase'] ?? '');
if (!in_array($phase, ['team', 'result'], true)) {
    http_response_code(400); echo json_encode(['error' => 'invalid phase']); exit;
}

/* Rate limit atomico: 1 invio ogni 5 secondi per IP, per fase. */
$ip = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
$rateDir = sys_get_temp_dir() . '/dcz_duels/';
@mkdir($rateDir, 0755, true);
$rf = $rateDir . 'r_' . $phase . '_' . $ip . '.tmp';
$rateFp = @fopen($rf, 'c+');
if (!$rateFp || !flock($rateFp, LOCK_EX)) {
    if ($rateFp) fclose($rateFp);
    http_response_code(500); echo json_encode(['error' => 'internal']); exit;
}
$last = (int)trim(stream_get_contents($rateFp));
if ($last > 0 && (time() - $last) < 5) {
    flock($rateFp, LOCK_UN); fclose($rateFp);
    http_response_code(429); echo json_encode(['error' => 'too many requests']); exit;
}
rewind($rateFp);
ftruncate($rateFp, 0);
fwrite($rateFp, (string)time());
fflush($rateFp);
flock($rateFp, LOCK_UN);
fclose($rateFp);

require_once __DIR__ . '/duel-lib.php';
require_once __DIR__ . '/duel-engine.php';
require_once __DIR__ . '/runtime-backup-lib.php';

/* Incrementa game-counter.json con la stessa semantica fail-safe dell'endpoint dedicato. */
function dcz_bump_games_counter() {
    $file = __DIR__ . '/game-counter.json';
    $minimum = 318;
    $fp = @fopen($file, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) {
        if ($fp) fclose($fp);
        return;
    }
    $raw = stream_get_contents($fp);
    if (trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['total']) || !is_numeric($decoded['total'])) {
            flock($fp, LOCK_UN); fclose($fp);
            error_log('Decempionz duel counter skipped: counter storage corrupt.');
            return;
        }
        dcz_backup_snapshot('game-counter', 'main', $raw, 30, 90);
    } else {
        $decoded = [];
    }
    $current = isset($decoded['total']) ? (int)$decoded['total'] : $minimum;
    $total = max($minimum, $current) + 1;
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode(['total' => $total]));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function dcz_duel_generate_authoritative_result($teamA, $teamB) {
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $seed = random_int(1, 0x7ffffffe);
        $result = dcz_duel_simulate_series($teamA, $teamB, $seed);
        $clean = dcz_sanitize_result($result);
        if ($clean !== null) {
            return ['seed' => $seed, 'result' => $clean];
        }
    }
    return null;
}

$id = preg_replace('/[^a-zA-Z0-9]/', '', (string)($data['id'] ?? ''));
if (strlen($id) < 6 || strlen($id) > 12) {
    http_response_code(400); echo json_encode(['error' => 'invalid id']); exit;
}
$file = __DIR__ . '/duels/' . $id . '.json';
if (!file_exists($file)) {
    http_response_code(404); echo json_encode(['error' => 'not found']); exit;
}

/* Lock esclusivo per tutta la transazione (anti doppio-join / doppio-risultato). */
$fp = fopen($file, 'c+');
if (!$fp || !flock($fp, LOCK_EX)) {
    if ($fp) fclose($fp);
    http_response_code(500); echo json_encode(['error' => 'lock failed']); exit;
}
$d = json_decode(stream_get_contents($fp), true);
if (!is_array($d)) {
    flock($fp, LOCK_UN); fclose($fp);
    http_response_code(500); echo json_encode(['error' => 'corrupt duel']); exit;
}

function dcz_write_and_close($fp, $d) {
    $encoded = json_encode($d, JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }
    rewind($fp);
    ftruncate($fp, 0);
    $written = fwrite($fp, $encoded);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $written !== false;
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

    $generated = dcz_duel_generate_authoritative_result($d['a'] ?? null, $team);
    if ($generated === null) {
        flock($fp, LOCK_UN); fclose($fp);
        http_response_code(500); echo json_encode(['error' => 'simulation failed']); exit;
    }

    $d['status'] = 'simulating';
    $d['b'] = $team;
    /* Campi interni: duel-join.php non deve mai esporli al browser. */
    $d['_serverSeed'] = $generated['seed'];
    $d['_serverEngine'] = DCZ_DUEL_ENGINE_VERSION;
    $d['_serverResult'] = $generated['result'];
    if (!dcz_write_and_close($fp, $d)) {
        http_response_code(500); echo json_encode(['error' => 'write failed']); exit;
    }

    /* Solo ora la rosa di A viene rivelata: B è già vincolato alla sua. */
    echo json_encode(['ok' => true, 'a' => $d['a'], 'b' => $d['b']], JSON_UNESCAPED_UNICODE);
    exit;
}

/* phase === 'result'. Il risultato inviato dal client è intenzionalmente ignorato. */
if (($d['status'] ?? '') !== 'simulating' || !is_array($d['b'] ?? null)) {
    flock($fp, LOCK_UN); fclose($fp);
    http_response_code(409); echo json_encode(['error' => 'duel not simulating', 'status' => $d['status'] ?? '?']); exit;
}

$result = dcz_sanitize_result($d['_serverResult'] ?? null);
$engine = (string)($d['_serverEngine'] ?? '');
if ($result === null) {
    /* Migrazione trasparente dei vecchi duelli rimasti in stato simulating. */
    $generated = dcz_duel_generate_authoritative_result($d['a'] ?? null, $d['b']);
    if ($generated === null) {
        flock($fp, LOCK_UN); fclose($fp);
        http_response_code(500); echo json_encode(['error' => 'simulation failed']); exit;
    }
    $result = $generated['result'];
    $engine = DCZ_DUEL_ENGINE_VERSION;
}

$d['status'] = 'done';
$d['result'] = $result;
$d['doneAt'] = date('c');
$d['integrity'] = [
    'result' => 'server-authoritative',
    'engine' => $engine !== '' ? $engine : DCZ_DUEL_ENGINE_VERSION,
    'squad' => 'client-submitted',
];
unset($d['_serverSeed'], $d['_serverEngine'], $d['_serverResult']);
if (!dcz_write_and_close($fp, $d)) {
    http_response_code(500); echo json_encode(['error' => 'write failed']); exit;
}

/* Il duello chiuso conta una sola volta nel contatore globale. */
dcz_bump_games_counter();

/* Compatibilità con il client monolitico attuale: su 409 esso apre subito la pagina canonica.
   Così non riproduce la serie casuale locale, che non è più la fonte autoritativa. */
http_response_code(409);
echo json_encode([
    'ok' => true,
    'finalized' => true,
    'authoritative' => true,
    'url' => 'https://decempionz.com/duel.php?id=' . $id,
]);
