{{-- ===== a field ===== --}}
<template x-if="current()">
    <div class="ins" x-data>
        <div class="ins-head">
            <span class="fb-ic" x-html="icons[current().type]"></span>
            <div>
                <small x-text="types[current().type]"></small>
                <strong x-text="current().data.label || current().data.message || types[current().type]"></strong>
            </div>
            <button type="button" class="ins-close" @click="select(sel.s, null)" title="رجوع للخطوة">✕</button>
        </div>

        {{-- the question --}}
        <div class="ins-block">
            <template x-if="!['note', 'stop'].includes(current().type)">
                <div class="ins-stack">
                    <label class="ins-field">
                        <span>السؤال</span>
                        <input class="ins-input big" x-model="current().data.label" @input="touch()" placeholder="مثلاً: الرقم القومي (14 رقم)">
                    </label>
                    <label class="ins-field">
                        <span>شرح صغير تحته <em>اختياري</em></span>
                        <input class="ins-input" x-model="current().data.help" @input="touch()" placeholder="مثلاً: زي ما هو مكتوب في البطاقة">
                    </label>
                    <label class="ins-field" x-show="['text', 'textarea', 'number', 'money', 'phone', 'national_id'].includes(current().type)">
                        <span>مثال جوه الخانة <em>اختياري</em></span>
                        <input class="ins-input" x-model="current().data.placeholder" @input="touch()">
                    </label>
                    <label class="ins-switch">
                        <input type="checkbox" x-model="current().data.required" @change="touch()">
                        <span class="ins-track"><span></span></span>
                        <span>العميل لازم يجاوبه</span>
                    </label>
                </div>
            </template>
            <template x-if="['note', 'stop'].includes(current().type)">
                <label class="ins-field">
                    <span x-text="current().type === 'stop' ? 'الرسالة اللي تظهر وتوقف التقديم' : 'النص اللي يظهر للعميل'"></span>
                    <textarea class="ins-input" rows="3" x-model="current().data.message" @input="touch()"></textarea>
                    <small class="ins-note" x-show="current().type === 'stop'">الرسالة دي بتظهر بس لما الشروط تحت تتحقق، ووقتها العميل مش هيقدر يبعت الطلب.</small>
                </label>
            </template>
        </div>

        {{-- choices --}}
        <template x-if="['select', 'radio', 'checkbox'].includes(current().type)">
            <div class="ins-block">
                <h5>الاختيارات</h5>
                <template x-for="(o, k) in current().data.options" :key="k">
                    <div class="ins-option">
                        <input class="ins-input" x-model="o.label" @input="optionLabel(o)" placeholder="النص اللي يظهر">
                        <select class="ins-input slim" x-show="current().data.binding === 'work.status'" x-model="o.customer_type_id" @change="touch()" title="نوع العميل">
                            <option value="">نوع العميل...</option>
                            <template x-for="(label, id) in customerTypes"><option :value="id" x-text="label" :selected="String(o.customer_type_id) === String(id)"></option></template>
                        </select>
                        <button type="button" class="ins-icon" @click="current().data.options.splice(k, 1); touch()" title="مسح">✕</button>
                        <div class="ins-multi ins-worktypes" x-show="current().data.binding === 'work.status'">
                            <small>البوت يختاره لو قال إنه:</small>
                            <template x-for="(label, wt) in workTypes" :key="wt">
                                <button type="button" :class="{on: (o.work_types || []).includes(wt)}" @click="toggleWorkType(o, wt)" x-text="label"></button>
                            </template>
                        </div>
                    </div>
                </template>
                <button type="button" class="ins-add" @click="(current().data.options ||= []).push({label: '', value: ''}); touch()">+ اختيار</button>
                <small class="ins-note" x-show="current().data.binding === 'work.status'">كل اختيار بيحدد نوع العميل والورق المطلوب منه. ولو اتنين بنفس النوع (زي الدليفري والشغل الحر)، علّم كل واحد بيغطي إيه، عشان البوت يختاره للعميل من كلامه.</small>
            </div>
        </template>

        {{-- address --}}
        <template x-if="current().type === 'address'">
            <div class="ins-block">
                <h5>أجزاء العنوان</h5>
                <p class="ins-note">دوس على الجزء: مخفي ← يظهر ← إجباري.</p>
                <div class="ins-parts">
                    <template x-for="(label, part) in addressParts" :key="part">
                        <button type="button" class="ins-part" :class="partState(part)" @click="cyclePart(part)">
                            <span x-text="label"></span><b x-show="partState(part) === 'required'">*</b>
                        </button>
                    </template>
                </div>
            </div>
        </template>

        {{-- document --}}
        <template x-if="current().type === 'document'">
            <div class="ins-block">
                <h5>المستند والفحص</h5>
                <label class="ins-field">
                    <span>الـ AI يتأكد إنه</span>
                    <select class="ins-input" x-model="current().data.document_type" @change="touch()">
                        <option value="">من غير فحص نوع</option>
                        <template x-for="(label, key) in documentTypes"><option :value="key" x-text="label" :selected="current().data.document_type === key"></option></template>
                    </select>
                </label>
                <div class="ins-row">
                    <label class="ins-field">
                        <span>أقصى عدد ملفات</span>
                        <input class="ins-input" type="number" min="1" max="10" x-model="current().data.max_files" @input="touch()">
                    </label>
                    <label class="ins-field">
                        <span>يقبل</span>
                        <select class="ins-input" x-model="current().data.accept" @change="touch()">
                            <option value="images_pdf">صور و PDF</option>
                            <option value="images">صور بس</option>
                            <option value="video">صور وفيديو</option>
                        </select>
                    </label>
                </div>
                <label class="ins-field">
                    <span>تعليماتك للـ AI <em>اختياري</em></span>
                    <textarea class="ins-input" rows="3" x-model="current().data.instructions" @input="touch()"
                              placeholder="اكتبها زي ما بتشرح لموظف: لازم يكون عليها ختم جهة العمل، ومش أقدم من 3 شهور."></textarea>
                    <small class="ins-note">لو الصورة مش مطابقة العميل يشوف تنبيه وهو بيرفع، والطلب بيوصل عادي وعليه علامة للموظف.</small>
                </label>
            </div>
        </template>

        {{-- group --}}
        <template x-if="current().type === 'group'">
            <div class="ins-block">
                <h5>جوه كل واحد</h5>
                <template x-for="(sub, k) in current().data.fields" :key="sub.key">
                    <div class="ins-sub">
                        <input class="ins-input" x-model="sub.label" @input="touch()" placeholder="السؤال">
                        <select class="ins-input slim" x-model="sub.type" @change="touch()">
                            <option value="text">نص</option><option value="phone">موبايل</option>
                            <option value="national_id">رقم قومي</option><option value="number">رقم</option>
                        </select>
                        <select class="ins-input slim" x-model="sub.maps_to" @change="touch()" title="يتربط بـ">
                            <option value="">مش مربوط</option><option value="name">الاسم</option><option value="phone">الموبايل</option>
                            <option value="national_id">الرقم القومي</option><option value="address">العنوان</option>
                        </select>
                        <label class="ins-mini"><input type="checkbox" x-model="sub.required" @change="touch()"> إجباري</label>
                        <button type="button" class="ins-icon" @click="current().data.fields.splice(k, 1); touch()">✕</button>
                    </div>
                </template>
                <button type="button" class="ins-add" @click="(current().data.fields ||= []).push({key: uid('g'), label: '', type: 'text', required: true}); touch()">+ سؤال</button>
                <div class="ins-sentence">
                    من <input class="ins-num" type="number" min="0" x-model="current().data.min_items" @input="touch()">
                    لـ <input class="ins-num" type="number" min="1" x-model="current().data.max_items" @input="touch()"> واحد
                </div>
            </div>
        </template>

        {{-- rules --}}
        <template x-if="['text', 'textarea', 'number', 'money', 'national_id', 'date'].includes(current().type)">
            <div class="ins-block">
                <h5>الشروط</h5>
                <template x-if="current().type === 'national_id'">
                    <div class="ins-stack">
                        <div class="ins-sentence">السن من <input class="ins-num" type="number" x-model="rules().min_age" @input="touch()"> لـ <input class="ins-num" type="number" x-model="rules().max_age" @input="touch()"> سنة</div>
                        <div class="ins-seg">
                            <button type="button" :class="{on: !rules().gender}" @click="rules().gender = ''; touch()">الكل</button>
                            <button type="button" :class="{on: rules().gender === 'male'}" @click="rules().gender = 'male'; touch()">رجالة بس</button>
                            <button type="button" :class="{on: rules().gender === 'female'}" @click="rules().gender = 'female'; touch()">ستات بس</button>
                        </div>
                    </div>
                </template>
                <template x-if="['text', 'textarea'].includes(current().type)">
                    <div class="ins-stack">
                        <div class="ins-sentence">عدد الحروف من <input class="ins-num" type="number" x-model="rules().min_length" @input="touch()"> لـ <input class="ins-num" type="number" x-model="rules().max_length" @input="touch()"></div>
                        <label class="ins-switch"><input type="checkbox" x-model="rules().digits_only" @change="touch()"><span class="ins-track"><span></span></span><span>أرقام بس</span></label>
                    </div>
                </template>
                <template x-if="['number', 'money'].includes(current().type)">
                    <div class="ins-sentence">القيمة من <input class="ins-num wide" type="number" x-model="rules().min" @input="touch()"> لـ <input class="ins-num wide" type="number" x-model="rules().max" @input="touch()"></div>
                </template>
                <template x-if="current().type === 'date'">
                    <div class="ins-sentence">من <input class="ins-input slim" type="date" x-model="rules().min" @input="touch()"> لـ <input class="ins-input slim" type="date" x-model="rules().max" @input="touch()"></div>
                </template>
                <label class="ins-field">
                    <span>رسالة الخطأ <em>اختياري - فاضي = رسالة جاهزة</em></span>
                    <input class="ins-input" x-model="current().data.error_message" @input="touch()">
                </label>
            </div>
        </template>

        {{-- show if --}}
        @include('filament.resources.dynamic-forms.partials.conditions', ['target' => 'current().data'])

        {{-- binding --}}
        <template x-if="!['note', 'stop'].includes(current().type)">
            <div class="ins-block">
                <h5>يتسجل فين في الطلب</h5>
                <select class="ins-input" x-model="current().data.binding" @change="touch()">
                    <option value="">بيانات إضافية (تظهر للموظف تحت الطلب)</option>
                    <template x-for="group in bindingGroupsFor(current().type)" :key="group.name">
                        <optgroup :label="group.name">
                            <template x-for="b in group.items" :key="b.key">
                                <option :value="b.key" x-text="b.label + (isBound(b.key, current()) ? ' (مستخدم)' : '')"
                                        :disabled="isBound(b.key, current())" :selected="current().data.binding === b.key"></option>
                            </template>
                        </optgroup>
                    </template>
                </select>
            </div>
        </template>

        <details class="ins-advanced">
            <summary>متقدم</summary>
            <label class="ins-field">
                <span>المفتاح الداخلي</span>
                <input class="ins-input mono" x-model="current().data.key" @input="touch()" dir="ltr">
                <small class="ins-note">بيستخدم في الشروط. متغيروش بعد النشر.</small>
            </label>
        </details>
    </div>
