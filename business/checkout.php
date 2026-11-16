<?php
/*
 * Creates a Stripe Checkout Session for a monthly subscription (Solo or Team) and sends the
 * buyer to Stripe's hosted payment page.
 */
declare(strict_types=1);
require __DIR__ . '/includes/lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect(site_url());
}

$email   = trim((string) ($_POST['email'] ?? ''));
$company = trim((string) ($_POST['company'] ?? ''));
$planKey = (string) ($_POST['plan'] ?? '');
$plan    = plan_info($planKey);

$back = fn(string $msg) => redirect(site_url('?' . http_build_query(['error' => $msg, 'email' => $email, 'plan' => $planKey])));

if ($plan === null) {
    $back('Please choose a plan.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $back('Please enter a valid work email.');
}
if ($company === '' || mb_strlen($company) > 200) {
    $back('Please enter your company name.');
}
if (($_POST['agree_terms'] ?? '') !== '1') {
    $back('Please confirm that you understand all payments are final.');
}
if (!rate_limit('checkout', 20, 3600)) {
    $back('Too many attempts. Please wait a few minutes and try again.');
}

try {
    $session = stripe_request('POST', 'checkout/sessions', [
        'mode'                       => 'subscription',
        'line_items'                 => [['price' => (string) $plan['stripe_price_id'], 'quantity' => 1]],
        'customer_email'             => $email,
        'allow_promotion_codes'      => 'true',
        'billing_address_collection' => 'auto',
        'metadata'                   => [
            'kind'    => 'audit',
            'plan'    => $planKey,
            'company' => mb_substr($company, 0, 200),
        ],
        'subscription_data'          => ['metadata' => ['kind' => 'audit', 'plan' => $planKey]],
        'success_url' => site_url('success.php') . '?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url'  => site_url('?canceled=1'),
    ]);
} catch (Throwable $e) {
    error_log('[SWA checkout] ' . $e->getMessage());
    $back('We could not start checkout right now. Please try again in a minute.');
}

redirect((string) $session['url']);
