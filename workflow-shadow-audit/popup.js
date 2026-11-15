/*
 * Workflow Shadow Audit — popup controller
 *
 * Screens:
 *   Free:      Start → Tracking (progress to 5 hours) → Results ready (email) → Results
 *   Business:  Tracking (no limit) + live Results + portal sync, webhook, new audit
 *
 * Storage key owned by the popup:
 *   profile : { roleTag, access: { mode, tier, value, ... } | null, webhookUrl }
 * Storage keys owned by background.js: settings, metrics, runtime, trial
 */

'use strict';

// Where your Business server (the /business folder) is hosted. No trailing slash.
const BUSINESS_BASE_URL = 'https://sirenaflow.com/business';
const REVALIDATE_MS = 12 * 60 * 60 * 1000;        // re-check a Business key twice a day
const OFFLINE_GRACE_MS = 7 * 24 * 60 * 60 * 1000;  // keep working offline for up to 7 days

const REFRESH_MS = 5000;
const MIN_LOOP_REPEATS = 2;
const MAX_EXPORT_LOOPS = 25;
const RESET_CONFIRM_MS = 4000;
const LOOP_SEPARATOR = ' -> ';
const DEFAULT_LITE_TARGET = 5 * 60 * 60;

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
  viewStart: $('viewStart'),
  viewTracking: $('viewTracking'),
  viewReady: $('viewReady'),
  viewResults: $('viewResults'),
  startBtn: $('startBtn'),
  enabledToggle: $('enabledToggle'),
  trackTitle: $('trackTitle'),
  trackSub: $('trackSub'),
  progressBox: $('progressBox'),
  progressText: $('progressText'),
  progressLeft: $('progressLeft'),
  progressBar: $('progressBar'),
  statHours: $('statHours'),
  statTools: $('statTools'),
  roleRowTracking: $('roleRowTracking'),
  roleInputs: Array.from(document.querySelectorAll('.role-input')),
  industrySelects: Array.from(document.querySelectorAll('.industry-select')),
  industryLine: $('industryLine'),
  emailInput: $('emailInput'),
  emailBtn: $('emailBtn'),
  emailMsg: $('emailMsg'),
  resultsTitle: $('resultsTitle'),
  resultsBody: $('resultsBody'),
  copyPromptBtn: $('copyPromptBtn'),
  downloadBtn: $('downloadBtn'),
  liteDoneBox: $('liteDoneBox'),
  businessBox: $('businessBox'),
  syncBtn: $('syncBtn'),
  portalBtn: $('portalBtn'),
  webhookInput: $('webhookInput'),
  webhookBtn: $('webhookBtn'),
  resetBtn: $('resetBtn'),
  upgradeRunning: $('upgradeRunning'),
  upgradeBtns: Array.from(document.querySelectorAll('.upgrade-btn')),
  toast: $('toast'),
  botsBox: $('botsBox'),
  botsTitle: $('botsTitle'),
  botsSub: $('botsSub'),
  botFeed: $('botFeed'),
  sendNudge: $('sendNudge'),
  nudgeSendBtn: $('nudgeSendBtn'),
  teamNote: $('teamNote'),
  surveyBox: $('surveyBox'),
  surveyForm: $('surveyForm'),
  surveyThanks: $('surveyThanks'),
  surveySend: $('surveySend'),
  surveyComment: $('surveyComment'),
  helpLink: $('helpLink'),
  keyLinkRow: $('keyLinkRow'),
  keyLink: $('keyLink'),
  keyBox: $('keyBox'),
  keyInput: $('keyInput'),
  keyBtn: $('keyBtn'),
  keyMsg: $('keyMsg'),
  bizRow: $('bizRow'),
  bizText: $('bizText'),
  bizChange: $('bizChange')
};

let profile = { roleTag: '', industry: '', access: null, webhookUrl: '' };
let latestMetrics = null;
let status = { enabled: false, tracking: false, idleState: 'active', trial: {}, liteTargetSeconds: DEFAULT_LITE_TARGET };
let refreshTimer = null;
let resetConfirmTimer = null;
let toastTimer = null;
let roleSaveTimer = null;
let lastResultsKey = '';
let lastFeedKey = '';
const NUDGE_AFTER_SECONDS = 4 * 60 * 60;          // remind team members to send after 4 tracked hours
const NUDGE_AGAIN_MS = 3 * 24 * 60 * 60 * 1000;    // ...and again 3 days after their last send

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

