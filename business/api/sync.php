<?php
/*
 * POST {"key": "AUDIT-...", "payload": {auditMetadata, softwareUsage, repeatedWorkflowLoops}}
 * Stores one extension export in the company's Business Portal.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/includes/lib.php';

api_cors();

if (!rate_limit('sync', 60, 3600)) {
    json_out(['ok' => false, 'error' => 'Too many syncs this hour. Try again later.'], 429);
}

$body    = read_json_body(2_000_000);
$license = find_license_by_key((string) ($body['key'] ?? ''));

if (!license_is_active($license)) {
    json_out(['ok' => false, 'error' => 'License key is not active.'], 403);
}

$clean = sanitize_audit_payload($body['payload'] ?? null);
if ($clean === null) {
    json_out(['ok' => false, 'error' => 'Audit data is not in the expected format.'], 422);
}

$auditId = store_audit((int) $license['id'], $clean);

json_out([
    'ok'        => true,
    'auditId'   => $auditId,
    'portalUrl' => site_url('portal.php'),
]);
