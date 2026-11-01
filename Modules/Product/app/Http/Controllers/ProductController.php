<?php

namespace Modules\Product\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Modules\Product\Transformers\ProductResource;
use Illuminate\Http\JsonResponse;
use Modules\Product\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    use ApiResponse;
    public function index(): JsonResponse
    {
        $products = Product::latest()->paginate(15);

        return response()->json([
            'success' => true,
            'request_id' => app('request_id'),
            'message' => 'Products retrieved successfully',
            'data'    => ProductResource::collection($products),
            'meta'    => [
                'current_page' => $products->currentPage(),
                'last_page'    => $products->lastPage(),
                'per_page'     => $products->perPage(),
                'total'        => $products->total(),
            ],
        ]);
    }
}
