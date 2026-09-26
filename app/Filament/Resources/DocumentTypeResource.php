<?php

namespace App\Filament\Resources;

use App\Domain\Documents\DocumentFields;
use App\Filament\Resources\DocumentTypeResource\Pages;
use App\Models\DocumentType;
use App\Models\RequirementField;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class DocumentTypeResource extends Resource
{
    protected static ?string $model = DocumentType::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationGroup = 'إعدادات الطلبات';
    protected static ?string $navigationLabel = 'أنواع المستندات';
    protected static ?string $modelLabel = 'نوع مستند';
    protected static ?string $pluralModelLabel = 'أنواع المستندات';

    /** Keyed by config('agent.document_rules'). */
    public const RULE_LABELS = [
        'matches_application_field' => 'البيانات لازم تطابق اللي العميل قاله (اسم / رقم قومي)',
        'not_past' => 'التاريخ ما يكونش فات (رخصة منتهية مثلاً)',
        'not_expired' => 'المستند ما يكونش أقدم من عدد أيام',
        'min_days_since' => 'لازم يكون فات على التاريخ ده عدد أيام (مدة خدمة مثلاً)',
        'period_coverage' => 'السكرينات لازم تغطي فترة كفاية (أرباح التطبيقات)',
    ];

    public const ISSUE_CODES = [
        'NAME_MISMATCH' => 'الاسم مش مطابق',
        'BUSINESS_NAME_MISMATCH' => 'اسم النشاط مش مطابق',
        'ID_MISMATCH' => 'الرقم القومي مش مطابق',
        'FIELD_MISMATCH' => 'بيان في المستند مش مطابق لكلام العميل',
        'EXPIRED_DOCUMENT' => 'المستند قديم / منتهي',
        'LICENSE_EXPIRED' => 'الرخصة منتهية',
        'EMPLOYMENT_TOO_RECENT' => 'مدة الخدمة أقل من المطلوب',
    ];

    /** Fields read off documents that are not application fields. */
    public const DOCUMENT_ONLY_FIELDS = DocumentFields::LABELS;

    public const MIMES = [
        'image/jpeg' => 'صورة JPG',
        'image/png' => 'صورة PNG',
        'image/webp' => 'صورة WEBP',
        'application/pdf' => 'ملف PDF',
    ];

    /** @return array<string, string> key => label for every field a document can carry */
    public static function fieldOptions(): array
    {
        $options = RequirementField::pluck('label', 'key')->all() + self::DOCUMENT_ONLY_FIELDS;

        $used = DocumentType::get(['extraction_fields', 'optional_fields'])->flatMap(fn (DocumentType $t) => $t->allFields());

        foreach ($used->filter()->unique() as $key) {
            $options[$key] ??= $key;
        }

        foreach ($options as $key => $label) {
            $options[$key] = $label === $key ? $key : "{$label} ({$key})";
        }

        return $options;
    }

    /** Drops the empty params of fields hidden for the chosen rule type. */
    public static function cleanRules(array $data): array
    {
        $data['validation_rules'] = array_values(array_map(fn (array $rule) => [
            'rule_type' => $rule['rule_type'] ?? null,
            'params' => array_filter($rule['params'] ?? [], fn ($v) => $v !== null && $v !== ''),
        ], $data['validation_rules'] ?? []));

        return $data;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('المستند')->schema([
                TextInput::make('label')
                    ->label('الاسم')
                    ->required()
                    ->placeholder('مثال: بطاقة الرقم القومي'),
                TextInput::make('key')
                    ->label('المفتاح')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->regex('/^[a-z0-9_]+$/')
                    ->helperText('حروف إنجليزي صغيرة و _ . متغيروش بعد ما يبقى فيه مستندات متسجلة بيه.'),
                Textarea::make('description_for_ai')
                    ->label('إزاي البوت يتعرف عليه')
                    ->rows(3)
                    ->columnSpanFull()
                    ->helperText('وصف شكل المستند عشان البوت يعرف يميزه لما العميل يبعت صورة. مثال: "كارت بلاستيك فيه صورة شخصية واسم رباعي ورقم قومي ١٤ رقم".'),
                Toggle::make('is_active')->label('شغال')->default(true),
            ])->columns(2),

            Section::make('البيانات اللي تتقرا منه')->schema([
                Select::make('extraction_fields')
                    ->label('الحقول')
                    ->multiple()
                    ->options(fn () => self::fieldOptions())
                    ->helperText('لازم تكون مكتوبة في المستند - لو واحدة مش باينة المستند بيترفض. الحقول اللي ليها قاعدة تحت لازم تكون هنا أو في اللي تحت.'),
                Select::make('optional_fields')
                    ->label('حقول بتتقري لو موجودة')
                    ->multiple()
                    ->options(fn () => self::fieldOptions())
                    ->helperText('البوت بيقراها لو مكتوبة (زي تاريخ التعيين في المفردات) - ولو مش موجودة المستند ما يترفضش. بتظهر للموظف في الطلب.'),
                CheckboxList::make('accepted_mimes')
                    ->label('أنواع الملفات المقبولة')
                    ->options(self::MIMES)
                    ->columns(4)
                    ->helperText('لو مفيش اختيار، أي صورة مقبولة.'),
            ]),

            Section::make('قواعد التحقق')
                ->description('السيستم بيطبقها بعد قراءة المستند، والبوت بيبلّغ العميل لو فيه مشكلة.')
                ->schema([
                    Repeater::make('validation_rules')
                        ->hiddenLabel()
                        ->addActionLabel('إضافة قاعدة')
                        ->itemLabel(fn (array $state) => self::RULE_LABELS[$state['rule_type'] ?? ''] ?? 'قاعدة جديدة')
                        ->collapsible()
                        ->default([])
                        ->schema([
                            Select::make('rule_type')
                                ->label('نوع القاعدة')
                                ->options(fn () => collect(config('agent.document_rules'))
                                    ->keys()->mapWithKeys(fn ($k) => [$k => self::RULE_LABELS[$k] ?? $k]))
                                ->required()
                                ->live(),
                            Group::make(self::ruleParamFields())
                                ->statePath('params')
                                ->columns(2),
                        ]),
                ]),
        ]);
    }

    private static function ruleParamFields(): array
    {
        $is = fn (string ...$types) => fn (Get $get) => in_array($get('../rule_type'), $types, true);
        $int = fn ($state) => $state === null || $state === '' ? null : (int) $state;

        return [
            Select::make('extracted_field')->label('الحقل في المستند')
                ->options(fn () => self::fieldOptions())->required()->visible($is('matches_application_field')),
            Select::make('stored_field')->label('يطابق الحقل ده في الطلب')
                ->options(fn () => RequirementField::pluck('label', 'key'))->required()->visible($is('matches_application_field')),
            // Not in the form before: saving a document type from here dropped
            // "contains" and the shop-sign name check became an exact match.
            Select::make('match')->label('طريقة المطابقة')
                ->options([
                    'name_tokens' => 'اسم شخص: نفس الاسم أو أقصر منه (تلاتي قدام رباعي)',
                    'contains' => 'واحد جوه التاني (اسم محل / يافطة)',
                ])
                ->placeholder('مطابقة كاملة')
                ->visible($is('matches_application_field'))
                ->helperText('المطابقة الكاملة بترفض اسم تلاتي قدام رباعي - للأسماء استخدم "اسم شخص".'),
            Select::make('date_field')->label('حقل التاريخ')
                ->options(fn () => self::fieldOptions())->required()->visible($is('not_past', 'not_expired', 'min_days_since')),
            Select::make('start_field')->label('حقل بداية الفترة')
                ->options(fn () => self::fieldOptions())->required()->visible($is('period_coverage')),
            Select::make('end_field')->label('حقل نهاية الفترة')
                ->options(fn () => self::fieldOptions())->required()->visible($is('period_coverage')),
            TextInput::make('min_days')
                ->label(fn (Get $get) => $get('../rule_type') === 'min_days_since' ? 'أقل عدد أيام من التاريخ لحد النهارده' : 'أقل عدد أيام لازم يتغطى')
                ->numeric()->minValue(1)
                ->required()->dehydrateStateUsing($int)->visible($is('period_coverage', 'min_days_since'))
                ->helperText(fn (Get $get) => $get('../rule_type') === 'min_days_since' ? 'مثال: 180 = ٦ شهور خدمة على الأقل.' : 'مثال: 80 يوم تقريبًا = ٣ شهور.'),
            TextInput::make('max_age_days')
                ->label(fn (Get $get) => $get('../rule_type') === 'not_expired' ? 'المستند ما يكونش أقدم من (يوم)' : 'آخر سكرين ما يكونش أقدم من (يوم)')->numeric()->minValue(1)
                ->dehydrateStateUsing($int)->visible($is('period_coverage', 'not_expired'))
                ->required(fn (Get $get) => $get('../rule_type') === 'not_expired'),
            Select::make('issue_code')->label('المشكلة اللي تتسجل')
                ->options(self::ISSUE_CODES)->visible($is('matches_application_field', 'not_past', 'not_expired', 'min_days_since'))
                ->helperText('البوت بيقول للعميل المشكلة بناءً على ده.'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('label')
                    ->label('المستند')
                    ->weight('bold')
                    ->searchable()
                    ->description(fn (DocumentType $record) => Str::limit((string) $record->description_for_ai, 90)),
                Tables\Columns\TextColumn::make('extraction_fields')
                    ->label('بيتقرا منه')
                    ->badge()
                    ->formatStateUsing(fn ($state) => RequirementField::where('key', $state)->value('label') ?? self::DOCUMENT_ONLY_FIELDS[$state] ?? $state),
                Tables\Columns\TextColumn::make('optional_fields')
                    ->label('ولو موجود')
                    ->badge()
                    ->color('gray')
                    ->toggleable()
                    ->formatStateUsing(fn ($state) => RequirementField::where('key', $state)->value('label') ?? self::DOCUMENT_ONLY_FIELDS[$state] ?? $state),
                Tables\Columns\TextColumn::make('rules_count')
                    ->label('قواعد')
                    ->state(fn (DocumentType $record) => count($record->validation_rules ?? [])),
                Tables\Columns\ToggleColumn::make('is_active')->label('شغال'),
                Tables\Columns\TextColumn::make('key')->label('المفتاح')->fontFamily('mono')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDocumentTypes::route('/'),
            'create' => Pages\CreateDocumentType::route('/create'),
            'edit' => Pages\EditDocumentType::route('/{record}/edit'),
        ];
    }
}
