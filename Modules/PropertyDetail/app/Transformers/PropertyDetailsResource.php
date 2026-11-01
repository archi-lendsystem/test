<?php


namespace Modules\PropertyDetails\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PropertyDetailsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [

            // =====================================================
            // PROPERTY DETAIL
            // =====================================================

            'id' => $this->id,

            // =====================================================
            // LEAD DETAILS
            // =====================================================

            'lead_uuid' => $this->lead_uuid,

            'lead_id' => $this->lead_id,

            'product_id' => $this->product_id,

            // =====================================================
            // PROPERTY
            // =====================================================

            'property_id' => $this->property_id,

            'property' => $this->whenLoaded(
                'property',
                function () {
                    return [
                        'id' => $this->property?->id,

                        'lead_uuid' =>
                            $this->property?->lead_uuid,

                        'lead_id' =>
                            $this->property?->lead_id,

                        'product_id' =>
                            $this->property?->product_id,

                        'property_type' =>
                            $this->property?->property_type,

                        'property_sno' =>
                            $this->property?->property_sno,
                    ];
                }
            ),

            // =====================================================
            // PROPERTY OWNER
            //
            // Example:
            //
            // [
            //    {
            //       "applicant_id": 10,
            //       "name": "Pushpendra",
            //       "type": "Applicant"
            //    }
            // ]
            // =====================================================

            'pd_property_owner' =>
                $this->pd_property_owner ?? [],

            // =====================================================
            // PROPERTY DETAILS
            // =====================================================

            'pd_collateral_usage' =>
                $this->pd_collateral_usage,

            'pd_collateral_type' =>
                $this->pd_collateral_type,

            'pd_property_categorization' =>
                $this->pd_property_categorization,

            'pd_collateral_status' =>
                $this->pd_collateral_status,

            'pd_property_ownership' =>
                $this->pd_property_ownership,

            'pd_property_type' =>
                $this->pd_property_type,

            'pd_occupancy_status' =>
                $this->pd_occupancy_status,

            // =====================================================
            // PROPERTY ADDRESS
            // =====================================================

            'pd_property_address' =>
                $this->pd_property_address,

            'pd_landmark' =>
                $this->pd_landmark,

            'pincode' =>
                $this->pincode,

            'district' =>
                $this->district,

            'city' =>
                $this->city,

            'state' =>
                $this->state,

            // =====================================================
            // PROPERTY DOCUMENT
            // =====================================================

            'pd_property_paper' =>
                $this->pd_property_paper,

            // =====================================================
            // CREATED BY
            // =====================================================

            'created_by' =>
                $this->created_by,

            'creator' => $this->whenLoaded(
                'creator',
                function () {
                    return [
                        'id' =>
                            $this->creator?->id,

                        'name' =>
                            $this->creator?->name,

                        'email' =>
                            $this->creator?->email,
                    ];
                }
            ),

            // =====================================================
            // LEAD
            // =====================================================

            'lead' => $this->whenLoaded(
                'leadCreation',
                function () {
                    return [
                        'uuid' =>
                            $this->leadCreation?->uuid,

                        'lead_id' =>
                            $this->leadCreation?->lead_id,

                        'product_id' =>
                            $this->leadCreation?->product_id,

                        'loan_type' =>
                            $this->leadCreation?->loan_type,

                        'customer_type' =>
                            $this->leadCreation?->customer_type,

                        'sub_loan_type' =>
                            $this->leadCreation?->sub_loan_type,
                    ];
                }
            ),

            // =====================================================
            // TIMESTAMPS
            // =====================================================

            'created_at' =>
                $this->created_at?->format('Y-m-d H:i:s'),

            'updated_at' =>
                $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}