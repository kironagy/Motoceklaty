<?php

use App\Domain\Settings\AgentInstructions;
use App\Models\BusinessMemory;
use App\Domain\Teaching\LessonSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // Keep historical staff/warehouse references intact.
            DB::table('branches')->where('name', 'like', '%الخصوص%')->update(['is_active' => false]);
            $policy = trim(file_get_contents(resource_path('agent/policies/current-branches.md')));
            BusinessMemory::withoutEvents(fn () => BusinessMemory::updateOrCreate(['key' => 'current_branches'], [
                'category' => 'general', 'title' => 'الفروع الحالية — ثلاثة فروع فقط',
                'content' => $policy, 'is_active' => true, 'is_pinned' => true, 'priority' => 100,
            ]));
            $instructions = app(AgentInstructions::class);
            $current = $instructions->current();
            $base = rtrim(LessonSync::withoutSection($current['text']));
            if (! str_contains($base, $policy)) {
                $base .= "\n\n".$policy;
            }
            $text = app(LessonSync::class)->withLessons($base);
            if (trim($text) !== trim($current['text'])) {
                $instructions->publish($text, 'إلغاء فرع الخصوص وتحديث الفروع إلى ثلاثة');
            }
        });
    }

    public function down(): void
    {
        // Business closures and published instructions are not undone automatically.
    }
};
