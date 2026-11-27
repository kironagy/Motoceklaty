<?php

namespace App\Domain\Asfoora;

use App\Filament\Resources\DeliveryResource;
use App\Models\AsfooraAction;
use App\Models\InstallmentRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Owner 2026-11-26: "اديله شيت أمان وأقوله غيّر حالة طلبات موتو جيت": Aman's sheet (a row per
 * application: customer, showroom, Aman's request number, status, amount, comment) is matched to
 * the showroom's requests and turned into status changes - approved with its limit written in the
 * reason, rejected / cancelled / stuck (متوقف امان) with Aman's comment, «توقيع» = استلم المكنة.
 * prepare() only proposes; apply() runs when the owner presses «نفّذ», as if he changed each one.
 */
class AmanSheetSync
{
    /** sheet status → ours */
    /** statuses a request has before Aman looks at it */
    private const BEFORE_AMAN = ['new', 'new_request', 'transferred', 'edited'];

    public static function mapStatus(?string $status): ?string
    {
        $s = mb_strtolower(trim((string) $status));

        return match (true) {
            $s === '' => null,
            // Owner 2026-11-27: Aman's Active = financed = «استلم المكنة» here
            (bool) preg_match('/^active$/u', $s) => 'delivered',
            (bool) preg_match('/^approved?$|موافق/u', $s) => 'approved',
            (bool) preg_match('/reject|رفض|مرفوض/u', $s) => 'rejected',
            (bool) preg_match('/cancel|ملغ|الغاء|إلغاء/u', $s) => 'canceled',
            (bool) preg_match('/توقيع|sign/u', $s) => 'delivered',
            // live 2026-11-27 (his monthly sheet): what Aman wants from him = متوقف امان; Aman still checking = تحت الاستعلام
            (bool) preg_match('/pend|hold|incomplete|missing|متوقف|معلق|استيفاء|مطلوب|income proof|customer id|commercial register|اوراق|أوراق/u', $s) => 'paused_aman',
            (bool) preg_match('/استعلام\s*عمل/u', $s) => 'work_check',
            (bool) preg_match('/استعلام|inquir|investigat|review|مراجع|processing|متابع|hits|مكالم|call/u', $s) => 'pending',
            default => null,
        };
    }

    /** @return array{summary: array, changes: list<array>, review: list<array>, not_found: list<array>} */
    public static function prepare(int $chatId, string $path, string $showroom = 'موتوجيت', array $onlySheets = []): array
    {
        $want = str_replace(' ', '', SheetFile::normalizeName($showroom));
        [$byRef, $byName, $requests] = self::index();
        $picked = [];   // request id => the sheet row that decides it (the newest)
        $review = [];
        $notFound = [];
        $rowsRead = 0;
        $otherShowrooms = 0;

        foreach (SheetFile::read($path) as $sheetName => $sheet) {
            if ($onlySheets !== [] && ! in_array($sheetName, $onlySheets, true)) {
                continue;
            }
            $col = self::columns($sheet['headers']);
            // a dashboard / lists sheet: no request rows on it
            if (! $col['status'] || (! $col['name'] && ! $col['ref'])) {
                continue;
            }
            foreach ($sheet['rows'] as $row) {
                $rowsRead++;
                $room = str_replace(' ', '', SheetFile::normalizeName((string) ($row[$col['showroom']] ?? '')));
                if ($col['showroom'] && ! str_contains($room, $want)) {
                    $otherShowrooms++;

                    continue;
                }
                $line = [
                    'sheet' => $sheetName,
                    'sheet_customer' => trim((string) ($row[$col['name']] ?? '')),
                    'aman_ref' => preg_replace('/\D/', '', (string) ($row[$col['ref']] ?? '')),
                    'aman_status' => trim((string) ($row[$col['status']] ?? '')),
                    'amount' => $row[$col['amount']] ?? null,
                    'comment' => trim((string) ($row[$col['comment']] ?? '')),
                    'date' => self::date($row[$col['date']] ?? null),
                ];
                $to = self::mapStatus($line['aman_status']);
                // Owner 2026-11-27: a row with only Aman's request number = his request went in to Aman = تحت الاستعلام
                if ($to === null && $line['aman_status'] === '' && $line['aman_ref'] !== '') {
                    $to = 'pending';
                }
                if ($to === null) {
                    $review[] = $line + ['why' => 'حالة أمان مش معروفة: «'.$line['aman_status'].'»'];

                    continue;
                }
                [$id, $how, $why] = self::match($line, $byRef, $byName, $requests);
                if ($id === null) {
                    $why ? $review[] = $line + ['why' => $why] : $notFound[] = $line;

                    continue;
                }
                // the newest row of a customer decides (he may have applied twice)
                if (! isset($picked[$id]) || (string) $line['date'] >= (string) $picked[$id]['date']) {
                    $picked[$id] = $line + ['to' => $to, 'match' => $how];
                }
            }
        }

        $changes = [];
        $same = 0;
        foreach ($picked as $id => $line) {
            $r = $requests[$id];
            if ($r->status === $line['to']
                // went in to Aman: only a request not yet past that moves (a decision already taken stays)
                || ($line['aman_status'] === '' && ! in_array($r->status, self::BEFORE_AMAN, true))) {
                $same++;

                continue;
            }
            if ($r->status === 'delivered') {
                $review[] = $line + ['why' => 'الطلب «استلم المكنة» عندنا وأمان بيقول «'.$line['aman_status'].'»', 'id' => $id];

                continue;
            }
            $changes[] = [
                'id' => $id,
                'customer' => $r->applicant_name,
                'staff' => $r->staff,
                'from' => DeliveryResource::STATUS_LABELS[$r->status] ?? $r->status,
                'to' => DeliveryResource::STATUS_LABELS[$line['to']] ?? $line['to'],
                'note' => self::note($line['to'], $line),
                'match' => $line['match'],
                'aman_ref' => $line['aman_ref'],
                'aman_status' => $line['aman_status'],
                'sheet_date' => $line['date'],
                'from_code' => $r->status,
                'to_code' => $line['to'],
            ];
        }

        usort($changes, fn ($a, $b) => [$a['to_code'], $b['id']] <=> [$b['to_code'], $a['id']]);
        $byTarget = collect($changes)->countBy('to')->all();
        $action = AsfooraAction::create(['asfoora_chat_id' => $chatId, 'kind' => 'aman_statuses', 'payload' => ['changes' => $changes]]);

        return [
            'summary' => [
                'action_id' => $action->id,
                'rows_read' => $rowsRead,
                'other_showrooms' => $otherShowrooms,
                'matched' => count($picked),
                'to_change' => count($changes),
                'by_new_status' => $byTarget,
                'already_same' => $same,
                'need_review' => count($review),
                'not_found' => count($notFound),
                'customers_messaged_on_apply' => count(array_filter($changes, fn ($c) => in_array($c['to_code'], \App\Observers\InstallmentRequestObserver::NOTIFIABLE_STATUSES, true))),
            ],
            'changes' => $changes,
            'review' => $review,
            'not_found' => $notFound,
        ];
    }

