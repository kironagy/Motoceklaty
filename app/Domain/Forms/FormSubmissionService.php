<?php

namespace App\Domain\Forms;

use App\Domain\Applications\LegacyRequestProjector;
use App\Domain\Conversations\DeliveryService;
use App\Models\Application;
use App\Models\ApplicationData;
use App\Models\ApplicationDocument;
use App\Models\ApplicationEvent;
use App\Models\CustomerType;
use App\Models\DocumentType;
use App\Models\FormLink;
use App\Models\FormLinkFile;
use App\Models\InstallmentPlan;
use App\Models\InstallmentRequest;
use App\Models\Machine;
use App\Models\WhatsappBot;
use App\Support\EgyptianNationalId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Sends a filled form (spec §4.5): an Application and the same installment_requests row a bot
 * request makes - same staff, same tab, same status messages - then the receipt on WhatsApp.
 * A "fix" link updates the request staff paused and sends it back to where they had it.
 */
class FormSubmissionService
{
    /** A request in one of these still waits for a decision: the same person cannot apply again. */
    public const UNDECIDED_STATUSES = ['new', 'new_request', 'pending', 'work_check', 'paused', 'transferred'];

    public function __construct(
        private readonly FormLinkService $links,
        private readonly DeliveryService $delivery,
        private readonly EgyptianNationalId $nationalIds,
    ) {
    }

    /**
     * @return array{ok: bool, code?: string, errors?: array<string, string>, blocking?: string[], message?: string, request_id?: int}
     */
    public function submit(FormLink $link): array
    {
        $result = DB::transaction(function () use ($link) {
            $link = FormLink::whereKey($link->id)->lockForUpdate()->firstOrFail();

            // a double tap, or the page sent twice: the first one counts
            if ($link->status === 'submitted') {
                return ['ok' => true, 'request_id' => $link->installment_request_id, 'already' => true];
            }

            if ($link->purpose === 'preview') {
                $errors = $link->version->formSchema()->submitErrors($link->answerValues(), $this->links->fileCounts($link));

                return $errors['fields'] !== [] || $errors['blocking'] !== []
                    ? ['ok' => false, 'code' => 'INVALID', 'errors' => $errors['fields'], 'blocking' => $errors['blocking']]
                    : ['ok' => false, 'code' => 'PREVIEW', 'message' => 'دي معاينة: كل حاجة تمام، ومفيش طلب اتبعت.'];
            }

            if (! $link->isOpen()) {
                return ['ok' => false, 'code' => 'LINK_CLOSED', 'message' => 'الرابط ده انتهى. كلّمنا على الواتساب ونبعتلك رابط جديد.'];
            }

            $schema = $link->version->formSchema();
            $answers = $link->answerValues();
            $errors = $schema->submitErrors($answers, $this->links->fileCounts($link));

            if ($errors['fields'] !== [] || $errors['blocking'] !== []) {
                return ['ok' => false, 'code' => 'INVALID', 'errors' => $errors['fields'], 'blocking' => $errors['blocking']];
            }

            $visible = $schema->visible($answers);
            $columns = $this->columns($schema, $answers, $visible);

            if ($duplicate = $this->undecidedRequest($link, $columns)) {
                return ['ok' => false, 'code' => 'DUPLICATE', 'message' => "عندك طلب لسه تحت المراجعة (رقم #{$duplicate->id}). هنكلمك عليه أول ما يطلع الرد."];
            }

            return $link->purpose === 'fix'
                ? $this->resubmitFix($link, $schema, $answers, $visible, $columns)
                : $this->createRequest($link, $schema, $answers, $visible, $columns);
        });

        if (($result['ok'] ?? false) && ! ($result['already'] ?? false)) {
            $this->sendReceipt($link->fresh());
        }

        unset($result['already']);

        return $result;
    }

