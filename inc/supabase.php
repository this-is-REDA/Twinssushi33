<?php
/* Twins Sushi — accès à Supabase (base de données, authentification, photos).
   Deux clés seulement, toutes deux publiques par nature :
     SUPABASE_URL      https://xxxx.supabase.co
     SUPABASE_ANON_KEY clé « anon », protégée par les règles RLS de la base
   Les écritures voyagent avec le jeton de la personne connectée, jamais avec
   une clé d'administration : la base reste seule juge de ce qui est permis. */
declare(strict_types=1);

const SB_TIMEOUT = 10;

/* Selon l'hébergeur, une variable d'environnement arrive dans $_SERVER, dans
   $_ENV ou seulement via getenv() : on regarde les trois plutôt que de dépendre
   d'un seul, sinon le site tombe en panne sur une plateforme et pas sur l'autre. */
function sb_env(string $name): string {
    foreach ([$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)] as $v) {
        if (is_string($v) && $v !== '') return trim($v);
    }
    return '';
}

function sb_url(): string {
    $u = rtrim(sb_env('SUPABASE_URL'), '/');
    if ($u === '') throw new RuntimeException('SUPABASE_URL manquante.');
    return $u;
}
function sb_key(): string {
    $k = sb_env('SUPABASE_ANON_KEY');
    if ($k === '') throw new RuntimeException('SUPABASE_ANON_KEY manquante.');
    return $k;
}
function sb_configured(): bool { return sb_env('SUPABASE_URL') !== '' && sb_env('SUPABASE_ANON_KEY') !== ''; }

/* Appel HTTP brut. Renvoie [code HTTP, corps décodé, en-têtes de réponse].
   $token : jeton d'accès de la personne connectée, sinon simple accès public. */
function sb_call(string $method, string $path, ?array $body = null, ?string $token = null, array $extra = []): array {
    $ch = curl_init(sb_url() . $path);
    /* La clé du projet voyage uniquement dans « apikey ». Les clés du nouveau
       format (sb_publishable_…) ne sont pas des JWT : les placer dans
       « Authorization: Bearer » ferait rejeter la requête. Cet en-tête est donc
       réservé au jeton de la personne connectée, quand il y en a un. */
    $headers = array_merge([
        'apikey: ' . sb_key(),
        'Accept: application/json',
    ], $extra);
    if ($token !== null && $token !== '') $headers[] = 'Authorization: Bearer ' . $token;
    if ($body !== null) $headers[] = 'Content-Type: application/json';

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => SB_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_POSTFIELDS     => $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch); curl_close($ch);
        throw new RuntimeException('Base de données injoignable : ' . $err);
    }
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $cut  = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $head = substr((string)$raw, 0, $cut);
    $json = json_decode(substr((string)$raw, $cut) ?: 'null', true);
    return [$code, $json, $head];
}

/* Lecture / écriture de la base (PostgREST). */
function sb_select(string $table, string $query = '', ?string $token = null): array {
    [$code, $data] = sb_call('GET', "/rest/v1/$table" . ($query === '' ? '' : "?$query"), null, $token);
    if ($code !== 200 || !is_array($data)) throw sb_error($code, $data, "Lecture de « $table » impossible");
    return $data;
}
function sb_write(string $method, string $table, string $query, ?array $body, string $token, bool $returning = false): array {
    $extra = ['Prefer: ' . ($returning ? 'return=representation' : 'return=minimal')];
    if ($method === 'POST') $extra[0] .= ',resolution=merge-duplicates';
    [$code, $data] = sb_call($method, "/rest/v1/$table" . ($query === '' ? '' : "?$query"), $body, $token, $extra);
    if ($code < 200 || $code > 299) throw sb_error($code, $data, 'Enregistrement impossible');
    return is_array($data) ? $data : [];
}

/* Traduit un refus de la base en message utile. Un jeton périmé ou falsifié ne
   doit pas afficher un message technique : c'est une simple reconnexion. */
function sb_error(int $code, $data, string $fallback): RuntimeException {
    if ($code === 401 || $code === 403) return new RuntimeException('Session expirée, reconnectez-vous.', 401);
    return new RuntimeException(sb_message($data, $fallback), $code >= 400 && $code < 600 ? $code : 500);
}

/* Message d'erreur lisible, sans révéler la structure interne de la base. */
function sb_message($data, string $fallback): string {
    if (is_array($data)) {
        $m = (string)($data['message'] ?? $data['error_description'] ?? $data['msg'] ?? $data['error'] ?? '');
        if ($m !== '') return $fallback . ' : ' . $m;
    }
    return $fallback . '.';
}

/* ---------------------------------------------------- Authentification ----- */

/* Connexion par e-mail et mot de passe. Renvoie les jetons, ou null si refusé. */
function sb_sign_in(string $email, string $password): ?array {
    [$code, $d] = sb_call('POST', '/auth/v1/token?grant_type=password', ['email' => $email, 'password' => $password]);
    if ($code !== 200 || !is_array($d) || empty($d['access_token'])) return null;
    return [
        'access_token'  => (string)$d['access_token'],
        'refresh_token' => (string)($d['refresh_token'] ?? ''),
        'expires_at'    => (int)($d['expires_at'] ?? time() + 3600),
        'email'         => (string)($d['user']['email'] ?? $email),
    ];
}

/* Renouvelle un jeton expiré sans redemander le mot de passe. */
function sb_refresh(string $refreshToken): ?array {
    [$code, $d] = sb_call('POST', '/auth/v1/token?grant_type=refresh_token', ['refresh_token' => $refreshToken]);
    if ($code !== 200 || !is_array($d) || empty($d['access_token'])) return null;
    return [
        'access_token'  => (string)$d['access_token'],
        'refresh_token' => (string)($d['refresh_token'] ?? $refreshToken),
        'expires_at'    => (int)($d['expires_at'] ?? time() + 3600),
        'email'         => (string)($d['user']['email'] ?? ''),
    ];
}

/* Vérifie un jeton auprès de Supabase et renvoie l'e-mail du compte. */
function sb_user(string $token): ?string {
    [$code, $d] = sb_call('GET', '/auth/v1/user', null, $token);
    if ($code !== 200 || !is_array($d) || empty($d['email'])) return null;
    return (string)$d['email'];
}

function sb_sign_out(string $token): void {
    try { sb_call('POST', '/auth/v1/logout', [], $token); } catch (Throwable) { /* sans conséquence */ }
}

function sb_change_password(string $token, string $password): bool {
    [$code] = sb_call('PUT', '/auth/v1/user', ['password' => $password], $token);
    return $code === 200;
}

/* ------------------------------------------------------------- Photos ----- */

/* Envoie une image dans le bucket « menu » et renvoie son adresse publique.
   Appel direct plutôt que sb_call : le corps est binaire, pas du JSON. */
function sb_upload_image(string $token, string $name, string $bytes, string $mime): string {
    $ch = curl_init(sb_url() . "/storage/v1/object/menu/$name");
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['apikey: ' . sb_key(), 'Authorization: Bearer ' . $token, 'Content-Type: ' . $mime, 'x-upsert: true'],
        CURLOPT_POSTFIELDS     => $bytes,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $res = curl_exec($ch);
    $st  = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($st < 200 || $st > 299) throw new RuntimeException(sb_message(json_decode((string)$res, true), 'Envoi de la photo impossible'));
    return sb_url() . "/storage/v1/object/public/menu/$name";
}

function sb_delete_image(string $token, string $name): void {
    try { sb_call('DELETE', "/storage/v1/object/menu/$name", null, $token); } catch (Throwable) { /* photo déjà absente */ }
}
