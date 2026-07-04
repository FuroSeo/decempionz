<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://decempionz.com');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Metodo non consentito.']);
    exit;
}

// Rate limiting base via sessione
session_start();
if (isset($_SESSION['hof_last']) && time() - $_SESSION['hof_last'] < 120) {
    http_response_code(429);
    echo json_encode(['error' => 'Hai già inviato un risultato di recente. Aspetta qualche minuto.']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Dati non validi.']);
    exit;
}

// Validazione campi obbligatori
$nick = trim($data['nickname'] ?? '');
if (!$nick || strlen($nick) > 24) {
    http_response_code(400);
    echo json_encode(['error' => 'Nickname mancante o troppo lungo (max 24 caratteri).']);
    exit;
}

$allowedGrades = ['S', 'A', 'B', 'C'];
$allowedDiff   = ['easy', 'normal', 'hard', 'legend'];
$allowedTourn  = ['ucl', 'copa', 'wc', 'dynasty'];

function sanitize($val, $maxLen = 50) {
    return htmlspecialchars(substr(trim((string)$val), 0, $maxLen), ENT_QUOTES, 'UTF-8');
}

function sanitizeArr($arr, $maxItems = 5, $maxLen = 40) {
    if (!is_array($arr)) return [];
    return array_slice(
        array_map(function($v) use ($maxLen) { return sanitize($v, $maxLen); }, $arr),
        0, $maxItems
    );
}

$grade      = in_array($data['grade'] ?? '', $allowedGrades) ? $data['grade'] : 'C';
$tournament = in_array($data['tournament'] ?? '', $allowedTourn) ? $data['tournament'] : 'ucl';
$difficulty = in_array($data['difficulty'] ?? '', $allowedDiff) ? $data['difficulty'] : 'normal';

$lineup = $data['lineup'] ?? [];

$entry = [
    'id'         => uniqid('hof_', true),
    'nickname'   => sanitize($nick, 24),
    'grade'      => $grade,
    'winner'     => (bool)($data['winner'] ?? false),
    'tournament'   => $tournament,
    'dynastyClub'  => $tournament === 'dynasty' ? sanitize($data['dynastyClub'] ?? '', 30) : '',
    'era'          => sanitize($data['era'] ?? '', 60),
    'format'     => sanitize($data['format'] ?? '', 30),
    'formation'  => sanitize($data['formation'] ?? '', 10),
    'difficulty' => $difficulty,
    'record'     => sanitize($data['record'] ?? '', 20),
    'goals'      => sanitize($data['goals'] ?? '', 15),
    'topScorer'  => sanitize($data['topScorer'] ?? '', 60),
    'lineup'     => [
        'GK'  => sanitizeArr($lineup['GK'] ?? [], 1),
        'DEF' => sanitizeArr($lineup['DEF'] ?? [], 5),
        'MID' => sanitizeArr($lineup['MID'] ?? [], 5),
        'FWD' => sanitizeArr($lineup['FWD'] ?? [], 3),
    ],
    'date'       => date('Y-m-d'),
];

// Auto-approvazione: scrive direttamente in hall-of-fame.json
// L'admin può eliminare le voci indesiderate dal pannello hof-admin.php
$approvedFile = __DIR__ . '/hall-of-fame.json';

$fh = fopen($approvedFile, 'c+');
if (!$fh) {
    http_response_code(500);
    echo json_encode(['error' => 'Errore interno. Riprova più tardi.']);
    exit;
}

flock($fh, LOCK_EX);
$content = stream_get_contents($fh);
$approved = json_decode($content, true);
if (!$approved || !isset($approved['entries'])) {
    $approved = ['entries' => []];
}

$approved['entries'][] = $entry;

ftruncate($fh, 0);
rewind($fh);
fwrite($fh, json_encode($approved, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
flock($fh, LOCK_UN);
fclose($fh);

$_SESSION['hof_last'] = time();
echo json_encode(['success' => true, 'id' => $entry['id'], 'message' => 'Risultato inviato! Sarà pubblicato dopo approvazione del moderatore.']);