    private function createRequest(FormLink $link, FormSchema $schema, array $answers, array $visible, array $columns): array
    {
        $customerTypeId = $schema->customerTypeId($answers);
        $customerType = $customerTypeId ? CustomerType::find($customerTypeId) : null;
        $selection = $this->selection($schema, $answers);
        $conversation = $link->conversation;

        if (! $customerType || ! $conversation || ! $link->customer_id) {
            Log::error('Form submit: missing customer type or conversation', ['form_link_id' => $link->id]);

            return ['ok' => false, 'code' => 'FORM_SETUP', 'message' => 'حصلت مشكلة عندنا في الفورم. كلّمنا على الواتساب وهنكمّل معاك.'];
        }

        $application = Application::create([
            'customer_id' => $link->customer_id,
            'origin_conversation_id' => $conversation->id,
            'customer_type_id' => $customerType->id,
            'machine_id' => $selection['machine']?->id,
            'installment_plan_id' => $selection['plan']?->id,
            'down_payment' => $selection['deposit'],
            'status' => 'submitted',
            'submitted_at' => now(),
            'last_activity_at' => now(),
            'applicant' => $this->applicant($link, $columns),
        ]);

        $this->recordData($application, $schema, $answers, $visible);
        $documentColumns = $this->recordDocuments($application, $link, $schema, $visible);

        $request = InstallmentRequest::create(array_merge($columns, $documentColumns, [
            'application_id' => $application->id,
            'form_link_id' => $link->id,
            'request_type' => LegacyRequestProjector::REQUEST_TYPE,
            'machine_id' => $selection['machine']?->id,
            'whatsapp_conversation_id' => $conversation->id,
            'staff_id' => WhatsappBot::whereKey($conversation->whatsapp_bot_id)->value('staff_id'),
            'installment_type' => $selection['plan']?->installmentSystem?->name,
            'months' => $selection['plan']?->months,
            'machine_installment_price' => $selection['machine']?->installment_price,
            'deposit' => $selection['deposit'] ?? 0,
            'work_status' => $customerType->legacy_work_status,
            'applicant_phone' => $columns['applicant_phone'] ?? $this->localPhone((string) $link->whatsapp_phone),
            'notes' => trim('اتقدم من رابط الفورم ('.$link->version->form->name.')'."\n".($columns['notes'] ?? '')),
            'status' => 'new_request',
        ]));

        $application->update(['installment_request_id' => $request->id]);

        ApplicationEvent::create([
            'application_id' => $application->id,
            'type' => 'submitted_from_form',
            'from_status' => null,
            'to_status' => 'submitted',
            'actor' => 'customer',
            'data' => ['form_link_id' => $link->id, 'installment_request_id' => $request->id],
        ]);

        $link->update([
            'status' => 'submitted',
            'submitted_at' => now(),
            'last_activity_at' => now(),
            'application_id' => $application->id,
            'installment_request_id' => $request->id,
        ]);

        return ['ok' => true, 'request_id' => $request->id];
    }

    /** Staff asked for a data fix: the same request takes the new answers and goes back to them. */
    private function resubmitFix(FormLink $link, FormSchema $schema, array $answers, array $visible, array $columns): array
    {
        $application = $link->application;
        $request = $application?->installmentRequest;

        if (! $application || ! $request) {
            return ['ok' => false, 'code' => 'FORM_SETUP', 'message' => 'حصلت مشكلة عندنا في الفورم. كلّمنا على الواتساب وهنكمّل معاك.'];
        }

        ApplicationData::where('application_id', $application->id)->delete();
        $this->recordData($application, $schema, $answers, $visible);

        ApplicationDocument::where('application_id', $application->id)->whereIn('status', ['accepted', 'needs_review'])
            ->update(['status' => 'superseded']);
        $documentColumns = $this->recordDocuments($application, $link, $schema, $visible);

        // back to the status staff had it in - as StaffDecisionService::resubmit does, but with the
        // form's own columns (its projection would put the WhatsApp number over the form's)
        $staffRequest = (array) $application->staff_request;

        $request->forceFill(array_merge(
            array_diff_key($columns, ['notes' => true]),
            $documentColumns,
            [
                'form_link_id' => $link->id,
                'status' => ($staffRequest['previous_status'] ?? null) ?: 'pending',
                'status_updated_at' => now(),
                'customer_action' => null,
                'notes' => trim((string) $request->notes."\n".'العميل عدّل البيانات من رابط الفورم - '.now()->format('Y-m-d H:i')),
            ],
        ))->saveQuietly();

        $machine = app(\App\Domain\Applications\ApplicationStateMachine::class);

        foreach (['collecting', 'submitted'] as $status) {
            if ($machine->canTransition($application->refresh(), $status)) {
                $machine->transition($application, $status, 'resubmitted_from_form', 'customer', [
                    'installment_request_id' => $request->id, 'form_link_id' => $link->id,
                ]);
            }
        }

        $application->update(['staff_request' => null]);

        $link->update(['status' => 'submitted', 'submitted_at' => now(), 'last_activity_at' => now()]);

        return ['ok' => true, 'request_id' => $request->id];
    }

