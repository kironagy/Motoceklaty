<?php

namespace Tests\Feature;

use App\Filament\Clusters\WebsiteSettings\Pages\GeneralSettings;
use App\Filament\Clusters\WebsiteSettings\Pages\HomeSettings;
use App\Filament\Clusters\WebsiteSettings\Resources\ContactMessageResource;
use App\Filament\Clusters\WebsiteSettings\Resources\PostResource;
use App\Filament\Clusters\WebsiteSettings\Resources\TeamMemberResource;
use App\Filament\Pages\AiCosts;
use App\Filament\Pages\AiHealth;
use App\Filament\Pages\BotInstructions;
use App\Filament\Pages\BotSettings;
use App\Filament\Pages\ConversationSimulatorPage;
use App\Filament\Pages\ReplyQualityPage;
use App\Filament\Resources\AdResource;
use App\Filament\Resources\AiTraceResource;
use App\Filament\Resources\BotLessonResource;
use App\Filament\Resources\BusinessMemoryResource;
use App\Filament\Resources\ClientReviewResource;
use App\Filament\Resources\GeminiApiKeyResource;
use App\Filament\Resources\SliderResource;
use App\Filament\Resources\TeachingCaseResource;
use App\Filament\Resources\TeachingChangeResource;
use App\Filament\Resources\WhatsappBotResource;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** AI controls and the website editor open for is_hitler staff only. */
class HitlerOnlyAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Staff isn't a FilamentUser; the panel lets it in only when app.env is
        // local - which is what the live server runs with.
        config(['app.env' => 'local']);
    }

    protected function urls(): array
    {
        return [
            AiCosts::getUrl(), AiHealth::getUrl(), BotInstructions::getUrl(), BotSettings::getUrl(),
            ConversationSimulatorPage::getUrl(), ReplyQualityPage::getUrl(),
            AiTraceResource::getUrl(), BotLessonResource::getUrl(), BusinessMemoryResource::getUrl(),
            GeminiApiKeyResource::getUrl(), TeachingCaseResource::getUrl(), TeachingChangeResource::getUrl(),
            WhatsappBotResource::getUrl(), WhatsappBotResource::getUrl('create'),
            GeneralSettings::getUrl(), HomeSettings::getUrl(),
            PostResource::getUrl(), PostResource::getUrl('create'), TeamMemberResource::getUrl(),
            ContactMessageResource::getUrl(), ClientReviewResource::getUrl(),
            SliderResource::getUrl(), AdResource::getUrl(),
        ];
    }

    public function test_a_super_admin_without_is_hitler_is_refused_everywhere(): void
    {
        $this->actingAs($this->staff(['is_bot' => true]), 'filament');

        foreach ($this->urls() as $url) {
            $this->get($url)->assertForbidden();
        }

        $menu = $this->get('/admin')->assertOk()->getContent();
        foreach (['تعليمات البوت', 'محاكي المحادثات', 'AI API Keys', 'بوتات واتساب', 'Website Settings', 'آراء العملاء'] as $label) {
            $this->assertStringNotContainsString($label, $menu);
        }
    }

    public function test_is_hitler_opens_every_page(): void
    {
        $this->actingAs($this->staff(['is_hitler' => true]), 'filament');

        foreach ($this->urls() as $url) {
            $this->get($url)->assertOk();
        }
    }

    protected function staff(array $flags): Staff
    {
        return Staff::create(['name' => 'S', 'email' => uniqid() . '@x.com', 'password' => 'secret',
            'is_admin' => true, 'is_super_admin' => true] + $flags);
    }
}
