// Panel side of متابعة الاستعلام (owner 2026-11-20); the work itself runs in inquiry.js inside its own Aman tab.
// - tab "متابعة الاستعلام": pull the requests under inquiry (oldest first), then start;
// - tab "تعليم", type "متابعة الاستعلام": its own recipe, recorded like the upload recipes.
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
      repeat: Number($('inqRepeat').value) || 0, stats: {}, log: [], startedAt: Date.now(),
    } });
  };

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
      + `<td>${esc(r.text)}</td><td>${r.error ? '<span class="warn">' + esc(r.error) + '</span>'
        : r.skipped ? esc(r.skipped) : r.changed ? '<b>بقى ' + esc(label(r.status)) + '</b>' : r.status ? 'لسه ' + esc(label(r.status)) : '<span class="warn">مااتغيرش</span>'}</td></tr>`).join('');
  }

  // ---------- teach (tab "تعليم", type "متابعة الاستعلام") ----------
  const inqMode = () => $('teachType').value === '_inquiry';

  function mapRow(m = { text: '', status: 'pending' }) {
    const div = document.createElement('div');
    div.className = 'row';
    div.style.marginTop = '4px';
    div.innerHTML = `<input type="text" class="mt" placeholder="كلام في حالة أمان" value="${esc(m.text)}" style="flex:1">`
      + `<select class="ms">${Object.entries(statuses).map(([k, v]) => `<option value="${k}"${k === m.status ? ' selected' : ''}>${esc(v)}</option>`).join('')}</select>`
      + '<button class="x" title="امسح">✕</button>';
    div.querySelector('.x').onclick = () => div.remove();
    $('inqMap').appendChild(div);
  }
  $('inqMapAdd').onclick = () => mapRow();
  const readMap = () => [...$('inqMap').children].map((d) => ({ text: d.querySelector('.mt').value.trim(), status: d.querySelector('.ms').value })).filter((m) => m.text);
  const fillMap = (map) => { $('inqMap').innerHTML = ''; (map?.length ? map : [{ text: '', status: 'approved' }, { text: '', status: 'rejected' }]).forEach(mapRow); };

  async function startTeach(base) {
    await loadRecipe();
    const tab = await activeTab();
    if (!tab) return;
    base = base || {};
    await store.set({ inqTeach: { active: true, tabId: tab.id, pick: null, url: base.url || tab.url, steps: base.steps || [], user: base.user || null, result: base.result || null, lastText: '', userText: '' } });
    fillMap(base.map);
  }

  $('inqTeachStart').onclick = async () => {
    await loadRecipe();
    if (recipe && !confirm('فيه وصفة محفوظة. التعليم الجديد هيستبدلها لما تحفظ. تكمل؟')) return;
    startTeach({ map: recipe?.map });
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
    const t = { ...inqTeach, steps: [...(inqTeach.steps || [])] };
    fn(t);
    await store.set({ inqTeach: t });
  }

  $('inqSave').onclick = async () => {
    const { inqTeach: t } = await store.get('inqTeach');
    const steps = t?.steps || [];
    if (!steps.some((x) => x.kind === 'fill' || x.kind === 'nid')) return ($('inqTeachMsg').textContent = 'علّم خانة الرقم القومي الأول.');
    if (steps.some((x) => x.kind === 'copy') && !t.user) return ($('inqTeachMsg').textContent = 'علّم اسم يوزر أمان عشان النسخ من الجدول.');
    if (!t.result) return ($('inqTeachMsg').textContent = 'علّم مكان الحالة الأول.');
    const map = readMap();
    if (!map.length) return ($('inqTeachMsg').textContent = 'اكتب حالة واحدة على الأقل.');
    try {
      const body = { url: t.url, steps, result: t.result, user: t.user || null, map };
      const r = await AMAN.api('PUT', '/inquiry/recipe', body);
      recipe = { ...body, version: r.version };
      await store.set({ inqTeach: null });
      $('inqSaved').textContent = 'اتحفظت الوصفة (نسخة ' + r.version + ').';
    } catch (e) {
      $('inqTeachMsg').textContent = 'ماتحفظتش: ' + e.message;
    }
  };

  const KIND = { select: ['select', 'اختيار'], fill: ['fill', 'خانة'], nid: ['fill', 'خانة'], click: ['click', 'ضغطة'], copy: ['copy', 'نسخ'] };
  function stepLine(x) {
    if (x.kind === 'select') return 'اختار "' + esc(x.text || x.value) + '"';
    if (x.kind === 'click') return 'دوس "' + esc(x.target?.text || x.target?.label || x.target?.id || x.target?.tag) + '"';
    if (x.kind === 'copy') return 'من الجدول: الصف اللي في عمود "' + esc(x.userHeader || ('رقم ' + (x.userCol + 1))) + '" يوزرنا، انسخ "' + esc(x.refHeader || ('عمود ' + (x.refCol + 1))) + '"' + (x.sample ? ' <span class="muted">(زي ' + esc(x.sample) + ')</span>' : '');
    return 'اكتب';
  }

  function renderTeach(t) {
    const pane = document.querySelector('[data-pane="teach"]');
    const on = inqMode();
    pane.classList.toggle('inq-mode', on);
    $('inqTeachBox').hidden = !on || !me?.can_teach;
    $('inqSaved').textContent = recipe ? `الوصفة المحفوظة: نسخة ${recipe.version || 1}، ${recipe.steps?.length || 0} خطوة${recipe.user ? '، وبتنسخ رقم الطلب من صف اليوزر' : ''}.` : 'لسه مافيش وصفة محفوظة.';
    const active = !!t?.active;
    $('inqTeachStart').hidden = active;
    $('inqTeachEdit').hidden = active || !recipe;
    $('inqSave').hidden = !active;
    $('inqTeachEnd').hidden = !active;
    $('inqPicks').hidden = !active;
    if (!active) return;
    const pickText = { fill: 'دوس على الخانة في صفحة أمان…', user: 'دوس على اسم اليوزر في صفحة أمان…', copy: 'دوس على اسم اليوزر في صف من الجدول…', copy2: 'دلوقتي دوس على رقم الطلب في نفس الصف…', result: 'دوس على الحالة في صفحة أمان…' }[t.pick];
    $('inqPickMsg').hidden = !pickText;
    $('inqPickMsg').textContent = pickText || '';
    const ol = $('inqSteps');
    ol.innerHTML = '';
    (t.steps || []).forEach((x, i) => {
      const [cls, name] = KIND[x.kind] || ['click', x.kind];
      const li = document.createElement('li');
      li.innerHTML = `<span class="pill ${cls}">${name}</span> ${stepLine(x)}`
        + ' <button class="mv up" title="لفوق">▲</button><button class="mv down" title="لتحت">▼</button><button class="x" title="امسح">✕</button>';
      if (x.kind === 'fill' || x.kind === 'nid') {
        const sel = document.createElement('select');
        sel.className = 'src';
        for (const [k, v] of Object.entries(SOURCES)) sel.append(new Option(v, k));
        sel.value = x.kind === 'nid' ? 'national_id' : (x.source || 'national_id');
        sel.onchange = () => editTeach((tt) => { tt.steps[i] = { ...tt.steps[i], kind: 'fill', source: sel.value }; });
        li.append(sel);
      }
      li.querySelector('.up').onclick = () => editTeach((tt) => { if (i > 0) [tt.steps[i - 1], tt.steps[i]] = [tt.steps[i], tt.steps[i - 1]]; });
      li.querySelector('.down').onclick = () => editTeach((tt) => { if (i < tt.steps.length - 1) [tt.steps[i + 1], tt.steps[i]] = [tt.steps[i], tt.steps[i + 1]]; });
      li.querySelector('.x').onclick = () => editTeach((tt) => { tt.steps.splice(i, 1); });
      ol.appendChild(li);
    });
    if (!(t.steps || []).length) ol.innerHTML = '<li class="muted">لسه مافيش خطوات. اعمل الاستعلام في صفحة أمان.</li>';
    $('inqInfo').innerHTML = `<b>يوزر أمان</b><span>${t.user ? '✓ ' + esc(t.userText || 'اتعلّم') : '<span class="warn">لسه</span>'}</span>`
      + `<b>مكان الحالة</b><span>${t.result ? '✓ ' + esc(t.lastText || 'اتعلّم') : '<span class="warn">لسه</span>'}</span>`;
  }

  async function render() {
    const { inqRun, inqTeach, inqBatch } = await store.get(['inqRun', 'inqTeach', 'inqBatch']);
    renderBatch(inqBatch || null, inqRun || null);
    renderRun(inqRun || null);
    renderTeach(inqTeach || null);
  }

  $('teachType').addEventListener('change', render);
  chrome.storage.onChanged.addListener((ch, area) => { if (area === 'local' && (ch.inqRun || ch.inqTeach || ch.inqBatch || ch.me)) render(); });
  // the tab running the follow-up was closed: the run stops
  chrome.tabs.onRemoved.addListener(async (id) => {
    const { inqRun } = await store.get('inqRun');
    if (inqRun?.tabId === id && ['running', 'waiting'].includes(inqRun.status)) await store.set({ inqRun: { ...inqRun, status: 'stopped' } });
  });

  (async () => {
    const { inqTeach, inqBatch } = await store.get(['inqTeach', 'inqBatch']);
    await loadRecipe();
    if (inqTeach?.active) { $('teachType').value = '_inquiry'; fillMap(recipe?.map); }
    if (inqBatch?.items?.length) $('inqPullMsg').textContent = `اتسحب ${inqBatch.items.length} طلب تحت الاستعلام.`;
    render();
  })();
})();
