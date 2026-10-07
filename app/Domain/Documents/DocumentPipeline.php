<?php

namespace App\Domain\Documents;

use App\Agent\Providers\AiProvider;
use App\Agent\Providers\AiProviderException;
use App\Agent\Providers\AiRequest;
use App\Domain\Applications\CustomerDataService;
use App\Domain\Applications\EligibilityService;
use App\Domain\Applications\RequirementService;
use App\Domain\Applications\Validators\FieldValidatorRegistry;
use App\Domain\Documents\DocumentRules\DocumentRuleRegistry;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\DocumentType;
use App\Models\MessageMedia;
use App\Models\RequirementField;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * T15 §3: the full, auditable document pipeline. Laravel's deterministic
 * validation (step 5) is authoritative - the AI only classifies/extracts,
 * it never decides acceptance.
 */
class DocumentPipeline
{
    /** @var array<int, array> media id => the model's reading, this request only */
    private array $readings = [];

    public function __construct(
        private readonly OcrProvider $ocr,
        private readonly AiProvider $ai,
        private readonly FieldValidatorRegistry $validators,
        private readonly DocumentRuleRegistry $documentRules,
        private readonly RequirementService $requirements,
        private readonly EligibilityService $eligibility,
        private readonly CustomerDataService $customerData,
        private readonly DocumentLifecycle $lifecycle,
    ) {
    }

    /**
     * Reads a document again with the current document types and rules,
     * recording nothing. Used by teach mode to show what changed.
     *
     * @return array{detected_type: ?string, fields: array, issues: array}
     */
    public function preview(MessageMedia $media, ?Application $application): array
    {
        $activeTypes = DocumentType::where('is_active', true)->get();

        try {
            $ocrText = $this->ocr($media);
        } catch (OcrException) {
            $ocrText = '';
        }

        $classification = $this->classify($media, $ocrText, $activeTypes);

        if (! $classification) {
            return ['detected_type' => null, 'fields' => [], 'issues' => [['code' => 'OCR_UNAVAILABLE']]];
        }

        [$typeKey, $legibility, , $fields] = $classification;
        $issues = $application
            ? $this->validate($application, $activeTypes->firstWhere('key', $typeKey), $typeKey, null, $legibility, $fields, $activeTypes)
            : [];

        return ['detected_type' => $typeKey, 'fields' => $fields, 'issues' => $issues];
    }

