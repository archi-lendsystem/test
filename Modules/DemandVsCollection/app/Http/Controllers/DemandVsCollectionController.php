<?php

namespace Modules\DemandVsCollection\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\DemandVsCollection\Http\Requests\DemandVsCollectionIndexRequest;
use Modules\DemandVsCollection\Services\DemandVsCollectionService;
use Throwable;

final class DemandVsCollectionController extends Controller
{
    public function __construct(
        private readonly DemandVsCollectionService $service
    ) {}

    /**
     * Return loan-wise Demand vs Collection report.z
     */
    public function index(
        DemandVsCollectionIndexRequest $request
    ): JsonResponse {
        $requestId = (string) Str::uuid();

        try {
            $filters = $request->validated();

            $report = $this->service->getReport(
                $filters
            );

            return response()->json([
                'success' => true,

                'message' =>
                    'Demand vs Collection report fetched successfully.',

                'code' =>
                    'DEMAND_VS_COLLECTION_FETCHED_SUCCESSFULLY',

                'request_id' =>
                    $requestId,

                'timestamp' =>
                    now()->toIso8601String(),

                'data' =>
                    $report,
            ], 200);
        } catch (Throwable $exception) {
            Log::error(
                'Demand vs Collection report fetch failed.',
                [
                    'request_id' =>
                        $requestId,

                    'user_id' =>
                        $request->user()?->id,

                    'filters' =>
                        $request->except([
                            'page',
                            'per_page',
                        ]),

                    'exception_class' =>
                        $exception::class,

                    'message' =>
                        $exception->getMessage(),

                    'file' =>
                        $exception->getFile(),

                    'line' =>
                        $exception->getLine(),
                ]
            );

            return response()->json([
                'success' => false,

                'message' =>
                    'Something went wrong while fetching the Demand vs Collection report.',

                'code' =>
                    'DEMAND_VS_COLLECTION_FETCH_FAILED',

                'request_id' =>
                    $requestId,

                'timestamp' =>
                    now()->toIso8601String(),

                'errors' =>
                    app()->environment('local')
                        ? [
                            'message' =>
                                $exception->getMessage(),

                            'exception' =>
                                $exception::class,

                            'file' =>
                                $exception->getFile(),

                            'line' =>
                                $exception->getLine(),
                        ]
                        : null,
            ], 500);
        }
    }
}