<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$configFile = __DIR__ . '/challenge-config.json';
if (!file_exists($configFile)) {
    echo json_encode(['current' => null, 'next' => null, 'past' => [], 'weekId' => null]);
    exit;
}

$raw        = file_get_contents($configFile);
$config     = json_decode($raw, true);
$challenges = $config['challenges'] ?? [];

// Settimana ISO corrente (es. "2026-W28")
$currentWeekId = date('o') . '-W' . date('W');
$today         = date('Y-m-d');

$current  = null;
$next     = null;
$past     = [];

// Ordina per weekId (ISO, quindi ordine lessicografico = cronologico)
ksort($challenges);

$keys     = array_keys($challenges);
$count    = count($keys);
$foundIdx = null;

foreach ($keys as $i => $weekId) {
    $ch = $challenges[$weekId];
    $ch['weekId'] = $weekId;

    if ($weekId === $currentWeekId) {
        $current  = $ch;
        $foundIdx = $i;
    } elseif ($today >= $ch['dateStart'] && $today <= $ch['dateEnd']) {
        // Fallback: match per data se il weekId non coincide esattamente
        $current  = $ch;
        $foundIdx = $i;
    } elseif ($foundIdx !== null && $next === null) {
        // Settimana successiva — teaser parziale (no formation, no difficulty)
        $next = [
            'weekId'      => $weekId,
            'week'        => $ch['week'],
            'tournament'  => $ch['tournament'],
            'eraName'     => $ch['eraName'],
            'dateStart'   => $ch['dateStart'],
            'teaser_it'   => 'Preparati: la prossima sfida arriva ' . date('d M', strtotime($ch['dateStart'])) . '.',
            'teaser_en'   => 'Get ready: next challenge starts ' . date('d M', strtotime($ch['dateStart'])) . '.',
            'teaser_es'   => 'Prepárate: el próximo desafío llega el ' . date('d M', strtotime($ch['dateStart'])) . '.',
        ];
    } elseif ($foundIdx === null && $today > $ch['dateEnd']) {
        // Sfida passata
        $past[] = $ch;
    }
}

// Partecipanti settimana corrente
$participants = 0;
if ($current) {
    $scoresFile = __DIR__ . '/challenge-scores/' . $currentWeekId . '.json';
    if (file_exists($scoresFile)) {
        $sd = json_decode(file_get_contents($scoresFile), true);
        $participants = count($sd['entries'] ?? []);
    }
}

// Passate in ordine inverso (più recente prima)
$past = array_reverse($past);

echo json_encode([
    'weekId'       => $currentWeekId,
    'current'      => $current,
    'next'         => $next,
    'past'         => $past,
    'participants' => $participants,
], JSON_UNESCAPED_UNICODE);
