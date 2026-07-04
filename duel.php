<?php
/* duel.php — pagina duello con OG dinamiche.
   Serve duel.html iniettando og:title/description specifici (invito o verdetto). */
$id  = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['id'] ?? '');
$tpl = @file_get_contents(__DIR__ . '/duel.html');
if ($tpl === false) { http_response_code(500); exit('template missing'); }

$SITE = 'https://decempionz.com';

if (strlen($id) >= 6 && strlen($id) <= 12 && file_exists(__DIR__ . '/duels/' . $id . '.json')) {
    $d = json_decode(file_get_contents(__DIR__ . '/duels/' . $id . '.json'), true) ?: [];
    $tLabel = ['ucl' => 'Champions League', 'copa' => 'Copa Libertadores', 'wc' => 'World Cup'][$d['tournament'] ?? ''] ?? 'Decempionz';
    $era    = trim((string)($d['era'] ?? ''));
    $lang   = $d['lang'] ?? 'it';
    $isIt   = $lang === 'it';
    $isEs   = $lang === 'es';
    $isDyn  = ($d['mode'] ?? 'classic') === 'dynasty';
    $nickA  = (string)($d['a']['nick'] ?? '?');
    $clubA  = (string)($d['a']['clubName'] ?? '');
    $sideA  = ($isDyn && $clubA !== '') ? $clubA : $nickA;

    if (($d['status'] ?? '') === 'done') {
        $nickB = (string)($d['b']['nick'] ?? '?');
        $clubB = (string)($d['b']['clubName'] ?? '');
        $sideB = ($isDyn && $clubB !== '') ? $clubB : $nickB;
        $r     = $d['result'] ?? [];
        $wa    = (int)($r['winsA'] ?? 0);
        $wb    = (int)($r['winsB'] ?? 0);
        $winN  = ($r['winner'] ?? 'a') === 'a' ? $sideA : $sideB;
        $title = '⚔️ ' . $sideA . ' ' . $wa . '–' . $wb . ' ' . $sideB . ' — ' . ($isIt ? 'Duello' : ($isEs ? 'Duelo' : 'Duel')) . ($isDyn ? ' Dynasty' : '') . ' | Decempionz';
        $desc  = $isIt
            ? '👑 ' . $winN . ' vince il duello ' . $tLabel . ($era ? ' ' . $era : '') . '. Guarda le due formazioni e sfida anche tu i tuoi amici!'
            : ($isEs
                ? '👑 ' . $winN . ' gana el duelo ' . $tLabel . ($era ? ' ' . $era : '') . '. ¡Mira las alineaciones y reta a tus amigos!'
                : '👑 ' . $winN . ' wins the ' . $tLabel . ($era ? ' ' . $era : '') . ' duel. Check both line-ups and challenge your friends!');
    } else {
        $chalName = ($isDyn && $clubA !== '')
            ? ($isIt ? 'Il ' . $clubA . ' di ' . $nickA : ($isEs ? 'El ' . $clubA . ' de ' . $nickA : $nickA . "'s " . $clubA))
            : $nickA;
        $title = '⚔️ ' . $chalName . ' ' . ($isIt ? 'ti sfida a duello!' : ($isEs ? '¡te reta a un duelo!' : 'challenges you to a duel!')) . ' — Decempionz';
        $desc  = $isIt
            ? 'Duello 1v1 su ' . $tLabel . ($era ? ' ' . $era : '') . ': drafta la tua rosa di leggende e scopri chi ha costruito la squadra migliore. Serie al meglio delle 3.'
            : ($isEs
                ? 'Duelo 1v1 en ' . $tLabel . ($era ? ' ' . $era : '') . ': draftea tu plantilla de leyendas y descubre quién armó el mejor equipo. Al mejor de 3.'
                : '1v1 duel in the ' . $tLabel . ($era ? ' ' . $era : '') . ': draft your squad of legends and find out who built the better team. Best of 3.');
    }

    $url = $SITE . '/duel.php?id=' . $id;
    $esc = function ($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
    $tpl = preg_replace('/<title>[^<]*<\/title>/', '<title>' . $esc($title) . '</title>', $tpl, 1);
    $tpl = preg_replace('/(<meta name="description" content=")[^"]*(">)/', '${1}' . $esc($desc) . '$2', $tpl, 1);
    $tpl = preg_replace('/(<meta property="og:title" content=")[^"]*(">)/', '${1}' . $esc($title) . '$2', $tpl, 1);
    $tpl = preg_replace('/(<meta property="og:description" content=")[^"]*(">)/', '${1}' . $esc($desc) . '$2', $tpl, 1);
    $tpl = preg_replace('/(<meta property="og:url" content=")[^"]*(">)/', '${1}' . $esc($url) . '$2', $tpl, 1);
    /* niente indicizzazione per le pagine duello (og resta leggibile dai bot social) */
    $tpl = str_replace('</head>', '<meta name="robots" content="noindex">' . "\n" . '</head>', $tpl);
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
echo $tpl;
