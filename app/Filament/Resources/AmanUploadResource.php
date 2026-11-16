<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AmanUploadResource\Pages;
use App\Models\AmanUpload;
use App\Models\Staff;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Daily report of the Aman uploader extension (aman-extension/): every request it worked on,
 * who uploaded it, how it ended and Aman's reference number. Read-only - rows are written by
 * the extension (POST /api/aman/uploads/{id}/result). An admin sees everyone; others their own.
 */
class AmanUploadResource extends Resource
{
    use \App\Filament\Concerns\RoleGatedResource;

    protected static ?string $model = AmanUpload::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';
    protected static ?string $navigationGroup = 'الطلبات والعملاء';
    protected static ?string $navigationLabel = 'تقرير الرفع على أمان';
    protected static ?string $modelLabel = 'رفع على أمان';
    protected static ?string $pluralModelLabel = 'تقرير الرفع على أمان';
    protected static ?int $navigationSort = 3;

    public const STATUS = [
        'uploaded' => 'اترفع',
        'rejected_before' => 'مرفوض قبل كده',
        'error' => 'مشكلة',
        'released' => 'اتوقف',
        'claimed' => 'شغال عليه',
    ];

    public const COLORS = ['uploaded' => 'success', 'rejected_before' => 'danger', 'error' => 'warning', 'released' => 'gray', 'claimed' => 'info'];

    public static function canViewAny(): bool
    {
        if (($allowed = static::roleAllows('view')) !== null) {
            return $allowed;
        }
        $user = Auth::user();

        return $user instanceof Staff && ($user->isAdmin() || $user->is_company_employee);
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
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['request:id,applicant_name,applicant_national_id,installment_type', 'staff:id,name']);
        $user = Auth::user();

        return $user instanceof Staff && $user->isAdmin() ? $query : $query->where('staff_id', $user?->id ?? 0);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('finished_at')->label('الوقت')->dateTime('Y-m-d H:i')->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('installment_request_id')->label('رقم الطلب في موتوجيت')->searchable()
                    ->url(fn (AmanUpload $r) => DeliveryResource::getUrl('edit', ['record' => $r->installment_request_id])),
                Tables\Columns\TextColumn::make('request.applicant_name')->label('العميل')->searchable(),
                Tables\Columns\TextColumn::make('request.applicant_national_id')->label('الرقم القومي')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('type')->label('النوع')
                    ->formatStateUsing(fn ($state) => config('aman_extension.types')[$state] ?? $state),
                Tables\Columns\TextColumn::make('status')->label('النتيجة')->badge()
                    ->formatStateUsing(fn ($state) => self::STATUS[$state] ?? $state)
                    ->color(fn ($state) => self::COLORS[$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('aman_ref')->label('رقم الطلب في أمان')->copyable()->searchable()->placeholder('—'),
                Tables\Columns\TextColumn::make('staff.name')->label('الموظف'),
                Tables\Columns\TextColumn::make('message')->label('ملاحظات')->wrap()->limit(80)->placeholder('—'),
            ])
            ->defaultSort('finished_at', 'desc')
            ->filters([
                Filter::make('day')
                    ->form([DatePicker::make('day')->label('اليوم')->default(now()->toDateString())])
                    ->query(fn (Builder $q, array $data) => $q->when($data['day'] ?? null, fn ($q, $d) => $q->whereDate('claimed_at', $d)))
                    ->indicateUsing(fn (array $data) => ($data['day'] ?? null) ? 'يوم '.$data['day'] : null),
                SelectFilter::make('status')->label('النتيجة')->options(self::STATUS),
                SelectFilter::make('staff_id')->label('الموظف')->relationship('staff', 'name')->visible(fn () => Auth::user()?->isAdmin()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAmanUploads::route('/')];
    }
}
