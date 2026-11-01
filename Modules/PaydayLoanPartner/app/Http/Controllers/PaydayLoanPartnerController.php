<?php


namespace Modules\PaydayLoanPartner\Http\Controllers;

use Illuminate\Support\Str;
use App\Http\Controllers\Controller;
use Modules\PaydayLoanPartner\Models\PaydayLoanPartner;
use Modules\PaydayLoanPartner\Services\PaydayPartnerService;
use Modules\PaydayLoanPartner\Transformers\PaydayLoanPartnerCollection;
use Modules\PaydayLoanPartner\Transformers\PaydayLoanPartnerResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PaydayLoanPartnerController extends Controller
{
    protected PaydayPartnerService $partnerService;

    public function __construct(PaydayPartnerService $partnerService)
    {
        $this->partnerService = $partnerService;
    }

private function generatePaydayPartnerCode(): string
{
    $lastPartner = PaydayLoanPartner::withTrashed()
        ->where('payday_partner_code', 'like', 'PR%')
        ->orderByDesc('id')
        ->first();

    if (!$lastPartner || !$lastPartner->payday_partner_code) {
        return 'PR0001';
    }

    $lastNumber = (int) str_replace('PR', '', $lastPartner->payday_partner_code);

    $nextNumber = $lastNumber + 1;

    return 'PR' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
}

    public function index(): JsonResponse
    {
        try {
            $partners = PaydayLoanPartner::with('creator')
                ->latest()
                ->paginate(15);

            return response()->json([
                'status'  => true,
                'message' => 'Partners fetched successfully.',
                'data'    => new PaydayLoanPartnerCollection($partners),
            ], Response::HTTP_OK);

        } catch (Throwable $e) {
            Log::error('PaydayLoanPartner index error', ['error' => $e->getMessage()]);
            return response()->json([
                'status'  => false,
                'message' => 'Failed to fetch partners.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function show(string $uuid): JsonResponse
    {
        try {
            $partner = PaydayLoanPartner::with('creator')
                ->where('uuid', $uuid)
                ->firstOrFail();

            return response()->json([
                'status'  => true,
                'message' => 'Partner details fetched successfully.',
                'data'    => new PaydayLoanPartnerResource($partner),
            ], Response::HTTP_OK);

        } catch (Throwable $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Partner not found.',
            ], Response::HTTP_NOT_FOUND);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payday_partner_name' => 'required|string|max:255',
           
            'description'         => 'nullable|string',
            'contact_email'       => 'required|email',
            'contact_phone'       => 'required|string|max:20',
            'api_url'             => 'nullable|url',
            'extra_config'        => 'nullable|array',
            'is_active'           => 'boolean',
        ]);

        try {
            DB::beginTransaction();

            $partner = PaydayLoanPartner::create([
                'uuid'                => (string) Str::uuid(),
                'payday_partner_name' => $validated['payday_partner_name'],
              
                'payday_partner_code' => $this->generatePaydayPartnerCode(),
                'description'         => $validated['description'] ?? null,
                'contact_email'       => $validated['contact_email'],
                'contact_phone'       => $validated['contact_phone'],
                'api_url'             => $validated['api_url'] ?? null,
                'extra_config'        => $validated['extra_config'] ?? null,
                'is_active'           => $validated['is_active'] ?? true,
                'payday_partner_id'   => $this->partnerService->generateUniquePartnerId(),
                'client_id'           => $this->partnerService->generateClientId(),
                'client_secret'       => $this->partnerService->generateClientSecret(),
                'created_by'          => auth()->id(),
            ]);

            DB::commit();

Log::channel('audit')->info('Payday partner created', [
    'action'       => 'partner.create',
    'partner_uuid' => $partner->uuid,
    'partner_code' => $partner->payday_partner_code,
    'partner_name' => $partner->payday_partner_name,
    'performed_by' => auth()->id(),
    'ip'           => request()->ip(),
]);

return response()->json([
    'status'  => true,
    'message' => 'Partner created successfully.',
    'data'    => new PaydayLoanPartnerResource($partner),
], Response::HTTP_CREATED);

        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('PaydayLoanPartner store error', ['error' => $e->getMessage()]);
            Log::channel('audit')->warning('Payday partner creation failed', [
        'action'       => 'partner.create.failed',
        'performed_by' => auth()->id(),
        'error'        => $e->getMessage(),
    ]);
            return response()->json([
                'status'  => false,
                'message' => 'Failed to create partner.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        try {
            DB::beginTransaction();

            $partner = PaydayLoanPartner::where('uuid', $uuid)->first();

            if (!$partner) {
                DB::rollBack();
                return response()->json([
                    'status'  => false,
                    'message' => 'Partner not found.',
                ], Response::HTTP_NOT_FOUND);
            }

            $validated = $request->validate([
                'payday_partner_name' => 'sometimes|required|string|max:255',
             
                'description'   => 'nullable|string',
                'contact_email' => 'nullable|email',
                'contact_phone' => 'nullable|string|max:20',
                'api_url'       => 'nullable|url',
                'extra_config'  => 'nullable|array',
                'is_active'     => 'sometimes|boolean',
            ]);

            $partner->fill($validated);
            $partner->save();
            $partner->refresh();

            DB::commit();
            Log::channel('audit')->info('Payday partner updated', [
    'action'        => 'partner.update',
    'partner_uuid'  => $partner->uuid,
    'changed_fields'=> array_keys($validated),
    'old_values'    => array_intersect_key($original, $validated),
    'new_values'    => $validated,
    'performed_by'  => auth()->id(),
    'ip'            => request()->ip(),
]);

            return response()->json([
                'status'  => true,
                'message' => 'Partner updated successfully.',
                'data'    => new PaydayLoanPartnerResource($partner),
            ], Response::HTTP_OK);

        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'errors' => $e->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('PaydayLoanPartner update error', ['uuid' => $uuid, 'error' => $e->getMessage()]);
            return response()->json([
                'status'  => false,
                'message' => 'Failed to update partner.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function toggleActive(string $uuid): JsonResponse
    {
        try {
            $partner = PaydayLoanPartner::where('uuid', $uuid)->firstOrFail();
                    $oldStatus = $partner->is_active;

            $partner->is_active = !$partner->is_active;
            $partner->save();
            Log::channel('audit')->info('Payday partner status toggled', [
            'action'       => 'partner.status_toggle',
            'partner_uuid' => $partner->uuid,
            'old_status'   => $oldStatus,
            'new_status'   => $partner->is_active,
            'performed_by' => auth()->id(),
            'ip'           => request()->ip(),
        ]);

            return response()->json([
                'status'  => true,
                'message' => 'Partner status updated successfully.',
                'data'    => new PaydayLoanPartnerResource($partner),
            ], Response::HTTP_OK);

        } catch (Throwable $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Partner not found.',
            ], Response::HTTP_NOT_FOUND);
        }
    }
}