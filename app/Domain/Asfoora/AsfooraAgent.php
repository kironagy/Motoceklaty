<?php

namespace App\Domain\Asfoora;

use App\Agent\Providers\AiProvider;
use App\Agent\Providers\AiRequest;
use App\Filament\Resources\DeliveryResource;
use App\Models\AsfooraChat;
use App\Models\AsfooraMessage;

/**
 * Owner 2026-11-26: «عصفورة» - he asks about anything in the system ("تقرير الطلبات اللي اترفعت
 * النهارده"، "الطلبات المتوقفة بالسبب ورقم العميل") and gets the real numbers. The AI (the bot's
 * Bormaa model) only reads, through AsfooraTools; it never makes a number up.
 */
class AsfooraAgent
{
    private const MAX_ROUNDS = 8;

    private const HISTORY_MESSAGES = 12;

    private const TOKEN_BUDGET = 120000;

    /** an answer still "thinking" after this long has failed (the process making it died) */
    public const PENDING_MINUTES = 15;

    public function __construct(private readonly AiProvider $ai)
    {
    }

    /**
     * A question with several lookups went past the web request's time, so the answer is made after
     * the page has its response: start() saves his question and an empty answer that the page shows
     * as "thinking" and polls; run() fills it in.
     */
    public function start(AsfooraChat $chat, string $question, array $meta = []): AsfooraMessage
    {
        $chat->messages()->create(['role' => 'user', 'content' => $question, 'meta' => $meta ?: null]);

        if (blank($chat->title)) {
            $chat->update(['title' => mb_substr($question, 0, 60)]);
        }
        $chat->touch();

        return $chat->messages()->create(['role' => 'assistant', 'content' => '', 'meta' => ['pending' => true]]);
    }

    /** Asks and answers in one go (tests, the console). */
    public function answer(AsfooraChat $chat, string $question): AsfooraMessage
    {
        return $this->run($this->start($chat, $question)->id);
    }

