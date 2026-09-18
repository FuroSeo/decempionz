<?php
/* duel-draft-lib.php — server-authoritative Duel draft sessions.
   The public browser sees only the current offer/slots. Hidden pool/state/history remain server-side. */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'duel-draft-lib.php') {
    http_response_code(403); exit;
}

const DCZ_DUEL_DRAFT_ENGINE_VERSION = 'server-draft-v1';
const DCZ_DUEL_DRAFT_TTL = 3600;

function dcz_draft_session_dir() {
    $duelsDir = __DIR__ . '/duels/';
    if (!is_dir($duelsDir)) @mkdir($duelsDir, 0755, true);
    $deny = $duelsDir . '.htaccess';
    if (!is_file($deny)) @file_put_contents($deny, "Require all denied\n", LOCK_EX);
    $dir = $duelsDir . '.draft-sessions/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function dcz_draft_session_path($id) {
    $id = preg_replace('/[^a-f0-9]/', '', strtolower((string)$id));
    if (strlen($id) !== 32) return null;
    return dcz_draft_session_dir() . $id . '.json';
}

function dcz_draft_cleanup_sessions() {
    $dir = dcz_draft_session_dir();
    $files = glob($dir . '*.json');
    if ($files === false) return;
    $cutoff = time() - (DCZ_DUEL_DRAFT_TTL * 3);
    foreach ($files as $file) {
        if ((int)@filemtime($file) < $cutoff) @unlink($file);
    }
}

function dcz_draft_js_unescape($value) {
    return str_replace(["\\\\", "\\'"], ["\\", "'"], (string)$value);
}

function dcz_draft_player_ref($p) {
    return (string)($p['teamId'] ?? '') . '|' . (string)($p['n'] ?? '') . '|' . (string)($p['p'] ?? '') . '|' . (int)($p['r'] ?? 0);
}

function dcz_draft_public_player($p) {
    if (!is_array($p)) return null;
    $out = [
        'n' => (string)$p['n'],
        'p' => (string)$p['p'],
        'r' => (int)$p['r'],
        'teamId' => (string)$p['teamId'],
    ];
    if (!empty($p['nat'])) $out['nat'] = (string)$p['nat'];
    if (!empty($p['club'])) $out['club'] = (string)$p['club'];
    return $out;
}

function dcz_draft_dataset($path = null) {
    $path = $path ?: (__DIR__ . '/game-data.js');
    static $cache = [];
    if (array_key_exists($path, $cache)) return $cache[$path];

    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) return $cache[$path] = null;

    $registry = [];
    $mode = null;
    foreach ($lines as $line) {
        if (preg_match('/^const\s+TEAMS\s*=\s*\{/', $line)) { $mode = 'ucl'; continue; }
        if (preg_match('/^const\s+COPA_TEAMS\s*=\s*\{/', $line)) { $mode = 'copa'; continue; }
        if (preg_match('/^const\s+WC_TEAMS\s*=\s*\{/', $line)) { $mode = 'wc'; continue; }
        if ($mode !== null && preg_match('/^\};\s*$/', $line)) { $mode = null; continue; }
        if ($mode === null) continue;

        if (!preg_match('/^\s*([A-Za-z0-9_]+):\{/', $line, $idm)) continue;
        if (!preg_match("/\bname:'((?:\\.|[^'\\])*)'/", $line, $nm)) continue;
        if (!preg_match("/\bclub:'([a-z0-9_]+)'/", $line, $cm)) continue;
        if (!preg_match('/players:\[(.*)\]\},?\s*$/u', $line, $pm)) continue;

        $players = [];
        preg_match_all("/\{n:'((?:\\.|[^'\\])*)',p:'([A-Z]+)',r:(\d+)(?:,nat:'((?:\\.|[^'\\])*)')?\}/u", $pm[1], $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $p = [
                'n' => dcz_draft_js_unescape($m[1]),
                'p' => $m[2],
                'r' => (int)$m[3],
            ];
            if (isset($m[4]) && $m[4] !== '') $p['nat'] = dcz_draft_js_unescape($m[4]);
            $players[] = $p;
        }
        if (!$players) continue;

        $registry[$idm[1]] = [
            'id' => $idm[1],
            'mode' => $mode,
            'name' => dcz_draft_js_unescape($nm[1]),
            'club' => $cm[1],
            'players' => $players,
        ];
    }

    return $cache[$path] = ($registry ?: null);
}