    /**
     * @return array{media_id: int, document_id: ?int, detected_type: ?string, accepted: bool, status: string, issues: array, applied_fields: string[]}
     */
    public function process(MessageMedia $media, Application $application, ?string $expectedTypeKey = null, bool $replacesPrevious = false, ?array $preRead = null): array
    {
        $activeTypes = DocumentType::where('is_active', true)->get();

        $intakeIssue = $this->intake($media, $activeTypes);

        if ($intakeIssue) {
            return $this->result($media->id, null, null, false, 'rejected', [$intakeIssue], []);
        }

        $duplicate = $this->existingAcceptedDuplicate($application, $media);

        if ($duplicate) {
            return $this->result(
                $media->id, $duplicate->id, $duplicate->detected_type_key, true, 'accepted', [], []
            );
        }

        try {
            $ocrText = $this->ocr($media);
        } catch (OcrException $e) {
            // The exception message is a provider/HTTP error, never document
            // content or extracted text (DEC-22) - safe to log so a real
            // failure is never invisible again (this masked a Gemini schema
            // bug in classify() below for a long time).
            Log::warning('Document OCR failed', ['media_id' => $media->id, 'error' => $e->getMessage()]);

            // The model reads the image itself; the OCR text only helps it.
            // A Vision outage used to fail every document the customer sent.
            $ocrText = '';
        }

        // QA 2026-10-04: a real shop front under a lit neon sign came back
        // "not a document" while the application was waiting for exactly
        // that photo. What he still owes is the likeliest reading.
        $owed = $this->owedDocuments($application);
        // DOC-004: a burst was read in one call (processMany); its reading of this photo is used as is.
        $classification = $preRead !== null
            ? $this->interpret($media, $ocrText, $activeTypes, $preRead)
            : $this->classify($media, $ocrText, $activeTypes, $owed);

        if (! $classification) {
            $document = $this->recordDocument($application, $media, null, $expectedTypeKey, 'failed', null, null, [], [['code' => 'OCR_UNAVAILABLE']]);

            return $this->result($media->id, $document->id, null, false, 'failed', [['code' => 'OCR_UNAVAILABLE']], []);
        }

        [$detectedTypeKey, $legibility, $confidence, $extractedFields, $reread] = $classification;
        $documentType = $activeTypes->firstWhere('key', $detectedTypeKey);
        $extractedFields = $this->trustedNationalId($extractedFields, $ocrText, $application);

        // The customer said the earlier document was wrong (not theirs, old,
        // the wrong person's). It stops counting - with the values read from
        // it - before this one is checked, so the new one is not rejected
        // for disagreeing with the very document it replaces.
        if ($replacesPrevious && $documentType) {
            ApplicationDocument::where('application_id', $application->id)
                ->where('document_type_id', $documentType->id)
                ->where('status', 'accepted')
                ->where('media_id', '!=', $media->id)
                ->get()
                ->each(fn (ApplicationDocument $old) => $this->lifecycle->supersede($old, 'customer_replaced'));
        }

        $issues = $this->validate(
            $application, $documentType, $detectedTypeKey, $expectedTypeKey, $legibility, $extractedFields, $activeTypes
        );

        // QA 2026-10-04: a lit sign "أكلات المعلم" was read "اكل البلد" and the
        // shop was refused against its own tax card. A name that does not match
        // is read once more, carefully - the stored name is never shown to the
        // reader, so a different shop still fails.
        // DOC-001: at most one re-read per photo - not when the reading already had it.
        if ($documentType && ! $reread && array_intersect(array_column($issues, 'code'), ['NAME_MISMATCH', 'BUSINESS_NAME_MISMATCH', 'TAXPAYER_NAME_MISMATCH']) !== []) {
            $reread = $this->fillMissingFields($media, $ocrText, $documentType, $extractedFields, 'high');
            $retry = $this->validate($application, $documentType, $detectedTypeKey, $expectedTypeKey, $legibility, $reread, $activeTypes);

            if (count($retry) < count($issues)) {
                [$issues, $extractedFields] = [$retry, $reread];
            }
        }

        $status = $issues === [] ? 'accepted' : 'rejected';
        $appliedFields = [];

        // Screenshots that add up (period_coverage) are all kept - a second
        // month must not replace the first.
        if ($status === 'accepted' && PeriodCoverage::ruleFor($documentType) === null) {
            $this->supersedeExisting($application, $documentType->id);
        }

        $document = $this->recordDocument(
            $application, $media, $documentType, $expectedTypeKey, $status, $detectedTypeKey, $confidence, $extractedFields, $issues
        );

        if ($status === 'accepted') {
            $normalized = $this->normalizeFields($documentType, $extractedFields);
            $recorded = $this->customerData->recordFromDocument(
                $application->customer, $application, $normalized, $document->id
            );
            $appliedFields = $recorded['applied'];
            $differs = $recorded['differs'];
        }

        $result = $this->result($media->id, $document->id, $detectedTypeKey, $status === 'accepted', $status, $issues, $appliedFields);

        // "البطاقه في ظهرها بدون عمل" got "عادي مفيش مشكلة" and an application
        // as a shop owner. The profession printed on the ID is compared with
        // the work he stated, and "بدون عمل" goes on the file for staff.
        if (filled($extractedFields['occupation'] ?? null)) {
            $result['occupation_on_id'] = (string) $extractedFields['occupation'];
            // Owner 2026-10-04: a job title on the ID makes the salary slip
            // compulsory; "بدون عمل" / "طالب" is no problem at all.
            // what it means for his papers: the instructions (§٧)
            $result['id_shows_job'] = ! \App\Domain\Applications\IdOccupation::isNoJob((string) $extractedFields['occupation']);

            if (\App\Domain\Applications\IdOccupation::isNoJob((string) $extractedFields['occupation'])) {
                \App\Models\ApplicationEvent::create([
                    'application_id' => $application->id,
                    'type' => 'id_without_occupation',
                    'actor' => 'system',
                    'data' => ['occupation' => $extractedFields['occupation']],
                    'created_at' => now(),
                ]);
            }
        }

        // Owner 2026-10-05: a clear place photo whose sign does not read is
        // accepted - the tax card proves the business name. Until that card
        // is in, the agent asks for it (a name typed in the chat is no proof).
        if ($status === 'accepted' && $detectedTypeKey === 'business_place_photo' && blank($extractedFields['business_name'] ?? null)) {
            $result['sign_not_readable'] = ApplicationDocument::where('application_id', $application->id)
                ->where('status', 'accepted')->where('detected_type_key', 'tax_card')->exists()
                ? 'tax_card_on_file' : 'tax_card_needed';
        }

        if (($differs ?? []) !== []) {
            // Not a rejection - an app screen shows the name in English, the
            // ID in Arabic. The agent asks the customer whether it's theirs.
            $result['differs_from_file'] = $differs;
        }

        return $result;
    }

