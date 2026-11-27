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
        // the AI got one page of them and the real total
        $result = json_decode((string) json_encode($fake->requests()[1]->contents[2]['parts'][0]['result']), true);
        $this->assertSame(70, $result['total_matching']);
        $this->assertCount(30, $result['requests']);
        // the AI never sees the database's own clock - it gets today's date
        $this->assertStringContainsString(now()->toDateString(), $fake->requests()[0]->system);

        // the file is the whole list, run again
        $path = AsfooraExcel::write($answer, 'الطلبات المتوقفة');
        $zip = new \ZipArchive();
        $zip->open($path);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);
        // the header + all 70 requests
        $this->assertSame(1 + 70, substr_count($sheet, '<row '));
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
        $this->assertSame(1 + 2, substr_count($dead, '<row '));
        $this->assertStringContainsString('سكور وحش', $dead);
        $this->assertStringContainsString('ليه في المجموعة دي', $dead);
        $alive = $sheet(1);
        $this->assertSame(1 + 3, substr_count($alive, '<row '));
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

    private function amanSheet(array $rows, bool $monthly = false): string
    {
        $path = 'asfoora-files/test-'.uniqid().'.xlsx';
        \Illuminate\Support\Facades\Storage::disk('local')->makeDirectory('asfoora-files');
        $writer = new \OpenSpout\Writer\XLSX\Writer();
        $writer->openToFile(\Illuminate\Support\Facades\Storage::disk('local')->path($path));
        if ($monthly) {
            // live 2026-11-27: his sheet starts with a dashboard, and each month has a title and totals above the header
            $writer->getCurrentSheet()->setName('Dashboard');
            $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['لوحة المتابعة']));
            $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['الشهر', 'الاسم في الشيت', 'إجمالي الطلبات']));
            $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['11-2026', 'x', 5]));
            $writer->addNewSheetAndMakeItCurrent();
        }
        $writer->getCurrentSheet()->setName('11-2026');
        if ($monthly) {
            $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['متابعة طلبات التمويل - نوفمبر']));
            $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['إجمالي الطلبات', '', 'طلبات Active', '', 'إجمالي مبالغ Active']));
            $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['=COUNTA(A5:A1004)', '', '=COUNTIF(D5:D1004,"Active")']));
        }
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['اسم العميل ', 'اسم المعرض ', 'رقم الطلب ', 'حاله الطلب ', 'المبلغ ', 'المتبقي ', 'تاريخ الطلب ', 'كومنت ']));
        foreach ($rows as $r) {
            $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($r));
        }
        $writer->close();

        return $path;
    }

    public function test_aman_sheet_changes_are_prepared_then_applied_only_on_the_owners_press(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $owner = $this->staff(['is_hitler' => true]);
        $this->requests(5, 'pending', $owner->id);
        $ids = DB::table('installment_requests')->orderBy('id')->pluck('id')->all();
        $names = ['أحمد عبد الموجود محمد', 'محمد سعد علي حسن', 'كريم عادل سيد', 'سامي فؤاد', 'وليد جبر'];
        foreach ($ids as $i => $id) {
            DB::table('installment_requests')->where('id', $id)->update(['applicant_name' => $names[$i], 'created_at' => '2026-11-05 10:00:00']);
        }
        DB::table('aman_uploads')->insert(['installment_request_id' => $ids[1], 'staff_id' => $owner->id, 'type' => 'upload', 'status' => 'uploaded', 'aman_ref' => '26116830050', 'created_at' => now(), 'updated_at' => now()]);

        $path = $this->amanSheet([
            ['احمد عبدالموجود محمد', 'موتوجيت', '26116830011', 'active', '57000', '55000', '11/05/2026', ''],          // by name (spelt differently) → approved with limit
            ['اسم تاني خالص', 'موتو جيت', '26116830050', 'Rejected ', '', '', '11/04/2026', 'حاله ائتمانيه مرفوضه'],    // by Aman's number → rejected
            ['كريم عادل سيد', 'موتوجيت', '26116830099', 'توقيع ', '40000', '40000', '11/06/2026', ''],                   // → delivered
            ['سامي فؤاد', 'العدوي', '26116830100', 'Rejected', '', '', '11/06/2026', 'سكور'],                           // another showroom → ignored
            ['وليد جبر', 'موتوجيت', '26116830101', 'Whatever', '', '', '11/06/2026', ''],                               // unknown status → review
            ['حد مش عندنا', 'موتوجيت', '26116839999', 'Rejected', '', '', '11/06/2026', 'x'],                          // not found
        ]);
        $chat = AsfooraChat::create(['staff_id' => $owner->id]);
        $chat->messages()->create(['role' => 'user', 'content' => 'غيري حالات موتو جيت', 'meta' => ['file' => ['name' => 'aman.xlsx', 'path' => $path]]]);
        \App\Domain\Asfoora\AsfooraTools::$chatId = $chat->id;

        $r = \App\Domain\Asfoora\AsfooraTools::call('aman_sheet_changes', []);
        $this->assertSame(3, $r['to_change']);
        $this->assertSame(1, $r['other_showrooms']);
        $this->assertSame(1, $r['need_review']);
        $this->assertSame(1, $r['not_found']);
        // nothing changed yet
        $this->assertSame(5, DB::table('installment_requests')->where('status', 'pending')->count());

        $action = \App\Models\AsfooraAction::sole();
        DB::table('installment_requests')->where('id', $ids[2])->update(['status' => 'paused']); // changed meanwhile
        $this->actingAs($owner, 'filament');
        $result = \App\Domain\Asfoora\AmanSheetSync::apply($action, $owner->id);

        $this->assertSame(2, $result['done']);
        $this->assertSame([$ids[2]], $result['skipped']);
        $approved = \App\Models\InstallmentRequest::find($ids[0]);
        $this->assertSame('approved', $approved->status);
        $this->assertStringContainsString('بحد 57,000', $approved->checks_report['aman']);
        $this->assertSame($owner->id, $approved->status_updated_by);
        $rejected = \App\Models\InstallmentRequest::find($ids[1]);
        $this->assertSame('rejected', $rejected->status);
        $this->assertStringContainsString('حاله ائتمانيه مرفوضه', $rejected->checks_report['aman']);
        $this->assertSame('paused', \App\Models\InstallmentRequest::find($ids[2])->status);
        $this->assertSame('pending', \App\Models\InstallmentRequest::find($ids[4])->status);
        $this->assertSame('applied', $action->fresh()->status);
        // the customers hear it like from staff
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SendWhatsappStatusNotification::class, 2);
    }

    public function test_many_requests_are_sorted_in_batches_by_a_separate_call(): void
    {
        $owner = $this->staff(['is_hitler' => true]);
        $this->requests(4, 'rejected', $owner->id);
        $ids = DB::table('installment_requests')->orderBy('id')->pluck('id')->all();
        // two requests share Aman's words: that reason is sorted once, for both
        foreach (['سكور وحش', 'سكور وحش', 'ناقص مفردات', 'كلام مش واضح'] as $i => $why) {
            DB::table('installment_requests')->where('id', $ids[$i])->update(['checks_report' => json_encode(['aman' => $why], JSON_UNESCAPED_UNICODE)]);
        }
        $fake = new FakeAiProvider();
        // three different reasons (newest first: كلام / ناقص / سكور); the third is left unlabelled and never dropped
        $fake->queue(new AiResponse(["```json\n".json_encode(['items' => [['k' => 2, 'group' => 'ميت', 'note' => 'سكور'], ['k' => 1, 'group' => 'ينفع', 'note' => 'ورق ناقص']]])."\n```"], [], 'STOP', [], 'bormaa:test', null, 1));
        $this->app->instance(AiProvider::class, $fake);

        $r = \App\Domain\Asfoora\AsfooraTools::call('sort_requests', ['statuses' => ['rejected'], 'groups' => [['title' => 'ميت', 'rule' => 'سكور'], ['title' => 'ينفع', 'rule' => 'ورق']]]);
        $this->assertSame(4, $r['total']);
        $this->assertCount(1, $fake->requests(), 'one call for three different reasons');
        $this->assertSame(['ميت' => 2, 'ينفع' => 1, 'محتاج مراجعة' => 1], array_column($r['files'], 'count', 'title'));

        // her own review group is used, never a second one
        $fake->queue(new AiResponse([json_encode(['items' => [['k' => 0, 'group' => 'ينفع', 'note' => 'x']]])], [], 'STOP', [], 'bormaa:test', null, 1));
        $again = \App\Domain\Asfoora\AsfooraTools::call('sort_requests', ['statuses' => ['rejected'], 'groups' => [['title' => 'ينفع', 'rule' => 'ورق'], ['title' => 'مرفوض - محتاج مراجعة', 'rule' => 'مش واضح']]]);
        $this->assertSame(['ينفع', 'مرفوض - محتاج مراجعة'], array_column($again['files'], 'title'));
        $this->assertStringContainsString('ورق ناقص', json_encode($r['_tables'], JSON_UNESCAPED_UNICODE));
    }

    public function test_reasons_and_notes_come_once_without_the_systems_own_lines(): void
    {
        $this->assertSame('وظيفة مرفوضه', \App\Domain\Asfoora\AsfooraTools::tidy("وظيفة مرفوضه\nرد أمان: وظيفة مرفوضه"));
        $this->assertSame("رقم طلب أمان: 26116833533\nكلمت العميل", \App\Domain\Asfoora\AsfooraTools::tidy(
            "رقم طلب أمان: 26116833533 — الموظف كتب رقم طلب أمان: 26116833533\nاتقدم من رابط الفورم (طلب تقسيط موتوسيكل)\nالعميل عدّل من تعديل العميل من رابط التقديم (2): صورة البطاقة\nكلمت العميل", true));
    }

    public function test_the_excel_looks_like_his_own_report(): void
    {
        $owner = $this->staff(['is_hitler' => true]);
        $chat = AsfooraChat::create(['staff_id' => $owner->id]);
        $rows = [['id' => 6196, 'customer' => 'عميل', 'phone' => '01050725028', 'created_at' => '2026-11-22', 'status' => 'متوقف امان']];
        $m = $chat->messages()->create(['role' => 'assistant', 'content' => 'x', 'meta' => ['tables' => [
            ['title' => 'الطلبات المتوقفة', 'columns' => array_keys($rows[0]), 'rows' => $rows, 'highlight' => [['column' => 'status', 'value' => 'متوقف امان', 'color' => 'yellow']]],
        ]]]);
        $path = AsfooraExcel::write($m, 'x');
        $zip = new \ZipArchive();
        $zip->open($path);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $all = $sheet.$zip->getFromName('xl/sharedStrings.xml').$zip->getFromName('xl/styles.xml');
        $zip->close();
        unlink($path);

        $this->assertSame(2, substr_count($sheet, '<row '));            // the header, then the rows - no title band
        $this->assertStringContainsString('0D9488', $all);                // the teal header
        $this->assertStringContainsString('FEF08A', $all);                // the yellow row
        $this->assertStringContainsString('22/11/2026', $all);           // his date format
        $this->assertStringContainsString('01050725028', $all);          // the phone keeps its 0
        $this->assertMatchesRegularExpression('/<col min="2" max="2" width="32"/', $sheet);
    }

    public function test_a_watch_posts_new_requests_every_hour_until_he_stops_it(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $owner = $this->staff(['is_hitler' => true]);
        $chat = AsfooraChat::create(['staff_id' => $owner->id]);
        \App\Domain\Asfoora\AsfooraTools::$chatId = $chat->id;

        $started = \App\Domain\Asfoora\AsfooraTools::call('watch_start', ['kind' => 'new_requests']);
        $watch = \App\Models\AsfooraWatch::findOrFail($started['started']);
        $this->assertSame(60, $watch->every_minutes);

        // nothing due yet
        $this->assertSame(0, app(\App\Domain\Asfoora\AsfooraWatcher::class)->runDue());

        // an hour later, nothing new: it still says so
        $this->travel(61)->minutes();
        $this->artisan('asfoora:watches')->assertSuccessful();
        $this->assertStringContainsString('مفيش طلبات جديدة', $chat->messages()->reorder('id', 'desc')->value('content'));
        $this->assertSame(1, \App\Models\Notification::where('user_id', $owner->id)->count());

        // two requests come; the next hour tells them
        $this->travel(5)->minutes();
        $this->requests(2, 'new_request', $owner->id);
        $this->travel(60)->minutes();
        $this->artisan('asfoora:watches')->assertSuccessful();
        $last = $chat->messages()->reorder('id', 'desc')->first();
        $this->assertStringContainsString('2 طلب جديد', $last->content);
        $this->assertCount(2, $last->meta['tables'][0]['rows']);

        // "خلاص وقف"
        \App\Domain\Asfoora\AsfooraTools::call('watch_stop', []);
        $this->travel(61)->minutes();
        $this->artisan('asfoora:watches')->assertSuccessful();
        $this->assertSame(2, \App\Models\Notification::where('user_id', $owner->id)->count());
    }

    public function test_owners_hear_every_employee_action_but_uploads_and_the_systems_own(): void
    {
        // only the customer messages are held; the alert itself runs
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\SendWhatsappStatusNotification::class]);
        app(\Illuminate\Bus\Dispatcher::class)->withoutDispatchingAfterResponses();
        $owner = $this->staff(['is_hitler' => true, 'name' => 'akera']);
        $habiba = $this->staff(['name' => 'حبيبة']);
        $yousef = $this->staff(['name' => 'يوسف']);
        Staff::create(['name' => 'متوقف', 'email' => 'hold@motogate.com', 'password' => 'x', 'is_hitler' => true]);
        $brand = DB::table('brands')->insertGetId(['name' => 'b', 'image' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $machine = DB::table('machines')->insertGetId(['name' => 'm', 'brand_id' => $brand, 'created_at' => now(), 'updated_at' => now()]);
        $alerts = fn () => \App\Models\Notification::where('user_id', $owner->id)->orderBy('id')->pluck('message')->all();

        // inside the panel Filament makes its guard the default one - the log's causer comes from it
        $this->actingAs($habiba, 'filament');
        auth()->shouldUse('filament');
        $request = \App\Models\InstallmentRequest::create(['applicant_name' => 'أحمد علي', 'applicant_phone' => '01000000001', 'installment_type' => 'امان',
            'machine_id' => $machine, 'months' => 24, 'status' => 'new_request', 'staff_id' => $habiba->id]);
        $this->assertSame([], $alerts(), 'uploading a request is not an alert');

        $request->update(['staff_id' => $yousef->id]);
        $request->update(['status' => 'paused', 'checks_report' => ['staff' => 'ناقص مفردات']]);
        $request->update(['applicant_phone' => '01000000002']);
        $request->update(['deposit' => 5000]); // the same burst of edits
        $got = $alerts();
        $this->assertSame("حبيبة نقل طلب #{$request->id} (أحمد علي) من حبيبة لـ يوسف", $got[0]);
        $this->assertSame("حبيبة غيّر حالة طلب #{$request->id} (أحمد علي) من طلب جديد لـ متوقف — ناقص مفردات", $got[1]);
        // the two edits right after each other are one alert
        $this->assertCount(3, $got);
        $this->assertStringContainsString("حبيبة عدّل في طلب #{$request->id} (أحمد علي): ", $got[2]);
        $this->assertStringContainsString('التليفون', $got[2]);
        // never to the parking accounts, never to the one who did it
        $this->assertSame(3, \App\Models\Notification::count());

        // the system's own move (no one logged in) says nothing
        auth('filament')->logout();
        $request->update(['staff_id' => $habiba->id]);
        $this->assertCount(3, $alerts());

        // the owner's own action is not sent to himself
        $this->actingAs($owner, 'filament');
        auth()->shouldUse('filament');
        $request->update(['status' => 'approved']);
        $this->assertCount(3, $alerts());
    }

    /** Owner 2026-11-26: he says «محمود», the system has «hefny» with mahmoud@gmail.com. */
    public function test_an_employee_is_found_by_login_email_or_number_and_is_in_her_staff_list(): void
    {
        $hefny = $this->staff(['name' => 'hefny', 'email' => 'mahmoud@gmail.com']);
        $this->requests(3, 'paused', $hefny->id);
        $this->requests(2, 'paused');

        $byEmail = \App\Domain\Asfoora\AsfooraTools::call('list_requests', ['staff' => 'mahmoud']);
        $this->assertSame(3, $byEmail['total_matching'] ?? null, json_encode(array_diff_key($byEmail, ['rows' => 1, 'requests' => 1])));
        $this->assertSame(3, \App\Domain\Asfoora\AsfooraTools::call('list_requests', ['staff' => '#'.$hefny->id])['total_matching'] ?? null);
        $this->assertStringContainsString("#{$hefny->id}: hefny - mahmoud@gmail.com", \App\Domain\Asfoora\AsfooraAgent::staffDirectory());
    }

    /** Owner 2026-11-27: «غيّر / حوّل / نزّل خصم» - she does it at once, as him, and never from a watch. */
    public function test_her_actions_change_statuses_move_requests_and_add_deductions_as_the_owner(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $owner = $this->staff(['is_hitler' => true, 'name' => 'المالك']);
        $from = $this->staff(['name' => 'beso', 'is_admin' => false, 'is_super_admin' => false]);
        $to = $this->staff(['name' => 'Mahmoud', 'is_admin' => false, 'is_super_admin' => false, 'monthly_salary' => 6000]);
        $this->requests(3, 'pending', $from->id);
        $ids = DB::table('installment_requests')->orderBy('id')->pluck('id')->all();
        $tools = \App\Domain\Asfoora\AsfooraTools::class;

        // no owner signed in (a background watch): refused, nothing changes
        $this->assertArrayHasKey('error', $tools::call('change_status', ['ids' => $ids, 'status' => 'approved']));
        $this->assertSame(3, DB::table('installment_requests')->where('status', 'pending')->count());

        $this->actingAs($owner, 'filament');

        $r = $tools::call('change_status', ['ids' => [$ids[0], $ids[1], 999999], 'status' => 'approved', 'reason' => 'ليمت 40000', 'reason_from' => 'aman']);
        $this->assertSame(2, $r['changed'] ?? null, json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame(2, $r['customers_messaged']);
        $this->assertSame([999999], $r['not_found']);
        $first = \App\Models\InstallmentRequest::find($ids[0]);
        $this->assertSame('approved', $first->status);
        $this->assertSame('ليمت 40000', $first->checks_report['aman'] ?? null);
        $this->assertSame($owner->id, (int) $first->status_updated_by);

        $m = $tools::call('transfer_requests', ['ids' => $ids, 'to_staff' => $to->id]);
        $this->assertSame(3, $m['moved']);
        $this->assertSame(['beso' => 3], $m['moved_from']);
        $this->assertSame(3, DB::table('installment_requests')->where('staff_id', $to->id)->count());

        $d = $tools::call('add_deduction', ['staff' => $to->id, 'amount' => 300, 'reason' => 'تأخير']);
        $this->assertSame(300.0, $d['amount']);
        $this->assertSame(1.5, $d['days']);
        $this->assertDatabaseHas('employee_deductions', ['staff_id' => $to->id, 'reason' => 'تأخير', 'created_by' => $owner->id]);
        $this->assertArrayHasKey('error', $tools::call('add_deduction', ['staff' => $from->id, 'amount' => 100, 'reason' => 'x'])); // no salary
    }

    public function test_aman_sheet_with_apply_changes_the_statuses_at_once(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $owner = $this->staff(['is_hitler' => true]);
        $this->actingAs($owner, 'filament');
        $this->requests(1, 'pending', $owner->id);
        $id = DB::table('installment_requests')->value('id');
        DB::table('installment_requests')->where('id', $id)->update(['applicant_name' => 'كريم عادل سيد', 'created_at' => '2026-11-05 10:00:00']);
        $path = $this->amanSheet([['كريم عادل سيد', 'موتوجيت', '26116830099', 'Rejected', '', '', '11/06/2026', 'سكور']]);
        $chat = AsfooraChat::create(['staff_id' => $owner->id]);
        $chat->messages()->create(['role' => 'user', 'content' => 'حدّث الحالات', 'meta' => ['file' => ['name' => 'aman.xlsx', 'path' => $path]]]);
        \App\Domain\Asfoora\AsfooraTools::$chatId = $chat->id;

        $r = \App\Domain\Asfoora\AsfooraTools::call('aman_sheet_changes', ['apply' => true]);
        $this->assertSame(1, $r['applied']['changed'] ?? null, json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame('rejected', DB::table('installment_requests')->where('id', $id)->value('status'));
    }

    public function test_his_monthly_sheet_with_titles_above_the_header_and_his_aman_statuses_is_read(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $owner = $this->staff(['is_hitler' => true]);
        $this->actingAs($owner, 'filament');
        $this->requests(4, 'new_request', $owner->id);
        $ids = DB::table('installment_requests')->orderBy('id')->pluck('id')->all();
        $names = ['كريم عادل سيد', 'سامي فؤاد علي', 'وليد جبر حسن', 'هاني صبري محمود'];
        foreach ($ids as $i => $id) {
            DB::table('installment_requests')->where('id', $id)->update(['applicant_name' => $names[$i], 'created_at' => '2026-11-05 10:00:00']);
        }
        $path = $this->amanSheet([
            ['كريم عادل سيد', 'موتوجيت', '26116830001', 'استعلام عمل', '', '', '11/06/2026', ''],
            ['سامي فؤاد علي', 'موتوجيت', '26116830002', 'Income proof / بيان دخل حديث و مختوم', '', '', '11/06/2026', ''],
            ['وليد جبر حسن', 'موتوجيت', '26116830003', 'استعلام منزل', '', '', '11/06/2026', ''],
            ['هاني صبري محمود', 'موتوجيت', '26116830004', 'مرحل للشهر التالي', '', '', '11/06/2026', ''],
        ], monthly: true);
        $chat = AsfooraChat::create(['staff_id' => $owner->id]);
        $chat->messages()->create(['role' => 'user', 'content' => 'حدّث الحالات', 'meta' => ['file' => ['name' => 'v12.xlsx', 'path' => $path]]]);
        \App\Domain\Asfoora\AsfooraTools::$chatId = $chat->id;

        $r = \App\Domain\Asfoora\AsfooraTools::call('aman_sheet_changes', ['apply' => true]);
        $this->assertSame(4, $r['rows_read'] ?? null, json_encode($r, JSON_UNESCAPED_UNICODE)); // the dashboard is not read as requests
        $this->assertSame(3, $r['applied']['changed'] ?? null, json_encode($r, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['work_check', 'paused_aman', 'pending', 'new_request'], DB::table('installment_requests')->orderBy('id')->pluck('status')->all());
        $this->assertStringContainsString('بيان دخل', json_encode(\App\Models\InstallmentRequest::find($ids[1])->checks_report, JSON_UNESCAPED_UNICODE));
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
