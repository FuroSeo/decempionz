<?php

declare(strict_types=1);

require_once __DIR__ . '/../duel-lib.php';
require_once __DIR__ . '/../duel-engine.php';

function t_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function t_approx(float $actual, float $expected, float $tolerance, string $message): void {
    t_assert(abs($actual - $expected) <= $tolerance, $message . " ({$actual} != {$expected})");
}

function team_fixture(string $nick, string $formation, string $tactic, string $teamId, array $players, ?string $club = null): array {
    $team = [
        'nick' => $nick,
        'formation' => $formation,
        'tactic' => $tactic,
        'players' => array_map(
            fn(array $p): array => ['n'=>$p[0], 'p'=>$p[1], 'r'=>$p[2], 'teamId'=>$teamId],
            $players
        ),
    ];
    if ($club !== null) {
        $team['club'] = $club;
        $team['tmode'] = 'ucl';
        $team['clubName'] = $club;
    }
    return $team;
}

$rmPlayers = [
    ['Rogelio','GK',8], ['Marquitos','RB',8], ['Santamaría','CB',9], ['Pachin','LB',7],
    ['Zárraga','CDM',8], ['Rial','CM',8], ['Del Sol','CM',8], ['Canario','RW',8],
    ['Di Stéfano','CF',10], ['Puskas','LW',10], ['Gento','LW',9],
];
$barPlayers = [
    ['Ter Stegen','GK',8], ['Alves','RB',8], ['Pique','CB',8], ['Mascherano','CB',8],
    ['Alba','LB',9], ['Busquets','CDM',9], ['Rakitic','CM',8], ['Iniesta','CM',10],
    ['Messi','SS',10], ['Neymar','LW',9], ['Suarez','ST',9],
];

$teamA = dcz_sanitize_team(team_fixture('Alpha', '4-3-3', 'attack', 'rm_5960', $rmPlayers));
$teamB = dcz_sanitize_team(team_fixture('Beta', '4-2-3-1', 'defend', 'bar_1415', $barPlayers));
t_assert($teamA !== null, 'valid canonical team A must pass structural validation');
t_assert($teamB !== null, 'valid canonical team B must pass structural validation');

$registry = dcz_duel_dataset_registry();
$registryCount = is_array($registry) ? array_sum(array_map('count', $registry)) : 0;
t_assert($registryCount > 200, 'canonical dataset registry must load all tournament families');
t_assert(isset($registry['ucl']['rm_5960'], $registry['ucl']['bar_1415']), 'known canonical UCL squads must be indexed');
t_assert(isset($registry['ucl']['atm_1314'], $registry['copa']['atm_1314']), 'same short teamId must coexist across tournament families');
t_assert(($registry['ucl']['atm_1314']['club'] ?? '') === 'atletico', 'UCL atm_1314 must resolve to Atletico Madrid');
t_assert(($registry['copa']['atm_1314']['club'] ?? '') === 'atletico_mineiro', 'Copa atm_1314 must resolve to Atletico Mineiro');
t_assert(dcz_duel_dataset_source($registry, 'atm_1314', null) === null, 'ambiguous short teamId must require a tournament family');
t_assert(dcz_duel_validate_team_dataset($teamA, 'ucl'), 'real Madrid fixture must match canonical UCL data');
t_assert(dcz_duel_validate_team_dataset($teamB, 'ucl'), 'Barcelona fixture must match canonical UCL data');
t_assert(!dcz_duel_validate_team_dataset($teamA, 'copa'), 'UCL sources must not validate as Copa sources');

$dynRaw = team_fixture('Dynasty', '4-3-3', 'balanced', 'rm_5960', $rmPlayers, 'real_madrid');
$dynTeam = dcz_sanitize_team($dynRaw);
t_assert($dynTeam !== null, 'Dynasty fixture must pass structural validation');
t_assert(dcz_duel_validate_team_dataset($dynTeam, 'ucl', 'real_madrid'), 'Dynasty sources must match selected club');
t_assert(!dcz_duel_validate_team_dataset($dynTeam, 'ucl', 'barcelona'), 'Dynasty sources from another club must be rejected');

