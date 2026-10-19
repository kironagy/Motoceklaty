{{-- One field drawn the way the customer sees it (not editable here - the inspector edits it). --}}
<template x-if="item.type === 'note'">
    <div class="pv-note" x-text="item.data.message || 'نص توضيحي - اكتبه من الإعدادات'"></div>
</template>
<template x-if="item.type === 'stop'">
    <div class="pv-stop">
        <span x-text="item.data.message || 'رسالة توقف التقديم - اكتبها من الإعدادات'"></span>
    </div>
</template>

<template x-if="!['note', 'stop'].includes(item.type)">
    <div>
        <label class="pv-label">
            <span x-text="item.data.label || 'سؤال من غير عنوان'" :class="{muted: !item.data.label}"></span>
            <b x-show="item.data.required">*</b>
        </label>
        <p class="pv-help" x-show="item.data.help" x-text="item.data.help"></p>

        <template x-if="['text', 'phone', 'national_id', 'number', 'money', 'date'].includes(item.type)">
            <div class="pv-input" x-text="item.data.placeholder || placeholderFor(item.type)"></div>
        </template>
        <template x-if="item.type === 'textarea'">
            <div class="pv-input tall" x-text="item.data.placeholder || ''"></div>
        </template>
        <template x-if="item.type === 'select'">
            <div class="pv-input pv-select">اختار...</div>
        </template>
        <template x-if="['radio', 'checkbox'].includes(item.type)">
            <div class="pv-choices">
                <template x-for="o in (item.data.options || [])"><span class="pv-choice" x-text="o.label || '...'"></span></template>
                <span class="pv-choice ghost" x-show="!(item.data.options || []).length">ضيف اختيارات</span>
            </div>
        </template>
        <template x-if="item.type === 'yes_no'">
            <div class="pv-choices"><span class="pv-choice">نعم</span><span class="pv-choice">لا</span></div>
        </template>
        <template x-if="item.type === 'address'">
            <div class="pv-address">
                <template x-for="part in (item.data.address_parts || Object.keys(addressParts))">
                    <span class="pv-input small" :class="{wide: ['street', 'landmark'].includes(part)}" x-text="addressParts[part]"></span>
                </template>
            </div>
        </template>
        <template x-if="item.type === 'document'">
            <div class="pv-upload">
                <span class="pv-upload-btn">📷 صوّر</span><span class="pv-upload-btn ghost">📁 من الموبايل</span>
                <small x-show="(+item.data.max_files || 1) > 1" x-text="'لحد ' + item.data.max_files + ' ملفات'"></small>
            </div>
        </template>
        <template x-if="item.type === 'machine'">
            <div class="pv-machines"><span></span><span></span><span></span></div>
        </template>
        <template x-if="item.type === 'plan'">
            <div class="pv-plans"><span>12 شهر</span><span>18 شهر</span><span>24 شهر</span><span>36 شهر</span></div>
        </template>
        <template x-if="item.type === 'group'">
            <div class="pv-group">
                <template x-for="sub in (item.data.fields || [])"><span class="pv-input small wide" x-text="sub.label || '...'"></span></template>
                <span class="pv-add" x-text="'+ ' + (item.data.add_label || 'إضافة واحد كمان')"></span>
            </div>
        </template>
    </div>
</template>
