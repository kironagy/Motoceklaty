{{-- Sidebar builder (App\Filament\Pages\DashboardSettings): groups as cards, tabs dragged between them,
     names edited in place, and a live preview of the sidebar beside it. State: [{label, items: [{key, label}]}]. --}}
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        dir="rtl"
        class="sb"
        x-data="{
            state: $wire.$entangle(@js($getStatePath())),
            entries: @js($entries),
            editing: null,
            hover: null,
            uid: 0,

            groups() { return Array.isArray(this.state) ? this.state : Object.values(this.state || {}) },
            id(obj) { if (! obj._id) obj._id = 'n' + (++this.uid); return obj._id },
            entry(key) { return this.entries[key] || { label: key, icon: '', group: '' } },
            name(item) { return (item.label && item.label.trim()) || this.entry(item.key).label },
            renamed(item) { return !! (item.label && item.label.trim() && item.label.trim() !== this.entry(item.key).label) },
            moved(item, group) { return (group.label || '') !== (this.entry(item.key).group || '') },
            total() { return this.groups().reduce((n, g) => n + (g.items || []).length, 0) },

            commit(groups) { this.state = groups.map(g => ({ ...g, items: [...(g.items || [])] })) },

            // Sortable moves DOM nodes; put the node back and let Alpine re-render from the state.
            undoDom(evt) {
                evt.item.remove();
                const items = [...evt.from.querySelectorAll(':scope > [data-sb-item]')];
                const ref = items[evt.oldDraggableIndex] ?? null;
                evt.from.insertBefore(evt.item, ref);
            },
            sortItems(el) {
                if (! window.Sortable) return;
                window.Sortable.create(el, {
                    group: 'sb-items',
                    draggable: '[data-sb-item]',
                    handle: '[data-sb-grip]',
                    animation: 180,
                    ghostClass: 'sb-ghost',
                    chosenClass: 'sb-chosen',
                    onEnd: (evt) => {
                        const from = +evt.from.dataset.group, to = +evt.to.dataset.group;
                        const oi = evt.oldDraggableIndex, ni = evt.newDraggableIndex;
                        this.undoDom(evt);
                        if (from === to && oi === ni) return;
                        const groups = this.groups();
                        const [moved] = groups[from].items.splice(oi, 1);
                        groups[to].items.splice(ni, 0, moved);
                        this.commit(groups);
                    },
                });
            },
            sortGroups(el) {
                if (! window.Sortable) return;
                window.Sortable.create(el, {
                    draggable: '[data-sb-group]',
                    handle: '[data-sb-group-grip]',
                    animation: 220,
                    ghostClass: 'sb-ghost',
                    onEnd: (evt) => {
                        const oi = evt.oldDraggableIndex, ni = evt.newDraggableIndex;
                        evt.item.remove();
                        const nodes = [...el.querySelectorAll(':scope > [data-sb-group]')];
                        el.insertBefore(evt.item, nodes[oi] ?? el.querySelector(':scope > [data-sb-end]'));
                        if (oi === ni) return;
                        const groups = this.groups();
                        const [moved] = groups.splice(oi, 1);
                        groups.splice(ni, 0, moved);
                        this.commit(groups);
                    },
                });
            },
            addGroup() {
                const groups = this.groups();
                groups.push({ label: 'جروب جديد', items: [] });
                this.commit(groups);
                this.$nextTick(() => { const inputs = this.$root.querySelectorAll('[data-sb-group-name]'); inputs[inputs.length - 1]?.select() });
            },
            removeGroup(i) {
                const groups = this.groups();
                if ((groups[i].items || []).length) return;
                groups.splice(i, 1);
                this.commit(groups);
            },
            setGroupLabel(i, value) { const groups = this.groups(); groups[i].label = value; this.commit(groups) },
            setItemLabel(gi, ii, value) {
                const groups = this.groups();
                const original = this.entry(groups[gi].items[ii].key).label;
                groups[gi].items[ii].label = (value.trim() === '' || value.trim() === original) ? null : value.trim();
                this.commit(groups);
                this.editing = null;
            },
            resetItem(gi, ii) { const groups = this.groups(); groups[gi].items[ii].label = null; this.commit(groups) },
        }"
    >
        <style>
            .sb { --sb-grad: linear-gradient(135deg, #2563eb, #7c3aed); --sb-blue: #2563eb; --sb-amber: #d97706;
                  --sb-page: #f5f7fb; --sb-card: #fff; --sb-line: #e5e7eb; --sb-soft: #f8fafc; --sb-text: #111827; --sb-muted: #6b7280;
                  --sb-rail: #ffffff; --sb-rail-line: #e5e7eb; --sb-rail-text: #334155; --sb-shadow: 0 12px 30px rgba(15,23,42,.08);
                  font-family: inherit; }
            .dark .sb { --sb-amber: #fbbf24; --sb-page: #0B1324; --sb-card: #1C293E; --sb-line: rgba(255,255,255,.08); --sb-soft: rgba(255,255,255,.04);
                  --sb-text: #f8fafc; --sb-muted: rgba(229,231,235,.6); --sb-rail: #0B1324; --sb-rail-line: rgba(255,255,255,.06);
                  --sb-rail-text: #cbd5e1; --sb-shadow: 0 18px 45px rgba(0,0,0,.35); }

            .sb-wrap { display: grid; grid-template-columns: minmax(0, 1fr) 290px; gap: 1.25rem; align-items: start; }
            @media (max-width: 1279px) { .sb-wrap { grid-template-columns: 1fr; } .sb-preview { position: static !important; } .sb-rail { max-height: 22rem; } }

            /* editor */
            .sb-groups { display: grid; gap: .9rem; }
            .sb-group { background: var(--sb-card); border: 1px solid var(--sb-line); border-radius: 18px; box-shadow: var(--sb-shadow); overflow: hidden; }
            .sb-ghead { display: flex; align-items: center; gap: .6rem; padding: .75rem .9rem; border-bottom: 1px solid var(--sb-line);
                        background: linear-gradient(135deg, rgba(37,99,235,.07), rgba(124,58,237,.07)); }
            .sb-grip { display: grid; place-items: center; width: 1.9rem; height: 1.9rem; border-radius: 9px; color: var(--sb-muted); cursor: grab; flex: none; }
            .sb-grip:hover { color: var(--sb-text); background: var(--sb-soft); }
            .sb-grip:active { cursor: grabbing; }
            .sb-grip svg, .sb-act svg { width: 1.05rem; height: 1.05rem; }
            .sb-gname { flex: 1; min-width: 0; background: transparent; border: 1px solid transparent; border-radius: 10px; padding: .35rem .55rem;
                        font-size: 1rem; font-weight: 800; color: var(--sb-amber); outline: none; box-shadow: none; }
            .sb-gname::placeholder { color: var(--sb-muted); font-weight: 600; }
            .sb-gname:hover { border-color: var(--sb-line); }
            .sb-gname:focus { border-color: var(--sb-blue); box-shadow: 0 0 0 3px rgba(37,99,235,.2); background: var(--sb-soft); }
            .sb-count { font-size: .72rem; font-weight: 800; color: #fff; background: var(--sb-grad); border-radius: 999px; padding: .15rem .6rem; flex: none; }
            .sb-act { display: grid; place-items: center; width: 1.9rem; height: 1.9rem; border-radius: 9px; border: 0; background: transparent; color: var(--sb-muted); cursor: pointer; flex: none; }
            .sb-act:hover { background: var(--sb-soft); color: var(--sb-text); }
            .sb-act.sb-del:hover { color: #ef4444; background: rgba(239,68,68,.1); }
            .sb-act:disabled { opacity: .3; cursor: not-allowed; }

            .sb-items { padding: .6rem; display: grid; gap: .4rem; min-height: 3.4rem; }
            .sb-empty { border: 1.5px dashed var(--sb-line); border-radius: 12px; padding: .8rem; text-align: center; font-size: .82rem; color: var(--sb-muted); }
            .sb-item { display: flex; align-items: center; gap: .55rem; padding: .45rem .5rem; border-radius: 12px; border: 1px solid transparent; background: var(--sb-soft); transition: border-color .15s, background .15s; }
            .sb-item:hover, .sb-item.sb-lit { border-color: rgba(37,99,235,.45); background: rgba(37,99,235,.08); }
            .sb-icon { display: grid; place-items: center; width: 2rem; height: 2rem; border-radius: 10px; background: var(--sb-card); border: 1px solid var(--sb-line); color: var(--sb-text); flex: none; }
            .sb-icon .sb-svg { width: 1.1rem; height: 1.1rem; }
            .sb-label { flex: 1; min-width: 0; display: flex; flex-direction: column; line-height: 1.25; }
            .sb-label b { font-size: .9rem; font-weight: 700; color: var(--sb-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .sb-label small { font-size: .72rem; color: var(--sb-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .sb-tag { font-size: .68rem; font-weight: 700; border-radius: 999px; padding: .1rem .5rem; flex: none; }
            .sb-tag-new { color: #7c3aed; background: rgba(124,58,237,.12); }
            .dark .sb-tag-new { color: #c4b5fd; }
            .sb-rename { flex: 1; min-width: 0; border: 1px solid var(--sb-blue); border-radius: 9px; padding: .35rem .55rem; font-size: .9rem; font-weight: 700;
                         background: var(--sb-card); color: var(--sb-text); outline: none; box-shadow: 0 0 0 3px rgba(37,99,235,.2); }

            .sb-ghost { opacity: .9; border: 1.5px dashed var(--sb-blue) !important; background: rgba(37,99,235,.12) !important; }
            .sb-ghost > * { visibility: hidden; }
            .sb-chosen { box-shadow: 0 14px 30px rgba(37,99,235,.35); }

            .sb-add { display: flex; align-items: center; justify-content: center; gap: .45rem; width: 100%; padding: .85rem; border-radius: 18px; cursor: pointer;
                      border: 1.5px dashed var(--sb-line); background: transparent; color: var(--sb-muted); font-weight: 700; font-size: .9rem; transition: .15s; }
            .sb-add:hover { border-color: transparent; background: var(--sb-grad); color: #fff; box-shadow: 0 10px 24px rgba(37,99,235,.3); }
            .sb-add svg { width: 1.1rem; height: 1.1rem; }

            /* live preview: the sidebar itself */
            .sb-preview { position: sticky; top: 5.5rem; }
            .sb-phead { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: .5rem; padding-inline: .2rem; }
            .sb-phead b { font-size: .9rem; color: var(--sb-text); }
            .sb-phead small { font-size: .74rem; color: var(--sb-muted); }
            .sb-rail { background: var(--sb-rail); border: 1px solid var(--sb-rail-line); border-radius: 20px; padding: .9rem .7rem; box-shadow: var(--sb-shadow);
                       max-height: calc(100vh - 9rem); overflow-y: auto; }
            .sb-rgroup + .sb-rgroup { margin-top: .9rem; }
            .sb-rtitle { font-size: .74rem; font-weight: 800; color: var(--sb-amber); padding: 0 .6rem .35rem; }
            .sb-ritem { display: flex; align-items: center; gap: .6rem; padding: .45rem .6rem; border-radius: 10px; font-size: .84rem; font-weight: 600; color: var(--sb-rail-text); transition: background .15s, color .15s; }
            .sb-ritem .sb-svg { width: 1.1rem; height: 1.1rem; flex: none; opacity: .85; }
            .sb-ritem span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .sb-ritem.sb-lit { background: var(--sb-grad); color: #fff; box-shadow: 0 8px 18px rgba(37,99,235,.3); }
            .sb-ritem.sb-lit .sb-svg { opacity: 1; }

            @media (prefers-reduced-motion: reduce) { .sb * { transition: none !important; } }
        </style>

        <div class="sb-wrap">
            {{-- editor --}}
            <div>
                <div class="sb-groups" x-init="sortGroups($el)">
                    <template x-for="(group, gi) in groups()" :key="id(group)">
                        <section class="sb-group" data-sb-group>
                            <header class="sb-ghead">
                                <span class="sb-grip" data-sb-group-grip title="اسحب لترتيب الجروب">
                                    <svg viewBox="0 0 20 20" fill="currentColor"><path d="M7 4a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Zm0 6a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Zm-1.5 7.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3ZM16 4a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Zm-1.5 7.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3ZM16 16a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Z"/></svg>
                                </span>
                                <input
                                    type="text"
                                    class="sb-gname"
                                    data-sb-group-name
                                    maxlength="60"
                                    placeholder="من غير عنوان"
                                    :value="group.label"
                                    @change="setGroupLabel(gi, $event.target.value.trim())"
                                    @keydown.enter.prevent="$event.target.blur()"
                                >
                                <span class="sb-count" x-text="(group.items || []).length"></span>
                                <button type="button" class="sb-act sb-del" @click="removeGroup(gi)" :disabled="(group.items || []).length > 0"
                                        :title="(group.items || []).length ? 'فضّي الجروب الأول (اسحب تاباته لجروب تاني)' : 'امسح الجروب'">
                                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.75 1A2.75 2.75 0 0 0 6 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 1 0 .23 1.482l.149-.022.841 10.518A2.75 2.75 0 0 0 7.596 19h4.807a2.75 2.75 0 0 0 2.742-2.53l.841-10.52.149.023a.75.75 0 0 0 .23-1.482A41.03 41.03 0 0 0 14 4.193V3.75A2.75 2.75 0 0 0 11.25 1h-2.5ZM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325C8.327 4.025 9.16 4 10 4ZM8.58 7.72a.75.75 0 0 0-1.5.06l.3 7.5a.75.75 0 1 0 1.5-.06l-.3-7.5Zm4.34.06a.75.75 0 1 0-1.5-.06l-.3 7.5a.75.75 0 1 0 1.5.06l.3-7.5Z" clip-rule="evenodd"/></svg>
                                </button>
                            </header>

                            <div class="sb-items" :data-group="gi" x-init="sortItems($el)">
                                <template x-for="(item, ii) in (group.items || [])" :key="id(item)">
                                    <div class="sb-item" data-sb-item :class="hover === item.key && 'sb-lit'"
                                         @mouseenter="hover = item.key" @mouseleave="hover = null">
                                        <span class="sb-grip" data-sb-grip title="اسحب لترتيب التاب أو انقله لجروب تاني">
                                            <svg viewBox="0 0 20 20" fill="currentColor"><path d="M7 4a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Zm0 6a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Zm-1.5 7.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3ZM16 4a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Zm-1.5 7.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3ZM16 16a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Z"/></svg>
                                        </span>
                                        <span class="sb-icon" x-html="entry(item.key).icon"></span>

                                        <template x-if="editing !== id(item)">
                                            <span class="sb-label" @dblclick="editing = id(item)">
                                                <b x-text="name(item)"></b>
                                                <small x-show="renamed(item)" x-text="'الاسم الأصلي: ' + entry(item.key).label"></small>
                                            </span>
                                        </template>
                                        <template x-if="editing === id(item)">
                                            <input type="text" class="sb-rename" maxlength="60"
                                                   :value="name(item)"
                                                   x-init="$nextTick(() => $el.select())"
                                                   @keydown.enter.prevent="setItemLabel(gi, ii, $event.target.value)"
                                                   @keydown.escape.prevent="editing = null"
                                                   @blur="setItemLabel(gi, ii, $event.target.value)">
                                        </template>

                                        <span class="sb-tag sb-tag-new" x-show="moved(item, group)" x-text="'من ' + (entry(item.key).group || 'فوق')"></span>
                                        <button type="button" class="sb-act" x-show="renamed(item)" @click="resetItem(gi, ii)" title="رجّع الاسم الأصلي">
                                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.793 2.232a.75.75 0 0 1-.025 1.06L3.622 7.25h10.003a5.375 5.375 0 0 1 0 10.75H10.75a.75.75 0 0 1 0-1.5h2.875a3.875 3.875 0 0 0 0-7.75H3.622l4.146 3.957a.75.75 0 0 1-1.036 1.085l-5.5-5.25a.75.75 0 0 1 0-1.085l5.5-5.25a.75.75 0 0 1 1.06.025Z" clip-rule="evenodd"/></svg>
                                        </button>
                                        <button type="button" class="sb-act" @click="editing = id(item)" title="غيّر الاسم">
                                            <svg viewBox="0 0 20 20" fill="currentColor"><path d="m5.433 13.917 1.262-3.155A4 4 0 0 1 7.58 9.42l6.92-6.918a2.121 2.121 0 0 1 3 3l-6.92 6.918c-.383.383-.84.685-1.343.886l-3.154 1.262a.5.5 0 0 1-.65-.65Z"/><path d="M3.5 5.75c0-.69.56-1.25 1.25-1.25H10A.75.75 0 0 0 10 3H4.75A2.75 2.75 0 0 0 2 5.75v9.5A2.75 2.75 0 0 0 4.75 18h9.5A2.75 2.75 0 0 0 17 15.25V10a.75.75 0 0 0-1.5 0v5.25c0 .69-.56 1.25-1.25 1.25h-9.5c-.69 0-1.25-.56-1.25-1.25v-9.5Z"/></svg>
                                        </button>
                                    </div>
                                </template>
                                <div class="sb-empty" x-show="! (group.items || []).length">اسحب تابات هنا</div>
                            </div>
                        </section>
                    </template>
                    <div data-sb-end></div>
                </div>

                <button type="button" class="sb-add" style="margin-top: .9rem" @click="addGroup()">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z"/></svg>
                    جروب جديد
                </button>
            </div>

            {{-- live preview --}}
            <aside class="sb-preview">
                <div class="sb-phead">
                    <b>شكل القايمة</b>
                    <small x-text="total() + ' تاب'"></small>
                </div>
                <nav class="sb-rail" aria-label="معاينة القايمة الجانبية">
                    <template x-for="group in groups()" :key="'p' + id(group)">
                        <div class="sb-rgroup" x-show="(group.items || []).length">
                            <div class="sb-rtitle" x-show="group.label" x-text="group.label"></div>
                            <template x-for="item in (group.items || [])" :key="'p' + id(item)">
                                <div class="sb-ritem" :class="hover === item.key && 'sb-lit'"
                                     @mouseenter="hover = item.key" @mouseleave="hover = null">
                                    <span x-html="entry(item.key).icon" style="display:contents"></span>
                                    <span x-text="name(item)"></span>
                                </div>
                            </template>
                        </div>
                    </template>
                </nav>
            </aside>
        </div>
    </div>
</x-dynamic-component>
