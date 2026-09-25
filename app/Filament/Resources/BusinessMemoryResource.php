<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BusinessMemoryResource\Pages;
use App\Models\BusinessMemory;
use App\Models\CustomerType;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class BusinessMemoryResource extends Resource
{
    protected static ?string $model = BusinessMemory::class;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';
    protected static ?string $navigationGroup = 'البوت الذكي';
    protected static ?string $navigationLabel = 'المعرفة التجارية';
    protected static ?string $modelLabel = 'معلومة';
    protected static ?string $pluralModelLabel = 'المعرفة التجارية';
    protected static ?int $navigationSort = 4;

    /** Known categories with Arabic labels; any other value still works. */
    public const CATEGORIES = [
        'style' => 'أسلوب الكلام',
        'pricing' => 'الأسعار والتقسيط',
        'application' => 'التقديم والطلب',
        'eligibility' => 'الشروط والمستندات',
        'catalog' => 'الموتوسيكلات',
        'ocr' => 'قراءة المستندات',
        'support' => 'المتابعة والشكاوى',
        'guardrails' => 'ممنوعات',
        'branches' => 'الفروع',
    ];

    public const APPLICATION_STATUSES = [
        'collecting' => 'بيجمع البيانات',
        'submitted' => 'اتقدم',
        'under_review' => 'تحت المراجعة',
        'needs_more_info' => 'محتاج بيانات زيادة',
        'approved' => 'اتوافق عليه',
        'rejected' => 'اترفض',
    ];

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    /** char/4, matching the estimate already used in GeminiClient. */
    public static function estimateTokens(?string $content): int
    {
        return (int) ceil(mb_strlen((string) $content) / 4);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('المعلومة')
                ->description('اكتبها زي ما هتشرحها لبياع جديد في المعرض: جمل قصيرة وواضحة.')
                ->schema([
                    TextInput::make('title')
                        ->label('العنوان')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('مثال: سياسة الضمان')
                        ->helperText('البوت بيشوف العنوان ده في القايمة، وبيقرر منه يفتح المعلومة ولا لأ - خليه واضح.')
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Set $set, Get $get, ?string $state, string $operation) {
                            if ($operation === 'create' && blank($get('key'))) {
                                $set('key', self::suggestKey((string) $state));
                            }
                        }),
                    Textarea::make('content')
                        ->label('المحتوى')
                        ->required()
                        ->rows(8)
                        ->autosize()
                        ->live(onBlur: true)
                        ->helperText(fn (Get $get) => new HtmlString(
                            'حوالي <b>'.self::estimateTokens($get('content')).'</b> توكن.'
                            .(preg_match('/\d/', (string) $get('content'))
                                ? ' <span style="color:#d97706">⚠️ فيه أرقام: الأسعار والأقساط والسن ومدد التقسيط مكانها جداولها (الموتوسيكلات، أنظمة التقسيط، شروط الأهلية) عشان السيستم يتحقق منها - مش هنا.</span>'
                                : '')
                        )),
                    TextInput::make('category')
                        ->label('التصنيف')
                        ->datalist(array_keys(self::CATEGORIES))
                        ->helperText('اختار من القايمة أو اكتب تصنيف جديد: '.implode('، ', array_map(
                            fn ($k, $v) => "{$k} ({$v})", array_keys(self::CATEGORIES), self::CATEGORIES
                        ))),
                ]),

            Section::make('البوت يستخدمها إمتى؟')
                ->schema([
                    Toggle::make('is_active')->label('شغالة')->default(true)
                        ->helperText('اقفلها بدل ما تمسحها لو عايز توقفها مؤقتًا.'),
                    Toggle::make('is_pinned')
                        ->label('مثبتة - تتبعت مع كل رسالة')
                        ->live()
                        ->helperText(fn (Get $get) => $get('is_pinned')
                            ? 'البوت هيقراها في كل رد. استخدمها للحاجات المهمة جدًا والقصيرة بس (أسلوب، ممنوعات) لأنها بتبطّأ كل رد وبتغلّيه.'
                            : 'البوت هيشوف العنوان بس، ولو السؤال محتاجها هيفتح المحتوى بأداة get_business_knowledge. ده الأنسب لأغلب المعلومات.'),
                    Placeholder::make('pinned_budget')
                        ->label('المساحة المستخدمة للمعلومات المثبتة')
                        ->visible(fn (Get $get) => (bool) $get('is_pinned'))
                        ->content(fn (Get $get, ?BusinessMemory $record) => self::pinnedBudgetText($record, $get('content'))),
                    Select::make('scope_customer_types')
                        ->label('تتبعت أوتوماتيك لما العميل يقدّم كـ')
                        ->multiple()
                        ->options(fn () => CustomerType::orderBy('sort')->pluck('label', 'key'))
                        ->helperText('اختياري. مثال: مستندات الموظفين تتبعت بس لما يكون فيه طلب لموظف.'),
                    Select::make('scope_application_statuses')
                        ->label('أو لما الطلب يكون في مرحلة')
                        ->multiple()
                        ->options(self::APPLICATION_STATUSES),
                ])->columns(2),

            Section::make('متقدم')
                ->collapsed()
                ->schema([
                    TextInput::make('key')
                        ->label('المفتاح')
                        ->required(fn (string $operation) => $operation === 'edit')
                        ->unique(ignoreRecord: true)
                        ->regex('/^[a-z0-9_]+$/')
                        ->helperText('بيتعمل لوحده من العنوان. حروف إنجليزي صغيرة وأرقام و _ بس. البوت بيطلب المعلومة بيه، فمتغيروش لو التعليمات بتذكره.'),
                    TextInput::make('priority')
                        ->label('الترتيب')
                        ->numeric()
                        ->default(0)
                        ->helperText('الأصغر يظهر الأول.'),
                ])->columns(2),
        ]);
    }

    public static function suggestKey(string $title): string
    {
        $slug = Str::slug(Str::ascii($title), '_');

        if ($slug === '' || ! preg_match('/[a-z]/', $slug)) {
            $slug = 'memory_'.Str::lower(Str::random(6));
        }

        $key = $slug;
        $n = 2;

        while (BusinessMemory::where('key', $key)->exists()) {
            $key = $slug.'_'.$n++;
        }

        return $key;
    }

    public static function pinnedBudgetText(?BusinessMemory $record, ?string $content = null): string
    {
        $others = BusinessMemory::where('is_pinned', true)->where('is_active', true)
            ->when($record, fn ($q) => $q->where('id', '!=', $record->id))
            ->get()
            ->sum(fn (BusinessMemory $m) => $m->estimatedTokens());
        $used = $others + self::estimateTokens($content);
        $cap = config('agent.context.pinned_memory_tokens');

        return $cap
            ? "{$used} من {$cap} توكن".($used > (int) $cap ? ' - زيادة عن الحد، مش هتتحفظ مثبتة' : '')
            : "{$used} توكن (مفيش حد متحدد)";
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('المعلومة')
                    ->searchable(['title', 'content', 'key'])
                    ->weight('bold')
                    ->description(fn (BusinessMemory $record) => Str::limit($record->content, 110)),
                Tables\Columns\TextColumn::make('category')
                    ->label('التصنيف')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => self::CATEGORIES[$state] ?? $state)
                    ->sortable(),
                Tables\Columns\TextColumn::make('usage')
                    ->label('إمتى بتتبعت')
                    ->state(fn (BusinessMemory $record) => match (true) {
                        $record->is_pinned => 'مع كل رسالة',
                        ! empty($record->scope_customer_types) || ! empty($record->scope_application_statuses) => 'مع طلبات معينة',
                        default => 'عند الحاجة',
                    })
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'مع كل رسالة' => 'warning',
                        'مع طلبات معينة' => 'info',
                        default => 'gray',
                    }),
                Tables\Columns\ToggleColumn::make('is_active')->label('شغالة'),
                Tables\Columns\TextColumn::make('tokens')
                    ->label('توكنز')
                    ->state(fn (BusinessMemory $record) => $record->estimatedTokens())
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('key')
                    ->label('المفتاح')
                    ->fontFamily('mono')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('آخر تعديل')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('priority')
            ->reorderable('priority')
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->label('التصنيف')
                    ->options(fn () => BusinessMemory::query()->whereNotNull('category')->distinct()->pluck('category', 'category')
                        ->map(fn ($c) => self::CATEGORIES[$c] ?? $c)),
                Tables\Filters\TernaryFilter::make('is_pinned')
                    ->label('مثبتة')
                    ->trueLabel('مع كل رسالة')
                    ->falseLabel('عند الحاجة'),
                Tables\Filters\TernaryFilter::make('is_active')->label('شغالة'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\ReplicateAction::make()
                    ->label('نسخ')
                    ->excludeAttributes(['key'])
                    ->beforeReplicaSaved(function (BusinessMemory $replica) {
                        $replica->title = $replica->title.' (نسخة)';
                        $replica->key = self::suggestKey($replica->title);
                        $replica->is_active = false;
                    }),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('activate')
                    ->label('تشغيل')
                    ->icon('heroicon-o-play')
                    ->action(fn ($records) => $records->each->update(['is_active' => true])),
                Tables\Actions\BulkAction::make('deactivate')
                    ->label('إيقاف')
                    ->icon('heroicon-o-pause')
                    ->action(fn ($records) => $records->each->update(['is_active' => false])),
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBusinessMemories::route('/'),
            'create' => Pages\CreateBusinessMemory::route('/create'),
            'edit' => Pages\EditBusinessMemory::route('/{record}/edit'),
        ];
    }
}
