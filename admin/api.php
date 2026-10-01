<?php
/* Twins Sushi — API de l'espace admin (JSON), adossée à Supabase.
   Cette couche valide et met en forme ; c'est la base de données qui autorise,
   via ses règles RLS : chaque écriture part avec le jeton de la personne
   connectée, et sera refusée si ce jeton ne vaut rien. */
declare(strict_types=1);
require dirname(__DIR__) . '/inc/bootstrap.php';
date_default_timezone_set('Africa/Casablanca');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');

function out(array $d, int $code = 200): never { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function fail(string $msg, int $code = 400): never { out(['ok' => false, 'error' => $msg], $code); }

/* Toute erreur imprévue doit rester du JSON : sinon l'admin reçoit une page HTML
   d'erreur et affiche « Réponse du serveur illisible ». */
set_exception_handler(function (Throwable $e): never {
    $c = (int)$e->getCode();
    fail($e->getMessage(), $c >= 400 && $c < 600 ? $c : 500);
});

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') fail('Méthode non autorisée', 405);
$isMultipart = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');
$in = $isMultipart ? $_POST : (json_decode(file_get_contents('php://input') ?: '{}', true) ?: []);
$action = (string)($in['action'] ?? '');

if (!sb_configured()) fail('Base de données non configurée : il manque SUPABASE_URL et SUPABASE_ANON_KEY.', 503);

/* ---------- Actions sans connexion ---------- */
if ($action === 'login') {
    /* Supabase limite lui-même les tentatives par adresse IP ; on ajoute une
       attente aléatoire pour ne pas révéler si l'identifiant existe. */
    $session = sb_sign_in(trim((string)($in['user'] ?? '')), (string)($in['pass'] ?? ''));
    if ($session === null) {
        usleep(random_int(300000, 700000));
        fail('Identifiant ou mot de passe incorrect.', 401);
    }
    out(['ok' => true, 'user' => $session['email'], 'csrf' => admin_store($session)]);
}
if ($action === 'me') {
    if (!is_admin() && !admin_renew()) out(['ok' => false, 'auth' => false]);
    /* Seul endroit où l'on vérifie vraiment la signature du jeton : ailleurs,
       c'est la base qui tranche. */
    $email = sb_user(admin_token());
    if ($email === null) { admin_logout(); out(['ok' => false, 'auth' => false]); }
    out(['ok' => true, 'user' => $email, 'csrf' => csrf_token()]);
}

/* ---------- Tout le reste : connecté + jeton CSRF ---------- */
if (!is_admin() && !admin_renew()) fail('Session expirée, reconnectez-vous.', 401);
$csrf = $_SERVER['HTTP_X_CSRF'] ?? ($in['csrf'] ?? '');
if (!is_string($csrf) || $csrf === '' || !hash_equals(csrf_token(), $csrf)) fail('Jeton de sécurité invalide, rechargez la page.', 403);
$TOKEN = admin_token();

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
function find_cat(array $m, string $id): array {
    foreach ($m['categories'] as $c) if ($c['id'] === $id) return $c;
    fail('Catégorie introuvable.', 404);
}
/* Renvoie [identifiant de catégorie, plat] pour un code donné. */
function locate(array $m, string $code): array {
    foreach ($m['categories'] as $c) foreach ($c['items'] as $i) if ($i['code'] === $code) return [$c['id'], $i];
    fail('Plat introuvable.', 404);
}
function new_code(array $m, string $catId): string {
    $pre = strtoupper(substr(preg_replace('/[^a-z]/', '', $catId) . 'xx', 0, 2));
    $all = [];
    foreach ($m['categories'] as $c) foreach ($c['items'] as $i) $all[$i['code']] = 1;
    for ($n = 1; $n < 1000; $n++) { $c = $pre . str_pad((string)$n, 2, '0', STR_PAD_LEFT); if (!isset($all[$c])) return $c; }
    return $pre . bin2hex(random_bytes(2));
}

/* ---------- Positions d'affichage ----------
   menu() ne les expose pas, pour rester identique à l'ancien format : on va
   donc les lire à part quand il faut insérer ou déplacer une ligne. */
function positions(string $table, string $key, string $filter, ?string $token): array {
    $out = [];
    foreach (sb_select($table, "select=$key,sort_order" . ($filter === '' ? '' : "&$filter") . '&order=sort_order', $token) as $r)
        $out[(string)$r[$key]] = (int)$r['sort_order'];
    return $out;
}
function next_position(array $pos): int { return $pos ? max($pos) + 1 : 0; }

/* Échange deux positions : un déplacement vers le haut ou vers le bas. */
function swap_positions(string $table, string $key, array $pos, string $id, int $dir, ?string $token): void {
    $ids = array_keys($pos);
    $k = array_search($id, $ids, true);
    if ($k === false) return;
    $to = $k + ($dir < 0 ? -1 : 1);
    if ($to < 0 || $to >= count($ids)) return;
    $other = $ids[$to];
    sb_write('PATCH', $table, "$key=eq." . rawurlencode($id), ['sort_order' => $pos[$other]], $token);
    sb_write('PATCH', $table, "$key=eq." . rawurlencode($other), ['sort_order' => $pos[$id]], $token);
}

/* ---------- Sauvegardes ----------
   Chaque modification archive la version précédente dans la table snapshots,
   invisible du public. */
function snapshot(string $kind, array $payload, string $token): void {
    sb_write('POST', 'snapshots', '', [['kind' => $kind, 'payload' => $payload]], $token);
}

/* Nom du fichier dans le bucket, à partir de son adresse publique. */
function storage_name(string $url): string {
    $p = '/storage/v1/object/public/menu/';
    $i = strpos($url, $p);
    return $i === false ? '' : rawurldecode(substr($url, $i + strlen($p)));
}

function done(array $extra = []): never {
    global $TOKEN;
    out(['ok' => true, 'menu' => menu($TOKEN), 'settings' => settings($TOKEN)] + $extra);
}

/* Les actions qui touchent la carte en archivent d'abord l'état actuel. */
const MENU_ACTIONS = ['item_save', 'item_delete', 'item_toggle', 'item_move',
                      'cat_save', 'cat_delete', 'cat_move', 'upload', 'photo_remove'];

$m = in_array($action, MENU_ACTIONS, true) || $action === 'data' ? menu($TOKEN) : ['categories' => []];
if (in_array($action, MENU_ACTIONS, true)) snapshot('menu', $m, $TOKEN);

switch ($action) {
case 'logout':
    admin_logout(); out(['ok' => true]);

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
    $row = [
        'name'        => str_in($d['name'] ?? '', 80, true, 'Nom'),
        'description' => str_in($d['desc'] ?? '', 220, false, 'Description'),
        'price'       => $price,
        'pcs'         => $pcs,
        'show_pcs'    => !empty($d['showPcs']),
        'badge'       => $badge,
        'available'   => !array_key_exists('available', $d) || !empty($d['available']),
        'visible'     => !array_key_exists('visible', $d) || !empty($d['visible']),
    ];
    if (preg_match('/\budon\b/i', $row['name'] . ' ' . $row['description'])) fail('« Udon » est un terme interne : utilisez « nouilles » (charte graphique).');
    $code = (string)($d['code'] ?? '');
    if ($code !== '') {
        [$oldCat] = locate($m, $code);
        if ($oldCat !== $catId) {
            $row['category_id'] = $catId;
            $row['sort_order']  = next_position(positions('items', 'code', 'category_id=eq.' . rawurlencode($catId), $TOKEN));
        }
        sb_write('PATCH', 'items', 'code=eq.' . rawurlencode($code), $row, $TOKEN);
    } else {
        $code = new_code($m, $catId);
        $row['code']        = $code;
        $row['category_id'] = $catId;
        $row['image']       = '';
        $row['sort_order']  = next_position(positions('items', 'code', 'category_id=eq.' . rawurlencode($catId), $TOKEN));
        sb_write('POST', 'items', '', [$row], $TOKEN);
    }
    done(['code' => $code]);

case 'item_delete':
    $code = (string)($in['code'] ?? '');
    [, $it] = locate($m, $code);
    sb_write('DELETE', 'items', 'code=eq.' . rawurlencode($code), null, $TOKEN);
    if (($name = storage_name((string)$it['img'])) !== '') sb_delete_image($TOKEN, $name);
    done();

case 'item_toggle':
    $f = (string)($in['field'] ?? '');
    if (!in_array($f, ['available', 'visible'], true)) fail('Champ inconnu.');
    $code = (string)($in['code'] ?? '');
    [, $it] = locate($m, $code);
    sb_write('PATCH', 'items', 'code=eq.' . rawurlencode($code), [$f => !($it[$f] ?? true)], $TOKEN);
    done();

case 'item_move':
    $code = (string)($in['code'] ?? '');
    [$catId] = locate($m, $code);
    swap_positions('items', 'code', positions('items', 'code', 'category_id=eq.' . rawurlencode($catId), $TOKEN),
                   $code, (int)($in['dir'] ?? 0), $TOKEN);
    done();

case 'cat_save':
    $title = str_in($in['title'] ?? '', 50, true, 'Titre');
    $jp = str_in($in['jp'] ?? '', 30, false, 'Sous-titre japonais');
    $id = (string)($in['id'] ?? '');
    if ($id !== '') {
        find_cat($m, $id);
        sb_write('PATCH', 'categories', 'id=eq.' . rawurlencode($id),
                 ['title' => $title, 'jp' => $jp, 'visible' => !empty($in['visible'])], $TOKEN);
    } else {
        $ids = array_column($m['categories'], 'id');
        $base = slug($title); $id = $base; $n = 2;
        while (in_array($id, $ids, true)) $id = $base . '-' . $n++;
        sb_write('POST', 'categories', '', [[
            'id' => $id, 'title' => $title, 'jp' => $jp, 'visible' => true,
            'sort_order' => next_position(positions('categories', 'id', '', $TOKEN)),
        ]], $TOKEN);
    }
    done(['id' => $id]);

case 'cat_delete':
    $cat = find_cat($m, $id = (string)($in['id'] ?? ''));
    if ($cat['items']) fail('Videz la catégorie avant de la supprimer (déplacez ou supprimez ses plats).');
    sb_write('DELETE', 'categories', 'id=eq.' . rawurlencode($id), null, $TOKEN);
    done();

case 'cat_move':
    $id = (string)($in['id'] ?? '');
    find_cat($m, $id);
    swap_positions('categories', 'id', positions('categories', 'id', '', $TOKEN), $id, (int)($in['dir'] ?? 0), $TOKEN);
    done();

case 'upload':
    $code = (string)($in['code'] ?? '');
    [, $it] = locate($m, $code);
    $f = $_FILES['photo'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) fail('Envoi de la photo impossible (fichier trop lourd ?).');
    if ($f['size'] > 8 * 1024 * 1024) fail('Photo trop lourde (8 Mo maximum).');
    $info = @getimagesize($f['tmp_name']);
    $mime = $info['mime'] ?? '';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) fail('Format accepté : JPG, PNG ou WebP.');
    $src = match ($mime) { 'image/jpeg' => @imagecreatefromjpeg($f['tmp_name']), 'image/png' => @imagecreatefrompng($f['tmp_name']), 'image/webp' => @imagecreatefromwebp($f['tmp_name']) };
    if (!$src) fail('Image illisible.');
    // Redimensionne (600 px max) et convertit en WebP en gardant la transparence
    $w = imagesx($src); $hh = imagesy($src); $max = 600;
    $r = min(1, $max / max($w, $hh)); $nw = (int)round($w * $r); $nh = (int)round($hh * $r);
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false); imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $hh);
    ob_start();
    if (function_exists('imagewebp')) { imagewebp($dst, null, 84); $ext = 'webp'; $outMime = 'image/webp'; }
    else { imagepng($dst, null, 8); $ext = 'png'; $outMime = 'image/png'; }
    $bytes = (string)ob_get_clean();
    imagedestroy($src); imagedestroy($dst);
    $url = sb_upload_image($TOKEN, strtolower($code) . '-' . date('YmdHis') . '.' . $ext, $bytes, $outMime);
    sb_write('PATCH', 'items', 'code=eq.' . rawurlencode($code), ['image' => $url], $TOKEN);
    if (($old = storage_name((string)$it['img'])) !== '') sb_delete_image($TOKEN, $old);
    done(['img' => $url]);

