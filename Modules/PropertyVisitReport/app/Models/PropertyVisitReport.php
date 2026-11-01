<?php

namespace Modules\PropertyVisitReport\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

// Change these imports according to your actual model namespaces
use Modules\Product\App\Models\Product;
use Modules\BL\App\Models\BLLeadCreation;

class PropertyVisitReport extends Model
{
    use HasFactory;

    protected $table = 'property_visit_reports';

    protected $fillable = [
        'lead_uuid',
        'product_id',

        // General Details
        'pd_done_by',
        'pd_date',
        'meeting_place',
        'person_meet',

        // Residence Details
        'loan_purpose',
        'residence_type',
        'staying_together',
        'years_at_residence',

        // Property Details
        'property_type',
        'property_sub_type',
        'construction_status',
        'age_of_property',

        'total_area',
        'buildup_area',
        'num_floors',
        'floor_number',

        // Valuation Details
        'market_value',
        'distress_value',
        'circle_rate',
        'loan_amount',

        // Ownership / Legal
        'ownership_type',
        'legal_docs_verified',
        'encumbrance_status',
        'property_condition',

        // Location Details
        'neighbourhood_type',
        'road_width',
        'distance_from_branch',

        'pd_with_owner',

        // Documents / Remarks
        'documents',
        'remarks',

        // Multiple Images
        'site_images',
    ];

    protected $casts = [
        'pd_date' => 'date',

        'documents' => 'array',
        'site_images' => 'array',

        'years_at_residence' => 'integer',
        'age_of_property' => 'integer',
        'num_floors' => 'integer',
        'floor_number' => 'integer',

        'total_area' => 'decimal:2',
        'buildup_area' => 'decimal:2',

        'market_value' => 'decimal:2',
        'distress_value' => 'decimal:2',
        'circle_rate' => 'decimal:2',
        'loan_amount' => 'decimal:2',

        'road_width' => 'decimal:2',
        'distance_from_branch' => 'decimal:2',
    ];

    /**
     * Property Visit Report belongs to a Lead.
     */
    public function lead()
    {
        return $this->belongsTo(
            BLLeadCreation::class,
            'lead_uuid',
            'uuid'
        );
    }

    /**
     * Property Visit Report belongs to a Product.
     */
    public function product()
    {
        return $this->belongsTo(
            Product::class,
            'product_id',
            'id'
        );
    }
}