<?php
namespace App\Agent\Runtime;
/** Exact, dashboard-approved social responses; no business facts are inferred. */
class SocialReply
{
    public function for(string $text): ?array
    {
        $normalize=fn($s)=>trim(preg_replace('/\s+/u',' ',\App\Support\ArabicTextNormalizer::normalize($s)));
        $raw=trim($text);
        if (preg_match('/^[\s.؟?!،…👍🏻🏼🏽🏾🏿❤️]+$/u', $raw) && $raw !== '') {
            return ['messages'=>[], 'no_reply'=>true];
        }
        $text=$normalize($text);
        $map=json_decode((string)config('agent.social.replies','{}'),true);
        if(!is_array($map))return null;
        foreach($map as $input=>$reply){
            $expected=$normalize((string)$input);
            // A failed turn can leave the same greeting queued twice before
            // any bot reply exists. Treat repeated identical social messages
            // as one, instead of sending them to the model to improvise.
            $parts=array_values(array_filter(array_map($normalize,preg_split('/[\r\n]+/u',$raw)?:[]),fn($part)=>$part!==''));
            $sameRepeated=$parts!==[] && count(array_unique($parts))===1 && $parts[0]===$expected;
            if(!is_string($reply) || ($text==='' ? $raw!==trim((string)$input) : $text!==$expected && !$sameRepeated))continue;
            return $reply===''?['messages'=>[],'no_reply'=>true]:['messages'=>[$reply]];
        }
        return null;
    }
}
