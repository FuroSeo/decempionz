<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$id = preg_replace('/[^a-zA-Z0-9_\.]/', '', $_GET['id'] ?? '');
if (!$id) {
    echo json_encode(['found' => false]);
    exit;
}

$file = __DIR__ . '/hall-of-fame.json';
if (!file_exists($file)) {
    echo json_encode(['found' => false]);
    exit;
}

$content = @file_get_contents($file);
$data    = json_decode($content, true);

if (!$data || !isset($data['entries'])) {
    echo json_encode(['found' => false]);
    exit;
}

foreach ($data['entries'] as $entry) {
    if (($entry['id'] ?? '') === $id) {
        echo json_encode(['found' => true]);
        exit;
    }
}

echo json_encode(['found' => false]);
