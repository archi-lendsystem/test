<?php

namespace Modules\SellerDetail\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SellerDetailResource extends JsonResource
{
    public function toArray(
        Request $request
    ): array {

        return [

            'id' =>
                $this->id,

            /*
            |--------------------------------------------------------------------------
            | Seller master
            |--------------------------------------------------------------------------
            */
            'seller_id' =>
                $this->seller_id,

            'seller_sno' =>
                $this->seller?->seller_sno,

          'seller_name' =>
    $this->seller_name,

            /*
            |--------------------------------------------------------------------------
            | Lead
            |--------------------------------------------------------------------------
            */
            'lead_uuid' =>
                $this->lead_uuid,

            'product_id' =>
                $this->product_id,

            /*
            |--------------------------------------------------------------------------
            | Basic
            |--------------------------------------------------------------------------
            */
            'mobile_number' =>
                $this->mobile_number,

            'email' =>
                $this->email,

            'pan_number' =>
                $this->pan_number,

                'aadhar_number' =>
    $this->aadhar_number,

            /*
            |--------------------------------------------------------------------------
            | Current Address
            |--------------------------------------------------------------------------
            */
            'current_address' => [
                'address' =>
                    $this->current_address,

                     'landmark' =>
        $this->current_landmark,

                'pincode' =>
                    $this->current_pincode,

                'city' =>
                    $this->current_city,

                'district' =>
                    $this->current_district,

                'state' =>
                    $this->current_state,
                        'staying_years' =>
        $this->current_staying_in_years,
            ],

            /*
            |--------------------------------------------------------------------------
            | Permanent Address
            |--------------------------------------------------------------------------
            */
            'same_as_current_address' =>
                $this->same_as_current_address,

            'permanent_address' => [
                'address' =>
                    $this->permanent_address,

                'pincode' =>
                    $this->permanent_pincode,

                'city' =>
                    $this->permanent_city,

                'district' =>
                    $this->permanent_district,

                'state' =>
                    $this->permanent_state,
            ],

            /*
            |--------------------------------------------------------------------------
            | Passport
            |--------------------------------------------------------------------------
            */
            'has_passport' =>
                $this->has_passport,

            'passport' =>
                $this->when(
                    $this->has_passport,
                    fn () => [

                        'number' =>
                            $this->passport_number,

                        'document' =>
                            $this->passport_file
                                ? route(
                                    'seller-detail.document',
                                    [
                                        $this->lead_uuid,
                                        $this->seller_id,
                                        'passport',
                                    ]
                                )
                                : null,
                    ]
                ),

            /*
            |--------------------------------------------------------------------------
            | Bank
            |--------------------------------------------------------------------------
            */
            'bank' => [
                'account_holder_name' =>
                    $this->bank_account_holder_name,

                'account_number' =>
                    $this->bank_account_number,

                'bank_name' =>
                    $this->bank_name,

                'ifsc_code' =>
                    $this->ifsc_code,
            ],

            /*
            |--------------------------------------------------------------------------
            | Documents
            |--------------------------------------------------------------------------
            */
            'documents' => [

                'pan' =>
                    $this->pan_document
                        ? route(
                            'seller-detail.document',
                            [
                                $this->lead_uuid,
                                $this->seller_id,
                                'pan',
                            ]
                        )
                        : null,

                'address' =>
                    $this->address_document
                        ? route(
                            'seller-detail.document',
                            [
                                $this->lead_uuid,
                                $this->seller_id,
                                'address',
                            ]
                        )
                        : null,

                'bank_statement' =>
                    $this->bank_statement
                        ? route(
                            'seller-detail.document',
                            [
                                $this->lead_uuid,
                                $this->seller_id,
                                'bank-statement',
                            ]
                        )
                        : null,

                'passbook_image' =>
                    $this->passbook_image
                        ? route(
                            'seller-detail.document',
                            [
                                $this->lead_uuid,
                                $this->seller_id,
                                'passbook',
                            ]
                        )
                        : null,
            ],

            'created_at' =>
                $this->created_at,

            'updated_at' =>
                $this->updated_at,
        ];
    }
}