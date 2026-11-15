/*
 * Workflow Shadow Audit — popup controller
 *
 * Responsibilities:
 *   - Shadowing toggle + role tag
 *   - Access gate (Client Access Key "AUDIT-..." or work email)
 *   - Live mini-dashboard (refreshes every few seconds)
 *   - JSON export, AI analysis prompt copy, webhook POST, session reset
 *
 * Storage key owned by the popup:
 *   profile : { roleTag, access: { mode, value, unlockedAt } | null, webhookUrl }
 */

'use strict';

// Where your Business server (the /business folder) is hosted. No trailing slash.
const BUSINESS_BASE_URL = 'https://sirenaflow.com/business';
const REVALIDATE_MS = 12 * 60 * 60 * 1000;        // re-check a Business key twice a day
const OFFLINE_GRACE_MS = 7 * 24 * 60 * 60 * 1000;  // keep working offline for up to 7 days

const REFRESH_MS = 5000;
const MIN_LOOP_REPEATS = 2;      // a loop must occur at least twice to count as "repeated"
const MAX_EXPORT_LOOPS = 25;
const RESET_CONFIRM_MS = 4000;
const LOOP_SEPARATOR = ' -> ';

const FREE_EMAIL_DOMAINS = new Set([
  'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.uk', 'ymail.com',
  'hotmail.com', 'hotmail.co.uk', 'outlook.com', 'live.com', 'msn.com',
  'icloud.com', 'me.com', 'mac.com', 'aol.com', 'proton.me', 'protonmail.com',
  'gmx.com', 'gmx.net', 'mail.com', 'zoho.com', 'yandex.com', 'yandex.ru',
  'tutanota.com', 'fastmail.com', 'hey.com'
]);

const $ = (id) => document.getElementById(id);

const el = {
  statusPill: $('statusPill'),
  enabledToggle: $('enabledToggle'),
  toggleSub: $('toggleSub'),
  roleInput: $('roleInput'),
  gateLocked: $('gateLocked'),
  gateUnlocked: $('gateUnlocked'),
  gateInput: $('gateInput'),
  gateBtn: $('gateBtn'),
  gateMsg: $('gateMsg'),
  unlockedText: $('unlockedText'),
  lockBtn: $('lockBtn'),
  statHours: $('statHours'),
  statTools: $('statTools'),
  statLoop: $('statLoop'),
  statSwitches: $('statSwitches'),
  topTools: $('topTools'),
  updatedAt: $('updatedAt'),
  lockNote: $('lockNote'),
  downloadBtn: $('downloadBtn'),
  copyPromptBtn: $('copyPromptBtn'),
  syncBtn: $('syncBtn'),
  portalBtn: $('portalBtn'),
  upgradeBtn: $('upgradeBtn'),
  webhookDetails: $('webhookDetails'),
  webhookInput: $('webhookInput'),
  webhookBtn: $('webhookBtn'),
  resetBtn: $('resetBtn'),
  toast: $('toast')
};

let profile = { roleTag: '', access: null, webhookUrl: '' };
let latestMetrics = null;
let refreshTimer = null;
let resetConfirmTimer = null;
let toastTimer = null;
let roleSaveTimer = null;

/* ------------------------------------------------------------------ */
/* Utilities                                                           */
/* ------------------------------------------------------------------ */

function sendMessage(message) {
  return new Promise((resolve) => {
    try {
      chrome.runtime.sendMessage(message, (response) => {
        if (chrome.runtime.lastError) {
          resolve({ ok: false, error: chrome.runtime.lastError.message });
        } else {
          resolve(response || { ok: false, error: 'No response' });
        }
      });
    } catch (e) {
      resolve({ ok: false, error: String(e) });
    }
  });
}

function showToast(text, kind) {
  el.toast.textContent = text;
  el.toast.className = 'toast' + (kind ? ' ' + kind : '');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => {
    el.toast.textContent = '';
    el.toast.className = 'toast';
  }, 4500);
}

function round1(n) {
  return Math.round(n * 10) / 10;
}

