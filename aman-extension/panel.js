const { store } = AMAN;
const $ = (id) => document.getElementById(id);
let me = null;

// ---------- tabs ----------
document.querySelectorAll('nav button').forEach((b) => b.onclick = () => showTab(b.dataset.tab));
function showTab(name) {
  document.querySelectorAll('nav button').forEach((b) => b.classList.toggle('on', b.dataset.tab === name));
  document.querySelectorAll('[data-pane]').forEach((p) => p.hidden = p.dataset.pane !== name);
}

async function activeTab() {
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  return tab || null;
}

const setTypes = (m) => { AMAN.types = Object.entries(m?.types || {}).map(([key, label]) => ({ key, label })); };

// ---------- account ----------
async function refreshAccount() {
  try {
    me = await AMAN.api('GET', '/me');
    setTypes(me);
    const recipes = await AMAN.api('GET', '/recipes');
    await store.set({ me, recipes: Array.isArray(recipes) ? {} : recipes });
    return true;
  } catch (e) {
    return e.message;
  }
}

$('login').onclick = async () => {
  const server = $('server').value.trim().replace(/\/+$/, '');
  const email = $('email').value.trim();
  const password = $('password').value;
  if (!server || !email || !password) return ($('loginMsg').textContent = 'اكتب العنوان والإيميل والباسورد.');
  $('loginMsg').textContent = 'بيدخل…';
  await store.set({ settings: { server, email, token: '' } });
  try {
    const res = await AMAN.api('POST', '/login', { email, password, device: 'Chrome ' + (navigator.userAgentData?.platform || navigator.platform || '') });
    $('password').value = '';
    await store.set({ settings: { server, email, token: res.token }, me: res.me });
    me = res.me; setTypes(me);
    await refreshAccount();
    $('loginMsg').textContent = '';
    showTab('run');
  } catch (e) {
    $('loginMsg').textContent = 'ماقدرش يدخل: ' + e.message;
  }
};

$('logout').onclick = async () => {
  try { await AMAN.api('POST', '/logout'); } catch { /* signed out locally anyway */ }
  const { settings = {} } = await store.get('settings');
  await store.set({ settings: { ...settings, token: '' }, me: null, recipes: {} });
  me = null;
};

// ---------- teach ----------
function stepLine(s) {
  if (s.kind === 'page') return ['page', 'صفحة', s.title || s.page];
  if (s.kind === 'expect') return ['expect', 'رسالة حالة', 'لازم تبقى: ' + s.sample];
  if (s.kind === 'capture') return ['capture', 'رقم الطلب', 'بيتقرا من: ' + (s.sample || 'الصفحة')];
  if (s.kind === 'click') return [s.gate ? 'gate' : 'click', s.gate ? 'وقفة موافقة' : 'ضغطة', s.target.text || s.target.name || 'زرار'];
  const t = s.target;
  return ['fill', 'خانة', (t.label || t.placeholder || t.name || 'بدون اسم') + ' ← ' + AMAN.describeSource(s.source)];
}

/** Source picker for a fill step in the steps list (edit any step by hand, owner 2026-11-17). */
function sourceSelect(s, i) {
  const sel = document.createElement('select'); sel.className = 'src';
  const src = s.source || {};
  const cur = src.type === 'const' ? 'const' : src.type === 'ask' ? 'ask' : src.type + ':' + src.key;
  const group = (label, items) => { const g = document.createElement('optgroup'); g.label = label; for (const [v, l] of items) g.append(new Option(l, v)); sel.append(g); };
  if (src.type === 'file') {
    group('ملف', AMAN.FILE_FIELDS.map(([k, l]) => ['file:' + k, l]));
  } else {
    group('أخرى', [['ask', 'الموظف يكتبها'], ['const', 'ثابت' + (src.type === 'const' ? ': ' + src.value : '…')]]);
    for (const g of AMAN.FIELD_GROUPS) group(g.label, g.fields.map(([k, l]) => ['field:' + k, l]));
    group('من البطاقة (قراءة آلية)', AMAN.CARD_FIELDS.map(([k, l]) => ['card:' + k, l]));
  }
  if (![...sel.options].some((o) => o.value === cur)) sel.prepend(new Option(AMAN.describeSource(src), cur));
  sel.value = cur;
  sel.onchange = () => {
    const v = sel.value;
    if (!v) return;
    let next;
    if (v === 'ask') next = { type: 'ask' };
    else if (v === 'const') {
      const value = prompt('القيمة الثابتة:', src.type === 'const' ? src.value : '');
      if (value === null) { sel.value = cur; return; }
      next = { type: 'const', value };
    } else {
      const [type, key] = v.split(':');
      next = { type, key, ...(src.format ? { format: src.format } : {}), ...(src.smart !== undefined ? { smart: src.smart } : {}), ...(src.verify ? { verify: true } : {}) };
    }
    editTeach((t) => { t.steps[i].source = next; });
  };
  return sel;
}

