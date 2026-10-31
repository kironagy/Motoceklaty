<script>
function applicationForm() {
    const F = window.__FORM__;
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const ADDRESS_LABELS = { governorate: 'المحافظة', area: 'المنطقة / المدينة', street: 'الشارع', branch_street: 'شارع جانبي', building: 'رقم العقار', floor: 'الدور', apartment: 'الشقة', landmark: 'علامة مميزة' };

    const latin = (v) => String(v ?? '').replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d)).replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d));

    // the same reading of a national id as App\Support\EgyptianNationalId
    function parseNid(raw) {
        const d = latin(raw).replace(/\D/g, '');
        if (d.length !== 14 || !['2', '3'].includes(d[0])) return null;
        const year = (d[0] === '2' ? 1900 : 2000) + +d.slice(1, 3), month = +d.slice(3, 5), day = +d.slice(5, 7);
        const birth = new Date(year, month - 1, day);
        if (birth.getMonth() !== month - 1 || birth > new Date()) return null;
        const now = new Date();
        let age = now.getFullYear() - year;
        if (now.getMonth() < month - 1 || (now.getMonth() === month - 1 && now.getDate() < day)) age--;
        const gov = F.nidGovernorates[d.slice(7, 9)];
        if (!gov) return null;
        return { age, gender: (+d[12]) % 2 === 1 ? 'male' : 'female', governorate: gov };
    }

    return {
        sections: F.sections,
        answers: {},
        files: F.state.files,
        progress: F.state.progress,
        machines: F.machines,
        governorates: F.governorates,
        errors: {},
        blocking: [],
        plans: [],
        plansMeta: { job_known: false, capped: false },
        skipped: 0,
        machineSearch: {},
        selectedBrand: null,
        plansRequestId: 0,
        capOffer: null,
        capConsent: null,
        voiceEnabled: false,
        saveChains: {},
        saveFailed: {},
        lastSavedValues: {},
        uploading: {},
        pending: [],
        dragOver: {},
        lightbox: null,
        timers: {},
        step: 0,
        sending: false,
        submitTried: false,
        done: false,
        savedNote: '',

        init() {
            const saved = F.state.answers || {};
            for (const s of this.sections) {
                for (const it of s.items) {
                    if (it.type === 'address') this.answers[it.key] = Object.assign({}, saved[it.key] || {});
                    else if (it.type === 'checkbox') this.answers[it.key] = Array.isArray(saved[it.key]) ? saved[it.key] : [];
                    else if (it.type === 'group') this.answers[it.key] = Array.isArray(saved[it.key]) && saved[it.key].length ? saved[it.key] : [{}];
                    else this.answers[it.key] = saved[it.key] ?? '';
                }
            }
            for (const [key,value] of Object.entries(this.answers)) this.lastSavedValues[key]=JSON.stringify(value);
            const machineKey = this.keyOf('request.machine');
            if (machineKey && this.answers[machineKey]) {this.selectedBrand=String(this.selectedMachine()?.brand_id ?? '');this.loadPlans(this.answers[machineKey]);}
            this.ping();
            setInterval(() => this.ping(), 60000);
            // what the bot already filled is skipped: he lands on the first step with something
            // left (Back still takes him to the machine to change it)
            this.$nextTick(() => {
                const shown = this.shownSections();
                const first = shown.findIndex(s => this.sectionMissing(s));
                if (first > 0) { this.step = first; this.skipped = first; }
            });
            if (this.files.some(f => f.status === 'checking')) this.poll();
        },

        // ---- visibility (mirrors FormSchema::visible) ----
        visibleMap() {
            const visible = {}, seen = {};
            for (const s of this.sections) {
                const sectionShown = this.met(s.conditions, s.match, seen);
                if (sectionShown) visible['section:' + s.key] = true;
                for (const it of s.items) {
                    if (sectionShown && this.met(it.conditions, it.match, seen)) {
                        visible[it.key] = true;
                        seen[it.key] = this.answers[it.key];
                    }
                }
            }
            return visible;
        },
        met(conditions, match, seen) {
            if (!conditions || !conditions.length) return true;
            const results = conditions.map(c => this.conditionMet(c, seen));
            return match === 'any' ? results.includes(true) : !results.includes(false);
        },
        conditionMet(c, seen) {
            let value = seen[c.field];
            if (c.prop && value) { const p = parseNid(value); value = p ? p[c.prop] : null; }
            const empty = value === null || value === undefined || value === '' || (Array.isArray(value) && !value.length)
                || (typeof value === 'object' && !Array.isArray(value) && value !== null && !Object.values(value).some(v => v));
            if (c.operator === 'filled') return !empty;
            if (c.operator === 'empty') return empty;
            if (empty) return false;
            if (Array.isArray(value)) return value.some(v => this.compare(String(v), c.operator, c.value));
            return this.compare(String(value), c.operator, c.value);
        },
        compare(v, op, expected) {
            expected = String(expected ?? '');
            const numeric = v.trim() !== '' && !isNaN(+v) && expected.trim() !== '' && !isNaN(+expected);
            switch (op) {
                case 'equals': return numeric ? +v === +expected : v.trim() === expected.trim();
                case 'not_equals': return numeric ? +v !== +expected : v.trim() !== expected.trim();
                case 'in': return expected.split(',').map(x => x.trim()).includes(v.trim());
                case 'gt': return numeric && +v > +expected;
                case 'gte': return numeric && +v >= +expected;
                case 'lt': return numeric && +v < +expected;
                case 'lte': return numeric && +v <= +expected;
            }
            return false;
        },
        isVisible(key) { return !!this.visibleMap()[key]; },
        shownSections() {
            const v = this.visibleMap();
            return this.sections.filter(s => v['section:' + s.key] && s.items.some(it => v[it.key]));
        },
        sectionMissing(section) {
            const v = this.visibleMap();
            return section.items.some(it => it.required && v[it.key] && !['note', 'stop'].includes(it.type) && this.isEmpty(it));
        },
        sectionHasErrors(section) { return section.items.some(it => this.errors[it.key]); },

        // ---- saving ----
        queue(key) {
            clearTimeout(this.timers[key]);
            this.timers[key] = setTimeout(() => this.save(key), 900);
        },
        async save(key) {
            clearTimeout(this.timers[key]);
            delete this.timers[key];
            const value = JSON.parse(JSON.stringify(this.answers[key] ?? ''));
            const task = async () => {
                const res = await this.post('/answer', { key, value });
                this.saveFailed[key] = !res;
                if (!res) { this.savedNote = 'تعذر الحفظ، تأكد من الاتصال وحاول تاني.'; return false; }
                if (JSON.stringify(this.answers[key]) === JSON.stringify(value)) this.errors[key] = res.error || null;
                if (res.progress) this.progress = res.progress;
                this.savedNote = 'اتحفظ ✓';
                this.blocking = [];
                const machineKey = this.keyOf('request.machine');
                if (key === this.keyOf('work.status') && this.lastSavedValues[key] !== JSON.stringify(value) && machineKey && this.answers[machineKey]) { this.capConsent = null; await this.loadPlans(this.answers[machineKey]); }
                this.lastSavedValues[key]=JSON.stringify(value);
                if (this.sections.some(s => s.items.some(it => it.key === key && it.type === 'national_id')) && this.files.length) this.refreshFiles();
                return true;
            };
            this.saveChains[key] = (this.saveChains[key] || Promise.resolve()).catch(() => {}).then(task);
            return await this.saveChains[key];
        },
        async flush(section = null) {
            const items = section ? section.items : this.shownSections().flatMap(s => s.items);
            await Promise.all(items.filter(it => this.isVisible(it.key) && !['note','stop','document','images'].includes(it.type)).map(it => this.save(it.key)));
            return !items.some(it => this.saveFailed[it.key]);
        },
        brands() {
            return [...new Map(this.machines.map(m => [String(m.brand_id ?? ''), {id:String(m.brand_id ?? ''), name:m.brand || 'أخرى'}])).values()];
        },
        toggleVoice() {
            this.voiceEnabled = !this.voiceEnabled;
            if (!this.voiceEnabled) window.speechSynthesis?.cancel();
            else this.speak('تم تشغيل المساعد الصوتي.');
        },
        speak(text) {
            if (!this.voiceEnabled || !window.speechSynthesis || !window.SpeechSynthesisUtterance) return;
            window.speechSynthesis.cancel();
            const speech = new SpeechSynthesisUtterance(text); speech.lang = 'ar-EG'; speech.rate = 0.9;
            const voice = window.speechSynthesis.getVoices().find(v => v.lang.startsWith('ar')); if (voice) speech.voice = voice;
            window.speechSynthesis.speak(speech);
        },
        addressPrompt(part) {
            const prompts = {governorate:'اختار المحافظة اللي إنت منها', area:'اكتب اسم المنطقة أو المدينة', street:'اكتب اسم الشارع الرئيسي', branch_street:'اكتب اسم الشارع المتفرع أو الشارع اللي ساكن فيه', building:'اكتب رقم العقار لو موجود', floor:'اكتب رقم الدور', apartment:'اكتب رقم الشقة', landmark:'اكتب علامة مميزة قريبة من العنوان، زي مسجد أو مدرسة أو محل معروف'};
            this.speak(prompts[part] || part);
        },
        needsCap(plan) { return !!plan?.capped && Number(plan.down_payment) > 0 && !(this.capConsent && this.capConsent.machine_id === Number(this.selectedMachine()?.id) && this.capConsent.plan_id === Number(plan.id) && this.capConsent.amount === Math.round(Number(plan.down_payment))); },
        pickPlan(key, plan) {
            if (this.needsCap(plan)) { this.capOffer = {key, plan}; return; }
            this.answers[key] = plan.id; this.save(key);
        },
        acceptCap() {
            const offer = this.capOffer; if (!offer) return;
            this.capConsent = {machine_id:Number(this.selectedMachine()?.id), plan_id:Number(offer.plan.id), amount:Math.round(Number(offer.plan.down_payment))};
            this.answers[offer.key] = offer.plan.id; this.save(offer.key); this.capOffer = null;
        },
        declineCap() { this.capOffer = null; this.capConsent = null; const key=this.keyOf('request.plan'); if(key){this.answers[key]='';this.save(key);} },
        // a value the server filled (from his ID card) lands only in a field he left empty
        mergeFilled(server) {
            let filled = false;
            for (const [key, value] of Object.entries(server)) {
                const current = this.answers[key];
                const empty = current === undefined || current === null || current === '' || (Array.isArray(current) && !current.length);
                if (empty && value !== null && value !== '' && typeof value !== 'object') { this.answers[key] = value; filled = true; }
            }
            if (filled) this.savedNote = 'كمّلنا الاسم والرقم القومي من صورة البطاقة، راجعهم.';
        },
        async refreshFiles() {
            try {
                const r = await fetch(F.base + '/files', { headers: { Accept: 'application/json' } });
                const fresh = (await r.json()).files || [];
                this.files = this.files.map(f => fresh.find(x => x.id === f.id) || f);
            } catch (e) { /* next poll */ }
        },
        toggle(key, value) {
            const list = (this.answers[key] || []).map(String);
            const v = String(value);
            this.answers[key] = list.includes(v) ? list.filter(x => x !== v) : [...list, v];
            this.save(key);
        },
        async ping() { const r = await this.post('/ping', {}); if (r?.progress) this.progress = r.progress; },

        // ---- machine and plan ----
        keyOf(binding) { return Object.keys(F.bindings).find(k => F.bindings[k] === binding) || null; },
        selectedMachine() {
            const key = this.keyOf('request.machine');
            return key ? this.machines.find(m => String(m.id) === String(this.answers[key])) : null;
        },
        selectedPlan() {
            const key = this.keyOf('request.plan');
            return key ? this.plans.find(p => String(p.id) === String(this.answers[key])) : null;
        },
        machineOptions(key) {
            if (this.selectedBrand === null) return [];
            const q = (this.machineSearch[key] || '').trim().toLowerCase();
            const list = this.machines.filter(m => !this.selectedBrand || String(m.brand_id ?? '') === this.selectedBrand);
            return q ? list.filter(m => m.name.toLowerCase().includes(q)) : list;
        },
        async pickMachine(key, id) {
            this.capConsent = null;
            this.answers[key] = id;
            await this.save(key);
            await this.loadPlans(id);
            const capped = this.plans.find(p => p.capped && Number(p.down_payment) > 0);
            if (capped) this.capOffer = {key:this.keyOf('request.plan'), plan:capped};
            const planKey = this.keyOf('request.plan');
            if (planKey && this.answers[planKey] && !this.plans.some(p => String(p.id) === String(this.answers[planKey]))) {
                this.answers[planKey] = '';
                this.save(planKey);
            }
        },
        async loadPlans(machineId) {
            if (String(this.selectedMachine()?.id) !== String(machineId)) return;
            const requestId=++this.plansRequestId;
            try {
                const r = await fetch(F.base + '/plans?machine=' + encodeURIComponent(machineId), { headers: { Accept: 'application/json' } });
                const data = await r.json();
                if(requestId!==this.plansRequestId || String(this.selectedMachine()?.id)!==String(machineId))return;
                this.plans = data.plans || [];
                this.plansMeta = { job_known: !!data.job_known, capped: !!data.capped };
            } catch (e) { if(requestId===this.plansRequestId)this.plans = []; }
        },
        money(n) { return n ? Math.round(n).toLocaleString('en-US') : ''; },

        // ---- address ----
        addressParts(item) {
            const parts = item.address_parts && item.address_parts.length ? item.address_parts : Object.keys(ADDRESS_LABELS);
            return parts.map(k => ({ key: k, label: ADDRESS_LABELS[k] || k }));
        },

        // ---- uploads ----
        filesOf(key) { return this.files.filter(f => f.key === key); },
        pendingOf(key) { return this.pending.filter(p => p.key === key); },
        maxFiles(item) { return Math.max(1, +(item.max_files || (item.type === 'images' ? 10 : 1))); },
        minFiles(item) { return item.type === 'images' ? Math.max(1, +(item.min_files || 1)) : 1; },
        // a single-file field keeps its zone so a new photo can replace the old one
        canAddFiles(item) {
            const n = this.filesOf(item.key).length + this.pendingOf(item.key).length;
            return this.maxFiles(item) === 1 || n < this.maxFiles(item);
        },
        zoneTitle(item) {
            const n = this.filesOf(item.key).length;
            if (item.type === 'images') return n ? 'ضيف صور كمان' : 'ارفع الصور';
            return n ? 'غيّر الملف' : 'ارفع ' + (item.label || 'الملف');
        },
        zoneHint(item) {
            if (item.type === 'images') {
                const min = this.minFiles(item), max = this.maxFiles(item);
                return (min > 1 ? `${min} صور على الأقل` : 'تقدر تختار أكتر من صورة مرة واحدة') + ` · لحد ${max} صورة`;
            }
            const kinds = item.accept === 'images' ? 'صورة' : (item.accept === 'video' ? 'صورة أو فيديو' : 'صورة أو PDF');
            return this.maxFiles(item) > 1 ? `${kinds} · لحد ${this.maxFiles(item)} ملفات` : `${kinds} واضحة`;
        },
        acceptFor(item) {
            if (item.type === 'images') return 'image/*';
            return item.accept === 'images' ? 'image/*' : (item.accept === 'video' ? 'image/*,video/*' : 'image/*,application/pdf');
        },
        async upload(item, event) {
            const source = event.dataTransfer ? event.dataTransfer.files : (event.target.files || []);
            let picked = Array.from(source);
            if (event.target && event.target.value !== undefined) event.target.value = '';
            const room = this.maxFiles(item) === 1 ? 1 : this.maxFiles(item) - this.filesOf(item.key).length - this.pendingOf(item.key).length;
            if (picked.length > room) {
                this.errors[item.key] = room > 0 ? `اخترت ${picked.length}، والمسموح ${room} بس دلوقتي. رفعنا أول ${room}.` : `وصلت لأقصى عدد (${this.maxFiles(item)}).`;
                picked = picked.slice(0, Math.max(0, room));
            } else {
                this.errors[item.key] = null;
            }
            // several at once: up to 3 uploads side by side
            const queue = picked.map(file => this.uploadOne(item, file));
            this.uploading[item.key] = true;
            await Promise.all(queue);
            this.uploading[item.key] = false;
            this.poll();
        },
        async uploadOne(item, original) {
            const id = 'p' + Math.random().toString(36).slice(2);
            const isImage = (original.type || '').startsWith('image/');
            const entry = { id, key: item.key, name: original.name, preview: isImage ? URL.createObjectURL(original) : null, progress: 2 };
            this.pending.push(entry);
            const track = () => this.pending.find(p => p.id === id);
            try {
                const file = isImage ? await this.shrink(original) : original;
                const body = new FormData();
                body.append('key', item.key);
                body.append('file', file, file.name || original.name);
                const data = await new Promise((resolve) => {
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', F.base + '/files');
                    xhr.setRequestHeader('X-CSRF-TOKEN', csrf);
                    xhr.setRequestHeader('Accept', 'application/json');
                    xhr.upload.onprogress = (e) => { const p = track(); if (p && e.lengthComputable) p.progress = Math.max(2, Math.round(e.loaded / e.total * 95)); };
                    xhr.onload = () => { let d = {}; try { d = JSON.parse(xhr.responseText); } catch (e) {} d.httpOk = xhr.status >= 200 && xhr.status < 300; resolve(d); };
                    xhr.onerror = () => resolve({ httpOk: false, error: 'النت فصل، جرّب تاني.' });
                    xhr.send(body);
                });
                if (!data.httpOk || !data.ok) { this.errors[item.key] = data.error || data.message || 'الملف موصلش، جرّب تاني.'; return; }
                if (this.maxFiles(item) === 1) this.files = this.files.filter(f => f.key !== item.key);
                this.files.push(data.file);
                if (!this.errors[item.key]?.startsWith?.('اخترت')) this.errors[item.key] = null;
                this.savedNote = 'اترفع ✓';
                this.progress = data.progress || this.progress;
            } finally {
                if (entry.preview) URL.revokeObjectURL(entry.preview);
                this.pending = this.pending.filter(p => p.id !== id);
            }
        },
        // phone photos are 4-12 MB: sent at most 2000px, JPEG
        shrink(file) {
            return new Promise((resolve) => {
                const img = new Image();
                img.onload = () => {
                    const max = 2000, scale = Math.min(1, max / Math.max(img.width, img.height));
                    if (scale === 1 && file.size < 2.5e6) return resolve(file);
                    const canvas = document.createElement('canvas');
                    canvas.width = Math.round(img.width * scale); canvas.height = Math.round(img.height * scale);
                    canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                    canvas.toBlob(b => resolve(b ? new File([b], (file.name || 'photo').replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file), 'image/jpeg', 0.85);
                };
                img.onerror = () => resolve(file);
                img.src = URL.createObjectURL(file);
            });
        },
        async removeFile(f) {
            this.files = this.files.filter(x => x.id !== f.id);
            const r = await fetch(F.base + '/files/' + f.id, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' } });
            const data = await r.json().catch(() => ({}));
            if (data.progress) this.progress = data.progress;
        },
        polling: false,
        async poll() {
            if (this.polling) return;
            this.polling = true;
            const started = Date.now();
            while (this.files.some(f => f.status === 'checking') && Date.now() - started < 45000) {
                await new Promise(r => setTimeout(r, 2000));
                try {
                    const r = await fetch(F.base + '/files', { headers: { Accept: 'application/json' } });
                    const data = await r.json();
                    const fresh = data.files || [];
                    this.files = this.files.map(f => fresh.find(x => x.id === f.id) || f);
                    this.mergeFilled(data.answers || {});
                    if (data.progress) this.progress = data.progress;
                } catch (e) { /* keep waiting */ }
            }
            this.polling = false;
        },

        // ---- steps and submit ----
        // ---- steps open in order: the next one only once this one is complete ----
        sectionProblems(section) {
            const v = this.visibleMap();
            if (v['section:' + section.key] === false) return {};
            const problems = {};
            for (const it of section.items) {
                if (!v[it.key]) continue;
                if (it.type === 'stop') { problems[it.key] = it.message || it.label || 'مش هينفع نكمّل'; continue; }
                if (it.type === 'address' && it.required) { const missing=(it.address_required || ['governorate','area','street']).filter(k => (it.address_parts || Object.keys(ADDRESS_LABELS)).includes(k) && !String((this.answers[it.key] || {})[k] || '').trim()); if(missing.length){problems[it.key]='كمّل: '+missing.map(k=>ADDRESS_LABELS[k]||k).join('، ');continue;} }
                if (this.errors[it.key] && this.errors[it.key] !== 'مطلوب' && !['document', 'images'].includes(it.type)) { problems[it.key] = this.errors[it.key]; continue; }
                if (it.required && this.isEmpty(it)) { problems[it.key] = ['document', 'images'].includes(it.type) ? 'ارفع ' + it.label : 'مطلوب'; continue; }
                if (it.type === 'images' && this.filesOf(it.key).length && this.filesOf(it.key).length < this.minFiles(it)) {
                    problems[it.key] = `ارفع ${this.minFiles(it)} صور على الأقل`;
                }
                if (['document', 'images'].includes(it.type) && this.pendingOf(it.key).length) problems[it.key] = 'استنى لما الرفع يخلص';
            }
            return problems;
        },
        sectionDone(section) { return Object.keys(this.sectionProblems(section)).length === 0; },
        // the furthest step he may open: every step before it is complete
        unlockedUpTo() {
            const shown = this.shownSections();
            for (let i = 0; i < shown.length; i++) if (!this.sectionDone(shown[i])) return i;
            return shown.length - 1;
        },
        canOpen(i) { return i <= this.step || i <= this.unlockedUpTo(); },
        go(i) {
            if (!this.canOpen(i)) { this.savedNote = 'كمّل الخطوة دي الأول.'; return; }
            this.step = i;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },
        async next(section, i) {
            // what he just typed is saved (and judged by the server) before the step is checked
            if (!await this.flush(section)) return;
            const plan=this.selectedPlan(); if (plan && this.needsCap(plan)) {this.capOffer={key:this.keyOf('request.plan'),plan};return;}
            const problems = this.sectionProblems(section);
            const keys = Object.keys(problems);
            if (keys.length) {
                Object.assign(this.errors, problems);
                this.savedNote = 'كمّل المطلوب في الخطوة دي الأول.';
                this.$nextTick(() => document.getElementById('f-' + keys[0])?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
                return;
            }
            this.savedNote = '';
            this.step = i + 1;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },
        isEmpty(it) {
            const v = this.answers[it.key];
            if (['document', 'images'].includes(it.type)) return !this.filesOf(it.key).length;
            if (it.type === 'address') return !v || !Object.values(v).some(x => x);
            if (it.type === 'group') return !(v || []).some(row => Object.values(row || {}).some(x => x));
            if (Array.isArray(v)) return !v.length;
            return v === null || v === undefined || String(v).trim() === '';
        },
        async submit() {
            this.submitTried = true;
            this.sending = true;
            // anything still waiting to autosave goes first
            if (!await this.flush()) { this.sending=false; return; }
            const plan=this.selectedPlan(); if (plan && this.needsCap(plan)) {this.sending=false;this.capOffer={key:this.keyOf('request.plan'),plan};return;}
            try {
                const r = await fetch(F.base + '/submit', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Content-Type':'application/json', Accept: 'application/json' }, body:JSON.stringify({cap_confirmation:this.capConsent || {}}) });
                const data = await r.json().catch(() => ({}));
                if (data.ok) { this.done = true; window.scrollTo({ top: 0 }); return; }
                if (data.code === 'CAP_CONFIRMATION_REQUIRED' && this.selectedPlan()) {this.capConsent=null;this.capOffer={key:this.keyOf('request.plan'),plan:this.selectedPlan()};}
                this.errors = Object.assign({}, data.errors || {});
                this.blocking = data.blocking || (data.message ? [data.message] : []);
                const firstKey = Object.keys(this.errors)[0];
                if (firstKey) {
                    const idx = this.shownSections().findIndex(s => s.items.some(it => it.key === firstKey));
                    if (idx >= 0) this.go(idx);
                    setTimeout(() => document.getElementById('f-' + firstKey)?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 500);
                }
            } catch (e) {
                this.blocking = ['النت فصل، جرّب تاني.'];
            } finally {
                this.sending = false;
            }
        },

        async post(path, payload) {
            try {
                const r = await fetch(F.base + path, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                    body: JSON.stringify(payload),
                });
                if (r.status === 410) { location.reload(); return null; }
                return r.ok ? await r.json() : null;
            } catch (e) {
                this.savedNote = 'النت ضعيف - هنحفظ أول ما يرجع';
                return null;
            }
        },
    };
}
</script>
