<?php
namespace App\Agent\Runtime;
/** Exact, dashboard-approved social responses; no business facts are inferred. */
class SocialReply
{
    public function for(string $text): ?array
    {
        $normalize=fn($s)=>trim(preg_replace('/\s+/u',' ',\App\Support\ArabicTextNormalizer::normalize($s)));
        $raw=trim($text);
        $text=$normalize($text);
        $map=json_decode((string)config('agent.social.replies','{}'),true);
        if(!is_array($map))return null;
        foreach($map as $input=>$reply){
            if(!is_string($reply) || ($text==='' ? $raw!==trim((string)$input) : $text!==$normalize((string)$input)))continue;
            return $reply===''?['messages'=>[],'no_reply'=>true]:['messages'=>[$reply]];
        }
        return null;
    }
}