function renderSteps(list, steps, insertAt) {
  list.innerHTML = '';
  steps.forEach((s, i) => {
    const [cls, kind, text] = stepLine(s);
    const li = document.createElement('li');
    li.innerHTML = `<span class="pill ${cls}">${kind}</span> `;
    li.append(document.createTextNode(text));
    if (s.kind === 'click') {
      const lab = document.createElement('label');
      const cb = document.createElement('input'); cb.type = 'checkbox'; cb.checked = !!s.gate;
      cb.onchange = () => editTeach((t) => { t.steps[i].gate = cb.checked; });
      lab.append(' ', cb, ' وقفة موافقة');
      li.append(lab);
    }
    const move = (d, label, title) => {
      const b = document.createElement('button'); b.className = 'mv'; b.textContent = label; b.title = title;
      b.disabled = i + d < 0 || i + d >= steps.length;
      b.onclick = () => editTeach((t) => { const [x] = t.steps.splice(i, 1); t.steps.splice(i + d, 0, x); });
      li.append(b);
    };
    const ins = document.createElement('button'); ins.className = 'ins'; ins.textContent = '＋';
    ins.title = 'الخطوات الجديدة تتزوّد بعد دي';
    ins.onclick = () => editTeach((t) => { t.insertAt = t.insertAt === i + 1 ? undefined : i + 1; });
    li.append(ins);
    if (insertAt === i + 1) li.classList.add('here');
    move(-1, '▲', 'طلّعها خطوة');
    move(1, '▼', 'نزّلها خطوة');
    const x = document.createElement('button'); x.className = 'x'; x.textContent = '✕'; x.title = 'شيل الخطوة';
    x.onclick = () => editTeach((t) => { t.steps.splice(i, 1); });
    li.append(x);
    if (s.kind === 'fill' && s.source?.type !== 'check') li.append(sourceSelect(s, i));
    if (s.kind === 'fill' && s.source?.type === 'check' && s.source.mode !== 'when') {
      const sel = document.createElement('select'); sel.className = 'src';
      sel.append(new Option('دايمًا متعلّم', 'always'), new Option('دايمًا مش متعلّم', 'never'));
      sel.value = s.source.mode;
      sel.onchange = () => editTeach((t) => { t.steps[i].source = { ...t.steps[i].source, mode: sel.value }; });
      li.append(sel);
    }
    list.append(li);
  });
}

async function editTeach(fn) {
  const { teach } = await store.get('teach');
  if (!teach) return;
  fn(teach);
  await store.set({ teach });
}

function fillTypes() {
  const sel = $('teachType');
  const v = sel.value;
  sel.innerHTML = '';
  for (const t of AMAN.types?.length ? AMAN.types : AMAN.REQUEST_TYPES) sel.append(new Option(t.label, t.key));
  if (v) sel.value = v;
}

$('teachStart').onclick = async () => {
  const tab = await activeTab();
  const type = $('teachType').value;
  const { recipes = {} } = await store.get('recipes');
  if (recipes[type] && !confirm('فيه وصفة لـ "' + AMAN.typeLabel(type) + '". التعليم الجديد هيستبدلها لما تحفظ. تكمل؟')) return;
  await store.set({ teach: { active: true, type, tabId: tab?.id, startUrl: tab?.url || '', steps: [], startedAt: new Date().toISOString() } });
};
// Open a saved recipe's steps to change them: delete, move, change a field's source, or add on the Aman page.
$('teachEdit').onclick = async () => {
  const tab = await activeTab();
  const type = $('teachType').value;
  const from = $('editFrom').value || type;
  const { recipes = {} } = await store.get('recipes');
  const r = recipes[from];
  if (!r?.steps?.length) return alert('مافيش وصفة محفوظة لـ "' + AMAN.typeLabel(from) + '".');
  if (from !== type && recipes[type] && !confirm('هتبدأ من وصفة "' + AMAN.typeLabel(from) + '" وتحفظها لـ "' + AMAN.typeLabel(type) + '"، والوصفة اللي محفوظة لـ "' + AMAN.typeLabel(type) + '" هتتستبدل. تكمل؟')) return;
  await store.set({ teach: { active: true, edit: true, type, tabId: tab?.id, startUrl: r.steps[0]?.url || tab?.url || '', steps: JSON.parse(JSON.stringify(r.steps)), startedAt: new Date().toISOString() } });
};
// A button known only by its text, e.g. the "تأكيد" of Aman's card review box.
$('addClick').onclick = async () => {
  const text = (prompt('اسم الزرار زي ما هو مكتوب في صفحة أمان (مثلا: تأكيد):') || '').trim();
  if (!text) return;
  const page = prompt('الصفحة (سيبها زي ما هي لو نفس صفحة الخطوة اللي قبلها):', '') ?? '';
  await editTeach((t) => {
    const at = Number.isInteger(t.insertAt) ? t.insertAt : t.steps.length;
    const prevPage = [...t.steps.slice(0, at)].reverse().find((x) => x.page)?.page || '';
    const pg = page.trim() || prevPage;
    t.steps.splice(at, 0, { kind: 'click', key: pg + '|text:' + text, page: pg, target: { tag: '*', type: '', id: '', name: '', label: '', aria: '', placeholder: '', css: '', text }, gate: false });
    if (Number.isInteger(t.insertAt)) t.insertAt++;
  });
};
$('teachCancel').onclick = async () => { if (confirm('تلغي التعليم من غير ما تحفظ؟')) await store.set({ teach: null }); };
$('pickExpect').onclick = () => editTeach((t) => { t.pick = 'expect'; });
$('pickGate').onclick = () => editTeach((t) => { t.pick = 'gate'; });
// Picked the wrong type? Change it while teaching; the recorded steps stay.
$('teachType').onchange = async () => {
  const { teach } = await store.get('teach');
  if (!teach?.active) return render(); // the edit button follows the picked type
  const type = $('teachType').value;
  const { recipes = {} } = await store.get('recipes');
  if (recipes[type] && !confirm('فيه وصفة محفوظة لـ "' + AMAN.typeLabel(type) + '". لما تحفظ هتستبدلها. تكمل؟')) { $('teachType').value = teach.type; return; }
  await editTeach((t) => { t.type = type; });
};
$('pickCapture').onclick = () => editTeach((t) => { t.pick = 'capture'; });