function formatMinutes(seconds) {
  const mins = seconds / 60;
  if (mins < 1) return Math.round(seconds) + 's';
  if (mins < 60) return Math.round(mins) + 'm';
  const h = Math.floor(mins / 60);
  const m = Math.round(mins % 60);
  return h + 'h ' + m + 'm';
}

function slugify(text) {
  const slug = String(text || '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
  return slug || 'general';
}

function localDateStamp(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

function currentTier() {
  const a = profile.access;
  if (!a || !a.mode || !a.value) return 'free';
  if (a.mode === 'key' && a.tier === 'business') return 'business';
  if (a.mode === 'email') return 'lite';
  return 'free';
}

/** Lite (work email) or Business (paid key): export + AI prompt. */
function isUnlocked() {
  return currentTier() !== 'free';
}

/** Business only: portal sync, webhook. */
function isBusiness() {
  return currentTier() === 'business';
}

async function postJson(path, body) {
  const res = await fetch(BUSINESS_BASE_URL + path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body)
  });
  let data = null;
  try {
    data = await res.json();
  } catch (e) {
    data = null;
  }
  if (!data) throw new Error('Unexpected server response (HTTP ' + res.status + ')');
  return data;
}

function openTab(url) {
  chrome.tabs.create({ url });
}

/* ------------------------------------------------------------------ */
/* Profile persistence                                                 */
/* ------------------------------------------------------------------ */

async function loadProfile() {
  const { profile: stored } = await chrome.storage.local.get('profile');
  profile = Object.assign({ roleTag: '', access: null, webhookUrl: '' }, stored || {});
}

async function saveProfile() {
  await chrome.storage.local.set({ profile });
}

/* ------------------------------------------------------------------ */
/* Access gate                                                         */
/* ------------------------------------------------------------------ */

function validateAccessKey(value) {
  return /^AUDIT-[A-Z0-9][A-Z0-9-]{3,}$/i.test(value);
}

function validateWorkEmail(value) {
  const match = /^[^\s@]+@([^\s@]+\.[^\s@]{2,})$/.exec(value);
  if (!match) return { ok: false, reason: 'Enter a valid email address.' };
  const domain = match[1].toLowerCase();
  if (FREE_EMAIL_DOMAINS.has(domain)) {
    return { ok: false, reason: 'Please use your work email (not a personal inbox).' };
  }
  return { ok: true };
}

async function handleUnlock() {
  const raw = el.gateInput.value.trim();
  if (!raw) {
    setGateMsg('Enter a Client Access Key or your work email.', 'error');
    return;
  }

  if (/^audit-/i.test(raw)) {
    if (!validateAccessKey(raw)) {
      setGateMsg('That access key looks incomplete. Format: AUDIT-XXXX-XXXX-XXXX-XXXX', 'error');
      return;
    }
    const key = raw.toUpperCase();
    el.gateBtn.disabled = true;
    setGateMsg('Checking your Business key…', '');
    let result;
    try {
      result = await postJson('/api/validate.php', { key });
    } catch (e) {
      el.gateBtn.disabled = false;
      setGateMsg('Could not reach the license server. Check your connection and try again.', 'error');
      return;
    }
    el.gateBtn.disabled = false;
    if (!result.ok || !result.valid) {
      setGateMsg(result.reason || result.error || 'That key is not valid.', 'error');
      return;
    }
    const now = new Date().toISOString();
    profile.access = {
      mode: 'key',
      tier: 'business',
      value: key,
      company: result.company || '',
      portalUrl: result.portalUrl || BUSINESS_BASE_URL + '/portal.php',
      unlockedAt: now,
      validatedAt: now
    };
  } else {
    const check = validateWorkEmail(raw);
    if (!check.ok) {
      setGateMsg(check.reason, 'error');
      return;
    }
    const email = raw.toLowerCase();
    profile.access = { mode: 'email', tier: 'lite', value: email, unlockedAt: new Date().toISOString() };
    // Record the lead (fire-and-forget; unlocking never depends on the server).
    postJson('/api/lead.php', { email, roleTag: profile.roleTag || '' }).catch(() => {});
  }

  await saveProfile();
  el.gateInput.value = '';
  renderGate();
  showToast(isBusiness() ? 'Business plan unlocked.' : 'Export + AI prompt unlocked.', 'ok');
}

