<?php
declare(strict_types=1);
/**
 * Réception des demandes de rappel envoyées par la landing (en plus de l'e-mail Web3Forms).
 * Appelé en POST JSON depuis la même origine que le site.
 */
require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function out(int $code, array $data): never
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(405, ['ok' => false]);

// Origine : le site lui-même (ou localhost pour les tests)
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$allowed = rtrim((string)cfg('site_origin'), '/');
$isLocal = (bool)preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin);
if ($origin !== '' && $origin !== $allowed && !$isLocal) out(403, ['ok' => false]);

if (rate_limited('lead', 5, 600)) out(429, ['ok' => false, 'error' => 'Trop de demandes, réessayez plus tard.']);

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) out(400, ['ok' => false]);
if (!empty($in['botcheck'])) out(200, ['ok' => true]); // robot : on ne dit rien

$str = function (string $k, int $max) use ($in): string {
    $v = trim((string)($in[$k] ?? ''));
    $v = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $v) ?? '';
    return mb_substr($v, 0, $max);
};

$lead = [
    'prenom'        => $str('prenom', 80),
    'nom'           => $str('nom', 80),
    'email'         => mb_strtolower($str('email', 160)),
    'telephone'     => $str('telephone', 30),
    'profession'    => $str('profession', 80),
    'mode_exercice' => $str('mode_exercice', 30),
    'message'       => $str('message', 2000),
    'spe'           => array_key_exists($str('spe', 20), SPECIALITES) ? $str('spe', 20) : 'general',
    'source'        => $str('source', 60) ?: 'landing_meta',
    'utm_campaign'  => $str('utm_campaign', 120),
    'utm_content'   => $str('utm_content', 120),
    'consent_ads'   => !empty($in['consent_ads']) ? 1 : 0,
    'event_id'      => preg_match('/^[A-Za-z0-9_-]{1,80}$/', (string)($in['event_id'] ?? '')) ? (string)$in['event_id'] : null,
];

if ($lead['prenom'] === '' || $lead['nom'] === '' || !filter_var($lead['email'], FILTER_VALIDATE_EMAIL)
    || strlen(preg_replace('/\D/', '', $lead['telephone'])) < 9) {
    out(422, ['ok' => false, 'error' => 'Champs invalides']);
}

$pdo = db();
$pdo->prepare('INSERT INTO leads(created_at, prenom, nom, email, telephone, profession, mode_exercice, message, spe, source,
                                 utm_campaign, utm_content, consent_ads, event_id)
               VALUES(:created_at, :prenom, :nom, :email, :telephone, :profession, :mode_exercice, :message, :spe, :source,
                      :utm_campaign, :utm_content, :consent_ads, :event_id)')
    ->execute(['created_at' => now_iso()] + $lead);

// API Conversions (même event_id que le pixel → pas de doublon), seulement avec consentement
if ($lead['consent_ads'] && $lead['event_id']) {
    $fbp = preg_match('/^fb\.\d\.\d+\.\d+$/', (string)($in['fbp'] ?? '')) ? $in['fbp'] : null;
    $fbc = preg_match('/^fb\.\d\.\d+\.[A-Za-z0-9_-]+$/', (string)($in['fbc'] ?? '')) ? $in['fbc'] : null;
    capi_send('Lead', $lead['event_id'], time(), [
        'email' => $lead['email'], 'phone' => $lead['telephone'], 'fn' => $lead['prenom'], 'ln' => $lead['nom'],
        'ip' => client_ip(), 'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400),
        'fbp' => $fbp, 'fbc' => $fbc,
    ], ['content_name' => 'Rappel landing', 'content_category' => $lead['profession']],
        rtrim((string)cfg('site_origin'), '/') . '/formation-apnee-sommeil');
}

purge_old_leads();
out(200, ['ok' => true]);
