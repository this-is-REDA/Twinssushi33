-- Twins Sushi — installation complète de la base Supabase.
-- Tout coller dans le SQL Editor du projet, puis « Run ». Une seule fois suffit.
-- Fichier généré : modifier schema.sql / seed.sql, puis regénérer avec
--   cat supabase/schema.sql supabase/seed.sql

-- Twins Sushi — schéma de la base Supabase
-- À exécuter une seule fois dans le SQL Editor du projet Supabase.
-- Le site public lit avec la clé « anon » ; seules les personnes connectées écrivent.

-- ---------------------------------------------------------------- Catégories
create table if not exists public.categories (
    id          text        primary key,
    title       text        not null check (length(title) between 1 and 60),
    jp          text        not null default '',
    visible     boolean     not null default true,
    sort_order  integer     not null,
    created_at  timestamptz not null default now(),
    updated_at  timestamptz not null default now()
);

-- --------------------------------------------------------------------- Plats
create table if not exists public.items (
    code        text        primary key,
    category_id text        not null references public.categories (id) on update cascade on delete cascade,
    name        text        not null check (length(name) between 1 and 80),
    description text        not null default '' check (length(description) <= 220),
    -- NULL = « prix à venir », affiché comme tel sur le site
    price       integer     check (price is null or price between 0 and 100000),
    pcs         integer     not null default 1 check (pcs between 1 and 500),
    show_pcs    boolean     not null default false,
    badge       text        not null default ''
                check (badge in ('', 'vege', 'epice', 'croustillant', 'bestseller', 'nouveau', 'signature', 'partager')),
    image       text        not null default '',
    available   boolean     not null default true,
    visible     boolean     not null default true,
    sort_order  integer     not null,
    created_at  timestamptz not null default now(),
    updated_at  timestamptz not null default now()
);

-- Le site affiche les plats catégorie par catégorie, dans l'ordre choisi en admin.
create index if not exists items_category_order_idx on public.items (category_id, sort_order);
create index if not exists categories_order_idx     on public.categories (sort_order);
-- Recherche d'un plat par nom ou ingrédient dans l'admin
create index if not exists items_search_idx on public.items
    using gin (to_tsvector('french', name || ' ' || description));

-- ------------------------------------------------------------------ Réglages
-- Clé/valeur : les réglages sont une vingtaine de champs libres qui évoluent
-- (liens des plateformes, bandeau, coordonnées) et ne justifient pas une colonne chacun.
create table if not exists public.settings (
    key        text        primary key,
    value      jsonb       not null,
    updated_at timestamptz not null default now()
);

-- -------------------------------------------------------------------Horaires
create table if not exists public.opening_hours (
    id         bigint generated always as identity primary key,
    label      text    not null check (length(label) between 1 and 40),
    -- Jours concernés, au format PHP : 0 = dimanche … 6 = samedi, ex. « 1,2,3,4,5 »
    days       text    not null check (days ~ '^[0-6](,[0-6])*$'),
    opens      time    not null,
    closes     time    not null,
    display    text    not null check (length(display) between 1 and 40),
    sort_order integer not null
);

-- --------------------------------------------------------------- Sauvegardes
-- Remplace les 40 fichiers de sauvegarde : une copie avant chaque modification.
create table if not exists public.snapshots (
    id       bigint generated always as identity primary key,
    kind     text        not null check (kind in ('menu', 'settings')),
    payload  jsonb       not null,
    taken_at timestamptz not null default now()
);

create index if not exists snapshots_recent_idx on public.snapshots (taken_at desc);

-- -------------------------------------------------- Horodatage automatique
create or replace function public.touch_updated_at() returns trigger
language plpgsql
as $$
begin
    new.updated_at := now();
    return new;
end;
$$;

drop trigger if exists categories_touch on public.categories;
create trigger categories_touch before update on public.categories
    for each row execute function public.touch_updated_at();

drop trigger if exists items_touch on public.items;
create trigger items_touch before update on public.items
    for each row execute function public.touch_updated_at();

drop trigger if exists settings_touch on public.settings;
create trigger settings_touch before update on public.settings
    for each row execute function public.touch_updated_at();

-- ============================================================================
-- Sécurité : RLS activé partout, aucune table n'est accessible par défaut.
-- ============================================================================
alter table public.categories    enable row level security;
alter table public.items         enable row level security;
alter table public.settings      enable row level security;
alter table public.opening_hours enable row level security;
alter table public.snapshots     enable row level security;

-- Le public ne voit que ce qui est en ligne : un plat masqué dans l'admin est
-- invisible au niveau de la base, pas seulement au niveau de l'affichage.
drop policy if exists categories_public_read on public.categories;
create policy categories_public_read on public.categories
    for select to anon using (visible = true);

drop policy if exists items_public_read on public.items;
create policy items_public_read on public.items
    for select to anon using (
        visible = true
        and exists (select 1 from public.categories c where c.id = category_id and c.visible = true)
    );

