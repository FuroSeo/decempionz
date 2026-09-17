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

function sample_team(string $nick, string $formation, string $tactic, int $offset = 0): array {
    $base = [
        ['GK',8], ['LB',7], ['CB',9], ['CB',8], ['RB',7],
        ['CDM',8], ['CM',9], ['CAM',10], ['LW',8], ['ST',10], ['RW',7],
    ];
    $players = [];
    foreach ($base as $i => [$pos, $rating]) {
        $players[] = [
            'n' => $nick . ' P' . ($i + 1 + $offset),
            'p' => $pos,
            'r' => $i === 10 ? 6 : $rating,
        ];
    }
    return [
        'nick' => $nick,
        'formation' => $formation,
        'tactic' => $tactic,
        'players' => $players,
    ];
}

$teamA = dcz_sanitize_team(sample_team('Alpha', '4-3-3', 'attack'));
$teamB = dcz_sanitize_team(sample_team('Beta', '4-2-3-1', 'defend', 20));
t_assert($teamA !== null, 'valid team A must pass structural validation');
t_assert($teamB !== null, 'valid team B must pass structural validation');
t_assert($teamA['players'][10]['r'] === 6, 'dataset-compatible rating 6 must remain valid');

$badRating = sample_team('BadR', '4-3-3', 'balanced', 40);
$badRating['players'][0]['r'] = 11;
t_assert(dcz_sanitize_team($badRating) === null, 'rating above dataset maximum must be rejected');

$repeatedLabel = sample_team('Repeat', '4-3-3', 'balanced', 60);
$repeatedLabel['players'][1]['n'] = $repeatedLabel['players'][0]['n'];
t_assert(dcz_sanitize_team($repeatedLabel) !== null, 'repeated historical display labels must not be rejected without canonical player ids');

$badFormation = sample_team('BadF', '2-2-6', 'balanced', 80);
t_assert(dcz_sanitize_team($badFormation) === null, 'unknown formation must be rejected');

$seed = 123456789;
$result1 = dcz_duel_simulate_series($teamA, $teamB, $seed);
$result2 = dcz_duel_simulate_series($teamA, $teamB, $seed);
t_assert(is_array($result1), 'server simulation must return a result');
t_assert($result1 === $result2, 'same teams and seed must produce identical series');
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

echo "Duel engine tests passed.\n";
