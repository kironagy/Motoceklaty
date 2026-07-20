<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\Tags\Url;
use App\Models\Machine;
use App\Models\Brand;
use Carbon\Carbon;

class GenerateSitemap extends Command
{
    protected $signature = 'sitemap:generate';
    protected $description = 'Generate dynamic sitemap.xml';

    public function handle()
    {
        $sitemap = Sitemap::create();

        // ========================
        // صفحات ثابتة
        // ========================
        $staticPages = [
            '/',
            '/machines',
            '/installment',
            '/brands',
            '/about',
            '/apply-installment',
        ];

        foreach ($staticPages as $page) {
            $sitemap->add(
                Url::create($page)
                    ->setPriority(0.9)
                    ->setLastModificationDate(now())
            );
        }

        // ========================
        // صفحات المكن الديناميكية
        // ========================
        $machines = Machine::all();

        foreach ($machines as $machine) {
            $sitemap->add(
                Url::create("/machines/{$machine->id}")
                    ->setPriority(0.8)
                    ->setLastModificationDate($machine->updated_at ?? now())
            );
        }

        // ========================
        // صفحات البراندات الديناميكية
        // ========================
        $brands = Brand::all();

        foreach ($brands as $brand) {
            $sitemap->add(
                Url::create("/brands/{$brand->id}")
                    ->setPriority(0.7)
                    ->setLastModificationDate($brand->updated_at ?? now())
            );
        }

        // ========================
        // حفظ الملف
        // ========================
        $sitemap->writeToFile(public_path('sitemap.xml'));

        $this->info('Dynamic Sitemap generated successfully!');
    }
}