-- Coordonnées, horaires et liens : publics par nature.
drop policy if exists settings_public_read on public.settings;
create policy settings_public_read on public.settings for select to anon using (true);

drop policy if exists hours_public_read on public.opening_hours;
create policy hours_public_read on public.opening_hours for select to anon using (true);

-- L'administration : lecture complète et écriture, réservées aux comptes connectés.
drop policy if exists categories_admin on public.categories;
create policy categories_admin on public.categories for all to authenticated using (true) with check (true);

drop policy if exists items_admin on public.items;
create policy items_admin on public.items for all to authenticated using (true) with check (true);

drop policy if exists settings_admin on public.settings;
create policy settings_admin on public.settings for all to authenticated using (true) with check (true);

drop policy if exists hours_admin on public.opening_hours;
create policy hours_admin on public.opening_hours for all to authenticated using (true) with check (true);

-- Les sauvegardes ne sont jamais publiques.
drop policy if exists snapshots_admin on public.snapshots;
create policy snapshots_admin on public.snapshots for all to authenticated using (true) with check (true);

-- ============================================================================
-- Photos des plats : bucket « menu », lisible par tous, modifiable par l'admin.
--
-- Cette partie touche au schéma « storage », qui n'appartient pas au rôle
-- courant sur tous les projets. Un échec ici annulerait tout ce qui précède,
-- puisque l'éditeur SQL exécute le script dans une seule transaction : on
-- intercepte donc le refus de droits et on affiche la marche à suivre.
-- ============================================================================
do $$
begin
    insert into storage.buckets (id, name, public) values ('menu', 'menu', true)
        on conflict (id) do update set public = true;

    execute 'drop policy if exists menu_photos_read on storage.objects';
    execute 'create policy menu_photos_read on storage.objects
                 for select to anon, authenticated using (bucket_id = ''menu'')';

    execute 'drop policy if exists menu_photos_admin on storage.objects';
    execute 'create policy menu_photos_admin on storage.objects
                 for all to authenticated using (bucket_id = ''menu'')
                 with check (bucket_id = ''menu'')';

    raise notice 'Bucket « menu » et ses règles d''accès en place.';
exception when insufficient_privilege or undefined_table then
    raise notice 'Partie stockage ignorée (droits insuffisants). Créer le bucket public « menu » depuis l''onglet Storage, puis rejouer uniquement ce bloc.';
end $$;


-- ============================================================================
-- Contenu initial : la carte et les réglages actuels du site.
-- ============================================================================

-- Twins Sushi — reprise des données existantes.
-- Généré par supabase/generer-seed.php, à exécuter après schema.sql.

begin;

-- Reprise rejouable : on repart d'une base vide.
truncate public.items, public.categories, public.settings, public.opening_hours restart identity cascade;

insert into public.categories (id, title, jp, visible, sort_order) values
  ('entrees', 'Entrées', '前菜', true, 0),
  ('nems', 'Nems & rouleaux', '春巻き', true, 1),
  ('brochettes', 'Brochettes', '串焼き', true, 2),
  ('soupes', 'Soupes', 'スープ', true, 3),
  ('salades', 'Salades', 'サラダ', true, 4),
  ('makis', 'Makis', '巻き寿司', true, 5),
  ('california', 'California', 'カリフォルニア', true, 6),
  ('speciaux', 'Les Spéciaux', 'スペシャル', true, 7),
  ('futomaki', 'Futomaki', '太巻き', true, 8),
  ('aromaki', 'Aromaki', 'ライスペーパー巻き', true, 9),
  ('fry', 'Fry', '揚げ巻き', true, 10),
  ('pizza', 'Pizza sushi', '寿司ピザ', true, 11),
  ('nigiri', 'Nigiri', '握り寿司', true, 12),
  ('gunkan', 'Gunkan', '軍艦巻き', true, 13),
  ('temaki', 'Temaki', '手巻き', true, 14),
  ('sashimi', 'Sashimi', '刺身', true, 15),
  ('carpaccio', 'Carpaccio', 'カルパッチョ', true, 16),
  ('tataki', 'Tataki', 'たたき', true, 17),
  ('tartares', 'Tartares', 'タルタル', true, 18),
  ('chirashi', 'Chirashi', 'ちらし寿司', true, 19),
  ('poke', 'Poké', 'ポケ', true, 20),
  ('tacos', 'Tacos', 'タコス', true, 21),
  ('plats', 'Plats chauds', '温かい料理', true, 22),
  ('accompagnements', 'Accompagnements', '付け合わせ', true, 23),
  ('bentos', 'Bentos', '弁当', true, 24),
  ('assortiments', 'Assortiments', '盛り合わせ', true, 25),
  ('box', 'Box promo', 'お得なボックス', true, 26),
  ('desserts', 'Desserts', 'デザート', true, 27);

