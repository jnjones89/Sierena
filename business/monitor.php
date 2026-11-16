<?php
/*
 * Restart a canceled monthly subscription for a signed-in Business customer (same plan, same key).
 *   POST  → starts Stripe Checkout for the plan's monthly price
 *   GET ?session_id=… → Stripe's return URL; confirms payment and turns access back on
 */
declare(strict_types=1);
require __DIR__ . '/includes/lib.php';

start_session();

function back_to_portal(string $msg, string $kind = 'ok'): never
{
    $_SESSION['flash'] = ['msg' => $msg, 'kind' => $kind];
    redirect(site_url('portal.php'));
}

$license = isset($_SESSION['license_id']) ? find_license_by_id((int) $_SESSION['license_id']) : null;
if (!$license) {
    redirect(site_url('portal.php'));
}

// Return from Stripe.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $sessionId = (string) ($_GET['session_id'] ?? '');
    if (!preg_match('/^cs_[A-Za-z0-9_]{10,255}$/', $sessionId)) {
        redirect(site_url('portal.php'));
    }
    try {
        $session = stripe_request('GET', 'checkout/sessions/' . rawurlencode($sessionId));
        if ((int) ($session['metadata']['license_id'] ?? 0) !== (int) $license['id']) {
            back_to_portal('That checkout belongs to a different account.', 'error');
        }
        $updated = fulfill_checkout_session($session);
    } catch (Throwable $e) {
        error_log('[SWA monitor] ' . $e->getMessage());
        back_to_portal('We could not confirm the payment yet. Refresh in a minute.', 'error');
    }
    back_to_portal($updated ? 'Your subscription is back on. Welcome back!' : 'Payment is still processing.');
}

// Start checkout.
csrf_check();
if (license_monitoring($license)) {
    back_to_portal('Your subscription is already on.');
}
if (!rate_limit('monitor_checkout', 10, 3600)) {
    back_to_portal('Too many attempts. Try again later.', 'error');
}

$params = [
    'mode'       => 'subscription',
    'line_items' => [['price' => (string) monitoring_tier($license)['stripe_price_id'], 'quantity' => 1]],
    'metadata'   => ['kind' => 'monitoring', 'license_id' => (string) $license['id']],
    'subscription_data' => ['metadata' => ['kind' => 'monitoring', 'license_id' => (string) $license['id']]],
    'success_url' => site_url('monitor.php') . '?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url'  => site_url('portal.php'),
];
if (!empty($license['stripe_customer_id'])) {
    $params['customer'] = $license['stripe_customer_id'];
} else {
    $params['customer_email'] = $license['email'];
}

try {
    $session = stripe_request('POST', 'checkout/sessions', $params);
} catch (Throwable $e) {
    error_log('[SWA monitor] ' . $e->getMessage());
    back_to_portal('Could not start checkout. Please try again in a minute.', 'error');
}
redirect((string) $session['url']);
