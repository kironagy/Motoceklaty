<?php

namespace App\Domain\Asfoora;

use App\Filament\Resources\DeliveryResource;
use App\Models\InstallmentRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Owner 2026-11-26: the tools «عصفورة» answers with. All of them only read. The common questions
 * (how many requests, whose, which are stuck and why, what happened to request X) have their own
 * tools with the right joins; anything else goes through run_sql (ReadOnlyQuery).
 */
class AsfooraTools
{
    /** what the AI gets of a list (the Excel export runs it again in full, up to EXPORT_ROWS) */
    private const LIST_LIMIT = 200;

    private const LIST_DEFAULT = 30;

    public const EXPORT_ROWS = 20000;

    /** the chat being answered - its uploaded sheet is the one the sheet tools read */
    public static ?int $chatId = null;

    public static function uploadedFile(): ?array
    {
        if (! self::$chatId) {
            return null;
        }
        $meta = \App\Models\AsfooraMessage::where('asfoora_chat_id', self::$chatId)->where('role', 'user')
            ->where('meta', 'like', '%"file"%')->latest('id')->value('meta');
        $meta = is_string($meta) ? json_decode($meta, true) : $meta;

        return $meta['file'] ?? null;
    }

    /** @return list<array{name: string, description: string, parameters: array}> */
    public static function declarations(): array
    {
        $statuses = array_keys(DeliveryResource::STATUS_LABELS);
        $filters = [
            'from' => ['type' => 'string', 'description' => 'First day, YYYY-MM-DD (Cairo). Leave out for no lower bound.'],
            'to' => ['type' => 'string', 'description' => 'Last day included, YYYY-MM-DD (Cairo).'],
            'date_field' => ['type' => 'string', 'enum' => ['created_at', 'status_updated_at'], 'description' => 'created_at = when the request was uploaded (default); status_updated_at = when its status last changed.'],
            'statuses' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $statuses], 'description' => 'Only these status codes.'],
            'staff' => ['type' => 'string', 'description' => 'The request\'s owner: the employee\'s number (best, from the staff list), or part of their name or login email.'],
            'installment_type' => ['type' => 'string', 'description' => 'Part of the installment system name (أمان، حالا، مايلو ...).'],
            'search' => ['type' => 'string', 'description' => 'Part of the customer\'s name, phone or national id.'],
            'reason' => ['type' => 'string', 'description' => 'Words in the reason (Aman\'s words, the employee\'s note), e.g. «غير مقيم».'],
            'source' => ['type' => 'string', 'enum' => ['bot', 'staff'], 'description' => 'bot = made by the WhatsApp bot (request_type bot); staff = entered by an employee. Test requests (fake) are always left out.'],
            'offset' => ['type' => 'integer', 'description' => 'Skip this many (paging through more than 200).'],
        ];

        return [
            [
                'name' => 'requests_report',
                'description' => 'Counts installment requests (الطلبات) with filters, grouped by employee, status, installment system, day or machine. Use for "how many / whose / per employee" questions.',
                'parameters' => ['type' => 'object', 'properties' => $filters + [
                    'group_by' => ['type' => 'string', 'enum' => ['staff', 'status', 'installment_type', 'day', 'machine'], 'description' => 'Default staff.'],
                    'compare' => ['type' => 'boolean', 'description' => 'Also count the period of the same length right before from..to (needs both), with the change for each group.'],
                ]],
            ],
            [
                'name' => 'list_requests',
                'description' => 'Lists installment requests with filters: id, customer name and phones, status, employee, system, machine, reason (سبب) and notes, dates. Newest first, at most 200.',
                'parameters' => ['type' => 'object', 'properties' => $filters + [
                    'limit' => ['type' => 'integer', 'description' => 'At most 200, default 50. total_matching tells how many there are - the Excel export always has all of them.'],
                ]],
            ],
            [
                'name' => 'requests_with_history',
                'description' => 'Requests with the story of who held them: who uploaded it, every employee it moved between, who it was taken from (the last real employee before a parking account such as «متوقف»/«مرفوض»), who has it now, whether it is still with its employee, Aman\'s own words (aman_reason) and the employee\'s note (staff_note). Use it for stuck / rejected / approved request reports and for "who uploaded / who took it" questions. Same filters as list_requests, or ids.',
                'parameters' => ['type' => 'object', 'properties' => $filters + [
                    'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Only these request numbers.'],
                    'limit' => ['type' => 'integer', 'description' => 'At most 200, default 50. total_matching tells how many there are - the Excel export always has all of them.'],
                ]],
            ],
            [
                'name' => 'make_report_files',
                'description' => 'After YOU have read the requests (requests_with_history) and decided, sorts them into groups. Many requests: call it several times, a batch of up to 80 ids each - a group with the same title collects every batch.  - e.g. «الطلبات الميتة» and «طلبات ينفع نقاوح فيها» - each group becomes its own Excel file (with every column of requests_with_history plus your note on each request) and its own download button. Can colour rows. Nothing in the system is changed.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'groups' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'title' => ['type' => 'string', 'description' => 'The file\'s name, e.g. «المرفوض الميت - نوفمبر».'],
                        'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Every request number in this group.'],
                        'notes' => ['type' => 'array', 'description' => 'Why each request is in this group, a few words each.', 'items' => ['type' => 'object', 'properties' => [
                            'id' => ['type' => 'integer'], 'note' => ['type' => 'string'],
                        ], 'required' => ['id', 'note']]],
                    ], 'required' => ['title', 'ids']]],
                    'highlight' => ['type' => 'array', 'description' => 'Colour the rows whose column equals a value, e.g. status = «متوقف امان» in yellow.', 'items' => ['type' => 'object', 'properties' => [
                        'column' => ['type' => 'string', 'enum' => ['status', 'current_staff', 'taken_from', 'uploaded_by']],
                        'value' => ['type' => 'string'],
                        'color' => ['type' => 'string', 'enum' => ['yellow', 'red', 'green', 'orange', 'blue']],
                    ], 'required' => ['column', 'value', 'color']]],
                ], 'required' => ['groups']],
            ],
            [
                'name' => 'sort_requests',
                'description' => 'Every request lands in exactly one group, so besides the groups he asked for always add one for the rest (e.g. «باقي المرفوض - رفض عادي») - «محتاج مراجعة» is added for you, only for what is unclear. Sorts MANY requests (any number) into groups by reading each one\'s Aman reason and employee note - e.g. «الميتة» / «ينفع نقاوح فيها» / «محتاج مراجعة». Give the filters and each group with its rule; the requests are read in batches for you and every group becomes its own Excel file with a note on each request. Use it instead of reading the requests yourself when there are more than ~40.',
                'parameters' => ['type' => 'object', 'properties' => $filters + [
                    'groups' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'title' => ['type' => 'string', 'description' => 'The file\'s name, e.g. «المرفوض الميت - نوفمبر».'],
                        'rule' => ['type' => 'string', 'description' => 'Which requests belong here, in plain words.'],
                    ], 'required' => ['title', 'rule']]],
                    'highlight' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'column' => ['type' => 'string', 'enum' => ['status', 'current_staff', 'taken_from', 'uploaded_by']],
                        'value' => ['type' => 'string'],
                        'color' => ['type' => 'string', 'enum' => ['yellow', 'red', 'green', 'orange', 'blue']],
                    ], 'required' => ['column', 'value', 'color']]],
                ], 'required' => ['groups']],
            ],
            [
                'name' => 'read_sheet',
                'description' => 'Reads the Excel/CSV sheet the owner uploaded in this chat: its sheets, their headers and row counts, and rows (up to 100 from offset).',
                'parameters' => ['type' => 'object', 'properties' => [
                    'sheet' => ['type' => 'string', 'description' => 'Which sheet (default the first).'],
                    'offset' => ['type' => 'integer'], 'limit' => ['type' => 'integer'],
                ]],
            ],
            [
                'name' => 'aman_sheet_changes',
                'description' => 'For Aman\'s sheet the owner uploaded (customer, showroom, Aman request number, status, amount, comment): finds the showroom\'s requests and their status changes - Active = استلم المكنة, Rejected = رفض, Canceled = ملغي, توقيع = استلم المكنة, pending/on hold = متوقف امان, with Aman\'s comment as the reason. apply=true CHANGES them now (when he asks to update/change the statuses); without it only a preview with a «نفّذ» button (when he only asks to compare/check). Returns counts and a few examples.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'apply' => ['type' => 'boolean', 'description' => 'true = change the statuses now. He asked to change/update them.'],
                    'showroom' => ['type' => 'string', 'description' => 'Only rows of this showroom (default «موتوجيت»).'],
                    'sheets' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Only these sheets (e.g. one month). Default all.'],
                ]],
            ],
            [
                'name' => 'change_status',
                'description' => 'CHANGES the status of requests now, as if the owner did it from the request page: the customer gets the usual WhatsApp message of the new status, the log shows the owner. For "غيّر حالة الطلب ده لـ..." or a sheet of request numbers he wants set to a status (read it with read_sheet first). At most 500 per call.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Request numbers.'],
                    'status' => ['type' => 'string', 'enum' => $statuses],
                    'reason' => ['type' => 'string', 'description' => 'The reason / note (the limit for موافقة, what is missing for متوقف, why for رفض).'],
                    'reason_from' => ['type' => 'string', 'enum' => ['aman', 'staff'], 'description' => 'aman = the reason is Aman\'s reply (رد أمان); staff = the showroom\'s note (default).'],
                ], 'required' => ['ids', 'status']],
            ],
            [
                'name' => 'transfer_requests',
                'description' => 'MOVES requests to another employee now ("حوّل الطلب ده لفلان"، "حوّل طلبات فلان المتوقفة لعلان"): get the numbers first (list_requests / run_sql), then call with them. The log shows the owner moved them. At most 500 per call.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Request numbers.'],
                    'to_staff' => ['type' => 'integer', 'description' => 'The employee\'s number from the staff list.'],
                ], 'required' => ['ids', 'to_staff']],
            ],
            [
                'name' => 'add_deduction',
                'description' => 'ADDS a salary deduction (خصم) for an employee now ("نزّل خصم على فلان يوم / 200 جنيه"). Give days, or amount in pounds (turned into days of his salary).',
                'parameters' => ['type' => 'object', 'properties' => [
                    'staff' => ['type' => 'integer', 'description' => 'The employee\'s number from the staff list.'],
                    'days' => ['type' => 'number'],
                    'amount' => ['type' => 'number', 'description' => 'Pounds, when he says an amount instead of days.'],
                    'reason' => ['type' => 'string'],
                    'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, default today.'],
                ], 'required' => ['staff', 'reason']],
            ],
            [
                'name' => 'watch_start',
                'description' => 'Starts a background watch in this chat that keeps sending him news with a push to his phone until he stops it: new requests every hour ("ابعتلي الطلبات الجديدة كل ساعة") - it also says when there is nothing new - or any question repeated on a schedule.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'kind' => ['type' => 'string', 'enum' => ['new_requests', 'question'], 'description' => 'new_requests = the requests that came since the last look; question = your answer to his question each time.'],
                    'question' => ['type' => 'string', 'description' => 'For kind=question: what to answer each time.'],
                    'every_minutes' => ['type' => 'integer', 'description' => 'Default 60, at least 15.'],
                    'source' => ['type' => 'string', 'enum' => ['bot', 'staff'], 'description' => 'new_requests: only the bot\'s / only the employees\'.'],
                    'statuses' => ['type' => 'array', 'items' => ['type' => 'string']],
                ], 'required' => ['kind']],
            ],
            [
                'name' => 'watch_stop',
                'description' => 'Stops a watch ("خلاص وقف"، "بطّلي تبعتي"): by id, or every watch of this chat when no id.',
                'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
            ],
            [
                'name' => 'remember',
                'description' => 'Saves something the owner told you to keep in mind from now on ("افتكري إن ..."، "من هنا ورايح ..."): a rule, a preference, how he sorts requests. It is in your instructions in every later question. Only when he asks you to remember.',
                'parameters' => ['type' => 'object', 'properties' => ['content' => ['type' => 'string', 'description' => 'The rule in his words, short and complete.']], 'required' => ['content']],
            ],
            [
                'name' => 'forget',
                'description' => 'Deletes a saved memory by its number, when he says to forget it or it is replaced.',
                'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']],
            ],
            [
                'name' => 'request_details',
                'description' => 'Everything about one request by its number: its fields, the customer, the employee, and its log (who changed what and when).',
                'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']],
            ],
            [
                'name' => 'run_sql',
                'description' => 'Runs ONE read-only SQL SELECT (or WITH ... SELECT) on the system database and returns up to 300 rows. Use it for anything the other tools do not cover. Only the tables in your instructions exist. Use literal dates, never NOW()/CURDATE().',
                'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']],
            ],
        ];
    }

    public static function call(string $name, array $args): array
    {
        try {
            return match ($name) {
                'requests_report' => self::report($args),
                'list_requests' => self::list($args),
                'requests_with_history' => self::historySummary($args),
                'make_report_files' => self::groups($args),
                'read_sheet' => self::readSheet($args),
                'watch_start' => self::watchStart($args),
                'watch_stop' => self::watchStop($args),
                'sort_requests' => self::sortRequests($args),
                'aman_sheet_changes' => self::amanChanges($args),
                'change_status' => self::changeStatus($args),
                'transfer_requests' => self::transfer($args),
                'add_deduction' => self::deduction($args),
                // her own notebook - the only thing she writes, and never the system's data
                'remember' => ['saved' => \App\Models\AsfooraMemory::create(['content' => mb_substr(trim((string) ($args['content'] ?? '')), 0, 1000), 'created_by' => auth()->id()])->id],
                'forget' => ['deleted' => (bool) \App\Models\AsfooraMemory::whereKey((int) ($args['id'] ?? 0))->delete()],
                'request_details' => self::details((int) ($args['id'] ?? 0)),
                'run_sql' => ReadOnlyQuery::run((string) ($args['query'] ?? '')),
                default => ['error' => "Unknown tool {$name}"],
            };
        } catch (\InvalidArgumentException $e) {
            return ['error' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['error' => 'Query failed: '.mb_substr($e->getMessage(), 0, 400)];
        }
    }

    private static function base(array $args)
    {
        $field = ($args['date_field'] ?? '') === 'status_updated_at' ? 'r.status_updated_at' : 'r.created_at';

        return DB::table('installment_requests as r')
            ->leftJoin('staff as s', 's.id', '=', 'r.staff_id')
            ->whereNull('r.deleted_at')
            // test requests are never part of a report
            ->where(fn ($q) => $q->whereNull('r.request_type')->orWhere('r.request_type', '!=', 'fake'))
            ->when(($args['source'] ?? null) === 'bot', fn ($q) => $q->where('r.request_type', 'bot'))
            ->when(($args['source'] ?? null) === 'staff', fn ($q) => $q->where(fn ($w) => $w->whereNull('r.request_type')->orWhere('r.request_type', '!=', 'bot')))
            ->when(filled($args['from'] ?? null), fn ($q) => $q->where($field, '>=', Carbon::parse($args['from'])->startOfDay()))
            ->when(filled($args['to'] ?? null), fn ($q) => $q->where($field, '<=', Carbon::parse($args['to'])->endOfDay()))
            // live: she searched «غير مقيم» in the names - the words of the reason are searched here
            ->when(filled($args['reason'] ?? null), fn ($q) => $q->where(fn ($w) => $w->where('r.checks_report', 'like', '%'.$args['reason'].'%')
                ->orWhere('r.aman_check_text', 'like', '%'.$args['reason'].'%')->orWhere('r.notes', 'like', '%'.$args['reason'].'%')))
            // an exact moment (a watch: what came since its last look)
            ->when(filled($args['since'] ?? null), fn ($q) => $q->where('r.created_at', '>', Carbon::parse($args['since'])))
            ->when(! empty($args['statuses']), fn ($q) => $q->whereIn('r.status', (array) $args['statuses']))
            ->when(filled($args['staff'] ?? null), fn ($q) => self::staffFilter($q, (string) $args['staff']))
            ->when(filled($args['installment_type'] ?? null), fn ($q) => $q->where('r.installment_type', 'like', '%'.$args['installment_type'].'%'))
            ->when(filled($args['search'] ?? null), fn ($q) => $q->where(fn ($w) => $w
                ->where('r.applicant_name', 'like', '%'.$args['search'].'%')
                ->orWhere('r.applicant_phone', 'like', '%'.$args['search'].'%')
                ->orWhere('r.applicant_phone_2', 'like', '%'.$args['search'].'%')
                ->orWhere('r.applicant_national_id', 'like', '%'.$args['search'].'%')));
    }

    private static function report(array $args): array
    {
        if (! empty($args['compare']) && filled($args['from'] ?? null) && filled($args['to'] ?? null)) {
            $from = Carbon::parse($args['from'])->startOfDay();
            $to = Carbon::parse($args['to'])->startOfDay();
            $days = $from->diffInDays($to) + 1;
            $before = ['from' => $from->copy()->subDays($days)->toDateString(), 'to' => $from->copy()->subDay()->toDateString(), 'compare' => false];
            $now = self::report(['compare' => false] + $args);
            $prev = collect(self::report($before + $args)['groups'])->pluck('count', 'group');
            $now['groups'] = array_map(fn ($g) => $g + ['previous' => (int) ($prev[$g['group']] ?? 0), 'change' => $g['count'] - (int) ($prev[$g['group']] ?? 0)], $now['groups']);
            foreach ($prev as $group => $count) {
                if (! in_array($group, array_column($now['groups'], 'group'), true)) {
                    $now['groups'][] = ['group' => $group, 'count' => 0, 'previous' => (int) $count, 'change' => -(int) $count];
                }
            }

            return $now + ['previous_period' => $before['from'].' → '.$before['to'], 'previous_total' => (int) $prev->sum()];
        }

        $group = $args['group_by'] ?? 'staff';
        $query = self::base($args);
        $dayField = ($args['date_field'] ?? '') === 'status_updated_at' ? 'r.status_updated_at' : 'r.created_at';

        $label = match ($group) {
            'status' => 'r.status',
            'installment_type' => 'r.installment_type',
            'day' => "date({$dayField})",
            'machine' => "coalesce(m.name, '')",
            default => "coalesce(s.name, 'من غير موظف')",
        };

        if ($group === 'machine') {
            $query->leftJoin('machines as m', 'm.id', '=', 'r.machine_id');
        }

        $rows = $query->selectRaw("count(*) as total, {$label} as label")->groupByRaw($label)
            ->orderByDesc('total')->get()
            ->map(fn ($r) => [
                'group' => $group === 'status' ? (DeliveryResource::STATUS_LABELS[$r->label] ?? $r->label) : ($r->label ?: '—'),
                'count' => (int) $r->total,
            ])->all();

        return ['total' => array_sum(array_column($rows, 'count')), 'groups' => $rows];
    }

    /**
     * Owner 2026-11-26: his report of stuck requests says who uploaded each one, who it was taken
     * from and who has it now. Every change of staff_id is in activity_log; requests parked in the
     * status accounts (InstallmentRequest::PARKING_ACCOUNTS: «متوقف»، «متوقف امان»، «مرفوض»، «موافقه»)
     * were "taken from" the last real employee before the parking account.
     */
    private static function history(array $args, int $cap = self::LIST_LIMIT): array
    {
        $limit = max(1, min($cap, (int) ($cap === self::LIST_LIMIT ? ($args['limit'] ?? self::LIST_DEFAULT) : $cap)));
        $query = self::base($args)->when(! empty($args['ids']), fn ($q) => $q->whereIn('r.id', array_map('intval', (array) $args['ids'])));
        $total = (clone $query)->count();
        $requests = $query->orderByDesc('r.id')->offset(max(0, (int) ($args['offset'] ?? 0)))->limit($limit)->get([
            'r.id', 'r.applicant_name', 'r.applicant_phone', 'r.created_at', 'r.status', 'r.staff_id', 's.name as staff',
            'r.checks_report', 'r.aman_check_text', 'r.notes', 'r.status_updated_at',
        ]);

        $parking = \App\Models\Staff::whereIn('email', array_column(\App\Models\InstallmentRequest::PARKING_ACCOUNTS, 0))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $moves = DB::table('activity_log')->where('subject_type', 'like', '%InstallmentRequest')
            ->whereIn('subject_id', $requests->pluck('id'))->where('properties', 'like', '%staff_id%')
            ->orderBy('id')->get(['subject_id', 'event', 'properties'])->groupBy('subject_id');
        $names = DB::table('staff')->pluck('name', 'id');

        $rows = $requests->map(function ($r) use ($moves, $names, $parking, $cap) {
            $chain = [];
            foreach ($moves[$r->id] ?? [] as $move) {
                $p = json_decode((string) $move->properties, true) ?: [];
                if (! array_key_exists('staff_id', $p['attributes'] ?? [])) {
                    continue;
                }
                if ($chain === [] && isset($p['old']['staff_id'])) {
                    $chain[] = (int) $p['old']['staff_id'];
                }
                $chain[] = (int) $p['attributes']['staff_id'];
            }
            // the log starts after some requests were made: the current holder is the whole story
            if ($chain === [] && $r->staff_id) {
                $chain[] = (int) $r->staff_id;
            }
            $chain = array_values(array_filter($chain));
            $collapsed = [];
            foreach ($chain as $id) {
                if (end($collapsed) !== $id) {
                    $collapsed[] = $id;
                }
            }
            $real = array_values(array_filter($collapsed, fn ($id) => ! in_array($id, $parking, true)));
            $current = (int) $r->staff_id;
            $parked = in_array($current, $parking, true);

            $parts = is_string($r->checks_report) ? (json_decode($r->checks_report, true) ?? $r->checks_report) : $r->checks_report;
            // the AI reads 200 requests at a time: each reason is cut to its first 220 characters
            // (the Excel export, $cap above LIST_LIMIT, has them whole)
            $cut = fn ($t) => $t !== null && $cap <= self::LIST_LIMIT && mb_strlen((string) $t) > 220 ? mb_substr((string) $t, 0, 220).'…' : $t;
            $aman = is_array($parts) ? ($parts['aman'] ?? null) : null;
            $staffNote = is_array($parts) ? implode("\n", array_filter(\Illuminate\Support\Arr::except($parts, ['aman']), 'is_string')) : (string) $parts;

            // the order of his own report (and of the Excel columns)
            return [
                'id' => $r->id,
                'customer' => $r->applicant_name,
                'phone' => $r->applicant_phone,
                'created_at' => substr((string) $r->created_at, 0, 10),
                'taken_from' => $parked ? ($names[end($real) ?: 0] ?? null) : null,
                'current_staff' => $r->staff,
                'aman_reason' => $cut(self::tidy($aman ?: ($r->aman_check_text ?: null))),
                'staff_note' => $cut(self::tidy(implode("\n", array_filter([$staffNote, $r->notes ? strip_tags((string) $r->notes) : null])), true)),
                'uploaded_by' => $names[$real[0] ?? $collapsed[0] ?? $current] ?? null,
                // oldest first; read right to left: «مريم ← beso ← متوقف»
                'owners' => implode(' ← ', array_map(fn ($id) => $names[$id] ?? "#{$id}", $collapsed)),
                'status' => DeliveryResource::STATUS_LABELS[$r->status] ?? $r->status,
                'still_with_employee' => ! $parked,
            ];
        })->all();

        return ['total_matching' => $total, 'shown' => count($rows), 'requests' => $rows];
    }

    /**
     * Live 2026-11-26: sorting 485 rejected requests had the AI read them all in its own
     * conversation and the call broke. Here they are read 60 at a time, each batch a short separate
     * call that only labels (id → group + a few words why), and the groups become the files.
     */
    private static function sortRequests(array $args): array
    {
        $groups = array_values(array_filter(array_map(fn ($g) => ['title' => mb_substr(trim((string) ($g['title'] ?? '')), 0, 60), 'rule' => trim((string) ($g['rule'] ?? ''))], (array) ($args['groups'] ?? [])), fn ($g) => $g['title'] !== ''));
        if ($groups === []) {
            return ['error' => 'Give at least one group with a title and a rule.'];
        }
        // her own «… مراجعة» group is the one for the unclear (live: a second one was added next to it)
        $titles = array_column($groups, 'title');
        $review = collect($titles)->first(fn ($t) => str_contains($t, 'مراجع')) ?? 'محتاج مراجعة';
        if (! in_array($review, $titles, true)) {
            $groups[] = ['title' => $review, 'rule' => 'مش واضح من الكلام المكتوب يروح فين.'];
            $titles[] = $review;
        }

        $rows = self::history(['offset' => 0] + array_diff_key($args, ['groups' => 1, 'highlight' => 1]), self::EXPORT_ROWS)['requests'];
        $memories = \App\Models\AsfooraMemory::orderBy('id')->pluck('content')->implode("\n- ");
        $system = "إنت بتفرز طلبات تقسيط موتوسيكلات (جهة التمويل أمان) لصاحب المعرض. لكل طلب اختار مجموعة واحدة بالظبط من دول حسب قاعدتها:\n"
            .collect($groups)->map(fn ($g) => "- {$g['title']}: {$g['rule']}")->implode("\n")
            .($memories !== '' ? "\nقواعد صاحب المعرض الثابتة:\n- ".$memories : '')
            ."\nلكل سطر: k، والمجموعة باسمها بالظبط، وملاحظة قصيرة (أقل من 10 كلمات) بالعامية بتقول ليه. ما تسيبش ولا سطر. رد JSON بس.";
        $ai = app(\App\Agent\Providers\AiProvider::class);
        $labels = [];

        // Live 2026-11-26: 477 rejected requests in batches of 60 - every call ran past 30 s and was cut.
        // Most share their words («لديه طلب مرفوض من قبل»، «وظيفه مرفوضه»): each different reason is
        // sorted once (40 to a call) and its group goes to every request that has it.
        $keyOf = fn ($r) => preg_replace('/[^\p{L}\p{N}]+/u', '', mb_substr((string) ($r['aman_reason'] ?? ''), 0, 200).'|'.mb_substr((string) ($r['staff_note'] ?? ''), 0, 160));
        $reasons = [];
        foreach ($rows as $r) {
            $reasons[$keyOf($r)] ??= ['status' => $r['status'], 'aman' => mb_substr((string) ($r['aman_reason'] ?? 'مفيش رد مكتوب'), 0, 200), 'staff' => mb_substr((string) ($r['staff_note'] ?? '-'), 0, 160)];
        }
        $keys = array_keys($reasons);

        foreach (array_chunk($keys, 40) as $chunk) {
            $lines = implode("\n", array_map(fn ($k) => array_search($k, $keys, true).' | '.$reasons[$k]['status'].' | أمان: '.$reasons[$k]['aman'].' | الموظف: '.$reasons[$k]['staff'], $chunk));
            try {
                $response = $ai->chat(new \App\Agent\Providers\AiRequest(
                    purpose: 'asfoora', label: 'asfoora-sort', system: $system,
                    contents: [['role' => 'user', 'parts' => [['type' => 'text', 'text' => "كل سطر رقمه (k) وبعده سبب أمان وملاحظة الموظف:\n".$lines]]]],
                    toolMode: 'none', temperature: 0.0, maxOutputTokens: 4000, thinkingLevel: 'low', timeoutSeconds: 120,
                    responseSchema: ['type' => 'object', 'properties' => ['items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'k' => ['type' => 'integer'], 'group' => ['type' => 'string', 'enum' => $titles], 'note' => ['type' => 'string'],
                    ], 'required' => ['k', 'group', 'note']]]], 'required' => ['items']],
                ));
                $text = trim(preg_replace('/^```(?:json)?|```$/m', '', implode('', $response->textParts)));
                foreach ((array) (json_decode($text, true)['items'] ?? []) as $item) {
                    $key = $keys[(int) ($item['k'] ?? -1)] ?? null;
                    if ($key !== null && in_array($item['group'] ?? null, $titles, true)) {
                        $labels[$key] = ['group' => $item['group'], 'note' => (string) ($item['note'] ?? '')];
                    }
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $labels = collect($rows)->mapWithKeys(fn ($r) => [(int) $r['id'] => $labels[$keyOf($r)] ?? null])->filter()->all();

        // a request no batch labelled is never silently dropped
        $sorted = ['groups' => array_map(fn ($t) => ['title' => $t, 'ids' => [], 'notes' => []], $titles), 'highlight' => (array) ($args['highlight'] ?? [])];
        foreach ($rows as $r) {
            $label = $labels[(int) $r['id']] ?? ['group' => $review, 'note' => 'ما اتفرزش - راجعه بنفسك'];
            $at = array_search($label['group'], $titles, true);
            $sorted['groups'][$at]['ids'][] = (int) $r['id'];
            $sorted['groups'][$at]['notes'][] = ['id' => (int) $r['id'], 'note' => $label['note']];
        }
        $sorted['groups'] = array_values(array_filter($sorted['groups'], fn ($g) => $g['ids'] !== []));
        $result = self::groups($sorted);

        return [
            'total' => count($rows),
            'files' => $result['files'],
            'examples' => collect($sorted['groups'])->mapWithKeys(fn ($g) => [$g['title'] => array_slice($g['notes'], 0, 4)])->all(),
            '_tables' => $result['_tables'],
        ];
    }

    private static function watchStart(array $args): array
    {
        $chat = self::$chatId ? \App\Models\AsfooraChat::find(self::$chatId) : null;
        if (! $chat) {
            return ['error' => 'No chat.'];
        }
        $kind = ($args['kind'] ?? '') === 'question' ? 'question' : 'new_requests';
        if ($kind === 'question' && blank($args['question'] ?? null)) {
            return ['error' => 'A question watch needs the question.'];
        }
        $every = max(15, min(1440, (int) ($args['every_minutes'] ?? 60)));
        $watch = \App\Models\AsfooraWatch::create([
            'asfoora_chat_id' => $chat->id, 'staff_id' => $chat->staff_id, 'kind' => $kind,
            'question' => $kind === 'question' ? mb_substr((string) $args['question'], 0, 500) : null,
            'filters' => array_filter(['source' => $args['source'] ?? null, 'statuses' => $args['statuses'] ?? null]) ?: null,
            'every_minutes' => $every, 'last_checked_at' => now(), 'next_run_at' => now()->addMinutes($every),
        ]);

        return ['started' => $watch->id, 'what' => $watch->describe(), 'first_at' => $watch->next_run_at->format('h:i A'),
            'note' => 'Tell him it runs in the background with a push each time - nothing new is said too - until he says stop here.'];
    }

    private static function watchStop(array $args): array
    {
        $q = \App\Models\AsfooraWatch::where('asfoora_chat_id', self::$chatId)->where('is_active', true)
            ->when(! empty($args['id']), fn ($q) => $q->whereKey((int) $args['id']));
        $stopped = $q->get()->map(fn ($w) => $w->describe())->all();
        $q->update(['is_active' => false]);

        return ['stopped' => $stopped];
    }

    private static function readSheet(array $args): array
    {
        $file = self::uploadedFile();
        if (! $file) {
            return ['error' => 'No sheet was uploaded in this chat - ask him to attach it (📎).'];
        }
        $sheets = SheetFile::read($file['path']);
        $name = (string) ($args['sheet'] ?? '') ?: array_key_first($sheets);
        $sheet = $sheets[$name] ?? null;
        if (! $sheet) {
            return ['error' => 'No sheet named '.$name, 'sheets' => array_keys($sheets)];
        }
        $offset = max(0, (int) ($args['offset'] ?? 0));

        return [
            'file' => $file['name'],
            'sheets' => array_map(fn ($s) => count($s['rows']).' rows', $sheets),
            'sheet' => $name,
            'headers' => $sheet['headers'],
            'rows' => array_slice($sheet['rows'], $offset, max(1, min(100, (int) ($args['limit'] ?? 50)))),
        ];
    }

    private static function amanChanges(array $args): array
    {
        $file = self::uploadedFile();
        if (! $file || ! self::$chatId) {
            return ['error' => 'No sheet was uploaded in this chat - ask him to attach Aman\'s sheet (📎).'];
        }
        $p = AmanSheetSync::prepare(self::$chatId, $file['path'], (string) ($args['showroom'] ?? '') ?: 'موتوجيت', (array) ($args['sheets'] ?? []));
        // Owner 2026-11-27: «غيّر الحالات» - she changes them herself
        if (! empty($args['apply']) && $p['summary']['action_id']) {
            $applied = AmanSheetSync::apply(\App\Models\AsfooraAction::findOrFail($p['summary']['action_id']), self::actor()->id);
            $p['summary']['applied'] = ['changed' => $applied['done'], 'skipped_status_changed_meanwhile' => count($applied['skipped']), 'failed' => count($applied['failed'])];
        }
        $tables = [
            ['title' => 'التغييرات اللي هتتنفذ', 'rows' => array_map(fn ($c) => array_diff_key($c, ['from_code' => 1, 'to_code' => 1]), $p['changes']), 'group' => true],
            ['title' => 'محتاج مراجعة', 'rows' => $p['review'], 'group' => true],
            ['title' => 'مش لاقيه عندنا', 'rows' => $p['not_found'], 'group' => true],
        ];

        // the AI gets the counts and a few examples; the files and the «نفّذ» button carry the rest
        return $p['summary'] + [
            'examples_changes' => array_slice(array_map(fn ($c) => array_diff_key($c, ['from_code' => 1, 'to_code' => 1]), $p['changes']), 0, 12),
            'examples_review' => array_slice($p['review'], 0, 8),
            'examples_not_found' => array_slice($p['not_found'], 0, 5),
            '_tables' => array_values(array_filter($tables, fn ($t) => $t['rows'] !== [])),
            '_action' => $p['summary']['action_id'],
        ];
    }

    /** Owner 2026-11-27: actions run as him, from his chat only - never from a background watch. */
    private static function actor(): \App\Models\Staff
    {
        $actor = InstallmentRequest::currentActor();
        if (! $actor?->is_hitler) {
            throw new \InvalidArgumentException('Actions run only from the owner\'s chat, not from a watch or a report. Tell him to ask in the chat.');
        }

        return $actor;
    }

    /** @return list<int> */
    private static function ids(array $args): array
    {
        $ids = array_values(array_unique(array_map('intval', (array) ($args['ids'] ?? []))));
        if ($ids === [] || count($ids) > 500) {
            throw new \InvalidArgumentException($ids === [] ? 'No request numbers.' : 'At most 500 requests per call - split them.');
        }

        return $ids;
    }

    private static function changeStatus(array $args): array
    {
        self::actor();
        $status = (string) ($args['status'] ?? '');
        if (! isset(DeliveryResource::STATUS_LABELS[$status])) {
            throw new \InvalidArgumentException("Unknown status {$status}.");
        }
        $note = trim((string) ($args['reason'] ?? ''));
        $done = $same = $missing = $failed = [];
        foreach (self::ids($args) as $id) {
            $r = InstallmentRequest::find($id);
            if (! $r) {
                $missing[] = $id;

                continue;
            }
            if ($r->status === $status && $note === '') {
                $same[] = $id;

                continue;
            }
            try {
                if ($note !== '') {
                    $parts = $r->checks_report;
                    $parts = is_array($parts) ? $parts : (filled($parts) ? ['staff' => (string) $parts] : []);
                    $r->checks_report = array_merge($parts, [($args['reason_from'] ?? 'staff') === 'aman' ? 'aman' : 'staff' => $note]);
                }
                $r->status = $status;
                $r->save();
                $done[] = $id;
            } catch (\Throwable $e) {
                report($e);
                $failed[] = $id;
            }
        }

        return ['changed' => count($done), 'to' => DeliveryResource::STATUS_LABELS[$status], 'customers_messaged' => in_array($status, \App\Observers\InstallmentRequestObserver::NOTIFIABLE_STATUSES, true) ? count($done) : 0,
            'already_that_status' => $same, 'not_found' => $missing, 'failed' => $failed, 'changed_ids' => array_slice($done, 0, 50)];
    }

    private static function transfer(array $args): array
    {
        $actor = self::actor();
        $to = \App\Models\Staff::find((int) ($args['to_staff'] ?? 0));
        if (! $to) {
            throw new \InvalidArgumentException('No employee with that number - check the staff list.');
        }
        $done = $same = $missing = [];
        $from = [];
        foreach (self::ids($args) as $id) {
            $r = InstallmentRequest::find($id);
            if (! $r) {
                $missing[] = $id;

                continue;
            }
            if ((int) $r->staff_id === $to->id) {
                $same[] = $id;

                continue;
            }
            $from[$r->staff?->name ?? '—'] = ($from[$r->staff?->name ?? '—'] ?? 0) + 1;
            $r->forceFill(['staff_id' => $to->id, 'pending_staff_id' => null, 'transfer_requested_by' => null, 'transfer_requested_at' => null])->save();
            $done[] = $id;
        }

        return ['moved' => count($done), 'to' => $to->name, 'by' => $actor->name, 'moved_from' => $from, 'already_his' => $same, 'not_found' => $missing];
    }

    private static function deduction(array $args): array
    {
        self::actor();
        $staff = \App\Models\Staff::find((int) ($args['staff'] ?? 0));
        if (! $staff) {
            throw new \InvalidArgumentException('No employee with that number - check the staff list.');
        }
        $rate = \App\Models\EmployeeDeduction::dayRate($staff);
        $amount = isset($args['amount']) ? round((float) $args['amount'], 2) : null;
        $days = isset($args['days']) ? (float) $args['days'] : null;
        if ($days === null && $amount !== null) {
            if ($rate <= 0) {
                throw new \InvalidArgumentException("{$staff->name} has no monthly salary in the system, so an amount can't be turned into days - ask him to set the salary or give days.");
            }
            $days = round($amount / $rate, 2);
        }
        if (! $days || $days <= 0 || $days > 31) {
            throw new \InvalidArgumentException('Give days (up to 31) or an amount.');
        }
        $d = \App\Models\EmployeeDeduction::create([
            'staff_id' => $staff->id, 'days' => $days, 'reason' => mb_substr(trim((string) $args['reason']), 0, 500),
            'deduction_date' => filled($args['date'] ?? null) ? Carbon::parse($args['date'])->toDateString() : now()->toDateString(),
        ]);
        if ($amount !== null && ! isset($args['days'])) {
            $d->forceFill(['amount' => $amount])->save(); // exactly what he said
        }

        return ['deduction_id' => $d->id, 'employee' => $staff->name, 'days' => (float) $d->days, 'amount' => (float) $d->fresh()->amount, 'date' => $d->deduction_date->toDateString(), 'reason' => $d->reason];
    }

    /** The groups the AI sorted the requests into: counts back to it, the tables to the Excel export. */
    public static function groups(array $args): array
    {
        $out = [];
        $tables = [];
        foreach (array_slice((array) ($args['groups'] ?? []), 0, 6) as $group) {
            $ids = array_values(array_unique(array_map('intval', (array) ($group['ids'] ?? []))));
            $notes = collect((array) ($group['notes'] ?? []))->filter(fn ($n) => isset($n['id']))->mapWithKeys(fn ($n) => [(string) (int) $n['id'] => (string) ($n['note'] ?? '')])->all();
            $rows = $ids === [] ? [] : self::history(['ids' => $ids], self::EXPORT_ROWS)['requests'];
            $found = array_column($rows, 'id');
            $title = mb_substr(trim((string) ($group['title'] ?? 'مجموعة')) ?: 'مجموعة', 0, 60);

            $out[] = ['title' => $title, 'count' => count($rows), 'not_found' => array_values(array_diff($ids, $found))];
            $tables[] = ['title' => $title, 'rows' => self::withNotes($rows, $notes), 'notes' => $notes,
                'source' => ['tool' => 'requests_with_history', 'args' => ['ids' => $ids]], 'highlight' => (array) ($args['highlight'] ?? []), 'group' => true];
        }

        return ['files' => $out, '_tables' => $tables];
    }

    /** the AI's note on each request as the first column after the number */
    public static function withNotes(array $rows, array $notes): array
    {
        if ($notes === []) {
            return $rows;
        }

        return array_map(fn ($r) => ['id' => $r['id'], 'verdict' => $notes[(string) $r['id']] ?? null] + $r, $rows);
    }

    /**
     * Live 2026-11-26: a report of 479 stuck requests had the AI page through all of them (200 at a
     * time) - 40k tokens a round, and the provider dropped the call. It now gets the numbers over
     * every matching request (per status, holder, who it was taken from, Aman's commonest reasons)
     * and one page of rows; the Excel export has them all.
     */
    private static function historySummary(array $args): array
    {
        $all = self::history(['offset' => 0] + $args, self::EXPORT_ROWS)['requests'];
        $offset = max(0, (int) ($args['offset'] ?? 0));
        // one page is for examples - the counts and the file cover the rest
        $limit = max(1, min(50, (int) ($args['limit'] ?? self::LIST_DEFAULT)));
        $cut = fn ($t) => $t !== null && mb_strlen((string) $t) > 220 ? mb_substr((string) $t, 0, 220).'…' : $t;
        $page = array_map(fn ($r) => ['aman_reason' => $cut($r['aman_reason']), 'staff_note' => $cut($r['staff_note'])] + $r, array_slice($all, $offset, $limit));
        $top = fn (string $key, int $n) => collect($all)->map(fn ($r) => $r[$key] ? mb_substr(preg_replace('/\s+/u', ' ', (string) $r[$key]), 0, 70) : '(فاضي)')
            ->countBy()->sortDesc()->take($n)->all();

        return [
            'total_matching' => count($all),
            'summary' => [
                'by_status' => collect($all)->countBy('status')->sortDesc()->all(),
                'by_current_staff' => collect($all)->countBy('current_staff')->sortDesc()->all(),
                'by_taken_from' => collect($all)->whereNotNull('taken_from')->countBy('taken_from')->sortDesc()->all(),
                'still_with_their_employee' => collect($all)->where('still_with_employee', true)->count(),
                'by_uploaded_by' => collect($all)->countBy('uploaded_by')->sortDesc()->take(15)->all(),
                'top_aman_reasons' => $top('aman_reason', 8),
                'top_staff_notes' => $top('staff_note', 5),
            ],
            'shown' => count($page),
            'offset' => $offset,
            'requests' => $page,
            'note' => 'Counts above are over ALL matching requests. The Excel file has every one of them - do not page through them for a report.',
        ];
    }

    public static function exportHistory(array $args): array
    {
        return self::history($args, self::EXPORT_ROWS)['requests'];
    }

    /**
     * Owner 2026-11-26: "الشيت ملعبك ومش مفهوم". Aman's words came twice («وظيفة مرفوضه» and
     * «رد أمان: وظيفة مرفوضه»), and the employee's note was buried under the system's own lines
     * («اتقدم من رابط الفورم…»، «العميل عدّل من…»). Each line once, without those.
     */
    public static function tidy(?string $text, bool $staffNote = false): ?string
    {
        $seen = [];
        $lines = [];
        foreach (preg_split('/\R/u', (string) $text) as $line) {
            $line = trim(preg_replace('/\s+/u', ' ', $line));
            if ($staffNote && preg_match('/^(اتقدم من رابط الفورم|العميل عد[ّ]?ل|العميل رج[ّ]?ع)/u', $line)) {
                continue;
            }
            $line = trim(preg_replace(['/^(رد أمان|رد امان|أمان قال|امان قال)\s*:\s*/u', '/\s*—\s*الموظف كتب رقم طلب أمان:\s*\d+/u'], '', $line));
            $key = preg_replace('/[^\p{L}\p{N}]+/u', '', $line);
            if ($line === '' || $line === '-' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $lines[] = $line;
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    /** The whole list for the Excel export. */
    public static function exportList(array $args): array
    {
        return self::list(['limit' => self::EXPORT_ROWS] + $args, self::EXPORT_ROWS)['requests'];
    }

    private static function list(array $args, int $cap = self::LIST_LIMIT): array
    {
        $limit = max(1, min($cap, (int) ($cap === self::LIST_LIMIT ? ($args['limit'] ?? self::LIST_DEFAULT) : $cap)));
        $query = self::base($args)->leftJoin('machines as m', 'm.id', '=', 'r.machine_id');
        $total = (clone $query)->count();

        $rows = $query->orderByDesc('r.id')->offset(max(0, (int) ($args['offset'] ?? 0)))->limit($limit)->get([
            'r.id', 'r.applicant_name', 'r.applicant_phone', 'r.applicant_phone_2', 'r.status', 's.name as staff',
            'r.installment_type', 'm.name as machine', 'r.months', 'r.checks_report', 'r.notes', 'r.created_at', 'r.status_updated_at',
        ])->map(fn ($r) => [
            'id' => $r->id,
            'customer' => $r->applicant_name,
            'phone' => $r->applicant_phone,
            'phone_2' => $r->applicant_phone_2,
            'status' => DeliveryResource::STATUS_LABELS[$r->status] ?? $r->status,
            'staff' => $r->staff,
            'system' => $r->installment_type,
            'machine' => $r->machine,
            'months' => $r->months,
            'reason' => DeliveryResource::reasonText($r->checks_report),
            'notes' => $r->notes ? mb_substr(strip_tags((string) $r->notes), 0, 200) : null,
            'created_at' => $r->created_at,
            'status_updated_at' => $r->status_updated_at,
        ])->all();

        return ['total_matching' => $total, 'shown' => count($rows), 'requests' => $rows];
    }

    private static function details(int $id): array
    {
        $r = DB::table('installment_requests as r')
            ->leftJoin('staff as s', 's.id', '=', 'r.staff_id')
            ->leftJoin('machines as m', 'm.id', '=', 'r.machine_id')
            ->where('r.id', $id)
            ->first(['r.*', 's.name as staff_name', 'm.name as machine_name']);

        if (! $r) {
            return ['error' => "No request #{$id}."];
        }

        // images and files are paths - not useful in a chat
        $fields = collect((array) $r)->reject(fn ($v, $k) => $v === null || $v === '' || preg_match('/image|_file$|_images$|video/', $k))->all();
        $fields['status'] = DeliveryResource::STATUS_LABELS[$r->status] ?? $r->status;
        $fields['checks_report'] = DeliveryResource::reasonText($r->checks_report);

        $log = DB::table('activity_log as a')
            ->leftJoin('staff as c', fn ($j) => $j->on('c.id', '=', 'a.causer_id')->where('a.causer_type', 'like', '%Staff'))
            ->where('a.subject_type', 'like', '%InstallmentRequest')->where('a.subject_id', $id)
            ->orderByDesc('a.id')->limit(40)
            ->get(['a.created_at', 'a.description', 'a.event', 'a.properties', 'c.name as by'])
            ->map(fn ($a) => [
                'at' => $a->created_at,
                'by' => $a->by ?? 'غير معروف',
                'what' => $a->description ?: $a->event,
                'details' => mb_substr((string) $a->properties, 0, 400),
            ])->all();

        return ['request' => $fields, 'log' => $log];
    }

    /** Owner 2026-11-26: «محمود» is hefny (mahmoud@...) - a number, a name or a login email all find him. */
    private static function staffFilter($query, string $staff): void
    {
        $staff = trim(ltrim(trim($staff), '#'));
        if (ctype_digit($staff)) {
            $query->where('r.staff_id', (int) $staff);

            return;
        }
        $query->where(fn ($w) => $w->where('s.name', 'like', '%'.$staff.'%')->orWhere('s.email', 'like', '%'.$staff.'%'));
    }
}
