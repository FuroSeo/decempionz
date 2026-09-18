<?php
/* duel-result.php — chiusura autoritativa del duello.
   - phase team: B committa una rosa verificata sul dataset; il server genera e salva la serie.
   - phase result: compatibilità con duelli/client storici. Qualunque risultato client è ignorato.
   La serie ufficiale nasce sempre da duel-engine.php. */
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
    $counterRaw = stream_get_contents($fp);
    if (trim($counterRaw) !== '') {
        $decoded = json_decode($counterRaw, true);
        if (!is_array($decoded) || !isset($decoded['total']) || !is_numeric($decoded['total'])) {
            flock($fp, LOCK_UN); fclose($fp);
            error_log('Decempionz duel counter skipped: counter storage corrupt.');
            return;
        }
        dcz_backup_snapshot('game-counter', 'main', $counterRaw, 30, 90);
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
            return ['result' => $clean, 'seed' => $seed];
        }
    }
    return null;
}

function dcz_duel_team_is_dataset_verified($team, $duelMode, $duelTournament) {
    if (!is_array($team)) return false;
    if ($duelMode === 'dynasty') {
        $tmode = $team['tmode'] ?? null;
        $club = $team['club'] ?? null;
        if (!in_array($tmode, ['ucl','copa','wc'], true) || !is_string($club) || $club === '') return false;
        return dcz_duel_validate_team_dataset($team, $tmode, $club);
    }
    return dcz_duel_validate_team_dataset($team, $duelTournament);
}

function dcz_duel_integrity_meta($squadTrust) {
    return [
        'result' => 'server-authoritative',
        'engine' => DCZ_DUEL_ENGINE_VERSION,
        'replay' => 'private-server-seed',
        'squad' => $squadTrust,
        'draftHistory' => 'client-unverified',
    ];
}

