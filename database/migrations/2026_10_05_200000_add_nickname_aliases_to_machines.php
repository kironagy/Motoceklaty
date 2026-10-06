<?php

use App\Support\ArabicTextNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CAT-002: the showroom's nicknames ("النحلة", "هوجن جمبو") were known only
 * through the catalog index sent with every call. As machine aliases they
 * are matched by search_motorcycles and CatalogMentions, and staff can edit
 * them in the dashboard. Added to every variant of the model (استيراد, فرز
 * تاني, اصلي) - which one he means is asked, as for any shared name.
 */
return new class extends Migration
{
    /** normalized name prefix => nicknames */
    private const NICKNAMES = [
        'هوجن 4' => ['هوجن جمبو', 'جمبو'],
        '(cc200) هوجن 4' => ['هوجن جمبو', 'جمبو'],
        'دايو 2' => ['النحلة', 'نحلة'],
        'هوجن 3' => ['الأرنبة', 'ارنبة'],
        'دايو 4' => ['التفاحة', 'تفاحة'],
        'z250' => ['زد'],
        'tx 250' => ['تي إكس', 'تي اكس'],
        'rk200 r' => ['آر كي', 'ار كي'],
        'h250' => ['إتش', 'اتش'],
    ];

    public function up(): void
    {
        foreach (DB::table('machines')->get(['id', 'name', 'aliases']) as $machine) {
            $name = ArabicTextNormalizer::normalize((string) $machine->name);
            $add = [];

            foreach (self::NICKNAMES as $prefix => $nicknames) {
                $prefix = ArabicTextNormalizer::normalize($prefix);

                if ($name === $prefix || str_starts_with($name, $prefix.' ')) {
                    $add = array_merge($add, $nicknames);
                }
            }

            if ($add === []) {
                continue;
            }

            $aliases = (array) (json_decode((string) $machine->aliases, true) ?: []);
            $merged = array_values(array_unique(array_merge($aliases, $add)));

            if ($merged !== $aliases) {
                DB::table('machines')->where('id', $machine->id)->update(['aliases' => json_encode($merged, JSON_UNESCAPED_UNICODE)]);
            }
        }

        \App\Domain\Catalog\CatalogService::invalidateIndexCache();
        \Illuminate\Support\Facades\Cache::forget('reply_guard.catalog_mentions');
    }

    public function down(): void
    {
        $all = array_merge(...array_values(self::NICKNAMES));

        foreach (DB::table('machines')->whereNotNull('aliases')->get(['id', 'aliases']) as $machine) {
            $aliases = (array) (json_decode((string) $machine->aliases, true) ?: []);
            $left = array_values(array_diff($aliases, $all));

            if ($left !== $aliases) {
                DB::table('machines')->where('id', $machine->id)->update(['aliases' => json_encode($left, JSON_UNESCAPED_UNICODE)]);
            }
        }
    }
};
