<?php
/* Twins Sushi — API de l'espace admin (JSON) */
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
date_default_timezone_set('Africa/Casablanca');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');

function out(array $d, int $code = 200): never { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function fail(string $msg, int $code = 400): never { out(['ok' => false, 'error' => $msg], $code); }

function admin_file(): string { return DATA . '/admin.php'; }
function admin_conf(): array { $f = admin_file(); return is_file($f) ? (require $f) : []; }
function save_admin(array $a): void {
    $php = "<?php\n/* Identifiants admin — ne pas partager. Mot de passe stocké chiffré (hash). */\nreturn " . var_export($a, true) . ";\n";
    $tmp = admin_file() . '.tmp';
    file_put_contents($tmp, $php, LOCK_EX); rename($tmp, admin_file());
    if (function_exists('opcache_invalidate')) @opcache_invalidate(admin_file(), true);
}

/* Limitation des tentatives de connexion : 5 échecs par IP → blocage 15 min */
function throttle_key(): string { return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '0') . '|twins'); }
function throttle_get(): array { $t = read_json('throttle'); $k = throttle_key(); return $t[$k] ?? ['n' => 0, 'until' => 0, 'first' => time()]; }
function throttle_set(?array $v): void {
    $t = read_json('throttle'); $k = throttle_key();
    foreach ($t as $kk => $vv) if (($vv['first'] ?? 0) < time() - 86400) unset($t[$kk]);
    if ($v === null) unset($t[$k]); else $t[$k] = $v;
    write_json('throttle', $t, false);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') fail('Méthode non autorisée', 405);
$isMultipart = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');
$in = $isMultipart ? $_POST : (json_decode(file_get_contents('php://input') ?: '{}', true) ?: []);
$action = (string)($in['action'] ?? '');
start_session();

/* ---------- Actions sans connexion ---------- */
if ($action === 'login') {
    $t = throttle_get();
    if ($t['until'] > time()) fail('Trop de tentatives. Réessayez dans ' . ceil(($t['until'] - time()) / 60) . ' min.', 429);
    $conf = admin_conf();
    $user = trim((string)($in['user'] ?? '')); $pass = (string)($in['pass'] ?? '');
    $ok = $conf && hash_equals(mb_strtolower($conf['user']), mb_strtolower($user)) && password_verify($pass, $conf['hash']);
    if (!$ok) {
        usleep(random_int(300000, 700000));
        $t['n']++; if ($t['n'] >= 5) { $t['until'] = time() + 900; $t['n'] = 0; }
        throttle_set($t);
        fail('Identifiant ou mot de passe incorrect.', 401);
    }
    throttle_set(null);
    if (password_needs_rehash($conf['hash'], PASSWORD_DEFAULT)) { $conf['hash'] = password_hash($pass, PASSWORD_DEFAULT); save_admin($conf); }
    session_regenerate_id(true);
    $_SESSION['admin'] = $conf['user'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    out(['ok' => true, 'user' => $conf['user'], 'mustChange' => !empty($conf['mustChange']), 'csrf' => $_SESSION['csrf']]);
}
if ($action === 'me') {
    if (!is_admin()) out(['ok' => false, 'auth' => false]);
    $conf = admin_conf();
    out(['ok' => true, 'user' => $_SESSION['admin'], 'mustChange' => !empty($conf['mustChange']), 'csrf' => csrf_token()]);
}

/* ---------- Tout le reste : connecté + jeton CSRF ---------- */
if (!is_admin()) fail('Session expirée, reconnectez-vous.', 401);
$csrf = $_SERVER['HTTP_X_CSRF'] ?? ($in['csrf'] ?? '');
if (!is_string($csrf) || !hash_equals(csrf_token(), $csrf)) fail('Jeton de sécurité invalide, rechargez la page.', 403);

/* ---------- Validation ---------- */
function str_in($v, int $max, bool $required = false, string $label = 'Champ'): string {
    $s = trim(preg_replace('/\s+/u', ' ', (string)$v));
    if ($required && $s === '') fail("$label obligatoire.");
    if (mb_strlen($s) > $max) fail("$label trop long ($max caractères max).");
    return $s;
}
function slug(string $s): string {
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $s = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $s));
    return trim($s, '-') ?: 'categorie';
}
function &find_cat(array &$m, string $id) {
    foreach ($m['categories'] as &$c) if ($c['id'] === $id) return $c;
    fail('Catégorie introuvable.', 404);
}
function locate(array $m, string $code): array {
    foreach ($m['categories'] as $ci => $c) foreach ($c['items'] as $ii => $i) if ($i['code'] === $code) return [$ci, $ii];
    fail('Plat introuvable.', 404);
}
function new_code(array $m, string $catId): string {
    $pre = strtoupper(substr(preg_replace('/[^a-z]/', '', $catId) . 'xx', 0, 2));
    $all = [];
    foreach ($m['categories'] as $c) foreach ($c['items'] as $i) $all[$i['code']] = 1;
    for ($n = 1; $n < 1000; $n++) { $c = $pre . str_pad((string)$n, 2, '0', STR_PAD_LEFT); if (!isset($all[$c])) return $c; }
    return $pre . bin2hex(random_bytes(2));
}
function done(array $extra = []): never { out(['ok' => true, 'menu' => menu(), 'settings' => settings()] + $extra); }

