<?php
namespace App\Domain\Applications;
use App\Models\{Application,Customer,FormLink,InstallmentRequest,WhatsappConversation};
use Illuminate\Database\Eloquent\Builder;
/** Shared ownership lookup: a typed request number alone never grants access. */
class RequestIdentity
{
    public function phones(?Customer $customer, WhatsappConversation $conversation): array
    {
        $phones=[];
        foreach([$customer?->phone,$conversation->real_phone,$conversation->phone] as $phone){
            $digits=preg_replace('/\D+/', '', explode(':',(string)$phone)[0]);
            if(preg_match('/^00201[0125]\d{8}$/',$digits))$digits=substr($digits,2);
            if(preg_match('/^201[0125]\d{8}$/',$digits))$digits=substr($digits,1);
            if(!preg_match('/^01[0125]\d{8}$/',$digits))continue;
            array_push($phones,$digits,'2'.$digits,'+2'.$digits,'002'.$digits);
        }
        return array_values(array_unique($phones));
    }
    public function query(?Customer $customer, WhatsappConversation $conversation): Builder
    {
        $ids=$customer?Application::where('customer_id',$customer->id)->whereNotNull('installment_request_id')->pluck('installment_request_id')->all():[];
        $ids=array_merge($ids,FormLink::where('whatsapp_conversation_id',$conversation->id)->whereNotNull('installment_request_id')->pluck('installment_request_id')->all());
        $phones=$this->phones($customer,$conversation);
        return InstallmentRequest::query()->where(function($q)use($conversation,$ids,$phones){
            $q->where('whatsapp_conversation_id',$conversation->id);
            if($ids!==[])$q->orWhereIn('id',$ids);
            if($phones!==[])$q->orWhereIn('applicant_phone',$phones);
        });
    }
}
