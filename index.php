<?php
/* Twins Sushi — page publique, générée à partir de data/menu.json et data/settings.json */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
date_default_timezone_set('Africa/Casablanca');

$S = settings();
$M = menu();
$v = fn(string $f) => $f . '?v=' . (@filemtime(__DIR__ . '/' . $f) ?: 1);

$BADGE_LABEL = BADGES;
$today = date('Y-m-d');
$showRibbon = !empty($S['bandeau_texte']) && (empty($S['bandeau_fin']) || $today <= $S['bandeau_fin']);

// Catégories et plats visibles
$cats = [];
$nb = 0;
foreach ($M['categories'] as $c) {
    if (isset($c['visible']) && !$c['visible']) continue;
    $items = array_values(array_filter($c['items'], fn($i) => !isset($i['visible']) || $i['visible']));
    if (!$items) continue;
    $c['items'] = $items;
    $cats[] = $c;
    $nb += count($items);
}

// Données structurées Google (Restaurant + carte)
$daymap = ['0' => 'Sunday', '1' => 'Monday', '2' => 'Tuesday', '3' => 'Wednesday', '4' => 'Thursday', '5' => 'Friday', '6' => 'Saturday'];
$ld = [
    '@context' => 'https://schema.org', '@type' => 'Restaurant', 'name' => 'Twins Sushi',
    'url' => $S['url'], 'image' => $S['url'] . '/assets/img/og.jpg', 'logo' => $S['url'] . '/assets/img/icon-512.png',
    'telephone' => $S['tel'], 'email' => $S['email'], 'servesCuisine' => ['Sushi', 'Japonaise', 'Asiatique'],
    'currenciesAccepted' => 'MAD', 'acceptsReservations' => false,
    'address' => ['@type' => 'PostalAddress', 'streetAddress' => $S['rue'], 'addressLocality' => $S['ville'],
                  'postalCode' => $S['cp'], 'addressRegion' => 'Casablanca-Settat', 'addressCountry' => 'MA'],
    'openingHoursSpecification' => array_map(fn($hh) => ['@type' => 'OpeningHoursSpecification',
        'dayOfWeek' => array_map(fn($d) => $daymap[trim($d)] ?? '', explode(',', $hh['days'])),
        'opens' => $hh['open'], 'closes' => $hh['close']], $S['horaires']),
    'sameAs' => array_values(array_filter([$S['instagram'] ?? '', $S['tiktok'] ?? '', $S['facebook'] ?? ''])),
    'hasMenu' => ['@type' => 'Menu', 'name' => 'La carte Twins Sushi', 'inLanguage' => 'fr', 'hasMenuSection' => array_map(fn($c) => [
        '@type' => 'MenuSection', 'name' => $c['title'], 'hasMenuItem' => array_map(function ($i) {
            $o = ['@type' => 'MenuItem', 'name' => $i['name'], 'description' => $i['desc']];
            if ($i['price'] !== null && $i['price'] !== '') $o['offers'] = ['@type' => 'Offer', 'price' => (string)$i['price'], 'priceCurrency' => 'MAD'];
            if ($i['badge'] === 'vege') $o['suitableForDiet'] = 'https://schema.org/VegetarianDiet';
            return $o;
        }, $c['items'])], $cats)],
];
$prices = [];
foreach ($cats as $c) foreach ($c['items'] as $i) if ($i['price'] !== null && $i['price'] !== '') $prices[] = (int)$i['price'];
if ($prices) $ld['priceRange'] = min($prices) . ' – ' . max($prices) . ' MAD';

$maps = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode('Twins Sushi ' . $S['rue'] . ' ' . $S['ville']);
$waOrder = 'https://wa.me/' . $S['wa'] . '?text=' . rawurlencode('Bonjour Twins Sushi, je souhaite passer une commande à emporter.');
$desc = "Twins Sushi, sushis et cuisine asiatique à Maârif, Casablanca. Makis, california, chirashi, poké, bentos et box. Livraison et vente à emporter, 7j/7.";
$social = [];
foreach (['instagram' => 'Instagram', 'tiktok' => 'TikTok', 'facebook' => 'Facebook'] as $k => $lbl)
    if (!empty($S[$k])) $social[] = '<a href="' . h($S[$k]) . '" target="_blank" rel="noopener">' . $lbl . '</a>';