$m = menu();

switch ($action) {
case 'logout':
    $_SESSION = []; session_destroy(); out(['ok' => true]);

case 'data':
    done(['badges' => BADGES]);

case 'item_save':
    $d = $in['item'] ?? [];
    $catId = (string)($in['cat'] ?? '');
    find_cat($m, $catId);
    $price = $d['price'] ?? null;
    if ($price === '' || $price === null) $price = null;
    else { if (!is_numeric($price) || $price < 0 || $price > 100000) fail('Prix invalide.'); $price = (int)round((float)$price); }
    $pcs = (int)($d['pcs'] ?? 1); if ($pcs < 1 || $pcs > 500) fail('Nombre de pièces invalide.');
    $badge = (string)($d['badge'] ?? ''); if (!array_key_exists($badge, BADGES)) $badge = '';
    $item = [
        'code' => '', 'name' => str_in($d['name'] ?? '', 80, true, 'Nom'), 'desc' => str_in($d['desc'] ?? '', 220, false, 'Description'),
        'price' => $price, 'pcs' => $pcs, 'showPcs' => !empty($d['showPcs']), 'badge' => $badge,
        'available' => !array_key_exists('available', $d) || !empty($d['available']),
        'visible' => !array_key_exists('visible', $d) || !empty($d['visible']), 'img' => '',
    ];
    if (preg_match('/\budon\b/i', $item['name'] . ' ' . $item['desc'])) fail('« Udon » est un terme interne : utilisez « nouilles » (charte graphique).');
    $code = (string)($d['code'] ?? '');
    if ($code !== '') {
        [$ci, $ii] = locate($m, $code);
        $old = $m['categories'][$ci]['items'][$ii];
        $item['code'] = $code; $item['img'] = $old['img'] ?? '';
        if ($m['categories'][$ci]['id'] === $catId) { $m['categories'][$ci]['items'][$ii] = $item; }
        else { array_splice($m['categories'][$ci]['items'], $ii, 1); $c = &find_cat($m, $catId); $c['items'][] = $item; unset($c); }
    } else {
        $item['code'] = new_code($m, $catId);
        $c = &find_cat($m, $catId); $c['items'][] = $item; unset($c);
    }
    write_json('menu', $m);
    done(['code' => $item['code']]);

case 'item_delete':
    [$ci, $ii] = locate($m, (string)($in['code'] ?? ''));
    array_splice($m['categories'][$ci]['items'], $ii, 1);
    write_json('menu', $m); done();

case 'item_toggle':
    $f = (string)($in['field'] ?? '');
    if (!in_array($f, ['available', 'visible'], true)) fail('Champ inconnu.');
    [$ci, $ii] = locate($m, (string)($in['code'] ?? ''));
    $cur = $m['categories'][$ci]['items'][$ii][$f] ?? true;
    $m['categories'][$ci]['items'][$ii][$f] = !$cur;
    write_json('menu', $m); done();

case 'item_move':
    [$ci, $ii] = locate($m, (string)($in['code'] ?? ''));
    $to = $ii + ((int)($in['dir'] ?? 0) < 0 ? -1 : 1);
    $items = &$m['categories'][$ci]['items'];
    if ($to >= 0 && $to < count($items)) { [$items[$ii], $items[$to]] = [$items[$to], $items[$ii]]; }
    unset($items);
    write_json('menu', $m); done();

case 'cat_save':
    $title = str_in($in['title'] ?? '', 50, true, 'Titre');
    $jp = str_in($in['jp'] ?? '', 30, false, 'Sous-titre japonais');
    $id = (string)($in['id'] ?? '');
    if ($id !== '') { $c = &find_cat($m, $id); $c['title'] = $title; $c['jp'] = $jp; $c['visible'] = !empty($in['visible']); unset($c); }
    else {
        $base = slug($title); $id = $base; $n = 2;
        $ids = array_column($m['categories'], 'id');
        while (in_array($id, $ids, true)) $id = $base . '-' . $n++;
        $m['categories'][] = ['id' => $id, 'title' => $title, 'jp' => $jp, 'visible' => true, 'items' => []];
    }
    write_json('menu', $m); done(['id' => $id]);

case 'cat_delete':
    $id = (string)($in['id'] ?? '');
    foreach ($m['categories'] as $k => $c) if ($c['id'] === $id) {
        if ($c['items']) fail('Videz la catégorie avant de la supprimer (déplacez ou supprimez ses plats).');
        array_splice($m['categories'], $k, 1); write_json('menu', $m); done();
    }
    fail('Catégorie introuvable.', 404);

case 'cat_move':
    $id = (string)($in['id'] ?? ''); $ids = array_column($m['categories'], 'id'); $k = array_search($id, $ids, true);
    if ($k === false) fail('Catégorie introuvable.', 404);
    $to = $k + ((int)($in['dir'] ?? 0) < 0 ? -1 : 1);
    if ($to >= 0 && $to < count($ids)) [$m['categories'][$k], $m['categories'][$to]] = [$m['categories'][$to], $m['categories'][$k]];
    write_json('menu', $m); done();

case 'upload':
    [$ci, $ii] = locate($m, (string)($in['code'] ?? ''));
    $f = $_FILES['photo'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) fail('Envoi de la photo impossible (fichier trop lourd ?).');
    if ($f['size'] > 8 * 1024 * 1024) fail('Photo trop lourde (8 Mo maximum).');
    $info = @getimagesize($f['tmp_name']);
    $mime = $info['mime'] ?? '';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) fail('Format accepté : JPG, PNG ou WebP.');
    if (!is_dir(UPLOADS)) mkdir(UPLOADS, 0755, true);
    $code = $m['categories'][$ci]['items'][$ii]['code'];
    $name = strtolower($code) . '-' . date('YmdHis');
    $src = match ($mime) { 'image/jpeg' => @imagecreatefromjpeg($f['tmp_name']), 'image/png' => @imagecreatefrompng($f['tmp_name']), 'image/webp' => @imagecreatefromwebp($f['tmp_name']) };
    if (!$src) fail('Image illisible.');
    // Redimensionne (600 px max) et convertit en WebP en gardant la transparence
    $w = imagesx($src); $hh = imagesy($src); $max = 600;
    $r = min(1, $max / max($w, $hh)); $nw = (int)round($w * $r); $nh = (int)round($hh * $r);
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false); imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $hh);
    if (function_exists('imagewebp')) { $rel = "assets/menu/uploads/$name.webp"; imagewebp($dst, ROOT . '/' . $rel, 84); }
    else { $rel = "assets/menu/uploads/$name.png"; imagepng($dst, ROOT . '/' . $rel, 8); }
    imagedestroy($src); imagedestroy($dst);
    $m['categories'][$ci]['items'][$ii]['img'] = $rel;
    write_json('menu', $m); done(['img' => $rel]);

