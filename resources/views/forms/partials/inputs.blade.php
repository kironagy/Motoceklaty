{{-- One input per field type. answers[key] holds the value; save(key) autosaves it. --}}
<template x-if="['text', 'phone', 'national_id', 'number', 'money'].includes(item.type)">
    <input class="input" :id="'in-' + item.key"
           :type="item.type === 'text' ? 'text' : 'text'"
           :inputmode="['phone', 'national_id', 'number', 'money'].includes(item.type) ? 'numeric' : 'text'"
           :placeholder="item.placeholder || ''"
           :maxlength="item.type === 'national_id' ? 14 : (item.type === 'phone' ? 11 : (item.rules?.max_length || 500))"
           x-model="answers[item.key]" @input="queue(item.key)" @blur="save(item.key)">
</template>

<template x-if="item.type === 'textarea'">
    <textarea class="input" rows="3" :id="'in-' + item.key" :placeholder="item.placeholder || ''"
              x-model="answers[item.key]" @input="queue(item.key)" @blur="save(item.key)"></textarea>
</template>

<template x-if="item.type === 'date'">
    <input class="input" type="date" :id="'in-' + item.key" x-model="answers[item.key]" @change="save(item.key)">
</template>

<template x-if="item.type === 'select'">
    <select class="input" :id="'in-' + item.key" x-model="answers[item.key]" @change="save(item.key)">
        <option value="">اختار...</option>
        <template x-for="o in item.options" :key="o.value">
            <option :value="o.value" x-text="o.label" :selected="String(answers[item.key]) === String(o.value)"></option>
        </template>
    </select>
</template>

<template x-if="item.type === 'radio'">
    <div class="choices">
        <template x-for="o in item.options" :key="o.value">
            <button type="button" class="choice" :class="{on: String(answers[item.key]) === String(o.value)}"
                    @click="answers[item.key] = o.value; save(item.key)" x-text="o.label"></button>
        </template>
    </div>
</template>

<template x-if="item.type === 'checkbox'">
    <div class="choices">
        <template x-for="o in item.options" :key="o.value">
            <button type="button" class="choice" :class="{on: (answers[item.key] || []).map(String).includes(String(o.value))}"
                    @click="toggle(item.key, o.value)" x-text="o.label"></button>
        </template>
    </div>
</template>

<template x-if="item.type === 'yes_no'">
    <div class="choices">
        <button type="button" class="choice" :class="{on: answers[item.key] === 'yes'}" @click="answers[item.key] = 'yes'; save(item.key)">نعم</button>
        <button type="button" class="choice" :class="{on: answers[item.key] === 'no'}" @click="answers[item.key] = 'no'; save(item.key)">لا</button>
    </div>
</template>

<template x-if="item.type === 'address'">
    <div class="address">
        <template x-for="part in addressParts(item)" :key="part.key">
            <div class="address-part" :class="{wide: ['street', 'landmark'].includes(part.key)}">
                <small x-text="part.label"></small>
                <template x-if="part.key === 'governorate'">
                    <select class="input" x-model="answers[item.key][part.key]" @change="save(item.key)">
                        <option value="">اختار...</option>
                        <template x-for="g in governorates" :key="g"><option :value="g" x-text="g" :selected="answers[item.key][part.key] === g"></option></template>
                    </select>
                </template>
                <template x-if="part.key !== 'governorate'">
                    <input class="input" type="text" x-model="answers[item.key][part.key]" @input="queue(item.key)" @blur="save(item.key)">
                </template>
            </div>
        </template>
    </div>
</template>

<template x-if="item.type === 'machine'">
    <div>
        <input class="input" type="search" placeholder="ابحث باسم المكنة..." x-model="machineSearch[item.key]">
        <div class="machines">
            <template x-for="m in machineOptions(item.key)" :key="m.id">
                <button type="button" class="machine" :class="{on: String(answers[item.key]) === String(m.id)}" @click="pickMachine(item.key, m.id)">
                    <img :src="m.image" x-show="m.image" alt="" loading="lazy">
                    <span x-text="m.name"></span>
                </button>
            </template>
        </div>
    </div>
</template>

<template x-if="item.type === 'plan'">
    <div>
        <p class="muted" x-show="!plans.length">اختار المكنة الأول وهتظهرلك مدد القسط.</p>
        <p class="muted" x-show="plans.length && !plansMeta.job_known">القسط ده مبدئي، وبيتحسب بالظبط على حسب شغلك.</p>
        <div class="cap-note" x-show="plansMeta.capped">
            المكنة دي أعلى من المبلغ اللي جهة التمويل بتموله لشغلك، فجزء من تمنها بيتدفع كاش وقت الاستلام (المقدم اللي تحت)، والباقي بيتقسط.
        </div>
        <div class="plans">
            <template x-for="p in plans" :key="p.id">
                <button type="button" class="plan" :class="{on: String(answers[item.key]) === String(p.id)}" @click="answers[item.key] = p.id; save(item.key)">
                    <strong x-text="`${p.months} شهر`"></strong>
                    <span x-show="p.monthly" x-text="`القسط ${money(p.monthly)} ج في الشهر`"></span>
                    <small x-show="p.down_payment" :class="{cash: p.capped}" x-text="p.capped ? `مقدم كاش ${money(p.down_payment)} ج` : `مقدم ${money(p.down_payment)} ج`"></small>
                    <small class="muted" x-show="p.upfront" x-text="`تدفع وقت الاستلام ${money(p.upfront)} ج (المقدم والمصاريف)`"></small>
                </button>
            </template>
        </div>
    </div>
