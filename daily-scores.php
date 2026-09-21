<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$day = preg_replace('/[^0-9\-]/', '', $_GET['day'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) $day = date('Y-m-d');

$scoresFile = __DIR__ . '/daily-scores/' . $day . '.json';
if (!file_exists($scoresFile)) {
    echo json_encode(['day' => $day, 'count' => 0, 'entries' => [], 'me' => null]);
    exit;
}

$db      = json_decode(file_get_contents($scoresFile), true);
$entries = $db['entries'] ?? [];

function gradeVal($g) { return ['S'=>4,'A'=>3,'B'=>2,'C'=>1][$g]??0; }
// Fascia di risultato: vittoria del torneo prima, poi grade (stessa priorita' dell'ordinamento sotto).
function tierVal($winner, $grade) { return ($winner ? 10 : 0) + gradeVal($grade); }

// Posizione opzionale di un risultato non ancora inviato: ?grade=A&winner=1.
// percentile = quota di giocatori in classifica con una fascia strettamente peggiore.
$me = null;
$qGrade = (string)($_GET['grade'] ?? '');
if (in_array($qGrade, ['S', 'A', 'B', 'C'], true)) {
    $myTier = tierVal(($_GET['winner'] ?? '') === '1', $qGrade);
    $better = $same = $worse = 0;
    foreach ($entries as $e) {
        $t = tierVal(!empty($e['winner']), $e['grade'] ?? '');
        if ($t > $myTier) $better++; elseif ($t === $myTier) $same++; else $worse++;
    }
    $total = count($entries);
    $me = [
        'better'     => $better,
        'same'       => $same,
        'worse'      => $worse,
        'percentile' => $total > 0 ? (int)floor(100 * $worse / $total) : null,
    ];
}
usort($entries, function($a, $b) {
    if (($b['winner']?1:0) !== ($a['winner']?1:0)) return ($b['winner']?1:0) - ($a['winner']?1:0);
    $gd = gradeVal($b['grade']) - gradeVal($a['grade']);
    if ($gd !== 0) return $gd;
    $gdA = ($a['goals']['gf']??0) - ($a['goals']['ga']??0);
    $gdB = ($b['goals']['gf']??0) - ($b['goals']['ga']??0);
    if ($gdB !== $gdA) return $gdB - $gdA;
    return ($b['goals']['gf']??0) - ($a['goals']['gf']??0);
});

echo json_encode([
    'day'     => $day,
    'count'   => count($entries),
    'entries' => array_slice($entries, 0, 50),
    'me'      => $me,
], JSON_UNESCAPED_UNICODE);
