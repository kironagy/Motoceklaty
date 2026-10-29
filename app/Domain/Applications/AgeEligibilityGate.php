<?php
namespace App\Domain\Applications;
use App\Domain\Memory\CustomerMemory;
use App\Models\WhatsappConversation;
/** Age screening belongs to an applicant, and survives unrelated work updates. */
class AgeEligibilityGate
{
    private function key(array $profile, bool $other = false): string
    {
        return $other || ($profile['applicant']??'customer')==='someone_else'
            ? 'other:'.($profile['applicant_relation']??'unclear') : 'customer';
    }
    public function record(int $conversationId, int $age, array $result, bool $other): void
    {
        $c=WhatsappConversation::findOrFail($conversationId);$profile=app(WorkProfiles::class)->get($conversationId)??[];
        $key=$this->key($other?$profile:[], $other);
        $state=$c->state??[];
        $state['age_eligibility'][$key]=['age'=>$age,'status'=>$result['status']??'unknown','reasons'=>$result['reasons']??[],'checked_at'=>now()->toIso8601String()];
        $c->update(['state'=>$state]);
    }
    public function for(WhatsappConversation $conversation): ?array
    {
        $profile=app(WorkProfiles::class)->get($conversation->id)??[];
        $other=($profile['applicant']??'customer')==='someone_else';
        if($other){
            $quote=trim((string)($profile['applicant_quote']??''));
            $other=$quote!=='' && app(\App\Domain\Conversations\CustomerStatements::class)->messageContainingQuote($conversation->id,$quote)!==null;
        }
        $key=$this->key($other?$profile:[], $other);
        $record=($conversation->fresh()->state??[])['age_eligibility'][$key]??null;
        $memoryAge = !$other && $conversation->customer_id ? app(CustomerMemory::class)->statedAge($conversation->customer_id) : null;
        // Older conversations may have an audited refusal but no saved age memory.
        // Only self-applicant checks can be attributed safely without a stored scope.
        if ($record === null && $memoryAge === null && ! $other) {
            $steps = \App\Models\AiTraceStep::whereIn('trace_id', \App\Models\AiTrace::where('conversation_id', $conversation->id)->select('id'))
                ->where('tool_name','check_eligibility')->latest('id')->limit(20)->get(['args_redacted','result_redacted']);
            foreach ($steps as $step) {
                $args = $step->args_redacted ?? []; $result = $step->result_redacted ?? [];
                $data = $result['data'] ?? [];
                if (($args['about'] ?? 'customer') !== 'customer' || ! ($result['ok'] ?? false) || empty($args['age'])
                    || ! in_array($data['status'] ?? '', ['eligible','not_eligible'], true)) continue;
                $record = ['age'=>(int)$args['age'], 'status'=>$data['status'], 'reasons'=>$data['reasons'] ?? []];
                break;
            }
        }
        $age=$record['age']??$memoryAge;
        $minimum=CustomerMemory::minimumAge();
        $codes=array_column($record['reasons']??[],'code');
        if(!(($age!==null&&$minimum!==null&&$age<$minimum)||(($record['status']??'')==='not_eligible'&&array_intersect($codes,['AGE_OUT_OF_RANGE','AGE_BELOW_MINIMUM_STATED']))))return null;
        return ['blocked'=>true,'age'=>$age,'minimum'=>$minimum,'applicant_key'=>$key,'work_questions_required'=>false,
            'instruction'=>(string)config('agent.eligibility.age_stop_instructions'),
            'customer_message'=>strtr((string)config('agent.eligibility.age_block_message'),['{age}'=>(string)$age,'{minimum}'=>(string)$minimum])];
    }
}
