<?php

namespace App\Filament\Resources;

use App\Domain\Teaching\LessonBook;
use App\Filament\Resources\BotLessonResource\Pages;
use App\Models\BotLesson;
use App\Models\CustomerType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BotLessonResource extends Resource
{
    protected static ?string $model = BotLesson::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';
    protected static ?string $navigationGroup = 'البوت الذكي';
    protected static ?string $navigationLabel = 'دروس البوت';
    protected static ?string $modelLabel = 'درس';
    protected static ?string $pluralModelLabel = 'دروس البوت';
    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')->label('العنوان')->required(),
            Forms\Components\Textarea::make('rule')->label('القاعدة')->required()->rows(3)->columnSpanFull(),
            Forms\Components\TagsInput::make('fixed_facts')->label('ثوابت تتقال بالنص')->columnSpanFull(),
            Forms\Components\Textarea::make('example_context')->label('مثال: العميل قال')->rows(2),
            Forms\Components\Textarea::make('example_reply')->label('مثال للفكرة (مش للنسخ)')->rows(2),
            Forms\Components\Select::make('scope_stage')->label('امتى')->options(LessonBook::STAGES)->placeholder('دايمًا'),
            Forms\Components\Select::make('scope_customer_types')->label('لأنواع العملاء')->multiple()
                ->options(fn () => CustomerType::pluck('label', 'key'))->placeholder('الكل'),
            Forms\Components\TextInput::make('priority')->label('الترتيب')->numeric()->default(100),
            Forms\Components\Toggle::make('is_active')->label('شغال'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('الدرس')->searchable()->description(fn (BotLesson $r) => \Illuminate\Support\Str::limit($r->rule, 120))->wrap(),
                Tables\Columns\TextColumn::make('scope_stage')->label('امتى')->formatStateUsing(fn ($state) => LessonBook::STAGES[$state] ?? $state)->placeholder('دايمًا'),
                Tables\Columns\TextColumn::make('revision')->label('مراجعة'),
                Tables\Columns\ToggleColumn::make('is_active')->label('شغال'),
                Tables\Columns\TextColumn::make('updated_at')->label('آخر تعديل')->since(),
            ])
            ->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBotLessons::route('/'),
            'edit' => Pages\EditBotLesson::route('/{record}/edit'),
        ];
    }
}
