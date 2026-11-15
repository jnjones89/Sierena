/*
 * Workflow Shadow Audit — background service worker (Manifest V3)
 *
 * Privacy model:
 *   - Reads ONLY tab URLs exposed by the "tabs" permission, and immediately
 *     reduces them to a hostname (no scheme, path, query string or fragment).
 *   - No content scripts, no page access, no network calls from this worker.
 *   - All data stays in chrome.storage.local on this machine.
 *
 * Storage keys owned by this worker:
 *   settings : { enabled }
 *   metrics  : { sessionStartedAt, rapidContextSwitches, domains{}, loops{} }
 *   runtime  : transient tracking state (current segment, recent domains, ...)
 *
 * MV3 service workers can be terminated at any time, so every piece of state
 * lives in storage and every event handler runs through a serialized queue to
 * avoid read-modify-write races.
 */

'use strict';

const IDLE_THRESHOLD_SECONDS = 60;
const RAPID_SWITCH_WINDOW_MS = 30 * 1000;
const LOOP_LENGTH = 4;
const MAX_SEGMENT_MS = 3 * 60 * 60 * 1000; // sanity cap for a single uninterrupted segment
const MAX_LOOP_KEYS = 1500;
const LOOP_KEYS_AFTER_PRUNE = 750;
const LOOP_SEPARATOR = ' -> ';

const BADGE_COLOR_ON = '#16a34a';

/* ------------------------------------------------------------------ */
/* Defaults                                                            */
/* ------------------------------------------------------------------ */

function defaultSettings() {
  return { enabled: false };
}

function defaultMetrics() {
  return {
    sessionStartedAt: new Date().toISOString(),
    rapidContextSwitches: 0,
    domains: {},
    loops: {}
  };
}

function defaultRuntime() {
  return {
    currentDomain: null,
    segmentStart: null,
    lastDomain: null,
    recentDomains: [],
    lastSwitchAt: null,
    idleState: 'active',
    windowFocused: true,
    focusedWindowId: null
  };
}

/* ------------------------------------------------------------------ */
/* Serialized task queue                                               */
/* ------------------------------------------------------------------ */

let queue = Promise.resolve();

function enqueue(task) {
  const run = queue.then(task);
  queue = run.catch((err) => console.error('[ShadowAudit]', err));
  return run;
}

/* ------------------------------------------------------------------ */
/* Storage helpers                                                     */
/* ------------------------------------------------------------------ */

async function loadState() {
  const stored = await chrome.storage.local.get(['settings', 'metrics', 'runtime']);
  return {
    settings: Object.assign(defaultSettings(), stored.settings || {}),
    metrics: Object.assign(defaultMetrics(), stored.metrics || {}),
    runtime: Object.assign(defaultRuntime(), stored.runtime || {})
  };
}

async function saveState(state) {
  await chrome.storage.local.set({
    settings: state.settings,
    metrics: state.metrics,
    runtime: state.runtime
  });
}

/* ------------------------------------------------------------------ */
/* Domain sanitizing — the core privacy guarantee                      */
/* ------------------------------------------------------------------ */

function toDomain(rawUrl) {
  if (!rawUrl || typeof rawUrl !== 'string') return null;
  let parsed;
  try {
    parsed = new URL(rawUrl);
  } catch (e) {
    return null;
  }
  if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') return null;
  let host = (parsed.hostname || '').toLowerCase();
  if (!host) return null;
  if (host.startsWith('www.')) host = host.slice(4);
  return host;
}

function tabDomain(tab) {
  if (!tab) return null;
  return toDomain(tab.url || tab.pendingUrl || '');
}

/* ------------------------------------------------------------------ */
/* Badge                                                               */
/* ------------------------------------------------------------------ */

async function updateBadge(enabled) {
  try {
    await chrome.action.setBadgeBackgroundColor({ color: BADGE_COLOR_ON });
    if (chrome.action.setBadgeTextColor) {
      await chrome.action.setBadgeTextColor({ color: '#ffffff' });
    }
    await chrome.action.setBadgeText({ text: enabled ? 'ON' : '' });
    await chrome.action.setTitle({
      title: enabled
        ? 'Workflow Shadow Audit — shadowing ON'
        : 'Workflow Shadow Audit — shadowing OFF'
    });
  } catch (e) {
    console.error('[ShadowAudit] badge update failed', e);
  }
}

/* ------------------------------------------------------------------ */
/* Core tracking logic (pure-ish, operate on loaded state)             */
/* ------------------------------------------------------------------ */

function isTracking(state) {
  const rt = state.runtime;
  return state.settings.enabled && rt.idleState === 'active' && rt.windowFocused;
}

