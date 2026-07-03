<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$day = preg_replace('/[^0-9\-]/', '', $_GET['day'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) $day = date('Y-m-d');

$scoresFile = __DIR__ . '/daily-scores/' . $day . '.json';
if (!file_exists($scoresFile)) {
    echo json_encode(['day' => $day, 'count' => 0, 'entries' => []]);
    exit;
}

$db      = json_decode(file_get_contents($scoresFile), true);
$entries = $db['entries'] ?? [];

function gradeVal($g) { return ['S'=>4,'A'=>3,'B'=>2,'C'=>1][$g]??0; }
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
], JSON_UNESCAPED_UNICODE);
