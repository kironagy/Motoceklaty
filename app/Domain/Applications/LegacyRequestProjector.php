<?php

namespace App\Domain\Applications;

use App\Domain\Documents\DocumentFields;
use App\Models\Application;
use App\Models\ApplicationData;
use App\Models\ApplicationDocument;
use App\Models\CustomerAttribute;
use App\Models\InstallmentRequest;
use App\Models\MessageMedia;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Maps everything the bot collected for an application onto the legacy
 * `installment_requests` columns, so a bot request in the deliveries
 * table carries the same customer data (address, guarantor, work info,
 * document images) as a request staff entered by hand.
 *
 * Accepted documents live on the private media disk; they are copied to
 * the public disk under the same directories the deliveries form uploads
 * to, because that form links them via asset('storage/...').
 */
class LegacyRequestProjector
{
    public const REQUEST_TYPE = 'bot';

    public function __construct(private readonly AddressSplitter $addresses)
    {
    }

    /** Stored field => the part it holds, per address. */
    private const HOME_FIELDS = ['address' => 'street', 'address_building_no' => 'building_number', 'address_floor' => 'floor',
        'address_apartment' => 'apartment', 'address_landmark' => 'landmark'];

    private const WORK_FIELDS = ['work_address' => 'street', 'work_building_no' => 'building_number', 'work_landmark' => 'landmark'];

    /** شرط السن: من 21 لـ 62 سنة */
    private const MIN_AGE = 21;

    private const MAX_AGE = 62;

    private const WORK_TYPE_LABELS = [
        'delivery_app' => 'دليفري تطبيق (موتوسيكل)',
        'delivery_app_bicycle' => 'دليفري تطبيق (عجلة)',
        'delivery_company' => 'دليفري مطعم / شركة',
        'craftsman' => 'صنايعي / حرفي (مش صاحب محل)',
        'business_owner' => 'صاحب نشاط',
        'other' => 'شغل حر',
    ];

    private const RESIDENCE_LABELS = [
        'owned' => 'تمليك',
        'rented' => 'إيجار',
        'family' => 'ساكن مع أهله',
    ];

    /** document type key => [column, directory, is_array_column] */
    private const DOCUMENT_COLUMNS = [
        'salary_slip' => ['salary_slip_file', 'installments/salary_slips', false],
        // the employee's salary proof when his company gives no slip
        'insurance_print' => ['salary_slip_file', 'installments/salary_slips', false],
        'pension_statement' => ['pension_statement_file', 'installments/pension', false],
        'tax_card' => ['tax_card_file', 'installments/business', false],
        'business_place_photo' => ['place_video', 'installments/business', true],
        'self_employed_income_proof' => ['free_income_proof_images', 'installments/free_income_proofs', true],
        'delivery_app_earnings' => ['free_income_proof_images', 'installments/free_income_proofs', true],
        'delivery_app_profile' => ['free_income_proof_images', 'installments/free_income_proofs', true],
        'driving_license' => ['free_income_proof_images', 'installments/free_income_proofs', true],
    ];

