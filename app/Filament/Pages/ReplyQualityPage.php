<?php

namespace App\Filament\Pages;

use App\Domain\Monitoring\ReplyQuality;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/** Owner 2026-10-04: the bot's mistakes as numbers, every day, not screenshots. */
class ReplyQualityPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-check-badge';

    protected static ?string $navigationLabel = 'جودة الردود';

    protected static ?string $navigationGroup = 'البوت الذكي';

    protected static ?string $title = 'جودة ردود البوت';

    protected static ?int $navigationSort = 89;

    protected static ?string $slug = 'reply-quality';

    protected static string $view = 'filament.pages.reply-quality';

    public int $days = 1;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return (bool) ($user?->is_super_admin || $user?->role === 'super_admin');
    }

    public function getSummary(): array
    {
        return app(ReplyQuality::class)->summary(now()->subDays($this->days));
    }

    public function getProblemTurns(): array
    {
        return app(ReplyQuality::class)->problemTurns(now()->subDays($this->days));
    }

    /** Plain-Arabic names for the refusal codes the owner sees most. */
    public static function codeLabel(string $code): string
    {
        return [
            'REVIEW_REDO' => 'المراجعة رجّعت الرد (معلومة غلط أو فهم غلط)',
            'UNVERIFIED_NUMBER' => 'رقم مش من السيستم',
            'PERCENT_DURATION_MISMATCH' => 'نسبة فايدة على مدة غلط',
            'DATA_NOT_RECORDED' => 'كرر كلام العميل ومسجلش',
            'ECHOES_CUSTOMER' => 'كرر كلام العميل',
            'ENGLISH_WORD' => 'كلمة إنجليزي',
            'FORMAL_ARABIC' => 'فصحى',
            'DUPLICATE_REPLY' => 'رد مكرر',
            'REPEATED_QUESTION' => 'سؤال مكرر',
            'DATA_CLAIMED_NOT_SAVED' => 'قال سجلت ومسجلش',
            'DOCUMENT_NOT_REQUIRED' => 'طلب ورقة مش مطلوبة',
            'UNRECORDED_PROMISE' => 'وعد مش متسجل',
            'STOCK_OR_CHECK_CLAIMED' => 'ادعى إنه هيشيّك على المخزون',
            'INTERNAL_KEY_IN_REPLY' => 'كلام داخلي',
            'SUBMISSION_CLAIMED_NOT_DONE' => 'قال الطلب اتبعت ومتبعتش',
            'HANDOFF_CLAIMED_NOT_DONE' => 'قال حوّلتك ومحوّلش',
            'IMAGES_CLAIMED_FOR_UNSENT_MODEL' => 'قال بعت صور موديل متبعتش',
            'APPLICATION_ALREADY_OPEN_CLAIMED' => 'قال فتحتلك الطلب وهو مفتوح من قبل',
            'APPLICATION_CLAIMED_NOT_OPENED' => 'قال فتحتلك الطلب ومتفتحش',
            'SELECTION_CLAIMED_NOT_SET' => 'قال الطلب على موديل أو مدة غير المتسجلة',
            'COMPLETION_OVERCLAIMED' => 'قال كملنا والطلب لسه ناقص',
            'DOCUMENT_CLAIMED_NOT_ACCEPTED' => 'قال الورق وصل والورق متقبلش',
            'MODEL_NOT_LOOKED_UP' => 'ذكر موديل من غير ما يدور عليه',
            'INVENTED_REASON_PHRASE' => 'سبب أو وعد متألف',
        ][$code] ?? $code;
    }
}
