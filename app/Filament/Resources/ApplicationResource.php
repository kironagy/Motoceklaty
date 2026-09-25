<?php

namespace App\Filament\Resources;

use App\Domain\Applications\SnapshotService;
use App\Filament\Resources\ApplicationResource\Pages;
use App\Models\Application;
use App\Models\ApplicationData;
use App\Models\ApplicationDocument;
use App\Models\DocumentType;
use App\Models\RequirementField;
use App\Support\DashboardLabels;
use Filament\Infolists\Components\Fieldset;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only (T13 §7): applications are only ever created/mutated by the
 * agent tools. Staff can inspect the snapshot, data with provenance, and
 * the event trail, but never edit rows here.
 */
class ApplicationResource extends Resource
{
    protected static ?string $model = Application::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationGroup = 'واتساب';
    protected static ?string $navigationLabel = 'طلبات التمويل';
    protected static ?string $modelLabel = 'طلب';
    protected static ?string $pluralModelLabel = 'طلبات التمويل';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user && ($user->is_admin || $user->is_super_admin);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#'),
                Tables\Columns\TextColumn::make('customer.phone')->label('العميل'),
                Tables\Columns\TextColumn::make('customerType.label')->label('نوع العميل'),
                Tables\Columns\TextColumn::make('machine.name')->label('الموتوسيكل')->default('-'),
                Tables\Columns\TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn ($state) => DashboardLabels::get(DashboardLabels::APPLICATION_STATUS, $state))
                    ->color(fn ($state) => DashboardLabels::color(DashboardLabels::APPLICATION_STATUS_COLOR, $state)),
                Tables\Columns\TextColumn::make('last_activity_at')->label('آخر نشاط')->dateTime('Y-m-d H:i')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make(),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        /** @var Application $record */
        $record = $infolist->getRecord();

        return $infolist->schema(array_values(array_filter([
            self::summarySection(),
            $record->status === 'collecting' ? self::progressSection($record) : null,
            ...self::dataSections($record),
            self::documentsSection($record),
            self::eventsSection(),
        ])));
    }

    private static function summarySection(): Section
    {
        return Section::make('ملخص الطلب')
            ->icon('heroicon-o-identification')
            ->columns(['default' => 1, 'sm' => 2, 'lg' => 4])
            ->schema([
                TextEntry::make('id')->label('رقم الطلب')->prefix('#'),
                TextEntry::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn ($state) => DashboardLabels::get(DashboardLabels::APPLICATION_STATUS, $state))
                    ->color(fn ($state) => DashboardLabels::color(DashboardLabels::APPLICATION_STATUS_COLOR, $state)),
                TextEntry::make('customerType.label')->label('نوع العميل')->default('-'),
                TextEntry::make('customer_name')->label('اسم العميل')
                    ->state(fn (Application $record) => self::fieldValue($record, 'full_name') ?? '-'),
                TextEntry::make('customer.phone')->label('رقم الواتساب')->copyable()->default('-'),
                TextEntry::make('machine.name')->label('الموتوسيكل')->default('-'),
                TextEntry::make('installmentPlan.months')->label('مدة التقسيط')->suffix(' شهر')->placeholder('-'),
                TextEntry::make('installmentPlan.installmentSystem.name')->label('جهة التمويل')->default('-'),
                TextEntry::make('down_payment')->label('المقدم')
                    ->formatStateUsing(fn ($state) => (float) $state > 0 ? number_format((float) $state).' جنيه' : 'من غير مقدم')
                    ->placeholder('-'),
                TextEntry::make('created_at')->label('اتفتح في')->dateTime('Y-m-d H:i'),
                TextEntry::make('last_activity_at')->label('آخر نشاط')->since()->placeholder('-'),
                TextEntry::make('submitted_at')->label('اتقدم في')->dateTime('Y-m-d H:i')->placeholder('-'),
            ]);
    }

    /** What the application is waiting for, in words - not the raw snapshot. */
    private static function progressSection(Application $record): Section
    {
        try {
            $snapshot = app(SnapshotService::class)->for($record);
        } catch (\Throwable $e) {
            report($e);

            return Section::make('الطلب واقف على إيه')->schema([
                TextEntry::make('snapshot_error')->hiddenLabel()->state('مش قادر أحسب حالة الطلب دلوقتي.'),
            ]);
        }

        $fieldLabels = RequirementField::pluck('label', 'key');
        $documentLabels = DocumentType::pluck('label', 'key');
        $missing = collect($snapshot['blockers'] ?? [])->map(fn (array $b) => match ($b['type'] ?? null) {
            'field' => ($fieldLabels[$b['key']] ?? $b['key']).(($b['code'] ?? '') === 'MISSING' ? '' : ' (فيها مشكلة)'),
            'document' => $documentLabels[$b['key']] ?? $b['key'],
            'selection' => DashboardLabels::get(DashboardLabels::SELECTION, $b['key']),
            'eligibility' => 'الأهلية: '.DashboardLabels::get(['NOT_ELIGIBLE' => 'مش مستوفي الشروط', 'INPUT_MISSING' => 'مستنية بيانات (السن من الرقم القومي)'], $b['code'] ?? null),
            default => DashboardLabels::get(DashboardLabels::BLOCKER_TYPE, $b['type'] ?? null),
        })->unique()->values()->all();

        $next = $snapshot['next_step'] ?? null;
        $eligibility = $snapshot['eligibility']['status'] ?? 'unknown';

        return Section::make('الطلب واقف على إيه')
            ->icon('heroicon-o-clipboard-document-check')
            ->columns(['default' => 1, 'lg' => 3])
            ->schema([
                TextEntry::make('ready')->label('جاهز يتقدم؟')->badge()
                    ->state(($snapshot['can_submit'] ?? false) ? 'جاهز' : 'لسه')
                    ->color(($snapshot['can_submit'] ?? false) ? 'success' : 'warning'),
                TextEntry::make('next')->label('الخطوة الجاية')
                    ->state($next ? ($next['label'] ?? ($next['type'] === 'submit' ? 'تقديم الطلب' : '-')) : '-'),
                TextEntry::make('eligibility')->label('الشروط')->badge()
                    ->state(match ($eligibility) { 'eligible' => 'مستوفي', 'not_eligible' => 'مش مستوفي', default => 'لسه مش معروف' })
                    ->color(match ($eligibility) { 'eligible' => 'success', 'not_eligible' => 'danger', default => 'gray' }),
                TextEntry::make('missing')->label('الناقص')->columnSpanFull()
                    ->state($missing === [] ? ['مفيش حاجة ناقصة'] : $missing)
                    ->badge()->color($missing === [] ? 'success' : 'warning'),
            ]);
    }

    /** Groups shown on the page, in this order; anything else lands in "بيانات تانية". */
    private const DATA_GROUPS = [
        'البيانات الشخصية' => ['full_name', 'national_id', 'phone', 'monthly_income'],
        'عنوان السكن' => ['address', 'address_building_no', 'address_floor', 'address_landmark', 'residence_ownership'],
        'الشغل' => ['work_type', 'business_name', 'work_address', 'work_building_no', 'work_landmark'],
    ];

    /** @return Section[] */
    private static function dataSections(Application $record): array
    {
        $rows = $record->data()->get();
        $fields = RequirementField::get()->keyBy('key');
        $applicant = $rows->where('party', 'applicant')->keyBy('field_key');
        $sections = [];
        $placed = [];

        foreach (self::DATA_GROUPS as $title => $keys) {
            $entries = [];

            foreach ($keys as $key) {
                if ($row = $applicant->get($key)) {
                    $entries[] = self::dataEntry($row, $fields->get($key));
                    $placed[] = $key;
                }
            }

            if ($entries !== []) {
                $sections[] = Section::make($title)->columns(['default' => 1, 'sm' => 2, 'lg' => 3])->schema($entries);
            }
        }

        // toBase(): an Eloquent collection's except() filters by model id, not by key.
        $others = $applicant->toBase()->except($placed)->map(fn ($row) => self::dataEntry($row, $fields->get($row->field_key)))->values()->all();

        if ($others !== []) {
            $sections[] = Section::make('بيانات تانية')->columns(['default' => 1, 'sm' => 2, 'lg' => 3])->schema($others);
        }

        $guarantor = $rows->where('party', 'guarantor')->map(fn ($row) => self::dataEntry($row, $fields->get($row->field_key)))->values()->all();

        if ($guarantor !== []) {
            $sections[] = Section::make('الضامن')->columns(['default' => 1, 'sm' => 2, 'lg' => 3])->schema($guarantor);
        }

        if ($sections === []) {
            $sections[] = Section::make('بيانات العميل')->schema([
                TextEntry::make('no_data')->hiddenLabel()->state('لسه مفيش بيانات متسجلة.'),
            ]);
        }

        return $sections;
    }

    private static function dataEntry(ApplicationData $row, ?RequirementField $field): TextEntry
    {
        $value = self::safe(fn () => $row->value);
        $display = match (true) {
            $value === self::UNREADABLE => 'مش قادر يتقري',
            $value === null || $value === '' => '-',
            $field?->is_sensitive && ! self::canSeeSensitive() => '••••••',
            $field?->data_type === 'enum' => DashboardLabels::get(DashboardLabels::ENUM_VALUE, (string) $value),
            default => (string) $value,
        };

        return TextEntry::make('data_'.$row->party.'_'.$row->field_key)
            ->label($field?->label ?? $row->field_key)
            ->state($display)
            ->copyable($display !== '••••••' && $display !== '-')
            ->helperText(DashboardLabels::get(DashboardLabels::DATA_SOURCE, $row->source)
                .($row->status !== 'valid' ? ' - '.DashboardLabels::get(DashboardLabels::DATA_STATUS, $row->status) : ''));
    }

    private static function documentsSection(Application $record): Section
    {
        $documents = $record->documents()->with(['documentType', 'media'])->latest()->get();
        $fieldLabels = RequirementField::pluck('label', 'key')->union(\App\Domain\Documents\DocumentFields::LABELS);
        $typeLabels = DocumentType::pluck('label', 'key');

        $cards = $documents->map(function (ApplicationDocument $document) use ($fieldLabels, $typeLabels) {
            $media = $document->media;
            $isImage = $media && str_starts_with((string) $media->mime, 'image/');
            $url = $media ? route('staff.media.show', $media) : null;
            $extracted = self::safe(fn () => $document->extracted);
            $issues = collect($document->issues ?? [])
                ->map(fn ($issue) => DashboardLabels::get(DashboardLabels::DOCUMENT_ISSUE, is_array($issue) ? ($issue['code'] ?? null) : (string) $issue))
                ->all();

            $lines = match (true) {
                $extracted === self::UNREADABLE => ['مش قادر يتقري'],
                ! is_array($extracted) || $extracted === [] => [],
                ! self::canSeeSensitive() => ['••••••'],
                default => collect($extracted)->reject(fn ($value) => in_array($value, [null, '', [], 'null'], true))->map(fn ($value, $key) => ($fieldLabels[$key] ?? str_replace('_', ' ', (string) $key)).': '
                    .(is_array($value) ? implode('، ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE), $value)) : (string) $value))
                    ->values()->all(),
            };

            $key = 'doc_'.$document->id;

            $typeKey = $document->detected_type_key ?? $document->expected_type_key;

            return Fieldset::make($document->documentType?->label ?? $typeLabels[$typeKey] ?? ($typeKey ? DashboardLabels::get([], $typeKey) : 'مستند مش معروف نوعه'))
                ->columns(1)
                ->schema(array_values(array_filter([
                    $isImage ? ImageEntry::make($key.'_image')->hiddenLabel()->state($url)->height(160)
                        ->url($url)->openUrlInNewTab()->extraImgAttributes(['class' => 'rounded-lg object-contain']) : null,
                    ! $isImage && $url ? TextEntry::make($key.'_file')->hiddenLabel()->state('فتح الملف')
                        ->url($url)->openUrlInNewTab()->icon('heroicon-o-paper-clip')->color('primary') : null,
                    TextEntry::make($key.'_status')->label('الحالة')->badge()
                        ->state(DashboardLabels::get(DashboardLabels::DOCUMENT_STATUS, $document->status))
                        ->color(DashboardLabels::color(DashboardLabels::DOCUMENT_STATUS_COLOR, $document->status)),
                    $issues !== [] ? TextEntry::make($key.'_issues')->label('المشاكل')->state($issues)->badge()->color('danger') : null,
                    $lines !== [] ? TextEntry::make($key.'_extracted')->label('اللي اتقري منه')->state($lines)->listWithLineBreaks() : null,
                    TextEntry::make($key.'_date')->label('وصل')->state($document->created_at)->dateTime('Y-m-d H:i'),
                ])));
        })->all();

        return Section::make('المستندات')
            ->icon('heroicon-o-photo')
            ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
            ->schema($cards !== [] ? $cards : [
                TextEntry::make('no_documents')->hiddenLabel()->state('لسه مفيش مستندات.'),
            ]);
    }

    private static function eventsSection(): Section
    {
        return Section::make('سجل الطلب')
            ->icon('heroicon-o-clock')
            ->collapsible()
            ->schema([
                RepeatableEntry::make('events')->label('سجل الطلب')->hiddenLabel()->contained(false)
                    ->columns(['default' => 1, 'sm' => 3])
                    ->schema([
                        TextEntry::make('type')->label('الحدث')->hiddenLabel()->weight('bold')
                            ->formatStateUsing(fn ($state) => DashboardLabels::get(DashboardLabels::EVENT_TYPE, $state)),
                        TextEntry::make('to_status')->label('الحالة')->hiddenLabel()
                            ->formatStateUsing(fn ($state, $record) => ($record->from_status ? DashboardLabels::get(DashboardLabels::APPLICATION_STATUS, $record->from_status).' ← ' : '')
                                .DashboardLabels::get(DashboardLabels::APPLICATION_STATUS, $state))
                            ->placeholder('-'),
                        TextEntry::make('created_at')->label('الوقت')->hiddenLabel()->dateTime('Y-m-d H:i')
                            ->suffix(fn ($record) => ' - '.DashboardLabels::get(DashboardLabels::ACTOR, $record->actor)),
                    ]),
            ]);
    }

    private const UNREADABLE = "\0unreadable";

    /**
     * Values are encrypted at rest; a row written under another APP_KEY
     * threw DecryptException and took the whole page down.
     */
    private static function safe(\Closure $read): mixed
    {
        try {
            return $read();
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return self::UNREADABLE;
        }
    }

    private static function fieldValue(Application $record, string $key): ?string
    {
        $row = $record->data()->where('party', 'applicant')->where('field_key', $key)->first();
        $value = $row ? self::safe(fn () => $row->value) : null;

        return $value === self::UNREADABLE ? null : $value;
    }

    private static function canSeeSensitive(): bool
    {
        $user = auth()->user();

        return (bool) ($user && ($user->is_admin || $user->is_super_admin));
    }

    public static function getRelations(): array
    {
        // Everything is on the page itself (owner: no raw tables).
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApplications::route('/'),
            'view' => Pages\ViewApplication::route('/{record}'),
        ];
    }
}