    /**
     * Customer/guarantor/work/document columns only - the selection columns
     * (machine, plan, deposit, status ...) are set by the caller.
     */
    public function attributes(Application $application, string $legacyWorkStatus, bool $aiAddressSplit = true): array
    {
        $application->loadMissing(['customer', 'machine.brand', 'installmentPlan.installmentSystem']);

        $applicant = $this->values($application, 'applicant');
        $guarantor = $this->values($application, 'guarantor');
        $installmentType = trim((string) $application->installmentPlan?->installmentSystem?->name);

        $nationalId = $this->digits($applicant['national_id'] ?? null);
        $applicantBirthdate = $this->birthdateFromNationalId($nationalId);
        $guarantorNationalId = $this->digits($guarantor['guarantor_national_id'] ?? null);
        // Not digits(): "5432.5" became 54325 - ten times the salary.
        $income = $this->amount($applicant['monthly_income'] ?? null);
        $facts = $this->documentFacts($application);

        $attributes = [
            'request_type' => self::REQUEST_TYPE,
            'applicant_name' => $applicant['full_name'] ?? '',
            'applicant_phone' => $application->customer?->phone ?: ($applicant['phone'] ?? ''),
            'applicant_phone_2' => $this->secondPhone($application, $applicant['phone'] ?? null),
            'applicant_national_id' => $nationalId,
            'applicant_birthdate' => $applicantBirthdate?->toDateString(),
            'applicant_age_ok' => $this->ageOk($applicantBirthdate),

            'applicant_street' => $applicant['address'] ?? null,
            'applicant_building_number' => $applicant['address_building_no'] ?? null,
            'applicant_floor' => $applicant['address_floor'] ?? null,
            'applicant_apartment' => $applicant['address_apartment'] ?? null,
            'applicant_landmark' => $applicant['address_landmark'] ?? null,
            'applicant_address' => $this->joinAddress([
                isset($applicant['address_building_no']) ? "رقم العقار {$applicant['address_building_no']}" : null,
                $applicant['address'] ?? null,
                isset($applicant['address_floor']) ? "الدور {$applicant['address_floor']}" : null,
                isset($applicant['address_apartment']) ? "شقة {$applicant['address_apartment']}" : null,
                isset($applicant['address_landmark']) ? "بجوار {$applicant['address_landmark']}" : null,
            ]),

            'guarantor_name' => $guarantor['guarantor_name'] ?? null,
            'guarantor_phone' => $guarantor['guarantor_phone'] ?? null,
            'guarantor_national_id' => $guarantorNationalId,
            'guarantor_birthdate' => $this->birthdateFromNationalId($guarantorNationalId)?->toDateString(),
            'guarantor_age_ok' => $this->ageOk($this->birthdateFromNationalId($guarantorNationalId)),

            'work_status' => $legacyWorkStatus,
            'work_address' => $applicant['work_address'] ?? null,
            'work_street' => $applicant['work_address'] ?? null,
            'work_building_number' => $applicant['work_building_no'] ?? null,
            'work_landmark' => $applicant['work_landmark'] ?? null,
            'salary_amount' => $legacyWorkStatus === 'employee' ? $income : null,
            'pension_amount' => $legacyWorkStatus === 'pension' ? $income : null,
            // Owner 2026-10-04: "اسم العمل" said "شغل حر" for every freelancer -
            // staff need what he actually does, and where.
            'free_work_name' => $this->jobTitle($application, $applicant, $facts),
            'free_work_address' => $applicant['work_address'] ?? null,

            'salary_issue_date' => $this->date($facts['salary_slip']['salary_slip_date'] ?? null),
            'tax_card_expiry' => $this->date($facts['tax_card']['document_expiry_date'] ?? null),
            'commercial_reg_expiry' => str_contains((string) ($facts['self_employed_income_proof']['income_proof_kind'] ?? ''), 'سجل')
                ? $this->date($facts['self_employed_income_proof']['document_expiry_date'] ?? null)
                : null,

            'notes' => $this->notes($application, $applicant, $facts),
        ];

        // The "حالا" system uses the guarantors repeater instead of the
        // single guarantor fields in the deliveries form.
        if ($installmentType === 'حالا' && filled($attributes['guarantor_name'])) {
            $attributes['guarantors'] = [[
                'name' => $attributes['guarantor_name'],
                'phone' => $attributes['guarantor_phone'],
                'address' => null,
            ]];
        }

        $attributes = array_merge($attributes, $this->addressColumns($application, $aiAddressSplit, $applicant, $facts));

        return array_merge($attributes, $this->documents($application));
    }

