<?php

namespace App\Domain\Offers;

use App\Models\BotOffer;
use App\Models\CustomerType;
use App\Models\InstallmentSystem;
use Illuminate\Support\Collection;

/**
 * Owner 2026-11-19: the live offers from "عروض البوت", for the bot's prompt, its installment
 * math (InstallmentCalculator) and the dashboard calculator. Read once and kept 30 s per
 * process, because the installment math asks for every plan of every system.
 */
class BotOfferService
{
    private static ?Collection $live = null;

    private static int $readAt = 0;

    public function forget(): void
    {
        self::$live = null;
    }

    /** @return Collection<int, BotOffer> live offers, highest priority first */
    public function live(): Collection
    {
        if (self::$live === null || time() - self::$readAt > 30) {
            self::$live = BotOffer::query()->live()->orderByDesc('priority')->orderBy('id')->get()
                ->filter(fn (BotOffer $o) => $o->isLive())->values();
            self::$readAt = time();
        }

        return self::$live;
    }

    /** The interest offer that applies, if any: the lowest rate wins, then the higher priority. */
    public function interestOffer(int $systemId, int $months, ?int $customerTypeId, ?int $machineId): ?BotOffer
    {
        return $this->live()
            ->filter(fn (BotOffer $o) => $o->covers($systemId, $months, $customerTypeId, $machineId))
            ->sortBy(fn (BotOffer $o) => [$o->rateFor($months), -$o->priority])
            ->first();
    }

    /** The plan's interest after any offer: [rate, offer or null]. */
    public function interest(int $systemId, int $months, float $planRate, ?int $customerTypeId, ?int $machineId): array
    {
        $offer = $this->interestOffer($systemId, $months, $customerTypeId, $machineId);

        // an offer only ever lowers the rate
        return $offer && $offer->rateFor($months) < $planRate ? [$offer->rateFor($months), $offer] : [$planRate, null];
    }

    /** The bot's prompt block: every live offer, told as an offer. Null when there is none. */
    public function promptBlock(): ?string
    {
        $offers = $this->live();

        if ($offers->isEmpty()) {
            return null;
        }

        $types = CustomerType::pluck('label', 'id');
        $systems = InstallmentSystem::pluck('name', 'id');
        $names = fn (?array $ids, $map, string $all) => empty($ids) ? $all : collect($ids)->map(fn ($id) => trim((string) ($map[(int) $id] ?? '')))->filter()->implode('، ');

        $lines = $offers->map(function (BotOffer $o) use ($types, $systems, $names) {
            $until = $o->ends_at ? ' (لحد '.$o->ends_at->format('d/m').')' : '';
            $line = match ($o->kind) {
                'machine' => "- عرض مكنة: {$o->title}{$until} - موتوسيكل id {$o->machine_id}"
                    .($o->condition ? " ({$o->condition})" : '')
                    .' - كاش '.number_format((float) $o->cash_price)
                    .($o->old_price ? ' بدل '.number_format((float) $o->old_price) : '')
                    .($o->stock !== null ? " - فاضل {$o->stock}" : '')
                    .'. تفاصيله وصوره وأقساطه بالأدوات زي أي موتوسيكل.',
                'interest' => "- عرض فايدة: {$o->title}{$until} - الفايدة ".rtrim(rtrim(number_format((float) $o->yearly_rate, 2, '.', ''), '0'), '.').'٪ في السنة'
                    .' لـ '.$names($o->customer_type_ids, $types, 'كل العملاء')
                    .' على '.$names($o->installment_system_ids, $systems, 'كل الأنظمة')
                    .(empty($o->months) ? '' : ' ('.implode('، ', array_map('intval', $o->months)).' شهر)')
                    .'. get_installment_offer بيحسبها لوحده (promo في النتيجة).',
                default => "- عرض: {$o->title}{$until}",
            };

            return $line.($o->details ? "\n  ".str_replace("\n", "\n  ", trim($o->details)) : '');
        });

        return "## العروض الشغالة دلوقتي (من الداشبورد - دي بس العروض الموجودة)\n"
            ."قول للعميل إنه عرض لما يناسب كلامه أو يسأل، ورشّح عرض المكنة لو قريب من اللي بيدور عليه. "
            ."ما تألفش عرض مش هنا، ولو سأل عن عرض مش في القايمة دي قوله إنه خلص واعرض البديل.\n"
            .$lines->implode("\n");
    }
}
