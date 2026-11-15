<?php
/*
 * Stripe webhook endpoint.
 * Add in Stripe Dashboard → Developers → Webhooks → Add endpoint:
 *   URL:    https://YOUR-DOMAIN/business/webhook.php
 *   Events: checkout.session.completed, checkout.session.async_payment_succeeded,
 *           customer.subscription.updated, customer.subscription.deleted
 * One-time Team Audits and the monthly Results Tracking plan both arrive here.
 */
declare(strict_types=1);
require __DIR__ . '/includes/lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['error' => 'POST required'], 405);
}

$payload   = (string) file_get_contents('php://input');
$signature = (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');

if (!stripe_verify_signature($payload, $signature, (string) cfg('stripe_webhook_secret'))) {
    json_out(['error' => 'Invalid signature'], 400);
}

$event = json_decode($payload, true);
if (!is_array($event) || !isset($event['type'], $event['data']['object'])) {
    json_out(['error' => 'Invalid payload'], 400);
}
$object = $event['data']['object'];

try {
    switch ($event['type']) {
        case 'checkout.session.completed':
        case 'checkout.session.async_payment_succeeded':
            fulfill_checkout_session($object);
            break;

        case 'customer.subscription.updated':
        case 'customer.subscription.deleted':
            sync_subscription_status($object);
            break;

        default:
            // Ignore everything else.
            break;
    }
} catch (Throwable $e) {
    error_log('[SWA webhook] ' . $e->getMessage());
    json_out(['error' => 'Processing failed'], 500); // Stripe will retry
}

json_out(['received' => true]);