/** Credit the open segment (if any) to its domain and close it. */
function commitSegment(state, now, deductMs) {
  const rt = state.runtime;
  if (rt.currentDomain && typeof rt.segmentStart === 'number') {
    let elapsed = now - rt.segmentStart - (deductMs || 0);
    if (elapsed < 0) elapsed = 0;
    if (elapsed > MAX_SEGMENT_MS) elapsed = MAX_SEGMENT_MS;
    const seconds = Math.round(elapsed / 1000);
    if (seconds > 0) {
      const entry = ensureDomain(state.metrics, rt.currentDomain);
      entry.activeSeconds += seconds;
      entry.lastVisited = new Date(now).toISOString();
    }
  }
  rt.segmentStart = null;
}

/** Open a new segment for the current domain if we are allowed to track. */
function openSegment(state, now) {
  const rt = state.runtime;
  rt.segmentStart = rt.currentDomain && isTracking(state) ? now : null;
}

function ensureDomain(metrics, domain) {
  if (!metrics.domains[domain]) {
    metrics.domains[domain] = { activeSeconds: 0, visitCount: 0, lastVisited: null };
  }
  return metrics.domains[domain];
}

function recordLoop(state, domain) {
  const rt = state.runtime;
  const last = rt.recentDomains[rt.recentDomains.length - 1];
  if (last === domain) return;
  rt.recentDomains.push(domain);
  if (rt.recentDomains.length > LOOP_LENGTH) {
    rt.recentDomains = rt.recentDomains.slice(-LOOP_LENGTH);
  }
  if (rt.recentDomains.length === LOOP_LENGTH) {
    const key = rt.recentDomains.join(LOOP_SEPARATOR);
    const loops = state.metrics.loops;
    loops[key] = (loops[key] || 0) + 1;
    pruneLoops(loops);
  }
}

function pruneLoops(loops) {
  const keys = Object.keys(loops);
  if (keys.length <= MAX_LOOP_KEYS) return;
  keys
    .sort((a, b) => loops[b] - loops[a])
    .slice(LOOP_KEYS_AFTER_PRUNE)
    .forEach((k) => delete loops[k]);
}

/**
 * Called whenever the user's foreground context may have changed.
 * @param {object} state
 * @param {string|null} domain  sanitized domain of the now-active tab
 * @param {boolean} isTabSwitch true when triggered by a tab activation
 */
function applyContext(state, domain, isTabSwitch) {
  const now = Date.now();
  const rt = state.runtime;

  commitSegment(state, now, 0);

  if (state.settings.enabled && rt.idleState === 'active' && rt.windowFocused) {
    const domainChanged = !!domain && domain !== rt.lastDomain;

    // Rapid context switch: a switch within 30s of the previous switch.
    if (isTabSwitch || domainChanged) {
      if (typeof rt.lastSwitchAt === 'number' && now - rt.lastSwitchAt < RAPID_SWITCH_WINDOW_MS) {
        state.metrics.rapidContextSwitches += 1;
      }
      rt.lastSwitchAt = now;
    }

    if (domainChanged) {
      const entry = ensureDomain(state.metrics, domain);
      entry.visitCount += 1;
      entry.lastVisited = new Date(now).toISOString();
      recordLoop(state, domain);
      rt.lastDomain = domain;
    }
  }

  rt.currentDomain = domain;
  openSegment(state, now);
}

/* ------------------------------------------------------------------ */
/* Chrome API helpers                                                  */
/* ------------------------------------------------------------------ */

async function getActiveTabInWindow(windowId) {
  try {
    const tabs = await chrome.tabs.query({ active: true, windowId });
    return tabs[0] || null;
  } catch (e) {
    return null;
  }
}

async function getFocusedContext() {
  try {
    const win = await chrome.windows.getLastFocused({ populate: false });
    if (!win || win.id === undefined) return { windowId: null, focused: false, domain: null };
    const tab = await getActiveTabInWindow(win.id);
    return { windowId: win.id, focused: !!win.focused, domain: tabDomain(tab) };
  } catch (e) {
    return { windowId: null, focused: false, domain: null };
  }
}

async function queryIdleState() {
  try {
    return await chrome.idle.queryState(IDLE_THRESHOLD_SECONDS);
  } catch (e) {
    return 'active';
  }
}

/* ------------------------------------------------------------------ */
/* Sync with the browser (used on startup, enable, idle->active)        */
/* ------------------------------------------------------------------ */

async function resyncContext(state, { countAsSwitch }) {
  const ctx = await getFocusedContext();
  state.runtime.windowFocused = ctx.focused;
  state.runtime.focusedWindowId = ctx.windowId;
  applyContext(state, ctx.domain, !!countAsSwitch);
}

async function initialize({ freshBrowserSession }) {
  return enqueue(async () => {
    const state = await loadState();
    if (freshBrowserSession) {
      // Any open segment is from a previous browser session — never credit it.
      state.runtime.segmentStart = null;
      state.runtime.currentDomain = null;
      state.runtime.lastSwitchAt = null;
    }
    state.runtime.idleState = await queryIdleState();
    await resyncContext(state, { countAsSwitch: false });
    await saveState(state);
    await updateBadge(state.settings.enabled);
  });
}

