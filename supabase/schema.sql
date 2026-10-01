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