function dcz_draft_extract_array_block($content, $constName) {
    $needle = 'const ' . $constName;
    $start = strpos($content, $needle);
    if ($start === false) return null;
    $open = strpos($content, '[', $start);
    if ($open === false) return null;

    $depth = 0; $quote = null; $escaped = false;
    $len = strlen($content);
    for ($i = $open; $i < $len; $i++) {
        $ch = $content[$i];
        if ($quote !== null) {
            if ($escaped) { $escaped = false; continue; }
            if ($ch === '\\') { $escaped = true; continue; }
            if ($ch === $quote) $quote = null;
            continue;
        }
        if ($ch === "'" || $ch === '"') { $quote = $ch; continue; }
        if ($ch === '[') $depth++;
        elseif ($ch === ']') {
            $depth--;
            if ($depth === 0) return substr($content, $open + 1, $i - $open - 1);
        }
    }
    return null;
}

function dcz_draft_split_objects($block) {
    $objects = [];
    $depth = 0; $quote = null; $escaped = false; $start = null;
    $len = strlen((string)$block);
    for ($i = 0; $i < $len; $i++) {
        $ch = $block[$i];
        if ($quote !== null) {
            if ($escaped) { $escaped = false; continue; }
            if ($ch === '\\') { $escaped = true; continue; }
            if ($ch === $quote) $quote = null;
            continue;
        }
        if ($ch === "'" || $ch === '"') { $quote = $ch; continue; }
        if ($ch === '{') {
            if ($depth === 0) $start = $i;
            $depth++;
        } elseif ($ch === '}') {
            $depth--;
            if ($depth === 0 && $start !== null) {
                $objects[] = substr($block, $start, $i - $start + 1);
                $start = null;
            }
        }
    }
    return $objects;
}

function dcz_draft_tournaments($path = null) {
    $path = $path ?: (__DIR__ . '/game-data.js');
    static $cache = [];
    if (array_key_exists($path, $cache)) return $cache[$path];
    $content = @file_get_contents($path);
    if ($content === false) return $cache[$path] = null;

    $out = ['ucl'=>[], 'copa'=>[], 'wc'=>[]];
    $consts = ['ucl'=>'TOURNAMENTS', 'copa'=>'COPA_TOURNAMENTS', 'wc'=>'WC_TOURNAMENTS'];
    foreach ($consts as $mode => $name) {
        $block = dcz_draft_extract_array_block($content, $name);
        if ($block === null) return $cache[$path] = null;
        foreach (dcz_draft_split_objects($block) as $obj) {
            if (!preg_match("/\bid:'([A-Za-z0-9_]+)'/", $obj, $idm)) continue;
            if (!preg_match('/teams:\[([^\]]*)\]/s', $obj, $tm)) continue;
            preg_match_all("/'([A-Za-z0-9_]+)'/", $tm[1], $ids);
            $out[$mode][$idm[1]] = [
                'id' => $idm[1],
                'teams' => $ids[1],
                'allTime' => strpos($obj, 'allTime:true') !== false,
            ];
        }
    }
    return $cache[$path] = $out;
}

