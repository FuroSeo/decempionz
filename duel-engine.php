<?php
/* duel-engine.php — motore autoritativo server-side per Duel 1v1.
   Replica i coefficienti del simulatore Duel browser, ma usa un RNG deterministico
   seedato dal server. Non è un endpoint HTTP. */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'duel-engine.php') {
    http_response_code(403); exit;
}

const DCZ_DUEL_ENGINE_VERSION = 'server-v1';

function dcz_duel_form_positions($formation, $tactic) {
    static $formations = [
        '4-3-3' => [
            'attack'   => ['GK','LB','CB','CB','RB','CM','CAM','CM','LW','ST','RW'],
            'balanced' => ['GK','LB','CB','CB','RB','CM','CM','CM','LW','ST','RW'],
            'defend'   => ['GK','LB','CB','CB','RB','CDM','CM','CM','LM','ST','RM'],
        ],
        '4-4-2' => [
            'attack'   => ['GK','LB','CB','CB','RB','LW','CM','CM','RW','ST','ST'],
            'balanced' => ['GK','LB','CB','CB','RB','LM','CM','CM','RM','ST','ST'],
            'defend'   => ['GK','LB','CB','CB','RB','LM','CDM','CM','RM','ST','ST'],
        ],
        '4-2-3-1' => [
            'attack'   => ['GK','LB','CB','CB','RB','CM','CDM','LW','CAM','RW','ST'],
            'balanced' => ['GK','LB','CB','CB','RB','CDM','CDM','LW','CAM','RW','ST'],
            'defend'   => ['GK','LB','CB','CB','RB','CDM','CDM','LM','CM','RM','ST'],
        ],
        '4-1-4-1' => [
            'attack'   => ['GK','LB','CB','CB','RB','CDM','LW','CM','CM','RW','ST'],
            'balanced' => ['GK','LB','CB','CB','RB','CDM','LM','CM','CM','RM','ST'],
            'defend'   => ['GK','LB','CB','CB','RB','CDM','CDM','LM','CM','RM','ST'],
        ],
        '4-5-1' => [
            'attack'   => ['GK','LB','CB','CB','RB','LW','CM','CM','CM','RW','ST'],
            'balanced' => ['GK','LB','CB','CB','RB','LM','CM','CDM','CM','RM','ST'],
            'defend'   => ['GK','LB','CB','CB','RB','LM','CDM','CDM','CM','RM','ST'],
        ],
        '3-4-3' => [
            'attack'   => ['GK','CB','CB','CB','LM','CM','CAM','RM','LW','ST','RW'],
            'balanced' => ['GK','CB','CB','CB','LM','CM','CM','RM','LW','ST','RW'],
            'defend'   => ['GK','CB','CB','CB','LM','CDM','CM','RM','LW','ST','RW'],
        ],
        '3-5-2' => [
            'attack'   => ['GK','CB','CB','CB','LM','CM','CAM','CM','RM','ST','ST'],
            'balanced' => ['GK','CB','CB','CB','LM','CDM','CM','CM','RM','ST','ST'],
            'defend'   => ['GK','CB','CB','CB','LM','CDM','CDM','CM','RM','ST','ST'],
        ],
        '3-6-1' => [
            'attack'   => ['GK','CB','CB','CB','LM','CM','CM','CM','CAM','RM','ST'],
            'balanced' => ['GK','CB','CB','CB','LM','CDM','CM','CM','CAM','RM','ST'],
            'defend'   => ['GK','CB','CB','CB','LM','CDM','CDM','CM','CM','RM','ST'],
        ],
        '5-3-2' => [
            'attack'   => ['GK','LB','CB','CB','CB','RB','CM','CAM','CM','ST','ST'],
            'balanced' => ['GK','LB','CB','CB','CB','RB','CM','CM','CM','ST','ST'],
            'defend'   => ['GK','LB','CB','CB','CB','RB','CDM','CM','CM','ST','ST'],
        ],
        '5-4-1' => [
            'attack'   => ['GK','LB','CB','CB','CB','RB','LW','CM','CAM','RW','ST'],
            'balanced' => ['GK','LB','CB','CB','CB','RB','LM','CM','CM','RM','ST'],
            'defend'   => ['GK','LB','CB','CB','CB','RB','LM','CDM','CM','RM','ST'],
        ],
    ];
    if (!isset($formations[$formation])) return [];
    return $formations[$formation][$tactic] ?? $formations[$formation]['balanced'];
}

