<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: https://decempionz.com');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$draftsDir = __DIR__ . '/drafts/';
if (!is_dir($draftsDir)) { @mkdir($draftsDir, 0755, true); }

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
    echo file_get_contents($file);
    exit;
}

/* ── POST: salva draft ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rate limit: 1 salvataggio ogni 20 secondi per IP
    $ip      = md5($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $rateDir = sys_get_temp_dir() . '/dcz_drafts/';
    @mkdir($rateDir, 0755, true);
    $rf = $rateDir . $ip . '.tmp';
    if (file_exists($rf) && (time() - filemtime($rf)) < 20) {
        http_response_code(429); echo json_encode(['error' => 'too many requests']); exit;
    }
    touch($rf);

    $raw = file_get_contents('php://input');
    if (strlen($raw) > 25000) {
        http_response_code(413); echo json_encode(['error' => 'payload too large']); exit;
    }
    $data = json_decode($raw, true);
    if (!$data) {
        http_response_code(400); echo json_encode(['error' => 'invalid json']); exit;
    }

    // Validazione campionato
    $validTournaments  = ['ucl', 'copa', 'wc'];
    $validFormats      = ['classic', 'coppa', 'nuovo'];
    $validDifficulties = ['easy', 'normal', 'hard'];
    $validGrades       = ['S', 'A', 'B', 'C'];

    if (!in_array($data['tournament'] ?? '', $validTournaments)) {
        http_response_code(400); echo json_encode(['error' => 'invalid tournament']); exit;
    }

    // Genera ID unico di 8 caratteri
    do {
        $bytes = random_bytes(6);
        $id    = rtrim(strtr(base64_encode($bytes), '+/', 'ab'), '=');
        $id    = substr($id, 0, 8);
        $file  = $draftsDir . $id . '.json';
    } while (file_exists($file));

    // Sanitizza payload
    $payload = [
        'id'         => $id,
        'tournament' => $data['tournament'],
        'era'        => mb_substr((string)($data['era'] ?? ''), 0, 60),
        'eraId'      => preg_replace('/[^a-zA-Z0-9_]/', '', (string)($data['eraId'] ?? '')),
        'format'     => in_array($data['format'] ?? '', $validFormats) ? $data['format'] : 'classic',
        'formation'  => preg_replace('/[^0-9\-]/', '', (string)($data['formation'] ?? '4-3-3')),
        'difficulty' => in_array($data['difficulty'] ?? '', $validDifficulties) ? $data['difficulty'] : 'normal',
        'grade'      => in_array($data['grade'] ?? '', $validGrades) ? $data['grade'] : 'B',
        'winner'     => !empty($data['winner']),
        'record'     => [
            'w' => (int)($data['record']['w'] ?? 0),
            'd' => (int)($data['record']['d'] ?? 0),
            'l' => (int)($data['record']['l'] ?? 0),
        ],
        'goals'      => [
            'gf' => (int)($data['goals']['gf'] ?? 0),
            'ga' => (int)($data['goals']['ga'] ?? 0),
        ],
        'topScorer'  => mb_substr((string)($data['topScorer'] ?? ''), 0, 40),
        'players'    => array_slice(
            array_map(function ($p) {
                return [
                    'n' => mb_substr((string)($p['n'] ?? ''), 0, 30),
                    'p' => preg_replace('/[^A-Z]/', '', (string)($p['p'] ?? '')),
                    'r' => min(10, max(1, (int)($p['r'] ?? 7))),
                ];
            }, (array)($data['players'] ?? [])),
            0, 11
        ),
        'journey'    => array_slice(
            array_map(function ($j) {
                return [
                    'tag'  => mb_substr((string)($j['tag'] ?? ''), 0, 10),
                    'opp'  => mb_substr((string)($j['opp'] ?? ''), 0, 50),
                    'myG'  => (int)($j['myG'] ?? 0),
                    'oppG' => (int)($j['oppG'] ?? 0),
                ];
            }, (array)($data['journey'] ?? [])),
            0, 18
        ),
        'lang'       => in_array($data['lang'] ?? 'it', ['it', 'en', 'es']) ? $data['lang'] : 'it',
        'savedAt'    => date('c'),
    ];

    file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE), LOCK_EX);

    echo json_encode([
        'id'  => $id,
        'url' => 'https://decempionz.com/draft.php?id=' . $id,
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method not allowed']);
