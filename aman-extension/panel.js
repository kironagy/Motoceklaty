const { store } = AMAN;
const $ = (id) => document.getElementById(id);

const SAMPLE_ORDER = {
  applicant_name: 'محمد أحمد علي حسن',
  applicant_national_id: '29001011234567',
  applicant_phone: '01012345678',
  applicant_birthdate: '1990-01-01',
  applicant_governorate: 'القاهرة',
  applicant_area: 'مدينة نصر',
  applicant_address: '12 شارع مصطفى النحاس',
  machine_installment_price: '45000',
  deposit: '5000',
  months: '12',
  free_work_name: 'سواق توك توك',
  applicant_id_image: 'https://example.com/id-front.jpg',
  applicant_id_back_image: 'https://example.com/id-back.jpg',
};

for (const sel of [$('teachType'), $('runType')]) {
  for (const t of AMAN.REQUEST_TYPES) sel.append(new Option(t.label, t.key));
}
$('order').value = JSON.stringify(SAMPLE_ORDER, null, 2);

async function activeTabId() {
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  return tab?.id ?? null;
}

function stepLine(s) {
  if (s.kind === 'page') return ['page', 'صفحة', s.title || s.page];
  if (s.kind === 'click') return [s.gate ? 'gate' : 'click', s.gate ? 'وقفة موافقة' : 'ضغطة', s.target.text || s.target.label || s.target.name || 'زرار'];
  const t = s.target;
  return ['fill', 'خانة', (t.label || t.placeholder || t.name || 'بدون اسم') + ' ← ' + AMAN.describeSource(s.source)];
}

