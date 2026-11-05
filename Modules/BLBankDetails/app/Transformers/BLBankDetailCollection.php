<?php

namespace Modules\BLBankDetails\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class BLBankDetailCollection extends ResourceCollection
{
    public $collects = BLBankDetailResource::class;

    public function toArray($request): array
    {
        return [
            'success'     => true,
            'status_code' => 200,
            'message'     => 'Bank details retrieved successfully.',
            'request_id' => app('request_id'),

            'summary' => [
                'total_accounts'   => $this->collection->count(),
                'primary_account'  => $this->collection
                    ->firstWhere('is_primary', true)?->account_holder_name ?? null,
                'account_types'    => $this->collection
                    ->pluck('account_type')
                    ->unique()
                    ->values(),
                    'has_statement'    => $this->collection
    ->filter(fn($item) => !empty($item->statement_file))
    ->count(),
            ],

            'data' => $this->collection,
        ];
    }
}
