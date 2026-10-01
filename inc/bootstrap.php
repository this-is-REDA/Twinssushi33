<?php
/* Twins Sushi — fonctions communes (données, sécurité) */
declare(strict_types=1);

/* Un visiteur ne doit jamais voir un message d'erreur PHP : il révèle les
   chemins des fichiers, et le moindre avertissement affiché avant les en-têtes
   empêche la page de les envoyer. Les erreurs partent dans les journaux de
   l'hébergeur, où elles servent vraiment à quelque chose. */
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('ROOT', dirname(__DIR__));
define('MAX_BACKUPS', 40);

require __DIR__ . '/supabase.php';

const BADGES = ['' => 'Aucun', 'vege' => 'Végé', 'epice' => 'Épicé', 'croustillant' => 'Croustillant',
                'bestseller' => 'Best-seller', 'nouveau' => 'Nouveau', 'signature' => 'Signature', 'partager' => 'À partager'];

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* ---------- Lecture de la carte et des réglages ----------
   Ces deux fonctions renvoient exactement la structure que le site et l'admin
   attendaient du temps des fichiers JSON : la base a changé, pas l'affichage.
   $token non nul (admin connecté) = on voit aussi les plats masqués. */

function menu(?string $token = null): array {
    $rows = sb_select('categories', 'select=id,title,jp,visible,items(code,name,description,price,pcs,show_pcs,badge,image,available,visible,sort_order)&order=sort_order', $token);
    $cats = [];
    foreach ($rows as $c) {
        $items = $c['items'] ?? [];
        usort($items, fn($a, $b) => ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0));
        $cats[] = [
            'id'      => (string)$c['id'],
            'title'   => (string)$c['title'],
            'jp'      => (string)($c['jp'] ?? ''),
            'visible' => (bool)($c['visible'] ?? true),
            'items'   => array_map(fn($i) => [
                'code'      => (string)$i['code'],
                'name'      => (string)$i['name'],
                'desc'      => (string)($i['description'] ?? ''),
                'price'     => $i['price'] === null ? null : (int)$i['price'],
                'pcs'       => (int)($i['pcs'] ?? 1),
                'showPcs'   => (bool)($i['show_pcs'] ?? false),
                'badge'     => (string)($i['badge'] ?? ''),
                'img'       => (string)($i['image'] ?? ''),
                'available' => (bool)($i['available'] ?? true),
                'visible'   => (bool)($i['visible'] ?? true),
            ], $items),
        ];
    }
    return ['categories' => $cats];
}

function settings(?string $token = null): array {
    $out = [];
    foreach (sb_select('settings', 'select=key,value', $token) as $r) {
        $out[(string)$r['key']] = $r['value'];
    }
    $hours = sb_select('opening_hours', 'select=label,days,opens,closes,display&order=sort_order', $token);
    $out['horaires'] = array_map(fn($h) => [
        'label' => (string)$h['label'],
        'days'  => (string)$h['days'],
        // La base stocke un type « time » (11:00:00), le site affiche 11:00
        'open'  => substr((string)$h['opens'], 0, 5),
        'close' => substr((string)$h['closes'], 0, 5),
        'text'  => (string)$h['display'],
    ], $hours);
    return $out;
}

function price_label(array $it): string {
    return $it['price'] === null || $it['price'] === '' ? '' : ((int)$it['price']) . ' DH';
}

/* ---------- Connexion admin : jetons Supabase ----------
   Supabase Auth vérifie l'e-mail et le mot de passe, et renvoie deux jetons.
   Rien n'est conservé côté serveur : l'admin fonctionne donc même sur un
   hébergement où chaque requête tombe sur une machine différente.
   L'autorisation réelle n'est jamais décidée ici : chaque écriture voyage avec
   le jeton jusqu'à la base, qui applique ses règles RLS. Les fonctions
   ci-dessous ne servent qu'à savoir s'il est utile d'essayer. */
const ADMIN_COOKIE   = 'twins_admin';
const REFRESH_COOKIE = 'twins_refresh';
const CSRF_COOKIE    = 'twins_csrf';

function b64url_decode(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/')); }

function admin_cookie_options(int $expires): array {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ['expires' => $expires, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict'];
}

/* Dépose les jetons dans des cookies inaccessibles au JavaScript et renvoie
   le jeton CSRF que le navigateur devra renvoyer en en-tête. */
function admin_store(array $session): string {
    setcookie(ADMIN_COOKIE, $session['access_token'], admin_cookie_options(0));
    $_COOKIE[ADMIN_COOKIE] = $session['access_token'];
    if (($session['refresh_token'] ?? '') !== '') {
        setcookie(REFRESH_COOKIE, $session['refresh_token'], admin_cookie_options(0));
        $_COOKIE[REFRESH_COOKIE] = $session['refresh_token'];
    }
    /* Le jeton CSRF survit au renouvellement du jeton d'accès, sinon toutes les
       heures la page afficherait « Jeton de sécurité invalide ». */
    if (csrf_token() === '') {
        $csrf = bin2hex(random_bytes(16));
        setcookie(CSRF_COOKIE, $csrf, admin_cookie_options(0));
        $_COOKIE[CSRF_COOKIE] = $csrf;
    }
    return csrf_token();
}

function admin_logout(): void {
    $token = admin_token();
    if ($token !== '') sb_sign_out($token);
    foreach ([ADMIN_COOKIE, REFRESH_COOKIE, CSRF_COOKIE] as $c) {
        setcookie($c, '', admin_cookie_options(time() - 3600));
        unset($_COOKIE[$c]);
    }
}

function admin_token(): string { return (string)($_COOKIE[ADMIN_COOKIE] ?? ''); }

/* Lit la charge utile du jeton SANS vérifier sa signature : uniquement pour
   afficher l'e-mail et repérer une expiration. Jamais pour autoriser. */
function admin_claims(): ?array {
    $p = explode('.', admin_token());
    if (count($p) !== 3) return null;
    $c = json_decode(b64url_decode($p[1]), true);
    if (!is_array($c) || empty($c['sub'])) return null;
    if ((int)($c['exp'] ?? 0) <= time()) return null;
    return $c;
}

function is_admin(): bool { return admin_claims() !== null; }
function admin_user(): string { return (string)(admin_claims()['email'] ?? ''); }

/* Jeton expiré : on tente un renouvellement silencieux avec le second cookie,
   pour éviter de redemander le mot de passe toutes les heures. */
function admin_renew(): bool {
    $r = (string)($_COOKIE[REFRESH_COOKIE] ?? '');
    if ($r === '') return false;
    $session = sb_refresh($r);
    if ($session === null) return false;
    admin_store($session);
    return true;
}

/* Le navigateur renvoie ce jeton en en-tête ; un autre site ne peut ni lire le
   cookie qui le contient, ni lire la réponse de l'API pour le découvrir. */
function csrf_token(): string { return (string)($_COOKIE[CSRF_COOKIE] ?? ''); }