function dcz_draft_positions($formation, $tactic) {
    static $formations = [
        '4-3-3'=>[
            'attack'=>['GK','LB','CB','CB','RB','CM','CAM','CM','LW','ST','RW'],
            'balanced'=>['GK','LB','CB','CB','RB','CM','CM','CM','LW','ST','RW'],
            'defend'=>['GK','LB','CB','CB','RB','CDM','CM','CM','LM','ST','RM']],
        '4-4-2'=>[
            'attack'=>['GK','LB','CB','CB','RB','LW','CM','CM','RW','ST','ST'],
            'balanced'=>['GK','LB','CB','CB','RB','LM','CM','CM','RM','ST','ST'],
            'defend'=>['GK','LB','CB','CB','RB','LM','CDM','CM','RM','ST','ST']],
        '4-2-3-1'=>[
            'attack'=>['GK','LB','CB','CB','RB','CM','CDM','LW','CAM','RW','ST'],
            'balanced'=>['GK','LB','CB','CB','RB','CDM','CDM','LW','CAM','RW','ST'],
            'defend'=>['GK','LB','CB','CB','RB','CDM','CDM','LM','CM','RM','ST']],
        '3-5-2'=>[
            'attack'=>['GK','CB','CB','CB','LM','CM','CAM','CM','RM','ST','ST'],
            'balanced'=>['GK','CB','CB','CB','LM','CDM','CM','CM','RM','ST','ST'],
            'defend'=>['GK','CB','CB','CB','LM','CDM','CDM','CM','RM','ST','ST']],
        '4-1-4-1'=>[
            'attack'=>['GK','LB','CB','CB','RB','CDM','LW','CM','CM','RW','ST'],
            'balanced'=>['GK','LB','CB','CB','RB','CDM','LM','CM','CM','RM','ST'],
            'defend'=>['GK','LB','CB','CB','RB','CDM','CDM','LM','CM','RM','ST']],
        '3-4-3'=>[
            'attack'=>['GK','CB','CB','CB','LM','CM','CAM','RM','LW','ST','RW'],
            'balanced'=>['GK','CB','CB','CB','LM','CM','CM','RM','LW','ST','RW'],
            'defend'=>['GK','CB','CB','CB','LM','CDM','CM','RM','LW','ST','RW']],
        '5-3-2'=>[
            'attack'=>['GK','LB','CB','CB','CB','RB','CM','CAM','CM','ST','ST'],
            'balanced'=>['GK','LB','CB','CB','CB','RB','CM','CM','CM','ST','ST'],
            'defend'=>['GK','LB','CB','CB','CB','RB','CDM','CM','CM','ST','ST']],
        '4-5-1'=>[
            'attack'=>['GK','LB','CB','CB','RB','LW','CM','CM','CM','RW','ST'],
            'balanced'=>['GK','LB','CB','CB','RB','LM','CM','CDM','CM','RM','ST'],
            'defend'=>['GK','LB','CB','CB','RB','LM','CDM','CDM','CM','RM','ST']],
        '5-4-1'=>[
            'attack'=>['GK','LB','CB','CB','CB','RB','LW','CM','CAM','RW','ST'],
            'balanced'=>['GK','LB','CB','CB','CB','RB','LM','CM','CM','RM','ST'],
            'defend'=>['GK','LB','CB','CB','CB','RB','LM','CDM','CM','RM','ST']],
        '3-6-1'=>[
            'attack'=>['GK','CB','CB','CB','LM','CM','CM','CM','CAM','RM','ST'],
            'balanced'=>['GK','CB','CB','CB','LM','CDM','CM','CM','CAM','RM','ST'],
            'defend'=>['GK','CB','CB','CB','LM','CDM','CDM','CM','CM','RM','ST']],
    ];
    if (!isset($formations[$formation])) return [];
    return $formations[$formation][$tactic] ?? $formations[$formation]['balanced'];
}

function dcz_draft_compat($slot) {
    static $compat = [
        'GK'=>['GK'], 'CB'=>['CB','LB','RB','LWB','RWB'], 'RB'=>['RB','RWB','LB','LWB','CB'],
        'LB'=>['LB','LWB','RB','RWB','CB'], 'RWB'=>['RWB','RB','LWB'], 'LWB'=>['LWB','LB','RWB'],
        'CDM'=>['CDM','CM','CB'], 'CM'=>['CM','CDM'], 'CAM'=>['CAM','SS','RW','LW'],
        'RM'=>['RM','LM','RW','LW'], 'LM'=>['LM','RM','LW','RW'],
        'RW'=>['RW','LW','RM','LM','CAM','SS'], 'LW'=>['LW','RW','LM','RM','CAM','SS'],
        'ST'=>['ST','CF','SS'], 'CF'=>['CF','ST','SS'], 'SS'=>['SS','ST','CF','CAM','RW','LW'],
    ];
    return $compat[$slot] ?? [];
}

function dcz_draft_pos_group($pos) {
    if ($pos === 'GK') return 'GK';
    if (in_array($pos, ['CB','RB','LB'], true)) return 'DEF';
    if (in_array($pos, ['CM','CDM','CAM','RM','LM'], true)) return 'MID';
    return 'FWD';
}

function dcz_draft_shuffle($arr) {
    $arr = array_values($arr);
    for ($i = count($arr) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        $tmp = $arr[$i]; $arr[$i] = $arr[$j]; $arr[$j] = $tmp;
    }
    return $arr;
}

function dcz_draft_random_float() {
    return random_int(0, 2147483647) / 2147483648.0;
}

function dcz_draft_stable_rating_desc($players) {
    $decorated = [];
    foreach (array_values($players) as $i => $p) $decorated[] = ['i'=>$i, 'p'=>$p];
    usort($decorated, function ($a, $b) {
        $cmp = ((int)$b['p']['r']) <=> ((int)$a['p']['r']);
        return $cmp !== 0 ? $cmp : ($a['i'] <=> $b['i']);
    });
    return array_map(fn($x) => $x['p'], $decorated);
}

