<?php

namespace App\Filament\Resources;

use App\Domain\Forms\FormBindings;
use App\Domain\Forms\FormSchema;
use App\Filament\Resources\DynamicFormResource\Pages;
use App\Models\CustomerType;
use App\Models\DocumentType;
use App\Models\DynamicForm;
use Filament\Forms;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * Dynamic Links: the owner builds the application form here, no code (spec §4.1). Sections hold
 * fields; each field can be tied to a system field, carry rules, and show only when earlier
 * answers match. Publishing freezes a version - links keep the one they were sent with.
 */
class DynamicFormResource extends Resource
{
    use \App\Filament\Concerns\RoleGatedResource;

    protected static ?string $model = DynamicForm::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-duplicate';

    protected static ?string $navigationGroup = 'إعدادات الطلبات';

    protected static ?string $navigationLabel = 'فورم التقديم (Dynamic Forms)';

    protected static ?string $modelLabel = 'فورم';

    protected static ?string $pluralModelLabel = 'فورمات التقديم';

    protected static ?int $navigationSort = 0;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('الفورم')->columns(2)->schema([
                TextInput::make('name')->label('اسم الفورم')->required()->maxLength(120)
                    ->helperText('بيظهر للعميل فوق الصفحة، مثلاً: طلب تقسيط موتوسيكل'),
                Toggle::make('is_bot_default')->label('دي الفورم اللي البوت يبعتها')->inline(false),
                Textarea::make('description')->label('وصف داخلي (للموظفين)')->rows(2),
                Toggle::make('is_active')->label('شغالة')->default(true)->inline(false),
                Select::make('schema.settings.customer_type_id')->label('نوع عميل ثابت للفورم (اختياري)')
                    ->options(fn () => CustomerType::where('is_active', true)->pluck('label', 'id'))
                    ->helperText('لو الفورم مفيهاش سؤال "نوع الشغل" اختار هنا نوع العميل اللي كل الطلبات هتتسجل عليه.'),
                Textarea::make('schema.settings.intro')->label('كلمة في أول الفورم للعميل (اختياري)')->rows(2),
            ]),

