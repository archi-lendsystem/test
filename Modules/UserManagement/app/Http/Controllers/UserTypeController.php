<?php

namespace Modules\UserManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\UserManagement\Models\UserType;

class UserTypeController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $types = UserType::latest()->get();
        return $this->success($types);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100|unique:user_types,name',
        ]);

        $type = UserType::create($data);
        return $this->success($type, 'User type created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        $type = UserType::find($id);
        if (! $type) {
            return $this->error('User type not found', 404);
        }
        return $this->success($type);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $type = UserType::find($id);
        if (! $type) {
            return $this->error('User type not found', 404);
        }

        $data = $request->validate([
            'name' => "required|string|max:100|unique:user_types,name,{$id}",
        ]);

        $type->update($data);
        return $this->success($type, 'User type updated successfully');
    }

    public function destroy(int $id): JsonResponse
    {
        $type = UserType::find($id);
        if (! $type) {
            return $this->error('User type not found', 404);
        }

        $type->delete();
        return $this->success(null, 'User type deleted successfully');
    }
}