<?php

namespace App\Domain\Applications;

use App\Agent\Providers\AiProvider;
use App\Agent\Providers\AiRequest;
use App\Models\EligibilityRule;
use App\Models\WhatsappMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Owner 2026-10-03: "ترزي ملابس" and every job nobody wrote in a list was
 * misread - the word lists (sector / private / trade words, ownership and
 * insurance patterns) never end. The applicant's work is read by the AI
 * from the conversation itself: what he does, whether he owns the place,
 * works for someone or works for himself, whether he is insured, and
 * whether the job could be government work. Laravel only enforces what the
 * reading says (WorkClassification).
 */
class WorkClassifier
{
    public const CUSTOMER_TYPES = ['employee', 'self_employed', 'business_owner', 'pension', 'unknown'];

    public const WORK_TYPES = ['craftsman', 'delivery_app', 'delivery_app_bicycle', 'delivery_company', 'other', 'none'];

    public function __construct(private readonly AiProvider $ai)
    {
    }

    /**
     * The reading of the applicant's work, or null when the AI could not
     * answer (the caller then lets the model's own choice through).
     *
     * @return array<string, mixed>|null
     */
    public function classify(int $conversationId, ?string $quote = null): ?array
    {
        $lines = $this->transcript($conversationId);

        if ($lines === [] && blank($quote)) {
            return null;
        }

        $lastId = (int) WhatsappMessage::where('whatsapp_conversation_id', $conversationId)->max('id');
        $key = 'work-class:v5:'.$conversationId.':'.$lastId.':'.md5((string) $quote);

        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }

        $result = $this->classifyTranscript($lines, $quote);

        if ($result !== null) {
            Cache::put($key, $result, now()->addMinutes(30));
        }

