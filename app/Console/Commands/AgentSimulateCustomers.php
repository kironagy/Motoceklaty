<?php

namespace App\Console\Commands;

use App\Domain\Simulation\ConversationSimulator;
use App\Models\AiTrace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The owner's five real customers (folder "بيانات العملاء " in the project
 * root - its name ends in a space) plus the scooter script from the
 * 2026-10-04 agent:evaluate run, played against the real provider through
 * the simulator: nothing reaches WhatsApp. Prints what the reply-quality
 * page shows for exactly these turns - refusals, reviewer redos, fallbacks,
 * seconds and cents per reply - so a change is measured before and after.
 * Costs real money (paid keys): manual use only.
 */
class AgentSimulateCustomers extends Command
{
    protected $signature = 'agent:simulate-customers {--only= : run only the scenarios whose name contains this}';

    protected $description = 'Play the five real customers (and the scooter script) against the real AI provider and report reply quality and cost.';

    public function handle(ConversationSimulator $simulator): int
    {
        if (app()->environment('testing', 'production')) {
            $this->error('agent:simulate-customers must not run in the testing or production environment.');

            return self::FAILURE;
        }

        \App\Domain\Settings\AgentSettings::apply();

        $started = now();
        $firstTrace = (int) AiTrace::max('id');
        $report = ["# agent:simulate-customers ".$started->toDateTimeString(), ''];

        foreach ($this->scenarios() as $name => $messages) {
            $only = array_filter(explode(',', (string) $this->option('only')));
            if ($only !== [] && ! collect($only)->contains(fn ($o) => str_contains($name, trim($o)))) {
                continue;
            }

            $answers = $messages['answers'] ?? [];
            unset($messages['answers']);

            $this->info("== {$name}");
            $report[] = "## {$name}";
            $conversation = $simulator->start($name);

            $messages = array_values($messages);

            for ($i = 0; $i < count($messages); $i++) {
                $entry = $messages[$i];
                [$text, $media] = is_array($entry) ? $entry : [$entry, []];
                $turn = $simulator->send($conversation, $text, $media);
                $codes = implode(',', array_column($turn['guard_events'], 'code'));
                foreach ($turn['guard_events'] as $event) {
                    if ($event['code'] === 'REVIEW_REDO') {
                        $codes .= "\n  REVIEWER: ".implode(' | ', (array) ($event['args']['problems'] ?? []))
                            ."\n  DRAFT: ".implode(' / ', (array) ($event['args']['messages'] ?? []));
                    }
                }
                $tools = implode(', ', array_map(fn ($t) => $t['name'].($t['code'] ? "({$t['code']})" : ''), $turn['tools']));

                $lines = [
                    'CUS: '.$text.($media !== [] ? ' ['.count($media).' صور]' : ''),
                    'BOT: '.implode("\n---\n", $turn['reply']),
                    "  {$turn['latency_ms']}ms | tools: {$tools} | guards: {$codes}".($turn['error'] ? ' | ERR '.$turn['error'] : ''),
                ];
                $this->line(implode("\n", $lines));
                array_push($report, ...$lines);
                $report[] = '';

                // the script is done: he answers whatever the application still asks, until it is sent
                if ($i === count($messages) - 1 && $answers !== []) {
                    $messages = array_merge($messages, $this->followUps($conversation, $answers));
                }
            }

            $application = \App\Models\Application::where('customer_id', $conversation->fresh()->customer_id)->latest('id')->first();
            $end = 'END: '.($application ? "application #{$application->id} status={$application->status}" : 'no application');
            $this->warn($end);
            $report[] = $end;
            $report[] = '';
        }

        $summary = $this->summary($firstTrace, $started);
        $this->newLine();
        $this->info('Summary');
        foreach ($summary as $key => $value) {
            $this->line(str_pad($key, 18).(is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value));
        }

        $report[] = '## Summary';
        $report[] = '```json';
        $report[] = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $report[] = '```';

        $path = storage_path('app/agent-eval/simulate-customers_'.$started->format('Y-m-d_His').'.md');
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, implode("\n", $report));
        $this->info("Report: {$path}");

