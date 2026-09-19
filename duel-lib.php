<?php
/* duel-lib.php — funzioni condivise degli endpoint duello (non chiamabile direttamente). */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'duel-lib.php') {
    http_response_code(403); exit;
}

function dcz_plain_label($value, $maxLen) {
    $s = mb_substr(trim((string)$value), 0, $maxLen);
    $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s);
    return str_replace(['<', '>'], '', $s);
}

function dcz_duel_js_unescape($value) {
    return str_replace(["\\\\", "\\'"], ["\\", "'"], (string)$value);
}

/* Sanitizza un blocco squadra {nick, formation, tactic, players[11]}.
   Ogni giocatore nuovo deve portare anche teamId: serve al backend per verificare
   nome/posizione/rating contro la rosa storica canonica in game-data.js. */
function dcz_sanitize_team($t) {
    if (!is_array($t)) return null;

    $nick = dcz_plain_label($t['nick'] ?? '', 20);
    $nick = preg_replace('/[<>"\'\\\\\/&;]/', '', $nick);
    if ($nick === '') return null;

    $validFormations = ['3-4-3','3-5-2','3-6-1','4-1-4-1','4-2-3-1','4-3-3','4-4-2','4-5-1','5-3-2','5-4-1'];
    $formation = (string)($t['formation'] ?? '');
    if (!in_array($formation, $validFormations, true)) return null;

    $validTactics = ['attack', 'balanced', 'defend'];
    $tactic = (string)($t['tactic'] ?? '');
    if (!in_array($tactic, $validTactics, true)) return null;

    $validPositions = ['GK','CB','RB','LB','LWB','RWB','SW','DC','DF','CM','CDM','CAM','AM','RM','LM','DM','MF','RW','LW','ST','CF','SS','FW'];
    $rawPlayers = (array)($t['players'] ?? []);
    if (count($rawPlayers) !== 11) return null;

    $players = [];
    $seenNames = [];
    foreach ($rawPlayers as $p) {
        if (!is_array($p)) return null;
        $name = dcz_plain_label($p['n'] ?? '', 30);
        $pos = substr(preg_replace('/[^A-Z]/', '', (string)($p['p'] ?? '')), 0, 5);
        $ratingRaw = $p['r'] ?? null;
        $teamId = preg_replace('/[^A-Za-z0-9_]/', '', (string)($p['teamId'] ?? ''));

        if ($name === '' || $teamId === '' || !in_array($pos, $validPositions, true)) return null;
        if (!is_int($ratingRaw) && !(is_string($ratingRaw) && preg_match('/^\d+$/', $ratingRaw))) return null;
        $rating = (int)$ratingRaw;
        if ($rating < 6 || $rating > 10) return null;

        /* Il draft corrente deduplica per nome: 11 copie dello stesso campione non sono una rosa valida. */
        $nameKey = mb_strtolower($name, 'UTF-8');
        if (isset($seenNames[$nameKey])) return null;
        $seenNames[$nameKey] = true;

        $players[] = ['n' => $name, 'p' => $pos, 'r' => $rating, 'teamId' => $teamId];
    }

    /* Dynasty duel: club opzionale (key dataset + torneo di provenienza + nome visualizzato). */
    $club = preg_replace('/[^a-z0-9_]/', '', (string)($t['club'] ?? ''));
    $tmode = in_array($t['tmode'] ?? '', ['ucl', 'copa', 'wc'], true) ? $t['tmode'] : null;
    $clubName = dcz_plain_label($t['clubName'] ?? '', 30);
    $clubName = preg_replace('/[<>"\'\\\\\/&;]/', '', $clubName);

    return [
        'nick'      => $nick,
        'formation' => $formation,
        'tactic'    => $tactic,
        'players'   => $players,
        'club'      => $club !== '' ? $club : null,
        'tmode'     => $club !== '' ? $tmode : null,
        'clubName'  => $club !== '' ? ($clubName !== '' ? $clubName : $club) : null,
    ];
}

/* Indicizza le rose canoniche senza eseguire game-data.js.
   La chiave canonica è torneo + teamId: lo stesso short id può esistere in famiglie
   diverse (es. atm_1314 in UCL e Copa) senza sovrascrivere la rosa precedente. */
