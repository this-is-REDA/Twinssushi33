<?php
/* Twins Sushi — fonctions communes (données, sécurité) */
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
define('DATA', ROOT . '/data');
define('UPLOADS', ROOT . '/assets/menu/uploads');
define('MAX_BACKUPS', 40);

const BADGES = ['' => 'Aucun', 'vege' => 'Végé', 'epice' => 'Épicé', 'croustillant' => 'Croustillant',
                'bestseller' => 'Best-seller', 'nouveau' => 'Nouveau', 'signature' => 'Signature', 'partager' => 'À partager'];

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function read_json(string $name): array {
    $f = DATA . "/$name.json";
    if (!is_file($f)) return [];
    $fp = fopen($f, 'rb');
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN); fclose($fp);
    $d = json_decode($raw ?: '[]', true);
    return is_array($d) ? $d : [];
}

/* Écriture atomique + copie de sauvegarde de la version précédente */
function write_json(string $name, array $data, bool $backup = true): void {
    $f = DATA . "/$name.json";
    if ($backup && is_file($f) && $name !== 'admin' && $name !== 'throttle') {
        $dir = DATA . '/backups';
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        $stamp = date('Ymd-His'); $dest = sprintf('%s/%s-%s.json', $dir, $name, $stamp); $n = 2;
        while (is_file($dest)) $dest = sprintf('%s/%s-%s-%d.json', $dir, $name, $stamp, $n++);
        copy($f, $dest);
        $old = glob("$dir/$name-*.json") ?: [];
        sort($old);
        while (count($old) > MAX_BACKUPS) @unlink(array_shift($old));
    }
    $tmp = $f . '.tmp' . bin2hex(random_bytes(4));
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('Écriture impossible');
    rename($tmp, $f);
}

function menu(): array { return read_json('menu') ?: ['categories' => []]; }
function settings(): array { return read_json('settings'); }

function price_label(array $it): string {
    return $it['price'] === null || $it['price'] === '' ? '' : ((int)$it['price']) . ' DH';
}

/* ---------- Identifiants admin ---------- */
function admin_file(): string { return DATA . '/admin.php'; }
function admin_conf(): array { $f = admin_file(); return is_file($f) ? (array)(require $f) : []; }

/* ---------- Connexion admin : jeton JWT signé, sans session serveur ----------
   Aucun état n'est conservé côté serveur, ce qui permet à l'admin de fonctionner
   sur un hébergement où chaque requête peut tomber sur une machine différente.
   La clé de signature est dérivée du mot de passe chiffré : changer de mot de
   passe invalide donc immédiatement tous les jetons déjà émis. */
const ADMIN_COOKIE = 'twins_admin';
const ADMIN_TTL = 8 * 3600;

function jwt_secret(): string {
    $env = getenv('TWINS_JWT_SECRET');
    if (is_string($env) && $env !== '') return $env;
    $hash = admin_conf()['hash'] ?? '';
    return $hash === '' ? '' : hash_hmac('sha256', 'twins-admin-jwt', $hash, true);
}

function b64url(string $raw): string { return rtrim(strtr(base64_encode($raw), '+/', '-_'), '='); }
function b64url_decode(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/')); }

function jwt_sign(array $claims): string {
    $head = b64url((string)json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $body = b64url((string)json_encode($claims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $head . '.' . $body . '.' . b64url(hash_hmac('sha256', "$head.$body", jwt_secret(), true));
}

function jwt_verify(string $token): ?array {
    $secret = jwt_secret();
    if ($secret === '') return null;
    $p = explode('.', $token);
    if (count($p) !== 3) return null;
    [$head, $body, $sig] = $p;
    if (!hash_equals(b64url(hash_hmac('sha256', "$head.$body", $secret, true)), $sig)) return null;
    $alg = json_decode(b64url_decode($head), true);
    // Refus explicite de « alg: none » et de tout autre algorithme
    if (!is_array($alg) || ($alg['alg'] ?? '') !== 'HS256') return null;
    $claims = json_decode(b64url_decode($body), true);
    if (!is_array($claims) || empty($claims['sub']) || empty($claims['jti'])) return null;
    if ((int)($claims['exp'] ?? 0) <= time()) return null;
    return $claims;
}

function admin_cookie_options(int $expires): array {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ['expires' => $expires, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict'];
}

/* Ouvre la session du navigateur et renvoie le jeton CSRF à lui transmettre. */
function admin_login(string $user): string {
    $jti = bin2hex(random_bytes(16));
    $token = jwt_sign(['sub' => $user, 'jti' => $jti, 'iat' => time(), 'exp' => time() + ADMIN_TTL]);
    setcookie(ADMIN_COOKIE, $token, admin_cookie_options(0));
    $_COOKIE[ADMIN_COOKIE] = $token;
    return csrf_for($jti);
}
function admin_logout(): void {
    setcookie(ADMIN_COOKIE, '', admin_cookie_options(time() - 3600));
    unset($_COOKIE[ADMIN_COOKIE]);
}

function admin_claims(): ?array { return jwt_verify((string)($_COOKIE[ADMIN_COOKIE] ?? '')); }
function is_admin(): bool { return admin_claims() !== null; }
function admin_user(): string { return (string)(admin_claims()['sub'] ?? ''); }

/* Jeton CSRF lié au jeton de connexion : le navigateur le renvoie en en-tête,
   ce qu'un autre site ne peut pas faire puisqu'il ne peut pas lire la réponse. */
function csrf_for(string $jti): string { return hash_hmac('sha256', 'csrf|' . $jti, jwt_secret()); }
function csrf_token(): string { $c = admin_claims(); return $c === null ? '' : csrf_for((string)$c['jti']); }
