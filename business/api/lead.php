<?php
/*
 * POST {"email": "...", "roleTag": "..."} — records a free audit lead from the extension.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/includes/lib.php';

api_cors();

if (!rate_limit('lead', 10, 3600)) {
    json_out(['ok' => false, 'error' => 'Too many requests'], 429);
}

$body  = read_json_body(10_000);
$email = strtolower(trim((string) ($body['email'] ?? '')));
$role  = mb_substr(trim((string) ($body['roleTag'] ?? '')), 0, 80);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_out(['ok' => false, 'error' => 'Invalid email'], 422);
}

$exists = db()->prepare('SELECT 1 FROM leads WHERE email = ?');
$exists->execute([$email]);
if (!$exists->fetchColumn()) {
    try {
        db()->prepare('INSERT INTO leads (email, role_tag, source, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$email, $role, 'extension', now_iso()]);
    } catch (PDOException $e) {
        // Duplicate from a concurrent request — fine.
    }
}

json_out(['ok' => true, 'upgradeUrl' => site_url('?email=' . rawurlencode($email))]);