function app_link(string $label, string $url): string {
    return $url ? '<a class="app" href="' . h($url) . '" target="_blank" rel="noopener">' . $label . '</a>'
                : '<span class="app soon">' . $label . ' <em>bientôt</em></span>';
}
require __DIR__ . '/inc/icons.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=120');
?><!doctype html>
<html lang="fr" class="no-js">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Twins Sushi · Sushis à Maârif, Casablanca · Livraison et à emporter</title>
<meta name="description" content="<?= h($desc) ?>">
<link rel="canonical" href="<?= h($S['url']) ?>/">
<meta name="theme-color" content="#FBF6EF" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#1A1312" media="(prefers-color-scheme: dark)">
<meta name="color-scheme" content="light dark">
<script>try{var t=localStorage.getItem("twins-theme");if(t==="dark"||t==="light")document.documentElement.setAttribute("data-theme",t)}catch(e){}</script>
<link rel="icon" type="image/png" href="favicon.png"><link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
<link rel="manifest" href="site.webmanifest">
<meta property="og:type" content="restaurant"><meta property="og:locale" content="fr_MA"><meta property="og:site_name" content="Twins Sushi">
<meta property="og:title" content="Twins Sushi · Sushis à Maârif, Casablanca"><meta property="og:description" content="<?= h($desc) ?>">
<meta property="og:url" content="<?= h($S['url']) ?>/"><meta property="og:image" content="<?= h($S['url']) ?>/assets/img/og.jpg"><meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image"><meta name="twitter:image" content="<?= h($S['url']) ?>/assets/img/og.jpg">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@1,500&family=Noto+Serif+JP:wght@400&family=Poppins:wght@400;500;600;700&display=swap" media="print" onload="this.media='all'"><noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@1,500&family=Noto+Serif+JP:wght@400&family=Poppins:wght@400;500;600;700&display=swap"></noscript>
<link rel="preload" as="image" href="assets/drip/000.webp" fetchpriority="high" media="(min-width: 861px)"><link rel="preload" as="image" href="assets/drip/m/000.webp" fetchpriority="high" media="(max-width: 860px)">
<link rel="stylesheet" href="<?= $v('assets/style.css') ?>">
<link rel="stylesheet" href="<?= $v('assets/home.css') ?>">
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
</head>
<body>
<a class="skip" href="#carte">Aller à la carte</a>
<?php if ($showRibbon): ?><div class="ribbon" role="note"><?= h($S['bandeau_texte']) ?></div><?php endif; ?>
<?php if (!empty($S['annonce'])): ?><div class="annonce" role="note"><?= h($S['annonce']) ?></div><?php endif; ?>
<header class="bar"><div class="wrap">
<a class="brand" href="#top" aria-label="Twins Sushi, haut de page"><img class="logo-l" src="assets/img/logo-96.webp" alt="Twins Sushi" width="53" height="48"><img class="logo-d" src="assets/img/logo-creme-96.webp" alt="Twins Sushi" width="53" height="48"><span>TWINS SUSHI</span></a>
<nav class="nav" aria-label="Navigation principale"><a href="#carte">La carte</a><a href="#commander">Commander</a><a href="#infos">Infos pratiques</a></nav>
<button class="theme" type="button" aria-label="Changer de thème (clair / sombre)" title="Clair / sombre"><?= $ICON['sun'] ?><?= $ICON['moon'] ?></button>
<button class="cart-btn" type="button" data-open-cart aria-label="Voir ma commande"><?= $ICON['bag'] ?><span class="lbl">Ma commande</span><span class="n cart-count">0</span></button>
</div></header>

