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

// Validazione giorno (YYYY-MM-DD)
$day = preg_replace('/[^0-9\-]/', '', $data['day'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid day']);
    exit;
}
// Accetta solo oggi o ieri (fusi orari)
$today = date('Y-m-d');
$yest  = date('Y-m-d', time()-86400);
$tom   = date('Y-m-d', time()+86400);
if (!in_array($day, [$today, $yest, $tom])) {
    http_response_code(400);
    echo json_encode(['error' => 'day not open']);
    exit;
}

$allowedGrades = ['S', 'A', 'B', 'C'];
$nick = mb_substr(trim($data['nickname'] ?? ''), 0, 24);
if (!$nick) {
    http_response_code(400);
    echo json_encode(['error' => 'missing nickname']);
    exit;
}

// Rate limit: 1 invio per IP+nickname per giorno
// (chiave include il nickname perché su reti mobili molti utenti diversi
// condividono lo stesso IP pubblico per via del CGNAT degli operatori:
// una chiave basata solo sull'IP bloccherebbe erroneamente utenti reali
// distinti che giocano dalla stessa rete)
$ip      = md5(($_SERVER['REMOTE_ADDR'] ?? 'x') . '_daily_' . $day . '_' . mb_strtolower($nick));
$rateDir = sys_get_temp_dir() . '/dcz_daily/';
@mkdir($rateDir, 0755, true);
$rf = $rateDir . $ip . '.tmp';
if (file_exists($rf) && (time() - filemtime($rf)) < 86400) {
    http_response_code(429);
    echo json_encode(['error' => 'already submitted']);
    exit;
}
$grade  = in_array($data['grade'] ?? '', $allowedGrades) ? $data['grade'] : 'C';
$winner = !empty($data['winner']);
$res    = preg_replace('/[^WDL]/', '', $data['res'] ?? '');
$res    = substr($res, 0, 12);
$num    = (int)($data['num'] ?? 0);
$goals  = [
    'gf' => max(0, min(99, (int)($data['goals']['gf'] ?? 0))),
    'ga' => max(0, min(99, (int)($data['goals']['ga'] ?? 0))),
];

$entry = [
    'nickname'    => htmlspecialchars($nick, ENT_QUOTES, 'UTF-8'),
    'grade'       => $grade,
    'winner'      => $winner,
    'res'         => $res,
    'goals'       => $goals,
    'submittedAt' => date('c'),
];

$scoresDir  = __DIR__ . '/daily-scores/';
@mkdir($scoresDir, 0755, true);
$scoresFile = $scoresDir . $day . '.json';

$fh = fopen($scoresFile, 'c+');
if (!$fh) {
    http_response_code(500);
    echo json_encode(['error' => 'internal']);
    exit;
}
flock($fh, LOCK_EX);
$content = stream_get_contents($fh);
$db = json_decode($content, true);
if (!$db || !isset($db['entries'])) $db = ['entries' => [], 'num' => $num];
if (count($db['entries']) >= 2000) { flock($fh,LOCK_UN); fclose($fh); http_response_code(429); echo json_encode(['error'=>'full']); exit; }
$db['entries'][] = $entry;
ftruncate($fh, 0);
rewind($fh);
fwrite($fh, json_encode($db, JSON_UNESCAPED_UNICODE));
flock($fh, LOCK_UN);
fclose($fh);
touch($rf);

function gradeVal($g) { return ['S'=>4,'A'=>3,'B'=>2,'C'=>1][$g]??0; }
$ranked = $db['entries'];
usort($ranked, function($a, $b) {
    if (($b['winner']?1:0) !== ($a['winner']?1:0)) return ($b['winner']?1:0) - ($a['winner']?1:0);
    $gd = gradeVal($b['grade']) - gradeVal($a['grade']);
    if ($gd !== 0) return $gd;
    $gdA = ($a['goals']['gf']??0) - ($a['goals']['ga']??0);
    $gdB = ($b['goals']['gf']??0) - ($b['goals']['ga']??0);
    if ($gdB !== $gdA) return $gdB - $gdA;
    return ($b['goals']['gf']??0) - ($a['goals']['gf']??0);
});
$position = count($ranked);
foreach (array_values($ranked) as $i => $e) {
    if ($e['submittedAt'] === $entry['submittedAt'] && $e['nickname'] === $entry['nickname']) { $position = $i + 1; break; }
}

echo json_encode(['success' => true, 'position' => $position, 'count' => count($ranked)]);
