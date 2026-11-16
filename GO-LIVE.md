# Go-live checklist: Workflow Shadow Audit

Everything is built and tested. These are the steps that need your accounts. Do them in this order.
Total: about half a day of setup, then the Chrome Web Store review (usually a few days).

## 0. Build the upload files (2 minutes)
```
tools/build-release.sh https://sirenaflow.com/business
```
This creates `dist/business-<version>.zip` (server) and `dist/workflow-shadow-audit-<version>.zip` (Chrome Web Store),
with the extension already pointing at your server.

## 1. Stripe, in test mode first (30 minutes)
See `business/README.md` → Setup → 1. Stripe.
- [ ] Product "Workflow Shadow Audit" with 4 **recurring monthly** prices: Solo $97, Starter $197, Team $397, Company $697
- [ ] Webhook to `https://sirenaflow.com/business/webhook.php` with the 4 events listed in the README
- [ ] Customer portal turned on, with cancel allowed
- [ ] Copy: secret key, webhook signing secret, 4 Price IDs

## 2. Hostinger (30 minutes)
See `business/README.md` → Setup → 3. Hostinger.
- [ ] MySQL database + user
- [ ] Mailbox `audit@sirenaflow.com`
- [ ] Upload `dist/business-<version>.zip` to `public_html` and Extract → `public_html/business/`
- [ ] Copy `business/includes/config.sample.php` to `swa-config.php` **one level above** `public_html`, and fill it in:
  - Stripe keys and the 4 Price IDs
  - Database, `mail_from`, `owner_email`, `booking_url`
  - `admin_password`: a long random password
  - `data_key`: a long random string (encrypts clients' CRM tokens; never change it after launch)
  - Tip: hPanel's terminal or any computer: `openssl rand -base64 32` makes a good value for each
- [ ] PHP 8.1 or newer, `max_execution_time` 300, SSL on
- [ ] Open `https://sirenaflow.com/business/`: the pricing page should load

## 3. Test it end to end (30 minutes, Stripe test mode, card 4242 4242 4242 4242)
- [ ] Load the extension unpacked (chrome://extensions → Developer mode → Load unpacked → the unzipped extension)
- [ ] Free audit: start, browse, check the bots feed, email gate, results; the lead shows on `admin.php`
- [ ] Buy a Solo and a Team Audit; the key works in the extension; Send to my Business Portal
- [ ] Portal: business profile, tools, questions, Client journey (all 7 stations), report
- [ ] **CRM check on your own account:** connect Sirena Flow's GoHighLevel from the Client journey page with a read-only token. You'll see your own real numbers, and it confirms the connection works.
- [ ] `admin.php`: the customer, handoff tests, publish a final plan
- [ ] Checkout requires ticking "all payments are final"; cancel the subscription in Stripe → portal shows **Restart my subscription**

## 4. GoHighLevel (1–2 hours)
See `business/GHL-SETUP.md`.
- [x] Sirena Flow: the 14 SWA fields are created
- [ ] Sirena Flow: Private Integration token (contacts.write, customFields read + write) → `ghl.sirena.api_token`
- [ ] **Before adding the token:** check your existing workflows. Any that start on "Contact Created" or on any tag
      will also catch audit leads. By their names, check these 5 first (GHL wouldn't let me read their triggers):
      - SF Ownership | New Contact → Chesoni
      - SF Pipeline | New Contact → New Prospect Deal (would make a deal for every free-audit lead)
      - SF Internal Online Lead Intake
      - SF Tag | Inbound Lead
      - SF Internal Warm Lead Nurture
      If one starts on new contacts, add the filter "Tags does not contain swa-free-audit" (or decide you want audit leads in it).
- [x] The SWA emails are created: Marketing → Emails → Templates → folder **Workflow Shadow Audit** (SWA 01 – SWA 12).
      Set the From name and From email on each, and send yourself a test.
- [ ] Build the 8 SWA workflows from `GHL-SETUP.md` (they start on the `swa-…` tags) and pick the matching SWA template in each email step
- [ ] ALJA: token → `ghl.alja.api_token`, then `admin.php` → GoHighLevel → **Create GHL fields**

## 5. Chrome Web Store (1 hour, then review)
See `store/STORE-LISTING.md`: every field is written out, and the screenshots are in `store/`.
- [ ] Developer account at chrome.google.com/webstore/devconsole (one-time $5 fee)
- [ ] Upload `dist/workflow-shadow-audit-<version>.zip`, fill in the listing and the Privacy tab
- [ ] Submit for review (tip: "Unlisted" first for a soft launch)
- [ ] When approved: put the store link in `extension_url` in `swa-config.php`

## 6. Go live
- [ ] Stripe: switch to live mode, make the same products and webhook there, replace every `sk_test_` / `whsec_` / `price_` value
- [ ] Optional: `anthropic_api_key` for instant AI reports (leave empty and your team writes the plans)
- [ ] Buy your own Solo Audit with a real card, then refund it from Stripe, to confirm live payments work

## First week after launch
- Check `admin.php` daily: "Needs attention", GHL sends (Sent / Failed), survey answers.
- Replace the placeholder icons with your logo when you have one (`workflow-shadow-audit/icons/`, 16/32/48/128 px),
  raise `version` in `manifest.json`, rebuild and upload.
