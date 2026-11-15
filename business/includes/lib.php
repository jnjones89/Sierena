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
        $pdo->query('SELECT 1 FROM rate_hits LIMIT 1');
        return; // schema already present
    } catch (PDOException $e) {
        // create below
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
        created_at VARCHAR(32) NOT NULL,
        updated_at VARCHAR(32) NOT NULL
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

function license_is_active(?array $license): bool
{
    return $license !== null && $license['status'] === 'active';
}

/**
 * Create (once) the license for a paid Checkout Session.
 * Safe to call from both the success page and the webhook — idempotent on session id.
 */
function fulfill_checkout_session(array $session): ?array
{
    $paid = ($session['status'] ?? '') === 'complete'
        && in_array($session['payment_status'] ?? '', ['paid', 'no_payment_required'], true);
    if (!$paid || empty($session['id'])) {
        return null;
    }

    $pdo  = db();
    $find = $pdo->prepare('SELECT * FROM licenses WHERE stripe_session_id = ?');
    $find->execute([$session['id']]);
    if ($existing = $find->fetch()) {
        return $existing;
    }

    $email   = (string) ($session['customer_details']['email'] ?? $session['customer_email'] ?? '');
    $company = mb_substr((string) ($session['metadata']['company'] ?? ''), 0, 255);
    $now     = now_iso();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $key = generate_license_key();
        try {
            $pdo->prepare('INSERT INTO licenses
                (license_key, email, company, status, stripe_session_id, stripe_customer_id, stripe_subscription_id, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $key,
                    strtolower($email),
                    $company,
                    'active',
                    $session['id'],
                    is_string($session['customer'] ?? null) ? $session['customer'] : null,
                    is_string($session['subscription'] ?? null) ? $session['subscription'] : null,
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

function notify_new_license(array $license): void
{
    $brand = (string) cfg('brand_name');
    send_mail(
        $license['email'],
        "Your $brand Workflow Shadow Audit Business key",
        "Thanks for upgrading to Workflow Shadow Audit Business.\n\n"
        . "Your license key:\n\n    {$license['license_key']}\n\n"
        . "1. Open the Workflow Shadow Audit extension and paste the key into the unlock box.\n"
        . "2. Sign in to your Business Portal with this email and key:\n    " . site_url('portal.php') . "\n"
        . "3. Fill in your business profile (social media, tools, goals) and sync your team's audits.\n\n"
        . "Keep this key private — anyone with it can access your company's portal.\n\n— $brand\n"
    );
    send_mail(
        (string) cfg('owner_email'),
        'New Business customer: ' . ($license['company'] ?: $license['email']),
        "New Workflow Shadow Audit Business license.\n\n"
        . "Company: {$license['company']}\nEmail: {$license['email']}\nCreated: {$license['created_at']}\n\n"
        . 'Admin: ' . site_url('admin.php') . "\n"
    );
}

/** Keep license status in sync with the Stripe subscription. */
function sync_subscription_status(array $subscription): void
{
    if (empty($subscription['id'])) {
        return;
    }
    $active = in_array($subscription['status'] ?? '', ['active', 'trialing', 'past_due'], true);
    db()->prepare('UPDATE licenses SET status = ?, updated_at = ? WHERE stripe_subscription_id = ?')
        ->execute([$active ? 'active' : 'inactive', now_iso(), $subscription['id']]);
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
You are a senior Operational AI Architect preparing a client-facing AI Operations Gap Analysis for {$company}.

## Inputs
1. BUSINESS PROFILE — supplied by the client (social channels, team, tools, goals).
2. TEAM TELEMETRY — aggregated from read-only Workflow Shadow Audit browser sessions across roles. It contains
   only domain names, active (non-idle) minutes, visit counts, repeated 4-step domain loops and rapid context
   switches (switches < 30 seconds apart). No page content or personal data.

## Rules
- Use only the data provided. When a number is missing, state a labeled assumption and show the formula.
- If the profile gives an hourly labor cost, use it; otherwise assume \$45/hour fully loaded, 21.7 workdays/month.
- Classify each domain: Core SaaS, Communication, Marketing/Social, Internal, Search/Reference, AI Tool, Personal.
  Exclude Personal from cost math.
- Executive tone: numbers first, plain language, no hype.

## Deliver these sections
### 1. Executive Summary (5 bullets max)
### 2. Business & Channel Snapshot
Assess each social/marketing channel listed: is it active, what it is likely costing in staff time (cross-reference
telemetry), and what is missing (e.g. no Google Business Profile, no lead capture, no review engine).
### 3. SaaS Consolidation & Shelfware Savings
Overlapping tools, low-usage paid tools, keep/retire recommendations, estimated monthly savings range.
### 4. Payroll Waste Math
Time lost to context switching and repeated loops: formula, low/high case, monthly & annual cost per role and
for the full team size given.
### 5. AI Agent Gap Map
A ranked table of the AI agents/automations this business should create to close the gaps. For each:
Agent name | Department | Problem it solves (cite telemetry or profile) | Trigger | Systems connected |
Human checkpoint | Build complexity (Low/Med/High) | Est. hours saved/month | Est. monthly value.
Include at least: one marketing/social-media agent, one lead-response/sales agent, one operations/admin agent,
and any others the data justifies.
### 6. 30-Day AI Infrastructure Roadmap
Week 1–4: quick wins, tool retirements, agents to build first, integrations (Make.com / n8n / native APIs),
owners and success metrics to re-measure with the next audit.
### 7. Assumptions & Data Needed
What extra data would sharpen the estimates.

## BUSINESS PROFILE
{$profileText}

## TEAM TELEMETRY (JSON)
```json
{$telemetry}
```
PROMPT;
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
