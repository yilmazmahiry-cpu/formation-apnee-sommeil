<?php
declare(strict_types=1);

/* ============================================================================================
 * Socle commun de l'admin Somnéa : configuration, base SQLite, authentification, utilitaires,
 * API Conversions Meta.
 * ========================================================================================== */

const SPECIALITES = [
    'generaliste' => 'Généraliste',
    'cardio'      => 'Cardiologue',
    'endocrino'   => 'Endocrinologue',
    'orl'         => 'ORL',
    'dentiste'    => 'Chirurgien-dentiste',
    'travail'     => 'Médecin du travail',
    'pharmacien'  => 'Pharmacien',
    'general'     => 'Accroche générale',
];

const LEAD_STATUTS = [
    'a_rappeler' => 'À rappeler',
    'rappele'    => 'Rappelé',
    'interesse'  => 'Intéressé',
    'inscrit'    => 'Inscrit',
    'perdu'      => 'Perdu',
];

function cfg(?string $key = null)
{
    static $c = null;
    if ($c === null) {
        $file = __DIR__ . '/config.php';
        if (!is_file($file)) {
            http_response_code(500);
            exit('Configuration manquante : copiez config.sample.php en config.php.');
        }
        $c = require $file;
        date_default_timezone_set($c['timezone'] ?? 'Europe/Paris');
    }
    return $key === null ? $c : ($c[$key] ?? null);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $pdo = new PDO('sqlite:' . $dir . '/somnea.sqlite', null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS sales (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id      TEXT NOT NULL UNIQUE,      -- cs_… (Stripe Checkout)
        created_at      TEXT NOT NULL,             -- date locale ISO
        amount          INTEGER NOT NULL,          -- en centimes
        currency        TEXT NOT NULL DEFAULT 'eur',
        email           TEXT,
        name            TEXT,
        reference       TEXT,                      -- client_reference_id brut
        source          TEXT NOT NULL,             -- meta_lp | site
        spe             TEXT,                      -- spécialité (landing) ou NULL
        consent_ads     INTEGER NOT NULL DEFAULT 0,
        lead_id         INTEGER REFERENCES leads(id) ON DELETE SET NULL,
        capi_sent       INTEGER NOT NULL DEFAULT 0
    );
    CREATE INDEX IF NOT EXISTS sales_created ON sales(created_at);

    CREATE TABLE IF NOT EXISTS leads (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at      TEXT NOT NULL,
        prenom          TEXT NOT NULL,
        nom             TEXT NOT NULL,
        email           TEXT NOT NULL,
        telephone       TEXT,
        profession      TEXT,
        mode_exercice   TEXT,
        message         TEXT,
        spe             TEXT,
        source          TEXT,
        utm_campaign    TEXT,
        utm_content     TEXT,
        consent_ads     INTEGER NOT NULL DEFAULT 0,
        event_id        TEXT,
        statut          TEXT NOT NULL DEFAULT 'a_rappeler',
        note            TEXT,
        updated_at      TEXT
    );
    CREATE INDEX IF NOT EXISTS leads_created ON leads(created_at);
    CREATE INDEX IF NOT EXISTS leads_email ON leads(email);

    CREATE TABLE IF NOT EXISTS meta_insights (
        date            TEXT NOT NULL,
        ad_id           TEXT NOT NULL,
        campaign_name   TEXT,
        adset_name      TEXT,
        ad_name         TEXT,
        spe             TEXT,
        spend           REAL NOT NULL DEFAULT 0,
        impressions     INTEGER NOT NULL DEFAULT 0,
        link_clicks     INTEGER NOT NULL DEFAULT 0,
        landing_views   INTEGER NOT NULL DEFAULT 0,
        meta_leads      INTEGER NOT NULL DEFAULT 0,
        meta_purchases  INTEGER NOT NULL DEFAULT 0,
        meta_purchase_value REAL NOT NULL DEFAULT 0,
        PRIMARY KEY (date, ad_id)
    );

    CREATE TABLE IF NOT EXISTS kv (k TEXT PRIMARY KEY, v TEXT);
    CREATE TABLE IF NOT EXISTS hits (ip TEXT NOT NULL, ts INTEGER NOT NULL);
    CREATE INDEX IF NOT EXISTS hits_ip ON hits(ip, ts);
    SQL);
}

function kv_get(string $k): ?string
{
    $s = db()->prepare('SELECT v FROM kv WHERE k = ?');
    $s->execute([$k]);
    $v = $s->fetchColumn();
    return $v === false ? null : (string)$v;
}

function kv_set(string $k, string $v): void
{
    db()->prepare('INSERT INTO kv(k, v) VALUES(?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')->execute([$k, $v]);
}