function dcz_duel_dataset_registry($path = null) {
    $path = $path ?: (__DIR__ . '/game-data.js');
    static $cache = [];
    if (array_key_exists($path, $cache)) return $cache[$path];

    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        $cache[$path] = null;
        return null;
    }

    $registry = ['ucl' => [], 'copa' => [], 'wc' => []];
    $mode = null;
    foreach ($lines as $line) {
        if (preg_match('/^const\s+TEAMS\s*=\s*\{/', $line)) {
            $mode = 'ucl';
            continue;
        }
        if (preg_match('/^const\s+COPA_TEAMS\s*=\s*\{/', $line)) {
            $mode = 'copa';
            continue;
        }
        if (preg_match('/^const\s+WC_TEAMS\s*=\s*\{/', $line)) {
            $mode = 'wc';
            continue;
        }
        if ($mode !== null && preg_match('/^\};\s*$/', $line)) {
            $mode = null;
            continue;
        }
        if ($mode === null) continue;

        if (!preg_match('/^\s*([A-Za-z0-9_]+):\{/', $line, $idMatch)) continue;
        if (!preg_match("/\bclub:'([a-z0-9_]+)'/", $line, $clubMatch)) continue;
        if (!preg_match('/\bname:\'((?:\\\\.|[^\'\\\\])*)\'/', $line, $nameMatch)) continue;
        if (!preg_match('/\bcountry:\'((?:\\\\.|[^\'\\\\])*)\'/', $line, $countryMatch)) continue;
        if (!preg_match('/players:\[(.*)\]\},?\s*$/u', $line, $playersMatch)) continue;

        $players = [];
        preg_match_all('/\{n:\'((?:\\\\.|[^\'\\\\])*)\',p:\'([A-Z]+)\',r:(\d+)(?:,nat:\'((?:\\\\.|[^\'\\\\])*)\')?\}/u', $playersMatch[1], $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $player = [
                'n' => dcz_duel_js_unescape($match[1]),
                'p' => $match[2],
                'r' => (int)$match[3],
            ];
            if (isset($match[4]) && $match[4] !== '') $player['nat'] = dcz_duel_js_unescape($match[4]);
            $players[] = $player;
        }
        if (!$players) continue;

        $registry[$mode][$idMatch[1]] = [
            'mode' => $mode,
            'name' => dcz_duel_js_unescape($nameMatch[1]),
            'country' => dcz_duel_js_unescape($countryMatch[1]),
            'club' => $clubMatch[1],
            'players' => $players,
            'raw'  => $line,
        ];
    }

    $count = array_sum(array_map('count', $registry));
    $cache[$path] = $count > 0 ? $registry : null;
    return $cache[$path];
}

function dcz_duel_dataset_source($registry, $teamId, $expectedMode = null) {
    if (!is_array($registry) || $teamId === '') return null;

    if ($expectedMode !== null) {
        return $registry[$expectedMode][$teamId] ?? null;
    }

    $matches = [];
    foreach (['ucl','copa','wc'] as $mode) {
        if (isset($registry[$mode][$teamId])) $matches[] = $registry[$mode][$teamId];
    }
    return count($matches) === 1 ? $matches[0] : null;
}

function dcz_duel_player_dataset_marker($player) {
    $name = str_replace(['\\', "'"], ['\\\\', "\\'"], (string)$player['n']);
    return "{n:'" . $name . "',p:'" . $player['p'] . "',r:" . (int)$player['r'];
}

/* Restituisce solo metadati ricostruiti dalla sorgente canonica. I campi nat/club
   eventualmente inviati dal browser non partecipano mai al calcolo ufficiale. */
function dcz_duel_canonical_player_meta($registry, $player, $expectedMode = null) {
    if (!is_array($player)) return null;
    $source = dcz_duel_dataset_source($registry, (string)($player['teamId'] ?? ''), $expectedMode);
    if (!is_array($source)) return null;

    foreach (($source['players'] ?? []) as $canonical) {
        if (($canonical['n'] ?? null) !== ($player['n'] ?? null)) continue;
        if (($canonical['p'] ?? null) !== ($player['p'] ?? null)) continue;
        if ((int)($canonical['r'] ?? 0) !== (int)($player['r'] ?? 0)) continue;
        return [
            'nat' => (string)($canonical['nat'] ?? ($source['country'] ?? '')),
            'club' => (string)($source['club'] ?? ''),
            'clubName' => (string)($source['name'] ?? ($source['club'] ?? '')),
            'mode' => (string)($source['mode'] ?? ''),
        ];
    }
    return null;
}

/* Verifica che ogni giocatore esista davvero nella teamId dichiarata e che la sorgente
   appartenga al torneo/club consentito. Non prova ancora la sequenza di carte mostrate
   dal browser: quella resta una limitazione documentata del draft client-side. */
function dcz_duel_validate_team_dataset($team, $expectedMode = null, $expectedClub = null, $path = null) {
    if (!is_array($team) || !is_array($team['players'] ?? null) || count($team['players']) !== 11) return false;
    $registry = dcz_duel_dataset_registry($path);
    if (!is_array($registry)) return false;

    foreach ($team['players'] as $player) {
        $teamId = (string)($player['teamId'] ?? '');
        $source = dcz_duel_dataset_source($registry, $teamId, $expectedMode);
        if (!is_array($source)) return false;

        if ($expectedClub !== null && $source['club'] !== $expectedClub) return false;

        if (dcz_duel_canonical_player_meta($registry, $player, $expectedMode) === null) return false;
    }
    return true;
}

