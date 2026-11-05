<?php

namespace Modules\BLOfficeDetails\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use Modules\BLOfficeDetails\Models\BLOfficeDetail;
use Modules\BLOfficeDetails\Transformers\BLOfficeDetailResource;

class BLOfficeDetailsController extends Controller
{
    use ApiResponse;

 
    public function store(Request $request, $lead_uuid, $applicant_id)
    {
        $request->merge([
            'lead_uuid' => $lead_uuid,
            'applicant_id' => $applicant_id,
        ]);

        $validated = $request->validate([
            'lead_uuid'         => ['required', 'exists:b_l_lead_creations,uuid'],
            'created_by'        => ['required', 'exists:users,id'],
            'lead_id'           => ['required'],
            'applicant_type'    => ['required'],
            'applicant_sno'     => ['required', 'integer'],
            'applicant_id'      => ['required', 'integer'],
            'address'           => ['required'],
            'landmark'          => ['required'],
            'pincode'           => ['required'],
            'district'          => ['required'],
            'city'              => ['required'],
            'state'             => ['required'],
            'working_in_months' => ['required', 'integer'],
        ]);

        $existing = BLOfficeDetail::where('lead_uuid', $validated['lead_uuid'])
            ->where('applicant_id', $validated['applicant_id'])
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'Office detail already exists for this lead and applicant.',
                'data' => null,
            ], 409);
        }

        $data = BLOfficeDetail::create($validated);

        return (new BLOfficeDetailResource($data->load('creator')))
            ->additional([
                'success' => true,
                'message' => 'Office detail created successfully',
            ])
            ->response()
            ->setStatusCode(201);
    }

 
    public function show($lead_uuid, $applicant_id)
    {
        $data = BLOfficeDetail::with('creator')
            ->where('lead_uuid', $lead_uuid)
            ->where('applicant_id', $applicant_id)
            ->first();

        if (!$data) {
            return response()->json([
                'success' => false,
                'message' => 'Office detail not found for this lead and applicant.',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Office detail fetched successfully',
            'data' => new BLOfficeDetailResource($data),
        ], 200);
    }

 
    public function update(Request $request, $lead_uuid, $applicant_id)
    {
        $data = BLOfficeDetail::where('lead_uuid', $lead_uuid)
            ->where('applicant_id', $applicant_id)
            ->first();

        if (!$data) {
            return response()->json([
                'success' => false,
                'message' => 'Office detail not found for this lead and applicant.',
                'data' => null,
            ], 404);
        }

        $request->merge([
            'lead_uuid' => $lead_uuid,
            'applicant_id' => $applicant_id,
        ]);

        $validated = $request->validate([
            'lead_uuid'         => ['required', 'exists:b_l_lead_creations,uuid'],
            'created_by'        => ['required', 'exists:users,id'],
            'lead_id'           => ['required'],
            'applicant_type'    => ['required'],
            'applicant_sno'     => ['required', 'integer'],
            'applicant_id'      => ['required', 'integer'],
            'address'           => ['required'],
            'landmark'          => ['required'],
            'pincode'           => ['required'],
            'district'          => ['required'],
            'city'              => ['required'],
            'state'             => ['required'],
            'working_in_months' => ['required', 'integer'],
        ]);

        $data->update($validated);

        return (new BLOfficeDetailResource($data->load('creator')))
            ->additional([
                'success' => true,
                'message' => 'Office detail updated successfully',
            ]);
    }
}