case 'photo_remove':
    [$ci, $ii] = locate($m, (string)($in['code'] ?? ''));
    $m['categories'][$ci]['items'][$ii]['img'] = '';
    write_json('menu', $m); done();

case 'settings_save':
    $s = settings(); $d = $in['settings'] ?? [];
    foreach (['tel_affiche' => 30, 'wa_affiche' => 30, 'rue' => 80, 'quartier' => 40, 'cp' => 10, 'ville' => 40, 'bandeau_texte' => 140, 'annonce' => 160] as $k => $max)
        if (array_key_exists($k, $d)) $s[$k] = str_in($d[$k], $max, false, $k);
    foreach (['tel', 'wa'] as $k) if (array_key_exists($k, $d)) {
        $v = preg_replace('/[^0-9+]/', '', (string)$d[$k]); if ($k === 'wa') $v = ltrim($v, '+');
        if (strlen($v) < 9) fail('Numéro invalide.'); $s[$k] = $v;
    }
    if (array_key_exists('email', $d)) { $e = trim((string)$d['email']); if ($e !== '' && !filter_var($e, FILTER_VALIDATE_EMAIL)) fail('E-mail invalide.'); $s['email'] = $e; }
    foreach (['glovo', 'yassir', 'kool', 'instagram', 'tiktok', 'facebook'] as $k) if (array_key_exists($k, $d)) {
        $u = trim((string)$d[$k]);
        if ($u !== '' && (!filter_var($u, FILTER_VALIDATE_URL) || !preg_match('#^https://#i', $u))) fail("Lien $k invalide (doit commencer par https://).");
        $s[$k] = $u;
    }
    if (array_key_exists('bandeau_fin', $d)) { $f = (string)$d['bandeau_fin']; if ($f !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) fail('Date invalide.'); $s['bandeau_fin'] = $f; }
    if (isset($d['horaires']) && is_array($d['horaires'])) {
        $hs = [];
        foreach (array_slice($d['horaires'], 0, 7) as $hrow) {
            $days = implode(',', array_values(array_unique(array_filter(array_map('trim', explode(',', (string)($hrow['days'] ?? ''))), fn($x) => preg_match('/^[0-6]$/', $x)))));
            $o = (string)($hrow['open'] ?? ''); $c = (string)($hrow['close'] ?? '');
            if (!preg_match('/^\d{2}:\d{2}$/', $o) || !preg_match('/^\d{2}:\d{2}$/', $c) || $days === '') fail('Horaires incomplets.');
            $hs[] = ['label' => str_in($hrow['label'] ?? '', 40, true, 'Jours'), 'days' => $days, 'open' => $o, 'close' => $c, 'text' => str_in($hrow['text'] ?? '', 40, true, 'Horaire affiché')];
        }
        if ($hs) $s['horaires'] = $hs;
    }
    write_json('settings', $s); done();

