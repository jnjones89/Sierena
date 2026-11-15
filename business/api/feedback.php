<?php
/*
 * POST survey answers from the extension.
 *   Free user:   {"context":"free","email":"...","accurate":"spot_on","nps":9,"wantsHelp":true,"comment":"...","industry":"insurance","agent":"..."}
 *   Team member: {"context":"team_member","key":"AUDIT-...","deviceId":"...", ...same answers}
 */
declare(strict_types=1);
require dirname(__DIR__) . '/includes/lib.php';

api_cors();

if (!rate_limit('feedback', 10, 3600)) {
    json_out(['ok' => false, 'error' => 'Too many requests'], 429);
}

$body    = read_json_body(10_000);
$context = (string) ($body['context'] ?? '');
$email   = strtolower(trim((string) ($body['email'] ?? '')));
$license = null;

if ($context === 'team_member') {
    $license = find_license_by_key((string) ($body['key'] ?? ''));
    if (!$license || !valid_device_id($body['deviceId'] ?? null)) {
        json_out(['ok' => false, 'error' => 'Unknown team key'], 403);
    }
} elseif ($context === 'free') {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_out(['ok' => false, 'error' => 'Invalid email'], 422);
    }
} else {
    json_out(['ok' => false, 'error' => 'Unknown context'], 422);
}

$wantsHelp = !empty($body['wantsHelp']);
save_feedback([
    'context'    => $context,
    'email'      => $context === 'free' ? $email : '',
    'license_id' => $license ? (int) $license['id'] : 0,
    'accurate'   => (string) ($body['accurate'] ?? ''),
    'nps'        => $body['nps'] ?? null,
    'wants_help' => $wantsHelp,
    'comment'    => (string) ($body['comment'] ?? ''),
    'industry'   => (string) ($body['industry'] ?? ''),
    'agent'      => (string) ($body['agent'] ?? ''),
]);

if ($context === 'free') {
    ghl_event($wantsHelp ? 'wants_help' : 'profile_updated', ['email' => $email], [
        'swa_nps'      => isset($body['nps']) && $body['nps'] !== '' ? (int) $body['nps'] : null,
        'swa_accuracy' => ACCURACY_ANSWERS[(string) ($body['accurate'] ?? '')] ?? null,
    ]);
}
if ($wantsHelp) {
    $who = $context === 'free' ? $email : ('a team member at ' . ($license['company'] ?: $license['email']));
    send_mail((string) cfg('owner_email'), 'Wants help: ' . $who,
        "Someone asked for help fixing what their audit found.\n\nWho: $who\nAgent suggested: " . ($body['agent'] ?? '') .
        "\nComment: " . ($body['comment'] ?? '') . "\n\nFollow up today.\n");
}

json_out(['ok' => true]);
