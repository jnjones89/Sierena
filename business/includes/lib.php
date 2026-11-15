<?php
/*
 * Workflow Shadow Audit — Business server shared library.
 * Plain PHP 8 + PDO + cURL. No Composer packages required (works on Hostinger shared hosting).
 */

declare(strict_types=1);

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
        total_minutes DOUBLE NOT NULL DEFAULT 0,
        payload $text NOT NULL,
        created_at VARCHAR(32) NOT NULL
    )$suffix");

    $pdo->exec("CREATE TABLE IF NOT EXISTS leads (
        id $id,
        email VARCHAR(255) NOT NULL UNIQUE,
        role_tag VARCHAR(80) NOT NULL DEFAULT '',
        source VARCHAR(40) NOT NULL DEFAULT '',
        created_at VARCHAR(32) NOT NULL
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
        return 'License key not found.';
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
        . "It works for up to {$license['seats']} people until $until.\n\n"
        . "1. Each person installs the Workflow Shadow Audit extension, clicks \"Have a Business key?\" and pastes this key.\n"
        . "2. Everyone works normally for a few days, then clicks \"Send to my Business Portal\".\n"
        . "3. Sign in to your portal with this email and key, fill in your business profile, and get your report:\n    "
        . site_url('portal.php') . "\n\n"
        . "Keep this key private — anyone with it can see your company's portal.\n\n— $brand\n"
    );
    send_mail(
        (string) cfg('owner_email'),
        "New $plan: " . ($license['company'] ?: $license['email']),
        "New purchase.\n\nPlan: $plan ({$license['seats']} people)\nCompany: {$license['company']}\n"
        . "Email: {$license['email']}\nAccess until: $until\n\nAdmin: " . site_url('admin.php?view=' . (int) $license['id']) . "\n"
    );
}

/** Keep Results Tracking in sync with its Stripe subscription. */
function sync_subscription_status(array $subscription): void
{
    if (empty($subscription['id'])) {
        return;
    }
    $active = in_array($subscription['status'] ?? '', ['active', 'trialing', 'past_due'], true);
    db()->prepare('UPDATE licenses SET monitor_status = ?, updated_at = ? WHERE monitor_subscription_id = ?')
        ->execute([$active ? 'active' : 'canceled', now_iso(), $subscription['id']]);
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
    $stmt = db()->prepare('SELECT * FROM devices WHERE license_id = ? ORDER BY first_seen');
    $stmt->execute([$licenseId]);
    return $stmt->fetchAll();
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
    ];
}

function store_audit(int $licenseId, array $clean): int
{
    db()->prepare('INSERT INTO audits (license_id, role_tag, total_minutes, payload, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([
            $licenseId,
            $clean['auditMetadata']['roleTag'],
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
            $domains[$k] ??= ['domain' => $k, 'activeMinutes' => 0.0, 'visitCount' => 0, 'roles' => []];
            $domains[$k]['activeMinutes'] += (float) $u['activeMinutes'];
            $domains[$k]['visitCount']    += (int) $u['visitCount'];
            $domains[$k]['roles'][$role] = true;
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
        'website'          => ['Website', 'url', 'https://acme.com'],
        'industry'         => ['Industry', 'text', 'e.g. Real estate, Med spa, E-commerce'],
        'team_size'        => ['Team size', 'text', 'e.g. 12'],
        'software_budget'  => ['Monthly software spend (approx.)', 'text', 'e.g. $3,500'],
        'hourly_cost'      => ['Average loaded hourly labor cost', 'text', 'e.g. $40'],
        'facebook'         => ['Facebook page', 'url', 'https://facebook.com/…'],
        'instagram'        => ['Instagram', 'url', 'https://instagram.com/…'],
        'linkedin'         => ['LinkedIn company page', 'url', 'https://linkedin.com/company/…'],
        'tiktok'           => ['TikTok', 'url', 'https://tiktok.com/@…'],
        'x_twitter'        => ['X / Twitter', 'url', 'https://x.com/…'],
        'youtube'          => ['YouTube', 'url', 'https://youtube.com/@…'],
        'google_business'  => ['Google Business Profile', 'url', 'https://g.page/…'],
        'other_channels'   => ['Other channels (email list, newsletter, podcast…)', 'textarea', ''],
        'departments'      => ['Departments & headcount', 'textarea', 'Sales: 4, Ops: 3, Admin: 2'],
        'current_tools'    => ['Software you pay for (name + monthly cost if known)', 'textarea', 'HubSpot $800, Slack $120, …'],
        'lead_sources'     => ['How do customers find you today?', 'textarea', ''],
        'bottlenecks'      => ['Biggest bottlenecks / repetitive tasks', 'textarea', ''],
        'goals'            => ['Goals for the next 90 days', 'textarea', ''],
    ];
}

