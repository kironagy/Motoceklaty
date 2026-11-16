// Dev only: fakes the chrome.* APIs the extension uses so its pages run on a plain http server.
// Storage lives in localStorage, so the panel and the mock Aman page (same origin) share it.
(() => {
  const listeners = [], msgListeners = [], tabListeners = [];
  const read = (k) => { const v = localStorage.getItem('aman:' + k); return v === null ? undefined : JSON.parse(v); };
  const fire = (changes) => listeners.forEach((fn) => fn(changes, 'local'));
  const local = {
    get(keys, cb) {
      const list = typeof keys === 'string' ? [keys] : Array.isArray(keys) ? keys : Object.keys(keys || {});
      const out = {}; for (const k of list) { const v = read(k); if (v !== undefined) out[k] = v; }
      setTimeout(() => cb(out), 0);
    },
    set(obj, cb) {
      const changes = {};
      for (const [k, v] of Object.entries(obj)) { changes[k] = { oldValue: read(k), newValue: v }; localStorage.setItem('aman:' + k, JSON.stringify(v)); }
      setTimeout(() => { fire(changes); cb && cb(); }, 0);
    },
  };
  addEventListener('storage', (e) => {
    if (e.key === 'dev:navigate' && e.newValue && !location.pathname.endsWith('panel-dev.html')) { location.href = JSON.parse(e.newValue).url; return; }
    if (e.key === 'dev:loaded' && e.newValue) { tabListeners.forEach((fn) => fn(1, { status: 'complete' })); return; }
    if (!e.key?.startsWith('aman:')) return;
    fire({ [e.key.slice(5)]: { oldValue: e.oldValue && JSON.parse(e.oldValue), newValue: e.newValue && JSON.parse(e.newValue) } });
  });
  if (!location.pathname.endsWith('panel-dev.html')) addEventListener('load', () => localStorage.setItem('dev:loaded', String(Date.now())));

  async function builtin(msg) {
    if (msg.type === 'whoami') return { tabId: 1 };
    if (msg.type === 'fetchFile') {
      const res = await fetch(msg.url); const blob = await res.blob();
      const buf = new Uint8Array(await blob.arrayBuffer()); let bin = '';
      for (const b of buf) bin += String.fromCharCode(b);
      return { ok: res.ok, name: msg.url.split('/').pop(), mime: blob.type, b64: btoa(bin) };
    }
  }
  window.chrome = {
    storage: { local, onChanged: { addListener: (fn) => listeners.push(fn) } },
    runtime: {
      onMessage: { addListener: (fn) => msgListeners.push(fn) },
      sendMessage(msg, cb) {
        const p = msgListeners.length && msg.type !== 'whoami'
          ? new Promise((resolve) => { for (const fn of msgListeners) if (fn(msg, { tab: { id: 1 } }, resolve) === true) return; resolve(undefined); })
          : builtin(msg);
        if (cb) p.then(cb);
        return p;
      },
    },
    tabs: {
      query: async () => [{ id: 1 }],
      update: async (_id, { url }) => { localStorage.setItem('dev:navigate', JSON.stringify({ url, at: Date.now() })); },
      onUpdated: { addListener: (fn) => tabListeners.push(fn), removeListener: (fn) => tabListeners.splice(tabListeners.indexOf(fn), 1) },
    },
  };
})();