    /**
     * Request columns from the visible bound fields (documents apart).
     *
     * @return array<string, mixed>
     */
    public function columns(FormSchema $schema, array $answers, array $visible): array
    {
        $columns = [];

        foreach ($schema->fields() as $key => $field) {
            $binding = FormBindings::get($field['binding']);
            $value = $answers[$key] ?? null;

            if (! $binding || ! isset($visible[$key]) || $schema->isEmpty($field, $value)) {
                continue;
            }

            switch ($binding['kind']) {
                case 'column':
                    $columns[$binding['column']] = $this->normalized($field, $value, $schema);

                    if ($field['type'] === 'national_id') {
                        $parsed = $this->nationalIds->parse((string) $value);
                        $prefix = str_starts_with($binding['column'], 'guarantor') ? 'guarantor' : 'applicant';

                        if ($parsed['valid']) {
                            $columns["{$prefix}_birthdate"] = $parsed['birthdate'];
                            $columns["{$prefix}_age_ok"] = $parsed['age'] >= LegacyRequestProjector::MIN_AGE && $parsed['age'] <= LegacyRequestProjector::MAX_AGE;
                        }
                    }
                    break;

                case 'address':
                    $parts = array_map(fn ($v) => filled($v) ? trim((string) $v) : null, (array) $value);

                    foreach (FormBindings::addressColumns($binding['prefix']) as $part => $column) {
                        if (filled($parts[$part] ?? null)) {
                            $columns[$column] = $parts[$part];
                        }
                    }

                    $columns[$binding['full']] = FormBindings::joinAddress($parts);
                    break;

                case 'notes':
                    $columns['notes'] = trim((string) $value);
                    break;

                case 'guarantors':
                    $rows = array_map(function (array $row) use ($field) {
                        $mapped = [];

                        foreach ($field['fields'] as $sub) {
                            if (filled($sub['maps_to']) && filled($row[$sub['key']] ?? null)) {
                                $mapped[$sub['maps_to']] = $sub['type'] === 'national_id'
                                    ? $this->nationalIds->normalizeDigits((string) $row[$sub['key']])
                                    : ($sub['type'] === 'phone' ? $this->localPhone((string) $row[$sub['key']]) : trim((string) $row[$sub['key']]));
                            }
                        }

                        return $mapped;
                    }, $schema->rows($value));

                    $columns['guarantors'] = array_map(fn ($r) => ['name' => $r['name'] ?? null, 'phone' => $r['phone'] ?? null, 'address' => $r['address'] ?? null], $rows);

                    // the first one also fills the single-guarantor columns
                    $first = $rows[0] ?? [];
                    $columns['guarantor_name'] ??= $first['name'] ?? null;
                    $columns['guarantor_phone'] ??= $first['phone'] ?? null;

                    if (filled($first['national_id'] ?? null) && ($parsed = $this->nationalIds->parse($first['national_id']))['valid']) {
                        $columns['guarantor_national_id'] ??= $parsed['digits'];
                        $columns['guarantor_birthdate'] ??= $parsed['birthdate'];
                        $columns['guarantor_age_ok'] ??= $parsed['age'] >= LegacyRequestProjector::MIN_AGE && $parsed['age'] <= LegacyRequestProjector::MAX_AGE;
                    }
                    break;
            }
        }

        // the guarantors list (حالا) mirrors the single guarantor
        if (! isset($columns['guarantors']) && filled($columns['guarantor_name'] ?? null)) {
            $columns['guarantors'] = [['name' => $columns['guarantor_name'], 'phone' => $columns['guarantor_phone'] ?? null, 'address' => null]];
        }

        return $columns;
    }

