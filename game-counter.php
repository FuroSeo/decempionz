<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$file = __DIR__ . '/game-counter.json';
$MIN_TOTAL = 318; // valore minimo di partenza

function dcz_counter_read($file, $minimum) {
    $raw = @file_get_contents($file);
    $d = $raw ? json_decode($raw, true) : null;
    $total = isset($d['total']) ? (int)$d['total'] : $minimum;
    return max($minimum, $total);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['total' => dcz_counter_read($file, $MIN_TOTAL)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Riduce incrementi automatici/doppi-tap senza penalizzare una normale partita.
    $rateDir = sys_get_temp_dir() . '/dcz_counter/';
    @mkdir($rateDir, 0755, true);
    $rateKey = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $rateFile = $rateDir . $rateKey . '.tmp';
    $rateFp = @fopen($rateFile, 'c+');
    if ($rateFp && flock($rateFp, LOCK_EX)) {
        $last = (int)trim(stream_get_contents($rateFp));
        if ($last > 0 && (time() - $last) < 2) {
            flock($rateFp, LOCK_UN);
            fclose($rateFp);
            http_response_code(429);
            echo json_encode(['error' => 'too many requests']);
            exit;
        }
        rewind($rateFp);
        ftruncate($rateFp, 0);
        fwrite($rateFp, (string)time());
        fflush($rateFp);
        flock($rateFp, LOCK_UN);
        fclose($rateFp);
    }

    // Transazione atomica: lettura + incremento + scrittura sotto lo stesso lock.
    $fp = @fopen($file, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) {
        if ($fp) fclose($fp);
        http_response_code(500);
        echo json_encode(['error' => 'io error']);
        exit;
    }

    $raw = stream_get_contents($fp);
    $d = $raw ? json_decode($raw, true) : null;
    $current = isset($d['total']) ? (int)$d['total'] : $MIN_TOTAL;
    $total = max($MIN_TOTAL, $current) + 1;

    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode(['total' => $total]));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    echo json_encode(['total' => $total]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'method not allowed']);