</template>

<template x-if="['document', 'images'].includes(item.type)">
    {{-- uploads: a drop zone, then a grid of thumbnails with their check status --}}
    <div class="up" :class="{ 'up-drag': dragOver[item.key], 'up-has': filesOf(item.key).length || pendingOf(item.key).length }"
         @dragover.prevent="dragOver[item.key] = true" @dragleave.prevent="dragOver[item.key] = false"
         @drop.prevent="dragOver[item.key] = false; upload(item, $event)">

        <div class="up-grid" x-show="filesOf(item.key).length || pendingOf(item.key).length">
            <template x-for="f in filesOf(item.key)" :key="f.id">
                <div class="up-tile" :class="'is-' + f.status">
                    <button type="button" class="up-thumb" @click="f.is_image ? (lightbox = f.url) : window.open(f.url, '_blank')">
                        <img :src="f.url" x-show="f.is_image" alt="" loading="lazy">
                        <span class="up-doc" x-show="!f.is_image">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/></svg>
                            <small x-text="f.name || 'ملف'"></small>
                        </span>
                    </button>
                    <span class="up-badge" x-show="f.status === 'checking'"><i class="spin"></i></span>
                    <span class="up-badge ok" x-show="f.status === 'ok' || f.status === 'failed'">✓</span>
                    <span class="up-badge warn" x-show="f.status === 'warning'">!</span>
                    <button type="button" class="up-x" @click="removeFile(f)" aria-label="مسح">×</button>
                    <p class="up-msg" x-show="f.status === 'warning'" x-text="f.message || 'راجع الصورة دي'"></p>
                    <p class="up-msg info" x-show="f.status === 'checking'">بنراجعها...</p>
                </div>
            </template>
            <template x-for="p in pendingOf(item.key)" :key="p.id">
                <div class="up-tile is-uploading">
                    <div class="up-thumb">
                        <img :src="p.preview" x-show="p.preview" alt="">
                        <span class="up-doc" x-show="!p.preview"><small x-text="p.name"></small></span>
                    </div>
                    <div class="up-bar"><span :style="`width: ${p.progress}%`"></span></div>
                </div>
            </template>
        </div>

        <div class="up-zone" x-show="canAddFiles(item)">
            <div class="up-icon">
                <svg x-show="item.type === 'images'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.6"/><path d="m21 15-5-5L5 21"/></svg>
                <svg x-show="item.type !== 'images'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
            </div>
            <strong x-text="zoneTitle(item)"></strong>
            <small x-text="zoneHint(item)"></small>
            <div class="up-actions">
                <label class="up-btn primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/></svg>
                    <span x-text="item.type === 'images' ? 'اختار صور' : 'اختار من الموبايل'"></span>
                    <input type="file" hidden :accept="acceptFor(item)" :multiple="maxFiles(item) > 1" @change="upload(item, $event)">
                </label>
                <label class="up-btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    صوّر
                    <input type="file" hidden accept="image/*" capture="environment" @change="upload(item, $event)">
                </label>
            </div>
        </div>

        <div class="up-count" x-show="maxFiles(item) > 1 && filesOf(item.key).length">
            <span x-text="`${filesOf(item.key).length} من ${maxFiles(item)}`"></span>
            <span x-show="item.type === 'images' && minFiles(item) > filesOf(item.key).length" class="warn"
                  x-text="`فاضل ${minFiles(item) - filesOf(item.key).length} على الأقل`"></span>
        </div>
    </div>
</template>

<template x-if="item.type === 'group'">
    <div class="group">
        <template x-for="(row, r) in answers[item.key]" :key="r">
            <div class="group-row">
                <div class="group-head">
                    <strong x-text="`${item.label} ${r + 1}`"></strong>
                    <button type="button" class="remove" x-show="answers[item.key].length > Math.max(1, +(item.min_items || 0))" @click="answers[item.key].splice(r, 1); save(item.key)">مسح</button>
                </div>
                <template x-for="sub in item.fields" :key="sub.key">
                    <div class="group-field">
                        <small><span x-text="sub.label"></span><b class="req" x-show="sub.required">*</b></small>
                        <input class="input" type="text" :inputmode="['phone', 'national_id', 'number'].includes(sub.type) ? 'numeric' : 'text'"
                               :maxlength="sub.type === 'national_id' ? 14 : (sub.type === 'phone' ? 11 : 300)"
                               x-model="row[sub.key]" @input="queue(item.key)" @blur="save(item.key)">
                    </div>
                </template>
            </div>
        </template>
        <button type="button" class="btn small ghost" x-show="answers[item.key].length < (+item.max_items || 5)"
                @click="answers[item.key].push({})" x-text="item.add_label || 'إضافة واحد كمان'"></button>
    </div>
</template>