function dcz_draft_config($raw) {
    if (!is_array($raw)) return null;
    $role = in_array($raw['role'] ?? '', ['a','b'], true) ? $raw['role'] : null;
    $mode = in_array($raw['mode'] ?? '', ['classic','dynasty'], true) ? $raw['mode'] : null;
    $tournament = in_array($raw['tournament'] ?? '', ['ucl','copa','wc'], true) ? $raw['tournament'] : null;
    $formation = (string)($raw['formation'] ?? '');
    $tactic = in_array($raw['tactic'] ?? '', ['attack','balanced','defend'], true) ? $raw['tactic'] : null;
    $eraId = preg_replace('/[^A-Za-z0-9_]/', '', (string)($raw['eraId'] ?? ''));
    $club = preg_replace('/[^a-z0-9_]/', '', (string)($raw['club'] ?? ''));
    $duelId = preg_replace('/[^A-Za-z0-9]/', '', (string)($raw['duelId'] ?? ''));
    if ($role === null || $mode === null || $tournament === null || $tactic === null || count(dcz_draft_positions($formation, $tactic)) !== 11) return null;
    if ($mode === 'classic' && $eraId === '') return null;
    if ($mode === 'dynasty' && $club === '') return null;
    if ($role === 'b' && (strlen($duelId) < 6 || strlen($duelId) > 12)) return null;
    return [
        'role'=>$role, 'mode'=>$mode, 'tournament'=>$tournament, 'eraId'=>$mode === 'dynasty' ? 'dynasty' : $eraId,
        'formation'=>$formation, 'tactic'=>$tactic, 'club'=>$mode === 'dynasty' ? $club : null,
        'duelId'=>$role === 'b' ? $duelId : null,
    ];
}

function dcz_draft_build_pool($config) {
    $dataset = dcz_draft_dataset();
    if (!is_array($dataset)) return null;

    if ($config['mode'] === 'dynasty') {
        $ids = [];
        foreach ($dataset as $id => $team) {
            if ($team['mode'] === $config['tournament'] && $team['club'] === $config['club']) $ids[] = $id;
        }
        sort($ids, SORT_STRING);
        if (!$ids) return null;
        $seen = [];
        foreach ($ids as $id) {
            $team = $dataset[$id];
            foreach ($team['players'] as $p) {
                $entry = $p + ['club'=>$team['name'], 'teamId'=>$id];
                if (!isset($seen[$p['n']]) || $entry['r'] > $seen[$p['n']]['r']) $seen[$p['n']] = $entry;
            }
        }
        return dcz_draft_shuffle(array_values($seen));
    }

    $tournaments = dcz_draft_tournaments();
    if (!is_array($tournaments) || !isset($tournaments[$config['tournament']][$config['eraId']])) return null;
    $era = $tournaments[$config['tournament']][$config['eraId']];
    $teamIds = $era['teams'];
    foreach ($teamIds as $id) {
        if (!isset($dataset[$id]) || $dataset[$id]['mode'] !== $config['tournament']) return null;
    }

    if (!empty($era['allTime'])) {
        $byClub = [];
        foreach ($teamIds as $id) {
            $team = $dataset[$id];
            $avg = array_sum(array_map(fn($p)=>(int)$p['r'], $team['players'])) / count($team['players']);
            $club = $team['club'] ?: $id;
            if (!isset($byClub[$club])) $byClub[$club] = [];
            $byClub[$club][] = ['id'=>$id, 'avg'=>$avg, 'ws'=>$avg + (dcz_draft_random_float() - 0.5) * 1.5];
        }
        $teamIds = [];
        foreach ($byClub as $versions) {
            usort($versions, fn($a,$b) => $b['ws'] <=> $a['ws']);
            foreach (array_slice($versions, 0, 3) as $v) $teamIds[] = $v['id'];
        }
    }

    $buckets = [];
    foreach ($teamIds as $id) {
        $team = $dataset[$id];
        $sorted = dcz_draft_stable_rating_desc($team['players']);
        $gi = count($sorted) > 1 && dcz_draft_random_float() < 0.35 ? 1 : 0;
        $top = null; $rest = [];
        foreach ($sorted as $i => $p) {
            $entry = $p + ['club'=>$team['name'], 'teamId'=>$id];
            if ($i === $gi) $top = $entry; else $rest[] = $entry;
        }
        $buckets[] = ['top'=>$top, 'rest'=>$rest];
    }

    $all = [];
    foreach ($buckets as $bucket) {
        if ($bucket['top']) $all[] = $bucket['top'];
        $used = [];
        if ($bucket['top']) $used[$bucket['top']['n']] = true;
        foreach (['RB','LB'] as $fbPos) {
            $cands = array_values(array_filter($bucket['rest'], fn($p) => $p['p'] === $fbPos));
            $cands = dcz_draft_stable_rating_desc($cands);
            if ($cands) {
                $fb = $cands[0];
                if (!isset($used[$fb['n']])) { $all[] = $fb; $used[$fb['n']] = true; }
            }
        }
        $remaining = array_values(array_filter($bucket['rest'], fn($p) => !isset($used[$p['n']])));
        $remaining = dcz_draft_shuffle($remaining);
        foreach (array_slice($remaining, 0, 3) as $p) $all[] = $p;
    }

    $all = dcz_draft_stable_rating_desc($all);
    $seen = []; $deduped = [];
    foreach ($all as $p) {
        if (!isset($seen[$p['n']])) { $seen[$p['n']] = true; $deduped[] = $p; }
    }
    $gks = dcz_draft_stable_rating_desc(array_values(array_filter($deduped, fn($p) => $p['p'] === 'GK')));
    $gkKeep = [];
    foreach (array_slice($gks, 0, 4) as $p) $gkKeep[$p['n']] = true;
    $deduped = array_values(array_filter($deduped, fn($p) => $p['p'] !== 'GK' || isset($gkKeep[$p['n']])));
    return dcz_draft_shuffle($deduped);
}

