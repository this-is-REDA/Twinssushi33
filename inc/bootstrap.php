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

/* ---------- Sessions admin ---------- */
function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('twins_admin');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
    // Expiration après 8 h d'inactivité
    if (isset($_SESSION['t']) && time() - $_SESSION['t'] > 8 * 3600) { $_SESSION = []; session_regenerate_id(true); }
    $_SESSION['t'] = time();
}
function is_admin(): bool { start_session(); return !empty($_SESSION['admin']); }
function csrf_token(): string {
    start_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
