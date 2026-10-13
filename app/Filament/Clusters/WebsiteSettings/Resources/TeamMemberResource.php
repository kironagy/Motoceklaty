<?php

namespace App\Filament\Clusters\WebsiteSettings\Resources;

use App\Filament\Clusters\WebsiteSettings;
use App\Filament\Clusters\WebsiteSettings\Resources\TeamMemberResource\Pages;
use App\Models\TeamMember;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TeamMemberResource extends Resource
{
    protected static ?string $model = TeamMember::class;

    protected static ?string $cluster = WebsiteSettings::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'محتوى الموقع';

    protected static ?string $navigationLabel = 'فريق العمل';

    protected static ?string $modelLabel = 'عضو';

    protected static ?string $pluralModelLabel = 'فريق العمل';

    protected static ?int $navigationSort = 21;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('الاسم')->required()->maxLength(255),
            Forms\Components\TextInput::make('role')->label('الوظيفة')->maxLength(255),
            Forms\Components\FileUpload::make('image')->label('الصورة')
                ->image()->disk('public')->directory('site/team')->imageEditor()->columnSpanFull()
                ->helperText('لو مفيش صورة هيظهر أول حرف من الاسم.'),
            Forms\Components\Toggle::make('is_active')->label('يظهر في صفحة فريق العمل')->default(true),
            Forms\Components\Toggle::make('show_on_about')->label('يظهر في صفحة من نحن')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort')
            ->defaultSort('sort')
            ->columns([
                Tables\Columns\ImageColumn::make('image')->label('الصورة')->circular()
                    ->getStateUsing(fn (TeamMember $record) => $record->imageUrl()),
                Tables\Columns\TextColumn::make('name')->label('الاسم')->searchable(),
                Tables\Columns\TextColumn::make('role')->label('الوظيفة'),
                Tables\Columns\ToggleColumn::make('is_active')->label('ظاهر'),
                Tables\Columns\ToggleColumn::make('show_on_about')->label('في من نحن'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageTeamMembers::route('/')];
    }
}
