<?php
/* Réinitialiser l'accès admin en ligne de commande :
   php outils/nouveau-mot-de-passe.php identifiant "MotDePasse123"
   (ou lancer ce script depuis le terminal de l'hébergeur). Ne pas laisser accessible en ligne. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Interdit'); }
[$_, $u, $p] = $argv + [null, null, null];
if (!$u || !$p || strlen($p) < 10) exit("Usage : php outils/nouveau-mot-de-passe.php identifiant \"MotDePasse (10+ caractères)\"\n");
$f = dirname(__DIR__) . '/data/admin.php';
file_put_contents($f, "<?php\nreturn " . var_export(['user' => $u, 'hash' => password_hash($p, PASSWORD_DEFAULT), 'mustChange' => false], true) . ";\n");
echo "Accès admin mis à jour pour « $u ».\n";
