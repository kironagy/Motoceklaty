<?php
namespace App\Domain\Requests;
use App\Models\InstallmentRequest;
/** Identity-based duplicate intake protection, across staff and entry points. */
class RequestDuplicateGuard
{
    public function find(array $data, ?int $except = null, bool $lock = false): ?InstallmentRequest
    {
        $nid=$this->digits((string)($data['applicant_national_id']??''));
        $phones=[];
        foreach(['applicant_phone','applicant_phone_2'] as $key){
            $p=$this->digits((string)($data[$key]??''));
            if(str_starts_with($p,'0020'))$p=substr($p,4);
            elseif(str_starts_with($p,'20'))$p=substr($p,2);
            if(strlen($p)===10 && str_starts_with($p,'1'))$p='0'.$p;
            if(strlen($p)===11)$phones=array_merge($phones,[$p,'2'.$p,'+2'.$p,'002'.$p]);
        }
        if(strlen($nid)!==14)$nid='';
        if($nid==='' && $phones===[])return null;
        return InstallmentRequest::query()->when($except,fn($q)=>$q->whereKeyNot($except))
            ->where(function($q)use($nid,$phones){
                $q->whereRaw('1=0');
                if($nid!=='')$q->orWhere('applicant_national_id',$nid);
                if($phones!==[]){
                    $values=array_values(array_unique(array_map(fn($p)=>str_replace('+','',$p),$phones)));
                    $marks=implode(',',array_fill(0,count($values),'?'));
                    foreach(['applicant_phone','applicant_phone_2']as$column){
                        $expr="REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($column, ' ', ''), '-', ''), '+', ''), '(', ''), ')', '')";
                        $q->orWhereRaw($expr.' IN ('.$marks.')',$values);
                    }
                }
            })->when($lock,fn($q)=>$q->lockForUpdate())->latest('id')->first();
    }
    public function withCreationLock(callable $callback): mixed
    {
        $db=\Illuminate\Support\Facades\DB::connection();
        $key='motogate-intake-'.substr(hash('sha256',$db->getDatabaseName()),0,24);
        if($db->getDriverName()!=='mysql')return \Illuminate\Support\Facades\Cache::lock($key,30)->block(15,$callback);
        $lock=$db->selectOne('SELECT GET_LOCK(?, 15) AS acquired',[$key]);
        if((int)($lock->acquired??0)!==1)throw \Illuminate\Validation\ValidationException::withMessages(['intake'=>'في طلبات بتتحفظ دلوقتي، حاول مرة تانية بعد لحظات.']);
        try{return $callback();}finally{$db->selectOne('SELECT RELEASE_LOCK(?) AS released',[$key]);}
    }

    public function digits(string $value): string
    {
        return preg_replace('/\D/','',strtr($value,array_combine(preg_split('//u','٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹',-1,PREG_SPLIT_NO_EMPTY),str_split('01234567890123456789'))));
    }
}
