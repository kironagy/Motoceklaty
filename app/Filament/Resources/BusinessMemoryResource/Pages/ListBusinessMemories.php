<?php

namespace App\Filament\Resources\BusinessMemoryResource\Pages;

use App\Domain\Knowledge\KnowledgeService;
use App\Filament\Resources\BusinessMemoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\HtmlString;

class ListBusinessMemories extends ListRecords
{
    protected static string $resource = BusinessMemoryResource::class;

    public function getSubheading(): ?string
    {
        return 'معلومات عن شغل المعرض البوت بيرجعلها (سياسات، طريقة الكلام، شرح المستندات). '
            .'المثبتة بتتبعت مع كل رسالة: '.BusinessMemoryResource::pinnedBudgetText(null).'.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('preview')
                ->label('البوت شايف إيه؟')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->modalHeading('المعرفة زي ما بتوصل للبوت في كل رسالة')
                ->modalDescription('المثبتة بتوصل كاملة. الباقي بيوصل عنوانه بس، والبوت بيفتح المحتوى لو احتاجه.')
                ->modalSubmitAction(false)
                ->modalContent(function () {
                    $knowledge = app(KnowledgeService::class);
                    $pinned = $knowledge->pinned()->map(fn ($m) => "### {$m->title}\n{$m->content}")->implode("\n\n");
                    $index = $knowledge->index()->map(fn ($title, $key) => "{$key}: {$title}")->implode("\n");

                    return new HtmlString(
                        '<div dir="rtl" style="white-space: pre-wrap; line-height: 1.9; font-size: 0.9rem">'
                        .'<h3 style="font-weight:700">مثبتة</h3>'.e($pinned ?: '—')
                        .'<h3 style="font-weight:700; margin-top:1rem">قايمة العناوين (عند الحاجة)</h3>'.e($index ?: '—')
                        .'</div>'
                    );
                }),
            Actions\CreateAction::make()->label('معلومة جديدة'),
        ];
    }
}