    /** @return array{machine: ?Machine, plan: ?InstallmentPlan, deposit: ?float} */
    public function selection(FormSchema $schema, array $answers): array
    {
        $value = fn (string $binding) => ($field = $schema->fieldBoundTo($binding)) ? ($answers[$field['key']] ?? null) : null;

        $machine = filled($value('request.machine')) ? Machine::find((int) $value('request.machine')) : null;
        $plan = filled($value('request.plan')) ? InstallmentPlan::with('installmentSystem')->find((int) $value('request.plan')) : null;
        $deposit = $value('request.deposit');

        // the months he picked, on the best system for his final answers (his work decides
        // which systems take him) - the owner's auto-picked offer, never a company he chose
        if ($machine && $plan) {
            $offer = app(\App\Domain\Installments\BestOfferService::class)
                ->offers($machine, $schema->customerTypeId($answers), $this->governorate($schema, $answers), (int) $plan->months, $deposit !== null ? $schema->number($deposit) : null)[0] ?? null;

            if ($offer) {
                $plan = InstallmentPlan::with('installmentSystem')->find($offer['plan_id']);
                $deposit ??= $offer['down_payment'];
            }
        }

        if ($deposit === null && $plan) {
            $deposit = (float) ($plan->installmentSystem?->minimum_down_payment ?? 0);
        }

        return ['machine' => $machine, 'plan' => $plan, 'deposit' => $deposit !== null ? $schema->number($deposit) : null];
    }

    /** The home governorate he wrote, for systems limited to some governorates. */
    private function governorate(FormSchema $schema, array $answers): ?string
    {
        $field = $schema->fieldBoundTo('applicant.address');
        $name = $field ? ($answers[$field['key']]['governorate'] ?? null) : null;

        return $name ? (array_search($name, config('agent.governorates', []), true) ?: null) : null;
    }

    private function normalized(array $field, mixed $value, FormSchema $schema): mixed
    {
        return match ($field['type']) {
            'national_id' => $this->nationalIds->normalizeDigits((string) $value),
            'phone' => $this->localPhone((string) $value),
            'number', 'money' => $schema->number($value),
            'date' => \Illuminate\Support\Carbon::parse((string) $value)->toDateString(),
            'select', 'radio' => collect($field['options'])->firstWhere('value', $value)['label'] ?? $value,
            'checkbox' => implode('، ', collect($field['options'])->whereIn('value', (array) $value)->pluck('label')->all()),
            'yes_no' => $value === 'yes' ? 'نعم' : 'لا',
            'group' => FormSchema::groupText($field, $value),
            default => is_string($value) ? trim($value) : $value,
        };
    }

    /** Values the bot reads later (my_requests, facts), the same keys the chat intake wrote. */
    private function recordData(Application $application, FormSchema $schema, array $answers, array $visible): void
    {
        foreach ($schema->fields() as $key => $field) {
            $binding = FormBindings::get($field['binding']);

            if (! $binding || ! isset($binding['data_key']) || ! isset($visible[$key]) || $schema->isEmpty($field, $answers[$key] ?? null)) {
                continue;
            }

            ApplicationData::create([
                'application_id' => $application->id,
                'field_key' => $binding['data_key'],
                'party' => $binding['party'] ?? 'applicant',
                'value' => (string) $this->normalized($field, $answers[$key], $schema),
                'source' => 'customer_stated',
                'status' => 'valid',
            ]);
        }
    }

