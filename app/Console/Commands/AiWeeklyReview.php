<?php

namespace App\Console\Commands;

use App\Models\AiMemory;
use App\Models\AiMemoryRetrievalLog;
use App\Models\GeminiApiKey;
use App\Models\GeminiApiKeyModel;
use App\Models\WhatsappConversation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Plan task 4.3: the weekly review, as a command instead of a checklist
 * nobody runs.
 *
 * Every item here is a leading indicator - something that is already wrong
 * but that no customer has complained about yet: a renamed memory title the
 * code still asks for, a reasoning model that quietly ran out of quota, a
 * rewording the number guard keeps rejecting, memories that are never
 * retrieved.
 *
 *   php artisan ai:weekly-review
 *   php artisan ai:weekly-review --days=30
 */
class AiWeeklyReview extends Command
{
    protected $signature = 'ai:weekly-review {--days=7 : How far back to look}';

    protected $description = 'Weekly AI health review (plan task 4.3)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days);

        $this->info("مراجعة آخر {$days} يوم");
        $this->newLine();

        $this->keysSection();
        $this->memorySection($since);
        $this->logSection($days);
        $this->conversationSection($since);

        $this->newLine();
        $this->comment('أي سلوك غلط لقيته هنا: ضيفه كحالة في AiGoldenSet::cases() قبل ما تصلحه.');

        return self::SUCCESS;
    }

    private function keysSection(): void
    {
        $this->line('<options=bold>مفاتيح وموديلات Gemini</>');

        $inactive = GeminiApiKey::query()->where('is_active', false)->pluck('id');

        $inactive->isEmpty()
            ? $this->line('  ✓ كل المفاتيح شغالة')
            : $this->warn('  ✗ مفاتيح متوقفة (محتاجة تجديد): ' . $inactive->implode(', '));

        $code = (string) config('gemini.models.reasoning');

        $rows = GeminiApiKeyModel::query()
            ->where('model_code', $code)
            ->where('is_active', true)
            ->whereHas('apiKey', fn ($query) => $query->where('is_active', true))
            ->get();

        $used = (int) $rows->sum('requests_today');
        $limit = (int) $rows->sum('rpd_limit');

        if ($limit === 0) {
            $this->warn("  ✗ {$code} مش متركّب على أي مفتاح شغال");
        } elseif ($used >= $limit) {
            $this->warn("  ✗ {$code}: {$used}/{$limit} - خلص النهاردة، كل الردود نازلة على " . config('gemini.models.fast'));
        } else {
            $this->line("  ✓ {$code}: {$used}/{$limit} نداء النهاردة");
        }

        $this->newLine();
    }

    private function memorySection(\DateTimeInterface $since): void
    {
        $this->line('<options=bold>الميموري</>');

        $active = AiMemory::query()->where('is_active', true)->get();

        $untagged = $active->filter(fn (AiMemory $memory) => empty($memory->keywords));

        $untagged->isEmpty()
            ? $this->line('  ✓ كل الميموري النشطة عندها كلمات مفتاحية')
            : $this->warn('  ✗ ' . $untagged->count() . ' ميموري من غير keywords: ' . $untagged->pluck('title')->take(5)->implode(' · ') . ' (شغّل ai:memory-tags)');

        /*
         * A memory that is never selected is either dead weight or worded so
         * differently from how customers ask that retrieval never finds it -
         * both are worth a human look.
         */
        $selected = AiMemoryRetrievalLog::query()
            ->where('created_at', '>=', $since)
            ->pluck('selected_memory_ids')
            ->flatten()
            ->unique();

        if ($selected->isNotEmpty()) {
            $never = $active->reject(fn (AiMemory $memory) => $selected->contains($memory->id));

            $never->isEmpty()
                ? $this->line('  ✓ كل ميموري اتسحبت مرة على الأقل')
                : $this->warn('  ✗ ' . $never->count() . ' ميموري عمرها ما اتسحبت: ' . $never->pluck('title')->take(5)->implode(' · '));
        }

        $methods = AiMemoryRetrievalLog::query()
            ->where('created_at', '>=', $since)
            ->get()
            ->groupBy('retrieval_method')
            ->map->count();

        foreach ($methods as $method => $count) {
            $this->line("  · {$method}: {$count}");
        }

        $this->newLine();
    }

    private function logSection(int $days): void
    {
        $this->line('<options=bold>اللوج</>');

        $markers = [
            'ai_memory_title_miss' => 'عنوان ميموري الكود بيدوّر عليه مش موجود - اتغير من Filament',
            'ai_phrasing_rejected' => 'صياغة الموديل اترفضت (حارس الأرقام) - الرد الثابت اتبعت بدالها',
            'extra_step_failed' => 'طلب إضافي في نفس الرسالة فشل',
            'Gemini preferred model unavailable' => 'نزول تلقائي للموديل الرخيص',
            'customer_profile_write_failed' => 'فشل حفظ ملف عميل',
        ];

        $contents = $this->recentLogContents($days);

        if (trim($contents) === '') {
            $this->line('  (مفيش لوج مكتوب في ملفات آخر ' . $days . ' يوم - لو اللوج رايح لـ stderr/خدمة خارجية، دوّر على نفس الكلمات هناك:');
            $this->line('   ' . implode(' · ', array_keys($markers)) . ')');
            $this->newLine();

            return;
        }

        foreach ($markers as $marker => $meaning) {
            $count = substr_count($contents, $marker);

            if ($count === 0) {
                continue;
            }

            $this->warn("  ✗ {$marker}: {$count} مرة - {$meaning}");

            if ($marker === 'ai_memory_title_miss') {
                preg_match_all('/ai_memory_title_miss.*?"title":"([^"]+)"/u', $contents, $matches);

                foreach (array_count_values($matches[1] ?? []) as $title => $times) {
                    $this->line("      - {$title} ({$times})");
                }
            }
        }

        $this->newLine();
    }

    private function conversationSection(\DateTimeInterface $since): void
    {
        $this->line('<options=bold>المحادثات</>');

        $waiting = WhatsappConversation::query()->where('status', 'awaiting_agent')->count();
        $touched = WhatsappConversation::query()->where('updated_at', '>=', $since)->count();

        $this->line("  · {$touched} محادثة اتحركت، {$waiting} مستنية موظف دلوقتي");

        $stuck = WhatsappConversation::query()
            ->where('status', 'awaiting_agent')
            ->where('updated_at', '<', now()->subDay())
            ->count();

        if ($stuck > 0) {
            $this->warn("  ✗ {$stuck} محادثة محوّلة لموظف وعدى عليها أكتر من يوم من غير حركة");
        }

        $this->newLine();
    }

    private function recentLogContents(int $days): string
    {
        $path = storage_path('logs');

        if (! File::isDirectory($path)) {
            return '';
        }

        $cutoff = now()->subDays($days)->getTimestamp();

        return collect(File::files($path))
            ->filter(fn ($file) => str_ends_with($file->getFilename(), '.log') && $file->getMTime() >= $cutoff)
            ->map(fn ($file) => (string) File::get($file->getPathname()))
            ->implode("\n");
    }
}