$('teachSave').onclick = async () => {
  const { teach, recipes = {} } = await store.get(['teach', 'recipes']);
  if (!teach) return;
  if (!teach.steps.some((s) => s.kind === 'fill')) return alert('مافيش ولا خانة متحددة.');
  if (!teach.steps.some((s) => s.gate)) return alert('لازم تعلّم زرار الحفظ: دوس "علّم زرار الحفظ (من غير ما يتداس)" وبعدين دوس على زرار الحفظ في صفحة أمان.');
  if (!confirm('هتتحفظ وصفة "' + AMAN.typeLabel(teach.type) + '". متأكد إن ده النوع الصح؟')) return;
  // The run opens the first page itself, so the recipe must start with one.
  if (teach.steps[0].kind !== 'page') {
    const page = teach.steps.find((s) => s.page)?.page || '';
    teach.steps.unshift({ kind: 'page', page, url: teach.startUrl || '', title: 'صفحة البداية' });
  }
  try {
    const res = await AMAN.api('PUT', '/recipes/' + teach.type, { steps: teach.steps });
    recipes[teach.type] = { type: teach.type, version: res.version, steps: teach.steps, savedAt: new Date().toISOString() };
    await store.set({ recipes, teach: null });
  } catch (e) {
    alert('ماتحفظتش على السيستم: ' + e.message);
  }
};

// ---------- batch: pull every new request, then upload them one by one ----------
const ACTIVE_RUN = ['starting', 'running', 'review', 'approved', 'blocked'];
const FINAL = ['done', 'stopped', 'resolved', 'error'];
const STATE_LABEL = { released: 'اتوقف', waiting: 'مستني', working: 'شغال عليه', uploaded: 'اترفع', rejected_before: 'مرفوض قبل كده', error: 'مشكلة', stopped: 'اتوقف', skipped: 'اتساب', held: 'اتوقف في موتوجيت', canceled: 'اتلغى في موتوجيت' };

async function getBatch() { return (await store.get('batch')).batch || null; }
async function editBatch(fn) {
  const batch = await getBatch();
  if (!batch) return null;
  fn(batch);
  await store.set({ batch });
  return batch;
}
const setItem = (id, patch) => editBatch((b) => { const it = b.items.find((x) => x.id === id); if (it) Object.assign(it, patch, { at: new Date().toISOString() }); });

$('pull').onclick = async () => {
  const [batch, { run }] = [await getBatch(), await store.get('run')];
  if (batch?.running || (run && ACTIVE_RUN.includes(run.status))) return ($('pullMsg').textContent = 'فيه رفع شغال، خلّصه أو وقّفه الأول.');
  $('pullMsg').textContent = 'بيسحب…';
  try {
    await refreshAccount();
    const { items } = await AMAN.api('GET', '/queue');
    const noRecipe = items.filter((i) => !i.has_recipe).length;
    await store.set({ run: null, batch: { pulledAt: new Date().toISOString(), running: false, errorsInRow: 0, items: items.map((i) => ({ ...i, state: 'waiting' })) } });
    $('pullMsg').textContent = items.length
      ? `تم سحب ${items.length} طلب جديد من موتوجيت.` + (noRecipe ? ` منهم ${noRecipe} مالهمش وصفة وهيتسابوا.` : '')
      : 'مافيش طلبات جديدة على موتوجيت دلوقتي.';
  } catch (e) {
    $('pullMsg').textContent = 'ماقدرش يسحب: ' + e.message;
  }
};

