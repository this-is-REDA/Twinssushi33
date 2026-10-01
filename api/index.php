<?php
/* Point d'entrée unique pour Vercel.
   Vercel n'exécute les fichiers PHP que depuis le dossier /api : tout le trafic est
   dirigé ici par vercel.json, puis renvoyé vers les pages habituelles du site.
   Sur un hébergement PHP classique (Apache, Nginx) ce fichier ne sert à rien. */
declare(strict_types=1);

$root = dirname(__DIR__);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/');

switch ($path) {
    case '':
    case '/index.php':
        require $root . '/index.php';
        return;
    case '/admin':
    case '/admin/index.php':
        require $root . '/admin/index.php';
        return;
    case '/admin/api.php':
        require $root . '/admin/api.php';
        return;
}

http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
readfile($root . '/404.html');
