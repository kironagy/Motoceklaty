<?php

namespace App\Filament\Pages;

use App\Support\{Access, WorkFactoryExport};
use Filament\Pages\Page;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http;

class WorkFactory extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';
    protected static ?string $navigationLabel = 'مصنع الشغل';
    protected static ?string $title = 'مصنع الشغل';
    protected static ?string $navigationGroup = 'إدارة المعرض';
    protected static ?int $navigationSort = 7;
    protected static ?string $slug = 'work-factory';
    protected static string $view = 'filament.pages.work-factory';
    public array $connection = [];
    public ?string $connectionError = null;

    public static function canAccess(): bool { return Access::allows(static::class, 'view'); }

    private function worker(string $method = 'get', string $suffix = ''): array
    {
        abort_unless(static::canAccess(), 403);
        $id = Access::user()?->getKey();
        abort_unless($id, 403);
        return Http::connectTimeout(3)->timeout(30)->withHeaders(['X-Bot-Token' => config('services.whatsapp.bot_token')])
            ->{$method}(rtrim(config('services.whatsapp.factory_url', 'http://127.0.0.1:3081'), '/').'/sessions/staff_'.$id.$suffix)->throw()->json();
    }

    public function mount(): void { $this->refreshConnection(); }

    public function refreshConnection(): void
    {
        try { $this->connection = $this->worker(); $this->connectionError = null; }
        catch (\Illuminate\Http\Client\ConnectionException|\Illuminate\Http\Client\RequestException $e) {
            $this->connection = []; $this->connectionError = 'خدمة الاتصال غير متاحة مؤقتًا. حدّث الحالة أو حاول لاحقًا.';
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('switchAccount')->label('ربط حساب واتساب آخر')->icon('heroicon-o-arrow-path')
                ->color('warning')->requiresConfirmation()
                ->modalHeading('ربط حساب واتساب آخر')
                ->modalDescription('سيتم إيقاف الاتصال الحالي وحفظ بياناته في أرشيف منفصل، ثم فتح QR جديد. قائمة الحساب الجديد تبدأ من صفر بدون خلط بالأرقام القديمة. قد تحتاج إزالة ربط مصنع الشغل القديم من الأجهزة المرتبطة في هاتفك.')
                ->modalSubmitActionLabel('فتح QR لحساب آخر')
                ->action(function () {
                    try { $this->worker('post', '/switch'); $this->refreshConnection(); }
                    catch (\Illuminate\Http\Client\HttpClientException $e) { Notification::make()->danger()->title('تعذر تبديل الحساب')->body('انتظر انتهاء محاولة الربط ثم أعد المحاولة. البيانات القديمة محفوظة.')->send(); }
                }),
            Action::make('connect')->label('ربط واتساب / إظهار QR')->icon('heroicon-o-qr-code')
                ->action(function () {
                    try { $this->worker('post', '/start'); $this->refreshConnection(); }
                    catch (\Illuminate\Http\Client\HttpClientException $e) { Notification::make()->danger()->title('تعذر بدء الاتصال')->body('حاول مرة أخرى أو راجع مسؤول النظام.')->send(); }
                }),
            Action::make('export')->label('تحميل Excel')->icon('heroicon-o-arrow-down-tray')->color('success')
                ->action(fn () => $this->download(1)),
            Action::make('split')->label('تقسيم الداتا على التيم')->icon('heroicon-o-squares-2x2')
                ->form([TextInput::make('parts')->label('عدد أفراد التيم / الملفات')->integer()->minValue(2)->maxValue(100)->required()->default(2)])
                ->action(fn (array $data) => $this->download((int) $data['parts'])),
            Action::make('disconnect')->label('فصل الحساب')->color('danger')->requiresConfirmation()
                ->modalDescription('هيتم فصل جلسة مصنع الشغل فقط. قائمة العملاء المستخرجة هتفضل محفوظة في حسابك.')
                ->action(function () {
                    try { $this->worker('post', '/logout'); $this->refreshConnection(); }
                    catch (\Illuminate\Http\Client\HttpClientException $e) { Notification::make()->danger()->title('تعذر الفصل، حاول مرة أخرى')->send(); }
                }),
        ];
    }

    public function download(int $parts)
    {
        try {
            $rows = $this->worker('get', '/contacts')['rows'] ?? [];
            $rows = collect($rows)->filter(fn ($row) => preg_match('/^[1-9][0-9]{6,14}$/', (string) ($row['phone'] ?? '')))
                ->unique('phone')->map(fn ($row) => ['phone' => (string) $row['phone']])->values()->all();
            if (! $rows) { Notification::make()->warning()->title('لسه مفيش أرقام هاتف مؤكدة')->body('الاسم والمعرّف المخفي مش أرقام. انتظر المزامنة؛ لو الحساب مربوط من قبل الإصلاح، افصله وأعد مسح QR لمزامنة تحويلات الأرقام.')->send(); return null; }
            $chunks = WorkFactoryExport::split($rows, $parts);
            $file = tempnam(storage_path('framework'), 'factory-');
            chmod($file, 0600);
            if ($parts === 1) WorkFactoryExport::workbook($rows, $file);
            else {
                $zip = new \ZipArchive;
                if ($zip->open($file, \ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('تعذر إنشاء الملفات.');
                foreach ($chunks as $i => $chunk) {
                    $part = tempnam(storage_path('framework'), 'factory-part-');
                    chmod($part, 0600);
                    try { WorkFactoryExport::workbook($chunk, $part); $zip->addFromString('team-'.($i + 1).'-'.count($chunk).'.xlsx', file_get_contents($part)); }
                    finally { @unlink($part); }
                }
                $zip->close();
            }
            return response()->streamDownload(function () use ($file) {
                try { readfile($file); } finally { @unlink($file); }
            }, 'work-factory-'.now()->format('Y-m-d-His').($parts === 1 ? '.xlsx' : '.zip'), [
                'Content-Type' => $parts === 1 ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/zip',
                'Cache-Control' => 'private, no-store',
            ]);
        } catch (\Illuminate\Http\Client\HttpClientException|\InvalidArgumentException|\RuntimeException $e) {
            if (isset($file)) @unlink($file);
            Notification::make()->danger()->title('تعذر استخراج الملف')->body($e instanceof \InvalidArgumentException ? $e->getMessage() : 'حدّث الحالة ثم حاول مرة أخرى.')->send();
            return null;
        }
    }
}
