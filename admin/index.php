<?php
/* Twins Sushi — espace admin */
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
/* Le serveur PHP intégré sert /admin sans slash : le navigateur cherche alors
   admin.js à la racine et la page reste sur « Chargement… ». Apache redirige déjà. */
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (str_starts_with($path, '/') && !str_starts_with($path, '//') && !str_ends_with($path, '/')) {
        $qs = $_SERVER['QUERY_STRING'] ?? '';
        header('Location: ' . $path . '/' . ($qs !== '' ? '?' . $qs : ''), true, 301);
        exit;
    }
}
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
$v = fn(string $f) => $f . '?v=' . (@filemtime(__DIR__ . '/' . $f) ?: 1);
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Admin · Twins Sushi</title>
<link rel="icon" type="image/png" href="../favicon.png">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@1,500&family=Poppins:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="<?= $v('admin.css') ?>">
</head>
<body>
<div id="app" aria-live="polite"><p class="boot">Chargement…</p></div>
<script src="<?= $v('admin.js') ?>"></script>
</body>
</html>
