# GoHighLevel setup — Workflow Shadow Audit

The server already sends every event to GHL. You only need to connect it and build the workflows.
Everything goes into **Sirena Flow**. **ALJA Solutions** only receives build-ready events
(`instant_report_ready`, `final_plan_sent`, `wants_help`).

## 1. Connect (about 10 minutes)
1. In each sub-account: **Settings → Private Integrations → Create**. Scopes: `contacts.write`,
   `locations/customFields.readonly`, `locations/customFields.write`. Copy the token.
2. Paste the tokens into `ghl` in your config file (`sirena` and, if you want the handoff, `alja`).
3. Open `/business/admin.php` → **GoHighLevel** → **Create GHL fields**. This adds the SWA fields
   (SWA Plan, SWA Industry, SWA Bottleneck, SWA Recommended Agent, SWA Audit Ends, SWA Portal Link, …).
   **Sirena Flow: done.** All 14 SWA fields were created on 2026-11-16 (the button will find them and skip).
   ALJA Solutions still needs the button once its token is in the config.
   Note: GHL gave three fields its own merge keys: `{{contact.swa_back_office_score}}`,
   `{{contact.swa_recommend_score}}`, `{{contact.swa_accuracy_rating}}`. The server matches fields by name, so this is fine.
4. Every send shows on the admin page as **Sent** or **Failed**, with the reason.

Merge fields in emails: `{{contact.swa_bottleneck}}`, `{{contact.swa_recommended_agent}}`,
`{{contact.swa_agent_does}}`, `{{contact.swa_plan}}`, `{{contact.swa_portal_link}}`, `{{contact.swa_audit_ends}}`.
(Check the exact keys under Settings → Custom Fields after step 3.)

## 2. Workflows (trigger: "Contact Tag added")
All paid plans are monthly. `swa_audit_ends` is the end of the customer's first month. The `swa-results-tracking-*` tags kept their names: *on* = subscription restarted, *canceled* = subscription ended.
Add **Goal: tag `swa-team-audit-purchased` added** to the free-audit workflow so buyers stop getting nurture emails.

| # | Tag | Steps |
|---|---|---|
| 1 | `swa-free-audit` | Email 1 now → wait 2 days → Email 2 → wait 3 days → Email 3 → wait 4 days → Email 4 |
| 2 | `swa-wants-help` | Internal notification + task "Call today" → Email "We'll reach out" → (ALJA) create opportunity |
| 3 | `swa-team-audit-purchased` | Internal notification → Welcome email → wait 2 days → if NOT `swa-first-audit-received`: reminder → wait until `swa_audit_ends` (end of the first month) minus 7 days → "First month check-in" |
| 4 | `swa-first-audit-received` | Email "Your data is in" |
| 5 | `swa-instant-report-ready` | Internal task "Write final plan (due in 2 business days)" → (ALJA) opportunity "Build: discovery" |
| 6 | `swa-final-plan-sent` | Email "Book your build call" → wait 2 days → reminder → wait 3 days → last reminder (stop when appointment booked) |
| 7 | `swa-results-tracking-on` (subscription restarted) | "Welcome back" email |
| 8 | `swa-results-tracking-canceled` (subscription ended) | Internal notification → wait 14 days → win-back email |

Optional segments: `swa-plan-solo`, `swa-plan-starter`, `swa-plan-team`, `swa-plan-company`, `swa-industry-<industry>`.

## 3. Emails
**Sirena Flow: done.** All 12 are in Marketing → Emails → Templates → folder **Workflow Shadow Audit**:
SWA 01–04 free audit (day 0, 2, 5, 9) · 05 welcome · 06 no audits yet · 07 first month check-in · 08 first audit received ·
09 wants help · 10 final plan sent · 11 welcome back (restarted) · 12 win-back (day 14).
Set the From name and From email on each. The drafts below are the same text, for reference.

### Drafts (edit freely)

**Free audit 1 — now.** Subject: *Your #1 bottleneck: {{contact.swa_bottleneck}}*
> Thanks for running the free audit. Your biggest time drain is in **{{contact.swa_bottleneck}}**.
> The fix: a **{{contact.swa_recommended_agent}}**. {{contact.swa_agent_does}}
> Want us to walk you through it? [Book a free call]

**Free audit 2 — day 2.** Subject: *What that repeated task is really costing you*
> Every time you repeat the same steps by hand, the hours add up. Most owners we audit find 10+ hours a month.
> A Solo Audit runs all week (your free hours carry over), adds your tools and real costs, and writes your full report. [See paid audits]

**Free audit 3 — day 5.** Subject: *Is it just you, or the whole team?*
> If your team does the same work, the savings multiply. A Team Audit covers everyone, checks your back office
> for your industry, and ends with a plan from our team. Monthly, cancel any time. [See Team Audits]

**Free audit 4 — day 9.** Subject: *Want us to just fix it?*
> We build the AI agents too. If you'd like the {{contact.swa_recommended_agent}} set up for you, grab a time. [Book a call]

**Purchase — welcome.** Subject: *You're in. Here's step 1*
> Your {{contact.swa_plan}} is ready. Sign in to your Business Portal: {{contact.swa_portal_link}}
> Step 1: tell us about your business. Step 2: invite your team (or connect your extension). Your plan is monthly; cancel any time from Billing & invoices in your portal.

**Purchase — reminder (no audits yet).** Subject: *Your team hasn't connected yet*
> No audits have come in yet. Send your team the invite message from the "Your team" page. It takes them 2 minutes.

**First audit received.** Subject: *Your data is in*
> Your first audit just arrived. Answer the quick questions, list your tools, then get your instant AI report: {{contact.swa_portal_link}}

**Final plan sent — book the build.** Subject: *Your plan is ready. Let's build it*
> Your final plan is in your portal. Your first month is credited toward the build. [Book your build call]

**Subscription ended (win-back, day 14).** Subject: *Still saving those hours?*
> Want to check the numbers are holding? Restart your subscription any time from your portal. Same key, all your history.
