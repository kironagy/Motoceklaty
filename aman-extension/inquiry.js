// متابعة الاستعلام (owner 2026-11-20): runs in its own Aman tab, beside the uploader's tab.
// Teach (inqTeach): the admin records the steps in order - Aman's "Installment Check History" needs the search
//   type picked ("Customer National ID"), the id typed, "بحث", and maybe a tab clicked - then marks where the status is.
// Run (inqRun): for every request under inquiry - replay the steps with its national id, read the status, send it.
// A step that reloads the page (ASP.NET postback) is fine: the step number is kept in storage and resumes here.
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

  // ---------- teach: record the steps ----------
  const isTeaching = () => teach && teach.active && teach.tabId === myTab;
  let banner = null;

  function showBanner() {
    const text = !isTeaching() ? ''
      : teach.pick === 'nid' ? 'دوس على خانة الرقم القومي'
      : teach.pick === 'result' ? 'دوس على المكان اللي أمان بيكتب فيه حالة العميل'
      : teach.recording ? 'بيسجّل: اختار نوع البحث، ودوس "علّم خانة الرقم القومي"، ودوس بحث، وأي حاجة لحد ما الحالة تظهر'
      : '';
    if (!text) { banner?.remove(); banner = null; return; }
    if (!banner) {
      banner = document.createElement('div');
      banner.style.cssText = 'position:fixed;bottom:0;left:0;right:0;z-index:2147483647;background:#0f766e;color:#fff;'
        + 'font:700 14px Tahoma,Arial,sans-serif;padding:8px;text-align:center;direction:rtl;pointer-events:none';
      document.documentElement.appendChild(banner);
    }
    banner.textContent = 'متابعة الاستعلام: ' + text;
  }

  const addStep = (step) => update('inqTeach', (t) => {
    if (!t) return;
    const steps = [...(t.steps || [])];
    const last = steps[steps.length - 1];
    // a select changed twice in a row: keep the last choice only
    if (last && step.kind === 'select' && last.kind === 'select' && last.target.css === step.target.css) steps.pop();
    steps.push(step);
    return { ...t, steps, url: t.url || location.href };
  });

  const TYPING = 'input:not([type=submit]):not([type=button]):not([type=image]):not([type=checkbox]):not([type=radio]), textarea, select, option';
  const CLICKABLE = 'button, a, [role=button], [role=tab], input[type=submit], input[type=button], input[type=image], [onclick], [data-toggle], [data-bs-toggle], li';
  let lastClick = { css: '', at: 0 };

  document.addEventListener('click', (e) => {
    if (!isTeaching()) return;
    const kind = teach.pick;
    if (kind === 'nid' || kind === 'result') {
      const el = kind === 'nid' ? (e.target.closest('input, textarea') || e.target) : e.target;
      e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
      if (kind === 'nid') {
        addStep({ kind: 'nid', target: locate(el) });
        update('inqTeach', (t) => (t ? { ...t, pick: null } : t));
      } else {
        update('inqTeach', (t) => (t ? { ...t, result: locate(el, 'result'), lastText: clean(el.innerText || el.value, 300), pick: null, recording: false } : t));
      }
      return;
    }
    if (!teach.recording || e.target.closest(TYPING)) return;
    // the click goes through (the search really runs while teaching); it is only noted
    const el = e.target.closest(CLICKABLE) || e.target;
    const target = locate(el, 'click');
    if (target.css === lastClick.css && Date.now() - lastClick.at < 500) return;
    lastClick = { css: target.css, at: Date.now() };
    addStep({ kind: 'click', target });
  }, true);

  document.addEventListener('change', (e) => {
    if (!isTeaching() || !teach.recording || teach.pick) return;
    const el = e.target;
    if (el.tagName !== 'SELECT') return;
    addStep({ kind: 'select', target: locate(el), value: el.value, text: clean(el.options[el.selectedIndex]?.text, 80) });
  }, true);

  // ---------- run ----------
  function setValue(el, v) {
    el.focus();
    const proto = el.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
    Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, v);
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function choose(el, step) {
    const o = [...el.options].find((x) => x.value === step.value) || [...el.options].find((x) => norm(x.text) === norm(step.text));
    if (!o) return false;
    el.value = o.value;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  }

  const resultEl = (recipe) => findEl(recipe.result);
  const resultText = (recipe) => { const el = resultEl(recipe); return el ? clean(el.innerText || el.value, 500) : ''; };

  function mapStatus(recipe, text) {
    const t = norm(text);
    const row = (recipe.map || []).find((m) => m.text && t.includes(norm(m.text)));
    return row ? row.status : null;
  }

  const api = (method, path, body) => AMAN.api(method, path, body);

  async function stillMine() {
    const r = (await store.get('inqRun')).inqRun;
    return r && r.status === 'running' && r.tabId === myTab ? r : null;
  }

  async function record(run, item, text, status) {
    let row = { id: item.id, name: item.name, text, status, at: Date.now() };
    try {
      const res = await api('POST', '/inquiry/requests/' + item.id + '/result', { text, status });
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
      return { ...r, i: r.i + 1, step: 0, retried: false, stats: s, log: [row, ...(r.log || [])].slice(0, 200), error: null };
    });
  }

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
            await update('inqRun', (r) => (r && r.status === 'waiting' ? { ...r, status: 'running', items: q.requests, i: 0, step: 0, round: (r.round || 1) + 1 } : r));
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
        const steps = recipe.steps || [];
        const step = run.step || 0;
        const nidAt = steps.findIndex((s) => s.kind === 'nid');

        if (step < steps.length) {
          const s = steps[step];
          // the last customer's status must not be read as this one's: mark what is on the page now
          if (step === 0) {
            const old = resultEl(recipe);
            if (old) old.dataset.motoSeen = '1';
            await update('inqRun', (r) => ({ ...r, prev: old ? resultText(recipe) : '', origin: performance.timeOrigin }));
          }
          const el = await waitFor(() => findEl(s.target), 15000);
          if (!el) {
            if (step <= nidAt) {
              // not on the search page: back to it once, then it is the recipe's fault
              if (!run.navigated) {
                await update('inqRun', (r) => ({ ...r, step: 0, navigated: true }));
                location.href = recipe.url;
                return;
              }
              await update('inqRun', (r) => ({ ...r, status: 'error', error: 'مش لاقي خطوة ' + (step + 1) + ' في صفحة أمان. علّم متابعة الاستعلام تاني.' }));
              return;
            }
            // the page reloaded halfway (Aman, or the tab was opened late): this customer from the top, once
            if (run.origin !== performance.timeOrigin && !run.retried) {
              await update('inqRun', (r) => ({ ...r, step: 0, retried: true }));
              continue;
            }
            // after the search: Aman showed nothing to click for this customer
            if (!(await stillMine())) return;
            await record(run, item, 'أمان مارجّعش بيانات للعميل ده', null);
            location.href = recipe.url;
            return;
          }
          // the step is saved before it runs: a click that reloads the page resumes on the next one
          await update('inqRun', (r) => ({ ...r, step: step + 1, navigated: false, stepAt: Date.now() }));
          if (s.kind === 'select') {
            if (!choose(el, s)) {
              await update('inqRun', (r) => ({ ...r, status: 'error', error: 'الاختيار "' + (s.text || s.value) + '" مش موجود في القايمة. علّم متابعة الاستعلام تاني.' }));
              return;
            }
          } else if (s.kind === 'nid') {
            setValue(el, String(item.national_id));
          } else {
            el.click();
          }
          await sleep(s.kind === 'click' ? 900 : 300);
          continue;
        }

        // all steps done: wait for the status
        const end = (run.stepAt || Date.now()) + 20000;
        // fresh = a new element (Aman redrew it, or the page reloaded) or new words in the marked one
        let text = await waitFor(() => {
          const el = resultEl(recipe);
          const t = el ? clean(el.innerText || el.value, 500) : '';
          return t && (el.dataset.motoSeen !== '1' || t !== run.prev) ? t : null;
        }, Math.max(1000, end - Date.now())) || '';
        // the same words as the last customer's: a real answer too (two customers, one status)
        if (!text && run.prev && resultText(recipe) === run.prev) text = run.prev;
        await sleep(200);
        if (!(await stillMine())) return;
        await record(run, item, text || 'مفيش حالة ظهرت', text ? mapStatus(recipe, text) : null);
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
