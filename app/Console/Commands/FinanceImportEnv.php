<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Brings the finance / showroom data of env.motocyklaty.store into this dashboard.
 *
 * Reads a copy of the env database (connection `env_source`) and writes here. Every imported row is
 * recorded in env_import_map, so running it again updates the same rows instead of duplicating them
 * (rows deleted in env are not deleted here). Env users become staff: an approved pair first, then the
 * same email, otherwise a new staff row with the env password hash. A merged person keeps his
 * dashboard name, email and password; only the showroom fields come from env.
 *
 * Ends with a check: row counts and money totals of every table, env against here.
 */
class FinanceImportEnv extends Command
{
    protected $signature = 'finance:import-env
        {--source=env_source : database connection holding the env copy}
        {--dry-run : run everything, then roll back}';

    protected $description = 'Import the env.motocyklaty.store finance system (staff, stock, letters, treasury, HR) into this dashboard';

    /**
     * env email => staff email, for people who use another email in each system.
     * Approved by the owner on 2026-10-16. "mohamed@gmail.com" is محمد الحاوي in env, while that
     * email belongs to Mohamed Ahmed here, so the pair must win over the email match.
     */
    private const PERSON_PAIRS = [
        'elhawy@admin.com' => 'elhawy@gmail.com',
        'joo@gmail.com' => 'yossef@gmail.com',
        'rodina@gmail.com' => 'rody@gmail.com',
        'marim@gmail.com' => 'mariam@gmail.com',
        'walaa@gmail.com' => 'walaadewedar14322@gmail.com',
        'kareem@gmail.com' => 'karem@gmail.com',
        'mohamed@gmail.com' => 'kata@gmail.com',
    ];

    private Connection $src;

    /** @var array<string, array<int, int>> */
    private array $map = [];

    /** @var array<string, int> */
    private array $staffReport = ['paired' => 0, 'email' => 0, 'created' => 0];

