# Workflow Shadow Audit — messaging guide

One set of words everywhere: extension, pricing page, portal, emails, admin, website and sales calls.
(This file is blocked from the web by `.htaccess`.)

## Names
| Use | Means | Don't say |
|---|---|---|
| **Workflow Shadow Audit** | The product and the Chrome extension | "the plugin", "the widget" in customer copy |
| **free audit** | One person, one 5-hour audit in the extension | Lite, trial, freemium |
| **Team Audit** | The paid, one-time audit for a whole team | Business plan, Business tier, Pro |
| **Starter / Team / Company** | Team Audit sizes: up to 5 / 15 / 50 people | seats tier, bundle |
| **team key** | The `AUDIT-XXXX-XXXX-XXXX-XXXX` code a buyer shares with their team | license key, Business key, access key |
| **Business Portal** | Where the buyer signs in, fills in the profile and gets reports | dashboard, team portal, client area |
| **business profile** | Company, social media, team, goals | intake form |
| **Your tools** | The software list with monthly cost and seats, matched to the audit (Team Audit) | software inventory, SaaS stack |
| **audit bots** | The animated crew and live feed in the extension while it tracks | AI scanning, monitoring |
| **Back-Office Check** | Industry questions about processes and SOPs, follow-up, contracts, compliance, data security. In the portal it shows up as **quick questions**, one at a time, answered Yes / Sometimes / No / Not sure | compliance audit, legal review |
| **instant AI report** | Written by AI in the portal in 1–2 minutes | gap analysis, AI Gap Map |
| **final plan** | Reviewed and published by our team, with the SOPs to write first | full report, custom report |
| **report prompt** | The copy-paste prompt for ChatGPT or Claude | full AI report, analysis prompt |
| **Results Tracking** | Optional monthly plan after the audit, month-by-month progress | monitoring, subscription, retainer |
| **build** | Us building the AI agents for them | implementation, onboarding |

## Buttons and actions (exact wording)
- Extension: **Start my free audit** · **Show** (after email) · **Copy report prompt for ChatGPT or Claude** · **Download my data**
- Extension, free: **Audit my whole team →** · **Want this for your whole team? See Team Audits →** · **Have a team key?**
- Extension, team: **Send to my Business Portal** · **Open Business Portal ↗** · **Start a new audit**
- Portal home steps: **Tell us about your business** · **Get your team connected** · **Answer some quick questions** · **Get your report**
- Portal: **Get my instant AI report** · **Save** · **Copy message** (team invite) · **Turn on Results Tracking** · **Book a call to build these agents →**
- Team invite page: **Join [company]'s Team Audit** → Add the extension · Paste your team key · Turn tracking on

## The promise, in one line each
- Free audit: *Turn it on and work like normal. After 5 hours of work, you'll see the task you repeat most, the AI agent that can do it for you, and a simple step-by-step fix.*
- Team Audit: *Everyone runs the private, read-only audit for a few days. You get one report: your biggest bottlenecks, the repetitive tasks behind them, the AI agents you need, and a step-by-step plan to fix them, plus a Back-Office Check for your industry.*
- Results Tracking: *Keep auditing every month after your agents are built, and see what improved.*
- Build credit: *If we build your agents, the audit price is credited toward the build.*

## Style
- Short sentences. Everyday words. One idea per sentence.
- Ask questions as "Do you…?" with Yes / Sometimes / No / Not sure.
- One main button per screen. Details (tables, numbers) go on the Numbers page.
- Say "tab switches", not "context switches"; "tasks you repeat", not "workflow loops"; "tools", not "domains".

## Privacy and trust lines (always the same)
- *We only see website names and time spent. Never what you type, read or click.*
- *Read-only: nothing on your computer is changed.*
- *Never touched: emails and messages, files on your computer, passwords, or anything you type.*
- *Easy to remove: right-click the icon and choose Remove from Chrome. Its data on your computer goes with it.*
- For teammates: *Your company sees the tools you use, time and repeated tasks, by role. Never what you type, read or send.*
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
6. After the build → **Results Tracking** shows month-by-month progress
