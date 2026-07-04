<?php
/* duel-lib.php — funzioni condivise degli endpoint duello (non chiamabile direttamente). */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'duel-lib.php') {
    http_response_code(403); exit;
}

/* Sanitizza un blocco squadra {nick, formation, tactic, players[11]}.
   Ritorna l'array pulito o null se non valido. */
function dcz_sanitize_team($t) {
    if (!is_array($t)) return null;

    $nick = trim(mb_substr((string)($t['nick'] ?? ''), 0, 20));
    $nick = preg_replace('/[<>"\'\\\\\/&;]/', '', $nick);
    if ($nick === '') return null; // nickname obbligatorio

    $validFormations = ['3-4-3','3-5-2','3-6-1','4-1-4-1','4-2-3-1','4-3-3','4-4-2','4-5-1','5-3-2','5-4-1'];
    $formation = in_array($t['formation'] ?? '', $validFormations) ? $t['formation'] : '4-3-3';

    $validTactics = ['attack', 'balanced', 'defend'];
    $tactic = in_array($t['tactic'] ?? '', $validTactics) ? $t['tactic'] : 'balanced';

    $players = array_slice(
        array_map(function ($p) {
            return [
                'n' => mb_substr((string)($p['n'] ?? ''), 0, 30),
                'p' => preg_replace('/[^A-Z]/', '', (string)($p['p'] ?? '')),
                'r' => min(10, max(1, (int)($p['r'] ?? 7))),
            ];
        }, (array)($t['players'] ?? [])),
        0, 11
    );
    if (count($players) !== 11) return null;

    /* Dynasty duel: club opzionale (key dataset + torneo di provenienza + nome visualizzato) */
    $club     = preg_replace('/[^a-z0-9_]/', '', (string)($t['club'] ?? ''));
    $tmode    = in_array($t['tmode'] ?? '', ['ucl', 'copa', 'wc']) ? $t['tmode'] : null;
    $clubName = trim(mb_substr((string)($t['clubName'] ?? ''), 0, 30));
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

/* Sanitizza il blocco risultato della serie best-of-3 simulata dal client di B.
   Struttura: {matches:[{ga,gb,pa,pb}], winsA, winsB, winner}
   ga/gb = gol nei 90'; pa/pb = rigori (null se non serviti).
   Ritorna l'array pulito o null se incoerente. */
function dcz_sanitize_result($r) {
    if (!is_array($r) || !is_array($r['matches'] ?? null)) return null;

    $matches = array_slice($r['matches'], 0, 3);
    $n = count($matches);
    if ($n < 2 || $n > 3) return null;

    $winsA = 0; $winsB = 0;
    $clean = [];
    foreach ($matches as $m) {
        if (!is_array($m)) return null;
        $ga = (int)($m['ga'] ?? -1);
        $gb = (int)($m['gb'] ?? -1);
        if ($ga < 0 || $ga > 15 || $gb < 0 || $gb > 15) return null;
        $cm = ['ga' => $ga, 'gb' => $gb, 'pa' => null, 'pb' => null];
        if ($ga === $gb) {
            /* pareggio nei 90' → rigori obbligatori */
            $pa = (int)($m['pa'] ?? -1);
            $pb = (int)($m['pb'] ?? -1);
            if ($pa < 0 || $pa > 30 || $pb < 0 || $pb > 30 || $pa === $pb) return null;
            $cm['pa'] = $pa; $cm['pb'] = $pb;
            if ($pa > $pb) $winsA++; else $winsB++;
        } else {
            if ($ga > $gb) $winsA++; else $winsB++;
        }
        $clean[] = $cm;
    }

    /* la serie deve chiudersi esattamente quando qualcuno arriva a 2 */
    if (max($winsA, $winsB) !== 2) return null;
    if ($n === 3 && ($winsA === 2 && $winsB === 0)) return null;
    if ($n === 2 && $winsA === 1 && $winsB === 1) return null;

    $winner = $winsA > $winsB ? 'a' : 'b';
    if (($r['winner'] ?? '') !== $winner) return null;

    return [
        'matches' => $clean,
        'winsA'   => $winsA,
        'winsB'   => $winsB,
        'winner'  => $winner,
    ];
}
