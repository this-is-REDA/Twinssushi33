# Déploiement sur votre propre serveur

## Prérequis
- Linux (Debian/Ubuntu conseillé), Apache **ou** Nginx
- PHP 8.1 ou plus récent, avec les extensions `gd`, `mbstring`, `json` (incluses en général)
  Debian/Ubuntu : `sudo apt install php8.2-fpm php8.2-gd php8.2-mbstring`
- Un certificat SSL (Let's Encrypt : `sudo certbot --nginx -d www.twinssushi.com -d twinssushi.com`)

## Étapes
1. Copier le contenu du dossier `TwinsSushi-site-v3/` dans le dossier web, par exemple `/var/www/twinssushi/`.
2. Donner les droits d'écriture à PHP sur les dossiers de données :
   ```
   sudo chown -R www-data:www-data /var/www/twinssushi/data /var/www/twinssushi/assets/menu/uploads
   sudo chmod -R 775 /var/www/twinssushi/data /var/www/twinssushi/assets/menu/uploads
   ```
3. **Apache** : activer `mod_rewrite` et `mod_headers`, et autoriser `.htaccess` (`AllowOverride All`) sur le dossier. Rien d'autre à faire.
   **Nginx** : utiliser `outils/nginx-twinssushi.conf` (adapter le chemin et la version de PHP-FPM), puis `sudo nginx -t && sudo systemctl reload nginx`.
4. Activer HTTPS. Avec Apache, retirer ensuite le `#` devant les deux lignes « Forcer HTTPS » dans `.htaccess`.
5. Vérifier :
   - https://www.twinssushi.com/ affiche le site ;
   - https://www.twinssushi.com/data/menu.json renvoie **403 / Forbidden** (obligatoire) ;
   - https://www.twinssushi.com/admin/ affiche la connexion.

## Espace admin
- Adresse : `/admin/`
- Identifiant et mot de passe : communiqués séparément, jamais écrits dans ce dépôt.
- Les identifiants sont stockés chiffrés dans `data/admin.php`, qui est volontairement exclu du dépôt (voir `.gitignore`).
- Créer ou réinitialiser le compte : `php outils/nouveau-mot-de-passe.php <identifiant> "<MotDePasse>"` depuis le dossier du site.

## Sauvegardes
Tout le contenu modifiable est dans `data/` (carte, réglages, identifiants) et `assets/menu/uploads/` (photos ajoutées).
Sauvegarder ces deux dossiers suffit. Le reste se réinstalle depuis le zip.

## Mises à jour
Pour une nouvelle version du site, remplacer tous les fichiers **sauf** `data/` et `assets/menu/uploads/`.
