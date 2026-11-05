<?php

namespace Modules\PropertyDetail\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\BLLeadCreation\Models\BLLeadCreation;


class PropertyDetail extends Model
{
    use SoftDeletes;

    protected $table = 'property_details';

    protected $fillable = [
        'lead_uuid',
        'lead_id',
        'product_id',
        'created_by',
        'pd_property_owner',
        'pd_collateral_usage',
        'pd_collateral_type',
        'pd_property_categorization',
        'pd_collateral_status',
        'pd_property_ownership',
        'pd_property_type',
        'pd_occupancy_status',
        'pd_property_address',
        'pd_landmark',
        'pincode',
        'district',
        'city',
        'state',
        'pd_property_paper',
    ];

    protected $casts = [
        'pd_property_owner' => 'array',
    ];

    public function leadCreation(): BelongsTo
    {
        return $this->belongsTo(BLLeadCreation::class, 'lead_uuid', 'uuid');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }
}
