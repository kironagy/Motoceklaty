<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BrandResource\Pages;
use App\Filament\Resources\BrandResource\RelationManagers;
use App\Models\Brand;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class BrandResource extends Resource
{
    use \App\Filament\Concerns\RoleGatedResource;
    protected static ?string $model = Brand::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';
    protected static ?int $navigationSort = 2;
    protected static ?string $navigationGroup = 'الموتوسيكلات والفروع';
    protected static ?string $navigationLabel = 'الشركات';
    protected static ?string $pluralLabel = ' الشركات';
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\FileUpload::make('image')
                    ->image()
                    ->required(),
                Forms\Components\Select::make('web_icon')
                    ->label('أيقونة الماركة في فلتر المعرض على الموقع')
                    ->options([
                        'fa-solid fa-motorcycle' => 'موتوسيكل',
                        'fa-solid fa-bolt' => 'برق',
                        'fa-solid fa-gauge-high' => 'عداد سرعة',
                        'fa-solid fa-shield-halved' => 'درع',
                        'fa-solid fa-wind' => 'رياح (سكوتر)',
                        'fa-solid fa-compass' => 'بوصلة',
                        'fa-solid fa-feather' => 'ريشة',
                        'fa-solid fa-fire' => 'نار',
                        'fa-solid fa-truck-pickup' => 'تروسيكل',
                        'fa-solid fa-star' => 'نجمة',
                        'fa-solid fa-charging-station' => 'شحن كهربائي',
                        'fa-solid fa-gem' => 'جوهرة',
                        'fa-solid fa-crown' => 'تاج',
                    ])
                    ->placeholder('موتوسيكل (الافتراضي)'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\ImageColumn::make('image'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBrands::route('/'),
            'create' => Pages\CreateBrand::route('/create'),
            'edit' => Pages\EditBrand::route('/{record}/edit'),
        ];
    }
    public static function canViewAny(): bool
    {
        if (($allowed = static::roleAllows('view')) !== null) {
            return $allowed;
        }

        $user = auth()->user();

        // 🟢 لو المستخدم لسه مش مسجل (auth null) => غالباً الأدمن داخل أول مرة => نعرضها
        if (!$user) {
            return true;
        }

        // ✅ السماح فقط للأدمن
        return $user->is_admin ?? false;
    }
}
