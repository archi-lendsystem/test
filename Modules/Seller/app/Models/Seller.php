<?php

namespace Modules\Seller\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\BLLeadCreation\Models\BLLeadCreation;

class Seller extends Model
{
    use SoftDeletes;

    protected $table = 'sellers';

    protected $fillable = [
        'lead_uuid',
        'lead_id',
        'product_id',
        'created_by',
        'seller_sno',

    ];

    protected $casts = [
        'seller_sno' => 'integer',
    ];

    /*
    |--------------------------------------------------------------------------
    | Lead Relation
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

    /*
    |--------------------------------------------------------------------------
    | Seller Detail
    |--------------------------------------------------------------------------
    | Add after SellerDetail module is created.
    |--------------------------------------------------------------------------
    */
    public function sellerDetail()
    {
        return $this->hasOne(
            \Modules\SellerDetail\Models\SellerDetail::class,
            'seller_id',
            'id'
        );
    }
}