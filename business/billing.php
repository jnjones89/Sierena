<?php
/*
 * Sends a signed-in customer to Stripe's Customer Portal (update card, cancel, invoices).
 * Enable it first: Stripe Dashboard → Settings → Billing → Customer portal.
 */
declare(strict_types=1);
require __DIR__ . '/includes/lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect(site_url('portal.php'));
}
csrf_check();

$license = isset($_SESSION['license_id']) ? find_license_by_id((int) $_SESSION['license_id']) : null;
if (!$license || empty($license['stripe_customer_id'])) {
    redirect(site_url('portal.php'));
}

try {
    $portal = stripe_request('POST', 'billing_portal/sessions', [
        'customer'   => $license['stripe_customer_id'],
        'return_url' => site_url('portal.php'),
    ]);
} catch (Throwable $e) {
    error_log('[SWA billing] ' . $e->getMessage());
    $_SESSION['flash'] = ['msg' => 'Billing portal is unavailable right now. Please try again later.', 'kind' => 'error'];
    redirect(site_url('portal.php'));
}

redirect((string) $portal['url']);