function renderSteps(list, steps, editable) {
  list.innerHTML = '';
  steps.forEach((s, i) => {
    const [cls, kind, text] = stepLine(s);
    const li = document.createElement('li');
    li.innerHTML = `<span class="pill ${cls}">${kind}</span> `;
    li.append(document.createTextNode(text));
    if (editable && s.kind === 'click') {
      const lab = document.createElement('label');
      const cb = document.createElement('input'); cb.type = 'checkbox'; cb.checked = !!s.gate;
      cb.onchange = () => editTeach((t) => { t.steps[i].gate = cb.checked; });
      lab.append(' ', cb, ' وقفة موافقة');
      li.append(lab);
    }
    if (editable) {
      const x = document.createElement('button'); x.className = 'x'; x.textContent = '✕'; x.title = 'شيل الخطوة';
      x.onclick = () => editTeach((t) => { t.steps.splice(i, 1); });
      li.append(x);
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

async function render() {
  const { teach, recipes = {}, run } = await store.get(['teach', 'recipes', 'run']);

  const on = !!teach?.active;
  $('teachStart').hidden = on; $('teachSave').hidden = !on; $('teachCancel').hidden = !on;
  $('teachType').disabled = on;
  if (on) $('teachType').value = teach.type;
  $('teachHint').textContent = on
    ? 'ارفع الطلب عادي. جنب كل خانة فيه قايمة، حدد منها الخانة تبع إيه. زرار الحفظ بيتعلّم عليه "وقفة موافقة" لوحده.'
    : 'اختار نوع الطلب، وافتح صفحة أمان، وبعدين دوس ابدأ.';
  renderSteps($('teachSteps'), on ? teach.steps : [], true);

  const box = $('recipes');
  const keys = Object.keys(recipes);
  box.innerHTML = keys.length ? '' : 'لسه مافيش.';
  for (const k of keys) {
    const r = recipes[k];
    const div = document.createElement('div');
    div.className = 'row';
    div.style.marginBottom = '6px';
    const fills = r.steps.filter((s) => s.kind === 'fill').length;
    const gates = r.steps.filter((s) => s.gate).length;
    div.append(document.createTextNode(`${AMAN.typeLabel(k)} — نسخة ${r.version}، ${fills} خانة، ${gates} وقفة موافقة`));
    const exp = document.createElement('button'); exp.textContent = 'تصدير';
    exp.onclick = () => {
      const a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([JSON.stringify(r, null, 2)], { type: 'application/json' }));
      a.download = `aman-recipe-${k}-v${r.version}.json`; a.click();
    };
    const del = document.createElement('button'); del.textContent = 'حذف'; del.className = 'bad';
    del.onclick = async () => {
      if (!confirm('تحذف وصفة ' + AMAN.typeLabel(k) + '؟')) return;
      const { recipes: all = {} } = await store.get('recipes'); delete all[k]; await store.set({ recipes: all });
    };
    div.append(exp, del);
    box.append(div);
  }

  const st = $('runStatus');
  st.innerHTML = '';
  $('review').hidden = !run; $('gateRow').hidden = run?.status !== 'review';
  if (run) {
    const msg = {
      running: `بيملا… خطوة ${run.i + 1} من ${run.recipe.steps.length}`,
      review: `وقف عند "${run.gateText}". راجع الجدول وصفحة أمان قبل ما توافق.`,
      error: 'وقف: ' + run.error,
      done: 'خلص.',
      stopped: 'اتوقف.',
      approved: 'بيحفظ…',
    }[run.status] || run.status;
    st.className = 'status ' + run.status;
    st.textContent = msg;
    const tb = $('review').querySelector('tbody');
    tb.innerHTML = '';
    for (const r of run.log) {
      const tr = document.createElement('tr');
      for (const v of [r.label, r.source, r.expected, r.actual, r.ok === null ? '✍' : r.ok ? '✔' : '✘']) {
        const td = document.createElement('td'); td.textContent = v ?? ''; tr.append(td);
      }
      if (r.ok === false) tr.style.color = 'var(--bad)';
      tb.append(tr);
    }
  }
}

$('teachStart').onclick = async () => {
  const tabId = await activeTabId();
  const type = $('teachType').value;
  const { recipes = {} } = await store.get('recipes');
  if (recipes[type] && !confirm('فيه وصفة محفوظة لـ ' + AMAN.typeLabel(type) + '. التعليم الجديد هيستبدلها لما تحفظ. تكمل؟')) return;
  await store.set({ teach: { active: true, type, tabId, steps: [], startedAt: new Date().toISOString() } });
};

$('teachCancel').onclick = async () => {
  if (confirm('تلغي التعليم من غير ما تحفظ؟')) await store.set({ teach: null });
};

$('teachSave').onclick = async () => {
  const { teach, recipes = {} } = await store.get(['teach', 'recipes']);
  if (!teach) return;
  if (!teach.steps.some((s) => s.kind === 'fill')) { alert('مافيش ولا خانة متحددة.'); return; }
  if (!teach.steps.some((s) => s.gate)) { alert('لازم خطوة واحدة على الأقل تبقى "وقفة موافقة" (زرار الحفظ).'); return; }
  const prev = recipes[teach.type];
  recipes[teach.type] = {
    type: teach.type,
    version: (prev?.version || 0) + 1,
    savedAt: new Date().toISOString(),
    steps: teach.steps,
  };
  await store.set({ recipes, teach: null });
};

$('runStart').onclick = async () => {
  const type = $('runType').value;
  const { recipes = {} } = await store.get('recipes');
  const recipe = recipes[type];
  if (!recipe) { alert('مافيش وصفة محفوظة لـ ' + AMAN.typeLabel(type)); return; }
  let order;
  try { order = JSON.parse($('order').value); } catch { alert('بيانات الطلب مش JSON سليم'); return; }
  await store.set({ run: { type, recipe, order, tabId: await activeTabId(), i: 0, status: 'running', log: [], startedAt: new Date().toISOString() } });
};

$('runStop').onclick = async () => {
  const { run } = await store.get('run');
  if (run) await store.set({ run: { ...run, status: 'stopped' } });
};

$('approve').onclick = async () => {
  const { run } = await store.get('run');
  if (run?.status === 'review') await store.set({ run: { ...run, status: 'approved' } });
};

$('reject').onclick = async () => {
  const { run } = await store.get('run');
  if (run) await store.set({ run: { ...run, status: 'stopped' } });
};

chrome.storage.onChanged.addListener((_c, area) => { if (area === 'local') render(); });
render();
