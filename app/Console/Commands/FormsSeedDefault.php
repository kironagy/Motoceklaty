<?php

namespace App\Console\Commands;

use App\Domain\Forms\FormPublisher;
use App\Models\CustomerType;
use App\Models\DynamicForm;
use Illuminate\Console\Command;

/**
 * Dynamic Links: the first application form, built from the rules the bot enforced in the chat
 * (owner's papers per job, age 21-62, Egyptians only, no woman on free income, guarantor on a
 * pension). The owner edits it from the dashboard afterwards; this never overwrites a form.
 */
class FormsSeedDefault extends Command
{
    protected $signature = 'forms:seed-default {--publish : publish it right away} {--refresh : rewrite the starter form with the current version of this layout (old links keep their version)}';

    protected $description = 'Create the starter application form from the current application rules';

    public function handle(FormPublisher $publisher): int
    {
        $existing = DynamicForm::where('name', 'طلب تقسيط موتوسيكل')->first();

        if ($existing && ! $this->option('refresh')) {
            $this->info('The starter form already exists - edit it from the dashboard (or --refresh).');

            return self::SUCCESS;
        }

        $type = fn (string $key) => CustomerType::where('key', $key)->value('id');
        $is = fn (string $field, string $values) => [['field' => $field, 'operator' => 'in', 'value' => $values]];

        $form = $existing ?? new DynamicForm();
        $form->fill([
            'name' => 'طلب تقسيط موتوسيكل',
            'description' => 'الفورم الأولى - مبنية من قواعد التقديم الحالية.',
            'is_bot_default' => true,
            'is_active' => true,
            'schema' => $this->arranged([
                'settings' => ['intro' => 'املا بياناتك وارفع الورق المطلوب، وكل اللي بتكتبه بيتحفظ لوحده. لو وقفت في حاجة كلّمنا على الواتساب.'],
                'sections' => [
                    ['key' => 'machine', 'title' => 'المكنة ونظام القسط', 'items' => [
                        ['type' => 'machine', 'data' => ['key' => 'machine', 'label' => 'اختار المكنة', 'required' => true, 'binding' => 'request.machine']],
                        ['type' => 'plan', 'data' => ['key' => 'plan', 'label' => 'تقسط على كام شهر؟', 'required' => true, 'binding' => 'request.plan']],
                    ]],
                    ['key' => 'person', 'title' => 'بيانات صاحب الطلب', 'items' => [
                        ['type' => 'note', 'data' => ['key' => 'person_note', 'message' => 'البيانات دي لصاحب الطلب نفسه، ولو بتقدّم لحد تاني اكتب بياناته هو.']],
                        ['type' => 'yes_no', 'data' => ['key' => 'egyptian', 'label' => 'صاحب الطلب مصري الجنسية؟', 'required' => true]],
                        ['type' => 'stop', 'data' => ['key' => 'stop_foreign', 'message' => 'للأسف التقسيط للمصريين بس، والكاش متاح في أي فرع من فروعنا.',
                            'conditions' => [['field' => 'egyptian', 'operator' => 'equals', 'value' => 'no']]]],
                        ['type' => 'text', 'data' => ['key' => 'name', 'label' => 'الاسم رباعي زي البطاقة', 'required' => true, 'binding' => 'applicant.name', 'rules' => ['min_length' => 8, 'max_length' => 80]]],
                        ['type' => 'national_id', 'data' => ['key' => 'national_id', 'label' => 'الرقم القومي (14 رقم)', 'required' => true, 'binding' => 'applicant.national_id',
                            'rules' => ['min_age' => 21, 'max_age' => 62], 'error_message' => null]],
                        ['type' => 'phone', 'data' => ['key' => 'phone', 'label' => 'رقم موبايل صاحب الطلب', 'required' => true, 'binding' => 'applicant.phone']],
                        ['type' => 'phone', 'data' => ['key' => 'phone_2', 'label' => 'رقم موبايل تاني (اختياري)', 'required' => false, 'binding' => 'applicant.phone_2']],
                        ['type' => 'address', 'data' => ['key' => 'home', 'label' => 'عنوان السكن (زي ما هو في البطاقة أو السكن الحالي)', 'required' => true, 'binding' => 'applicant.address',
                            'address_parts' => ['governorate', 'area', 'street', 'branch_street', 'building', 'floor', 'apartment', 'landmark'],
                            'address_required' => ['governorate', 'area', 'street', 'landmark']]],
                    ]],
                    ['key' => 'work', 'title' => 'الشغل', 'items' => [
                        ['type' => 'radio', 'data' => ['key' => 'work', 'label' => 'صاحب الطلب بيشتغل إيه؟', 'required' => true, 'binding' => 'work.status', 'options' => [
                            ['value' => 'employee', 'label' => 'موظف متأمن عليه', 'customer_type_id' => $type('employee')],
                            ['value' => 'delivery', 'label' => 'دليفري على تطبيق (طلبات / أوبر / ديدي)', 'customer_type_id' => $type('self_employed'),
                                'work_types' => ['delivery_app', 'delivery_app_bicycle', 'delivery_company']],
                            ['value' => 'free', 'label' => 'شغل حر / صنايعي / باليومية', 'customer_type_id' => $type('self_employed'),
                                'work_types' => ['craftsman', 'other', 'none']],
                            ['value' => 'business', 'label' => 'صاحب محل / ورشة / نشاط', 'customer_type_id' => $type('business_owner')],
                            ['value' => 'pension', 'label' => 'على المعاش', 'customer_type_id' => $type('pension')],
                        ]]],
                        ['type' => 'stop', 'data' => ['key' => 'stop_female_free', 'match' => 'all',
                            'message' => 'للأسف جهات التمويل مش بتقبل الدخل الحر للستات. ينفع لو شغلك متأمن عليه، أو نشاط باسمك بورقه، أو معاش - والكاش متاح.',
                            'conditions' => [
                                ['field' => 'national_id', 'prop' => 'gender', 'operator' => 'equals', 'value' => 'female'],
                                ['field' => 'work', 'operator' => 'in', 'value' => 'delivery,free'],
                            ]]],
                        ['type' => 'text', 'data' => ['key' => 'job_title', 'label' => 'الوظيفة / الشغلانة بالظبط', 'required' => true,
                            'conditions' => $is('work', 'employee,free,business')]],
                        ['type' => 'text', 'data' => ['key' => 'place_name', 'label' => 'اسم الشركة / المحل / مكان الشغل', 'required' => true, 'binding' => 'work.place_name',
                            'conditions' => $is('work', 'employee,business')]],
                        ['type' => 'money', 'data' => ['key' => 'salary', 'label' => 'صافي المرتب الشهري', 'required' => true, 'binding' => 'work.salary_amount',
                            'rules' => ['min' => 1000], 'conditions' => $is('work', 'employee')]],
                        ['type' => 'money', 'data' => ['key' => 'pension', 'label' => 'قيمة المعاش الشهري', 'required' => true, 'binding' => 'work.pension_amount',
                            'conditions' => $is('work', 'pension')]],
                        ['type' => 'address', 'data' => ['key' => 'work_address', 'label' => 'عنوان الشغل', 'required' => true, 'binding' => 'work.address',
                            'address_parts' => ['governorate', 'area', 'street', 'building', 'landmark'],
                            'address_required' => ['governorate', 'area', 'street'],
                            'conditions' => $is('work', 'free,business')]],
                    ]],
                    ['key' => 'guarantor', 'title' => 'بيانات الضامن', 'description' => 'طلبات المعاش محتاجة ضامن.',
                        'conditions' => $is('work', 'pension'), 'items' => [
                            ['type' => 'text', 'data' => ['key' => 'g_name', 'label' => 'اسم الضامن رباعي', 'required' => true, 'binding' => 'guarantor.name']],
                            ['type' => 'national_id', 'data' => ['key' => 'g_nid', 'label' => 'الرقم القومي للضامن', 'required' => true, 'binding' => 'guarantor.national_id', 'rules' => ['min_age' => 21, 'max_age' => 62]]],
                            ['type' => 'phone', 'data' => ['key' => 'g_phone', 'label' => 'موبايل الضامن', 'required' => true, 'binding' => 'guarantor.phone']],
                            ['type' => 'document', 'data' => ['key' => 'g_id_front', 'label' => 'صورة بطاقة الضامن (وش)', 'required' => true, 'binding' => 'guarantor.id_front', 'accept' => 'images']],
                            ['type' => 'document', 'data' => ['key' => 'g_id_back', 'label' => 'صورة بطاقة الضامن (ضهر)', 'required' => true, 'binding' => 'guarantor.id_back', 'accept' => 'images']],
                        ]],
                    ['key' => 'papers', 'title' => 'الورق', 'description' => 'صوّر كل ورقة كاملة في نور كويس ومن غير انعكاس.', 'items' => [
                        ['type' => 'document', 'data' => ['key' => 'id_front', 'label' => 'صورة البطاقة (وش)', 'required' => true, 'binding' => 'applicant.id_front', 'accept' => 'images']],
                        ['type' => 'document', 'data' => ['key' => 'id_back', 'label' => 'صورة البطاقة (ضهر)', 'required' => true, 'binding' => 'applicant.id_back', 'accept' => 'images']],
                        ['type' => 'document', 'data' => ['key' => 'salary_slip', 'label' => 'مفردات المرتب (أو برنت التأمينات لو الشركة مش بتطلع مفردات)', 'required' => true,
                            'binding' => 'work.salary_slip', 'max_files' => 2, 'conditions' => $is('work', 'employee')]],
                        ['type' => 'document', 'data' => ['key' => 'pension_statement', 'label' => 'كشف المعاش', 'required' => true,
                            'binding' => 'work.pension_statement', 'conditions' => $is('work', 'pension')]],
                        ['type' => 'document', 'data' => ['key' => 'license', 'label' => 'رخصة القيادة', 'required' => true, 'document_type' => 'driving_license',
                            'binding' => 'work.free_income_proof', 'max_files' => 2, 'accept' => 'images', 'conditions' => $is('work', 'delivery')]],
                        ['type' => 'document', 'data' => ['key' => 'earnings', 'label' => 'سكرين أرباح التطبيق (آخر 3 شهور)', 'required' => true, 'document_type' => 'delivery_app_earnings',
                            'max_files' => 6, 'accept' => 'images', 'conditions' => $is('work', 'delivery')]],
                        ['type' => 'document', 'data' => ['key' => 'profile', 'label' => 'سكرين بروفايل التطبيق (لو متاح)', 'required' => false, 'document_type' => 'delivery_app_profile',
                            'accept' => 'images', 'conditions' => $is('work', 'delivery')]],
                        ['type' => 'document', 'data' => ['key' => 'place', 'label' => 'صور مكان النشاط (اليافطة من بره واضحة + من جوا)', 'required' => true,
                            'binding' => 'work.place_media', 'max_files' => 4, 'accept' => 'video', 'conditions' => $is('work', 'business')]],
                        ['type' => 'document', 'data' => ['key' => 'tax_card', 'label' => 'البطاقة الضريبية أو السجل التجاري', 'required' => true,
                            'binding' => 'work.tax_card', 'max_files' => 2, 'conditions' => $is('work', 'business')]],
                    ]],
                ],
            ]),
        ]);
        $form->save();

        $this->info(($existing ? 'Rewrote' : 'Created')." form #{$form->id}.");

        if ($this->option('publish')) {
            $problems = $publisher->publish($form, null);
            $problems === [] ? $this->info('Published.') : $this->error(implode("\n", $problems));
        }

        return self::SUCCESS;
    }

    /**
     * Owner 2026-10-19: the job comes first (it decides the papers, the systems and the cap that
     * the machine step shows), then the machine, then his data. A stop that reads the national
     * id sits right after it - a condition only sees answers above it.
     */
    private function arranged(array $schema): array
    {
        $sections = collect($schema['sections'])->keyBy('key');
        $work = $sections['work'];
        $stop = collect($work['items'])->first(fn ($i) => $i['data']['key'] === 'stop_female_free');
        $work['items'] = array_values(array_filter($work['items'], fn ($i) => $i['data']['key'] !== 'stop_female_free'));
        $work['title'] = 'شغلك';
        $work['description'] = 'اختار شغلك الأول، وعلى أساسه هنقولك القسط والورق المطلوب.';

        $person = $sections['person'];
        $at = collect($person['items'])->search(fn ($i) => $i['data']['key'] === 'national_id') + 1;
        array_splice($person['items'], $at, 0, [$stop]);

        // the details of the job (salary, workplace) stay with the job question
        $schema['sections'] = array_values(array_filter([
            $work, $sections['machine'], $person, $sections['guarantor'], $sections['papers'],
        ]));

        return $schema;
    }
}
