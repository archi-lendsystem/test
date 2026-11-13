<?php

namespace Modules\Repayment\Services;

use Modules\Repayment\Enums\PaymentMode;

final class RepaymentImportTemplateService
{
    public const TEMPLATE_VERSION = 3;
    public const MAX_ROWS = 1000;

    public function build(?string $search = null, int $limit = self::MAX_ROWS): array
    {
        return [
            'template_version' => self::TEMPLATE_VERSION,
            'template_generated_at' => now()->toIso8601String(),
            'timezone' => config('app.timezone', 'UTC'),
            'max_rows' => min(max($limit, 1), self::MAX_ROWS),
            'gst_percentage' => '18.00',
            'columns' => [
                'loan_number', 'amount_collected', 'collection_date', 'payment_mode',
                'utr_number', 'penalty', 'bounce', 'remarks',
            ],
            'payment_modes' => array_map(
                static fn (PaymentMode $mode): array => [
                    'value' => $mode->value,
                    'label' => $mode->label(),
                    'requires_reference' => $mode->requiresReference(),
                ],
                PaymentMode::cases()
            ),
        ];
    }
}