function dcz_duel_pos_group($pos) {
    /* Parità intenzionale con posGroup() nel client corrente. */
    if ($pos === 'GK') return 'GK';
    if (in_array($pos, ['CB','RB','LB'], true)) return 'DEF';
    if (in_array($pos, ['CM','CDM','CAM','RM','LM'], true)) return 'MID';
    return 'FWD';
}

function dcz_duel_slot_penalty($slotPos, $playerPos) {
    if ($slotPos === $playerPos) return 0.0;
    $left = ['LB','LWB'];
    $right = ['RB','RWB'];
    $center = ['CB','SW','DC'];
    $isLeftSlot = in_array($slotPos, $left, true);
    $isRightSlot = in_array($slotPos, $right, true);
    $isCBSlot = in_array($slotPos, $center, true);
    $isLeftP = in_array($playerPos, $left, true);
    $isRightP = in_array($playerPos, $right, true);
    $isCBP = in_array($playerPos, $center, true);
    $isFullbackP = $isLeftP || $isRightP;
    if (($isLeftSlot && $isRightP) || ($isRightSlot && $isLeftP)) return 0.08;
    if ($isCBSlot && $isFullbackP) return 0.12;
    if (($isLeftSlot || $isRightSlot) && $isCBP) return 0.18;
    return 0.0;
}

function dcz_duel_avg($values) {
    return count($values) ? array_sum($values) / count($values) : 0.0;
}

function dcz_duel_team_eval($team) {
    $positions = dcz_duel_form_positions($team['formation'], $team['tactic'] ?? 'balanced');
    if (count($positions) !== 11) return null;
    $groups = ['GK'=>[], 'DEF'=>[], 'MID'=>[], 'FWD'=>[]];
    $effective = ['GK'=>[], 'DEF'=>[], 'MID'=>[], 'FWD'=>[]];
    foreach (($team['players'] ?? []) as $i => $p) {
        if (!is_array($p)) return null;
        $slot = $positions[$i] ?? ($p['p'] ?? '');
        $natural = (string)($p['p'] ?? '');
        $rating = (float)($p['r'] ?? 0);
        $penalty = dcz_duel_slot_penalty($slot, $natural);
        $group = dcz_duel_pos_group($natural);
        $groups[$group][] = $p;
        $effective[$group][] = $rating * (1.0 - $penalty);
    }
    if (array_sum(array_map('count', $effective)) !== 11) return null;

    $gkR  = dcz_duel_avg($effective['GK'])  ?: 6.0;
    $defR = dcz_duel_avg($effective['DEF']) ?: 6.0;
    $midR = dcz_duel_avg($effective['MID']) ?: 6.0;
    $fwdR = dcz_duel_avg($effective['FWD']) ?: 6.0;

    $starRating = 0.0;
    $r10 = ['gk'=>0.0, 'def'=>0.0, 'midAtk'=>0.0, 'midDef'=>0.0, 'fwd'=>0.0];
    foreach ($team['players'] as $p) {
        $rating = (float)$p['r'];
        if ($rating > $starRating) $starRating = $rating;
        if ($rating < 10) continue;
        $group = dcz_duel_pos_group($p['p']);
        if ($group === 'GK') $r10['gk'] += 0.04;
        elseif ($group === 'DEF') $r10['def'] += 0.03;
        elseif ($group === 'MID') { $r10['midAtk'] += 0.03; $r10['midDef'] += 0.02; }
        else $r10['fwd'] += 0.04;
    }

    $offensiveForms = ['4-2-3-1'=>true, '3-4-3'=>true];
    $defensiveForms = ['5-3-2'=>true, '5-4-1'=>true, '4-5-1'=>true, '3-6-1'=>true];
    $formation = $team['formation'];
    $fmtType = isset($offensiveForms[$formation]) ? 'off' : (isset($defensiveForms[$formation]) ? 'def' : 'mid');

    return [
        'atk' => $fwdR * 0.65 + $midR * 0.35,
        'def' => $defR * 0.70 + $gkR * 0.30,
        'starBonus' => max(0.0, ($starRating - 8.5) * 0.06),
        'r10' => $r10,
        'tactic' => in_array($team['tactic'] ?? '', ['attack','balanced','defend'], true) ? $team['tactic'] : 'balanced',
        'fmtT' => $fmtType,
    ];
}