    /**
     * APP-006: the address columns alone. Without the AI split (submit) the
     * deterministic AddressParser divides the line at once; the AI split
     * runs later in SplitRequestAddresses and fills what staff left as is.
     *
     * @return array<string, ?string>
     */
    public function addressColumns(Application $application, bool $aiSplit = true, ?array $applicant = null, ?array $facts = null): array
    {
        $applicant ??= $this->values($application, 'applicant');
        $facts ??= $this->documentFacts($application);

        $columns = [
            'applicant_street' => $applicant['address'] ?? null,
            'applicant_building_number' => $applicant['address_building_no'] ?? null,
            'applicant_floor' => $applicant['address_floor'] ?? null,
            'applicant_apartment' => $applicant['address_apartment'] ?? null,
            'applicant_landmark' => $applicant['address_landmark'] ?? null,
            'applicant_address' => $this->joinAddress([
                isset($applicant['address_building_no']) ? "رقم العقار {$applicant['address_building_no']}" : null,
                $applicant['address'] ?? null,
                isset($applicant['address_floor']) ? "الدور {$applicant['address_floor']}" : null,
                isset($applicant['address_apartment']) ? "شقة {$applicant['address_apartment']}" : null,
                isset($applicant['address_landmark']) ? "بجوار {$applicant['address_landmark']}" : null,
            ]),
            'work_address' => $applicant['work_address'] ?? null,
            'work_street' => $applicant['work_address'] ?? null,
            'work_building_number' => $applicant['work_building_no'] ?? null,
            'work_landmark' => $applicant['work_landmark'] ?? null,
        ];

        foreach (['applicant' => self::HOME_FIELDS, 'work' => self::WORK_FIELDS] as $prefix => $fields) {
            $split = $this->splitAddress($application, $applicant, $fields, $prefix, $aiSplit);

            // Request 4391: "مدينة بدر ... قطعة 194" has no street - the
            // split put it in area + building, and the whole line stayed
            // in the street column on top of them.
            if ($split !== [] && ! isset($split[$prefix.'_street'])) {
                $columns[$prefix.'_street'] = null;
            }

            $columns = array_merge($columns, $split);
        }

        // Owner 2026-10-04: staff could not see where he works. The name of
        // the company/shop leads the full work address.
        if (($place = $this->workPlaceName($application, $applicant, $facts)) !== null && filled($columns['work_address'] ?? null)
            && ! str_contains((string) $columns['work_address'], $place)) {
            $columns['work_address'] = $place.' - '.$columns['work_address'];
        }

        return $columns;
    }

    /**
     * Where he works, from the most reliable source first: the business name
     * on file, the employer on his salary slip, the business on his tax card
     * or sign, then what he said in the chat (the AI reading of his work).
     */
    private function workPlaceName(Application $application, array $applicant, array $facts): ?string
    {
        $name = $applicant['business_name']
            ?? $facts['salary_slip']['employer_name']
            ?? $facts['insurance_print']['employer_name']
            ?? $facts['tax_card']['business_name']
            ?? $facts['business_place_photo']['business_name']
            ?? null;

        if (blank($name) && $application->origin_conversation_id) {
            $name = app(WorkClassification::class)->reading((int) $application->origin_conversation_id)['workplace_name'] ?? null;
        }

        return filled($name) ? trim((string) $name) : null;
    }

