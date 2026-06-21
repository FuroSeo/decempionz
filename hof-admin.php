<?php
session_start();

define('ADMIN_PASSWORD', 'Dcmpz2026!');

$pendingFile  = __DIR__ . '/hall-of-fame-pending.json';
$approvedFile = __DIR__ . '/hall-of-fame.json';

function loadJson($file) {
    if (!file_exists($file)) return ['entries' => []];
    $d = json_decode(file_get_contents($file), true);
    return ($d && isset($d['entries'])) ? $d : ['entries' => []];
}

function saveJson($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Azioni
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'login') {
        if ($_POST['password'] === ADMIN_PASSWORD) {
            $_SESSION['hof_admin'] = true;
        } else {
            $loginError = 'Password errata.';
        }
    }

    if ($action === 'logout') {
        session_destroy();
        header('Location: hof-admin.php');
        exit;
    }

    if (!($_SESSION['hof_admin'] ?? false)) goto show_page;

    if ($action === 'approve' && isset($_POST['id'])) {
        $id      = $_POST['id'];
        $pending = loadJson($pendingFile);
        $approved = loadJson($approvedFile);
        foreach ($pending['entries'] as $k => $e) {
            if ($e['id'] === $id) {
                unset($e['pending'], $e['ip_hash']);
                $approved['entries'][] = $e;
                unset($pending['entries'][$k]);
                break;
            }
        }
        $pending['entries'] = array_values($pending['entries']);
        saveJson($pendingFile, $pending);
        saveJson($approvedFile, $approved);
        header('Location: hof-admin.php');
        exit;
    }

    if ($action === 'reject' && isset($_POST['id'])) {
        $id      = $_POST['id'];
        $pending = loadJson($pendingFile);
        $pending['entries'] = array_values(array_filter($pending['entries'], fn($e) => $e['id'] !== $id));
        saveJson($pendingFile, $pending);
        header('Location: hof-admin.php');
        exit;
    }

    if ($action === 'delete' && isset($_POST['id'])) {
        $id       = $_POST['id'];
        $approved = loadJson($approvedFile);
        $approved['entries'] = array_values(array_filter($approved['entries'], fn($e) => ($e['id'] ?? '') !== $id));
        saveJson($approvedFile, $approved);
        header('Location: hof-admin.php#tab-approved');
        exit;
    }
}

show_page:
$isAdmin  = $_SESSION['hof_admin'] ?? false;
$pending  = $isAdmin ? loadJson($pendingFile) : ['entries' => []];
$approved = $isAdmin ? loadJson($approvedFile) : ['entries' => []];
$pendingCount = count($pending['entries']);

