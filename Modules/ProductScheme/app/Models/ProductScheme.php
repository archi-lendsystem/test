<?php

namespace Modules\ProductScheme\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\Models\Product;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\BLSchemeSelect\Models\BLSchemeSelect;


class ProductScheme extends Model
{
    use HasFactory,HasUuids, SoftDeletes;

    protected $table = 'product_schemes';

    protected $fillable = [
        'uuid',

        // Product relation
        'product_id',

        'product_name',

        // Scheme checklist fields
        'scheme_name',
        'sub_loan_type',
        'interest_rate',
        'repayment_frequency',
        'lpf_rate',

        // new fields
        'platform_charges',
        'sms_charges',
        'minimum_loan_amount',
        'maximum_loan_amount',
        'minimum_pf',
        'maximum_pf',


        'moratorium_days',
        'calculation_method',
        'overdue_interest_on',
        'emi_round_off_type',
        'emi_round_up_value',

         'tenure_type',
    // 'tenure_value',
    'minimum_tenure',
    'maximum_tenure',
        'tenure_value',
        'tenure_unit',
        'valid_from',
        'valid_till',

        // Common fields
        'remarks',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active'             => 'boolean',
        'interest_rate'         => 'decimal:2',
        'lpf_rate'              => 'decimal:2',

        'platform_charges'      => 'float',
        'sms_charges'           => 'float',
        'minimum_loan_amount'   => 'integer',
        'maximum_loan_amount'   => 'integer',
        'minimum_pf'            => 'decimal:2',
        'maximum_pf'            => 'decimal:2',

        
        'emi_round_up_value'    => 'decimal:2',
        'moratorium_days'       => 'integer',
        'tenure_value'          => 'integer',
          'minimum_tenure'        => 'integer',
    'maximum_tenure'        => 'integer',
        'valid_from'            => 'date:Y-m-d',
        'valid_till'            => 'date:Y-m-d',
        'created_at'            => 'datetime',
        'updated_at'            => 'datetime',
        'deleted_at'            => 'datetime',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

public function schemeSelections(): HasMany
{
    return $this->hasMany(
        BLSchemeSelect::class,
        'product_scheme_uuid',
        'uuid'
    );
}
}