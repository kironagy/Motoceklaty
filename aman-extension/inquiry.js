// متابعة الاستعلام (owner 2026-11-20): runs in its own Aman tab, beside the uploader's tab.
// Teach (inqTeach) - its own recipe, recorded in order like the upload recipes:
//   - picks in a list (the search type) and clicks are recorded as they happen;
//   - "علّم خانة": a box filled from the national id, the copied request number, or motobot's Aman number;
//   - "اسم يوزر أمان": where Aman shows the signed-in user (Shady_Amr_auto);
//   - "انسخ من الجدول": in Aman's results table, the row whose user is ours gives its request number;
//   - "مكان الحالة": where Aman writes the status.
// Run (inqRun): replay it for every request under inquiry, read the status, send it to motobot.
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

  // ---------- table helpers ----------
  const cellText = (td) => clean(td?.innerText || td?.textContent || '', 200);
  const headerRow = (table) => [...table.rows].find((r) => r.querySelector('th')) || null;
  const headerOf = (table, i) => cellText(headerRow(table)?.cells[i]);
  function columnIndex(table, header, fallback) {
    const h = headerRow(table);
    if (h && header) {
      const i = [...h.cells].findIndex((c) => norm(cellText(c)) === norm(header));
      if (i >= 0) return i;
    }
    return fallback;
  }
  const dataRows = (table) => [...table.rows].filter((r) => !r.querySelector('th') && r.cells.length > 1);
  const digits = (s) => (String(s).match(/\d{4,}/) || [''])[0];

  // ---------- teach ----------
  const isTeaching = () => teach && teach.active && teach.tabId === myTab;
  let banner = null;
  const PICK = {
    fill: 'دوس على الخانة اللي هتتملي (الرقم القومي أو رقم الطلب)',
    user: 'دوس على اسم يوزر أمان (مثلا Shady_Amr_auto اللي فوق)',
    copy: 'في جدول النتايج: دوس على الخانة اللي فيها اسم اليوزر',
    copy2: 'دلوقتي دوس على رقم الطلب في نفس الصف',
    result: 'دوس على المكان اللي أمان بيكتب فيه حالة العميل',
    reason: 'دوس على المكان اللي أمان بيكتب فيه السبب',
    status: 'دوس على الحالة اللي أمان كتبها',
  };

  function showBanner() {
    const text = !isTeaching() ? '' : teach.pick ? PICK[teach.pick]
      : teach.phase === 'more' ? 'بيسجّل خطوات الحالة "' + (teach.map?.[teach.row]?.text || '') + '" لحد ما السبب يظهر' : 'بيسجّل الاختيارات والضغطات بالترتيب';
    if (!text) { banner?.remove(); banner = null; return; }
    if (!banner) {
      banner = document.createElement('div');
      banner.style.cssText = 'position:fixed;bottom:0;left:0;right:0;z-index:2147483647;background:#0f172a;color:#5eead4;'
        + 'font:700 14px Tahoma,Arial,sans-serif;padding:8px;text-align:center;direction:rtl;pointer-events:none';
      document.documentElement.appendChild(banner);
    }
    banner.textContent = 'تعليم متابعة الاستعلام: ' + text;
  }

  const addStep = (step) => update('inqTeach', (t) => {
    if (!t) return;
    // the steps after the status (owner 2026-11-21): their own list
    if (t.phase === 'more' && t.map?.[t.row]) {
      const map = t.map.map((m) => ({ ...m }));
      const mo = { steps: [], reason: null, map: [], ...(map[t.row].more || {}) };
      const list = [...(mo.steps || [])];
      const prev = list[list.length - 1];
      if (prev && step.kind === 'select' && prev.kind === 'select' && prev.target.css === step.target.css) list.splice(-1, 1, step);
      else list.push(step);
      map[t.row] = { ...map[t.row], more: { ...mo, steps: list } };
      return { ...t, map };
    }
    const steps = [...(t.steps || [])];
    const at = Number.isInteger(t.insertAt) ? t.insertAt : steps.length;
    const last = steps[at - 1];
    // a list changed twice in a row: keep the last choice only
    if (last && step.kind === 'select' && last.kind === 'select' && last.target.css === step.target.css) steps.splice(at - 1, 1, step);
    else steps.splice(at, 0, step);
    const grew = steps.length > (t.steps || []).length;
    return { ...t, steps, insertAt: Number.isInteger(t.insertAt) && grew ? at + 1 : t.insertAt, url: t.url || location.href };
  });

  const TYPING = 'input:not([type=submit]):not([type=button]):not([type=image]):not([type=checkbox]):not([type=radio]), textarea, select, option';
  const CLICKABLE = 'button, a, [role=button], [role=tab], input[type=submit], input[type=button], input[type=image], [onclick], [data-toggle], [data-bs-toggle], li';
  let lastClick = { css: '', at: 0 };

  document.addEventListener('click', (e) => {
    if (!isTeaching()) return;
    const kind = teach.pick;
    if (kind) {
      e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
      const el = e.target;
      if (kind === 'fill') {
        const box = el.closest('input, textarea') || el;
        const hasFill = (teach.steps || []).some((s) => s.kind === 'fill' || s.kind === 'nid');
        addStep({ kind: 'fill', target: locate(box), source: hasFill ? 'ref' : 'national_id' });
        update('inqTeach', (t) => (t ? { ...t, pick: null } : t));
      } else if (kind === 'user') {
        update('inqTeach', (t) => (t ? { ...t, user: locate(el, 'result'), userText: clean(el.innerText, 80), pick: null } : t));
      } else if (kind === 'copy' || kind === 'copy2') {
        const td = el.closest('td, th');
        const table = td?.closest('table');
        if (!td || !table) return alert('دوس على خانة جوه جدول النتايج.');
        if (kind === 'copy') {
          update('inqTeach', (t) => (t ? { ...t, pick: 'copy2', copyA: { table: locate(table, 'result'), col: td.cellIndex, header: headerOf(table, td.cellIndex), text: cellText(td) } } : t));
        } else {
          const a = teach.copyA;
          if (!a) return;
          addStep({ kind: 'copy', table: a.table, userCol: a.col, userHeader: a.header, userSample: a.text, refCol: td.cellIndex, refHeader: headerOf(table, td.cellIndex), sample: cellText(td) });
          update('inqTeach', (t) => (t ? { ...t, pick: null, copyA: null } : t));
        }
      } else if (kind === 'reason') {
        // the reason of the status being taught: where it shows, and its words as a first rule
        const text = clean(el.innerText || el.value, 300);
        update('inqTeach', (t) => {
          if (!t || !t.map?.[t.row]) return t ? { ...t, pick: null } : t;
          const map = t.map.map((m) => ({ ...m }));
          const mo = { steps: [], reason: null, map: [], ...(map[t.row].more || {}) };
          const rules = [...(mo.map || [])];
          if (text && !rules.some((r) => norm(r.text) === norm(text))) rules.push({ text: clean(text, 120), status: 'paused_aman' });
          map[t.row] = { ...map[t.row], more: { ...mo, reason: locate(el, 'result'), reasonText: text, map: rules } };
          return { ...t, map, pick: null };
        });
      } else if (kind === 'status') {
        // owner 2026-11-21: click a status on Aman's page - it is where the status shows, and a row to decide
        const text = clean(el.innerText || el.value, 120);
        update('inqTeach', (t) => {
          if (!t) return t;
          const map = [...(t.map || [])];
          if (text && !map.some((m) => norm(m.text) === norm(text))) map.push({ text, status: 'pending', more: null });
          return { ...t, result: locate(el, 'result'), lastText: text, map, pick: null };
        });
      } else if (kind === 'result') {
        update('inqTeach', (t) => (t ? { ...t, result: locate(el, 'result'), lastText: clean(el.innerText || el.value, 300), pick: null } : t));
      }
      return;
    }
    if (e.target.closest(TYPING)) return;
    // the click goes through (the search really runs while teaching); it is only noted
    // a click on plain text (a table cell, a label) does nothing on the page: not a step
    // (owner 2026-11-21: clicking the request number in the table was saved as a click)
    const el = e.target.closest(CLICKABLE);
    if (!el) return;
    const target = locate(el, 'click');
    if (target.css === lastClick.css && Date.now() - lastClick.at < 500) return;
    lastClick = { css: target.css, at: Date.now() };
    addStep({ kind: 'click', target });
  }, true);

  document.addEventListener('change', (e) => {
    if (!isTeaching() || teach.pick) return;
    const el = e.target;
    if (el.matches?.('input, textarea') && el.closest(TYPING)) return typed(el);
    if (el.tagName !== 'SELECT') return;
    addStep({ kind: 'select', target: locate(el), value: el.value, text: clean(el.options[el.selectedIndex]?.text, 80) });
  }, true);

  // typing in a box while teaching is a fill step (owner 2026-11-21: the request number typed by
  // hand must be the copied one when running). The source is guessed, and changed in the panel.
  function typed(el) {
    const v = clean(el.value, 80);
    if (!v) return;
    const target = locate(el);
    const more = teach.phase === 'more';
    const steps = (more ? teach.map?.[teach.row]?.more?.steps : teach.steps) || [];
    const at = !more && Number.isInteger(teach.insertAt) ? teach.insertAt : steps.length;
    const last = steps[at - 1];
    // the box was just marked with "علّم خانة": that step already types here
    if (last && (last.kind === 'fill' || last.kind === 'nid') && last.target?.css === target.css) return;
    const before = steps.slice(0, at);
    const copied = before.some((s) => s.kind === 'copy');
    const hasFill = before.some((s) => s.kind === 'fill' || s.kind === 'nid');
    const source = /^\d{14}$/.test(v) && !copied ? 'national_id' : (copied ? 'ref' : (hasFill ? 'ref' : 'national_id'));
    addStep({ kind: 'fill', target, source });
  }

  // ---------- run ----------
  const userLink = () => [...document.querySelectorAll('a[href*="resetpassword" i], a[href*="changepassword" i]')].find((a) => clean(a.innerText, 80)) || null;

  // a recipe that ends typing the request number without pressing search (owner 2026-11-21):
  // press the search taught after the first box again
  function withSearch(steps) {
    const last = steps[steps.length - 1];
    if (!last || (last.kind !== 'fill' && last.kind !== 'nid')) return steps;
    const firstFill = steps.findIndex((s) => s.kind === 'fill' || s.kind === 'nid');
    const search = steps.slice(firstFill + 1).find((s) => s.kind === 'click');
    return search ? [...steps, search] : steps;
  }
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

  const textIn = (el) => (el ? clean(el.innerText || el.value, 500) : '');
  const resultText = (recipe) => textIn(findEl(recipe.result));

  function mapStatus(recipe, text) {
    const row = matchRow(recipe.map, text);
    return row ? row.status : null;
  }
  const matchRow = (map, text) => { const t = norm(text); return (map || []).find((m) => m.text && t.includes(norm(m.text))) || null; };
  // a status's own steps; a recipe from before kept one shared "more"
  const moreOf = (recipe, row) => (row?.more?.steps?.length ? row.more : (row?.status === '_more' && recipe.more?.steps?.length ? recipe.more : null));

  // the last search's table / status must not be read as this one's: mark what is on the page now
  function markPage(recipe, reasonT) {
    const marks = {};
    const res = findEl(recipe.result);
    if (res) { res.dataset.motoSeen = '1'; marks.prev = textIn(res); } else marks.prev = '';
    const why = reasonT && findEl(reasonT);
    if (why) { why.dataset.motoSeen = '1'; marks.prevReason = textIn(why); } else marks.prevReason = '';
    for (const s of recipe.steps || []) {
      if (s.kind !== 'copy') continue;
      const tb = findEl(s.table);
      if (tb) { tb.dataset.motoSeen = '1'; marks.prevTable = clean(tb.innerText, 3000); }
    }
    return marks;
  }

  // the row of our Aman user -> its request number (the newest when there are several)
  function copyRef(step, names) {
    const wanted = names.map(norm).filter(Boolean);
    const table = findEl(step.table);
    if (!table) return { missing: true };
    const uc = columnIndex(table, step.userHeader, step.userCol);
    const rc = columnIndex(table, step.refHeader, step.refCol);
    const mine = dataRows(table).filter((r) => {
      const u = norm(cellText(r.cells[uc]));
      return u && wanted.some((w) => u === w || u.includes(w));
    });
    const refs = mine.map((r) => digits(cellText(r.cells[rc])) || cellText(r.cells[rc])).filter(Boolean);
    if (!refs.length) return { none: true };
    return { ref: refs.sort((a, b) => (b.length - a.length) || (b > a ? 1 : -1))[0], count: refs.length };
  }

  const api = (method, path, body) => AMAN.api(method, path, body);

  async function stillMine() {
    const r = (await store.get('inqRun')).inqRun;
    return r && r.status === 'running' && r.tabId === myTab ? r : null;
  }

  async function record(item, text, status, extra = {}) {
    let row = { id: item.id, name: item.name, text, status, at: Date.now(), ...extra };
    try {
      const res = await api('POST', '/inquiry/requests/' + item.id + '/result', { text, status, reason: extra.reason || null });
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
      return { ...r, i: r.i + 1, step: 0, phase: null, mainText: null, moreRow: null, retried: false, vars: { me: r.vars?.me }, stats: s, log: [row, ...(r.log || [])].slice(0, 300), error: null };
    });
  }

  const fail = (error) => update('inqRun', (r) => ({ ...r, status: 'error', error }));

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
          // the next round: the queue again from motobot (oldest first)
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
        const more = run.phase === 'more';
        const mo = more ? moreOf(recipe, recipe.map?.[run.moreRow]) : null;
        const steps = more ? (mo?.steps || []) : withSearch(recipe.steps || []);
        const step = run.step || 0;
        const firstFill = steps.findIndex((s) => s.kind === 'fill' || s.kind === 'nid');
        const vars = run.vars || {};

        if (step === 0 && !more) {
          // our Aman user, read off the page once per run
          let me = vars.me || (run.users?.length ? run.users.join('، ') : '');
          if (!me && (recipe.user || steps.some((s) => s.kind === 'copy'))) {
            // owner 2026-11-21: the marked spot was not found on the run's page; the header link to
            // Aman's own password page carries the user name on every page
            me = textIn(await waitFor(() => (recipe.user && findEl(recipe.user)) || userLink(), 10000)) || textIn(userLink());
            if (!me) return fail('مش لاقي اسم يوزر أمان في الصفحة. اكتبه في خانة "يدور على يوزر أمان مين".');
          }
          await update('inqRun', (r) => ({ ...r, vars: { ...(r.vars || {}), me }, origin: performance.timeOrigin }));
          if (!vars.me && me) continue;
        }

        if (step < steps.length) {
          const s = steps[step];

          if (s.kind === 'copy') {
            // wait for this search's table: a new one, or new rows in the marked one
            await waitFor(() => {
              const tb = findEl(s.table);
              return tb && (tb.dataset.motoSeen !== '1' || clean(tb.innerText, 3000) !== run.prevTable) ? tb : null;
            }, 15000);
            await sleep(300);
            const got = copyRef(s, run.users?.length ? run.users : [vars.me || '']);
            if (!(await stillMine())) return;
            if (!got.ref) {
              // no table at all (Aman was slow, or the search ran before the page was ready): this customer
              // from the top once before it is written down
              if (got.missing && !run.retried) {
                await update('inqRun', (r) => ({ ...r, step: 0, retried: true, vars: { me: r.vars?.me } }));
                location.href = recipe.url;
                return;
              }
              await record(item, got.missing ? 'جدول النتايج ماظهرش' : 'مافيش طلب في الجدول باسم ' + (vars.me || 'اليوزر'), null);
              location.href = recipe.url;
              return;
            }
            await update('inqRun', (r) => ({ ...r, step: step + 1, vars: { ...(r.vars || {}), ref: got.ref } }));
            continue;
          }

          const el = await waitFor(() => findEl(s.target), 15000);
          if (!el && more) {
            if (!(await stillMine())) return;
            await record(item, run.mainText || '', null, { ref: vars.ref || null, reason: 'مالقيتش خطوة ' + (step + 1) + ' من خطوات السبب' });
            location.href = recipe.url;
            return;
          }
          if (!el) {
            if (step <= firstFill) {
              // not on the search page: back to it once, then it is the recipe's fault
              if (!run.navigated) {
                await update('inqRun', (r) => ({ ...r, step: 0, navigated: true }));
                location.href = recipe.url;
                return;
              }
              return fail('مش لاقي خطوة ' + (step + 1) + ' في صفحة أمان. علّم وصفة متابعة الاستعلام تاني.');
            }
            // the page reloaded halfway (Aman, or the tab was opened late): this customer from the top, once
            if (run.origin !== performance.timeOrigin && !run.retried) {
              await update('inqRun', (r) => ({ ...r, step: 0, retried: true, origin: performance.timeOrigin }));
              continue;
            }
            if (!(await stillMine())) return;
            await record(item, 'أمان مارجّعش بيانات للعميل ده', null);
            location.href = recipe.url;
            return;
          }

          let value = null;
          if (s.kind === 'fill' || s.kind === 'nid') {
            const src = s.kind === 'nid' ? 'national_id' : s.source;
            value = src === 'ref' ? vars.ref : src === 'aman_ref' ? item.aman_ref : item.national_id;
            if (!value) {
              if (!(await stillMine())) return;
              await record(item, src === 'aman_ref' ? 'مافيش رقم طلب أمان متسجل في موتوجيت' : 'مافيش رقم طلب اتنسخ', null);
              continue;
            }
          }
          // marks before a click (it may draw new results); the step is saved before it runs,
          // so a click that reloads the page resumes on the next one
          const marks = s.kind === 'click' ? markPage(recipe, mo?.reason) : {};
          await update('inqRun', (r) => ({ ...r, ...marks, step: step + 1, navigated: false, stepAt: Date.now() }));
          if (s.kind === 'select') {
            if (!choose(el, s)) return fail('الاختيار "' + (s.text || s.value) + '" مش موجود في القايمة. علّم الوصفة تاني.');
          } else if (value !== null) {
            setValue(el, String(value));
          } else {
            el.click();
          }
          await sleep(s.kind === 'click' ? 900 : 300);
          continue;
        }

        if (more) {
          // the steps after the status are done: the reason (a new element, or new words in the marked one)
          const end = (run.stepAt || Date.now()) + 20000;
          let why = await waitFor(() => {
            const el = mo?.reason && findEl(mo.reason);
            const t = textIn(el);
            return t && (el.dataset.motoSeen !== '1' || t !== run.prevReason) ? t : null;
          }, Math.max(1000, end - Date.now())) || '';
          if (!why && run.prevReason && mo?.reason && textIn(findEl(mo.reason)) === run.prevReason) why = run.prevReason;
          await sleep(200);
          if (!(await stillMine())) return;
          await record(item, run.mainText || '', why ? mapStatus(mo || {}, why) : null, { ref: vars.ref || null, reason: why || 'السبب ماظهرش' });
          await sleep(1200);
          continue;
        }

        // all steps done: wait for the status - a new element (Aman redrew it, or the page reloaded)
        // or new words in the marked one
        const end = (run.stepAt || Date.now()) + 20000;
        let text = await waitFor(() => {
          const el = findEl(recipe.result);
          const t = textIn(el);
          return t && (el.dataset.motoSeen !== '1' || t !== run.prev) ? t : null;
        }, Math.max(1000, end - Date.now())) || '';
        // the same words as before the last search: a real answer too (one status twice)
        if (!text && run.prev && resultText(recipe) === run.prev) text = run.prev;
        await sleep(200);
        if (!(await stillMine())) return;
        const row = text ? matchRow(recipe.map, text) : null;
        const status = row ? row.status : null;
        // a status that needs more steps on Aman to show its reason
        if (status === '_more' && moreOf(recipe, row)) {
          await update('inqRun', (r) => ({ ...r, phase: 'more', step: 0, mainText: text, moreRow: recipe.map.indexOf(row) }));
          continue;
        }
        await record(item, text || 'مفيش حالة ظهرت', status === '_more' ? null : status, { ref: vars.ref || null });
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
