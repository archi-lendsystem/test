<?php

namespace Modules\Seller\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\Seller\Models\Seller;
use Modules\Seller\Transformers\SellerResource;

class SellerController extends Controller
{
    use ApiResponse;

    /*
    |--------------------------------------------------------------------------
    | List all Sellers of Lead
    |--------------------------------------------------------------------------
    */
    public function index($lead_uuid): JsonResponse
    {
        $lead = BLLeadCreation::where(
            'uuid',
            $lead_uuid
        )->first();

        if (! $lead) {
            return response()->json([
                'success' => false,
                'message' => 'Lead not found.',
                'data' => null,
            ], 404);
        }

        if ((int) $lead->product_id !== 4) {
            return $this->hlOnlyResponse();
        }

        $sellers = Seller::where(
            'lead_uuid',
            $lead_uuid
        )
            ->with('sellerDetail')
            ->orderBy('seller_sno', 'asc')
            ->get();

        return response()->json([
            'success' => true,

            'message' =>
                'Sellers retrieved successfully.',

            'request' => [
                'lead_uuid' => $lead_uuid,
            ],

            'data' =>
                SellerResource::collection($sellers),

        ], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | Create Seller
    |--------------------------------------------------------------------------
    */
  public function store(
    Request $request,
    $lead_uuid
): JsonResponse {

    DB::beginTransaction();

    try {

        $lead = BLLeadCreation::where(
            'uuid',
            $lead_uuid
        )
            ->lockForUpdate()
            ->first();

        if (! $lead) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Lead not found.',
                'data' => null,
            ], 404);
        }

        // Seller only for HL
        if ((int) $lead->product_id !== 4) {
            DB::rollBack();

            return $this->hlOnlyResponse();
        }

        /*
        |--------------------------------------------------------------------------
        | Auto Seller SNO
        |--------------------------------------------------------------------------
        |
        | Existing active sellers:
        | 1,2,3
        |
        | New seller:
        | 4
        |
        */
        $lastSellerSno = Seller::where(
            'lead_uuid',
            $lead_uuid
        )
            ->lockForUpdate()
            ->max('seller_sno');

        $sellerSno = ($lastSellerSno ?? 0) + 1;

        /*
        |--------------------------------------------------------------------------
        | Values automatically from Lead
        |--------------------------------------------------------------------------
        */
        $seller = Seller::create([
            'lead_uuid' => $lead->uuid,
            'lead_id' => $lead->lead_id,
            'product_id' => $lead->product_id,
            'created_by' => $request->user()?->id,
            'seller_sno' => $sellerSno,
        ]);

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Seller created successfully.',
            'request' => [
                'lead_uuid' => $lead_uuid,
            ],
            'data' => new SellerResource($seller),
        ], 201);

    } catch (\Throwable $e) {

        DB::rollBack();

        Log::error(
            'Seller creation failed',
            [
                'lead_uuid' => $lead_uuid,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ]
        );

        return response()->json([
            'success' => false,
            'message' => 'Seller creation failed.',
            'error' => config('app.debug')
                ? $e->getMessage()
                : null,
        ], 500);
    }
}

    /*
    |--------------------------------------------------------------------------
    | Single Seller
    |--------------------------------------------------------------------------
    */
    public function show(
        $lead_uuid,
        $seller_id
    ): JsonResponse {

        if ($error = $this->leadAccessError($lead_uuid)) {
            return $error;
        }

        $seller = Seller::where(
            'lead_uuid',
            $lead_uuid
        )
            ->where(
                'id',
                $seller_id
            )
            ->with('sellerDetail')
            ->first();

        if (! $seller) {

            return response()->json([
                'success' => false,

                'message' =>
                    'Seller not found for this lead.',

                'request' => [
                    'lead_uuid' =>
                        $lead_uuid,

                    'seller_id' =>
                        $seller_id,
                ],

                'data' => null,

            ], 404);
        }

        return response()->json([
            'success' => true,

            'message' =>
                'Seller retrieved successfully.',

            'request' => [
                'lead_uuid' =>
                    $lead_uuid,

                'seller_id' =>
                    $seller_id,
            ],

            'data' =>
                new SellerResource($seller),

        ], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | Update Seller Name
    |--------------------------------------------------------------------------
    */
    public function update(
        Request $request,
        $lead_uuid,
        $seller_id
    ): JsonResponse {

        if ($error = $this->leadAccessError($lead_uuid)) {
            return $error;
        }

        $validated = $request->validate([

            'seller_name' => [
                'required',
                'string',
                'max:255',
            ],
        ]);

        $seller = Seller::where(
            'lead_uuid',
            $lead_uuid
        )
            ->where(
                'id',
                $seller_id
            )
            ->first();

        if (! $seller) {

            return response()->json([
                'success' => false,

                'message' =>
                    'Seller not found for this lead.',

                'request' => [
                    'lead_uuid' =>
                        $lead_uuid,

                    'seller_id' =>
                        $seller_id,
                ],

                'data' => null,

            ], 404);
        }

        $seller->update([
            'seller_name' =>
                $validated['seller_name'],
        ]);

        Log::info(
            'Seller updated successfully',
            [
                'seller_id' => $seller->id,
                'lead_uuid' => $seller->lead_uuid,
            ]
        );

        return response()->json([
            'success' => true,

            'message' =>
                'Seller updated successfully.',

            'request' => [
                'lead_uuid' =>
                    $lead_uuid,

                'seller_id' =>
                    $seller_id,
            ],

            'data' =>
                new SellerResource($seller),

        ], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | Delete Seller
    |--------------------------------------------------------------------------
    */
  public function destroy(
    $lead_uuid,
    $seller_id
): JsonResponse {

    DB::beginTransaction();

    try {

        $lead = BLLeadCreation::where(
            'uuid',
            $lead_uuid
        )
            ->lockForUpdate()
            ->first();

        if (! $lead) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Lead not found.',
                'data' => null,
            ], 404);
        }

        if ((int) $lead->product_id !== 4) {
            DB::rollBack();

            return $this->hlOnlyResponse();
        }

        $seller = Seller::where(
            'lead_uuid',
            $lead_uuid
        )
            ->where('id', $seller_id)
            ->lockForUpdate()
            ->first();

        if (! $seller) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Seller not found for this lead.',
                'data' => null,
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Hard delete
        |--------------------------------------------------------------------------
        */
        $seller->forceDelete();

        /*
        |--------------------------------------------------------------------------
        | Re-sequence remaining sellers
        |--------------------------------------------------------------------------
        */
        $remainingSellers = Seller::where(
            'lead_uuid',
            $lead_uuid
        )
            ->orderBy('seller_sno')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($remainingSellers as $index => $remainingSeller) {

            $newSno = $index + 1;

            if ((int) $remainingSeller->seller_sno !== $newSno) {
                $remainingSeller->update([
                    'seller_sno' => $newSno,
                ]);
            }
        }

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'Seller deleted and re-ordered successfully.',
            'request' => [
                'lead_uuid' => $lead_uuid,
                'seller_id' => $seller_id,
            ],
            'data' => SellerResource::collection(
                Seller::where('lead_uuid', $lead_uuid)
                    ->with('sellerDetail')
                    ->orderBy('seller_sno')
                    ->get()
            ),
        ], 200);

    } catch (\Throwable $e) {

        DB::rollBack();

        Log::error(
            'Seller deletion failed',
            [
                'seller_id' => $seller_id,
                'lead_uuid' => $lead_uuid,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ]
        );

        return response()->json([
            'success' => false,
            'message' => 'Seller deletion failed.',
            'error' => config('app.debug')
                ? $e->getMessage()
                : null,
        ], 500);
    }
}

    private function leadAccessError(string $lead_uuid): ?JsonResponse
    {
        $lead = BLLeadCreation::where('uuid', $lead_uuid)->first();

        if (! $lead) {
            return response()->json([
                'success' => false,
                'message' => 'Lead not found.',
                'data' => null,
            ], 404);
        }

        return (int) $lead->product_id === 4 ? null : $this->hlOnlyResponse();
    }

    private function hlOnlyResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Seller details are available only for Home Loan (product_id = 4).',
            'data' => null,
        ], 403);
    }
}