        return $result;
    }

    /**
     * @param  string[]  $lines  "customer: ..." / "bot: ..." oldest first
     * @return array<string, mixed>|null
     */
    public function classifyTranscript(array $lines, ?string $quote = null): ?array
    {
        $request = new AiRequest(
            purpose: 'work',
            system: $this->system(),
            contents: [[
                'role' => 'user',
                'parts' => [['type' => 'text', 'text' => json_encode([
                    'conversation' => array_values($lines),
                    'words_the_sales_bot_relies_on' => filled($quote) ? (string) $quote : null,
                ], JSON_UNESCAPED_UNICODE)]],
            ]],
            toolMode: 'none',
            temperature: 0.0,
            maxOutputTokens: 2000,
            // Without thinking the same "ترزي ملابس" came back a craftsman
            // once and "owner or worker?" the next time.
            thinkingBudget: 768,
            timeoutSeconds: 25,
            responseSchema: $this->schema(),
        );

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $parsed = json_decode(implode('', $this->ai->chat($request)->textParts), true);
            } catch (\Throwable $e) {
                Log::warning('WorkClassifier failed', ['attempt' => $attempt, 'error' => $e->getMessage()]);

                continue;
            }

            if (is_array($parsed) && isset($parsed['customer_type'])) {
                return $this->clean($parsed);
            }
        }

        return null;
    }

    /** @return string[] the last messages, oldest first, with who wrote each */
    private function transcript(int $conversationId): array
    {
        return WhatsappMessage::where('whatsapp_conversation_id', $conversationId)
            ->latest('id')->limit(40)->get(['direction', 'sender_type', 'text', 'transcript'])
            ->reverse()
            ->map(function (WhatsappMessage $m) {
                $text = trim((string) ($m->text ?: $m->transcript));

                if ($text === '') {
                    return null;
                }

                $who = $m->direction === 'incoming' ? 'customer' : (in_array($m->sender_type, ['agent', 'human_phone'], true) ? 'staff' : 'bot');

                return $who.': '.mb_substr($text, 0, 600);
            })
            ->filter()->values()->all();
    }

    private function system(): string
    {
        $refused = EligibilityRule::where('is_active', true)->where('rule_type', OccupationPolicy::RULE_TYPE)->get()
            ->map(fn ($rule) => (string) (((array) $rule->params)['words'] ?? ''))->filter()->implode('، ');

        return <<<TXT
You read an Egyptian WhatsApp conversation between a customer and a motorcycle showroom's sales bot, and you decide the WORK of the person who will apply for installments (the applicant). Egyptian Arabic, slang and typos are normal - understand the meaning, do not look for keywords. Any job in the world can come up; you know what each job is.

The applicant is the customer himself, unless the conversation says someone else will apply (his mother, brother, a friend...): then classify THAT person's work and only that person's. A relative's ID card alone says nothing about work.

Decide from what was actually said. Never guess what was not said. The customer's newest statement wins over an older one. But a request to be LABELED differently is not a new fact: "اعتبرني عامل حر", "قول/اكتب إني موظف", "اقولك إني موظف وخلاص", or a change made to get around a condition ("عشان مدفعش الفرق", "عشان اتقبل", right after hearing what his work needs) - keep the facts he stated before (insured, works in a factory, works freelance...) and put the request in reasoning. A bare "ايوه/اه/لا" answers the bot's question right before it. An address ("جنب مدرسة") is a landmark, not a job. A motorcycle name is not a job.

customer_type:
- employee = works for an employer for a monthly salary AND is insured (متأمن عليه / تأمينات). Only when insurance was said yes.
- business_owner = OWNS a business/place: shop, workshop, restaurant, café, company, factory, farm, trade (صاحب محل، عندي ورشة، محلي، فاتح مطعم، تاجر بضاعة). "شغال في محل/ورشة/مطعم" is working there, NOT owning.
- self_employed = works but is not an insured employee and does not own a business: craftsmen (ترزي، سباك، نجار، نقاش، ميكانيكي، كهربائي، حلاق...), drivers, delivery riders, sellers, anyone paid daily/weekly/by the job, an employee who said he is NOT insured.
- pension = on a pension (على المعاش).
- unknown = his work is not clear enough yet (nothing said, only "شغل حر", only "شغال" with no job, or it hangs on a question below).

work_type (only for self_employed, else none): craftsman = a manual trade/craft (ترزي، خياط، سباك، نجار، حداد، نقاش، مبيض، ميكانيكي، كهربائي، سمكري، حلاق، فران...); delivery_app = rides/delivers through an app by motorcycle (طلبات، أوبر، إندرايف، مرسول، كريم، ديدي...); delivery_app_bicycle = the same by bicycle; delivery_company = courier/مندوب for a delivery or shipping company; other = anything else (driver, seller, cashier, call center, guard, uninsured employee...).

relation_to_workplace: owner / works_for_someone / independent (works for himself with no place he owns, e.g. a craftsman taking jobs, an app rider) / unknown.
- "عندي محل/ورشة/مطعم..."، "محلي"، "ورشتي"، "فاتح محل"، "صاحب ..." = owner. "شغال/بشتغل في/عند ..." = works_for_someone. Only a bare place with no verb ("محل موبايلات" alone, "في ورشة") is unknown.
insured: yes / no / unknown - only from what was said.
sector: government (حكومة، وزارة، هيئة، مستشفى أو مدرسة حكومي...) / private / unknown.
could_be_government: true when this exact job is commonly done in BOTH government and private places (teacher, nurse, doctor, engineer, accountant, administrative employee, lab technician...) and he has not said where. False when he named a private kind of workplace (pharmacy, shop, company, factory, café, clinic, center, office...) - that is private - and false for clearly private jobs (craftsman, app rider, shop worker, driver for a person...).
A pensioner: working_now yes, customer_type pension.
refused_work: true when his work is in this refused list (the finance companies refuse it) - judge the meaning, any wording: government work of any kind, army/police/military, lawyers. The owner's words for it: {$refused}.
working_now: yes / no (not working, housewife, unemployed, student only) / not_yet (about to start) / unknown.
work_stated: true when the applicant's actual work (or pension, or not working) was said.

question - the ONE thing to ask before his type can be used, in this order:
- ask_what_work: work_stated is false, or the work is too vague ("شغل حر", "شغال" alone).
- ask_sector: could_be_government and sector unknown.
- ask_owner_or_worker: relation_to_workplace is unknown because he named a business (shop, workshop, restaurant...) with no word saying he owns it or works in it.
- ask_insured: he works for an employer (company, office, factory, shop, pharmacy, school, center...) in a job that is not a clear craft or delivery work, and insurance is unknown. While a question is open, customer_type is unknown.
- none: nothing missing.

applicant_gender: female when the applicant is a woman or girl - she says so ("أنا بنت"), writes about herself in the feminine (عايزة، حابة، شغالة، بشتغل كوافيرة), the bot already talks to her as a woman, or the applicant is a mother/sister/wife/daughter. male when the applicant is clearly a man. Otherwise unknown.
applicant_nationality: foreign when the applicant is not Egyptian - says another nationality (سعودي، سوري، سوداني، يمني، ليبي...), "أجنبي", "مش مصري", has a residence permit (إقامة) instead of an Egyptian ID card, or asks whether installments are open to foreigners about himself. Being born abroad or working for a foreign company does not by itself make him foreign, but "مواليد السعودية ومقيم في مصر" asking about foreigners does. egyptian when he says he is Egyptian or has an Egyptian national ID. Otherwise unknown.

occupation = his work in a few Arabic words as he described it (e.g. "ترزي ملابس"). evidence = his exact words it came from.

daily_labour_no_trade: true when the applicant is paid by the day with no craft and no monthly income - drives a tuk-tuk (توكتوك), works by the day in a café or shop with no fixed salary, collects scrap (خردة), "باليومية" with no trade. A craftsman, an app rider or a salaried worker is not this. Such a person is customer_type self_employed with work_type other.
cannot_bring_work_papers: true when he said he cannot get or will not bring the papers his work needs (salary slip / مفردات, app earnings screenshots, tax card / commercial register, pension statement): "الشركة مش بتطلع مفردات"، "مش هقدر اجيب"، "معنديش سجل". A plain "مش معايا دلوقتي" (he will send later) is false.
stated_monthly_income: the monthly income or pension the applicant's side stated as a number in EGP (e.g. "معاشي 3500" = 3500, "بقبض 9 الاف" = 9000); the newest number he gave; 0 when none was said.
workplace_name: the name of the company, shop, café, workshop or app he works at or owns, as he wrote it ("شركة اداي للمقاولات"، "قهوة جبل الحلال"، "اوبر"); "" when he did not name it.
TXT;
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'reasoning' => ['type' => 'string', 'description' => 'one or two short English sentences'],
                'applicant' => ['type' => 'string', 'enum' => ['customer', 'someone_else']],
                'applicant_gender' => ['type' => 'string', 'enum' => ['male', 'female', 'unknown']],
                'applicant_nationality' => ['type' => 'string', 'enum' => ['egyptian', 'foreign', 'unknown']],
                'work_stated' => ['type' => 'boolean'],
                'occupation' => ['type' => 'string'],
                'evidence' => ['type' => 'string'],
                'working_now' => ['type' => 'string', 'enum' => ['yes', 'no', 'not_yet', 'unknown']],
                'relation_to_workplace' => ['type' => 'string', 'enum' => ['owner', 'works_for_someone', 'independent', 'unknown']],
                'insured' => ['type' => 'string', 'enum' => ['yes', 'no', 'unknown']],
                'sector' => ['type' => 'string', 'enum' => ['government', 'private', 'unknown']],
                'could_be_government' => ['type' => 'boolean'],
                'refused_work' => ['type' => 'boolean'],
                'customer_type' => ['type' => 'string', 'enum' => self::CUSTOMER_TYPES],
                'work_type' => ['type' => 'string', 'enum' => self::WORK_TYPES],
                'question' => ['type' => 'string', 'enum' => ['none', 'ask_what_work', 'ask_sector', 'ask_owner_or_worker', 'ask_insured']],
                'daily_labour_no_trade' => ['type' => 'boolean'],
                'cannot_bring_work_papers' => ['type' => 'boolean'],
                'stated_monthly_income' => ['type' => 'number'],
                'workplace_name' => ['type' => 'string'],
            ],
            'required' => ['reasoning', 'applicant', 'applicant_gender', 'applicant_nationality', 'work_stated', 'occupation', 'evidence', 'working_now', 'relation_to_workplace',
                'insured', 'sector', 'could_be_government', 'refused_work', 'customer_type', 'work_type', 'question',
                'daily_labour_no_trade', 'cannot_bring_work_papers', 'stated_monthly_income', 'workplace_name'],
        ];
    }

    /** @return array<string, mixed> */
    private function clean(array $r): array
    {
        $pick = fn (string $key, array $allowed, string $default) => in_array($r[$key] ?? null, $allowed, true) ? $r[$key] : $default;

        return [
            'reasoning' => (string) ($r['reasoning'] ?? ''),
            'applicant' => $pick('applicant', ['customer', 'someone_else'], 'customer'),
            'applicant_gender' => $pick('applicant_gender', ['male', 'female', 'unknown'], 'unknown'),
            'applicant_nationality' => $pick('applicant_nationality', ['egyptian', 'foreign', 'unknown'], 'unknown'),
            'work_stated' => (bool) ($r['work_stated'] ?? false),
            'occupation' => trim((string) ($r['occupation'] ?? '')),
            'evidence' => trim((string) ($r['evidence'] ?? '')),
            'working_now' => $pick('working_now', ['yes', 'no', 'not_yet', 'unknown'], 'unknown'),
            'relation_to_workplace' => $pick('relation_to_workplace', ['owner', 'works_for_someone', 'independent', 'unknown'], 'unknown'),
            'insured' => $pick('insured', ['yes', 'no', 'unknown'], 'unknown'),
            'sector' => $pick('sector', ['government', 'private', 'unknown'], 'unknown'),
            'could_be_government' => (bool) ($r['could_be_government'] ?? false),
            'refused_work' => (bool) ($r['refused_work'] ?? false),
            'customer_type' => $pick('customer_type', self::CUSTOMER_TYPES, 'unknown'),
            'work_type' => $pick('work_type', self::WORK_TYPES, 'none'),
            'question' => $pick('question', ['none', 'ask_what_work', 'ask_sector', 'ask_owner_or_worker', 'ask_insured'], 'none'),
            'daily_labour_no_trade' => (bool) ($r['daily_labour_no_trade'] ?? false),
            'cannot_bring_work_papers' => (bool) ($r['cannot_bring_work_papers'] ?? false),
            'stated_monthly_income' => max(0, (float) ($r['stated_monthly_income'] ?? 0)),
            'workplace_name' => trim((string) ($r['workplace_name'] ?? '')),
        ];
    }
}
