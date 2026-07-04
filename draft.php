<?php
/* draft.php — viewer draft condiviso con OG dinamiche.
   Serve draft.html iniettando og:title/description/image specifici della rosa. */
$id = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['id'] ?? '');
$tpl = @file_get_contents(__DIR__ . '/draft.html');
if ($tpl === false) { http_response_code(500); exit('template missing'); }

$SITE = 'https://decempionz.com';

if (strlen($id) >= 6 && strlen($id) <= 12 && file_exists(__DIR__ . '/drafts/' . $id . '.json')) {
    $d = json_decode(file_get_contents(__DIR__ . '/drafts/' . $id . '.json'), true) ?: [];
    $tLabel = ['ucl' => 'Champions League', 'copa' => 'Copa Libertadores', 'wc' => 'World Cup'][$d['tournament'] ?? ''] ?? 'Decempionz';
    $era    = trim((string)($d['era'] ?? ''));
    $grade  = $d['grade'] ?? 'B';
    $w = (int)($d['record']['w'] ?? 0); $dr = (int)($d['record']['d'] ?? 0); $l = (int)($d['record']['l'] ?? 0);
    $win    = !empty($d['winner']);
    $lang   = $d['lang'] ?? 'it';
    $isIt   = $lang === 'it';
    $status = $win ? ($isIt ? 'Campione' : 'Champion') : ($isIt ? 'Eliminato' : 'Eliminated');
    $recStr = $isIt ? ($w.'V '.$dr.'P '.$l.'S') : ($w.'W '.$dr.'D '.$l.'L');
    $title  = 'Grade '.$grade.' · '.$status.' — '.$tLabel.($era ? ' '.$era : '').' | Decempionz';
    $names  = array_slice(array_map(function($p){ return $p['n'] ?? ''; }, (array)($d['players'] ?? [])), 0, 5);
    $desc   = ($isIt ? 'Rosa draftata su Decempionz: ' : 'Squad drafted on Decempionz: ')
            . implode(', ', array_filter($names))
            . ($isIt ? '… Record ' : '… Record ') . $recStr
            . ($isIt ? '. Riesci a fare meglio?' : '. Can you do better?');
    $img    = file_exists(__DIR__ . '/drafts/' . $id . '.jpg')
            ? $SITE . '/drafts/' . $id . '.jpg'
            : $SITE . '/og-image.png';
    $url    = $SITE . '/draft.php?id=' . $id;

    $esc = function($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
    $tpl = preg_replace('/<title>[^<]*<\/title>/', '<title>'.$esc($title).'</title>', $tpl, 1);
    $tpl = preg_replace('/(<meta name="description" content=")[^"]*(">)/', '${1}'.$esc($desc).'$2', $tpl, 1);
    $tpl = preg_replace('/(<meta property="og:title" content=")[^"]*(">)/', '${1}'.$esc($title).'$2', $tpl, 1);
    $tpl = preg_replace('/(<meta property="og:description" content=")[^"]*(">)/', '${1}'.$esc($desc).'$2', $tpl, 1);
    $hasCard = file_exists(__DIR__ . '/drafts/' . $id . '.jpg');
    $imgMeta = '<meta property="og:image" content="'.$esc($img).'">';
    if ($hasCard) {
        $imgMeta .= "\n".'<meta property="og:image:type" content="image/jpeg">'
                 .  "\n".'<meta property="og:image:width" content="1080">'
                 .  "\n".'<meta property="og:image:height" content="1080">'
                 .  "\n".'<meta property="og:image:alt" content="'.$esc($title).'">';
    }
    $tpl = preg_replace('/<meta property="og:image" content="[^"]*">/', $imgMeta, $tpl, 1);
    $tpl = preg_replace('/(<meta property="og:url" content=")[^"]*(">)/', '${1}'.$esc($url).'$2', $tpl, 1);
    // niente indicizzazione per le pagine condivise (og resta leggibile dai bot social)
    $tpl = str_replace('</head>', '<meta name="robots" content="noindex">'."\n".'</head>', $tpl);
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
echo $tpl;
