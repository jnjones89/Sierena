<?php
/*
 * POST {"key": "AUDIT-...", "deviceId": "...", "roleTag": "..."} → {"ok":true,"valid":bool,...}
 * Called by the Chrome extension to verify a team key and claim a seat for this browser.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/includes/lib.php';

api_cors();

if (!rate_limit('validate', 30, 600)) {
    json_out(['ok' => false, 'error' => 'Too many attempts. Try again in a few minutes.'], 429);
}

$body     = read_json_body(10_000);
$license  = find_license_by_key((string) ($body['key'] ?? ''));
$deviceId = $body['deviceId'] ?? null;

if (!license_is_active($license)) {
    json_out(['ok' => true, 'valid' => false, 'reason' => license_inactive_reason($license)]);
}
if (!valid_device_id($deviceId)) {
    json_out(['ok' => true, 'valid' => false, 'reason' => 'Please update the Workflow Shadow Audit extension.']);
}
if ($reason = claim_seat($license, $deviceId, (string) ($body['roleTag'] ?? ''))) {
    json_out(['ok' => true, 'valid' => false, 'reason' => $reason]);
}

json_out([
    'ok'          => true,
    'valid'       => true,
    'tier'        => 'business',
    'plan'        => plan_name($license),
    'company'     => $license['company'],
    'seats'       => (int) $license['seats'],
    'seatsUsed'   => seats_used((int) $license['id']),
    'accessUntil' => license_monitoring($license) ? null : $license['access_until'],
    'monitoring'  => license_monitoring($license),
    'solo'        => license_is_solo($license),
    'portalUrl'   => site_url('portal.php'),
]);
