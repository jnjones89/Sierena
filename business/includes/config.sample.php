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

    // ---- Plans: all monthly subscriptions (cancel any time) ----
    // Create one RECURRING monthly price per plan in Stripe (Product catalog) and paste its Price ID.
    // "people" = how many computers can use the key. "solo" = one-person wording in the portal.
    // "final_plan" = whether a final plan written by your team is included (default true).
    // Access lasts while the subscription is active; canceling ends it at the end of the paid month.
    'plans' => [
        'solo'    => ['name' => 'Solo Audit',         'people' => 1,  'price_label' => '$97 / month',  'stripe_price_id' => 'price_REPLACE_ME', 'solo' => true, 'final_plan' => false],
        'starter' => ['name' => 'Starter Team Audit', 'people' => 5,  'price_label' => '$197 / month', 'stripe_price_id' => 'price_REPLACE_ME'],
        'team'    => ['name' => 'Team Audit',         'people' => 15, 'price_label' => '$397 / month', 'stripe_price_id' => 'price_REPLACE_ME'],
        'company' => ['name' => 'Company Audit',      'people' => 50, 'price_label' => '$697 / month', 'stripe_price_id' => 'price_REPLACE_ME'],
    ],
    // Days of access given at checkout, as a safety net until Stripe's subscription events arrive.
    'audit_access_days' => 31,

    // Billing terms shown on the pricing page (buyers must tick a box to agree), the success page and Help.
    'billing_terms' => 'All payments are final. Cancel any time to stop future charges. Your access continues until the end of the month you paid for.',

    // Where customers book a call to have the agents built (Calendly, GoHighLevel, etc.).
    'booking_url' => 'https://sirenaflow.com/#contact',

    // Your extension's Chrome Web Store link (shown on the team invite page). Leave empty until it's published.
    'extension_url' => '',

    // ---- Stripe (Dashboard → Developers → API keys / Webhooks) ----
    'stripe_secret_key'     => 'sk_test_REPLACE_ME',
    'stripe_webhook_secret' => 'whsec_REPLACE_ME',

    // ---- Claude (instant AI report) — console.anthropic.com → API keys ----
    // Leave empty to turn the instant report off (your team can still post the plan from admin.php).
    'anthropic_api_key' => '',
    'anthropic_model'   => 'claude-opus-5-5',
    // low | medium | high — higher = more thorough but slower. "high" usually takes 1–2 minutes.
    'anthropic_effort'  => 'high',
    // Limits on instant AI reports (only paying customers can generate them; free users never use Claude).
    'reports_per_day'      => 5,   // per customer, per day
    'reports_per_month'    => 5,   // per subscriber, rolling 30 days

    // ---- GoHighLevel (optional) ----
    // Each destination is a sub-account with its own Private Integration token
    // (GHL sub-account → Settings → Private Integrations; scopes: contacts.write, locations/customFields.readonly + .write).
    // 'events' => 'all', or a list. Leave a token empty to switch that destination off.
    // Events (tags): free_audit (swa-free-audit), team_audit_purchased (swa-team-audit-purchased + swa-plan-<plan>),
    // first_audit_received, instant_report_ready, final_plan_sent, results_tracking_on/_canceled,
    // wants_help, profile_updated (fields only, no tag).
    'ghl' => [
        'sirena' => ['api_token' => '', 'location_id' => 'sR58k68dGcC4kxfeJudZ', 'events' => 'all'],
        'alja'   => ['api_token' => '', 'location_id' => 'xAlVOKt0OfFGc5f5WPAh', 'events' => ['instant_report_ready', 'final_plan_sent', 'wants_help']],
    ],

    // ---- Client CRM check (customers connect their own GoHighLevel, read-only) ----
    // Encrypts their tokens. Any long random string; keep it secret and don't change it later
    // (customers would have to reconnect). If empty, a key is derived from admin_password.
    'data_key'   => '',
    // How many recent new leads to check for reply speed and follow-up.
    'crm_sample' => 40,

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
