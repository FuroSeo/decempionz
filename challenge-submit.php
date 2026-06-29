<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid json']);
    exit;
}

// Validazione weekId
$weekId = preg_replace('/[^0-9A-Z\-]/', '', $data['weekId'] ?? '');
if (!preg_match('/^\d{4}-W\d{2}$/', $weekId)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid weekId']);
    exit;
}

// Solo sfide correnti (non retro) entrano in classifica
// Le retro possono entrare liberamente
$isRetro = !empty($data['isRetro']);

// Rate limit: 1 invio per IP per settimana
$ip      = md5(($_SERVER['REMOTE_ADDR'] ?? 'x') . $weekId . ($isRetro ? '_retro' : ''));
$rateDir = sys_get_temp_dir() . '/dcz_challenge/';
@mkdir($rateDir, 0755, true);
$rf = $rateDir . $ip . '.tmp';
if (file_exists($rf) && (time() - filemtime($rf)) < 60) {
    http_response_code(429);
    echo json_encode(['error' => 'Hai già inviato un risultato. Aspetta un momento.']);
    exit;
}
touch($rf);

// Validazioni
$allowedGrades = ['S', 'A', 'B', 'C'];
$nick = mb_substr(trim($data['nickname'] ?? ''), 0, 24);
if (!$nick) {
    http_response_code(400);
    echo json_encode(['error' => 'Nickname mancante.']);
    exit;
}
$grade      = in_array($data['grade'] ?? '', $allowedGrades) ? $data['grade'] : 'C';
$winner     = !empty($data['winner']);
$formation  = preg_replace('/[^0-9\-]/', '', $data['formation'] ?? '4-3-3');
$record     = [
    'w' => (int)($data['record']['w'] ?? 0),
    'd' => (int)($data['record']['d'] ?? 0),
    'l' => (int)($data['record']['l'] ?? 0),
];
$goals      = [
    'gf' => (int)($data['goals']['gf'] ?? 0),
    'ga' => (int)($data['goals']['ga'] ?? 0),
];

$entry = [
    'nickname'    => htmlspecialchars($nick, ENT_QUOTES, 'UTF-8'),
    'grade'       => $grade,
    'winner'      => $winner,
    'record'      => $record,
    'goals'       => $goals,
    'formation'   => $formation,
    'isRetro'     => $isRetro,
    'submittedAt' => date('c'),
];

// Salva in challenge-scores/{weekId}.json
$scoresDir  = __DIR__ . '/challenge-scores/';
@mkdir($scoresDir, 0755, true);
$scoresFile = $scoresDir . $weekId . '.json';

$fh = fopen($scoresFile, 'c+');
if (!$fh) {
    http_response_code(500);
    echo json_encode(['error' => 'Errore interno.']);
    exit;
}
flock($fh, LOCK_EX);
$content = stream_get_contents($fh);
$db      = json_decode($content, true);
if (!$db || !isset($db['entries'])) {
    $db = ['entries' => []];
}
$db['entries'][] = $entry;
ftruncate($fh, 0);
rewind($fh);
fwrite($fh, json_encode($db, JSON_UNESCAPED_UNICODE));
flock($fh, LOCK_UN);
fclose($fh);

// Calcola posizione in classifica (solo entry non-retro per la posizione)
function gradeVal($g) { return ['S'=>4,'A'=>3,'B'=>2,'C'=>1][$g]??0; }
$ranked = array_filter($db['entries'], function($e){ return !($e['isRetro']??false); });
usort($ranked, function($a, $b) {
    $gd = gradeVal($b['grade']) - gradeVal($a['grade']);
    if ($gd !== 0) return $gd;
    $gdA = ($a['goals']['gf']??0) - ($a['goals']['ga']??0);
    $gdB = ($b['goals']['gf']??0) - ($b['goals']['ga']??0);
    if ($gdB !== $gdA) return $gdB - $gdA;
    if ($b['record']['w'] !== $a['record']['w']) return $b['record']['w'] - $a['record']['w'];
    return ($b['goals']['gf']??0) - ($a['goals']['gf']??0);
});
$position = null;
if (!$isRetro) {
    foreach (array_values($ranked) as $i => $e) {
        if ($e['nickname'] === $entry['nickname'] && $e['grade'] === $entry['grade'] && $e['submittedAt'] === $entry['submittedAt']) {
            $position = $i + 1;
            break;
        }
    }
    // Fallback: count + 1
    if ($position === null) $position = count($ranked);
}

echo json_encode(['success' => true, 'position' => $position]);
