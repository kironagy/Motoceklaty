<?php

namespace Tests\Feature;

use App\Agent\Providers\AiProvider;
use App\Agent\Providers\AiResponse;
use App\Agent\Providers\FakeAiProvider;
use App\Domain\Asfoora\AsfooraAgent;
use App\Domain\Asfoora\AsfooraExcel;
use App\Domain\Asfoora\ReadOnlyQuery;
use App\Filament\Pages\Asfoora;
use App\Models\AsfooraChat;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Owner 2026-11-26: «عصفورة» only reads, only for is_hitler, and its Excel holds every row. */
class AsfooraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'local']);
    }

    private function staff(array $flags = []): Staff
    {
        return Staff::create($flags + ['name' => 'يوسف', 'email' => uniqid().'@x.test', 'password' => 'secret', 'is_admin' => true, 'is_super_admin' => true]);
    }

    private function requests(int $count, string $status, ?int $staffId = null): void
    {
        $brand = DB::table('brands')->insertGetId(['name' => 'بجاج', 'image' => 'x.png', 'created_at' => now(), 'updated_at' => now()]);
        $machine = DB::table('machines')->insertGetId(['name' => 'بوكسر', 'brand_id' => $brand, 'created_at' => now(), 'updated_at' => now()]);
        for ($i = 1; $i <= $count; $i++) {
            DB::table('installment_requests')->insert([
                'applicant_name' => "عميل {$i}", 'applicant_phone' => '0100000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'installment_type' => 'امان', 'machine_id' => $machine, 'months' => 24, 'status' => $status,
                'staff_id' => $staffId, 'checks_report' => json_encode('ناقص ورق', JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_the_query_guard_refuses_anything_but_one_plain_read(): void
    {
        foreach ([
            "update staff set name = 'x'", 'select 1; delete from staff', 'select * from staff -- x', 'select * from staff /* x */',
            'select password from staff', 'select * from gemini_api_keys', 'select * from aman_tokens', 'select sleep(3)',
            "select * into outfile '/tmp/x' from staff", 'with x as (select 1) delete from staff', 'select * from information_schema.tables',
            'select * from staff for update', 'select benchmark(1000, md5(1))', "select load_file('/etc/passwd')",
        ] as $sql) {
            try {
                ReadOnlyQuery::check($sql);
                $this->fail("not refused: {$sql}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        // a word inside a string is only data
        $this->assertSame(['x' => 'update me'], ReadOnlyQuery::run("select 'update me' as x")['rows'][0]);
    }

    public function test_reads_never_return_a_password_and_cannot_write(): void
    {
        $this->staff();
        $row = ReadOnlyQuery::run('select * from staff')['rows'][0];
        $this->assertArrayHasKey('name', $row);
        $this->assertArrayNotHasKey('password', $row);
        $this->assertArrayNotHasKey('remember_token', $row);

        // even a write that got past the guard fails inside its READ ONLY transaction
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('START TRANSACTION READ ONLY');
            try {
                DB::statement("update staff set name = 'x'");
                $this->fail('the read only transaction let a write through');
            } catch (\Illuminate\Database\QueryException) {
                $this->addToAssertionCount(1);
            } finally {
                DB::statement('ROLLBACK');
            }
        }
        $this->assertSame(0, DB::table('staff')->where('name', 'x')->count());
    }

    public function test_it_answers_from_a_tool_and_its_excel_has_every_row(): void
    {
        $owner = $this->staff(['is_hitler' => true]);
        $this->requests(70, 'paused', $owner->id);
        $this->requests(5, 'approved', $owner->id);

        $fake = new FakeAiProvider();
        $fake->queue(new AiResponse([], [['id' => 'c1', 'name' => 'list_requests', 'args' => ['statuses' => ['paused']]]], 'TOOL', ['total_tokens' => 10], 'bormaa:test', null, 1));
        $fake->queue(new AiResponse(['عندك **70 طلب متوقف**، أولهم #75. الباقي في ملف الإكسيل.'], [], 'STOP', ['total_tokens' => 10], 'bormaa:test', null, 1));
        $this->app->instance(AiProvider::class, $fake);

        $chat = AsfooraChat::create(['staff_id' => $owner->id]);
        $answer = app(AsfooraAgent::class)->answer($chat, 'الطلبات المتوقفة');

        $this->assertStringContainsString('70 طلب', $answer->content);
        $this->assertArrayNotHasKey('pending', $answer->meta);
        // the AI got 50 of them and the real total
        $result = json_decode((string) json_encode($fake->requests()[1]->contents[2]['parts'][0]['result']), true);
        $this->assertSame(70, $result['total_matching']);
        $this->assertCount(50, $result['requests']);
        // the AI never sees the database's own clock - it gets today's date
        $this->assertStringContainsString(now()->toDateString(), $fake->requests()[0]->system);

        // the file is the whole list, run again
        $path = AsfooraExcel::write($answer, 'الطلبات المتوقفة');
        $zip = new \ZipArchive();
        $zip->open($path);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);
        // title, subtitle and header (the blank spacer row is not written) + all 70 requests
        $this->assertSame(3 + 70, substr_count($sheet, '<row '));
        $this->assertStringContainsString('rightToLeft="true"', $sheet);

        $this->assertStringContainsString('/admin/deliveries/75/edit', Asfoora::render_answer($answer->content));
    }

    public function test_the_history_tells_who_uploaded_who_took_it_and_who_it_was_taken_from(): void
    {
        $mariam = $this->staff(['name' => 'مريم']);
        $beso = $this->staff(['name' => 'beso']);
        $manga = $this->staff(['name' => 'Manga']);
        $hold = Staff::create(['name' => 'متوقف', 'email' => 'hold@motogate.com', 'password' => 'secret']);
        $this->requests(2, 'paused');
        [$taken, $kept] = DB::table('installment_requests')->orderBy('id')->pluck('id')->all();
        DB::table('installment_requests')->where('id', $taken)->update(['staff_id' => $hold->id,
            'checks_report' => json_encode(['aman' => 'محتاج مفردات مرتب', 'staff' => 'كلمت العميل'], JSON_UNESCAPED_UNICODE)]);
        DB::table('installment_requests')->where('id', $kept)->update(['staff_id' => $manga->id]);

        $log = fn ($id, $props) => DB::table('activity_log')->insert(['log_name' => 'default', 'description' => 'updated', 'subject_type' => 'App\\Models\\InstallmentRequest',
            'subject_id' => $id, 'event' => 'updated', 'properties' => json_encode($props), 'created_at' => now(), 'updated_at' => now()]);
        $log($taken, ['attributes' => ['staff_id' => $mariam->id, 'status' => 'new_request']]);
        $log($taken, ['attributes' => ['status' => 'paused'], 'old' => ['status' => 'new_request']]);
        $log($taken, ['attributes' => ['staff_id' => $beso->id], 'old' => ['staff_id' => $mariam->id]]);
        $log($taken, ['attributes' => ['staff_id' => $hold->id], 'old' => ['staff_id' => $beso->id]]);
        $log($kept, ['attributes' => ['staff_id' => $manga->id]]);

        $rows = collect(\App\Domain\Asfoora\AsfooraTools::call('requests_with_history', ['statuses' => ['paused']])['requests'])->keyBy('id');

        $this->assertSame('مريم', $rows[$taken]['uploaded_by']);
        $this->assertSame('مريم ← beso ← متوقف', $rows[$taken]['owners']);
        $this->assertSame('beso', $rows[$taken]['taken_from']);
        $this->assertFalse($rows[$taken]['still_with_employee']);
        $this->assertSame('محتاج مفردات مرتب', $rows[$taken]['aman_reason']);
        $this->assertSame('كلمت العميل', $rows[$taken]['staff_note']);

        $this->assertNull($rows[$kept]['taken_from']);
        $this->assertTrue($rows[$kept]['still_with_employee']);
        $this->assertSame('Manga', $rows[$kept]['current_staff']);
    }

    public function test_it_sorts_requests_into_files_of_their_own_with_notes_and_colours(): void
    {
        $owner = $this->staff(['is_hitler' => true]);
        $this->requests(6, 'rejected', $owner->id);
        $ids = DB::table('installment_requests')->orderBy('id')->pluck('id')->all();
        DB::table('installment_requests')->where('id', $ids[5])->update(['request_type' => 'fake']);
        DB::table('installment_requests')->where('id', $ids[0])->update(['status' => 'paused_aman']);

        $fake = new FakeAiProvider();
        $fake->queue(new AiResponse([], [['id' => 'c1', 'name' => 'requests_with_history', 'args' => ['statuses' => ['rejected', 'paused_aman']]]], 'TOOL', [], 'bormaa:test', null, 1));
        $fake->queue(new AiResponse([], [['id' => 'c2', 'name' => 'make_report_files', 'args' => [
            'groups' => [
                ['title' => 'المرفوض الميت', 'ids' => [$ids[1], $ids[2]], 'notes' => [['id' => $ids[1], 'note' => 'سكور وحش']]],
                ['title' => 'ينفع نقاوح فيه', 'ids' => [$ids[0], $ids[3], $ids[4]]],
            ],
            'highlight' => [['column' => 'status', 'value' => 'متوقف امان', 'color' => 'yellow']],
        ]]], 'TOOL', [], 'bormaa:test', null, 1));
        $fake->queue(new AiResponse(['فرزتهم: 2 ميتين و3 ينفع نقاوح فيهم.'], [], 'STOP', [], 'bormaa:test', null, 1));
        $this->app->instance(AiProvider::class, $fake);

        $answer = app(AsfooraAgent::class)->answer(AsfooraChat::create(['staff_id' => $owner->id]), 'افرز المرفوض');

        // the test request is never in a report
        $this->assertSame(5, json_decode((string) json_encode($fake->requests()[1]->contents[2]['parts'][0]['result']), true)['total_matching']);
        // the AI got the counts back, not the rows
        $back = json_decode((string) json_encode($fake->requests()[2]->contents[4]['parts'][0]['result']), true);
        $this->assertSame([2, 3], array_column($back['files'], 'count'));
        $this->assertArrayNotHasKey('_tables', $back);

        $tables = $answer->meta['tables'];
        $this->assertSame(['المرفوض الميت', 'ينفع نقاوح فيه'], array_column($tables, 'title'));

        $sheet = function (int $only) use ($answer) {
            $path = AsfooraExcel::write($answer, 'افرز المرفوض', $only);
            $zip = new \ZipArchive();
            $zip->open($path);
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml').$zip->getFromName('xl/sharedStrings.xml').$zip->getFromName('xl/styles.xml');
            $zip->close();
            unlink($path);

            return $xml;
        };
        $dead = $sheet(0);
        $this->assertSame(3 + 2, substr_count($dead, '<row '));
        $this->assertStringContainsString('سكور وحش', $dead);
        $this->assertStringContainsString('ليه في المجموعة دي', $dead);
        $alive = $sheet(1);
        $this->assertSame(3 + 3, substr_count($alive, '<row '));
        $this->assertStringContainsString('FEF08A', $alive);
        $this->assertStringContainsString('ينفع نقاوح فيه', AsfooraExcel::filename($answer, 1));
    }

    private function say(FakeAiProvider $fake, array $text, array $calls = []): void
    {
        $fake->queue(new AiResponse($text, $calls, $calls ? 'TOOL' : 'STOP', [], 'bormaa:test', null, 1));
    }

    public function test_a_big_sort_comes_in_batches_into_the_same_files(): void
    {
        $owner = $this->staff(['is_hitler' => true]);
        $this->requests(4, 'rejected', $owner->id);
        $ids = DB::table('installment_requests')->orderBy('id')->pluck('id')->all();

        $fake = new FakeAiProvider();
        $this->say($fake, [], [['id' => 'a', 'name' => 'make_report_files', 'args' => ['groups' => [['title' => 'ميت', 'ids' => [$ids[0]]], ['title' => 'ينفع', 'ids' => [$ids[1]]]]]]]);
        $this->say($fake, [], [['id' => 'b', 'name' => 'make_report_files', 'args' => ['groups' => [['title' => 'ميت', 'ids' => [$ids[2]], 'notes' => [['id' => $ids[2], 'note' => 'متعثر']]], ['title' => 'ينفع', 'ids' => [$ids[3]]]]]]]);
        $this->say($fake, ['تمام']);
        $this->app->instance(AiProvider::class, $fake);

        $answer = app(AsfooraAgent::class)->answer(AsfooraChat::create(['staff_id' => $owner->id]), 'افرز');
        $tables = collect($answer->meta['tables'])->keyBy('title');
        $this->assertEqualsCanonicalizing([$ids[0], $ids[2]], array_column($tables['ميت']['rows'], 'id'));
        $this->assertEqualsCanonicalizing([$ids[1], $ids[3]], array_column($tables['ينفع']['rows'], 'id'));
        $this->assertSame(16000, $fake->requests()[0]->maxOutputTokens);
    }

    public function test_she_remembers_what_she_is_told_and_forgets_it_on_request(): void
    {
        $owner = $this->staff(['is_hitler' => true]);
        $chat = AsfooraChat::create(['staff_id' => $owner->id]);
        $fake = new FakeAiProvider();
        $this->say($fake, [], [['id' => 'r', 'name' => 'remember', 'args' => ['content' => 'السكور الوحش = ميت']]]);
        $this->say($fake, ['حاضر، افتكرت.']);
        $this->say($fake, ['تمام']);
        $this->app->instance(AiProvider::class, $fake);

        app(AsfooraAgent::class)->answer($chat, 'افتكري إن السكور الوحش ميت');
        $memory = \App\Models\AsfooraMemory::sole();
        $this->assertSame('السكور الوحش = ميت', $memory->content);

        app(AsfooraAgent::class)->answer($chat, 'افرز المرفوض');
        $this->assertStringContainsString($memory->id.'. السكور الوحش = ميت', $fake->requests()[2]->system);

        \App\Domain\Asfoora\AsfooraTools::call('forget', ['id' => $memory->id]);
        $this->assertSame(0, \App\Models\AsfooraMemory::count());
        // her notebook is not readable by the free query
        $this->assertArrayHasKey('error', \App\Domain\Asfoora\AsfooraTools::call('run_sql', ['query' => 'select * from asfoora_memories']));
    }

    public function test_follow_ups_become_buttons_and_charts_are_drawn(): void
    {
        $owner = $this->staff(['is_hitler' => true]);
        $fake = new FakeAiProvider();
        $this->say($fake, ["النهارده 15 طلب.\n\n```chart\n{\"type\":\"bar\",\"title\":\"لكل موظف\",\"labels\":[\"حبيبة\",\"يوسف\"],\"values\":[10,5],\"previous\":[8,6]}\n```\n\n```followups\n[\"نفس التقرير لامبارح\", \"طلبات حبيبة بس\"]\n```"]);
        $this->app->instance(AiProvider::class, $fake);

        $answer = app(AsfooraAgent::class)->answer(AsfooraChat::create(['staff_id' => $owner->id]), 'تقرير النهارده');
        $this->assertSame(['نفس التقرير لامبارح', 'طلبات حبيبة بس'], $answer->meta['followups']);
        $this->assertStringNotContainsString('followups', $answer->content);

        $html = Asfoora::render_answer($answer->content);
        $this->assertStringContainsString('mg-asf-bars', $html);
        $this->assertStringContainsString('width:100%', $html);
        $this->assertStringContainsString('class="prev"', $html);
        $this->assertStringNotContainsString('```', $html);
        // a script in a chart is only text
        $this->assertStringNotContainsString('<script', Asfoora::render_answer("```chart\n{\"labels\":[\"<script>x</script>\"],\"values\":[1]}\n```"));
    }

    public function test_a_period_is_compared_with_the_one_before(): void
    {
        $owner = $this->staff(['is_hitler' => true, 'name' => 'حبيبة']);
        $this->requests(3, 'new', $owner->id);
        DB::table('installment_requests')->limit(1)->update(['created_at' => now()->subDays(7)]);

        $r = \App\Domain\Asfoora\AsfooraTools::call('requests_report', ['from' => now()->subDays(6)->toDateString(), 'to' => now()->toDateString(), 'compare' => true]);
        $this->assertSame(['group' => 'حبيبة', 'count' => 2, 'previous' => 1, 'change' => 1], $r['groups'][0]);
        $this->assertSame(1, $r['previous_total']);
    }

    public function test_the_daily_report_reaches_every_owner(): void
    {
        $a = $this->staff(['is_hitler' => true]);
        $b = $this->staff(['is_hitler' => true]);
        $this->staff();
        // a parking account carries is_hitler but is nobody
        $this->staff(['is_hitler' => true, 'email' => 'hold@motogate.com', 'name' => 'متوقف']);
        $fake = new FakeAiProvider();
        $this->say($fake, ["امبارح اترفع **12 طلب**.\n\nحاجتين تعرفهم: ..."]);
        $this->app->instance(AiProvider::class, $fake);

        $this->artisan('asfoora:daily-report', ['--date' => '2026-11-25'])->assertSuccessful();

        $this->assertStringContainsString('2026-11-25', $fake->requests()[0]->contents[0]['parts'][0]['text']);
        foreach ([$a, $b] as $owner) {
            $chat = AsfooraChat::where('staff_id', $owner->id)->sole();
            $this->assertSame('📅 تقرير يوم 2026-11-25', $chat->title);
            $this->assertStringContainsString('12 طلب', $chat->messages()->where('role', 'assistant')->value('content'));
            $this->assertSame(1, \App\Models\Notification::where('user_id', $owner->id)->count());
        }
        $this->assertSame(2, \App\Models\Notification::count());
    }

    public function test_staff_without_is_hitler_is_refused(): void
    {
        $this->actingAs($this->staff(), 'filament');
        $this->get(Asfoora::getUrl())->assertForbidden();
        $this->assertStringNotContainsString('عصفورة', $this->get('/admin')->getContent());
    }

    public function test_is_hitler_opens_it(): void
    {
        $this->actingAs($this->staff(['is_hitler' => true]), 'filament');
        $this->get(Asfoora::getUrl())->assertOk()->assertSee('عصفورة');
    }
}
