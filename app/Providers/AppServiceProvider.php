<?php

namespace App\Providers;

use App\Agent\Providers\AiProvider;
use App\Agent\Providers\GeminiProvider;
use App\Agent\Runtime\DisabledTurnProcessor;
use App\Agent\Runtime\TurnProcessor;
use App\Domain\Conversations\GeminiVoiceTranscriber;
use App\Domain\Conversations\TurnScheduler;
use App\Domain\Conversations\TurnSchedulerHook;
use App\Domain\Conversations\VoiceTranscriber;
use App\Domain\Documents\GoogleVisionOcr;
use App\Domain\Documents\OcrProvider;
use App\Models\InstallmentRequest;
use App\Models\InstallmentSystem;
use App\Models\Machine;
use App\Models\RequirementField;
use App\Observers\InstallmentRequestObserver;
use App\Observers\InstallmentSystemObserver;
use App\Observers\MachineObserver;
use App\Observers\RequirementFieldObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AiProvider::class, GeminiProvider::class);
        $this->app->bind(VoiceTranscriber::class, GeminiVoiceTranscriber::class);
        $this->app->bind(OcrProvider::class, GoogleVisionOcr::class);
        $this->app->bind(TurnSchedulerHook::class, TurnScheduler::class);
        $this->app->bind(TurnProcessor::class, function ($app) {
            return config('agent.enabled')
                ? $app->make(\App\Agent\Runtime\AgentTurnProcessor::class)
                : $app->make(DisabledTurnProcessor::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \App\Domain\Settings\AgentSettings::apply();

        InstallmentRequest::observe(InstallmentRequestObserver::class);
        Machine::observe(MachineObserver::class);
        RequirementField::observe(RequirementFieldObserver::class);
        InstallmentSystem::observe(InstallmentSystemObserver::class);
    }
}