/* ------------------------------------------------------------------ */
/* Event listeners (registered synchronously at top level)              */
/* ------------------------------------------------------------------ */

chrome.idle.setDetectionInterval(IDLE_THRESHOLD_SECONDS);

chrome.runtime.onInstalled.addListener(() => {
  initialize({ freshBrowserSession: true });
});

chrome.runtime.onStartup.addListener(() => {
  initialize({ freshBrowserSession: true });
});

chrome.tabs.onActivated.addListener(({ tabId, windowId }) => {
  enqueue(async () => {
    const state = await loadState();
    let tab = null;
    try {
      tab = await chrome.tabs.get(tabId);
    } catch (e) {
      tab = null;
    }
    state.runtime.focusedWindowId = windowId;
    applyContext(state, tabDomain(tab), true);
    await saveState(state);
  });
});

chrome.tabs.onUpdated.addListener((tabId, changeInfo, tab) => {
  if (!changeInfo.url || !tab || !tab.active) return;
  enqueue(async () => {
    const state = await loadState();
    const rt = state.runtime;
    if (rt.focusedWindowId !== null && tab.windowId !== rt.focusedWindowId) return;
    const domain = toDomain(changeInfo.url);
    if (domain === rt.currentDomain) return; // same-site navigation: keep segment running
    applyContext(state, domain, false);
    await saveState(state);
  });
});

chrome.windows.onFocusChanged.addListener((windowId) => {
  enqueue(async () => {
    const state = await loadState();
    const rt = state.runtime;
    if (windowId === chrome.windows.WINDOW_ID_NONE) {
      // Chrome lost focus (user is in another app): pause.
      commitSegment(state, Date.now(), 0);
      rt.windowFocused = false;
    } else {
      rt.windowFocused = true;
      rt.focusedWindowId = windowId;
      const tab = await getActiveTabInWindow(windowId);
      applyContext(state, tabDomain(tab), false);
    }
    await saveState(state);
  });
});

chrome.idle.onStateChanged.addListener((newState) => {
  enqueue(async () => {
    const state = await loadState();
    const rt = state.runtime;
    const now = Date.now();

    if (newState === 'active') {
      const missedIdleTransition = rt.idleState === 'active';
      rt.idleState = 'active';
      if (missedIdleTransition) {
        // We never saw the user go idle (e.g. machine slept); the open segment
        // length is unreliable, so drop it rather than over-count.
        rt.segmentStart = null;
      }
      await resyncContext(state, { countAsSwitch: false });
    } else {
      // "idle" means no input for the last 60s, so don't credit those 60s.
      commitSegment(state, now, newState === 'idle' ? IDLE_THRESHOLD_SECONDS * 1000 : 0);
      rt.idleState = newState;
    }
    await saveState(state);
  });
});

chrome.tabs.onRemoved.addListener(() => {
  // Removal of the active tab is followed by onActivated for the next tab,
  // which commits the segment. Nothing extra is needed here.
});

/* ------------------------------------------------------------------ */
/* Messages from the popup                                             */
/* ------------------------------------------------------------------ */

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  if (!message || typeof message.type !== 'string') return false;

  // Only accept messages from this extension's own pages.
  if (sender.id !== chrome.runtime.id) return false;

  const respond = (promise) => {
    promise
      .then((result) => sendResponse({ ok: true, ...(result || {}) }))
      .catch((err) => sendResponse({ ok: false, error: String(err && err.message ? err.message : err) }));
    return true; // keep channel open for async response
  };

  switch (message.type) {
    case 'flush':
      // Credit time up to "now" so the popup shows live numbers.
      return respond(
        enqueue(async () => {
          const state = await loadState();
          const now = Date.now();
          commitSegment(state, now, 0);
          openSegment(state, now);
          await saveState(state);
          return { tracking: isTracking(state), idleState: state.runtime.idleState };
        })
      );

    case 'setEnabled':
      return respond(
        enqueue(async () => {
          const state = await loadState();
          const enabled = !!message.enabled;
          commitSegment(state, Date.now(), 0);
          state.settings.enabled = enabled;
          if (enabled) {
            state.runtime.idleState = await queryIdleState();
            state.runtime.lastSwitchAt = null;
            await resyncContext(state, { countAsSwitch: false });
          } else {
            state.runtime.segmentStart = null;
          }
          await saveState(state);
          await updateBadge(enabled);
          return { enabled };
        })
      );

    case 'reset':
      return respond(
        enqueue(async () => {
          const state = await loadState();
          state.metrics = defaultMetrics();
          const keep = state.runtime;
          state.runtime = defaultRuntime();
          state.runtime.idleState = keep.idleState;
          state.runtime.windowFocused = keep.windowFocused;
          state.runtime.focusedWindowId = keep.focusedWindowId;
          await resyncContext(state, { countAsSwitch: false });
          await saveState(state);
          return {};
        })
      );

    default:
      return false;
  }
});