function dcz_draft_compatible_slots($state, $player) {
    $out = [];
    foreach ($state['positions'] as $i => $pos) {
        if ($state['slots'][$i] !== null) continue;
        if (in_array($player['p'], dcz_draft_compat($pos), true)) $out[] = ['i'=>$i, 'pos'=>$pos];
    }
    return $out;
}

function dcz_draft_best_slot($state, $player) {
    $slots = dcz_draft_compatible_slots($state, $player);
    if (!$slots) return null;
    usort($slots, function ($a, $b) use ($player) {
        $pref = function ($s) use ($player) {
            if ($s['pos'] === $player['p']) return 0;
            if (dcz_draft_pos_group($s['pos']) === dcz_draft_pos_group($player['p'])) return 1;
            return 2;
        };
        $cmp = $pref($a) <=> $pref($b);
        return $cmp !== 0 ? $cmp : ($a['i'] <=> $b['i']);
    });
    return $slots[0];
}

function dcz_draft_tier($p) {
    $r = (int)$p['r'];
    return $r >= 10 ? 10 : ($r >= 9 ? 9 : ($r >= 8 ? 8 : 7));
}

function dcz_draft_init_state($config) {
    $pool = dcz_draft_build_pool($config);
    if (!is_array($pool) || !$pool) return null;
    $tiers = [10=>[],9=>[],8=>[],7=>[]];
    foreach ($pool as $p) $tiers[dcz_draft_tier($p)][] = $p;
    foreach ([10,9,8,7] as $t) $tiers[$t] = dcz_draft_shuffle($tiers[$t]);

    return [
        'engine'=>DCZ_DUEL_DRAFT_ENGINE_VERSION,
        'config'=>$config,
        'createdAt'=>time(),
        'expiresAt'=>time()+DCZ_DUEL_DRAFT_TTL,
        'version'=>0,
        'status'=>'active',
        'draftPool'=>$pool,
        'tiers'=>$tiers,
        'cards'=>[],
        'discarded'=>[],
        'positions'=>dcz_draft_positions($config['formation'], $config['tactic']),
        'slots'=>array_fill(0, 11, null),
        'passes'=>3,
        'history'=>[],
        'finalTeam'=>null,
        'consumedAt'=>null,
    ];
}

