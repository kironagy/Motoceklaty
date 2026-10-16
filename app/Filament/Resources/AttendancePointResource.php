<?php

namespace App\Filament\Resources;

use App\Domain\Attendance\AttendancePoints;
use App\Filament\Resources\AttendancePointResource\Pages;
use App\Models\AttendancePoint;
use App\Models\Staff;
use Filament\Actions\Action as PageAction;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Green / red / black points (App\Domain\Attendance\AttendancePoints).
 * Without full access an employee sees his own points and cancels his own reds / blacks;
 * full access sees everyone, adds points by hand and turns a clean month's greens into a bonus.
 */
class AttendancePointResource extends Resource
{
    use \App\Filament\Concerns\RoleGatedResource;
    use \App\Filament\Concerns\HasFullAccess;

    protected static ?string $model = AttendancePoint::class;

    protected static ?string $navigationLabel = 'النقط';
    protected static ?string $modelLabel = 'نقطة';
    protected static ?string $pluralModelLabel = 'النقط';
    protected static ?string $navigationIcon = 'heroicon-o-star';
    protected static ?int $navigationSort = 3;
    protected static ?string $navigationGroup = 'إدارة الموظفين';

    public static function canViewAny(): bool
    {
        return \App\Support\Access::allows(static::class, 'view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        // Only points added by hand, still unused, by full access whose role also allows deleting.
        return (static::roleAllows('delete') ?? true)
            && static::fullAccess()
            && $record->status === AttendancePoints::ACTIVE
            && $record->source === 'manual';
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['staff', 'creator']);

        if (! static::fullAccess()) {
            $query->where('staff_id', auth()->id());
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('earned_on', 'desc')
            ->columns([
                TextColumn::make('earned_on')->label('اليوم')->date('Y-m-d')->sortable(),
                TextColumn::make('staff.name')->label('الموظف')->searchable()->visible(fn () => static::fullAccess()),
                TextColumn::make('color')
                    ->label('النقطة')
                    ->badge()
                    ->formatStateUsing(fn ($state) => (AttendancePoints::ICONS[$state] ?? '').' '.(AttendancePoints::LABELS[$state] ?? $state))
                    ->color(fn ($state, AttendancePoint $record) => $record->status !== AttendancePoints::ACTIVE ? 'gray' : match ($state) {
                        AttendancePoints::GREEN => 'success',
                        AttendancePoints::RED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('amount')
                    ->label('الخصم')
                    ->formatStateUsing(fn ($state) => (float) $state > 0 ? number_format((float) $state).' ج' : null)
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        AttendancePoints::ACTIVE => 'قائمة',
                        AttendancePoints::USED => 'اتستخدمت',
                        AttendancePoints::CANCELED => 'اتلغت',
                        default => $state,
                    })
                    ->color(fn ($state) => $state === AttendancePoints::ACTIVE ? 'warning' : 'gray'),
                TextColumn::make('source')
                    ->label('من')
                    ->formatStateUsing(fn ($state, AttendancePoint $record) => $state === 'manual'
                        ? 'إضافة يدوي'.($record->creator ? ' - '.$record->creator->name : '')
                        : 'البصمة'),
                TextColumn::make('reason')->label('السبب')->placeholder('—')->wrap(),
            ])
            ->filters([
                SelectFilter::make('staff_id')
                    ->label('الموظف')
                    ->options(fn () => Staff::query()->where('is_showroom_employee', true)->orderBy('name')->pluck('name', 'id'))
                    ->visible(fn () => static::fullAccess()),
                SelectFilter::make('color')->label('النقطة')->options([
                    AttendancePoints::GREEN => 'خضرا',
                    AttendancePoints::RED => 'حمرا',
                    AttendancePoints::BLACK => 'سودا',
                ]),
                SelectFilter::make('status')->label('الحالة')->options([
                    AttendancePoints::ACTIVE => 'قائمة',
                    AttendancePoints::USED => 'اتستخدمت',
                    AttendancePoints::CANCELED => 'اتلغت',
                ]),
            ])
            ->actions([
                static::cancelPointAction(),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ]);
    }

    /** Spends greens earned after a red / black to cancel it - by the employee himself or a full-access admin. */
    public static function cancelPointAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('cancel_point')
            ->label('الغي النقطة')
            ->icon('heroicon-o-arrow-path')
            ->color('success')
            ->visible(fn (AttendancePoint $record) => ((int) $record->staff_id === (int) auth()->id() || static::fullAccess())
                && AttendancePoints::canCancel($record))
            ->requiresConfirmation()
            ->modalHeading(fn (AttendancePoint $record) => 'إلغاء '.(AttendancePoints::LABELS[$record->color] ?? 'النقطة'))
            ->modalDescription(fn (AttendancePoint $record) => 'هيتخصم '.AttendancePoints::greensNeeded($record->color)
                .' نقط خضرا من اللي اتحسبت بعد يوم '.$record->earned_on->format('Y-m-d')
                .'، والخصم ('.number_format((float) $record->amount).' ج) هيتشال من المرتب.')
            ->modalSubmitActionLabel('استبدل')
            ->action(function (AttendancePoint $record) {
                try {
                    AttendancePoints::cancel($record, auth()->user());
                } catch (\RuntimeException $e) {
                    Notification::make()->title('مينفعش')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('اتلغت النقطة واتشال الخصم')->success()->send();
            });
    }

    /** Points by hand: greens as a reward, reds / blacks as a deduction (each at today's set value). */
    public static function manualPointsAction(): PageAction
    {
        return PageAction::make('manual_points')
            ->label('إضافة نقط لموظف')
            ->icon('heroicon-o-plus-circle')
            ->visible(fn () => static::fullAccess())
            ->form([
                Forms\Components\Select::make('staff_id')
                    ->label('الموظف')
                    ->options(fn () => Staff::query()->where('is_showroom_employee', true)->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required(),
                Forms\Components\ToggleButtons::make('color')
                    ->label('النوع')
                    ->options([
                        AttendancePoints::GREEN => '🟢 بونص (خضرا)',
                        AttendancePoints::RED => '🔴 خصم (حمرا)',
                        AttendancePoints::BLACK => '⚫ خصم (سودا)',
                    ])
                    ->inline()
                    ->live()
                    ->required(),
                Forms\Components\TextInput::make('count')
                    ->label('عدد النقط')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(30)
                    ->default(1)
                    ->live()
                    ->required()
                    ->helperText(fn (Get $get) => in_array($get('color'), [AttendancePoints::RED, AttendancePoints::BLACK], true)
                        ? 'الخصم: '.number_format(AttendancePoints::amountFor($get('color')) * max(1, (int) $get('count'))).' ج'
                        : null),
                Forms\Components\DatePicker::make('date')->label('اليوم')->default(today())->required(),
                Forms\Components\TextInput::make('reason')->label('السبب')->required()->maxLength(255),
            ])
            ->action(function (array $data) {
                AttendancePoints::addManual(
                    Staff::findOrFail($data['staff_id']),
                    $data['color'],
                    (int) $data['count'],
                    $data['date'],
                    $data['reason'],
                    auth()->user(),
                );

                Notification::make()->title('اتضافت النقط')->success()->send();
            });
    }

    /** A month with no red / black standing: the admin turns that month's greens into a bonus. */
    public static function greensToBonusAction(): PageAction
    {
        return PageAction::make('greens_to_bonus')
            ->label('حوّل النقط الخضرا لبونص')
            ->icon('heroicon-o-gift')
            ->color('success')
            ->visible(fn () => static::fullAccess())
            ->form([
                Forms\Components\TextInput::make('month')
                    ->label('الشهر')
                    ->type('month')
                    ->default(now()->format('Y-m'))
                    ->required()
                    ->live(),
                Forms\Components\Select::make('staff_id')
                    ->label('الموظف')
                    ->helperText('بيظهر بس اللي عنده نقط خضرا ومعندوش نقط حمرا أو سودا قائمة في الشهر ده.')
                    ->options(function (Get $get) {
                        $month = Carbon::parse(($get('month') ?: now()->format('Y-m')).'-01');

                        return Staff::query()
                            ->where('is_showroom_employee', true)
                            ->orderBy('name')
                            ->get()
                            ->filter(fn (Staff $s) => AttendancePoints::canConvertToBonus($s->id, $month))
                            ->mapWithKeys(fn (Staff $s) => [$s->id => $s->name.' - 🟢 '.AttendancePoints::monthSummary($s->id, $month)['green']]);
                    })
                    ->searchable()
                    ->required(),
                Forms\Components\TextInput::make('amount')
                    ->label('قيمة البونص')
                    ->numeric()
                    ->prefix('ج')
                    ->minValue(1)
                    ->required(),
            ])
            ->action(function (array $data) {
                try {
                    AttendancePoints::convertToBonus(
                        Staff::findOrFail($data['staff_id']),
                        Carbon::parse($data['month'].'-01'),
                        (float) $data['amount'],
                        auth()->user(),
                    );
                } catch (\RuntimeException $e) {
                    Notification::make()->title('مينفعش')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('اتضاف البونص واتستخدمت النقط الخضرا')->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAttendancePoints::route('/'),
        ];
    }
}
