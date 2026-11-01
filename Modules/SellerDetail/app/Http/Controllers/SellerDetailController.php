<?php

namespace Modules\SellerDetail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

use Modules\Seller\Models\Seller;

use Modules\SellerDetail\Models\SellerDetail;

use Modules\SellerDetail\Http\Requests\SellerDetailRequest;

use Modules\SellerDetail\Transformers\SellerDetailResource;

class SellerDetailController extends Controller
{
    use ApiResponse;

    /*
    |--------------------------------------------------------------------------
    | Show Seller Detail
    |--------------------------------------------------------------------------
    */
    public function show(
        $lead_uuid,
        $seller_id
    ): JsonResponse {

        $seller = $this->hlSellerQuery()->where(
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

                'data' => null,

            ], 404);
        }

        $detail = SellerDetail::where(
            'seller_id',
            $seller->id
        )
            ->with('seller')
            ->first();

        if (! $detail) {

            return response()->json([
                'success' => false,

                'message' =>
                    'Seller detail not found.',

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
                'Seller detail retrieved successfully.',

            'data' =>
                new SellerDetailResource(
                    $detail
                ),

        ], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | Create Seller Detail
    |--------------------------------------------------------------------------
    */
    public function store(
        SellerDetailRequest $request,
        $lead_uuid,
        $seller_id
    ): JsonResponse {

        DB::beginTransaction();

        try {

            /*
            |--------------------------------------------------------------------------
            | Find exact Seller
            |--------------------------------------------------------------------------
            */
            $seller = $this->hlSellerQuery()->where(
                'lead_uuid',
                $lead_uuid
            )
                ->where(
                    'id',
                    $seller_id
                )
                ->first();

            if (! $seller) {

                DB::rollBack();

                return response()->json([
                    'success' => false,

                    'message' =>
                        'Seller not found for this lead.',

                    'data' => null,

                ], 404);
            }

            /*
            |--------------------------------------------------------------------------
            | One seller only one detail
            |--------------------------------------------------------------------------
            */
            $alreadyExists =
                SellerDetail::where(
                    'seller_id',
                    $seller->id
                )->exists();

            if ($alreadyExists) {

                DB::rollBack();

                return response()->json([
                    'success' => false,

                    'message' =>
                        'Seller detail already exists. Please use update API.',

                    'data' => null,

                ], 422);
            }

            $data =
                $request->validated();

          

            /*
            |--------------------------------------------------------------------------
            | Link automatically
            |--------------------------------------------------------------------------
            */
            $data['seller_id'] =
                $seller->id;

            $data['lead_uuid'] =
                $seller->lead_uuid;

            $data['product_id'] =
                $seller->product_id;

            /*
            |--------------------------------------------------------------------------
            | Remove file objects before DB create
            |--------------------------------------------------------------------------
            */
            $this->removeFiles(
                $data
            );

            /*
            |--------------------------------------------------------------------------
            | Same Permanent Address
            |--------------------------------------------------------------------------
            */
            $this->prepareAddress(
                $data
            );

            /*
            |--------------------------------------------------------------------------
            | Passport No
            |--------------------------------------------------------------------------
            */
            if (
                ! $data['has_passport']
            ) {
                $data['passport_number']
                    = null;
            }

            $detail =
                SellerDetail::create(
                    $data
                );

            /*
            |--------------------------------------------------------------------------
            | Documents
            |--------------------------------------------------------------------------
            */
            $this->saveDocuments(
                $request,
                $detail
            );

            DB::commit();

            $detail->load('seller');

            Log::info(
                'Seller detail created',
                [
                    'seller_id' =>
                        $seller->id,

                    'lead_uuid' =>
                        $seller->lead_uuid,

                    'product_id' =>
                        $seller->product_id,
                ]
            );

            return response()->json([
                'success' => true,

                'message' =>
                    'Seller detail created successfully.',

                'data' =>
                    new SellerDetailResource(
                        $detail
                    ),

            ], 201);

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error(
                'Seller detail creation failed',
                [
                    'seller_id' =>
                        $seller_id,

                    'lead_uuid' =>
                        $lead_uuid,

                    'error' =>
                        $e->getMessage(),

                    'line' =>
                        $e->getLine(),
                ]
            );

            return response()->json([
                'success' => false,

                'message' =>
                    'Seller detail creation failed.',

                'error' =>
                    config('app.debug')
                        ? $e->getMessage()
                        : null,

            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Update Seller Detail
    |--------------------------------------------------------------------------
    */
    public function update(
        SellerDetailRequest $request,
        $lead_uuid,
        $seller_id
    ): JsonResponse {

        DB::beginTransaction();

        try {

            $seller = $this->hlSellerQuery()->where(
                'lead_uuid',
                $lead_uuid
            )
                ->where(
                    'id',
                    $seller_id
                )
                ->first();

            if (! $seller) {

                DB::rollBack();

                return response()->json([
                    'success' => false,

                    'message' =>
                        'Seller not found for this lead.',

                    'data' => null,

                ], 404);
            }

            $detail =
                SellerDetail::where(
                    'seller_id',
                    $seller->id
                )->first();

            if (! $detail) {

                DB::rollBack();

                return response()->json([
                    'success' => false,

                    'message' =>
                        'Seller detail not found.',

                    'data' => null,

                ], 404);
            }

            $data =
                $request->validated();

           

            /*
             * These cannot change from frontend
             */
            unset(
                $data['seller_id'],
                $data['lead_uuid'],
                $data['product_id']
            );

            $this->removeFiles(
                $data
            );

            $this->prepareAddress($data, $detail);

            /*
            |--------------------------------------------------------------------------
            | Passport changed YES -> NO
            |--------------------------------------------------------------------------
            */
            if (
                isset(
                    $data['has_passport']
                )
                &&
                ! $data['has_passport']
            ) {

                if (
                    $detail->passport_file
                ) {
                    Storage::disk(
                        'local'
                    )->delete(
                        $detail->passport_file
                    );
                }

                $data['passport_number']
                    = null;

                $data['passport_file']
                    = null;
            }

            $detail->update(
                $data
            );

            /*
            |--------------------------------------------------------------------------
            | Replace new documents
            |--------------------------------------------------------------------------
            */
            $this->saveDocuments(
                $request,
                $detail
            );

            DB::commit();

            $detail->refresh();
            $detail->load('seller');

            Log::info(
                'Seller detail updated',
                [
                    'seller_id' =>
                        $seller->id,

                    'lead_uuid' =>
                        $seller->lead_uuid,
                ]
            );

            return response()->json([
                'success' => true,

                'message' =>
                    'Seller detail updated successfully.',

                'data' =>
                    new SellerDetailResource(
                        $detail
                    ),

            ], 200);

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error(
                'Seller detail update failed',
                [
                    'seller_id' =>
                        $seller_id,

                    'lead_uuid' =>
                        $lead_uuid,

                    'error' =>
                        $e->getMessage(),

                    'line' =>
                        $e->getLine(),
                ]
            );

            return response()->json([
                'success' => false,

                'message' =>
                    'Seller detail update failed.',

                'error' =>
                    config('app.debug')
                        ? $e->getMessage()
                        : null,

            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Delete Seller Detail only
    |--------------------------------------------------------------------------
    */
    public function destroy(
        $lead_uuid,
        $seller_id
    ): JsonResponse {

        $seller = $this->hlSellerQuery()->where(
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
                    'Seller not found.',
                'data' => null,
            ], 404);
        }

        $detail =
            SellerDetail::where(
                'seller_id',
                $seller->id
            )->first();

        if (! $detail) {

            return response()->json([
                'success' => false,
                'message' =>
                    'Seller detail not found.',
                'data' => null,
            ], 404);
        }

        /*
         * Private files delete
         */
        Storage::disk('local')
            ->deleteDirectory(
                $this->documentFolder(
                    $detail
                )
            );

        $detail->delete();

        Log::info(
            'Seller detail deleted',
            [
                'seller_id' =>
                    $seller->id,

                'lead_uuid' =>
                    $lead_uuid,
            ]
        );

        return response()->json([
            'success' => true,

            'message' =>
                'Seller detail deleted successfully.',

            'data' => null,

        ], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | Private Document
    |--------------------------------------------------------------------------
    */
    public function document(
        $lead_uuid,
        $seller_id,
        $type
    ) {

        $seller = $this->hlSellerQuery()->where(
            'lead_uuid',
            $lead_uuid
        )
            ->where(
                'id',
                $seller_id
            )
            ->firstOrFail();

        $detail =
            SellerDetail::where(
                'seller_id',
                $seller->id
            )->firstOrFail();

        $documents = [

            'pan' =>
                $detail->pan_document,

            'address' =>
                $detail->address_document,

            'passport' =>
                $detail->passport_file,

            'bank-statement' =>
                $detail->bank_statement,

            'passbook' =>
                $detail->passbook_image,
        ];

        abort_unless(
            array_key_exists(
                $type,
                $documents
            ),
            404
        );

        $path =
            $documents[$type];

        abort_if(
            empty($path)
            ||
            ! Storage::disk(
                'local'
            )->exists($path),
            404,
            'Document not found.'
        );

        Log::info(
            'Seller document accessed',
            [
                'seller_id' =>
                    $seller->id,

                'document_type' =>
                    $type,
            ]
        );

        return Storage::disk(
            'local'
        )->download($path);
    }

    /*
    |--------------------------------------------------------------------------
    | Save / Replace Files
    |--------------------------------------------------------------------------
    */
    private function saveDocuments(
        SellerDetailRequest $request,
        SellerDetail $detail
    ): void {

        $documents = [

            'pan_document' =>
                'pan_document',

            'address_document' =>
                'address_document',

            'passport_file' =>
                'passport',

            'bank_statement' =>
                'bank_statement',

            'passbook_image' =>
                'passbook_image',
        ];

        $folder =
            $this->documentFolder(
                $detail
            );

        $updates = [];

        foreach (
            $documents
            as $field => $fileName
        ) {

            if (
                ! $request->hasFile(
                    $field
                )
            ) {
                continue;
            }

            $file =
                $request->file(
                    $field
                );

            $extension =
                strtolower(
                    $file
                        ->getClientOriginalExtension()
                );

            $oldPath =
                $detail->{$field};

            /*
            |--------------------------------------------------------------------------
            | Fixed file name
            |--------------------------------------------------------------------------
            */
            $newPath =
                $file->storeAs(
                    $folder,
                    $fileName
                    . '.'
                    . $extension,
                    'local'
                );

            /*
             * If old file extension changed,
             * remove previous file.
             */
            if (
                $oldPath
                &&
                $oldPath !== $newPath
                &&
                Storage::disk(
                    'local'
                )->exists(
                    $oldPath
                )
            ) {
                Storage::disk(
                    'local'
                )->delete(
                    $oldPath
                );
            }

            $updates[$field] =
                $newPath;

            Log::info(
                'Seller document saved',
                [
                    'seller_id' =>
                        $detail->seller_id,

                    'document_type' =>
                        $field,
                ]
            );
        }

        if (
            ! empty($updates)
        ) {
            $detail->update(
                $updates
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Private Document Folder
    |--------------------------------------------------------------------------
    */
    private function documentFolder(
        SellerDetail $detail
    ): string {

        return 'seller-documents/'
            . $detail->lead_uuid
            . '/seller-'
            . $detail->seller_id;
    }

    /*
    |--------------------------------------------------------------------------
    | Remove Uploaded Files from DB array
    |--------------------------------------------------------------------------
    */
    private function removeFiles(
        array &$data
    ): void {

        unset(
            $data['pan_document'],
            $data['address_document'],
            $data['passport_file'],
            $data['bank_statement'],
            $data['passbook_image']
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Same Address
    |--------------------------------------------------------------------------
    */
    private function prepareAddress(
        array &$data,
        ?SellerDetail $existing = null
    ): void {

        if ($data['same_as_current_address'] ?? $existing?->same_as_current_address ?? false) {

            $data['permanent_address'] =
                $data['current_address']
                ?? $existing?->current_address;

            $data['permanent_pincode'] =
                $data['current_pincode']
                ?? $existing?->current_pincode;

            $data['permanent_city'] =
                $data['current_city']
                ?? $existing?->current_city;

            $data['permanent_district'] =
                $data['current_district']
                ?? $existing?->current_district;

            $data['permanent_state'] =
                $data['current_state']
                ?? $existing?->current_state;
        }
    }

    // Always check the current lead product, not the caller's product_id.
    private function hlSellerQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Seller::query()->whereHas('lead', function ($query) {
            $query->where('product_id', 4);
        });
    }
}
