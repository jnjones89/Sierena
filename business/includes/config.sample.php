<?php
/*
 * Workflow Shadow Audit — Business server configuration
 *
 * 1. Copy this file to config.php (same folder), OR — more secure — to a file
 *    named swa-config.php placed ONE LEVEL ABOVE public_html on Hostinger
 *    (e.g. /home/u123456789/domains/sirenaflow.com/swa-config.php).
 * 2. Fill in every value below. Never commit the real file to Git.
 */

return [
    // Public URL of this /business folder, no trailing slash.
    'site_url'   => 'https://sirenaflow.com/business',
    'brand_name' => 'SirenaFlow',

    // ---- One-time Team Audits, priced by team size ----
    // Create one ONE-TIME price per plan in Stripe (Product catalog) and paste its Price ID.
    // "people" = how many teammates' extensions can use the key.
    'plans' => [
        'starter' => ['name' => 'Starter Team Audit', 'people' => 5,  'price_label' => '$297', 'stripe_price_id' => 'price_REPLACE_ME'],
        'team'    => ['name' => 'Team Audit',         'people' => 15, 'price_label' => '$497', 'stripe_price_id' => 'price_REPLACE_ME'],
        'company' => ['name' => 'Company Audit',      'people' => 50, 'price_label' => '$997', 'stripe_price_id' => 'price_REPLACE_ME'],
    ],
    // How long a one-time audit stays open for tracking, syncing and the portal.
    'audit_access_days' => 30,

    // ---- Optional monthly Results Tracking (after the audit) ----
    // Create a RECURRING monthly price in Stripe and paste its Price ID.
    'monitoring' => [
        'name'            => 'Results Tracking',
        'price_label'     => '$97 / month',
        'stripe_price_id' => 'price_REPLACE_ME',
    ],

    // Where customers book a call to have the agents built (Calendly, GoHighLevel, etc.).
    'booking_url' => 'https://sirenaflow.com/#contact',

    // ---- Stripe (Dashboard → Developers → API keys / Webhooks) ----
    'stripe_secret_key'     => 'sk_test_REPLACE_ME',
    'stripe_webhook_secret' => 'whsec_REPLACE_ME',

    // ---- Claude (instant AI report) — console.anthropic.com → API keys ----
    // Leave empty to turn the instant report off (your team can still post the plan from admin.php).
    'anthropic_api_key' => '',
    'anthropic_model'   => 'claude-opus-5-5',
    // low | medium | high — higher = more thorough but slower. "high" usually takes 1–2 minutes.
    'anthropic_effort'  => 'high',
    // Instant reports a customer can generate per day.
    'reports_per_day'   => 5,

    // ---- MySQL (Hostinger hPanel → Databases → MySQL Databases) ----
    'db_dsn'  => 'mysql:host=localhost;dbname=u123456789_swa;charset=utf8mb4',
    'db_user' => 'u123456789_swa',
    'db_pass' => 'REPLACE_ME',

    // ---- Email ----
    // Must be a mailbox on your own domain (hPanel → Emails) or mail may be rejected.
    'mail_from'   => 'audit@sirenaflow.com',
    // Where new-customer, report and intake notifications are sent.
    'owner_email' => 'you@sirenaflow.com',

    // ---- Admin dashboard (/business/admin.php) ----
    // At least 12 characters. Use a long random password.
    'admin_password' => 'REPLACE_WITH_A_LONG_RANDOM_PASSWORD',
];