$badRating = team_fixture('BadR', '4-3-3', 'balanced', 'rm_5960', $rmPlayers);
$badRating['players'][8]['r'] = 9; // Di Stéfano is canonical r=10 in rm_5960.
$badRatingTeam = dcz_sanitize_team($badRating);
t_assert($badRatingTeam !== null, 'plausible but tampered rating stays structurally valid');
t_assert(!dcz_duel_validate_team_dataset($badRatingTeam, 'ucl'), 'tampered canonical rating must be rejected by dataset validation');

$badSource = team_fixture('BadS', '4-3-3', 'balanced', 'rm_5960', $rmPlayers);
$badSource['players'][8]['teamId'] = 'bar_1415';
$badSourceTeam = dcz_sanitize_team($badSource);
t_assert($badSourceTeam !== null, 'plausible but false source stays structurally valid');
t_assert(!dcz_duel_validate_team_dataset($badSourceTeam, 'ucl'), 'player absent from declared source squad must be rejected');

$missingSource = team_fixture('NoSource', '4-3-3', 'balanced', 'rm_5960', $rmPlayers);
unset($missingSource['players'][0]['teamId']);
t_assert(dcz_sanitize_team($missingSource) === null, 'new Duel payloads require source teamId for every player');

$duplicate = team_fixture('Repeat', '4-3-3', 'balanced', 'rm_5960', $rmPlayers);
$duplicate['players'][1] = $duplicate['players'][0];
t_assert(dcz_sanitize_team($duplicate) === null, 'duplicate player names must be rejected');

$badFormation = team_fixture('BadF', '2-2-6', 'balanced', 'rm_5960', $rmPlayers);
t_assert(dcz_sanitize_team($badFormation) === null, 'unknown formation must be rejected');

$seed = 123456789;
$result1 = dcz_duel_simulate_series($teamA, $teamB, $seed);
$result2 = dcz_duel_simulate_series($teamA, $teamB, $seed);
t_assert(is_array($result1), 'server simulation must return a result');
t_assert($result1 === $result2, 'same teams and private seed must replay the identical series');
t_assert(dcz_sanitize_result($result1) !== null, 'server result must satisfy best-of-3 canonical validation');
t_assert(in_array(count($result1['matches']), [2, 3], true), 'best-of-3 must contain two or three matches');
t_assert(max((int)$result1['winsA'], (int)$result1['winsB']) === 2, 'series winner must reach two wins');
t_assert(in_array($result1['winner'], ['a', 'b'], true), 'winner marker must be canonical');

foreach ($result1['matches'] as $match) {
    t_assert($match['ga'] >= 0 && $match['gb'] >= 0, 'goals cannot be negative');
    if ($match['ga'] === $match['gb']) {
        t_assert(is_int($match['pa']) && is_int($match['pb']) && $match['pa'] !== $match['pb'], 'draws require a decided shootout');
        t_assert(isset($match['pSeqA'], $match['pSeqB']), 'server shootout must preserve kick sequences for replay');
    }
}

/* Match Engine v2 positional-fit parity fixture (mirrors scripts/test_match_engine.js). */
$compat = [
    'GK'=>['GK'], 'CB'=>['CB','LB','RB','LWB','RWB'], 'RB'=>['RB','RWB','LB','LWB','CB'],
    'LB'=>['LB','LWB','RB','RWB','CB'], 'RWB'=>['RWB','RB','LWB'], 'LWB'=>['LWB','LB','RWB'],
    'CDM'=>['CDM','CM','CB'], 'CM'=>['CM','CDM'], 'CAM'=>['CAM','SS','RW','LW'],
    'RM'=>['RM','LM','RW','LW'], 'LM'=>['LM','RM','LW','RW'],
    'RW'=>['RW','LW','RM','LM','CAM','SS'], 'LW'=>['LW','RW','LM','RM','CAM','SS'],
    'ST'=>['ST','CF','SS'], 'CF'=>['CF','ST','SS'], 'SS'=>['SS','ST','CF','CAM','RW','LW'],
];
foreach ($compat as $slot => $playerPositions) {
    foreach ($playerPositions as $playerPosition) {
        $penalty = dcz_duel_slot_penalty($slot, $playerPosition);
        if ($slot === $playerPosition) {
            t_assert($penalty === 0.0, "exact fit must have zero penalty: {$slot}");
        } else {
            t_assert($penalty > 0.0 && $penalty < 0.25, "compatible adaptation must be penalized: {$playerPosition} -> {$slot}");
        }
    }
}
t_assert(dcz_duel_slot_penalty('GK', 'ST') === 0.25, 'emergency incompatible fill must pay 25%');

