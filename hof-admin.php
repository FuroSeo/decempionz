<?php
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

$configFile = __DIR__ . '/hof-config.php';
if (!file_exists($configFile)) {
    http_response_code(503);
    die('Configurazione admin mancante.');
}
require $configFile;

$hasPasswordHash = defined('ADMIN_PASSWORD_HASH') && is_string(ADMIN_PASSWORD_HASH) && ADMIN_PASSWORD_HASH !== '';
$hasLegacyPassword = defined('ADMIN_PASSWORD') && is_string(ADMIN_PASSWORD) && ADMIN_PASSWORD !== '';
if (!$hasPasswordHash && !$hasLegacyPassword) {
    http_response_code(503);
    die('Configurazione admin non valida.');
}

$approvedFile = __DIR__ . '/hall-of-fame.json';
$loginRateDir = sys_get_temp_dir() . '/dcz_hof_admin/';
@mkdir($loginRateDir, 0755, true);
$loginRateKey = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
$loginRateFile = $loginRateDir . $loginRateKey . '.json';

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function hof_password_ok(string $password): bool {
    if (defined('ADMIN_PASSWORD_HASH') && is_string(ADMIN_PASSWORD_HASH) && ADMIN_PASSWORD_HASH !== '') {
        return password_verify($password, ADMIN_PASSWORD_HASH);
    }
    if (defined('ADMIN_PASSWORD') && is_string(ADMIN_PASSWORD) && ADMIN_PASSWORD !== '') {
        return hash_equals(ADMIN_PASSWORD, $password);
    }
    return false;
}

function hof_rate_read(string $file): array {
    $fp = @fopen($file, 'c+');
    if (!$fp) return ['fails' => 0, 'first' => 0, 'blocked_until' => 0];
    if (!flock($fp, LOCK_SH)) { fclose($fp); return ['fails' => 0, 'first' => 0, 'blocked_until' => 0]; }
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = $raw !== '' ? json_decode($raw, true) : null;
    return is_array($data) ? $data : ['fails' => 0, 'first' => 0, 'blocked_until' => 0];
}

function hof_rate_remaining(string $file): int {
    $data = hof_rate_read($file);
    $until = (int)($data['blocked_until'] ?? 0);
    return max(0, $until - time());
}

function hof_rate_fail(string $file): int {
    $now = time();
    $window = 15 * 60;
    $maxAttempts = 5;
    $fp = @fopen($file, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) {
        if ($fp) fclose($fp);
        return 0;
    }
    $raw = stream_get_contents($fp);
    $data = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($data)) $data = ['fails' => 0, 'first' => $now, 'blocked_until' => 0];

    if ((int)($data['blocked_until'] ?? 0) > $now) {
        $remaining = (int)$data['blocked_until'] - $now;
    } else {
        $first = (int)($data['first'] ?? 0);
        if ($first === 0 || ($now - $first) > $window) {
            $data = ['fails' => 0, 'first' => $now, 'blocked_until' => 0];
        }
        $data['fails'] = (int)($data['fails'] ?? 0) + 1;
        if ($data['fails'] >= $maxAttempts) {
            $data['blocked_until'] = $now + $window;
        }
        $remaining = max(0, (int)$data['blocked_until'] - $now);
    }

    $encoded = json_encode($data);
    if ($encoded !== false) {
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, $encoded);
        fflush($fp);
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $remaining;
}

function hof_load_entries(string $file, ?string &$error = null): array {
    if (!file_exists($file)) return [];
    $fp = @fopen($file, 'r');
    if (!$fp || !flock($fp, LOCK_SH)) {
        if ($fp) fclose($fp);
        $error = 'Impossibile leggere la Hall of Fame.';
        return [];
    }
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    if (trim($raw) === '') return [];
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['entries']) || !is_array($data['entries'])) {
        $error = 'Archivio Hall of Fame non valido: nessuna modifica è stata eseguita.';
        return [];
    }
    return $data['entries'];
}

function hof_delete_entry(string $file, string $id, ?string &$error = null): bool {
    if (!file_exists($file)) {
        $error = 'Archivio Hall of Fame non trovato.';
        return false;
    }
    $fp = @fopen($file, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) {
        if ($fp) fclose($fp);
        $error = 'Impossibile bloccare l’archivio Hall of Fame.';
        return false;
    }
    $raw = stream_get_contents($fp);
    $data = trim($raw) !== '' ? json_decode($raw, true) : null;
    if (!is_array($data) || !isset($data['entries']) || !is_array($data['entries'])) {
        flock($fp, LOCK_UN);
        fclose($fp);
        $error = 'Archivio Hall of Fame non valido: cancellazione annullata.';
        return false;
    }

    $before = count($data['entries']);
    $data['entries'] = array_values(array_filter(
        $data['entries'],
        fn($entry) => (string)($entry['id'] ?? '') !== $id
    ));
    if (count($data['entries']) === $before) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }

    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        flock($fp, LOCK_UN);
        fclose($fp);
        $error = 'Errore durante la serializzazione dell’archivio.';
        return false;
    }
    rewind($fp);
    ftruncate($fp, 0);
    $written = fwrite($fp, $encoded);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    if ($written === false) {
        $error = 'Errore durante il salvataggio dell’archivio.';
        return false;
    }
    return true;
}

