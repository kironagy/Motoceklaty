<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsLog extends Model
{
    protected $fillable = ['installment_request_id', 'phone', 'message', 'status', 'response'];

    public function installmentRequest()
    {
        return $this->belongsTo(InstallmentRequest::class);
    }
}