/** Re-check a Business key periodically so canceled subscriptions lose access. */
async function revalidateBusinessKey() {
  const a = profile.access;
  if (!a || a.mode !== 'key') return;

  const last = a.validatedAt ? Date.parse(a.validatedAt) : 0;
  if (a.tier === 'business' && Date.now() - last < REVALIDATE_MS) return;

  try {
    const result = await postJson('/api/validate.php', { key: a.value });
    if (result.ok && result.valid) {
      profile.access = Object.assign({}, a, {
        tier: 'business',
        company: result.company || a.company || '',
        portalUrl: result.portalUrl || a.portalUrl,
        validatedAt: new Date().toISOString()
      });
    } else if (result.ok && !result.valid) {
      profile.access = null;
      setGateMsg(result.reason || 'Your Business key is no longer active.', 'error');
    }
  } catch (e) {
    // Offline: allow a grace period, then require a fresh check.
    if (Date.now() - last > OFFLINE_GRACE_MS) {
      profile.access = null;
      setGateMsg('Please reconnect to the internet to re-verify your Business key.', 'error');
    }
  }
  await saveProfile();
  renderGate();
}

async function handleLock() {
  profile.access = null;
  await saveProfile();
  renderGate();
  setGateMsg('Live preview is free. Work email unlocks export + AI prompt. Business key unlocks team sync & webhook.', '');
}

function setGateMsg(text, kind) {
  el.gateMsg.textContent = text;
  el.gateMsg.className = 'gate-msg' + (kind ? ' ' + kind : '');
}

function maskAccess(access) {
  if (!access) return '';
  if (access.mode === 'key') {
    const v = access.value;
    return v.length > 10 ? v.slice(0, 8) + '…' + v.slice(-2) : v;
  }
  return access.value;
}

function renderGate() {
  const unlocked = isUnlocked();
  const business = isBusiness();
  el.gateLocked.classList.toggle('hidden', unlocked);
  el.gateUnlocked.classList.toggle('hidden', !unlocked);
  if (business) {
    el.unlockedText.textContent =
      'Business' + (profile.access.company ? ' · ' + profile.access.company : '') + ' · ' + maskAccess(profile.access);
  } else if (unlocked) {
    el.unlockedText.textContent = 'Lite · ' + maskAccess(profile.access);
  }

  el.lockNote.classList.toggle('hidden', unlocked);
  el.downloadBtn.disabled = !unlocked;
  el.copyPromptBtn.disabled = !unlocked;

  el.syncBtn.disabled = !business;
  el.syncBtn.classList.toggle('hidden', !business);
  el.portalBtn.classList.toggle('hidden', !business);
  el.upgradeBtn.classList.toggle('hidden', business);

  el.webhookBtn.disabled = !business;
  el.webhookInput.disabled = !business;
  el.webhookDetails.classList.toggle('disabled', !business);
  el.webhookDetails.querySelector('summary').textContent = business ? 'Send to Webhook' : 'Send to Webhook (Business)';
  if (!business) el.webhookDetails.open = false;
}

/* ------------------------------------------------------------------ */
/* Payload construction (matches the documented JSON schema)           */
/* ------------------------------------------------------------------ */

function computeSummary(metrics) {
  const domains = (metrics && metrics.domains) || {};
  const entries = Object.keys(domains).map((domain) => ({
    domain,
    activeSeconds: domains[domain].activeSeconds || 0,
    visitCount: domains[domain].visitCount || 0
  }));
  entries.sort((a, b) => b.activeSeconds - a.activeSeconds || b.visitCount - a.visitCount);

  const totalSeconds = entries.reduce((sum, e) => sum + e.activeSeconds, 0);

  const loopsObj = (metrics && metrics.loops) || {};
  const loops = Object.keys(loopsObj)
    .map((key) => ({ sequence: key.split(LOOP_SEPARATOR), repeatCount: loopsObj[key] }))
    .filter((l) => l.repeatCount >= MIN_LOOP_REPEATS)
    .sort((a, b) => b.repeatCount - a.repeatCount);

  return {
    entries,
    totalSeconds,
    loops,
    rapidContextSwitches: (metrics && metrics.rapidContextSwitches) || 0,
    sessionStartedAt: (metrics && metrics.sessionStartedAt) || null
  };
}

