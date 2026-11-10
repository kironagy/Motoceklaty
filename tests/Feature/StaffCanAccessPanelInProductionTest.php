<?php

namespace Tests\Feature;

use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Server 2026-11-10: APP_ENV went local -> production and every dashboard user got 403,
 * because Filament only lets a model that implements FilamentUser in outside local.
 */
class StaffCanAccessPanelInProductionTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_opens_the_dashboard_when_app_env_is_production(): void
    {
        config(['app.env' => 'production']);
        $staff = Staff::create(['name' => 'S', 'email' => uniqid().'@x.com', 'password' => 'secret', 'is_admin' => true, 'is_super_admin' => true]);

        $this->actingAs($staff, 'filament')->get('/admin')->assertOk();
    }
}