    /**
     * One dashboard column per address part, split from the customer's own
     * words. Falls back to the columns above when the split is unavailable.
     *
     * @return array<string, string>
     */
    private function splitAddress(Application $application, array $values, array $fields, string $prefix, bool $aiSplit = true): array
    {
        $stored = array_filter(array_intersect_key($values, $fields));

        if ($stored === []) {
            return [];
        }

        $parts = $aiSplit ? $this->addresses->split($stored, $this->evidenceMessages($application, array_keys($fields)), $prefix === 'work' ? 'work' : 'home') : [];
        $lineKey = array_search('street', $fields, true);
        $line = (string) ($values[$lineKey] ?? '');
        $parser = app(AddressParser::class);

        // The AI split runs once, inside the submission, and fails when the
        // provider is busy: the marker words and the district list still
        // divide the line (server: 7 of 15 requests had no governorate).
        if ($parts === []) {
            $parts = $line !== '' ? $parser->parse($line) : [];

            if ($parts === [] || ! isset($parts['street'])) {
                $parts['street'] = $line;
            }
        }

        // Request 4276: the split made the street "مميز" and moved "الجامعة
        // الروسية" out of the address into the landmark. What the customer
        // gave field by field is kept as he gave it; the AI only divides the
        // address line itself, and only if no word of it is lost.
        foreach ($fields as $storedKey => $part) {
            if ($storedKey !== $lineKey) {
                unset($parts[$part]);

                if (filled($values[$storedKey] ?? null)) {
                    $parts[$part] = (string) $values[$storedKey];
                }
            }
        }

        // A split that lost a word of his line is not trusted - its governorate
        // neither: a Maadi work address kept "الجيزة" from the home. What the
        // marker words and the district list read off the line itself is kept
        // (server: "34 شارع الشرفاء العشرين فيصل" lost Giza and فيصل this way).
        if ($lineKey && $line !== '' && ! $this->coversLine($line, $parts)) {
            unset($parts['area'], $parts['branch_street'], $parts['governorate']);
            $parts['street'] = $line;

            // only the district named in it - the line itself stays the street
            [$governorate, $district] = $parser->placeIn($line);

            foreach (array_filter(['governorate' => $governorate, 'area' => $district]) as $part => $value) {
                $parts[$part] = $value;
            }
        }

        // the governorate he did not name, from a district in his line or in the area
        if (! isset($parts['governorate'])) {
            $governorate = $parser->placeIn($line)[0] ?? (isset($parts['area']) ? $parser->governorateOf((string) $parts['area']) : null);

            if ($governorate !== null) {
                $parts['governorate'] = $governorate;
            }
        }

        $columns = [];

        foreach ($parts as $part => $value) {
            $columns[$prefix.'_'.$part] = $value;
        }

        if ($prefix === 'applicant') {
            $columns['applicant_address'] = $this->joinAddress([
                isset($parts['building_number']) ? "رقم العقار {$parts['building_number']}" : null,
                isset($parts['street']) ? "شارع {$parts['street']}" : null,
                isset($parts['branch_street']) ? "متفرع من {$parts['branch_street']}" : null,
                isset($parts['area']) ? "المنطقة {$parts['area']}" : null,
                isset($parts['governorate']) ? "محافظة {$parts['governorate']}" : null,
                isset($parts['floor']) ? "الدور {$parts['floor']}" : null,
                isset($parts['apartment']) ? "شقة {$parts['apartment']}" : null,
                isset($parts['landmark']) ? "علامة مميزة: {$parts['landmark']}" : null,
            ]) ?? ($columns['applicant_address'] ?? null);
        } else {
            // "شارع الهرم - الهرم - الجيزة": a part already in the street is not repeated
            $street = (string) ($parts['street'] ?? '');

            if ($street !== '' && str_contains((string) ($values['work_address'] ?? ''), 'شارع '.$street) && ! str_starts_with($street, 'شارع')) {
                $street = 'شارع '.$street;
            }

            $columns['work_address'] = $this->joinAddress(array_map(
                fn ($part) => $part !== null && $street !== '' && $part !== $street && str_contains($street, (string) $part) ? null : $part,
                [$street !== '' ? $street : null, $parts['area'] ?? null, $parts['governorate'] ?? null],
            )) ?? ($values['work_address'] ?? null);
        }

        return array_filter($columns, fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Every word of the customer's address line is still somewhere in the
     * split parts. The building number and landmark count: they hold what
     * he gave for them ("48ش الحرية" keeps 48 as the building; "خلف
     * المحكمة" said in the line is his landmark) - "48ش" alone used to
     * throw away a correct split and put the whole line in the street.
     */
    private function coversLine(string $line, array $parts): bool
    {
        $words = fn (string $text) => array_filter(
            preg_split('/[\s،,\-\/]+/u', preg_replace('/(\d)(?=\D)|(\D)(?=\d)/u', '$1$2 ', \App\Support\ArabicTextNormalizer::normalize($text))),
            fn ($w) => mb_strlen($w) > 1 && ! in_array($w, ['شارع', 'ش', 'في', 'من', 'متفرع', 'محافظه', 'منطقه', 'مدينه', 'رقم', 'عماره', 'عقار', 'قطعه', 'بلوك'], true));
        $split = implode(' ', $words(implode(' ', array_intersect_key($parts, array_flip(['governorate', 'area', 'street', 'branch_street', 'building_number', 'floor', 'apartment', 'landmark'])))));

        foreach ($words($line) as $word) {
            if (! str_contains($split, $word)) {
                return false;
            }
        }

        return true;
    }

    /** @return string[] the customer messages these fields were taken from */
    private function evidenceMessages(Application $application, array $keys): array
    {
        $ids = ApplicationData::where('application_id', $application->id)->whereIn('field_key', $keys)
            ->where('status', 'valid')->pluck('evidence_message_id')
            ->merge(CustomerAttribute::where('customer_id', $application->customer_id)->whereIn('field_key', $keys)
                ->where('status', 'valid')->pluck('evidence_message_id'))
            ->filter()->unique()->all();

        return \App\Models\WhatsappMessage::whereIn('id', $ids)->orderBy('id')->get()
            ->map(fn ($m) => trim((string) ($m->text ?: $m->transcript)))
            ->filter()->values()->all();
    }

    /** @return string[] request columns a document type is copied into */
    public function documentColumns(string $typeKey): array
    {
        return match (true) {
            $typeKey === 'national_id_front' => ['applicant_id_image', 'guarantor_id_image'],
            $typeKey === 'national_id_back' => ['applicant_id_back_image', 'guarantor_id_back_image'],
            isset(self::DOCUMENT_COLUMNS[$typeKey]) => [self::DOCUMENT_COLUMNS[$typeKey][0]],
            default => [],
        };
    }

    /** Re-applies the mapping to an already projected request (backfill). */
    /** Columns the bot itself writes from the chat - safe to rebuild while staff have not touched the request. */
    public const BOT_TEXT_COLUMNS = [
        'applicant_address', 'applicant_governorate', 'applicant_area', 'applicant_street', 'applicant_branch_street',
        'applicant_building_number', 'applicant_floor', 'applicant_apartment', 'applicant_landmark',
        'work_address', 'work_governorate', 'work_area', 'work_street', 'work_branch_street', 'work_building_number', 'work_landmark',
        'free_work_name', 'free_work_address', 'notes',
    ];

    /** @param  string[]  $overwrite  columns to rebuild even when already filled */
    public function refresh(InstallmentRequest $request, array $overwrite = []): void
    {
        $application = $request->application;

        if (! $application) {
            return;
        }

        $attributes = $this->attributes($application, $request->work_status ?: (string) $application->customerType?->legacy_work_status);

        // Never overwrite what staff already filled in by hand.
        $attributes = array_filter(
            $attributes,
            fn ($value, $column) => $column === 'request_type'
                || in_array($column, $overwrite, true)
                || blank($request->getAttribute($column))
                || (str_ends_with($column, '_age_ok') && ! $request->getAttribute($column)),
            ARRAY_FILTER_USE_BOTH
        );

        // a rebuilt column the new projection leaves empty is emptied too (the
        // whole line used to sit in the street column)
        foreach ($overwrite as $column) {
            if (! array_key_exists($column, $attributes)) {
                $attributes[$column] = null;
            }
        }

        $request->forceFill($attributes)->saveQuietly();
    }

    /** @return array<string, string> valid values for one party, application-scope over customer-scope */
    private function values(Application $application, string $party): array
    {
        $values = [];

        if ($party === 'applicant') {
            $values = CustomerAttribute::where('customer_id', $application->customer_id)->where('status', 'valid')->get()
                ->mapWithKeys(fn ($r) => [$r->field_key => (string) $r->value])->all();
        }

        foreach (ApplicationData::where('application_id', $application->id)->where('party', $party)->where('status', 'valid')->get() as $row) {
            $values[$row->field_key] = (string) $row->value;
        }

        return array_filter($values, fn ($v) => trim($v) !== '');
    }

    private function documents(Application $application): array
    {
        $columns = [];

        $documents = ApplicationDocument::where('application_id', $application->id)
            ->where('status', 'accepted')
            ->with('documentType')
            ->orderBy('id')
            ->get();

        foreach ($documents as $document) {
            $typeKey = $document->documentType?->key ?? $document->detected_type_key ?? $document->expected_type_key;

            if ($typeKey === 'national_id_front' || $typeKey === 'national_id_back') {
                $side = $typeKey === 'national_id_back' ? '_back' : '';
                $column = ($document->party === 'guarantor' ? 'guarantor_id' : 'applicant_id').$side.'_image';
                $directory = $document->party === 'guarantor' ? 'installments/guarantors' : 'installments/applicants';
                $isArray = false;
            } elseif (isset(self::DOCUMENT_COLUMNS[$typeKey])) {
                [$column, $directory, $isArray] = self::DOCUMENT_COLUMNS[$typeKey];
            } else {
                continue;
            }

            $path = $this->copyToPublic($document->media_id, $directory);

            if (! $path) {
                continue;
            }

            if ($isArray) {
                $columns[$column][] = $path;
            } else {
                // Latest accepted upload wins.
                $columns[$column] = $path;
            }
        }

        return $columns;
    }

    private function copyToPublic(?int $mediaId, string $directory): ?string
    {
        $media = $mediaId ? MessageMedia::find($mediaId) : null;

        if (! $media || ! $media->path) {
            return null;
        }

        try {
            $source = Storage::disk($media->disk ?: 'local');

            if (! $source->exists($media->path)) {
                return null;
            }

            $extension = pathinfo($media->path, PATHINFO_EXTENSION) ?: 'jpg';
            $target = $directory.'/bot_'.$media->id.'_'.Str::random(8).'.'.$extension;

            Storage::disk('public')->put($target, $source->get($media->path));

            return $target;
        } catch (\Throwable $e) {
            Log::warning('LegacyRequestProjector: could not copy media', ['media_id' => $media->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * What was read off each accepted document beyond the application
     * fields (hire date, employer, slip month...), latest copy per type.
     *
     * @return array<string, array<string, string>> type key => field => value
     */
    private function documentFacts(Application $application): array
    {
        $facts = [];

        $documents = ApplicationDocument::where('application_id', $application->id)
            ->where('status', 'accepted')
            ->with('documentType')
            ->orderBy('id')
            ->get();

        foreach ($documents as $document) {
            $typeKey = $document->documentType?->key ?? $document->detected_type_key;

            try {
                $extracted = (array) $document->extracted;
            } catch (\Throwable) {
                continue;
            }

            if (! $typeKey || $extracted === []) {
                continue;
            }

            $facts[$typeKey] = array_filter(
                array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', $extracted),
                fn ($v) => $v !== ''
            );
        }

        return $facts;
    }

    private function notes(Application $application, array $applicant, array $facts = []): string
    {
        $lines = ['طلب من بوت الواتساب (طلب رقم #'.$application->id.')'];

        $machine = trim(($application->machine?->brand?->name ?? '').' '.($application->machine?->name ?? ''));
        if ($machine !== '') {
            $lines[] = "الموتوسيكل: {$machine}";
        }

        $lines[] = '— بيانات الشغل —';

        foreach ($this->workProfile($application, $applicant, $facts) as $label => $value) {
            $lines[] = "{$label}: {$value}";
        }

        if (CardOnlyRoute::on($application)) {
            $lines[] = 'تقديم بالبطاقة فقط (مسار العمل الحر) - العميل قال إنه مش هيقدر يجيب أوراق شغله';
        }

        if (isset($applicant['residence_ownership'])) {
            $lines[] = 'السكن: '.(self::RESIDENCE_LABELS[$applicant['residence_ownership']] ?? $applicant['residence_ownership']);
        }

        // what else he told the bot about himself, with nothing guessed
        $said = $this->statedFacts($application);

        if ($said !== []) {
            $lines[] = '— قاله العميل في المحادثة —';

            foreach ($said as $label => $value) {
                $lines[] = "{$label}: {$value}";
            }
        }

        // Staff see what the bot read off each document, not only the file.
        foreach ($facts as $values) {
            foreach (array_intersect_key($values, DocumentFields::LABELS) as $key => $value) {
                if (in_array($key, ['period_start', 'period_end', 'app_name'], true)) {
                    continue;
                }

                $line = DocumentFields::LABELS[$key].": {$value}";

                if ($key === 'hire_date' && ($hired = $this->date($value))) {
                    $months = (int) Carbon::parse($hired)->diffInMonths(now());
                    $line .= ' (مدة الخدمة: '.intdiv($months, 12).' سنة و'.($months % 12).' شهر)';
                }

                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    private const RELATION_LABELS = ['owner' => 'صاحب المكان', 'works_for_someone' => 'شغال عند حد', 'independent' => 'شغال لحسابه'];

    /** "صنايعي خراط وعجان - فرن بلدي": what he does, then where. */
    private function jobTitle(Application $application, array $applicant, array $facts): ?string
    {
        $profile = $this->workProfile($application, $applicant, $facts);
        $job = $profile['المهنة'] ?? $profile['الشغل بكلامه'] ?? (self::WORK_TYPE_LABELS[$applicant['work_type'] ?? ''] ?? null);
        $place = $profile['مكان الشغل'] ?? null;

        if ($job === null) {
            return $place;
        }

        return $place !== null && ! str_contains($job, $place) ? "{$job} - {$place}" : $job;
    }

    /**
     * Everything known about his work, each from where it was read: his own
     * words (the chat), the AI reading of them, his memory, and documents.
     *
     * @return array<string, string> label => value
     */
    private function workProfile(Application $application, array $applicant, array $facts): array
    {
        $reading = $application->origin_conversation_id
            ? (app(WorkClassification::class)->reading((int) $application->origin_conversation_id) ?? [])
            : [];
        $memory = $application->customer_id ? app(\App\Domain\Memory\CustomerMemory::class)->get((int) $application->customer_id)['facts'] : [];
        $stated = fn (string $key) => ($memory[$key]['source'] ?? null) === 'customer_statement' ? trim((string) $memory[$key]['value']) : null;

        $income = $applicant['monthly_income'] ?? ($facts['salary_slip']['monthly_income'] ?? null)
            ?? (($reading['stated_monthly_income'] ?? 0) > 0 ? (string) $reading['stated_monthly_income'] : null) ?? $stated('monthly_income');

        $profile = [
            'نوع العميل' => trim((string) $application->customerType?->label) ?: null,
            'المهنة' => filled($reading['occupation'] ?? null) ? $reading['occupation'] : $stated('job'),
            'الشغل بكلامه' => filled($reading['evidence'] ?? null) ? '«'.$reading['evidence'].'»' : (isset($memory['job']['quote']) ? '«'.$memory['job']['quote'].'»' : null),
            'نوع الشغل' => self::WORK_TYPE_LABELS[$applicant['work_type'] ?? ''] ?? null,
            'مكان الشغل' => $this->workPlaceName($application, $applicant, $facts) ?? $stated('workplace'),
            'علاقته بالمكان' => self::RELATION_LABELS[$reading['relation_to_workplace'] ?? ''] ?? null,
            'متأمن عليه' => match ($reading['insured'] ?? null) { 'yes' => 'أيوه', 'no' => 'لأ', default => $stated('insured') },
            'القطاع' => match ($reading['sector'] ?? null) { 'government' => 'حكومي', 'private' => 'خاص', default => null },
            'الدخل الشهري' => $income !== null ? (string) $income : null,
            'عنوان الشغل' => $applicant['work_address'] ?? null,
        ];

        return array_filter($profile, fn ($v) => $v !== null && trim((string) $v) !== '');
    }

    /** @return array<string, string> facts he stated in the chat that the request has no column for */
    private function statedFacts(Application $application): array
    {
        if (! $application->customer_id) {
            return [];
        }

        $labels = ['has_driving_license' => 'الرخصة', 'usage' => 'هيستخدم الموتوسيكل في', 'preferred_duration' => 'المدة اللي عايزها',
            'down_payment' => 'المقدم اللي يقدر عليه', 'monthly_budget' => 'القسط اللي يقدر عليه', 'applicant' => 'مين بيقدّم', 'age' => 'السن (بكلامه)'];
        $facts = app(\App\Domain\Memory\CustomerMemory::class)->get((int) $application->customer_id)['facts'];
        $out = [];

        foreach ($labels as $key => $label) {
            if (($facts[$key]['source'] ?? null) === 'customer_statement' && filled($facts[$key]['value'] ?? null)) {
                $out[$label] = (string) $facts[$key]['value'];
            }
        }

        return $out;
    }

    /** The phone the customer typed, when it differs from the WhatsApp number. */
    private function secondPhone(Application $application, ?string $phone): ?string
    {
        $phone = $this->digits($phone);

        if (! $phone || strlen($phone) !== 11 || $phone === $this->digits($application->customer?->phone)) {
            return null;
        }

        // applicant_phone_2 is unique across the whole table.
        return InstallmentRequest::withTrashed()->where('applicant_phone_2', $phone)->exists() ? null : $phone;
    }

    /** Egyptian national id: C YYMMDD ... where C=2 → 1900s, C=3 → 2000s. */
    private function birthdateFromNationalId(?string $nationalId): ?Carbon
    {
        if (! $nationalId || ! preg_match('/^([23])(\d{2})(\d{2})(\d{2})\d{7}$/', $nationalId, $m)) {
            return null;
        }

        $year = ($m[1] === '2' ? 1900 : 2000) + (int) $m[2];

        return checkdate((int) $m[3], (int) $m[4], $year) ? Carbon::create($year, (int) $m[3], (int) $m[4]) : null;
    }

    private function ageOk(?Carbon $birthdate): bool
    {
        return $birthdate !== null
            && $birthdate->age >= self::MIN_AGE
            && $birthdate->age <= self::MAX_AGE;
    }

    private function digits(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $value = preg_replace('/\D+/', '', $value);

        return $value === '' ? null : $value;
    }

    private function amount(?string $value): ?int
    {
        $value = str_replace([',', '٬', ' '], '', strtr((string) $value, ['٫' => '.'] + array_combine(
            ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'], range(0, 9)
        )));

        return is_numeric($value) && (float) $value > 0 ? (int) round((float) $value) : null;
    }

    private function date(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse(\App\Support\ArabicTextNormalizer::normalize($value))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function joinAddress(array $parts): ?string
    {
        $parts = array_filter(array_map(fn ($p) => $p === null ? null : trim($p), $parts));

        return $parts === [] ? null : implode(' - ', $parts);
    }
}
