<?php

namespace App\Filament\Clusters\WebsiteSettings\Resources\NewsletterSubscriberResource\Pages;

use App\Filament\Clusters\WebsiteSettings\Resources\NewsletterSubscriberResource;
use App\Models\NewsletterSubscriber;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageNewsletterSubscribers extends ManageRecords
{
    protected static string $resource = NewsletterSubscriberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('export')
                ->label('تصدير CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => response()->streamDownload(function () {
                    $out = fopen('php://output', 'w');
                    fputcsv($out, ['email', 'subscribed_at']);
                    NewsletterSubscriber::orderBy('created_at')->each(fn ($s) => fputcsv($out, [$s->email, $s->created_at]));
                    fclose($out);
                }, 'newsletter-subscribers.csv')),
            Actions\CreateAction::make(),
        ];
    }
}
