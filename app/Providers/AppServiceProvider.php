<?php

namespace App\Providers;

use App\Models\InstallmentRequest;
use App\Observers\InstallmentRequestObserver;
use App\Services\Ocr\GoogleVisionClient;
use App\Services\Ocr\OcrProviderInterface;
use App\Services\Ocr\PaddleOcrClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(OcrProviderInterface::class, function () {
            return config('ocr.driver') === 'google_vision'
                ? new GoogleVisionClient()
                : new PaddleOcrClient();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        InstallmentRequest::observe(InstallmentRequestObserver::class);
    }
}
