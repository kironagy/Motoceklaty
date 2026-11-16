// Dev only: answers the extension's /api/aman calls for http://fake.motobot like motobot would.
(() => {
  const BASE = 'http://fake.motobot/api/aman';
  const get = (k, d) => JSON.parse(localStorage.getItem('fake:' + k) || 'null') ?? d;
  const put = (k, v) => localStorage.setItem('fake:' + k, JSON.stringify(v));
  const file = location.origin + '/dev/id-front.jpg';
  if (!localStorage.getItem('fake:queue')) put('queue', [
    { id: 101, nid: '29001011234567', name: 'محمد أحمد علي حسن', gender: 'ذكر' },
    { id: 102, nid: '29505051234569', name: 'سعيد محمود كامل فرج', gender: 'ذكر' },
    { id: 103, nid: '28503031234561', name: 'فاطمة علي حسن محمد', gender: 'أنثى', type: 'pension' },
    { id: 104, nid: '29107071234563', name: 'كريم سامي فؤاد عبده', gender: 'ذكر', area: '', street: 'شارع العريش', addr: 'شارع العريش من فيصل محافظة الجيزة' },
  ]);
  const TYPES = { no_income_proof: 'دخل حر', employee: 'موظف / صاحب عمل', pension: 'صاحب معاش', self_employed: 'صاحب نشاط' };
  const ME = { id: 1, name: 'شادي (تجربة)', can_teach: true, types: TYPES };
  const json = (data, status = 200) => new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json' } });
  const realFetch = window.fetch.bind(window);
  window.fetch = async (url, opt = {}) => {
    if (!String(url).startsWith(BASE)) return realFetch(url, opt);
    const path = String(url).slice(BASE.length), method = opt.method || 'GET', body = opt.body ? JSON.parse(opt.body) : {};
    if (path === '/login') {
      if (body.email !== 'shady@motogate.test' || body.password !== 'secret') return json({ message: 'الإيميل أو الباسورد غلط' }, 422);
      return json({ token: 'amn_dev', me: ME });
    }
    if ((opt.headers?.Authorization || '') !== 'Bearer amn_dev') return json({ message: 'سجّل دخول تاني' }, 401);
    if (path === '/me') return json(ME);
    if (path === '/logout') return json({ ok: true });
    if (path === '/recipes' && method === 'GET') return json(get('recipes', {}));
    if (path.startsWith('/recipes/') && method === 'PUT') {
      const type = path.split('/')[2], all = get('recipes', {});
      const version = (all[type]?.version || 0) + 1;
      all[type] = { type, version, steps: body.steps }; put('recipes', all);
      return json({ type, version });
    }
    const finished = () => get('results', []).filter((r) => r.status !== 'error' && r.status !== 'released').map((r) => r.id);
    if (path === '/queue') {
      const recipes = get('recipes', {});
      return json({ items: get('queue', []).filter((q) => !finished().includes(q.id)).map((q) => ({
        id: q.id, name: q.name, national_id: q.nid, phone: '01012345678', type: q.type || 'no_income_proof',
        type_label: TYPES[q.type || 'no_income_proof'], company: 'امان', has_recipe: !!recipes[q.type || 'no_income_proof'] })) });
    }
    const c = path.match(/^\/requests\/(\d+)\/claim$/);
    if (c) {
      const o = get('queue', []).find((q) => q.id === +c[1] && !finished().includes(q.id));
      if (!o) return json({ message: 'الطلب ده مبقاش جديد أو حد تاني شغال عليه' }, 409);
      const p = o.name.split(' ');
      return json({
        upload: { id: o.id, type: o.type || 'no_income_proof', type_label: TYPES[o.type || 'no_income_proof'] }, request_id: o.id,
        order: { applicant_name: o.name, applicant_national_id: o.nid, applicant_phone: '01012345678', applicant_id_image: [file], applicant_id_back_image: [file],
          applicant_governorate: o.gov || 'الجيزة', applicant_area: o.area ?? 'الهرم', applicant_street: o.street ?? 'ترسا متفرع من العمده', applicant_building_number: '2',
          applicant_address: o.addr || 'رقم العقار 2 شارع ترسا متفرع من العمده محافظة الجيزة المنطقة الهرم علامة مميزة: حلواني الروشيه الدور 2 شقة 6' },
        card: { name_1: p[0], name_2: p[1], name_3: p[2], name_4: p[3], full_name: o.name, national_id: o.nid, gender: o.gender, birthdate: '1990-01-01', name_source: 'card', errors: [] },
      });
    }
    if (path.startsWith('/report')) {
      const q = get('queue', []);
      return json({ rows: get('results', []).map((r) => ({ id: r.id, name: q.find((x) => x.id === r.id)?.name, type_label: 'دخل حر', status: r.status, aman_ref: r.aman_ref, message: r.message, at: new Date().toISOString() })) });
    }
    const m = path.match(/^\/uploads\/(\d+)\/result$/);
    if (m) { put('results', [...get('results', []), { id: +m[1], ...body }]); return json({ ok: true }); }
    return json({ message: 'not found' }, 404);
  };
})();
