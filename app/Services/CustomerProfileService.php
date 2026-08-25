<?php

namespace App\Services;

use App\Models\CustomerProfile;
use App\Models\Machine;
use App\Models\WhatsappConversation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Plan task 3.5: remembers a customer across conversations.
 *
 * Written to from the two moments something durable is learned - an
 * installment calculation (which machine, how many months, how much down)
 * and an application turn (name, job) - and read back as one short line
 * injected into the planner and the free-form reply, so a returning
 * customer is not interrogated from zero.
 *
 * Every method is best-effort: profiles are a nicety, and no failure here
 * may ever break a reply, so writes are wrapped and reads degrade to null.
 */
class CustomerProfileService
{
    public function forConversation(WhatsappConversation $conversation): ?CustomerProfile
    {
        $phone = $this->phone($conversation);

        if ($phone === null) {
            return null;
        }

        try {
            return CustomerProfile::query()->where('phone', $phone)->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * A single line for the prompts. Null when we know nothing worth saying -
     * an empty "معلومات العميل:" block is noise the model has to read past.
     */
    public function summaryFor(WhatsappConversation $conversation): ?string
    {
        $profile = $this->forConversation($conversation);

        if (! $profile) {
            return null;
        }

        $parts = [];

        if ($profile->name) {
            $parts[] = "اسمه {$profile->name}";
        }

        if ($profile->job_type) {
            $parts[] = "شغله {$profile->job_type}";
        }

        if ($profile->last_machine_id) {
            $machine = $profile->lastMachine;

            if ($machine) {
                $parts[] = "آخر مكنة اتكلم عليها {$machine->name}";
            }
        }

        if ($profile->preferred_months) {
            $parts[] = "بيفضل التقسيط على {$profile->preferred_months} شهر";
        }

        if ($profile->last_deposit > 0) {
            $parts[] = 'آخر مقدم قاله ' . number_format((float) $profile->last_deposit) . ' جنيه';
        }

        if ($profile->applications_count > 0) {
            $parts[] = "قدّم عندنا قبل كده {$profile->applications_count} مرة";
        }

        if (empty($parts)) {
            return null;
        }

        return implode('، ', $parts) . '.';
    }

    public function rememberInstallmentInterest(
        WhatsappConversation $conversation,
        ?int $machineId,
        ?int $months,
        ?float $deposit
    ): void {
        $this->remember($conversation, array_filter([
            'last_machine_id' => $machineId,
            'preferred_months' => $months,
            'last_deposit' => $deposit !== null && $deposit > 0 ? $deposit : null,
        ], fn ($value) => $value !== null));
    }

    public function rememberApplication(WhatsappConversation $conversation, array $application, ?string $incomeCategory = null): void
    {
        $this->remember($conversation, array_filter([
            'name' => $application['full_name'] ?? null,
            'job_type' => $application['job_type'] ?? null,
            'income_category' => $incomeCategory,
            'last_machine_id' => $application['machine_id'] ?? null,
            'preferred_months' => $application['installment_months'] ?? null,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    public function recordSubmittedApplication(WhatsappConversation $conversation): void
    {
        $profile = $this->remember($conversation, [
            'last_application_at' => now(),
        ]);

        if ($profile) {
            try {
                $profile->increment('applications_count');
            } catch (\Throwable $e) {
                // best-effort, see class docblock
            }
        }
    }

    private function remember(WhatsappConversation $conversation, array $attributes): ?CustomerProfile
    {
        $phone = $this->phone($conversation);

        if ($phone === null || empty($attributes)) {
            return null;
        }

        try {
            if (! Schema::hasTable('customer_profiles')) {
                return null;
            }

            $attributes['last_seen_at'] = now();

            /*
             * A machine id only lands here when the row still exists - a
             * deleted machine would otherwise fail the foreign key and take
             * the whole turn down with it.
             */
            if (isset($attributes['last_machine_id']) && ! Machine::query()->whereKey($attributes['last_machine_id'])->exists()) {
                unset($attributes['last_machine_id']);
            }

            return CustomerProfile::query()->updateOrCreate(['phone' => $phone], $attributes);
        } catch (\Throwable $e) {
            Log::warning('customer_profile_write_failed', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function phone(WhatsappConversation $conversation): ?string
    {
        $phone = trim((string) ($conversation->real_phone ?: $conversation->phone));

        return $phone !== '' ? $phone : null;
    }
}
