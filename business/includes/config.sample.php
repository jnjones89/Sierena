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

    // Shown on the pricing page. Must match the Stripe Price below.
    'price_label' => '$97 / month',

    // ---- Stripe (Dashboard → Developers → API keys / Webhooks) ----
    // Use sk_test_... while testing, then switch to sk_live_... to go live.
    'stripe_secret_key'     => 'sk_test_REPLACE_ME',
    // From Dashboard → Developers → Webhooks → your endpoint → Signing secret.
    'stripe_webhook_secret' => 'whsec_REPLACE_ME',
    // Dashboard → Product catalog → your Business product → Price ID.
    'stripe_price_id'       => 'price_REPLACE_ME',
    // 'subscription' for a recurring price, 'payment' for a one-time price.
    'stripe_mode'           => 'subscription',

    // ---- MySQL (Hostinger hPanel → Databases → MySQL Databases) ----
    'db_dsn'  => 'mysql:host=localhost;dbname=u123456789_swa;charset=utf8mb4',
    'db_user' => 'u123456789_swa',
    'db_pass' => 'REPLACE_ME',

    // ---- Email ----
    // Must be a mailbox on your own domain (hPanel → Emails) or mail may be rejected.
    'mail_from'   => 'audit@sirenaflow.com',
    // Where new-customer and intake notifications are sent.
    'owner_email' => 'you@sirenaflow.com',

    // ---- Admin dashboard (/business/admin.php) ----
    // At least 12 characters. Use a long random password.
    'admin_password' => 'REPLACE_WITH_A_LONG_RANDOM_PASSWORD',
];
