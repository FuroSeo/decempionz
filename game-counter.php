<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$file = __DIR__ . '/game-counter.json';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $raw = @file_get_contents($file);
    $d   = $raw ? json_decode($raw, true) : null;
    echo json_encode(['total' => isset($d['total']) ? (int)$d['total'] : 0]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw   = @file_get_contents($file);
    $d     = $raw ? json_decode($raw, true) : null;
    $total = isset($d['total']) ? (int)$d['total'] + 1 : 1;
    @file_put_contents($file, json_encode(['total' => $total]), LOCK_EX);
    echo json_encode(['total' => $total]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method not allowed']);