        // PRE-001: the same run as raw numbers (calls incl. side calls, tokens, latency), for docs/baselines/
        $metrics = app(AgentBaseline::class)->metrics($started, now());
        file_put_contents(substr($path, 0, -3).'.json', json_encode(['summary' => $summary, 'metrics' => $metrics], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->info('Metrics: '.substr($path, 0, -3).'.json');

        return self::SUCCESS;
    }

    /**
     * Up to 8 more turns: whatever next_step asks, answered from his folder;
     * "submit" is confirmed. Stops when it is sent or he has nothing to give.
     *
     * @return array<int, string|array{0: string, 1: string[]}>
     */
    private function followUps(\App\Models\WhatsappConversation $conversation, array $answers): array
    {
        static $given = [];
        $application = \App\Models\Application::where('customer_id', $conversation->fresh()->customer_id)
            ->whereIn('status', \App\Models\Application::ACTIVE_STATUSES)->latest('id')->first();

        if (! $application || $application->status !== 'collecting' || ($given[$conversation->id] = ($given[$conversation->id] ?? 0) + 1) > 8) {
            return [];
        }

        $step = app(\App\Domain\Applications\SnapshotService::class)->for($application)['next_step'] ?? [];

        return match (true) {
            ($step['type'] ?? null) === 'submit' => ['ايوه كله صح، قدم الطلب'],
            isset($answers[$step['key'] ?? '']) => [$answers[$step['key']]],
            default => ['محتاج مني ايه تاني بالظبط؟'],
        };
    }

    /** The reply-quality numbers for the turns this run made, and what they cost. */
    private function summary(int $afterTraceId, \Illuminate\Support\Carbon $started): array
    {
        $traces = AiTrace::where('id', '>', $afterTraceId)->get(['id', 'status', 'guard_events', 'created_at', 'updated_at']);
        $codes = $traces->flatMap(fn ($t) => collect($t->guard_events ?? [])->pluck('code'))->countBy()->sortDesc()->all();
        $cost = (float) DB::table('ai_usage_logs')->where('created_at', '>=', $started)->sum('cost_usd');
        $count = max(1, $traces->count());

        return [
            'replies' => $traces->count(),
            'review_redo' => $codes['REVIEW_REDO'] ?? 0,
            'fallbacks' => $traces->where('status', 'fallback')->count(),
            'errors' => $traces->where('status', 'error')->count(),
            'avg_seconds' => round($traces->avg(fn ($t) => $t->created_at->diffInSeconds($t->updated_at)) ?? 0, 1),
            'cents_per_reply' => round(100 * $cost / $count, 3),
            'cost_usd' => round($cost, 4),
            'codes' => $codes,
        ];
    }

    /** @return array<string, array> messages in order, plus 'answers': what he gives for each next_step key */
    private function scenarios(): array
    {
        $folder = base_path('بيانات العملاء ');
        $photos = fn (string $dir, int $take = 4) => array_slice(glob($folder.'/'.$dir.'/*.jpeg') ?: [], 0, $take);

        return [
            // agent:evaluate 2026-10-04 22:11 (gpt-5-mini, reviewer on)
            'scooter-employee-woman' => [
                'السلام عليكم انا عايزة اقسط سكوتر',
                'انا موظفة في شركة',
                'ميزانيتي في حدود 45 الف',
                'عايزة اعرف القسط على سنة',
                'هو ما ينفعش نخلي الفايده 20% على السنتين',
                'تمام عايزة اقدم',
                'انا ساكنة في ١٢ شارع الهرم الدور التالت قدام بنك مصر',
                'بس الشركة مش بتطلع مفردات مرتب',
                'طيب ينفع كشف حساب البنك؟',
            ],
            'insured-employee' => [
                'السلام عليكم عايز اقسط هوجن 4',
                'على سنتين',
                'انا موظف في شركة في الشيخ زايد ومتأمن عليا',
                'عايز اقدم',
                ['دي البطاقة', $photos('موظف تأمين/بطاقه')],
                ['ودي المفردات', $photos('موظف تأمين/مفردات مرتب')],
                'رقمي 01147709597 ورقم تاني 01128884715',
                'ساكن الجيزه منشيه القناطر قريه زاد الكوم شارع الموقف الرئيسي متفرع من شارع المدرسه عماره رقم7 الدور الارضي شقه 1 جنب نادي زاد الكوم',
                'الشركة في الشيخ زايد وصلة دهشور امام كافيه صاصا',
                'answers' => [
                    'down_payment' => 'هحط 10 الاف مقدم', 'motorcycle' => 'الأرخص فيهم', 'plan' => 'اللي يطلع أقل قسط', 'duration' => 'سنة',
                    'phone' => 'رقمي 01147709597', 'address' => 'الجيزه منشيه القناطر قريه زاد الكوم شارع الموقف الرئيسي متفرع من شارع المدرسه',
                    'address_building_no' => 'عماره رقم 7', 'address_floor' => 'الدور الارضي', 'address_apartment' => 'شقه 1', 'address_landmark' => 'جنب نادي زاد الكوم',
                    'work_address' => 'الشيخ زايد وصلة دهشور', 'work_landmark' => 'امام كافيه صاصا', 'work_building_no' => 'مش عارف رقم المبنى',
                    'business_name' => 'شركة في الشيخ زايد',
                    'national_id_front' => ['البطاقة', $photos('موظف تأمين/بطاقه')], 'national_id_back' => ['ضهر البطاقة', $photos('موظف تأمين/بطاقه')],
                    'salary_slip' => ['المفردات', $photos('موظف تأمين/مفردات مرتب')],
                    'monthly_income' => 'مرتبي 9000', 'residence_ownership' => 'ملك',
                ],
            ],
            'delivery-app-rider' => [
                'عايز موتوسيكل اشتغل بيه على ديدي',
                'بكام دايو 4 قسط',
                'سنة',
                'تمام عايز اقدم',
                ['البطاقة', $photos('ديدي وطلبات وغيره/بطاقه')],
                ['الرخصة', $photos('ديدي وطلبات وغيره/رخصه')],
                ['اسكرينات الابلكيشن', $photos('ديدي وطلبات وغيره/اسكرين برنامج + اثبات دخل ٣ شهور')],
                'القليوبيه الخصوص 12 شارع عبد الحميد خالد بجوار البنزينه متفرع من الشارع العمومي عماره12 الدور الاول شقه 2',
                'رقمي 01002561689',
                'answers' => [
                    'down_payment' => 'هحط 10 الاف مقدم', 'motorcycle' => 'الأرخص فيهم', 'plan' => 'اللي يطلع أقل قسط', 'duration' => 'سنة',
                    'phone' => 'رقمي 01002561689', 'address' => 'القليوبيه الخصوص 12 شارع عبد الحميد خالد متفرع من الشارع العمومي',
                    'address_building_no' => 'عماره 12', 'address_floor' => 'الدور الاول', 'address_apartment' => 'شقه 2', 'address_landmark' => 'بجوار البنزينه',
                    'work_address' => 'بشتغل دليفري في الخصوص والشبرا', 'work_landmark' => 'أوكازيون فرع البنزينه', 'work_building_no' => 'مفيش رقم، شغال في الشارع',
                    'national_id_front' => ['البطاقة', $photos('ديدي وطلبات وغيره/بطاقه')], 'national_id_back' => ['ضهر البطاقة', $photos('ديدي وطلبات وغيره/بطاقه')],
                    'driving_license' => ['الرخصة', $photos('ديدي وطلبات وغيره/رخصه')],
                    'delivery_app_earnings' => ['اثبات الدخل', $photos('ديدي وطلبات وغيره/اسكرين برنامج + اثبات دخل ٣ شهور')],
                    'delivery_app_profile' => ['البروفايل', array_slice($photos('ديدي وطلبات وغيره/اسكرين برنامج + اثبات دخل ٣ شهور', 9), 4, 4)],
                    'monthly_income' => 'بطلع حوالي 12 الف في الشهر', 'residence_ownership' => 'ايجار',
                ],
            ],
            'shop-owner' => [
                'السلام عليكم انا عندي قهوة وعايز اقسط مكنة',
                'كيواي سوبر لايت بكام',
                'على سنة ونص',
                'عايز اقدم',
                ['البطاقة', $photos('صاحب نشاط/البطاقه')],
                ['الضرايب', $photos('صاحب نشاط/الضرايب المصريه')],
                ['صور القهوة', $photos('صاحب نشاط/صور للنشاط')],
                'القهوة القليوبيه الخصوص شارع السعاده متفرع من شارع الصرف تقاطع السيد حماده اسمها قهوه جبل الحلال',
                'رقمي 01038839327',
                'answers' => [
                    'down_payment' => 'هحط 10 الاف مقدم', 'motorcycle' => 'الأرخص فيهم', 'plan' => 'اللي يطلع أقل قسط', 'duration' => 'سنة',
                    'phone' => 'رقمي 01038839327', 'address' => 'القليوبيه الخصوص شارع السعاده متفرع من شارع الصرف تقاطع السيد حماده',
                    'address_building_no' => 'عقار 3', 'address_floor' => 'الدور الارضي', 'address_apartment' => 'شقه 1', 'address_landmark' => 'قهوه جبل الحلال',
                    'work_address' => 'القهوة القليوبيه الخصوص شارع السعاده متفرع من شارع الصرف تقاطع السيد حماده', 'work_landmark' => 'تقاطع السيد حماده', 'work_building_no' => 'عقار 3',
                    'business_name' => 'قهوه جبل الحلال',
                    'national_id_front' => ['البطاقة', $photos('صاحب نشاط/البطاقه')], 'national_id_back' => ['ضهر البطاقة', $photos('صاحب نشاط/البطاقه')],
                    'tax_card' => ['الضرايب', $photos('صاحب نشاط/الضرايب المصريه')], 'business_place_photo' => ['صور القهوة', $photos('صاحب نشاط/صور للنشاط')],
                    'monthly_income' => 'القهوة بتطلع حوالي 20 الف في الشهر', 'residence_ownership' => 'ملك',
                ],
            ],
            // over 62: refused even with his son as guarantor; the son may apply in his own name (owner, 2026-10-05)
            'pensioner-over-62' => [
                'مساء الخير انا على المعاش وعايز اقسط دايو 4',
                'سنتين',
                'ابني هيبقى الضامن ينفع؟',
                'تمام نقدم',
                ['البطاقة', $photos('معاش بالضمان/بطاقه')],
                ['بيان المعاش', $photos('معاش بالضمان/بيان معاش')],
                'القليوبيه الخصوص شارع عبد المنعم تركي متفرع من نازله الخصوص عقار 1 الدور السابع شقه7 قدام معرض فستاني',
                'رقمي 01098655527',
            ],
            // the warehouse worker with no salary slip (2026-10-01)
            'warehouse-no-salary-slip' => [
                'هوجن 4 فرز تاني عايز اقسطها سنتين',
                'عايز اقدم',
                'شغال في شركه رويال بتاعه المكرونه في مسطرد عامل في المخزن',
                'والله الشركه مش بتطلع مفردات مرتب',
                'لا مش متأمن عليا لسه بقالي 5 شهور',
                'لا خلاص مش دلوقتي شكرا',
                'تسلم',
            ],
        ];
    }
}
