<?php

namespace App\Domain\Forms;

use App\Models\Customer;
use App\Models\DynamicForm;
use App\Models\FormLink;
use App\Models\FormLinkAnswer;
use App\Models\FormLinkFile;
use App\Models\Machine;
use App\Models\Staff;
use App\Models\WhatsappBot;
use App\Models\WhatsappConversation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates and reuses customer form links (spec §4.3). One open link per conversation: asking
 * again gets the same link with what he already wrote; an expired one is renewed with every
 * answer and file carried over.
 */
class FormLinkService
{
    public static function formsMode(): bool
    {
        return config('agent.applications.mode', 'form_links') === 'form_links';
    }

    /**
     * The conversation's open link, or a new one on the bot's form. Prefill (only these three,
     * owner 2026-10-18): customer_name, machine_id, months - each only into a field still empty.
     *
     * @param  array{customer_name?: ?string, machine_id?: ?int, months?: ?int}  $prefill
     *
     * @throws FormLinkException NO_FORM when no form is published
     */
    public function linkFor(WhatsappConversation $conversation, array $prefill = [], ?Staff $staff = null, ?DynamicForm $form = null): FormLink
    {
        return DB::transaction(function () use ($conversation, $prefill, $staff, $form) {
            $existing = FormLink::where('whatsapp_conversation_id', $conversation->id)
                ->where('purpose', 'apply')
                ->whereIn('status', FormLink::OPEN_STATUSES)
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($existing && $existing->isOpen() && ($form === null || $existing->version->dynamic_form_id === $form->id)) {
                $this->applyPrefill($existing, $prefill);

                return $existing;
            }

            $form ??= DynamicForm::forBot();

            if (! $form || ! $form->published_version_id) {
                throw new FormLinkException('NO_FORM', 'No published application form.');
            }

            $link = FormLink::create([
                'token' => Str::random(40),
                'dynamic_form_version_id' => $form->published_version_id,
                'customer_id' => $conversation->customer_id,
                'whatsapp_conversation_id' => $conversation->id,
                'whatsapp_phone' => explode(':', (string) ($conversation->real_phone ?: $conversation->phone))[0],
                'created_by_type' => $staff ? 'staff' : 'bot',
                'created_by_staff_id' => $staff?->id,
                'prefill' => array_filter($prefill, fn ($v) => filled($v)),
                'status' => 'sent',
                'expires_at' => now()->addDays((int) config('agent.form_links.expires_after_days', 7)),
            ]);

            // An expired (or other-form) link: everything he wrote and uploaded comes along.
            if ($existing) {
                $this->carryOver($existing, $link);
                $existing->update(['status' => $existing->isOpen() ? 'cancelled' : 'expired']);
                $link->update(['renewed_from_id' => $existing->id]);
            } elseif ($previous = FormLink::where('whatsapp_conversation_id', $conversation->id)->where('purpose', 'apply')
                ->whereIn('status', ['cancelled', 'expired'])->latest('id')->first()) {
                // live 2026-10-19: staff cancelled his link and he asked again - he does not start over
                $this->carryOver($previous, $link);
                $link->update(['renewed_from_id' => $previous->id]);
            }

            $this->applyPrefill($link, $prefill);

            return $link;
        });
    }

    /**
     * Staff open a link for a phone number: the conversation is found or made on the chosen bot
     * the same way an incoming message would make it, so receipts and follow-ups work.
     */
    public function linkForPhone(WhatsappBot $bot, string $phone, Staff $staff, array $prefill = [], ?DynamicForm $form = null): FormLink
    {
        $digits = app(\App\Support\EgyptianNationalId::class)->normalizeDigits($phone);
        $international = str_starts_with($digits, '0') ? '2'.$digits : $digits;

        $customer = Customer::firstOrCreate(
            ['whatsapp_bot_id' => $bot->id, 'jid' => $international.'@s.whatsapp.net'],
            ['phone' => $international],
        );

        $conversation = WhatsappConversation::firstOrCreate(
            ['whatsapp_bot_id' => $bot->id, 'phone' => $international],
            ['status' => 'open', 'customer_id' => $customer->id],
        );

        if (! $conversation->customer_id) {
            $conversation->update(['customer_id' => $customer->id]);
        }

        return $this->linkFor($conversation, $prefill, $staff, $form);
    }

