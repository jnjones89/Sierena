# Workflow Shadow Audit — Team Audit server (Hostinger + Stripe + Claude)

Plain PHP 8.1+ and MySQL. Runs on Hostinger web hosting. Composer packages (Anthropic SDK + Guzzle) are already
included in `vendor/`, so you only upload the folder.

## How it works

```
Free extension user ──► "Audit my whole team" ──► /business/ (pricing: Starter / Team / Company, priced by team size)
   ──► checkout.php ──► Stripe (one-time payment)
   ──► success.php: verifies payment, creates the team key AUDIT-XXXX-XXXX-XXXX-XXXX (also emailed)
   ──► the owner sends the invite message from the portal's "Your team" page (link to join.php)
   ──► each teammate pastes the key in the extension (one seat per computer, up to the plan's limit);
       the "Your team" page shows who's connected and who has sent in their audit
   ──► "Send to my Business Portal" ──► portal.php
         • business profile (industry, social media, goals)
         • Your tools: software they pay for, with monthly cost and seats, matched to what the audit saw
           (shows "Not seen" for paid tools nobody opened, so the report can show real savings)
         • Back-Office Check: processes & SOPs, follow-up, contracts, compliance and data security,
           with extra checks per industry (insurance, lending, business funding, credit repair, real estate, healthcare,
           legal, home services, e-commerce, agencies) and how each gap can be automated
         • "Get my instant report" — Claude writes it in 1–2 minutes
         • your team reviews it in admin.php and publishes the final plan (customer gets an email)
         • "Book a call to build these agents" → your booking link (audit price credited toward the build)
   ──► optional: "Turn on Results Tracking" ($/month subscription) keeps the key working after the
       audit window and shows month-by-month progress.
```

## Plans
| | Free (extension) | Team Audit (one-time) | Results Tracking (monthly, optional) |
|---|---|---|---|
| People | 1 | 5 / 15 / 50 by plan | same as the plan |
| How long | One 5-hour audit | 30 days (set `audit_access_days`) | as long as the subscription is active |
| Results in the extension | ✅ after work email | ✅ live | ✅ live |
| Company portal + business profile | — | ✅ | ✅ |
| Back-Office Check for their industry | — | ✅ | ✅ |
| Instant AI report + final plan from your team | — | ✅ | ✅ |
| Month-by-month progress | — | — | ✅ |

## Setup