function load_profile(int $licenseId): array
{
    $stmt = db()->prepare('SELECT data FROM profiles WHERE license_id = ?');
    $stmt->execute([$licenseId]);
    $raw = $stmt->fetchColumn();
    $data = $raw ? (json_decode((string) $raw, true) ?: []) : [];
    $out = [];
    foreach (profile_fields() as $key => $_) {
        $out[$key] = (string) ($data[$key] ?? '');
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
        $clean[$key] = $val;
    }
    $pdo  = db();
    $json = json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $now  = now_iso();
    $upd  = $pdo->prepare('UPDATE profiles SET data = ?, updated_at = ? WHERE license_id = ?');
    $upd->execute([$json, $now, $licenseId]);
    if ($upd->rowCount() === 0) {
        $exists = $pdo->prepare('SELECT 1 FROM profiles WHERE license_id = ?');
        $exists->execute([$licenseId]);
        if (!$exists->fetchColumn()) {
            $pdo->prepare('INSERT INTO profiles (license_id, data, updated_at) VALUES (?, ?, ?)')
                ->execute([$licenseId, $json, $now]);
        }
    }
    return $clean;
}

/* ------------------------------------------------------------------ */
/* AI Gap Analysis prompt (business edition)                           */
/* ------------------------------------------------------------------ */

function build_business_prompt(array $license, array $profile, array $aggregate): string
{
    $labels = profile_fields();
    $profileLines = [];
    foreach ($profile as $key => $val) {
        if ($val !== '') {
            $profileLines[] = '- ' . $labels[$key][0] . ': ' . str_replace("\n", ' / ', $val);
        }
    }
    $profileText = $profileLines ? implode("\n", $profileLines) : '- (Business profile not filled in yet)';
    $company = $profile['company'] ?: ($license['company'] ?: 'the client');

    $telemetry = json_encode($aggregate, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return <<<PROMPT
You are an AI operations consultant writing a report for the owner of {$company}. The owner is busy and not
technical: use plain words, short sentences and numbers first. Keep the whole report under about 1,200 words.

## Inputs
1. BUSINESS PROFILE: supplied by the owner (social channels, team, tools, goals).
2. TEAM TELEMETRY: combined from private, read-only browser audits across the team. It contains only website
   names, active minutes, visit counts, repeated 4-site patterns and quick tab jumps (less than 30 seconds apart).
   No page content or personal data.

## Rules
- Use only the data provided. When a number is missing, say it is an estimate and show the simple math.
- Use the hourly labor cost from the profile if given; otherwise assume \$45/hour and 21.7 workdays/month.
- Ignore personal (non-work) websites in the cost math.
- Format in Markdown: ## headings, short bullet lists, numbered steps, and at most two small tables.

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
## 6. Software you may not need
Overlapping or barely used tools and the likely monthly savings (estimates).
## 7. Your next 30 days
Week 1 to Week 4, one or two actions each.

## BUSINESS PROFILE
{$profileText}

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
