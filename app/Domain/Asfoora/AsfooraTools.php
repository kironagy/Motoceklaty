<?php

namespace App\Domain\Asfoora;

use App\Filament\Resources\DeliveryResource;
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

    private const LIST_DEFAULT = 50;

    public const EXPORT_ROWS = 20000;

    /** @return list<array{name: string, description: string, parameters: array}> */
    public static function declarations(): array
    {
        $statuses = array_keys(DeliveryResource::STATUS_LABELS);
        $filters = [
            'from' => ['type' => 'string', 'description' => 'First day, YYYY-MM-DD (Cairo). Leave out for no lower bound.'],
            'to' => ['type' => 'string', 'description' => 'Last day included, YYYY-MM-DD (Cairo).'],
            'date_field' => ['type' => 'string', 'enum' => ['created_at', 'status_updated_at'], 'description' => 'created_at = when the request was uploaded (default); status_updated_at = when its status last changed.'],
            'statuses' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $statuses], 'description' => 'Only these status codes.'],
            'staff' => ['type' => 'string', 'description' => 'Part of the employee\'s name (the request\'s owner).'],
            'installment_type' => ['type' => 'string', 'description' => 'Part of the installment system name (أمان، حالا، مايلو ...).'],
            'search' => ['type' => 'string', 'description' => 'Part of the customer\'s name, phone or national id.'],
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
                'requests_with_history' => self::history($args),
                'make_report_files' => self::groups($args),
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
            ->when(! empty($args['statuses']), fn ($q) => $q->whereIn('r.status', (array) $args['statuses']))
            ->when(filled($args['staff'] ?? null), fn ($q) => $q->where('s.name', 'like', '%'.$args['staff'].'%'))
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
                'aman_reason' => $cut($aman ?: ($r->aman_check_text ?: null)),
                'staff_note' => $cut(trim(implode("\n", array_filter([$staffNote, $r->notes ? strip_tags((string) $r->notes) : null]))) ?: null),
                'uploaded_by' => $names[$real[0] ?? $collapsed[0] ?? $current] ?? null,
                // oldest first; read right to left: «مريم ← beso ← متوقف»
                'owners' => implode(' ← ', array_map(fn ($id) => $names[$id] ?? "#{$id}", $collapsed)),
                'status' => DeliveryResource::STATUS_LABELS[$r->status] ?? $r->status,
                'still_with_employee' => ! $parked,
            ];
        })->all();

        return ['total_matching' => $total, 'shown' => count($rows), 'requests' => $rows];
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

    public static function exportHistory(array $args): array
    {
        return self::history($args, self::EXPORT_ROWS)['requests'];
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
}
