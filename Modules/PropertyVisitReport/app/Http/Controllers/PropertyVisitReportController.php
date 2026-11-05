<?php

namespace Modules\PropertyVisitReport\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

use Modules\PropertyVisitReport\Http\Requests\PropertyVisitReportRequest;
use Modules\PropertyVisitReport\Models\PropertyVisitReport;
use Modules\PropertyVisitReport\Services\PropertyVisitReportService;
use Modules\PropertyVisitReport\Transformers\PropertyVisitReportResource;

class PropertyVisitReportController extends Controller
{
    public function __construct(
        protected PropertyVisitReportService $service
    ) {
    }


    /**
     * Check whether Property Visit Report
     * option should be shown.
     */
    public function eligibility(
        string $leadUuid
    ): JsonResponse {

        $result =
            $this->service->checkEligibility(
                $leadUuid
            );

        return response()->json([
            'status' => true,
            'data' => $result,
        ]);
    }


    /**
     * All reports.
     */
    public function index(Request $request): JsonResponse
    {
        $reports = PropertyVisitReport::query()
            ->when(
                $request->filled('lead_uuid'),
                function ($query) use ($request) {

                    $query->where(
                        'lead_uuid',
                        $request->lead_uuid
                    );
                }
            )
            ->latest()
            ->paginate(
                min(
                    max(
                        $request->integer(
                            'per_page',
                            15
                        ),
                        1
                    ),
                    100
                )
            );


        return response()->json([
            'status' => true,

            'message' =>
                'Property Visit Reports fetched successfully.',

            'data' =>
                PropertyVisitReportResource::collection(
                    $reports
                )->response()->getData(true),
        ]);
    }


    /**
     * Create by Lead UUID.
     */
    public function store(
        PropertyVisitReportRequest $request,
        string $leadUuid
    ): JsonResponse {

        $report = $this->service->create(
            $leadUuid,
            $request->validated(),
            $request->file('documents', []),
            $request->file('site_images', [])
        );


        return response()->json([
            'status' => true,

            'message' =>
                'Property Visit Report created successfully.',

            'data' =>
                new PropertyVisitReportResource(
                    $report
                ),
        ], 201);
    }


    /**
     * Get by Lead UUID.
     */
    public function show(
        string $leadUuid
    ): JsonResponse {

        $report =
            $this->service->findByLeadUuid(
                $leadUuid
            );


        return response()->json([
            'status' => true,

            'message' =>
                'Property Visit Report fetched successfully.',

            'data' =>
                new PropertyVisitReportResource(
                    $report
                ),
        ]);
    }


    /**
     * Update by Lead UUID.
     */
    public function update(
        PropertyVisitReportRequest $request,
        string $leadUuid
    ): JsonResponse {

        $report = $this->service->update(
            $leadUuid,
            $request->validated(),
            $request->file('documents', []),
            $request->file('site_images', [])
        );


        return response()->json([
            'status' => true,

            'message' =>
                'Property Visit Report updated successfully.',

            'data' =>
                new PropertyVisitReportResource(
                    $report
                ),
        ]);
    }


    /**
     * Secure private document download.
     */
    public function document(
        string $leadUuid,
        int $index
    ) {

        $file = $this->service->getDocument(
            $leadUuid,
            $index
        );


        return Storage::disk('private')->download(
            $file['path'],
            $file['name']
        );
    }


    /**
     * Secure private site image.
     */
    public function siteImage(
        string $leadUuid,
        int $index
    ) {

        $file = $this->service->getSiteImage(
            $leadUuid,
            $index
        );


        return Storage::disk('private')->response(
            $file['path']
        );
    }
}