$('startBatch').onclick = async () => { await editBatch((b) => { b.running = true; b.errorsInRow = 0; b.finishedAt = null; }); processNext(); };
$('stopBatch').onclick = async () => {
  await editBatch((b) => { b.running = false; });
  const { run } = await store.get('run');
  if (run && ACTIVE_RUN.includes(run.status)) await store.set({ run: { ...run, status: 'stopped', stopReason: 'الموظف وقف الرفع' } });
};
$('sheet').onclick = async () => downloadSheet(await getBatch());
// Every request this employee finished today (all runs, from the server), not just this batch.
$('todayReport').onclick = async () => {
  try {
    const { rows } = await AMAN.api('GET', '/report');
    if (!rows.length) return ($('pullMsg').textContent = 'مارفعتش ولا طلب النهارده لسه.');
    downloadSheet({ items: rows.map((r) => ({ ...r, state: r.status, amanRef: r.aman_ref })) }, 'aman-today');
    $('pullMsg').textContent = `اتحمّل تقرير النهارده: ${rows.length} طلب، اترفع منهم ${rows.filter((r) => r.status === 'uploaded').length}.`;
  } catch (e) {
    $('pullMsg').textContent = 'ماقدرش يجيب التقرير: ' + e.message;
  }
};

function waitTabLoaded(tabId) {
  return new Promise((resolve) => {
    const done = () => { chrome.tabs.onUpdated.removeListener(l); clearTimeout(t); resolve(); };
    const l = (id, info) => { if (id === tabId && info.status === 'complete') done(); };
    const t = setTimeout(done, 30000);
    chrome.tabs.onUpdated.addListener(l);
  });
}

// Opens the recipe's first page and starts filling.
async function launch(base) {
  const tab = await activeTab();
  const start = base.recipe.steps.find((s) => s.kind === 'page');
  await store.set({ run: { ...base, tabId: tab?.id ?? base.tabId, status: 'starting', problem: false, beforeStart: false, blockMessage: '' } });
  if (start?.url && tab) {
    await chrome.tabs.update(tab.id, { url: start.url });
    await waitTabLoaded(tab.id);
  }
  const { run: cur } = await store.get('run');
  if (cur?.uploadId === base.uploadId && cur.status === 'starting') await store.set({ run: { ...cur, status: 'running' } });
}

let processing = false;
async function processNext() {
  if (processing) return;
  processing = true;
  try {
    for (;;) {
      const batch = await getBatch();
      if (!batch?.running) return;
      if (batch.items.some((i) => i.state === 'working')) return; // its run is still going
      const item = batch.items.find((i) => i.state === 'waiting');
      if (!item) return finishBatch();
      if (!item.has_recipe) { await setItem(item.id, { state: 'skipped', message: 'مافيش وصفة لنوع "' + item.type_label + '"' }); continue; }

      await setItem(item.id, { state: 'working' });
      let data;
      try {
        data = await AMAN.api('POST', '/requests/' + item.id + '/claim');
      } catch (e) {
        await setItem(item.id, { state: 'skipped', message: e.message });
        continue;
      }
      const tab = await activeTab();
      const { recipes = {} } = await store.get('recipes');
      const recipe = recipes[data.upload.type];
      const base = { batchItemId: item.id, uploadId: data.upload.id, requestId: data.request_id, type: data.upload.type, typeLabel: data.upload.type_label,
        order: data.order, card: data.card || {}, recipe, tabId: tab?.id, i: 0, log: [], dialogs: [], startedAt: new Date().toISOString() };
      if (!recipe) { await store.set({ run: { ...base, status: 'error', error: 'مافيش وصفة لنوع "' + data.upload.type_label + '"' } }); return; }
      await launch(base);
      return; // the run's end (onRunChange) calls processNext again
    }
  } finally {
    processing = false;
  }
}

async function finishBatch() {
  const batch = await editBatch((b) => { b.running = false; b.finishedAt = new Date().toISOString(); });
  if (batch?.items.length) downloadSheet(batch);
}

// A finished run, once recorded on the server, closes its batch item and moves on.
let handled = '';
async function onRunChange(run) {
  if (!run?.batchItemId || !FINAL.includes(run.status) || !(run.reported || run.reportError)) return;
  const key = run.uploadId + ':' + run.status;
  if (handled === key) return;
  handled = key;
  const batch = await getBatch();
  const item = batch?.items.find((i) => i.id === run.batchItemId);
  if (!item || item.state !== 'working') return;

  const state = run.status === 'done' ? 'uploaded' : run.status === 'resolved' ? run.resolution : run.status;
  const manual = (run.log || []).filter((r) => r.ok === null).map((r) => r.label.replace(/[:：\s]+$/, ''));
  const message = run.status === 'error' ? run.error : run.status === 'resolved' ? run.blockMessage : run.status === 'stopped' ? run.stopReason
    : [manual.length ? 'الموظف اختار بإيده: ' + manual.join('، ') : '', run.reportError ? 'ماتسجلش في السيستم: ' + run.reportError : ''].filter(Boolean).join(' — ');
  await editBatch((b) => {
    const it = b.items.find((i) => i.id === run.batchItemId);
    Object.assign(it, { state, amanRef: run.amanRef || '', message: message || '', at: new Date().toISOString() });
    b.errorsInRow = state === 'error' ? (b.errorsInRow || 0) + 1 : 0;
    if (b.errorsInRow >= 3) { b.running = false; b.pausedReason = 'وقف بعد 3 مشاكل ورا بعض، شوف الوصفة أو صفحة أمان.'; }
  });
  setTimeout(processNext, 1500);
}