$tournLabels = ['ucl' => '🏆 UCL', 'copa' => '🌎 Copa', 'wc' => '🌍 World Cup'];
$diffLabels  = ['easy' => '🟢 Facile', 'normal' => '🟡 Normale', 'hard' => '🔴 Difficile'];
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>HoF Admin — Decempionz</title>
<style>
:root{--bg:#070a12;--surface:#0d1320;--text:#e8edf5;--mut:#6b7e95;--gold:#c9a227;--brd:#1e2d42;--red:#ef4444;--green:#22c55e}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:system-ui,sans-serif;font-size:15px;line-height:1.6}
a{color:var(--gold)}
.header{background:#0a0f1e;border-bottom:1px solid var(--brd);padding:14px 20px;display:flex;justify-content:space-between;align-items:center}
.logo{font-weight:900;letter-spacing:3px;text-transform:uppercase;background:linear-gradient(135deg,var(--gold),#fff 55%,var(--gold));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.container{max-width:900px;margin:0 auto;padding:24px 20px}
.login-box{max-width:340px;margin:80px auto;background:var(--surface);border:1px solid var(--brd);border-radius:16px;padding:32px}
.login-box h2{margin-bottom:20px;font-size:1.2rem}
input[type=password]{width:100%;background:#07090f;border:1px solid var(--brd);border-radius:8px;color:var(--text);padding:10px 14px;font-size:1rem;margin-bottom:12px;outline:none}
input[type=password]:focus{border-color:var(--gold)}
.btn{display:inline-block;padding:9px 22px;border-radius:8px;border:none;font-weight:700;font-size:.88rem;cursor:pointer;transition:opacity .15s}
.btn-gold{background:linear-gradient(135deg,#c9a227,#e8c84a);color:#07090f}
.btn-ghost{background:transparent;border:1px solid var(--brd);color:var(--mut)}
.btn-green{background:rgba(34,197,94,.15);border:1px solid rgba(34,197,94,.3);color:var(--green)}
.btn-red{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.25);color:var(--red)}
.btn:hover{opacity:.82}
.error{color:var(--red);font-size:.85rem;margin-bottom:10px}
.section-title{font-size:.7rem;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--gold);margin-bottom:14px}
.badge{display:inline-block;background:var(--red);color:#fff;font-size:.7rem;font-weight:800;border-radius:10px;padding:1px 7px;margin-left:6px}
.card{background:var(--surface);border:1px solid var(--brd);border-radius:12px;padding:16px;margin-bottom:12px}
.card-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px}
.nickname{font-weight:800;font-size:1rem}
.grade{font-size:1.2rem;font-weight:900}
.grade-S{color:#c9a227}.grade-A{color:#16a34a}.grade-B{color:#2451a8}.grade-C{color:#dc2626}
.meta{font-size:.75rem;color:var(--mut);margin-bottom:8px}
.lineup{font-size:.72rem;color:var(--mut);border-top:1px solid var(--brd);padding-top:8px;margin-top:8px;line-height:1.8}
.actions{display:flex;gap:8px;margin-top:12px}
.empty{color:var(--mut);font-size:.9rem;padding:20px 0}
.tabs{display:flex;gap:0;margin-bottom:24px;border-bottom:1px solid var(--brd)}
.tab{padding:10px 20px;font-size:.85rem;font-weight:700;cursor:pointer;color:var(--mut);border-bottom:2px solid transparent;margin-bottom:-1px}
.tab.active{color:var(--gold);border-bottom-color:var(--gold)}
.tab-content{display:none}.tab-content.active{display:block}
</style>
</head>
<body>
<div class="header">
  <div class="logo">Decempionz · Admin HoF</div>
  <?php if($isAdmin): ?>
  <form method="post" style="margin:0">
    <input type="hidden" name="action" value="logout">
    <button class="btn btn-ghost" type="submit">Esci</button>
  </form>
  <?php endif; ?>
</div>

<?php if(!$isAdmin): ?>
<div class="login-box">
  <h2>🔒 Accesso Admin</h2>
  <?php if(isset($loginError)): ?><p class="error"><?= htmlspecialchars($loginError) ?></p><?php endif; ?>
  <form method="post">
    <input type="hidden" name="action" value="login">
    <input type="password" name="password" placeholder="Password" autofocus>
    <button class="btn btn-gold" type="submit" style="width:100%">Accedi</button>
  </form>
</div>

<?php else: ?>
<div class="container">

  <div class="tabs">
    <div class="tab active" onclick="showTab('pending')">
      In attesa <span class="badge"><?= $pendingCount ?></span>
    </div>
    <div class="tab" onclick="showTab('approved')">Approvate (<?= count($approved['entries']) ?>)</div>
  </div>

  <div class="tab-content active" id="tab-pending">
    <div class="section-title">Entry in attesa di approvazione</div>
    <?php if(empty($pending['entries'])): ?>
      <p class="empty">Nessuna entry in attesa.</p>
    <?php else: ?>
      <?php foreach(array_reverse($pending['entries']) as $e): ?>
      <div class="card">
        <div class="card-head">
          <div>
            <span class="nickname"><?= htmlspecialchars($e['nickname']) ?></span>
            &nbsp;·&nbsp;
            <span class="grade grade-<?= $e['grade'] ?>"><?= $e['grade'] ?></span>
            &nbsp;·&nbsp;
            <span style="font-size:.8rem;color:var(--mut)"><?= $e['winner'] ? '🏆 Campione' : '💔 Eliminato' ?></span>
          </div>
          <span style="font-size:.72rem;color:var(--mut)"><?= htmlspecialchars($e['date']) ?></span>
        </div>
        <div class="meta">
          <?= $tournLabels[$e['tournament']] ?? $e['tournament'] ?>
          &nbsp;·&nbsp; <?= htmlspecialchars($e['era']) ?>
          &nbsp;·&nbsp; <?= htmlspecialchars($e['formation']) ?>
          &nbsp;·&nbsp; <?= $diffLabels[$e['difficulty']] ?? $e['difficulty'] ?>
          &nbsp;·&nbsp; <?= htmlspecialchars($e['record']) ?>
          &nbsp;·&nbsp; Gol: <?= htmlspecialchars($e['goals']) ?>
          &nbsp;·&nbsp; ⚽ <?= htmlspecialchars($e['topScorer']) ?>
        </div>
        <div class="lineup">
          🧤 <?= implode(', ', $e['lineup']['GK'] ?? []) ?><br>
          🛡️ <?= implode(', ', $e['lineup']['DEF'] ?? []) ?><br>
          ⚙️ <?= implode(', ', $e['lineup']['MID'] ?? []) ?><br>
          ⚡ <?= implode(', ', $e['lineup']['FWD'] ?? []) ?>
        </div>
        <div class="actions">
          <form method="post" style="margin:0">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="id" value="<?= htmlspecialchars($e['id']) ?>">
            <button class="btn btn-green" type="submit">✓ Approva</button>
          </form>
          <form method="post" style="margin:0" onsubmit="return confirm('Rifiutare questa entry?')">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="id" value="<?= htmlspecialchars($e['id']) ?>">
            <button class="btn btn-red" type="submit">✕ Rifiuta</button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="tab-content" id="tab-approved">
    <div class="section-title">Entry approvate (<?= count($approved['entries']) ?>)</div>
    <?php if(empty($approved['entries'])): ?>
      <p class="empty">Nessuna entry approvata.</p>
    <?php else: ?>
      <?php foreach(array_reverse($approved['entries']) as $e): ?>
      <div class="card">
        <div class="card-head">
          <div>
            <span class="nickname"><?= htmlspecialchars($e['nickname']) ?></span>
            &nbsp;·&nbsp;
            <span class="grade grade-<?= $e['grade'] ?>"><?= $e['grade'] ?></span>
            &nbsp;·&nbsp;
            <span style="font-size:.8rem;color:var(--mut)"><?= ($e['winner'] ?? false) ? '🏆 Campione' : '💔 Eliminato' ?></span>
          </div>
          <span style="font-size:.72rem;color:var(--mut)"><?= htmlspecialchars($e['date'] ?? '') ?></span>
        </div>
        <div class="meta">
          <?= $tournLabels[$e['tournament'] ?? 'ucl'] ?? '' ?>
          &nbsp;·&nbsp; <?= htmlspecialchars($e['era'] ?? '') ?>
          &nbsp;·&nbsp; <?= htmlspecialchars($e['formation'] ?? '') ?>
          &nbsp;·&nbsp; <?= $diffLabels[$e['difficulty'] ?? 'normal'] ?? '' ?>
          &nbsp;·&nbsp; <?= htmlspecialchars($e['record'] ?? '') ?>
          &nbsp;·&nbsp; Gol: <?= htmlspecialchars($e['goals'] ?? '') ?>
          <?php if(!empty($e['topScorer'])): ?>&nbsp;·&nbsp; ⚽ <?= htmlspecialchars($e['topScorer']) ?><?php endif; ?>
        </div>
        <?php if(!empty($e['lineup'])): ?>
        <div class="lineup">
          🧤 <?= implode(', ', $e['lineup']['GK'] ?? []) ?><br>
          🛡️ <?= implode(', ', $e['lineup']['DEF'] ?? []) ?><br>
          ⚙️ <?= implode(', ', $e['lineup']['MID'] ?? []) ?><br>
          ⚡ <?= implode(', ', $e['lineup']['FWD'] ?? []) ?>
        </div>
        <?php endif; ?>
        <div class="actions">
          <form method="post" style="margin:0" onsubmit="return confirm('Eliminare definitivamente questa entry di <?= htmlspecialchars(addslashes($e['nickname'])) ?>?')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= htmlspecialchars($e['id'] ?? '') ?>">
            <button class="btn btn-red" type="submit">🗑 Elimina</button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div>

<script>
function showTab(name) {
  document.querySelectorAll('.tab').forEach(function(t,i){ t.classList.toggle('active', ['pending','approved'][i]===name); });
  document.querySelectorAll('.tab-content').forEach(function(c){ c.classList.remove('active'); });
  document.getElementById('tab-'+name).classList.add('active');
}
</script>
<?php endif; ?>
</body>
</html>
