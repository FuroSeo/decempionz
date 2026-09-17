<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: https://decempionz.com');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Metodo non consentito.']);
    exit;
}

session_set_cookie_params([
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

$maxBody = 20000;
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBody) {
    http_response_code(413);
    echo json_encode(['error' => 'Dati troppo grandi.']);
    exit;
}
$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > $maxBody) {
    http_response_code(413);
    echo json_encode(['error' => 'Dati troppo grandi.']);
    exit;
}
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Dati non validi.']);
    exit;
}

$nick = mb_substr(trim((string)($data['nickname'] ?? '')), 0, 24);
$nick = preg_replace('/[\x00-\x1F\x7F]/u', '', $nick);
if ($nick === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Nickname mancante o troppo lungo (max 24 caratteri).']);
    exit;
}

// Doppia barriera: sessione + IP/nickname. La seconda non dipende dai cookie ed evita bypass banali.
if (isset($_SESSION['hof_last']) && time() - (int)$_SESSION['hof_last'] < 120) {
    http_response_code(429);
    echo json_encode(['error' => 'Hai già inviato un risultato di recente. Aspetta qualche minuto.']);
    exit;
}
$rateDir = sys_get_temp_dir() . '/dcz_hof/';
@mkdir($rateDir, 0755, true);
$rateKey = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'x') . '_' . mb_strtolower($nick));
$rateFile = $rateDir . $rateKey . '.tmp';
$rateFp = @fopen($rateFile, 'c+');
if (!$rateFp || !flock($rateFp, LOCK_EX)) {
    if ($rateFp) fclose($rateFp);
    http_response_code(500);
    echo json_encode(['error' => 'Errore interno. Riprova più tardi.']);
    exit;
}
$lastSubmit = (int)trim(stream_get_contents($rateFp));
if ($lastSubmit > 0 && (time() - $lastSubmit) < 120) {
    flock($rateFp, LOCK_UN);
    fclose($rateFp);
    http_response_code(429);
    echo json_encode(['error' => 'Hai già inviato un risultato di recente. Aspetta qualche minuto.']);
    exit;
}

$allowedGrades = ['S', 'A', 'B', 'C'];
$allowedDiff   = ['easy', 'normal', 'hard', 'legend'];
$allowedTourn  = ['ucl', 'copa', 'wc', 'dynasty'];

function sanitize($val, $maxLen = 50) {
    return htmlspecialchars(mb_substr(trim((string)$val), 0, $maxLen), ENT_QUOTES, 'UTF-8');
}

function sanitizeArr($arr, $maxItems = 5, $maxLen = 40) {
    if (!is_array($arr)) return [];
    return array_slice(
        array_map(function($v) use ($maxLen) { return sanitize($v, $maxLen); }, $arr),
        0,
        $maxItems
    );
}

$grade      = in_array($data['grade'] ?? '', $allowedGrades, true) ? $data['grade'] : 'C';
$tournament = in_array($data['tournament'] ?? '', $allowedTourn, true) ? $data['tournament'] : 'ucl';
$difficulty = in_array($data['difficulty'] ?? '', $allowedDiff, true) ? $data['difficulty'] : 'normal';
$lineup = is_array($data['lineup'] ?? null) ? $data['lineup'] : [];

$entry = [
    'id'           => 'hof_' . bin2hex(random_bytes(8)),
    'nickname'     => sanitize($nick, 24),
    'grade'        => $grade,
    'winner'       => (bool)($data['winner'] ?? false),
    'tournament'   => $tournament,
    'dynastyClub'  => $tournament === 'dynasty' ? sanitize($data['dynastyClub'] ?? '', 30) : '',
    'era'          => sanitize($data['era'] ?? '', 60),
    'format'       => sanitize($data['format'] ?? '', 30),
    'formation'    => sanitize($data['formation'] ?? '', 10),
    'difficulty'   => $difficulty,
    'record'       => sanitize($data['record'] ?? '', 20),
    'goals'        => sanitize($data['goals'] ?? '', 15),
    'topScorer'    => sanitize($data['topScorer'] ?? '', 60),
    'lineup'       => [
        'GK'  => sanitizeArr($lineup['GK'] ?? [], 1),
        'DEF' => sanitizeArr($lineup['DEF'] ?? [], 5),
        'MID' => sanitizeArr($lineup['MID'] ?? [], 5),
        'FWD' => sanitizeArr($lineup['FWD'] ?? [], 3),
    ],
    'date'         => date('Y-m-d'),
];

// Il comportamento corrente e' auto-publish; il pannello admin permette la rimozione successiva.
$approvedFile = __DIR__ . '/hall-of-fame.json';
$fh = @fopen($approvedFile, 'c+');
if (!$fh || !flock($fh, LOCK_EX)) {
    if ($fh) fclose($fh);
    flock($rateFp, LOCK_UN);
    fclose($rateFp);
    http_response_code(500);
    echo json_encode(['error' => 'Errore interno. Riprova più tardi.']);
    exit;
}
$content = stream_get_contents($fh);
if (trim($content) === '') {
    $approved = ['entries' => []];
} else {
    $approved = json_decode($content, true);
    if (!is_array($approved) || !isset($approved['entries']) || !is_array($approved['entries'])) {
        flock($fh, LOCK_UN);
        fclose($fh);
        flock($rateFp, LOCK_UN);
        fclose($rateFp);
        http_response_code(500);
        echo json_encode(['error' => 'Archivio Hall of Fame non valido.']);
        exit;
    }
}
if (count($approved['entries']) >= 2000) {
    flock($fh, LOCK_UN);
    fclose($fh);
    flock($rateFp, LOCK_UN);
    fclose($rateFp);
    http_response_code(429);
    echo json_encode(['error' => 'Hall of Fame temporaneamente piena.']);
    exit;
}
$approved['entries'][] = $entry;
$encoded = json_encode($approved, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
if ($encoded === false) {
    flock($fh, LOCK_UN);
    fclose($fh);
    flock($rateFp, LOCK_UN);
    fclose($rateFp);
    http_response_code(500);
    echo json_encode(['error' => 'Errore interno. Riprova più tardi.']);
    exit;
}
rewind($fh);
ftruncate($fh, 0);
$written = fwrite($fh, $encoded);
fflush($fh);
flock($fh, LOCK_UN);
fclose($fh);
if ($written === false) {
    flock($rateFp, LOCK_UN);
    fclose($rateFp);
    http_response_code(500);
    echo json_encode(['error' => 'Errore interno. Riprova più tardi.']);
    exit;
}

rewind($rateFp);
ftruncate($rateFp, 0);
fwrite($rateFp, (string)time());
fflush($rateFp);
flock($rateFp, LOCK_UN);
fclose($rateFp);
$_SESSION['hof_last'] = time();

echo json_encode([
    'success' => true,
    'id' => $entry['id'],
    'message' => 'Risultato pubblicato nella Hall of Fame.',
]);