/* ---------- Sécurité / session ----------------------------------------------------------- */

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('somnea_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/') ?: '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function is_logged_in(): bool
{
    start_session();
    return !empty($_SESSION['ok']) && ($_SESSION['exp'] ?? 0) > time();
}

/** Renvoie l'identifiant si le couple est valide, sinon null. Temps constant pour un identifiant inconnu. */
function check_credentials(string $user, string $password): ?string
{
    $user = mb_strtolower(trim($user));
    $users = array_change_key_case((array)(cfg('admin_users') ?? []), CASE_LOWER);
    $hash = $users[$user] ?? null;
    if (!$hash) {
        password_verify($password, 'y$MTkYarbA2lf7GQ22HgRv7uL5CUPF3cRg88sNr5X8TTUtUKGDjLrqW'); // leurre
        return null;
    }
    return password_verify($password, (string)$hash) ? $user : null;
}

function current_user(): string
{
    start_session();
    return (string)($_SESSION['user'] ?? '');
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
    $_SESSION['exp'] = time() + 8 * 3600; // 8 h d'inactivité max
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function check_csrf(): void
{
    start_session();
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Jeton de sécurité invalide, rechargez la page.');
    }
}

function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** Limite simple : $max requêtes par $window secondes et par IP. */
function rate_limited(string $bucket, int $max, int $window): bool
{
    $ip = $bucket . ':' . client_ip();
    $now = time();
    $pdo = db();
    $pdo->prepare('DELETE FROM hits WHERE ts < ?')->execute([$now - 86400]);
    $s = $pdo->prepare('SELECT COUNT(*) FROM hits WHERE ip = ? AND ts > ?');
    $s->execute([$ip, $now - $window]);
    if ((int)$s->fetchColumn() >= $max) return true;
    $pdo->prepare('INSERT INTO hits(ip, ts) VALUES(?, ?)')->execute([$ip, $now]);
    return false;
}

/* ---------- Utilitaires ------------------------------------------------------------------ */

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function now_iso(): string
{
    return date('Y-m-d H:i:s');
}

function eur(float $v, int $dec = 0): string
{
    return number_format($v, $dec, ',', "\u{202F}") . "\u{00A0}€";
}

function pct(?float $v): string
{
    return $v === null ? '—' : number_format($v * 100, 1, ',', '') . "\u{00A0}%";
}

function spe_label(?string $spe): string
{
    return SPECIALITES[$spe ?? ''] ?? 'Non renseignée';
}

/**
 * Lit le client_reference_id envoyé par la landing : « meta-lp-<spe>[-c1] ».
 * -c1 = le visiteur avait accepté les cookies publicitaires au moment du clic.
 */
function parse_reference(?string $ref): array
{
    $ref = (string)$ref;
    if (preg_match('/^meta-lp-([a-z]+)(-c1)?$/', $ref, $m)) {
        return ['source' => 'meta_lp', 'spe' => array_key_exists($m[1], SPECIALITES) ? $m[1] : 'general', 'consent' => !empty($m[2])];
    }
    return ['source' => 'site', 'spe' => null, 'consent' => false];
}

/** Spécialité d'une pub d'après son nom : y inclure le mot-clé, ex. « A4 · cardio · carrousel ». */
function spe_from_ad_name(?string $name): ?string
{
    $n = mb_strtolower((string)$name);
    foreach (array_keys(SPECIALITES) as $slug) {
        if ($slug !== 'general' && preg_match('/\b' . $slug . '\b/u', $n)) return $slug;
    }
    return null;
}

function http_json(string $method, string $url, ?array $body = null, array $headers = [], ?string $basicUser = null): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
    ];
    if ($basicUser !== null) $opts[CURLOPT_USERPWD] = $basicUser . ':';
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('Erreur réseau : ' . $err);
    $data = json_decode((string)$raw, true);
    if ($code >= 400) {
        $msg = $data['error']['message'] ?? substr((string)$raw, 0, 300);
        throw new RuntimeException("HTTP $code : $msg");
    }
    return is_array($data) ? $data : [];
}

/* ---------- API Conversions Meta ---------------------------------------------------------- */

function capi_hash(?string $v): ?string
{
    $v = trim(mb_strtolower((string)$v));
    return $v === '' ? null : hash('sha256', $v);
}

function capi_phone(?string $tel): ?string
{
    $d = preg_replace('/\D+/', '', (string)$tel);
    if ($d === '') return null;
    if (strlen($d) === 10 && $d[0] === '0') $d = '33' . substr($d, 1); // 06… → 336…
    return hash('sha256', $d);
}

/**
 * Envoie un évènement à l'API Conversions. Ne fait rien si l'API est désactivée.
 * N'appeler que pour un visiteur ayant consenti aux cookies publicitaires.
 */
