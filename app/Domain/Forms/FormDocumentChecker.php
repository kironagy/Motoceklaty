<?php

namespace App\Domain\Forms;

use App\Agent\Providers\AiProvider;
use App\Agent\Providers\AiProviderException;
use App\Agent\Providers\AiRequest;
use App\Domain\Documents\DocumentEquivalents;
use App\Domain\Documents\DocumentRules\DocumentRuleRegistry;
use App\Models\Application;
use App\Models\DocumentType;
use App\Models\FormLinkFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Checks one form upload while the customer is still on the page (spec §4.4): the document
 * type the field asks for, legibility, the fields that type must show, the type's own rules
 * (expiry ...) and the owner's free-text instructions for that field. It warns, it never
 * blocks the submit - staff decide (owner: a wrong AI reading must not stop a good request).
 */
class FormDocumentChecker
{
    /** What the customer reads under the file, per issue code. */
    private const MESSAGES = [
        'WRONG_DOCUMENT' => 'الصورة دي مش :expected. ارفع :expected.',
        'UNREADABLE' => 'الصورة مش واضحة. صوّرها تاني في نور كويس ومن غير ما حاجة تغطي الكلام.',
        'BLURRY_DOCUMENT' => 'الصورة مش واضحة كفاية. صوّرها تاني في نور كويس.',
        'MISSING_DATA' => 'مش باين كل البيانات في الصورة. صوّر المستند كله.',
        'EXPIRED_DOCUMENT' => 'المستند ده قديم أو منتهي. ارفع نسخة أحدث.',
        'NATIONAL_ID_MISMATCH' => 'الرقم القومي في البطاقة مش زي اللي كتبته في الفورم. راجعه.',
        'NOT_A_DOCUMENT' => 'مش قادرين نتعرف على المستند في الصورة. ارفع :expected.',
    ];

    public function __construct(
        private readonly \App\Domain\Documents\DocumentPipeline $pipeline,
        private readonly DocumentRuleRegistry $rules,
        private readonly AiProvider $ai,
    ) {
    }

    public function check(FormLinkFile $file): void
    {
        $link = $file->link;
        $field = $link->version->formSchema()->field($file->field_key);
        $media = $file->media;

        if (! $field || ! $media) {
            $file->update(['check_status' => 'failed']);

            return;
        }

        // "multiple images" (app screenshots): no document to recognise - only the owner's
        // instructions, when he wrote any, are checked
        if ($field['type'] === 'images') {
            $issues = filled($field['instructions'] ?? null) && str_starts_with((string) $media->mime, 'image/')
                ? $this->ownerInstructions($media, (string) $field['instructions'], (string) $field['label'])
                : [];

            $file->update([
                'check_status' => $issues === [] ? 'ok' : 'warning',
                'issues' => $issues,
                'message' => $issues === [] ? null : $this->message($issues[0], (string) $field['label']),
            ]);

            return;
        }

        $expectedKey = $this->expectedType($field);
        $expected = $expectedKey ? DocumentType::where('key', $expectedKey)->first() : null;

        // a video (shop) or PDF the reader cannot see is left to staff
        if (! str_starts_with((string) $media->mime, 'image/') && $media->mime !== 'application/pdf') {
            $file->update(['check_status' => 'ok']);

            return;
        }

        $reading = $this->pipeline->readForForm($media, $expectedKey);

        if ($reading === null) {
            $file->update(['check_status' => 'failed']);

            return;
        }

        $issues = $this->issues($reading, $expected, $expectedKey, $link->answerValues(), $link->version->formSchema());

        if ($issues === [] && filled($field['instructions'] ?? null)) {
            $issues = $this->ownerInstructions($media, (string) $field['instructions'], (string) $field['label']);
        }

        $this->fillFromCard($link, $field, $reading);

        $file->update([
            'check_status' => $issues === [] ? 'ok' : 'warning',
            'detected_type_key' => $reading['type'],
            'extracted' => $reading['fields'],
            'issues' => $issues,
            'message' => $issues === [] ? null : $this->message($issues[0], $expected?->label ?? $field['label']),
        ]);
    }

