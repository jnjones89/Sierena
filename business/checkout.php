<?php
/*
 * Creates a Stripe Checkout Session and sends the buyer to Stripe's hosted payment page.
 */
declare(strict_types=1);
require __DIR__ . '/includes/lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect(site_url());
}

$email   = trim((string) ($_POST['email'] ?? ''));
$company = trim((string) ($_POST['company'] ?? ''));

$back = fn(string $msg) => redirect(site_url('?' . http_build_query(['error' => $msg, 'email' => $email])));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $back('Please enter a valid work email.');
}
if ($company === '' || mb_strlen($company) > 200) {
    $back('Please enter your company name.');
}
if (!rate_limit('checkout', 20, 3600)) {
    $back('Too many attempts. Please wait a few minutes and try again.');
}

$mode = cfg('stripe_mode') === 'payment' ? 'payment' : 'subscription';
$metadata = [
    'company' => mb_substr($company, 0, 200),
    'product' => 'workflow_shadow_audit_business',
];

$params = [
    'mode'                  => $mode,
    'line_items'            => [['price' => (string) cfg('stripe_price_id'), 'quantity' => 1]],
    'customer_email'        => $email,
    'allow_promotion_codes' => 'true',
    'billing_address_collection' => 'auto',
    'metadata'              => $metadata,
    'success_url'           => site_url('success.php') . '?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url'            => site_url('?canceled=1'),
];
if ($mode === 'subscription') {
    $params['subscription_data'] = ['metadata' => $metadata];
} else {
    $params['customer_creation'] = 'always';
}

try {
    $session = stripe_request('POST', 'checkout/sessions', $params);
} catch (Throwable $e) {
    error_log('[SWA checkout] ' . $e->getMessage());
    $back('We could not start checkout right now. Please try again in a minute.');
}

redirect((string) $session['url']);
