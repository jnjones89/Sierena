/*
 * Workflow Shadow Audit — plain-language results.
 *
 * Turns the tracked domains and repeated 4-step loops into:
 *   - the #1 bottleneck (which part of the business)
 *   - the repetitive task (in plain words)
 *   - the AI agent that would do it
 *   - a simple step-by-step process to fix it
 *
 * Runs entirely in the popup. No network, no AI call needed.
 */

'use strict';

/* Known tools: [domain suffix, friendly name, category]. Specific entries first. */
const TOOL_CATALOG = [
  ['mail.google.com', 'Gmail', 'email'],
  ['outlook.office.com', 'Outlook', 'email'],
  ['outlook.office365.com', 'Outlook', 'email'],
  ['outlook.live.com', 'Outlook', 'email'],
  ['mail.yahoo.com', 'Yahoo Mail', 'email'],
  ['calendar.google.com', 'Google Calendar', 'calendar'],
  ['meet.google.com', 'Google Meet', 'calendar'],
  ['chat.google.com', 'Google Chat', 'chat'],
  ['docs.google.com', 'Google Docs & Sheets', 'docs'],
  ['sheets.google.com', 'Google Sheets', 'docs'],
  ['drive.google.com', 'Google Drive', 'docs'],
  ['gemini.google.com', 'Gemini', 'ai'],
  ['business.google.com', 'Google Business Profile', 'social'],
  ['teams.microsoft.com', 'Microsoft Teams', 'chat'],
  ['sharepoint.com', 'SharePoint / Excel', 'docs'],
  ['onedrive.live.com', 'OneDrive', 'docs'],
  ['office.com', 'Microsoft 365', 'docs'],
  ['hubspot.com', 'HubSpot', 'crm'],
  ['force.com', 'Salesforce', 'crm'],
  ['salesforce.com', 'Salesforce', 'crm'],
  ['pipedrive.com', 'Pipedrive', 'crm'],
  ['gohighlevel.com', 'GoHighLevel', 'crm'],
  ['leadconnectorhq.com', 'GoHighLevel', 'crm'],
  ['close.com', 'Close', 'crm'],
  ['zoho.com', 'Zoho', 'crm'],
  ['airtable.com', 'Airtable', 'docs'],
  ['notion.so', 'Notion', 'pm'],
  ['dropbox.com', 'Dropbox', 'docs'],
  ['box.com', 'Box', 'docs'],
  ['slack.com', 'Slack', 'chat'],
  ['discord.com', 'Discord', 'chat'],
  ['calendly.com', 'Calendly', 'calendar'],
  ['acuityscheduling.com', 'Acuity', 'calendar'],
  ['zoom.us', 'Zoom', 'calendar'],
  ['asana.com', 'Asana', 'pm'],
  ['trello.com', 'Trello', 'pm'],
  ['monday.com', 'monday.com', 'pm'],
  ['clickup.com', 'ClickUp', 'pm'],
  ['basecamp.com', 'Basecamp', 'pm'],
  ['atlassian.net', 'Jira', 'pm'],
  ['linear.app', 'Linear', 'pm'],
  ['quickbooks.intuit.com', 'QuickBooks', 'finance'],
  ['xero.com', 'Xero', 'finance'],
  ['freshbooks.com', 'FreshBooks', 'finance'],
  ['dashboard.stripe.com', 'Stripe', 'finance'],
  ['paypal.com', 'PayPal', 'finance'],
  ['bill.com', 'Bill.com', 'finance'],
  ['gusto.com', 'Gusto', 'finance'],
  ['facebook.com', 'Facebook', 'social'],
  ['instagram.com', 'Instagram', 'social'],
  ['linkedin.com', 'LinkedIn', 'social'],
  ['x.com', 'X', 'social'],
  ['twitter.com', 'X', 'social'],
  ['tiktok.com', 'TikTok', 'social'],
  ['youtube.com', 'YouTube', 'social'],
  ['pinterest.com', 'Pinterest', 'social'],
  ['canva.com', 'Canva', 'social'],
  ['mailchimp.com', 'Mailchimp', 'social'],
  ['klaviyo.com', 'Klaviyo', 'social'],
  ['zendesk.com', 'Zendesk', 'support'],
  ['intercom.com', 'Intercom', 'support'],
  ['freshdesk.com', 'Freshdesk', 'support'],
  ['gorgias.com', 'Gorgias', 'support'],
  ['myshopify.com', 'Shopify', 'store'],
  ['shopify.com', 'Shopify', 'store'],
  ['sellercentral.amazon.com', 'Amazon Seller Central', 'store'],
  ['etsy.com', 'Etsy', 'store'],
  ['docusign.net', 'DocuSign', 'forms'],
  ['docusign.com', 'DocuSign', 'forms'],
  ['pandadoc.com', 'PandaDoc', 'forms'],
  ['typeform.com', 'Typeform', 'forms'],
  ['jotform.com', 'Jotform', 'forms'],
  ['chatgpt.com', 'ChatGPT', 'ai'],
  ['chat.openai.com', 'ChatGPT', 'ai'],
  ['claude.ai', 'Claude', 'ai'],
  ['google.com', 'Google Search', 'search'],
  ['bing.com', 'Bing', 'search']
];

