<?php

namespace App\Services\Whatsapp\Handlers;

use App\Models\Machine;
use App\Models\WhatsappConversation;
use Illuminate\Support\Facades\Schema;

class ApplicationHandler
{
    public function handle(WhatsappConversation $conversation, string $message): array
    {
        $conversation->refresh();

        $machine = $this->currentMachine($conversation);

        $payload = $conversation->context_payload ?? [];

        if (is_string($payload)) {
            $payload = json_decode($payload, true) ?: [];
        }

        $application = $payload['application'] ?? [];

        if (!$machine) {
            return $this->reply($conversation, 'تمام يا فندم، تحب تقدم على أنهي مكنة؟');
        }

        $application['machine_id'] = $machine->id;
        $application['machine_name'] = $this->machineDisplayName($machine);

        $analysis = app(\App\Services\AiIntentClassifier::class)->classify($conversation, $message, [
            'mode' => 'application_data_extraction',
            'required_fields' => [
                'full_name',
                'national_id',
                'phone',
                'job_type',
                'income_proof',
                'work_address',
                'home_address',
                'installment_months',
            ],
            'current_application' => $application,
            'selected_machine' => [
                'id' => $machine->id,
                'name' => $this->machineDisplayName($machine),
            ],
        ]);

        $application = array_merge($application, $analysis['application_data'] ?? []);

        $missing = $this->missingFields($application);

        $this->saveState($conversation, $application, $missing);

        if (!empty($missing)) {
            return $this->reply($conversation, $this->questionForMissing($missing, $application));
        }

        return $this->reply(
            $conversation,
            "تمام يا فندم، كده البيانات الأساسية مكتملة على {$application['machine_name']}.\nهراجع الطلب وحد من المعرض هيتابع معاك."
        );
    }

    private function currentMachine(WhatsappConversation $conversation): ?Machine
    {
        $ids = $conversation->last_machine_ids ?? [];

        if (is_string($ids)) {
            $ids = json_decode($ids, true) ?: [];
        }

        if (!is_array($ids) || empty($ids)) {
            return null;
        }

        if (count($ids) > 1) {
            return null;
        }

        return Machine::query()->with('brand')->find($ids[0]);
    }

    private function missingFields(array $data): array
    {
        $required = [
            'full_name',
            'national_id',
            'phone',
            'job_type',
            'income_proof',
            'work_address',
            'home_address',
            'installment_months',
        ];

        return array_values(array_filter($required, fn ($key) => empty($data[$key])));
    }

    private function questionForMissing(array $missing, array $application): string
    {
        $labels = [
            'full_name' => 'الاسم بالكامل',
            'national_id' => 'الرقم القومي',
            'phone' => 'رقم الموبايل',
            'job_type' => 'طبيعة شغلك',
            'income_proof' => 'هل معاك مفردات مرتب أو إثبات دخل؟',
            'work_address' => 'عنوان الشغل بالتفصيل',
            'home_address' => 'عنوان السكن بالتفصيل',
            'installment_months' => 'مدة التقسيط اللي تحبها',
        ];

        $first = $missing[0];

        return 'تمام يا فندم، ناقصني ' . ($labels[$first] ?? $first) . ' عشان أكمل طلب التقديم.';
    }

    private function saveState(WhatsappConversation $conversation, array $application, array $missing): void
    {
        $payload = $conversation->context_payload ?? [];

        if (is_string($payload)) {
            $payload = json_decode($payload, true) ?: [];
        }

        $payload['application'] = $application;
        $payload['missing_fields'] = $missing;

        $conversation->forceFill([
            'last_topic' => 'application',
            'pending_question' => empty($missing) ? null : 'application_missing_data',
            'context_payload' => $payload,
        ])->save();
    }

    private function reply(WhatsappConversation $conversation, string $reply): array
    {
        $conversation->messages()->create([
            'direction' => 'outgoing',
            'message' => $reply,
            'payload' => [
                'source' => 'application_handler',
            ],
        ]);

        return [
            'handled' => true,
            'type' => 'text',
            'reply' => $reply,
            'image' => null,
            'images' => [],
            'image_items' => [],
            'image_groups' => [],
        ];
    }

    private function machineDisplayName(Machine $machine): string
    {
        $brand = trim((string) ($machine->brand?->name ?? ''));
        $name = trim((string) $machine->name);

        return $brand && !str_contains(mb_strtolower($name), mb_strtolower($brand))
            ? $brand . ' ' . $name
            : $name;
    }
}