    /**
     * The owner pressed «نفّذ»: each request is saved like a staff member's edit - the log names him,
     * the customer gets the usual WhatsApp message, a rejected one goes to «مرفوض». A request whose
     * status changed since the preview is left alone.
     */
    public static function apply(AsfooraAction $action, int $staffId): array
    {
        $done = 0;
        $skipped = [];
        $failed = [];
        foreach ((array) ($action->payload['changes'] ?? []) as $c) {
            try {
                $r = InstallmentRequest::find($c['id']);
                if (! $r || $r->status !== $c['from_code']) {
                    $skipped[] = $c['id'];

                    continue;
                }
                $parts = $r->checks_report;
                $parts = is_array($parts) ? $parts : (filled($parts) ? ['staff' => (string) $parts] : []);
                $r->checks_report = array_merge($parts, ['aman' => $c['note']]);
                $r->status = $c['to_code'];
                $r->save();
                $done++;
            } catch (\Throwable $e) {
                report($e);
                $failed[] = $c['id'];
            }
        }

        $result = ['done' => $done, 'skipped' => $skipped, 'failed' => $failed];
        $action->update(['status' => 'applied', 'applied_by' => $staffId, 'applied_at' => now(), 'result' => $result]);

        return $result;
    }

    private static function note(string $to, array $line): string
    {
        $amount = is_numeric(str_replace(',', '', (string) $line['amount'])) ? number_format((float) str_replace(',', '', (string) $line['amount'])) : null;

        return match ($to) {
            'approved' => 'موافقة أمان'.($amount ? ' بحد '.$amount.' جنيه' : '').($line['comment'] !== '' ? ' — '.$line['comment'] : ''),
            'delivered' => (mb_strtolower(trim($line['aman_status'])) === 'active' ? 'اتنفّذ عند أمان (Active)' : 'توقيع عند أمان').($amount ? ' بمبلغ '.$amount.' جنيه' : ''),
            // a long Aman status is itself what he must send («Income proof / بيان دخل ...»)
            'pending' => $line['aman_status'] === '' ? 'الطلب دخل أمان ولسه مفيش رد'.($line['comment'] !== '' ? ' — '.$line['comment'] : '') : ($line['comment'] !== '' ? $line['comment'] : 'أمان: '.$line['aman_status']),
            default => implode(' — ', array_filter([
                mb_strlen($line['aman_status']) > 20 || str_contains($line['aman_status'], '/') ? $line['aman_status'] : null,
                $line['comment'] !== '' ? $line['comment'] : null,
            ])) ?: 'أمان: '.$line['aman_status'],
        } .($line['aman_ref'] !== '' ? ' (رقم طلب أمان: '.$line['aman_ref'].')' : '');
    }

