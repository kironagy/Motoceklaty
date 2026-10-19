<?php

namespace App\Domain\Forms;

use App\Models\FormLink;
use App\Models\FormLinkFile;
use App\Models\InstallmentRequest;
use Illuminate\Support\HtmlString;

/**
 * What the deliveries screen shows for a request that came from a form (spec §4.8): the answers
 * not tied to any request column, and every upload the AI warned about.
 */
class FormRequestExtras
{
    /** @return array{answers: array<string, string>, warnings: list<array{label: string, message: string, url: ?string}>} */
    public function for(InstallmentRequest $request): array
    {
        $link = $request->form_link_id ? FormLink::with('version')->find($request->form_link_id) : null;

        if (! $link) {
            return ['answers' => [], 'warnings' => []];
        }

        $schema = $link->version->formSchema();
        $answers = $link->answerValues();
        $visible = $schema->visible($answers);
        $extra = [];

        foreach ($schema->fields() as $key => $field) {
            if ($field['binding'] || in_array($field['type'], FormSchema::FILE_TYPES, true) || ! isset($visible[$key]) || $schema->isEmpty($field, $answers[$key] ?? null)) {
                continue;
            }

            $value = $answers[$key];
            $extra[$field['label']] = match ($field['type']) {
                'select', 'radio' => collect($field['options'])->firstWhere('value', $value)['label'] ?? (string) $value,
                'checkbox' => collect($field['options'])->whereIn('value', (array) $value)->pluck('label')->implode('، '),
                'yes_no' => $value === 'yes' ? 'نعم' : 'لا',
                'group' => FormSchema::groupText($field, $value),
                'address' => (string) FormBindings::joinAddress((array) $value),
                default => is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE),
            };
        }

        $warnings = FormLinkFile::where('form_link_id', $link->id)->where('check_status', 'warning')->with('media')->get()
            ->map(fn (FormLinkFile $f) => [
                'label' => $schema->field($f->field_key)['label'] ?? $f->field_key,
                'message' => (string) $f->message,
                'url' => $f->media ? route('staff.media.show', $f->media) : null,
            ])->all();

        return ['answers' => $extra, 'warnings' => $warnings];
    }

    public function html(InstallmentRequest $request): HtmlString
    {
        $data = $this->for($request);
        $html = '';

        foreach ($data['warnings'] as $w) {
            $html .= '<div style="color:#b85c00;margin-bottom:6px">⚠️ <b>'.e($w['label']).':</b> '.e($w['message'])
                .($w['url'] ? ' - <a href="'.e($w['url']).'" target="_blank" style="text-decoration:underline">افتح الملف</a>' : '').'</div>';
        }

        foreach ($data['answers'] as $label => $value) {
            $html .= '<div style="margin-bottom:4px"><b>'.e($label).':</b> '.e($value).'</div>';
        }

        return new HtmlString($html !== '' ? $html : '<span style="opacity:.6">مفيش بيانات إضافية.</span>');
    }
}