function buildPayload() {
  const summary = computeSummary(latestMetrics);
  return {
    auditMetadata: {
      roleTag: (profile.roleTag || '').trim() || 'Unspecified',
      exportDate: new Date().toISOString(),
      totalActiveMinutes: round1(summary.totalSeconds / 60),
      uniqueToolsCount: summary.entries.length,
      rapidContextSwitches: summary.rapidContextSwitches
    },
    softwareUsage: summary.entries.map((e) => ({
      domain: e.domain,
      activeMinutes: round1(e.activeSeconds / 60),
      visitCount: e.visitCount
    })),
    repeatedWorkflowLoops: summary.loops.slice(0, MAX_EXPORT_LOOPS).map((l) => ({
      sequence: l.sequence,
      repeatCount: l.repeatCount
    }))
  };
}

/* ------------------------------------------------------------------ */
/* AI analysis prompt                                                  */
/* ------------------------------------------------------------------ */

function buildAnalysisPrompt(payload) {
  const summary = computeSummary(latestMetrics);
  const started = summary.sessionStartedAt ? new Date(summary.sessionStartedAt) : null;
  const ended = new Date(payload.auditMetadata.exportDate);
  const windowText = started
    ? `${started.toISOString()} → ${ended.toISOString()} (${round1((ended - started) / 36e5)} wall-clock hours)`
    : `ending ${ended.toISOString()}`;

  return [
    'You are a senior Operational AI Architect and SaaS cost analyst preparing a client-facing report.',
    '',
    '## Context',
    'The JSON below was captured by "Workflow Shadow Audit", a read-only browser extension that passively',
    'recorded ONLY domain names, active (non-idle) foreground time, visit counts, repeated 4-step domain',
    'loops, and rapid context switches (tab/domain switches within 30 seconds of the previous one).',
    'No page content, URL paths, keystrokes or personal data were collected.',
    '',
    `- Role / department audited: ${payload.auditMetadata.roleTag}`,
    `- Observation window: ${windowText}`,
    `- Total active minutes: ${payload.auditMetadata.totalActiveMinutes}`,
    `- Unique domains: ${payload.auditMetadata.uniqueToolsCount}`,
    `- Rapid context switches: ${payload.auditMetadata.rapidContextSwitches}`,
    '',
    '## Rules',
    '1. Use ONLY the data provided. Never invent tools, seat counts or prices that are not stated; when you',
    '   need a number the data does not contain, state it as a labeled assumption and show the formula.',
    '2. First classify every domain as: Core SaaS, Internal/Company, Communication, Search/Reference,',
    '   AI Tool, or Personal/Non-work. Exclude Personal/Non-work from cost math but mention its share of time.',
    '3. Default assumptions (override if the client provides real figures): fully loaded labor cost $45/hour,',
    '   21.7 working days/month, 1–3 minutes of lost focus per rapid context switch (give low and high cases).',
    '4. Extrapolate from the observation window to a monthly and annual figure per employee, and clearly',
    '   label every extrapolation.',
    '5. Write for an executive: plain language, numbers first, no hype.',
    '',
    '## Deliver the report with exactly these sections',
    '',
    '### 1. Executive Summary',
    'Three to five bullet points with the single biggest saving, the biggest time sink, and the top recommendation.',
    '',
    '### 2. SaaS Consolidation',
    'Group tools with overlapping functions (e.g. multiple project, docs, CRM or chat tools). For each group,',
    'recommend which tool to keep, which to retire, and why — based on observed usage share.',
    '',
    '### 3. Shelfware Savings',
    'Identify tools with very low active minutes or visit counts relative to the window (likely paid but',
    'barely used). Provide a table: Domain | Active min | Visits | Usage signal | Recommendation | Est. monthly',
    'savings (as an assumption-based range using typical public list pricing, clearly marked as estimates).',
    '',
    '### 4. Payroll Waste Math',
    'Quantify (a) time lost to rapid context switching and (b) time spent executing repeated workflow loops.',
    'Show the formula, the low/high case, and the per-employee monthly and annual dollar cost, then the cost',
    'for a 10-person and 50-person team doing the same role.',
    '',
    '### 5. Automation & AI Agent Gap Map',
    'For each repeated workflow loop, describe the likely manual task it represents, then specify the AI agent',
    'or automation that should replace it: agent name, trigger, inputs, actions, systems it connects (from the',
    'observed domains), human-in-the-loop checkpoint, build complexity (Low/Med/High), and estimated hours',
    'saved per month. Rank by ROI.',
    '',
    '### 6. 30-Day AI Infrastructure Roadmap',
    'Week-by-week plan (Week 1 → Week 4): quick wins, tool retirements, agents to build first, integrations',
    '(e.g. Make.com / n8n / native APIs), owners, and success metrics to re-measure with a second audit.',
    '',
    '### 7. Assumptions & Data Limitations',
    'List every assumption used and what additional data (seat counts, contract prices, headcount) would',
    'sharpen the numbers.',
    '',
    '## Audit telemetry (JSON)',
    '```json',
    JSON.stringify(payload, null, 2),
    '```'
  ].join('\n');
}

