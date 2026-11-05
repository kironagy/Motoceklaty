<?php
namespace App\Agent\Runtime;
use App\Models\InstallmentSystem;
/** Financial policy is live data, never a remembered lesson. */
class LiveInstallmentRates
{
    public function reply(): ?string
    {
        $lines=[];
        foreach(InstallmentSystem::where('is_active',true)->with(['installmentPlans'=>fn($q)=>$q->where('is_active',true)->where('months',12)])->get() as $system){
            foreach($system->installmentPlans as $plan){
                $fee=(float)$system->administrative_fees;
                $rate=rtrim(rtrim(number_format((float)$plan->interest_percent,2,'.',''),'0'),'.');
                $key=$rate.':'.$fee;
                if(!isset($lines[$key])) $lines[$key]=$system->name.': '.$rate.'٪ على السنة'.($fee>0?' + '.rtrim(rtrim(number_format($fee,2,'.',''),'0'),'.').'٪ مصاريف إدارية مرة واحدة وقت الاستلام':' بدون مصاريف إدارية');
            }
        }
        return $lines ? 'حسب أنظمة التقسيط المفعلة حاليًا: '.implode('، أو ',array_values($lines)).'. النظام المتاح والقسط الفعلي بيتحددوا حسب الموديل وشروط التمويل؛ الحساب من عرض التقسيط، مش رقم تقريبي.' : null;
    }
}
