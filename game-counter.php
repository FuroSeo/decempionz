<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://decempionz.com');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

$file = __DIR__ . '/game-counter.json';

function loadCount($file) {
    if (!file_exists($file)) return 0;
    $d = json_decode(file_get_contents($file), true);
    return isset($d['total']) ? (int)$d['total'] : 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['total' => loadCount($file)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fh = fopen($file, 'c+');
    if (!$fh) {
        http_response_code(500);
        echo json_encode(['error' => 'Internal error']);
        exit;
    }
    flock($fh, LOCK_EX);
    $content = stream_get_contents($fh);
    $data = json_decode($content, true);
    $total = isset($data['total']) ? (int)$data['total'] : 0;
    $total++;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode(['total' => $total]));
    flock($fh, LOCK_UN);
    fclose($fh);
    echo json_encode(['total' => $total]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
