// Panel side of متابعة الاستعلام (owner 2026-11-20); the work itself runs in inquiry.js inside its own Aman tab.
// Uses panel.js's globals: $, store, me, activeTab.
(() => {
  let statuses = {};
  let recipe = null;
  const esc = (v) => String(v ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

  async function loadRecipe() {
    try {
      const r = await AMAN.api('GET', '/inquiry/recipe');
      statuses = r.statuses || {};
      recipe = r.recipe;
    } catch { /* not signed in yet */ }
  }

  // ---------- run ----------
  $('inqStart').onclick = async () => {
    await loadRecipe();
    if (!recipe) return ($('inqStatus').hidden = false, $('inqStatus').className = 'status error', $('inqStatus').textContent = 'متابعة الاستعلام لسه ماتعلّمتش. المسؤول يعلّمها الأول.');
    let q;
    try { q = await AMAN.api('GET', '/inquiry/queue'); } catch (e) {
      $('inqStatus').hidden = false; $('inqStatus').className = 'status error'; $('inqStatus').textContent = 'ماقدرش يسحب الطلبات: ' + e.message;
      return;
    }
    // its own tab, so the uploader keeps its tab (owner: both run together)
    const tab = await chrome.tabs.create({ url: recipe.url, active: false });
    await store.set({ inqRun: {
      tabId: tab.id, status: 'running', recipe, items: q.requests, i: 0, phase: 'fill', round: 1,
      repeat: Number($('inqRepeat').value) || 0, stats: {}, log: [], startedAt: Date.now(),
    } });
  };

  $('inqStop').onclick = async () => {
    const { inqRun } = await store.get('inqRun');
    if (inqRun) await store.set({ inqRun: { ...inqRun, status: 'stopped' } });
  };

  const label = (s) => (s ? (statuses[s] || s) : '');

  function renderRun(run) {
    const active = run && ['running', 'waiting'].includes(run.status);
    $('inqStart').hidden = !!active;
    $('inqStop').hidden = !active;
    const box = $('inqStatus');
    if (!run) { box.hidden = true; $('inqStats').innerHTML = ''; $('inqLog').hidden = true; $('inqBarWrap').hidden = true; return; }
    const total = (run.items || []).length;
    const cur = run.items?.[run.i];
    box.hidden = false;
    box.className = 'status ' + ({ running: 'running', waiting: 'done', done: 'done', error: 'error', stopped: 'review' }[run.status] || '');
    box.textContent = run.status === 'running' ? (cur ? `بيدوّر على ${cur.name || ''} (${run.i + 1} من ${total})` : 'بيخلّص…')
      : run.status === 'waiting' ? `خلصت اللفة. اللفة الجاية الساعة ${new Date(run.nextAt).toLocaleTimeString('ar-EG', { hour: 'numeric', minute: '2-digit' })}`
      : run.status === 'done' ? `خلصت: اتراجع ${total} طلب`
      : run.status === 'error' ? run.error
      : 'اتوقفت';
    if (run.error && run.status !== 'error') box.textContent += ' — ' + run.error;
    $('inqBarWrap').hidden = run.status !== 'running';
    $('inqBar').style.width = total ? Math.round((run.i / total) * 100) + '%' : '0';
    const s = run.stats || {};
    $('inqStats').innerHTML = `<b>اللفة</b><span>${run.round || 1}</span><b>اتراجع</b><span>${s.checked || 0}</span>`
      + `<b>حالته اتغيرت</b><span>${s.changed || 0}</span><b>حالة مش متعلّمة</b><span>${s.unknown || 0}</span>`
      + (s.errors ? `<b>مشاكل</b><span>${s.errors}</span>` : '');
    const log = run.log || [];
    $('inqLog').hidden = !log.length;
    $('inqLog').querySelector('tbody').innerHTML = log.slice(0, 60).map((r) => `<tr><td>${esc(r.name)} <span class="muted">#${r.id}</span></td>`
      + `<td>${esc(r.text)}</td><td>${r.error ? '<span class="warn">' + esc(r.error) + '</span>'
        : r.skipped ? esc(r.skipped) : r.changed ? '<b>بقى ' + esc(label(r.status)) + '</b>' : r.status ? 'لسه ' + esc(label(r.status)) : '<span class="warn">حالة مش متعلّمة</span>'}</td></tr>`).join('');
  }

  // ---------- teach ----------
  const describe = (t) => (t ? '✓ ' + (t.label || t.text || t.placeholder || t.name || t.id || t.tag) : '');

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

  const readMap = () => [...$('inqMap').children].map((d) => ({ text: d.querySelector('.mt').value.trim(), status: d.querySelector('.ms').value })).filter((m) => m.text);

  $('inqTeachStart').onclick = async () => {
    await loadRecipe();
    const tab = await activeTab();
    if (!tab) return;
    const base = recipe || {};
    // with a saved recipe its steps stay; "سجّل خطوات تاني" starts them over
    await store.set({ inqTeach: { active: true, tabId: tab.id, pick: null, recording: !base.steps?.length, url: base.url || tab.url, steps: base.steps || [], result: base.result || null, lastText: '' } });
    $('inqMap').innerHTML = '';
    (base.map?.length ? base.map : [{ text: '', status: 'approved' }, { text: '', status: 'rejected' }]).forEach(mapRow);
  };

  $('inqRecord').onclick = async () => {
    const tab = await activeTab();
    const { inqTeach } = await store.get('inqTeach');
    if (inqTeach) await store.set({ inqTeach: { ...inqTeach, recording: true, pick: null, steps: [], result: null, lastText: '', url: tab?.url || inqTeach.url } });
  };

  $('inqTeachEnd').onclick = () => store.set({ inqTeach: null });
  $('inqMapAdd').onclick = () => mapRow();

  document.querySelectorAll('[data-inqpick]').forEach((b) => b.onclick = async () => {
    const { inqTeach } = await store.get('inqTeach');
    if (inqTeach) await store.set({ inqTeach: { ...inqTeach, pick: b.dataset.inqpick } });
  });

  $('inqSave').onclick = async () => {
    const { inqTeach: t } = await store.get('inqTeach');
    const steps = t?.steps || [];
    if (!steps.some((x) => x.kind === 'nid')) return ($('inqTeachMsg').textContent = 'علّم خانة الرقم القومي الأول.');
    if (!t.result) return ($('inqTeachMsg').textContent = 'علّم مكان الحالة الأول.');
    const map = readMap();
    if (!map.length) return ($('inqTeachMsg').textContent = 'اكتب حالة واحدة على الأقل.');
    try {
      const body = { url: t.url, steps, result: t.result, map };
      const r = await AMAN.api('PUT', '/inquiry/recipe', body);
      $('inqTeachMsg').textContent = 'اتحفظت (نسخة ' + r.version + ').';
      recipe = { ...body, version: r.version };
    } catch (e) {
      $('inqTeachMsg').textContent = 'ماتحفظتش: ' + e.message;
    }
  };

  const stepText = (x) => x.kind === 'select' ? 'اختار "' + (x.text || x.value) + '"'
    : x.kind === 'nid' ? 'اكتب الرقم القومي' : 'دوس "' + (x.target?.text || x.target?.label || x.target?.id || x.target?.tag) + '"';

  async function dropStep(i) {
    const { inqTeach } = await store.get('inqTeach');
    if (!inqTeach) return;
    const steps = [...(inqTeach.steps || [])];
    steps.splice(i, 1);
    await store.set({ inqTeach: { ...inqTeach, steps } });
  }

  function renderTeach(t) {
    $('inqTeachBox').hidden = !me?.can_teach;
    const on = !!t?.active;
    $('inqTeachStart').hidden = on;
    $('inqTeachEnd').hidden = !on;
    $('inqPicks').hidden = !on;
    if (!on) return;
    $('inqPickMsg').textContent = t.pick === 'nid' ? 'دوس على خانة الرقم القومي في صفحة أمان…'
      : t.pick === 'result' ? 'دوس على الحالة في صفحة أمان…'
      : t.recording ? 'بيسجّل كل اختيار وضغطة في صفحة أمان.' : '';
    const ol = $('inqSteps');
    ol.innerHTML = '';
    (t.steps || []).forEach((x, i) => {
      const li = document.createElement('li');
      li.innerHTML = esc(stepText(x)) + ' <button class="x" title="امسح">✕</button>';
      li.querySelector('.x').onclick = () => dropStep(i);
      ol.appendChild(li);
    });
    $('inqResult').textContent = t.result ? 'مكان الحالة اتعلّم' + (t.lastText ? ': ' + t.lastText : '') : 'مكان الحالة لسه ماتعلّمش';
  }

  async function render() {
    const { inqRun, inqTeach } = await store.get(['inqRun', 'inqTeach']);
    renderRun(inqRun || null);
    renderTeach(inqTeach || null);
  }

  chrome.storage.onChanged.addListener((ch, area) => { if (area === 'local' && (ch.inqRun || ch.inqTeach || ch.me)) render(); });
  // the tab running the follow-up was closed: the run stops
  chrome.tabs.onRemoved.addListener(async (id) => {
    const { inqRun } = await store.get('inqRun');
    if (inqRun?.tabId === id && ['running', 'waiting'].includes(inqRun.status)) await store.set({ inqRun: { ...inqRun, status: 'stopped' } });
  });

  (async () => {
    const { inqTeach } = await store.get('inqTeach');
    await loadRecipe();
    if (inqTeach?.active) (recipe?.map?.length ? recipe.map : [{ text: '', status: 'approved' }]).forEach(mapRow);
    render();
  })();
})();