case 'photo_remove':
    $code = (string)($in['code'] ?? '');
    [, $it] = locate($m, $code);
    sb_write('PATCH', 'items', 'code=eq.' . rawurlencode($code), ['image' => ''], $TOKEN);
    if (($name = storage_name((string)$it['img'])) !== '') sb_delete_image($TOKEN, $name);
    done();

case 'settings_save':
    $s = settings($TOKEN); $d = $in['settings'] ?? [];
    snapshot('settings', $s, $TOKEN);
    $changed = [];
    foreach (['tel_affiche' => 30, 'wa_affiche' => 30, 'rue' => 80, 'quartier' => 40, 'cp' => 10, 'ville' => 40, 'bandeau_texte' => 140, 'annonce' => 160] as $k => $max)
        if (array_key_exists($k, $d)) $changed[$k] = str_in($d[$k], $max, false, $k);
    foreach (['tel', 'wa'] as $k) if (array_key_exists($k, $d)) {
        $v = preg_replace('/[^0-9+]/', '', (string)$d[$k]); if ($k === 'wa') $v = ltrim($v, '+');
        if (strlen($v) < 9) fail('Numéro invalide.'); $changed[$k] = $v;
    }
    if (array_key_exists('email', $d)) { $e = trim((string)$d['email']); if ($e !== '' && !filter_var($e, FILTER_VALIDATE_EMAIL)) fail('E-mail invalide.'); $changed['email'] = $e; }
    foreach (['glovo', 'yassir', 'kool', 'instagram', 'tiktok', 'facebook'] as $k) if (array_key_exists($k, $d)) {
        $u = trim((string)$d[$k]);
        if ($u !== '' && (!filter_var($u, FILTER_VALIDATE_URL) || !preg_match('#^https://#i', $u))) fail("Lien $k invalide (doit commencer par https://).");
        $changed[$k] = $u;
    }
    if (array_key_exists('bandeau_fin', $d)) { $fin = (string)$d['bandeau_fin']; if ($fin !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin)) fail('Date invalide.'); $changed['bandeau_fin'] = $fin; }
    if ($changed) {
        $rows = [];
        foreach ($changed as $k => $v) $rows[] = ['key' => $k, 'value' => $v];
        sb_write('POST', 'settings', '', $rows, $TOKEN);
    }
    if (isset($d['horaires']) && is_array($d['horaires'])) {
        $hs = [];
        foreach (array_slice($d['horaires'], 0, 7) as $n => $hrow) {
            $days = implode(',', array_values(array_unique(array_filter(array_map('trim', explode(',', (string)($hrow['days'] ?? ''))), fn($x) => preg_match('/^[0-6]$/', $x)))));
            $o = (string)($hrow['open'] ?? ''); $c = (string)($hrow['close'] ?? '');
            if (!preg_match('/^\d{2}:\d{2}$/', $o) || !preg_match('/^\d{2}:\d{2}$/', $c) || $days === '') fail('Horaires incomplets.');
            $hs[] = ['label' => str_in($hrow['label'] ?? '', 40, true, 'Jours'), 'days' => $days,
                     'opens' => $o, 'closes' => $c, 'display' => str_in($hrow['text'] ?? '', 40, true, 'Horaire affiché'),
                     'sort_order' => $n];
        }
        if ($hs) {
            sb_write('DELETE', 'opening_hours', 'id=gt.0', null, $TOKEN);
            sb_write('POST', 'opening_hours', '', $hs, $TOKEN);
        }
    }
    done();

