<?php

namespace App\Filament\Resources\DynamicFormResource\Pages;

use App\Domain\Forms\FormBindings;
use App\Domain\Forms\FormPublisher;
use App\Domain\Forms\FormSchema;
use App\Filament\Resources\DynamicFormResource;
use App\Models\CustomerType;
use App\Models\DocumentType;
use App\Models\DynamicForm;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

/**
 * Dynamic Links: the visual form builder. The form is edited as the customer will see it -
 * the page keeps the whole form in the browser and saves it in one call.
 */
class BuildDynamicForm extends Page
{
    use InteractsWithRecord;

    protected static string $resource = DynamicFormResource::class;

    protected static string $view = 'filament.resources.dynamic-forms.builder';

    protected ?string $maxContentWidth = 'full';

    public const ICONS = [
        'text' => 'heroicon-o-pencil', 'textarea' => 'heroicon-o-document-text', 'number' => 'heroicon-o-hashtag',
        'money' => 'heroicon-o-banknotes', 'phone' => 'heroicon-o-device-phone-mobile', 'national_id' => 'heroicon-o-identification',
        'date' => 'heroicon-o-calendar-days', 'select' => 'heroicon-o-chevron-up-down', 'radio' => 'heroicon-o-stop-circle',
        'checkbox' => 'heroicon-o-check-circle', 'yes_no' => 'heroicon-o-hand-thumb-up', 'address' => 'heroicon-o-map-pin',
        'document' => 'heroicon-o-camera', 'images' => 'heroicon-o-photo', 'machine' => 'heroicon-o-cube', 'plan' => 'heroicon-o-calculator',
        'group' => 'heroicon-o-user-group', 'note' => 'heroicon-o-information-circle', 'stop' => 'heroicon-o-no-symbol',
    ];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(DynamicFormResource::canEdit($this->record), 403);
    }

    public function getTitle(): string
    {
        return $this->record->name;
    }

    /** The name is edited in the builder's own bar. */
    public function getHeading(): string
    {
        return '';
    }

    public function getBreadcrumbs(): array
    {
        return [DynamicFormResource::getUrl() => 'فورمات التقديم', 'بناء الفورم'];
    }

    /** Everything the builder needs, once. */
    public function payload(): array
    {
        /** @var DynamicForm $form */
        $form = $this->record;
        $published = $form->publishedVersion;

        return [
            'form' => [
                'name' => $form->name,
                'description' => $form->description,
                'is_bot_default' => $form->is_bot_default,
                'is_active' => $form->is_active,
            ],
            'schema' => $this->clientSchema($form->schema ?? []),
            'published' => $published ? ['version' => $published->version,
                'same' => json_encode($this->cleanSchema($this->clientArray($published->schema ?? []))) === json_encode($this->cleanSchema($this->clientArray($form->schema ?? [])))] : null,
            'types' => FormSchema::TYPES,
            'icons' => collect(self::ICONS)->map(fn ($icon) => svg($icon)->toHtml())->all(),
            'bindings' => collect(FormBindings::all())->map(fn ($b, $key) => [
                'key' => $key, 'label' => $b['label'], 'group' => $b['group'], 'types' => $b['types'],
                'document_type' => $b['document_type'] ?? null,
            ])->values()->all(),
            'documentTypes' => DocumentType::where('is_active', true)->pluck('label', 'key'),
            'customerTypes' => CustomerType::where('is_active', true)->pluck('label', 'id'),
            'operators' => ['in' => 'واحد من'] + FormSchema::OPERATORS,
            'addressParts' => FormBindings::ADDRESS_PARTS,
            'governorates' => array_values(config('agent.governorates', [])),
        ];
    }

    /** The client shape as plain arrays, so two stored forms compare by content. */
    private function clientArray(array $schema): array
    {
        return json_decode(json_encode($this->clientSchema($schema)), true);
    }

    /** Builder items keep the {type, data} shape the schema stores, as a list. */
    private function clientSchema(array $schema): array
    {
        return [
            'settings' => (object) ($schema['settings'] ?? []),
            'sections' => array_values(array_map(fn ($section) => array_merge($section, [
                'items' => array_values(array_map(fn ($item) => [
                    'type' => $item['type'] ?? ($item['data']['type'] ?? 'text'),
                    'data' => (object) ($item['data'] ?? $item),
                ], (array) ($section['items'] ?? []))),
                'conditions' => array_values((array) ($section['conditions'] ?? [])),
            ]), (array) ($schema['sections'] ?? []))),
        ];
    }

    /** @return array{ok: bool, saved_at: string} */
    public function saveForm(array $form, array $schema): array
    {
        abort_unless(DynamicFormResource::canEdit($this->record), 403);

        $this->record->update([
            'name' => trim((string) ($form['name'] ?? '')) ?: $this->record->name,
            'description' => $form['description'] ?? null,
            'is_bot_default' => (bool) ($form['is_bot_default'] ?? false),
            'is_active' => (bool) ($form['is_active'] ?? true),
            'schema' => $this->cleanSchema($schema),
        ]);

        app(FormPublisher::class)->keepOneBotDefault($this->record);

        return ['ok' => true, 'saved_at' => now()->format('H:i')];
    }

    /** @return array{ok: bool, problems?: string[], version?: int} */
    public function publishForm(array $form, array $schema): array
    {
        $this->saveForm($form, $schema);
        $problems = app(FormPublisher::class)->publish($this->record->refresh(), auth()->id());

        return $problems === []
            ? ['ok' => true, 'version' => $this->record->refresh()->publishedVersion?->version]
            : ['ok' => false, 'problems' => $problems];
    }

    /** @return array{url: string} */
    public function previewForm(array $form, array $schema): array
    {
        $this->saveForm($form, $schema);

        return ['url' => app(FormPublisher::class)->preview($this->record->refresh(), auth()->id())->url()];
    }

    /** Drops empty values the browser keeps around, so the stored JSON stays readable. */
    private function cleanSchema(array $schema): array
    {
        $clean = function ($value) {
            if (! is_array($value)) {
                return $value;
            }

            $kept = array_filter($value, fn ($v) => ! ($v === null || $v === '' || $v === []));

            return array_is_list($value) ? array_values($kept) : $kept;
        };

        return [
            'settings' => $clean((array) ($schema['settings'] ?? [])),
            'sections' => array_values(array_map(fn ($section) => [
                'key' => (string) ($section['key'] ?? ''),
                'title' => (string) ($section['title'] ?? ''),
                'description' => $section['description'] ?? null,
                'match' => $section['match'] ?? 'all',
                'conditions' => array_values((array) ($section['conditions'] ?? [])),
                'items' => array_values(array_map(fn ($item) => [
                    'type' => (string) ($item['type'] ?? 'text'),
                    'data' => array_map($clean, $clean((array) ($item['data'] ?? []))),
                ], (array) ($section['items'] ?? []))),
            ], (array) ($schema['sections'] ?? []))),
        ];
    }
}
