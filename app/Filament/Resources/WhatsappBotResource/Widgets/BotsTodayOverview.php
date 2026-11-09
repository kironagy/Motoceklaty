<?php

namespace App\Filament\Resources\WhatsappBotResource\Widgets;

use App\Filament\Resources\WhatsappBotResource;
use App\Models\WhatsappBot;
use Filament\Widgets\Widget;

/** Top of the bots page: active / connected bots and today's chats and messages across all bots. */
class BotsTodayOverview extends Widget
{
    protected static string $view = 'filament.resources.whatsapp-bots.overview';

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = '60s';

    protected function getViewData(): array
    {
        $totals = WhatsappBotResource::todayTotals();
        $bots = WhatsappBot::query()->get(['id', 'name', 'is_active', 'session_status']);
        $busiest = collect($totals)->sortByDesc('conversations')->keys()->first();

        return [
            'active' => $bots->where('is_active', true)->count(),
            'connected' => $bots->where('session_status', 'connected')->count(),
            'total' => $bots->count(),
            'conversations' => array_sum(array_column($totals, 'conversations')),
            'messages' => array_sum(array_column($totals, 'messages')),
            'busiest' => $busiest ? [
                'name' => $bots->firstWhere('id', $busiest)?->name,
                'conversations' => $totals[$busiest]['conversations'],
                'url' => WhatsappBotResource::getUrl('activity', ['record' => $busiest]),
            ] : null,
        ];
    }
}