const CATEGORY_WORDS = {
  email: 'your inbox', crm: 'your CRM', docs: 'your docs & spreadsheets', chat: 'team chat',
  calendar: 'your calendar', pm: 'your project tool', finance: 'your accounting tool',
  social: 'social media', support: 'your help desk', store: 'your online store',
  forms: 'your forms & e-sign tool', ai: 'AI tools', search: 'web search', other: 'your tools'
};

/*
 * Playbooks, checked in order (most specific first).
 * needs:    every listed category must be present
 * needsAny: at least one of these must also be present
 * Text placeholders like {email} become the real tool name (e.g. "Gmail").
 */
const PLAYBOOKS = [
  {
    needs: ['email', 'crm'],
    area: 'Sales',
    task: 'Copying new leads and replies between {email} and {crm} by hand.',
    agent: 'Lead Capture Agent',
    does: 'Reads new leads in {email}, adds them to {crm}, and drafts your reply.',
    steps: [
      'A new inquiry lands in {email}.',
      'The agent pulls out the name, contact info and what they want.',
      'It adds or updates the contact in {crm} and sets a follow-up.',
      'It writes a reply for you to approve and send.',
      'Connect {email} and {crm} with Make.com, n8n or Zapier.'
    ]
  },
  {
    needs: ['store'],
    needsAny: ['email', 'docs', 'finance', 'support'],
    area: 'Orders',
    task: 'Handling orders by hand across {store} and your other tools.',
    agent: 'Order Operations Agent',
    does: 'Watches new orders in {store}, updates records and sends customer updates.',
    steps: [
      'A new order or order problem comes into {store}.',
      'The agent logs it and checks stock, payment and shipping.',
      'It sends the customer an update automatically.',
      'Anything unusual is flagged for you to handle.',
      'Connect {store} with your other tools using Make.com, n8n or Zapier.'
    ]
  },
  {
    needs: ['support'],
    area: 'Customer service',
    task: 'Answering the same customer questions over and over in {support}.',
    agent: 'Customer Support Agent',
    does: 'Answers common questions in {support} and hands harder ones to you.',
    steps: [
      'A customer question arrives in {support}.',
      'The agent checks your FAQ and past answers.',
      'It drafts or sends the answer for simple questions.',
      'Harder questions are tagged and passed to a person.',
      'Start with your top 10 most-asked questions.'
    ]
  },
  {
    needs: ['email', 'finance'],
    area: 'Finance',
    task: 'Matching emails, invoices and payments by hand in {finance}.',
    agent: 'Invoice & Payment Agent',
    does: 'Creates invoices, chases late payments and records them in {finance}.',
    steps: [
      'A job is finished or an invoice/receipt arrives in {email}.',
      'The agent creates or records it in {finance}.',
      'It sends polite payment reminders on a schedule.',
      'You get a weekly summary of what is paid and overdue.',
      'Connect {email} and {finance} with Make.com, n8n or Zapier.'
    ]
  },
  {
    needs: ['finance', 'docs'],
    area: 'Finance',
    task: 'Re-typing numbers between {finance} and {docs}.',
    agent: 'Bookkeeping Agent',
    does: 'Moves numbers between {finance} and {docs} and builds your reports.',
    steps: [
      'New transactions show up in {finance}.',
      'The agent sorts and labels them.',
      'It updates your report in {docs} automatically.',
      'You review a short summary instead of building it.',
      'Connect {finance} and {docs} with Make.com, n8n or Zapier.'
    ]
  },
  {
    needs: ['crm', 'docs'],
    area: 'Sales',
    task: 'Moving data out of {crm} into {docs} to track or report.',
    agent: 'Sales Reporting Agent',
    does: 'Pulls numbers from {crm} into {docs} and sends you the report.',
    steps: [
      'Every day or week, on a schedule.',
      'The agent pulls deals and activity from {crm}.',
      'It updates the sheet or report in {docs}.',
      'It sends you a short summary with what needs attention.',
      'Connect {crm} and {docs} with Make.com, n8n or Zapier.'
    ]
  },
  {
    needs: ['email', 'docs'],
    area: 'Operations',
    task: 'Copying information from {email} into {docs} by hand.',
    agent: 'Data Entry Agent',
    does: 'Reads emails in {email} and fills in {docs} for you.',
    steps: [
      'An email with information you usually copy arrives in {email}.',
      'The agent pulls out the details you need.',
      'It adds them to the right place in {docs}.',
      'It flags anything it is unsure about for you to check.',
      'Connect {email} and {docs} with Make.com, n8n or Zapier.'
    ]
  },
  {
    needs: ['calendar'],
    needsAny: ['email', 'crm', 'chat'],
    area: 'Scheduling',
    task: 'Going back and forth to book, move and confirm meetings.',
    agent: 'Scheduling Agent',
    does: 'Books, confirms and reminds people about meetings in {calendar}.',
    steps: [
      'Someone asks to meet.',
      'The agent sends your booking link or offers open times from {calendar}.',
      'It confirms the meeting and sends reminders.',
      'After the meeting it logs notes and next steps.',
      'Connect {calendar} with your inbox and CRM.'
    ]
  },
  {
    needs: ['forms'],
    area: 'Client onboarding',
    task: 'Sending forms and contracts and copying the answers by hand.',
    agent: 'Client Onboarding Agent',
    does: 'Sends forms and contracts in {forms} and files the answers for you.',
    steps: [
      'A new client says yes.',
      'The agent sends the right forms and contract from {forms}.',
      'When they are signed, it files the details where they belong.',
      'It sends a welcome message and next steps.',
      'Connect {forms} with your CRM and inbox.'
    ]
  },
  {
    needs: ['chat', 'pm'],
    area: 'Operations',
    task: 'Updating tasks in {pm} and posting the same updates in {chat}.',
    agent: 'Project Update Agent',
    does: 'Keeps {pm} up to date and posts status updates to {chat}.',
    steps: [
      'Work is finished or a task changes.',
      'The agent updates the task in {pm}.',
      'It posts a short update in {chat}.',
      'Every Friday it sends a summary of what is done and stuck.',
      'Connect {pm} and {chat} with Make.com, n8n or Zapier.'
    ]
  },
  {
    needs: ['social'],
    area: 'Marketing',
    task: 'Creating, posting and replying on {social} by hand.',
    agent: 'Social Media Agent',
    does: 'Drafts and schedules posts and answers common comments and messages on {social}.',
    steps: [
      'Each week the agent drafts posts from your ideas, offers and news.',
      'You approve them in one batch.',
      'It schedules and posts them on {social}.',
      'It answers simple comments and DMs and sends hot leads to you.',
      'Connect {social} with a scheduler plus Make.com or n8n.'
    ]
  },
  {
    needs: ['email'],
    area: 'Admin',
    task: 'Sorting and answering a busy inbox in {email}.',
    agent: 'Inbox Assistant Agent',
    does: 'Sorts {email}, drafts replies to common emails and flags what is urgent.',
    steps: [
      'New email arrives in {email}.',
      'The agent sorts it: urgent, needs reply, info only, or junk.',
      'It drafts replies to the common ones for you to approve.',
      'You get one short list of what needs you today.',
      'Start with the 5 types of email you answer most.'
    ]
  },
  {
    needs: ['pm'],
    area: 'Operations',
    task: 'Updating and chasing tasks by hand in {pm}.',
    agent: 'Task Follow-up Agent',
    does: 'Keeps {pm} updated and reminds people what is due.',
    steps: [
      'Tasks are created or due dates get close.',
      'The agent reminds the right person.',
      'It updates the task when work is done.',
      'You get a weekly summary of what is late.',
      'Connect {pm} with your team chat and inbox.'
    ]
  },
  {
    needs: ['ai'],
    area: 'Research & writing',
    task: 'Copying and pasting between AI chat tools and your work.',
    agent: 'Built-in AI Assistant',
    does: 'Puts AI directly inside the tools you already use, so you stop copy-pasting.',
    steps: [
      'Write down the 3 things you ask AI for most.',
      'Turn each one into a saved prompt or template.',
      'Connect AI directly to the tool where the result is needed.',
      'Review the results instead of copying and pasting.',
      'Use Make.com or n8n to connect the AI to your tools.'
    ]
  }
];

