<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StaffResource\Pages;
use App\Models\Staff;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use Filament\Forms\Get;
use App\Models\InstallmentSystem;
use Filament\Forms\Set;
class StaffResource extends Resource
{
    protected static ?string $model = Staff::class;
    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationGroup = 'التوظيف';
    protected static ?string $label = 'الموظفين';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label(' الاسم ')
                ->required(),

            Forms\Components\TextInput::make('email')
                ->label('البريد الإلكتروني')
                ->email()
                ->required(),

            Forms\Components\TextInput::make('password')
                ->label('كلمة المرور')
                ->password()
                ->required(fn (string $context): bool => $context === 'create')
                ->dehydrateStateUsing(fn ($state) => filled($state) ? Hash::make($state) : null)
                ->dehydrated(fn ($state) => filled($state))
                ->columnSpan('full'),

Forms\Components\Toggle::make('is_admin')
    ->label('هل أدمن؟')
    ->visible(fn () => auth()->user()?->is_super_admin)
    ->disabled(fn (?Staff $record) => $record?->id === auth()->id())
    ->helperText('السوبر أدمن فقط يستطيع تعديل هذه الصلاحية.'),

Forms\Components\Toggle::make('is_super_admin')
    ->label('هل سوبر أدمن؟')
    ->visible(fn () => auth()->user()?->is_super_admin)
    ->disabled(fn (?Staff $record) => $record?->id === auth()->id())
    ->helperText('لا يمكن للسوبر أدمن تعديل صلاحيات حسابه بنفسه.'),

Forms\Components\Toggle::make('is_bot')
    ->label('هل هذا الموظف بوت؟')
    ->default(false)
    ->visible(fn () => auth()->user()?->is_super_admin)
    ->disabled(fn (?Staff $record) => $record?->id === auth()->id())
    ->helperText('السوبر أدمن فقط يستطيع تحديد حسابات البوت.'),

Forms\Components\Toggle::make('is_company_employee')
    ->label('هل موظف شركة؟')
    ->default(false)
    ->live()
    ->visible(fn () => auth()->user()?->is_super_admin)
    ->disabled(fn (?Staff $record) => $record?->id === auth()->id())
    ->afterStateUpdated(function ($state, callable $set) {
        if (! $state) {
            $set('installmentSystems', []);
        }
    })
    ->helperText('السوبر أدمن فقط يستطيع ربط الموظف بشركات التقسيط.'),

Forms\Components\Select::make('installmentSystems')
    ->label('تابع للشركات')
    ->relationship('installmentSystems', 'name')
    ->multiple()
    ->searchable()
    ->preload()
    ->visible(fn (Get $get) =>
        auth()->user()?->is_super_admin &&
        $get('is_company_employee')
    )
    ->required(fn (Get $get) =>
        auth()->user()?->is_super_admin &&
        (bool) $get('is_company_employee')
    )
    ->columnSpan('full'), 
   
          ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('الاسم')
                    ->searchable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('البريد الإلكتروني'),

                Tables\Columns\IconColumn::make('is_admin')
                    ->boolean()
                    ->label('أدمن'),

                Tables\Columns\IconColumn::make('is_super_admin')
                    ->boolean()
                    ->label('سوبر أدمن'),

               Tables\Columns\IconColumn::make('is_bot')
    ->boolean()
    ->label('بوت')
    ->visible(fn () => auth()->user()?->is_super_admin),
Tables\Columns\IconColumn::make('is_company_employee')
    ->boolean()
    ->label('موظف شركة'),

Tables\Columns\TextColumn::make('installmentSystems.name')
    ->label('الشركات')
    ->badge()
    ->separator(',')
    ->placeholder('-'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإضافة')
                    ->dateTime('d/m/Y'),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }
public static function mutateFormDataBeforeSave(array $data, ?Staff $record = null): array
{
    $user = auth()->user();

    // أي شخص غير السوبر أدمن لا يمكنه إرسال أو تعديل الرولات.
    if (! $user?->is_super_admin) {
        unset(
            $data['is_admin'],
            $data['is_super_admin'],
            $data['is_bot'],
            $data['is_company_employee'],
            $data['installmentSystems'],
        );
    }

    // السوبر أدمن لا يغير صلاحيات حسابه الشخصي.
    if ($record && $record->id === $user?->id) {
        unset(
            $data['is_admin'],
            $data['is_super_admin'],
            $data['is_bot'],
            $data['is_company_employee'],
            $data['installmentSystems'],
        );
    }

    return $data;
}
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStaff::route('/'),
            'create' => Pages\CreateStaff::route('/create'),
            'edit' => Pages\EditStaff::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        if (!$user) {
            return false;
        }

        return $user->is_admin || $user->is_super_admin;
    }
}