case 'password':
    $conf = admin_conf();
    if (!password_verify((string)($in['current'] ?? ''), $conf['hash'])) fail('Mot de passe actuel incorrect.');
    $new = (string)($in['new'] ?? '');
    if (mb_strlen($new) < 10 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) fail('Le nouveau mot de passe doit faire au moins 10 caractères, avec des lettres et des chiffres.');
    $user = str_in($in['user'] ?? $conf['user'], 40, true, 'Identifiant');
    save_admin(['user' => $user, 'hash' => password_hash($new, PASSWORD_DEFAULT), 'mustChange' => false]);
    session_regenerate_id(true); $_SESSION['admin'] = $user;
    out(['ok' => true, 'user' => $user, 'csrf' => csrf_token()]);

case 'backups':
    $list = array_map('basename', glob(DATA . '/backups/*.json') ?: []);
    rsort($list); out(['ok' => true, 'backups' => array_slice($list, 0, MAX_BACKUPS)]);

case 'restore':
    $b = basename((string)($in['file'] ?? ''));
    if (!preg_match('/^(menu|settings)-\d{8}-\d{6}(-\d+)?\.json$/', $b, $mm) || !is_file(DATA . "/backups/$b")) fail('Sauvegarde introuvable.', 404);
    $d = json_decode(file_get_contents(DATA . "/backups/$b"), true);
    if (!is_array($d)) fail('Sauvegarde illisible.');
    write_json($mm[1], $d); done();

default:
    fail('Action inconnue.', 404);
}