/* ------------------------------------------------------------------ */
/* Actions                                                             */
/* ------------------------------------------------------------------ */

async function refreshForExport() {
  // Pull the latest committed time before exporting.
  await sendMessage({ type: 'flush' });
  const { metrics } = await chrome.storage.local.get('metrics');
  latestMetrics = metrics || null;
}

async function handleDownload() {
  if (!isUnlocked()) return;
  await refreshForExport();
  const payload = buildPayload();
  const json = JSON.stringify(payload, null, 2);
  const blob = new Blob([json], { type: 'application/json' });
  const url = URL.createObjectURL(blob);
  const filename = `workflow-audit-${slugify(payload.auditMetadata.roleTag)}-${localDateStamp(new Date())}.json`;

  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 10000);
  showToast('Downloaded ' + filename, 'ok');
}

async function copyText(text) {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch (e) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    let ok = false;
    try {
      ok = document.execCommand('copy');
    } catch (err) {
      ok = false;
    }
    ta.remove();
    return ok;
  }
}

async function handleCopyPrompt() {
  if (!isUnlocked()) return;
  await refreshForExport();
  const payload = buildPayload();
  const prompt = buildAnalysisPrompt(payload);
  const ok = await copyText(prompt);
  if (ok) {
    showToast('Prompt + data copied. Paste into Claude or ChatGPT.', 'ok');
  } else {
    showToast('Copy failed — please try again.', 'error');
  }
}

function validateWebhookUrl(value) {
  let url;
  try {
    url = new URL(value);
  } catch (e) {
    return { ok: false, reason: 'Enter a full webhook URL starting with https://' };
  }
  const isLocal = url.hostname === 'localhost' || url.hostname === '127.0.0.1';
  if (url.protocol !== 'https:' && !(url.protocol === 'http:' && isLocal)) {
    return { ok: false, reason: 'Webhook must use https:// (http:// allowed only for localhost).' };
  }
  return { ok: true, url: url.toString() };
}

async function handleSync() {
  if (!isBusiness()) return;
  await refreshForExport();
  const payload = buildPayload();
  if (payload.softwareUsage.length === 0) {
    showToast('Nothing to sync yet — shadow some work first.', 'error');
    return;
  }
  el.syncBtn.disabled = true;
  el.syncBtn.textContent = 'Syncing…';
  try {
    const result = await postJson('/api/sync.php', { key: profile.access.value, payload });
    if (result.ok) {
      showToast('Synced to your Business Portal.', 'ok');
    } else {
      showToast('Sync failed: ' + (result.error || 'unknown error'), 'error');
      if (/not active/i.test(result.error || '')) {
        profile.access.validatedAt = null;
        await revalidateBusinessKey();
      }
    }
  } catch (e) {
    showToast('Could not reach the Business server. Try again shortly.', 'error');
  } finally {
    el.syncBtn.textContent = 'Sync to Business Portal';
    el.syncBtn.disabled = !isBusiness();
  }
}

