// Runs inside the Aman portal pages.
// Teach mode: a small badge on every field; clicking it opens a picker ("what is this field?").
//   Clicks and pages are recorded; status messages and the Aman reference number are picked from the panel.
// Run mode: replays the recipe with the order and card data, and stops at the save button for approval.
(() => {
  if (window.__amanUploader) return;
  window.__amanUploader = true;

  const { store, norm } = AMAN;
  let myTab = null;

  // ---------- element helpers ----------
  const FIELD_SEL = 'input:not([type=hidden]):not([type=submit]):not([type=button]):not([type=reset]):not([type=image]), select, textarea';
  const CLICK_SEL = 'button, a[href], a[onclick], [role=button], input[type=submit], input[type=button], input[type=image]';
  const isCheck = (el) => el.type === 'checkbox' || el.type === 'radio';

  const visible = (el) => {
    if (!el || !el.isConnected) return false;
    if (el.type === 'file') return !!el.offsetParent || el.getClientRects().length > 0 || el.parentElement?.offsetParent;
    const r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== 'hidden';
  };
  const clean = (s, n = 80) => String(s || '').replace(/\s+/g, ' ').trim().slice(0, n);
  const textOf = (el) => clean(el.innerText || el.value || el.getAttribute('aria-label') || el.title || el.alt, 60);
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  function labelOf(el) {
    if (el.labels && el.labels[0]) return clean(el.labels[0].innerText);
    const by = el.getAttribute('aria-labelledby');
    if (by) { const t = by.split(/\s+/).map((id) => document.getElementById(id)?.innerText || '').join(' '); if (clean(t)) return clean(t); }
    const wrap = el.closest('label');
    if (wrap && clean(wrap.innerText)) return clean(wrap.innerText);
    // Table layouts (ASP.NET): the label sits in the previous cell.
    const cell = el.closest('td, th');
    if (cell) {
      let prev = cell.previousElementSibling;
      while (prev && !clean(prev.innerText)) prev = prev.previousElementSibling;
      if (prev) return clean(prev.innerText);
    }
    let prev = el.previousElementSibling;
    while (prev && !clean(prev.innerText) && prev.tagName !== 'INPUT') prev = prev.previousElementSibling;
    if (prev && clean(prev.innerText)) return clean(prev.innerText);
    prev = el.parentElement?.previousElementSibling;
    if (prev && clean(prev.innerText)) return clean(prev.innerText);
    return '';
  }

  const stableId = (id) => /^[A-Za-z][\w$-]*$/.test(id) && !/\d{5,}/.test(id);

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

  function locate(el, mode) {
    const t = {
      tag: el.tagName.toLowerCase(),
      type: el.type || '',
      id: el.id && stableId(el.id) ? el.id : '',
      name: el.getAttribute('name') || '',
      label: mode ? '' : labelOf(el),
      aria: el.getAttribute('aria-label') || '',
      placeholder: el.getAttribute('placeholder') || '',
      css: cssPath(el),
    };
    if (isCheck(el)) t.value = el.value;
    if (mode === 'click') t.text = textOf(el);
    return t;
  }

  const pagePath = () => location.pathname;
  const locKey = (page, t) => page + '|' + (t.id || (t.name && t.tag + '[' + t.name + ']') || t.css) + (t.value !== undefined ? '=' + t.value : '');

  function findEl(t) {
    const c = [];
    const tag = t.tag === '*' ? CLICK_SEL + ', .btn' : t.tag;
    const q = (sel) => { try { return [...document.querySelectorAll(sel)]; } catch { return []; } };
    if (t.id) c.push(document.getElementById(t.id));
    if (t.name) c.push(...document.getElementsByName(t.name));
    if (t.label) c.push(...q(tag).filter((e) => norm(labelOf(e)) === norm(t.label)));
    if (t.aria) c.push(...q(tag).filter((e) => e.getAttribute('aria-label') === t.aria));
    if (t.placeholder) c.push(...q(tag).filter((e) => e.getAttribute('placeholder') === t.placeholder));
    if (t.text) c.push(...q(tag).filter((e) => norm(textOf(e)) === norm(t.text)));
    if (t.css) c.push(...q(t.css));
    // tag "*": a button known only by its text (added by hand, e.g. the "تأكيد" of Aman's card review box).
    return c.find((e) => e && (t.tag === '*' || e.tagName.toLowerCase() === t.tag) && visible(e)
      && (!t.type || (e.type || '') === t.type)
      && (t.value === undefined || e.value === t.value)) || null;
  }

  async function waitFor(fn, ms = 10000) {
    const end = Date.now() + ms;
    while (Date.now() < end) { const v = fn(); if (v) return v; await sleep(250); }
    return null;
  }

  // Serialised read-modify-write on one storage key.
  let chain = Promise.resolve();
  function update(key, fn) {
    chain = chain.then(async () => { const cur = (await store.get(key))[key]; const next = fn(cur); if (next !== undefined) await store.set({ [key]: next }); return next; });
    return chain;
  }

  // ---------- dialogs (alert/confirm) seen by page-hook.js ----------
  let lastRun = null;
  const lastDialogs = []; // also read while waiting for Aman's number after the save
  window.addEventListener('message', (e) => {
    if (e.source !== window || !e.data?.__amanDialog) return;
    lastDialogs.push({ kind: e.data.kind, msg: e.data.msg, at: e.data.at });
    update('run', (r) => {
      if (!r || r.tabId !== myTab || !['running', 'approved'].includes(r.status)) return;
      return { ...r, dialogs: [...(r.dialogs || []), { kind: e.data.kind, msg: e.data.msg, at: e.data.at }] };
    });
  });

  // ---------- teach mode ----------
  let teach = null;
  let host = null, root = null, pop = null, popFor = null;
  const badges = new Map(); // element -> badge
  let lastPage = null;

  const isTeaching = () => teach && teach.active && teach.tabId === myTab;

  const CSS_TEXT = `
    :host{all:initial}
    [hidden]{display:none!important}
    .badge{position:absolute;width:18px;height:18px;border-radius:50%;display:flex;align-items:center;justify-content:center;
      font:bold 12px/1 Tahoma,Arial,sans-serif;color:#fff;background:#9ca3af;cursor:pointer;box-shadow:0 0 0 2px #fff,0 1px 4px rgba(0,0,0,.35);
      pointer-events:auto;user-select:none;opacity:.85}
    .badge:hover{opacity:1;transform:scale(1.15)}
    .badge.field,.badge.card,.badge.file,.badge.check{background:#ff6100}.badge.const{background:#b84500}.badge.ask{background:#f59e0b}
    .badge.verify{background:#0f766e}
    .banner{position:fixed;top:6px;left:50%;transform:translateX(-50%);background:#ff6100;color:#fff;direction:rtl;pointer-events:none;
      font:bold 12px Tahoma,Arial,sans-serif;padding:5px 12px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.3);white-space:nowrap}
    .banner.pick{background:#b84500}
    .pop{position:fixed;width:280px;max-height:420px;display:flex;flex-direction:column;background:#fff;color:#0f172a;direction:rtl;
      font:13px Tahoma,Arial,sans-serif;border-radius:10px;box-shadow:0 8px 30px rgba(0,0,0,.3);pointer-events:auto;overflow:hidden}
    .pop-h{display:flex;justify-content:space-between;align-items:center;gap:6px;padding:8px 10px;background:#f1f5f9;font-weight:bold}
    .pop-h small{font-weight:normal;color:#64748b;display:block}
    .x{border:0;background:none;font-size:16px;cursor:pointer;color:#64748b}
    .search{margin:6px 8px;padding:5px 8px;border:1px solid #cbd5e1;border-radius:6px;font:inherit}
    .list{overflow:auto;padding:0 4px 6px}
    .grp{font-size:11px;color:#64748b;padding:6px 6px 2px}
    .opt{padding:5px 8px;border-radius:6px;cursor:pointer}
    .opt:hover{background:#fff2e9}.opt.on{background:#ff6100;color:#fff}
    .extra{border-top:1px solid #e2e8f0;padding:6px 10px;display:flex;flex-direction:column;gap:5px;font-size:12px}
    .extra select,.extra input[type=text]{font:inherit;padding:3px;border:1px solid #cbd5e1;border-radius:5px}
    .row{display:flex;gap:5px;align-items:center}
    .btn{border:0;border-radius:6px;padding:4px 10px;background:#ff6100;color:#fff;cursor:pointer;font:inherit}
  `;

  function ensureOverlay() {
    if (host) return;
    host = document.createElement('div');
    host.id = 'aman-uploader-overlay';
    host.style.cssText = 'position:absolute;top:0;left:0;width:0;height:0;z-index:2147483647;pointer-events:none;';
    root = host.attachShadow({ mode: 'open' });
    root.innerHTML = `<style>${CSS_TEXT}</style><div class="banner"></div>`;
    document.documentElement.appendChild(host);
  }

  function removeOverlay() { host?.remove(); host = root = pop = popFor = null; badges.clear(); }

  // New steps go where the panel's "زوّد هنا" marker is (editing a saved recipe), else at the end.
  function addStep(t, step) {
    if (Number.isInteger(t.insertAt) && t.insertAt >= 0 && t.insertAt <= t.steps.length) t.steps.splice(t.insertAt++, 0, step);
    else t.steps.push(step);
  }

  const stepFor = (el) => {
    const key = locKey(pagePath(), locate(el));
    return teach?.steps.find((s) => s.kind === 'fill' && s.key === key);
  };

  function saveStep(el, source) {
    const page = pagePath();
    const target = locate(el);
    const key = locKey(page, target);
    return update('teach', (t) => {
      if (!t) return;
      const i = t.steps.findIndex((s) => s.kind === 'fill' && s.key === key);
      if (!source) { if (i >= 0) t.steps.splice(i, 1); return t; }
      const step = { kind: 'fill', key, page, target, source };
      if (i >= 0) t.steps[i] = step; else addStep(t, step);
      return t;
    });
  }

  const badgeClass = (s) => !s ? '' : s.source.verify ? 'verify' : s.source.type;
  const badgeText = (s) => !s ? '+' : ({ field: '✓', card: '✓', const: '=', ask: '✎', file: '⇪', check: '☑' })[s.source.type] || '✓';

  function closePop() { pop?.remove(); pop = popFor = null; }

  function openPop(el, badge) {
    closePop();
    popFor = el;
    const step = stepFor(el);
    const cur = step?.source || {};
    pop = document.createElement('div');
    pop.className = 'pop';
    const title = labelOf(el) || el.getAttribute('placeholder') || el.name || 'خانة';
    pop.innerHTML = `<div class="pop-h"><div>${esc(title)}<small>الخانة دي تبع إيه؟</small></div><button class="x" title="اقفل">✕</button></div>`;
    pop.querySelector('.x').onclick = closePop;

    const choose = async (source) => { await saveStep(el, source); closePop(); };

    if (isCheck(el)) {
      const list = div('list');
      list.append(opt('دايمًا متعلّم', cur.mode === 'always', () => choose({ type: 'check', mode: 'always' })));
      list.append(opt('دايمًا مش متعلّم', cur.mode === 'never', () => choose({ type: 'check', mode: 'never' })));
      list.append(opt('تجاهل', !step, () => choose(null)));
      const ex = div('extra');
      ex.innerHTML = '<b>متعلّم بشرط:</b>';
      const src = sourceSelect(cur.mode === 'when' ? (cur.from || 'field') + ':' + cur.key : '');
      const val = input(cur.mode === 'when' ? cur.equals : '', 'القيمة، مثلًا: ذكر');
      const ok = btn('احفظ الشرط', () => {
        if (!src.value || !val.value.trim()) return;
        const [from, key] = src.value.split(':');
        choose({ type: 'check', mode: 'when', from, key, equals: val.value.trim() });
      });
      ex.append(src, val, ok);
      pop.append(list, ex);
    } else {
      const search = document.createElement('input');
      search.className = 'search'; search.placeholder = 'دوّر…';
      const list = div('list');
      const extra = div('extra');
      const verify = document.createElement('input'); verify.type = 'checkbox'; verify.checked = !!cur.verify;
      const fmt = document.createElement('select');
      for (const [k, l] of AMAN.DATE_FORMATS) fmt.append(new Option(l, k));
      fmt.value = cur.format || (el.type === 'date' ? 'iso' : 'dmy');
      const smart = document.createElement('input'); smart.type = 'checkbox'; smart.checked = cur.smart !== false;
      const isSel = el.tagName === 'SELECT';
      const extras = () => ({ ...(verify.checked ? { verify: true } : {}), format: fmt.value, ...(isSel ? { smart: smart.checked } : {}) });
      const items = [];
      const add = (grp, label, on, fn) => items.push({ grp, label, on, fn });

      if (el.type === 'file') {
        for (const [k, l] of AMAN.FILE_FIELDS) add('ملف من الطلب', l, cur.type === 'file' && cur.key === k, () => choose({ type: 'file', key: k }));
      } else {
        for (const [k, l] of AMAN.CARD_FIELDS) add('من البطاقة (قراءة آلية)', l, cur.type === 'card' && cur.key === k, () => choose({ type: 'card', key: k, ...extras() }));
        for (const g of AMAN.FIELD_GROUPS) for (const [k, l] of g.fields) add(g.label, l, cur.type === 'field' && cur.key === k, () => choose({ type: 'field', key: k, ...extras() }));
        add('تانيين', 'قيمة ثابتة (اكتب أي حاجة)…', cur.type === 'const', () => {
          const now = el.tagName === 'SELECT' ? el.options[el.selectedIndex]?.text : el.value;
          constRow.hidden = false; constIn.value = cur.type === 'const' ? cur.value : (now || ''); constIn.focus();
        });
      }
      add('تانيين', 'الموظف يكتبها وقت المراجعة', cur.type === 'ask', () => choose({ type: 'ask', label: title }));
      add('تانيين', 'تجاهل الخانة', !step, () => choose(null));

      const draw = () => {
        list.innerHTML = '';
        const q = norm(search.value);
        let g = null;
        for (const it of items) {
          if (q && !norm(it.label).includes(q) && !norm(it.grp).includes(q)) continue;
          if (it.grp !== g) { g = it.grp; const h = div('grp'); h.textContent = g; list.append(h); }
          list.append(opt(it.label, it.on, it.fn));
        }
      };
      search.oninput = draw;
      draw();

      const constIn = input('', 'القيمة اللي تتكتب في كل طلب من النوع ده');
      const constRow = div('row'); constRow.hidden = cur.type !== 'const';
      constRow.append(constIn, btn('حفظ', () => choose({ type: 'const', value: constIn.value })));
      if (cur.type === 'const') constIn.value = cur.value;

      if (el.type !== 'file') {
        const vRow = div('row'); vRow.append(verify, txt('اتأكد بس ماتكتبش (لو أمان بيملاها لوحده)'));
        const fRow = div('row'); fRow.append(txt('شكل التاريخ:'), fmt);
        fRow.hidden = !(el.type === 'date' || /تاريخ|ميلاد/.test(title) || AMAN.isDateKey(cur.key));
        const sRow = div('row'); sRow.append(smart, txt('اختيار ذكي: لو مش لاقي نفس الكلمة، يدوّر عليها في الخانة دي وبعدين في العنوان التفصيلي'));
        sRow.hidden = !isSel;
        extra.append(constRow, sRow, vRow, fRow);
        const resave = () => { if (step && ['field', 'card'].includes(cur.type)) saveStep(el, { ...cur, ...extras(), ...(verify.checked ? {} : { verify: undefined }) }); };
        verify.onchange = resave; fmt.onchange = resave; smart.onchange = resave;
      }
      pop.append(search, list, extra);
      setTimeout(() => search.focus(), 0);
    }

    root.append(pop);
    const b = badge.getBoundingClientRect();
    const w = 280, h = Math.min(420, pop.offsetHeight || 420);
    pop.style.left = Math.max(8, Math.min(innerWidth - w - 8, b.left - w / 2)) + 'px';
    pop.style.top = (b.bottom + h + 8 < innerHeight ? b.bottom + 6 : Math.max(8, b.top - h - 6)) + 'px';
  }

  // tiny DOM builders for the picker
  const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
  const div = (cls) => { const d = document.createElement('div'); d.className = cls; return d; };
  const txt = (s) => { const d = document.createElement('span'); d.textContent = s; return d; };
  const opt = (label, on, fn) => { const d = div('opt' + (on ? ' on' : '')); d.textContent = label; d.onclick = fn; return d; };
  const btn = (label, fn) => { const b = document.createElement('button'); b.className = 'btn'; b.textContent = label; b.onclick = fn; return b; };
  const input = (v, ph) => { const i = document.createElement('input'); i.type = 'text'; i.value = v || ''; i.placeholder = ph; return i; };
  function sourceSelect(value) {
    const s = document.createElement('select');
    s.append(new Option('— الخانة اللي بنقارن بيها —', ''));
    const g1 = document.createElement('optgroup'); g1.label = 'من البطاقة';
    for (const [k, l] of AMAN.CARD_FIELDS) g1.append(new Option(l, 'card:' + k));
    s.append(g1);
    for (const g of AMAN.FIELD_GROUPS) {
      const og = document.createElement('optgroup'); og.label = g.label;
      for (const [k, l] of g.fields) og.append(new Option(l, 'field:' + k));
      s.append(og);
    }
    s.value = value;
    return s;
  }

  function renderBadges() {
    if (!isTeaching()) { if (host) removeOverlay(); return; }
    ensureOverlay();
    const banner = root.querySelector('.banner');
    const els = [...document.querySelectorAll(FIELD_SEL)].filter((e) => visible(e) && !host.contains(e));
    const done = els.filter((e) => stepFor(e)).length;
    if (teach.pick) {
      banner.className = 'banner pick';
      banner.textContent = teach.pick === 'expect' ? 'دوس على رسالة الحالة اللي بتظهر لما الطلب سليم (مثلًا: عميل جديد)'
        : teach.pick === 'gate' ? 'دوس على زرار الحفظ: هيتسجل "وقفة موافقة" ومش هيتداس فعلًا'
          : 'دوس على رقم الطلب اللي أمان بيديهولك بعد الحفظ';
    } else {
      banner.className = 'banner';
      banner.textContent = `تعليم: ${AMAN.typeLabel(teach.type)} — اتحدد ${done} من ${els.length} خانة في الصفحة دي. دوس على الدواير.`;
    }
    for (const [el, b] of badges) if (!els.includes(el)) { b.remove(); badges.delete(el); }
    for (const el of els) {
      let b = badges.get(el);
      if (!b) {
        b = div('badge');
        b.addEventListener('mousedown', (e) => { e.preventDefault(); e.stopPropagation(); });
        b.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); popFor === el ? closePop() : openPop(el, b); });
        b.addEventListener('mouseenter', () => { el.style.outline = '2px dashed #ff6100'; });
        b.addEventListener('mouseleave', () => { el.style.outline = ''; });
        root.append(b); badges.set(el, b);
      }
      const s = stepFor(el);
      b.className = 'badge ' + badgeClass(s);
      b.textContent = badgeText(s);
      b.title = (labelOf(el) || el.name || '') + ' — ' + (s ? AMAN.describeSource(s.source) : 'لسه ماتحددتش');
      const anchor = el.type === 'file' && el.getBoundingClientRect().width === 0 ? el.parentElement : el;
      const r = anchor.getBoundingClientRect();
      // Inside the field's left edge (RTL text starts on the right); checkboxes get it just outside.
      const left = isCheck(el) ? r.left - 22 : el.tagName === 'SELECT' ? r.left + 20 : r.left + 3;
      b.style.left = (left + scrollX) + 'px';
      b.style.top = (r.top + scrollY + (r.height - 18) / 2) + 'px';
    }
  }

  let raf = 0;
  const schedule = () => {
    if (raf) return;
    const go = () => { raf = 0; renderBadges(); };
    raf = document.hidden ? setTimeout(go, 50) : requestAnimationFrame(go); // no frames in a background tab
  };

  function notePage() {
    if (!isTeaching()) return;
    const page = pagePath();
    if (page === lastPage) return;
    lastPage = page;
    update('teach', (t) => {
      if (!t) return;
      const last = [...t.steps].reverse().find((s) => s.kind === 'page');
      if (last && last.page === page) return;
      addStep(t, { kind: 'page', page, url: location.href, title: clean(document.title, 60) });
      return t;
    });
  }

  // Picking a status message / reference number (panel buttons set teach.pick).
  let hoverEl = null;
  document.addEventListener('mouseover', (e) => {
    if (!isTeaching() || !teach.pick || e.composedPath().includes(host)) return;
    if (hoverEl) hoverEl.style.outline = '';
    hoverEl = e.target; hoverEl.style.outline = '2px solid #ff6100';
  }, true);

  let lastClick = { key: '', at: 0 };
  document.addEventListener('click', (e) => {
    if (!isTeaching() || e.composedPath().includes(host)) return;
    closePop();

    if (teach.pick) {
      e.preventDefault(); e.stopPropagation();
      const el = e.target;
      if (hoverEl) hoverEl.style.outline = '';
      const kind = teach.pick;
      if (kind === 'gate') {
        const b = el.closest?.(CLICK_SEL) || el;
        const target = locate(b, 'click');
        update('teach', (t) => { if (!t) return; addStep(t, { kind: 'click', key: locKey(pagePath(), target), page: pagePath(), target, gate: true }); t.pick = null; return t; });
        return;
      }
      update('teach', (t) => {
        if (!t) return;
        addStep(t, { kind, page: pagePath(), target: locate(el, 'text'), sample: textOf(el) });
        t.pick = null;
        return t;
      });
      return;
    }

    const field = e.target.closest?.('input[type=checkbox], input[type=radio]');
    if (field) {
      setTimeout(() => {
        const s = stepFor(field);
        if (s?.source.mode === 'when') return;
        if (field.type === 'radio') {
          // one radio of a group: forget the others' fixed choice
          for (const other of document.getElementsByName(field.name)) if (other !== field && stepFor(other)?.source.mode !== 'when') saveStep(other, null);
          saveStep(field, { type: 'check', mode: 'always' });
        } else {
          saveStep(field, { type: 'check', mode: field.checked ? 'always' : 'never' });
        }
      }, 0);
      return;
    }

    const b = e.target.closest?.(CLICK_SEL);
    if (!b) return;
    const page = pagePath();
    const target = locate(b, 'click');
    const key = locKey(page, target);
    if (key === lastClick.key && Date.now() - lastClick.at < 800) return;
    lastClick = { key, at: Date.now() };
    // norm() drops the tatweel: Aman's button reads "حفـظ". "تأكيد" is not here: "تأكيد صورة البطاقة" is a normal step.
    const gate = /حفظ|save|submit|ارسال|اعتماد|تقديم الطلب/i.test(norm(target.text));
    if (gate) {
      if (confirm('ده زرار الحفظ عند أمان ("' + target.text + '").\n\nموافق = يتسجل "وقفة موافقة" من غير ما يتداس (الطلب مش هيتبعت لأمان).\nإلغاء = يتداس فعلًا ويتبعت الطلب.')) {
        e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
      }
    }
    update('teach', (t) => { if (!t) return; addStep(t, { kind: 'click', key, page, target, gate }); return t; });
  }, true);

  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closePop(); }, true);

  // ---------- run mode ----------
  let running = false;

  function mark(el, color) { el.style.outline = '3px solid ' + color; el.style.outlineOffset = '2px'; }
  const empty = (v) => v === null || v === undefined || (typeof v === 'string' && v.trim() === '') || (Array.isArray(v) && !v.length);
  const stepName = (step) => step.target ? (step.target.label || step.target.placeholder || step.target.text || step.target.name || step.sample || 'خانة') : '';

  function sourceValue(s, run) {
    if (s.type === 'const') return s.value;
    const v = s.type === 'card' ? run.card?.[s.key] : run.order?.[s.key];
    return AMAN.isDateKey(s.key) ? AMAN.formatDate(v, s.format) : v;
  }
  const srcName = (s) => s.type === 'card' ? 'البطاقة: ' + AMAN.cardLabel(s.key) : AMAN.fieldLabel(s.key);

  function shown(el) { return el.tagName === 'SELECT' ? (el.options[el.selectedIndex]?.text ?? '') : el.value; }
  const same = (el, v) => norm(shown(el)) === norm(v) || (el.tagName === 'SELECT' && norm(el.value) === norm(v));

  // ---- dropdowns: pick the option that the address text names ----
  // Aman splits the address into dependent lists (governorate -> city/area -> district), while
  // motobot keeps free text. An option matches when its main part appears as whole words in the text
  // ("ترسا" in "شارع ترسا متفرع من العمده"); "ال" and words like محافظة / مدينة / حي are ignored.
  const STOP = new Set(['محافظه', 'مدينه', 'مركز', 'قسم', 'حي', 'منطقه', 'شارع', 'ش', 'قريه', 'عزبه', 'رقم', 'عقار']);
  const stripAl = (w) => (w.length > 3 && w.startsWith('ال') ? w.slice(2) : w);
  const words = (t) => norm(t).replace(/[^\p{L}\p{N}\s]/gu, ' ').split(/\s+/).filter(Boolean).map(stripAl);
  const meaningful = (t) => words(t).filter((w) => !STOP.has(w));
  function hasSeq(hay, needle) {
    if (!needle.length) return false;
    outer: for (let i = 0; i + needle.length <= hay.length; i++) {
      for (let j = 0; j < needle.length; j++) if (hay[i + j] !== needle[j]) continue outer;
      return true;
    }
    return false;
  }
  const isPlaceholder = (o) => !o.value || o.value === '0' || o.value === '-1' || /^(اختر|اختار|select|--|\.\.\.)/i.test(norm(o.text));
  /** @returns {{option?: HTMLOptionElement, tie?: string[]}} */
  function optionFromText(el, text) {
    const hay = words(text);
    if (!hay.length) return {};
    let best = [], bestScore = 0;
    for (const o of el.options) {
      if (isPlaceholder(o)) continue;
      const parts = o.text.split(/\s*[-–\/،,()]\s*/).filter((p) => p.trim());
      const main = meaningful(parts[0] || '');
      if (!main.length || !hasSeq(hay, main)) continue;
      let score = main.join('').length * 2;
      for (const p of parts.slice(1)) { const pw = meaningful(p); if (pw.length && hasSeq(hay, pw)) score += pw.join('').length; }
      if (score > bestScore) { best = [o]; bestScore = score; } else if (score === bestScore) best.push(o);
    }
    const distinct = [...new Map(best.map((o) => [norm(o.text), o])).values()];
    return distinct.length === 1 ? { option: distinct[0] } : distinct.length > 1 ? { tie: distinct.map((o) => o.text) } : {};
  }
  const exactOption = (el, v) => [...el.options].find((o) => !isPlaceholder(o) && (o.value === String(v) || norm(o.text) === norm(v)));
  const loaded = (el) => [...el.options].filter((o) => !isPlaceholder(o)).length > 0;

  // The page hook marks ASP.NET async postbacks; dependent lists reload during them.
  async function settle() {
    await sleep(350);
    await waitFor(() => document.documentElement.dataset.amanBusy !== '1', 15000);
    await sleep(250);
  }

  /** Picks a dropdown option for a field/card source: exact value, then the value's words, then the full address. */
  async function chooseSmart(el, s, run) {
    const v = s.type === 'card' ? run.card?.[s.key] : run.order?.[s.key];
    const texts = [];
    if (!empty(v)) texts.push(['الخانة', String(v)]);
    const isWork = /^(aman_)?work_|^free_work/.test(s.key || '');
    const addr = isWork
      ? [run.order?.work_address, run.order?.free_work_address, run.order?.work_area, run.order?.work_street, run.order?.work_governorate]
      : [run.order?.applicant_address, run.order?.applicant_area, run.order?.applicant_street, run.order?.applicant_branch_street, run.order?.applicant_governorate, run.order?.applicant_landmark, run.card?.address];
    const full = addr.filter((x) => !empty(x)).join(' ');
    if (full) texts.push(['العنوان', full]);

    await waitFor(() => loaded(el), 10000);
    if (!empty(v)) { const o = exactOption(el, v); if (o) return { option: o, how: 'نفس الكلمة' }; }
    let tie = null;
    for (const [where, t] of texts) {
      const r = optionFromText(el, t);
      if (r.option) return { option: r.option, how: 'من ' + where };
      if (r.tie && !tie) tie = r.tie;
    }
    return { tie, wanted: empty(v) ? full : String(v) };
  }

  /**
   * Tries each option of list p (recipe step index) until list c contains `wanted`. Progress is kept in
   * run.hunt, so a page reload after a choice carries on where it was.
   * @returns {Promise<{c:number,value:string,text:string,parentText:string}|null|'rejected'>}
   */
  async function hunt(at, p, c, wanted, run) {
    const steps = run.recipe.steps;
    let h = run.hunt?.at === at ? run.hunt : { at, p, c, wanted, tried: [] };
    const match = (C) => exactOption(C, wanted) || optionFromText(C, wanted).option;
    for (let n = 0; n < 200; n++) {
      await settle();
      if (amanRejection()) return 'rejected';
      const P = await waitFor(() => findEl(steps[h.p].target), 8000);
      if (!P) break;
      if (h.tried.includes(P.value)) {
        const C = await waitFor(() => { const x = findEl(steps[h.c].target); return x && loaded(x) ? x : null; }, 6000);
        const o = C && match(C);
        if (o) return { c: h.c, value: o.value, text: o.text, parentText: P.selectedOptions[0]?.text || '' };
      }
      const next = [...P.options].find((o) => !isPlaceholder(o) && !h.tried.includes(o.value));
      if (!next) break;
      if (h.orig === undefined) h = { ...h, orig: P.value };
      h = { ...h, tried: [...h.tried, next.value] };
      await update('run', (r) => ({ ...r, hunt: h })); // saved first: the choice may reload the page
      P.value = next.value;
      fire(P);
    }
    // Not found anywhere: the parent goes back to what it was.
    const P = findEl(steps[h.p].target);
    if (P && h.orig !== undefined && P.value !== h.orig) { P.value = h.orig; fire(P); await settle(); }
    await update('run', (r) => ({ ...r, hunt: null }));
    return null;
  }

  // Writes without events, so progress is saved before the portal reacts (ASP.NET postbacks reload the page).
  async function setRaw(el, v) {
    if (el.tagName === 'SELECT') {
      const o = await waitFor(() => [...el.options].find((o) => o.value === String(v)) || [...el.options].find((o) => norm(o.text) === norm(v) || norm(o.value) === norm(v)), 8000);
      if (!o) throw new Error('القيمة "' + v + '" مش موجودة في قايمة "' + (labelOf(el) || el.name) + '"');
      el.value = o.value;
    } else {
      const proto = el.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
      Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, String(v));
    }
  }
  function fire(el) {
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    el.dispatchEvent(new Event('blur'));
  }

  async function buildFiles(urls) {
    const dt = new DataTransfer();
    for (const url of urls) {
      const r = await chrome.runtime.sendMessage({ type: 'fetchFile', url });
      if (!r?.ok) throw new Error('مش قادر أحمّل الملف: ' + (r?.error || url));
      const bin = atob(r.b64); const buf = new Uint8Array(bin.length);
      for (let i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
      dt.items.add(new File([buf], r.name, { type: r.mime }));
    }
    return dt.files;
  }

  const advance = (row, extra = {}) => update('run', (r) => ({ ...r, i: r.i + 1, ...(row ? { log: [...r.log, row] } : {}), ...extra }));
  // A problem stops the run on this step (not the batch's next request) until the employee fixes it
  // on Aman's page and presses "كمّل" (the step is tried again) or "عملتها بإيدي" (the next step).
  const fail = (msg, row) => update('run', (r) => ({ ...r, status: 'blocked', problem: true, blockMessage: msg, problemRow: row ? { ...row, ok: false } : null }));
  const block = (msg) => update('run', (r) => ({ ...r, status: 'blocked', blockMessage: msg }));

  // The customer was refused before: recorded as such (motobot marks the request rejected) and the
  // batch goes on to the next request without waiting for the employee.
  const rejectedBefore = (msg) => update('run', (r) => ({ ...r, status: 'resolved', resolution: 'rejected_before', blockMessage: 'أمان قال: ' + msg }));
  // Aman refuses the customer: "مرفوض", or "عفواً هذا العميل لدية طلب كارت لذلك لا يمكن تقديم له طلب تقسيط".
  const REJECTED = /مرفوض|لا\s*يمكن\s*تقديم/;

  // Aman's red text on the page, e.g. "عفواً العميل لدية طلب مرفوض من قبل لذلك لا يمكن عمل طلب تقسيط له الأن".
  function amanRejection() {
    for (const el of document.querySelectorAll('span, font, label, td, p, b')) {
      if (host && host.contains(el)) continue;
      const t = (el.textContent || '').replace(/\s+/g, ' ').trim();
      if (!t || t.length > 250 || !REJECTED.test(t)) continue;
      if ([...el.children].some((c) => REJECTED.test(c.textContent || ''))) continue; // the innermost one
      if (visible(el)) return t;
    }
    return '';
  }

  // Cloudflare's error page instead of Aman's (e.g. "A timeout occurred", Error code 524).
  function portalDown() {
    const t = document.title + ' ' + (document.body?.innerText || '').slice(0, 1500);
    if (!/cloudflare/i.test(t)) return '';
    const m = t.match(/Error\s*code\s*(5\d\d)/i) || t.match(/\b(52[0-9]|50[234])\b/);
    return m ? 'Cloudflare ' + m[1] : /timeout occurred|bad gateway|service unavailable|origin.*(down|unreachable)/i.test(t) ? 'Cloudflare' : '';
  }

  // After a click: did the portal pop up a message?
  async function dialogAfter(since) {
    await sleep(900);
    const { run } = await store.get('run');
    const d = (run?.dialogs || []).find((x) => x.at >= since);
    if (d && REJECTED.test(d.msg)) { await rejectedBefore(d.msg); return true; }
    if (d) { await block('أمان قال: ' + d.msg); return true; }
    return false;
  }

  async function runStep(step, run) {
    const what = stepName(step);

    if (step.kind === 'page') {
      if (!(await waitFor(() => pagePath() === step.page, 45000))) return fail('مستني صفحة "' + (step.title || step.page) + '" ومافتحتش');
      return advance();
    }

    // Aman can be slow: wait up to 45 s for the element (the page may still be loading after a click).
    // Stops waiting as soon as Aman says the customer was refused before.
    const el = await waitFor(() => (amanRejection() ? 'rejected' : findEl(step.target)), 45000);
    if (el === 'rejected') return rejectedBefore(amanRejection());

    if (step.kind === 'expect') {
      const got = el ? textOf(el) : '';
      if (!el || norm(got) !== norm(step.sample)) {
        if (el) mark(el, '#dc2626');
        return block(el ? 'أمان كاتب: "' + got + '" بدل "' + step.sample + '"' : 'مالقتش رسالة "' + step.sample + '"، شوف الصفحة');
      }
      mark(el, '#16a34a');
      return advance({ label: 'رسالة الحالة', source: 'المفروض: ' + step.sample, expected: step.sample, actual: got, ok: true });
    }
    if (!el) return fail('مش لاقي "' + what + '" في الصفحة. ممكن أمان غيّر الصفحة، علّم الخطوة دي تاني.');

    if (step.kind === 'capture') {
      // "تم تقديم الحد الائتمانى بنجاح و رقم العملية : 26116832162" -> 26116832162
      const ref = await waitFor(() => refFrom(textOf(findEl(step.target) || el)), 8000) || '';
      mark(el, '#2563eb');
      if (!ref) return fail('مالقتش رقم الطلب في رسالة أمان: "' + textOf(el) + '"');
      return advance({ label: 'رقم الطلب عند أمان', source: 'من الصفحة', expected: '', actual: ref, ok: true }, { amanRef: ref, amanMessage: textOf(findEl(step.target) || el) });
    }

    if (step.kind === 'click') {
      // Old recipes marked "تأكيد صورة البطاقة" as a save stop by mistake. A click that is not a
      // save button, with a real save stop later in the recipe, is pressed like any other click.
      const saveLike = /حفظ|save|submit|ارسال|اعتماد|تقديم الطلب/i.test(norm(step.target?.text || textOf(el)));
      const laterGate = (run.recipe?.steps || []).slice(run.i + 1).some((x) => x.gate);
      if (step.gate && (saveLike || !laterGate)) {
        mark(el, '#dc2626');
        el.scrollIntoView({ block: 'center' });
        return update('run', (r) => ({ ...r, status: 'review', gateText: what }));
      }
      await advance(); // save progress first: the click may reload the page
      const t = Date.now();
      await realClick(el);
      await afterClick();
      return dialogAfter(t);
    }

    // fill
    const s = step.source;
    const row = { label: what, source: AMAN.describeSource(s), expected: '', actual: '', ok: true };

    if (s.type === 'ask') {
      mark(el, '#f59e0b');
      return advance({ ...row, expected: 'الموظف يكتبها', ok: null });
    }

    if (s.type === 'check') {
      let want = s.mode === 'always';
      if (s.mode === 'when') {
        const v = s.from === 'card' ? run.card?.[s.key] : run.order?.[s.key];
        if (empty(v)) return fail('مش عارف أحدد "' + what + '": ' + srcName({ type: s.from, key: s.key }) + ' فاضي', row);
        want = norm(v) === norm(s.equals);
      }
      row.expected = want ? 'متعلّم' : 'مش متعلّم';
      row.actual = row.expected;
      mark(el, '#16a34a');
      await advance(row);
      if (el.checked !== want && (want || el.type !== 'radio')) el.click();
      return;
    }

    if (s.type === 'file') {
      const v = run.order?.[s.key];
      if (empty(v)) {
        // Owner 2026-11-18: a photo the request lacks (e.g. no price offer) is left out, the run goes on.
        // Only the customer's own card photos are a must.
        if (['applicant_id_image', 'applicant_id_back_image'].includes(s.key)) return fail('الطلب مافيهوش ملف: ' + AMAN.fieldLabel(s.key), row);
        row.expected = 'مافيش في الطلب'; row.actual = 'اتساب'; row.ok = true;
        mark(el, '#a8a29e');
        return advance(row);
      }
      const urls = Array.isArray(v) ? v : [v];
      let files;
      try { files = await buildFiles(el.multiple ? urls : urls.slice(0, 1)); } catch (e) { return fail(e.message, row); }
      el.files = files;
      row.expected = files.length + ' ملف';
      row.actual = [...el.files].map((f) => f.name).join('، ');
      row.ok = el.files.length === files.length;
      if (!row.ok) return fail('الملف ماترفعش في "' + what + '"', row);
      mark(el, '#16a34a');
      await advance(row);
      fire(el);
      return;
    }

    // A value found by the search below for this list (e.g. district "العياط" under city "المنيب").
    const found = run.override?.[run.i];
    if (el.tagName === 'SELECT' && found) {
      await waitFor(() => loaded(el), 10000);
      const o = [...el.options].find((x) => x.value === found.value);
      if (o) {
        el.value = o.value;
        mark(el, '#16a34a');
        await advance({ ...row, expected: found.wanted + ' (لقيتها بالتدوير)', actual: o.text, ok: true });
        fire(el);
        await settle();
        return;
      }
    }

    if (el.tagName === 'SELECT' && ['field', 'card'].includes(s.type) && s.smart !== false && !s.verify) {
      let pick = await chooseSmart(el, s, run);
      const isWorkKey = /^(aman_)?work_/.test(s.key || '');
      if (!pick.option && isWorkKey) {
        // Work area not in the list: the same area as the customer's own, chosen above (owner 2026-11-16).
        const home = run.recipe.steps.find((x) => x.source?.key === 'applicant_area' && x.target?.tag === 'select');
        const homeEl = home && findEl(home.target);
        const homeText = homeEl?.selectedOptions?.[0] && !isPlaceholder(homeEl.selectedOptions[0]) ? homeEl.selectedOptions[0].text : '';
        const o = homeText && (exactOption(el, homeText) || optionFromText(el, homeText).option);
        if (o) pick = { option: o, how: 'نفس منطقة العميل' };
      }
      if (!pick.option && pick.wanted && !isWorkKey) {
        // Not in this list: Aman may file it one level lower or under another parent. Try every option
        // of the parent list until the value shows up in its child list (owner 2026-11-16).
        const steps = run.recipe.steps;
        const isSel = (k) => steps[k]?.kind === 'fill' && steps[k]?.target?.tag === 'select';
        const r = isSel(run.i + 1) ? await hunt(run.i, run.i, run.i + 1, pick.wanted, run)
          : isSel(run.i - 1) ? await hunt(run.i, run.i - 1, run.i, pick.wanted, run) : null;
        if (r === 'rejected') return rejectedBefore(amanRejection());
        if (r && r.c === run.i) {
          // The parent was changed; this list now has the value.
          const cur = findEl(step.target) || el;
          cur.value = r.value;
          mark(cur, '#16a34a');
          await advance({ ...row, expected: pick.wanted, actual: r.text + ' (بعد ما غيّرت "' + r.parentText + '")', ok: true }, { hunt: null });
          fire(cur);
          await settle();
          return;
        }
        if (r) {
          // This list (the parent) is set; the value goes in the next list.
          mark(findEl(step.target) || el, '#16a34a');
          return advance({ ...row, expected: pick.wanted, actual: r.parentText + ' (فيها "' + r.text + '" في القايمة اللي بعدها)', ok: true },
            { hunt: null, override: { ...(run.override || {}), [r.c]: { value: r.value, wanted: pick.wanted } } });
        }
      }
      if (!pick.option) {
        // Not sure which one: the employee picks it during the review instead of a wrong guess.
        mark(el, '#f59e0b');
        return advance({ ...row, expected: pick.wanted || '—', actual: pick.tie ? 'أكتر من اختيار: ' + pick.tie.slice(0, 3).join(' / ') : 'مش لاقيها في القايمة', ok: null });
      }
      el.value = pick.option.value;
      const sv = sourceValue(s, run);
      row.expected = (empty(sv) ? 'فاضي في السيستم' : sv) + ' (' + pick.how + ')';
      row.actual = pick.option.text;
      mark(el, '#16a34a');
      await advance(row);
      fire(el);
      await settle();
      return;
    }

    const v = sourceValue(s, run);
    if (s.type !== 'const' && empty(v)) return fail((s.type === 'card' ? 'البطاقة مافيهاش: ' : 'الطلب مافيهوش: ') + srcName(s), row);
    row.expected = String(v);

    if (s.verify) {
      row.actual = shown(el);
      row.ok = same(el, v);
      mark(el, row.ok ? '#0891b2' : '#dc2626');
      return advance(row); // a mismatch is shown red in the review, the employee decides
    }

    try { await setRaw(el, v); } catch (e) { return fail(e.message, row); }
    row.actual = shown(el);
    row.ok = same(el, v);
    mark(el, row.ok ? '#16a34a' : '#dc2626');
    if (!row.ok) return fail('"' + what + '" اتكتب فيها "' + row.actual + '" بدل "' + row.expected + '"', row);
    await advance(row);
    fire(el);
    if (el.tagName === 'SELECT') await settle();
  }

  /** The Aman reference in a message: the number after "رقم العملية/الطلب", else its longest number. */
  function refFrom(text) {
    const t = String(text || '').replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    const m = t.match(/رقم\s*(?:ال)?(?:عمليه|عملية|طلب)\s*[:：]?\s*(\d{5,})/);
    if (m) return m[1];
    const all = t.match(/\d{6,}/g);
    return all ? all.sort((a, b) => b.length - a.length)[0] : '';
  }
  /** After saving, when no step reads the number: look for Aman's success message anywhere on the page. */
  // Returns { ref, msg }: Aman's number and its whole success message ("تم تقديم الحد الائتمانى بنجاح و
  // رقم العملية : 26116832162"), or null when Aman shows none.
  async function autoRef() {
    const RE = /رقم\s*(?:ال)?(?:عمليه|عملية|طلب)\s*[:：]?\s*(\d{5,})/;
    const digits = (t) => t.replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
    const OK = /بنجاح|تم\s*(?:الحفظ|التسجيل|تقديم|ارسال|إرسال)/;
    const since = Date.parse(run0().approvedAt || '') || 0;
    return waitFor(() => {
      // A popup after the save ("تم الحفظ بنجاح رقم 26116832163").
      for (const d of (lastDialogs || []).filter((x) => x.at >= since)) {
        const t = digits(d.msg || '');
        const n = (t.match(RE) || [])[1] || (OK.test(t) ? (t.match(/\d{8,}/g) || []).sort((a, b) => b.length - a.length)[0] : '');
        if (n) return { ref: n, msg: d.msg };
      }
      let m = digits(document.body?.innerText || '').match(RE);
      if (!m) {
        // Another wording: a short success message with a long number in it.
        for (const el of document.querySelectorAll('span, font, label, td, p, b, div')) {
          if (host && host.contains(el)) continue;
          const t = digits((el.textContent || '').replace(/\s+/g, ' ').trim());
          if (t.length > 300 || !OK.test(t) || [...el.children].some((c) => OK.test(c.textContent || ''))) continue;
          const n = (t.match(/\d{8,}/g) || []).sort((a, b) => b.length - a.length)[0];
          if (n && visible(el)) return { ref: n, msg: (el.textContent || '').replace(/\s+/g, ' ').trim() };
        }
        return null;
      }
      let msg = '';
      for (const el of document.querySelectorAll('span, font, label, td, p, b, div')) {
        if (host && host.contains(el)) continue;
        const t = (el.textContent || '').replace(/\s+/g, ' ').trim();
        if (t.length <= 400 && RE.test(digits(t)) && ![...el.children].some((c) => RE.test(digits(c.textContent || '')))) { msg = t; break; }
      }
      return { ref: m[1], msg: msg || m[0] };
    }, 20000);
  }

  let again = false;
  const run0 = () => lastRun || {};
  /** Clicks through the page hook (page world) so javascript: links and ASP.NET postbacks work. */
  async function realClick(el) {
    const token = Math.random().toString(36).slice(2);
    el.setAttribute('data-aman-click', token);
    window.postMessage({ __amanClick: true, token }, '*');
    await sleep(150);
    if (el.isConnected && el.getAttribute('data-aman-click') === token) { el.removeAttribute('data-aman-click'); el.click(); } // no hook on this page
  }

  /** After a click: wait for Aman's postback / page load to finish before the next step. */
  async function afterClick() {
    await sleep(500);
    await waitFor(() => document.readyState === 'complete' && document.documentElement.dataset.amanBusy !== '1', 60000);
    await sleep(400);
  }

  async function runLoop() {
    // A change (e.g. the employee's approval) that lands while the loop is finishing must not be lost.
    if (running) { again = true; return; }
    running = true;
    again = false;
    try {
      for (;;) {
        const { run } = await store.get('run');
        if (!run || run.tabId !== myTab) break;
        if (run.status === 'approved') {
          const step = run.recipe.steps[run.i];
          const el = findEl(step.target);
          if (!el) { await fail('زرار الحفظ اختفى من الصفحة'); break; }
          await update('run', (r) => ({ ...r, i: r.i + 1, status: 'running', approvedAt: new Date().toISOString() }));
          const t = Date.now();
          await realClick(el);
          await afterClick();
          if (await dialogAfter(t)) break;
          continue;
        }
        if (run.status !== 'running') break;
        if (portalDown()) {
          // Owner 2026-11-18: Aman's server timed out (Cloudflare 524 page): the panel opens Aman's first
          // page again and starts the request over. Not after save was pressed: it may have gone through.
          await update('run', (r) => r.approvedAt
            ? { ...r, status: 'blocked', problem: true, blockMessage: 'صفحة أمان وقعت (' + portalDown() + ') بعد ما دوست حفظ. شوف في أمان الطلب اترفع ولا لأ قبل ما تعيده' }
            : { ...r, status: 'restart', restarts: (r.restarts || 0) + 1, blockMessage: portalDown() });
          break;
        }
        const rejection = amanRejection();
        if (rejection) { await rejectedBefore(rejection); break; }
        const step = run.recipe.steps[run.i];
        if (!step) {
          let ref = run.amanRef, msg = run.amanMessage || '';
          lastRun = run;
          for (const d of run.dialogs || []) if (!lastDialogs.some((x) => x.at === d.at)) lastDialogs.push(d); // from before a reload
          if (!ref && run.approvedAt) { const got = await autoRef(); if (got) ({ ref, msg } = got); }
          if (run.approvedAt && !ref) {
            // Saved, but Aman showed no request number: not uploaded. The employee looks at the page,
            // fixes what Aman complains about and saves by hand, then "كمّل" looks for the number again.
            await update('run', (r) => ({ ...r, status: 'blocked', problem: true, problemRow: null, noRef: true,
              blockMessage: 'دوست حفظ بس أمان ماطلّعش رقم طلب، يعني الطلب مااترفعش. شوف صفحة أمان: لو فيه غلط صلّحه واحفظ بإيدك' }));
            break;
          }
          await update('run', (r) => ({ ...r, ...(ref ? { amanRef: ref, amanMessage: msg } : {}), status: 'done', finishedAt: new Date().toISOString() }));
          break;
        }
        try {
          await runStep(step, run);
        } catch (e) {
          await fail('حصلت مشكلة: ' + e.message);
        }
        await sleep(150);
      }
    } finally {
      running = false;
      if (again) setTimeout(runLoop, 0);
    }
  }

  // ---------- wiring ----------
  function onTeach() {
    if (isTeaching()) { notePage(); schedule(); } else if (host) removeOverlay();
  }

  chrome.storage.onChanged.addListener((ch, area) => {
    if (area !== 'local') return;
    if (ch.teach) { teach = ch.teach.newValue || null; onTeach(); }
    if (ch.run) runLoop();
  });

  new MutationObserver(() => { if (isTeaching()) schedule(); })
    .observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'class', 'hidden'] });
  addEventListener('scroll', schedule, true);
  addEventListener('resize', schedule);
  setInterval(() => { if (isTeaching()) { notePage(); schedule(); } }, 1000);

  chrome.runtime.sendMessage({ type: 'whoami' }, async (r) => {
    myTab = r?.tabId ?? null;
    teach = (await store.get('teach')).teach || null;
    onTeach();
    runLoop();
  });
})();
