<?php
/*
 * Workflow Shadow Audit — Business server shared library.
 * Plain PHP 8 + PDO + cURL. No Composer packages required (works on Hostinger shared hosting).
 */

declare(strict_types=1);

require_once __DIR__ . '/industries.php';
require_once __DIR__ . '/tools.php';
require_once __DIR__ . '/chains.php';

/* ------------------------------------------------------------------ */
/* Configuration                                                       */
/* ------------------------------------------------------------------ */

function cfg(string $key)
{
    static $config = null;
    if ($config === null) {
        $candidates = array_filter([
            getenv('SWA_CONFIG') ?: null,
            dirname(__DIR__, 3) . '/swa-config.php', // one level above public_html
            __DIR__ . '/config.php',
        ]);
        foreach ($candidates as $file) {
            if (is_file($file)) {
                $config = require $file;
                break;
            }
        }
        if (!is_array($config)) {
            http_response_code(500);
            exit('Server not configured: copy includes/config.sample.php to includes/config.php and fill it in.');
        }
    }
    if (!array_key_exists($key, $config)) {
        throw new RuntimeException("Missing config key: $key");
    }
    return $config[$key];
}

/** Optional config value (for settings added after launch, so older config files keep working). */
function cfg_opt(string $key, $default = null)
{
    try {
        return cfg($key);
    } catch (RuntimeException $e) {
        return $default;
    }
}

function site_url(string $path = ''): string
{
    return rtrim((string) cfg('site_url'), '/') . ($path === '' ? '' : '/' . ltrim($path, '/'));
}

function now_iso(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

/* ------------------------------------------------------------------ */
/* Database                                                            */
/* ------------------------------------------------------------------ */

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO((string) cfg('db_dsn'), (string) cfg('db_user'), (string) cfg('db_pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        ensure_schema($pdo);
    }
    return $pdo;
}

function ensure_schema(PDO $pdo): void
{
    try {
        $pdo->query('SELECT monitor_status FROM licenses LIMIT 1');
        $pdo->query('SELECT 1 FROM reports LIMIT 1');
        $pdo->query('SELECT device_id FROM audits LIMIT 1');
        $pdo->query('SELECT 1 FROM ghl_log LIMIT 1');
        $pdo->query('SELECT 1 FROM feedback LIMIT 1');
        $pdo->query('SELECT agent FROM leads LIMIT 1');
        return; // schema is current
    } catch (PDOException $e) {
        // create / upgrade below
    }

    $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $id      = $isMysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $suffix  = $isMysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $text    = $isMysql ? 'LONGTEXT' : 'TEXT';

    $pdo->exec("CREATE TABLE IF NOT EXISTS licenses (
        id $id,
        license_key VARCHAR(40) NOT NULL UNIQUE,
        email VARCHAR(255) NOT NULL,
        company VARCHAR(255) NOT NULL DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        stripe_session_id VARCHAR(255) NOT NULL UNIQUE,
        stripe_customer_id VARCHAR(255) NULL,
        stripe_subscription_id VARCHAR(255) NULL,
        plan VARCHAR(20) NOT NULL DEFAULT 'starter',
        seats INT NOT NULL DEFAULT 5,
        access_until VARCHAR(32) NULL,
        monitor_status VARCHAR(20) NOT NULL DEFAULT 'none',
        monitor_subscription_id VARCHAR(255) NULL,
        created_at VARCHAR(32) NOT NULL,
        updated_at VARCHAR(32) NOT NULL
    )$suffix");

    // Upgrade a licenses table created by an earlier version.
    $newColumns = [
        'plan'                    => "VARCHAR(20) NOT NULL DEFAULT 'starter'",
        'seats'                   => 'INT NOT NULL DEFAULT 5',
        'access_until'            => 'VARCHAR(32) NULL',
        'monitor_status'          => "VARCHAR(20) NOT NULL DEFAULT 'none'",
        'monitor_subscription_id' => 'VARCHAR(255) NULL',
    ];
    foreach ($newColumns as $column => $definition) {
        try {
            $pdo->query("SELECT $column FROM licenses LIMIT 1");
        } catch (PDOException $e) {
            $pdo->exec("ALTER TABLE licenses ADD COLUMN $column $definition");
        }
    }

    try {
        $pdo->query('SELECT device_id FROM audits LIMIT 1');
    } catch (PDOException $e) {
        try {
            $pdo->query('SELECT 1 FROM audits LIMIT 1');
            $pdo->exec("ALTER TABLE audits ADD COLUMN device_id VARCHAR(64) NOT NULL DEFAULT ''");
        } catch (PDOException $e2) {
            // audits table is created below with the column
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS devices (
        id $id,
        license_id INT NOT NULL,
        device_id VARCHAR(64) NOT NULL,
        role_tag VARCHAR(80) NOT NULL DEFAULT '',
        first_seen VARCHAR(32) NOT NULL,
        last_seen VARCHAR(32) NOT NULL,
        UNIQUE (license_id, device_id)
    )$suffix");

    $pdo->exec("CREATE TABLE IF NOT EXISTS reports (
        id $id,
        license_id INT NOT NULL,
        kind VARCHAR(10) NOT NULL,
        content $text NOT NULL,
        model VARCHAR(64) NOT NULL DEFAULT '',
        created_at VARCHAR(32) NOT NULL
    )$suffix");

    $pdo->exec("CREATE TABLE IF NOT EXISTS profiles (
        license_id INT NOT NULL PRIMARY KEY,
        data $text NOT NULL,
        updated_at VARCHAR(32) NOT NULL
    )$suffix");

    $pdo->exec("CREATE TABLE IF NOT EXISTS audits (
        id $id,
        license_id INT NOT NULL,
        role_tag VARCHAR(80) NOT NULL DEFAULT '',
        device_id VARCHAR(64) NOT NULL DEFAULT '',
        total_minutes DOUBLE NOT NULL DEFAULT 0,
        payload $text NOT NULL,
        created_at VARCHAR(32) NOT NULL
    )$suffix");

    $pdo->exec("CREATE TABLE IF NOT EXISTS leads (
        id $id,
        email VARCHAR(255) NOT NULL UNIQUE,
        role_tag VARCHAR(80) NOT NULL DEFAULT '',
        source VARCHAR(40) NOT NULL DEFAULT '',
        industry VARCHAR(40) NOT NULL DEFAULT '',
        bottleneck VARCHAR(80) NOT NULL DEFAULT '',
        agent VARCHAR(80) NOT NULL DEFAULT '',
        task VARCHAR(300) NOT NULL DEFAULT '',
        created_at VARCHAR(32) NOT NULL
    )$suffix");

    foreach (['industry' => 'VARCHAR(40)', 'bottleneck' => 'VARCHAR(80)', 'agent' => 'VARCHAR(80)', 'task' => 'VARCHAR(300)'] as $col => $type) {
        try {
            $pdo->query("SELECT $col FROM leads LIMIT 1");
        } catch (PDOException $e) {
            try {
                $pdo->exec("ALTER TABLE leads ADD COLUMN $col $type NOT NULL DEFAULT ''");
            } catch (PDOException $e2) {
                // leads table is created below with the columns
            }
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS feedback (
        id $id,
        context VARCHAR(20) NOT NULL,
        email VARCHAR(255) NOT NULL DEFAULT '',
        license_id INT NOT NULL DEFAULT 0,
        accurate VARCHAR(20) NOT NULL DEFAULT '',
        nps INT NULL,
        wants_help INT NOT NULL DEFAULT 0,
        comment VARCHAR(2000) NOT NULL DEFAULT '',
        industry VARCHAR(40) NOT NULL DEFAULT '',
        agent VARCHAR(80) NOT NULL DEFAULT '',
        created_at VARCHAR(32) NOT NULL
    )$suffix");

    $pdo->exec("CREATE TABLE IF NOT EXISTS issues (
        id $id,
        kind VARCHAR(40) NOT NULL,
        detail VARCHAR(1000) NOT NULL,
        created_at VARCHAR(32) NOT NULL
    )$suffix");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ghl_log (
        id $id,
        destination VARCHAR(40) NOT NULL,
        event VARCHAR(60) NOT NULL,
        email VARCHAR(255) NOT NULL,
        ok INT NOT NULL DEFAULT 0,
        detail VARCHAR(500) NOT NULL DEFAULT '',
        created_at VARCHAR(32) NOT NULL
    )$suffix");

    $pdo->exec("CREATE TABLE IF NOT EXISTS kv (
        k VARCHAR(100) NOT NULL PRIMARY KEY,
        v $text NOT NULL,
        updated_at INT NOT NULL
    )$suffix");

    $pdo->exec("CREATE TABLE IF NOT EXISTS rate_hits (
        id $id,
        ip VARCHAR(64) NOT NULL,
        bucket VARCHAR(40) NOT NULL,
        ts INT NOT NULL
    )$suffix");
}

/* ------------------------------------------------------------------ */
/* HTTP helpers                                                        */
/* ------------------------------------------------------------------ */

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function client_ip(): string
{
    // Hostinger sits behind its own proxy/CDN; REMOTE_ADDR is the safe default.
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return substr($ip, 0, 64);
}

function redirect(string $url, int $code = 303): never
{
    header('Location: ' . $url, true, $code);
    exit;
}

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

/** CORS for the Chrome extension's popup (chrome-extension:// origin). */
function api_cors(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_out(['ok' => false, 'error' => 'POST required'], 405);
    }
}

function read_json_body(int $maxBytes = 2_000_000): array
{
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false || strlen($raw) > $maxBytes) {
        json_out(['ok' => false, 'error' => 'Request too large'], 413);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_out(['ok' => false, 'error' => 'Invalid JSON'], 400);
    }
    return $data;
}

/** Returns false when the caller exceeded $max hits in $windowSec for this bucket. */
function rate_limit(string $bucket, int $max, int $windowSec): bool
{
    $pdo = db();
    $now = time();
    $ip  = client_ip();
    if (random_int(1, 50) === 1) {
        $pdo->prepare('DELETE FROM rate_hits WHERE ts < ?')->execute([$now - 86400]);
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM rate_hits WHERE ip = ? AND bucket = ? AND ts > ?');
    $stmt->execute([$ip, $bucket, $now - $windowSec]);
    if ((int) $stmt->fetchColumn() >= $max) {
        return false;
    }
    $pdo->prepare('INSERT INTO rate_hits (ip, bucket, ts) VALUES (?, ?, ?)')->execute([$ip, $bucket, $now]);
    return true;
}

/* ------------------------------------------------------------------ */
/* Sessions & CSRF                                                     */
/* ------------------------------------------------------------------ */

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || str_starts_with((string) cfg('site_url'), 'https://');
    session_name('swa_sess');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => parse_url(site_url(), PHP_URL_PATH) ?: '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): void
{
    start_session();
    $sent = (string) ($_POST['csrf'] ?? '');
    if ($sent === '' || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        http_response_code(400);
        exit('Your session expired. Please go back and try again.');
    }
}

/* ------------------------------------------------------------------ */
/* Email                                                               */
/* ------------------------------------------------------------------ */

function send_mail(string $to, string $subject, string $body): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $from    = (string) cfg('mail_from');
    $brand   = preg_replace('/[^\w .-]/u', '', (string) cfg('brand_name'));
    $subject = '=?UTF-8?B?' . base64_encode(str_replace(["\r", "\n"], ' ', $subject)) . '?=';
    $headers = [
        'From: ' . $brand . ' <' . $from . '>',
        'Reply-To: ' . (string) cfg('owner_email'),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ];
    if (getenv('SWA_DISABLE_MAIL')) {
        return true;
    }
    return @mail($to, $subject, $body, implode("\r\n", $headers), '-f' . $from);
}

/* ------------------------------------------------------------------ */
/* Stripe (REST via cURL — no SDK needed)                              */
/* ------------------------------------------------------------------ */

function stripe_request(string $method, string $path, array $params = []): array
{
    $url = 'https://api.stripe.com/v1/' . ltrim($path, '/');
    $ch  = curl_init();
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => (string) cfg('stripe_secret_key') . ':',
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Stripe-Version: 2024-06-20'],
    ];
    if ($method === 'GET') {
        if ($params) {
            $url .= '?' . http_build_query($params);
        }
    } else {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    }
    $opts[CURLOPT_URL] = $url;
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('Could not reach Stripe: ' . $err);
    }
    $data = json_decode((string) $body, true);
    if (!is_array($data)) {
        throw new RuntimeException('Unexpected response from Stripe (HTTP ' . $code . ')');
    }
    if ($code >= 400) {
        $msg = $data['error']['message'] ?? ('HTTP ' . $code);
        throw new RuntimeException('Stripe error: ' . $msg);
    }
    return $data;
}

