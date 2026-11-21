// Panel side of متابعة الاستعلام (owner 2026-11-20); the work itself runs in inquiry.js inside its own Aman tab.
// - tab "متابعة الاستعلام": pull the requests under inquiry (oldest first), then start;
// - its recipe is taught in the same tab (owner 2026-11-21), recorded like the upload recipes.
// Uses panel.js's globals: $, store, me, activeTab.
(() => {
  let statuses = {};
  let recipe = null;
  const esc = (v) => String(v ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const SOURCES = { national_id: 'الرقم القومي', ref: 'رقم الطلب المنسوخ من الجدول', aman_ref: 'رقم طلب أمان المتسجل في موتوجيت' };

  async function loadRecipe() {
    try {
      const r = await AMAN.api('GET', '/inquiry/recipe');
      statuses = r.statuses || {};
      recipe = r.recipe;
    } catch { /* not signed in yet */ }
  }

  const say = (cls, text) => { const b = $('inqStatus'); b.hidden = false; b.className = 'status ' + cls; b.textContent = text; };

  // ---------- run ----------
  $('inqPull').onclick = async () => {
    $('inqPullMsg').textContent = 'بيسحب…';
    try {
      const q = await AMAN.api('GET', '/inquiry/queue');
      await store.set({ inqBatch: { items: q.requests, pulledAt: Date.now() } });
      $('inqPullMsg').textContent = q.requests.length ? `اتسحب ${q.requests.length} طلب تحت الاستعلام، من الأقدم للأحدث.` : 'مافيش طلبات تحت الاستعلام.';
    } catch (e) {
      $('inqPullMsg').textContent = 'ماقدرش يسحب: ' + e.message;
    }
  };

  $('inqStart').onclick = async () => {
    await loadRecipe();
    if (!recipe?.steps?.length) return say('error', 'وصفة متابعة الاستعلام لسه ماتعلّمتش. المسؤول يعلّمها من تبويب "تعليم".');
    const { inqBatch } = await store.get('inqBatch');
    if (!inqBatch?.items?.length) return say('error', 'اسحب الطلبات الأول.');
    // its own tab, so the uploader keeps its tab (owner: both run together)
    const tab = await chrome.tabs.create({ url: recipe.url, active: false });
    await store.set({ inqRun: {
      tabId: tab.id, status: 'running', recipe, items: inqBatch.items, i: 0, step: 0, round: 1, vars: {},
      // owner 2026-11-21: old requests were made under another employee's Aman user - he picks whose rows to copy
      users: $('inqUsers').value.split(/[,،]/).map((u) => u.trim()).filter(Boolean),
      repeat: Number($('inqRepeat').value) || 0, stats: {}, log: [], startedAt: Date.now(),
    } });
  };

  store.get('inqUsers').then(({ inqUsers }) => { $('inqUsers').value = inqUsers || ''; });
  $('inqUsers').oninput = () => store.set({ inqUsers: $('inqUsers').value });

  $('inqStop').onclick = async () => {
    const { inqRun } = await store.get('inqRun');
    if (inqRun) await store.set({ inqRun: { ...inqRun, status: 'stopped' } });
  };

  const label = (s) => (s ? (statuses[s] || s) : '');

  function renderBatch(batch, run) {
    const active = run && ['running', 'waiting'].includes(run.status);
    $('inqStart').disabled = !batch?.items?.length;
    $('inqStart').hidden = !!active;
    $('inqStop').hidden = !active;
    $('inqPull').disabled = !!active;
    const items = batch?.items || [];
    $('inqListBox').hidden = !items.length || !!active;
    $('inqList').querySelector('tbody').innerHTML = items.slice(0, 200).map((r, i) => `<tr><td class="muted">${i + 1}</td>`
      + `<td>${esc(r.name)} <span class="muted">#${r.id}</span></td><td class="muted">${esc(r.national_id)}</td><td class="muted">${esc(r.created_at || '')}</td></tr>`).join('')
      + (items.length > 200 ? `<tr><td colspan="4" class="muted">و${items.length - 200} طلب كمان</td></tr>` : '');
  }

  function renderRun(run) {
    if (!run) { $('inqStatus').hidden = true; $('inqStats').innerHTML = ''; $('inqLogBox').hidden = true; $('inqBarWrap').hidden = true; return; }
    const total = (run.items || []).length;
    const cur = run.items?.[run.i];
    say({ running: 'running', waiting: 'done', done: 'done', error: 'error', stopped: 'review' }[run.status] || '',
      run.status === 'running' ? (cur ? `بيستعلم عن ${cur.name || ''} (${run.i + 1} من ${total})` : 'بيخلّص…')
        : run.status === 'waiting' ? `خلصت اللفة. اللفة الجاية الساعة ${new Date(run.nextAt).toLocaleTimeString('ar-EG', { hour: 'numeric', minute: '2-digit' })}`
        : run.status === 'done' ? `خلصت: اتراجع ${total} طلب`
        : run.status === 'error' ? run.error
        : 'اتوقفت');
    if (run.error && run.status !== 'error') $('inqStatus').textContent += ' — ' + run.error;
    $('inqBarWrap').hidden = run.status !== 'running';
    $('inqBar').style.width = total ? Math.round((run.i / total) * 100) + '%' : '0';
    const s = run.stats || {};
    $('inqStats').innerHTML = (run.vars?.me ? `<b>يوزر أمان</b><span>${esc(run.vars.me)}</span>` : '')
      + `<b>اللفة</b><span>${run.round || 1}</span><b>اتراجع</b><span>${s.checked || 0}</span>`
      + `<b>حالته اتغيرت</b><span>${s.changed || 0}</span><b>من غير حالة متعلّمة</b><span>${s.unknown || 0}</span>`
      + (s.errors ? `<b>مشاكل</b><span>${s.errors}</span>` : '');
    const log = run.log || [];
    $('inqLogBox').hidden = !log.length;
    $('inqLog').querySelector('tbody').innerHTML = log.slice(0, 80).map((r) => `<tr><td>${esc(r.name)} <span class="muted">#${r.id}</span>${r.ref ? `<br><span class="muted">طلب أمان ${esc(r.ref)}</span>` : ''}</td>`
      + `<td>${esc(r.text)}${r.reason ? `<br><span class="muted">السبب: ${esc(r.reason)}</span>` : ''}</td><td>${r.error ? '<span class="warn">' + esc(r.error) + '</span>'
        : r.skipped ? esc(r.skipped) : r.changed ? '<b>بقى ' + esc(label(r.status)) + '</b>' : r.status ? 'لسه ' + esc(label(r.status)) : '<span class="warn">مااتغيرش</span>'}</td></tr>`).join('');
  }

  // ---------- teach (in this tab, owner 2026-11-21: "عايز الخطوات تكون سهلة وبسيطة") ----------
  // inqTeach holds everything being taught: the steps, our user, where the status is, and the statuses -
  // each status (added by clicking it on Aman's page) says what it becomes here, or that it needs more
  // steps on Aman whose reason decides (Aman's Pending: متوقف امان with the reason, or استعلام عمل).
  const MORE = '_more';
  const blankMore = () => ({ steps: [], reason: null, reasonText: '', map: [] });

  // a recipe saved before statuses had their own steps kept one shared "more"
  function withRows(base) {
    return (base?.map || []).map((m) => ({ ...m, more: m.more || (m.status === MORE && base.more ? { ...blankMore(), ...base.more } : null) }));
  }

  async function startTeach(base) {
    await loadRecipe();
    const tab = await activeTab();
    if (!tab) return;
    base = base || {};
    await store.set({ inqTeach: {
      active: true, tabId: tab.id, pick: null, phase: null, row: null, url: base.url || tab.url, steps: base.steps || [],
      user: base.user || null, userText: '', result: base.result || null, lastText: '', map: withRows(base),
    } });
  }

  $('inqTeachStart').onclick = async () => {
    await loadRecipe();
    if (recipe && !confirm('فيه وصفة محفوظة. التعليم الجديد هيستبدلها لما تحفظ. تكمل؟')) return;
    startTeach({ map: recipe?.map, more: recipe?.more });
  };
  $('inqTeachEdit').onclick = async () => {
    await loadRecipe();
    if (!recipe) return alert('مافيش وصفة محفوظة لمتابعة الاستعلام.');
    startTeach(recipe);
  };
  $('inqTeachEnd').onclick = async () => { if (confirm('تلغي التعليم من غير ما تحفظ؟')) await store.set({ inqTeach: null }); };

  document.querySelectorAll('[data-inqpick]').forEach((b) => b.onclick = async () => {
    const { inqTeach } = await store.get('inqTeach');
    if (inqTeach) await store.set({ inqTeach: { ...inqTeach, pick: b.dataset.inqpick, copyA: null } });
  });

  async function editTeach(fn) {
    const { inqTeach } = await store.get('inqTeach');
    if (!inqTeach) return;
    const t = JSON.parse(JSON.stringify(inqTeach));
    t.steps = t.steps || [];
    t.map = t.map || [];
    fn(t);
    await store.set({ inqTeach: t });
  }

  $('inqMapAdd').onclick = () => editTeach((t) => { t.map.push({ text: '', status: 'pending', more: null }); });

  // "بدوس حفظ مش بيحفظ" - the reason is said out loud
  const saveMsg = (m) => { $('inqTeachMsg').textContent = m; $('inqTeachMsg').scrollIntoView({ block: 'center' }); alert(m); };
  $('inqSave').onclick = async () => {
    const { inqTeach: t } = await store.get('inqTeach');
    const steps = t?.steps || [];
    if (!steps.some((x) => x.kind === 'fill' || x.kind === 'nid')) return saveMsg('علّم خانة الرقم القومي الأول.');
    if (!t.result) return saveMsg('دوس على حالة في صفحة أمان الأول.');
    const map = (t.map || []).filter((m) => m.text?.trim()).map((m) => ({
      text: m.text.trim(), status: m.status,
      more: m.status === MORE ? { steps: m.more?.steps || [], reason: m.more?.reason || null, map: (m.more?.map || []).filter((r) => r.text?.trim()) } : null,
    }));
    if (!map.length) return saveMsg('زوّد حالة واحدة على الأقل: دوس "دوس على حالة" وبعدين على الحالة في صفحة أمان.');
    const bad = map.find((m) => m.status === MORE && (!m.more.steps.length || !m.more.reason));
    if (bad) return saveMsg('الحالة "' + bad.text + '": سجّل خطواتها ودوس على السبب.');
    try {
      const body = { url: t.url, steps, result: t.result, user: t.user || null, map };
      const r = await AMAN.api('PUT', '/inquiry/recipe', body);
      $('inqTeachMsg').textContent = '';
      recipe = { ...body, version: r.version };
      await store.set({ inqTeach: null });
      $('inqSaved').textContent = 'اتحفظت الوصفة (نسخة ' + r.version + ').';
    } catch (e) {
      saveMsg('ماتحفظتش: ' + e.message);
    }
  };

  const KIND = { select: ['select', 'اختيار'], fill: ['fill', 'خانة'], nid: ['fill', 'خانة'], click: ['click', 'ضغطة'], copy: ['copy', 'نسخ'] };
  function stepLine(x) {
    if (x.kind === 'select') return 'اختار "' + esc(x.text || x.value) + '"';
    if (x.kind === 'click') return 'دوس "' + esc(x.target?.text || x.target?.label || x.target?.id || x.target?.tag) + '"';
    if (x.kind === 'copy') return 'من الجدول: الصف اللي في عمود "' + esc(x.userHeader || ('رقم ' + (x.userCol + 1))) + '" يوزرنا، انسخ "' + esc(x.refHeader || ('عمود ' + (x.refCol + 1))) + '"' + (x.sample ? ' <span class="muted">(زي ' + esc(x.sample) + ')</span>' : '');
    return 'اكتب';
  }

  // one step line; path picks the list inside inqTeach (the main steps, or a status's own)
  function stepItem(x, i, list, getList) {
    const [cls, name] = KIND[x.kind] || ['click', x.kind];
    const li = document.createElement('li');
    li.innerHTML = `<span class="pill ${cls}">${name}</span> ${stepLine(x)}`
      + ' <button class="mv up" title="لفوق">▲</button><button class="mv down" title="لتحت">▼</button><button class="x" title="امسح">✕</button>';
    if (x.kind === 'fill' || x.kind === 'nid') {
      const sel = document.createElement('select');
      sel.className = 'src';
      for (const [k, v] of Object.entries(SOURCES)) sel.append(new Option(v, k));
      sel.value = x.kind === 'nid' ? 'national_id' : (x.source || 'national_id');
      sel.onchange = () => editTeach((tt) => { const l = getList(tt); l[i] = { ...l[i], kind: 'fill', source: sel.value }; });
      li.append(sel);
    }
    li.querySelector('.up').onclick = () => editTeach((tt) => { const l = getList(tt); if (i > 0) [l[i - 1], l[i]] = [l[i], l[i - 1]]; });
    li.querySelector('.down').onclick = () => editTeach((tt) => { const l = getList(tt); if (i < l.length - 1) [l[i + 1], l[i]] = [l[i], l[i + 1]]; });
    li.querySelector('.x').onclick = () => editTeach((tt) => { getList(tt).splice(i, 1); });
    return li;
  }

  const statusOptions = (cur, withMore) => Object.entries({ ...statuses, ...(withMore ? { [MORE]: 'يعمل خطوات ويقرا السبب' } : {}) })
    .map(([k, v]) => `<option value="${k}"${k === cur ? ' selected' : ''}>${esc(v)}</option>`).join('');

  function statusCard(t, m, i) {
    const card = document.createElement('div');
    const recording = t.phase === 'more' && t.row === i;
    card.className = 'scard' + (recording ? ' rec' : '');
    card.innerHTML = '<div class="row"><input type="text" class="mt" placeholder="كلام الحالة عند أمان" style="flex:1">'
      + `<select class="ms">${statusOptions(m.status, true)}</select><button class="x" title="امسح">✕</button></div>`;
    card.querySelector('.mt').value = m.text || '';
    card.querySelector('.mt').onchange = (e) => editTeach((tt) => { tt.map[i].text = e.target.value; });
    card.querySelector('.ms').onchange = (e) => editTeach((tt) => {
      tt.map[i].status = e.target.value;
      if (e.target.value === MORE && !tt.map[i].more) tt.map[i].more = blankMore();
    });
    card.querySelector('.x').onclick = () => editTeach((tt) => {
      tt.map.splice(i, 1);
      if (tt.row === i) { tt.phase = null; tt.row = null; } else if (tt.row > i) tt.row--;
    });
    if (m.status !== MORE) return card;

    const mo = m.more || blankMore();
    const box = document.createElement('div');
    box.className = 'more';
    box.innerHTML = '<div class="row">'
      + `<button class="rec${recording ? ' ok' : ''}">${recording ? 'خلّصت الخطوات' : (mo.steps.length ? 'كمّل تسجيل خطواتها' : 'سجّل خطواتها')}</button>`
      + '<button class="why">دوس على السبب</button></div>'
      + (recording ? '<div class="now">اعمل في صفحة أمان اللي بيطلّع السبب. الضغطات بتتسجل هنا.</div>' : '')
      + '<ol class="ms-steps"></ol>'
      + `<div class="kv"><b>مكان السبب</b><span>${mo.reason ? '✓ ' + esc(mo.reasonText || 'اتعلّم') : '<span class="warn">لسه</span>'}</span></div>`
      + '<label class="f">لو السبب فيه الكلام ده، تبقى إيه عندنا</label><div class="rs"></div>'
      + '<div class="row" style="margin-top:4px"><button class="radd">+ سبب</button></div>';
    const ol = box.querySelector('.ms-steps');
    mo.steps.forEach((x, j) => ol.appendChild(stepItem(x, j, mo.steps, (tt) => tt.map[i].more.steps)));
    box.querySelector('.rec').onclick = () => editTeach((tt) => {
      tt.map[i].more = tt.map[i].more || blankMore();
      const on = tt.phase === 'more' && tt.row === i;
      tt.phase = on ? null : 'more';
      tt.row = on ? null : i;
      tt.pick = null;
    });
    box.querySelector('.why').onclick = () => editTeach((tt) => { tt.map[i].more = tt.map[i].more || blankMore(); tt.pick = 'reason'; tt.row = i; });
    box.querySelector('.radd').onclick = () => editTeach((tt) => { tt.map[i].more.map.push({ text: '', status: 'paused' }); });
    const rs = box.querySelector('.rs');
    (mo.map || []).forEach((r, j) => {
      const row = document.createElement('div');
      row.className = 'row';
      row.style.marginTop = '4px';
      row.innerHTML = `<input type="text" class="rt" placeholder="كلام في السبب" style="flex:1"><select class="rsel">${statusOptions(r.status, false)}</select><button class="x" title="امسح">✕</button>`;
      row.querySelector('.rt').value = r.text || '';
      row.querySelector('.rt').onchange = (e) => editTeach((tt) => { tt.map[i].more.map[j].text = e.target.value; });
      row.querySelector('.rsel').onchange = (e) => editTeach((tt) => { tt.map[i].more.map[j].status = e.target.value; });
      row.querySelector('.x').onclick = () => editTeach((tt) => { tt.map[i].more.map.splice(j, 1); });
      rs.appendChild(row);
    });
    card.appendChild(box);
    return card;
  }

  function renderTeach(t) {
    $('inqTeachBox').hidden = !me?.can_teach;
    $('inqSaved').textContent = recipe ? `الوصفة المحفوظة: نسخة ${recipe.version || 1}، ${recipe.steps?.length || 0} خطوة، ${recipe.map?.length || 0} حالة.` : 'لسه مافيش وصفة محفوظة.';
    const active = !!t?.active;
    $('inqTeachStart').hidden = active;
    $('inqTeachEdit').hidden = active || !recipe;
    $('inqSave').hidden = !active;
    $('inqTeachEnd').hidden = !active;
    $('inqPicks').hidden = !active;
    if (!active) return;
    const rowName = t.map?.[t.row]?.text || '';
    const pickText = { fill: 'دوس على الخانة في صفحة أمان…', user: 'دوس على اسم اليوزر في صفحة أمان…', copy: 'دوس على اسم اليوزر في صف من الجدول…', copy2: 'دلوقتي دوس على رقم الطلب في نفس الصف…', status: 'دوس على الحالة في صفحة أمان…', result: 'دوس على الحالة في صفحة أمان…', reason: 'دوس على السبب في صفحة أمان (الحالة "' + rowName + '")…' }[t.pick];
    $('inqPickMsg').hidden = !pickText;
    $('inqPickMsg').textContent = pickText || '';
    const ol = $('inqSteps');
    ol.innerHTML = '';
    (t.steps || []).forEach((x, i) => ol.appendChild(stepItem(x, i, t.steps, (tt) => tt.steps)));
    if (!(t.steps || []).length) ol.innerHTML = '<li class="muted">لسه مافيش خطوات. اعمل الاستعلام في صفحة أمان.</li>';
    $('inqInfo').innerHTML = `<b>يوزر أمان</b><span>${t.user ? '✓ ' + esc(t.userText || 'اتعلّم') : '<span class="warn">لسه</span>'}</span>`
      + `<b>مكان الحالة</b><span>${t.result ? '✓ ' + esc(t.lastText || 'اتعلّم') : '<span class="warn">لسه</span>'}</span>`;
    // typing in a status box: a re-render now would drop what is being typed
    const typing = document.activeElement && $('inqMap').contains(document.activeElement) && document.activeElement.tagName === 'INPUT';
    if (typing) return;
    const box = $('inqMap');
    box.innerHTML = '';
    (t.map || []).forEach((m, i) => box.appendChild(statusCard(t, m, i)));
    if (!(t.map || []).length) box.innerHTML = '<div class="muted">لسه مافيش حالات. دوس "دوس على حالة" وبعدين على الحالة في صفحة أمان.</div>';
  }

  async function render() {
    const { inqRun, inqTeach, inqBatch } = await store.get(['inqRun', 'inqTeach', 'inqBatch']);
    renderBatch(inqBatch || null, inqRun || null);
    renderRun(inqRun || null);
    renderTeach(inqTeach || null);
  }

  chrome.storage.onChanged.addListener((ch, area) => { if (area === 'local' && (ch.inqRun || ch.inqTeach || ch.inqBatch || ch.me)) render(); });
  // the tab running the follow-up was closed: the run stops
  chrome.tabs.onRemoved.addListener(async (id) => {
    const { inqRun } = await store.get('inqRun');
    if (inqRun?.tabId === id && ['running', 'waiting'].includes(inqRun.status)) await store.set({ inqRun: { ...inqRun, status: 'stopped' } });
  });

  (async () => {
    const { inqBatch } = await store.get('inqBatch');
    await loadRecipe();
    if (inqBatch?.items?.length) $('inqPullMsg').textContent = `اتسحب ${inqBatch.items.length} طلب تحت الاستعلام.`;
    render();
  })();
})();