    public function run(int $answerId): AsfooraMessage
    {
        // a sort of hundreds of requests is several AI calls - minutes, in the background
        @set_time_limit(900);
        $answer = AsfooraMessage::findOrFail($answerId);

        if (! ($answer->meta['pending'] ?? false)) {
            return $answer;
        }

        // the conversation so far, without answers still being made or that never came
        $contents = AsfooraMessage::where('asfoora_chat_id', $answer->asfoora_chat_id)->where('id', '<', $answer->id)
            ->where('content', '!=', '')->latest('id')->limit(self::HISTORY_MESSAGES)->get()->reverse()
            ->map(fn ($m) => ['role' => $m->role === 'assistant' ? 'model' : 'user', 'parts' => [['type' => 'text', 'text' => $m->content
                .(isset($m->meta['file']) ? "\n[رفع ملف: {$m->meta['file']['name']} - اقراه بـ read_sheet، ولو شيت أمان وعايز يغير الحالات: aman_sheet_changes]" : '')]]])
            ->values()->all();
        AsfooraTools::$chatId = $answer->asfoora_chat_id;

        $calls = [];
        $actionId = null;
        $tables = [];
        $groupTables = null;
        $groupArgs = ['groups' => [], 'highlight' => []];
        $tokens = 0;
        $text = '';
        $error = null;

        try {
            for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
                // Live 2026-11-26: one question looped to 300k tokens - past the budget she answers with what she has
                $last = $round === self::MAX_ROUNDS - 1 || $tokens > self::TOKEN_BUDGET;
                $response = $this->ai->chat(new AiRequest(
                    purpose: 'asfoora',
                    label: 'asfoora',
                    system: $this->system(),
                    contents: $contents,
                    tools: AsfooraTools::declarations(),
                    // the last round must answer with what it has
                    toolMode: $last ? 'none' : 'auto',
                    temperature: 0.1,
                    // a sort of a few hundred requests writes every number and a note for each
                    maxOutputTokens: 16000,
                    thinkingLevel: 'low',
                    timeoutSeconds: 90,
                ));
                $tokens += (int) ($response->usage['total_tokens'] ?? 0);
                $text = trim(implode('', $response->textParts));

                if ($response->toolCalls === [] || $last) {
                    break;
                }

                $contents[] = ['role' => 'model', 'parts' => array_merge(
                    $text !== '' ? [['type' => 'text', 'text' => $text]] : [],
                    array_map(fn ($c) => ['type' => 'tool_call', 'id' => $c['id'], 'name' => $c['name'], 'args' => $c['args']], $response->toolCalls),
                )];

                $results = [];
                foreach ($response->toolCalls as $call) {
                    $args = (array) $call['args'];
                    // sorting comes in batches: a group with the same title collects them all
                    if ($call['name'] === 'make_report_files') {
                        $args = $groupArgs = self::mergeGroups($groupArgs, $args);
                    }
                    $result = AsfooraTools::call($call['name'], $args);
                    // the sorted groups are the files of this answer; the AI only needs their counts
                    if (isset($result['_tables'])) {
                        $groupTables = $result['_tables'];
                        unset($result['_tables']);
                    }
                    if (isset($result['_action'])) {
                        $actionId = $result['_action'];
                        unset($result['_action']);
                    }
                    $calls[] = ['tool' => $call['name'], 'args' => $args, 'rows' => self::size($result), 'error' => $result['error'] ?? null];
                    foreach (self::tables($call['name'], $args, $result) as $table) {
                        // the same list read again (another page) is one file
                        $key = isset($table['source']) ? json_encode([$table['source']['tool'], array_diff_key($table['source']['args'], ['offset' => 1, 'limit' => 1])]) : null;
                        $tables = array_values(array_filter($tables, fn ($t) => $key === null || ! isset($t['source'])
                            || json_encode([$t['source']['tool'], array_diff_key($t['source']['args'], ['offset' => 1, 'limit' => 1])]) !== $key));
                        $tables[] = $table;
                    }
                    $results[] = ['type' => 'tool_result', 'id' => $call['id'], 'name' => $call['name'], 'result' => $result];
                }
                $contents[] = ['role' => 'tool', 'parts' => $results];
                $text = '';
            }
        } catch (\Throwable $e) {
            report($e);
            $error = mb_substr($e->getMessage(), 0, 300);
        }

        if ($text === '') {
            // the technical error stays in meta (and the log), not in the chat
            $text = $error
                ? 'معلش، حصلت مشكلة وأنا بجهز الرد. جرّب تسأل تاني كمان شوية.'
                : 'معلش، ما قدرتش أوصل لإجابة. جرّب تسأل بطريقة تانية.';
        }

        // the follow-up questions she suggests become buttons under the answer
        $followups = [];
        $text = trim(preg_replace_callback('/```followups\s*\n(.*?)\n```/s', function ($m) use (&$followups) {
            $followups = array_slice(array_values(array_filter(array_map(fn ($q) => mb_substr(trim((string) $q), 0, 120), (array) json_decode($m[1], true)))), 0, 3);

            return '';
        }, $text));

        $answer->update([
            'content' => $text,
            // the data behind the answer - its Excel export (the last 6 tables)
            'meta' => ['tools' => $calls, 'tokens' => $tokens, 'error' => $error, 'tables' => $groupTables ?? array_slice($tables, -6), 'followups' => $followups, 'action_id' => $actionId],
        ]);

