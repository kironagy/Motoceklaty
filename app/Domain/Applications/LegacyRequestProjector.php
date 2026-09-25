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

    /** شرط السن: من 21 لـ 62 سنة */
    private const MIN_AGE = 21;

    private const MAX_AGE = 62;

    private const WORK_TYPE_LABELS = [
        'delivery_app' => 'دليفري تطبيق (موتوسيكل)',
        'delivery_app_bicycle' => 'دليفري تطبيق (عجلة)',
        'delivery_company' => 'دليفري مطعم / شركة',
        'craftsman' => 'صاحب مهنة',
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
    public function attributes(Application $application, string $legacyWorkStatus): array
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
            'applicant_landmark' => $applicant['address_landmark'] ?? null,
            'applicant_address' => $this->joinAddress([
                isset($applicant['address_building_no']) ? "رقم العقار {$applicant['address_building_no']}" : null,
                $applicant['address'] ?? null,
                isset($applicant['address_floor']) ? "الدور {$applicant['address_floor']}" : null,
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
            'free_work_name' => $applicant['business_name'] ?? (self::WORK_TYPE_LABELS[$applicant['work_type'] ?? ''] ?? null),
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

        return array_merge($attributes, $this->documents($application));
    }

    /** Re-applies the mapping to an already projected request (backfill). */
    public function refresh(InstallmentRequest $request): void
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
                || blank($request->getAttribute($column))
                || (str_ends_with($column, '_age_ok') && ! $request->getAttribute($column)),
            ARRAY_FILTER_USE_BOTH
        );

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

            if ($typeKey === 'national_id_front') {
                $column = $document->party === 'guarantor' ? 'guarantor_id_image' : 'applicant_id_image';
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

        if (isset($applicant['work_type'])) {
            $lines[] = 'نوع الشغل: '.(self::WORK_TYPE_LABELS[$applicant['work_type']] ?? $applicant['work_type']);
        }

        if (isset($applicant['business_name'])) {
            $lines[] = "اسم النشاط: {$applicant['business_name']}";
        }

        if (isset($applicant['monthly_income'])) {
            $lines[] = "الدخل الشهري: {$applicant['monthly_income']}";
        }

        if (isset($applicant['residence_ownership'])) {
            $lines[] = 'السكن: '.(self::RESIDENCE_LABELS[$applicant['residence_ownership']] ?? $applicant['residence_ownership']);
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
