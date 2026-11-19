<?php

namespace App\Domain\Offers;

use App\Models\BotOffer;
use App\Models\Machine;

/**
 * A machine offer (e.g. هوندا كسر زيرو) lives only in "عروض البوت"; this keeps its catalog
 * row (type = offer) in step, so the bot's search, details, photos, installments and
 * applications treat it like any motorcycle. The row is on only while the offer is live.
 */
class OfferMachineSync
{
    public function sync(BotOffer $offer): ?Machine
    {
        if ($offer->kind !== 'machine' || blank($offer->machine_name) || ! $offer->brand_id || ! $offer->cash_price) {
            return null;
        }

        $images = array_values(array_filter((array) ($offer->images ?? [])));
        $machine = $offer->machine_id ? Machine::find($offer->machine_id) : null;
        $machine ??= new Machine();

        $machine->fill([
            'name' => trim($offer->machine_name),
            'brand_id' => $offer->brand_id,
            'type' => 'offer',
            'cash_price' => $offer->cash_price,
            'installment_price' => $offer->installment_price ?: $offer->cash_price,
            'old_price' => $offer->old_price ?: $offer->cash_price,
            'new_price' => $offer->cash_price,
            'description' => trim(implode("\n", array_filter([$offer->condition ? 'الحالة: '.$offer->condition : null, $offer->details]))),
            'specifications' => $offer->specifications ?: null,
            'aliases' => array_values(array_unique(array_filter(array_merge(
                (array) ($offer->aliases ?? []),
                [trim($offer->machine_name.' '.$offer->condition), $offer->title],
            )))),
            'installment_systems' => array_map('strval', (array) ($offer->installment_system_ids ?? [])),
            'availability' => $offer->stock !== null && $offer->stock <= 0 ? 'out_of_stock' : 'in_stock',
            'is_active' => $offer->isLive(),
            'display_image' => $images[0] ?? null,
            // MachineObserver projects these onto motorcycle_images (what the bot sends)
            'colors' => $images === [] ? [] : [['color' => null, 'color_name' => null, 'color_display' => $images[0], 'images' => array_slice($images, 1)]],
        ]);
        $machine->save();

        if ((int) $offer->machine_id !== (int) $machine->id) {
            $offer->machine_id = $machine->id;
            $offer->saveQuietly();
        }

        return $machine;
    }

    /** Dates and stock change with time, not with a save: the scheduler turns the rows on/off. */
    public function syncAll(): int
    {
        $changed = 0;

        BotOffer::where('kind', 'machine')->whereNotNull('machine_id')->with('machine')->get()
            ->each(function (BotOffer $offer) use (&$changed) {
                if ($offer->machine && (bool) $offer->machine->is_active !== $offer->isLive()) {
                    $offer->machine->update(['is_active' => $offer->isLive()]);
                    $changed++;
                }
            });

        return $changed;
    }
}