if (!isset($_SESSION['hof_csrf']) || !is_string($_SESSION['hof_csrf'])) {
    $_SESSION['hof_csrf'] = bin2hex(random_bytes(32));
}

// Scadenza della sessione admin dopo 60 minuti di inattività.
if (!empty($_SESSION['hof_admin'])) {
    $lastSeen = (int)($_SESSION['hof_last_seen'] ?? 0);
    if ($lastSeen > 0 && (time() - $lastSeen) > 3600) {
        unset($_SESSION['hof_admin'], $_SESSION['hof_last_seen']);
        session_regenerate_id(true);
        $_SESSION['hof_csrf'] = bin2hex(random_bytes(32));
    } else {
        $_SESSION['hof_last_seen'] = time();
    }
}

$loginError = null;
$adminError = null;
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf'] ?? '');
    if (!hash_equals((string)$_SESSION['hof_csrf'], $csrf)) {
        http_response_code(403);
        $adminError = 'Richiesta non valida o scaduta. Ricarica la pagina e riprova.';
    } else {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'login') {
            $remaining = hof_rate_remaining($loginRateFile);
            if ($remaining > 0) {
                http_response_code(429);
                $loginError = 'Troppi tentativi. Riprova tra circa ' . max(1, (int)ceil($remaining / 60)) . ' minuti.';
            } else {
                $password = (string)($_POST['password'] ?? '');
                if (strlen($password) > 256 || !hof_password_ok($password)) {
                    $remaining = hof_rate_fail($loginRateFile);
                    $loginError = $remaining > 0
                        ? 'Troppi tentativi. Accesso temporaneamente bloccato.'
                        : 'Password errata.';
                } else {
                    @unlink($loginRateFile);
                    session_regenerate_id(true);
                    $_SESSION['hof_admin'] = true;
                    $_SESSION['hof_last_seen'] = time();
                    $_SESSION['hof_csrf'] = bin2hex(random_bytes(32));
                    header('Location: hof-admin.php');
                    exit;
                }
            }
        } elseif ($action === 'logout') {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
            }
            session_destroy();
            header('Location: hof-admin.php');
            exit;
        } elseif (!empty($_SESSION['hof_admin']) && $action === 'delete') {
            $id = preg_replace('/[^a-zA-Z0-9_.-]/', '', (string)($_POST['id'] ?? ''));
            if ($id === '' || strlen($id) > 100) {
                $adminError = 'ID non valido.';
            } else {
                $deleted = hof_delete_entry($approvedFile, $id, $adminError);
                if ($deleted) {
                    header('Location: hof-admin.php?deleted=1');
                    exit;
                }
                if ($adminError === null) $adminError = 'Entry non trovata.';
            }
        }
    }
}

$isAdmin = !empty($_SESSION['hof_admin']);
$approved = [];
if ($isAdmin) {
    $approved = hof_load_entries($approvedFile, $adminError);
    if (isset($_GET['deleted']) && $_GET['deleted'] === '1') $notice = 'Entry eliminata.';
}

