// متابعة الاستعلام (owner 2026-11-20): runs in its own Aman tab, beside the uploader's tab.
// Teach (inqTeach): the admin marks the national-id box, the search button and where Aman shows the status.
// Run (inqRun): for every request under inquiry - type the id, search, read the status, send it to motobot.
// A search that reloads the page (ASP.NET postback) is fine: the step is kept in storage and resumes here.
(() => {
  if (window.__amanInquiry) return;
  window.__amanInquiry = true;

  const { store, norm } = AMAN;
  const { locate, findEl, clean } = AMAN.dom || {};
  if (!findEl) return;
  // timed by the background worker: this tab is usually in the background, where Chrome throttles page timers
  const sleep = (ms) => new Promise((r) => { try { chrome.runtime.sendMessage({ type: 'sleep', ms }, () => r()); } catch { setTimeout(r, ms); } });
  async function waitFor(fn, ms = 10000) {
    const end = Date.now() + ms;
    while (Date.now() < end) { const v = fn(); if (v) return v; await sleep(300); }
    return null;
  }
  let myTab = null;
  let teach = null;
  let busy = false;

  let chain = Promise.resolve();
  function update(key, fn) {
    chain = chain.then(async () => { const cur = (await store.get(key))[key]; const next = fn(cur); if (next !== undefined) await store.set({ [key]: next }); return next; });
    return chain;
  }

  // ---------- teach ----------
  const isTeaching = () => teach && teach.active && teach.tabId === myTab;
  let banner = null;
  const PICK_TEXT = {
    nid: 'دوس على خانة الرقم القومي في صفحة البحث',
    search: 'دوس على زرار البحث (مش هيتداس فعلًا)',
    result: 'دوس على المكان اللي أمان بيكتب فيه حالة العميل',
  };

  function showBanner() {
    const text = isTeaching() && teach.pick ? PICK_TEXT[teach.pick] : '';
    if (!text) { banner?.remove(); banner = null; return; }
    if (!banner) {
      banner = document.createElement('div');
      banner.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:2147483647;background:#0f766e;color:#fff;'
        + 'font:700 15px Tahoma,Arial,sans-serif;padding:10px;text-align:center;direction:rtl';
      document.documentElement.appendChild(banner);
    }
    banner.textContent = 'متابعة الاستعلام: ' + text;
  }

  document.addEventListener('click', (e) => {
    if (!isTeaching() || !teach.pick || e.target === banner) return;
    const kind = teach.pick;
    let el = e.target;
    if (kind === 'nid') el = el.closest('input, textarea') || el;
    if (kind === 'search') el = el.closest('button, a, input[type=submit], input[type=button], input[type=image], [role=button]') || el;
    e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
    const target = locate(el, kind === 'search' ? 'click' : kind === 'result' ? 'result' : undefined);
    update('inqTeach', (t) => {
      if (!t) return;
      t[kind] = target;
      if (kind === 'result') t.lastText = clean(el.innerText || el.value, 300);
      t.url = location.href;
      t.pick = null;
      return t;
    });
  }, true);

  // ---------- run ----------
  function setValue(el, v) {
    el.focus();
    const proto = el.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
    Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, v);
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
  }

  const resultText = (recipe) => { const el = findEl(recipe.result); return el ? clean(el.innerText || el.value, 500) : ''; };

  function mapStatus(recipe, text) {
    const t = norm(text);
    const row = (recipe.map || []).find((m) => m.text && t.includes(norm(m.text)));
    return row ? row.status : null;
  }

  const api = (method, path, body) => AMAN.api(method, path, body);

  async function runLoop() {
    if (busy) return;
    busy = true;
    try {
      for (;;) {
        const { inqRun: run } = await store.get('inqRun');
        if (!run || run.tabId !== myTab) return;

        if (run.status === 'waiting') {
          const wait = (run.nextAt || 0) - Date.now();
          if (wait > 0) { await sleep(Math.min(wait, 30000)); continue; }
          // the next round: the queue again from motobot
          try {
            const q = await api('GET', '/inquiry/queue');
            await update('inqRun', (r) => (r && r.status === 'waiting' ? { ...r, status: 'running', items: q.requests, i: 0, phase: 'fill', round: (r.round || 1) + 1 } : r));
          } catch (e) {
            await update('inqRun', (r) => (r ? { ...r, nextAt: Date.now() + 60000, error: 'ماقدرش يسحب الطلبات: ' + e.message } : r));
          }
          continue;
        }
        if (run.status !== 'running') return;

        const item = run.items[run.i];
        if (!item) {
          await update('inqRun', (r) => (r ? (r.repeat
            ? { ...r, status: 'waiting', nextAt: Date.now() + r.repeat * 60000 }
            : { ...r, status: 'done' }) : r));
          continue;
        }

        const recipe = run.recipe;
        if (run.phase !== 'read') {
          const nid = await waitFor(() => findEl(recipe.nid), 15000);
          if (!nid) {
            // not on the search page (a result page, or Aman went elsewhere): back to it once
            if (!run.navigated) {
              await update('inqRun', (r) => ({ ...r, navigated: true }));
              location.href = recipe.url;
              return;
            }
            await update('inqRun', (r) => ({ ...r, status: 'error', error: 'مش لاقي خانة الرقم القومي في صفحة أمان. علّم متابعة الاستعلام تاني.' }));
            return;
          }
          const search = findEl(recipe.search);
          if (!search) {
            await update('inqRun', (r) => ({ ...r, status: 'error', error: 'مش لاقي زرار البحث في صفحة أمان. علّم متابعة الاستعلام تاني.' }));
            return;
          }
          const prev = resultText(recipe);
          setValue(nid, String(item.national_id));
          await update('inqRun', (r) => ({ ...r, phase: 'read', prev, origin: performance.timeOrigin, clickedAt: Date.now(), navigated: false }));
          await sleep(300);
          search.click();
          continue;
        }

        // phase "read": the page may have reloaded since the click
        const reloaded = run.origin !== performance.timeOrigin;
        if (reloaded) await sleep(800);
        const end = run.clickedAt + 20000;
        let text = '';
        while (Date.now() < end) {
          const t = resultText(recipe);
          if (t && (reloaded || t !== run.prev)) { text = t; break; }
          await sleep(400);
        }
        // the same status as the last customer's is a real answer too (two customers with one status)
        if (!text && run.prev && resultText(recipe) === run.prev) text = run.prev;
        await sleep(200);
        const fresh = (await store.get('inqRun')).inqRun;
        if (!fresh || fresh.status !== 'running' || fresh.tabId !== myTab) return;

        const shown = text || 'مفيش حالة ظهرت';
        const status = text ? mapStatus(recipe, text) : null;
        let row = { id: item.id, name: item.name, text: shown, status, at: Date.now() };
        try {
          const res = await api('POST', '/inquiry/requests/' + item.id + '/result', { text: shown, status });
          row = { ...row, changed: !!res.changed, skipped: res.skipped || null };
        } catch (e) {
          row = { ...row, error: e.message };
        }
        await update('inqRun', (r) => {
          if (!r) return r;
          const s = { ...(r.stats || {}) };
          s.checked = (s.checked || 0) + 1;
          if (row.changed) s.changed = (s.changed || 0) + 1;
          if (!status) s.unknown = (s.unknown || 0) + 1;
          if (row.error) s.errors = (s.errors || 0) + 1;
          return { ...r, i: r.i + 1, phase: 'fill', prev: null, stats: s, log: [row, ...(r.log || [])].slice(0, 200), error: null };
        });
        await sleep(1200); // easy on Aman
      }
    } finally {
      busy = false;
    }
  }

  // ---------- wiring ----------
  chrome.storage.onChanged.addListener((ch, area) => {
    if (area !== 'local') return;
    if (ch.inqTeach) { teach = ch.inqTeach.newValue || null; showBanner(); }
    if (ch.inqRun) runLoop();
  });

  chrome.runtime.sendMessage({ type: 'whoami' }, async (r) => {
    myTab = r?.tabId ?? null;
    teach = (await store.get('inqTeach')).inqTeach || null;
    showBanner();
    runLoop();
  });
})();
