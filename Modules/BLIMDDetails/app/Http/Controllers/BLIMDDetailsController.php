<?php

namespace Modules\BLIMDDetails\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\BLIMDDetails\Models\BLIMDDetails;
use Modules\BLLeadCreation\Models\BLLeadCreation; 

class BLIMDDetailsController extends Controller
{
    use ApiResponse;

  
    public function show(string $uuid)
    {
        $detail = BLIMDDetails::where('lead_uuid', $uuid)
            ->with(['creator', 'leadCreation'])
            ->first();

        if (!$detail) {
            return $this->errorResponse('IMD detail not found', 404);
        }

        return $this->successResponse($detail, 'IMD detail fetched successfully');
    }


    public function store(Request $request)
    {
        $validated = $request->validate([
            "created_by"                     =>'nullable|string|max:255',
            'lead_uuid'                      => 'required|uuid|exists:b_l_lead_creations,uuid',
            'imd_waiver'                     => 'nullable|string|max:255',
            'imd_waiver_remarks'             => 'nullable|string|max:255',
            'imd_instrument_type'            => 'nullable|string|max:255',
            'imd_amount'                     => 'nullable|string|max:255',
            'imd_clearing_date'              => 'nullable|string|max:255',
            'imd_payment_transaction_number' => 'nullable|string|max:255',
            'imd_status'                     => 'nullable|string|max:255',
            'imd_picture'                    => 'nullable|string|max:255',
        ]);

        
        $lead = BLLeadCreation::where('uuid', $validated['lead_uuid'])->first();

        if (!$lead) {
            return $this->errorResponse('Lead not found for given lead_uuid', 404);
        }

        $validated['lead_id'] = $lead->lead_id; 

        $detail = BLIMDDetails::create($validated);

        return $this->successResponse($detail, 'IMD detail created successfully', 201);
    }

    
    public function update(Request $request, string $uuid)
    {
        $validated = $request->validate([
            'imd_waiver'                     => 'nullable|string|max:255',
            'imd_waiver_remarks'             => 'nullable|string|max:255',
            'imd_instrument_type'            => 'nullable|string|max:255',
            'imd_amount'                     => 'nullable|numeric',
            'imd_clearing_date'              => 'nullable|string|max:255',
            'imd_payment_transaction_number' => 'nullable|string|max:255',
            'imd_status'                     => 'nullable|string|max:255',
            'imd_picture'                    => 'nullable|string|max:255',
        ]);

        $detail = BLIMDDetails::where('lead_uuid', $uuid)->first();

        if (!$detail) {
            return $this->errorResponse('IMD detail not found', 404);
        }

        $lead = BLLeadCreation::where('uuid', $uuid)->first();
        if ($lead) {
            $validated['lead_id'] = $lead->lead_id;
        }

        $detail->update($validated);

        return $this->successResponse($detail->fresh(), 'IMD detail updated successfully');
    }
}
