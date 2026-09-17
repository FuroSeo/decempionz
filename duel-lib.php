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

/* Sanitizza un blocco squadra {nick, formation, tactic, players[11]}.
   La provenienza dal draft resta client-trusted, ma la struttura deve essere compatibile
   con il dataset/engine reale: formazione e tattica valide, 11 giocatori, rating 6..10. */
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

    $validPositions = ['GK','CB','RB','LB','LWB','RWB','SW','DC','DF','CM','CDM','CAM','RM','LM','DM','MF','RW','LW','ST','CF','SS','FW'];
    $rawPlayers = (array)($t['players'] ?? []);
    if (count($rawPlayers) !== 11) return null;

    $players = [];
    foreach ($rawPlayers as $p) {
        if (!is_array($p)) return null;
        $name = dcz_plain_label($p['n'] ?? '', 30);
        $pos = substr(preg_replace('/[^A-Z]/', '', (string)($p['p'] ?? '')), 0, 5);
        $ratingRaw = $p['r'] ?? null;
        if ($name === '' || !in_array($pos, $validPositions, true)) return null;
        if (!is_int($ratingRaw) && !(is_string($ratingRaw) && preg_match('/^\d+$/', $ratingRaw))) return null;
        $rating = (int)$ratingRaw;
        if ($rating < 6 || $rating > 10) return null;
        $players[] = ['n' => $name, 'p' => $pos, 'r' => $rating];
    }

    /* Dynasty duel: club opzionale (key dataset + torneo di provenienza + nome visualizzato) */
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

    return [
        'matches' => $clean,
        'winsA'   => $winsA,
        'winsB'   => $winsB,
        'winner'  => $winner,
    ];
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
