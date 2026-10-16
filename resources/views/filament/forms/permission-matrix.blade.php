{{-- Tabs x actions grid for roles and staff custom permissions (App\Filament\Support\PermissionMatrix). --}}
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        dir="rtl"
        class="pm"
        x-data="{
            state: $wire.$entangle(@js($getStatePath())),
            groups: @js($groups),
            abilities: @js($abilities),
            query: '',
            closed: {},

            all() { return (this.state && ! Array.isArray(this.state) && typeof this.state === 'object') ? this.state : {} },
            get(key) { const v = this.all()[key]; return Array.isArray(v) ? v : [] },
            has(key, ab) { return this.get(key).includes(ab) },
            write(changes) {
                const s = { ...this.all() };
                for (const [key, list] of Object.entries(changes)) {
                    if (list.length) { s[key] = list } else { delete s[key] }
                }
                this.state = s;
            },
            // Any action opens the tab too; closing the tab clears its actions.
            normalize(list) {
                if (! list.length) return [];
                if (! list.includes('view')) list = ['view', ...list];
                return this.order(list);
            },
            order(list) { return Object.keys(this.abilities).filter(a => list.includes(a)) },
            toggle(tab, ab) {
                let list = this.get(tab.key);
                if (list.includes(ab)) {
                    list = ab === 'view' ? [] : list.filter(a => a !== ab);
                } else {
                    list = this.normalize([...list, ab]);
                }
                this.write({ [tab.key]: list });
            },
            rowState(tab) {
                const n = tab.abilities.filter(a => this.has(tab.key, a)).length;
                return n === 0 ? 'off' : (n === tab.abilities.length ? 'on' : 'some');
            },
            toggleRow(tab) {
                this.write({ [tab.key]: this.rowState(tab) === 'on' ? [] : [...tab.abilities] });
            },
            tabsOf(group, ab) { return group.tabs.filter(t => t.abilities.includes(ab)) },
            colState(group, ab) {
                const tabs = this.tabsOf(group, ab);
                const n = tabs.filter(t => this.has(t.key, ab)).length;
                return (! tabs.length || n === 0) ? 'off' : (n === tabs.length ? 'on' : 'some');
            },
            toggleCol(group, ab) {
                const on = this.colState(group, ab) === 'on';
                const changes = {};
                for (const t of this.tabsOf(group, ab)) {
                    let list = this.get(t.key);
                    if (on) { list = ab === 'view' ? [] : list.filter(a => a !== ab) }
                    else { list = this.normalize([...list, ab]) }
                    changes[t.key] = list;
                }
                this.write(changes);
            },
            setGroups(groups, mode) {
                const changes = {};
                for (const g of groups) for (const t of g.tabs) {
                    changes[t.key] = mode === 'all' ? [...t.abilities] : (mode === 'view' ? ['view'] : []);
                }
                this.write(changes);
            },
            opened(group) { return group.tabs.filter(t => this.has(t.key, 'view')).length },
            totalOpened() { return this.groups.reduce((n, g) => n + this.opened(g), 0) },
            totalTabs() { return this.groups.reduce((n, g) => n + g.tabs.length, 0) },
            matches(tab) { return ! this.query || tab.label.toLowerCase().includes(this.query.toLowerCase()) || tab.group.includes(this.query) },
            visibleGroups() { return this.groups.filter(g => g.tabs.some(t => this.matches(t))) },
            isOpen(group) { return this.query !== '' || ! this.closed[group.name] },
            toggleGroup(group) { this.closed = { ...this.closed, [group.name]: ! this.closed[group.name] } },
            setAllClosed(value) { const c = {}; for (const g of this.groups) c[g.name] = value; this.closed = c },
        }"
    >
        <style>
            /* Same palette as the dashboard theme (resources/css/filament/admin/theme.css): navy cards, blue-purple accents. */
            .pm { --pm-grad: linear-gradient(135deg, #2563eb, #7c3aed); --pm-blue: #2563eb; --pm-card: #fff; --pm-bar: #fff; --pm-border: #e5e7eb; --pm-line: #eef2f7; --pm-head: linear-gradient(135deg, rgba(37,99,235,.08), rgba(124,58,237,.08)); --pm-even: #f8fafc; --pm-hover: #eff6ff; --pm-text: #111827; --pm-muted: #6b7280; --pm-box: #fff; --pm-box-border: #cbd5e1; --pm-shadow: 0 12px 30px rgba(15,23,42,.08); font-family: inherit; }
            .dark .pm { --pm-card: #1C293E; --pm-bar: #1C293E; --pm-border: rgba(255,255,255,.08); --pm-line: rgba(255,255,255,.07); --pm-head: linear-gradient(135deg, rgba(37,99,235,.18), rgba(124,58,237,.18)); --pm-even: rgba(255,255,255,.035); --pm-hover: rgba(37,99,235,.16); --pm-text: #f8fafc; --pm-muted: rgba(229,231,235,.6); --pm-box: rgba(255,255,255,.06); --pm-box-border: rgba(255,255,255,.18); --pm-shadow: 0 18px 45px rgba(0,0,0,.35); }
            .pm-bar { display: flex; flex-wrap: wrap; gap: .6rem; align-items: center; justify-content: space-between; padding: .8rem; border: 1px solid var(--pm-border); border-radius: 14px; background: var(--pm-bar); box-shadow: var(--pm-shadow); margin-bottom: .9rem; }
            .pm-search { flex: 1 1 220px; min-width: 0; display: flex; align-items: center; gap: .45rem; background: var(--pm-box); border: 1px solid var(--pm-box-border); border-radius: 10px; padding: 0 .7rem; transition: .15s; }
            .pm-search:focus-within { border-color: var(--pm-blue); box-shadow: 0 0 0 3px rgba(37,99,235,.2); }
            .pm-search input { flex: 1; min-width: 0; border: 0; background: transparent; padding: .55rem 0; color: var(--pm-text); font-size: .875rem; outline: none; box-shadow: none; }
            .pm-search input::placeholder { color: var(--pm-muted); }
            .pm-chip { display: inline-flex; align-items: center; font-size: .8rem; font-weight: 700; color: #fff; background: var(--pm-grad); border-radius: 999px; padding: .35rem .85rem; white-space: nowrap; box-shadow: 0 6px 16px rgba(37,99,235,.3); }
            .pm-btns { display: flex; flex-wrap: wrap; gap: .4rem; }
            .pm-btn { font-size: .78rem; font-weight: 600; padding: .4rem .75rem; border-radius: 9px; border: 1px solid var(--pm-border); background: var(--pm-box); color: var(--pm-text); cursor: pointer; transition: .15s; white-space: nowrap; }
            .pm-btn:hover { background: var(--pm-grad); border-color: transparent; color: #fff; box-shadow: 0 6px 16px rgba(37,99,235,.3); }
            .pm-btn.pm-danger:hover { background: #ef4444; box-shadow: 0 6px 16px rgba(239,68,68,.3); }
            .pm-group { border: 1px solid var(--pm-border); border-radius: 14px; background: var(--pm-card); box-shadow: var(--pm-shadow); margin-bottom: .8rem; overflow: hidden; }
            .pm-ghead { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; justify-content: space-between; padding: .7rem .9rem; cursor: pointer; user-select: none; background: var(--pm-head); position: relative; }
            .pm-ghead::before { content: ''; position: absolute; inset-block: 0; right: 0; width: 4px; background: var(--pm-grad); opacity: .35; transition: .2s; }
            .pm-group.pm-active .pm-ghead::before { opacity: 1; }
            .pm-gtitle { display: flex; align-items: center; gap: .55rem; font-weight: 700; color: var(--pm-text); font-size: .95rem; }
            .pm-caret { width: 1rem; height: 1rem; transition: transform .2s; color: var(--pm-muted); }
            .pm-count { font-size: .72rem; font-weight: 700; padding: .15rem .55rem; border-radius: 999px; background: var(--pm-box); color: var(--pm-muted); border: 1px solid var(--pm-border); }
            .pm-count.pm-has { background: var(--pm-grad); color: #fff; border-color: transparent; }
            .pm-scroll { overflow-x: auto; border-top: 1px solid var(--pm-border); }
            .pm table { width: 100%; border-collapse: collapse; min-width: 560px; }
            .pm th { font-size: .74rem; font-weight: 700; color: var(--pm-text); padding: .55rem .4rem; background: var(--pm-head); text-align: center; }
            .pm th:first-child, .pm td:first-child { text-align: right; padding-right: .95rem; }
            .pm td { padding: .42rem; text-align: center; border-top: 1px solid var(--pm-line); }
            .pm tbody tr:nth-child(even) td { background: var(--pm-even); }
            .pm tbody tr:hover td { background: var(--pm-hover); }
            .pm-label { font-size: .875rem; font-weight: 600; color: var(--pm-text); }
            .pm-page { font-size: .68rem; color: var(--pm-muted); margin-right: .35rem; }
            .pm-colhead { display: inline-flex; flex-direction: column; align-items: center; gap: .3rem; background: none; border: 0; cursor: pointer; color: inherit; font: inherit; padding: 0; }
            .pm-box { width: 1.75rem; height: 1.75rem; display: inline-grid; place-items: center; border-radius: 8px; border: 1.5px solid var(--pm-box-border); background: var(--pm-box); color: #fff; cursor: pointer; transition: .15s; padding: 0; }
            .pm-box:hover { border-color: var(--pm-blue); box-shadow: 0 0 0 3px rgba(37,99,235,.18); }
            .pm-box.pm-on { background: var(--pm-grad); border-color: transparent; box-shadow: 0 4px 12px rgba(37,99,235,.35); }
            .pm-box.pm-some { background: rgba(37,99,235,.18); border-color: var(--pm-blue); color: #60a5fa; }
            html:not(.dark) .pm-box.pm-some { color: var(--pm-blue); }
            .pm-box.pm-sm { width: 1.3rem; height: 1.3rem; border-radius: 6px; }
            .pm-box svg { width: 70%; height: 70%; }
            .pm-na { color: var(--pm-muted); opacity: .4; }
            .pm-empty { text-align: center; color: var(--pm-muted); padding: 1.5rem; font-size: .875rem; }
        </style>

        <div class="pm-bar">
            <div class="pm-search">
                <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="color: var(--pm-muted)"><path stroke-linecap="round" stroke-width="2" d="m21 21-4.3-4.3M17 10.5a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0Z"/></svg>
                <input type="text" x-model.debounce.150ms="query" placeholder="دوّر على تاب..." />
            </div>
            <span class="pm-chip" x-text="`${totalOpened()} من ${totalTabs()} تاب مفتوح`"></span>
            <div class="pm-btns">
                <button type="button" class="pm-btn" x-on:click="setGroups(groups, 'all')">كل الصلاحيات</button>
                <button type="button" class="pm-btn" x-on:click="setGroups(groups, 'view')">مشاهدة بس للكل</button>
                <button type="button" class="pm-btn pm-danger" x-on:click="setGroups(groups, 'none')">مسح الكل</button>
                <button type="button" class="pm-btn" x-on:click="setAllClosed(false)">فتح المجموعات</button>
                <button type="button" class="pm-btn" x-on:click="setAllClosed(true)">قفل المجموعات</button>
            </div>
        </div>

        <template x-for="group in visibleGroups()" :key="group.name">
            <div class="pm-group" :class="opened(group) && 'pm-active'">
                <div class="pm-ghead" x-on:click="toggleGroup(group)">
                    <div class="pm-gtitle">
                        <svg class="pm-caret" :style="isOpen(group) ? '' : 'transform: rotate(90deg)'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="m6 9 6 6 6-6"/></svg>
                        <span x-text="group.name"></span>
                        <span class="pm-count" :class="opened(group) && 'pm-has'" x-text="`${opened(group)} من ${group.tabs.length}`"></span>
                    </div>
                    <div class="pm-btns" x-on:click.stop>
                        <button type="button" class="pm-btn" x-on:click="setGroups([group], 'all')">كله</button>
                        <button type="button" class="pm-btn" x-on:click="setGroups([group], 'view')">مشاهدة بس</button>
                        <button type="button" class="pm-btn pm-danger" x-on:click="setGroups([group], 'none')">مسح</button>
                    </div>
                </div>

                <div class="pm-scroll" x-show="isOpen(group)" x-collapse>
                    <table>
                        <thead>
                            <tr>
                                <th>التاب</th>
                                <template x-for="(label, ab) in abilities" :key="ab">
                                    <th>
                                        <button type="button" class="pm-colhead" x-on:click="toggleCol(group, ab)" :title="`${label} لكل تابات المجموعة`">
                                            <span x-text="label"></span>
                                            <span class="pm-box pm-sm" :class="{ 'pm-on': colState(group, ab) === 'on', 'pm-some': colState(group, ab) === 'some' }">
                                                <svg x-show="colState(group, ab) === 'on'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="m5 13 4 4L19 7"/></svg>
                                                <svg x-show="colState(group, ab) === 'some'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-width="3" d="M6 12h12"/></svg>
                                            </span>
                                        </button>
                                    </th>
                                </template>
                                <th>الكل</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="tab in group.tabs.filter(t => matches(t))" :key="tab.key">
                                <tr>
                                    <td>
                                        <span class="pm-label" x-text="tab.label"></span>
                                        <span class="pm-page" x-show="tab.abilities.length === 1">(صفحة)</span>
                                    </td>
                                    <template x-for="(label, ab) in abilities" :key="ab">
                                        <td>
                                            <template x-if="tab.abilities.includes(ab)">
                                                <button type="button" class="pm-box" :class="has(tab.key, ab) && 'pm-on'" x-on:click="toggle(tab, ab)" :title="`${label} — ${tab.label}`">
                                                    <svg x-show="has(tab.key, ab)" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="m5 13 4 4L19 7"/></svg>
                                                </button>
                                            </template>
                                            <template x-if="! tab.abilities.includes(ab)">
                                                <span class="pm-na">—</span>
                                            </template>
                                        </td>
                                    </template>
                                    <td>
                                        <button type="button" class="pm-box" :class="{ 'pm-on': rowState(tab) === 'on', 'pm-some': rowState(tab) === 'some' }" x-on:click="toggleRow(tab)" title="كل صلاحيات التاب">
                                            <svg x-show="rowState(tab) === 'on'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="m5 13 4 4L19 7"/></svg>
                                            <svg x-show="rowState(tab) === 'some'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-width="3" d="M6 12h12"/></svg>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>

        <div class="pm-empty" x-show="! visibleGroups().length">مفيش تاب بالاسم ده.</div>
    </div>
</x-dynamic-component>