            Repeater::make('schema.sections')
                ->label('الأقسام (كل قسم خطوة في الموبايل)')
                ->addActionLabel('إضافة قسم')
                ->collapsible()
                ->cloneable()
                ->reorderableWithButtons()
                ->itemLabel(fn (array $state) => $state['title'] ?? 'قسم')
                ->schema([
                    Forms\Components\Grid::make(2)->schema([
                        TextInput::make('title')->label('عنوان القسم')->required(),
                        TextInput::make('description')->label('وصف قصير (اختياري)'),
                        Forms\Components\Hidden::make('key')->default(fn () => 's_'.Str::lower(Str::random(6))),
                    ]),
                    static::conditionsFields('القسم كله يظهر لو (اختياري)'),
                    Builder::make('items')
                        ->label('الحقول')
                        ->addActionLabel('إضافة حقل')
                        ->collapsible()
                        ->cloneable()
                        ->blockNumbers(false)
                        ->blocks(static::blocks()),
                ]),
        ]);
    }

    /** @return Block[] one block per field type */
    private static function blocks(): array
    {
        $blocks = [];

        foreach (FormSchema::TYPES as $type => $label) {
            $blocks[] = Block::make($type)
                ->label(fn (?array $state) => $label.(filled($state['label'] ?? null) ? ': '.$state['label'] : ''))
                ->schema(static::fieldSchema($type));
        }

        return $blocks;
    }

    private static function fieldSchema(string $type): array
    {
        $common = [
            Forms\Components\Grid::make(3)->schema([
                TextInput::make('label')->label(in_array($type, ['note', 'stop'], true) ? 'عنوان (داخلي)' : 'السؤال / اسم الحقل')
                    ->required(! in_array($type, ['note', 'stop'], true))->columnSpan(2),
                TextInput::make('key')->label('مفتاح داخلي')
                    ->default(fn () => 'f_'.Str::lower(Str::random(6)))
                    ->required()->alphaDash()
                    ->helperText('متغيروش بعد ما الفورم تتنشر.'),
            ]),
        ];

        if (in_array($type, ['note', 'stop'], true)) {
            return array_merge($common, [
                Textarea::make('message')->label($type === 'stop' ? 'الرسالة اللي تظهر وتوقف التقديم' : 'النص اللي يظهر للعميل')->rows(2)->required(),
                static::conditionsFields($type === 'stop' ? 'تظهر (وتوقف التقديم) لو' : 'يظهر لو (اختياري)'),
            ]);
        }

        $fields = array_merge($common, [
            Forms\Components\Grid::make(3)->schema([
                Toggle::make('required')->label('إجباري')->default(true)->inline(false),
                TextInput::make('help')->label('شرح تحت السؤال (اختياري)')->columnSpan(2),
            ]),
            Forms\Components\Grid::make(2)->schema([
                Select::make('binding')
                    ->label('يتربط بإيه في السيستم')
                    ->options(FormBindings::options($type))
                    ->default(match ($type) {
                        'machine' => 'request.machine',
                        'plan' => 'request.plan',
                        default => null,
                    })
                    ->required(in_array($type, ['machine', 'plan'], true))
                    ->searchable()
                    ->placeholder('بيانات إضافية (مش مربوط)')
                    ->helperText('القيمة هتنزل في الطلب في المكان ده. مش مربوط = تظهر للموظف في "بيانات إضافية من الفورم".'),
                TextInput::make('placeholder')->label('مثال جوه الخانة (اختياري)')
                    ->visible(in_array($type, ['text', 'textarea', 'number', 'money', 'phone', 'national_id'], true)),
            ]),
        ]);

        $rules = match ($type) {
            'text', 'textarea' => [
                TextInput::make('rules.min_length')->label('أقل عدد حروف')->numeric()->minValue(0),
                TextInput::make('rules.max_length')->label('أكبر عدد حروف')->numeric()->minValue(1),
                Toggle::make('rules.digits_only')->label('أرقام بس')->inline(false),
            ],
            'number', 'money' => [
                TextInput::make('rules.min')->label('أقل قيمة')->numeric(),
                TextInput::make('rules.max')->label('أكبر قيمة')->numeric(),
            ],
            'national_id' => [
                TextInput::make('rules.min_age')->label('أقل سن')->numeric()->minValue(0),
                TextInput::make('rules.max_age')->label('أكبر سن')->numeric()->minValue(0),
                Select::make('rules.gender')->label('النوع المسموح')->options(['male' => 'رجالة بس', 'female' => 'ستات بس'])->placeholder('الكل'),
            ],
            'date' => [
                Forms\Components\DatePicker::make('rules.min')->label('أقدم تاريخ'),
                Forms\Components\DatePicker::make('rules.max')->label('أحدث تاريخ'),
            ],
            default => [],
        };

        if ($rules !== []) {
            $fields[] = Section::make('الشروط')->compact()->collapsible()->columns(3)->schema(array_merge($rules, [
                TextInput::make('error_message')->label('رسالة الخطأ للعميل (اختياري)')->columnSpanFull()
                    ->helperText('فاضي = رسالة جاهزة على حسب الشرط.'),
            ]));
        }

        if (in_array($type, ['select', 'radio', 'checkbox'], true)) {
            $fields[] = Repeater::make('options')
                ->label('الاختيارات')
                ->addActionLabel('إضافة اختيار')
                ->reorderableWithButtons()
                ->columns(3)
                ->minItems(1)
                ->itemLabel(fn (array $state) => $state['label'] ?? null)
                ->schema([
                    TextInput::make('label')->label('النص اللي يظهر')->required()
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Forms\Set $set, Get $get, ?string $state) => blank($get('value')) ? $set('value', Str::slug((string) $state) ?: Str::lower(Str::random(5))) : null),
                    TextInput::make('value')->label('القيمة (داخلية)')->required()
                        ->helperText('دي اللي بتستخدمها في "يظهر لو".'),
                    Select::make('customer_type_id')->label('نوع العميل (لسؤال نوع الشغل)')
                        ->options(fn () => CustomerType::where('is_active', true)->pluck('label', 'id'))
                        ->visible(fn (Get $get) => $get('../../binding') === 'work.status'),
                ]);
        }

        if ($type === 'address') {
            $fields[] = Forms\Components\Grid::make(2)->schema([
                Forms\Components\CheckboxList::make('address_parts')->label('الأجزاء اللي تظهر')
                    ->options(FormBindings::ADDRESS_PARTS)->default(array_keys(FormBindings::ADDRESS_PARTS))->columns(2),
                Forms\Components\CheckboxList::make('address_required')->label('الأجزاء الإجبارية')
                    ->options(FormBindings::ADDRESS_PARTS)->default(['governorate', 'area', 'street'])->columns(2),
            ]);
        }

        if ($type === 'group') {
            $fields[] = Section::make('المجموعة')->compact()->columns(3)->schema([
                TextInput::make('min_items')->label('أقل عدد')->numeric()->minValue(0)->default(1),
                TextInput::make('max_items')->label('أكبر عدد')->numeric()->minValue(1)->default(3),
                TextInput::make('add_label')->label('نص زرار الإضافة')->default('إضافة واحد كمان'),
                Repeater::make('fields')
                    ->label('الحقول جوه كل واحد')
                    ->addActionLabel('إضافة حقل')
                    ->columns(5)
                    ->columnSpanFull()
                    ->minItems(1)
                    ->schema([
                        TextInput::make('label')->label('السؤال')->required()->columnSpan(2),
                        Select::make('type')->label('النوع')->options([
                            'text' => 'نص', 'phone' => 'موبايل', 'national_id' => 'رقم قومي', 'number' => 'رقم', 'textarea' => 'نص طويل',
                        ])->default('text')->required(),
                        Select::make('maps_to')->label('يتربط بـ')->options([
                            'name' => 'الاسم', 'phone' => 'الموبايل', 'national_id' => 'الرقم القومي', 'address' => 'العنوان',
                        ])->placeholder('مش مربوط'),
                        Toggle::make('required')->label('إجباري')->default(true)->inline(false),
                        Forms\Components\Hidden::make('key')->default(fn () => 'g_'.Str::lower(Str::random(5))),
                    ]),
            ]);
        }

        if ($type === 'document') {
            $fields[] = Section::make('المستند والـ AI')->compact()->columns(3)->schema([
                Select::make('document_type')->label('نوع المستند (للـ OCR والـ AI)')
                    ->options(fn () => DocumentType::where('is_active', true)->pluck('label', 'key'))
                    ->placeholder('من الربط / من غير فحص نوع')
                    ->helperText('الـ AI بيتأكد إن الصورة هي المستند ده وواضحة وفيها البيانات.'),
                TextInput::make('max_files')->label('أقصى عدد ملفات')->numeric()->minValue(1)->maxValue(10)->default(1),
                Select::make('accept')->label('يقبل')->options([
                    'images_pdf' => 'صور و PDF',
                    'images' => 'صور بس',
                    'video' => 'صور وفيديو',
                ])->default('images_pdf'),
                Textarea::make('instructions')->label('تعليمات للـ AI على المستند ده (اختياري)')->rows(2)->columnSpanFull()
                    ->helperText('اكتبها بالعربي زي ما بتشرح لموظف، مثلاً: "لازم يكون عليها ختم جهة العمل" أو "مش أقدم من 3 شهور". لو مش مطابقة العميل هيشوف تنبيه وهو بيرفع، والطلب بيوصل للموظف عادي وعليه علامة.'),
            ]);
        }

        if ($type === 'images') {
            $fields[] = Section::make('الصور')->compact()->columns(2)->schema([
                TextInput::make('min_files')->label('أقل عدد صور')->numeric()->minValue(1)->maxValue(20)->default(1)
                    ->helperText('مثلاً 3 سكرينات من تطبيق التوصيل.'),
                TextInput::make('max_files')->label('أكبر عدد صور')->numeric()->minValue(1)->maxValue(20)->default(10),
                Textarea::make('instructions')->label('تعليمات للـ AI على الصور (اختياري)')->rows(2)->columnSpanFull()
                    ->helperText('مثلاً: "لازم تكون سكرين من تطبيق توصيل (طلبات، مرسول، أوبر) وباين فيها الأرباح". لو مش مطابقة العميل بيشوف تنبيه، والطلب بيوصل عادي.'),
            ]);
        }

        $fields[] = static::conditionsFields('يظهر بس لو (اختياري)');

        return $fields;
    }

    /** "يظهر لو" - conditions on answers of earlier fields. */
    private static function conditionsFields(string $label): Section
    {
        return Section::make($label)->compact()->collapsible()->collapsed(fn (?array $state) => blank($state['conditions'] ?? null))
            ->schema([
                Select::make('match')->label('لو فيه أكتر من شرط')
                    ->options(['all' => 'كل الشروط تتحقق', 'any' => 'أي شرط يتحقق'])->default('all')->selectablePlaceholder(false),
                Repeater::make('conditions')
                    ->label('الشروط')
                    ->addActionLabel('إضافة شرط')
                    ->defaultItems(0)
                    ->columns(4)
                    ->schema([
                        Select::make('field')->label('الحقل')
                            ->options(fn ($livewire) => static::fieldOptions($livewire))
                            ->searchable()->required()->live(),
                        Select::make('prop')->label('من الرقم القومي')
                            ->options(FormSchema::NATIONAL_ID_PROPS)->placeholder('القيمة نفسها'),
                        Select::make('operator')->label('الشرط')->options(FormSchema::OPERATORS)->default('equals')->required()->live(),
                        TextInput::make('value')->label('القيمة')
                            ->hidden(fn (Get $get) => in_array($get('operator'), ['filled', 'empty'], true))
                            ->helperText('للاختيارات اكتب "القيمة الداخلية" بتاعة الاختيار.'),
                    ]),
            ]);
    }

    /** Every answer field of the form being edited, for the conditions dropdown. */
    private static function fieldOptions($livewire): array
    {
        $sections = data_get($livewire?->data ?? [], 'schema.sections', []);
        $schema = new FormSchema(['sections' => is_array($sections) ? $sections : []]);

        return collect($schema->fields())
            ->mapWithKeys(fn ($f, $key) => [$key => ($f['label'] ?: $key).' ('.(FormSchema::TYPES[$f['type']] ?? $f['type']).')'])
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('الفورم')->searchable(),
                Tables\Columns\IconColumn::make('is_bot_default')->label('بتاعة البوت')->boolean(),
                Tables\Columns\IconColumn::make('is_active')->label('شغالة')->boolean(),
                Tables\Columns\TextColumn::make('publishedVersion.version')->label('النسخة المنشورة')->placeholder('مش منشورة')->badge(),
                Tables\Columns\TextColumn::make('links_count')->label('روابط')
                    ->state(fn (DynamicForm $record) => \App\Models\FormLink::whereIn('dynamic_form_version_id', $record->versions()->select('id'))->where('purpose', 'apply')->count()),
                Tables\Columns\TextColumn::make('updated_at')->label('آخر تعديل')->since(),
            ])
            ->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDynamicForms::route('/'),
            'create' => Pages\CreateDynamicForm::route('/create'),
            'edit' => Pages\BuildDynamicForm::route('/{record}/edit'),
        ];
    }
}
