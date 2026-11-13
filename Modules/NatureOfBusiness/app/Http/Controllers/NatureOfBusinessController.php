<?php

namespace Modules\NatureOfBusiness\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;

class NatureOfBusinessController extends Controller
{
    public function index(): JsonResponse
    {
        $filePath = base_path('Modules/NatureOfBusiness/resources/data/nature_of_business.json');

        if (!File::exists($filePath)) {
            return response()->json([
                'success' => false,
                'status_code' => 404,
                 'request_id' => app('request_id'),
                 
                 'timestamp'  => now()->toISOString(),
                'message' => 'Nature of business data file not found.',
                'data' => null,
            ], 404);
        }

        $natureOfBusiness = json_decode(File::get($filePath), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json([
                'success' => false,
                'status_code' => 500,
                 'request_id' => app('request_id'),
                 
                'timestamp'  => now()->toISOString(),
                'message' => 'Invalid nature of business JSON format.',
                'data' => null,
            ], 500);
        }

        return response()->json([
            'success' => true,
            'status_code' => 200,
            'request_id' => app('request_id'),
            'timestamp'  => now()->toISOString(),
            'message' => 'Nature of business fetched successfully.',
            'data' => $natureOfBusiness,
        ], 200);
    }
}