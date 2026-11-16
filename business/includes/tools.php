<?php
/*
 * Known tools: domain suffix => [friendly name, category].
 * Generated from TOOL_CATALOG in workflow-shadow-audit/insights.js — keep them in sync.
 */
declare(strict_types=1);

const TOOL_CATALOG = [
    ['mail.google.com', 'Gmail', 'email'],
    ['outlook.office.com', 'Outlook', 'email'],
    ['outlook.office365.com', 'Outlook', 'email'],
    ['outlook.live.com', 'Outlook', 'email'],
    ['mail.yahoo.com', 'Yahoo Mail', 'email'],
    ['calendar.google.com', 'Google Calendar', 'calendar'],
    ['meet.google.com', 'Google Meet', 'meeting'],
    ['chat.google.com', 'Google Chat', 'chat'],
    ['sheets.google.com', 'Google Sheets', 'docs'],
    ['slides.google.com', 'Google Slides', 'docs'],
    ['forms.google.com', 'Google Forms', 'forms'],
    ['docs.google.com', 'Google Docs', 'docs'],
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
    ['zoom.us', 'Zoom', 'meeting'],
    ['gotomeeting.com', 'GoTo Meeting', 'meeting'],
    ['webex.com', 'Webex', 'meeting'],
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
    ['bing.com', 'Bing', 'search'],
];

/** Friendly name and category for a domain (e.g. app.hubspot.com => HubSpot). */
function tool_info(string $domain): array
{
    foreach (TOOL_CATALOG as [$suffix, $name, $category]) {
        if ($domain === $suffix || str_ends_with($domain, '.' . $suffix)) {
            return ['name' => $name, 'category' => $category];
        }
    }
    return ['name' => $domain, 'category' => 'other'];
}