function dcz_duel_rng_float(&$state) {
    // LCG a 31 bit: deterministico e riproducibile su PHP 64 bit.
    $state = (int)((1103515245 * $state + 12345) & 0x7fffffff);
    return $state / 2147483648.0;
}

function dcz_duel_poisson($lambda, &$state) {
    $limit = exp(-$lambda);
    $k = 0;
    $p = 1.0;
    do {
        $k++;
        $u = dcz_duel_rng_float($state);
        if ($u <= 0.0) $u = 1.0 / 2147483648.0;
        $p *= $u;
    } while ($p > $limit);
    return $k - 1;
}

function dcz_duel_penalties(&$state) {
    $a = 0; $b = 0; $seqA = []; $seqB = [];
    for ($i = 0; $i < 5; $i++) {
        $sa = dcz_duel_rng_float($state) < 0.75; $seqA[] = $sa; if ($sa) $a++;
        $sb = dcz_duel_rng_float($state) < 0.75; $seqB[] = $sb; if ($sb) $b++;
    }
    while ($a === $b && $a < 28) {
        $sa = dcz_duel_rng_float($state) < 0.75; $seqA[] = $sa; if ($sa) $a++;
        $sb = dcz_duel_rng_float($state) < 0.75; $seqB[] = $sb; if ($sb) $b++;
    }
    if ($a === $b) { $a++; $seqA[] = true; $seqB[] = false; }
    return ['a'=>$a, 'b'=>$b, 'seqA'=>$seqA, 'seqB'=>$seqB];
}

