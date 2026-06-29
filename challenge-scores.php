<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Settimana richiesta (default: corrente)
$requestedWeek = preg_replace('/[^0-9A-Z\-]/', '', $_GET['week'] ?? '');
if (!preg_match('/^\d{4}-W\d{2}$/', $requestedWeek)) {
    $requestedWeek = date('o') . '-W' . date('W');
}

$scoresFile = __DIR__ . '/challenge-scores/' . $requestedWeek . '.json';
if (!file_exists($scoresFile)) {
    echo json_encode(['weekId' => $requestedWeek, 'count' => 0, 'entries' => []]);
    exit;
}

$db      = json_decode(file_get_contents($scoresFile), true);
$entries = $db['entries'] ?? [];

function gradeVal($g) { return ['S'=>4,'A'=>3,'B'=>2,'C'=>1][$g]??0; }

// Ordina: grade > goal diff > wins > gf
usort($entries, function($a, $b) {
    $gd = gradeVal($b['grade']) - gradeVal($a['grade']);
    if ($gd !== 0) return $gd;
    $gdA = ($a['goals']['gf']??0) - ($a['goals']['ga']??0);
    $gdB = ($b['goals']['gf']??0) - ($b['goals']['ga']??0);
    if ($gdB !== $gdA) return $gdB - $gdA;
    if (($b['record']['w']??0) !== ($a['record']['w']??0)) return ($b['record']['w']??0) - ($a['record']['w']??0);
    return ($b['goals']['gf']??0) - ($a['goals']['gf']??0);
});

// Top 50, aggiungi rank
$top = array_slice($entries, 0, 50);
foreach ($top as $i => &$e) {
    $e['rank'] = $i + 1;
    // Stringhe leggibili
    $e['recordStr'] = ($e['record']['w']??0).'V '.($e['record']['d']??0).'P '.($e['record']['l']??0).'S';
    $e['goalsStr']  = ($e['goals']['gf']??0).'-'.($e['goals']['ga']??0);
    $e['goalDiff']  = ($e['goals']['gf']??0) - ($e['goals']['ga']??0);
}
unset($e);

echo json_encode([
    'weekId'  => $requestedWeek,
    'count'   => count($entries),
    'entries' => $top,
], JSON_UNESCAPED_UNICODE);
