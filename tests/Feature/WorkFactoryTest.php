<?php

namespace Tests\Feature;

use App\Filament\Pages\WorkFactory;
use App\Models\Staff;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkFactoryTest extends TestCase
{
    public function test_worker_session_is_always_derived_from_authenticated_staff(): void
    {
        $staff = new Staff(['name' => 'Owner', 'is_hitler' => true]); $staff->id = 42;
        auth('filament')->setUser($staff);
        Http::fake(['*' => Http::response(['status' => 'disconnected', 'count' => 0])]);
        $page = new WorkFactory;
        $page->connection = ['staff_id' => 99];
        $page->refreshConnection();
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sessions/staff_42'));
        $this->assertSame(0, $page->connection['count']);
    }

    public function test_page_requires_its_own_view_permission(): void
    {
        $staff = new Staff(['permissions' => ['WorkFactory' => ['view']]]); $staff->id = 42;
        auth('filament')->setUser($staff); $this->assertTrue(WorkFactory::canAccess());
        $staff->permissions = []; $this->assertFalse(WorkFactory::canAccess());
    }

    public function test_split_download_returns_zip_from_the_current_users_contacts(): void
    {
        $staff = new Staff(['is_hitler' => true]); $staff->id = 42;
        auth('filament')->setUser($staff);
        Http::fake(['*' => Http::response(['rows' => array_map(fn ($i) => ['phone' => '20123456789'.$i, 'name' => 'عميل'], range(0, 2))])]);
        $response = (new WorkFactory)->download(2);
        $this->assertSame('application/zip', $response->headers->get('Content-Type'));
        ob_start(); $response->sendContent(); $bytes = ob_get_clean();
        $file = tempnam(sys_get_temp_dir(), 'factory-zip-');
        try {
            file_put_contents($file, $bytes); $zip = new \ZipArchive;
            $this->assertTrue($zip->open($file)); $this->assertSame(2, $zip->numFiles);
            $this->assertNotFalse($zip->locateName('team-1-2.xlsx'));
            $this->assertNotFalse($zip->locateName('team-2-1.xlsx')); $zip->close();
        } finally { @unlink($file); }
    }
}
