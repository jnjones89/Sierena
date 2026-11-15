# Workflow Shadow Audit — Business server (Hostinger + Stripe)

Plain PHP 8 + MySQL. No Composer, no Node. Runs on any Hostinger web hosting plan.

## How the paid flow works

```
Extension "Upgrade to Business" ──► /business/ (pricing page)
        ──► checkout.php ──► Stripe Checkout (customer pays)
        ──► success.php  (verifies payment with Stripe, creates AUDIT-XXXX-XXXX-XXXX-XXXX key, emails it)
        ──► customer pastes key in extension ──► api/validate.php confirms it's active
        ──► "Sync to Business Portal" ──► api/sync.php stores the audit
        ──► portal.php: company profile + social media + team audits + AI Agent Gap prompt
Stripe webhook.php keeps the key active/inactive when a subscription renews, fails or is canceled.
You see every customer, profile and lead in admin.php.
```

## Setup (about 20 minutes)

### 1. Stripe
1. Stripe Dashboard → **Product catalog → Add product** "Workflow Shadow Audit Business", add a recurring price (e.g. $97/month). Copy the **Price ID** (`price_…`).
2. **Developers → API keys** → copy the **Secret key** (`sk_test_…` while testing).
3. **Developers → Webhooks → Add endpoint**
   - URL: `https://YOURDOMAIN.com/business/webhook.php`
   - Events: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `customer.subscription.updated`, `customer.subscription.deleted`
   - Copy the **Signing secret** (`whsec_…`).
4. **Settings → Billing → Customer portal** → turn it on (lets customers update cards / cancel from the portal's "Manage billing" button).

### 2. Hostinger
1. hPanel → **Databases → MySQL Databases** → create a database + user. Note the name, user and password (they look like `u123456789_swa`).
2. hPanel → **Emails** → create a mailbox such as `audit@yourdomain.com` (used as the sender).
3. hPanel → **File Manager** → open `public_html` → create a folder `business` → upload everything in this folder into it (keep the `includes`, `api`, `assets` sub-folders and the `.htaccess` files).
4. Copy `includes/config.sample.php` to **`swa-config.php` one level above `public_html`** (most secure), or to `includes/config.php`. Fill in every value.
5. Make sure SSL is on (hPanel → **Security → SSL**). Stripe requires https.

Tables are created automatically on the first request — no SQL import needed.

### 3. Extension
In `workflow-shadow-audit/popup.js`, set:
```js
const BUSINESS_BASE_URL = 'https://YOURDOMAIN.com/business';
```
Reload the extension in `chrome://extensions`.

### 4. Test with Stripe test mode
1. Visit `https://YOURDOMAIN.com/business/`, enter an email + company, pay with card `4242 4242 4242 4242`, any future date, any CVC.
2. You land on the success page with your key. Paste it into the extension → it shows **Business**.
3. Click **Sync to Business Portal**, then **Open Business Portal** and sign in with the same email + key.
4. Open `/business/admin.php` with your admin password — you'll see the customer.
5. In Stripe, cancel the test subscription → the key stops working in the extension within 12 hours (immediately on next unlock/sync).

When everything works, swap `sk_test_` / `whsec_` / `price_` values for your **live** ones (the live webhook has its own signing secret).

## Tiers
| | Free | Lite (work email) | Business (paid key) |
|---|---|---|---|
| Live dashboard | ✅ | ✅ | ✅ |
| JSON export + AI prompt | — | ✅ | ✅ |
| Sync to company portal | — | — | ✅ |
| Webhook (Make / n8n) | — | — | ✅ |
| Business profile, social media, team AI Agent Gap Map | — | — | ✅ |

## Files
- `index.php` pricing/upgrade page · `checkout.php` · `success.php` · `webhook.php` · `billing.php`
- `portal.php` customer portal · `admin.php` your dashboard
- `api/validate.php`, `api/sync.php`, `api/lead.php` — called by the extension
- `includes/` — library + config (blocked from the web by `.htaccess`)
