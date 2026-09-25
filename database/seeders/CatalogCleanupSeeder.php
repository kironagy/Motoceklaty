<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Machine;
use Illuminate\Database\Seeder;

/**
 * Catalog hygiene found in the 2026-09-25 dashboard audit: names with stray
 * spaces (" VLM 200", "N-MAX "), tatweel ("بلسـر") and no Arabic aliases for
 * Latin names - "ديمورا 2000" found nothing, the agent had to guess
 * "Demora 2000w". Idempotent: aliases are merged, never replaced.
 */
class CatalogCleanupSeeder extends Seeder
{
    /** machine id => extra aliases (what customers actually type) */
    private const ALIASES = [
        4 => ['ال 250', 'هوجن ال'],
        5 => ['اف 250', 'هوجن اف', 'F 250'],
        6 => ['Boxer 150', 'بوكسر'],
        8 => ['ار كي 200', 'ار كيه', 'RK 200'],
        9 => ['تي اكس 250', 'هوجن تي اكس'],
        10 => ['اتش 250', 'H 250'],
        11 => ['زد 250', 'Z 250'],
        12 => ['سوبر لايت'],
        13 => ['سي لايت', 'C light'],
        14 => ['اكس رود', 'X Road'],
        16 => ['في ال ار 150', 'VLR150'],
        17 => ['اس 200', 'S200'],
        18 => ['Pulsar 150', 'بولسر 150'],
        19 => ['Pulsar 180', 'بولسر 180'],
        20 => ['في ال ار 200', 'VLR200'],
        24 => ['Wing 150'],
        25 => ['Wing 200'],
        26 => ['في ال ام 200', 'VLM200'],
        31 => ['في ال ار 200', 'VLR 200'],
        32 => ['26 ماكس'],
        33 => ['كي تي اكس', 'KTX'],
        35 => ['سي ام جي تايجر', 'تايجر'],
        36 => ['سي ام جي', 'CMG'],
        37 => ['بي ان اي ميوزك', 'ميوزك'],
        38 => ['جي ماكس', 'G Max'],
        39 => ['اكس ال 100', 'XL100'],
        40 => ['اتش ال اكس 150', 'HLX150'],
        41 => ['اتش ال اكس اف', 'HLX 150F'],
        42 => ['Apache 160'],
        43 => ['Apache 200'],
        44 => ['ان ماكس', 'NMAX'],
        45 => ['دايون دي ماكس', 'D Max'],
        46 => ['كيواي كيت 150', 'كيت 150'],
        47 => ['دايون تي اكس'],
        48 => ['لايفان 150', 'ليفان 150'],
        49 => ['كيت 2000'],
        50 => ['فولتواي', 'فولت واي'],
        51 => ['فيجوري ليون', 'ليون 2000'],
        52 => ['ديمورا', 'ديمورة'],
        53 => ['في جي ال 250', 'VG L250'],
        54 => ['في 250 ماكس', 'V250'],
        55 => ['اف 16'],
        57 => ['لايفان سبورت', 'ليفان سبورت'],
        58 => ['لايفان كلاسيك', 'ليفان كلاسيك'],
        59 => ['Discover 125', 'ديسكفر 125'],
        60 => ['في ال ام 200', 'VLM200'],
    ];

    public function run(): void
    {
        Brand::all()->each(function (Brand $brand) {
            $clean = $this->clean($brand->name);

            if ($clean !== $brand->name) {
                $brand->update(['name' => $clean]);
            }
        });

        Machine::all()->each(function (Machine $machine) {
            $changes = [];
            $clean = $this->clean($machine->name);

            if ($clean !== $machine->name) {
                $changes['name'] = $clean;
            }

            $extra = self::ALIASES[$machine->id] ?? [];
            $aliases = collect($machine->aliases ?? [])->merge($extra)->map(fn ($a) => trim((string) $a))->filter()->unique()->values()->all();

            if ($aliases !== ($machine->aliases ?? [])) {
                $changes['aliases'] = $aliases;
            }

            if ($changes !== []) {
                $machine->update($changes);
            }
        });
    }

    private function clean(string $value): string
    {
        $value = preg_replace('/\x{0640}+/u', '', $value);   // tatweel
        $value = preg_replace('/\(\s*/u', '(', $value);
        $value = preg_replace('/\s*\)/u', ')', $value);

        return trim(preg_replace('/\s+/u', ' ', $value));
    }
}
