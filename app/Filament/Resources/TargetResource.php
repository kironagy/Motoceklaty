<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TargetResource\Pages;
use App\Models\Target;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TargetResource extends Resource
{
    use \App\Filament\Concerns\RoleGatedResource;
    use \App\Filament\Concerns\HasFullAccess;
    protected static ?string $model = Target::class;

    protected static ?string $navigationIcon = 'heroicon-o-flag';
    protected static ?string $navigationLabel = 'التارجت';
    protected static ?string $modelLabel = 'تارجت';
    protected static ?string $pluralModelLabel = 'التارجت';
    protected static ?string $navigationGroup = 'إدارة المعرض';
    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool
    {
        if (($allowed = static::roleAllows('view')) !== null) {
            return $allowed;
        }

        return \App\Support\Access::allows(static::class, 'view');
    }

    /** A showroom manager without full access sees his own showroom's target only. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('warehouse');

        if (! static::fullAccess()) {
            $query->where('warehouse_id', auth()->user()?->warehouse_id ?? 0);
        }

        return $query;
    }

    /** The month the list counts, from the table's month filter. */
    public static function month($livewire): Carbon
    {
        $value = $livewire?->getTableFilterState('month')['value'] ?? null;

        return $value && preg_match('/^\d{4}-\d{2}$/', $value) ? Carbon::parse($value.'-01') : now()->startOfMonth();
    }

    public static function monthOptions(): array
    {
        $months = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];

        return collect(range(-12, 1))
            ->mapWithKeys(function ($i) use ($months) {
                $m = now()->startOfMonth()->addMonthsNoOverflow($i);

                return [$m->format('Y-m') => $months[$m->month - 1].' '.$m->year];
            })
            ->reverse()
            ->all();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('بيانات التارجت')
                    ->icon('heroicon-o-flag')
                    ->description('التارجت شهري: العداد بيبدأ من الصفر أول كل شهر. التواريخ اختيارية - لو اتحددت العداد بيحسب جواها بس.')
                    ->schema([
                        Forms\Components\Select::make('warehouse_id')
                            ->label('المعرض / المخزن')
                            ->relationship('warehouse', 'name')
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required(),

                        Forms\Components\TextInput::make('target_count')
                            ->label('عدد المكن المطلوب بيعه في الشهر')
                            ->numeric()
                            ->minValue(1)
                            ->required(),

                        Forms\Components\DatePicker::make('from_date')
                            ->label('من تاريخ'),

                        Forms\Components\DatePicker::make('to_date')
                            ->label('إلى تاريخ')
                            ->afterOrEqual('from_date'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('مفعل')
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        $sold = fn (Target $record, $livewire) => $record->soldCount(static::month($livewire));

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('warehouse.name')
                    ->label('المعرض / المخزن')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('target_count')
                    ->label('التارجت')
                    ->sortable(),

                Tables\Columns\TextColumn::make('sold_count')
                    ->label('المباع في الشهر')
                    ->state($sold),

                Tables\Columns\TextColumn::make('remaining')
                    ->label('الباقي')
                    ->state(fn (Target $record, $livewire) => $record->periodIn(static::month($livewire))
                        ? max(0, $record->target_count - $sold($record, $livewire))
                        : '—'),

                Tables\Columns\TextColumn::make('progress')
                    ->label('العداد')
                    ->badge()
                    ->state(function (Target $record, $livewire) use ($sold) {
                        $n = $sold($record, $livewire);

                        if (! $record->periodIn(static::month($livewire))) {
                            return 'مش في الشهر ده';
                        }

                        if ($n >= $record->target_count) {
                            $extra = $n - $record->target_count;

                            return "✅ تم التارجت {$record->target_count}/{$record->target_count}".($extra ? " (+{$extra} زيادة)" : '');
                        }

                        return "{$n}/{$record->target_count} (".(int) floor($n / max(1, $record->target_count) * 100).'%)';
                    })
                    ->color(fn (Target $record, $livewire) => ! $record->periodIn(static::month($livewire)) ? 'gray'
                        : ($sold($record, $livewire) >= $record->target_count ? 'success' : 'warning')),

                Tables\Columns\TextColumn::make('from_date')
                    ->label('من')
                    ->date('Y-m-d')
                    ->placeholder('كل شهر')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('to_date')
                    ->label('إلى')
                    ->date('Y-m-d')
                    ->placeholder('مفتوح')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('مفعل')
                    ->boolean(),
            ])
            ->filters([
                // Only picks the month the counter shows; it doesn't hide rows.
                Tables\Filters\SelectFilter::make('month')
                    ->label('الشهر')
                    ->options(fn () => static::monthOptions())
                    ->default(now()->format('Y-m'))
                    ->selectablePlaceholder(false)
                    ->query(fn (Builder $query) => $query),

                Tables\Filters\SelectFilter::make('warehouse_id')
                    ->label('فلترة بالمعرض')
                    ->relationship('warehouse', 'name')
                    ->searchable()
                    ->preload()
                    ->visible(fn () => static::fullAccess()),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('مفعل'),
            ])
            ->filtersLayout(Tables\Enums\FiltersLayout::AboveContent)
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('تعديل'),

                Tables\Actions\DeleteAction::make()
                    ->label('حذف'),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make()
                    ->label('حذف المحدد'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTargets::route('/'),
            'create' => Pages\CreateTarget::route('/create'),
            'edit' => Pages\EditTarget::route('/{record}/edit'),
        ];
    }
}
