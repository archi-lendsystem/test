<?php

namespace Modules\PropertyVisitReport\App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PropertyVisitReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'lead_uuid' => $this->lead_uuid,

            'product_id' => $this->product_id,

            // General
            'pd_done_by' => $this->pd_done_by,

            'pd_date' => $this->pd_date
                ? $this->pd_date->format('Y-m-d')
                : null,

            'meeting_place' => $this->meeting_place,

            'person_meet' => $this->person_meet,

            // Residence
            'loan_purpose' => $this->loan_purpose,

            'residence_type' => $this->residence_type,

            'staying_together' => $this->staying_together,

            'years_at_residence' =>
                $this->years_at_residence,

            // Property
            'property_type' => $this->property_type,

            'property_sub_type' =>
                $this->property_sub_type,

            'construction_status' =>
                $this->construction_status,

            'age_of_property' =>
                $this->age_of_property,

            'total_area' => $this->total_area,

            'buildup_area' => $this->buildup_area,

            'num_floors' => $this->num_floors,

            'floor_number' => $this->floor_number,

            // Valuation
            'market_value' => $this->market_value,

            'distress_value' => $this->distress_value,

            'circle_rate' => $this->circle_rate,

            'loan_amount' => $this->loan_amount,

            // Legal
            'ownership_type' => $this->ownership_type,

            'legal_docs_verified' =>
                $this->legal_docs_verified,

            'encumbrance_status' =>
                $this->encumbrance_status,

            'property_condition' =>
                $this->property_condition,

            // Location
            'neighbourhood_type' =>
                $this->neighbourhood_type,

            'road_width' => $this->road_width,

            'distance_from_branch' =>
                $this->distance_from_branch,

            'pd_with_owner' =>
                $this->pd_with_owner,

            'remarks' => $this->remarks,


            /*
             * Never expose private physical path.
             *
             * Frontend gets index + secure API URL.
             */
            'documents' => collect(
                $this->documents ?? []
            )->values()->map(
                function ($path, $index) {

                    return [
                        'index' => $index,

                        'name' => basename($path),

                        'download_url' => route(
                            'property-visit-reports.document',
                            [
                                'leadUuid' => $this->lead_uuid,
                                'index' => $index,
                            ]
                        ),
                    ];
                }
            ),


            'site_images' => collect(
                $this->site_images ?? []
            )->values()->map(
                function ($path, $index) {

                    return [
                        'index' => $index,

                        'name' => basename($path),

                        'view_url' => route(
                            'property-visit-reports.site-image',
                            [
                                'leadUuid' => $this->lead_uuid,
                                'index' => $index,
                            ]
                        ),
                    ];
                }
            ),


            'created_at' => $this->created_at,

            'updated_at' => $this->updated_at,
        ];
    }
}