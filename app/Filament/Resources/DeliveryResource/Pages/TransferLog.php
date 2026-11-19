<?php

namespace App\Filament\Resources\DeliveryResource\Pages;

use App\Filament\Resources\DeliveryResource;
use App\Models\InstallmentRequest;
use App\Models\Staff;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/**
 * Owner 2026-11-19: "لوج التحويل" - every move of a request from one employee to another: the request,
 * who had it, who got it and who moved it. Read from the activity log (staff_id changes); owner only.
 */
class TransferLog extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = DeliveryResource::class;

    protected static string $view = 'filament.resources.deliveries.transfer-log';

    protected static ?string $title = 'لوج التحويل';

    private ?array $staffNames = null;

    public static function canAccess(array $parameters = []): bool
    {
        return DeliveryResource::isHitler();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back')->label('رجوع للطلبات')->icon('heroicon-o-arrow-right')->color('gray')
                ->url(DeliveryResource::getUrl('index')),
        ];
    }

    private function staffName($id): string
    {
        $this->staffNames ??= Staff::query()->pluck('name', 'id')->all();

        return $id === null || $id === '' ? '—' : ($this->staffNames[$id] ?? "موظف #{$id}");
    }

    private static function staffOptions(): array
    {
        return Staff::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    private static function json(string $path): string
    {
        return "json_unquote(json_extract(activity_log.properties, '$.{$path}.staff_id'))";
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Activity::query()
                ->where('subject_type', InstallmentRequest::class)
                // a move from someone to someone else (a new request's first owner is not a transfer)
                ->whereRaw(self::json('old').' is not null')
                ->whereRaw(self::json('attributes').' is not null')
                ->whereRaw(self::json('old').' <> '.self::json('attributes'))
                // the same move logged twice in one second (two runs of the parking job at once, 2026-11-19): show it once
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('activity_log as dup')
                    ->whereColumn('dup.subject_type', 'activity_log.subject_type')
                    ->whereColumn('dup.subject_id', 'activity_log.subject_id')
                    ->whereColumn('dup.created_at', 'activity_log.created_at')
                    ->whereColumn('dup.id', '<', 'activity_log.id')
                    ->whereRaw("json_unquote(json_extract(dup.properties, '$.attributes.staff_id')) <=> ".self::json('attributes'))
                    ->whereRaw("json_unquote(json_extract(dup.properties, '$.old.staff_id')) <=> ".self::json('old')))
                ->with(['subject' => fn ($q) => $q->withTrashed()]))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('الوقت')
                    ->dateTime('d/m/Y h:i A')->sortable(),
                Tables\Columns\TextColumn::make('subject_id')->label('رقم الطلب')
                    ->formatStateUsing(fn ($state) => '#'.$state)
                    ->url(fn (Activity $r) => $r->subject ? DeliveryResource::getUrl('edit', ['record' => $r->subject_id]) : null)
                    ->color('primary')
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn ($q) => $q
                        ->where('subject_id', (int) ltrim($search, '#'))
                        ->orWhereIn('subject_id', InstallmentRequest::withTrashed()
                            ->where('applicant_name', 'like', "%{$search}%")->select('id')))),
                Tables\Columns\TextColumn::make('subject.applicant_name')->label('العميل')->placeholder('—'),
                Tables\Columns\TextColumn::make('subject.installment_type')->label('الشركة')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('subject.status')->label('حالته دلوقتي')->badge()
                    ->formatStateUsing(fn ($state) => DeliveryResource::STATUS_LABELS[$state] ?? $state)
                    ->color(fn ($state) => DeliveryResource::STATUS_COLORS[$state] ?? 'gray')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('from')->label('كان مع')
                    ->state(fn (Activity $r) => $this->staffName(data_get($r->properties, 'old.staff_id'))),
                Tables\Columns\TextColumn::make('to')->label('اتحول لـ')->weight('bold')
                    ->state(fn (Activity $r) => $this->staffName(data_get($r->properties, 'attributes.staff_id'))),
                Tables\Columns\TextColumn::make('by')->label('مين حوّله')
                    ->state(fn (Activity $r) => match (true) {
                        $r->causer_id === null => 'السيستم',
                        $r->causer_type === Staff::class => $this->staffName($r->causer_id),
                        default => 'العميل',
                    })
                    ->color(fn (Activity $r) => $r->causer_id === null ? 'gray' : null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('from_staff')->label('كان مع')->options(fn () => self::staffOptions())->searchable()
                    ->query(fn (Builder $q, array $data) => filled($data['value'] ?? null) ? $q->whereRaw(self::json('old').' = ?', [(string) $data['value']]) : $q),
                Tables\Filters\SelectFilter::make('to_staff')->label('اتحول لـ')->options(fn () => self::staffOptions())->searchable()
                    ->query(fn (Builder $q, array $data) => filled($data['value'] ?? null) ? $q->whereRaw(self::json('attributes').' = ?', [(string) $data['value']]) : $q),
                Tables\Filters\SelectFilter::make('by_staff')->label('مين حوّله')->options(fn () => ['system' => 'السيستم'] + self::staffOptions())->searchable()
                    ->query(fn (Builder $q, array $data) => match ($data['value'] ?? null) {
                        null, '' => $q,
                        'system' => $q->whereNull('causer_id'),
                        default => $q->where('causer_type', Staff::class)->where('causer_id', $data['value']),
                    }),
                Tables\Filters\Filter::make('date')->label('التاريخ')
                    ->form([
                        DatePicker::make('from')->label('من يوم'),
                        DatePicker::make('until')->label('لحد يوم'),
                    ])
                    ->query(fn (Builder $q, array $data) => $q
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))),
            ])
            ->paginated([25, 50, 100]);
    }
}
