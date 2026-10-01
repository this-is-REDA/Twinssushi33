# Déploiement

Le site est en PHP et ne stocke plus rien sur son disque : la carte, les réglages,
les identifiants et les photos vivent dans **Supabase**. N'importe quel hébergeur
PHP convient donc, y compris les hébergements sans disque inscriptible comme Vercel.

## 1. Préparer le projet Supabase

1. Créer un projet sur [supabase.com](https://supabase.com) (région **Europe (Paris)**,
   la plus proche de Casablanca).
2. Dans **SQL Editor**, exécuter `supabase/schema.sql` puis `supabase/seed.sql`.
   Le premier crée les tables, les règles d'accès et le bucket des photos ;
   le second installe la carte actuelle (28 catégories, 136 plats).
3. Dans **Authentication → Users**, créer l'utilisateur de l'espace admin
   (e-mail + mot de passe). C'est ce compte qui servira à se connecter sur `/admin/`.
4. Dans **Project Settings → API**, relever :
   - l'**URL du projet** (`https://xxxx.supabase.co`) ;
   - la clé **anon public**.

Ces deux valeurs sont publiques par nature : elles n'ouvrent aucun droit en écriture.
La clé **service_role**, elle, ne doit jamais sortir du tableau de bord Supabase ni
être placée dans ce projet.

## 2. Renseigner les deux variables d'environnement

| Variable            | Valeur                          |
| ------------------- | ------------------------------- |
| `SUPABASE_URL`      | `https://xxxx.supabase.co`      |
| `SUPABASE_ANON_KEY` | la clé *anon public*            |

- **Vercel** : Project Settings → Environment Variables, pour les trois
  environnements (Production, Preview, Development), puis relancer un déploiement.
- **Serveur Apache/Nginx** : `SetEnv` (Apache) ou `fastcgi_param` (PHP-FPM),
  ou un `.env` chargé par l'hébergeur.

Sans ces variables, le site affiche une page « momentanément indisponible »
avec le téléphone du restaurant, au lieu d'une erreur technique.

## 3. Vérifier

- `/` affiche la carte ;
- `/admin/` affiche l'écran de connexion, et l'e-mail créé à l'étape 1 fonctionne ;
- modifier un plat dans l'admin se voit immédiatement sur la page publique.

## Espace admin

- Adresse : `/admin/`
- La connexion passe par Supabase Auth. Les jetons sont déposés dans des cookies
  inaccessibles au JavaScript, renouvelés automatiquement, et toute écriture est
  revalidée par la base elle-même (règles RLS) : le code PHP ne décide jamais seul.
- Mot de passe oublié : le réinitialiser depuis **Authentication → Users** dans
  le tableau de bord Supabase.
- Aucun identifiant n'est stocké dans ce dépôt.

## Sauvegardes

- Chaque modification de la carte ou des réglages archive la version précédente
  dans la table `snapshots`, consultable et restaurable depuis
  **Sécurité & sauvegardes** dans l'admin. Conservation : 60 jours.
- Pour une sauvegarde complète, Supabase propose ses propres sauvegardes de base
  (**Database → Backups**).
- `data/menu.json` et `data/settings.json` ne servent plus au site : ils ne sont
  gardés que pour regénérer `supabase/seed.sql` (`php supabase/generer-seed.php`)
  si l'on repart d'une base vide.

## Développer en local

PHP n'est pas nécessaire sur la machine, Docker suffit :

```sh
docker run --rm -p 8080:8080 -v "$PWD":/app -w /app \
  -e SUPABASE_URL="https://xxxx.supabase.co" \
  -e SUPABASE_ANON_KEY="..." \
  php:8.3-cli php -S 0.0.0.0:8080
```

L'envoi de photos a besoin de l'extension **GD** compilée avec WebP, absente de
l'image `php:8.3-cli` : voir `supabase/Dockerfile.gd` pour une image qui l'inclut.

Pour travailler sans toucher à la base de production, la CLI Supabase monte une
pile complète en local (`supabase start`), avec les mêmes adresses `/rest/v1`,
`/auth/v1` et `/storage/v1`.