case 'password':
    $new = (string)($in['new'] ?? '');
    if (mb_strlen($new) < 10 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) fail('Le nouveau mot de passe doit faire au moins 10 caractères, avec des lettres et des chiffres.');
    /* On revérifie le mot de passe actuel : le jeton seul ne suffit pas pour
       une opération aussi sensible. */
    $email = admin_user();
    $check = sb_sign_in($email, (string)($in['current'] ?? ''));
    if ($check === null) fail('Mot de passe actuel incorrect.');
    if (!sb_change_password($check['access_token'], $new)) fail('Changement de mot de passe refusé.');
    $session = sb_sign_in($email, $new);
    if ($session === null) fail('Mot de passe modifié, mais reconnexion impossible : rechargez la page.');
    out(['ok' => true, 'user' => $email, 'csrf' => admin_store($session)]);

case 'backups':
    /* Au passage, on fait le ménage : inutile de garder des mois d'archives. */
    sb_write('DELETE', 'snapshots', 'taken_at=lt.' . gmdate('Y-m-d', time() - 60 * 86400), null, $TOKEN);
    $rows = sb_select('snapshots', 'select=id,kind,taken_at&order=taken_at.desc&limit=' . MAX_BACKUPS, $TOKEN);
    out(['ok' => true, 'backups' => array_map(fn($r) => [
        'id'   => (int)$r['id'],
        'kind' => (string)$r['kind'],
        'at'   => (string)$r['taken_at'],
    ], $rows)]);