// While a request is open (stopped for the employee, or waiting for approval) its claim is kept
// alive, so nobody else picks it up after claim_minutes.
setInterval(async () => {
  const { run } = await store.get('run');
  if (!run?.uploadId || FINAL.includes(run.status)) return;
  try { await AMAN.api('POST', '/uploads/' + run.uploadId + '/alive'); } catch { /* next time */ }
}, 4 * 60 * 1000);

// ---------- results sheet (CSV that Excel opens, Arabic kept) ----------
function downloadSheet(batch, prefix = 'aman-uploads') {
  if (!batch) return;
  const cols = ['رقم الطلب في موتوجيت', 'اسم العميل', 'الرقم القومي', 'نوع الطلب', 'النتيجة', 'رقم الطلب في أمان', 'ملاحظات', 'الوقت'];
  const cell = (v) => '"' + String(v ?? '').replace(/"/g, '""') + '"';
  const rows = batch.items.map((i) => [i.id, i.name, i.national_id ? '‎' + i.national_id : '', i.type_label, STATE_LABEL[i.state] || i.state, i.amanRef, i.message, i.at ? new Date(i.at).toLocaleString('ar-EG') : '']);
  const csv = '﻿' + [cols, ...rows].map((r) => r.map(cell).join(',')).join('\r\n');
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
  const d = new Date(); const p = (n) => String(n).padStart(2, '0');
  a.download = `${prefix}-${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}-${p(d.getHours())}${p(d.getMinutes())}.csv`;
  a.click();
}

// ---------- current request controls ----------
async function setRun(patch) {
  const { run } = await store.get('run');
  if (run) await store.set({ run: { ...run, ...patch } });
}
$('approve').onclick = async () => { const { run } = await store.get('run'); if (run?.status === 'review') setRun({ status: 'approved' }); };
$('reject').onclick = () => setRun({ status: 'stopped', stopReason: 'الموظف مش موافق على البيانات' });
$('asRejected').onclick = () => setRun({ status: 'resolved', resolution: 'rejected_before', blockMessage: 'الموظف سجّله: العميل عنده طلب مرفوض قبل كده عند أمان' });
$('asError').onclick = () => setRun({ status: 'resolved', resolution: 'error' });
// After a stop: the employee fixed it on Aman's page. A failed step is tried again; a message
// that did not match (expect) is passed.
$('goOn').onclick = async () => {
  const { run } = await store.get('run');
  if (run?.status !== 'blocked') return;
  if (run.beforeStart) return launch(run);
  const pass = !run.problem && run.recipe.steps[run.i]?.kind === 'expect';
  setRun({ status: 'running', dialogs: [], problem: false, problemRow: null, i: pass ? run.i + 1 : run.i });
};
// Saved at Aman but its number was not caught: the employee types it, the request counts as uploaded.
$('refSave').onclick = async () => {
  const ref = $('refInput').value.replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d)).replace(/\D/g, '');
  if (ref.length < 5) { $('refInput').focus(); return; }
  const { run } = await store.get('run');
  if (run?.status !== 'blocked') return;
  $('refInput').value = '';
  setRun({ status: 'done', amanRef: ref, amanMessage: 'الموظف كتب رقم طلب أمان: ' + ref, problem: false, noRef: false, finishedAt: new Date().toISOString() });
};
// Owner 2026-11-16: the page hung or skipped fields - start the request over from Aman's first page.
$('refill').onclick = async () => {
  const { run } = await store.get('run');
  if (!run || FINAL.includes(run.status)) return;
  closeDecide();
  await launch({ ...run, i: 0, log: [], dialogs: [], hunt: null, override: {}, problemRow: null, noRef: false, approvedAt: null, amanRef: '', amanMessage: '' });
};
// Owner 2026-11-16: the employee stops a request that cannot go on (paused in motobot with a reason),
// or cancels one that is no good (status, reason and Aman's reply go to motobot).
function closeDecide() { $('holdBox').hidden = true; $('cancelBox').hidden = true; }
$('hold').onclick = () => { $('cancelBox').hidden = true; $('holdBox').hidden = false; $('holdReason').focus(); };
$('cancel').onclick = () => { $('holdBox').hidden = true; $('cancelBox').hidden = false; $('cancelReason').focus(); };
$('holdClose').onclick = closeDecide;
$('cancelClose').onclick = closeDecide;
$('holdSave').onclick = async () => {
  const reason = $('holdReason').value.trim();
  if (!reason) { $('holdReason').focus(); return; }
  const { run } = await store.get('run');
  if (!run || FINAL.includes(run.status)) return;
  $('holdReason').value = ''; closeDecide();
  setRun({ status: 'resolved', resolution: 'held', blockMessage: reason, problem: false });
};
$('cancelSave').onclick = async () => {
  const reason = $('cancelReason').value.trim();
  if (!reason) { $('cancelReason').focus(); return; }
  const { run } = await store.get('run');
  if (!run || FINAL.includes(run.status)) return;
  const reply = $('cancelReply').value.trim();
  $('cancelReason').value = ''; $('cancelReply').value = ''; closeDecide();
  setRun({ status: 'resolved', resolution: 'canceled', blockMessage: reason, cancelStatus: $('cancelStatus').value, cancelReply: reply, problem: false });
};

