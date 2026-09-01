<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AiMemoryResource\Pages;
use App\Models\AiMemory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AiMemoryResource extends Resource
{
    protected static ?string $model = AiMemory::class;

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationGroup = 'WhatsApp AI';

    protected static ?string $navigationLabel = 'ذاكرة الذكاء الاصطناعي';

    protected static ?string $modelLabel = 'ميموري';

    protected static ?string $pluralModelLabel = 'الميموري';

    protected static ?int $navigationSort = 1;

public static function form(Form $form): Form
{
    return $form
        ->schema([

            Forms\Components\Section::make('بيانات الميموري')
                ->schema([

                    Forms\Components\TextInput::make('title')
                        ->label('العنوان')
                        ->required(),

                    Forms\Components\Textarea::make('content')
                        ->label('المحتوى')
                        ->rows(20)
                        ->required()
                        ->columnSpanFull(),

                    Forms\Components\Repeater::make('template_replies')
                        ->label('ردود جاهزة بصيغ مختلفة')
                        ->schema([
                            Forms\Components\Textarea::make('reply')
                                ->label('نص الرد')
                                ->rows(4)
                                ->required()
                                ->helperText('اكتب صيغة رد مختلفة. تقدر تستخدم متغيرات زي {machine_name} و {cash_price} و {installment_price}.'),
                        ])
                        ->addActionLabel('إضافة رد')
                        ->reorderable()
                        ->collapsible()
                        ->collapsed()
                        ->defaultItems(0)
                        ->columnSpanFull(),

                    Forms\Components\Toggle::make('is_active')
                        ->label('مفعلة')
                        ->default(true),

                    Forms\Components\TextInput::make('sort')
                        ->label('الترتيب')
                        ->numeric()
                        ->default(10),

                ])
                ->columns(2),

            Forms\Components\Section::make('تصنيف الاسترجاع (Retrieval Metadata)')
                ->description('تحدد إمتى الميموري دي تتبعت للـ AI. متأثرش على معنى المحتوى.')
                ->schema([

                    Forms\Components\Select::make('category')
                        ->label('التصنيف')
                        ->options([
                            'pricing' => 'أسعار',
                            'eligibility' => 'شروط أهلية',
                            'application' => 'بيانات التقديم',
                            'catalog' => 'المخزون والموديلات',
                            'support' => 'دعم/فروع',
                            'ocr' => 'قراءة مستندات',
                            'style' => 'أسلوب الرد',
                        ])
                        ->searchable(),

                    Forms\Components\Select::make('scope')
                        ->label('النطاق')
                        ->options([
                            'always_include' => 'دايمًا تتبعت (قاعدة صارمة)',
                            'fallback_context_only' => 'للرد الحر بس، مش مصدر أسعار',
                        ])
                        ->helperText('سيب فاضي للسلوك العادي (بتترشح حسب التشابه مع رسالة العميل).'),

                    Forms\Components\CheckboxList::make('applicable_intents')
                        ->label('النوايا المرتبطة')
                        ->options([
                            'price' => 'سعر',
                            'images' => 'صور',
                            'installment_calc' => 'حساب قسط',
                            'installment_system' => 'نظام تقسيط',
                            'brand_models' => 'موديلات براند',
                            'application' => 'تقديم',
                            'application_status' => 'حالة الطلب',
                            'delivery_question' => 'توصيل',
                            'payment_question' => 'دفع',
                            'warranty_question' => 'ضمان',
                            'complaint' => 'شكوى',
                            'faq' => 'أسئلة شائعة',
                            'small_talk' => 'كلام عام',
                        ])
                        ->columns(3)
                        ->helperText('سيبها فاضية لو الميموري تنفع مع أي نية.'),

                    Forms\Components\TagsInput::make('keywords')
                        ->label('كلمات مفتاحية إضافية')
                        ->helperText('كلمات بتساعد الاسترجاع تلاقي الميموري دي غير الموجودة في العنوان/المحتوى.'),

                    Forms\Components\TextInput::make('priority')
                        ->label('الأولوية')
                        ->numeric()
                        ->default(0),

                    /*
                     * القواعد المنفَّذة (خطة 3.3): النص فوق بيقرأه الموديل،
                     * والبلوك ده بينفّذه الكود نفسه - مهنة ممنوعة تتضاف هنا
                     * بتقفل التقديم فورًا من غير نشر كود. الإضافة بس:
                     * القوايم المكتوبة في الكود مبتتشالش من هنا.
                     */
                    Forms\Components\KeyValue::make('rules')
                        ->label('قواعد منفَّذة (اختياري)')
                        ->keyLabel('المفتاح')
                        ->valueLabel('القيمة (افصل بينهم بفاصلة)')
                        ->columnSpanFull()
                        ->helperText(
                            'المفاتيح المدعومة: banned_professions (مهن ممنوعة) · job_category (اسم فئة شغل) · '
                            . 'job_keywords (كلمات بتدل على الفئة) · required_documents (المستندات المطلوبة للفئة: '
                            . 'id_card_front, id_card_back, salary_slip, pension_statement, activity_photo, '
                            . 'bank_statement, driver_license, vehicle_license, work_app_screens - سكرينات تطبيق الشغل: تاريخ التعيين والملف التعريفي ودخل آخر 3 شهور). سيبها فاضية '
                            . 'لو الميموري للقراءة بس.'
                        ),

                ])
                ->columns(2),

        ]);
}    
public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([

                Tables\Columns\TextColumn::make('sort')
                    ->label('الترتيب')
                    ->sortable(),

                Tables\Columns\TextColumn::make('title')
                    ->label('العنوان')
                    ->searchable()
                    ->sortable(),
Tables\Columns\TextColumn::make('template_replies_count')
    ->label('عدد الردود')
    ->state(fn ($record) => is_array($record->template_replies) ? count($record->template_replies) : 0),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('الحالة')
                    ->boolean(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('آخر تعديل')
                    ->dateTime('Y-m-d h:i A'),

            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiMemories::route('/'),
            'create' => Pages\CreateAiMemory::route('/create'),
            'edit' => Pages\EditAiMemory::route('/{record}/edit'),
        ];
    }
}

