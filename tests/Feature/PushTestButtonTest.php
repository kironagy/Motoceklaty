<?php

namespace Tests\Feature;

use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Owner 2026-11-27: «جرّب الإشعار على الجهاز ده» - only a device of his own, never a stranger's endpoint. */
class PushTestButtonTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_test_push_needs_a_login_and_his_own_device(): void
    {
        $this->postJson(route('push-subscriptions.test'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/x'])->assertStatus(401);

        $me = Staff::create(['name' => 'S', 'email' => uniqid().'@x.test', 'password' => 'secret']);
        $other = Staff::create(['name' => 'O', 'email' => uniqid().'@x.test', 'password' => 'secret']);
        \App\Models\PushSubscription::create(['user_id' => $other->id, 'endpoint' => 'https://fcm.googleapis.com/fcm/send/other', 'public_key' => 'k', 'auth_token' => 'a']);

        $this->actingAs($me, 'filament')
            ->postJson(route('push-subscriptions.test'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/other'])
            ->assertOk()->assertJson(['ok' => false, 'reason' => 'not_subscribed']);
    }

    public function test_the_listener_and_the_button_are_on_the_pages(): void
    {
        $me = Staff::create(['name' => 'S', 'email' => uniqid().'@x.test', 'password' => 'secret', 'is_admin' => true, 'is_super_admin' => true]);
        $this->actingAs($me, 'filament')->get('/admin/notifications')->assertOk()
            ->assertSee('mg-push-test-btn', false)->assertSee('__mgPushListener', false);
    }
}
