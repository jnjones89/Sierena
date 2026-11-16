# Workflow Shadow Audit — messaging guide

One set of words everywhere: extension, pricing page, portal, emails, admin, website and sales calls.
(This file is blocked from the web by `.htaccess`.)

## Names
| Use | Means | Don't say |
|---|---|---|
| **Workflow Shadow Audit** | The product and the Chrome extension | "the plugin", "the widget" in customer copy |
| **free audit** | One person, one 5-hour audit in the extension | Lite, trial, freemium |
| **Solo Audit** | The paid monthly plan for one person (solo founders), $97 / month | Pro, Premium, single seat |
| **Team Audit** | The paid monthly plan for a whole team ($197 / $397 / $697 a month by size) | Business plan, Business tier, Pro |
| **paid audit** | Solo Audit or Team Audit, when talking about both | upgrade, premium |
| **Starter / Team / Company** | Team Audit sizes: up to 5 / 15 / 50 people | seats tier, bundle |
| **team key** | The `AUDIT-XXXX-XXXX-XXXX-XXXX` code a buyer shares with their team | license key, Business key, access key |
| **Business Portal** | Where the buyer signs in, fills in the profile and gets reports | dashboard, team portal, client area |
| **business profile** | Company, social media, team, goals | intake form |
| **Your tools** | The software list with monthly cost and seats, matched to the audit (Team Audit) | software inventory, SaaS stack |
| **Client journey** | One flow of **7 stations**: Lead capture · Speed to lead · Follow-up · Booking and conversion · Onboarding and handoff · Delivery · Retention and referral. Three questions at each: **Process** (Defined / Partly / Improvised), **Owner** (A named person / The owner / Nobody), **Automation** (Automated / Manual / Missing). Each station lists its steps, each marked Automatic / By hand / Sometimes / Doesn't happen | stages, funnel steps, pipeline audit, process map |
| **From your CRM** | Real numbers from the customer's own GoHighLevel (Connect GoHighLevel, read-only) | CRM sync, integration data |
| **handoff tests** | Test lead, test booking, no-show and recap tests our team runs; shown as "Tested by our team" | mystery shop (internal only) |
| **Interruptions** | Short check-ins (under 2 minutes) to social media, chat or email in the middle of work | distractions, time wasting |
| **back and forth** | Two tools people jump between most, e.g. Gmail ↔ Google Sheets | context switching |
| **audit bots** | The animated crew and live feed in the extension while it tracks | AI scanning, monitoring |
| **Back-Office Check** | Industry questions about processes and SOPs, follow-up, contracts, compliance, data security. In the portal it shows up as **quick questions**, one at a time, answered Yes / Sometimes / No / Not sure | compliance audit, legal review |
| **instant AI report** | Written by AI in the portal in 1–2 minutes | gap analysis, AI Gap Map |
| **final plan** | Reviewed and published by our team, with the SOPs to write first | full report, custom report |
| **report prompt** | The copy-paste prompt for ChatGPT or Claude | full AI report, analysis prompt |
| **your subscription** | The monthly billing for a paid audit. Say "cancel any time". After canceling: **Restart my subscription** | retainer, membership, Results Tracking (retired name) |
| **build** | Us building the AI agents for them | implementation, onboarding |

## Buttons and actions (exact wording)
- Extension: **Start my free audit** · **Show** (after email) · **Copy report prompt for ChatGPT or Claude** · **Download my data**
- Extension, free: **Audit my whole team →** · **Want this for your whole team? See Team Audits →** · **Have a team key?**
- Extension, team: **Send to my Business Portal** · **Open Business Portal ↗** · **Start a new audit**
- Portal home steps: **Tell us about your business** · **List the software you pay for** · **Get your team connected** · **Answer some quick questions** · **Map your client journey** · **Get your report**
- Portal: **Get my instant AI report** · **Save** · **Copy message** (team invite) · **Restart my subscription** (only after canceling) · **Book a call to build these agents →**
- Team invite page: **Join [company]'s Team Audit** → Add the extension · Paste your team key · Turn tracking on

## Guarantee (always the same, set in config)
*Not happy with your first month? Ask for your money back within 30 days. No questions asked.*
Customers request it from Help in the Business Portal. You refund in Stripe.

## Survey (same three questions everywhere)
1. Did this find something real? Spot on / Partly / Not really
2. How likely are you to recommend it? 0–10
3. Want help fixing it? Yes, reach out / Not now (+ optional "What did we miss?")

## The promise, in one line each
- Solo Audit: *Just you, with no time limit. Keeps the hours you already tracked.*
- Free audit: *Turn it on and work like normal. After 5 hours of work, you'll see the task you repeat most, the AI agent that can do it for you, and a simple step-by-step fix.*
- Team Audit: *Everyone runs the private, read-only audit for a few days. You get one report: your biggest bottlenecks, the repetitive tasks behind them, the AI agents you need, and a step-by-step plan to fix them, plus a Back-Office Check for your industry.*
- Every month after: *Keep auditing every month after your agents are built, and see what improved, Before → Now.*
- Build credit: *If we build your agents, your first month is credited toward the build.*

## Style
- Short sentences. Everyday words. One idea per sentence.
- Ask questions as "Do you…?" with Yes / Sometimes / No / Not sure.
- One main button per screen. Details (tables, numbers) go on the Numbers page.
- Say "tab switches", not "context switches"; "tasks you repeat", not "workflow loops"; "tools", not "domains".
- Interruptions are a business finding, never a personal one. In Team Audits show them by role, never as "who was on Facebook".

## Privacy and trust lines (always the same)
- *We only see website names and time spent. Never what you type, read or click.*
- *Read-only: nothing on your computer is changed.*
- *Never touched: emails and messages, files on your computer, passwords, or anything you type.*
- *Easy to remove: right-click the icon and choose Remove from Chrome. Its data on your computer goes with it.*
- For teammates: *Your company sees the tools you use, time and repeated tasks, by role. Never what you type, read or send.*
- CRM check: *Read-only: nothing in your CRM is changed. We keep the numbers only, never names, emails, phone numbers or message text.*
- The audit bots' live feed only shows things the extension really saw. Never show "scanning your drive", "reading your email" or anything it doesn't do.

## Compliance line (always the same)
*Compliance checks are general guidance for your industry, not legal advice. Confirm the requirements that apply to you with your attorney or compliance advisor.*
Never say a client "is compliant" or that we make them compliant.

## Industries (same list in the extension and on the server)
Insurance agency · Lending & loan brokers · Business funding & bank approvals · Credit repair · Real estate · Healthcare & med spas ·
Law firms · Home services & contractors · E-commerce · Agencies & consultants · Other.
Source of truth: `business/includes/industries.php` and `workflow-shadow-audit/industries.js` (keep them identical).

## The customer journey (each step uses the words above)
1. Free audit in the extension → results → **Audit my whole team →**
2. Pricing page → pick Starter / Team / Company → Stripe → **team key** (screen + email)
3. Team pastes the **team key** → works normally → **Send to my Business Portal**
4. Buyer fills in the **business profile** and **Back-Office Check** → **Get my instant AI report**
5. Our team publishes the **final plan** (customer gets an email) → **Book a call to build these agents**
6. After the build → staying subscribed shows month-by-month progress and CRM Before → Now