// The employee did the failed step by hand: go on from the next one.
$('skipStep').onclick = async () => {
  const { run } = await store.get('run');
  if (run?.status !== 'blocked' || !run.problem) return;
  const row = { ...(run.problemRow || { label: 'خطوة ' + (run.i + 1), source: '', expected: '' }), actual: 'الموظف عملها بإيده', ok: null };
  setRun({ status: 'running', dialogs: [], problem: false, problemRow: null, i: run.i + 1, log: [...run.log, row] });
};

const STATUS = {
  starting: () => 'بيفتح صفحة أمان…',
  running: (r) => `بيملا… خطوة ${r.i + 1} من ${r.recipe.steps.length}`,
  review: (r) => `وقف عند "${r.gateText}". راجع الجدول وصفحة أمان، وبعدين وافق.`,
  approved: () => 'بيحفظ…',
  blocked: (r) => r.problem ? 'وقف: ' + r.blockMessage + (r.beforeStart ? '. راجعها، ولو تمام دوس كمّل.' : '. صلّحها في صفحة أمان وبعدين دوس كمّل.') : r.blockMessage,
  error: (r) => 'وقف: ' + r.error,
  done: (r) => 'اترفع' + (r.amanRef ? ' — رقم الطلب عند أمان: ' + r.amanRef : '') + '.',
  stopped: (r) => 'اتوقف: ' + (r.stopReason || ''),
  resolved: (r) => ({ rejected_before: 'اتسجل إنه مرفوض: ', held: 'الطلب بقى متوقف في موتوجيت: ', canceled: 'الطلب اتلغى في موتوجيت: ' }[r.resolution] || 'اتسجل كمشكلة: ') + r.blockMessage,
};

function renderRun(run) {
  $('current').hidden = !run;
  if (!run) return;
  const name = run.card?.full_name || run.order?.applicant_name || '';
  $('curTitle').textContent = `طلب #${run.requestId} — ${run.typeLabel || AMAN.typeLabel(run.type)}`;
  const info = $('curInfo'); info.innerHTML = '';
  for (const [k, v] of [['العميل', name], ['الرقم القومي', run.order?.applicant_national_id || run.card?.national_id], ['النوع', run.card?.gender], ['الاسم من', run.card?.name_source === 'card' ? 'البطاقة' : 'السيستم (البطاقة ماتقريتش)']]) {
    if (!v) continue;
    const b = document.createElement('b'); b.textContent = k; const s = document.createElement('span'); s.textContent = v; info.append(b, s);
  }
  const warn = $('curWarn'); warn.innerHTML = '';
  for (const e of run.card?.errors || []) { const d = document.createElement('div'); d.className = 'warn'; d.textContent = '⚠ ' + e; warn.append(d); }

  const st = $('runStatus');
  st.className = 'status ' + run.status;
  st.textContent = (STATUS[run.status] || (() => run.status))(run)
    + (FINAL.includes(run.status) && run.uploadId ? (run.reported ? ' (اتسجل في موتوجيت)' : run.reportError ? ' (ماتسجلش: ' + run.reportError + ')' : ' (بيسجل…)') : '');
  $('gateRow').hidden = run.status !== 'review';
  $('blockRow').hidden = run.status !== 'blocked';
  $('skipStep').hidden = !run.problem || !!run.beforeStart || !!run.noRef;
  $('refRow').hidden = !(run.status === 'blocked' && run.noRef);
  renderFiles(run);
  $('asRejected').hidden = !!run.problem;
  $('tools').hidden = FINAL.includes(run.status) || !run.uploadId;
  if (FINAL.includes(run.status)) closeDecide();

  const rows = [...run.log, ...(run.problemRow ? [run.problemRow] : [])]; // the step it stopped on, in red
  $('review').hidden = !rows.length;
  const tb = $('review').querySelector('tbody');
  tb.innerHTML = '';
  for (const r of rows) {
    const tr = document.createElement('tr');
    for (const v of [r.label, r.source, r.expected, r.actual, r.ok === null ? '✍' : r.ok ? '✔' : '✘']) {
      const td = document.createElement('td'); td.textContent = short(v); td.title = v ?? ''; tr.append(td);
    }
    if (r.ok === false) tr.style.color = 'var(--bad)';
    if (r.ok === null) tr.style.color = 'var(--brand-dark)';
    tb.append(tr);
  }
}