### 1. Stripe
1. **Product catalog → Add product** for the audits with **one-time prices**: Solo $97, Starter Team $397, Team $797, Company $1,497. Copy each Price ID.
2. Add a product "Results Tracking" with **three recurring monthly prices**: $97 (Solo), $197 (up to 15 people), $297 (up to 50). Copy each Price ID.
3. **Developers → API keys** → copy the Secret key.
4. **Developers → Webhooks → Add endpoint** `https://YOURDOMAIN.com/business/webhook.php` with events
   `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `customer.subscription.updated`,
   `customer.subscription.deleted`. Copy the Signing secret.
5. **Settings → Billing → Customer portal** → turn it on (customers manage Results Tracking and invoices).

### 2. Claude (instant report) — optional
Leave `anthropic_api_key` empty to run with **AI off**: no Anthropic cost. Customers get a "Get an AI report draft now"
card (copy the prompt into their own ChatGPT or Claude), Team plans get the final plan from your team, and admin.php
marks customers "Needs final plan" as soon as audits arrive. The free audit never uses Anthropic either way.

1. console.anthropic.com → **API keys** → create a key and add a few dollars of credit. Each report costs a few cents.
2. Paste it as `anthropic_api_key`. Leave it empty to turn instant reports off — you can still publish plans from admin.php.

### 3. Hostinger
1. hPanel → **Databases → MySQL Databases** → create a database + user.
2. hPanel → **Emails** → create `audit@yourdomain.com` (the sender).
3. Zip this `business` folder on your computer, then in hPanel **File Manager** open `public_html`, upload the zip and
   **Extract** it (much faster than uploading thousands of `vendor/` files one by one). You should end up with `public_html/business/`.
4. Copy `includes/config.sample.php` to **`swa-config.php` one level above `public_html`** and fill in every value
   (plans' Price IDs, Results Tracking Price ID, Stripe keys, Anthropic key, database, emails, booking link, admin password).
5. hPanel → **Advanced → PHP Configuration**: PHP 8.1 or newer, and set `max_execution_time` to **300** so the
   instant report has time to finish.
6. Make sure SSL is on (hPanel → Security → SSL).

Database tables are created automatically on the first visit.

### 4. Extension
In `workflow-shadow-audit/popup.js` set `const BUSINESS_BASE_URL = 'https://YOURDOMAIN.com/business';` and reload it.

### 5. Test (Stripe test mode, card 4242 4242 4242 4242)
1. Buy the Starter plan on `/business/` → copy the key from the success page.
2. Paste it in the extension (**Have a team key?**) → it shows "Starter Team Audit · until …".
3. Seed some activity, click **Send to my Business Portal**, sign in to `/business/portal.php`.
4. Fill the business profile (pick an industry) and the Back-Office Check → **Get my instant AI report**.
5. Open `/business/admin.php` → the customer shows "Needs follow-up" → publish the final plan.
6. Click **Turn on Results Tracking** in the portal and subscribe → status shows "Monthly".
Then switch every `sk_test_` / `whsec_` / `price_` value to live mode.

## Admin tools (`admin.php`)
- Customers with plan, status, audits and report status ("Needs follow-up" / "Plan sent").
- Per customer: Back-Office Check scores and gaps, publish the final plan (prefilled with the AI draft), extend the audit 30 days, free all seats,
  see connected browsers, profile, team data and month-by-month progress.
- Free audit leads.

## Solo Audit, guarantee, survey
- **Solo Audit** (plan `solo` in config): one computer, no time limit, free-audit hours carry over. The portal hides team pages.
- **Guarantee:** set `guarantee` in config. Customers ask from Help in the portal; you get an email and refund in Stripe.
- **Survey:** three questions after the free results (extension), after a team member sends (extension), and after the owner's report (portal). Answers and the "Is the audit doing its job?" scoreboard are on `admin.php`.

## Client journey (7 stations) and handoff tests
- Portal **Client journey** page, one combined flow: lead capture, speed to lead, follow-up, booking and conversion,
  onboarding and handoff, delivery, retention and referral. Each station has a few facts, a **Step by step** list of its
  links (marked Automatic / By hand / Sometimes / Doesn't happen, with industry-specific links for funding, lending,
  insurance, credit repair, healthcare), and three questions (process, owner, automation). Stations are in
  `includes/stations.php`, links in `includes/chains.php` (each link names its station).
- The overview shows the 7 station tiles and the full step-by-step strip from first lead to referral.
- The background audit keeps running: evidence from the team's audits appears on the matching link
  (e.g. "After Zoom calls, people went straight to email 9 of 11 times").
- On `admin.php`, each customer has **Handoff tests**. Results show to the customer as "Tested by our team" and override
  guesses. The report's section 7 covers each station; section 8 is "Where things fall through the cracks".

## Client CRM check (GoHighLevel, optional)
- On the Client journey page, customers can **Connect GoHighLevel** with a read-only Private Integration token
  (scopes: contacts, conversations, messages, calendars, events, opportunities, all `.readonly`).
- We check the last 90 days: new contacts and sources, speed to lead (sample of 40 new leads: first reply time,
  % never answered, % within 5 minutes), follow-ups per lead and channels, show rate, no-show rebooking, close rate
  and open deals stuck 30+ days.
- Only the numbers are kept (in the profile). Names, emails, phone numbers and message text are never saved.
  The token is encrypted with `data_key` (set it in config). Disconnect deletes the token and the numbers.
- The numbers show as **From your CRM** on the matching stations and steps, can only make a status worse,
  appear on `admin.php`, and go into the report prompt as "FROM THEIR CRM". Code: `includes/crm.php`.
- **Before → Now:** every check is kept as a small snapshot (numbers only, up to 36). The first check is the baseline.
  The CRM page, the Numbers page and `admin.php` show Before → Now (reply speed, never-replied, follow-ups, show rate,
  no-show rebooking, close rate, stuck deals). Admin has "Copy for an email" to send the results to the client.

## GoHighLevel (your own Sirena Flow account)
See `GHL-SETUP.md`: connect tokens, click **Create GHL fields** on the admin page, then build the tag-triggered workflows.

## Wording
`MESSAGING.md` lists the exact names, buttons and promise lines used everywhere (extension, pages, emails).
Industry lists live in `includes/industries.php` and `../workflow-shadow-audit/industries.js` — keep them identical.

## Files
- `index.php` pricing · `checkout.php` one-time audit · `success.php` · `monitor.php` Results Tracking · `webhook.php` · `billing.php`
- `portal.php` customer portal (Home steps · Your business · Questions · Your team · Report · Numbers) · `join.php` team invite page · `admin.php` your dashboard
- `api/validate.php`, `api/sync.php`, `api/lead.php` — called by the extension
- `includes/` library + config (`industries.php` questions per industry, `tools.php` tool names, generated from the extension's `insights.js`), `vendor/` Composer packages (both blocked from the web)
