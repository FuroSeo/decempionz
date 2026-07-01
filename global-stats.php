<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: https://decempionz.com');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

$file = __DIR__ . '/global-stats.json';

function gs_load($file) {
    $raw = @file_get_contents($file);
    $d   = $raw ? json_decode($raw, true) : null;
    if (!$d) $d = [];
    // ensure structure
    foreach (['ucl','copa','wc','dynasty'] as $t) {
        if (!isset($d['campaigns'][$t])) $d['campaigns'][$t] = ['started'=>0,'won'=>0];
    }
    if (!isset($d['dynasty_clubs']))  $d['dynasty_clubs']  = [];
    if (!isset($d['formations']))     $d['formations']     = [];
    if (!isset($d['grades']))         $d['grades']         = ['S'=>0,'A'=>0,'B'=>0,'C'=>0];
    if (!isset($d['difficulties']))   $d['difficulties']   = ['easy'=>0,'normal'=>0,'hard'=>0];
    if (!isset($d['updated']))        $d['updated']        = date('Y-m-d');
    return $d;
}

function gs_save($file, $d) {
    $d['updated'] = date('Y-m-d');
    @file_put_contents($file, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// ── GET: return aggregated stats ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(gs_load($file));
    exit;
}

// ── POST: record event ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!$data || !isset($data['event'])) {
        http_response_code(400);
        echo json_encode(['error' => 'bad request']);
        exit;
    }

    $allowedTournaments  = ['ucl','copa','wc','dynasty'];
    $allowedDifficulties = ['easy','normal','hard'];
    $allowedGrades       = ['S','A','B','C'];
    $allowedEvents       = ['campaign_start','campaign_won'];

    $event      = in_array($data['event'], $allowedEvents) ? $data['event'] : null;
    $tournament = in_array($data['tournament'] ?? '', $allowedTournaments) ? $data['tournament'] : null;

    if (!$event || !$tournament) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid event or tournament']);
        exit;
    }

    $fh = fopen($file, 'c+');
    if (!$fh) { http_response_code(500); echo json_encode(['error'=>'io error']); exit; }
    flock($fh, LOCK_EX);
    $d = gs_load($file);

    if ($event === 'campaign_start') {
        $d['campaigns'][$tournament]['started']++;

        // Dynasty club
        if ($tournament === 'dynasty' && !empty($data['dynastyClub'])) {
            $club = substr(preg_replace('/[^a-z0-9_]/', '', strtolower($data['dynastyClub'])), 0, 30);
            if ($club) $d['dynasty_clubs'][$club] = ($d['dynasty_clubs'][$club] ?? 0) + 1;
        }

        // Formation
        $formation = substr(preg_replace('/[^0-9\-]/', '', $data['formation'] ?? ''), 0, 10);
        if ($formation) $d['formations'][$formation] = ($d['formations'][$formation] ?? 0) + 1;

        // Difficulty
        $diff = in_array($data['difficulty'] ?? '', $allowedDifficulties) ? $data['difficulty'] : null;
        if ($diff) $d['difficulties'][$diff]++;
    }

    if ($event === 'campaign_won') {
        $d['campaigns'][$tournament]['won']++;

        // Grade
        $grade = in_array($data['grade'] ?? '', $allowedGrades) ? $data['grade'] : null;
        if ($grade) $d['grades'][$grade]++;
    }

    $content = json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    ftruncate($fh, 0); rewind($fh);
    fwrite($fh, $content);
    flock($fh, LOCK_UN);
    fclose($fh);

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method not allowed']);