// A long file name ("v2-ea5651...jpg") shows as "صورة …a48f.jpg" so the table keeps its width.
function short(v) {
  const t = String(v ?? '');
  return t.replace(/\S{26,}/g, (w) => /\.(jpe?g|png|webp|gif|pdf)$/i.test(w) ? 'ملف …' + w.slice(-10) : w.slice(0, 12) + '…' + w.slice(-8));
}

// Tap a photo: it opens over the panel, full width; tap it again for double size.
const CARD_KEYS = ['applicant_id_image', 'applicant_id_back_image', 'guarantor_id_image', 'guarantor_id_back_image'];
function zoom(url) {
  const img = $('zoom').querySelector('img');
  img.src = url; img.classList.remove('x2');
  $('zoom').hidden = false; $('zoom').scrollTo(0, 0);
}
$('zoom').onclick = (e) => {
  const img = $('zoom').querySelector('img');
  if (e.target === img) img.classList.toggle('x2'); else $('zoom').hidden = true;
};

// ---------- the photos this request uploads: seen and downloadable from the panel ----------
let filesFor = '';
const fileUrls = []; // blob URLs to free when the request changes
async function renderFiles(run) {
  const key = run.uploadId + ':' + (run.recipe?.version || '');
  if (filesFor === key) return;
  filesFor = key;
  fileUrls.splice(0).forEach((u) => URL.revokeObjectURL(u));
  const box = $('thumbs'); box.innerHTML = '';
  const seen = new Set(), items = [];
  for (const st of run.recipe?.steps || []) {
    const src = st.source;
    if (st.kind !== 'fill' || src?.type !== 'file') continue;
    const urls = run.order?.[src.key];
    for (const url of (Array.isArray(urls) ? urls : urls ? [urls] : [])) {
      if (seen.has(url)) continue;
      seen.add(url);
      items.push({ url, big: CARD_KEYS.includes(src.key), label: (st.target?.label || AMAN.fieldLabel(src.key)).replace(/[:：\s]+$/, '') });
    }
  }
  $('files').hidden = !items.length;
  items.sort((a, b) => b.big - a.big);
  for (const it of items) {
    const card = document.createElement('div'); card.className = 'thumb' + (it.big ? ' big' : '');
    const pic = document.createElement('a'); pic.className = 'pic'; pic.href = '#';
    const cap = document.createElement('div'); cap.className = 'cap'; cap.textContent = it.label + ' …';
    const dl = document.createElement('a'); dl.className = 'dl'; dl.textContent = 'تحميل'; dl.hidden = true;
    card.append(pic, cap, dl); box.append(card);
    chrome.runtime.sendMessage({ type: 'fetchFile', url: it.url }, (r) => {
      if (filesFor !== key) return; // another request by now
      if (!r?.ok) { card.classList.add('err'); cap.textContent = it.label + ' — مش قادر أجيبها'; return; }
      const bin = atob(r.b64); const buf = new Uint8Array(bin.length);
      for (let i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
      const u = URL.createObjectURL(new Blob([buf], { type: r.mime })); fileUrls.push(u);
      const ext = (r.mime.split('/')[1] || 'jpg').replace('jpeg', 'jpg');
      pic.onclick = (e) => { e.preventDefault(); zoom(u); };
      if (it.big) { const img = document.createElement('img'); img.src = u; img.alt = it.label; pic.append(img); } else pic.style.backgroundImage = `url("${u}")`;
      cap.textContent = it.label;
      dl.href = u; dl.download = `طلب-${run.requestId}-${it.label.replace(/[\\/:*?"<>|]/g, '')}.${ext}`; dl.hidden = false;
    });
  }
}

function renderBatch(batch, run) {
  const items = batch?.items || [];
  const count = (s) => items.filter((i) => i.state === s).length;
  const left = count('waiting') + count('working');
  const handledCount = items.length - left;

  $('startBatch').hidden = !items.length || batch.running || !count('waiting');
  $('startBatch').textContent = handledCount ? 'كمّل الرفع' : 'ابدأ الرفع';
  $('stopBatch').hidden = !batch?.running;
  $('sheet').hidden = !handledCount;
  $('listBox').hidden = !items.length;
  $('progress').hidden = !items.length || !handledCount && !batch?.running;

  const working = items.find((i) => i.state === 'working');
  const now = $('now');
  now.hidden = !(batch?.running || working || batch?.finishedAt || batch?.pausedReason);
  if (working) now.textContent = `جاري العمل على طلب باسم ${working.name || '—'} رقم #${working.id} على سيستم موتوجيت`;
  else if (batch?.running) now.textContent = 'بيجهّز الطلب اللي بعده…';
  else if (batch?.pausedReason) now.textContent = batch.pausedReason;
  else if (batch?.finishedAt) now.textContent = `خلصت: تم تنفيذ ${count('uploaded')} طلب من ${items.length}. الشيت اتحمّل.`;

  $('progressText').textContent = `تم تنفيذ ${count('uploaded')} من ${items.length}`
    + ` — مرفوض قبل كده ${count('rejected_before')}، مشاكل ${count('error') + count('stopped')}، اتساب ${count('skipped')}، فاضل ${left}`;
  $('barFill').style.width = (items.length ? Math.round((handledCount / items.length) * 100) : 0) + '%';

  const list = $('list'); list.innerHTML = '';
  for (const i of items) {
    const tr = document.createElement('tr');
    const cells = [i.id, i.name, i.type_label, null, i.amanRef || ''];
    cells.forEach((v, n) => {
      const td = document.createElement('td');
      if (n === 3) {
        const s = document.createElement('span'); s.className = 'st ' + i.state; s.textContent = STATE_LABEL[i.state] || i.state; s.title = i.message || ''; td.append(s);
        if (i.message && i.state !== 'uploaded') { const m = document.createElement('div'); m.className = 'why'; m.textContent = i.message; td.append(m); }
      }
      else td.textContent = v ?? '';
      tr.append(td);
    });
    list.append(tr);
  }
}

// ---------- render ----------
async function render() {
  const { teach, recipes = {}, run, settings = {}, me: cachedMe, batch } = await store.get(['teach', 'recipes', 'run', 'settings', 'me', 'batch']);
  if (cachedMe !== undefined) me = cachedMe;
  if (me && !AMAN.types?.length) setTypes(me);
  const signedIn = !!(settings.token && me);

  $('who').textContent = signedIn ? me.name : 'مش داخل';
  $('loginBox').hidden = signedIn; $('accountBox').hidden = !signedIn;
  if (signedIn) $('accountInfo').textContent = `داخل باسم ${me.name} (${settings.email || ''}) على ${settings.server}`;
  if (document.activeElement !== $('server')) $('server').value = settings.server || '';
  if (document.activeElement !== $('email')) $('email').value = settings.email || '';
  $('pull').disabled = !signedIn;
  fillTypes();

  const on = !!teach?.active;
  $('teachStart').hidden = on; $('teachEdit').hidden = on; $('editFromRow').hidden = on; $('addClick').hidden = !on; $('teachSave').hidden = !on; $('teachCancel').hidden = !on; $('pickRow').hidden = !on;
  $('teachType').disabled = false;
  if (on && document.activeElement !== $('teachType')) $('teachType').value = teach.type;
  $('teachStart').disabled = !signedIn || !me.can_teach;
  const ef = $('editFrom'), efv = ef.value;
  ef.innerHTML = '';
  for (const k of Object.keys(recipes || {})) ef.append(new Option(AMAN.typeLabel(k) + ' (نسخة ' + recipes[k].version + ')', k));
  ef.value = recipes?.[efv] ? efv : (recipes?.[$('teachType').value] ? $('teachType').value : (ef.options[0]?.value || ''));
  $('teachEdit').disabled = !signedIn || !me.can_teach || !ef.value;
  $('teachHint').textContent = signedIn && !me.can_teach ? 'التعليم للمسؤول بس.'
    : on && teach.edit && !teach.pick ? 'بتعدّل الوصفة لـ "' + AMAN.typeLabel(teach.type) + '" (تغيّر النوع من فوق تتحفظ باسمه). شيل أي خطوة بـ ✕، حرّكها بالأسهم، غيّر الخانة بتتملي من إيه من القايمة تحتها. دوس ＋ جنب خطوة عشان الجديد يتزوّد بعدها: تدوس في صفحة أمان على زرار أو الدايرة في أي خانة، أو "زوّد زرار بالاسم". وبعدين دوس احفظ الوصفة.'
    : on ? (teach.pick ? 'دوس على الكلام في صفحة أمان…' : 'ارفع الطلب عادي. دوس على الدايرة اللي جوه كل خانة واختار هي تبع إيه. ارفع البطاقة ودوس تأكيد زي ما بتعمل. لما رسالة الحالة تظهر (عميل جديد) دوس "علّم رسالة الحالة". وبعد الحفظ دوس "علّم رقم الطلب".')
      : 'اختار نوع الطلب، وافتح صفحة طلب جديد على أمان، وبعدين دوس ابدأ.';
  renderSteps($('teachSteps'), on ? teach.steps : [], on ? teach.insertAt : undefined);

  const box = $('recipes');
  const keys = Object.keys(recipes || {});
  box.innerHTML = keys.length ? '' : 'لسه مافيش.';
  for (const k of keys) {
    const r = recipes[k];
    const d = document.createElement('div');
    d.style.marginBottom = '4px';
    d.textContent = `${AMAN.typeLabel(k)} — نسخة ${r.version}، ${r.steps.filter((s) => s.kind === 'fill').length} خانة`;
    box.append(d);
  }

  renderRun(run);
  renderBatch(batch, run);
  onRunChange(run);
}

chrome.storage.onChanged.addListener((_c, area) => { if (area === 'local') render(); });
(async () => {
  await render();
  const { settings = {}, batch } = await store.get(['settings', 'batch']);
  if (settings.token) {
    const ok = await refreshAccount();
    if (ok !== true) $('loginMsg').textContent = ok;
    render();
    if (batch?.running) processNext();
  } else {
    showTab('settings');
  }
})();