    /**
     * Every upload becomes an application document (staff can ask for one again) and the bound
     * ones are copied into the request's image columns. A file the AI warned about still goes
     * (needs_review) - staff decide.
     *
     * @return array<string, mixed> request columns
     */
    private function recordDocuments(Application $application, FormLink $link, FormSchema $schema, array $visible): array
    {
        $columns = [];
        $checker = app(FormDocumentChecker::class);

        foreach (FormLinkFile::where('form_link_id', $link->id)->with('media')->orderBy('id')->get() as $file) {
            $field = $schema->field($file->field_key);

            if (! $field || ! isset($visible[$file->field_key]) || ! $file->media) {
                continue;
            }

            $binding = FormBindings::get($field['binding']);
            $typeKey = $checker->expectedType($field) ?? $file->detected_type_key;
            $documentType = $typeKey ? DocumentType::where('key', $typeKey)->first() : null;

            ApplicationDocument::create([
                'application_id' => $application->id,
                'document_type_id' => $documentType?->id,
                'media_id' => $file->media_id,
                'party' => ($binding['party'] ?? 'applicant') === 'guarantor' ? 'guarantor' : 'applicant',
                'status' => $file->check_status === 'warning' ? 'needs_review' : 'accepted',
                'detected_type_key' => $file->detected_type_key,
                'expected_type_key' => $typeKey,
                'extracted' => $file->extracted ?? [],
                'issues' => $file->issues ?? [],
                'attempts' => 1,
            ]);

            if (($binding['kind'] ?? null) !== 'document' || ! ($path = $this->copyToPublic($file, $binding['dir']))) {
                continue;
            }

            if ($binding['array'] ?? false) {
                $columns[$binding['column']][] = $path;
            } else {
                $columns[$binding['column']] = $path;
            }
        }

        return $columns;
    }

    private function copyToPublic(FormLinkFile $file, string $directory): ?string
    {
        $media = $file->media;

        try {
            $extension = pathinfo($media->path, PATHINFO_EXTENSION) ?: 'jpg';
            $target = $directory.'/form_'.$media->id.'_'.Str::random(8).'.'.$extension;
            Storage::disk('public')->put($target, Storage::disk($media->disk)->get($media->path));

            return $target;
        } catch (\Throwable $e) {
            Log::warning('Form submit: could not copy upload', ['media_id' => $media->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** The person on the request, when not the WhatsApp customer himself. */
    private function applicant(FormLink $link, array $columns): ?array
    {
        $phone = $columns['applicant_phone'] ?? null;
        $own = $this->localPhone((string) $link->whatsapp_phone);

        return $phone && $phone !== $own
            ? ['who' => 'other', 'relation' => 'unclear', 'name' => $columns['applicant_name'] ?? null, 'phone' => $phone, 'source' => 'form']
            : null;
    }

    /** Owner 2026-10-18: no second application while one for the same person waits for a decision. */
    private function undecidedRequest(FormLink $link, array $columns): ?InstallmentRequest
    {
        $phone = $columns['applicant_phone'] ?? $this->localPhone((string) $link->whatsapp_phone);
        $phones = array_values(array_unique(array_filter([$phone, $phone ? '2'.$phone : null, $phone ? '+2'.$phone : null])));
        $nationalId = $columns['applicant_national_id'] ?? null;

        return InstallmentRequest::query()
            ->whereIn('status', self::UNDECIDED_STATUSES)
            ->when($link->installment_request_id, fn ($q) => $q->whereKeyNot($link->installment_request_id))
            ->where(function ($q) use ($phones, $nationalId) {
                $q->whereIn('applicant_phone', $phones);

                if ($nationalId) {
                    $q->orWhere('applicant_national_id', $nationalId);
                }
            })
            ->latest('id')
            ->first();
    }

    /** 01xxxxxxxxx from any of 2010..., +2010..., 010... */
    public function localPhone(string $phone): string
    {
        $digits = $this->nationalIds->normalizeDigits($phone);

        return str_starts_with($digits, '20') && strlen($digits) === 12 ? substr($digits, 1) : $digits;
    }

    private function sendReceipt(FormLink $link): void
    {
        $conversation = $link->conversation;

        if (! $conversation) {
            return;
        }

        $text = $link->purpose === 'fix'
            ? 'وصلنا التعديل ✅ طلبك رجع للمراجعة وهنكلمك أول ما يطلع الرد.'
            : (string) config('agent.form_links.received_message');

        try {
            $this->delivery->deliverForConversation($conversation, ['messages' => [$text]], 'bot', null, false);
            $link->update(['receipt_status' => 'sent']);
        } catch (\Throwable $e) {
            Log::warning('Form receipt not delivered', ['form_link_id' => $link->id, 'error' => $e->getMessage()]);
            $link->update(['receipt_status' => 'failed']);
        }
    }
}
