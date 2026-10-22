<?php

namespace App\Console\Commands;

use App\Models\SiteSetting;
use App\Support\SiteContent;
use App\Support\SiteDefaults;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

/** Upgrade only untouched design defaults; never reset custom content. */
class AdoptMotogateDesign extends Command
{
    protected $signature = 'site:adopt-motogate-v2';
    protected $description = 'Adopt MotoGate 2 artwork while preserving custom dashboard content and backing up saved settings';

    public function handle(): int
    {
        $rows = SiteSetting::all();
        $backup = 'site-backups/before-motogate-v2-' . now()->format('Ymd-His') . '.json';
        Storage::disk('local')->put($backup, $rows->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $defaults = SiteDefaults::all();
        $legacy = [
            'general.logo' => 'site/defaults/logo-light.png',
            'general.footer_logo' => 'site/defaults/logo-light.png',
            'general.seo.og_image' => 'site/defaults/logo-light.png',
            'home.hero.bg_image' => 'site/defaults/slider1.jpg',
            'home.hero.small_title' => 'الأفضل في مصر',
            'home.hero.slides' => [
                ['title' => 'TX 250', 'image' => 'site/defaults/TX250.png'],
                ['title' => 'KTX 250', 'image' => 'site/defaults/KTX.png'],
                ['title' => 'TX 250 Blue', 'image' => 'site/defaults/TX250-blue.png'],
                ['title' => 'TX 250 Red', 'image' => 'site/defaults/TX250-red.png'],
            ],
            'home.about.image' => 'site/defaults/desert-mountain-bg.jpg',
            'home.about.image2' => 'site/defaults/KTX.png',
            'home.about.video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ];
        $legacy = array_merge($legacy, json_decode(file_get_contents(resource_path('site/motogate-v1-upgrade-values.json')), true));
        foreach ($rows as $row) {
            $data = $row->value;
            foreach ($legacy as $path => $oldValue) {
                [$group, $key] = explode('.', $path, 2);
                if ($row->key === $group && Arr::get($data, $key) === $oldValue) {
                    Arr::set($data, $key, Arr::get($defaults, $path));
                }
            }
            if ($row->key === 'general') {
                $footer = Arr::get($data, 'footer.links', []);
                $oldFooter = $legacy['general.footer.links'];
                if ($footer === [...$oldFooter, ['label' => 'تتبع طلبك', 'url' => '/track']]) {
                    Arr::set($data, 'footer.links', $defaults['general']['footer']['links']);
                }
                $originalMenu = [
                    ['label' => 'الرئيسية', 'url' => '/'], ['label' => 'من نحن', 'url' => '/about'],
                    ['label' => 'المعرض', 'url' => '/machines'], ['label' => 'المدونة', 'url' => '/blog'],
                    ['label' => 'فريق موتوجيت', 'url' => '/team'], ['label' => 'تواصل معنا', 'url' => '/contact'],
                ];
                $withTracking = [...$originalMenu, ['label' => 'تتبع طلبك', 'url' => '/track']];
                if (in_array($data['menu'] ?? [], [$originalMenu, $withTracking], true)) {
                    $data['menu'] = $defaults['general']['menu'];
                }
            }
            $row->update(['value' => $data]);
        }
        SiteContent::flush();
        SiteContent::publishDefaults();
        $this->info('MotoGate 2 defaults adopted. Custom content retained. Backup: ' . $backup);
        return self::SUCCESS;
    }
}