        return $answer;
    }

    /** Adds one batch of make_report_files to the groups so far (same title = same group). */
    public static function mergeGroups(array $so, array $batch): array
    {
        foreach ((array) ($batch['groups'] ?? []) as $group) {
            $title = trim((string) ($group['title'] ?? '')) ?: 'مجموعة';
            $at = array_search($title, array_column($so['groups'], 'title'), true);
            if ($at === false) {
                $so['groups'][] = ['title' => $title, 'ids' => [], 'notes' => []];
                $at = array_key_last($so['groups']);
            }
            $so['groups'][$at]['ids'] = array_values(array_unique(array_merge($so['groups'][$at]['ids'], array_map('intval', (array) ($group['ids'] ?? [])))));
            $so['groups'][$at]['notes'] = array_merge($so['groups'][$at]['notes'], (array) ($group['notes'] ?? []));
        }
        foreach ((array) ($batch['highlight'] ?? []) as $rule) {
            if (! in_array($rule, $so['highlight'], true)) {
                $so['highlight'][] = $rule;
            }
        }

        return $so;
    }

    /** The tabular part of a tool's result, for the Excel export. */
    private static function tables(string $tool, array $args, array $result): array
    {
        if (isset($result['error'])) {
            return [];
        }

        // a list or a query is run again in full when it is exported (the AI saw only part of it)
        $source = in_array($tool, ['list_requests', 'requests_with_history', 'run_sql'], true) ? ['source' => ['tool' => $tool, 'args' => $args]] : [];

        return collect(array_filter(match ($tool) {
            'list_requests' => [['title' => 'الطلبات', 'rows' => $result['requests'] ?? []] + $source],
            'requests_with_history' => [['title' => self::titleFor($args), 'rows' => $result['requests'] ?? []] + $source],
            'requests_report' => [['title' => 'التقرير', 'rows' => $result['groups'] ?? []]],
            'run_sql' => [['title' => 'النتيجة', 'columns' => $result['columns'] ?? [], 'rows' => $result['rows'] ?? []] + $source],
            'request_details' => [
                ['title' => 'الطلب', 'columns' => ['البيان', 'القيمة'], 'rows' => collect($result['request'] ?? [])
                    ->map(fn ($v, $k) => ['البيان' => $k, 'القيمة' => is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE)])->values()->all()],
                ['title' => 'السجل', 'rows' => $result['log'] ?? []],
            ],
            default => [],
        }, fn ($t) => ! empty($t['rows'])))
            // the column order is kept apart: MySQL's JSON column reorders the keys of each row
            ->map(fn ($t) => $t + ['columns' => array_keys($t['rows'][0])])->values()->all();
    }

    /** «الطلبات المتوقفة» for a stuck-requests report - the sheet's and the file's title */
    private static function titleFor(array $args): string
    {
        $statuses = array_values((array) ($args['statuses'] ?? []));
        $labels = array_unique(array_map(fn ($s) => DeliveryResource::STATUS_LABELS[$s] ?? $s, $statuses));

        return match (true) {
            $statuses !== [] && array_diff($statuses, ['paused', 'paused_aman']) === [] => 'الطلبات المتوقفة',
            count($labels) === 1 => 'طلبات '.$labels[0],
            default => 'الطلبات',
        };
    }

    /** What the owner told her to keep in mind - after the schema, so it weighs most. */
    private static function memories(): string
    {
        $memories = \App\Models\AsfooraMemory::orderBy('id')->get(['id', 'content']);
        if ($memories->isEmpty()) {
            return "\n\nلما يقولك «افتكري ...» احفظها بـ remember.";
        }

        return "\n\nحاجات صاحب المعرض قالك تفتكريها (امشي بيها دايماً، ولو اتعارضت مع كلامه دلوقتي امشي بكلامه دلوقتي؛ forget بالرقم لو قال انسي):\n"
            .$memories->map(fn ($m) => "{$m->id}. {$m->content}")->implode("\n");
    }

    private static function size(array $result): ?int
    {
        return isset($result['rows']) ? count($result['rows'])
            : (isset($result['requests']) ? count($result['requests']) : (isset($result['groups']) ? count($result['groups']) : null));
    }

    /** Owner 2026-11-26: he calls staff by what he calls them («محمود»), the system by their login («hefny», mahmoud@...). */
    public static function staffDirectory(): string
    {
        $parking = array_column(\App\Models\InstallmentRequest::PARKING_ACCOUNTS, 0);

        return \App\Models\Staff::query()->orderBy('id')->get(['id', 'name', 'email'])
            ->map(fn ($s) => "#{$s->id}: {$s->name} - {$s->email}".(in_array($s->email, $parking, true) ? ' (حساب ركن، مش موظف)' : ''))
            ->implode("\n        ");
    }

    private function system(): string
    {
        $now = now();
        $statuses = collect(DeliveryResource::STATUS_LABELS)->map(fn ($label, $code) => "{$code} = {$label}")->implode('، ');
        // the week starts on Saturday in Egypt
        $weekStart = $now->copy()->startOfWeek(\Carbon\CarbonInterface::SATURDAY)->toDateString();
        $staffList = self::staffDirectory();

        return <<<TXT
        إنت «عصفورة»، مساعد صاحب معرض موتو جيت (معرض موتوسيكلات بالتقسيط في مصر) جوه السيستم بتاعه. بتجاوب على أسئلته عن اللي حصل في السيستم من بياناته الحقيقية بس.

        الوقت دلوقتي (القاهرة): {$now->format('Y-m-d H:i')} - النهارده {$now->toDateString()}، امبارح {$now->copy()->subDay()->toDateString()}، أول الأسبوع (السبت) {$weekStart}، أول الشهر {$now->copy()->startOfMonth()->toDateString()}.
        كل التواريخ في قاعدة البيانات بتوقيت القاهرة. ساعة قاعدة البيانات مختلفة، فما تستخدمش NOW() ولا CURDATE() ولا CURRENT_DATE - اكتب التاريخ نفسه.

        القواعد:
        - كل رقم أو اسم بتقوله لازم يكون جاي من أداة نادتها دلوقتي. ما تخمنش ولا تكمّل من عندك. لو مفيش بيانات قول كده.
        - للأسئلة عن الطلبات استخدم requests_report (العدد والتقسيم) و list_requests (القايمة) و request_details (طلب واحد وسجله). لأي حاجة تانية اكتب SELECT في run_sql.
        - run_sql: قراءة بس، جملة واحدة، من الجداول اللي تحت بس، ومن غير تعليقات. لو رجعلك خطأ صلّح الاستعلام وجرب تاني. سمّي الأعمدة بالعربي (AS `رقم الطلب`، AS `الموظف` ...) لأن النتيجة بتتصدّر إكسيل زي ما هي. ما تستخدمش REPLACE().
        - صاحب المعرض يقدر ينزّل أي إجابة ملف إكسيل من زرار تحتها، والملف بيبقى فيه كل الصفوف حتى اللي مش ظاهرة لك. لو طلب إكسيل، هات البيانات بالأداة وقوله يدوس «نزّل إكسيل».
        - الجدول في الشات بحد أقصى 25 صف. لو العدد أكتر، اعرض أول 25 وقول الإجمالي الحقيقي (total_matching أو COUNT) و«الباقي في ملف الإكسيل». لما تحتاج العدد بس استخدم requests_report أو COUNT مش قايمة.
        - رسالته ممكن تكون تسجيل صوتي اتحوّل لكلام - افهم المقصود حتى لو فيه أخطاء كتابة.
        - رقم التليفون زي ما هو. في الجدول رقم الطلب اكتبه كده #123 (بيبقى رابط يفتح الطلب).

        أسلوب الكلام (صاحب المعرض عايزه كده بالظبط):
        - اتكلم زي زميل شغل شاطر بيحكيله اللي لقاه، مش تقرير جاف: بالعامية المصرية، جمل قصيرة، والأسماء زي ما هي في السيستم (Manga، El Joo، beso، مريم ...).
        - ابدأ بالخلاصة والحاجات اللي تلفت النظر قبل الجدول: العدد، وبعدين «حاجتين تعرفهم:» لو فيه حاجة مهمة.
        - جمّع الطلبات اللي ليها نفس القصة في جملة واحدة بأرقامها، من غير ما تكرر نفس الجملة لكل طلب. مثال من كلامه:
          «حاجتين تعرفهم: طلبات 5207 و5415 و5178 و5141 أصلاً مريم رفعتها، وبعدين beso خدها وبعد كده اتسحبت منه. وطلب 5874 و5273 و6196 لسه على اسم سيلزهم ومتسحبوش (El Joo وEl Hawi وManga).»
        - بعد الكلام حط الجدول، وفي الآخر سطر واحد بالسبب اللي متكرر أكتر أو الحاجة اللي محتاجة تتعمل.
        - requests_with_history بترجّع summary محسوب على كل الطلبات + صفحة صغيرة. للتقرير اعتمد على summary وأمثلة من الصفحة، وما تقلبش الصفحات - ملف الإكسيل فيه الكل.
        - تقارير الطلبات المتوقفة/المرفوضة/الموافقة و«مين رفع/مين خد/اتسحب من مين»: استخدم requests_with_history. أعمدة الجدول بالترتيب ده: رقم الطلب، العميل، التليفون، تاريخ الطلب، اتسحب من (taken_from)، السيلز الحالي، سبب أمان (aman_reason)، ملاحظة الموظف (staff_note). «مفيش رد مكتوب من أمان» لو aman_reason فاضي. القصة (owners) استخدمها في الكلام: مين رفع ومين خد ومين اتسحب منه.
        - في الفرز كل طلب بيروح لمجموعة واحدة: جنب المجموعات اللي طلبها ضيف دايماً مجموعة للباقي باسم واضح («باقي المرفوض - رفض عادي»)، و«محتاج مراجعة» بتتضاف لوحدها للكلام المش واضح بس - ما تعملش واحدة تانية.
        - فرز الطلبات («افرز المرفوض: الميت لوحده واللي ينفع نقاوح فيه لوحده»، «شوف اللي ينفع واللي مينفعش»، «هاتلي المرفوض غير مقيم واللي اترفض ظلم»): نادي sort_requests على طول من غير ما تقرا الطلبات قبلها بالفلاتر وبالمجموعات وقاعدة كل مجموعة (هي بتقرا كل طلب وتفرزه على دفعات، مهما كان العدد). لو الطلبات قليلة (أقل من 40) ممكن تقراها بنفسك وتنادي make_report_files. كل مجموعة بتطلع ملف إكسيل لوحدها بزرار لوحدها.
          ميت عادةً: رفض نهائي من أمان، سكور/استعلام ائتماني وحش، متعثر أو عليه أقساط متأخرة، طلب تاني شغال عند جهة تانية ومش هيلغيه، السن مش مناسب، مش شغال ومفيش حد يقدّم بداله، أو العميل لغى ومش عايز.
          ينفع نقاوح: ورق ناقص (مفردات مرتب، اسكرينات دخل، كشف حساب، بيان معاش، برنت تأمينات)، صورة بطاقة مش واضحة أو تالفة، عنوان مش مطابق، بيانات غلط تتصلح، مقدم أو مدة ممكن تتغير.
          لو صاحب المعرض قال معايير بنفسه امشي بيها هي. قول في ردك المعيار اللي مشيت بيه وعدد كل مجموعة، وأمثلة قليلة بالأرقام. الطلب اللي مش واضح حطه في «محتاج مراجعة» بدل ما تخمن.
        - رسم: لما الأرقام مقسمة على موظفين أو أيام أو أنظمة (3 بنود أو أكتر)، ارسمها تحت الكلام ببلوك كده (من غير أي كلام جوه البلوك):
          ```chart
          {"type":"bar","title":"طلبات النهارده لكل موظف","labels":["حبيبة","يوسف"],"values":[10,5]}
          ```
          type: bar للمقارنة بين بنود، line لحاجة على الأيام. مقارنة فترتين: ضيف "previous":[..] و"label":"الشهر ده" و"previous_label":"الشهر اللي فات".
        - مقارنة («الشهر ده قصاد اللي فات»، «اتحسن ولا لأ»): requests_report بـ compare: true، وقول الفرق بالعدد والنسبة لكل بند والإجمالي.
        - في آخر كل رد اقترح 2 أو 3 أسئلة متابعة قصيرة مفيدة (هتبقى زراير)، ببلوك كده في الآخر خالص:
          ```followups
          ["نفس التقرير للأسبوع اللي فات", "هاتلي طلبات حبيبة بس"]
          ```
        - تلوين الصفوف في الإكسيل (زي «متوقف امان» أصفر): highlight في make_report_files. لو طلب ملف مش فرز، برضه ينفع تعمل مجموعة واحدة بيها.
        - request_type: bot = الطلب جه من البوت، normal = موظف دخله، fake = تجريبي (مستبعد دايماً). «طلبات البوت» = source: bot.
        - شيت مرفوع (📎): read_sheet تشوفه. شيت أمان: aman_sheet_changes (المعرض افتراضياً «موتوجيت»). لو قال «غيّر/حدّث الحالات» ابعت apply=true وإنتِ اللي بتغيري. لو قال «قارن/شوف» بس من غير ما يقول غيّر، من غير apply ويظهر تحت ردك زرار «نفّذ». قوله: كام طلب اتغير (أو هيتغير) ولكل حالة كام، وكام عميل وصله رسالة واتساب، واللي محتاج مراجعة وليه، واللي مش لاقيه، وكام نفس حالته.
        - تقارير المتابعة والتقرير اليومي بيوصلوله إشعار، ولو حاطط رقم واتساب (whatsapp_alerts) بيوصلوه واتساب كمان من رقم من أرقام البوت. لو قال «ابعتلي على الواتساب» ومن غير رقم، اسأليه عن الرقم.
        - أوامر (owner 2026-11-27: بتتنفذ على طول من غير ما تستأذنيه): change_status (تغيير حالة طلبات، أو شيت فيه أرقام طلبات: اقريه بـ read_sheet وبعدين change_status لكل حالة)، transfer_requests (تحويل طلبات لموظف)، add_deduction (خصم على موظف)، aman_sheet_changes بـ apply=true.
          قبل الأمر: اتأكدي إنتِ فاهمة مين وإيه بالظبط (الموظف من قايمة الموظفين برقمه، أرقام الطلبات من البحث). لو في أكتر من احتمال للموظف أو مش واضح أنهي طلبات أو المبلغ، اسأليه الأول وما تنفّذيش. ما تنفّذيش أمر إلا لو هو طالبه صريح في رسالته.
          بعد الأمر: قوليله اللي اتعمل بالأرقام (اتغير كام، لمين، كام عميل وصله رسالة، اللي اتساب وليه). تغيير الحالة بيبعت للعميل رسالة الحالة على الواتساب زي ما الموظف بيغيرها.
        - متابعة في الخلفية: «ابعتلي/تابعيلي الطلبات الجديدة كل ساعة» = watch_start (kind new_requests، ولو قال من البوت source bot). أي سؤال يتكرر = kind question. «خلاص»، «وقفي»، «بطّلي تبعتي» = watch_stop. المتابعة بتبعت إشعار على الموبايل كل مرة، وبتقول «مفيش جديد» لو مفيش، لحد ما يوقفها من هنا.
        - غير كده انت بتقرا بس: ولا فرز ولا ملف بيغيّر حاجة في اللوحة، وقول كده لو سأل.
        - الحسابات «متوقف» و«متوقف امان» و«مرفوض» و«موافقه» مش موظفين: دي حسابات ركن الطلبات بيتنقل لها الطلب بعد مدة، فالطلب «اتسحب» من آخر موظف حقيقي كان معاه.
        - لو الطلب ناقص (مش واضح الفترة مثلاً) خد أقرب معنى وقول افترضت إيه.

        معاني البيانات:
        - installment_requests = الطلبات. id رقم الطلب، applicant_name/applicant_phone/applicant_phone_2 العميل، staff_id الموظف صاحب الطلب (staff.name)، machine_id المكنة (machines.name و machines.brand_id → brands.name)، installment_type نظام التقسيط (أمان، حالا، مايلو ...)، months المدة، deposit المقدم، machine_installment_price سعر التقسيط.
        - status حالة الطلب: {$statuses}. «المتوقفة» = paused و paused_aman. «تحت الاستعلام» = pending و work_check.
        - checks_report = سبب الحالة (رفض/توقف/إلغاء)، نص أو JSON فيه أجزاء زي {"aman": ...} و {"customer": ...}. notes ملاحظات.
        - created_at = وقت رفع الطلب، status_updated_at = آخر تغيير في الحالة (status_updated_by الموظف اللي غيرها). deleted_at مش فاضي = محذوف، استبعده دايماً.
        - activity_log = سجل كل التعديلات: subject_type 'App\\Models\\InstallmentRequest' و subject_id رقم الطلب، causer_id الموظف (staff)، description و properties التفاصيل.
        - whatsapp_conversations / whatsapp_messages = محادثات البوت على الواتساب (direction incoming من العميل / outgoing، sender_type customer/bot/staff). whatsapp_bots = أرقام البوت.
        - staff = الموظفين.

        الموظفين (رقم: الاسم في السيستم - إيميل الدخول):
        {$staffList}
        - صاحب المعرض بيقول اسم الموظف زي ما بيناديه، مش زي ما هو متسجل. لو الاسم مش لاقيه، دوّر عليه في القايمة دي بالاسم والإيميل ونطق الاسم بالإنجليزي أو بالعربي (محمود = mahmoud، حفني = hefny). ولو برضه مش لاقيه، استبعد: الأسامي اللي ليها صاحب واضح في القايمة مش هو، واللي فاضل من غير اسم يتطابق معاه (اسم دلع أو اسم عيلة زي «حفني») غالباً هو. لاقيت واحد بس؟ اشتغل عليه على طول وقول في سطر إنك افترضت إنه هو (مثلاً «محمود = hefny، إيميله mahmoud@...»). لاقيت أكتر من واحد؟ اسأله يقصد مين. ما تقولش «مش لاقيه» من غير ما تدور في القايمة.
        - فلتر staff في الأدوات بياخد رقم الموظف (الأضمن) أو جزء من اسمه أو إيميله.
        - «حوّلتله طلبات» = سطور activity_log على InstallmentRequest فيها JSON_EXTRACT(properties, '$.attributes.staff_id') = رقمه و '$.old.staff_id' مختلف، و created_at وقت التحويل. «عدّل فيهم كام» = سطور activity_log على نفس الطلبات بعد وقت التحويل و causer_id = رقمه (properties فيها اللي اتغير). ما تطلبش منه أرقام الطلبات، هاتها إنت من السجل.
        - inventory_items = مكن الخزنة، كل سطر مكنة (chassis_number الشاسيه، engine_number الماتور، inventory_id → inventories.warehouse_id → warehouses.name الخزنة، inventories.product_id → machines). sold_at مش فاضي = اتباعت (sold_by الموظف اللي باع). returned_at مش فاضي = البيعة دي اتعمل عليها مرتجع: مش بيعة، ما تحسبهاش. المرتجع بيرجّع نفس المكنة للخزنة كسطر جديد sold_at فاضي (ممكن يفضل عليه اسم العميل القديم، ده مش بيعة). البيعات = sold_at مش فاضي و returned_at فاضي.
        - letters = جوابات البيع (sold_by البايع، inventory_item_id سطر الخزنة). المرتجع بيمسح الجواب.

        الجداول (اسم الجدول(العمود النوع، ...)):
        TXT
            ."\n".ReadOnlyQuery::schemaText()
            .self::memories();
    }
}
