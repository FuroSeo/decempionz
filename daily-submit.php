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

// Validazione giorno (YYYY-MM-DD)
$day = preg_replace('/[^0-9\-]/', '', (string)($data['day'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid day']);
    exit;
}
// Accetta oggi, ieri o domani per tollerare i fusi orari tra client e server.
$today = date('Y-m-d');
$yest  = date('Y-m-d', time() - 86400);
$tom   = date('Y-m-d', time() + 86400);
if (!in_array($day, [$today, $yest, $tom], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'day not open']);
    exit;
}

$allowedGrades = ['S', 'A', 'B', 'C'];
$nick = mb_substr(trim((string)($data['nickname'] ?? '')), 0, 24);
$nick = preg_replace('/[\x00-\x1F\x7F]/u', '', $nick);
if ($nick === '') {
    http_response_code(400);
    echo json_encode(['error' => 'missing nickname']);
    exit;
}

$grade  = in_array($data['grade'] ?? '', $allowedGrades, true) ? $data['grade'] : 'C';
$winner = !empty($data['winner']);
$res    = preg_replace('/[^WDL]/', '', (string)($data['res'] ?? ''));
$res    = substr($res, 0, 12);
$num    = max(0, min(99999, (int)($data['num'] ?? 0)));
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

// Rate limit atomico: 1 invio per IP+nickname+giorno nelle 24 ore.
// La combinazione evita di bloccare intere reti mobili CGNAT sulla sola base dell'IP.
$rateDir = sys_get_temp_dir() . '/dcz_daily/';
@mkdir($rateDir, 0755, true);
$rateKey = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'x') . '_daily_' . $day . '_' . mb_strtolower($nick));
$rateFile = $rateDir . $rateKey . '.tmp';
$rateFp = @fopen($rateFile, 'c+');
if (!$rateFp || !flock($rateFp, LOCK_EX)) {
    if ($rateFp) fclose($rateFp);
    http_response_code(500);
    echo json_encode(['error' => 'internal']);
    exit;
}
$lastSubmit = (int)trim(stream_get_contents($rateFp));
if ($lastSubmit > 0 && (time() - $lastSubmit) < 86400) {
    flock($rateFp, LOCK_UN);
    fclose($rateFp);
    http_response_code(429);
    echo json_encode(['error' => 'already submitted']);
    exit;
}

$scoresDir = __DIR__ . '/daily-scores/';
@mkdir($scoresDir, 0755, true);
$scoresFile = $scoresDir . $day . '.json';

$fh = @fopen($scoresFile, 'c+');
if (!$fh || !flock($fh, LOCK_EX)) {
    if ($fh) fclose($fh);
    flock($rateFp, LOCK_UN);
    fclose($rateFp);
    http_response_code(500);
    echo json_encode(['error' => 'internal']);
    exit;
}
$content = stream_get_contents($fh);
if (trim($content) === '') {
    $db = ['entries' => [], 'num' => $num];
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
    echo json_encode(['error' => 'full']);
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
    echo json_encode(['error' => 'internal']);
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
    echo json_encode(['error' => 'internal']);
    exit;
}

// Segna il rate limit solo dopo un salvataggio riuscito, mantenendo il lock fino a qui.
rewind($rateFp);
ftruncate($rateFp, 0);
fwrite($rateFp, (string)time());
fflush($rateFp);
flock($rateFp, LOCK_UN);
fclose($rateFp);

function gradeVal($g) { return ['S'=>4,'A'=>3,'B'=>2,'C'=>1][$g] ?? 0; }
$ranked = $db['entries'];
usort($ranked, function($a, $b) {
    if (($b['winner'] ? 1 : 0) !== ($a['winner'] ? 1 : 0)) return ($b['winner'] ? 1 : 0) - ($a['winner'] ? 1 : 0);
    $gd = gradeVal($b['grade']) - gradeVal($a['grade']);
    if ($gd !== 0) return $gd;
    $gdA = ($a['goals']['gf'] ?? 0) - ($a['goals']['ga'] ?? 0);
    $gdB = ($b['goals']['gf'] ?? 0) - ($b['goals']['ga'] ?? 0);
    if ($gdB !== $gdA) return $gdB - $gdA;
    return ($b['goals']['gf'] ?? 0) - ($a['goals']['gf'] ?? 0);
});
$position = count($ranked);
foreach (array_values($ranked) as $i => $e) {
    if (($e['submittedAt'] ?? '') === $entry['submittedAt'] && ($e['nickname'] ?? '') === $entry['nickname']) {
        $position = $i + 1;
        break;
    }
}

echo json_encode(['success' => true, 'position' => $position, 'count' => count($ranked)]);
