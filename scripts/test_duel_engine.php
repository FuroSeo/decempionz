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
t_assert(is_array($registry) && count($registry) > 200, 'canonical dataset registry must load all tournament families');
t_assert(isset($registry['rm_5960'], $registry['bar_1415']), 'known canonical squads must be indexed');
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

echo "Duel integrity tests passed.\n";
