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
                ->dehydrated(fn ($state) => filled($state)),

            Forms\Components\Toggle::make('is_admin')
                ->label('هل أدمن؟'),

            Forms\Components\Toggle::make('is_super_admin')
                ->label('هل سوبر أدمن؟')
                ->helperText('يمتلك جميع الصلاحيات'),
                
                
          Forms\Components\Toggle::make('is_bot')
            ->label('هل هذا الموظف بوت؟')
            ->default(false)
            ->visible(fn () => auth()->user()?->is_super_admin)
            ->helperText('فعّل ده لو الموظف مخصص لربطه ببوت واتساب'),
                

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

