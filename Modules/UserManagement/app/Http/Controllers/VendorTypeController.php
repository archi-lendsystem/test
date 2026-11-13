<?php

namespace Modules\UserManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\UserManagement\Models\VendorType;

class VendorTypeController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $types = VendorType::latest()->get();
        return $this->success($types);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100|unique:vendor_types,name',
        ]);

        $type = VendorType::create($data);
        return $this->success($type, 'Vendor type created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        $type = VendorType::find($id);
        if (! $type) {
            return $this->error('Vendor type not found', 404);
        }
        return $this->success($type);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $type = VendorType::find($id);
        if (! $type) {
            return $this->error('Vendor type not found', 404);
        }

        $data = $request->validate([
            'name' => "required|string|max:100|unique:vendor_types,name,{$id}",
        ]);

        $type->update($data);
        return $this->success($type, 'Vendor type updated successfully');
    }

    public function destroy(int $id): JsonResponse
    {
        $type = VendorType::find($id);
        if (! $type) {
            return $this->error('Vendor type not found', 404);
        }

        $type->delete();
        return $this->success(null, 'Vendor type deleted successfully');
    }
}