</template>

{{-- ===== a step ===== --}}
<template x-if="!current() && sel !== null && sel.i === null && schema.sections[sel.s]">
    <div class="ins" x-data>
        <div class="ins-head">
            <span class="fb-step-n" x-text="sel.s + 1"></span>
            <div><small>خطوة</small><strong x-text="schema.sections[sel.s].title || 'خطوة من غير اسم'"></strong></div>
        </div>
        <div class="ins-block ins-stack">
            <label class="ins-field"><span>عنوان الخطوة</span>
                <input class="ins-input big" x-model="schema.sections[sel.s].title" @input="touch()"></label>
            <label class="ins-field"><span>كلمة تحت العنوان <em>اختياري</em></span>
                <input class="ins-input" x-model="schema.sections[sel.s].description" @input="touch()"></label>
        </div>
        @include('filament.resources.dynamic-forms.partials.conditions', ['target' => 'schema.sections[sel.s]'])
        <div class="ins-block">
            <button type="button" class="ins-danger" @click="removeSection(sel.s)">مسح الخطوة دي بكل حقولها</button>
        </div>
    </div>
</template>

{{-- ===== the form ===== --}}
<template x-if="sel === null">
    <div class="ins">
        <div class="ins-head"><div><small>إعدادات</small><strong>الفورم كلها</strong></div></div>
        <div class="ins-block ins-stack">
            <label class="ins-field"><span>كلمة في أول الفورم للعميل</span>
                <textarea class="ins-input" rows="3" x-model="schema.settings.intro" @input="touch()"></textarea></label>
            <label class="ins-switch"><input type="checkbox" x-model="form.is_bot_default" @change="touch()"><span class="ins-track"><span></span></span><span>دي الفورم اللي البوت يبعتها</span></label>
            <label class="ins-switch"><input type="checkbox" x-model="form.is_active" @change="touch()"><span class="ins-track"><span></span></span><span>شغالة</span></label>
            <label class="ins-field"><span>نوع عميل ثابت <em>لو مفيش سؤال نوع الشغل</em></span>
                <select class="ins-input" x-model="schema.settings.customer_type_id" @change="touch()">
                    <option value="">من سؤال نوع الشغل</option>
                    <template x-for="(label, id) in customerTypes"><option :value="id" x-text="label" :selected="String(schema.settings.customer_type_id) === String(id)"></option></template>
                </select></label>
            <label class="ins-field"><span>وصف داخلي للموظفين</span>
                <textarea class="ins-input" rows="2" x-model="form.description" @input="touch()"></textarea></label>
        </div>
        <div class="ins-block ins-tip">
            <strong>إزاي تبني</strong>
            <p>اختار حقل من العمود اليمين، أو دوس على أي سؤال في الفورم عشان تعدّله. اسحب السؤال من <b>⋮⋮</b> عشان ترتّبه. بعد ما تخلص دوس <b>نشر</b> والروابط الجديدة هتتبعت بيها.</p>
        </div>
    </div>
</template>
