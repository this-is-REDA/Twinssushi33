<?php
/* Génère supabase/seed.sql à partir de data/menu.json et data/settings.json.
   À relancer si la carte locale change avant la migration :
   php supabase/generer-seed.php */
declare(strict_types=1);

$root = dirname(__DIR__);
$menu = json_decode((string)file_get_contents("$root/data/menu.json"), true);
$set  = json_decode((string)file_get_contents("$root/data/settings.json"), true);
if (!is_array($menu) || !is_array($set)) {
    fwrite(STDERR, "Fichiers de données illisibles.\n");
    exit(1);
}

/* Échappement SQL : on ne concatène jamais une valeur sans passer par ici. */
function q(?string $v): string { return $v === null ? 'null' : "'" . str_replace("'", "''", $v) . "'"; }
function b(bool $v): string { return $v ? 'true' : 'false'; }
function n($v): string { return $v === null || $v === '' ? 'null' : (string)(int)$v; }

$out = [];
$out[] = "-- Twins Sushi — reprise des données existantes.";
$out[] = "-- Généré par supabase/generer-seed.php, à exécuter après schema.sql.";
$out[] = "";
$out[] = "begin;";
$out[] = "";
$out[] = "-- Reprise rejouable : on repart d'une base vide.";
$out[] = "truncate public.items, public.categories, public.settings, public.opening_hours restart identity cascade;";
$out[] = "";

$out[] = "insert into public.categories (id, title, jp, visible, sort_order) values";
$rows = [];
foreach (array_values($menu['categories']) as $pos => $c) {
    $rows[] = sprintf('  (%s, %s, %s, %s, %d)',
        q($c['id']), q($c['title']), q($c['jp'] ?? ''), b($c['visible'] ?? true), $pos);
}
$out[] = implode(",\n", $rows) . ';';
$out[] = "";

$out[] = "insert into public.items (code, category_id, name, description, price, pcs, show_pcs, badge, image, available, visible, sort_order) values";
$rows = [];
foreach ($menu['categories'] as $c) {
    foreach (array_values($c['items']) as $pos => $i) {
        $rows[] = sprintf('  (%s, %s, %s, %s, %s, %d, %s, %s, %s, %s, %s, %d)',
            q($i['code']), q($c['id']), q($i['name']), q($i['desc'] ?? ''), n($i['price'] ?? null),
            max(1, (int)($i['pcs'] ?? 1)), b($i['showPcs'] ?? false), q($i['badge'] ?? ''),
            q($i['img'] ?? ''), b($i['available'] ?? true), b($i['visible'] ?? true), $pos);
    }
}
$out[] = implode(",\n", $rows) . ';';
$out[] = "";

/* Les horaires ont leur propre table ; le reste va en clé/valeur. */
$hours = $set['horaires'] ?? [];
unset($set['horaires']);

$out[] = "insert into public.settings (key, value) values";
$rows = [];
foreach ($set as $k => $v) {
    $rows[] = sprintf('  (%s, %s::jsonb)', q((string)$k), q((string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
}
$out[] = implode(",\n", $rows) . ';';
$out[] = "";

if ($hours) {
    $out[] = "insert into public.opening_hours (label, days, opens, closes, display, sort_order) values";
    $rows = [];
    foreach (array_values($hours) as $pos => $h) {
        $rows[] = sprintf('  (%s, %s, %s, %s, %s, %d)',
            q($h['label']), q($h['days']), q($h['open']), q($h['close']), q($h['text']), $pos);
    }
    $out[] = implode(",\n", $rows) . ';';
    $out[] = "";
}

$out[] = "commit;";
$out[] = "";

file_put_contents("$root/supabase/seed.sql", implode("\n", $out));

$nbItems = array_sum(array_map(fn($c) => count($c['items']), $menu['categories']));
printf("seed.sql écrit : %d catégories, %d plats, %d réglages, %d lignes d'horaires.\n",
    count($menu['categories']), $nbItems, count($set), count($hours));
