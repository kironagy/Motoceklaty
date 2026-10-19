<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner's list of 2026-10-18:
 * - staff.can_clock_in_any_branch: an employee moved between branches clocks in at whichever he stands in.
 * - teams + team_staff, daily_tasks (+ files) and daily_task_reports: team leaders hand out daily tasks
 *   and send the day's report to the owner.
 * - sms_logs: the "your request is under review" SMS sent for every new request.
 * - installment_requests transfer: who refused a transfer of an owner's request.
 * - The old clock-in deducted 3 days for being a minute late: days late 90 minutes or less get 1 day.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('staff', 'can_clock_in_any_branch')) {
            Schema::table('staff', function (Blueprint $table) {
                $table->boolean('can_clock_in_any_branch')->default(false)->after('branch_id');
            });
        }

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('leader_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('team_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['team_id', 'staff_id']);
        });

        Schema::create('daily_task_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->date('report_date');
            $table->unsignedInteger('tasks_total')->default(0);
            $table->unsignedInteger('tasks_done')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['team_id', 'report_date']);
        });

        Schema::create('daily_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('task_date');
            $table->string('status', 20)->default('todo'); // todo | done
            $table->text('done_note')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->foreignId('report_id')->nullable()->constrained('daily_task_reports')->nullOnDelete();
            $table->timestamps();
            $table->index(['team_id', 'task_date']);
            $table->index(['assigned_to', 'status']);
        });

        Schema::create('daily_task_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_task_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // attachment (from the leader) | proof (from the member)
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installment_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone', 32)->nullable();
            $table->text('message');
            $table->string('status', 20); // sent | failed | skipped
            $table->text('response')->nullable();
            $table->timestamps();
        });

        if (! Schema::hasColumn('installment_requests', 'transfer_rejected_at')) {
            Schema::table('installment_requests', function (Blueprint $table) {
                $table->timestamp('transfer_rejected_at')->nullable();
            });
        }

        // Days of the old clock-in (no point) that took 3 days for 90 minutes late or less: 1 day.
        DB::table('attendances')
            ->where('deduction_days', 3)
            ->where('late_minutes', '<=', 90)
            ->whereNotExists(fn ($q) => $q->from('attendance_points')->whereColumn('attendance_points.attendance_id', 'attendances.id'))
            ->update([
                'deduction_amount' => DB::raw('ROUND(deduction_amount / 3, 2)'),
                'deduction_days' => 1,
            ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
        Schema::dropIfExists('daily_task_files');
        Schema::dropIfExists('daily_tasks');
        Schema::dropIfExists('daily_task_reports');
        Schema::dropIfExists('team_staff');
        Schema::dropIfExists('teams');

        if (Schema::hasColumn('staff', 'can_clock_in_any_branch')) {
            Schema::table('staff', fn (Blueprint $table) => $table->dropColumn('can_clock_in_any_branch'));
        }

        if (Schema::hasColumn('installment_requests', 'transfer_rejected_at')) {
            Schema::table('installment_requests', fn (Blueprint $table) => $table->dropColumn('transfer_rejected_at'));
        }
    }
};
