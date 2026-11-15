<?php
/*
 * POST {"key": "AUDIT-XXXX-..."} → {"ok":true,"valid":bool,...}
 * Called by the Chrome extension to verify a Business license key.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/includes/lib.php';

api_cors();

if (!rate_limit('validate', 30, 600)) {
    json_out(['ok' => false, 'error' => 'Too many attempts. Try again in a few minutes.'], 429);
}

$body    = read_json_body(10_000);
$license = find_license_by_key((string) ($body['key'] ?? ''));

if (!license_is_active($license)) {
    json_out([
        'ok'     => true,
        'valid'  => false,
        'reason' => $license ? 'This license is no longer active. Renew your subscription to restore access.' : 'License key not found.',
    ]);
}

json_out([
    'ok'        => true,
    'valid'     => true,
    'tier'      => 'business',
    'company'   => $license['company'],
    'portalUrl' => site_url('portal.php'),
]);