    /**
     * DOC-004: the photos of one burst (front, back, salary slip...) are
     * classified and read in ONE model call, then each is validated on its
     * own. Average 26 s per photo (max 143 s) came from up to four calls per
     * photo, one photo after another. A photo the batch answer misses is
     * read on its own.
     *
     * @param  MessageMedia[]  $medias
     * @param  array<int, ?string>  $expectedByMedia  media id => expected type key
     * @return array<int, array> one result per media, same order
     */
    public function processMany(array $medias, Application $application, array $expectedByMedia = [], bool $replacesPrevious = false): array
    {
        $activeTypes = DocumentType::where('is_active', true)->get();
        $toRead = array_values(array_filter($medias, fn (MessageMedia $m) => $this->intake($m, $activeTypes) === null
            && $this->existingAcceptedDuplicate($application, $m) === null));
        $preRead = [];

        if (count($toRead) > 1) {
            $ocrTexts = array_map(function (MessageMedia $m) {
                try {
                    return $this->ocr($m);
                } catch (OcrException $e) {
                    Log::warning('Document OCR failed', ['media_id' => $m->id, 'error' => $e->getMessage()]);

                    return '';
                }
            }, $toRead);

            $activeKeys = $activeTypes->pluck('key')->all();

            foreach ((array) $this->read($toRead, $ocrTexts, $activeTypes, $this->owedDocuments($application)) as $i => $parsed) {
                // Real customers (2026-10-07): an ID front+back or a license sent together
                // came back unclassified from the batch call and both photos were then
                // marked "not a document" for good - sent alone, the same photos read
                // fine. A batch entry that classified nothing is not an answer: that
                // photo is read on its own, like one the batch left out.
                if (! in_array($parsed['document_type_key'] ?? '', $activeKeys, true)) {
                    continue;
                }

                $preRead[$toRead[$i]->id] = $parsed;
            }
        }

        return array_map(fn (MessageMedia $m) => $this->process($m, $application->refresh(), $expectedByMedia[$m->id] ?? null, $replacesPrevious, $preRead[$m->id] ?? null), $medias);
    }

    private function intake(MessageMedia $media, \Illuminate\Support\Collection $activeTypes): ?array
    {
        $allowedMimes = $activeTypes->pluck('accepted_mimes')->filter()->flatten()->unique();

        if ($allowedMimes->isNotEmpty() && ! $allowedMimes->contains($media->mime)) {
            return ['code' => 'UNSUPPORTED_FILE'];
        }

        $maxBytes = config('agent.media.max_bytes');

        if ($maxBytes && $media->size > $maxBytes) {
            return ['code' => 'UNSUPPORTED_FILE'];
        }

        return null;
    }

    private function existingAcceptedDuplicate(Application $application, MessageMedia $media): ?ApplicationDocument
    {
        if (! $media->sha256) {
            return null;
        }

        return ApplicationDocument::where('application_id', $application->id)
            ->where('status', 'accepted')
            ->whereHas('media', fn ($q) => $q->where('sha256', $media->sha256))
            ->first();
    }

    /** @throws OcrException */
    private function ocr(MessageMedia $media): string
    {
        if (isset($media->analysis['ocr']['text'])) {
            return $media->analysis['ocr']['text'];
        }

        $result = $this->ocr->extractText(base64_encode(Storage::disk($media->disk)->get($media->path)), $media->mime);

        $media->update(['analysis' => array_merge($media->analysis ?? [], ['ocr' => $result->toArray()])]);

        return $result->text;
    }

