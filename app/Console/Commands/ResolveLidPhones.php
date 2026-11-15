<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\WhatsappConversation;
use Illuminate\Console\Command;

/**
 * Owner 2026-11-15: most waiting customers show only WhatsApp's hidden id (@lid), not their phone.
 * Every number the worker ever linked kept WhatsApp's lid -> phone pairs it learned
 * (whatsapp-bot/sessions/<bot>[.loggedout-*]/lid-mapping-<lid>_reverse.json). This copies the
 * phone of every customer found there into the conversation's real_phone.
 */
class ResolveLidPhones extends Command
{
    protected $signature = 'whatsapp:resolve-lids {--dry : only count}';

    protected $description = 'Fill real phones of @lid customers from the worker\'s lid mappings';

    public function handle(): int
    {
        $map = self::mappings(base_path('whatsapp-bot/sessions'));
        $this->line(count($map).' lid mappings found');

        $filled = 0;

        Customer::query()->where(fn ($q) => $q->where('jid', 'like', '%@lid')->orWhereNotNull('lid_jid'))
            ->chunkById(500, function ($customers) use ($map, &$filled) {
                foreach ($customers as $customer) {
                    $lid = preg_replace('/\D/', '', explode('@', (string) ($customer->lid_jid ?: $customer->jid))[0]);
                    $phone = $map[$lid] ?? null;

                    if (! $phone) {
                        continue;
                    }

                    $query = WhatsappConversation::where('customer_id', $customer->id)
                        ->where(fn ($q) => $q->whereNull('real_phone')->orWhere('real_phone', ''));

                    $filled += $this->option('dry') ? $query->count() : $query->update(['real_phone' => $phone]);
                }
            });

        $this->info(($this->option('dry') ? 'Would fill ' : 'Filled ').$filled.' conversations.');

        return self::SUCCESS;
    }

    /** @return array<string, string> lid digits => phone digits */
    public static function mappings(string $sessionsDir): array
    {
        $map = [];

        foreach (glob($sessionsDir.'/*/lid-mapping-*_reverse.json') ?: [] as $file) {
            $lid = substr(basename($file), strlen('lid-mapping-'), -strlen('_reverse.json'));
            $phone = preg_replace('/\D/', '', (string) json_decode((string) @file_get_contents($file), true));

            if ($lid !== '' && strlen($phone) >= 10) {
                $map[$lid] = $phone;
            }
        }

        return $map;
    }
}
