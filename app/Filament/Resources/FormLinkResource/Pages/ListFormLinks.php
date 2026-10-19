<?php

namespace App\Filament\Resources\FormLinkResource\Pages;

use App\Domain\Forms\FormLinkService;
use App\Filament\Resources\FormLinkResource;
use App\Models\FormLink;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Dynamic Links: every link sent, as a funnel (sent → opened → filling → sent the form) that
 * doubles as the filter, and one row per customer saying how far he got and what is left.
 */
class ListFormLinks extends Page
{
    use WithPagination;

    protected static string $resource = FormLinkResource::class;

    protected static string $view = 'filament.resources.form-links.board';

    protected ?string $maxContentWidth = 'full';

    #[Url]
    public string $stage = 'all';

    #[Url]
    public string $search = '';

    public function getTitle(): string
    {
        return 'روابط التقديم';
    }

    protected function getHeaderActions(): array
    {
        return [FormLinkResource::createAction()];
    }

    public function updatedStage(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    private function base(): Builder
    {
        return FormLink::query()->whereIn('purpose', ['apply', 'fix']);
    }

    /** The funnel: each stage counts everyone who reached it. */
    public function funnel(): array
    {
        $since = now()->subDays(30);
        $q = fn () => $this->base()->where('created_at', '>=', $since);

        return [
            ['key' => 'all', 'label' => 'اتبعت', 'count' => $q()->count(), 'hint' => 'آخر 30 يوم'],
            ['key' => 'opened', 'label' => 'اتفتح', 'count' => $q()->whereNotNull('first_opened_at')->count(), 'hint' => 'فتح الرابط'],
            ['key' => 'filling', 'label' => 'بدأ يملا', 'count' => $q()->whereIn('status', ['in_progress', 'submitted'])->count(), 'hint' => 'كتب أو رفع حاجة'],
            ['key' => 'submitted', 'label' => 'بعت الطلب', 'count' => $q()->where('status', 'submitted')->count(), 'hint' => 'وصل للموظفين'],
        ];
    }

    /** Filters that are not funnel stages. */
    public function extraStages(): array
    {
        return [
            'waiting' => ['label' => 'لسه مفتحش', 'count' => $this->base()->where('status', 'sent')->count()],
            'stuck' => ['label' => 'وقف في النص', 'count' => $this->base()->where('status', 'in_progress')->where('last_activity_at', '<', now()->subHour())->count()],
            'closed' => ['label' => 'انتهى / اتلغى', 'count' => $this->base()->whereIn('status', ['expired', 'cancelled'])->count()],
        ];
    }

    public function rows()
    {
        $query = $this->base()->with(['version.form', 'createdByStaff', 'conversation.customer'])->latest('id');

        match ($this->stage) {
            'opened' => $query->whereNotNull('first_opened_at'),
            'filling' => $query->whereIn('status', ['in_progress', 'submitted']),
            'submitted' => $query->where('status', 'submitted'),
            'waiting' => $query->where('status', 'sent'),
            'stuck' => $query->where('status', 'in_progress')->where('last_activity_at', '<', now()->subHour()),
            'closed' => $query->whereIn('status', ['expired', 'cancelled']),
            default => null,
        };

        if (($term = trim($this->search)) !== '') {
            $digits = preg_replace('/\D/', '', strtr($term, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']));
            $query->where(fn ($q) => $q->where('whatsapp_phone', 'like', '%'.ltrim($digits ?: $term, '0').'%')
                ->orWhere('installment_request_id', $digits ?: 0));
        }

        $page = $query->paginate(20);
        $service = app(FormLinkService::class);

        $page->getCollection()->transform(function (FormLink $link) use ($service) {
            $progress = $link->status === 'submitted' ? ['percent' => 100, 'missing' => []] : $service->progress($link);
            $answers = $link->answerValues();
            $nameField = $link->version->formSchema()->fieldBoundTo('applicant.name');

            $link->setAttribute('ui', [
                'name' => $nameField ? ($answers[$nameField['key']] ?? null) : null,
                'phone' => $this->localPhone((string) $link->whatsapp_phone),
                'percent' => $progress['percent'],
                'missing' => array_slice($progress['missing'], 0, 3),
                'missing_more' => max(0, count($progress['missing']) - 3),
                'warnings' => $link->files()->where('check_status', 'warning')->count(),
            ]);

            return $link;
        });

        return $page;
    }

    private function localPhone(string $phone): string
    {
        return str_starts_with($phone, '20') && strlen($phone) === 12 ? '0'.substr($phone, 2) : $phone;
    }

    public function viewUrl(FormLink $link): string
    {
        return FormLinkResource::getUrl('view', ['record' => $link]);
    }
}