    /** @return string[] document keys this application still waits for */
    private function owedDocuments(Application $application): array
    {
        try {
            return (array) (app(\App\Domain\Applications\SnapshotService::class)->for($application)['documents']['missing'] ?? []);
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array{0: ?string, 1: string, 2: float, 3: array, 4: bool}|null type, legibility, confidence, fields, re-read done */
    private function classify(MessageMedia $media, string $ocrText, \Illuminate\Support\Collection $activeTypes, array $owed = []): ?array
    {
        $parsed = $this->read([$media], [$ocrText], $activeTypes, $owed)[0] ?? null;

        return $parsed === null ? null : $this->interpret($media, $ocrText, $activeTypes, $parsed);
    }

    /** The model's reading of a photo processed in this request, so validating it again costs no call. */
    public function readingOf(int $mediaId): ?array
    {
        return $this->readings[$mediaId] ?? null;
    }

    /**
     * DOC-001: ONE call classifies and reads every field of the type (it
     * used to classify, then always re-read the fields, then re-read again
     * for a date or a name). One photo = the object schema; a burst = one
     * entry per image.
     *
     * @param  MessageMedia[]  $medias
     * @param  string[]  $ocrTexts  same order as $medias
     * @return array<int, array>|null parsed reading per index; null when the provider failed
     */
    private function read(array $medias, array $ocrTexts, \Illuminate\Support\Collection $activeTypes, array $owed = []): ?array
    {
        // extraction_fields is never enforced here (Gemini's `fields` schema
        // below has no per-type shape to keep the prompt type-agnostic) -
        // but without listing the field keys per type, the model had no
        // vocabulary at all for what to put in `fields`, so it always came
        // back empty and every document failed with MISSING_DATA regardless
        // of what the customer actually sent.
        $typeList = $activeTypes->map(function (DocumentType $t) {
            $required = (array) ($t->extraction_fields ?? []);
            $optional = array_diff((array) ($t->optional_fields ?? []), $required);
            $fieldsNote = ($required !== [] ? ' - extract fields: '.implode(', ', $required) : '')
                .($optional !== [] ? ' - also extract when printed: '.implode(', ', $optional) : '');

            return "{$t->key}: {$t->description_for_ai}{$fieldsNote}";
        })->implode("\n");

        // Regression: an unconstrained `fields: {type: object}` (no declared
        // sub-properties) sent the model into a genuine degenerate output
        // loop - it would emit thousands of space characters instead of the
        // extracted value and hit MAX_TOKENS, every single time a name had
        // to go into it. A strict schema (every known extraction field,
        // named, as an optional string) reliably fixes this. The field set
        // is the union across every active document type's extraction_fields
        // - small and bounded, not per-type (the type isn't known until this
        // same call classifies it).
        $fieldProperties = $activeTypes->flatMap(fn (DocumentType $t) => $t->allFields())
            ->unique()
            ->mapWithKeys(fn ($key) => [$key => DocumentFields::schema($key)])
            ->all();

        $document = [
            // Gemini's responseSchema (like its function-calling schema) has no
            // union types - an empty string means "couldn't classify", handled
            // identically to null below (in_array against active keys).
            'document_type_key' => ['type' => 'string'],
            'legibility' => ['type' => 'string', 'enum' => ['good', 'poor', 'unreadable']],
            'confidence' => ['type' => 'number'],
            'fields' => $fieldProperties !== []
                ? ['type' => 'object', 'properties' => $fieldProperties]
                : ['type' => 'object'],
        ];
        $many = count($medias) > 1;

        $ocrSection = $many
            ? implode("\n\n", array_map(fn ($text, $i) => 'OCR text of image '.($i + 1).":\n".$text, $ocrTexts, array_keys($ocrTexts)))
            : "OCR text:\n".($ocrTexts[0] ?? '');

        $parts = [];

        foreach ($medias as $i => $media) {
            if ($many) {
                $parts[] = ['type' => 'text', 'text' => 'Image '.($i + 1).':'];
            }

            $parts[] = ['type' => 'inline_media', 'mime' => $media->mime, 'base64' => base64_encode(Storage::disk($media->disk)->get($media->path))];
        }

        try {
            $response = $this->ai->chat(new AiRequest(
                purpose: 'document',
                label: 'document',
                system: ($many ? 'Classify EACH of these '.count($medias).' customer-submitted document images (one entry per image, `image` = its number) ' : 'Classify a customer-submitted document image ')
                    ."against these types (key: description):\n{$typeList}\n\n"
                    .'Use a type only if the image really is that document as described (the side and content the description names). '
                    .'If it matches none of them - e.g. the other side of a card when only one side is listed, or a different document - '
                    .'return an empty document_type_key; never force the closest type. '
                    .'Once you identify the type, put ALL its listed fields (exact keys) and their values into `fields`. '
                    .'A field that is not visible on this image is left out - never write "null" or a guess. '
                    // the focused second read's rules, now in the one read (DOC-001)
                    .'Copy every number digit by digit exactly as printed (check it against the OCR text), converting Arabic-Indic digits to 0-9; '
                    .'an amount is the one the field description names (e.g. net = صافي), without currency or thousands separators. '
                    .'A person\'s full name may be printed across several lines (e.g. the first name alone on the line above the rest): '
                    .'join all name lines in reading order. The person\'s name is the person the document is about (the employee, the pensioner), '
                    .'never a manager or signer in the letterhead. '
                    .'Today is '.now()->toDateString().' - resolve relative periods ("آخر 30 يوم", "this month", a month name without a year) against it; dates as YYYY-MM-DD. '
                    .'A date printed with a typo (a 5-digit year like 20246, a missing digit) is read as the date it plainly means from the other dates '
                    .'on the document and today - an issue date can never be before the hire date on the same paper.'
                    .($owed !== [] ? "\nThis customer was just asked for: ".implode(', ', $owed).' - a photo that fits one of these (even a shop front with a lit or stylised sign, '
                        .'or a screenshot in another language) is most likely it; still never force a type it is not.' : '')."\n\n"
                    .$ocrSection,
                contents: [['role' => 'user', 'parts' => $parts]],
                toolMode: 'none',
                temperature: 0.1,
                // Regression: with no explicit thinkingBudget, the model's default
                // "thinking" ate into the (default 1024) output budget, so the
                // JSON response was silently truncated mid-`fields` object on
                // every real document - every single one failed extraction even
                // when classification itself succeeded. This is pure structured
                // extraction with no need for extended reasoning.
                maxOutputTokens: 2048 * max(1, count($medias)),
                thinkingBudget: 0,
                responseSchema: $many
                    ? ['type' => 'object', 'properties' => ['documents' => ['type' => 'array', 'items' => [
                        'type' => 'object', 'properties' => ['image' => ['type' => 'integer']] + $document,
                    ]]]]
                    : ['type' => 'object', 'properties' => $document],
            ));
        } catch (AiProviderException $e) {
            Log::warning('Document classification failed', ['media_ids' => array_map(fn ($m) => $m->id, $medias), 'error' => $e->getMessage()]);

            return null;
        }

        $raw = implode('', $response->textParts);

        if (! $many) {
            return [json_decode($raw, true) ?? $this->salvage($raw)];
        }

        $readings = [];

        foreach ((array) ((json_decode($raw, true) ?? [])['documents'] ?? []) as $entry) {
            $index = (int) ($entry['image'] ?? 0) - 1;

            if (is_array($entry) && isset($medias[$index]) && ! isset($readings[$index])) {
                $readings[$index] = $entry;
            }
        }

        return $readings;
    }

    /**
     * The reading becomes the result. The one optional re-read (DOC-001):
     * only when a field the type requires is missing, or a date contradicts
     * the paper it is on.
     *
     * @return array{0: ?string, 1: string, 2: float, 3: array, 4: bool}
     */
    private function interpret(MessageMedia $media, string $ocrText, \Illuminate\Support\Collection $activeTypes, array $parsed): array
    {
        $this->readings[$media->id] = $parsed;
        $activeKeys = $activeTypes->pluck('key')->all();
        $detectedTypeKey = is_string($parsed['document_type_key'] ?? null) ? strtolower(trim($parsed['document_type_key'])) : null;

        if (! in_array($detectedTypeKey, $activeKeys, true)) {
            $detectedTypeKey = null;
        }

        $fields = $this->withoutEmptyValues(is_array($parsed['fields'] ?? null) ? $parsed['fields'] : []);
        $detectedTypeKey = $this->nationalIdSide($detectedTypeKey, $ocrText, $activeKeys);
        $reread = (bool) ($parsed['_reread'] ?? false);

        if ($detectedTypeKey !== null) {
            $type = $activeTypes->firstWhere('key', $detectedTypeKey);
            // The schema is the union over every type: a salary slip came
            // back with an app_name, which is not this document's to carry.
            $fields = array_intersect_key($fields, array_flip($type->allFields()));

            // The generic read used to skip type-specific fields: a clearly
            // printed license end date came back empty. A required field
            // missing = one focused, typed read of this type.
            $missing = array_diff((array) ($type->extraction_fields ?? []), array_keys($fields));

            // QA 2026-10-04: a letter printed "13/9/20246" was read as 2024 -
            // a year before the hire date on the same paper - and rejected as
            // expired. A date that contradicts the document is read once more.
            $issued = $fields['salary_slip_date'] ?? $fields['pension_statement_date'] ?? null;
            $hired = $fields['hire_date'] ?? null;
            $dateConflict = $issued && $hired && strtotime((string) $issued) && strtotime((string) $hired) && strtotime((string) $issued) < strtotime((string) $hired);

            if (($missing !== [] || $dateConflict) && ! ($parsed['_reread'] ?? false)) {
                $fields = $this->fillMissingFields($media, $ocrText.($dateConflict ? "\n\nNOTE: the issue date you read ({$issued}) is before the hire date ({$hired}) "
                    .'on the same paper - that cannot be. Read the issue date again (a typo in the year is likely).' : ''), $type, $fields);
                $reread = true;
                // checked again later (ID back after the front): the re-read is not paid twice
                $this->readings[$media->id] = ['fields' => $fields, '_reread' => true] + $parsed;
            }

            if ($detectedTypeKey === 'national_id_front') {
                $fields = $this->withFirstNameLine($fields, $ocrText);
            }
        }

        return [
            $detectedTypeKey,
            $parsed['legibility'] ?? 'unreadable',
            (float) ($parsed['confidence'] ?? 0),
            $fields,
            $reread,
        ];
    }

    /**
     * QA 2026-10-04: three clear ID backs in a row came back as fronts and
     * were rejected for "no name", so no customer could finish. The side of
     * an Egyptian ID is printed on it: the front says "بطاقة تحقيق الشخصية",
     * only the back says "البطاقة سارية حتى". The OCR text settles it.
     */
    private function nationalIdSide(?string $typeKey, string $ocrText, array $activeKeys): ?string
    {
        if ($ocrText === '' || ! in_array($typeKey, ['national_id_front', 'national_id_back'], true)) {
            return $typeKey;
        }

        $text = \App\Support\ArabicTextNormalizer::normalize($ocrText);
        $front = str_contains($text, \App\Support\ArabicTextNormalizer::normalize('تحقيق الشخصية'));
        $back = (bool) preg_match('/'.\App\Support\ArabicTextNormalizer::normalize('سارية').'\s*'.\App\Support\ArabicTextNormalizer::normalize('حتى').'/u', $text);

        $side = match (true) {
            $back && ! $front => 'national_id_back',
            $front && ! $back => 'national_id_front',
            default => $typeKey,
        };

        return in_array($side, $activeKeys, true) ? $side : $typeKey;
    }

    /**
     * QA 2026-10-04: "يوسف" / "سلام" / "حسن" - the first name printed alone
     * on the line above the rest of the name - was dropped from every ID
     * front, and the next document then failed NAME_MISMATCH against the
     * short name. In the OCR text the first name is the one-word line just
     * before the name line (the card's header lines may sit in between).
     */
    private function withFirstNameLine(array $fields, string $ocrText): array
    {
        if (blank($fields['full_name'] ?? null) || $ocrText === '') {
            return $fields;
        }

        $norm = fn (string $s) => trim(preg_replace('/\s+/u', ' ', \App\Support\ArabicTextNormalizer::normalize($s)));
        $name = $norm((string) $fields['full_name']);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $ocrText)), fn ($l) => $l !== ''));
        $header = [$norm('جمهورية مصر العربية'), $norm('بطاقة تحقيق الشخصية')];

        foreach ($lines as $i => $line) {
            if ($norm($line) !== $name) {
                continue;
            }

            for ($j = $i - 1; $j >= 0; $j--) {
                if (in_array($norm($lines[$j]), $header, true)) {
                    continue;
                }

                if (preg_match('/^\p{Arabic}{2,15}$/u', $lines[$j]) && ! str_starts_with($name, $norm($lines[$j]).' ')) {
                    $fields['full_name'] = $lines[$j].' '.$fields['full_name'];
                }

                break;
            }

            break;
        }

        return $fields;
    }

    /**
     * The one generic classify call reliably returns the fields every ID
     * carries (name, national_id) but skips or misreads type-specific ones:
     * a clearly printed driving license end date ("نهاية الترخيص") came back
     * empty, and a salary slip gave its name only - no hire date, no
     * employer, and "٦٬٧٧٥" read as 6075. Once the type is known, one
     * focused, typed call reads all of its fields; its values win.
     */
    private function fillMissingFields(MessageMedia $media, string $ocrText, DocumentType $type, array $fields, ?string $effort = null): array
    {
        $all = $type->allFields();

        if ($all === []) {
            return $fields;
        }

        try {
            $response = $this->ai->chat(new AiRequest(
                purpose: 'document',
                label: 'document',
                system: "This document is: {$type->label} - {$type->description_for_ai}\n\n"
                    .'Extract these fields: '.implode(', ', $all).'. Copy every number digit by digit exactly as printed '
                    .'(check it against the OCR text), converting Arabic-Indic digits to 0-9; an amount is the one the field '
                    .'description names (e.g. net = صافي), without currency or thousands separators. Dates as YYYY-MM-DD. '
                    .'Today is '.now()->toDateString().' - resolve relative periods against it. '
                    .'A date printed with a typo (a 5-digit year like 20246, a missing digit) is read as the date it plainly means from the '
                    .'other dates on the document and today - an issue date can never be before the hire date on the same paper. '
                    .'The person\'s name is the person the document is about (the employee, the pensioner), never a manager or signer in the letterhead. '
                    .'Use an empty string only if the value is truly not on the document.'."\n\n"
                    ."OCR text:\n{$ocrText}",
                contents: [
                    ['role' => 'user', 'parts' => [
                        ['type' => 'inline_media', 'mime' => $media->mime, 'base64' => base64_encode(Storage::disk($media->disk)->get($media->path))],
                    ]],
                ],
                toolMode: 'none',
                temperature: 0.1,
                maxOutputTokens: 1024,
                thinkingBudget: 0,
                thinkingLevel: $effort,
                responseSchema: [
                    'type' => 'object',
                    'properties' => collect($all)->mapWithKeys(fn ($key) => [$key => DocumentFields::schema($key)])->all(),
                    'required' => array_values((array) ($type->extraction_fields ?? [])),
                ],
            ));
        } catch (AiProviderException $e) {
            Log::warning('Document field extraction failed', ['media_id' => $media->id, 'error' => $e->getMessage()]);

            return $fields;
        }

        $focused = json_decode(implode('', $response->textParts), true) ?? [];

        foreach ($this->withoutEmptyValues(array_intersect_key(is_array($focused) ? $focused : [], array_flip($all))) as $key => $value) {
            $fields[$key] = $value;
        }

        return $fields;
    }

    /**
     * Simulation 676: a sharp ID back was rejected as "مش واضحة" - the model
     * read 290112610101839, one digit too many, while the front (accepted a
     * minute earlier) said 29011260101839. A number that does not parse is
     * settled from what we can trust, never guessed:
     *  1. exactly one valid 14-digit ID in the OCR text (character-accurate);
     *  2. else the ID already verified from this customer's other side, when
     *     the reading is at most two digits away from it (same card).
     * Otherwise the reading stays as it was and the document is rejected.
     */
    private function trustedNationalId(array $fields, string $ocrText, Application $application): array
    {
        if (! isset($fields['national_id'])) {
            return $fields;
        }

        $parser = app(\App\Support\EgyptianNationalId::class);
        $read = $parser->normalizeDigits((string) $fields['national_id']);

        if ($parser->parse($read)['valid']) {
            // Simulator 2026-10-05: the back of the same card, one digit misread
            // into another valid number, was flagged "differs" and the customer
            // was asked whether the card is his. Two digits off = the same card.
            $known = $this->knownNationalId($application);

            return $known && $read !== $known && levenshtein($read, $known) <= 2 ? ['national_id' => $known] + $fields : $fields;
        }

        // per line: the parser's normalisation drops separators, and the card
        // number line ("KP1248958") was glued onto the ID number below it
        preg_match_all('/(?<!\d)(?:\d[ \-]?){13}\d(?!\d)/', implode("\n", array_map(fn ($line) => $parser->normalizeDigits($line), preg_split('/\R/u', $ocrText))), $runs);
        $valid = array_values(array_unique(array_filter(
            array_map(fn ($run) => preg_replace('/\D/', '', $run), $runs[0]),
            fn ($id) => $parser->parse($id)['valid']
        )));

        if (count($valid) === 1) {
            return ['national_id' => $valid[0]] + $fields;
        }

        $known = $this->knownNationalId($application);

        if ($known && $read !== '' && levenshtein($read, (string) $known) <= 2) {
            return ['national_id' => (string) $known] + $fields;
        }

        // The model's reading may be further off than the OCR's own 14 digits
        // ("4040723016719" read, "40407230106719" printed, one ٣ misread as ٤).
        foreach (array_map(fn ($run) => preg_replace('/\D/', '', $run), $runs[0]) as $ocrRead) {
            if ($known && strlen($ocrRead) === 14 && levenshtein($ocrRead, (string) $known) <= 2) {
                return ['national_id' => (string) $known] + $fields;
            }
        }

        return $fields;
    }

    private function knownNationalId(Application $application): ?string
    {
        $known = \App\Models\ApplicationData::where('application_id', $application->id)->where('party', 'applicant')
            ->where('field_key', 'national_id')->where('status', 'valid')->value('value')
            ?? \App\Models\CustomerAttribute::where('customer_id', $application->customer_id)
                ->where('field_key', 'national_id')->where('status', 'valid')->value('value');

        return $known !== null ? (string) $known : null;
    }

    /**
     * "null", "N/A", "-", "غير موجود" came back as field *values*: a tax card
     * back with no name on it was accepted (full_name = "null") and replaced
     * the front that had the name. An absent value is absent.
     */
    private function withoutEmptyValues(array $fields): array
    {
        $empty = ['', 'null', 'none', 'n/a', 'na', 'nil', 'undefined', 'unknown', '-', '--', '—', 'غير موجود', 'لا يوجد', 'غير واضح', 'مش موجود'];

        return array_filter(
            array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : null, $fields),
            fn ($v) => $v !== null && ! in_array(mb_strtolower($v), $empty, true)
        );
    }

    /**
     * A truncated classification still names its type early in the JSON;
     * keep the type so fillMissingFields can re-read the fields, instead of
     * rejecting the document as unsupported.
     */
    private function salvage(string $raw): array
    {
        $parsed = [];

        foreach (['document_type_key', 'legibility'] as $key) {
            if (preg_match('/"'.$key.'"\s*:\s*"([^"]*)"/u', $raw, $m)) {
                $parsed[$key] = $m[1];
            }
        }

        if (preg_match('/"confidence"\s*:\s*([\d.]+)/', $raw, $m)) {
            $parsed['confidence'] = (float) $m[1];
        }

        return $parsed;
    }

    /** @return array<int, array{code: string, field?: string}> */
    private function validate(
        Application $application,
        ?DocumentType $documentType,
        ?string $detectedTypeKey,
        ?string $expectedTypeKey,
        string $legibility,
        array $extractedFields,
        \Illuminate\Support\Collection $activeTypes,
    ): array {
        if (! $documentType) {
            return [['code' => 'DOCUMENT_NOT_SUPPORTED']];
        }

        if ($expectedTypeKey && $detectedTypeKey !== $expectedTypeKey) {
            // QA 2026-10-04: an Uber earnings screenshot sent with the model's
            // guess "delivery_app_profile" was rejected WRONG_DOCUMENT - the
            // list here was the bare type's (ID only), not this application's
            // (delivery work: license, earnings, profile).
            $requiredKeys = collect(app(\App\Domain\Applications\SnapshotService::class)->for($application)['documents']['required'] ?? [])
                ->merge(collect($this->requirements->requirementsFor($application->customerType)['documents'])->where('required', true)->pluck('key'))
                ->unique();
            // an insurance print sent for the salary slip is that paper, not a wrong one
            $requiredKeys = collect(\App\Domain\Documents\DocumentEquivalents::acceptable($requiredKeys->all()));
            $alreadyAccepted = ApplicationDocument::where('application_id', $application->id)
                ->where('status', 'accepted')->pluck('detected_type_key');

            $acceptedAsRequired = $requiredKeys->contains($detectedTypeKey)
                && (! $alreadyAccepted->contains($detectedTypeKey) || PeriodCoverage::ruleFor($documentType) !== null);

            if (! $acceptedAsRequired) {
                return [['code' => 'WRONG_DOCUMENT']];
            }
        }

        if ($legibility === 'unreadable') {
            return [['code' => 'UNREADABLE']];
        }

        $issues = [];

        foreach ((array) ($documentType->extraction_fields ?? []) as $fieldKey) {
            if (! array_key_exists($fieldKey, $extractedFields) || $extractedFields[$fieldKey] === null || $extractedFields[$fieldKey] === '') {
                // Server history: 7 of 8 app profile screenshots were refused
                // MISSING_DATA app_name (conversation 830: six tries, "ده كل
                // حاجه وضحه") - the profile page rarely prints the app's
                // name. The app is already known from his earnings
                // screenshot or from what he said he works on.
                if ($fieldKey === 'app_name' && $this->knownAppName($application) !== null) {
                    continue;
                }

                $issues[] = ['code' => 'MISSING_DATA', 'field' => $fieldKey];
            }
        }

        $facts = [];

        foreach ($extractedFields as $fieldKey => $value) {
            $field = RequirementField::where('key', $fieldKey)->where('is_active', true)->first();

            if (! $field) {
                continue;
            }

            $result = $this->validators->for($field->data_type)?->validate((string) $value, $field);

            if (! $result || ! $result->valid) {
                // An optional value that does not read cleanly is left out
                // (normalizeFields), never a reason to reject the document.
                if (! in_array($fieldKey, (array) ($documentType->extraction_fields ?? []), true)) {
                    continue;
                }

                $issues[] = ['code' => 'INVALID_FORMAT', 'field' => $fieldKey];

                continue;
            }

            $facts = array_merge($facts, $result->facts);
        }

        foreach ((array) ($documentType->validation_rules ?? []) as $rule) {
            $evaluator = $this->documentRules->for($rule['rule_type']);
            $violation = $evaluator?->evaluate($rule['params'] ?? [], $extractedFields, $application);

            if ($violation) {
                $issues[] = $violation;
            }
        }

        // QA 2026-10-04: a sharp ID back, every field read and valid (the
        // number matching the front), was rejected "blurry" on the model's
        // own "poor". A poor photo is only a reason when it cost a field.
        if ($legibility === 'poor' && $issues !== []) {
            return [['code' => 'BLURRY_DOCUMENT']];
        }

        // Eligibility (e.g. age) is not a property of the document: a valid
        // ID of someone over the age limit is still a valid ID. It used to be
        // rejected here, so the ID's data never reached the application and
        // the customer was asked for the same card again. Eligibility is
        // decided on the application snapshot, from the accepted data.
        return $issues;
    }

    private function normalizeFields(DocumentType $documentType, array $extractedFields): array
    {
        $normalized = [];

        $required = (array) ($documentType->extraction_fields ?? []);

        foreach ($documentType->allFields() as $fieldKey) {
            if (! array_key_exists($fieldKey, $extractedFields)) {
                continue;
            }

            $field = RequirementField::where('key', $fieldKey)->where('is_active', true)->first();
            $result = $field ? $this->validators->for($field->data_type)?->validate((string) $extractedFields[$fieldKey], $field) : null;

            if ($field && ! $result?->valid && ! in_array($fieldKey, $required, true)) {
                continue;
            }

            $normalized[$fieldKey] = $result?->valid ? $result->normalized : $extractedFields[$fieldKey];
        }

        return $normalized;
    }

    private function supersedeExisting(Application $application, int $documentTypeId): void
    {
        ApplicationDocument::where('application_id', $application->id)
            ->where('document_type_id', $documentTypeId)
            ->where('status', 'accepted')
            ->get()
            ->each(fn (ApplicationDocument $old) => $this->lifecycle->supersede($old, 'newer_copy_accepted'));
    }

    private function recordDocument(
        Application $application,
        MessageMedia $media,
        ?DocumentType $documentType,
        ?string $expectedTypeKey,
        string $status,
        ?string $detectedTypeKey,
        ?float $confidence,
        array $extractedFields,
        array $issues,
    ): ApplicationDocument {
        return ApplicationDocument::create([
            'application_id' => $application->id,
            'document_type_id' => $documentType?->id,
            'media_id' => $media->id,
            'party' => 'applicant',
            'status' => $status,
            'detected_type_key' => $detectedTypeKey,
            'expected_type_key' => $expectedTypeKey,
            'confidence' => $confidence,
            'extracted' => $extractedFields === [] ? null : $extractedFields,
            'issues' => $issues,
            'attempts' => 1,
        ]);
    }

    private function result(int $mediaId, ?int $documentId, ?string $detectedType, bool $accepted, string $status, array $issues, array $appliedFields): array
    {
        return [
            'media_id' => $mediaId,
            'document_id' => $documentId,
            'detected_type' => $detectedType,
            'accepted' => $accepted,
            'status' => $status,
            'issues' => $issues,
            'applied_fields' => $appliedFields,
        ];
    }

    /** The delivery app he works on: from an accepted earnings screenshot, else his own words. */
    private function knownAppName(Application $application): ?string
    {
        foreach (ApplicationDocument::where('application_id', $application->id)->where('status', 'accepted')
            ->where('detected_type_key', 'delivery_app_earnings')->latest('id')->get(['id', 'extracted'])->pluck('extracted') as $extracted) {
            $fields = is_array($extracted) ? $extracted : (json_decode((string) $extracted, true) ?: []);

            if (filled($fields['app_name'] ?? null)) {
                return (string) $fields['app_name'];
            }
        }

        $memory = app(\App\Domain\Memory\CustomerMemory::class)->get((int) $application->customer_id)['facts'];

        foreach (['workplace', 'job'] as $key) {
            $value = (string) ($memory[$key]['value'] ?? '');

            if (($memory[$key]['source'] ?? null) === 'customer_statement' && preg_match('/اوبر|أوبر|uber|ديدي|didi|طلبات|talabat|مرسول|بولت|bolt|اندرايف|إندرايف|indrive|نون|noon|بريد فاست|breadfast|رابيت|rabbit|جاهز|كريم|careem|swvl|سويفل/iu', $value, $m)) {
                return $m[0];
            }
        }

        $place = $application->origin_conversation_id
            ? (string) (app(\App\Domain\Applications\WorkClassification::class)->reading((int) $application->origin_conversation_id)['workplace_name'] ?? '')
            : '';

        return $place !== '' ? $place : null;
    }
}