/* Valida una sequenza di rigori calcio-per-calcio. */
function dcz_sanitize_pen_seq($seq, $expectedSum) {
    if (!is_array($seq) || count($seq) < 1 || count($seq) > 40) return null;
    $clean = [];
    $sum = 0;
    foreach ($seq as $v) {
        $b = (bool)$v;
        $clean[] = $b;
        if ($b) $sum++;
    }
    if ($sum !== $expectedSum) return null;
    return $clean;
}

function dcz_sanitize_duel_team_metrics($metrics) {
    if (!is_array($metrics)) return null;
    foreach (['score','fit','chemistry','attack','defence'] as $key) {
        if (!isset($metrics[$key]) || !is_numeric($metrics[$key])) return null;
    }
    $score = (int)$metrics['score'];
    $fit = (int)$metrics['fit'];
    $chemistry = (int)$metrics['chemistry'];
    $attack = round((float)$metrics['attack'], 2);
    $defence = round((float)$metrics['defence'], 2);
    if ($score < 0 || $score > 100 || $fit < 0 || $fit > 100 || $chemistry < 0 || $chemistry > 6) return null;
    if ($attack < 0 || $attack > 15 || $defence < 0 || $defence > 15) return null;
    return ['score'=>$score, 'fit'=>$fit, 'chemistry'=>$chemistry, 'attack'=>$attack, 'defence'=>$defence];
}

/* Valida/canonicalizza una serie best-of-3. Usato sui risultati prodotti dal motore server
   e per compatibilità con dati Duel storici; non rende autoritativo un risultato client. */
function dcz_sanitize_result($r) {
    if (!is_array($r) || !is_array($r['matches'] ?? null)) return null;

    $matches = array_slice($r['matches'], 0, 3);
    $n = count($matches);
    if ($n < 2 || $n > 3) return null;

    $winsA = 0;
    $winsB = 0;
    $clean = [];
    foreach ($matches as $idx => $m) {
        if (!is_array($m)) return null;
        $ga = (int)($m['ga'] ?? -1);
        $gb = (int)($m['gb'] ?? -1);
        if ($ga < 0 || $ga > 15 || $gb < 0 || $gb > 15) return null;
        $cm = ['ga' => $ga, 'gb' => $gb, 'pa' => null, 'pb' => null];
        if ($ga === $gb) {
            $pa = (int)($m['pa'] ?? -1);
            $pb = (int)($m['pb'] ?? -1);
            if ($pa < 0 || $pa > 30 || $pb < 0 || $pb > 30 || $pa === $pb) return null;
            $cm['pa'] = $pa;
            $cm['pb'] = $pb;
            if ($pa > $pb) $winsA++; else $winsB++;
            $seqA = dcz_sanitize_pen_seq($m['pSeqA'] ?? null, $pa);
            $seqB = dcz_sanitize_pen_seq($m['pSeqB'] ?? null, $pb);
            if ($seqA !== null && $seqB !== null) {
                $cm['pSeqA'] = $seqA;
                $cm['pSeqB'] = $seqB;
            }
        } else {
            if ($ga > $gb) $winsA++; else $winsB++;
        }
        $clean[] = $cm;

        // Una best-of-3 termina appena uno dei due arriva a 2 vittorie.
        if (max($winsA, $winsB) === 2 && $idx !== $n - 1) return null;
    }

    if (max($winsA, $winsB) !== 2) return null;
    $winner = $winsA > $winsB ? 'a' : 'b';
    if (($r['winner'] ?? '') !== $winner) return null;

    $out = [
        'matches' => $clean,
        'winsA'   => $winsA,
        'winsB'   => $winsB,
        'winner'  => $winner,
    ];
    if (isset($r['teams'])) {
        $metricsA = dcz_sanitize_duel_team_metrics($r['teams']['a'] ?? null);
        $metricsB = dcz_sanitize_duel_team_metrics($r['teams']['b'] ?? null);
        if ($metricsA === null || $metricsB === null) return null;
        $out['teams'] = ['a'=>$metricsA, 'b'=>$metricsB];
    }
    return $out;
}

/* Mantiene al massimo $max file duello sul server. */
function dcz_cleanup_old_duels($duelsDir, $max = 500) {
    $files = glob($duelsDir . '*.json');
    if ($files === false || count($files) <= $max) return;
    usort($files, function ($a, $b) { return filemtime($a) <=> filemtime($b); });
    $excess = count($files) - $max;
    for ($i = 0; $i < $excess; $i++) {
        @unlink($files[$i]);
    }
}
