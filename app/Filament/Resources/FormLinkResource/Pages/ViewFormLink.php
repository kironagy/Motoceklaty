<?php

namespace App\Filament\Resources\FormLinkResource\Pages;

use App\Domain\Forms\FormBindings;
use App\Domain\Forms\FormLinkService;
use App\Filament\Resources\FormLinkResource;
use App\Models\FormLink;
use App\Models\FormLinkFile;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/** One link: every answer he wrote (sent or not), his uploads and what the AI said about each. */
class ViewFormLink extends ViewRecord
{
    protected static string $resource = FormLinkResource::class;

    protected static string $view = 'filament.resources.form-links.view';

    public function getTitle(): string
    {
        return 'رابط التقديم';
    }

    public function getHeading(): string
    {
        return '';
    }

    public function applicantName(): ?string
    {
        $field = $this->record->version->formSchema()->fieldBoundTo('applicant.name');

        return $field ? ($this->record->answerValues()[$field['key']] ?? null) : null;
    }

    public function phone(): string
    {
        $phone = (string) $this->record->whatsapp_phone;

        return str_starts_with($phone, '20') && strlen($phone) === 12 ? '0'.substr($phone, 2) : $phone;
    }

    /** Sections with each field's answer, for the view. */
    public function getDetails(): array
    {
        /** @var FormLink $link */
        $link = $this->record;
        $schema = $link->version->formSchema();
        $answers = $link->answerValues();
        $visible = $schema->visible($answers);
        $files = FormLinkFile::where('form_link_id', $link->id)->with('media')->orderBy('id')->get()->groupBy('field_key');
        $sections = [];

        foreach ($schema->sections() as $section) {
            $rows = [];

            foreach ($section['items'] as $item) {
                if (in_array($item['type'], ['note', 'stop'], true)) {
                    continue;
                }

                $rows[] = [
                    'label' => $item['label'],
                    'binding' => FormBindings::get($item['binding'])['label'] ?? null,
                    'required' => $item['required'],
                    'shown' => isset($visible[$item['key']]),
                    'value' => $this->display($item, $answers[$item['key']] ?? null),
                    'error' => in_array($item['type'], \App\Domain\Forms\FormSchema::FILE_TYPES, true) ? null : $schema->validateValue($item, $answers[$item['key']] ?? null),
                    'files' => ($files[$item['key']] ?? collect())->map(fn (FormLinkFile $f) => [
                        'url' => $f->media ? route('staff.media.show', $f->media) : null,
                        'is_image' => str_starts_with((string) $f->media?->mime, 'image/'),
                        'status' => $f->check_status,
                        'message' => $f->message,
                    ])->all(),
                    'is_document' => in_array($item['type'], \App\Domain\Forms\FormSchema::FILE_TYPES, true),
                ];
            }

            $sections[] = ['title' => $section['title'], 'rows' => $rows];
        }

        return [
            'sections' => $sections,
            'progress' => app(FormLinkService::class)->progress($link),
            'blocking' => $schema->blockingMessages($answers),
        ];
    }

    private function display(array $item, mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return match ($item['type']) {
            'select', 'radio' => collect($item['options'])->firstWhere('value', $value)['label'] ?? (string) $value,
            'checkbox' => collect($item['options'])->whereIn('value', (array) $value)->pluck('label')->implode('، '),
            'yes_no' => $value === 'yes' ? 'نعم' : 'لا',
            'group' => \App\Domain\Forms\FormSchema::groupText($item, $value),
            'address' => FormBindings::joinAddress((array) $value),
            'machine' => \App\Models\Machine::find((int) $value)?->name,
            'plan' => ($plan = \App\Models\InstallmentPlan::with('installmentSystem')->find((int) $value)) ? $plan->months.' شهر - '.$plan->installmentSystem?->name : null,
            default => is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE),
        };
    }

    protected function getHeaderActions(): array
    {
        $link = $this->record;

        return [
            Actions\Action::make('open')->label('افتح')->icon('heroicon-o-arrow-top-right-on-square')->color('gray')
                ->url($link->url(), shouldOpenInNewTab: true),
            Actions\Action::make('resend')->label('ابعته تاني')->icon('heroicon-o-paper-airplane')
                ->visible(fn () => $link->isOpen())
                ->disabled(fn () => ! FormLinkResource::canSendFrom($link))
                ->tooltip(fn () => FormLinkResource::canSendFrom($link) ? null : 'رقم الواتساب بتاع المحادثة دي مش متوصل (أو محادثة من المحاكي) - انسخ الرابط وابعته بنفسك')
                ->requiresConfirmation()
                ->action(function () use ($link) {
                    $problem = FormLinkResource::sendToCustomer($link, 'ده رابط طلب التقسيط بتاعك، كمّل منه 👇');

                    $problem === null
                        ? Notification::make()->title('اتبعت')->success()->send()
                        : Notification::make()->title('متبعتش')->body($problem)->danger()->persistent()->send();
                }),
            // owner 2026-10-19: a cancelled or expired link can be switched back on, with what he wrote
            Actions\Action::make('reactivate')->label('شغّل الرابط تاني')->icon('heroicon-o-arrow-path')->color('success')
                ->visible(fn () => in_array($link->status, ['cancelled', 'expired'], true) && $link->purpose !== 'preview')
                ->requiresConfirmation()
                ->modalDescription('الرابط هيرجع يشتغل 7 أيام بكل اللي العميل كتبه. لو فيه رابط تاني شغال لنفس العميل هيتقفل.')
                ->action(function () use ($link) {
                    FormLink::where('whatsapp_conversation_id', $link->whatsapp_conversation_id)->whereKeyNot($link->id)
                        ->where('purpose', $link->purpose)->whereIn('status', FormLink::OPEN_STATUSES)->update(['status' => 'cancelled']);

                    $link->update([
                        'status' => $link->last_activity_at ? 'in_progress' : ($link->first_opened_at ? 'opened' : 'sent'),
                        'expires_at' => now()->addDays(7),
                        'followups_sent' => 0,
                        'last_followup_at' => null,
                    ]);

                    Notification::make()->title('الرابط رجع يشتغل لحد '.$link->expires_at->format('Y-m-d'))->success()->send();
                    $this->redirect(FormLinkResource::getUrl('view', ['record' => $link]));
                }),
            Actions\Action::make('extend')->label('مدّ 7 أيام')->icon('heroicon-o-clock')->color('gray')
                ->visible(fn () => $link->isOpen())
                ->action(function () use ($link) {
                    $link->update([
                        'expires_at' => now()->addDays(7),
                        'status' => $link->status === 'expired' ? ($link->last_activity_at ? 'in_progress' : 'sent') : $link->status,
                    ]);
                    Notification::make()->title('الرابط شغال لحد '.$link->expires_at->format('Y-m-d'))->success()->send();
                }),
            Actions\Action::make('cancel')->label('إلغاء الرابط')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn () => $link->isOpen())
                ->requiresConfirmation()
                ->action(function () use ($link) {
                    $link->update(['status' => 'cancelled']);
                    $this->redirect(FormLinkResource::getUrl('view', ['record' => $link]));
                }),
        ];
    }
}
