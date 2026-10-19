<x-filament-panels::page>
    @include('filament.resources.dynamic-forms.partials.builder-styles')

    <div class="fb" x-data="formBuilder(@js($this->payload()))" x-cloak
         @keydown.window.ctrl.s.prevent="save()" @keydown.window.meta.s.prevent="save()">

        {{-- top bar: name, state, actions --}}
        <header class="fb-bar">
            <div class="fb-bar-name">
                <input class="fb-title" x-model="form.name" @input="touch()" aria-label="اسم الفورم">
                <span class="fb-state" :class="stateClass()" x-text="stateText()"></span>
            </div>
            <div class="fb-bar-actions">
                <span class="fb-saved" x-show="savedAt && !dirty" x-text="'اتحفظ ' + savedAt"></span>
                <button type="button" class="fb-btn ghost" @click="preview()" :disabled="busy">معاينة</button>
                <button type="button" class="fb-btn ghost" @click="save()" :disabled="busy || !dirty">حفظ</button>
                <button type="button" class="fb-btn primary" @click="publish()" :disabled="busy">نشر</button>
            </div>
        </header>

        <div class="fb-problems" x-show="problems.length" x-transition>
            <strong>قبل النشر صلّح دول:</strong>
            <ul><template x-for="p in problems"><li x-text="p"></li></template></ul>
            <button type="button" class="fb-x" @click="problems = []" aria-label="إغلاق">✕</button>
        </div>

        <div class="fb-grid">
            {{-- palette --}}
            <aside class="fb-palette">
                <div class="fb-tabs">
                    <button type="button" :class="{on: paletteTab === 'system'}" @click="paletteTab = 'system'">من السيستم</button>
                    <button type="button" :class="{on: paletteTab === 'fields'}" @click="paletteTab = 'fields'">حقول عامة</button>
                </div>

                <div x-show="paletteTab === 'system'" class="fb-palette-list">
                    <p class="fb-hint">حقول جاهزة ومربوطة بمكانها في الطلب. دوس على أي واحد يتضاف.</p>
                    <template x-for="group in systemGroups()" :key="group.name">
                        <div class="fb-pgroup">
                            <h4 x-text="group.name"></h4>
                            <template x-for="b in group.items" :key="b.key">
                                <button type="button" class="fb-pitem" :class="{used: isBound(b.key)}" :disabled="isBound(b.key)"
                                        @click="addFromBinding(b)" :title="isBound(b.key) ? 'موجود في الفورم' : 'إضافة'">
                                    <span class="fb-ic" x-html="icons[b.types[0]]"></span>
                                    <span x-text="b.label"></span>
                                    <span class="fb-check" x-show="isBound(b.key)">✓</span>
                                </button>
                            </template>
                        </div>
                    </template>
                </div>

                <div x-show="paletteTab === 'fields'" class="fb-palette-list">
                    <template x-for="group in fieldGroups" :key="group.name">
                        <div class="fb-pgroup">
                            <h4 x-text="group.name"></h4>
                            <div class="fb-ptiles">
                                <template x-for="t in group.types" :key="t">
                                    <button type="button" class="fb-tile" @click="addField(t)">
                                        <span class="fb-ic" x-html="icons[t]"></span>
                                        <span x-text="shortType(t)"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </aside>

            {{-- canvas: the form as the customer sees it --}}
            <main class="fb-canvas" @click.self="select(null)">
                <div class="fb-phone" @click.self="select(null)">
                    <div class="fb-phone-head">
                        <strong x-text="form.name"></strong>
                        <div class="fb-phone-bar"><span></span></div>
                    </div>
                    <p class="fb-phone-intro" x-show="schema.settings.intro" x-text="schema.settings.intro"
                       :class="{sel: sel === null}" @click="select(null)"></p>

                    <template x-for="(section, s) in schema.sections" :key="section.key">
                        <section class="fb-step" :class="{sel: isSel(s, null)}">
                            <div class="fb-step-head" @click="select(s, null)">
                                <span class="fb-step-n" x-text="s + 1"></span>
                                <div class="fb-step-text">
                                    <h3 x-text="section.title || 'خطوة من غير اسم'"></h3>
                                    <p x-show="section.description" x-text="section.description"></p>
                                </div>
                                <span class="fb-chip cond" x-show="(section.conditions || []).length" x-text="condText(section)"></span>
                                <div class="fb-tools">
                                    <button type="button" @click.stop="moveSection(s, -1)" :disabled="s === 0" title="لفوق">↑</button>
                                    <button type="button" @click.stop="moveSection(s, 1)" :disabled="s === schema.sections.length - 1" title="لتحت">↓</button>
                                </div>
                            </div>

                            <div class="fb-items" @dragover.prevent @drop.prevent="dropOn(s, section.items.length)">
                                <template x-for="(item, i) in section.items" :key="item.data.key">
                                    <div class="fb-item" :class="{sel: isSel(s, i), dragging: drag && drag.s === s && drag.i === i, 'drop-before': dropHint && dropHint.s === s && dropHint.i === i}"
                                         draggable="true"
                                         @dragstart="drag = {s, i}" @dragend="drag = null; dropHint = null"
                                         @dragover.prevent.stop="dropHint = {s, i}" @drop.prevent.stop="dropOn(s, i)"
                                         @click.stop="select(s, i)">
                                        <span class="fb-grip" title="اسحب لترتيب">⋮⋮</span>
                                        <div class="fb-item-body">
                                            @include('filament.resources.dynamic-forms.partials.canvas-item')
                                            <div class="fb-chips">
                                                <span class="fb-chip bind" x-show="item.data.binding" x-text="'مربوط: ' + bindingLabel(item.data.binding)" :title="bindingLabel(item.data.binding)"></span>
                                                <span class="fb-chip cond" x-show="(item.data.conditions || []).length" x-text="condText(item.data, item.type === 'stop')" :title="condText(item.data, item.type === 'stop')"></span>
                                                <span class="fb-chip rule" x-show="ruleText(item)" x-text="ruleText(item)"></span>
                                                <span class="fb-chip ai" x-show="item.type === 'document' && item.data.instructions">فحص AI بتعليماتك</span>
                                            </div>
                                        </div>
                                        <div class="fb-tools">
                                            <button type="button" @click.stop="moveItem(s, i, -1)" title="لفوق">↑</button>
                                            <button type="button" @click.stop="moveItem(s, i, 1)" title="لتحت">↓</button>
                                            <button type="button" @click.stop="duplicate(s, i)" title="نسخة">⧉</button>
                                            <button type="button" class="danger" @click.stop="removeItem(s, i)" title="مسح">✕</button>
                                        </div>
                                    </div>
                                </template>
                                <div class="fb-empty" x-show="!section.items.length">
                                    الخطوة دي فاضية. اختار حقل من العمود اليمين وهيتضاف هنا.
                                </div>
                            </div>
                        </section>
                    </template>

                    <button type="button" class="fb-add-step" @click="addSection()">+ خطوة جديدة</button>
                    <div class="fb-phone-submit">إرسال الطلب</div>
                </div>
            </main>

            {{-- inspector --}}
            <aside class="fb-inspector">
                @include('filament.resources.dynamic-forms.partials.inspector')
            </aside>
        </div>
    </div>

    @include('filament.resources.dynamic-forms.partials.builder-script')
</x-filament-panels::page>