insert into public.items (code, category_id, name, description, price, pcs, show_pcs, badge, image, available, visible, sort_order) values
  ('ST01', 'entrees', 'Edamame Salé', 'Fèves de soja, sel', 55, 1, false, 'vege', 'assets/menu/st01.webp', true, true, 0),
  ('ST02', 'entrees', 'Edamame Épicé', 'Fèves de soja, sauce chili', 55, 1, false, 'epice', 'assets/menu/st02.webp', true, true, 1),
  ('FR03', 'entrees', 'Crevettes Tempura Mayo Ciboulette', 'Crevettes panées croustillantes, mayonnaise japonaise, ciboulette', 59, 7, true, 'croustillant', 'assets/menu/fr03.webp', true, true, 2),
  ('CR01', 'entrees', 'Croquettes Fromage', 'Fromage fondant et poivron, panure croustillante', 39, 4, true, 'croustillant', 'assets/menu/cr01.webp', true, true, 3),
  ('CR02', 'entrees', 'Croquettes Poulet Pané', 'Filet de poulet pané au panko', 45, 4, true, 'croustillant', 'assets/menu/cr02.webp', true, true, 4),
  ('CR03', 'entrees', 'Croquettes Poulet Aigre-Doux', 'Poulet pané, sauce aigre-douce, ciboulette. Environ 10 pièces', 49, 1, false, 'croustillant', 'assets/menu/cr03.webp', true, true, 5),
  ('CR04', 'entrees', 'Croquettes Saumon', 'Saumon grillé et riz, panure croustillante', 49, 4, true, 'croustillant', 'assets/menu/cr04.webp', true, true, 6),
  ('NM01', 'nems', 'Nem Veggie', 'Légumes croquants et vermicelles, feuille croustillante', 30, 3, true, '', 'assets/menu/nm01.webp', true, true, 0),
  ('NM02', 'nems', 'Nem Poulet', 'Poulet, légumes et vermicelles', 30, 3, true, '', 'assets/menu/nm02.webp', true, true, 1),
  ('NM03', 'nems', 'Nem Crevette', 'Crevettes, légumes et vermicelles', 35, 3, true, '', 'assets/menu/nm03.webp', true, true, 2),
  ('RP01', 'nems', 'Rouleau de Printemps Poulet', 'Galette de riz, poulet, salade, carotte, chou', 35, 6, true, '', 'assets/menu/rp01.webp', true, true, 3),
  ('RP02', 'nems', 'Rouleau de Printemps Crevettes', 'Galette de riz, crevettes, salade, carotte, chou', 40, 6, true, '', 'assets/menu/rp02.webp', true, true, 4),
  ('BR03', 'brochettes', 'Brochettes Poulet', 'Filet de poulet grillé', 40, 2, true, '', 'assets/menu/br03.webp', true, true, 0),
  ('BR06', 'brochettes', 'Brochettes Bœuf Fromage', 'Bœuf émincé et fromage fondant', 49, 2, true, '', 'assets/menu/br06.webp', true, true, 1),
  ('BR05', 'brochettes', 'Brochettes Gambas', 'Gambas grillées', 39, 2, true, '', 'assets/menu/br05.webp', true, true, 2),
  ('BR02', 'brochettes', 'Brochettes Crevettes', 'Crevettes grillées', 55, 2, true, '', 'assets/menu/br02.webp', true, true, 3),
  ('BR04', 'brochettes', 'Brochettes Saumon', 'Saumon frais grillé', 59, 2, true, '', 'assets/menu/br04.webp', true, true, 4),
  ('BR01', 'brochettes', 'Brochettes Thon', 'Thon frais grillé', null, 2, true, '', 'assets/menu/br01.webp', true, true, 5),
  ('SP05', 'soupes', 'Soupe Miso', 'Miso, tofu, algue wakamé', 45, 1, false, 'vege', 'assets/menu/sp05.webp', true, true, 0),
  ('SP06', 'soupes', 'Soupe Crabe', 'Chair de crabe, champignons, œuf', 55, 1, false, '', 'assets/menu/sp06.webp', true, true, 1),
  ('SP02', 'soupes', 'Soupe Vermicelle', 'Vermicelles, crevettes, poulet, champignons', 55, 1, false, '', 'assets/menu/sp02.webp', true, true, 2),
  ('SP01', 'soupes', 'Soupe Fruits de Mer', 'Crevettes, calamar, saumon, palourdes, champignons noirs', 65, 1, false, '', 'assets/menu/sp01.webp', true, true, 3),
  ('SP04', 'soupes', 'Soupe Tom Yum Kong', 'Soupe thaï épicée aux crevettes, citronnelle, champignons', 65, 1, false, 'epice', 'assets/menu/sp04.webp', true, true, 4),
  ('SP03', 'soupes', 'Soupe Tom Kha Kai', 'Soupe thaï au lait de coco, poulet, champignons', 70, 1, false, '', 'assets/menu/sp03.webp', true, true, 5),
  ('SL01', 'salades', 'Salade de Nouilles', 'Nouilles, saumon, crabe, brocoli, tomates cerises', 60, 1, false, '', 'assets/menu/sl01.webp', true, true, 0),
  ('SL02', 'salades', 'Salade Gambas', 'Crevettes panées, maïs, avocat', 60, 1, false, 'croustillant', 'assets/menu/sl02.webp', true, true, 1),
  ('SL05', 'salades', 'Salade Vietnamienne', 'Poulet, crevettes, chou, carotte, maïs, salade', 60, 1, false, '', 'assets/menu/sl05.webp', true, true, 2),
  ('SL03', 'salades', 'Salade de Bœuf', 'Bœuf sauté, ananas frais, avocat, maïs', 65, 1, false, '', 'assets/menu/sl03.webp', true, true, 3),
  ('SL04', 'salades', 'Wakame Saumon', 'Salade wakamé, saumon, avocat, surimi, tobiko', 65, 1, false, '', 'assets/menu/sl04.webp', true, true, 4),
  ('MK06', 'makis', 'Maki Concombre', 'Concombre croquant', 22, 6, true, 'vege', 'assets/menu/mk06.webp', true, true, 0),
  ('MK03', 'makis', 'Maki Avocat', 'Avocat crémeux', 28, 6, true, 'vege', 'assets/menu/mk03.webp', true, true, 1),
  ('MK04', 'makis', 'Maki Mangue', 'Mangue fraîche', 27, 6, true, 'vege', 'assets/menu/mk04.webp', true, true, 2),
  ('MK05', 'makis', 'Maki Saumon Avocat', 'Le duo incontournable : saumon frais et avocat', 32, 6, true, '', 'assets/menu/mk05.webp', true, true, 3),
  ('MK02', 'makis', 'Maki Saumon', 'Saumon frais', 35, 6, true, '', 'assets/menu/mk02.webp', true, true, 4),
  ('MK08', 'makis', 'Maki Thon Concombre', 'Thon frais et concombre', 39, 6, true, '', 'assets/menu/mk08.webp', true, true, 5),
  ('MK01', 'makis', 'Maki Thon Rouge', 'Thon rouge frais', 49, 6, true, '', 'assets/menu/mk01.webp', true, true, 6),
  ('MK09', 'makis', 'Maki Ebi Fry', 'Crevette panée', 49, 6, true, 'croustillant', 'assets/menu/mk09.webp', true, true, 7),
  ('CA05', 'california', 'California Classique', 'Surimi, concombre, avocat et cream cheese, roulé dans le tobiko', 40, 4, true, '', 'assets/menu/ca05.webp', true, true, 0),
  ('CA02', 'california', 'California Ebi Fry', 'Crevette panée, avocat', 40, 4, true, 'croustillant', 'assets/menu/ca02.webp', true, true, 1),
  ('CA03', 'california', 'California Saumon Avocat', 'Saumon frais, avocat', 40, 4, true, '', 'assets/menu/ca03.webp', true, true, 2),
  ('CA06', 'california', 'California Shake Yaki', 'Saumon grillé, cream cheese, tobiko', 40, 4, true, '', 'assets/menu/ca06.webp', true, true, 3),
  ('CA08', 'california', 'California Thon', 'Thon frais, concombre, cream cheese, sésame', 40, 4, true, '', 'assets/menu/ca08.webp', true, true, 4),
  ('CA07', 'speciaux', 'Ebi Cheese', 'Crevette panée, avocat, nappé de cream cheese, sésame', 36, 4, true, '', 'assets/menu/ca07.webp', true, true, 0),
  ('OK01', 'speciaux', 'Okinawa Cheese', 'Saumon frais et cream cheese', 45, 4, true, '', 'assets/menu/ok01.webp', true, true, 1),
  ('OK02', 'speciaux', 'Okinawa Anguille', 'Saumon frais, cream cheese, anguille', 45, 4, true, '', 'assets/menu/ok02.webp', true, true, 2),
  ('OK03', 'speciaux', 'Okinawa Spicy', 'Saumon frais, cream cheese, sauce chili', 45, 4, true, 'epice', 'assets/menu/ok03.webp', true, true, 3),
  ('FM01', 'futomaki', 'Futomaki Crevette Avocat Surimi', 'Crevette panée, avocat, surimi', 49, 6, true, '', 'assets/menu/fm01.webp', true, true, 0),
  ('FM02', 'futomaki', 'Futomaki Saumon Avocat Surimi', 'Saumon frais, avocat, surimi', 55, 6, true, '', 'assets/menu/fm02.webp', true, true, 1),
  ('FM03', 'futomaki', 'Futomaki Crab Saumon Surimi', 'Chair de crabe, saumon frais, surimi', 59, 6, true, '', 'assets/menu/fm03.webp', true, true, 2),
  ('AR01', 'aromaki', 'Aromaki Saumon Avocat', 'Galette de riz, saumon frais, avocat, surimi, cream cheese, tobiko', 59, 6, true, '', 'assets/menu/ar01.webp', true, true, 0),
  ('AR02', 'aromaki', 'Aromaki Crevette Avocat', 'Galette de riz, crevette panée, avocat, surimi, cream cheese', 59, 6, true, '', 'assets/menu/ar02.webp', true, true, 1),
  ('AR03', 'aromaki', 'Aromaki Goma Wakame', 'Galette de riz, saumon, surimi, salade wakamé, cream cheese', 59, 6, true, '', 'assets/menu/ar03.webp', true, true, 2),
  ('AR05', 'aromaki', 'Aromaki Saumon Mangue', 'Galette de riz, saumon frais, mangue, cream cheese, gingembre', 59, 6, true, '', 'assets/menu/ar05.webp', true, true, 3),
  ('AR06', 'aromaki', 'Aromaki Thon', 'Galette de riz, thon frais, concombre, salade', 59, 6, true, '', 'assets/menu/ar06.webp', true, true, 4),
  ('AR07', 'aromaki', 'Aromaki Poulet', 'Galette de riz, poulet, concombre, salade', 59, 6, true, '', 'assets/menu/ar07.webp', true, true, 5),
  ('AR04', 'aromaki', 'Aromaki Crabe', 'Galette de riz, chair de crabe, avocat, cream cheese, salade', 65, 6, true, '', 'assets/menu/ar04.webp', true, true, 6),
  ('FR01', 'fry', 'Fry Ebi', 'Roulé pané : crevette, surimi', 55, 6, true, 'croustillant', 'assets/menu/fr01.webp', true, true, 0),
  ('FR02', 'fry', 'Fry Saumon', 'Roulé pané : saumon frais, surimi', 55, 6, true, 'croustillant', 'assets/menu/fr02.webp', true, true, 1),
  ('FR06', 'fry', 'Fry Pacific', 'Roulé pané : crevette, avocat, surimi', 55, 6, true, 'croustillant', 'assets/menu/fr06.webp', true, true, 2),
  ('FR05', 'fry', 'Fry Chicken', 'Roulé pané : poulet, avocat, cream cheese', 50, 6, true, 'croustillant', 'assets/menu/fr05.webp', true, true, 3),
  ('PZ01', 'pizza', 'Pizza Saumon Avocat', 'Base de riz, saumon frais, avocat', 59, 8, true, '', 'assets/menu/pz01.webp', true, true, 0),
  ('PZ02', 'pizza', 'Pizza Saumon Crabe', 'Base de riz, saumon frais, avocat, chair de crabe', 59, 8, true, '', 'assets/menu/pz02.webp', true, true, 1),
  ('PZ03', 'pizza', 'Pizza Mozzarella', 'Base de riz, saumon, mozzarella gratinée', 59, 8, true, '', 'assets/menu/pz03.webp', true, true, 2),
  ('NG05', 'nigiri', 'Nigiri Avocat', 'Avocat sur riz vinaigré', 25, 2, true, 'vege', 'assets/menu/ng05.webp', true, true, 0),
  ('NG03', 'nigiri', 'Nigiri Mangue', 'Mangue sur riz vinaigré', 25, 2, true, 'vege', 'assets/menu/ng03.webp', true, true, 1),
  ('NG04', 'nigiri', 'Nigiri Poisson Blanc', 'Loup frais sur riz vinaigré', 25, 2, true, '', 'assets/menu/ng04.webp', true, true, 2),
  ('NG01', 'nigiri', 'Nigiri Saumon', 'Saumon frais sur riz vinaigré', 30, 2, true, '', 'assets/menu/ng01.webp', true, true, 3),
  ('NG02', 'nigiri', 'Nigiri Crevette', 'Crevette sur riz vinaigré', 30, 2, true, '', 'assets/menu/ng02.webp', true, true, 4),
  ('NG06', 'nigiri', 'Nigiri Anguille', 'Anguille laquée sur riz vinaigré', 35, 2, true, '', 'assets/menu/ng06.webp', true, true, 5),
  ('NG07', 'nigiri', 'Nigiri Thon', 'Thon frais sur riz vinaigré', 39, 2, true, '', 'assets/menu/ng07.webp', true, true, 6),
  ('GK06', 'gunkan', 'Gunkan Crabe', 'Chair de crabe', 55, 2, true, '', 'assets/menu/gk06.webp', true, true, 0),
  ('GK05', 'gunkan', 'Gunkan Saumon Ciboulette', 'Saumon frais haché, ciboulette', 55, 2, true, '', 'assets/menu/gk05.webp', true, true, 1),
  ('GK04', 'gunkan', 'Gunkan Saumon Épicé', 'Saumon frais, mayonnaise épicée maison', 55, 2, true, 'epice', 'assets/menu/gk04.webp', true, true, 2),
  ('GK02', 'gunkan', 'Gunkan Thon', 'Thon frais haché', 59, 2, true, '', 'assets/menu/gk02.webp', true, true, 3),
  ('GK03', 'gunkan', 'Gunkan Tobiko', 'Œufs de poisson volant', 69, 2, true, '', 'assets/menu/gk03.webp', true, true, 4),
  ('TM01', 'temaki', 'Temaki Saumon', 'Cornet de nori, saumon frais, concombre', 50, 1, false, '', 'assets/menu/tm01.webp', true, true, 0),
  ('TM03', 'temaki', 'Temaki Thon', 'Cornet de nori, thon frais, concombre', 50, 1, false, '', 'assets/menu/tm03.webp', true, true, 1),
  ('TM02', 'temaki', 'Temaki Anguille', 'Cornet de nori, anguille, concombre', 59, 1, false, '', 'assets/menu/tm02.webp', true, true, 2),
  ('SB02', 'sashimi', 'Sashimi Saumon', 'Tranches de saumon frais', 45, 4, true, '', 'assets/menu/sb02.webp', true, true, 0),
  ('SB01', 'sashimi', 'Sashimi Poisson Blanc', 'Tranches de loup frais', 45, 4, true, '', 'assets/menu/sb01.webp', true, true, 1),
  ('SB03', 'sashimi', 'Sashimi Thon Rouge', 'Tranches de thon rouge frais', 45, 4, true, '', 'assets/menu/sb03.webp', true, true, 2),
  ('CP02', 'carpaccio', 'Carpaccio Saumon', 'Fines tranches de saumon frais', 59, 9, false, '', 'assets/menu/cp02.webp', true, true, 0),
  ('CP01', 'carpaccio', 'Carpaccio Poisson Blanc', 'Fines tranches de loup frais', 59, 9, false, '', 'assets/menu/cp01.webp', true, true, 1),
  ('CP03', 'carpaccio', 'Carpaccio Thon Rouge', 'Fines tranches de thon rouge', 59, 9, false, '', 'assets/menu/cp03.webp', true, true, 2),
  ('TK01', 'tataki', 'Tataki Saumon', 'Saumon snacké, sauce soja, sésame', 55, 5, true, '', 'assets/menu/tk01.webp', true, true, 0),
  ('TK02', 'tataki', 'Tataki Thon', 'Thon snacké en croûte de sésame', 55, 5, true, '', 'assets/menu/tk02.webp', true, true, 1),
  ('TR01', 'tartares', 'Tartare Saumon', 'Saumon frais, avocat, riz vinaigré', 59, 1, false, '', 'assets/menu/tr01.webp', true, true, 0),
  ('TR02', 'tartares', 'Tartare Thon', 'Thon frais, avocat, riz vinaigré', 59, 1, false, '', 'assets/menu/tr02.webp', true, true, 1),
  ('TR04', 'tartares', 'Tartare Poisson Blanc', 'Loup frais, avocat, riz vinaigré', 59, 1, false, '', 'assets/menu/tr04.webp', true, true, 2),
  ('TR03', 'tartares', 'Tartare Crabe', 'Chair de crabe, avocat, riz vinaigré', 59, 1, false, '', 'assets/menu/tr03.webp', true, true, 3),
  ('CH01', 'chirashi', 'Chirashi Saumon', 'Bol de riz vinaigré, saumon frais', 69, 9, false, '', 'assets/menu/ch01.webp', true, true, 0),
  ('CH02', 'chirashi', 'Chirashi Saumon Avocat', 'Riz vinaigré, saumon frais, avocat', 79, 9, false, '', 'assets/menu/ch02.webp', true, true, 1),
  ('CH05', 'chirashi', 'Chirashi Saumon Mangue Avocat', 'Riz vinaigré, saumon frais, mangue, avocat', 79, 9, false, '', 'assets/menu/ch05.webp', true, true, 2),
  ('CH04', 'chirashi', 'Chirashi Thon', 'Riz vinaigré, thon frais', 69, 9, false, '', 'assets/menu/ch04.webp', true, true, 3),
  ('CH06', 'chirashi', 'Chirashi Mixte Thon Saumon', 'Riz vinaigré, thon et saumon frais', 79, 9, false, '', 'assets/menu/ch06.webp', true, true, 4),
  ('CH03', 'chirashi', 'Chirashi Anguille', 'Riz vinaigré, anguille laquée', 89, 9, false, '', 'assets/menu/ch03.webp', true, true, 5),
  ('PK01', 'poke', 'Poké Saumon', 'Riz vinaigré, saumon, wakamé, avocat, surimi', 70, 1, false, '', 'assets/menu/pk01.webp', true, true, 0),
  ('PK02', 'poke', 'Poké Crevettes', 'Riz vinaigré, crevettes, wakamé, avocat, mangue', 70, 1, false, '', 'assets/menu/pk02.webp', true, true, 1),
  ('PK03', 'poke', 'Poké Végétarien', 'Riz vinaigré, mangue, edamame, avocat, chou rouge, carotte', 65, 1, false, 'vege', 'assets/menu/pk03.webp', true, true, 2),
  ('TC01', 'tacos', 'Tacos Saumon', 'Saumon frais, avocat, tortilla', 69, 3, true, '', 'assets/menu/tc01.webp', true, true, 0),
  ('TC02', 'tacos', 'Tacos Thon', 'Thon frais, avocat, tortilla', 69, 3, true, '', 'assets/menu/tc02.webp', true, true, 1),
  ('TC04', 'tacos', 'Tacos Crevette', 'Crevettes, avocat, tortilla', 69, 3, true, '', 'assets/menu/tc04.webp', true, true, 2),
  ('TC03', 'tacos', 'Tacos Crabe', 'Chair de crabe, avocat, tortilla', 69, 3, true, '', 'assets/menu/tc03.webp', true, true, 3),
  ('NU01', 'plats', 'Nouilles Sautées au Wok Veggie', 'Nouilles sautées au wok, légumes croquants', 49, 1, false, 'vege', 'assets/menu/nu01.webp', true, true, 0),
  ('RC01', 'plats', 'Riz Cantonais Veggie', 'Riz sauté, légumes, champignons noirs', 35, 1, false, 'vege', 'assets/menu/rc01.webp', true, true, 1),
  ('PL02', 'plats', 'Plat Basilic Légumes', 'Légumes sautés au basilic, riz blanc', 59, 1, false, 'vege', 'assets/menu/pl02.webp', true, true, 2),
  ('PL03', 'plats', 'Plat Gingembre Légumes', 'Légumes sautés au gingembre, riz blanc', 59, 1, false, 'vege', 'assets/menu/pl03.webp', true, true, 3),
  ('PL01', 'plats', 'Saumon Sauce Légumes', 'Saumon, légumes, curry rouge et lait de coco, riz blanc', 89, 1, false, '', 'assets/menu/pl01.webp', true, true, 4),
  ('AC02', 'accompagnements', 'Riz Nature', 'Riz blanc', 25, 1, false, 'vege', 'assets/menu/ac02.webp', true, true, 0),
  ('AC05', 'accompagnements', 'Riz Vinaigré', 'Riz à sushi vinaigré, sésame', 28, 1, false, 'vege', 'assets/menu/ac05.webp', true, true, 1),
  ('AC03', 'accompagnements', 'Salade de Choux', 'Chou blanc et carotte, vinaigrette sucrée', 20, 1, false, 'vege', 'assets/menu/ac03.webp', true, true, 2),
  ('AC04', 'accompagnements', 'Nouilles Sautées Légumes', 'Nouilles, carotte, courgette, poivrons', 45, 1, false, 'vege', 'assets/menu/ac04.webp', true, true, 3),
  ('AC01', 'accompagnements', 'Salade Wakame', 'Algues wakamé, tomates cerises, tobiko', 59, 1, false, '', 'assets/menu/ac01.webp', true, true, 4),
  ('BT01', 'bentos', 'Bento 1', 'California ebi fry, california cream cheese, nems poulet, salade de choux, petite soupe fruits de mer', 109, 1, false, '', 'assets/menu/bt01.webp', true, true, 0),
  ('BT02', 'bentos', 'Bento 2', 'California ebi fry, maki ebi fry, crevettes panées, salade de choux, petite soupe fruits de mer', 119, 1, false, '', 'assets/menu/bt02.webp', true, true, 1),
  ('BT03', 'bentos', 'Bento 3', 'California cream cheese, maki saumon, riz nature, nouilles, petite soupe fruits de mer', 119, 1, false, '', 'assets/menu/bt03.webp', true, true, 2),
  ('BT04', 'bentos', 'Bento 4', 'Fry ebi, crevettes panées, nouilles, petite soupe fruits de mer', 119, 1, false, '', 'assets/menu/bt04.webp', true, true, 3),
  ('BT05', 'bentos', 'Bento 5', 'California ebi fry, croquettes saumon, nems poulet, riz cantonais, petite soupe fruits de mer', 119, 1, false, '', 'assets/menu/bt05.webp', true, true, 4),
  ('BT06', 'bentos', 'Bento 6', 'California ebi fry, croquettes saumon, fry ebi, salade de choux, petite soupe fruits de mer', 129, 1, false, '', 'assets/menu/bt06.webp', true, true, 5),
  ('AS09', 'assortiments', 'Assortiment Salmon', 'Délice saumon, maki saumon, sashimi saumon', 119, 14, true, '', 'assets/menu/as09.webp', true, true, 0),
  ('AS03', 'assortiments', 'Assortiment California Mixte', 'California cream cheese, classique, ebi fry, saumon avocat', 109, 16, true, '', 'assets/menu/as03.webp', true, true, 1),
  ('AS05', 'assortiments', 'Assortiment Spéciaux Mixte', 'Ebi avocado, crab roll, mango, shaker roll', 169, 16, true, '', 'assets/menu/as05.webp', true, true, 2),
  ('AS01', 'assortiments', 'Assortiment Smart', 'California cream cheese, california ebi fry, fry ebi, fry saumon', 119, 20, true, '', 'assets/menu/as01.webp', true, true, 3),
  ('AS04', 'assortiments', 'Assortiment California Découverte', '5 california : saumon avocat, cream cheese, classique, ebi fry, crunchy crevette', 139, 20, true, '', 'assets/menu/as04.webp', true, true, 4),
  ('AS02', 'assortiments', 'Twin Box', '3 california, aromaki saumon avocat, aromaki crevettes avocat', 159, 24, true, '', 'assets/menu/as02.webp', true, true, 5),
  ('FR04', 'assortiments', 'Fry Over', 'Fry ebi, fry saumon, pizza saumon avocat, crunchy crevettes', 169, 24, true, 'croustillant', 'assets/menu/fr04.webp', true, true, 6),
  ('AS07', 'assortiments', 'Assortiment Fraîcheur', 'Aromaki, fry, crunchy crevette et pizza saumon avocat', 199, 34, true, 'partager', 'assets/menu/as07.webp', true, true, 7),
  ('AS06', 'assortiments', 'Assortiment Prestige', 'Spéciaux, futomaki, maki, aromaki et fry : le grand tour', 359, 51, true, 'partager', 'assets/menu/as06.webp', true, true, 8),
  ('AS08', 'assortiments', 'Assortiment Découverte', 'California, aromaki, maki, fry, pizza, futomaki : toute la carte en une box', 389, 57, true, 'partager', 'assets/menu/as08.webp', true, true, 9),
  ('BP01', 'box', 'Box Promo Combo', 'Fry ebi, fry pacific, croquettes saumon', 89, 16, true, '', 'assets/menu/bp01.webp', true, true, 0),
  ('BP02', 'box', 'Box Promo Crazy Twins', '3 california et maki saumon', 99, 18, true, '', 'assets/menu/bp02.webp', true, true, 1),
  ('BP03', 'box', 'Box Promo Super Mix', '3 california, fry ebi, fry saumon', 119, 24, true, '', 'assets/menu/bp03.webp', true, true, 2),
  ('BP04', 'box', 'Box Promo Mega Box', '4 california, 2 makis, 2 fry, croquettes saumon', 199, 44, true, 'partager', 'assets/menu/bp04.webp', true, true, 3),
  ('DS01', 'desserts', 'Panna Cotta', 'Crème onctueuse à l’italienne', 45, 1, false, '', 'assets/menu/ds01.webp', true, true, 0),
  ('DS02', 'desserts', 'Dessert Mangue Tapioca', 'Perles de tapioca, mangue, lait de coco', 45, 1, false, '', 'assets/menu/ds02.webp', true, true, 1),
  ('DS03', 'desserts', 'Nems Nutella', 'Nems croustillants au Nutella', 40, 3, false, '', 'assets/menu/ds03.webp', true, true, 2);

