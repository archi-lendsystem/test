<?php
namespace Modules\BLApplicant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Modules\BLApplicant\Models\BLApplicant;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Modules\BLApplicant\Transformers\BLApplicantCollection;
use Modules\BLApplicant\Transformers\BLApplicantResource;

class BLApplicantController extends Controller
{
    use ApiResponse;

    

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lead_uuid'      => ['required', 'uuid', 'exists:b_l_lead_creations,uuid'],
            'applicant_type' => ['required', 'string', 'in:Co-applicant,Guarantor'],
        ]);

        $lead = \Modules\BLLeadCreation\Models\BLLeadCreation::query()
            ->where('uuid', $validated['lead_uuid'])
            ->firstOrFail();

        DB::beginTransaction();

        try {
            $isGuarantor = $validated['applicant_type'] === 'Guarantor';

            if ($isGuarantor) {
                
                $applicantSno = BLApplicant::query()
                    ->where('lead_uuid', $validated['lead_uuid'])
                    ->where('applicant_types', 'Guarantor')
                    ->count() + 1;
            } else {
                
                $applicantSno = BLApplicant::query()
                    ->where('lead_uuid', $validated['lead_uuid'])
                    ->where('applicant_types', '!=', 'Guarantor')
                    ->count();
            }

            $applicant = BLApplicant::query()->create([
                'lead_uuid'       => $validated['lead_uuid'],
                'lead_id'         => $lead->lead_id,
                 'product_id'      => $lead->product_id,
    'created_by'      => Auth::id(),
                'applicant_sno'   => $applicantSno,
                'applicant_types' => $validated['applicant_type'],
            ]);

            $applicantDetail = BLApplicantDetails::query()->create([
                'lead_uuid'                 => $applicant->lead_uuid,
                'applicant_id'              => $applicant->id,
                'lead_id'                   => $applicant->lead_id,
                'applicant_sno'             => $applicant->applicant_sno,
                'applicant_type'            => $applicant->applicant_types,
                'email_address'             => null,
                'alternate_contact_number'  => null,
            ]);

            DB::commit();

            $typeLabel = $isGuarantor ? 'Guarantor' : 'Co-Applicant';

            return response()->json([
                'success'     => true,
                'status_code' => Response::HTTP_CREATED,
                'request_id'  => app('request_id'),
                'timestamp'   => now()->format('Y-m-d H:i:s'),
                'message'     => "BL {$typeLabel} created successfully.",
                'data'        => [
                    'applicant'        => new BLApplicantResource($applicant),
                    'applicant_detail' => $applicantDetail,
                ],
            ], Response::HTTP_CREATED);

        } catch (\Throwable $exception) {
            DB::rollBack();
            report($exception);

            return response()->json([
                'status'     => false,
                'code'       => Response::HTTP_INTERNAL_SERVER_ERROR,
                'request_id' => app('request_id'),
                'timestamp'  => now()->format('Y-m-d H:i:s'),
                'message'    => 'Something went wrong while creating applicant.',
                'error'      => app()->environment('local') ? $exception->getMessage() : null,
                'data'       => null,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

  

    public function show(string $uuid): JsonResponse
    {
        $applicants = BLApplicant::query()
            ->with('detail')
            ->where('lead_uuid', $uuid)
            ->orderByRaw("FIELD(applicant_types, 'applicant', 'Co-applicant', 'Guarantor')")
            ->orderBy('applicant_sno')
            ->paginate(
                perPage: (int) request()->get('per_page', 10)
            );

        if ($applicants->isEmpty()) {
            return response()->json([
                'status'     => false,
                'code'       => Response::HTTP_NOT_FOUND,
                'request_id' => app('request_id'),
                'timestamp'  => now()->format('Y-m-d H:i:s'),
                'message'    => 'No applicants found for this lead.',
                'data'       => [],
            ], Response::HTTP_NOT_FOUND);
        }

        return (new BLApplicantCollection($applicants))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

  

    public function destroy(string $lead_uuid, int $applicant_sno, Request $request): JsonResponse
    {
        $isGuarantor = strtolower((string) $request->query('applicant_type', '')) === 'guarantor';

      
        $query = BLApplicant::query()
            ->where('lead_uuid', $lead_uuid)
            ->where('applicant_sno', $applicant_sno);

        if ($isGuarantor) {
            $query->where('applicant_types', 'Guarantor');
        } else {
            $query->where('applicant_types', '!=', 'Guarantor');
        }

        $blApplicant = $query->first();

        if (!$blApplicant) {
            return response()->json([
                'status'     => false,
                'request_id' => app('request_id'),
                'timestamp'  => now()->format('Y-m-d H:i:s'),
                'message'    => 'Applicant not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        if (!$isGuarantor && (int) $blApplicant->applicant_sno === 0) {
            return response()->json([
                'status'     => false,
                'code'       => Response::HTTP_FORBIDDEN,
                'request_id' => app('request_id'),
                'timestamp'  => now()->format('Y-m-d H:i:s'),
                'message'    => 'Primary applicant cannot be deleted.',
            ], Response::HTTP_FORBIDDEN);
        }

        \Modules\BLLeadCreation\Models\BLLeadCreation::query()
            ->where('uuid', $lead_uuid)
            ->firstOrFail();

        DB::beginTransaction();
        try {
            BLApplicantDetails::query()
                ->where('applicant_id', $blApplicant->id)
                ->delete();

            $blApplicant->delete();

            if ($isGuarantor) {
              
                BLApplicant::query()
                    ->where('lead_uuid', $lead_uuid)
                    ->where('applicant_types', 'Guarantor')
                    ->orderBy('id')
                    ->get()
                    ->each(function (BLApplicant $applicant, int $index): void {
                        $newSno = $index + 1; 
                        $applicant->update(['applicant_sno' => $newSno]);

                        BLApplicantDetails::query()
                            ->where('applicant_id', $applicant->id)
                            ->update(['applicant_sno' => $newSno]);
                    });
            } else {
                
                BLApplicant::query()
                    ->where('lead_uuid', $lead_uuid)
                    ->where('applicant_types', '!=', 'Guarantor')
                    ->orderBy('id')
                    ->get()
                    ->each(function (BLApplicant $applicant, int $index): void {
                        $applicant->update(['applicant_sno' => $index]);

                        BLApplicantDetails::query()
                            ->where('applicant_id', $applicant->id)
                            ->update(['applicant_sno' => $index]);
                    });
            }

            DB::commit();

            return response()->json([
                'status'     => true,
                'code'       => Response::HTTP_OK,
                'request_id' => app('request_id'),
                'timestamp'  => now()->format('Y-m-d H:i:s'),
                'message'    => 'BL applicant deleted and re-ordered successfully.',
            ], Response::HTTP_OK);

        } catch (\Throwable $exception) {
            DB::rollBack();
            report($exception);

            return response()->json([
                'status'     => false,
                'request_id' => app('request_id'),
                'timestamp'  => now()->format('Y-m-d H:i:s'),
                'message'    => 'Something went wrong while deleting applicant.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}