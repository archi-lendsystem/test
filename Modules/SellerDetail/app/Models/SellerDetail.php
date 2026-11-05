<?php

namespace Modules\SellerDetail\Models;

use Illuminate\Database\Eloquent\Model;

use Modules\Seller\Models\Seller;
use Modules\BLLeadCreation\Models\BLLeadCreation;

class SellerDetail extends Model
{
    protected $table = 'seller_details';

    protected $fillable = [
        'seller_id',

        'lead_uuid',

        'product_id',
    'seller_name',
        'mobile_number',
        'email',
        'pan_number',
        'aadhar_number',

        'current_address',
        'current_landmark',
        'current_pincode',
        'current_city',
        'current_district',
        'current_state',
        'current_staying_in_years',

        'same_as_current_address',

        'permanent_address',
        'permanent_pincode',
        'permanent_city',
        'permanent_district',
        'permanent_state',

        'has_passport',
        'passport_number',

        'bank_account_holder_name',
        'bank_account_number',
        'bank_name',
        'ifsc_code',

        'pan_document',
        'address_document',
        'passport_file',
        'bank_statement',
        'passbook_image',
    ];

    protected $casts = [
        'same_as_current_address'
            => 'boolean',

        'has_passport'
            => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Seller
    |--------------------------------------------------------------------------
    */
    public function seller()
    {
        return $this->belongsTo(
            Seller::class,
            'seller_id',
            'id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Lead
    |--------------------------------------------------------------------------
    */
    public function lead()
    {
        return $this->belongsTo(
            BLLeadCreation::class,
            'lead_uuid',
            'uuid'
        );
    }
}