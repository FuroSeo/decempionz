<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$file = __DIR__ . '/game-counter.json';
$MIN_TOTAL = 318; // valore minimo di partenza

// Init: se il file non esiste o il totale è inferiore al minimo, imposta il minimo
$_raw_init = @file_get_contents($file);
$_d_init   = $_raw_init ? json_decode($_raw_init, true) : null;
if (!$_d_init || !isset($_d_init['total']) || (int)$_d_init['total'] < $MIN_TOTAL) {
    @file_put_contents($file, json_encode(['total' => $MIN_TOTAL]), LOCK_EX);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $raw = @file_get_contents($file);
    $d   = $raw ? json_decode($raw, true) : null;
    echo json_encode(['total' => isset($d['total']) ? (int)$d['total'] : $MIN_TOTAL]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw   = @file_get_contents($file);
    $d     = $raw ? json_decode($raw, true) : null;
    $total = isset($d['total']) ? (int)$d['total'] + 1 : $MIN_TOTAL + 1;
    @file_put_contents($file, json_encode(['total' => $total]), LOCK_EX);
    echo json_encode(['total' => $total]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method not allowed']);