    /**
     * He changed a typed national id: the cards already read are compared again (no new model
     * call) - correcting the number clears the warning without uploading again.
     */
    public function recheckNationalId(\App\Models\FormLink $link): void
    {
        $schema = $link->version->formSchema();
        $answers = $link->answerValues();
        $ids = app(\App\Support\EgyptianNationalId::class);
        $typed = array_filter(array_map(
            fn ($f) => $ids->normalizeDigits((string) ($answers[$f['key']] ?? '')),
            array_filter($schema->fields(), fn ($f) => $f['type'] === 'national_id')
        ), fn ($v) => strlen($v) === 14);

        foreach (FormLinkFile::where('form_link_id', $link->id)->whereIn('check_status', ['ok', 'warning'])->get() as $file) {
            $onCard = $ids->normalizeDigits((string) ($file->extracted['national_id'] ?? ''));

            if (strlen($onCard) !== 14) {
                continue;
            }

            $issues = array_values(array_filter((array) $file->issues, fn ($i) => ($i['code'] ?? null) !== 'NATIONAL_ID_MISMATCH'));

            if ($typed !== [] && ! in_array($onCard, $typed, true)) {
                $issues[] = ['code' => 'NATIONAL_ID_MISMATCH'];
            }

            $field = $schema->field($file->field_key);
            $expected = ($key = $field ? $this->expectedType($field) : null) ? DocumentType::where('key', $key)->value('label') : null;

            $file->update([
                'issues' => $issues,
                'check_status' => $issues === [] ? 'ok' : 'warning',
                'message' => $issues === [] ? null : $this->message($issues[0], $expected ?? ($field['label'] ?? '')),
            ]);
        }
    }

    /**
     * His own ID card was read: the name and national id he has not typed yet are filled from it
     * (never over what he wrote - a difference is flagged instead, NATIONAL_ID_MISMATCH).
     */
    private function fillFromCard(\App\Models\FormLink $link, array $field, array $reading): void
    {
        $binding = (string) ($field['binding'] ?? '');

        if ($reading['type'] !== 'national_id_front' || ! in_array($binding, ['applicant.id_front', ''], true)) {
            return;
        }

        $schema = $link->version->formSchema();
        $answers = $link->answerValues();
        $values = [
            'applicant.name' => trim((string) ($reading['fields']['full_name'] ?? '')),
            'applicant.national_id' => app(\App\Support\EgyptianNationalId::class)->normalizeDigits((string) ($reading['fields']['national_id'] ?? '')),
        ];

        foreach ($values as $target => $value) {
            $answerField = $schema->fieldBoundTo($target);

            if ($value === '' || ! $answerField || ! $schema->isEmpty($answerField, $answers[$answerField['key']] ?? null)) {
                continue;
            }

            // a reading that breaks the field's own rules (age, length) is still his - the page
            // shows the rule's message, so it is filled only when it is a well-formed value
            if ($answerField['type'] === 'national_id' && ! app(\App\Support\EgyptianNationalId::class)->parse($value)['valid']) {
                continue;
            }

            \App\Models\FormLinkAnswer::updateOrCreate(['form_link_id' => $link->id, 'field_key' => $answerField['key']], ['value' => $value]);
        }
    }

    public function expectedType(array $field): ?string
    {
        return filled($field['document_type'] ?? null)
            ? (string) $field['document_type']
            : (FormBindings::get($field['binding'] ?? null)['document_type'] ?? null);
    }

