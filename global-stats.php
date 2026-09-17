<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: https://decempionz.com');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');
require_once __DIR__ . '/runtime-backup-lib.php';

$file = __DIR__ . '/global-stats.json';

function gs_normalize($d) {
    if (!is_array($d)) $d = [];
    foreach (['ucl','copa','wc','dynasty'] as $t) {
        if (!isset($d['campaigns'][$t]) || !is_array($d['campaigns'][$t])) {
            $d['campaigns'][$t] = ['started'=>0,'won'=>0];
        }
        $d['campaigns'][$t]['started'] = max(0, (int)($d['campaigns'][$t]['started'] ?? 0));
        $d['campaigns'][$t]['won'] = max(0, (int)($d['campaigns'][$t]['won'] ?? 0));
    }
    if (!isset($d['dynasty_clubs']) || !is_array($d['dynasty_clubs'])) $d['dynasty_clubs'] = [];
    if (!isset($d['formations']) || !is_array($d['formations'])) $d['formations'] = [];
    if (!isset($d['grades']) || !is_array($d['grades'])) $d['grades'] = ['S'=>0,'A'=>0,'B'=>0,'C'=>0];
    foreach (['S','A','B','C'] as $g) $d['grades'][$g] = max(0, (int)($d['grades'][$g] ?? 0));
    if (!isset($d['difficulties']) || !is_array($d['difficulties'])) $d['difficulties'] = [];
    foreach (['easy','normal','hard','legend'] as $diff) $d['difficulties'][$diff] = max(0, (int)($d['difficulties'][$diff] ?? 0));
    $d['updated'] = (string)($d['updated'] ?? date('Y-m-d'));
    return $d;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $raw = @file_get_contents($file);
    $d = $raw ? json_decode($raw, true) : [];
    echo json_encode(gs_normalize($d), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $maxBody = 4096;
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
    if (!is_array($data) || !isset($data['event'])) {
        http_response_code(400);
        echo json_encode(['error' => 'bad request']);
        exit;
    }

    $allowedTournaments  = ['ucl','copa','wc','dynasty'];
    $allowedDifficulties = ['easy','normal','hard','legend'];
    $allowedGrades       = ['S','A','B','C'];
    $allowedEvents       = ['campaign_start','campaign_won'];

    $event = in_array($data['event'], $allowedEvents, true) ? $data['event'] : null;
    $tournament = in_array($data['tournament'] ?? '', $allowedTournaments, true) ? $data['tournament'] : null;
    if (!$event || !$tournament) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid event or tournament']);
        exit;
    }

    // Throttle leggero: le statistiche sono telemetria anonima, non un endpoint ad alta frequenza.
    $rateDir = sys_get_temp_dir() . '/dcz_global_stats/';
    @mkdir($rateDir, 0755, true);
    $rateKey = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'x') . '_' . $event . '_' . $tournament);
    $rateFile = $rateDir . $rateKey . '.tmp';
    $rateFp = @fopen($rateFile, 'c+');
    if ($rateFp && flock($rateFp, LOCK_EX)) {
        $last = (int)trim(stream_get_contents($rateFp));
        if ($last > 0 && (time() - $last) < 2) {
            flock($rateFp, LOCK_UN);
            fclose($rateFp);
            http_response_code(429);
            echo json_encode(['error' => 'too many requests']);
            exit;
        }
        rewind($rateFp);
        ftruncate($rateFp, 0);
        fwrite($rateFp, (string)time());
        fflush($rateFp);
        flock($rateFp, LOCK_UN);
        fclose($rateFp);
    }

    $fh = @fopen($file, 'c+');
    if (!$fh || !flock($fh, LOCK_EX)) {
        if ($fh) fclose($fh);
        http_response_code(500);
        echo json_encode(['error'=>'io error']);
        exit;
    }
    $content = stream_get_contents($fh);
    if (trim($content) === '') {
        $d = gs_normalize([]);
    } else {
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            flock($fh, LOCK_UN);
            fclose($fh);
            http_response_code(500);
            echo json_encode(['error' => 'stats storage corrupt']);
            exit;
        }
        $d = gs_normalize($decoded);
    }

    if ($event === 'campaign_start') {
        $d['campaigns'][$tournament]['started']++;
        if ($tournament === 'dynasty' && !empty($data['dynastyClub'])) {
            $club = substr(preg_replace('/[^a-z0-9_]/', '', strtolower((string)$data['dynastyClub'])), 0, 30);
            if ($club) $d['dynasty_clubs'][$club] = max(0, (int)($d['dynasty_clubs'][$club] ?? 0)) + 1;
        }
        $formation = substr(preg_replace('/[^0-9\-]/', '', (string)($data['formation'] ?? '')), 0, 10);
        if ($formation) $d['formations'][$formation] = max(0, (int)($d['formations'][$formation] ?? 0)) + 1;
        $diff = in_array($data['difficulty'] ?? '', $allowedDifficulties, true) ? $data['difficulty'] : null;
        if ($diff) $d['difficulties'][$diff]++;
    }

    if ($event === 'campaign_won') {
        $d['campaigns'][$tournament]['won']++;
        $grade = in_array($data['grade'] ?? '', $allowedGrades, true) ? $data['grade'] : null;
        if ($grade) $d['grades'][$grade]++;
    }

    $d['updated'] = date('Y-m-d');
    $encoded = json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        flock($fh, LOCK_UN);
        fclose($fh);
        http_response_code(500);
        echo json_encode(['error'=>'io error']);
        exit;
    }

    // Snapshot dello stato precedente mentre il lock esclusivo è ancora attivo.
    dcz_backup_snapshot('global-stats', 'main', $content, 30, 90);

    rewind($fh);
    ftruncate($fh, 0);
    $written = fwrite($fh, $encoded);
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    if ($written === false) {
        http_response_code(500);
        echo json_encode(['error'=>'io error']);
        exit;
    }

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method not allowed']);