function dcz_duel_sim_match($a, $b, &$state) {
    static $tact = [
        'attack' => ['myXG'=>1.10, 'oppXG'=>1.08],
        'balanced' => ['myXG'=>1.00, 'oppXG'=>1.00],
        'defend' => ['myXG'=>0.92, 'oppXG'=>0.88],
    ];
    static $counter = [
        'attack-attack'=>[1.00,1.00], 'attack-balanced'=>[0.90,1.08], 'attack-defend'=>[1.12,0.88],
        'balanced-attack'=>[1.08,0.90], 'balanced-balanced'=>[1.00,1.00], 'balanced-defend'=>[0.90,1.06],
        'defend-attack'=>[1.06,0.88], 'defend-balanced'=>[1.04,0.90], 'defend-defend'=>[1.00,1.00],
    ];
    $floor = 0.13; $cap = 2.6;
    $xa = ($a['atk'] - $b['def'] * 0.82) * 0.38 + 0.46;
    $xb = ($b['atk'] - $a['def'] * 0.82) * 0.38 + 0.46;
    $xa *= $tact[$a['tactic']]['myXG'] * $tact[$b['tactic']]['oppXG'];
    $xb *= $tact[$b['tactic']]['myXG'] * $tact[$a['tactic']]['oppXG'];
    $ct = $counter[$a['tactic'] . '-' . $b['tactic']] ?? [1.0, 1.0];
    $xa *= $ct[0]; $xb *= $ct[1];
    $xa += $a['starBonus']; $xb += $b['starBonus'];

    if ($a['fmtT'] === 'off' && $b['tactic'] === 'defend') { $xa *= 1.04; $xb *= 0.97; }
    elseif ($a['fmtT'] === 'off' && $b['tactic'] === 'attack') { $xa *= 1.02; $xb *= 1.02; }
    elseif ($a['fmtT'] === 'def' && $b['tactic'] === 'attack') { $xb *= 0.96; $xa *= 1.02; }
    elseif ($a['fmtT'] === 'def' && $b['tactic'] === 'defend') { $xa *= 0.97; }

    if ($b['fmtT'] === 'off' && $a['tactic'] === 'defend') { $xb *= 1.04; $xa *= 0.97; }
    elseif ($b['fmtT'] === 'off' && $a['tactic'] === 'attack') { $xb *= 1.02; $xa *= 1.02; }
    elseif ($b['fmtT'] === 'def' && $a['tactic'] === 'attack') { $xa *= 0.96; $xb *= 1.02; }
    elseif ($b['fmtT'] === 'def' && $a['tactic'] === 'defend') { $xb *= 0.97; }

    if ($a['r10']['gk']) $xb *= 1 - min($a['r10']['gk'], 0.08);
    if ($a['r10']['def']) $xb *= 1 - min($a['r10']['def'], 0.09);
    if ($a['r10']['midAtk']) $xa *= 1 + min($a['r10']['midAtk'], 0.06);
    if ($a['r10']['midDef']) $xb *= 1 - min($a['r10']['midDef'], 0.04);
    if ($a['r10']['fwd']) $xa *= 1 + min($a['r10']['fwd'], 0.10);
    if ($b['r10']['gk']) $xa *= 1 - min($b['r10']['gk'], 0.08);
    if ($b['r10']['def']) $xa *= 1 - min($b['r10']['def'], 0.09);
    if ($b['r10']['midAtk']) $xb *= 1 + min($b['r10']['midAtk'], 0.06);
    if ($b['r10']['midDef']) $xa *= 1 - min($b['r10']['midDef'], 0.04);
    if ($b['r10']['fwd']) $xb *= 1 + min($b['r10']['fwd'], 0.10);

    $delta = abs($xa - $xb);
    if ($delta < 0.35) {
        $pull = 0.05 * (0.35 - $delta) / 0.35;
        $xa -= $pull; $xb -= $pull;
    }
    $xa = max($floor, min($xa, $cap));
    $xb = max($floor, min($xb, $cap));
    $ga = dcz_duel_poisson($xa, $state);
    $gb = dcz_duel_poisson($xb, $state);
    $match = ['ga'=>$ga, 'gb'=>$gb, 'pa'=>null, 'pb'=>null];
    if ($ga === $gb) {
        $pen = dcz_duel_penalties($state);
        $match['pa'] = $pen['a']; $match['pb'] = $pen['b'];
        $match['pSeqA'] = $pen['seqA']; $match['pSeqB'] = $pen['seqB'];
    }
    return $match;
}

function dcz_duel_simulate_series($teamA, $teamB, $seed) {
    $evalA = dcz_duel_team_eval($teamA);
    $evalB = dcz_duel_team_eval($teamB);
    if ($evalA === null || $evalB === null) return null;
    $state = ((int)$seed) & 0x7fffffff;
    if ($state === 0) $state = 1;
    $matches = []; $winsA = 0; $winsB = 0;
    while ($winsA < 2 && $winsB < 2 && count($matches) < 3) {
        $m = dcz_duel_sim_match($evalA, $evalB, $state);
        $aWon = $m['ga'] > $m['gb'] || ($m['ga'] === $m['gb'] && $m['pa'] > $m['pb']);
        if ($aWon) $winsA++; else $winsB++;
        $matches[] = $m;
    }
    return [
        'matches' => $matches,
        'winsA' => $winsA,
        'winsB' => $winsB,
        'winner' => $winsA > $winsB ? 'a' : 'b',
    ];
}
