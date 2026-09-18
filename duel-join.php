<?php
/* duel-join.php — stato pubblico di un duello.
   I campi interni usati dal motore autoritativo (_server*) non vengono mai serializzati. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: https://decempionz.com');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405); echo json_encode(['error' => 'method not allowed']); exit;
}

$id = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['id'] ?? '');
if (strlen($id) < 6 || strlen($id) > 12) {
    http_response_code(400); echo json_encode(['error' => 'invalid id']); exit;
}
$file = __DIR__ . '/duels/' . $id . '.json';
if (!file_exists($file)) {
    http_response_code(404); echo json_encode(['error' => 'not found']); exit;
}

$d = json_decode((string)file_get_contents($file), true);
if (!is_array($d)) { http_response_code(500); echo json_encode(['error' => 'corrupt duel']); exit; }
$status = (string)($d['status'] ?? 'waiting');

/* 'simulating' esiste solo per compatibilità con record storici interrotti. I nuovi duelli
   passano da waiting a done nella stessa transazione che accetta la rosa di B. */
if ($status === 'simulating') {
    echo json_encode([
        'id'         => $d['id'],
        'status'     => 'simulating',
        'mode'       => $d['mode'] ?? 'classic',
        'tournament' => $d['tournament'],
        'era'        => $d['era'],
        'eraId'      => $d['eraId'],
        'lang'       => $d['lang'],
        'a'          => $d['a'],
        'b'          => $d['b'],
        'result'     => null,
        'createdAt'  => $d['createdAt'],
        'doneAt'     => null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($status === 'done') {
    echo json_encode([
        'id'         => $d['id'],
        'status'     => 'done',
        'mode'       => $d['mode'] ?? 'classic',
        'tournament' => $d['tournament'],
        'era'        => $d['era'],
        'eraId'      => $d['eraId'],
        'lang'       => $d['lang'],
        'a'          => $d['a'],
        'b'          => $d['b'],
        'result'     => $d['result'] ?? null,
        'createdAt'  => $d['createdAt'],
        'doneAt'     => $d['doneAt'] ?? null,
        'integrity'  => $d['integrity'] ?? [
            'result' => 'legacy-client-reported',
            'engine' => null,
            'squad' => 'legacy-client-submitted',
            'draftHistory' => 'legacy-unknown',
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* waiting: esporre solo i vincoli, MAI la rosa di A. */
echo json_encode([
    'id'         => $d['id'],
    'status'     => 'waiting',
    'mode'       => $d['mode'] ?? 'classic',
    'tournament' => $d['tournament'],
    'era'        => $d['era'],
    'eraId'      => $d['eraId'],
    'lang'       => $d['lang'],
    'a'          => [
        'nick'     => $d['a']['nick'] ?? '?',
        'club'     => $d['a']['club'] ?? null,
        'tmode'    => $d['a']['tmode'] ?? null,
        'clubName' => $d['a']['clubName'] ?? null,
    ],
    'createdAt'  => $d['createdAt'],
], JSON_UNESCAPED_UNICODE);
