<?php

namespace Modules\ProductScheme\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Modules\Product\Models\Product;
use Modules\ProductScheme\Http\Requests\StoreProductSchemeRequest;
use Modules\ProductScheme\Http\Requests\UpdateProductSchemeRequest;
use Modules\ProductScheme\Models\ProductScheme;
use Modules\ProductScheme\Transformers\ProductSchemeResource;

class ProductSchemeController extends Controller
{
     public function options(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'request_id' => app('request_id'),
            'message' => 'Product scheme options fetched successfully.',
            'data' => [
                'sub_loan_types' => $this->formatOptions(
                    config('product_configuration.sub_loan_types', [])
                ),
                'repayment_frequencies' => $this->formatOptions(
                    config('product_configuration.repayment_frequencies', [])
                ),
                'calculation_methods' => $this->formatOptions(
                    config('product_configuration.calculation_methods', [])
                ),
                'overdue_interest_on' => $this->formatOptions(
                    config('product_configuration.overdue_interest_on', [])
                ),
                'emi_round_off_types' => $this->formatOptions(
                    config('product_configuration.emi_round_off_types', [])
                ),

                   'tenure_types' => $this->formatOptions(
    config('product_configuration.tenure_types', [])
),
            ],
                'tenure_units' => $this->formatOptions(
                    config('product_configuration.tenure_units', [])
                ),
                'status_options' => $this->formatOptions(
                    config('product_configuration.status_options', [])
                ),
                'form_fields' => config('product_configuration.form_fields', []),

             
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 15);
        $perPage = $perPage > 0 && $perPage <= 100 ? $perPage : 15;

        $schemes = ProductScheme::query()
            ->with('product')
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim((string) $request->search);

