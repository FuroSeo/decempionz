<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

$maxBody = 8192;
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBody) {
    http_response_code(413);
    echo json_encode(['error' => 'payload too large']);
    exit;
}
$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > $maxBody) {
    http_response_code(413);
    echo json_encode(['error' => 'payload too large']);
    exit;
}
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid json']);
    exit;
}

$weekId = preg_replace('/[^0-9A-Z\-]/', '', (string)($data['weekId'] ?? ''));
if (!preg_match('/^\d{4}-W(?:0[1-9]|[1-4]\d|5[0-3])$/', $weekId)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid weekId']);
    exit;
}

$isRetro = !empty($data['isRetro']);

// Accetta soltanto settimane realmente configurate; le entry non-retro devono essere nella finestra attiva.
$configFile = __DIR__ . '/challenge-config.json';
$config = null;
if (file_exists($configFile)) {
    $configRaw = @file_get_contents($configFile);
    $config = $configRaw ? json_decode($configRaw, true) : null;
}
if (is_array($config) && isset($config['challenges']) && is_array($config['challenges'])) {
    $challenge = $config['challenges'][$weekId] ?? null;
    if (!is_array($challenge)) {
        http_response_code(400);
        echo json_encode(['error' => 'unknown challenge']);
        exit;
    }
    if (!$isRetro) {
        $today = date('Y-m-d');
        $start = (string)($challenge['dateStart'] ?? '');
        $end = (string)($challenge['dateEnd'] ?? '');
        if ($start === '' || $end === '' || $today < $start || $today > $end) {
            http_response_code(400);
            echo json_encode(['error' => 'challenge not open']);
            exit;
        }
    }
} elseif (!$isRetro) {
    $currentWeekId = date('o') . '-W' . date('W');
    if ($weekId !== $currentWeekId) {
        http_response_code(400);
        echo json_encode(['error' => 'challenge not open']);
        exit;
    }
}

$allowedGrades = ['S', 'A', 'B', 'C'];
$allowedFormations = ['3-4-3','3-5-2','3-6-1','4-1-4-1','4-2-3-1','4-3-3','4-4-2','4-5-1','5-3-2','5-4-1'];
$nick = mb_substr(trim((string)($data['nickname'] ?? '')), 0, 24);
$nick = preg_replace('/[\x00-\x1F\x7F]/u', '', $nick);
if ($nick === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Nickname mancante.']);
    exit;
}
$grade = in_array($data['grade'] ?? '', $allowedGrades, true) ? $data['grade'] : 'C';
$winner = !empty($data['winner']);
$formation = in_array($data['formation'] ?? '', $allowedFormations, true) ? $data['formation'] : '4-3-3';
$record = [
    'w' => max(0, min(30, (int)($data['record']['w'] ?? 0))),
    'd' => max(0, min(30, (int)($data['record']['d'] ?? 0))),
    'l' => max(0, min(30, (int)($data['record']['l'] ?? 0))),
];
$goals = [
    'gf' => max(0, min(99, (int)($data['goals']['gf'] ?? 0))),
    'ga' => max(0, min(99, (int)($data['goals']['ga'] ?? 0))),
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

// Throttle atomico per IP+nickname+settimana: evita burst/doppio invio senza penalizzare CGNAT intere.
$rateDir = sys_get_temp_dir() . '/dcz_challenge/';
@mkdir($rateDir, 0755, true);
$rateKey = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'x') . '_' . $weekId . '_' . mb_strtolower($nick) . ($isRetro ? '_retro' : ''));
$rateFile = $rateDir . $rateKey . '.tmp';
$rateFp = @fopen($rateFile, 'c+');
if (!$rateFp || !flock($rateFp, LOCK_EX)) {
    if ($rateFp) fclose($rateFp);
    http_response_code(500);
    echo json_encode(['error' => 'Errore interno.']);
    exit;
}
$lastSubmit = (int)trim(stream_get_contents($rateFp));
if ($lastSubmit > 0 && (time() - $lastSubmit) < 60) {
    flock($rateFp, LOCK_UN);
    fclose($rateFp);
    http_response_code(429);
    echo json_encode(['error' => 'Hai già inviato un risultato. Aspetta un momento.']);
    exit;
}

$scoresDir = __DIR__ . '/challenge-scores/';
@mkdir($scoresDir, 0755, true);
$scoresFile = $scoresDir . $weekId . '.json';

$fh = @fopen($scoresFile, 'c+');
if (!$fh || !flock($fh, LOCK_EX)) {
    if ($fh) fclose($fh);
    flock($rateFp, LOCK_UN);
    fclose($rateFp);
    http_response_code(500);
    echo json_encode(['error' => 'Errore interno.']);
    exit;
}
$content = stream_get_contents($fh);
if (trim($content) === '') {
    $db = ['entries' => []];
} else {
    $db = json_decode($content, true);
    if (!is_array($db) || !isset($db['entries']) || !is_array($db['entries'])) {
        flock($fh, LOCK_UN);
        fclose($fh);
        flock($rateFp, LOCK_UN);
        fclose($rateFp);
        http_response_code(500);
        echo json_encode(['error' => 'score storage corrupt']);
        exit;
    }
}
if (count($db['entries']) >= 2000) {
    flock($fh, LOCK_UN);
    fclose($fh);
    flock($rateFp, LOCK_UN);
    fclose($rateFp);
    http_response_code(429);
    echo json_encode(['error' => 'classifica piena']);
    exit;
}
$db['entries'][] = $entry;
$encoded = json_encode($db, JSON_UNESCAPED_UNICODE);
if ($encoded === false) {
    flock($fh, LOCK_UN);
    fclose($fh);
    flock($rateFp, LOCK_UN);
    fclose($rateFp);
    http_response_code(500);
    echo json_encode(['error' => 'Errore interno.']);
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
    echo json_encode(['error' => 'Errore interno.']);
    exit;
}

rewind($rateFp);
ftruncate($rateFp, 0);
fwrite($rateFp, (string)time());
fflush($rateFp);
flock($rateFp, LOCK_UN);
fclose($rateFp);

function gradeVal($g) { return ['S'=>4,'A'=>3,'B'=>2,'C'=>1][$g] ?? 0; }
$ranked = array_filter($db['entries'], function($e) { return !($e['isRetro'] ?? false); });
usort($ranked, function($a, $b) {
    $gd = gradeVal($b['grade']) - gradeVal($a['grade']);
    if ($gd !== 0) return $gd;
    $gdA = ($a['goals']['gf'] ?? 0) - ($a['goals']['ga'] ?? 0);
    $gdB = ($b['goals']['gf'] ?? 0) - ($b['goals']['ga'] ?? 0);
    if ($gdB !== $gdA) return $gdB - $gdA;
    if (($b['record']['w'] ?? 0) !== ($a['record']['w'] ?? 0)) return ($b['record']['w'] ?? 0) - ($a['record']['w'] ?? 0);
    return ($b['goals']['gf'] ?? 0) - ($a['goals']['gf'] ?? 0);
});
$position = null;
if (!$isRetro) {
    foreach (array_values($ranked) as $i => $e) {
        if (($e['nickname'] ?? '') === $entry['nickname'] && ($e['grade'] ?? '') === $entry['grade'] && ($e['submittedAt'] ?? '') === $entry['submittedAt']) {
            $position = $i + 1;
            break;
        }
    }
    if ($position === null) $position = count($ranked);
}

echo json_encode(['success' => true, 'position' => $position]);
