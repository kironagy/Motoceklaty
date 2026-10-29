<?php
namespace App\Agent\Runtime;
/** Only phrases explicitly forbidden in dashboard policy are removed. */
class ReplyPhrasePolicy
{
    public function apply(array $args): array
    {
        $pattern=trim((string)config('agent.safety.banned_reply_pattern'));
        if($pattern===''||@preg_match($pattern,'')===false)return ['changed'=>false,'args'=>$args];
        $changed=false;$messages=[];
        foreach((array)($args['messages']??[]) as $message){
            $sentences=preg_split('/(?<=[.!؟?])\s*|\n+/u',(string)$message,-1,PREG_SPLIT_NO_EMPTY)?:[];
            $kept=[];
            foreach($sentences as $sentence){
                $plain=preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u','',$sentence);
                if(preg_match($pattern,$plain)){$changed=true;continue;}
                $kept[]=$sentence;
            }
            if($kept!==[])$messages[]=implode("\n",$kept);
        }
        if($changed)$args['messages']=$messages;
        return ['changed'=>$changed,'args'=>$args];
    }
}