insert into public.settings (key, value) values
  ('url', '"https://www.twinssushi.com"'::jsonb),
  ('tel_affiche', '"05 21 23 19 26"'::jsonb),
  ('tel', '"+212521231926"'::jsonb),
  ('wa_affiche', '"06 19 16 20 94"'::jsonb),
  ('wa', '"212619162094"'::jsonb),
  ('email', '"twins.sushi01@gmail.com"'::jsonb),
  ('rue', '"167 rue Ibnou Faris"'::jsonb),
  ('quartier', '"Maârif"'::jsonb),
  ('cp', '"20250"'::jsonb),
  ('ville', '"Casablanca"'::jsonb),
  ('glovo', '""'::jsonb),
  ('yassir', '""'::jsonb),
  ('kool', '""'::jsonb),
  ('instagram', '"https://www.instagram.com/twins_sushi01/"'::jsonb),
  ('tiktok', '""'::jsonb),
  ('facebook', '""'::jsonb),
  ('bandeau_texte', '"Ouverture le lundi 28 septembre · 167 rue Ibnou Faris, Maârif"'::jsonb),
  ('bandeau_fin', '"2026-09-28"'::jsonb),
  ('annonce', '""'::jsonb);

insert into public.opening_hours (label, days, opens, closes, display, sort_order) values
  ('Lundi – Vendredi', '1,2,3,4,5', '11:00', '23:59', '11h00 – minuit', 0),
  ('Samedi – Dimanche', '6,0', '13:00', '23:59', '13h00 – minuit', 1);

commit;