    private function issues(array $reading, ?DocumentType $expected, ?string $expectedKey, array $answers, FormSchema $schema): array
    {
        $type = $reading['type'];

        if ($expectedKey && $type !== $expectedKey && ! in_array($type, DocumentEquivalents::acceptable([$expectedKey]), true)) {
            return [['code' => $type ? 'WRONG_DOCUMENT' : 'NOT_A_DOCUMENT', 'detected' => $type]];
        }

        if ($reading['legibility'] === 'unreadable') {
            return [['code' => 'UNREADABLE']];
        }

        $documentType = $expected && $type === $expectedKey ? $expected : DocumentType::where('key', $type)->first();

        if (! $documentType) {
            return [];
        }

        $issues = [];

        foreach ((array) ($documentType->extraction_fields ?? []) as $key) {
            if (blank($reading['fields'][$key] ?? null)) {
                $issues[] = ['code' => 'MISSING_DATA', 'field' => $key];
            }
        }

        foreach ((array) ($documentType->validation_rules ?? []) as $rule) {
            // compared below with what he typed - there is no application yet
            if (($rule['rule_type'] ?? null) === 'matches_application_field') {
                continue;
            }

            if ($violation = $this->rules->for($rule['rule_type'])?->evaluate($rule['params'] ?? [], $reading['fields'], new Application())) {
                $issues[] = $violation;
            }
        }

        // the number on the card against the number typed in the form
        $onCard = app(\App\Support\EgyptianNationalId::class)->normalizeDigits((string) ($reading['fields']['national_id'] ?? ''));

        if (strlen($onCard) === 14) {
            $typed = array_filter(array_map(
                fn ($f) => app(\App\Support\EgyptianNationalId::class)->normalizeDigits((string) ($answers[$f['key']] ?? '')),
                array_filter($schema->fields(), fn ($f) => $f['type'] === 'national_id')
            ), fn ($v) => strlen($v) === 14);

            if ($typed !== [] && ! in_array($onCard, $typed, true)) {
                $issues[] = ['code' => 'NATIONAL_ID_MISMATCH'];
            }
        }

        if ($reading['legibility'] === 'poor' && $issues !== []) {
            return [['code' => 'BLURRY_DOCUMENT']];
        }

        return $issues;
    }

    /** The owner's own words for this document ("لازم يكون عليها ختم جهة العمل"), judged by the model. */
    private function ownerInstructions(\App\Models\MessageMedia $media, string $instructions, string $label): array
    {
        try {
            $response = $this->ai->chat(new AiRequest(
                system: 'You check one document a customer uploaded for a motorcycle installment application ('.$label.'). '
                    .'The business requires exactly this about it: "'.$instructions.'". '
                    .'Decide only whether the image clearly fails that requirement. When unsure, it passes. '
                    .'reason_ar: one short Egyptian Arabic sentence telling the customer what to fix (empty when it passes).',
                contents: [['role' => 'user', 'parts' => [
                    ['type' => 'inline_media', 'mime' => $media->mime, 'base64' => base64_encode(Storage::disk($media->disk)->get($media->path))],
                ]]],
                toolMode: 'none',
                temperature: 0.1,
                maxOutputTokens: 300,
                thinkingBudget: 0,
                responseSchema: ['type' => 'object', 'properties' => [
                    'passes' => ['type' => 'boolean'],
                    'reason_ar' => ['type' => 'string'],
                ]],
                purpose: 'document',
                label: 'form_document_instructions',
            ));
        } catch (AiProviderException $e) {
            Log::warning('Form document instructions check failed', ['media_id' => $media->id, 'error' => $e->getMessage()]);

            return [];
        }

        $verdict = json_decode(implode('', $response->textParts), true) ?? [];

        return ($verdict['passes'] ?? true) === false
            ? [['code' => 'OWNER_INSTRUCTIONS', 'reason' => trim((string) ($verdict['reason_ar'] ?? ''))]]
            : [];
    }

    private function message(array $issue, string $expectedLabel): string
    {
        if ($issue['code'] === 'OWNER_INSTRUCTIONS') {
            return filled($issue['reason'] ?? null) ? $issue['reason'] : 'المستند ده مش مطابق للمطلوب. راجعه وارفعه تاني.';
        }

        return str_replace(':expected', $expectedLabel, self::MESSAGES[$issue['code']] ?? 'راجع الصورة دي وارفعها تاني لو فيها مشكلة.');
    }
}