<main id="top">
<?php
/* ---------- Données des scènes animées ---------- */
$byCode = [];
foreach ($cats as $c) foreach ($c['items'] as $i) $byCode[$i['code']] = $i + ['cat' => $c['title'], 'cid' => $c['id'], 'jp' => $c['jp'] ?? ''];
$sigCodes = ['TR01' => 'Le tartare, monté comme une tour : saumon frais, avocat crémeux, riz vinaigré. Simple. Frais. Saumon.',
             'CH05' => 'Un bol généreux de riz vinaigré, saumon frais, mangue et avocat. Le soleil dans un bol.',
             'CA05' => 'Surimi, concombre, avocat et cream cheese, roulés dans le tobiko. Celui qu’on prend les yeux fermés.',
             'PK01' => 'Riz vinaigré, saumon, wakamé, avocat et surimi. Frais, coloré, complet.'];
$sigs = [];
foreach ($sigCodes as $code => $txt) if (isset($byCode[$code])) $sigs[] = $byCode[$code] + ['txt' => $txt, 'hd' => "assets/hd/" . strtolower($code) . ".webp"];
$boxCodes = ['CA05', 'CA03', 'CA02', 'CA08', 'MK05', 'MK02', 'FR01', 'FR06'];
$boxItems = [];
foreach ($boxCodes as $code) if (isset($byCode[$code]) && $byCode[$code]['price'] !== null && (!isset($byCode[$code]['available']) || $byCode[$code]['available'])) $boxItems[] = $byCode[$code];
$gallery = [
  ['g08641.webp', 'Plateau california', true], ['g08661.webp', 'California tobiko'], ['g06831.webp', 'Assortiment'], ['g07110.webp', 'Chirashi', true],
  ['g08250.webp', 'Bento'], ['g08714.webp', 'Fry croustillant'], ['g07418.webp', 'Nigiri saumon'], ['g06958.webp', 'Salade gambas'],
  ['g08722.webp', 'Rouleaux'], ['g06865.webp', 'Makis'], ['g08322.webp', 'Bento ouvert'], ['g07930.webp', 'Nems Nutella'], ['g08783.webp', 'Grande box'],
];
$nbCats = count($cats);
$minP = $prices ? min($prices) : 0;
?>
<!-- Scène 1 : la sauce qui coule -->
<section class="scene drip" aria-label="Laissez couler">
<div class="pin">
<span class="kanji" aria-hidden="true">寿司</span>
<div class="txt">
<span class="kicker">Sushis &amp; cuisine asiatique · Maârif, Casablanca</span>
<h1 class="display"><span class="w"><span>Laissez</span></span> <span class="w"><span>couler.</span></span><span class="l2"><span class="w"><span>Trempez.</span></span> <span class="w"><span>Savourez.</span></span></span></h1>
<p class="sub">Makis, california, chirashi, poké et bentos préparés chaque jour dans notre cuisine du <?= h($S['rue']) ?>. En livraison et à emporter, 7j/7.</p>
<div class="ctas"><a class="btn btn-plein" href="#carte">Voir la carte</a><a class="btn btn-ligne" href="#commander">Commander</a></div>
<div class="steps" aria-hidden="true">
<span class="step" data-a="0.05" data-b="0.4">Un california, tobiko rouge.</span>
<span class="step" data-a="0.4" data-b="0.75">Sauce soja maison.</span>
<span class="step" data-a="0.75" data-b="1.01">Laissez couler.</span>
</div>
</div>
<div class="stage">
<canvas id="drip" data-frames="95" data-src="assets/drip/" aria-hidden="true"></canvas>
<img class="poster" src="assets/drip/094.webp" alt="California roll trempé dans la sauce soja" width="560" height="1108">
</div>
<div class="hint"><i></i>Faites défiler</div>
</div>
</section>