async function handleWebhook() {
  if (!isBusiness()) return;
  const check = validateWebhookUrl(el.webhookInput.value.trim());
  if (!check.ok) {
    showToast(check.reason, 'error');
    return;
  }

  profile.webhookUrl = check.url;
  await saveProfile();

  await refreshForExport();
  const body = JSON.stringify(buildPayload());

  el.webhookBtn.disabled = true;
  el.webhookBtn.textContent = 'Sending…';
  try {
    let delivered = false;
    let statusText = '';
    try {
      const res = await fetch(check.url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body
      });
      delivered = res.ok;
      statusText = `HTTP ${res.status}`;
    } catch (corsOrNetworkError) {
      // Many webhook endpoints don't answer CORS preflights. Retry as a
      // "simple" request: the payload is still delivered, but the response
      // is opaque, so we can't read the status code.
      await fetch(check.url, {
        method: 'POST',
        mode: 'no-cors',
        headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
        body
      });
      delivered = true;
      statusText = 'sent (response not readable due to CORS)';
    }

    if (delivered) {
      showToast('Webhook delivered — ' + statusText, 'ok');
    } else {
      showToast('Webhook responded with ' + statusText, 'error');
    }
  } catch (e) {
    showToast('Webhook failed: ' + (e && e.message ? e.message : 'network error'), 'error');
  } finally {
    el.webhookBtn.disabled = !isBusiness();
    el.webhookBtn.textContent = 'POST JSON Payload';
  }
}

async function handleReset() {
  if (!el.resetBtn.classList.contains('confirming')) {
    el.resetBtn.classList.add('confirming');
    el.resetBtn.textContent = 'Click again to permanently erase session data';
    clearTimeout(resetConfirmTimer);
    resetConfirmTimer = setTimeout(cancelResetConfirm, RESET_CONFIRM_MS);
    return;
  }
  cancelResetConfirm();
  const res = await sendMessage({ type: 'reset' });
  if (res.ok) {
    showToast('Session reset. All metrics cleared.', 'ok');
  } else {
    showToast('Reset failed: ' + (res.error || 'unknown error'), 'error');
  }
  await refresh();
}

function cancelResetConfirm() {
  clearTimeout(resetConfirmTimer);
  el.resetBtn.classList.remove('confirming');
  el.resetBtn.textContent = 'Reset Session';
}

async function handleToggle() {
  const enabled = el.enabledToggle.checked;
  el.enabledToggle.disabled = true;
  const res = await sendMessage({ type: 'setEnabled', enabled });
  el.enabledToggle.disabled = false;
  if (!res.ok) {
    el.enabledToggle.checked = !enabled;
    showToast('Could not change shadowing: ' + (res.error || 'unknown error'), 'error');
  } else {
    showToast(enabled ? 'Shadowing is ON.' : 'Shadowing is OFF.', enabled ? 'ok' : '');
  }
  await refresh();
}

function handleRoleInput() {
  clearTimeout(roleSaveTimer);
  roleSaveTimer = setTimeout(async () => {
    profile.roleTag = el.roleInput.value.trim().slice(0, 40);
    await saveProfile();
  }, 300);
}

/* ------------------------------------------------------------------ */
/* Rendering                                                           */
/* ------------------------------------------------------------------ */

function renderStatus(enabled, tracking, idleState) {
  el.enabledToggle.checked = enabled;
  el.statusPill.classList.remove('on', 'paused');
  if (!enabled) {
    el.statusPill.textContent = 'OFF';
    el.toggleSub.textContent = 'Tracking is paused';
  } else if (tracking) {
    el.statusPill.textContent = '● LIVE';
    el.statusPill.classList.add('on');
    el.toggleSub.textContent = 'Passively shadowing domains & time';
  } else {
    el.statusPill.textContent = 'PAUSED';
    el.statusPill.classList.add('paused');
    el.toggleSub.textContent =
      idleState && idleState !== 'active'
        ? 'Auto-paused: you are ' + idleState
        : 'Auto-paused: no tracked tab in focus';
  }
}

