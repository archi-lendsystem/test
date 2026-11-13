<?php

namespace Modules\UserManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\UserManagement\Models\Department;
use Modules\UserManagement\Transformers\DepartmentResource;

class DepartmentController extends Controller
{
    use ApiResponse;

    
    public function index(): JsonResponse
    {
        $departments = Department::where('status', true)
            ->orderBy('name')
            ->get();

        return $this->success(DepartmentResource::collection($departments));
    }

    
    public function show(Department $department): JsonResponse
    {
        return $this->success(new DepartmentResource($department));
    }
}