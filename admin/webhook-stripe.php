<?php
declare(strict_types=1);
/**
 * Webhook Stripe → enregistre chaque vente payée.
 * Stripe → Développeurs → Webhooks → endpoint https://formationsahos.fr/admin/webhook-stripe.php
 * Évènements : checkout.session.completed (+ checkout.session.async_payment_succeeded pour Klarna/virement)
 */
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$payload = (string)file_get_contents('php://input');
$sigHeader = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
$secret = (string)cfg('stripe_webhook_secret');

if ($secret === '' || !stripe_signature_ok($payload, $sigHeader, $secret)) {
    http_response_code(400);
    exit('Signature invalide');
}

$event = json_decode($payload, true);
$type = $event['type'] ?? '';

try {
    if (in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
        record_sale($event['data']['object'] ?? []);
    }
    http_response_code(200);
    echo 'ok';
} catch (Throwable $e) {
    error_log('[somnea-admin] webhook : ' . $e->getMessage());
    http_response_code(500); // Stripe réessaiera automatiquement
}

/** Vérification de signature Stripe (schéma v1, tolérance 5 min), sans SDK. */
function stripe_signature_ok(string $payload, string $header, string $secret, int $tolerance = 300): bool
{
    $ts = null;
    $sigs = [];
    foreach (explode(',', $header) as $part) {
        [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($k === 't') $ts = (int)$v;
        if ($k === 'v1') $sigs[] = $v;
    }
    if (!$ts || !$sigs || abs(time() - $ts) > $tolerance) return false;
    $expected = hash_hmac('sha256', $ts . '.' . $payload, $secret);
    foreach ($sigs as $s) {
        if (hash_equals($expected, $s)) return true;
    }
    return false;
}