function renderLoop(loop) {
  el.statLoop.textContent = '';
  if (!loop) {
    const empty = document.createElement('div');
    empty.className = 'empty';
    empty.textContent = 'No repeated loops detected yet';
    el.statLoop.appendChild(empty);
    return;
  }
  const seq = document.createElement('div');
  seq.className = 'loop-seq';
  loop.sequence.forEach((domain, i) => {
    if (i > 0) {
      const arrow = document.createElement('span');
      arrow.className = 'arrow';
      arrow.textContent = '→';
      seq.appendChild(arrow);
    }
    seq.appendChild(document.createTextNode(domain));
  });
  const count = document.createElement('span');
  count.className = 'loop-count';
  count.textContent = `Repeated ${loop.repeatCount}×`;
  el.statLoop.appendChild(seq);
  el.statLoop.appendChild(count);
}

function renderTopTools(entries) {
  el.topTools.textContent = '';
  const top = entries.filter((e) => e.activeSeconds > 0).slice(0, 5);
  if (top.length === 0) {
    const li = document.createElement('li');
    li.className = 'empty';
    li.textContent = 'Nothing recorded yet';
    el.topTools.appendChild(li);
    return;
  }
  top.forEach((e) => {
    const li = document.createElement('li');
    const d = document.createElement('span');
    d.className = 'd';
    d.textContent = e.domain;
    const m = document.createElement('span');
    m.className = 'm';
    m.textContent = `${formatMinutes(e.activeSeconds)} · ${e.visitCount} visit${e.visitCount === 1 ? '' : 's'}`;
    li.appendChild(d);
    li.appendChild(m);
    el.topTools.appendChild(li);
  });
}

function renderMetrics(metrics) {
  const summary = computeSummary(metrics);
  el.statHours.textContent = (summary.totalSeconds / 3600).toFixed(2);
  el.statTools.textContent = String(summary.entries.length);
  el.statSwitches.textContent = String(summary.rapidContextSwitches);
  renderLoop(summary.loops[0] || null);
  renderTopTools(summary.entries);
  el.updatedAt.textContent = 'updated ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

async function refresh() {
  const flush = await sendMessage({ type: 'flush' });
  const { settings, metrics } = await chrome.storage.local.get(['settings', 'metrics']);
  latestMetrics = metrics || null;
  const enabled = !!(settings && settings.enabled);
  renderStatus(enabled, !!(flush && flush.tracking), flush && flush.idleState);
  renderMetrics(latestMetrics);
}

/* ------------------------------------------------------------------ */
/* Init                                                                */
/* ------------------------------------------------------------------ */

async function init() {
  await loadProfile();
  el.roleInput.value = profile.roleTag || '';
  el.webhookInput.value = profile.webhookUrl || '';
  renderGate();

  el.enabledToggle.addEventListener('change', handleToggle);
  el.roleInput.addEventListener('input', handleRoleInput);
  el.gateBtn.addEventListener('click', handleUnlock);
  el.gateInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') handleUnlock();
  });
  el.lockBtn.addEventListener('click', handleLock);
  el.downloadBtn.addEventListener('click', handleDownload);
  el.copyPromptBtn.addEventListener('click', handleCopyPrompt);
  el.webhookBtn.addEventListener('click', handleWebhook);
  el.webhookDetails.addEventListener('toggle', () => {
    if (!isBusiness() && el.webhookDetails.open) el.webhookDetails.open = false;
  });
  el.syncBtn.addEventListener('click', handleSync);
  el.portalBtn.addEventListener('click', () => {
    openTab((profile.access && profile.access.portalUrl) || BUSINESS_BASE_URL + '/portal.php');
  });
  el.upgradeBtn.addEventListener('click', () => {
    const email = profile.access && profile.access.mode === 'email' ? profile.access.value : '';
    openTab(BUSINESS_BASE_URL + '/' + (email ? '?email=' + encodeURIComponent(email) : ''));
  });
  el.resetBtn.addEventListener('click', handleReset);

  await refresh();
  refreshTimer = setInterval(refresh, REFRESH_MS);
  revalidateBusinessKey();
}

window.addEventListener('pagehide', () => {
  clearInterval(refreshTimer);
  // Persist any pending role edit immediately.
  if (roleSaveTimer) {
    clearTimeout(roleSaveTimer);
    profile.roleTag = el.roleInput.value.trim().slice(0, 40);
    chrome.storage.local.set({ profile });
  }
});

document.addEventListener('DOMContentLoaded', init);