$tournLabels = [
    'ucl' => '🏆 UCL',
    'copa' => '🌎 Copa',
    'wc' => '🌍 World Cup',
    'dynasty' => '🏰 Dynasty',
];
$diffLabels = [
    'easy' => '🟢 Facile',
    'normal' => '🟡 Normale',
    'hard' => '🔴 Difficile',
    'legend' => '⚫ Leggenda',
];
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>HoF Admin — Decempionz</title>
<style>
:root{--bg:#070a12;--surface:#0d1320;--text:#e8edf5;--mut:#6b7e95;--gold:#c9a227;--brd:#1e2d42;--red:#ef4444;--green:#22c55e}
*{box-sizing:border-box;margin:0;padding:0}body{background:var(--bg);color:var(--text);font-family:system-ui,sans-serif;font-size:15px;line-height:1.6}.header{background:#0a0f1e;border-bottom:1px solid var(--brd);padding:14px 20px;display:flex;justify-content:space-between;align-items:center}.logo{font-weight:900;letter-spacing:3px;text-transform:uppercase;color:var(--gold)}.container{max-width:900px;margin:0 auto;padding:24px 20px}.login-box{max-width:340px;margin:80px auto;background:var(--surface);border:1px solid var(--brd);border-radius:16px;padding:32px}.login-box h2{margin-bottom:20px;font-size:1.2rem}input[type=password]{width:100%;background:#07090f;border:1px solid var(--brd);border-radius:8px;color:var(--text);padding:10px 14px;font-size:1rem;margin-bottom:12px;outline:none}.btn{display:inline-block;padding:9px 22px;border-radius:8px;border:none;font-weight:700;font-size:.88rem;cursor:pointer}.btn-gold{background:linear-gradient(135deg,#c9a227,#e8c84a);color:#07090f}.btn-ghost{background:transparent;border:1px solid var(--brd);color:var(--mut)}.btn-red{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.25);color:var(--red)}.error{color:var(--red);font-size:.85rem;margin:10px 0}.notice{color:var(--green);font-size:.85rem;margin-bottom:14px}.section-title{font-size:.72rem;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--gold);margin-bottom:14px}.sub{font-size:.78rem;color:var(--mut);margin:-8px 0 18px}.card{background:var(--surface);border:1px solid var(--brd);border-radius:12px;padding:16px;margin-bottom:12px}.card-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;gap:12px}.nickname{font-weight:800}.grade{font-size:1.1rem;font-weight:900}.grade-S{color:#c9a227}.grade-A{color:#16a34a}.grade-B{color:#2451a8}.grade-C{color:#dc2626}.meta{font-size:.75rem;color:var(--mut);margin-bottom:8px}.lineup{font-size:.72rem;color:var(--mut);border-top:1px solid var(--brd);padding-top:8px;margin-top:8px;line-height:1.8}.actions{display:flex;gap:8px;margin-top:12px}.empty{color:var(--mut);font-size:.9rem;padding:20px 0}.count{color:var(--mut);font-size:.8rem}
</style>
</head>
<body>
<div class="header">
  <div class="logo">Decempionz · Admin HoF</div>
  <?php if ($isAdmin): ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h($_SESSION['hof_csrf']) ?>">
    <input type="hidden" name="action" value="logout">
    <button class="btn btn-ghost" type="submit">Esci</button>
  </form>
  <?php endif; ?>
</div>

<?php if (!$isAdmin): ?>
<div class="login-box">
  <h2>🔒 Accesso Admin</h2>
  <?php if ($loginError): ?><p class="error"><?= h($loginError) ?></p><?php endif; ?>
  <?php if ($adminError): ?><p class="error"><?= h($adminError) ?></p><?php endif; ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h($_SESSION['hof_csrf']) ?>">
    <input type="hidden" name="action" value="login">
    <input type="password" name="password" placeholder="Password" maxlength="256" autocomplete="current-password" autofocus required>
    <button class="btn btn-gold" type="submit" style="width:100%">Accedi</button>
  </form>
</div>
<?php else: ?>
<div class="container">
  <div class="section-title">Hall of Fame pubblicata <span class="count">(<?= count($approved) ?>)</span></div>
  <div class="sub">Le submission vengono pubblicate automaticamente. Da questo pannello puoi rimuovere le entry indesiderate.</div>
  <?php if ($notice): ?><p class="notice"><?= h($notice) ?></p><?php endif; ?>
  <?php if ($adminError): ?><p class="error"><?= h($adminError) ?></p><?php endif; ?>

  <?php if (empty($approved)): ?>
    <p class="empty">Nessuna entry pubblicata.</p>
  <?php else: ?>
    <?php foreach (array_reverse($approved) as $entry): ?>
    <div class="card">
      <div class="card-head">
        <div>
          <span class="nickname"><?= h($entry['nickname'] ?? '?') ?></span>
          &nbsp;·&nbsp;
          <span class="grade grade-<?= h($entry['grade'] ?? 'C') ?>"><?= h($entry['grade'] ?? 'C') ?></span>
          &nbsp;·&nbsp;
          <span style="font-size:.8rem;color:var(--mut)"><?= !empty($entry['winner']) ? '🏆 Campione' : '💔 Eliminato' ?></span>
        </div>
        <span style="font-size:.72rem;color:var(--mut)"><?= h($entry['date'] ?? '') ?></span>
      </div>
      <div class="meta">
        <?= h($tournLabels[$entry['tournament'] ?? ''] ?? ($entry['tournament'] ?? '')) ?>
        &nbsp;·&nbsp; <?= h($entry['era'] ?? '') ?>
        &nbsp;·&nbsp; <?= h($entry['formation'] ?? '') ?>
        &nbsp;·&nbsp; <?= h($diffLabels[$entry['difficulty'] ?? ''] ?? ($entry['difficulty'] ?? '')) ?>
        &nbsp;·&nbsp; <?= h($entry['record'] ?? '') ?>
        &nbsp;·&nbsp; Gol: <?= h($entry['goals'] ?? '') ?>
        <?php if (!empty($entry['topScorer'])): ?>&nbsp;·&nbsp; ⚽ <?= h($entry['topScorer']) ?><?php endif; ?>
      </div>
      <?php if (!empty($entry['lineup']) && is_array($entry['lineup'])): ?>
      <div class="lineup">
        <?php foreach (['GK'=>'🧤','DEF'=>'🛡️','MID'=>'⚙️','FWD'=>'⚡'] as $role => $icon): ?>
          <?= $icon ?> <?= h(implode(', ', array_map('strval', (array)($entry['lineup'][$role] ?? [])))) ?><br>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <div class="actions">
        <form method="post" onsubmit="return confirm('Eliminare definitivamente questa entry dalla Hall of Fame?')">
          <input type="hidden" name="csrf" value="<?= h($_SESSION['hof_csrf']) ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= h($entry['id'] ?? '') ?>">
          <button class="btn btn-red" type="submit">🗑 Elimina</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php endif; ?>
</body>
</html>
