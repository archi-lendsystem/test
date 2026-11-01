<?php

namespace Modules\Customers\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Customers\Support\LeadStatusResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\BLDocumentUpload\Models\BLDocumentUpload;
use Modules\Customers\Models\Customer;
use Modules\Customers\Transformers\CustomerCollection;
use Modules\Customers\Transformers\CustomerResource;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class CustomersController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $customers = Customer::query()
                ->withCount('leads')
                ->when($request->filled('search'), function ($q) use ($request) {
                    $search = $request->get('search');
                    $q->where(function ($sub) use ($search) {
                        $sub->where('customer_application_id', 'like', "%{$search}%")
                            ->orWhere('full_name', 'like', "%{$search}%")
                            ->orWhere('mobile_number', 'like', "%{$search}%")
                            ->orWhere('pan_card_number', 'like', "%{$search}%")
                            ->orWhere('aadhaar_card_number', 'like', "%{$search}%");
                    });
                })
                ->orderByDesc('id')
                ->paginate((int) $request->get('per_page', 15));

            return (new CustomerCollection($customers))
                ->response()
                ->setStatusCode(200);
        } catch (Throwable $e) {
            return response()->json([
                'success'    => false,
                'message'    => 'Something went wrong while fetching customers.',
                'request_id' => (string) Str::uuid(),
                'timestamp'  => now()->format('Y-m-d H:i:s'),
                'errors'     => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function show(string $customer_application_id): JsonResponse
    {
        try {
            $customer = Customer::where('customer_application_id', $customer_application_id)
                ->withCount('leads')
                ->with(['leads' => fn($q) => $q->orderByDesc('id')->with('disbursement')])
                ->first();

            if (! $customer) {
                return response()->json([
                    'success'    => false,
                    'message'    => 'Customer not found.',
                    'request_id' => (string) Str::uuid(),
                    'timestamp'  => now()->format('Y-m-d H:i:s'),
                ], 404);
            }

            $loans = $customer->leads->map(function ($lead) {
                $status = LeadStatusResolver::resolve($lead);

                $documents = BLDocumentUpload::where('lead_id', $lead->uuid)->get();

                return [
                    'lead_uuid'      => $lead->uuid,
                    'lead_id'        => $lead->lead_id,
                    'loan_type'      => $lead->loan_type,
                    'loan_amount'    => $lead->loan_amount,
                    'current_stage'  => $status['stage'],
                    'current_status' => $status['status'],
                    'is_active'      => ! $status['is_closed'],
                    'is_closed'      => $status['is_closed'],
                    'documents'      => $documents->map(fn($doc) => [
                        'document_name'  => $doc->document_name,
                        'document_group' => $doc->document_group,
                        'document'       => $doc->document,
                        'uploaded_at'    => $doc->created_at?->format('Y-m-d H:i:s'),
                    ]),
                    'disbursement' => $lead->disbursement ? [
                        'disbursement_amount' => $lead->disbursement->disbursement_amount,
                        'bank_name'           => $lead->disbursement->bank_name,
                        'utr_number'          => $lead->disbursement->utr_number,
                        'disbursement_date'   => $lead->disbursement->disbursement_date?->format('Y-m-d'),
                    ] : null,
                ];
            });

            return response()->json([
                'success'    => true,
                'message'    => 'Customer details fetched successfully.',
                'request_id' => (string) Str::uuid(),
                'timestamp'  => now()->format('Y-m-d H:i:s'),
                'data'       => [
                    'customer' => new CustomerResource($customer),
                    'loans'    => $loans,
                ],
            ], 200);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success'    => false,
                'message'    => 'Something went wrong while fetching customer details.',
                'request_id' => (string) Str::uuid(),
                'timestamp'  => now()->format('Y-m-d H:i:s'),
                'errors'     => $e->getMessage(),
            ], 500);
        }
    }

    // public function export(): BinaryFileResponse|JsonResponse
    // {
    //     try {
    //         $customers = Customer::with('leads.disbursement')->orderBy('full_name')->get();

    //         $spreadsheet = new Spreadsheet();
    //         $sheet = $spreadsheet->getActiveSheet();

    //         $headers = [
    //             'Application ID',
    //             'Full Name',
    //             'PAN',
    //             'Aadhaar',
    //             'Mobile Number',
    //             'Email',
    //             'Total Loans',
    //             'Active Loans',
    //             'Closed Loans',
    //             'Total Disbursed Amount',
    //         ];
    //         $sheet->fromArray($headers, null, 'A1');

    //         $row = 2;
    //         foreach ($customers as $customer) {
    //             $activeCount = 0;
    //             $closedCount = 0;
    //             $totalDisbursed = 0;

    //             foreach ($customer->leads as $lead) {
    //                 $status = LeadStatusResolver::resolve($lead);

    //                 if ($status['is_closed']) {
    //                     $closedCount++;
    //                     $totalDisbursed += (float) ($lead->disbursement?->disbursement_amount ?? 0);
    //                 } else {
    //                     $activeCount++;
    //                 }
    //             }

    //             $sheet->fromArray([
    //                 $customer->customer_application_id,
    //                 $customer->full_name,
    //                 $customer->pan_card_number,
    //                 $customer->aadhaar_card_number,
    //                 $customer->mobile_number,
    //                 $customer->email_address,
    //                 $customer->leads->count(),
    //                 $activeCount,
    //                 $closedCount,
    //                 $totalDisbursed,
    //             ], null, "A{$row}");

    //             $row++;
    //         }

    //         foreach (range('A', 'J') as $column) {
    //             $sheet->getColumnDimension($column)->setAutoSize(true);
    //         }

    //         $filename = 'customers-export-' . now()->format('Ymd-His') . '.xlsx';
    //         $tmpDir = storage_path('app/tmp');

    //         if (! is_dir($tmpDir)) {
    //             mkdir($tmpDir, 0755, true);
    //         }

    //         $filePath = $tmpDir . '/' . $filename;

    //         (new Xlsx($spreadsheet))->save($filePath);

    //         // Discard any stray buffered output emitted earlier in the request
    //         // lifecycle (unrelated to this endpoint) so it can't corrupt the
    //         // binary xlsx stream — JSON responses tolerate leading whitespace,
    //         // a zip/xlsx file's magic-byte signature does not.
    //         while (ob_get_level() > 0) {
    //             ob_end_clean();
    //         }

    //         return response()->download($filePath, $filename)->deleteFileAfterSend(true);
    //     } catch (Throwable $e) {
    //         return response()->json([
    //             'success'    => false,
    //             'message'    => 'Something went wrong while exporting customers.',
    //             'request_id' => (string) Str::uuid(),
    //             'timestamp'  => now()->format('Y-m-d H:i:s'),
    //             'errors'     => app()->environment('local') ? $e->getMessage() : null,
    //         ], 500);
    //     }
    // }

    public function export(): BinaryFileResponse|JsonResponse
{
    try {
        $customers = Customer::query()
            ->with([
                'leads' => fn ($query) => $query->with('disbursement'),
            ])
            ->orderBy('full_name')
            ->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Customers');

        $headers = [
            'Application ID',
            'Full Name',
            'PAN',
            'Aadhaar',
            'Mobile Number',
            'Email',
            'Total Loans',
            'Active Loans',
            'Closed Loans',
            'Total Disbursed Amount',
        ];

        $sheet->fromArray($headers, null, 'A1');

        /*
         * Header formatting
         */
        $sheet->getStyle('A1:J1')->getFont()
            ->setBold(true)
            ->getColor()
            ->setARGB('FFFFFFFF');

        $sheet->getStyle('A1:J1')->getFill()
            ->setFillType(
                \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID
            )
            ->getStartColor()
            ->setARGB('FF1F4E78');

        $sheet->getStyle('A1:J1')->getAlignment()
            ->setHorizontal(
                \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER
            )
            ->setVertical(
                \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER
            );

        $sheet->getRowDimension(1)->setRowHeight(25);

        $row = 2;

        foreach ($customers as $customer) {
            $activeCount = 0;
            $closedCount = 0;
            $totalDisbursedAmount = 0.0;

            foreach ($customer->leads as $lead) {
                $status = LeadStatusResolver::resolve($lead);

                if ($status['is_closed']) {
                    $closedCount++;
                } else {
                    $activeCount++;
                }

                // Include disbursement amount for every disbursed loan.
                $totalDisbursedAmount += (float) (
                    $lead->disbursement?->disbursement_amount ?? 0
                );
            }

            /*
             * IDs and phone numbers are written as strings so Excel does not
             * convert them to scientific notation or remove leading zeroes.
             */
            $sheet->setCellValueExplicit(
                "A{$row}",
                (string) $customer->customer_application_id,
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
            );

            $sheet->setCellValue("B{$row}", $customer->full_name ?? '');

            $sheet->setCellValueExplicit(
                "C{$row}",
                (string) ($customer->pan_card_number ?? ''),
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
            );

            $sheet->setCellValueExplicit(
                "D{$row}",
                (string) ($customer->aadhaar_card_number ?? ''),
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
            );

            $sheet->setCellValueExplicit(
                "E{$row}",
                (string) ($customer->mobile_number ?? ''),
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
            );

            $sheet->setCellValue("F{$row}", $customer->email_address ?? '');
            $sheet->setCellValue("G{$row}", $customer->leads->count());
            $sheet->setCellValue("H{$row}", $activeCount);
            $sheet->setCellValue("I{$row}", $closedCount);
            $sheet->setCellValue("J{$row}", $totalDisbursedAmount);

            $row++;
        }

        $lastRow = max(1, $row - 1);

        /*
         * Excel formatting
         */
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:J{$lastRow}");

        $sheet->getStyle("G2:I{$lastRow}")
            ->getNumberFormat()
            ->setFormatCode('0');

        $sheet->getStyle("J2:J{$lastRow}")
            ->getNumberFormat()
            ->setFormatCode('#,##0.00');

        $sheet->getStyle("G2:J{$lastRow}")
            ->getAlignment()
            ->setHorizontal(
                \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT
            );

        $sheet->getStyle("A1:J{$lastRow}")
            ->getBorders()
            ->getAllBorders()
            ->setBorderStyle(
                \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN
            )
            ->getColor()
            ->setARGB('FFD9E1F2');

        foreach (range('A', 'J') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $filename = 'customers-export-' . now()->format('Ymd-His') . '.xlsx';
        $tmpDirectory = storage_path('app/tmp');

        if (! is_dir($tmpDirectory)) {
            if (! mkdir($tmpDirectory, 0755, true) && ! is_dir($tmpDirectory)) {
                throw new \RuntimeException(
                    "Unable to create export directory: {$tmpDirectory}"
                );
            }
        }

        $filePath = $tmpDirectory . DIRECTORY_SEPARATOR . $filename;

        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $writer);

        // Prevent stray output from corrupting the XLSX binary response.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        return response()
            ->download(
                $filePath,
                $filename,
                [
                    'Content-Type' =>
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ]
            )
            ->deleteFileAfterSend(true);
    } catch (Throwable $e) {
        report($e);

        return response()->json([
            'success'    => false,
            'message'    => 'Something went wrong while exporting customers.',
            'request_id' => (string) Str::uuid(),
            'timestamp'  => now()->format('Y-m-d H:i:s'),
            'errors'     => app()->environment('local')
                ? $e->getMessage()
                : null,
        ], 500);
    }
}
}
