<?php

namespace App\Console\Commands;

use App\Models\AiMemoryRetrievalLog;
use App\Models\CustomerProfile;
use App\Models\InstallmentRequest;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Console\Command;

/**
 * Wipes the conversation state for one test number so the bot meets you as a
 * brand-new customer: the conversation rows, their messages, the retrieval
 * logs they produced, and the durable customer_profiles row.
 *
 * Deliberately scoped by phone and deliberately never touches
 * installment_requests - a submitted application is real business data, not
 * conversation state, and losing one because someone wanted a clean test is
 * not a trade this command is allowed to make. Use --all only on a dev
 * database.
 */
class WhatsappResetTesting extends Command
{
    protected $signature = 'whatsapp:reset-testing
        {phone? : رقم الموبايل (بيقارن بـ phone و real_phone)}
        {--all : امسح كل المحادثات - للتطوير بس}
        {--force : من غير سؤال تأكيد}';

    protected $description = 'Reset WhatsApp conversation state for a test number (keeps installment_requests)';

    public function handle(): int
    {
        $phone = (string) $this->argument('phone');
        $all = (bool) $this->option('all');

        if ($phone === '' && ! $all) {
            $this->error('اكتب رقم، أو استخدم --all لو عايز تمسح كل المحادثات.');

            return self::FAILURE;
        }

        $query = WhatsappConversation::query()
            ->when(! $all, fn ($q) => $q->where(function ($q) use ($phone) {
                $q->where('phone', 'like', "%{$phone}%")
                    ->orWhere('real_phone', 'like', "%{$phone}%");
            }));

        $conversations = $query->get();

        if ($conversations->isEmpty()) {
            $this->info('مفيش محادثات مطابقة - مفيش حاجة اتمسحت.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'phone', 'real_phone', 'status', 'messages', 'last update'],
            $conversations->map(fn (WhatsappConversation $c) => [
                $c->id,
                $c->phone,
                $c->real_phone ?? '-',
                $c->status,
                $c->messages()->count(),
                (string) $c->updated_at,
            ])->all()
        );

        if (! $this->option('force') && ! $this->confirm('امسح المحادثات دي وكل رسايلها؟', false)) {
            $this->info('اتلغى.');

            return self::SUCCESS;
        }

        $ids = $conversations->pluck('id');
        $phones = $conversations->flatMap(fn (WhatsappConversation $c) => array_filter([$c->phone, $c->real_phone]))->unique();

        $messages = WhatsappMessage::query()->whereIn('whatsapp_conversation_id', $ids)->delete();
        $logs = AiMemoryRetrievalLog::query()->whereIn('whatsapp_conversation_id', $ids)->delete();
        $profiles = CustomerProfile::query()->whereIn('phone', $phones)->delete();
        $deleted = WhatsappConversation::query()->whereIn('id', $ids)->delete();

        $this->newLine();
        $this->info("اتمسح: {$deleted} محادثة · {$messages} رسالة · {$logs} سجل استرجاع · {$profiles} ملف عميل");
        $this->comment('طلبات التقسيط متأثرتش: ' . InstallmentRequest::query()->count() . ' طلب زي ما هو.');
        $this->newLine();
        $this->comment('لو فيه queue worker شغال، شغّل php artisan queue:restart عشان ياخد الكود الجديد.');

        return self::SUCCESS;
    }
}