    /**
     * Staff paused a submitted request asking him to fix data: the same answers, editable again,
     * with the staff's reason on top. Its submit updates the same request.
     */
    public function reopenForFix(FormLink $submitted, string $reason): FormLink
    {
        return DB::transaction(function () use ($submitted, $reason) {
            FormLink::where('application_id', $submitted->application_id)->where('purpose', 'fix')
                ->whereIn('status', FormLink::OPEN_STATUSES)->update(['status' => 'cancelled']);

            $link = FormLink::create([
                'token' => Str::random(40),
                'dynamic_form_version_id' => $submitted->dynamic_form_version_id,
                'customer_id' => $submitted->customer_id,
                'whatsapp_conversation_id' => $submitted->whatsapp_conversation_id,
                'whatsapp_phone' => $submitted->whatsapp_phone,
                'created_by_type' => 'staff',
                'status' => 'sent',
                'purpose' => 'fix',
                'staff_note' => $reason,
                'renewed_from_id' => $submitted->id,
                'application_id' => $submitted->application_id,
                'installment_request_id' => $submitted->installment_request_id,
                'expires_at' => now()->addDays((int) config('agent.form_links.expires_after_days', 7)),
            ]);

            $this->carryOver($submitted, $link);

            return $link;
        });
    }

    /** Marks links past their date expired. */
    public function expireStale(): int
    {
        return FormLink::whereIn('status', FormLink::OPEN_STATUSES)
            ->whereNotNull('expires_at')->where('expires_at', '<', now())
            ->update(['status' => 'expired']);
    }

    /** A real person on the page (the JS heartbeat, never WhatsApp's preview fetch). */
    public function touch(FormLink $link, bool $wroteSomething = false): void
    {
        $changes = ['last_activity_at' => now()];

        if (! $link->first_opened_at) {
            $changes['first_opened_at'] = now();
        }

        if ($link->status === 'sent') {
            $changes['status'] = 'opened';
        }

        if ($wroteSomething && in_array($link->status, ['sent', 'opened'], true)) {
            $changes['status'] = 'in_progress';
        }

        $link->update($changes);
    }

    /** @return array<string, int> document field key => files uploaded */
    public function fileCounts(FormLink $link): array
    {
        return FormLinkFile::where('form_link_id', $link->id)
            ->selectRaw('field_key, count(*) as n')->groupBy('field_key')
            ->pluck('n', 'field_key')->map(fn ($n) => (int) $n)->all();
    }

    /** @return array{done: int, total: int, missing: string[], percent: int} */
    public function progress(FormLink $link): array
    {
        $progress = $link->version->formSchema()->progress($link->answerValues(), $this->fileCounts($link));

        return $progress + ['percent' => $progress['total'] > 0 ? (int) round(100 * $progress['done'] / $progress['total']) : 0];
    }

    /** The best offer's plan for these months (BestOfferService), as the bot quotes it. */
    public function planIdFor(Machine $machine, int $months, ?int $customerTypeId = null): ?int
    {
        return app(\App\Domain\Installments\BestOfferService::class)->bestPlan($machine, $months, $customerTypeId)?->id;
    }