function dcz_draft_draw_cards(&$state) {
    $state['cards'] = [];
    $weights = [10=>3,9=>2,8=>2,7=>1];
    $remaining = [];
    foreach ($state['positions'] as $i => $pos) {
        if ($state['slots'][$i] === null && !in_array($pos, $remaining, true)) $remaining[] = $pos;
    }
    if (!$remaining) return;

    $available = [];
    foreach ([10,9,8,7] as $t) {
        foreach ($state['tiers'][$t] as $p) {
            if (dcz_draft_compatible_slots($state, $p)) { $available[] = $t; break; }
        }
    }
    if (!$available && $state['discarded']) {
        foreach ($state['discarded'] as $p) {
            $tier = dcz_draft_tier($p);
            $exists = false;
            foreach ($state['tiers'][$tier] as $e) if ($e['n'] === $p['n']) { $exists = true; break; }
            if (!$exists) $state['tiers'][$tier][] = $p;
        }
        $state['discarded'] = [];
        foreach ([10,9,8,7] as $t) $state['tiers'][$t] = dcz_draft_shuffle($state['tiers'][$t]);
        foreach ([10,9,8,7] as $t) {
            foreach ($state['tiers'][$t] as $p) {
                if (dcz_draft_compatible_slots($state, $p)) { $available[] = $t; break; }
            }
        }
    }
    if (!$available) return;

    $sum = array_sum(array_map(fn($t)=>$weights[$t] ?? 1, $available));
    $wr = dcz_draft_random_float() * $sum; $wc = 0; $tier = $available[count($available)-1];
    foreach ($available as $t) { $wc += $weights[$t] ?? 1; if ($wr < $wc) { $tier = $t; break; } }

    $feasible = [];
    foreach ($remaining as $type) {
        $ok = false;
        foreach ([10,9,8,7] as $t) {
            foreach ($state['tiers'][$t] as $p) {
                foreach (dcz_draft_compatible_slots($state, $p) as $slot) {
                    if ($slot['pos'] === $type) { $ok = true; break 3; }
                }
            }
        }
        if ($ok) $feasible[] = $type;
    }

    $anyNonGk = false;
    foreach ($state['positions'] as $i => $pos) if ($state['slots'][$i] === null && $pos !== 'GK') { $anyNonGk = true; break; }
    $targetPool = $anyNonGk ? array_values(array_filter($feasible, fn($x)=>$x !== 'GK')) : $feasible;
    $targets = array_slice(dcz_draft_shuffle($targetPool), 0, 3);
    $tierOrder = [$tier];
    foreach ([10,9,8,7] as $t) if ($t !== $tier && count($state['tiers'][$t]) > 0) $tierOrder[] = $t;

    $drawn = []; $names = [];
    foreach ($targets as $type) {
        foreach ($tierOrder as $t) {
            $found = false;
            foreach ($state['tiers'][$t] as $p) {
                if (isset($names[$p['n']])) continue;
                foreach (dcz_draft_compatible_slots($state, $p) as $slot) {
                    if ($slot['pos'] === $type) {
                        $drawn[] = ['p'=>$p, 'tier'=>$t]; $names[$p['n']] = true; $found = true; break 2;
                    }
                }
            }
            if ($found) break;
        }
    }

    if (count($drawn) < 3) {
        $padTypes = $anyNonGk ? array_values(array_filter($feasible, fn($x)=>$x !== 'GK')) : $feasible;
        $padTypes = dcz_draft_shuffle($padTypes);
        foreach ($padTypes as $type) {
            if (count($drawn) >= 3) break;
            foreach ($tierOrder as $t) {
                $added = false;
                foreach ($state['tiers'][$t] as $p) {
                    if (isset($names[$p['n']])) continue;
                    foreach (dcz_draft_compatible_slots($state, $p) as $slot) {
                        if ($slot['pos'] === $type) {
                            $drawn[] = ['p'=>$p, 'tier'=>$t]; $names[$p['n']] = true; $added = true; break 2;
                        }
                    }
                }
                if ($added) break;
            }
        }
    }

    while (count($drawn) < 3) {
        $found = false;
        foreach ($tierOrder as $t) {
            foreach ($state['tiers'][$t] as $p) {
                if (isset($names[$p['n']])) continue;
                if ($anyNonGk && $p['p'] === 'GK') continue;
                if (dcz_draft_compatible_slots($state, $p)) {
                    $drawn[] = ['p'=>$p, 'tier'=>$t]; $names[$p['n']] = true; $found = true; break 2;
                }
            }
        }
        if (!$found) break;
    }

    foreach ($drawn as $d) {
        $state['tiers'][$d['tier']] = array_values(array_filter($state['tiers'][$d['tier']], fn($p)=>$p['n'] !== $d['p']['n']));
    }
    $state['cards'] = array_map(fn($d)=>$d['p'], $drawn);
}

function dcz_draft_finalize(&$state, $reason = 'complete') {
    if ($state['status'] === 'done') return;
    $drafted = [];
    foreach ($state['slots'] as $p) if ($p) $drafted[$p['n']] = true;

    $flat = array_merge($state['draftPool'], $state['cards']);
    foreach ($flat as $p) {
        if (count(array_filter($state['slots'])) >= 11) break;
        if (isset($drafted[$p['n']])) continue;
        $slot = dcz_draft_best_slot($state, $p);
        if ($slot !== null) {
            $state['slots'][$slot['i']] = $p;
            $drafted[$p['n']] = true;
        }
    }

    if (count(array_filter($state['slots'])) < 11) {
        $unique = [];
        foreach ($flat as $p) if (!isset($drafted[$p['n']]) && !isset($unique[$p['n']])) $unique[$p['n']] = $p;
        $cands = dcz_draft_stable_rating_desc(array_values($unique));
        foreach ($state['slots'] as $i => $sp) {
            if ($sp !== null || !$cands) continue;
            $slotPos = $state['positions'][$i] ?? 'CM';
            $k = -1;
            foreach ($cands as $j => $p) if (dcz_draft_pos_group($p['p']) === dcz_draft_pos_group($slotPos)) { $k = $j; break; }
            if ($k < 0) $k = 0;
            $chosen = $cands[$k]; array_splice($cands, $k, 1);
            $state['slots'][$i] = $chosen; $drafted[$chosen['n']] = true;
        }
    }

    if (count(array_filter($state['slots'])) === 11) {
        $state['status'] = 'done';
        $state['cards'] = [];
        $state['finalTeam'] = array_map('dcz_draft_public_player', $state['slots']);
        $state['history'][] = ['v'=>$state['version'], 'action'=>'finalize', 'reason'=>$reason];
    }
}

