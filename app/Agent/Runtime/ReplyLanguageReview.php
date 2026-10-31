<?php
namespace App\Agent\Runtime;
/** Mandatory language-only check, independent of advisory business-fact guards. */
class ReplyLanguageReview
{
    public function allows(array $args): bool
    {
        if (! config('agent.safety.language_enabled')) return true;
        $text=implode("\n",(array)($args['messages']??[]));
        if(trim($text)==='')return true;
        $pattern=trim((string)config('agent.safety.language_banned_pattern'));
        $plain=preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u','',$text);
        if($pattern!=='' && (@preg_match($pattern,'')===false || preg_match($pattern,$plain)))return false;
        return app(EvidenceReview::class)->judge('reply_language',['candidate'=>$text]);
    }
    public function safeReply(): array
    {
        $message=trim((string)config('agent.safety.language_safe_reply'));
        return ['messages'=>$message===''?[]:[$message]];
    }
}
