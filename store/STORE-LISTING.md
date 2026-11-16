# Chrome Web Store listing: Workflow Shadow Audit

Copy each block into the Developer Dashboard (chrome.google.com/webstore/devconsole).
Upload `dist/workflow-shadow-audit-<version>.zip` (built by `tools/build-release.sh`).

## Store listing tab

**Name:** Workflow Shadow Audit

**Summary (132 characters max):**
Private, read-only audit of where your workday goes. Finds the tasks you repeat and the AI agents that can do them.

**Category:** Productivity → Workflow & Planning
**Language:** English

**Description:**
```
Find out where your workday really goes, and which work AI can do for you.

Turn it on and work like normal. After 5 hours of work, Workflow Shadow Audit shows you:
• The task you repeat most, step by step
• The AI agent that can do it for you, and what it would do
• The tools you jump back and forth between
• Quick check-ins that break your focus
• The software you use most, and how much time it takes

PRIVATE AND READ-ONLY
• It only sees website names (like mail.google.com) and time spent.
• It never sees what you type, read or click. No page content, emails, messages, files, passwords or screenshots.
• It never changes anything on your computer or in your accounts.
• Your free audit stays on your computer until you choose to see your results.
• Easy to remove: right-click the icon and choose Remove from Chrome.

FOR TEAMS
Want this for your whole team? A Team Audit gives everyone a team key. You get one Business Portal with your client journey, a back-office check for your industry, your real CRM numbers (optional), an instant AI report and a final plan from our team.

Built by SirenaFlow. Privacy policy: https://sirenaflow.com/business/privacy.php
```

**Graphics (in this folder):**
- Store icon: `../workflow-shadow-audit/icons/icon128.png`
- Screenshots (1280×800): `screenshot-1-start.png`, `screenshot-2-private.png`, `screenshot-3-results.png`, `screenshot-4-team.png`
- Small promo tile (440×280): `promo-small-440x280.png`

**Official URL / Homepage:** https://sirenaflow.com/business/
**Support URL:** https://sirenaflow.com/business/help.php

## Privacy practices tab

**Single purpose:**
Shows the user how their working time is spread across websites, so they can find repeated tasks that can be automated.

**Permission justifications:**
- `tabs`: Reads the address of the active tab to record its domain name only (the path and query are removed immediately), so the audit can measure time per website.
- `idle`: Pauses the timer when the computer is idle, so idle time is not counted as work.
- `storage`: Saves the audit (domain names, time and settings) on the user's computer.

**Remote code:** No, I am not using remote code.

**Data usage (check these):**
- ✅ Personally identifiable information: the work email the user enters to see results.
- ✅ Web history: domain names and time spent (no full URLs).
- ❌ Health, financial, authentication, personal communications, location, user activity (clicks, keystrokes), website content: not collected.

**Certify all three:**
- I do not sell or transfer user data to third parties, outside of the approved use cases.
- I do not use or transfer user data for purposes that are unrelated to my item's single purpose.
- I do not use or transfer user data to determine creditworthiness or for lending purposes.

**Privacy policy URL:** https://sirenaflow.com/business/privacy.php

## Distribution tab
- Visibility: **Public** (or **Unlisted** for a soft launch: only people with the link can install it)
- Regions: all, or United States only to start

## After it is approved
1. Copy the store link (chromewebstore.google.com/detail/…).
2. Put it in your server config as `extension_url`, so invite pages and emails point to it.