function dcz_draft_ensure_offer(&$state) {
    if ($state['status'] !== 'active') return;
    if (count(array_filter($state['slots'])) >= 11) { dcz_draft_finalize($state, 'eleven-picks'); return; }
    if (!$state['cards']) dcz_draft_draw_cards($state);
    if (!$state['cards']) { dcz_draft_finalize($state, 'pool-exhausted'); return; }

    $hasCompat = false;
    foreach ($state['cards'] as $p) if (dcz_draft_compatible_slots($state, $p)) { $hasCompat = true; break; }
    if (!$hasCompat) {
        if ($state['discarded']) {
            foreach ($state['discarded'] as $p) {
                $tier = dcz_draft_tier($p); $exists = false;
                foreach ($state['tiers'][$tier] as $e) if ($e['n'] === $p['n']) { $exists = true; break; }
                if (!$exists) $state['tiers'][$tier][] = $p;
            }
            $state['discarded'] = [];
            foreach ([10,9,8,7] as $t) $state['tiers'][$t] = dcz_draft_shuffle($state['tiers'][$t]);
            $state['cards'] = [];
            dcz_draft_draw_cards($state);
            foreach ($state['cards'] as $p) if (dcz_draft_compatible_slots($state, $p)) { $hasCompat = true; break; }
        }
        if (!$hasCompat) dcz_draft_finalize($state, 'no-compatible-offer');
    }
}

function dcz_draft_public_state($id, $state) {
    return [
        'ok'=>true,
        'sessionId'=>$id,
        'engine'=>$state['engine'],
        'version'=>(int)$state['version'],
        'status'=>$state['status'],
        'cards'=>array_map('dcz_draft_public_player', $state['cards']),
        'slots'=>array_map('dcz_draft_public_player', $state['slots']),
        'passes'=>(int)$state['passes'],
        'filled'=>count(array_filter($state['slots'])),
        'finalTeam'=>$state['status'] === 'done' ? $state['finalTeam'] : null,
    ];
}

function dcz_draft_write_locked($fp, $state) {
    $encoded = json_encode($state, JSON_UNESCAPED_UNICODE);
    if ($encoded === false) return false;
    rewind($fp); ftruncate($fp, 0);
    $ok = fwrite($fp, $encoded) !== false;
    fflush($fp);
    return $ok;
}

function dcz_draft_create_session($config) {
    dcz_draft_cleanup_sessions();
    $state = dcz_draft_init_state($config);
    if ($state === null) return null;
    dcz_draft_ensure_offer($state);
    $id = bin2hex(random_bytes(16));
    $path = dcz_draft_session_path($id);
    $encoded = json_encode($state, JSON_UNESCAPED_UNICODE);
    if ($encoded === false || @file_put_contents($path, $encoded, LOCK_EX) === false) return null;
    return dcz_draft_public_state($id, $state);
}

function dcz_draft_with_session_lock($id, $callback) {
    $path = dcz_draft_session_path($id);
    if ($path === null || !is_file($path)) return ['error'=>'not found', 'code'=>404];
    $fp = @fopen($path, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) { if ($fp) fclose($fp); return ['error'=>'lock failed', 'code'=>500]; }
    $raw = stream_get_contents($fp);
    $state = json_decode($raw, true);
    if (!is_array($state)) { flock($fp, LOCK_UN); fclose($fp); return ['error'=>'corrupt session', 'code'=>500]; }
    if ((int)($state['expiresAt'] ?? 0) < time()) { flock($fp, LOCK_UN); fclose($fp); return ['error'=>'draft session expired', 'code'=>410]; }
    $result = $callback($state, $fp);
    flock($fp, LOCK_UN); fclose($fp);
    return $result;
}

function dcz_draft_session_state($id) {
    return dcz_draft_with_session_lock($id, function ($state, $fp) use ($id) {
        return dcz_draft_public_state($id, $state);
    });
}