function capi_send(string $eventName, string $eventId, int $eventTime, array $user, array $custom = [], ?string $url = null): bool
{
    if (!cfg('meta_capi_enabled') || !cfg('meta_access_token') || !cfg('meta_pixel_id')) return false;
    $ud = array_filter([
        'em' => isset($user['email']) ? [capi_hash($user['email'])] : null,
        'ph' => isset($user['phone']) ? [capi_phone($user['phone'])] : null,
        'fn' => isset($user['fn']) ? [capi_hash($user['fn'])] : null,
        'ln' => isset($user['ln']) ? [capi_hash($user['ln'])] : null,
        'client_ip_address' => $user['ip'] ?? null,
        'client_user_agent' => $user['ua'] ?? null,
        'fbp' => $user['fbp'] ?? null,
        'fbc' => $user['fbc'] ?? null,
        'country' => [hash('sha256', 'fr')],
    ], fn($v) => $v !== null && $v !== [null]);

    $payload = ['data' => [[
        'event_name'       => $eventName,
        'event_time'       => $eventTime,
        'event_id'         => $eventId,
        'action_source'    => 'website',
        'event_source_url' => $url ?? cfg('site_origin'),
        'user_data'        => $ud,
        'custom_data'      => $custom ?: new stdClass(),
    ]]];
    if (cfg('meta_capi_test_code')) $payload['test_event_code'] = cfg('meta_capi_test_code');

    $endpoint = sprintf('https://graph.facebook.com/%s/%s/events?access_token=%s',
        cfg('meta_api_version'), rawurlencode((string)cfg('meta_pixel_id')), rawurlencode((string)cfg('meta_access_token')));
    try {
        http_json('POST', $endpoint, $payload);
        return true;
    } catch (Throwable $e) {
        error_log('[somnea-admin] CAPI ' . $eventName . ' : ' . $e->getMessage());
        return false;
    }
}

/* ---------- Enregistrement d'une vente (webhook + import) --------------------------------- */

function record_sale(array $session): bool
{
    if (($session['payment_status'] ?? '') !== 'paid') return false;
    $sid = (string)$session['id'];
    $ref = parse_reference($session['client_reference_id'] ?? null);
    $email = $session['customer_details']['email'] ?? $session['customer_email'] ?? null;
    $name  = $session['customer_details']['name'] ?? null;
    $created = date('Y-m-d H:i:s', (int)($session['created'] ?? time()));

    $pdo = db();
    $ins = $pdo->prepare('INSERT OR IGNORE INTO sales(session_id, created_at, amount, currency, email, name, reference, source, spe, consent_ads)
                          VALUES(?,?,?,?,?,?,?,?,?,?)');
    $ins->execute([$sid, $created, (int)($session['amount_total'] ?? 0), $session['currency'] ?? 'eur',
        $email, $name, $session['client_reference_id'] ?? null, $ref['source'], $ref['spe'], $ref['consent'] ? 1 : 0]);
    if ($ins->rowCount() === 0) return false; // déjà enregistrée

    $saleId = (int)$pdo->lastInsertId();

    // Rapprochement avec un lead (même e-mail, 90 jours) → statut « Inscrit »
    if ($email) {
        $s = $pdo->prepare("SELECT id FROM leads WHERE lower(email) = lower(?) AND created_at >= datetime(?, '-90 days') ORDER BY created_at DESC LIMIT 1");
        $s->execute([$email, $created]);
        if ($leadId = $s->fetchColumn()) {
            $pdo->prepare('UPDATE sales SET lead_id = ? WHERE id = ?')->execute([$leadId, $saleId]);
            $pdo->prepare("UPDATE leads SET statut = 'inscrit', updated_at = ? WHERE id = ?")->execute([now_iso(), $leadId]);
        }
    }

    // API Conversions : seulement si consentement publicitaire donné au moment du clic
    if ($ref['consent']) {
        $ok = capi_send('Purchase', $sid, (int)($session['created'] ?? time()),
            ['email' => $email],
            ['value' => round(((int)($session['amount_total'] ?? 0)) / 100, 2), 'currency' => strtoupper($session['currency'] ?? 'eur'),
             'content_name' => 'Formation SAHOS 44h'],
            rtrim((string)cfg('site_origin'), '/') . '/merci');
        if ($ok) $pdo->prepare('UPDATE sales SET capi_sent = 1 WHERE id = ?')->execute([$saleId]);
    }
    return true;
}

/* ---------- Purge RGPD ------------------------------------------------------------------- */

function purge_old_leads(): void
{
    $months = max(1, (int)(cfg('lead_retention_months') ?: 24));
    if (kv_get('last_purge') === date('Y-m-d')) return;
    db()->prepare("DELETE FROM leads WHERE statut <> 'inscrit' AND created_at < datetime('now', ?)")->execute(["-$months months"]);
    kv_set('last_purge', date('Y-m-d'));
}
