<?php

namespace Modules\BLSchemeSelect\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\Product\Models\Product;
use Modules\ProductScheme\Models\ProductScheme;


class BLSchemeSelect extends Model
{
  
  use HasFactory;

    protected $table = 'b_l_scheme_selects';

    protected $fillable = [
        'lead_uuid',
            'product_id',
        'product_scheme_uuid',
    ];

    protected $casts = [
        'id'         => 'integer',
         'product_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

  
    public function lead(): BelongsTo
    {
        return $this->belongsTo(
            BLLeadCreation::class,
            'lead_uuid',
            'uuid'
        );
    }

    public function product(): BelongsTo
{
    return $this->belongsTo(
        Product::class,
        'product_id',
        'id'
    );
}

   
    public function productScheme(): BelongsTo
    {
        return $this->belongsTo(
            ProductScheme::class,
            'product_scheme_uuid',
            'uuid'
        );
    }
}
