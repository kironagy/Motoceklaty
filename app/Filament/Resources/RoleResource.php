<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages;
use App\Filament\Support\PermissionMatrix;
use App\Models\Role;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Dashboard roles: which tabs a group of staff sees and which actions they may take there.
 * Only the owner manages them, unless his role opens this tab to someone else.
 */
class RoleResource extends Resource
{
    use \App\Filament\Concerns\RoleGatedResource;

    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'إدارة الموظفين';

    protected static ?int $navigationSort = 2;

    protected static ?string $label = 'دور';

    protected static ?string $pluralLabel = 'الأدوار والصلاحيات';

    protected static ?string $navigationLabel = 'الأدوار والصلاحيات';

    public static function canViewAny(): bool
    {
        if (($allowed = static::roleAllows('view')) !== null) {
            return $allowed;
        }

        return (bool) auth()->user()?->is_hitler;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('بيانات الدور')
                ->description('علّم على التابات اللي الدور ده يشوفها والأكشنز المسموحة فيها. أي أكشن بيفتح التاب لوحده، وقفل "يشوف التاب" بيقفل التاب كله. استخدم "تطبيق تمبلت" عشان تعلّم مجموعة حاجات مرة واحدة.')
                ->icon('heroicon-o-shield-check')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('اسم الدور')
                        ->placeholder('مثلًا: مبيعات، مخازن، خدمة عملاء')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(100),
                ]),

            PermissionMatrix::field(),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('الدور')->searchable(),
                Tables\Columns\TextColumn::make('permissions')
                    ->label('التابات المفتوحة')
                    ->state(fn (Role $record) => collect($record->permissions ?? [])->filter(fn ($a) => in_array('view', (array) $a, true))->count()),
                Tables\Columns\TextColumn::make('staff_count')->label('عدد الموظفين')->counts('staff'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