function dcz_duel_mark_joined_cookie($id) {
    setcookie('dcz_duel_joined', $id, [
        'expires' => time() + 900,
        'path' => '/duel.php',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function dcz_duel_redirect_response($id, $extra = []) {
    http_response_code(409);
    echo json_encode(array_merge([
        'ok' => true,
        'finalized' => true,
        'authoritative' => true,
        'url' => 'https://decempionz.com/duel.php?id=' . $id,
    ], $extra));
}

function dcz_write_and_close($fp, $duel) {
    $encoded = json_encode($duel, JSON_UNESCAPED_UNICODE);
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

$duelMode = ($d['mode'] ?? 'classic') === 'dynasty' ? 'dynasty' : 'classic';
$duelTournament = in_array($d['tournament'] ?? '', ['ucl','copa','wc'], true) ? $d['tournament'] : null;
if ($duelTournament === null) {
    flock($fp, LOCK_UN); fclose($fp);
    http_response_code(500); echo json_encode(['error' => 'corrupt duel scope']); exit;
}

if ($phase === 'team') {
    if (($d['status'] ?? '') !== 'waiting') {
        $status = $d['status'] ?? '?';
        flock($fp, LOCK_UN); fclose($fp);
        if ($status === 'done') {
            dcz_duel_mark_joined_cookie($id);
            dcz_duel_redirect_response($id, ['status' => 'done']); exit;
        }
        http_response_code(409); echo json_encode(['error' => 'duel not open', 'status' => $status]); exit;
    }

    $team = dcz_sanitize_team($data['team'] ?? null);
    if ($team === null) {
        flock($fp, LOCK_UN); fclose($fp);
        http_response_code(400); echo json_encode(['error' => 'invalid team']); exit;
    }

    if ($duelMode === 'dynasty') {
        if (empty($team['club']) || empty($team['tmode'])) {
            flock($fp, LOCK_UN); fclose($fp);
            http_response_code(400); echo json_encode(['error' => 'dynasty duel requires a club']); exit;
        }
        /* B può scegliere un club di un altro torneo, come previsto dal flusso Dynasty. */
        $teamDatasetOk = dcz_duel_validate_team_dataset($team, $team['tmode'], $team['club']);
    } else {
        $teamDatasetOk = dcz_duel_validate_team_dataset($team, $duelTournament);
    }
    if (!$teamDatasetOk) {
        flock($fp, LOCK_UN); fclose($fp);
        http_response_code(400); echo json_encode(['error' => 'invalid team source']); exit;
    }

    $generated = dcz_duel_generate_authoritative_result($d['a'] ?? null, $team);
    if ($generated === null) {
        flock($fp, LOCK_UN); fclose($fp);
        http_response_code(500); echo json_encode(['error' => 'simulation failed']); exit;
    }

    $aVerified = dcz_duel_team_is_dataset_verified($d['a'] ?? null, $duelMode, $duelTournament);
    $squadTrust = $aVerified ? 'dataset-source-verified' : 'mixed-legacy-a';

    /* Commit atomico: appena B è vincolato alla propria rosa, il server decide e salva il Duel. */
    $d['b'] = $team;
    $d['status'] = 'done';
    $d['result'] = $generated['result'];
    $d['doneAt'] = date('c');
    $d['integrity'] = dcz_duel_integrity_meta($squadTrust);
    $d['_serverSeed'] = $generated['seed'];
    $d['_serverEngine'] = DCZ_DUEL_ENGINE_VERSION;
    if (!dcz_write_and_close($fp, $d)) {
        http_response_code(500); echo json_encode(['error' => 'write failed']); exit;
    }

    dcz_bump_games_counter();
    dcz_duel_mark_joined_cookie($id);

    /* Il client moderno anima direttamente il risultato ufficiale ricevuto qui. */
    echo json_encode([
        'ok' => true,
        'a' => $d['a'],
        'b' => $d['b'],
        'result' => $d['result'],
        'integrity' => $d['integrity'],
        'authoritativeReady' => true,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* phase === result. Il risultato inviato dal client è intenzionalmente ignorato. */
$status = (string)($d['status'] ?? '');
if ($status === 'done') {
    flock($fp, LOCK_UN); fclose($fp);
    dcz_duel_mark_joined_cookie($id);
    dcz_duel_redirect_response($id, ['status' => 'done']);
    exit;
}

/* Migrazione trasparente dei vecchi duelli rimasti in stato simulating. */
if ($status !== 'simulating' || !is_array($d['b'] ?? null)) {
    flock($fp, LOCK_UN); fclose($fp);
    http_response_code(409); echo json_encode(['error' => 'duel not simulating', 'status' => $status ?: '?']); exit;
}

$generated = dcz_duel_generate_authoritative_result($d['a'] ?? null, $d['b']);
if ($generated === null) {
    flock($fp, LOCK_UN); fclose($fp);
    http_response_code(500); echo json_encode(['error' => 'simulation failed']); exit;
}
$aVerified = dcz_duel_team_is_dataset_verified($d['a'] ?? null, $duelMode, $duelTournament);
$bVerified = dcz_duel_team_is_dataset_verified($d['b'] ?? null, $duelMode, $duelTournament);
$squadTrust = ($aVerified && $bVerified) ? 'dataset-source-verified' : 'legacy-client-submitted';

$d['status'] = 'done';
$d['result'] = $generated['result'];
$d['doneAt'] = date('c');
$d['integrity'] = dcz_duel_integrity_meta($squadTrust);
$d['_serverSeed'] = $generated['seed'];
$d['_serverEngine'] = DCZ_DUEL_ENGINE_VERSION;
if (!dcz_write_and_close($fp, $d)) {
    http_response_code(500); echo json_encode(['error' => 'write failed']); exit;
}
dcz_bump_games_counter();
dcz_duel_mark_joined_cookie($id);

/* I client che arrivano da uno stato storico simulating vanno al verdetto canonico. */
dcz_duel_redirect_response($id, ['status' => 'done', 'migrated' => true]);
