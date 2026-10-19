{{-- "يظهر بس لو" as sentences, on a field or a step ($target is the Alpine path of the object). --}}
<div class="ins-block">
    <h5>
        <span x-text="current() && current().type === 'stop' ? 'الرسالة تظهر لو' : 'يظهر بس لو'"></span>
        <em x-show="!({{ $target }}.conditions || []).length">دايمًا ظاهر</em>
    </h5>

    <div class="ins-seg small" x-show="({{ $target }}.conditions || []).length > 1">
        <button type="button" :class="{on: ({{ $target }}.match || 'all') === 'all'}" @click="{{ $target }}.match = 'all'; touch()">كل الشروط</button>
        <button type="button" :class="{on: {{ $target }}.match === 'any'}" @click="{{ $target }}.match = 'any'; touch()">أي شرط منهم</button>
    </div>

    <template x-for="(c, k) in ({{ $target }}.conditions || [])" :key="k">
        <div class="ins-cond">
            <select class="ins-input" x-model="c.field" @change="c.value = ''; c.prop = ''; touch()">
                <option value="">اختار سؤال...</option>
                <template x-for="f in earlierFields()" :key="f.key">
                    <option :value="f.key" x-text="f.label" :selected="c.field === f.key"></option>
                </template>
            </select>

            <template x-if="fieldByKey(c.field)?.type === 'national_id'">
                <select class="ins-input slim" x-model="c.prop" @change="touch()">
                    <option value="age">السن</option>
                    <option value="gender">النوع</option>
                    <option value="governorate">المحافظة</option>
                </select>
            </template>

            <select class="ins-input slim" x-model="c.operator" @change="touch()">
                <template x-for="(label, op) in operatorsFor(c)"><option :value="op" x-text="label" :selected="c.operator === op"></option></template>
            </select>

            <template x-if="!['filled', 'empty'].includes(c.operator)">
                <div class="ins-cond-value">
                    <template x-if="valueChoices(c).length && c.operator === 'in'">
                        <div class="ins-multi">
                            <template x-for="o in valueChoices(c)">
                                <button type="button" :class="{on: inList(c, o.value)}" @click="toggleIn(c, o.value)" x-text="o.label"></button>
                            </template>
                        </div>
                    </template>
                    <template x-if="valueChoices(c).length && c.operator !== 'in'">
                        <select class="ins-input" x-model="c.value" @change="touch()">
                            <option value="">...</option>
                            <template x-for="o in valueChoices(c)"><option :value="o.value" x-text="o.label" :selected="String(c.value) === String(o.value)"></option></template>
                        </select>
                    </template>
                    <template x-if="!valueChoices(c).length">
                        <input class="ins-input" x-model="c.value" @input="touch()" :placeholder="c.operator === 'in' ? 'قيم بينها فاصلة' : 'القيمة'">
                    </template>
                </div>
            </template>

            <button type="button" class="ins-icon" @click="{{ $target }}.conditions.splice(k, 1); touch()" title="مسح الشرط">✕</button>
        </div>
    </template>

    <button type="button" class="ins-add" @click="({{ $target }}.conditions ||= []).push({field: '', operator: 'equals', value: ''}); touch()">+ شرط</button>
</div>