    /**
     * What the bot already knows goes into the form, each only into a field still empty and
     * only when it passes the field's own rules (owner 2026-10-19: the most the chat can give):
     * his name and WhatsApp number (when he applies for himself), his job option, workplace and
     * income, the machine and the months.
     *
     * @param  array{customer_name?: ?string, phone?: ?string, applicant_is_other?: bool, customer_type?: ?string,
     *               work_type?: ?string, workplace_name?: ?string, monthly_income?: ?float, machine_id?: ?int, months?: ?int}  $prefill
     */
    private function applyPrefill(FormLink $link, array $prefill): void
    {
        $schema = $link->version->formSchema();
        $answers = $link->answerValues();
        $values = [];
        $bound = fn (string $binding) => $schema->fieldBoundTo($binding);
        $forHimself = ! ($prefill['applicant_is_other'] ?? false);

        if ($forHimself && filled($prefill['customer_name'] ?? null) && ($field = $bound('applicant.name'))) {
            $values[$field['key']] = trim((string) $prefill['customer_name']);
        }

        // the number he is chatting from - the form's own check decides if it is a real Egyptian mobile
        if ($forHimself && filled($prefill['phone'] ?? null) && ($field = $bound('applicant.phone'))) {
            $values[$field['key']] = $this->localPhone((string) $prefill['phone']);
        }

        // his job: the one option of that customer type, or the one whose work kinds match his
        $customerTypeId = null;

        if (filled($prefill['customer_type'] ?? null) && $prefill['customer_type'] !== 'unknown'
            && ($field = $bound('work.status'))
            && ($customerTypeId = \App\Models\CustomerType::where('key', $prefill['customer_type'])->value('id'))) {
            $candidates = array_values(array_filter($field['options'], fn ($o) => (int) ($o['customer_type_id'] ?? 0) === (int) $customerTypeId));

            if (count($candidates) > 1) {
                $candidates = array_values(array_filter($candidates, fn ($o) => in_array($prefill['work_type'] ?? 'none', (array) ($o['work_types'] ?? []), true)));
            }

            if (count($candidates) === 1) {
                $values[$field['key']] = $candidates[0]['value'];
            }
        }

        if (filled($prefill['workplace_name'] ?? null) && ($field = $bound('work.place_name'))) {
            $values[$field['key']] = trim((string) $prefill['workplace_name']);
        }

        if (($prefill['monthly_income'] ?? 0) > 0) {
            $binding = ($prefill['customer_type'] ?? null) === 'pension' ? 'work.pension_amount' : 'work.salary_amount';

            if ($field = $bound($binding)) {
                $values[$field['key']] = (string) round((float) $prefill['monthly_income']);
            }
        }

        // An unknown machine or months stay empty: he picks them on the form.
        $machine = filled($prefill['machine_id'] ?? null)
            ? Machine::where('is_active', true)->find((int) $prefill['machine_id'])
            : null;

        if ($machine && ($field = $bound('request.machine'))) {
            $values[$field['key']] = $machine->id;

            if (filled($prefill['months'] ?? null) && ($planField = $bound('request.plan'))
                && ($planId = $this->planIdFor($machine, (int) $prefill['months'], $customerTypeId ? (int) $customerTypeId : null))) {
                $values[$planField['key']] = $planId;
            }
        }

        foreach ($values as $key => $value) {
            $field = $schema->field($key);

            if ($field && $schema->isEmpty($field, $answers[$key] ?? null) && $schema->validateValue($field, $value) === null) {
                FormLinkAnswer::updateOrCreate(['form_link_id' => $link->id, 'field_key' => $key], ['value' => $value]);
            }
        }
    }

    /** 01xxxxxxxxx from 2010..., +2010..., 010... */
    private function localPhone(string $phone): string
    {
        $digits = app(\App\Support\EgyptianNationalId::class)->normalizeDigits($phone);

        return str_starts_with($digits, '20') && strlen($digits) === 12 ? substr($digits, 1) : $digits;
    }

    private function carryOver(FormLink $from, FormLink $to): void
    {
        foreach ($from->answers as $answer) {
            FormLinkAnswer::updateOrCreate(['form_link_id' => $to->id, 'field_key' => $answer->field_key], ['value' => $answer->value]);
        }

        foreach ($from->files as $file) {
            $to->files()->create($file->only(['field_key', 'media_id', 'check_status', 'detected_type_key', 'extracted', 'issues', 'message']));
        }
    }
}