    /** @return array{0: ?int, 1: ?string, 2: ?string} [request id, how it matched, why it needs review] */
    private static function match(array $line, array $byRef, array $byName, array $requests): array
    {
        if ($line['aman_ref'] !== '' && isset($byRef[$line['aman_ref']])) {
            return [$byRef[$line['aman_ref']], 'رقم طلب أمان', null];
        }

        $name = SheetFile::normalizeName($line['sheet_customer']);
        if ($name === '') {
            return [null, null, null];
        }
        $date = $line['date'] ? Carbon::parse($line['date']) : null;
        $days = fn ($id) => $date ? abs($date->diffInDays(Carbon::parse($requests[$id]->created_at), false)) : 0;

        $full = $byName[$name] ?? [];
        if (count($full) === 1) {
            return [$full[0], 'الاسم كامل', null];
        }
        if (count($full) > 1) {
            $sorted = collect($full)->sortBy($days)->values();
            if ($date && $days($sorted[0]) <= 15 && $days($sorted[1]) - $days($sorted[0]) > 3) {
                return [$sorted[0], 'الاسم كامل + أقرب تاريخ', null];
            }

            return [null, null, 'أكتر من طلب بنفس الاسم: '.implode(' و', array_map(fn ($id) => "#{$id}", $full))];
        }

        // the sheet may spell the fourth name differently - the first three and a close date
        $three = implode(' ', array_slice(explode(' ', $name), 0, 3));
        if ($date && substr_count($three, ' ') === 2) {
            $near = [];
            foreach ($byName as $n => $ids) {
                if (str_starts_with($n.' ', $three.' ')) {
                    foreach ($ids as $id) {
                        if ($days($id) <= 10) {
                            $near[] = $id;
                        }
                    }
                }
            }
            if (count($near) === 1) {
                return [$near[0], 'أول 3 أسماء + التاريخ', null];
            }
            if (count($near) > 1) {
                return [null, null, 'أكتر من طلب قريب من الاسم: '.implode(' و', array_map(fn ($id) => "#{$id}", $near))];
            }
        }

        return [null, null, null];
    }

    /** Aman's request numbers we know (the extension's uploads, and written in the reason) and every name */
    private static function index(): array
    {
        $requests = DB::table('installment_requests as r')->leftJoin('staff as s', 's.id', '=', 'r.staff_id')
            ->whereNull('r.deleted_at')->where(fn ($q) => $q->whereNull('r.request_type')->orWhere('r.request_type', '!=', 'fake'))
            ->get(['r.id', 'r.applicant_name', 'r.status', 'r.created_at', 'r.checks_report', 'r.aman_check_text', 'r.notes', 's.name as staff'])
            ->keyBy('id')->all();

        $byRef = DB::table('aman_uploads')->whereNotNull('aman_ref')->pluck('installment_request_id', 'aman_ref')
            ->mapWithKeys(fn ($id, $ref) => [preg_replace('/\D/', '', (string) $ref) => (int) $id])->all();
        $byName = [];
        foreach ($requests as $r) {
            if (preg_match_all('/\b(2\d{10})\b/', $r->checks_report.' '.$r->aman_check_text.' '.$r->notes, $m)) {
                foreach ($m[1] as $ref) {
                    $byRef[$ref] ??= (int) $r->id;
                }
            }
            $byName[SheetFile::normalizeName($r->applicant_name)][] = (int) $r->id;
        }
        unset($byName['']);

        return [$byRef, $byName, $requests];
    }

    /** the header of each column, whatever its spelling */
    private static function columns(array $headers): array
    {
        $find = function (array $words) use ($headers) {
            foreach ($headers as $h) {
                $n = SheetFile::normalizeName($h);
                foreach ($words as $w) {
                    if (str_contains($n, $w)) {
                        return $h;
                    }
                }
            }

            return null;
        };

        return [
            'name' => $find(['اسم العميل', 'العميل', 'customer']),
            'showroom' => $find(['المعرض', 'showroom', 'dealer']),
            'ref' => $find(['رقم الطلب', 'request']),
            'status' => $find(['حاله الطلب', 'الحاله', 'status']),
            'amount' => $find(['المبلغ', 'amount', 'limit']),
            'comment' => $find(['كومنت', 'comment', 'ملاحظ', 'السبب']),
            'date' => $find(['تاريخ', 'date']),
        ];
    }

    private static function date(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = trim((string) $v);
        try {
            return preg_match('#^\d{1,2}/\d{1,2}/\d{4}$#', $s) ? Carbon::createFromFormat('m/d/Y', $s)->toDateString() : Carbon::parse($s)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
