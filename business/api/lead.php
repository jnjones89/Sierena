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
$industry   = isset(industries()[(string) ($body['industry'] ?? '')]) ? (string) $body['industry'] : '';
$bottleneck = mb_substr(trim((string) ($body['bottleneck'] ?? '')), 0, 80);
$agent      = mb_substr(trim((string) ($body['agent'] ?? '')), 0, 80);
$agentDoes  = mb_substr(trim((string) ($body['agentDoes'] ?? '')), 0, 300);
$task       = mb_substr(trim((string) ($body['task'] ?? '')), 0, 300);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_out(['ok' => false, 'error' => 'Invalid email'], 422);
}

$exists = db()->prepare('SELECT 1 FROM leads WHERE email = ?');
$exists->execute([$email]);
if (!$exists->fetchColumn()) {
    try {
        db()->prepare('INSERT INTO leads (email, role_tag, source, industry, bottleneck, agent, task, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$email, $role, 'extension', $industry, $bottleneck, $agent, $task, now_iso()]);
    } catch (PDOException $e) {
        // Duplicate from a concurrent request — fine.
    }
}

ghl_event('free_audit', ['email' => $email], [
    'swa_role'              => $role,
    'swa_industry'          => $industry ? industry_label($industry) : null,
    'swa_bottleneck'        => $bottleneck,
    'swa_repeated_task'     => $task,
    'swa_recommended_agent' => $agent,
    'swa_agent_does'        => $agentDoes,
], ghl_industry_tag($industry));

json_out(['ok' => true, 'upgradeUrl' => site_url('?email=' . rawurlencode($email))]);