case 'restore':
    $id = (int)($in['file'] ?? 0);
    $rows = $id > 0 ? sb_select('snapshots', 'select=kind,payload&id=eq.' . $id, $TOKEN) : [];
    if (!$rows) fail('Sauvegarde introuvable.', 404);
    $kind = (string)$rows[0]['kind']; $payload = $rows[0]['payload'];
    if (!is_array($payload)) fail('Sauvegarde illisible.');

    if ($kind === 'menu') {
        snapshot('menu', menu($TOKEN), $TOKEN);
        $cats = []; $items = [];
        foreach (($payload['categories'] ?? []) as $n => $c) {
            $cats[] = ['id' => (string)$c['id'], 'title' => (string)$c['title'], 'jp' => (string)($c['jp'] ?? ''),
                       'visible' => !empty($c['visible']), 'sort_order' => $n];
            foreach (($c['items'] ?? []) as $k => $i)
                $items[] = ['code' => (string)$i['code'], 'category_id' => (string)$c['id'], 'name' => (string)$i['name'],
                            'description' => (string)($i['desc'] ?? ''), 'price' => $i['price'] === null ? null : (int)$i['price'],
                            'pcs' => (int)($i['pcs'] ?? 1), 'show_pcs' => !empty($i['showPcs']), 'badge' => (string)($i['badge'] ?? ''),
                            'image' => (string)($i['img'] ?? ''), 'available' => !empty($i['available']),
                            'visible' => !empty($i['visible']), 'sort_order' => $k];
        }
        if (!$cats) fail('Sauvegarde vide.');
        /* Les plats partent avec leur catégorie (suppression en cascade).
           PostgREST exige un filtre sur un DELETE : celui-ci prend tout. */
        sb_write('DELETE', 'categories', 'id=not.is.null', null, $TOKEN);
        sb_write('POST', 'categories', '', $cats, $TOKEN);
        if ($items) sb_write('POST', 'items', '', $items, $TOKEN);
    } else {
        snapshot('settings', settings($TOKEN), $TOKEN);
        $rows = []; $hs = [];
        foreach ($payload as $k => $v) {
            if ($k === 'horaires') continue;
            $rows[] = ['key' => (string)$k, 'value' => $v];
        }
        foreach (($payload['horaires'] ?? []) as $n => $h)
            $hs[] = ['label' => (string)$h['label'], 'days' => (string)$h['days'], 'opens' => (string)$h['open'],
                     'closes' => (string)$h['close'], 'display' => (string)$h['text'], 'sort_order' => $n];
        if ($rows) sb_write('POST', 'settings', '', $rows, $TOKEN);
        if ($hs) { sb_write('DELETE', 'opening_hours', 'id=gt.0', null, $TOKEN); sb_write('POST', 'opening_hours', '', $hs, $TOKEN); }
    }
    done();

default:
    fail('Action inconnue.', 404);
}