$fitPlayers = [
    ['Keeper','GK',8], ['Left back','LB',8], ['Centre back','CB',9], ['Adapted right back','RB',8],
    ['Right back','RB',8], ['Wide left','LW',9], ['Central mid','CM',8], ['Holding mid','CDM',9],
    ['Wide right','RW',9], ['Centre forward','CF',9], ['Elite striker','ST',10],
];
$fitTeam = team_fixture('Fit', '4-4-2', 'balanced', 'rm_5960', $fitPlayers);
$fitEval = dcz_duel_team_eval($fitTeam);
t_assert(is_array($fitEval), 'Match Engine v2 fixture must evaluate');
t_approx((float)$fitEval['lines']['GK'], 8.0, 1e-9, 'goalkeeper line parity');
t_approx((float)$fitEval['lines']['DEF'], 8.01, 1e-9, 'defensive line parity');
t_approx((float)$fitEval['lines']['MID'], 8.345, 1e-9, 'midfield line parity');
t_approx((float)$fitEval['lines']['FWD'], 9.32, 1e-9, 'forward line parity');
t_approx((float)$fitEval['atk'], 8.97875, 1e-9, 'attack rating parity');
t_approx((float)$fitEval['def'], 8.007, 1e-9, 'defence rating parity');
t_assert($fitEval['score'] === 84 && $fitEval['fit'] === 97, 'Team Score / fit parity fixture changed');
t_assert($fitEval['r10']['fwd'] === 0.04 && $fitEval['r10']['midAtk'] === 0.0, 'elite bonus must follow occupied slot');

/* Deterministic statistical guardrails: neutral sides, quality separation and tactic counters. */
$natural442 = [
    ['P1','GK',8], ['P2','LB',8], ['P3','CB',8], ['P4','CB',8], ['P5','RB',8],
    ['P6','LM',8], ['P7','CM',8], ['P8','CM',8], ['P9','RM',8], ['P10','ST',8], ['P11','ST',8],
];
$equalA = dcz_duel_team_eval(team_fixture('Equal A', '4-4-2', 'balanced', 'rm_5960', $natural442));
$equalB = dcz_duel_team_eval(team_fixture('Equal B', '4-4-2', 'balanced', 'rm_5960', $natural442));
$winsA = 0;
$samples = 4000;
for ($seed2 = 1; $seed2 <= $samples; $seed2++) {
    $state2 = $seed2;
    $match = dcz_duel_sim_match($equalA, $equalB, $state2);
    if ($match['ga'] > $match['gb'] || ($match['ga'] === $match['gb'] && $match['pa'] > $match['pb'])) $winsA++;
}
$equalRate = $winsA / $samples;
t_assert($equalRate >= 0.47 && $equalRate <= 0.53, 'equal teams must not show material side bias');

$strongPlayers = array_map(fn(array $p): array => [$p[0], $p[1], 10], $natural442);
$weakPlayers = array_map(fn(array $p): array => [$p[0], $p[1], 7], $natural442);
$strong = dcz_duel_team_eval(team_fixture('Strong', '4-4-2', 'balanced', 'rm_5960', $strongPlayers));
$weak = dcz_duel_team_eval(team_fixture('Weak', '4-4-2', 'balanced', 'rm_5960', $weakPlayers));
$strongWins = 0;
for ($seed2 = 1; $seed2 <= $samples; $seed2++) {
    $state2 = $seed2;
    $match = dcz_duel_sim_match($strong, $weak, $state2);
    if ($match['ga'] > $match['gb'] || ($match['ga'] === $match['gb'] && $match['pa'] > $match['pb'])) $strongWins++;
}
t_assert($strongWins / $samples >= 0.75, 'materially stronger XI must win at least 75% of deterministic fixtures');

foreach ([['attack','defend'], ['balanced','attack'], ['defend','balanced']] as [$winningTactic, $losingTactic]) {
    $evalA = $equalA; $evalB = $equalB;
    $evalA['tactic'] = $winningTactic; $evalB['tactic'] = $losingTactic;
    $state2 = 24681357;
    $match = dcz_duel_sim_match($evalA, $evalB, $state2);
    t_assert($match['xa'] > $match['xb'], "tactic counter {$winningTactic} must beat {$losingTactic} on xG");
}

echo "Duel integrity tests passed.\n";