function stripe_verify_signature(string $payload, string $header, string $secret, int $tolerance = 300): bool
{
    $timestamp = 0;
    $signatures = [];
    foreach (explode(',', $header) as $part) {
        [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($k === 't') {
            $timestamp = (int) $v;
        } elseif ($k === 'v1') {
            $signatures[] = $v;
        }
    }
    if ($timestamp === 0 || !$signatures || abs(time() - $timestamp) > $tolerance) {
        return false;
    }
    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) {
            return true;
        }
    }
    return false;
}

/* ------------------------------------------------------------------ */
/* Licenses                                                            */
/* ------------------------------------------------------------------ */

function generate_license_key(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // 32 chars, no 0/O/1/I
    $bytes = random_bytes(16);
    $chars = '';
    for ($i = 0; $i < 16; $i++) {
        $chars .= $alphabet[ord($bytes[$i]) & 31];
    }
    return 'AUDIT-' . implode('-', str_split($chars, 4));
}

function normalize_key(string $key): string
{
    return strtoupper(trim($key));
}

function find_license_by_key(string $key): ?array
{
    $stmt = db()->prepare('SELECT * FROM licenses WHERE license_key = ?');
    $stmt->execute([normalize_key($key)]);
    return $stmt->fetch() ?: null;
}

function find_license_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM licenses WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function plan_info(string $plan): ?array
{
    $plans = (array) cfg('plans');
    return isset($plans[$plan]) ? $plans[$plan] : null;
}

function plan_name(array $license): string
{
    $info = plan_info((string) $license['plan']);
    return $info ? (string) $info['name'] : 'Team Audit';
}

function license_is_solo(array $license): bool
{
    $info = plan_info((string) $license['plan']);
    return (bool) ($info['solo'] ?? false) || (int) $license['seats'] === 1;
}

/** Does this plan include a final plan written by our team? */
function plan_includes_final_plan(array $license): bool
{
    $info = plan_info((string) $license['plan']);
    return (bool) ($info['final_plan'] ?? true);
}

function guarantee_text(): string
{
    return (string) cfg_opt('guarantee', 'Not happy with your audit? Ask for your money back within 30 days. No questions asked.');
}

function license_monitoring(array $license): bool
{
    return $license['monitor_status'] === 'active';
}

function license_is_active(?array $license): bool
{
    if ($license === null || $license['status'] !== 'active') {
        return false;
    }
    if (license_monitoring($license)) {
        return true;
    }
    return !empty($license['access_until']) && $license['access_until'] > now_iso();
}

function license_inactive_reason(?array $license): string
{
    if ($license === null) {
        return 'Team key not found. Check it and try again.';
    }
    if ($license['status'] !== 'active') {
        return 'This license has been turned off. Contact us for help.';
    }
    return 'Your audit window has ended. Turn on Results Tracking in your Business Portal to keep going.';
}

function format_date(?string $iso): string
{
    return $iso ? date('M j, Y', strtotime($iso)) : '';
}

/**
 * Fulfill a paid Checkout Session (a Team Audit purchase or a Results Tracking
 * subscription). Idempotent — safe to call from both the success page and the webhook.
 */
function fulfill_checkout_session(array $session): ?array
{
    $paid = ($session['status'] ?? '') === 'complete'
        && in_array($session['payment_status'] ?? '', ['paid', 'no_payment_required'], true);
    if (!$paid || empty($session['id'])) {
        return null;
    }
    if (($session['metadata']['kind'] ?? '') === 'monitoring') {
        return fulfill_monitoring_session($session);
    }

    $pdo  = db();
    $find = $pdo->prepare('SELECT * FROM licenses WHERE stripe_session_id = ?');
    $find->execute([$session['id']]);
    if ($existing = $find->fetch()) {
        return $existing;
    }

    $planKey = (string) ($session['metadata']['plan'] ?? '');
    $plan    = plan_info($planKey);
    if ($plan === null) {
        throw new RuntimeException('Checkout session has an unknown plan: ' . $planKey);
    }

    $email       = (string) ($session['customer_details']['email'] ?? $session['customer_email'] ?? '');
    $company     = mb_substr((string) ($session['metadata']['company'] ?? ''), 0, 255);
    $now         = now_iso();
    $accessUntil = gmdate('Y-m-d\TH:i:s\Z', time() + 86400 * (int) cfg('audit_access_days'));

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $key = generate_license_key();
        try {
            $pdo->prepare('INSERT INTO licenses
                (license_key, email, company, status, stripe_session_id, stripe_customer_id, plan, seats, access_until, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $key,
                    strtolower($email),
                    $company,
                    'active',
                    $session['id'],
                    is_string($session['customer'] ?? null) ? $session['customer'] : null,
                    $planKey,
                    (int) $plan['people'],
                    $accessUntil,
                    $now,
                    $now,
                ]);
            break;
        } catch (PDOException $e) {
            // Another request may have fulfilled this session at the same moment.
            $find->execute([$session['id']]);
            if ($existing = $find->fetch()) {
                return $existing;
            }
            if ($attempt === 4) {
                throw $e;
            }
        }
    }

    $find->execute([$session['id']]);
    $license = $find->fetch() ?: null;
    if ($license) {
        notify_new_license($license);
    }
    return $license;
}

