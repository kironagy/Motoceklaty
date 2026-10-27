<?php

namespace Tests\Feature;

use App\Filament\Resources\GuideResource;
use App\Http\Middleware\RecordGuideActions;
use App\Models\GuideEvent;
use App\Models\Staff;
use App\Models\User;
use App\Support\GuideRecorder;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GuideTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        (require database_path('migrations/2026_10_25_120000_create_guide_events_table.php'))->up();
        Schema::create('users', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('email');
            $table->string('password'); $table->timestamps();
        });
    }

    public function test_only_the_two_staff_ids_can_access_even_if_other_staff_are_super_admins(): void
    {
        foreach ([25 => true, 27 => true, 1 => false, 26 => false] as $id => $allowed) {
            Auth::guard('filament')->setUser((new Staff)->forceFill(['id' => $id, 'is_super_admin' => true]));
            $this->assertSame($allowed, GuideResource::canViewAny());
            $this->assertSame($allowed, GuideResource::shouldRegisterNavigation());
            $this->assertSame($allowed, GuideResource::canView(new GuideEvent));
            $this->assertFalse(GuideResource::canCreate());
            $this->assertFalse(GuideResource::canEdit(new GuideEvent));
            $this->assertFalse(GuideResource::canDelete(new GuideEvent));
        }
        Auth::guard('filament')->forgetUser();
        Auth::guard('web')->setUser((new User)->forceFill(['id' => 25]));
        $this->assertFalse(GuideResource::canViewAny());
    }

    public function test_records_creation_old_and_new_values_and_deletion_without_recursing(): void
    {
        Auth::guard('filament')->setUser((new Staff)->forceFill(['id' => 25, 'name' => 'أحمد']));
        $user = User::create(['name' => 'قبل', 'email' => 'test@example.com', 'password' => 'secret']);
        $user->update(['name' => 'بعد']);
        $user->delete();
        $this->assertSame(3, GuideEvent::count());
        $event = GuideEvent::where('event', 'updated')->firstOrFail();
        $this->assertSame('أحمد', $event->actor_name);
        $this->assertSame('25', $event->actor_id);
        $this->assertSame([['field' => 'الاسم', 'before' => 'قبل', 'after' => 'بعد']], $event->changes);
        $this->assertStringNotContainsString($user->getRawOriginal('password'), GuideEvent::first()->toJson());
        $this->assertSame('[محجوب]', collect(GuideEvent::first()->changes)->firstWhere('field', 'password')['after']);
    }

    public function test_records_logins_and_logouts_with_the_explicit_actor(): void
    {
        Schema::create('staff_login_logs', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('staff_id');
            foreach (['ip_address', 'user_agent', 'device_hash', 'browser', 'platform', 'device_type', 'logged_in_at'] as $field) {
                $table->string($field)->nullable();
            }
            $table->timestamps();
        });
        $staff = (new Staff)->forceFill(['id' => 27, 'name' => 'المدير']);
        Event::dispatch(new Login('filament', $staff, false));
        Event::dispatch(new Logout('filament', $staff));
        $this->assertSame(['login', 'logout'], GuideEvent::whereIn('event', ['login', 'logout'])->orderBy('id')->pluck('event')->all());
        $this->assertSame('27', GuideEvent::where('event', 'login')->first()->actor_id);
    }

    public function test_task_attachment_creation_and_update_are_saved_and_audited_without_calling_name_as_a_relation(): void
    {
        Schema::create('daily_task_files', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('daily_task_id');
            $table->string('kind'); $table->string('path'); $table->string('original_name')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable(); $table->timestamps();
        });
        Auth::guard('filament')->setUser((new Staff)->forceFill(['id' => 27, 'name' => 'المدير']));
        $file = \App\Models\DailyTaskFile::create([
            'daily_task_id' => 1, 'kind' => 'attachment', 'path' => 'tasks/photo.jpg', 'original_name' => 'صورة التاسك.jpg',
        ]);
        $file->update(['original_name' => 'الصورة الجديدة.jpg']);
        $this->assertSame('الصورة الجديدة.jpg', $file->fresh()->original_name);
        $events = GuideEvent::where('subject_type', \App\Models\DailyTaskFile::class)->orderBy('id')->get();
        $this->assertSame(['created', 'updated'], $events->pluck('event')->all());
        $this->assertStringContainsString('صورة التاسك.jpg', $events[0]->subject_label);
        $this->assertStringContainsString('الصورة الجديدة.jpg', $events[1]->subject_label);
        $file->update(['original_name' => null]);
        $this->assertSame(3, GuideEvent::where('subject_type', \App\Models\DailyTaskFile::class)->count());
    }

    public function test_bulk_writes_are_captured_without_logging_request_secrets(): void
    {
        $request = Request::create('/bulk-update', 'POST', ['password' => 'never-record-this']);
        app(RecordGuideActions::class)->handle($request, function () {
            DB::table('users')->insert(['name' => 'جديد', 'email' => 'x@example.com', 'password' => 'hidden']);
            DB::table('users')->update(['name' => 'تعديل جماعي']);
            return response('ok');
        });
        $event = GuideEvent::where('event', 'action')->firstOrFail();
        $this->assertContains('تعديل users', $event->context['operations']);
        $this->assertStringNotContainsString('never-record-this', $event->toJson());
    }

    public function test_redacts_nested_credentials_in_json_strings(): void
    {
        $safe = GuideRecorder::sanitize(['settings' => '{"nested":{"api_key":"secret","name":"safe"}}']);
        $this->assertSame('[محجوب]', $safe['settings']['nested']['api_key']);
        $this->assertSame('safe', $safe['settings']['nested']['name']);
    }

    public function test_noop_save_does_not_create_a_change_and_details_escape_html(): void
    {
        $user = User::create(['name' => '<script>alert(1)</script>', 'email' => 'x@example.com', 'password' => 'hidden']);
        $user->save();
        $this->assertSame(1, GuideEvent::count());
        $html = view('filament.guide.details', ['record' => GuideEvent::first()])->render();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_imports_old_audit_events_once_with_original_time_and_redacted_values(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            foreach (['event', 'description', 'subject_type', 'subject_id', 'causer_type', 'causer_id', 'properties', 'created_at'] as $field) $table->text($field)->nullable();
        });
        DB::table('users')->insert(['id' => 7, 'name' => 'المستخدم القديم', 'email' => 'old@example.test', 'password' => 'hidden']);
        DB::table('activity_log')->insert(['id' => 10, 'event' => 'updated', 'description' => 'updated',
            'subject_type' => User::class, 'subject_id' => 7, 'causer_type' => User::class, 'causer_id' => 7,
            'properties' => json_encode(['old' => ['name' => 'قبل', 'password' => 'old-secret'], 'attributes' => ['name' => 'بعد', 'password' => 'new-secret']]),
            'created_at' => '2026-01-01 12:00:00']);
        $history = app(\App\Support\GuideHistory::class);
        $this->assertSame(1, $history->import()['activity_log']);
        $this->assertSame(0, $history->import()['activity_log']);
        $record = GuideEvent::where('legacy_source', 'activity_log')->firstOrFail();
        $this->assertSame('المستخدم القديم', $record->actor_name);
        $this->assertSame('2026-01-01 12:00:00', $record->created_at->format('Y-m-d H:i:s'));
        $this->assertStringNotContainsString('old-secret', $record->toJson());
        $this->assertStringNotContainsString('new-secret', $record->toJson());
        $this->assertSame(1, DB::table('activity_log')->count());
    }

    public function test_query_cannot_be_called_by_another_super_admin(): void
    {
        Auth::guard('filament')->setUser((new Staff)->forceFill(['id' => 1, 'is_hitler' => true, 'is_super_admin' => true]));
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        GuideResource::getEloquentQuery();
    }

    public function test_download_actions_are_recorded_but_polling_and_malformed_metadata_are_not(): void
    {
        Auth::guard('filament')->setUser((new Staff)->forceFill(['id' => 27, 'name' => 'المدير']));
        $middleware = app(RecordGuideActions::class);
        $request = Request::create('/livewire/update', 'POST', ['components' => [[
            'snapshot' => json_encode(['memo' => ['path' => 'admin/letters']]), 'calls' => [['method' => 'download']],
        ]], 'password' => 'never-log-me']);
        $middleware->handle($request, fn () => response('ok'));
        $record = GuideEvent::firstOrFail();
        $this->assertSame(['download'], $record->context['actions']);
        $this->assertSame('admin/letters', $record->context['page']);
        $this->assertStringNotContainsString('never-log-me', $record->toJson());
        $middleware->handle(Request::create('/livewire/update', 'POST', ['components' => [
            'bad', ['snapshot' => [], 'calls' => [['method' => 'refreshConnection']]],
        ]]), fn () => response('ok'));
        $this->assertSame(1, GuideEvent::count());
    }

    public function test_people_view_hides_server_bots_and_duplicate_login_metadata(): void
    {
        Schema::create('staff', function (Blueprint $table) { $table->id(); $table->string('name'); $table->boolean('is_bot')->default(false); });
        DB::table('staff')->insert([['id' => 25, 'name' => 'موظف', 'is_bot' => false], ['id' => 90, 'name' => 'بوت', 'is_bot' => true]]);
        Auth::guard('filament')->setUser((new Staff)->forceFill(['id' => 27]));
        foreach ([[25, \App\Models\Machine::class], [null, \App\Models\Machine::class], [90, \App\Models\Machine::class], [25, \App\Models\StaffLoginLog::class]] as [$actor, $subject]) {
            DB::table('guide_events')->insert(['event' => 'updated', 'actor_type' => $actor ? Staff::class : null, 'actor_id' => $actor,
                'actor_name' => 'test', 'subject_type' => $subject, 'subject_label' => 'test', 'description' => 'test', 'created_at' => now()]);
        }
        $this->assertSame(1, GuideResource::getEloquentQuery()->count());
        $this->assertSame('25', GuideResource::getEloquentQuery()->first()->actor_id);
        $this->assertSame(4, GuideEvent::count());
    }

    public function test_summary_uses_plain_arabic_values_and_hides_technical_fields(): void
    {
        $record = new GuideEvent(['event' => 'updated', 'subject_type' => \App\Models\Machine::class, 'subject_id' => 5, 'changes' => [
            ['field' => 'السعر النقدي', 'before' => 50000, 'after' => 55000],
            ['field' => 'turn_id', 'before' => 1, 'after' => 2],
        ]]);
        $this->assertSame('مكنة #5', $record->subjectTitle());
        $this->assertStringContainsString('50,000 جنيه', $record->changeSummary());
        $this->assertStringContainsString('55,000 جنيه', $record->changeSummary());
        $this->assertStringNotContainsString('turn_id', $record->changeSummary());
        $this->assertSame('اتوافق عليه', GuideRecorder::displayValue('approved', 'الحالة'));
        $record->setAttribute('changes', [['field' => 'colors', 'before' => ['أحمر'], 'after' => ['أسود']]]);
        $this->assertSame('عدّل الألوان المتاحة', $record->changeSummary());
    }

    public function test_model_changes_do_not_also_create_a_duplicate_request_summary(): void
    {
        Auth::guard('filament')->setUser((new Staff)->forceFill(['id' => 27, 'name' => 'مدير']));
        app(RecordGuideActions::class)->handle(Request::create('/save', 'POST'), function () {
            User::create(['name' => 'موظف', 'email' => 'new@example.test', 'password' => 'hidden']);
            return response('ok');
        });
        $this->assertSame(1, GuideEvent::where('event', 'created')->count());
        $this->assertSame(0, GuideEvent::where('event', 'action')->count());
    }
}
