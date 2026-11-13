<?php

namespace Modules\BLSaction\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Illuminate\Support\Str;

class BLSaction extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'b_l_sactions';

    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'lead_uuid',
        'sanction_number',
        'version',            
        'pdf_path',           
        'sanctioned_amount',
        'interest_rate',
        'tenure_months',
        'emi_amount',
        'sanction_date',
        'sanction_expiry_date',
        'loan_type',
        'repayment_mode',
        'terms_and_conditions',
        'remarks',
        'status',
        'confirmed_by',
        'confirmed_at',
        'generated_by',
     
    ];

    protected $casts = [
        'sanctioned_amount'    => 'decimal:2',
        'interest_rate'        => 'decimal:2',
        'emi_amount'           => 'decimal:2',
        'tenure_months'        => 'integer',
        'version'              => 'integer',   
        'sanction_date'        => 'date',
        'sanction_expiry_date' => 'date',
        'confirmed_at'         => 'datetime',
        'created_at'           => 'datetime',
        'updated_at'           => 'datetime',
        'deleted_at'           => 'datetime',
    ];

    
    protected static function boot()
    {
        parent::boot();
       
    }



    public function leadCreation()
    {
        return $this->belongsTo(BLLeadCreation::class, 'lead_uuid', 'uuid');
    }

    public function confirmedBy()
    {
        return $this->belongsTo(
            \Modules\UserManagement\Models\User::class, 
            'confirmed_by'
        );
    }

    public function generatedBy()
    {
        return $this->belongsTo(
            \Modules\UserManagement\Models\User::class, 
            'generated_by'
        );
    }


    public function scopeLatestVersion($query)
    {
        return $query->orderBy('version', 'desc');
    }
}