<div class="marquee" aria-hidden="true"><div class="track">
<?php $mq = ''; foreach (array_slice($cats, 0, 14) as $c) $mq .= '<span>' . h($c['title']) . (!empty($c['jp']) ? ' <em lang="ja">' . h($c['jp']) . '</em>' : '') . '</span>'; echo $mq . $mq; ?>
</div></div>

<!-- Scène 2 : manifeste -->
<section class="manif" aria-labelledby="h-manif">
<div class="float f1"><img src="assets/hd/ng01.webp" alt="" width="500" height="500" loading="lazy"></div>
<div class="float f2"><img src="assets/hd/mk05.webp" alt="" width="500" height="500" loading="lazy"></div>
<div class="float f3"><img src="assets/hd/ca02.webp" alt="" width="500" height="500" loading="lazy"></div>
<div class="wrap">
<span class="kicker rv">La maison</span>
<h2 class="display" id="h-manif"><span class="w"><span>Simple.</span></span> <span class="w"><span>Frais.</span></span> <span class="w"><span>Gourmand.</span></span></h2>
<p class="lead rv" data-d="1">Twins Sushi est une cuisine de sushis et de plats asiatiques à Maârif. Du poisson frais chaque matin, du riz vinaigré à la minute, et une carte qui va des makis aux nouilles sautées au wok. Livrée chez vous ou à emporter, au prix de la carte.</p>
<div class="stats rv" data-d="2">
<div class="stat"><b data-to="<?= $nb ?>">0</b><span>plats à la carte</span></div>
<div class="stat"><b data-to="<?= $nbCats ?>">0</b><span>familles de plats</span></div>
<div class="stat"><b>7<small>j/7</small></b><span>midi et soir</span></div>
<div class="stat"><b data-to="<?= $minP ?>">0<small> DH</small></b><span>dès</span></div>
</div>
</div>
</section>

<?php if ($sigs): ?>
<!-- Scène 3 : signatures -->
<section class="scene signatures" aria-labelledby="h-sig">
<div class="pin">
<div class="head"><span class="kicker" id="h-sig">Nos signatures</span><span class="rule"></span><div class="dots" aria-hidden="true"><?php foreach ($sigs as $x): ?><i></i><?php endforeach; ?></div></div>
<div class="plate"><div class="halo"></div><?php foreach ($sigs as $k => $x): ?><img src="<?= h($x['hd']) ?>" alt="<?= h($x['name']) ?>" width="1000" height="1000"<?= $k ? ' loading="lazy"' : '' ?>><?php endforeach; ?></div>
<div class="sigp">
<?php foreach ($sigs as $k => $x): ?>
<div class="item">
<span class="n"><?= str_pad((string)($k + 1), 2, '0', STR_PAD_LEFT) ?> / <?= str_pad((string)count($sigs), 2, '0', STR_PAD_LEFT) ?> · <?= h($x['cat']) ?></span>
<h3><?= h($x['name']) ?></h3><?php if ($x['jp']): ?><span class="jp" lang="ja"><?= h($x['jp']) ?></span><?php endif; ?>
<p><?= h($x['txt']) ?></p>
<div class="pr"><?php if ($x['price'] !== null): ?><span class="price"><?= (int)$x['price'] ?> DH<?php if (!empty($x['showPcs'])): ?> <span>· <?= (int)$x['pcs'] ?> PCS</span><?php endif; ?></span><?php endif; ?>
<a class="btn btn-ligne" href="#<?= h($x['cid']) ?>" data-goto="<?= h($x['code']) ?>">Voir sur la carte</a></div>
</div>
<?php endforeach; ?>
</div>
</div>
</section>
<?php endif; ?>

<!-- Scène 4 : galerie (désactivée pour l’instant, voir GALERIE_DESACTIVEE dans inc/) -->
<div class="waves" role="presentation"></div>

