<?php
/* duel-join.php — stato di un duello.
   GET ?id=xxx
   - status 'waiting': ritorna solo i vincoli (torneo, era, nick dello sfidante) — la rosa di A resta nascosta.
   - status 'done': ritorna il duello completo (serve al verdetto per entrambi). */
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

$d = json_decode(file_get_contents($file), true);
if (!$d) { http_response_code(500); echo json_encode(['error' => 'corrupt duel']); exit; }

/* done E simulating: rose visibili (B ha già committato la sua).
   In 'simulating' il client di B può riprendere la sim se si era interrotta. */
if (in_array($d['status'] ?? '', ['done', 'simulating'])) {
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

/* waiting: esporre solo i vincoli, MAI la rosa di A */
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
