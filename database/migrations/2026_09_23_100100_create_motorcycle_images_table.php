<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DEC-11: a dedicated, indexed table instead of reading machines.colors
 * JSON on every request. `colors` is left untouched (the website still
 * reads it) - this table is populated once from it here and kept in sync
 * going forward by the Filament resource (T09).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motorcycle_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->string('color')->nullable();
            $table->string('path');
            $table->boolean('is_display')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['machine_id', 'color']);
        });

        $rows = [];
        $now = now();

        foreach (DB::table('machines')->whereNotNull('colors')->get(['id', 'colors']) as $machine) {
            $colors = json_decode((string) $machine->colors, true);

            if (! is_array($colors)) {
                continue;
            }

            foreach ($colors as $colorEntry) {
                if (! is_array($colorEntry)) {
                    continue;
                }

                $color = $colorEntry['color'] ?? null;

                if (! empty($colorEntry['color_display'])) {
                    $rows[] = [
                        'machine_id' => $machine->id,
                        'color' => $color,
                        'path' => $colorEntry['color_display'],
                        'is_display' => true,
                        'sort' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                foreach ((array) ($colorEntry['images'] ?? []) as $sort => $path) {
                    if (! $path) {
                        continue;
                    }

                    $rows[] = [
                        'machine_id' => $machine->id,
                        'color' => $color,
                        'path' => $path,
                        'is_display' => false,
                        'sort' => $sort,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('motorcycle_images')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('motorcycle_images');
    }
};