<?php if (count($boxItems) >= 4): ?>
<!-- Scène 5 : composez votre box -->
<section class="box" id="composer" aria-labelledby="h-box"><div class="wrap">
<div class="grid2">
<div>
<span class="kicker rv">À composer</span>
<h2 class="display" id="h-box"><span class="w"><span>Votre</span></span> <span class="w"><span>box,</span></span> <span class="w"><span>vos</span></span> <span class="w"><span>rolls.</span></span></h2>
<p class="lead rv" data-d="1">Choisissez 4 rolls, on prépare la box. Le prix, c’est simplement celui de chaque roll : pas de supplément.</p>
<div class="picks rv" data-d="2">
<?php foreach ($boxItems as $x): ?>
<button class="pick" type="button" data-code="<?= h($x['code']) ?>" data-name="<?= h($x['name']) ?>" data-price="<?= (int)$x['price'] ?>" aria-disabled="false">
<span class="q">×1</span><span class="minus" role="button" aria-label="Retirer un <?= h($x['name']) ?>" tabindex="0">−</span><img src="<?= h($x['img']) ?>" alt="" width="440" height="440" loading="lazy"><b><?= h(preg_replace('/^(California|Maki|Fry) /', '', $x['name'])) ?></b><i><?= (int)$x['price'] ?> DH · <?= (int)$x['pcs'] ?> pcs</i>
</button>
<?php endforeach; ?>
</div>
</div>
<div class="bento">
<h3>Ma box</h3><div class="cap"><span id="box-count">0/4</span> rolls choisis · <button type="button" id="box-clear" class="lnk">tout retirer</button></div>
<div class="slots"><div class="slot"></div><div class="slot"></div><div class="slot"></div><div class="slot"></div></div>
<div class="tot"><span>Total</span><b id="box-total">0 DH</b></div>
<a class="btn" id="box-add" href="#panier" aria-disabled="true">Ajouter à ma commande</a>
<p class="note">Touchez un roll dans la box pour le retirer. Les rolls s’ajoutent ensuite à votre commande à emporter, envoyée sur WhatsApp.</p>
<p class="box-msg" id="box-msg" role="status" aria-live="polite"></p>
</div>
</div>
</div></section>
<?php endif; ?>

<section class="sec" id="commander" aria-labelledby="h-cmd"><div class="wrap">
<div class="sec-head"><h2 id="h-cmd">Commandez</h2><span class="jp" lang="ja">ご注文</span><span class="rule"></span></div>
<div class="order-grid">
<div class="box-rouge">
<img class="logo-c" src="assets/img/logo-creme-240.webp" alt="" width="120" height="110" loading="lazy">
<div><h3>Livré chez vous</h3><p>Retrouvez toute la carte sur vos applications de livraison, aux mêmes noms et mêmes photos.</p>
<div class="apps"><?= app_link('Glovo', $S['glovo'] ?? '') . app_link('Yassir', $S['yassir'] ?? '') . app_link('Kool', $S['kool'] ?? '') ?></div></div>
</div>
<div class="box-creme">
<h3>À emporter</h3>
<p class="addr"><?= h($S['rue']) ?> · <?= h($S['quartier']) ?> · <?= h($S['ville']) ?></p>
<p>Commandez sur WhatsApp ou par téléphone, passez récupérer votre commande à la cuisine. Sans commission, au prix de la carte.</p>
<div class="row">
<a class="btn btn-plein" href="<?= h($waOrder) ?>" target="_blank" rel="noopener"><?= $ICON['wa'] ?>WhatsApp</a>
<a class="btn btn-ligne" href="tel:<?= h($S['tel']) ?>"><?= $ICON['tel'] ?><?= h($S['tel_affiche']) ?></a>
</div>
</div>
</div>
<p class="tip"><b>Astuce</b> Composez votre commande avec le bouton + sur la carte : on reçoit la liste complète sur WhatsApp, prête à préparer.</p>
</div></section>