                $query->where(function ($q) use ($search) {
                    $q->where('scheme_name', 'like', "%{$search}%")
                        ->orWhere('product_name', 'like', "%{$search}%")
                        ->orWhere('sub_loan_type', 'like', "%{$search}%")
                        ->orWhere('repayment_frequency', 'like', "%{$search}%")
                        ->orWhereHas('product', function ($productQuery) use ($search) {
                            $productQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('code', 'like', "%{$search}%");
                        });
                });
            })
            ->when($request->filled('product_id'), function ($query) use ($request) {
                $query->where('product_id', $request->product_id);
            })
            ->when($request->filled('sub_loan_type'), function ($query) use ($request) {
                $query->where('sub_loan_type', $request->sub_loan_type);
            })
            ->when($request->filled('repayment_frequency'), function ($query) use ($request) {
                $query->where('repayment_frequency', $request->repayment_frequency);
            })
            ->when($request->filled('is_active'), function ($query) use ($request) {
                $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
            })
            ->when($request->filled('valid_from'), function ($query) use ($request) {
                $query->whereDate('valid_from', '>=', $request->valid_from);
            })
            ->when($request->filled('valid_till'), function ($query) use ($request) {
                $query->whereDate('valid_till', '<=', $request->valid_till);
            })
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'request_id' => app('request_id'),
            'message' => 'Product schemes fetched successfully.',
            'data' => ProductSchemeResource::collection($schemes),
            'meta' => [
                'current_page' => $schemes->currentPage(),
                'last_page' => $schemes->lastPage(),
                'per_page' => $schemes->perPage(),
                'total' => $schemes->total(),
            ],
        ]);
    }

    public function show(ProductScheme $productScheme): JsonResponse
    {
        return response()->json([
            'success' => true,
            'request_id' => app('request_id'),
            'message' => 'Product scheme fetched successfully.',
            'data' => new ProductSchemeResource(
                $productScheme->load('product')
            ),
        ]);
    }

    public function store(StoreProductSchemeRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $validated = $this->normalizeTenurePayload(
    $validated,
    $validated['tenure_type']
);

        $product = Product::query()->findOrFail($validated['product_id']);

        $validated['product_name'] = $product->name;
        $validated['scheme_name'] = $validated['scheme_name']
            ?? $this->generateSchemeName($product->name, $validated['sub_loan_type']);

        $validated['lpf_rate'] = $validated['lpf_rate'] ?? 0;


        $validated['platform_charges'] = $validated['platform_charges'] ?? 0;
        $validated['sms_charges'] = $validated['sms_charges'] ?? 0;
        $validated['minimum_loan_amount'] = $validated['minimum_loan_amount'] ?? 0;
        $validated['maximum_loan_amount'] = $validated['maximum_loan_amount'] ?? 0;
        // $validated['minimum_pf'] = $validated['minimum_pf'] ?? 0;
        // $validated['maximum_pf'] = $validated['maximum_pf'] ?? 0;

        
        $validated['moratorium_days'] = $validated['moratorium_days'] ?? 0;
        $validated['emi_round_up_value'] = $validated['emi_round_up_value'] ?? 0;
        $validated['is_active'] = $validated['is_active'] ?? true;
        $validated['created_by'] = auth()->id();
        $validated['updated_by'] = auth()->id();

        //  if ($this->hasDuplicateActiveScheme($validated)) {
        //     return response()->json([
        //         'success' => false,
        //         'request_id' => app('request_id'),
        //         'message' => 'Active product scheme already exists for selected product, sub loan type, repayment frequency and tenure.',
        //     ], Response::HTTP_UNPROCESSABLE_ENTITY);
        // } 

        $scheme = DB::transaction(function () use ($validated) {
            return ProductScheme::create($validated);
        });

        return response()->json([
            'success' => true,
            'request_id' => app('request_id'),
            'message' => 'Product scheme created successfully.',
            'data' => new ProductSchemeResource(
                $scheme->load('product')
            ),
        ], Response::HTTP_CREATED);
    }



    public function update(UpdateProductSchemeRequest $request, ProductScheme $productScheme): JsonResponse
{
    $validated = $request->validated();

    $productScheme->loadMissing('product');

    $validated = $this->normalizeTenurePayload(
    $validated,
    $validated['tenure_type']
        ?? $productScheme->tenure_type
        ?? 'fixed'
);

    if (array_key_exists('product_id', $validated)) {
        $product = Product::query()->findOrFail($validated['product_id']);
        $validated['product_name'] = $product->name;
    }

    if (! array_key_exists('scheme_name', $validated) || empty($validated['scheme_name'])) {
        $productNameForScheme = $validated['product_name']
            ?? $productScheme->product_name
            ?? $productScheme->product?->name;

        $subLoanTypeForScheme = $validated['sub_loan_type']
            ?? $productScheme->sub_loan_type;

        if ($productNameForScheme && $subLoanTypeForScheme) {
            $validated['scheme_name'] = $this->generateSchemeName(
                $productNameForScheme,
                $subLoanTypeForScheme
            );
        }
    }

    $validated['updated_by'] = auth()->id();

    $duplicatePayload = $this->mergeSchemePayload($productScheme, $validated);

    // if (
    //     ($duplicatePayload['is_active'] ?? false)
    //     && $this->hasDuplicateActiveScheme($duplicatePayload, $productScheme->id)
    // ) {
    //     return response()->json([
    //         'success' => false,
    //         'request_id' => app('request_id'),
    //         'message' => 'Active product scheme already exists for selected product, sub loan type, repayment frequency and tenure.',
    //     ], Response::HTTP_UNPROCESSABLE_ENTITY);
    // }

    DB::transaction(function () use ($productScheme, $validated) {
        $productScheme->update($validated);
    });

    return response()->json([
        'success' => true,
        'request_id' => app('request_id'),
        'message' => 'Product scheme updated successfully.',
        'data' => new ProductSchemeResource(
            $productScheme->fresh()->load('product')
        ),
    ]);
}

    public function destroy(ProductScheme $productScheme): JsonResponse
    {
        DB::transaction(function () use ($productScheme) {
            $productScheme->update([
                'updated_by' => auth()->id(),
            ]);

            $productScheme->delete();
        });

        return response()->json([
            'success' => true,
            'request_id' => app('request_id'),
            'message' => 'Product scheme deleted successfully.',
        ]);
    }

    private function hasDuplicateActiveScheme(array $data, ?int $ignoreId = null): bool
    {
        if (! ($data['is_active'] ?? true)) {
            return false;
        }

        $validFrom = $data['valid_from'] ?? null;
        $validTill = $data['valid_till'] ?? null;

        return ProductScheme::query()
            ->where('is_active', true)
            ->where('product_id', $data['product_id'] ?? null)
            ->where('sub_loan_type', $data['sub_loan_type'] ?? null)
            ->where('repayment_frequency', $data['repayment_frequency'] ?? null)
            ->where('tenure_value', $data['tenure_value'] ?? null)
            // ->where('tenure_unit', $data['tenure_unit'] ?? null)
            ->where(function ($query) use ($data) {
    $tenureType = $data['tenure_type'] ?? 'fixed';

    if ($tenureType === 'fixed') {
        $tenureValue = (int) $data['tenure_value'];

        $query
            ->where(function ($fixedQuery) use ($tenureValue) {
                $fixedQuery
                    ->where('tenure_type', 'fixed')
                    ->where('tenure_value', $tenureValue);
            })
            ->orWhere(function ($rangeQuery) use ($tenureValue) {
                $rangeQuery
                    ->where('tenure_type', 'range')
                    ->where('minimum_tenure', '<=', $tenureValue)
                    ->where('maximum_tenure', '>=', $tenureValue);
            });

        return;
    }

    $minimumTenure = (int) $data['minimum_tenure'];
    $maximumTenure = (int) $data['maximum_tenure'];

    $query
        ->where(function ($fixedQuery) use (
            $minimumTenure,
            $maximumTenure
        ) {
            $fixedQuery
                ->where('tenure_type', 'fixed')
                ->whereBetween(
                    'tenure_value',
                    [$minimumTenure, $maximumTenure]
                );
        })
        ->orWhere(function ($rangeQuery) use (
            $minimumTenure,
            $maximumTenure
        ) {
            $rangeQuery
                ->where('tenure_type', 'range')
                ->where('minimum_tenure', '<=', $maximumTenure)
                ->where('maximum_tenure', '>=', $minimumTenure);
        });
})
            ->when($ignoreId, function ($query) use ($ignoreId) {
                $query->where('id', '!=', $ignoreId);
            })
            ->where(function ($query) use ($validFrom, $validTill) {
                $query->where(function ($q) use ($validTill) {
                    $q->whereNull('valid_from');

                    if ($validTill !== null) {
                        $q->orWhereDate('valid_from', '<=', $validTill);
                    } else {
                        $q->orWhereNotNull('valid_from');
                    }
                })
                ->where(function ($q) use ($validFrom) {
                    $q->whereNull('valid_till');

                    if ($validFrom !== null) {
                        $q->orWhereDate('valid_till', '>=', $validFrom);
                    } else {
                        $q->orWhereNotNull('valid_till');
                    }
                });
            })
            ->exists();
    }

    // private function mergeSchemePayload(ProductScheme $productScheme, array $data): array
    // {
    //     return [
    //         'product_id' => $data['product_id'] ?? $productScheme->product_id,
    //         'sub_loan_type' => $data['sub_loan_type'] ?? $productScheme->sub_loan_type,
    //         'repayment_frequency' => $data['repayment_frequency'] ?? $productScheme->repayment_frequency,
    //         'tenure_value' => $data['tenure_value'] ?? $productScheme->tenure_value,
    //         'tenure_unit' => $data['tenure_unit'] ?? $productScheme->tenure_unit,
    //         'valid_from' => $data['valid_from'] ?? optional($productScheme->valid_from)->format('Y-m-d'),
    //         'valid_till' => $data['valid_till'] ?? optional($productScheme->valid_till)->format('Y-m-d'),
    //         'is_active' => array_key_exists('is_active', $data)
    //             ? (bool) $data['is_active']
    //             : (bool) $productScheme->is_active,
    //     ];
    // }

    private function mergeSchemePayload(
    ProductScheme $productScheme,
    array $data
): array {
    $value = static fn (string $key, mixed $current): mixed =>
        array_key_exists($key, $data)
            ? $data[$key]
            : $current;

    return [
        'product_id' => $value(
            'product_id',
            $productScheme->product_id
        ),

        'sub_loan_type' => $value(
            'sub_loan_type',
            $productScheme->sub_loan_type
        ),

        'repayment_frequency' => $value(
            'repayment_frequency',
            $productScheme->repayment_frequency
        ),

        'tenure_type' => $value(
            'tenure_type',
            $productScheme->tenure_type ?: 'fixed'
        ),

        'tenure_value' => $value(
            'tenure_value',
            $productScheme->tenure_value
        ),

        'minimum_tenure' => $value(
            'minimum_tenure',
            $productScheme->minimum_tenure
        ),

        'maximum_tenure' => $value(
            'maximum_tenure',
            $productScheme->maximum_tenure
        ),

        'tenure_unit' => $value(
            'tenure_unit',
            $productScheme->tenure_unit
        ),

        'valid_from' => $value(
            'valid_from',
            optional($productScheme->valid_from)->format('Y-m-d')
        ),

        'valid_till' => $value(
            'valid_till',
            optional($productScheme->valid_till)->format('Y-m-d')
        ),

        'is_active' => (bool) $value(
            'is_active',
            $productScheme->is_active
        ),
    ];
}

    private function generateSchemeName(string $productName, string $subLoanType): string
    {
        $subLoanTypeLabel = config(
            "product_configuration.sub_loan_types.{$subLoanType}",
            $subLoanType
        );

        return trim($productName . ' - ' . $subLoanTypeLabel);
    }

    private function formatOptions(array $options): array
    {
        return collect($options)
            ->map(fn ($label, $value) => [
                'value' => $value,
                'label' => $label,
            ])
            ->values()
            ->all();
    }

    private function normalizeTenurePayload(
    array $data,
    string $tenureType
): array {
    $data['tenure_type'] = $tenureType;

    if ($tenureType === 'fixed') {
        $data['minimum_tenure'] = null;
        $data['maximum_tenure'] = null;
    } else {
        $data['tenure_value'] = null;
    }

    return $data;
}
}
