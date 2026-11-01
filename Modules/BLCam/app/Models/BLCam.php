<?php

namespace Modules\BLCam\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class BLCam extends Model
{
    use HasFactory;


    protected $fillable = [
        'lead_uuid',
        'status',
        'forward_to',
        'forward_by',
        'remarks',
        'loan_amount',
        'loan_tenure',
        'roi',
        'pf',
        'gst',
     
    ];


}