<section class="sec sec-carte" id="carte" aria-labelledby="h-carte"><div class="wrap">
<div class="sec-head"><h2 id="h-carte">La carte</h2><span class="jp" lang="ja">お品書き</span><span class="rule"></span></div>
<div class="menu-tools">
<div class="tools-row">
<label class="search" for="q"><?= $ICON['search'] ?><span class="sr">Rechercher un plat</span><input id="q" type="search" placeholder="Saumon, crevette, avocat…" autocomplete="off" enterkeyhint="search"></label>
<div class="filters"><button class="fchip vege" type="button" data-f="vege" aria-pressed="false"><i></i>Végé</button><button class="fchip epice" type="button" data-f="epice" aria-pressed="false"><i></i>Épicé</button></div>
</div>
<nav class="cats" aria-label="Catégories de la carte"><?php foreach ($cats as $c): ?><a href="#<?= h($c['id']) ?>"><?= h($c['title']) ?></a><?php endforeach; ?></nav>
</div>
<?php foreach ($cats as $c): ?>
<section class="cat" id="<?= h($c['id']) ?>" aria-labelledby="h-<?= h($c['id']) ?>">
<div class="cat-head"><h3 id="h-<?= h($c['id']) ?>"><?= h($c['title']) ?></h3><?php if (!empty($c['jp'])): ?><span class="jp" lang="ja"><?= h($c['jp']) ?></span><?php endif; ?><span class="rule"></span><span class="count"><?= count($c['items']) ?> plat<?= count($c['items']) > 1 ? 's' : '' ?></span></div>
<div class="grid">
<?php foreach ($c['items'] as $i):
    $hasPrice = $i['price'] !== null && $i['price'] !== '';
    $avail = !isset($i['available']) || $i['available'];
    $n = $hasPrice && $avail ? (int)$i['price'] : 0;
    $badge = $i['badge'] ?? '';
?>
<article class="p<?= $avail ? '' : ' off' ?>" data-code="<?= h($i['code']) ?>" data-name="<?= h($i['name']) ?>" data-price="<?= $n ?>" data-badge="<?= h($badge) ?>" data-search="<?= h($i['name'] . ' ' . $i['desc'] . ' ' . $c['title']) ?>">
<div class="ph"><?php if (!$avail): ?><span class="badge off">Indisponible</span><?php elseif ($badge && isset($BADGE_LABEL[$badge])): ?><span class="badge <?= h($badge) ?>"><?= h($BADGE_LABEL[$badge]) ?></span><?php endif; ?>
<?php if (!empty($i['img'])): ?><img src="<?= h($i['img']) ?>" alt="<?= h($i['name']) ?>" loading="lazy" decoding="async" width="238" height="238"><?php else: ?><span class="nophoto">Photo à venir</span><?php endif; ?></div>
<h4><?= h($i['name']) ?></h4><p class="d"><?= h($i['desc']) ?></p>
<div class="foot"><?php if ($hasPrice): ?><span class="price"><?= (int)$i['price'] ?> DH<?php if (!empty($i['showPcs']) && (int)$i['pcs'] > 1): ?> <span>· <?= (int)$i['pcs'] ?> PCS</span><?php endif; ?></span><?php else: ?><span class="price tbd">Prix à venir</span><?php endif; ?>
<button class="add" type="button" aria-label="Ajouter <?= h($i['name']) ?> à ma commande"<?= $n ? '' : ' disabled' ?>><?= $ICON['plus'] ?></button></div>
</article>
<?php endforeach; ?>
</div></section>
<?php endforeach; ?>
<p class="empty" hidden>Aucun plat ne correspond à votre recherche.</p>
</div></section>

