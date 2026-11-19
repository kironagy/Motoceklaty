// Background worker: opens the side panel, talks to motobot with the employee's key,
// downloads order files for the content script, and reports how each run ended.
if (typeof importScripts === 'function') importScripts('shared.js');

chrome.runtime.onInstalled?.addListener(() => {
  chrome.sidePanel?.setPanelBehavior({ openPanelOnActionClick: true }).catch(() => {});
});

async function settings() {
  const { settings: s = {} } = await AMAN.store.get('settings');
  return { server: (s.server || '').replace(/\/+$/, ''), token: s.token || '' };
}

async function api(method, path, body) {
  const s = await settings();
  const login = path === '/login';
  if (!s.server) throw new Error('اكتب عنوان السيستم الأول');
  if (!login && !s.token) throw new Error('سجّل دخول الأول');
  const headers = { Accept: 'application/json', 'Content-Type': 'application/json' };
  if (!login) headers.Authorization = 'Bearer ' + s.token;
  const res = await fetch(s.server + '/api/aman' + path, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) });
  const data = await res.json().catch(() => ({}));
  if (res.status === 401 && !login) {
    const { settings: cur = {} } = await AMAN.store.get('settings');
    await AMAN.store.set({ settings: { ...cur, token: '' } });
  }
  // Owner 2026-11-19: the admin locked the extension for this employee (كنترول الاكستنشن).
  if (res.status === 423 || data.locked) await AMAN.store.set({ locked: data.message || 'الإكستنشن مقفولة عليك من الأدمن.' });
  else if (res.ok) await AMAN.store.set({ locked: '' });
  if (!res.ok) throw new Error(data.message || ('السيستم رجّع خطأ ' + res.status));
  return data;
}

async function fetchFile(url) {
  const s = await settings();
  const headers = s.server && url.startsWith(s.server) ? { Authorization: 'Bearer ' + s.token } : {};
  const res = await fetch(url, { headers });
  if (!res.ok) throw new Error('HTTP ' + res.status);
  const blob = await res.blob();
  const buf = new Uint8Array(await blob.arrayBuffer());
  let bin = '';
  for (let i = 0; i < buf.length; i += 0x8000) bin += String.fromCharCode(...buf.subarray(i, i + 0x8000));
  const cd = res.headers.get('content-disposition') || '';
  const name = (cd.match(/filename="?([^";]+)"?/) || [])[1] || decodeURIComponent(new URL(url, location.href).pathname.split('/').pop() || 'file');
  return { name, mime: blob.type || 'application/octet-stream', b64: btoa(bin) };
}

chrome.runtime.onMessage.addListener((msg, sender, reply) => {
  if (msg?.type === 'whoami') { reply({ tabId: sender.tab?.id ?? null }); return false; }
  const job = msg?.type === 'api' ? api(msg.method, msg.path, msg.body)
    : msg?.type === 'fetchFile' ? fetchFile(msg.url) : null;
  if (!job) return false;
  job.then((data) => reply(msg.type === 'api' ? { ok: true, data } : { ok: true, ...data }))
    .catch((e) => reply({ ok: false, error: String(e.message || e) }));
  return true; // async reply
});

// ---- report the end of each run to motobot (once) ----
const reported = new Set();
const RESULT = { done: 'uploaded', stopped: 'released', resolved: null, error: 'error' };

chrome.storage.onChanged.addListener(async (ch, area) => {
  if (area !== 'local' || !ch.run?.newValue) return;
  const run = ch.run.newValue;
  if (!run.uploadId || run.reported || !(run.status in RESULT)) return;
  const key = run.uploadId + ':' + run.status;
  if (reported.has(key)) return;
  reported.add(key);

  const status = run.status === 'resolved' ? run.resolution : RESULT[run.status];
  const message = run.status === 'resolved' ? run.blockMessage : run.status === 'error' ? run.error : run.status === 'stopped' ? (run.stopReason || 'الموظف وقف الرفع')
    : run.status === 'done' ? (run.amanMessage || null) : null;
  try {
    await api('POST', '/uploads/' + run.uploadId + '/result', {
      status, message, aman_ref: run.amanRef || null, recipe_version: run.recipe?.version || null,
      request_status: run.resolution === 'canceled' ? (run.cancelStatus || 'canceled') : null, reply: run.cancelReply || null,
      log: (run.log || []).map((r) => ({ label: r.label, source: r.source, expected: r.expected, actual: r.actual, ok: r.ok })),
    });
    const { run: cur } = await AMAN.store.get('run');
    if (cur && cur.uploadId === run.uploadId) await AMAN.store.set({ run: { ...cur, reported: true } });
  } catch (e) {
    reported.delete(key);
    const { run: cur } = await AMAN.store.get('run');
    if (cur && cur.uploadId === run.uploadId) await AMAN.store.set({ run: { ...cur, reportError: String(e.message || e) } });
  }
});
