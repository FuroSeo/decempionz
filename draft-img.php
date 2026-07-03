<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'method not allowed']); exit;
}

$raw = file_get_contents('php://input');
if (strlen($raw) > 600000) {
    http_response_code(413); echo json_encode(['error' => 'too large']); exit;
}
$data = json_decode($raw, true);
if (!$data) { http_response_code(400); echo json_encode(['error' => 'invalid json']); exit; }

$id = preg_replace('/[^a-zA-Z0-9]/', '', $data['id'] ?? '');
if (strlen($id) < 6 || strlen($id) > 12) {
    http_response_code(400); echo json_encode(['error' => 'invalid id']); exit;
}
$draftsDir = __DIR__ . '/drafts/';
if (!file_exists($draftsDir . $id . '.json')) {
    http_response_code(404); echo json_encode(['error' => 'draft not found']); exit;
}
$imgFile = $draftsDir . $id . '.jpg';
if (file_exists($imgFile)) {
    echo json_encode(['success' => true, 'existing' => true]); exit;
}

$img = (string)($data['img'] ?? '');
if (strpos($img, 'data:image/jpeg;base64,') !== 0) {
    http_response_code(400); echo json_encode(['error' => 'invalid format']); exit;
}
$bin = base64_decode(substr($img, 23), true);
if ($bin === false || strlen($bin) < 1000 || strlen($bin) > 450000) {
    http_response_code(400); echo json_encode(['error' => 'invalid image']); exit;
}
// magic bytes JPEG + verifica dimensioni
if (substr($bin, 0, 3) !== "\xFF\xD8\xFF") {
    http_response_code(400); echo json_encode(['error' => 'not a jpeg']); exit;
}
$info = @getimagesizefromstring($bin);
if (!$info || $info[2] !== IMAGETYPE_JPEG || $info[0] < 200 || $info[0] > 2000 || $info[1] < 200 || $info[1] > 2000) {
    http_response_code(400); echo json_encode(['error' => 'bad dimensions']); exit;
}

file_put_contents($imgFile, $bin, LOCK_EX);
echo json_encode(['success' => true]);
