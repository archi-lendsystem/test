<?php

namespace Modules\Charges\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Charges\Models\Charge;
use Modules\Charges\Transformers\ChargeResource;
use Modules\BLSaction\Models\BLSaction;
use Modules\BLDisbursment\Models\BLDisbursment;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Throwable;

class ChargesController extends Controller
{
    private function isLeadDisbursed(string $lead_uuid): bool
    {
        return BLDisbursment::query()
            ->where('lead_uuid', $lead_uuid)
            ->exists();
    }



    public function show(string $lead_uuid): JsonResponse
    {
        try {

            Log::channel('bl_audit')->info('Charges fetch request received.', [
                'lead_uuid' => $lead_uuid,
                'user_id'   => auth()->id(),
                'ip_address' => request()->ip(),
            ]);

            $charge = Charge::query()
                ->where('lead_uuid', $lead_uuid)
                ->first();


            $sanction = BLSaction::query()
                ->where('lead_uuid', $lead_uuid)
                ->orderByDesc('id')
                ->first();


            if ($charge) {
                $data = (new ChargeResource($charge))
                    ->resolve(request());
            } else {
                $data = [
                    'id'                  => null,
                    'lead_uuid'           => $lead_uuid,
                    'platform_charges'    => null,
                    'sms_changes'         => null,
                    'application_charges' => null,
                    'other_charges'       => null,
                    'total_charges'       => null,
                    'created_at'          => null,
                    'updated_at'          => null,
                ];
            }


            $data['sanction_details'] = $sanction
                ? [
                    'id'                => $sanction->id,
                    'lead_uuid'         => $sanction->lead_uuid,
                    'sanction_number'   => $sanction->sanction_number,
                    'version'           => $sanction->version !== null
                        ? (int) $sanction->version
                        : null,
                    'pdf_path'          => $sanction->pdf_path,

                    'sanctioned_amount' => $sanction->sanctioned_amount !== null
                        ? (float) $sanction->sanctioned_amount
                        : null,

                    'interest_rate' => $sanction->interest_rate !== null
                        ? (float) $sanction->interest_rate
                        : null,

                    'tenure_months' => $sanction->tenure_months !== null
                        ? (int) $sanction->tenure_months
                        : null,

                    'emi_amount' => $sanction->emi_amount !== null
                        ? (float) $sanction->emi_amount
                        : null,

                    'sanction_date' => $sanction->sanction_date
                        ? $sanction->sanction_date->format('Y-m-d')
                        : null,

                    'sanction_expiry_date' => $sanction->sanction_expiry_date
                        ? $sanction->sanction_expiry_date->format('Y-m-d')
                        : null,

                    'loan_type'            => $sanction->loan_type,
                    'repayment_mode'       => $sanction->repayment_mode,
                    'terms_and_conditions' => $sanction->terms_and_conditions,
                    'remarks'              => $sanction->remarks,
                    'status'               => $sanction->status,
                    'confirmed_by'         => $sanction->confirmed_by,

                    'confirmed_at' => $sanction->confirmed_at
                        ? $sanction->confirmed_at->toIso8601String()
                        : null,

                    'generated_by' => $sanction->generated_by,

                    'created_at' => $sanction->created_at
                        ? $sanction->created_at->toIso8601String()
                        : null,

                    'updated_at' => $sanction->updated_at
                        ? $sanction->updated_at->toIso8601String()
                        : null,
                ]
                : null;

            Log::channel('bl_audit')->info('Charges and sanction details fetched successfully.', [
                'lead_uuid'    => $lead_uuid,
                'charge_found' => (bool) $charge,
                'sanction_found' => (bool) $sanction,
                'charge_id'    => $charge?->id,
                'sanction_id'  => $sanction?->id,
                'user_id'      => auth()->id(),
            ]);

            return response()
                ->json([
                    'success' => true,
                    'message' => $charge
                        ? 'Charges and sanction details fetched successfully.'
                        : 'Charges not available. Sanction details fetched successfully.',
                    'data' => $data,
                ], 200)
                ->header(
                    'Cache-Control',
                    'no-store, no-cache, must-revalidate, max-age=0'
                )
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0');
        } catch (Throwable $e) {
            Log::channel('bl_audit')->error(
                'Failed to fetch charges and sanction details.',
                [
                    'lead_uuid' => $lead_uuid,
                    'user_id'   => auth()->id(),
                    'error'     => $e->getMessage(),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                ]
            );

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong while fetching charges and sanction details.',
                'data'    => null,
            ], 500);
        }
    }

    public function store(
        Request $request,
        string $lead_uuid
    ): JsonResponse {
        try {
            if ($this->isLeadDisbursed($lead_uuid)) {
                Log::channel('bl_audit')->warning('Charges creation blocked because lead is already disbursed.', [
                    'action'    => 'store',
                    'lead_uuid' => $lead_uuid,
                    'user_id'   => auth()->id(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Charges cannot be created because this lead has already been disbursed.',
                    'data'    => null,
                ], 422);
            }

            $validated = $this->validateCharges(
                $request,
                $lead_uuid
            );

            $charge = DB::transaction(function () use (
                $validated,
                $lead_uuid
            ) {
                $charge = Charge::query()->updateOrCreate(
                    [
                        'lead_uuid' => $lead_uuid,
                    ],
                    $validated
                );

                $this->calculateTotalCharges($charge);

                return $charge->refresh();
            });

            $statusCode = $charge->wasRecentlyCreated
                ? 201
                : 200;

            $message = $charge->wasRecentlyCreated
                ? 'Charges created successfully.'
                : 'Charges updated successfully.';

            Log::channel('bl_audit')->info($message, [
                'action'        => $charge->wasRecentlyCreated ? 'create' : 'update',
                'lead_uuid'     => $lead_uuid,
                'charge_id'     => $charge->id,
                'platform_charges' => $charge->platform_charges,
                'sms_changes'   => $charge->sms_changes,
                'application_charges' => $charge->application_charges,
                'other_charges' => $charge->other_charges,
                'total_charges' => $charge->total_charges,
                'user_id'       => auth()->id(),
                'ip_address'    => $request->ip(),
            ]);

            return (new ChargeResource($charge))
                ->additional([
                    'success' => true,
                    'message' => $message,
                ])
                ->response()
                ->setStatusCode($statusCode);
        } catch (ValidationException $e) {

            Log::channel('bl_audit')->warning('Charges validation failed.', [
                'action'    => 'store',
                'lead_uuid' => $lead_uuid,
                'user_id'   => auth()->id(),
                'errors'    => $e->errors(),
            ]);
            throw $e;
        } catch (Throwable $e) {
            Log::channel('bl_audit')->error('Failed to save charges.', [
                'action'    => 'store',
                'lead_uuid' => $lead_uuid,
                'user_id'   => auth()->id(),
                'error'     => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong while saving charges.',
            ], 500);
        }
    }

    public function update(
        Request $request,
        string $lead_uuid
    ): JsonResponse {
        try {
            $charge = Charge::query()
                ->where('lead_uuid', $lead_uuid)
                ->first();

            if (! $charge) {

                Log::channel('bl_audit')->warning('Charges update failed because record was not found.', [
                    'action'    => 'update',
                    'lead_uuid' => $lead_uuid,
                    'user_id'   => auth()->id(),
                ]);
                return response()->json([

                    'success' => false,
                    'message' => 'Charges not found for this lead.',
                    'data'    => null,
                ], 404);
            }

            if ($this->isLeadDisbursed($lead_uuid)) {
                Log::channel('bl_audit')->warning('Charges update blocked because lead is already disbursed.', [
                    'action'    => 'update',
                    'lead_uuid' => $lead_uuid,
                    'charge_id' => $charge->id,
                    'user_id'   => auth()->id(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Charges cannot be updated because this lead has already been disbursed.',
                    'data'    => null,
                ], 422);
            }

            $validated = $this->validateCharges(
                $request,
                $lead_uuid
            );

            DB::transaction(function () use (
                $charge,
                $validated
            ) {
                $charge->update($validated);

                $this->calculateTotalCharges($charge);
            });

            $charge->refresh();

            Log::channel('bl_audit')->info('Charges updated successfully.', [
                'action'        => 'update',
                'lead_uuid'     => $lead_uuid,
                'charge_id'     => $charge->id,
                'platform_charges' => $charge->platform_charges,
                'sms_changes'   => $charge->sms_changes,
                'application_charges' => $charge->application_charges,
                'other_charges' => $charge->other_charges,
                'total_charges' => $charge->total_charges,
                'user_id'       => auth()->id(),
                'ip_address'    => $request->ip(),
            ]);

            return (new ChargeResource($charge))
                ->additional([
                    'success' => true,
                    'message' => 'Charges updated successfully.',
                ])
                ->response();
        } catch (ValidationException $e) {

            Log::channel('bl_audit')->warning('Charges validation failed.', [
                'action'    => 'update',
                'lead_uuid' => $lead_uuid,
                'user_id'   => auth()->id(),
                'errors'    => $e->errors(),
            ]);
            throw $e;
        } catch (Throwable $e) {
            Log::channel('bl_audit')->error('Failed to update charges.', [
                'action'    => 'update',
                'lead_uuid' => $lead_uuid,
                'user_id'   => auth()->id(),
                'error'     => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong while updating charges.',
            ], 500);
        }
    }

    private function calculateTotalCharges(Charge $charge): void
    {
        $totalCharges =
            (float) ($charge->platform_charges ?? 0) +
            (float) ($charge->sms_changes ?? 0) +
            (float) ($charge->application_charges ?? 0) +
            (float) ($charge->other_charges ?? 0);

        $charge->update([
            'total_charges' => round($totalCharges, 2),
        ]);
    }

    private function validateCharges(
        Request $request,
        string $lead_uuid
    ): array {
        $request->merge([
            'lead_uuid' => $lead_uuid,
        ]);

        return $request->validate([
            'lead_uuid' => [
                'required',
                'uuid',
                'exists:b_l_lead_creations,uuid',
            ],

            'platform_charges' => [
                'nullable',
                'numeric',
                'min:0',
                'max:9999999999999.99',
            ],

            'sms_changes' => [
                'nullable',
                'numeric',
                'min:0',
                'max:9999999999999.99',
            ],

            'application_charges' => [
                'nullable',
                'numeric',
                'min:0',
                'max:9999999999999.99',
            ],

            'other_charges' => [
                'nullable',
                'numeric',
                'min:0',
                'max:9999999999999.99',
            ],
        ]);
    }
}