function setMsg(node, text, kind) {
  node.textContent = text;
  node.className = 'msg' + (kind ? ' ' + kind : '');
}

function round1(n) {
  return Math.round(n * 10) / 10;
}

function hours(seconds) {
  return (seconds / 3600).toFixed(1);
}

function slugify(text) {
  const slug = String(text || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  return slug || 'general';
}

function localDateStamp(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

function openTab(url) {
  chrome.tabs.create({ url });
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

/* ------------------------------------------------------------------ */
/* Tiers                                                               */
/* ------------------------------------------------------------------ */

function currentTier() {
  const a = profile.access;
  if (!a || !a.mode || !a.value) return 'free';
  if (a.mode === 'key' && a.tier === 'business') return 'business';
  if (a.mode === 'email') return 'lite';
  return 'free';
}

function isBusiness() {
  return currentTier() === 'business';
}

function hasEmail() {
  return currentTier() === 'lite';
}

/* ------------------------------------------------------------------ */
/* Profile                                                             */
/* ------------------------------------------------------------------ */

async function loadProfile() {
  const { profile: stored } = await chrome.storage.local.get('profile');
  profile = Object.assign({ roleTag: '', industry: '', access: null, webhookUrl: '' }, stored || {});
}

async function saveProfile() {
  await chrome.storage.local.set({ profile });
}

/** Random ID for this browser, so a team key counts one seat per person. */
async function getDeviceId() {
  if (!profile.deviceId) {
    profile.deviceId = crypto.randomUUID();
    await saveProfile();
  }
  return profile.deviceId;
}

function accessFromResult(key, result, previous) {
  return Object.assign({}, previous || {}, {
    mode: 'key',
    tier: 'business',
    value: key,
    plan: result.plan || 'Team Audit',
    company: result.company || '',
    accessUntil: result.accessUntil || null,
    monitoring: !!result.monitoring,
    solo: !!result.solo,
    portalUrl: result.portalUrl || BUSINESS_BASE_URL + '/portal.php',
    validatedAt: new Date().toISOString()
  });
}

function handleRoleInput(e) {
  const value = e.target.value;
  el.roleInputs.forEach((input) => {
    if (input !== e.target) input.value = value;
  });
  clearTimeout(roleSaveTimer);
  roleSaveTimer = setTimeout(async () => {
    profile.roleTag = value.trim().slice(0, 40);
    await saveProfile();
    roleSaveTimer = null;
  }, 300);
}

function fillIndustrySelects() {
  el.industrySelects.forEach((select) => {
    select.textContent = '';
    const blank = document.createElement('option');
    blank.value = '';
    blank.textContent = 'Choose your industry…';
    select.appendChild(blank);
    Object.keys(INDUSTRIES).forEach((key) => {
      const opt = document.createElement('option');
      opt.value = key;
      opt.textContent = INDUSTRIES[key].label;
      select.appendChild(opt);
    });
    select.value = profile.industry || '';
    select.addEventListener('change', async (e) => {
      profile.industry = INDUSTRIES[e.target.value] ? e.target.value : '';
      el.industrySelects.forEach((other) => {
        other.value = profile.industry;
      });
      await saveProfile();
      renderIndustryLine();
    });
  });
  renderIndustryLine();
}

function renderIndustryLine() {
  const industry = INDUSTRIES[profile.industry];
  el.industryLine.textContent = industry
    ? `${industry.label} checks: ${industry.checks.join(', ')}`
    : 'Checks for your industry: insurance, lending, credit repair, real estate and more';
}

/* ------------------------------------------------------------------ */
/* Email (free results) and team key                                   */
/* ------------------------------------------------------------------ */

function validateWorkEmail(value) {
  const match = /^[^\s@]+@([^\s@]+\.[^\s@]{2,})$/.exec(value);
  if (!match) return { ok: false, reason: 'Please enter a valid email.' };
  if (FREE_EMAIL_DOMAINS.has(match[1].toLowerCase())) {
    return { ok: false, reason: 'Please use your work email.' };
  }
  return { ok: true };
}

async function handleEmail() {
  const raw = el.emailInput.value.trim();
  const check = validateWorkEmail(raw);
  if (!check.ok) {
    setMsg(el.emailMsg, check.reason, 'error');
    return;
  }
  const email = raw.toLowerCase();
  profile.access = { mode: 'email', tier: 'lite', value: email, unlockedAt: new Date().toISOString() };
  await saveProfile();
  const insight = buildInsights(latestMetrics);
  postJson('/api/lead.php', {
    email,
    roleTag: profile.roleTag || '',
    industry: profile.industry || '',
    bottleneck: insight ? insight.main.area : '',
    agent: insight ? insight.main.agent : '',
    agentDoes: insight ? insight.main.does : '',
    task: insight ? insight.main.task : ''
  }).catch(() => {});
  render();
}

function validateKeyFormat(value) {
  return /^AUDIT-[A-Z0-9][A-Z0-9-]{3,}$/i.test(value);
}

async function handleKey() {
  const key = el.keyInput.value.trim().toUpperCase();
  if (!validateKeyFormat(key)) {
    setMsg(el.keyMsg, 'Keys look like AUDIT-XXXX-XXXX-XXXX-XXXX', 'error');
    return;
  }
  el.keyBtn.disabled = true;
  setMsg(el.keyMsg, 'Checking…', '');
  await saveProfile();
  let result;
  try {
    result = await postJson('/api/validate.php', { key, deviceId: await getDeviceId(), roleTag: profile.roleTag || '' });
  } catch (e) {
    el.keyBtn.disabled = false;
    setMsg(el.keyMsg, "Couldn't connect. Check your internet and try again.", 'error');
    return;
  }
  el.keyBtn.disabled = false;
  if (!result.ok || !result.valid) {
    setMsg(el.keyMsg, result.reason || result.error || "That key didn't work.", 'error');
    return;
  }
  profile.access = accessFromResult(key, result, { unlockedAt: new Date().toISOString() });
  await saveProfile();
  el.keyInput.value = '';
  setMsg(el.keyMsg, '', '');
  el.keyBox.classList.add('hidden');
  showToast('Team key connected.', 'ok');
  await refresh();
}

async function handleChangeKey() {
  profile.access = null;
  await saveProfile();
  el.keyBox.classList.remove('hidden');
  await refresh();
}

/** Re-check a team key so ended audits and canceled tracking lose access. */
async function revalidateBusinessKey(force) {
  const a = profile.access;
  if (!a || a.mode !== 'key') return;
  const last = a.validatedAt ? Date.parse(a.validatedAt) : 0;
  if (!force && a.tier === 'business' && Date.now() - last < REVALIDATE_MS) return;

  try {
    const result = await postJson('/api/validate.php', { key: a.value, deviceId: await getDeviceId(), roleTag: profile.roleTag || '' });
    if (result.ok && result.valid) {
      profile.access = accessFromResult(a.value, result, a);
    } else if (result.ok && !result.valid) {
      profile.access = null;
      showToast(result.reason || 'Your team key is no longer active.', 'error');
    }
  } catch (e) {
    if (Date.now() - last > OFFLINE_GRACE_MS) {
      profile.access = null;
      showToast('Connect to the internet to re-check your team key.', 'error');
    }
  }
  await saveProfile();
  await refresh();
}

/* ------------------------------------------------------------------ */
/* Data export (matches the documented JSON schema)                    */
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

function buildAnalysisPrompt(payload) {
  const insight = buildInsights(latestMetrics);
  const quick = insight
    ? [
        `- Biggest bottleneck area: ${insight.main.area}`,
        `- Most repeated task: ${insight.loop ? insight.loop.names.join(' → ') + ` (${insight.loop.count} times)` : 'no clear repeat yet'}`,
        `- Suggested agent: ${insight.main.agent} — ${insight.main.does}`
      ].join('\n')
    : '- Not enough data yet';

  return [
    'You are an AI operations consultant. Write a short, simple report for a busy business owner.',
    'Use plain words. No jargon. Short sentences. Numbers first.',
    '',
    'The data below comes from a private, read-only browser tracker. It only saw website names, time spent,',
    'how often the person came back to each site, 4-site patterns they repeated, and quick tab jumps',
    '(less than 30 seconds apart). It never saw page content or anything typed.',
    '',
    `Role: ${payload.auditMetadata.roleTag}`,
    `Time tracked: ${round1(payload.auditMetadata.totalActiveMinutes / 60)} hours`,
    '',
    'Quick findings from the tool:',
    quick,
    '',
    'Write these sections:',
    '1. Your biggest bottleneck — which part of the business is slowed down, and why (1–2 sentences).',
    '2. Your repetitive tasks — the top 3, in plain words, and what each one probably is.',
    '3. The AI agents you need — for each task: the agent name, what it does, and which tools it connects.',
    '4. How to fix it — a simple step-by-step process for the #1 task (5 steps max).',
    '5. What it is costing you — hours per week and dollars per month. Assume $45/hour unless told otherwise,',
    '   and say clearly that these are estimates.',
    '6. Tools you might not need — software that overlaps or is barely used.',
    '7. Your next 30 days — one simple action per week.',
    '',
    'Only use the data below. If you have to guess, say so.',
    '',
    'Data (JSON):',
    '```json',
    JSON.stringify(payload, null, 2),
    '```'
  ].join('\n');
}

/* ------------------------------------------------------------------ */
/* Actions                                                             */
/* ------------------------------------------------------------------ */

async function refreshForExport() {
  await sendMessage({ type: 'flush' });
  const { metrics } = await chrome.storage.local.get('metrics');
  latestMetrics = metrics || null;
}

function canSeeResults() {
  return isBusiness() || hasEmail();
}

async function handleStart() {
  const res = await sendMessage({ type: 'setEnabled', enabled: true });
  if (!res.ok) {
    showToast(res.error === 'trial_used' ? 'Your free audit is used. Upgrade to keep going.' : "Couldn't start. Try again.", 'error');
  } else {
    showToast("You're all set. Work like normal.", 'ok');
  }
  await refresh();
}

async function handleToggle() {
  const enabled = el.enabledToggle.checked;
  el.enabledToggle.disabled = true;
  const res = await sendMessage({ type: 'setEnabled', enabled });
  el.enabledToggle.disabled = false;
  if (!res.ok) {
    el.enabledToggle.checked = !enabled;
    showToast(res.error === 'trial_used' ? 'Your free audit is used. Upgrade to keep going.' : "Couldn't change tracking. Try again.", 'error');
  }
  await refresh();
}

async function handleDownload() {
  if (!canSeeResults()) return;
  await refreshForExport();
  const payload = buildPayload();
  const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
  const url = URL.createObjectURL(blob);
  const filename = `workflow-audit-${slugify(payload.auditMetadata.roleTag)}-${localDateStamp(new Date())}.json`;
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 10000);
  showToast('Saved ' + filename, 'ok');
}

async function copyText(text) {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch (e) {
    const ta = document.createElement('textarea');
    ta.value = text;
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
  if (!canSeeResults()) return;
  await refreshForExport();
  const ok = await copyText(buildAnalysisPrompt(buildPayload()));
  showToast(ok ? 'Copied! Paste it into ChatGPT or Claude.' : "Couldn't copy. Try again.", ok ? 'ok' : 'error');
}

async function handleSync() {
  if (!isBusiness()) return;
  await refreshForExport();
  const payload = buildPayload();
  if (payload.softwareUsage.length === 0) {
    showToast('Nothing to send yet. Work a little first.', 'error');
    return;
  }
  el.syncBtn.disabled = true;
  el.syncBtn.textContent = 'Sending…';
  try {
    const result = await postJson('/api/sync.php', { key: profile.access.value, deviceId: await getDeviceId(), payload });
    if (result.ok) {
      profile.lastSyncAt = new Date().toISOString();
      await saveProfile();
      showToast('Sent to your Business Portal.', 'ok');
      render();
    } else {
      showToast(result.error || "Couldn't send. Try again.", 'error');
      if (/not active|seats/i.test(result.error || '')) await revalidateBusinessKey(true);
    }
  } catch (e) {
    showToast("Couldn't connect. Try again shortly.", 'error');
  } finally {
    el.syncBtn.textContent = 'Send to my Business Portal';
    el.syncBtn.disabled = false;
  }
}

function validateWebhookUrl(value) {
  let url;
  try {
    url = new URL(value);
  } catch (e) {
    return { ok: false, reason: 'Paste the full webhook link (starts with https://).' };
  }
  const isLocal = url.hostname === 'localhost' || url.hostname === '127.0.0.1';
  if (url.protocol !== 'https:' && !(url.protocol === 'http:' && isLocal)) {
    return { ok: false, reason: 'The webhook link must start with https://' };
  }
  return { ok: true, url: url.toString() };
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
    try {
      const res = await fetch(check.url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body });
      delivered = res.ok;
    } catch (corsOrNetworkError) {
      // Many webhook tools don't answer browser CORS checks. Resend as a simple
      // request: it still arrives, we just can't read the reply.
      await fetch(check.url, { method: 'POST', mode: 'no-cors', headers: { 'Content-Type': 'text/plain;charset=UTF-8' }, body });
      delivered = true;
    }
    showToast(delivered ? 'Sent.' : 'The webhook said no. Check the link.', delivered ? 'ok' : 'error');
  } catch (e) {
    showToast("Couldn't send. Check the link and try again.", 'error');
  } finally {
    el.webhookBtn.disabled = false;
    el.webhookBtn.textContent = 'Send';
  }
}

async function handleReset() {
  if (!el.resetBtn.classList.contains('confirming')) {
    el.resetBtn.classList.add('confirming');
    el.resetBtn.textContent = 'Click again to erase this audit and start over';
    clearTimeout(resetConfirmTimer);
    resetConfirmTimer = setTimeout(cancelResetConfirm, RESET_CONFIRM_MS);
    return;
  }
  cancelResetConfirm();
  const res = await sendMessage({ type: 'reset' });
  showToast(res.ok ? 'New audit started.' : res.error || "Couldn't start over.", res.ok ? 'ok' : 'error');
  await refresh();
}

function cancelResetConfirm() {
  clearTimeout(resetConfirmTimer);
  el.resetBtn.classList.remove('confirming');
  el.resetBtn.textContent = 'Start a new audit';
}

function openUpgrade() {
  const params = new URLSearchParams();
  if (profile.access && profile.access.mode === 'email') params.set('email', profile.access.value);
  if (profile.industry) params.set('industry', profile.industry);
  if (!isBusiness()) params.set('plan', 'solo');
  const query = params.toString();
  openTab(BUSINESS_BASE_URL + '/' + (query ? '?' + query : ''));
}

/* ------------------------------------------------------------------ */
/* Survey (end of the free audit, or after a team member sends)        */
/* ------------------------------------------------------------------ */

const survey = { accurate: '', nps: '', wantsHelp: '' };

function setupSurvey() {
  const npsRow = document.querySelector('.seg.nps');
  for (let n = 0; n <= 10; n++) {
    const b = document.createElement('button');
    b.type = 'button';
    b.dataset.v = String(n);
    b.textContent = String(n);
    npsRow.appendChild(b);
  }
  document.querySelectorAll('.seg').forEach((seg) => {
    seg.addEventListener('click', (e) => {
      const btn = e.target.closest('button');
      if (!btn) return;
      seg.querySelectorAll('button').forEach((b) => b.classList.toggle('on', b === btn));
      survey[seg.dataset.q] = btn.dataset.v;
      el.surveySend.disabled = !(survey.accurate && survey.wantsHelp);
    });
  });
  el.surveySend.addEventListener('click', sendSurvey);
}

function surveyWanted() {
  if (profile.surveyDone) return false;
  if (isBusiness()) return !!profile.lastSyncAt;     // team member: after they send their audit
  return hasEmail() && !!(status.trial && status.trial.completedAt); // free: after results
}

async function sendSurvey() {
  el.surveySend.disabled = true;
  const insight = buildInsights(latestMetrics);
  const body = {
    accurate: survey.accurate,
    nps: survey.nps === '' ? null : Number(survey.nps),
    wantsHelp: survey.wantsHelp === 'yes',
    comment: el.surveyComment.value.trim(),
    industry: profile.industry || '',
    agent: insight ? insight.main.agent : ''
  };
  if (isBusiness()) {
    Object.assign(body, { context: 'team_member', key: profile.access.value, deviceId: await getDeviceId() });
  } else {
    Object.assign(body, { context: 'free', email: profile.access.value });
  }
  try {
    await postJson('/api/feedback.php', body);
  } catch (e) {
    // Never block the user on feedback.
  }
  profile.surveyDone = true;
  await saveProfile();
  el.surveyForm.classList.add('hidden');
  el.surveyThanks.textContent = body.wantsHelp ? "Thank you! We'll reach out soon." : 'Thank you! That helps us a lot.';
  el.surveyThanks.classList.remove('hidden');
}

/* ------------------------------------------------------------------ */
/* Rendering                                                           */
/* ------------------------------------------------------------------ */

function show(node, visible) {
  node.classList.toggle('hidden', !visible);
}

function flowNode(names, className) {
  const div = document.createElement('div');
  div.className = className || 'flow';
  names.forEach((name, i) => {
    if (i > 0) {
      const arrow = document.createElement('span');
      arrow.className = 'arrow';
      arrow.textContent = '→';
      div.appendChild(arrow);
    }
    div.appendChild(document.createTextNode(name));
  });
  return div;
}

function textNode(tag, text, className) {
  const n = document.createElement(tag);
  if (className) n.className = className;
  n.textContent = text;
  return n;
}

function duration(seconds) {
  if (seconds < 60) return 'under a minute';
  const mins = Math.round(seconds / 60);
  if (mins < 60) return mins + ' min';
  const h = Math.floor(mins / 60);
  const m = mins % 60;
  return h + ' h' + (m ? ' ' + m + ' min' : '');
}

/** The bots' live feed: only things the extension really saw. */
function buildFeed(insight, tracking, enabled, business) {
  const items = [];
  if (!enabled) {
    items.push({ text: 'Tracking is off. Turn it on to wake the bots.' });
    return items;
  }
  if (!tracking) {
    items.push({ text: status.idleState && status.idleState !== 'active' ? "Paused while you're away." : 'Paused while Chrome is in the background.' });
  }
  if (!insight) {
    items.push({ text: 'Waiting for you to open your first work tool…', working: tracking });
    return items;
  }
  if (tracking) {
    items.push({
      text: business && insight.main ? `Best match so far: ${insight.main.agent}` : 'Matching your tasks to AI agents…',
      working: true
    });
  }
  if (insight.loop) {
    items.push({ text: `Pattern spotted: ${insight.loop.names.join(' → ')} (${insight.loop.count} times)` });
  }
  if (insight.quickSwitches > 0) {
    items.push({ text: `${insight.quickSwitches} quick tab switches so far` });
  }
  insight.topTools.slice(0, 3).forEach((t) => items.push({ text: `Noticed ${t.name}: ${duration(t.seconds)}` }));
  return items.slice(0, 5);
}

function renderBots(insight, business) {
  const tracking = !!status.tracking;
  el.botsBox.classList.toggle('sleeping', !tracking);
  el.botsTitle.textContent = tracking ? 'Your audit bots are working' : status.enabled ? 'Your audit bots are resting' : 'Your audit bots are off';
  el.botsSub.textContent = tracking ? 'Checking as you work · read-only' : 'Nothing is being checked right now';

  const items = buildFeed(insight, tracking, !!status.enabled, business);
  const key = JSON.stringify(items);
  if (key === lastFeedKey) return;
  const old = new Set(Array.from(el.botFeed.children).map((li) => li.textContent));
  lastFeedKey = key;
  el.botFeed.textContent = '';
  items.forEach((item) => {
    const li = document.createElement('li');
    li.textContent = item.text;
    if (item.working) li.classList.add('working');
    if (old.size && !old.has(item.text)) li.classList.add('new');
    el.botFeed.appendChild(li);
  });
}

function renderStats(insight, totalSeconds) {
  el.statHours.textContent = hours(totalSeconds);
  el.statTools.textContent = String(insight ? insight.toolCount : 0);
}

function renderResults(insight) {
  const key = JSON.stringify(insight);
  if (key === lastResultsKey) return; // avoid re-drawing every 5 seconds
  lastResultsKey = key;

  const body = el.resultsBody;
  body.textContent = '';
  const wrap = document.createElement('div');
  wrap.style.display = 'flex';
  wrap.style.flexDirection = 'column';
  wrap.style.gap = '10px';

  if (!insight) {
    wrap.appendChild(textNode('p', 'Not enough data yet. Keep working and check back soon.', 'muted'));
    body.appendChild(wrap);
    return;
  }

  // 1. Bottleneck + repetitive task
  const task = document.createElement('div');
  task.className = 'result';
  task.appendChild(textNode('span', 'Bottleneck: ' + insight.main.area, 'tag'));
  task.appendChild(textNode('div', 'Your most repeated task', 'label'));
  if (insight.loop) {
    task.appendChild(flowNode(insight.loop.names));
    task.appendChild(textNode('div', `You did this ${insight.loop.count} times.`, 'muted small'));
  }
  task.appendChild(textNode('div', insight.main.task));
  const pct = insight.totalSeconds ? Math.round((insight.focusSeconds / insight.totalSeconds) * 100) : 0;
  task.appendChild(textNode('div',
    `Time in these tools: ${hours(insight.focusSeconds)} of ${hours(insight.totalSeconds)} hours (${pct}%).`, 'muted small'));
  wrap.appendChild(task);

  // 2. Agent
  const agent = document.createElement('div');
  agent.className = 'result agent';
  agent.appendChild(textNode('div', 'The AI agent you need', 'label'));
  agent.appendChild(textNode('div', insight.main.agent, 'big'));
  agent.appendChild(textNode('div', insight.main.does));
  wrap.appendChild(agent);

  // 3. Process
  const fix = document.createElement('div');
  fix.className = 'result';
  fix.appendChild(textNode('div', 'How to fix it', 'label'));
  const ol = document.createElement('ol');
  ol.className = 'steps';
  insight.main.steps.forEach((s) => ol.appendChild(textNode('li', s)));
  fix.appendChild(ol);
  wrap.appendChild(fix);

  // 4. Also worth fixing
  if (insight.extras.length) {
    const more = document.createElement('div');
    more.className = 'result';
    more.appendChild(textNode('div', 'Also worth fixing', 'label'));
    insight.extras.forEach((x) => {
      const line = document.createElement('div');
      line.appendChild(textNode('b', x.agent));
      line.appendChild(document.createTextNode(' — ' + x.does));
      more.appendChild(line);
    });
    wrap.appendChild(more);
  }

  body.appendChild(wrap);
}

function render() {
  const tier = currentTier();
  const business = tier === 'business';
  const trial = status.trial || {};
  const target = status.liteTargetSeconds || DEFAULT_LITE_TARGET;
  const totalSeconds = computeSummary(latestMetrics).totalSeconds;
  const insight = buildInsights(latestMetrics);

  const liteNotStarted = !business && !trial.startedAt;
  const liteRunning = !business && !!trial.startedAt && !trial.completedAt;
  const liteDone = !business && !!trial.completedAt;

  // Screens
  show(el.viewStart, liteNotStarted);
  show(el.viewTracking, business || liteRunning);
  show(el.progressBox, liteRunning);
  show(el.roleRowTracking, business);
  show(el.viewReady, liteDone && !hasEmail());
  show(el.viewResults, (business && !!insight) || (liteDone && hasEmail()));
  show(el.liteDoneBox, !business);
  show(el.businessBox, business);
  show(el.upgradeRunning, liteRunning);

  // Status pill
  el.statusPill.className = 'pill';
  if (liteDone) {
    el.statusPill.textContent = 'RESULTS READY';
    el.statusPill.classList.add('done');
  } else if (liteNotStarted) {
    el.statusPill.textContent = '';
  } else if (!status.enabled) {
    el.statusPill.textContent = 'OFF';
  } else if (status.tracking) {
    el.statusPill.textContent = '● ON';
    el.statusPill.classList.add('on');
  } else {
    el.statusPill.textContent = 'PAUSED';
    el.statusPill.classList.add('paused');
  }
  show(el.statusPill, !!el.statusPill.textContent);

  // Tracking card
  el.enabledToggle.checked = !!status.enabled;
  if (!status.enabled) {
    el.trackTitle.textContent = 'Tracking is off';
    el.trackSub.textContent = 'Turn it on to continue.';
  } else if (status.tracking) {
    el.trackTitle.textContent = 'Tracking';
    el.trackSub.textContent = 'Work like normal.';
  } else {
    el.trackTitle.textContent = 'Paused';
    el.trackSub.textContent =
      status.idleState && status.idleState !== 'active'
        ? "Paused while you're away."
        : 'Paused while Chrome is in the background.';
  }

  if (liteRunning) {
    const pct = Math.min(100, Math.round((totalSeconds / target) * 100));
    el.progressBar.style.width = pct + '%';
    el.progressText.textContent = `${hours(totalSeconds)} of ${target / 3600} hours`;
    const left = Math.max(0, target - totalSeconds);
    el.progressLeft.textContent = left > 0 ? `about ${hours(left)} hrs to go` : 'almost done…';
  }

  renderStats(insight, totalSeconds);
  renderBots(insight, business);

  // Team members: what the company sees, and a reminder to send. (Solo buyers are the owner.)
  const solo = business && !!(profile.access && profile.access.solo);
  show(el.teamNote, business && !solo);
  $('nudgeTitle').textContent = solo ? 'Ready to send your audit?' : 'Your company is waiting for your audit.';
  if (!el.surveyThanks.classList.contains('hidden')) {
    show(el.surveyBox, true);
  } else {
    show(el.surveyBox, surveyWanted() && !el.viewResults.classList.contains('hidden'));
  }
  const lastSync = profile.lastSyncAt ? Date.parse(profile.lastSyncAt) : 0;
  show(el.sendNudge, business && totalSeconds >= NUDGE_AFTER_SECONDS && (!lastSync || Date.now() - lastSync > NUDGE_AGAIN_MS));

  // Results
  el.resultsTitle.textContent = business ? 'Your results so far' : 'Your results';
  if (!el.viewResults.classList.contains('hidden')) renderResults(insight);

  // Business key area
  show(el.keyLinkRow, !business && el.keyBox.classList.contains('hidden'));
  show(el.bizRow, business);
  if (business) {
    el.keyBox.classList.add('hidden');
    const a = profile.access;
    const until = a.accessUntil && !a.monitoring
      ? ' · until ' + new Date(a.accessUntil).toLocaleDateString([], { month: 'short', day: 'numeric' })
      : '';
    el.bizText.textContent = (a.plan || 'Team Audit') + (a.company ? ' · ' + a.company : '') + until;
  }
}

async function refresh() {
  const flush = await sendMessage({ type: 'flush' });
  const { metrics, settings, trial } = await chrome.storage.local.get(['metrics', 'settings', 'trial']);
  latestMetrics = metrics || null;
  status = {
    enabled: flush.ok ? !!flush.enabled : !!(settings && settings.enabled),
    tracking: !!flush.tracking,
    idleState: flush.idleState || 'active',
    trial: (flush.ok ? flush.trial : trial) || {},
    liteTargetSeconds: flush.liteTargetSeconds || DEFAULT_LITE_TARGET
  };
  render();
}

/* ------------------------------------------------------------------ */
/* Init                                                                */
/* ------------------------------------------------------------------ */

async function init() {
  await loadProfile();
  el.roleInputs.forEach((input) => {
    input.value = profile.roleTag || '';
    input.addEventListener('input', handleRoleInput);
  });
  el.webhookInput.value = profile.webhookUrl || '';
  fillIndustrySelects();

  el.startBtn.addEventListener('click', handleStart);
  el.enabledToggle.addEventListener('change', handleToggle);
  el.emailBtn.addEventListener('click', handleEmail);
  el.emailInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') handleEmail();
  });
  el.keyLink.addEventListener('click', () => {
    el.keyBox.classList.remove('hidden');
    el.keyLinkRow.classList.add('hidden');
    el.keyInput.focus();
  });
  el.keyBtn.addEventListener('click', handleKey);
  el.keyInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') handleKey();
  });
  el.bizChange.addEventListener('click', handleChangeKey);
  el.copyPromptBtn.addEventListener('click', handleCopyPrompt);
  el.downloadBtn.addEventListener('click', handleDownload);
  el.syncBtn.addEventListener('click', handleSync);
  el.nudgeSendBtn.addEventListener('click', handleSync);
  el.helpLink.addEventListener('click', () => openTab(BUSINESS_BASE_URL + '/help.php'));
  setupSurvey();
  el.portalBtn.addEventListener('click', () => {
    openTab((profile.access && profile.access.portalUrl) || BUSINESS_BASE_URL + '/portal.php');
  });
  el.webhookBtn.addEventListener('click', handleWebhook);
  el.resetBtn.addEventListener('click', handleReset);
  el.upgradeBtns.forEach((b) => b.addEventListener('click', openUpgrade));

  await refresh();
  refreshTimer = setInterval(refresh, REFRESH_MS);
  revalidateBusinessKey(false);
}

window.addEventListener('pagehide', () => {
  clearInterval(refreshTimer);
  if (roleSaveTimer) {
    clearTimeout(roleSaveTimer);
    const input = el.roleInputs.find((i) => i.value) || el.roleInputs[0];
    profile.roleTag = input.value.trim().slice(0, 40);
    chrome.storage.local.set({ profile });
  }
});

document.addEventListener('DOMContentLoaded', init);
