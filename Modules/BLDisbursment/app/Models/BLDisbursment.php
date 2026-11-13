<?php

namespace Modules\BLDisbursment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\BLLeadCreation\Models\BLLeadCreation;


class BLDisbursment extends Model
{
    
   use HasFactory, SoftDeletes;

    protected $table = 'b_l_disbursments';

    protected $fillable = [
        'lead_uuid',
        'disbursement_amount',
        'bank_name',
        'utr_number',
         'disbursement_date',
           'loan_number',
               'first_emi_date',
    ];

    protected $casts = [
        'disbursement_amount' => 'decimal:2',
           'disbursement_date'   => 'date:Y-m-d',
            'first_emi_date'      => 'date:Y-m-d',
    ];

    
    public function lead()
    {
        return $this->belongsTo(BLLeadCreation::class, 'lead_uuid', 'uuid');
    }
}
