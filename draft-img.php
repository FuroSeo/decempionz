<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/draft-storage-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'method not allowed']); exit;
}

$maxBody = 600000;
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBody) {
    http_response_code(413); echo json_encode(['error' => 'too large']); exit;
}
$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > $maxBody) {
    http_response_code(413); echo json_encode(['error' => 'too large']); exit;
}
$data = json_decode($raw, true);
if (!is_array($data)) { http_response_code(400); echo json_encode(['error' => 'invalid json']); exit; }

// L'upload immagine non deve essere un endpoint ad alta frequenza.
$rateDir = sys_get_temp_dir() . '/dcz_draft_img/';
@mkdir($rateDir, 0755, true);
$rateKey = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
$rateFile = $rateDir . $rateKey . '.tmp';
$rateFp = @fopen($rateFile, 'c+');
if (!$rateFp || !flock($rateFp, LOCK_EX)) {
    if ($rateFp) fclose($rateFp);
    http_response_code(500); echo json_encode(['error' => 'internal']); exit;
}
$last = (int)trim(stream_get_contents($rateFp));
if ($last > 0 && (time() - $last) < 5) {
    flock($rateFp, LOCK_UN); fclose($rateFp);
    http_response_code(429); echo json_encode(['error' => 'too many requests']); exit;
}
rewind($rateFp);
ftruncate($rateFp, 0);
fwrite($rateFp, (string)time());
fflush($rateFp);
flock($rateFp, LOCK_UN);
fclose($rateFp);

$id = preg_replace('/[^a-zA-Z0-9]/', '', (string)($data['id'] ?? ''));
if (strlen($id) < 6 || strlen($id) > 12) {
    http_response_code(400); echo json_encode(['error' => 'invalid id']); exit;
}
$draftsDir = __DIR__ . '/drafts/';
$draftFile = $draftsDir . $id . '.json';
if (!file_exists($draftFile)) {
    http_response_code(404); echo json_encode(['error' => 'draft not found']); exit;
}

// Serializza gli upload per lo stesso draft: il controllo write-once e il consumo
// del grant avvengono sotto lo stesso lock.
$draftFp = @fopen($draftFile, 'r');
if (!$draftFp || !flock($draftFp, LOCK_EX)) {
    if ($draftFp) fclose($draftFp);
    http_response_code(500); echo json_encode(['error' => 'internal']); exit;
}

$imgFile = $draftsDir . $id . '.jpg';
if (file_exists($imgFile)) {
    flock($draftFp, LOCK_UN); fclose($draftFp);
    dcz_draft_clear_upload_cookie();
    echo json_encode(['success' => true, 'existing' => true]); exit;
}

$uploadToken = dcz_draft_cookie_token_for_id($id);
if ($uploadToken === null || !dcz_draft_validate_upload_grant($draftsDir, $id, $uploadToken)) {
    flock($draftFp, LOCK_UN); fclose($draftFp);
    http_response_code(403); echo json_encode(['error' => 'upload not authorized']); exit;
}

$img = (string)($data['img'] ?? '');
if (strpos($img, 'data:image/jpeg;base64,') !== 0) {
    flock($draftFp, LOCK_UN); fclose($draftFp);
    http_response_code(400); echo json_encode(['error' => 'invalid format']); exit;
}
$bin = base64_decode(substr($img, 23), true);
if ($bin === false || strlen($bin) < 1000 || strlen($bin) > 450000) {
    flock($draftFp, LOCK_UN); fclose($draftFp);
    http_response_code(400); echo json_encode(['error' => 'invalid image']); exit;
}
if (substr($bin, 0, 3) !== "\xFF\xD8\xFF") {
    flock($draftFp, LOCK_UN); fclose($draftFp);
    http_response_code(400); echo json_encode(['error' => 'not a jpeg']); exit;
}
$info = @getimagesizefromstring($bin);
if (!$info || $info[2] !== IMAGETYPE_JPEG || $info[0] < 200 || $info[0] > 2000 || $info[1] < 200 || $info[1] > 2000) {
    flock($draftFp, LOCK_UN); fclose($draftFp);
    http_response_code(400); echo json_encode(['error' => 'bad dimensions']); exit;
}

$tmpImg = $imgFile . '.tmp.' . bin2hex(random_bytes(4));
if (file_put_contents($tmpImg, $bin, LOCK_EX) === false || !@rename($tmpImg, $imgFile)) {
    @unlink($tmpImg);
    flock($draftFp, LOCK_UN); fclose($draftFp);
    http_response_code(500); echo json_encode(['error' => 'write failed']); exit;
}

dcz_draft_consume_upload_grant($draftsDir, $id);
dcz_draft_clear_upload_cookie();
flock($draftFp, LOCK_UN);
fclose($draftFp);

echo json_encode(['success' => true]);
