<script>
function formBuilder(payload) {
    const DEFAULTS = {
        select: { options: [{ label: 'اختيار 1', value: 'option_1' }] },
        radio: { options: [{ label: 'اختيار 1', value: 'option_1' }] },
        checkbox: { options: [{ label: 'اختيار 1', value: 'option_1' }] },
        address: { address_parts: ['governorate', 'area', 'street', 'building', 'landmark'], address_required: ['governorate', 'area', 'street'] },
        document: { max_files: 1, accept: 'images_pdf' },
        group: { min_items: 1, max_items: 3, add_label: 'إضافة واحد كمان', fields: [] },
        machine: { binding: 'request.machine', label: 'اختار المكنة' },
        plan: { binding: 'request.plan', label: 'تقسط على كام شهر؟' },
        note: { message: '' },
        stop: { message: '', conditions: [] },
    };
    const LABELS = {
        text: 'سؤال جديد', textarea: 'سؤال جديد', number: 'رقم', money: 'المبلغ', phone: 'رقم الموبايل', national_id: 'الرقم القومي',
        date: 'التاريخ', select: 'اختار', radio: 'اختار', checkbox: 'اختار اللي ينطبق', yes_no: 'سؤال نعم ولا لأ', address: 'العنوان',
        document: 'ارفع المستند', group: 'ضامن', note: 'نص توضيحي', stop: 'رسالة توقف',
    };
    const SHORT = {
        text: 'نص', textarea: 'نص طويل', number: 'رقم', money: 'فلوس', phone: 'موبايل', national_id: 'رقم قومي', date: 'تاريخ',
        select: 'قائمة', radio: 'أزرار', checkbox: 'متعدد', yes_no: 'نعم / لا', address: 'عنوان', document: 'مستند',
        machine: 'المكنة', plan: 'نظام القسط', group: 'مجموعة', note: 'نص توضيحي', stop: 'توقف التقديم',
    };
    const PLACEHOLDERS = { phone: '01xxxxxxxxx', national_id: '14 رقم', number: '0', money: '0 جنيه', date: 'يوم / شهر / سنة', text: '' };

    const clone = (v) => JSON.parse(JSON.stringify(v));

    return {
        form: payload.form,
        schema: payload.schema,
        published: payload.published,
        types: payload.types,
        icons: payload.icons,
        bindings: payload.bindings,
        documentTypes: payload.documentTypes,
        customerTypes: payload.customerTypes,
        operators: payload.operators,
        addressParts: payload.addressParts,
        workTypes: { delivery_app: 'دليفري تطبيق', delivery_app_bicycle: 'دليفري بعجلة', delivery_company: 'دليفري مطعم/شركة', craftsman: 'صنايعي', other: 'شغل حر تاني', none: 'مش محدد' },
        sel: null,
        paletteTab: 'system',
        drag: null,
        dropHint: null,
        dirty: false,
        busy: false,
        savedAt: null,
        problems: [],
        fieldGroups: [
            { name: 'بيانات بيكتبها', types: ['text', 'textarea', 'number', 'money', 'phone', 'national_id', 'date', 'address'] },
            { name: 'اختيارات', types: ['radio', 'select', 'checkbox', 'yes_no'] },
            { name: 'ورق وطلب', types: ['document', 'machine', 'plan', 'group'] },
            { name: 'للعرض بس', types: ['note', 'stop'] },
        ],

        init() {
            if (!this.schema.settings || Array.isArray(this.schema.settings)) this.schema.settings = {};
            this.schema.sections.forEach(s => { s.conditions ||= []; s.items.forEach(it => this.normalize(it)); });
            if (!this.schema.sections.length) this.addSection(false);
            window.addEventListener('beforeunload', (e) => { if (this.dirty) { e.preventDefault(); e.returnValue = ''; } });
        },

        normalize(item) {
            const d = item.data;
            d.conditions ||= [];
            d.rules = d.rules && !Array.isArray(d.rules) ? d.rules : {};
            if (['select', 'radio', 'checkbox'].includes(item.type)) d.options ||= [];
            d.required = !!d.required;
        },

        uid(prefix) { return prefix + '_' + Math.random().toString(36).slice(2, 8); },
        touch() { this.dirty = true; },

        // ---- selection ----
        select(s, i = null) { this.sel = s === null ? null : { s, i }; },
        isSel(s, i) { return this.sel !== null && this.sel.s === s && this.sel.i === i; },
        current() {
            if (!this.sel || this.sel.i === null) return null;
            return this.schema.sections[this.sel.s]?.items[this.sel.i] || null;
        },
        rules() { return this.current().data.rules; },

        // ---- palette ----
        shortType(t) { return SHORT[t] || this.types[t]; },
        systemGroups() {
            const groups = {};
            this.bindings.forEach(b => { (groups[b.group] ||= []).push(b); });
            return Object.entries(groups).map(([name, items]) => ({ name, items }));
        },
        bindingGroupsFor(type) {
            const groups = {};
            this.bindings.filter(b => b.types.includes(type)).forEach(b => { (groups[b.group] ||= []).push(b); });
            return Object.entries(groups).map(([name, items]) => ({ name, items }));
        },
        bindingLabel(key) { return this.bindings.find(b => b.key === key)?.label || key; },
        isBound(key, except = null) {
            return this.schema.sections.some(s => s.items.some(it => it !== except && it.data.binding === key));
        },
        targetSection() {
            if (this.sel) return this.sel.s;
            return Math.max(0, this.schema.sections.length - 1);
        },
        addField(type, extra = {}) {
            if (!this.schema.sections.length) this.addSection(false);
            const s = this.targetSection();
            const items = this.schema.sections[s].items;
            const at = this.sel && this.sel.i !== null ? this.sel.i + 1 : items.length;
            const item = { type, data: Object.assign({ key: this.uid('f'), label: LABELS[type] || '', required: !['note', 'stop'].includes(type), conditions: [], rules: {} }, clone(DEFAULTS[type] || {}), extra) };
            this.normalize(item);
            items.splice(at, 0, item);
            this.select(s, at);
            this.touch();
            this.$nextTick(() => document.querySelector('.fb-item.sel')?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
        },
        addFromBinding(b) {
            const type = b.types[0];
            const extra = { binding: b.key, label: b.label };
            if (b.document_type) extra.document_type = b.document_type;
            if (b.key === 'work.status') extra.options = [];
            if (b.key.endsWith('.address')) Object.assign(extra, clone(DEFAULTS.address));
            this.addField(type, extra);
        },

        // ---- steps ----
        addSection(select = true) {
            this.schema.sections.push({ key: this.uid('s'), title: 'خطوة جديدة', description: '', conditions: [], match: 'all', items: [] });
            if (select) { this.select(this.schema.sections.length - 1, null); this.touch(); }
        },
        moveSection(s, dir) {
            const to = s + dir;
            if (to < 0 || to >= this.schema.sections.length) return;
            const [sec] = this.schema.sections.splice(s, 1);
            this.schema.sections.splice(to, 0, sec);
            this.select(to, null);
            this.touch();
        },
        removeSection(s) {
            const n = this.schema.sections[s].items.length;
            if (n && !confirm(`الخطوة فيها ${n} حقل، هيتمسحوا كلهم. متأكد؟`)) return;
            this.schema.sections.splice(s, 1);
            this.select(null);
            this.touch();
        },

        // ---- fields ----
        moveItem(s, i, dir) {
            const items = this.schema.sections[s].items;
            let to = i + dir;
            if (to < 0 && s > 0) { const [it] = items.splice(i, 1); this.schema.sections[s - 1].items.push(it); this.select(s - 1, this.schema.sections[s - 1].items.length - 1); this.touch(); return; }
            if (to >= items.length && s < this.schema.sections.length - 1) { const [it] = items.splice(i, 1); this.schema.sections[s + 1].items.unshift(it); this.select(s + 1, 0); this.touch(); return; }
            if (to < 0 || to >= items.length) return;
            const [it] = items.splice(i, 1);
            items.splice(to, 0, it);
            this.select(s, to);
            this.touch();
        },
        duplicate(s, i) {
            const copy = clone(this.schema.sections[s].items[i]);
            copy.data.key = this.uid('f');
            copy.data.binding = '';
            this.schema.sections[s].items.splice(i + 1, 0, copy);
            this.select(s, i + 1);
            this.touch();
        },
        removeItem(s, i) {
            const item = this.schema.sections[s].items[i];
            const users = this.usersOf(item.data.key);
            if (users.length && !confirm(`فيه ${users.length} شرط بيعتمد على السؤال ده، هيتمسحوا معاه. متأكد؟`)) return;
            this.schema.sections.forEach(sec => {
                sec.conditions = (sec.conditions || []).filter(c => c.field !== item.data.key);
                sec.items.forEach(it => { it.data.conditions = (it.data.conditions || []).filter(c => c.field !== item.data.key); });
            });
            this.schema.sections[s].items.splice(i, 1);
            this.select(s, null);
            this.touch();
        },
        dropOn(s, i) {
            if (!this.drag) return;
            const from = this.schema.sections[this.drag.s].items;
            const [it] = from.splice(this.drag.i, 1);
            let at = i;
            if (this.drag.s === s && this.drag.i < i) at--;
            this.schema.sections[s].items.splice(Math.max(0, at), 0, it);
            this.select(s, Math.max(0, at));
            this.drag = null; this.dropHint = null;
            this.touch();
        },
        usersOf(key) {
            const users = [];
            this.schema.sections.forEach(sec => {
                if ((sec.conditions || []).some(c => c.field === key)) users.push(sec);
                sec.items.forEach(it => { if ((it.data.conditions || []).some(c => c.field === key)) users.push(it); });
            });
            return users;
        },

        // ---- choices / address ----
        optionLabel(o) {
            // the value is fixed once: renaming an option never breaks a condition that uses it
            if (!o.value) o.value = this.uid('o');
            this.touch();
        },
        partState(part) {
            const d = this.current().data;
            if ((d.address_required || []).includes(part)) return 'required';
            if ((d.address_parts || []).includes(part)) return 'shown';
            return 'hidden';
        },
        cyclePart(part) {
            const d = this.current().data;
            d.address_parts ||= []; d.address_required ||= [];
            const state = this.partState(part);
            if (state === 'hidden') d.address_parts.push(part);
            else if (state === 'shown') d.address_required.push(part);
            else { d.address_parts = d.address_parts.filter(p => p !== part); d.address_required = d.address_required.filter(p => p !== part); }
            this.touch();
        },

        // ---- conditions ----
        allFields() {
            const list = [];
            this.schema.sections.forEach((sec, s) => sec.items.forEach((it, i) => {
                if (!['note', 'stop', 'document', 'group'].includes(it.type)) list.push({ s, i, key: it.data.key, label: it.data.label || 'سؤال من غير عنوان', type: it.type, options: it.data.options || [] });
            }));
            return list;
        },
        earlierFields() {
            if (!this.sel) return [];
            return this.allFields().filter(f => f.s < this.sel.s || (this.sel.i !== null && f.s === this.sel.s && f.i < this.sel.i));
        },
        fieldByKey(key) { return this.allFields().find(f => f.key === key); },
        toggleWorkType(o, wt) {
            o.work_types ||= [];
            o.work_types = o.work_types.includes(wt) ? o.work_types.filter(x => x !== wt) : [...o.work_types, wt];
            this.touch();
        },
        inList(c, value) { return String(c.value || '').split(',').map(v => v.trim()).includes(String(value)); },
        toggleIn(c, value) {
            const list = String(c.value || '').split(',').map(v => v.trim()).filter(Boolean);
            const v = String(value);
            c.value = (list.includes(v) ? list.filter(x => x !== v) : [...list, v]).join(',');
            this.touch();
        },
        operatorsFor(c) {
            const f = this.fieldByKey(c.field);
            const numeric = f && (['number', 'money'].includes(f.type) || (f.type === 'national_id' && (c.prop || 'age') === 'age'));
            const keys = numeric ? ['equals', 'not_equals', 'gt', 'gte', 'lt', 'lte', 'filled', 'empty'] : ['equals', 'not_equals', 'in', 'filled', 'empty'];
            return Object.fromEntries(keys.map(k => [k, this.operators[k]]));
        },
        valueChoices(c) {
            const f = this.fieldByKey(c.field);
            if (!f) return [];
            if (['select', 'radio', 'checkbox'].includes(f.type)) return f.options.map(o => ({ value: o.value, label: o.label }));
            if (f.type === 'yes_no') return [{ value: 'yes', label: 'نعم' }, { value: 'no', label: 'لا' }];
            if (f.type === 'national_id' && c.prop === 'gender') return [{ value: 'male', label: 'راجل' }, { value: 'female', label: 'ست' }];
            if (f.type === 'national_id' && c.prop === 'governorate') return payload.governorates.map(g => ({ value: g, label: g }));
            return [];
        },
        condText(target, isStop = false) {
            const conds = target.conditions || [];
            if (!conds.length) return '';
            const parts = conds.map(c => {
                const f = this.fieldByKey(c.field);
                if (!f) return '...';
                const prop = c.prop ? ({ age: 'السن', gender: 'النوع', governorate: 'المحافظة' })[c.prop] + ' ' : '';
                const name = (v) => this.valueChoices(c).find(o => String(o.value) === String(v).trim())?.label ?? v;
                const value = c.operator === 'in' ? String(c.value || '').split(',').filter(Boolean).map(name).join(' أو ') : name(c.value);
                const op = ({ equals: '=', not_equals: '≠', gt: '>', gte: '≥', lt: '<', lte: '≤', in: '=', filled: 'متجاوب', empty: 'فاضي' })[c.operator] || c.operator;
                return `${prop || f.label + ' '}${op}${['filled', 'empty'].includes(c.operator) ? '' : ' ' + value}`;
            });
            return (isStop ? 'توقف التقديم لو ' : 'يظهر لو ') + parts.join(target.match === 'any' ? ' أو ' : ' و ');
        },
        ruleText(item) {
            const r = item.data.rules || {};
            if (item.type === 'national_id' && (r.min_age || r.max_age)) return `السن ${r.min_age || '؟'}–${r.max_age || '؟'}`;
            if (['number', 'money'].includes(item.type) && (r.min || r.max)) return `من ${r.min || '؟'} لـ ${r.max || '؟'}`;
            if (['text', 'textarea'].includes(item.type) && (r.min_length || r.max_length)) return `${r.min_length || 0}–${r.max_length || '∞'} حرف`;
            return '';
        },
        placeholderFor(type) { return PLACEHOLDERS[type] ?? ''; },

        // ---- state ----
        stateText() {
            if (this.dirty) return 'فيه تعديلات مش محفوظة';
            if (!this.published) return 'مش منشورة لسه';
            return this.published.same ? `منشورة، نسخة ${this.published.version}` : `فيه تعديلات مش منشورة (المنشور نسخة ${this.published.version})`;
        },
        stateClass() {
            if (this.dirty || (this.published && !this.published.same)) return 'warn';
            return this.published ? 'ok' : 'muted';
        },

        async save() {
            if (this.busy) return;
            this.busy = true;
            try {
                const r = await this.$wire.saveForm(this.form, this.schema);
                this.savedAt = r.saved_at;
                this.dirty = false;
                if (this.published) this.published.same = false;
            } finally { this.busy = false; }
        },
        async publish() {
            if (this.busy) return;
            this.busy = true;
            try {
                const r = await this.$wire.publishForm(this.form, this.schema);
                this.dirty = false;
                if (r.ok) {
                    this.problems = [];
                    this.published = { version: r.version, same: true };
                    new FilamentNotification().title('اتنشرت، نسخة ' + r.version).body('الروابط الجديدة هتتبعت بالنسخة دي.').success().send();
                } else {
                    this.problems = r.problems;
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            } finally { this.busy = false; }
        },
        async preview() {
            const tab = window.open('', '_blank');
            this.busy = true;
            try {
                const r = await this.$wire.previewForm(this.form, this.schema);
                this.dirty = false;
                if (tab) tab.location = r.url; else window.open(r.url, '_blank');
            } finally { this.busy = false; }
        },
    };
}
</script>
