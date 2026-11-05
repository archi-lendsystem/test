<?php

namespace Modules\Customers\Support;

use Modules\BLLeadCreation\Models\BLLeadCreation;

final class LeadStatusResolver
{
    public static function resolve(BLLeadCreation $lead): array
    {
        $stage = trim((string) (
            $lead->getAttribute('current_stage')
            ?? $lead->getAttribute('stage')
            ?? 'Lead Creation'
        ));

        $status = trim((string) (
            $lead->getAttribute('status')
            ?? $lead->getAttribute('lead_status')
            ?? 'Pending'
        ));

        $normalizedStatus = strtolower(
            str_replace([' ', '-'], '_', $status)
        );

        $closedStatuses = [
            'closed',
            'completed',
            'rejected',
            'cancelled',
            'canceled',
            'foreclosed',
            'written_off',
            'withdrawn',
        ];

        return [
            'stage' => $stage !== '' ? $stage : 'Lead Creation',
            'status' => $status !== '' ? $status : 'Pending',
            'is_closed' => in_array(
                $normalizedStatus,
                $closedStatuses,
                true
            ),
        ];
    }
}