const FALLBACK_PLAYBOOK = {
  area: 'Operations',
  task: 'Moving information between {tools} by hand.',
  agent: 'Workflow Agent',
  does: 'Moves information between {tools} automatically.',
  steps: [
    'Write down what you copy from one tool to the next.',
    'The agent watches for that information in the first tool.',
    'It moves it to the next tool automatically.',
    'It flags anything unusual for you to check.',
    'Connect {tools} with Make.com, n8n or Zapier.'
  ]
};

function toolInfo(domain) {
  for (const [suffix, name, category] of TOOL_CATALOG) {
    if (domain === suffix || domain.endsWith('.' + suffix)) return { domain, name, category };
  }
  return { domain, name: domain, category: 'other' };
}

function matchPlaybook(categories) {
  const has = (c) => categories.has(c);
  for (const pb of PLAYBOOKS) {
    if (!pb.needs.every(has)) continue;
    if (pb.needsAny && !pb.needsAny.some(has)) continue;
    return pb;
  }
  return null;
}

function fillText(text, tools) {
  const names = {};
  tools.forEach((t) => {
    if (!names[t.category]) names[t.category] = t.name;
  });
  const uniqueNames = [...new Set(tools.map((t) => t.name))];
  const list =
    uniqueNames.length <= 1
      ? uniqueNames[0] || 'your tools'
      : uniqueNames.slice(0, -1).join(', ') + ' and ' + uniqueNames[uniqueNames.length - 1];
  return text.replace(/\{(\w+)\}/g, (m, key) => {
    if (key === 'tools') return list;
    return names[key] || CATEGORY_WORDS[key] || 'your tools';
  });
}

