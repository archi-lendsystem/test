<?php

namespace Modules\BLLeadCreation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\BLWorkDetails\Models\BLWorkDetails;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Modules\BLJourney\Models\BLJourney;
use Modules\UserManagement\Models\User;
use Modules\BLLeadAssignment\Models\BLLeadAssignment;
use Modules\BLSchemeSelect\Models\BLSchemeSelect;
use Modules\BLDisbursment\Models\BLDisbursment;
use Modules\Customers\Models\Customer;
use Modules\BLCam\Models\BLCam;
use Modules\Product\Models\Product;
use Modules\Pan\Models\Pan;
use Modules\Experian\Models\Experian;
use Modules\Transunion\Models\Transunion;
use Modules\Crif\Models\CrifReport;



class BLLeadCreation extends Model
{
    use SoftDeletes;

    protected $table = 'b_l_lead_creations';

    protected $fillable = [
        'uuid',
        'lead_id',
        'created_by',
        'branch_id',
        'product_id',
        'loan_type',
        'customer_type',
        'is_reloan',
        'previous_lead_uuid',
        'sub_loan_type',
        'source_by',
        'email',
        'mobile_number',
        'loan_amount',
        'loan_tenur',
    ];

    protected $casts = ['is_reloan' => 'boolean'];

    public function previousLead(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_lead_uuid', 'uuid');
    }

    public function nextReloans(): HasMany
    {
        return $this->hasMany(self::class, 'previous_lead_uuid', 'uuid');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    public function workDetails(): HasMany
    {
        return $this->hasMany(BLWorkDetails::class, 'lead_uuid', 'uuid');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(
            \Modules\UserManagement\Models\OrganizationBranch::class,
            'branch_id'
        );
    }

    public function applicantDetails(): HasMany
    {
        return $this->hasMany(BLApplicantDetails::class, 'lead_uuid', 'uuid');
    }

    public function currentJourney(): HasOne
    {
        return $this->hasOne(BLJourney::class, 'lead_id', 'lead_id')
            ->where('is_latest', true)
            ->latestOfMany();
    }

    public function currentAssignment(): HasOne
    {
        return $this->hasOne(BLLeadAssignment::class, 'lead_uuid', 'uuid')
            ->where('is_active', true)
            ->latest('assigned_at');
    }

    public function assignedToUser()
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function disbursement()
    {
        return $this->hasOne(BLDisbursment::class, 'lead_uuid', 'uuid');
    }

    public function schemeSelect(): HasOne
    {
        return $this->hasOne(
            BLSchemeSelect::class,
            'lead_uuid',
            'uuid'
        );
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(
            Customer::class,
            'customer_id',
            'id'
        );
    }
    public function pan(): HasMany
    {
        return $this->hasMany(
            Pan::class,
            'lead_uuid',
            'uuid'
        );
    }

    public function latestCam(): HasOne
    {
        return $this->hasOne(
            BLCam::class,
            'lead_uuid',
            'uuid'
        )->latestOfMany();
    }

   public function experianReports(): HasMany
{
    return $this->hasMany(
        Experian::class,
        'lead_uuid',
        'uuid'
    );
}

    public function latestExperianReport(): HasOne
    {
        return $this->hasOne(
            Experian::class,
            'lead_uuid',
            'uuid'
        )->latestOfMany();
    }
    public function transunionReports(): HasMany
    {
        return $this->hasMany(
            Transunion::class,
            'lead_uuid',
            'uuid'
        );
    }

    public function latestTransunionReport(): HasOne
    {
        return $this->hasOne(
            Transunion::class,
            'lead_uuid',
            'uuid'
        )->latestOfMany();
    }

        public function sellers()
    {
        return $this->hasMany(
            Seller::class,
            'lead_uuid',
            'uuid'
        );
    }

        public function properties()
    {
        return $this->hasMany(
            Property::class,
            'lead_uuid', // properties table column
            'uuid'       // b_l_lead_creations table column
        );
    }

    public function crifReports(): HasMany
{
    return $this->hasMany(
        CrifReport::class,
        'lead_uuid',
        'uuid'
    );
}
}



