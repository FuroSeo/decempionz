<?php

declare(strict_types=1);

require_once __DIR__ . '/../duel-lib.php';
require_once __DIR__ . '/../duel-draft-lib.php';

function dd_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function dd_finish_session(array $config, bool $rerollFirst = false): array {
    $public = dcz_draft_create_session($config);
    dd_assert(is_array($public) && !empty($public['sessionId']), 'session must start');
    dd_assert(!array_key_exists('draftPool', $public), 'hidden pool must not be public');
    dd_assert(!array_key_exists('history', $public), 'draft history must not be public');
    $id = $public['sessionId'];

    if ($rerollFirst && $public['status'] === 'active') {
        $beforeVersion = (int)$public['version'];
        $next = dcz_draft_session_action($id, $beforeVersion, 'reroll');
        dd_assert(!empty($next['ok']), 'first reroll must succeed');
        dd_assert((int)$next['passes'] === 2, 'normal Duel starts with three rerolls');
        $conflict = dcz_draft_session_action($id, $beforeVersion, 'pick', 0);
        dd_assert(($conflict['code'] ?? null) === 409 && !empty($conflict['conflict']), 'stale action version must resync, not double-apply');
        $public = $next;
    }

    $guard = 0;
    while (($public['status'] ?? '') === 'active' && $guard++ < 30) {
        dd_assert(!empty($public['cards']), 'active draft must expose at least one offer');
        $public = dcz_draft_session_action($id, (int)$public['version'], 'pick', 0);
        dd_assert(is_array($public) && !isset($public['error']), 'pick must advance server draft');
    }

    dd_assert(($public['status'] ?? '') === 'done', 'server draft must finish');
    dd_assert((int)$public['filled'] === 11, 'server draft must finish with XI');
    dd_assert(count($public['finalTeam'] ?? []) === 11, 'final team must contain 11 players');
    return $public;
}

$dataset = dcz_draft_dataset();
dd_assert(is_array($dataset) && count($dataset) > 200, 'all canonical team families must parse');

foreach (['3-4-3','3-5-2','3-6-1','4-1-4-1','4-2-3-1','4-3-3','4-4-2','4-5-1','5-3-2','5-4-1'] as $formation) {
    foreach (['attack','balanced','defend'] as $tactic) {
        dd_assert(count(dcz_draft_positions($formation, $tactic)) === 11, "formation/tactic must expose 11 slots: {$formation} {$tactic}");
    }
}

$tournaments = dcz_draft_tournaments();
dd_assert(isset($tournaments['ucl']['pioneers'], $tournaments['copa']['copa_pioneros'], $tournaments['wc']['wc_pionieri']), 'canonical era definitions must parse');

$config = dcz_draft_config([
    'role'=>'a',
    'mode'=>'classic',
    'tournament'=>'ucl',
    'eraId'=>'pioneers',
    'formation'=>'4-3-3',
    'tactic'=>'balanced',
]);
dd_assert($config !== null, 'classic Duel draft config must validate');

$done = dd_finish_session($config, true);
$team = [
    'nick'=>'IntegrityTest',
    'formation'=>'4-3-3',
    'tactic'=>'balanced',
    'players'=>$done['finalTeam'],
    'club'=>null,
    'tmode'=>null,
    'clubName'=>null,
];

$verified = dcz_draft_verify_completed_session($done['sessionId'], $team, [
    'role'=>'a',
    'mode'=>'classic',
    'tournament'=>'ucl',
    'eraId'=>'pioneers',
    'formation'=>'4-3-3',
    'tactic'=>'balanced',
    'club'=>null,
    'duelId'=>null,
]);
dd_assert(!empty($verified['ok']), 'completed server draft must verify');
dd_assert(($verified['proof']['engine'] ?? '') === DCZ_DUEL_DRAFT_ENGINE_VERSION, 'proof must record draft engine version');
dd_assert(count($verified['proof']['history'] ?? []) >= 12, 'proof must keep offer/action history');

$tampered = $team;
$tampered['players'][0]['r'] = max(6, (int)$tampered['players'][0]['r'] - 1);
if ($tampered['players'][0]['r'] === $team['players'][0]['r']) $tampered['players'][0]['r'] = min(10, (int)$tampered['players'][0]['r'] + 1);
$bad = dcz_draft_verify_completed_session($done['sessionId'], $tampered, [
    'role'=>'a','mode'=>'classic','tournament'=>'ucl','eraId'=>'pioneers',
    'formation'=>'4-3-3','tactic'=>'balanced','club'=>null,'duelId'=>null,
]);
dd_assert(empty($bad['ok']), 'tampered final team must fail draft verification');

dd_assert(dcz_draft_consume_session($done['sessionId'], 'TESTDUEL') === true, 'completed draft must be consumable');
dd_assert(dcz_draft_consume_session($done['sessionId'], 'TESTDUEL') === true, 'same consume reference must be idempotent');
dd_assert(dcz_draft_consume_session($done['sessionId'], 'OTHERDUEL') === false, 'different consume reference must lose the race');
$reused = dcz_draft_verify_completed_session($done['sessionId'], $team, [
    'role'=>'a','mode'=>'classic','tournament'=>'ucl','eraId'=>'pioneers',
    'formation'=>'4-3-3','tactic'=>'balanced','club'=>null,'duelId'=>null,
]);
dd_assert(empty($reused['ok']), 'consumed draft session must not be reusable');

$dynConfig = dcz_draft_config([
    'role'=>'a',
    'mode'=>'dynasty',
    'tournament'=>'ucl',
    'eraId'=>'dynasty',
    'formation'=>'4-2-3-1',
    'tactic'=>'balanced',
    'club'=>'real_madrid',
]);
dd_assert($dynConfig !== null, 'Dynasty Duel draft config must validate');
$dynDone = dd_finish_session($dynConfig);
foreach ($dynDone['finalTeam'] as $player) {
    $src = $dataset[$player['teamId']] ?? null;
    dd_assert(is_array($src) && $src['mode'] === 'ucl' && $src['club'] === 'real_madrid', 'Dynasty offer must stay inside selected club');
}

foreach (glob(dcz_draft_session_dir() . '*.json') ?: [] as $file) @unlink($file);

echo "Duel draft session tests passed.\n";
