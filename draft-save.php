<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: https://decempionz.com');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$draftsDir = __DIR__ . '/drafts/';
if (!is_dir($draftsDir)) { @mkdir($draftsDir, 0755, true); }

function dcz_plain_text($value, $maxLen) {
    $s = mb_substr(trim((string)$value), 0, $maxLen);
    $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s);
    return str_replace(['<', '>'], '', $s);
}

function dcz_sanitize_draft_for_output($d) {
    if (!is_array($d)) return null;
    $d['era'] = dcz_plain_text($d['era'] ?? '', 60);
    $d['topScorer'] = dcz_plain_text($d['topScorer'] ?? '', 40);
    if (isset($d['players']) && is_array($d['players'])) {
        foreach ($d['players'] as &$p) {
            if (!is_array($p)) $p = [];
            $p['n'] = dcz_plain_text($p['n'] ?? '', 30);
            $p['p'] = preg_replace('/[^A-Z]/', '', (string)($p['p'] ?? ''));
            $p['r'] = min(10, max(1, (int)($p['r'] ?? 7)));
        }
        unset($p);
    }
    if (isset($d['journey']) && is_array($d['journey'])) {
        foreach ($d['journey'] as &$j) {
            if (!is_array($j)) $j = [];
            $j['tag'] = dcz_plain_text($j['tag'] ?? '', 10);
            $j['opp'] = dcz_plain_text($j['opp'] ?? '', 50);
            $j['myG'] = max(0, min(30, (int)($j['myG'] ?? 0)));
            $j['oppG'] = max(0, min(30, (int)($j['oppG'] ?? 0)));
        }
        unset($j);
    }
    return $d;
}

/* ── GET: recupera draft ── */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['id'] ?? '');
    if (strlen($id) < 6 || strlen($id) > 12) {
        http_response_code(400); echo json_encode(['error' => 'invalid id']); exit;
    }
    $file = $draftsDir . $id . '.json';
    if (!file_exists($file)) {
        http_response_code(404); echo json_encode(['error' => 'not found']); exit;
    }
    $stored = json_decode((string)@file_get_contents($file), true);
    $safe = dcz_sanitize_draft_for_output($stored);
    if ($safe === null) {
        http_response_code(500); echo json_encode(['error' => 'corrupt draft']); exit;
    }
    echo json_encode($safe, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ── POST: salva draft ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rate limit: 1 salvataggio ogni 20 secondi per IP
    $ip = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $rateDir = sys_get_temp_dir() . '/dcz_drafts/';
    @mkdir($rateDir, 0755, true);
    $rf = $rateDir . $ip . '.tmp';
    if (file_exists($rf) && (time() - filemtime($rf)) < 20) {
        http_response_code(429); echo json_encode(['error' => 'too many requests']); exit;
    }

    $maxBody = 25000;
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBody) {
        http_response_code(413); echo json_encode(['error' => 'payload too large']); exit;
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > $maxBody) {
        http_response_code(413); echo json_encode(['error' => 'payload too large']); exit;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_response_code(400); echo json_encode(['error' => 'invalid json']); exit;
    }

    $validTournaments  = ['ucl', 'copa', 'wc'];
    $validFormats      = ['classic', 'coppa', 'nuovo'];
    $validDifficulties = ['easy', 'normal', 'hard', 'legend'];
    $validGrades       = ['S', 'A', 'B', 'C'];
    $validFormations   = ['3-4-3','3-5-2','3-6-1','4-1-4-1','4-2-3-1','4-3-3','4-4-2','4-5-1','5-3-2','5-4-1'];

    if (!in_array($data['tournament'] ?? '', $validTournaments, true)) {
        http_response_code(400); echo json_encode(['error' => 'invalid tournament']); exit;
    }

    do {
        $bytes = random_bytes(6);
        $id = rtrim(strtr(base64_encode($bytes), '+/', 'ab'), '=');
        $id = substr($id, 0, 8);
        $file = $draftsDir . $id . '.json';
    } while (file_exists($file));

    $payload = [
        'id'         => $id,
        'tournament' => $data['tournament'],
        'era'        => dcz_plain_text($data['era'] ?? '', 60),
        'eraId'      => preg_replace('/[^a-zA-Z0-9_]/', '', (string)($data['eraId'] ?? '')),
        'format'     => in_array($data['format'] ?? '', $validFormats, true) ? $data['format'] : 'classic',
        'formation'  => in_array($data['formation'] ?? '', $validFormations, true) ? $data['formation'] : '4-3-3',
        'difficulty' => in_array($data['difficulty'] ?? '', $validDifficulties, true) ? $data['difficulty'] : 'normal',
        'grade'      => in_array($data['grade'] ?? '', $validGrades, true) ? $data['grade'] : 'B',
        'winner'     => !empty($data['winner']),
        'record'     => [
            'w' => max(0, min(30, (int)($data['record']['w'] ?? 0))),
            'd' => max(0, min(30, (int)($data['record']['d'] ?? 0))),
            'l' => max(0, min(30, (int)($data['record']['l'] ?? 0))),
        ],
        'goals'      => [
            'gf' => max(0, min(99, (int)($data['goals']['gf'] ?? 0))),
            'ga' => max(0, min(99, (int)($data['goals']['ga'] ?? 0))),
        ],
        'topScorer'  => dcz_plain_text($data['topScorer'] ?? '', 40),
        'players'    => array_slice(
            array_map(function ($p) {
                return [
                    'n' => dcz_plain_text($p['n'] ?? '', 30),
                    'p' => preg_replace('/[^A-Z]/', '', (string)($p['p'] ?? '')),
                    'r' => min(10, max(1, (int)($p['r'] ?? 7))),
                ];
            }, (array)($data['players'] ?? [])),
            0, 11
        ),
        'journey'    => array_slice(
            array_map(function ($j) {
                return [
                    'tag'  => dcz_plain_text($j['tag'] ?? '', 10),
                    'opp'  => dcz_plain_text($j['opp'] ?? '', 50),
                    'myG'  => max(0, min(30, (int)($j['myG'] ?? 0))),
                    'oppG' => max(0, min(30, (int)($j['oppG'] ?? 0))),
                ];
            }, (array)($data['journey'] ?? [])),
            0, 18
        ),
        'lang'       => in_array($data['lang'] ?? 'it', ['it', 'en', 'es'], true) ? $data['lang'] : 'it',
        'savedAt'    => date('c'),
    ];

    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($encoded === false || file_put_contents($file, $encoded, LOCK_EX) === false) {
        http_response_code(500); echo json_encode(['error' => 'write failed']); exit;
    }
    touch($rf);

    echo json_encode([
        'id'  => $id,
        'url' => 'https://decempionz.com/draft.php?id=' . $id,
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method not allowed']);