<div class="waves" role="presentation"></div>
<section class="sec infos" id="infos" aria-labelledby="h-infos"><div class="wrap">
<div class="sec-head"><h2 id="h-infos">Infos pratiques</h2><span class="jp" lang="ja">店舗情報</span><span class="rule"></span></div>
<p class="about"><em>Simple. Frais. Gourmand.</em><br>Twins Sushi est une cuisine de sushis et de plats asiatiques à Maârif, Casablanca, en livraison et à emporter.</p>
<div class="info-grid">
<div class="info"><h3>Adresse</h3><p><?= h($S['rue']) ?><br><?= h($S['quartier']) ?>, <?= h($S['cp']) ?> <?= h($S['ville']) ?></p><p><a href="<?= h($maps) ?>" target="_blank" rel="noopener">Itinéraire Google Maps</a></p></div>
<div class="info"><h3>Horaires</h3><table class="hours"><tbody><?php foreach ($S['horaires'] as $hh): ?><tr data-days="<?= h($hh['days']) ?>"><td><?= h($hh['label']) ?></td><td><?= h($hh['text']) ?></td></tr><?php endforeach; ?></tbody></table></div>
<div class="info"><h3>Contact</h3>
<p>Téléphone : <a href="tel:<?= h($S['tel']) ?>"><?= h($S['tel_affiche']) ?></a><br>WhatsApp : <a href="https://wa.me/<?= h($S['wa']) ?>" target="_blank" rel="noopener"><?= h($S['wa_affiche']) ?></a><?php if (!empty($S['email'])): ?><br>E-mail : <a href="mailto:<?= h($S['email']) ?>"><?= h($S['email']) ?></a><?php endif; ?></p>
<?php if ($social): ?><p><?= implode(' · ', $social) ?></p><?php endif; ?>
<p class="small">Allergies ou intolérances ? Précisez-le à la commande.</p></div>
</div>
<div class="map" data-src="https://www.google.com/maps?q=<?= rawurlencode($S['rue'] . ' ' . $S['ville']) ?>&amp;output=embed"><button type="button" class="btn btn-ligne map-load"><?= $ICON['pin'] ?>Afficher le plan</button></div>
</div></section>
</main>

<footer><div class="waves" role="presentation"></div>
<div class="foot-site wrap">
<div class="sig">Twins Sushi · Casablanca</div>
<div class="links"><a href="#carte">La carte</a><a href="#commander">Commander</a><a href="#infos">Infos pratiques</a><?= implode('', $social) ?></div>
<div>Prix en dirhams TTC. © <?= date('Y') ?> Twins Sushi</div>
</div></footer>

<div class="fab"><button class="cart-btn" type="button" data-open-cart><?= $ICON['bag'] ?>Voir ma commande<span class="n cart-count">0</span></button></div>

<div class="drawer" id="panier" hidden role="dialog" aria-modal="true" aria-labelledby="h-panier">
<div class="panel">
<header><h2 id="h-panier">Ma commande à emporter</h2><button class="x" type="button" data-close aria-label="Fermer">✕</button></header>
<div class="body">
<div id="lines"></div>
<form class="form" onsubmit="return false">
<label for="c-nom">Votre prénom<input id="c-nom" type="text" autocomplete="given-name" placeholder="Ex. Yasmine" maxlength="40"></label>
<label for="c-heure">Heure de retrait<select id="c-heure"><option>Dès que possible</option></select></label>
<label for="c-note">Remarque (facultatif)<textarea id="c-note" placeholder="Sans wasabi, sauce en plus…" maxlength="300"></textarea></label>
</form>
</div>
<footer>
<div class="total"><span>Total</span><b id="total">0 DH</b></div>
<a class="btn btn-wa" id="send" href="#" target="_blank" rel="noopener" aria-disabled="true"><?= $ICON['wa'] ?>Envoyer sur WhatsApp</a>
<p class="note">Votre commande s’ouvre dans WhatsApp, prête à envoyer. Nous vous confirmons l’heure de retrait au <?= h($S['wa_affiche']) ?>.</p>
</footer>
</div></div>
<div class="toast" role="status" aria-live="polite"></div>
<script>window.TWINS={wa:<?= json_encode($S['wa']) ?>};</script>
<script src="<?= $v('assets/app.js') ?>" defer></script>
<script src="<?= $v('assets/home.js') ?>" defer></script>
</body>
</html>
