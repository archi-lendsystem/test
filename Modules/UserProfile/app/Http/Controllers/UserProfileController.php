<?php

namespace Modules\UserProfile\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\UserProfile\Transformers\UserProfileResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;


class UserProfileController extends Controller
{
    public function getProfile(): JsonResponse
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.'
            ], 401);
        }

        // Load user roles and all assigned branches
        $user->loadMissing(['roles', 'branches']);

        return response()->json([
            'status' => true,
            'message' => 'User profile fetched successfully.',
            'request_id' => app('request_id'),
            'timestamp' => now()->format('Y-m-d H:i:s'),
            'data' => new UserProfileResource($user)
        ]);
    }

   


    public function changePassword(Request $request): JsonResponse
{
    $user = $request->user();

    if (! $user) {
        return response()->json([
            'status'  => false,
            'message' => 'Unauthenticated.',
        ], 401);
    }

    $stored      = (string) $user->user_password;
    $hasPassword = $stored !== '';

    $rules = [
        'new_password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
    ];

    // Current password sirf tab maango jab pehle se password set ho
    if ($hasPassword) {
        $rules['current_password'] = ['required', 'string'];
        $rules['new_password'][]   = 'different:current_password';
    }

    $validator = Validator::make($request->all(), $rules);

    if ($validator->fails()) {
        return response()->json([
            'status'  => false,
            'message' => $validator->errors()->first(),
            'errors'  => $validator->errors(),
        ], 422);
    }

    $data = $validator->validated();

    if ($hasPassword) {
        $isHashed = str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon');
        $valid    = $isHashed
            ? Hash::check($data['current_password'], $stored)
            : hash_equals($stored, $data['current_password']);

        if (! $valid) {
            return response()->json([
                'status'  => false,
                'message' => 'Current password is incorrect.',
                'errors'  => [
                    'current_password' => ['Current password is incorrect.'],
                ],
            ], 422);
        }
    }

    $newHash = Hash::make($data['new_password']);

    DB::table($user->getTable())
        ->where('id', $user->id)
        ->update([
            'user_password' => $newHash,
            'updated_at'    => now(),
        ]);

    $storedNow = DB::table($user->getTable())->where('id', $user->id)->value('user_password');

    if (! $storedNow || ! Hash::check($data['new_password'], $storedNow)) {
        Log::error('change-password-not-saved', ['user_id' => $user->id]);

        return response()->json([
            'status'  => false,
            'message' => 'Password could not be saved. Please contact support.',
        ], 500);
    }

    // Baaki devices se logout (current token rehne do)
    $currentId = $user->currentAccessToken()?->id;
    if ($currentId) {
        $user->tokens()->where('id', '!=', $currentId)->delete();
    }

    return response()->json([
        'status'     => true,
        'message'    => $hasPassword ? 'Password changed successfully.' : 'Password set successfully.',
        'request_id' => app('request_id'),
        'timestamp'  => now()->format('Y-m-d H:i:s'),
    ]);
}
}