function buildRecommendation(pb, tools) {
  return {
    area: pb.area,
    task: fillText(pb.task, tools),
    agent: pb.agent,
    does: fillText(pb.does, tools),
    steps: pb.steps.map((s) => fillText(s, tools))
  };
}

/**
 * @param {object} metrics  chrome.storage "metrics" object
 * @returns {object|null}   null when there is not enough data yet
 */
function buildInsights(metrics) {
  const domains = (metrics && metrics.domains) || {};
  const all = Object.keys(domains)
    .map((d) => Object.assign(toolInfo(d), {
      seconds: domains[d].activeSeconds || 0,
      visits: domains[d].visitCount || 0
    }))
    .sort((a, b) => b.seconds - a.seconds);

  const totalSeconds = all.reduce((s, t) => s + t.seconds, 0);
  if (all.length === 0 || totalSeconds === 0) return null;

  const work = all.filter((t) => t.category !== 'search');

  // Most repeated 4-step loop (must repeat at least twice).
  const loopsObj = (metrics && metrics.loops) || {};
  let topLoop = null;
  Object.keys(loopsObj).forEach((key) => {
    const count = loopsObj[key];
    if (count >= 2 && (!topLoop || count > topLoop.count)) topLoop = { key, count };
  });

  let focusTools;
  let loop = null;
  if (topLoop) {
    const seq = topLoop.key.split(' -> ').map(toolInfo);
    loop = { names: seq.map((t) => t.name), count: topLoop.count };
    const seen = new Set();
    focusTools = seq.filter((t) => (seen.has(t.domain) ? false : seen.add(t.domain)));
  } else {
    focusTools = (work.length ? work : all).slice(0, 3);
  }

  const categories = new Set(focusTools.map((t) => t.category));
  const main = buildRecommendation(matchPlaybook(categories) || FALLBACK_PLAYBOOK, focusTools);

  // Time spent in the tools behind the main task.
  const focusDomains = new Set(focusTools.map((t) => t.domain));
  const focusSeconds = all.filter((t) => focusDomains.has(t.domain)).reduce((s, t) => s + t.seconds, 0);

  // Up to 2 more agents worth building, from the other top tools.
  const extras = [];
  const usedAgents = new Set([main.agent]);
  const topWork = work.slice(0, 8);
  for (const t of topWork) {
    if (extras.length >= 2) break;
    const pair = topWork.filter((o) => o.category === t.category || o.domain === t.domain);
    const pb = matchPlaybook(new Set(pair.map((o) => o.category)));
    if (pb && !usedAgents.has(pb.agent)) {
      usedAgents.add(pb.agent);
      extras.push(buildRecommendation(pb, pair));
    }
  }

  return {
    loop,
    main,
    extras,
    focusNames: [...new Set(focusTools.map((t) => t.name))],
    topTools: all.filter((t) => t.seconds > 0).slice(0, 5).map((t) => ({ name: t.name, seconds: t.seconds })),
    focusSeconds,
    totalSeconds,
    quickSwitches: (metrics && metrics.rapidContextSwitches) || 0,
    toolCount: all.length
  };
}