function dcz_draft_session_action($id, $expectedVersion, $action, $cardIndex = null) {
    return dcz_draft_with_session_lock($id, function ($state, $fp) use ($id, $expectedVersion, $action, $cardIndex) {
        if ($state['status'] !== 'active') return dcz_draft_public_state($id, $state);
        if ((int)$expectedVersion !== (int)$state['version']) {
            $out = dcz_draft_public_state($id, $state); $out['conflict'] = true; $out['code'] = 409; return $out;
        }

        $offerRefs = array_map('dcz_draft_player_ref', $state['cards']);
        if ($action === 'pick') {
            $idx = is_int($cardIndex) ? $cardIndex : (is_numeric($cardIndex) ? (int)$cardIndex : -1);
            if ($idx < 0 || $idx >= count($state['cards'])) return ['error'=>'invalid card', 'code'=>400];
            $p = $state['cards'][$idx];
            $slot = dcz_draft_best_slot($state, $p);
            if ($slot === null) return ['error'=>'card not compatible', 'code'=>409];
            $state['slots'][$slot['i']] = $p;
            foreach ($state['cards'] as $i => $card) if ($i !== $idx && $card) $state['discarded'][] = $card;
            $state['history'][] = [
                'v'=>$state['version'], 'offer'=>$offerRefs, 'action'=>'pick', 'index'=>$idx,
                'chosen'=>dcz_draft_player_ref($p), 'slot'=>$slot['i'],
            ];
            $state['cards'] = [];
        } elseif ($action === 'reroll') {
            $empty = 11 - count(array_filter($state['slots']));
            if ((int)$state['passes'] <= 0 || $empty <= 2) return ['error'=>'reroll not available', 'code'=>409];
            foreach ($state['cards'] as $card) if ($card) $state['discarded'][] = $card;
            $state['passes']--;
            $state['history'][] = ['v'=>$state['version'], 'offer'=>$offerRefs, 'action'=>'reroll'];
            $state['cards'] = [];
        } else {
            return ['error'=>'invalid action', 'code'=>400];
        }

        $state['version']++;
        dcz_draft_ensure_offer($state);
        if (!dcz_draft_write_locked($fp, $state)) return ['error'=>'write failed', 'code'=>500];
        return dcz_draft_public_state($id, $state);
    });
}

function dcz_draft_team_equals_final($team, $finalTeam) {
    if (!is_array($team) || !is_array($finalTeam) || count($team['players'] ?? []) !== 11 || count($finalTeam) !== 11) return false;
    foreach ($finalTeam as $i => $p) {
        if (dcz_draft_player_ref($team['players'][$i] ?? []) !== dcz_draft_player_ref($p)) return false;
    }
    return true;
}

function dcz_draft_verify_completed_session($id, $team, $expect) {
    return dcz_draft_with_session_lock($id, function ($state, $fp) use ($id, $team, $expect) {
        if ($state['status'] !== 'done' || !is_array($state['finalTeam'] ?? null)) return ['error'=>'draft not complete', 'code'=>409];
        if (!empty($state['consumedAt'])) return ['error'=>'draft session already used', 'code'=>409];
        $cfg = $state['config'] ?? [];
        foreach (['role','mode','tournament','formation','tactic'] as $key) {
            if (isset($expect[$key]) && ($cfg[$key] ?? null) !== $expect[$key]) return ['error'=>'draft context mismatch', 'code'=>400];
        }
        if (array_key_exists('eraId', $expect) && ($cfg['eraId'] ?? null) !== $expect['eraId']) return ['error'=>'draft era mismatch', 'code'=>400];
        if (array_key_exists('club', $expect) && ($cfg['club'] ?? null) !== $expect['club']) return ['error'=>'draft club mismatch', 'code'=>400];
        if (array_key_exists('duelId', $expect) && ($cfg['duelId'] ?? null) !== $expect['duelId']) return ['error'=>'draft duel mismatch', 'code'=>400];
        if (!dcz_draft_team_equals_final($team, $state['finalTeam'])) return ['error'=>'team does not match server draft', 'code'=>400];
        return [
            'ok'=>true,
            'proof'=>[
                'engine'=>$state['engine'],
                'sessionId'=>$id,
                'config'=>$cfg,
                'history'=>$state['history'],
                'createdAt'=>$state['createdAt'],
                'completedVersion'=>$state['version'],
            ],
        ];
    });
}

function dcz_draft_consume_session($id) {
    return dcz_draft_with_session_lock($id, function ($state, $fp) {
        if (!empty($state['consumedAt'])) return true;
        $state['consumedAt'] = time();
        return dcz_draft_write_locked($fp, $state);
    });
}