function fulfill_monitoring_session(array $session): ?array
{
    $license = find_license_by_id((int) ($session['metadata']['license_id'] ?? 0));
    $subscription = is_string($session['subscription'] ?? null) ? $session['subscription'] : null;
    if (!$license || !$subscription) {
        return null;
    }
    if ($license['monitor_subscription_id'] === $subscription && license_monitoring($license)) {
        return $license; // already done
    }
    db()->prepare('UPDATE licenses SET monitor_status = ?, monitor_subscription_id = ?,
            stripe_customer_id = COALESCE(stripe_customer_id, ?), updated_at = ? WHERE id = ?')
        ->execute([
            'active',
            $subscription,
            is_string($session['customer'] ?? null) ? $session['customer'] : null,
            now_iso(),
            (int) $license['id'],
        ]);
    ghl_event('results_tracking_on', ghl_license_contact($license), ghl_license_fields($license));
    send_mail(
        (string) cfg('owner_email'),
        'Results Tracking started: ' . ($license['company'] ?: $license['email']),
        "{$license['company']} ({$license['email']}) turned on monthly Results Tracking.\n\nAdmin: "
        . site_url('admin.php?view=' . (int) $license['id']) . "\n"
    );
    return find_license_by_id((int) $license['id']);
}

function notify_new_license(array $license): void
{
    $brand = (string) cfg('brand_name');
    $plan  = plan_name($license);
    $until = format_date($license['access_until']);
    send_mail(
        $license['email'],
        "Your $brand $plan key",
        "Thanks for purchasing the $plan.\n\n"
        . "Your team key:\n\n    {$license['license_key']}\n\n"
        . ((int) $license['seats'] === 1 ? "It works on one computer until $until.\n\n" : "It works for up to {$license['seats']} people until $until.\n\n")
        . "1. Each person installs the Workflow Shadow Audit extension, clicks \"Have a team key?\", pastes this key and turns tracking on.\n"
        . "2. Everyone works normally for a few days, then clicks \"Send to my Business Portal\".\n"
        . "3. Sign in to your Business Portal with this email and key. Fill in your business profile and the Back-Office Check,\n"
        . "   then get your instant AI report. Our team follows up with your final plan:\n    "
        . site_url('portal.php') . "\n\n"
        . "Keep this key private — anyone with it can see your company's portal.\n\n— $brand\n"
    );
    send_mail(
        (string) cfg('owner_email'),
        "New $plan: " . ($license['company'] ?: $license['email']),
        "New purchase.\n\nPlan: $plan ({$license['seats']} people)\nCompany: {$license['company']}\n"
        . "Email: {$license['email']}\nAccess until: $until\n\nAdmin: " . site_url('admin.php?view=' . (int) $license['id']) . "\n"
    );
    ghl_event('team_audit_purchased', ['email' => $license['email'], 'companyName' => $license['company']],
        ghl_license_fields($license), ['swa-plan-' . $license['plan']]);
}

/** Keep Results Tracking in sync with its Stripe subscription. */
function sync_subscription_status(array $subscription): void
{
    if (empty($subscription['id'])) {
        return;
    }
    $active = in_array($subscription['status'] ?? '', ['active', 'trialing', 'past_due'], true);
    $find = db()->prepare('SELECT * FROM licenses WHERE monitor_subscription_id = ?');
    $find->execute([$subscription['id']]);
    $before = $find->fetch() ?: null;
    db()->prepare('UPDATE licenses SET monitor_status = ?, updated_at = ? WHERE monitor_subscription_id = ?')
        ->execute([$active ? 'active' : 'canceled', now_iso(), $subscription['id']]);
    if ($before && !$active && $before['monitor_status'] === 'active') {
        ghl_event('results_tracking_canceled', ghl_license_contact($before), ghl_license_fields($before));
    }
}

/* ------------------------------------------------------------------ */
/* Seats (one entry per teammate's browser)                            */
/* ------------------------------------------------------------------ */

function valid_device_id($id): bool
{
    return is_string($id) && (bool) preg_match('/^[A-Za-z0-9-]{8,64}$/', $id);
}

function seats_used(int $licenseId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM devices WHERE license_id = ?');
    $stmt->execute([$licenseId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Registers this browser against the license (or refreshes it).
 * Returns null when OK, or a plain-language reason when the seats are full.
 */
function claim_seat(array $license, string $deviceId, string $roleTag = ''): ?string
{
    $pdo  = db();
    $now  = now_iso();
    $role = mb_substr($roleTag, 0, 80);
    $find = $pdo->prepare('SELECT id FROM devices WHERE license_id = ? AND device_id = ?');
    $find->execute([(int) $license['id'], $deviceId]);
    if ($find->fetchColumn()) {
        $sql = $role !== ''
            ? 'UPDATE devices SET last_seen = ?, role_tag = ? WHERE license_id = ? AND device_id = ?'
            : 'UPDATE devices SET last_seen = ? WHERE license_id = ? AND device_id = ?';
        $pdo->prepare($sql)->execute($role !== ''
            ? [$now, $role, (int) $license['id'], $deviceId]
            : [$now, (int) $license['id'], $deviceId]);
        return null;
    }
    if (seats_used((int) $license['id']) >= (int) $license['seats']) {
        return "All {$license['seats']} seats on this key are in use. Ask your account owner to upgrade or free a seat.";
    }
    try {
        $pdo->prepare('INSERT INTO devices (license_id, device_id, role_tag, first_seen, last_seen) VALUES (?, ?, ?, ?, ?)')
            ->execute([(int) $license['id'], $deviceId, $role, $now, $now]);
    } catch (PDOException $e) {
        // Same browser registering twice at once — already counted.
    }
    return null;
}

function list_devices(int $licenseId): array
{
    $stmt = db()->prepare('SELECT d.*, (SELECT COUNT(*) FROM audits a WHERE a.license_id = d.license_id AND a.device_id = d.device_id) AS audits_sent
        FROM devices d WHERE d.license_id = ? ORDER BY d.first_seen');
    $stmt->execute([$licenseId]);
    return $stmt->fetchAll();
}

function join_url(array $license): string
{
    return site_url('join.php') . '?k=' . rawurlencode($license['license_key']);
}

/* ------------------------------------------------------------------ */
/* Audits                                                              */
/* ------------------------------------------------------------------ */

function clean_domain($value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $value = strtolower(trim($value));
    return preg_match('/^[a-z0-9.-]{1,253}$/', $value) ? $value : null;
}

/** Validate an extension export and rebuild it from known fields only. */
function sanitize_audit_payload($payload): ?array
{
    if (!is_array($payload) || !is_array($payload['auditMetadata'] ?? null) || !is_array($payload['softwareUsage'] ?? null)) {
        return null;
    }
    $meta = $payload['auditMetadata'];

    $usage = [];
    foreach (array_slice($payload['softwareUsage'], 0, 500) as $row) {
        $domain = clean_domain($row['domain'] ?? null);
        if ($domain === null) {
            continue;
        }
        $usage[] = [
            'domain'        => $domain,
            'activeMinutes' => round(max(0, (float) ($row['activeMinutes'] ?? 0)), 1),
            'visitCount'    => max(0, (int) ($row['visitCount'] ?? 0)),
            'shortVisits'   => max(0, (int) ($row['shortVisits'] ?? 0)),
        ];
    }

    $loops = [];
    foreach (array_slice((array) ($payload['repeatedWorkflowLoops'] ?? []), 0, 50) as $row) {
        $seq = array_values(array_filter(array_map('clean_domain', (array) ($row['sequence'] ?? []))));
        if (count($seq) !== 4) {
            continue;
        }
        $loops[] = ['sequence' => $seq, 'repeatCount' => max(0, (int) ($row['repeatCount'] ?? 0))];
    }

    $jumps = [];
    foreach (array_slice((array) ($payload['domainJumps'] ?? []), 0, 60) as $row) {
        $from = clean_domain($row['from'] ?? null);
        $to   = clean_domain($row['to'] ?? null);
        if ($from && $to && $from !== $to) {
            $jumps[] = ['from' => $from, 'to' => $to, 'count' => max(0, (int) ($row['count'] ?? 0))];
        }
    }

    $exportDate = (string) ($meta['exportDate'] ?? '');
    if (strtotime($exportDate) === false) {
        $exportDate = now_iso();
    }

    return [
        'auditMetadata' => [
            'roleTag'              => mb_substr(trim((string) ($meta['roleTag'] ?? 'Unspecified')), 0, 80) ?: 'Unspecified',
            'exportDate'           => $exportDate,
            'totalActiveMinutes'   => round(max(0, (float) ($meta['totalActiveMinutes'] ?? 0)), 1),
            'uniqueToolsCount'     => count($usage),
            'rapidContextSwitches' => max(0, (int) ($meta['rapidContextSwitches'] ?? 0)),
        ],
        'softwareUsage'         => $usage,
        'repeatedWorkflowLoops' => $loops,
        'domainJumps'           => $jumps,
    ];
}

function store_audit(int $licenseId, array $clean, string $deviceId = ''): int
{
    db()->prepare('INSERT INTO audits (license_id, role_tag, device_id, total_minutes, payload, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([
            $licenseId,
            $clean['auditMetadata']['roleTag'],
            $deviceId,
            $clean['auditMetadata']['totalActiveMinutes'],
            json_encode($clean, JSON_UNESCAPED_SLASHES),
            now_iso(),
        ]);
    return (int) db()->lastInsertId();
}

function list_audits(int $licenseId): array
{
    $stmt = db()->prepare('SELECT * FROM audits WHERE license_id = ? ORDER BY id DESC');
    $stmt->execute([$licenseId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['data'] = json_decode($row['payload'], true) ?: [];
    }
    return $rows;
}

function aggregate_audits(array $audits): array
{
    $domains = [];
    $loops   = [];
    $jumps   = [];
    $roles   = [];
    $minutes = 0.0;
    $switches = 0;
    foreach ($audits as $a) {
        $d = $a['data'];
        $role = $d['auditMetadata']['roleTag'] ?? 'Unspecified';
        $roles[$role] = ($roles[$role] ?? 0) + 1;
        $minutes  += (float) ($d['auditMetadata']['totalActiveMinutes'] ?? 0);
        $switches += (int) ($d['auditMetadata']['rapidContextSwitches'] ?? 0);
        foreach ($d['softwareUsage'] ?? [] as $u) {
            $k = $u['domain'];
            $domains[$k] ??= ['domain' => $k, 'activeMinutes' => 0.0, 'visitCount' => 0, 'shortVisits' => 0, 'roles' => []];
            $domains[$k]['activeMinutes'] += (float) $u['activeMinutes'];
            $domains[$k]['visitCount']    += (int) $u['visitCount'];
            $domains[$k]['shortVisits']   += (int) ($u['shortVisits'] ?? 0);
            $domains[$k]['roles'][$role] = true;
        }
        foreach ($d['domainJumps'] ?? [] as $jmp) {
            $k = $jmp['from'] . ' -> ' . $jmp['to'];
            $jumps[$k] = ($jumps[$k] ?? 0) + (int) $jmp['count'];
        }
        foreach ($d['repeatedWorkflowLoops'] ?? [] as $l) {
            $k = implode(' -> ', $l['sequence']);
            $loops[$k] ??= ['sequence' => $l['sequence'], 'repeatCount' => 0];
            $loops[$k]['repeatCount'] += (int) $l['repeatCount'];
        }
    }
    foreach ($domains as &$dm) {
        $dm['activeMinutes'] = round($dm['activeMinutes'], 1);
        $dm['roles'] = array_keys($dm['roles']);
    }
    unset($dm);
    usort($domains, fn($a, $b) => $b['activeMinutes'] <=> $a['activeMinutes']);
    $loops = array_values($loops);
    usort($loops, fn($a, $b) => $b['repeatCount'] <=> $a['repeatCount']);

    return [
        'auditCount'           => count($audits),
        'roles'                => $roles,
        'totalActiveMinutes'   => round($minutes, 1),
        'rapidContextSwitches' => $switches,
        'softwareUsage'        => array_values($domains),
        'repeatedWorkflowLoops' => array_slice($loops, 0, 40),
        'domainJumps'           => (function (array $j) { arsort($j); return array_slice($j, 0, 40, true); })($jumps),
    ];
}

/* ------------------------------------------------------------------ */
/* Business profile                                                    */
/* ------------------------------------------------------------------ */

function profile_fields(): array
{
    // key => [label, type, placeholder]
    return [
        'company'          => ['Company name', 'text', 'Acme Inc.'],
        'industry'         => ['Industry', 'select', ''],
        'website'          => ['Website', 'url', 'acme.com'],
        'team_size'        => ['How many people work here?', 'text', 'e.g. 12'],
        'software_budget'  => ['About how much do you spend on software each month?', 'text', 'e.g. $3,500'],
        'hourly_cost'      => ['About how much does an hour of staff time cost you?', 'text', 'e.g. $40'],
        'facebook'         => ['Facebook', 'url', 'facebook.com/…'],
        'instagram'        => ['Instagram', 'url', 'instagram.com/…'],
        'linkedin'         => ['LinkedIn', 'url', 'linkedin.com/company/…'],
        'tiktok'           => ['TikTok', 'url', 'tiktok.com/@…'],
        'x_twitter'        => ['X (Twitter)', 'url', 'x.com/…'],
        'youtube'          => ['YouTube', 'url', 'youtube.com/@…'],
        'google_business'  => ['Google Business Profile', 'url', 'g.page/…'],
        'other_channels'   => ['Anywhere else you reach customers? (email list, podcast…)', 'textarea', ''],
        'departments'      => ['Who is on your team?', 'textarea', 'e.g. Sales: 4, Office: 2, Owner: 1'],
        'current_tools'    => ['What software do you pay for?', 'textarea', 'e.g. HubSpot $800, Slack $120'],
        'lead_sources'     => ['How do new customers find you?', 'textarea', ''],
        'bottlenecks'      => ['What slows you down the most?', 'textarea', ''],
        'goals'            => ['What do you want to fix in the next 90 days?', 'textarea', ''],
    ];
}

function profile_data(int $licenseId): array
{
    $stmt = db()->prepare('SELECT data FROM profiles WHERE license_id = ?');
    $stmt->execute([$licenseId]);
    $raw = $stmt->fetchColumn();
    return $raw ? (json_decode((string) $raw, true) ?: []) : [];
}

function write_profile_data(int $licenseId, array $data): void
{
    $pdo  = db();
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $now  = now_iso();
    $exists = $pdo->prepare('SELECT 1 FROM profiles WHERE license_id = ?');
    $exists->execute([$licenseId]);
    if ($exists->fetchColumn()) {
        $pdo->prepare('UPDATE profiles SET data = ?, updated_at = ? WHERE license_id = ?')->execute([$json, $now, $licenseId]);
    } else {
        $pdo->prepare('INSERT INTO profiles (license_id, data, updated_at) VALUES (?, ?, ?)')->execute([$licenseId, $json, $now]);
    }
}

function load_profile(int $licenseId): array
{
    $data = profile_data($licenseId);
    $out = [];
    foreach (profile_fields() as $key => $_) {
        $out[$key] = (string) ($data[$key] ?? '');
    }
    if ($out['industry'] !== '' && !isset(industries()[$out['industry']])) {
        $out['industry'] = 'other';
    }
    return $out;
}

function save_profile(int $licenseId, array $input): array
{
    $clean = [];
    foreach (profile_fields() as $key => [, $type]) {
        $val = trim((string) ($input[$key] ?? ''));
        $val = mb_substr($val, 0, $type === 'textarea' ? 4000 : 300);
        if ($type === 'url' && $val !== '' && !preg_match('#^https?://#i', $val)) {
            $val = 'https://' . $val;
        }
        if ($type === 'select' && !isset(industries()[$val])) {
            $val = '';
        }
        $clean[$key] = $val;
    }
    write_profile_data($licenseId, array_merge(profile_data($licenseId), $clean));
    return $clean;
}

/** Back-Office Check answers: [item id => yes|partly|no|unsure]. */
function load_checklist(int $licenseId): array
{
    $answers = profile_data($licenseId)['checklist'] ?? [];
    return is_array($answers) ? $answers : [];
}

function save_checklist(int $licenseId, string $industry, array $input): array
{
    $answers = load_checklist($licenseId); // keep answers from other industries if they switch back
    foreach (checklist_items($industry) as $id => $_) {
        $a = (string) ($input[$id] ?? '');
        if (isset(CHECK_ANSWERS[$a])) {
            $answers[$id] = $a;
        }
    }
    $data = profile_data($licenseId);
    $data['checklist'] = $answers;
    write_profile_data($licenseId, $data);
    return $answers;
}

/* ------------------------------------------------------------------ */
/* Software the customer pays for (Team Audit)                         */
/* ------------------------------------------------------------------ */

/** @return list<array{name:string,cost:float,seats:int,note:string}> */
function load_tools(int $licenseId): array
{
    $tools = profile_data($licenseId)['tools'] ?? [];
    return is_array($tools) ? array_values($tools) : [];
}

function save_tools(int $licenseId, array $tools): void
{
    $data = profile_data($licenseId);
    $data['tools'] = array_values(array_slice($tools, 0, 100));
    write_profile_data($licenseId, $data);
}

function clean_tool_input(array $in): ?array
{
    $name = trim(mb_substr((string) ($in['name'] ?? ''), 0, 80));
    if ($name === '') {
        return null;
    }
    $cost = (float) preg_replace('/[^0-9.]/', '', (string) ($in['cost'] ?? ''));
    return [
        'name'  => $name,
        'cost'  => round(max(0, min($cost, 1000000)), 2),
        'seats' => max(0, min((int) ($in['seats'] ?? 0), 100000)),
        'note'  => trim(mb_substr((string) ($in['note'] ?? ''), 0, 120)),
    ];
}

/** Minutes per friendly tool name across all audits. */
function tool_minutes_seen(array $aggregate): array
{
    $seen = [];
    foreach ($aggregate['softwareUsage'] ?? [] as $u) {
        $info = tool_info((string) $u['domain']);
        if ($info['category'] === 'search') {
            continue;
        }
        $key = strtolower($info['name']);
        $seen[$key] ??= ['name' => $info['name'], 'minutes' => 0.0, 'category' => $info['category']];
        $seen[$key]['minutes'] += (float) $u['activeMinutes'];
    }
    uasort($seen, fn($a, $b) => $b['minutes'] <=> $a['minutes']);
    return $seen;
}

/** Match each paid tool to what the audits saw. */
function tools_summary(array $tools, array $aggregate): array
{
    $seen = tool_minutes_seen($aggregate);
    $rows = [];
    $total = 0.0;
    $unseenCost = 0.0;
    foreach ($tools as $i => $t) {
        $needle = strtolower($t['name']);
        $minutes = null;
        foreach ($seen as $key => $s) {
            if ($key === $needle || str_contains($key, $needle) || str_contains($needle, $key)) {
                $minutes = ($minutes ?? 0) + $s['minutes'];
            }
        }
        $desktop = (bool) preg_match('/desktop|installed|on.?prem|phone system/i', $t['note'] . ' ' . $t['name']);
        $total += $t['cost'];
        if ($minutes === null && !$desktop && $t['cost'] > 0) {
            $unseenCost += $t['cost'];
        }
        $rows[] = $t + ['index' => $i, 'minutes' => $minutes, 'desktop' => $desktop];
    }
    $listed = array_map(fn($t) => strtolower($t['name']), $tools);
    $suggest = [];
    foreach ($seen as $key => $s) {
        $isListed = false;
        foreach ($listed as $l) {
            if ($l === $key || str_contains($key, $l) || str_contains($l, $key)) {
                $isListed = true;
                break;
            }
        }
        if (!$isListed && $s['minutes'] >= 1) {
            $suggest[] = $s;
        }
    }
    return [
        'rows'        => $rows,
        'monthly'     => round($total, 2),
        'unseen_cost' => round($unseenCost, 2),
        'suggestions' => array_slice($suggest, 0, 12),
    ];
}

function money(float $n): string
{
    return '$' . number_format($n, $n == floor($n) ? 0 : 2);
}

/* ------------------------------------------------------------------ */
/* Report prompt (Team Audit)                                         */
/* ------------------------------------------------------------------ */

function build_business_prompt(array $license, array $profile, array $aggregate, array $checklist = [], array $tools = []): string
{
    $lidForChain = (int) $license['id'];
    $chainStatus = chain_status($profile['industry'] ?: 'other', load_chain($lidForChain), chain_evidence($aggregate), load_tests($lidForChain));
    $chainText = chain_prompt_text($chainStatus);
    $toolLines = [];
    foreach (tools_summary($tools, $aggregate)['rows'] as $t) {
        $toolLines[] = '- ' . $t['name'] . ': ' . money((float) $t['cost']) . '/month'
            . ($t['seats'] ? ', ' . $t['seats'] . ' seats' : '')
            . ($t['note'] !== '' ? ' (' . $t['note'] . ')' : '')
            . ' -> ' . ($t['minutes'] !== null ? 'seen in the audit: ' . round($t['minutes'] / 60, 1) . ' hours'
                : ($t['desktop'] ? 'desktop app (the browser audit cannot see it)' : 'not seen in the browser audit'));
    }
    $toolText = $toolLines ? implode("\n", $toolLines) : '- (The owner has not listed their software yet. Use the telemetry and estimates.)';
    $coverage = round(((float) ($aggregate['totalActiveMinutes'] ?? 0)) / 60, 1) . ' hours of work across '
        . (int) ($aggregate['auditCount'] ?? 0) . ' audit(s).';
    $labels = profile_fields();
    $profileLines = [];
    foreach ($profile as $key => $val) {
        if ($val !== '') {
            $shown = $key === 'industry' ? industry_label($val) : str_replace("\n", ' / ', $val);
            $profileLines[] = '- ' . $labels[$key][0] . ': ' . $shown;
        }
    }
    $industryKey = $profile['industry'] ?: 'other';
    $industry    = industry_label($industryKey);
    $checkLines  = [];
    foreach (checklist_items($industryKey) as $id => $item) {
        $answer = CHECK_ANSWERS[$checklist[$id] ?? ''] ?? 'Not answered';
        $checkLines[] = '- [' . CHECK_AREAS[$item['area']] . '] ' . $item['q'] . ' -> ' . $answer;
    }
    $checkText = implode("\n", $checkLines);
    $profileText = $profileLines ? implode("\n", $profileLines) : '- (Business profile not filled in yet)';
    $company = $profile['company'] ?: ($license['company'] ?: 'the client');

    $telemetry = json_encode($aggregate, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return <<<PROMPT
You are an AI operations consultant writing a report for the owner of {$company}, a business in this industry:
{$industry}. The owner is busy and not technical: use plain words, short sentences and numbers first.
Keep the whole report under about 1,500 words.

## Inputs
1. BUSINESS PROFILE: supplied by the owner (social channels, team, tools, goals).
2. BACK-OFFICE CHECK: the owner's own answers about their processes, SOPs, follow-up, contracts, compliance
   and data security, including items specific to their industry.
3. TEAM TELEMETRY: combined from private, read-only browser audits across the team. It contains only website
   names, active minutes, visit counts, repeated 4-site patterns and quick tab jumps (less than 30 seconds apart).
   No page content or personal data.

## Data behind this report
{$coverage}

## Rules
- Start the report with one line saying what it is based on (hours of work and number of audits). If it is under
  10 hours, say the findings are early and will sharpen with more data.
- Use only the data provided. When a number is missing, say it is an estimate and show the simple math.
- Use the hourly labor cost from the profile if given; otherwise assume \$45/hour and 21.7 workdays/month.
- Ignore personal (non-work) websites in the cost math.
- Compliance points are general guidance for this industry, not legal advice. Say once, briefly, that the owner
  should confirm them with their attorney or compliance advisor. Never state that the business is compliant.
- Format in Markdown: ## headings, short bullet lists, numbered steps, and at most three small tables.

## Write these sections
## 1. The short version
3–5 bullets: the biggest bottleneck, what it costs per month, and the first fix.
## 2. Your bottlenecks
The top 3 repetitive tasks, in plain words, with the part of the business each one slows down (sales,
marketing, operations, finance, customer service) and the evidence from the data.
## 3. The AI agents you need
A table: Agent | What it does | Tools it connects | Hours saved per month (estimate) | Build effort (Low/Med/High).
Rank by value. Include a social media agent if the profile lists social channels.
## 4. How to fix the #1 bottleneck
A simple step-by-step process (5–7 steps) showing exactly how the work will flow once the agent is in place,
including where a person approves things.
## 5. What it is costing you now
Hours and dollars per month for the team size given, with the math.
## 6. Your back office
From the Back-Office Check: the biggest gaps in processes, follow-up, contracts, compliance and data security,
most serious first (compliance and contracts before the rest). For each: why it matters for a {$industry}
business and how it can be automated. Then list the 3–5 SOPs to write first.
## 7. Where things fall through the cracks
From CLIENT JOURNEY: every link answered "Sometimes" or "Doesn't happen", every link where the audit or our test
shows a problem, and "By hand" links that cost the most time. For each: what is being missed, what it likely costs
(lost deals, slow payment, no-shows), the process fix, and the agent that keeps it from breaking again.
Also point out interruptions: tools with many shortVisits (check-ins under 2 minutes, e.g. social media, chat, email)
and the tools people jump between most (domainJumps), which usually means copying information by hand.
## 8. Software you may not need
Use the owner's real prices from SOFTWARE YOU PAY FOR. Flag tools that overlap, and tools that cost money but
were barely used or not seen in the audit. A tool not seen may be a desktop app (the audit only sees websites),
so say "check whether this is still used" rather than "cancel it". Give the possible monthly and yearly savings.
## 9. Your next 30 days
Week 1 to Week 4, one or two actions each.

## BUSINESS PROFILE
{$profileText}

## BACK-OFFICE CHECK
{$checkText}

## SOFTWARE YOU PAY FOR
{$toolText}

## CLIENT JOURNEY (does each step trigger the next?)
{$chainText}

## TEAM TELEMETRY (JSON)
```json
{$telemetry}
```
PROMPT;
}

/* ------------------------------------------------------------------ */
/* Reports (instant AI draft + final plan from your team)              */
/* ------------------------------------------------------------------ */

function latest_report(int $licenseId, string $kind): ?array
{
    $stmt = db()->prepare('SELECT * FROM reports WHERE license_id = ? AND kind = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$licenseId, $kind]);
    return $stmt->fetch() ?: null;
}

function save_report(int $licenseId, string $kind, string $content, string $model = ''): int
{
    db()->prepare('INSERT INTO reports (license_id, kind, content, model, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$licenseId, $kind, $content, $model, now_iso()]);
    return (int) db()->lastInsertId();
}

function reports_today(int $licenseId): int
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM reports WHERE license_id = ? AND kind = 'ai' AND created_at > ?");
    $stmt->execute([$licenseId, gmdate('Y-m-d\TH:i:s\Z', time() - 86400)]);
    return (int) $stmt->fetchColumn();
}

function ai_reports_enabled(): bool
{
    $key = (string) cfg('anthropic_api_key');
    return $key !== '' && !str_contains($key, 'REPLACE');
}

/**
 * Writes the instant report with Claude (official Anthropic PHP SDK, in vendor/).
 * Streams the response so long reports don't hit HTTP timeouts.
 *
 * @return array{text: string, model: string}
 */
function generate_ai_report(string $prompt): array
{
    require_once dirname(__DIR__) . '/vendor/autoload.php';

    $client = new \Anthropic\Client(
        apiKey: (string) cfg('anthropic_api_key'),
        requestOptions: ['timeout' => 280],
    );

    $stream = $client->beta->messages->createStream(
        maxTokens: 16000,
        messages: [['role' => 'user', 'content' => $prompt]],
        model: (string) cfg('anthropic_model'),
        outputConfig: ['effort' => (string) cfg('anthropic_effort')],
        // If the model declines for policy reasons, let the API retry on its default fallback model.
        fallbacks: 'default',
        betas: ['server-side-fallback-2026-07-01'],
    );

    $accumulator = \Anthropic\Lib\Streaming\MessageAccumulator::forBetaMessages();
    foreach ($stream as $event) {
        $accumulator->accumulate($event);
    }
    $message = $accumulator->message();

    if ($message->stopReason === 'refusal') {
        throw new RuntimeException('The AI declined to write this report.');
    }

    $text = '';
    foreach ($message->content as $block) {
        if ($block->type === 'text') {
            $text .= $block->text;
        }
    }
    if (trim($text) === '') {
        throw new RuntimeException('The AI returned an empty report.');
    }
    if ($message->stopReason === 'max_tokens') {
        $text .= "\n\n_(Report was cut short. Generate it again for the full version.)_";
    }
    return ['text' => $text, 'model' => (string) $message->model];
}

/* ------------------------------------------------------------------ */
/* Minimal, safe Markdown renderer (everything is escaped first)       */
/* ------------------------------------------------------------------ */

function md_inline(string $text): string
{
    $html = h($text);
    $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);
    $html = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/s', '<em>$1</em>', $html);
    $html = preg_replace('/(?<!\w)_(?!\s)(.+?)(?<!\s)_(?!\w)/s', '<em>$1</em>', $html);
    $html = preg_replace('/`([^`]+)`/', '<code>$1</code>', $html);
    return (string) $html;
}

function render_markdown(string $md): string
{
    $lines = preg_split('/\r\n|\r|\n/', $md);
    $out   = '';
    $list  = null;   // 'ul' | 'ol'
    $para  = [];
    $table = [];

    $flushPara = function () use (&$para, &$out) {
        if ($para) {
            $out .= '<p>' . md_inline(implode(' ', $para)) . '</p>';
            $para = [];
        }
    };
    $closeList = function () use (&$list, &$out) {
        if ($list) {
            $out .= "</$list>";
            $list = null;
        }
    };
    $flushTable = function () use (&$table, &$out) {
        if (!$table) {
            return;
        }
        $rows = array_values(array_filter($table, fn($r) => !preg_match('/^\s*\|?\s*:?-{2,}/', $r)));
        $html = '<div class="table-wrap"><table>';
        foreach ($rows as $i => $row) {
            $cells = array_map('trim', explode('|', trim(trim($row), '|')));
            $tag = $i === 0 ? 'th' : 'td';
            $html .= '<tr>' . implode('', array_map(fn($c) => "<$tag>" . md_inline($c) . "</$tag>", $cells)) . '</tr>';
        }
        $out .= $html . '</table></div>';
        $table = [];
    };

    foreach ($lines as $line) {
        $trim = trim($line);
        if (str_starts_with($trim, '|')) {
            $flushPara();
            $closeList();
            $table[] = $trim;
            continue;
        }
        $flushTable();

        if ($trim === '' || preg_match('/^(-{3,}|\*{3,})$/', $trim)) {
            $flushPara();
            $closeList();
        } elseif (preg_match('/^(#{1,6})\s+(.*)$/', $trim, $m)) {
            $flushPara();
            $closeList();
            $level = min(4, max(2, strlen($m[1]) + 1));
            $out .= "<h$level>" . md_inline($m[2]) . "</h$level>";
        } elseif (preg_match('/^[-*+]\s+(.*)$/', $trim, $m)) {
            $flushPara();
            if ($list !== 'ul') {
                $closeList();
                $out .= '<ul>';
                $list = 'ul';
            }
            $out .= '<li>' . md_inline($m[1]) . '</li>';
        } elseif (preg_match('/^\d+[.)]\s+(.*)$/', $trim, $m)) {
            $flushPara();
            if ($list !== 'ol') {
                $closeList();
                $out .= '<ol>';
                $list = 'ol';
            }
            $out .= '<li>' . md_inline($m[1]) . '</li>';
        } else {
            $closeList();
            $para[] = $trim;
        }
    }
    $flushTable();
    $flushPara();
    $closeList();
    return '<div class="report">' . $out . '</div>';
}

/* ------------------------------------------------------------------ */
/* Results Tracking: month-by-month progress                           */
/* ------------------------------------------------------------------ */

function progress_by_month(array $audits): array
{
    $months = [];
    foreach ($audits as $a) {
        $month = substr((string) $a['created_at'], 0, 7);
        $d     = $a['data'];
        $months[$month] ??= ['month' => $month, 'minutes' => 0.0, 'switches' => 0, 'repeats' => 0, 'audits' => 0];
        $months[$month]['audits']++;
        $months[$month]['minutes']  += (float) ($d['auditMetadata']['totalActiveMinutes'] ?? 0);
        $months[$month]['switches'] += (int) ($d['auditMetadata']['rapidContextSwitches'] ?? 0);
        foreach ($d['repeatedWorkflowLoops'] ?? [] as $l) {
            $months[$month]['repeats'] += (int) $l['repeatCount'];
        }
    }
    ksort($months);
    foreach ($months as &$m) {
        $hours = $m['minutes'] / 60;
        $m['hours']             = round($hours, 1);
        $m['repeats_per_hour']  = $hours > 0 ? round($m['repeats'] / $hours, 1) : 0.0;
        $m['switches_per_hour'] = $hours > 0 ? round($m['switches'] / $hours, 1) : 0.0;
    }
    return array_values($months);
}

/* ------------------------------------------------------------------ */
/* Layout                                                              */
/* ------------------------------------------------------------------ */

function render_header(string $title): void
{
    $brand = h((string) cfg('brand_name'));
    $css   = h(site_url('assets/style.css'));
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{$title} · {$brand}</title>
<link rel="stylesheet" href="{$css}">
</head>
<body>
<header class="topbar"><a class="brand" href="./">{$brand} <span>Workflow Shadow Audit</span></a></header>
<main class="container">
HTML;
}

function render_footer(): void
{
    $brand = h((string) cfg('brand_name'));
    $year  = date('Y');
    echo <<<HTML
</main>
<footer class="foot">© {$year} {$brand} · Payments processed securely by Stripe</footer>
<script>
document.querySelectorAll('[data-copy]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var src = document.getElementById(btn.getAttribute('data-copy'));
    var text = src.value !== undefined ? src.value : src.textContent;
    var done = function () { var t = btn.textContent; btn.textContent = 'Copied!'; setTimeout(function(){ btn.textContent = t; }, 1800); };
    if (navigator.clipboard) { navigator.clipboard.writeText(text).then(done, function(){ src.select && src.select(); document.execCommand('copy'); done(); }); }
    else { src.select && src.select(); document.execCommand('copy'); done(); }
  });
});
</script>
</body>
</html>
HTML;
}


/* ------------------------------------------------------------------ */
/* GoHighLevel: send events so GHL workflows can follow up             */
/* ------------------------------------------------------------------ */
/*
 * Each event creates/updates the contact in GHL, fills in custom fields and
 * adds tags. Build GHL workflows with the trigger "Contact Tag added".
 *
 * Destinations (config 'ghl'): each has its own sub-account (location) and
 * Private Integration token. 'events' => 'all' or a list of event names.
 * Example: Sirena Flow gets everything; ALJA gets only build-ready events.
 *
 * GHL failures never break the customer's flow — they are logged and shown in admin.php.
 */

const GHL_EVENTS = [
    'free_audit'                => ['swa-free-audit'],
    'team_audit_purchased'      => ['swa-team-audit-purchased'],
    'profile_updated'           => [],
    'first_audit_received'      => ['swa-first-audit-received'],
    'instant_report_ready'      => ['swa-instant-report-ready'],
    'final_plan_sent'           => ['swa-final-plan-sent'],
    'results_tracking_on'       => ['swa-results-tracking-on'],
    'results_tracking_canceled' => ['swa-results-tracking-canceled'],
    'wants_help'                => ['swa-wants-help'],
    'refund_requested'          => ['swa-refund-requested'],
];

/** Our field key => [name shown in GHL, data type]. GHL merge tag: {{contact.<key>}}. */
const GHL_FIELDS = [
    'swa_plan'              => ['SWA Plan', 'TEXT'],
    'swa_industry'          => ['SWA Industry', 'TEXT'],
    'swa_role'              => ['SWA Role', 'TEXT'],
    'swa_seats'             => ['SWA Seats', 'NUMERICAL'],
    'swa_audit_ends'        => ['SWA Audit Ends', 'DATE'],
    'swa_portal_link'       => ['SWA Portal Link', 'TEXT'],
    'swa_bottleneck'        => ['SWA Bottleneck', 'TEXT'],
    'swa_repeated_task'     => ['SWA Repeated Task', 'LARGE_TEXT'],
    'swa_recommended_agent' => ['SWA Recommended Agent', 'TEXT'],
    'swa_agent_does'        => ['SWA Agent Does', 'LARGE_TEXT'],
    'swa_audits_received'   => ['SWA Audits Received', 'NUMERICAL'],
    'swa_backoffice_score'  => ['SWA Back Office Score', 'NUMERICAL'],
    'swa_nps'               => ['SWA Recommend Score', 'NUMERICAL'],
    'swa_accuracy'          => ['SWA Accuracy Rating', 'TEXT'],
];

function ghl_destinations(): array
{
    $out = [];
    foreach ((array) cfg_opt('ghl', []) as $name => $d) {
        if (!empty($d['api_token']) && !empty($d['location_id']) && !str_contains((string) $d['api_token'], 'REPLACE')) {
            $out[(string) $name] = $d;
        }
    }
    return $out;
}

function ghl_request(array $dest, string $method, string $path, ?array $body = null): array
{
    $base = rtrim((string) cfg_opt('ghl_base_url', 'https://services.leadconnectorhq.com'), '/');
    $ch = curl_init($base . $path);
    $headers = [
        'Authorization: Bearer ' . $dest['api_token'],
        'Version: 2021-07-28',
        'Accept: application/json',
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        throw new RuntimeException('Could not reach GHL: ' . $err);
    }
    $data = json_decode((string) $raw, true);
    if ($code >= 400) {
        $msg = is_array($data) ? (string) ($data['message'] ?? json_encode($data)) : substr((string) $raw, 0, 200);
        throw new RuntimeException("GHL HTTP $code: " . (is_array($msg) ? json_encode($msg) : $msg));
    }
    return is_array($data) ? $data : [];
}

/** Map our field keys to this sub-account's custom field ids (cached for a day). */
function ghl_field_ids(array $dest, bool $refresh = false): array
{
    $cacheKey = 'ghl_fields_' . $dest['location_id'];
    if (!$refresh) {
        $stmt = db()->prepare('SELECT v FROM kv WHERE k = ? AND updated_at > ?');
        $stmt->execute([$cacheKey, time() - 86400]);
        if (($cached = $stmt->fetchColumn()) !== false) {
            return json_decode((string) $cached, true) ?: [];
        }
    }
    $res = ghl_request($dest, 'GET', '/locations/' . rawurlencode($dest['location_id']) . '/customFields?model=contact');
    $byName = [];
    foreach ($res['customFields'] ?? [] as $f) {
        $byName[strtolower(trim((string) ($f['name'] ?? '')))] = (string) ($f['id'] ?? '');
    }
    $ids = [];
    foreach (GHL_FIELDS as $key => [$name]) {
        if (!empty($byName[strtolower($name)])) {
            $ids[$key] = $byName[strtolower($name)];
        }
    }
    $json = json_encode($ids);
    $pdo  = db();
    $pdo->prepare('DELETE FROM kv WHERE k = ?')->execute([$cacheKey]);
    $pdo->prepare('INSERT INTO kv (k, v, updated_at) VALUES (?, ?, ?)')->execute([$cacheKey, $json, time()]);
    return $ids;
}

/** Create any missing SWA custom fields in a destination. Returns the names created. */
function ghl_setup_fields(array $dest): array
{
    $have = ghl_field_ids($dest, true);
    $created = [];
    foreach (GHL_FIELDS as $key => [$name, $type]) {
        if (isset($have[$key])) {
            continue;
        }
        ghl_request($dest, 'POST', '/locations/' . rawurlencode($dest['location_id']) . '/customFields',
            ['name' => $name, 'dataType' => $type, 'model' => 'contact']);
        $created[] = $name;
    }
    ghl_field_ids($dest, true);
    return $created;
}

function ghl_log(string $dest, string $event, string $email, bool $ok, string $detail = ''): void
{
    try {
        db()->prepare('INSERT INTO ghl_log (destination, event, email, ok, detail, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$dest, $event, $email, $ok ? 1 : 0, mb_substr($detail, 0, 500), now_iso()]);
    } catch (Throwable $e) {
        error_log('[SWA ghl_log] ' . $e->getMessage());
    }
}

/**
 * Send one event to every GHL destination that wants it.
 * $contact: email (required), companyName, website, phone
 * $fields:  our field keys (see GHL_FIELDS) => value
 * $extraTags: added on top of the event's own tags
 */
function ghl_event(string $event, array $contact, array $fields = [], array $extraTags = []): void
{
    $email = strtolower(trim((string) ($contact['email'] ?? '')));
    if (!isset(GHL_EVENTS[$event]) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    $tags = array_values(array_unique(array_filter(array_merge(GHL_EVENTS[$event], $extraTags))));

    foreach (ghl_destinations() as $name => $dest) {
        $wanted = $dest['events'] ?? 'all';
        if ($wanted !== 'all' && !in_array($event, (array) $wanted, true)) {
            continue;
        }
        try {
            $ids = ghl_field_ids($dest);
            $custom = [];
            foreach ($fields as $key => $value) {
                if (isset($ids[$key]) && $value !== null && $value !== '') {
                    $custom[] = ['id' => $ids[$key], 'fieldValue' => is_numeric($value) && GHL_FIELDS[$key][1] === 'NUMERICAL' ? $value + 0 : (string) $value];
                }
            }
            $body = array_filter([
                'locationId'   => $dest['location_id'],
                'email'        => $email,
                'companyName'  => $contact['companyName'] ?? null,
                'website'      => $contact['website'] ?? null,
                'source'       => 'Workflow Shadow Audit',
                'customFields' => $custom ?: null,
            ], fn($v) => $v !== null && $v !== '');
            $res = ghl_request($dest, 'POST', '/contacts/upsert', $body);
            $contactId = (string) ($res['contact']['id'] ?? '');
            if ($contactId === '') {
                throw new RuntimeException('GHL did not return a contact id');
            }
            $allTags = array_merge($tags, (array) ($dest['extra_tags'][$event] ?? []));
            if ($allTags) {
                // Add tags separately: the upsert "tags" field would replace the contact's existing tags.
                ghl_request($dest, 'POST', '/contacts/' . rawurlencode($contactId) . '/tags', ['tags' => array_values(array_unique($allTags))]);
            }
            ghl_log($name, $event, $email, true, implode(', ', $allTags));
        } catch (Throwable $e) {
            error_log("[SWA ghl $name/$event] " . $e->getMessage());
            ghl_log($name, $event, $email, false, $e->getMessage());
            log_issue('ghl', "$name/$event for $email: " . $e->getMessage());
        }
    }
}

/** Fields that describe a Team Audit customer. */
function ghl_license_fields(array $license): array
{
    $profile = load_profile((int) $license['id']);
    return [
        'swa_plan'        => plan_name($license),
        'swa_seats'       => (int) $license['seats'],
        'swa_audit_ends'  => $license['access_until'] ? substr((string) $license['access_until'], 0, 10) : null,
        'swa_portal_link' => site_url('portal.php'),
        'swa_industry'    => $profile['industry'] ? industry_label($profile['industry']) : null,
    ];
}

function ghl_license_contact(array $license): array
{
    $profile = load_profile((int) $license['id']);
    return [
        'email'       => $license['email'],
        'companyName' => $profile['company'] ?: $license['company'],
        'website'     => $profile['website'] ?: null,
    ];
}

function ghl_industry_tag(?string $industryKey): array
{
    return $industryKey && isset(industries()[$industryKey]) ? ['swa-industry-' . str_replace('_', '-', $industryKey)] : [];
}


/* ------------------------------------------------------------------ */
/* Issues (shown on the admin page so nothing fails silently)          */
/* ------------------------------------------------------------------ */

function log_issue(string $kind, string $detail): void
{
    try {
        db()->prepare('INSERT INTO issues (kind, detail, created_at) VALUES (?, ?, ?)')
            ->execute([mb_substr($kind, 0, 40), mb_substr($detail, 0, 1000), now_iso()]);
    } catch (Throwable $e) {
        error_log('[SWA log_issue] ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------ */
/* Survey / feedback                                                   */
/* ------------------------------------------------------------------ */

const ACCURACY_ANSWERS = ['spot_on' => 'Spot on', 'partly' => 'Partly', 'not_really' => 'Not really'];

function save_feedback(array $f): int
{
    db()->prepare('INSERT INTO feedback (context, email, license_id, accurate, nps, wants_help, comment, industry, agent, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([
            $f['context'],
            $f['email'] ?? '',
            (int) ($f['license_id'] ?? 0),
            isset(ACCURACY_ANSWERS[$f['accurate'] ?? '']) ? $f['accurate'] : '',
            isset($f['nps']) && $f['nps'] !== '' && $f['nps'] !== null ? max(0, min(10, (int) $f['nps'])) : null,
            empty($f['wants_help']) ? 0 : 1,
            mb_substr(trim((string) ($f['comment'] ?? '')), 0, 2000),
            mb_substr((string) ($f['industry'] ?? ''), 0, 40),
            mb_substr((string) ($f['agent'] ?? ''), 0, 80),
            now_iso(),
        ]);
    return (int) db()->lastInsertId();
}

function feedback_given(int $licenseId, string $context): bool
{
    $stmt = db()->prepare('SELECT 1 FROM feedback WHERE license_id = ? AND context = ? LIMIT 1');
    $stmt->execute([$licenseId, $context]);
    return (bool) $stmt->fetchColumn();
}

/* ------------------------------------------------------------------ */
/* How much data a report is based on                                  */
/* ------------------------------------------------------------------ */

function data_coverage(array $license, array $audits, array $devices): array
{
    $hours  = 0.0;
    $dates  = [];
    $senders = [];
    foreach ($audits as $a) {
        $hours += (float) $a['total_minutes'] / 60;
        $dates[] = substr((string) $a['created_at'], 0, 10);
        $senders[$a['device_id'] ?: ('audit-' . $a['id'])] = true;
    }
    $solo    = license_is_solo($license);
    $minimum = $solo ? 5 : 10;
    $waiting = [];
    foreach ($devices as $d) {
        if ((int) $d['audits_sent'] === 0) {
            $waiting[] = $d['role_tag'] ?: 'someone';
        }
    }
    $people = count($senders);
    $text = $audits
        ? 'Based on ' . round($hours, 1) . ' hours of work'
            . ($solo ? '' : ' from ' . $people . ' ' . ($people === 1 ? 'person' : 'people'))
            . ($dates ? (min($dates) === max($dates) ? ', sent on ' . format_date(min($dates)) : ', sent between ' . format_date(min($dates)) . ' and ' . format_date(max($dates))) : '')
            . '.'
        : 'No audits yet.';
    if (!$solo && $waiting) {
        $text .= ' Still waiting on ' . count($waiting) . ' (' . implode(', ', array_slice($waiting, 0, 4)) . ').';
    }
    return [
        'hours'   => round($hours, 1),
        'people'  => $people,
        'waiting' => $waiting,
        'enough'  => $hours >= $minimum,
        'minimum' => $minimum,
        'text'    => $text,
    ];
}

/* ------------------------------------------------------------------ */
/* What happens next                                                   */
/* ------------------------------------------------------------------ */

/** Stages set by our team on the admin page. */
const BUILD_STAGES = [
    ''          => 'Not started',
    'call'      => 'Build call booked',
    'building'  => 'Building',
    'golive'    => 'Go-Live check',
    'live'      => 'Live',
];

function journey(array $license, bool $hasAudits, bool $hasAi, bool $hasFinal): array
{
    $stage = (string) (profile_data((int) $license['id'])['stage'] ?? '');
    $order = array_keys(BUILD_STAGES);
    $at    = array_search($stage, $order, true) ?: 0;
    $final = plan_includes_final_plan($license);
    $steps = [
        ['label' => 'Audit', 'done' => $hasAudits],
        ['label' => 'Instant AI report', 'done' => $hasAi],
    ];
    if ($final) {
        $steps[] = ['label' => 'Final plan (within 2 business days)', 'done' => $hasFinal];
    }
    $steps[] = ['label' => 'Build call', 'done' => $at >= 1];
    $steps[] = ['label' => 'Build', 'done' => $at >= 3];
    $steps[] = ['label' => 'Go-Live check', 'done' => $at >= 4];
    $steps[] = ['label' => 'Results Tracking', 'done' => license_monitoring($license)];
    // The first step not done is "now".
    foreach ($steps as $i => $st) {
        if (!$st['done']) {
            $steps[$i]['now'] = true;
            break;
        }
    }
    return $steps;
}

/* ------------------------------------------------------------------ */
/* Customer data: download and delete                                  */
/* ------------------------------------------------------------------ */

function export_customer_data(array $license): array
{
    $lid = (int) $license['id'];
    $reports = db()->prepare('SELECT kind, content, created_at FROM reports WHERE license_id = ? ORDER BY id');
    $reports->execute([$lid]);
    return [
        'exportedAt' => now_iso(),
        'company'    => $license['company'],
        'email'      => $license['email'],
        'plan'       => plan_name($license),
        'profile'    => profile_data($lid),
        'audits'     => array_map(fn($a) => $a['data'] + ['receivedAt' => $a['created_at']], list_audits($lid)),
        'reports'    => $reports->fetchAll(),
        'people'     => array_map(fn($d) => ['role' => $d['role_tag'], 'joined' => $d['first_seen'], 'lastActive' => $d['last_seen']], list_devices($lid)),
    ];
}

/** Deletes the company's audit data. Keeps only the purchase record (needed for receipts and refunds). */
function delete_customer_data(array $license): void
{
    $lid = (int) $license['id'];
    $pdo = db();
    foreach (['audits', 'profiles', 'reports', 'devices', 'feedback'] as $table) {
        $pdo->prepare("DELETE FROM $table WHERE license_id = ?")->execute([$lid]);
    }
    $pdo->prepare("UPDATE licenses SET status = 'deleted', updated_at = ? WHERE id = ?")->execute([now_iso(), $lid]);
}

/* ------------------------------------------------------------------ */
/* Admin scoreboard                                                    */
/* ------------------------------------------------------------------ */

function scoreboard(): array
{
    $pdo = db();
    $one = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
    $plans = [];
    foreach ($pdo->query('SELECT plan, COUNT(*) AS n FROM licenses GROUP BY plan')->fetchAll() as $r) {
        $plans[$r['plan']] = (int) $r['n'];
    }
    $nps = $pdo->query('SELECT nps FROM feedback WHERE nps IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
    $npsScore = null;
    if ($nps) {
        $prom = count(array_filter($nps, fn($n) => (int) $n >= 9));
        $det  = count(array_filter($nps, fn($n) => (int) $n <= 6));
        $npsScore = (int) round(100 * ($prom - $det) / count($nps));
    }
    $acc = [];
    foreach ($pdo->query("SELECT accurate, COUNT(*) AS n FROM feedback WHERE accurate <> '' GROUP BY accurate")->fetchAll() as $r) {
        $acc[$r['accurate']] = (int) $r['n'];
    }
    $accTotal = array_sum($acc);
    $leads    = $one('SELECT COUNT(*) FROM leads');
    $fallback = $one("SELECT COUNT(*) FROM leads WHERE agent = 'Workflow Agent'");
    $withAgent = $one("SELECT COUNT(*) FROM leads WHERE agent <> ''");

    // Domains the tool list doesn't recognise yet (from team audits).
    $unknown = [];
    foreach ($pdo->query('SELECT payload FROM audits ORDER BY id DESC LIMIT 300')->fetchAll(PDO::FETCH_COLUMN) as $raw) {
        foreach ((json_decode((string) $raw, true)['softwareUsage'] ?? []) as $u) {
            if (tool_info((string) $u['domain'])['category'] === 'other') {
                $unknown[$u['domain']] = ($unknown[$u['domain']] ?? 0) + (float) $u['activeMinutes'];
            }
        }
    }
    arsort($unknown);

    return [
        'free_audits'   => $leads,
        'purchases'     => array_sum($plans),
        'by_plan'       => $plans,
        'reports'       => $one("SELECT COUNT(DISTINCT license_id) FROM reports WHERE kind = 'ai'"),
        'final_plans'   => $one("SELECT COUNT(DISTINCT license_id) FROM reports WHERE kind = 'final'"),
        'tracking'      => $one("SELECT COUNT(*) FROM licenses WHERE monitor_status = 'active'"),
        'wants_help'    => $one('SELECT COUNT(*) FROM feedback WHERE wants_help = 1'),
        'surveys'       => $one('SELECT COUNT(*) FROM feedback'),
        'nps'           => $npsScore,
        'nps_count'     => count($nps),
        'accuracy'      => $accTotal ? (int) round(100 * (($acc['spot_on'] ?? 0) + 0.5 * ($acc['partly'] ?? 0)) / $accTotal) : null,
        'fallback_rate' => $withAgent ? (int) round(100 * $fallback / $withAgent) : null,
        'unknown_tools' => array_slice($unknown, 0, 10, true),
        'conversion'    => $leads ? min(100, (int) round(100 * array_sum($plans) / $leads)) : null,
    ];
}
