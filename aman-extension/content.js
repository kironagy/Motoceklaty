// Runs inside every page of the Aman portal.
// Teach mode: puts a "what is this field?" select next to every input and records clicks/pages.
// Run mode: replays a saved recipe with an order's data, and stops at the save button for approval.
(() => {
  if (window.__amanUploader) return;
  window.__amanUploader = true;

  const { store, norm } = AMAN;
  let myTab = null;

  // ---------- element helpers ----------
  const FIELD_SEL = 'input:not([type=hidden]):not([type=submit]):not([type=button]):not([type=reset]):not([type=image]):not([type=checkbox]):not([type=radio]), select, textarea';
  const CLICK_SEL = 'button, a[href], [role=button], input[type=submit], input[type=button], input[type=checkbox], input[type=radio]';

  const visible = (el) => {
    if (!el || !el.isConnected) return false;
    if (el.type === 'file') return true; // file inputs are often styled invisible
    const r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== 'hidden';
  };
  const clean = (s, n = 80) => String(s || '').replace(/\s+/g, ' ').trim().slice(0, n);
  const textOf = (el) => clean(el.innerText || el.value || el.getAttribute('aria-label') || el.title, 60);

  function labelOf(el) {
    if (el.labels && el.labels[0]) return clean(el.labels[0].innerText);
    const by = el.getAttribute('aria-labelledby');
    if (by) { const t = by.split(/\s+/).map((id) => document.getElementById(id)?.innerText || '').join(' '); if (clean(t)) return clean(t); }
    const wrap = el.closest('label');
    if (wrap) return clean(wrap.innerText);
    let prev = el.previousElementSibling;
    if (prev && clean(prev.innerText)) return clean(prev.innerText);
    prev = el.parentElement?.previousElementSibling;
    if (prev && clean(prev.innerText)) return clean(prev.innerText);
    return '';
  }

  const stableId = (id) => /^[A-Za-z][\w-]*$/.test(id) && !/\d{4,}/.test(id);

  function cssPath(el) {
    const parts = [];
    let n = el;
    while (n && n.nodeType === 1 && parts.length < 7) {
      if (n.id && stableId(n.id)) { parts.unshift('#' + CSS.escape(n.id)); break; }
      let i = 1, s = n;
      while ((s = s.previousElementSibling)) if (s.tagName === n.tagName) i++;
      parts.unshift(n.tagName.toLowerCase() + ':nth-of-type(' + i + ')');
      n = n.parentElement;
    }
    return parts.join(' > ');
  }

  function locate(el, withText) {
    const t = {
      tag: el.tagName.toLowerCase(),
      type: el.type || '',
      id: el.id && stableId(el.id) ? el.id : '',
      name: el.getAttribute('name') || '',
      label: labelOf(el),
      aria: el.getAttribute('aria-label') || '',
      placeholder: el.getAttribute('placeholder') || '',
      css: cssPath(el),
    };
    if (el.type === 'radio' || el.type === 'checkbox') t.value = el.value;
    if (withText) { t.text = textOf(el); t.label = ''; } // a button's "label" is just neighbouring text
    return t;
  }

  const pagePath = () => location.pathname + location.hash;
  const locKey = (page, t) => page + '|' + (t.id || (t.name && t.tag + '[' + t.name + ']') || t.css);

  function findEl(t) {
    const c = [];
    const q = (sel) => { try { return [...document.querySelectorAll(sel)]; } catch { return []; } };
    if (t.id) c.push(document.getElementById(t.id));
    if (t.name) c.push(...document.getElementsByName(t.name));
    if (t.label) c.push(...q(t.tag).filter((e) => norm(labelOf(e)) === norm(t.label)));
    if (t.aria) c.push(...q(t.tag).filter((e) => e.getAttribute('aria-label') === t.aria));
    if (t.placeholder) c.push(...q(t.tag).filter((e) => e.getAttribute('placeholder') === t.placeholder));
    if (t.text) c.push(...q(t.tag).filter((e) => norm(textOf(e)) === norm(t.text)));
    if (t.css) c.push(...q(t.css));
    return c.find((e) => e && e.tagName.toLowerCase() === t.tag && visible(e)
      && (!t.type || (e.type || '') === t.type)
      && (t.value === undefined || e.value === t.value)) || null;
  }

  async function waitFor(fn, ms = 10000) {
    const end = Date.now() + ms;
    while (Date.now() < end) { const v = fn(); if (v) return v; await sleep(250); }
    return null;
  }
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  // Serialised read-modify-write on one storage key.
  let chain = Promise.resolve();
  function update(key, fn) {
    chain = chain.then(async () => { const cur = (await store.get(key))[key]; const next = fn(cur); if (next !== undefined) await store.set({ [key]: next }); return next; });
    return chain;
  }

  // ---------- teach mode ----------
  let teach = null;
  let host = null, root = null;
  const tags = new Map(); // element -> tag element
  let lastPage = null;

  const isTeaching = () => teach && teach.active && teach.tabId === myTab;

  function ensureOverlay() {
    if (host) return;
    host = document.createElement('div');
    host.id = 'aman-uploader-overlay';
    host.style.cssText = 'position:absolute;top:0;left:0;width:0;height:0;z-index:2147483647;';
    root = host.attachShadow({ mode: 'open' });
    root.innerHTML = `<style>
      .tag{position:absolute;display:flex;gap:4px;align-items:center;direction:rtl;font:12px Tahoma,Arial,sans-serif;
        background:#6b7280;color:#fff;border-radius:6px;padding:2px 4px;box-shadow:0 1px 4px rgba(0,0,0,.3)}
      .tag.mapped{background:#15803d}.tag.const{background:#1d4ed8}.tag.ask{background:#b45309}
      select{font:12px Tahoma,Arial,sans-serif;max-width:190px;border:0;border-radius:4px;padding:1px}
      .banner{position:fixed;top:8px;left:50%;transform:translateX(-50%);background:#7c3aed;color:#fff;direction:rtl;
        font:bold 13px Tahoma,Arial,sans-serif;padding:6px 14px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.3)}
    </style><div class="banner"></div>`;
    document.documentElement.appendChild(host);
  }

  function removeOverlay() {
    host?.remove(); host = root = null; tags.clear();
  }

  function stepFor(el) {
    const key = locKey(pagePath(), locate(el));
    return teach.steps.find((s) => s.kind === 'fill' && s.key === key);
  }

  function buildSelect(el) {
    const s = document.createElement('select');
    const opt = (v, l) => { const o = document.createElement('option'); o.value = v; o.textContent = l; return o; };
    s.append(opt('', '— تبع إيه في السيستم؟ —'));
    if (el.type === 'file') {
      const g = document.createElement('optgroup'); g.label = 'ملف من الطلب';
      for (const [k, l] of AMAN.FILE_FIELDS) g.append(opt('file:' + k, l));
      s.append(g);
    } else {
      for (const grp of AMAN.FIELD_GROUPS) {
        const g = document.createElement('optgroup'); g.label = grp.label;
        for (const [k, l] of grp.fields) g.append(opt('field:' + k, l));
        s.append(g);
      }
      s.append(opt('const', 'قيمة ثابتة لكل طلبات النوع ده…'));
    }
    s.append(opt('ask', 'الموظف يكتبها وقت المراجعة'));
    s.append(opt('ignore', 'تجاهل الخانة'));
    s.addEventListener('mousedown', (e) => e.stopPropagation(), true);
    s.addEventListener('change', () => onPick(el, s.value));
    return s;
  }

  function onPick(el, v) {
    const page = pagePath();
    const target = locate(el);
    const key = locKey(page, target);
    let source = null;
    if (v.startsWith('field:')) source = { type: 'field', key: v.slice(6) };
    else if (v.startsWith('file:')) source = { type: 'file', key: v.slice(5) };
    else if (v === 'const') {
      const cur = el.tagName === 'SELECT' ? el.options[el.selectedIndex]?.text : el.value;
      const val = prompt('القيمة الثابتة اللي تتكتب في كل طلبات "' + AMAN.typeLabel(teach.type) + '":', cur || '');
      if (val === null) { renderTags(); return; }
      source = { type: 'const', value: val };
    } else if (v === 'ask') {
      source = { type: 'ask', label: target.label || target.placeholder || 'خانة' };
    }
    update('teach', (t) => {
      if (!t) return;
      const i = t.steps.findIndex((s) => s.kind === 'fill' && s.key === key);
      if (!source || v === 'ignore') { if (i >= 0) t.steps.splice(i, 1); return t; }
      const step = { kind: 'fill', key, page, target, source };
      if (i >= 0) t.steps[i] = step; else t.steps.push(step);
      return t;
    });
  }

  function renderTags() {
    if (!isTeaching()) { if (host) removeOverlay(); return; }
    ensureOverlay();
    root.querySelector('.banner').textContent = 'وضع التعليم: ' + AMAN.typeLabel(teach.type) + ' — حدد كل خانة تبع إيه، وكمّل الرفع عادي';
    const els = [...document.querySelectorAll(FIELD_SEL)].filter(visible);
    for (const [el, tag] of tags) if (!els.includes(el)) { tag.remove(); tags.delete(el); }
    for (const el of els) {
      let tag = tags.get(el);
      if (!tag) {
        tag = document.createElement('div'); tag.className = 'tag';
        tag.append(buildSelect(el)); root.append(tag); tags.set(el, tag);
      }
      const step = stepFor(el);
      const sel = tag.querySelector('select');
      const want = !step ? '' : step.source.type === 'field' ? 'field:' + step.source.key
        : step.source.type === 'file' ? 'file:' + step.source.key : step.source.type;
      if (document.activeElement !== host || root.activeElement !== sel) sel.value = want;
      tag.className = 'tag' + (step ? (step.source.type === 'const' ? ' const' : step.source.type === 'ask' ? ' ask' : ' mapped') : '');
      tag.title = step ? AMAN.describeSource(step.source) : 'لسه ماتحددتش';
      const r = (el.type === 'file' && el.getBoundingClientRect().width === 0 ? el.parentElement : el).getBoundingClientRect();
      tag.style.top = Math.max(0, r.top + scrollY - 24) + 'px';
      tag.style.left = Math.max(0, r.left + scrollX) + 'px';
    }
  }

  let raf = 0;
  const schedule = () => { if (!raf) raf = requestAnimationFrame(() => { raf = 0; renderTags(); }); };

  function notePage() {
    if (!isTeaching()) return;
    const page = pagePath();
    if (page === lastPage) return;
    lastPage = page;
    update('teach', (t) => {
      if (!t) return;
      const last = [...t.steps].reverse().find((s) => s.kind === 'page');
      if (last && last.page === page) return;
      t.steps.push({ kind: 'page', page, url: location.href, title: clean(document.title, 60) });
      return t;
    });
  }

  let lastClick = { key: '', at: 0 };
  document.addEventListener('click', (e) => {
    if (!isTeaching() || e.composedPath().includes(host)) return;
    const b = e.target.closest?.(CLICK_SEL);
    if (!b) return;
    const page = pagePath();
    const target = locate(b, true);
    const key = locKey(page, target) + '|' + (target.value ?? '');
    if (key === lastClick.key && Date.now() - lastClick.at < 800) return;
    lastClick = { key, at: Date.now() };
    const gate = /حفظ|save|submit|ارسال|إرسال|تأكيد|تاكيد|اعتماد/i.test(target.text);
    update('teach', (t) => { if (!t) return; t.steps.push({ kind: 'click', key, page, target, gate }); return t; });
  }, true);

  // ---------- run mode ----------
  let running = false;

  function mark(el, color) { el.style.outline = '3px solid ' + color; el.style.outlineOffset = '2px'; }

  function setValue(el, v) {
    if (el.tagName === 'SELECT') {
      const o = [...el.options].find((o) => o.value === String(v)) || [...el.options].find((o) => norm(o.text) === norm(v) || norm(o.value) === norm(v));
      if (!o) throw new Error('القيمة "' + v + '" مش موجودة في القايمة');
      el.value = o.value;
    } else {
      const proto = el.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
      el.focus();
      Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, String(v));
    }
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    el.blur();
    return el.tagName === 'SELECT' ? el.options[el.selectedIndex]?.text : el.value;
  }

  async function setFiles(el, urls) {
    const dt = new DataTransfer();
    for (const url of urls) {
      const r = await chrome.runtime.sendMessage({ type: 'fetchFile', url });
      if (!r?.ok) throw new Error('مش قادر أحمّل الملف: ' + (r?.error || url));
      const bin = atob(r.b64); const buf = new Uint8Array(bin.length);
      for (let i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
      dt.items.add(new File([buf], r.name, { type: r.mime }));
    }
    el.files = dt.files;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    return [...el.files].map((f) => f.name).join('، ');
  }

  const empty = (v) => v === null || v === undefined || (typeof v === 'string' && v.trim() === '') || (Array.isArray(v) && !v.length);

  async function doStep(step, order) {
    const what = step.target ? (step.target.label || step.target.placeholder || step.target.text || step.target.name || 'خانة') : '';
    if (step.kind === 'page') {
      const ok = await waitFor(() => pagePath() === step.page, 20000);
      if (!ok) throw new Error('مستني صفحة ' + (step.title || step.page) + ' ومافتحتش');
      return null;
    }
    const el = await waitFor(() => findEl(step.target), 10000);
    if (!el) throw new Error('مش لاقي "' + what + '" في الصفحة. ممكن أمان غيّر الصفحة، علّم الخطوة دي تاني.');
    if (step.kind === 'click') return { el, what };
    const s = step.source;
    const row = { label: what, source: AMAN.describeSource(s), expected: '', actual: '', ok: true };
    if (s.type === 'ask') { mark(el, '#f59e0b'); row.expected = 'الموظف يكتبها'; row.ok = null; return { row }; }
    if (s.type === 'file') {
      const v = order[s.key];
      if (empty(v)) throw new Error('الطلب مافيهوش ملف: ' + AMAN.fieldLabel(s.key));
      const urls = Array.isArray(v) ? v : [v];
      row.expected = urls.length + ' ملف';
      row.actual = await setFiles(el, urls);
      row.ok = el.files.length === urls.length;
    } else {
      const v = s.type === 'const' ? s.value : order[s.key];
      if (s.type === 'field' && empty(v)) throw new Error('الطلب مافيهوش: ' + AMAN.fieldLabel(s.key));
      row.expected = String(v);
      row.actual = setValue(el, v);
      row.ok = norm(row.actual) === norm(v) || (el.tagName === 'SELECT' && norm(el.value) === norm(v));
    }
    mark(el, row.ok ? '#16a34a' : '#dc2626');
    if (!row.ok) throw Object.assign(new Error('"' + what + '" اتكتب فيها "' + row.actual + '" بدل "' + row.expected + '"'), { row });
    return { row };
  }

  async function runLoop() {
    if (running) return;
    running = true;
    try {
      for (;;) {
        const { run } = await store.get('run');
        if (!run || run.tabId !== myTab) break;
        if (run.status === 'approved') {
          const step = run.recipe.steps[run.i];
          const el = findEl(step.target);
          if (!el) { await update('run', (r) => ({ ...r, status: 'error', error: 'زرار الحفظ اختفى من الصفحة' })); break; }
          await update('run', (r) => ({ ...r, i: r.i + 1, status: 'running', approvedAt: new Date().toISOString() }));
          el.click();
          await sleep(600);
          continue;
        }
        if (run.status !== 'running') break;
        const step = run.recipe.steps[run.i];
        if (!step) { await update('run', (r) => ({ ...r, status: 'done', finishedAt: new Date().toISOString() })); break; }
        let res;
        try {
          res = await doStep(step, run.order);
        } catch (e) {
          await update('run', (r) => ({ ...r, status: 'error', error: e.message, log: e.row ? [...r.log, { ...e.row, ok: false }] : r.log }));
          break;
        }
        if (step.kind === 'click') {
          if (step.gate) {
            mark(res.el, '#dc2626');
            res.el.scrollIntoView({ block: 'center' });
            await update('run', (r) => ({ ...r, status: 'review', gateText: res.what }));
            break;
          }
          await update('run', (r) => ({ ...r, i: r.i + 1 })); // save progress before a click that may reload the page
          res.el.click();
          await sleep(600);
          continue;
        }
        await update('run', (r) => ({ ...r, i: r.i + 1, log: res?.row ? [...r.log, res.row] : r.log }));
      }
    } finally {
      running = false;
    }
  }

  // ---------- wiring ----------
  async function refresh() {
    teach = (await store.get('teach')).teach || null;
    if (isTeaching()) { notePage(); schedule(); } else if (host) removeOverlay();
    runLoop();
  }

  chrome.storage.onChanged.addListener((ch, area) => {
    if (area !== 'local') return;
    if (ch.teach) { teach = ch.teach.newValue || null; if (isTeaching()) { notePage(); schedule(); } else if (host) removeOverlay(); }
    if (ch.run) runLoop();
  });

  new MutationObserver((muts) => {
    if (isTeaching() && muts.some((m) => !host || !host.contains(m.target))) schedule();
  }).observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'class', 'hidden'] });
  addEventListener('scroll', schedule, true);
  addEventListener('resize', schedule);
  setInterval(() => { if (isTeaching()) { notePage(); schedule(); } }, 1000);

  chrome.runtime.sendMessage({ type: 'whoami' }, (r) => { myTab = r?.tabId ?? null; refresh(); });
})();