    public function handle(): int
    {
        $this->src = DB::connection($this->option('source'));
        $this->loadMap();

        DB::beginTransaction();

        try {
            $this->importWarehouses();
            $this->importStaff();
            $this->importProducts();

            $this->importRows('inventories', fn ($r) => [
                'warehouse_id' => $this->req('warehouses', $r->warehouse_id),
                'product_id' => $this->req('products', $r->product_id),
            ]);

            $this->importRows('inventory_items', fn ($r) => [
                'inventory_id' => $this->req('inventories', $r->inventory_id),
            ] + $this->copy($r, [
                'chassis_image', 'engine_image', 'color', 'customer_name', 'national_id', 'phone', 'address',
                'customer_id_image', 'cash', 'limit', 'admin_fees', 'paid', 'remaining', 'chassis_number', 'engine_number',
            ]));

            $this->importRows('letters', fn ($r) => [
                'inventory_id' => $this->opt('inventories', $r->inventory_id),
                'warehouse_id' => $this->opt('warehouses', $r->warehouse_id),
                // Usually gone: env deletes the stock item when it becomes a letter.
                'inventory_item_id' => $this->opt('inventory_items', $r->inventory_item_id),
            ] + $this->copy($r, [
                'chassis_image', 'engine_image', 'color', 'customer_name', 'national_id', 'phone', 'address',
                'customer_id_image', 'cash', 'limit', 'admin_fees', 'paid', 'remaining', 'is_returned', 'returned_at',
                'chassis_number', 'engine_number', 'status',
            ]));

            $this->importRows('treasury_expenses', fn ($r) => [
                'staff_id' => $this->req('users', $r->user_id),
                'warehouse_id' => $this->opt('warehouses', $r->warehouse_id),
            ] + $this->copy($r, ['total_amount', 'expense_date']));

            $this->importRows('treasury_expense_items', fn ($r) => [
                'created_by' => $this->opt('users', $r->created_by),
                'treasury_expense_id' => $this->req('treasury_expenses', $r->treasury_expense_id),
            ] + $this->copy($r, ['expense_item', 'amount']));

            $this->importRows('targets', fn ($r) => [
                'warehouse_id' => $this->req('warehouses', $r->warehouse_id),
            ] + $this->copy($r, ['target_count', 'from_date', 'to_date', 'is_active']));

            $this->importRows('attendance_deduction_rules', fn ($r) => [
                'staff_id' => $this->req('users', $r->user_id),
            ] + $this->copy($r, ['from_minute', 'to_minute', 'deduction_days']));

            $this->importRows('attendances', fn ($r) => [
                'staff_id' => $this->req('users', $r->user_id),
            ] + $this->copy($r, [
                'attendance_date', 'check_in_at', 'check_out_at', 'check_in_latitude', 'check_in_longitude',
                'check_out_latitude', 'check_out_longitude', 'late_minutes', 'deduction_days', 'deduction_amount',
                'status', 'notes',
            ]));

            foreach (['employee_advance_requests', 'employee_vacation_requests'] as $table) {
                $fields = $table === 'employee_advance_requests'
                    ? ['amount', 'request_date', 'reason', 'status', 'reviewed_at', 'admin_notes']
                    : ['from_date', 'to_date', 'vacation_type', 'reason', 'status', 'reviewed_at', 'admin_notes'];

                $this->importRows($table, fn ($r) => [
                    'staff_id' => $this->req('users', $r->user_id),
                    'reviewed_by' => $this->opt('users', $r->reviewed_by),
                ] + $this->copy($r, $fields));
            }

            $this->importRows('employee_bonuses', fn ($r) => [
                'staff_id' => $this->req('users', $r->user_id),
                'created_by' => $this->opt('users', $r->created_by),
            ] + $this->copy($r, ['amount', 'bonus_date', 'reason']));

            $this->importActivityLog();

            $ok = $this->verify();

            if ($this->option('dry-run')) {
                DB::rollBack();
                $this->warn('Dry run: everything rolled back.');
            } else {
                DB::commit();
            }

            return $ok ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function importWarehouses(): void
    {
        $branches = DB::table('branches')->get(['id', 'name']);

        $this->importRows('warehouses', function ($r) use ($branches) {
            $row = ['name' => trim($r->name)];

            // Only on first import: after that the dashboard owns the link.
            if (! $this->mapped('warehouses', $r->id)) {
                $row['branch_id'] = $this->matchBranch($r->name, $branches);
            }

            return $row;
        });
    }

    /** "موتـــوجيت جسـر السويس" -> branch "فرع جسر السويس"; expense centres stay unlinked. */
    private function matchBranch(string $warehouse, $branches): ?int
    {
        $name = $this->norm($warehouse);
        $hits = $branches->filter(function ($b) use ($name) {
            $core = $this->norm(preg_replace('/^\s*فرع\s*/u', '', $b->name));

            return $core !== '' && str_contains($name, $core);
        });

        return $hits->count() === 1 ? (int) $hits->first()->id : null;
    }

    private function importStaff(): void
    {
        $users = $this->src->table('users')->orderBy('id')->get();
        $taken = [];

        foreach ($users as $u) {
            $email = Str::lower(trim($u->email));
            $staffId = $this->mapped('users', $u->id);
            $how = 'mapped';

            if (! $staffId && isset(self::PERSON_PAIRS[$email])) {
                $staffId = DB::table('staff')->whereRaw('LOWER(email) = ?', [self::PERSON_PAIRS[$email]])->value('id');
                $how = 'paired';
            }

            if (! $staffId) {
                $staffId = DB::table('staff')->whereRaw('LOWER(TRIM(email)) = ?', [$email])->value('id');
                $how = 'email';
            }

            if (isset($taken[$staffId])) {
                throw new RuntimeException("env users {$taken[$staffId]} and {$u->id} both match staff {$staffId}");
            }

            $finance = [
                'warehouse_id' => $this->opt('warehouses', $u->warehouse_id),
                'is_showroom_employee' => (bool) $u->is_showroom_employee,
                'monthly_salary' => $u->monthly_salary,
                'attendance_time' => $u->attendance_time,
                'departure_time' => $u->departure_time,
                'updated_at' => now(),
            ];

            if ($staffId) {
                DB::table('staff')->where('id', $staffId)->update($finance);
                if ($how !== 'mapped') {
                    $this->staffReport[$how]++;
                }
            } else {
                // Same bcrypt hash: he logs in with his env password. Referral code like StaffResource makes it.
                $staffId = DB::table('staff')->insertGetId($finance + [
                    'name' => trim($u->name),
                    'email' => trim($u->email),
                    'password' => $u->password,
                    'referral_code' => $this->referralCode($u->name),
                    'created_at' => $u->created_at ?? now(),
                ]);
                $this->staffReport['created']++;
            }

            $taken[$staffId] = $u->id;
            $this->remember('users', $u->id, $staffId);
        }
    }

    private function referralCode(string $name): string
    {
        $base = 'em-' . (Str::slug($name) ?: 'staff');
        $code = $base;

        for ($i = 1; DB::table('staff')->where('referral_code', $code)->exists(); $i++) {
            $code = "{$base}-{$i}";
        }

        return $code;
    }

    private function importProducts(): void
    {
        $machines = DB::table('machines')->get(['id', 'name'])
            ->groupBy(fn ($m) => $this->norm($m->name));

        $this->importRows('products', function ($r) use ($machines) {
            $row = ['name' => trim($r->name)];

            if (! $this->mapped('products', $r->id)) {
                // Exact name only, and only when one machine has it ("L250" exists twice: left for the dashboard).
                $hits = $machines->get($this->norm($r->name));
                $row['machine_id'] = $hits && $hits->count() === 1 ? (int) $hits->first()->id : null;
            }

            return $row;
        });
    }

    private function importActivityLog(): void
    {
        $subjects = [
            'App\\Models\\Letter' => 'letters',
            'App\\Models\\Inventory' => 'inventories',
            'App\\Models\\InventoryItem' => 'inventory_items',
            'App\\Models\\TreasuryExpense' => 'treasury_expenses',
            'App\\Models\\TreasuryExpenseItem' => 'treasury_expense_items',
        ];

        $this->importRows('activity_log', function ($r) use ($subjects) {
            $table = $subjects[$r->subject_type] ?? null;

            return $this->copy($r, ['log_name', 'description', 'subject_type', 'event', 'properties', 'batch_uuid']) + [
                // Subjects deleted in env (a stock item that became a letter) keep the env id in properties only.
                'subject_id' => $table ? $this->opt($table, $r->subject_id) : null,
                'causer_type' => $r->causer_type === 'App\\Models\\User' ? 'App\\Models\\Staff' : $r->causer_type,
                'causer_id' => $r->causer_type === 'App\\Models\\User' ? $this->opt('users', $r->causer_id) : $r->causer_id,
            ];
        });
    }

    /** Insert new env rows, update the ones imported before. Timestamps are kept from env. */
    private function importRows(string $table, callable $transform): void
    {
        foreach ($this->src->table($table)->orderBy('id')->cursor() as $r) {
            $row = $transform($r) + [
                'created_at' => $r->created_at ?? null,
                'updated_at' => $r->updated_at ?? null,
            ];

            $localId = $this->mapped($table, $r->id);

            if ($localId && DB::table($table)->where('id', $localId)->exists()) {
                DB::table($table)->where('id', $localId)->update($row);
            } else {
                $this->remember($table, $r->id, DB::table($table)->insertGetId($row));
            }
        }
    }

    private function verify(): bool
    {
        $sums = [
            'letters' => ['cash', 'limit', 'admin_fees', 'paid', 'remaining'],
            'inventory_items' => ['cash', 'paid', 'remaining'],
            'treasury_expenses' => ['total_amount'],
            'treasury_expense_items' => ['amount'],
            'attendances' => ['late_minutes', 'deduction_days', 'deduction_amount'],
            'attendance_deduction_rules' => ['deduction_days'],
            'employee_advance_requests' => ['amount'],
            'employee_bonuses' => ['amount'],
            'targets' => ['target_count'],
        ];

        $rows = [];
        $ok = true;

        foreach (['users', 'warehouses', 'products', 'inventories', 'inventory_items', 'letters', 'treasury_expenses',
            'treasury_expense_items', 'targets', 'attendance_deduction_rules', 'attendances', 'employee_advance_requests',
            'employee_vacation_requests', 'employee_bonuses', 'activity_log'] as $table) {
            $local = $table === 'users' ? 'staff' : $table;
            $ids = array_values($this->map[$table] ?? []);
            $envCount = $this->src->table($table)->count();
            $hereCount = $ids ? DB::table($local)->whereIn('id', $ids)->count() : 0;
            $same = $envCount === $hereCount && count(array_unique($ids)) === $envCount;

            $detail = [];
            foreach ($sums[$table] ?? [] as $col) {
                $a = round((float) $this->src->table($table)->sum($col), 2);
                $b = round((float) ($ids ? DB::table($local)->whereIn('id', $ids)->sum($col) : 0), 2);
                $same = $same && $a === $b;
                $detail[] = "{$col} " . number_format($a, 2) . ($a === $b ? ' =' : ' ≠ ' . number_format($b, 2));
            }

            $ok = $ok && $same;
            $rows[] = [$table, $envCount, $hereCount, $same ? 'OK' : 'MISMATCH', implode(' | ', $detail)];
        }

        $this->table(['table', 'env', 'here', 'check', 'totals (env = here)'], $rows);
        $this->info(sprintf(
            'Staff: %d paired, %d same email, %d new.',
            $this->staffReport['paired'], $this->staffReport['email'], $this->staffReport['created']
        ));

        return $ok;
    }

    private function copy(object $r, array $fields): array
    {
        return array_intersect_key((array) $r, array_flip($fields));
    }

    private function req(string $table, $envId): int
    {
        return $this->mapped($table, $envId) ?? throw new RuntimeException("{$table} #{$envId} was not imported");
    }

    private function opt(string $table, $envId): ?int
    {
        return $envId === null ? null : $this->mapped($table, $envId);
    }

    private function mapped(string $table, $envId): ?int
    {
        return $this->map[$table][(int) $envId] ?? null;
    }

    private function remember(string $table, int $envId, int $localId): void
    {
        $this->map[$table][$envId] = $localId;

        DB::table('env_import_map')->updateOrInsert(
            ['table' => $table, 'env_id' => $envId],
            ['local_id' => $localId, 'updated_at' => now(), 'created_at' => now()]
        );
    }

    private function loadMap(): void
    {
        foreach (DB::table('env_import_map')->get() as $m) {
            $this->map[$m->table][(int) $m->env_id] = (int) $m->local_id;
        }
    }

    /** Compare names the way people write them: no tatweel/spaces/case, one alef, ة=ه, ى=ي, Arabic digits. */
    private function norm(string $s): string
    {
        $s = Str::lower(trim($s));
        $s = strtr($s, ['ـ' => '', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);

        return preg_replace('/[\s\-_]+/u', '', $s);
    }
}
