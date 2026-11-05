<?php

namespace Tests\Unit\Repayment;

use Modules\Repayment\Exceptions\RepaymentActivationException;
use Modules\Repayment\Services\AmortizationScheduleNormalizer;
use PHPUnit\Framework\TestCase;

class AmortizationScheduleNormalizerTest extends TestCase
{
    private AmortizationScheduleNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new AmortizationScheduleNormalizer();
    }

    public function test_it_normalizes_a_valid_schedule(): void
    {
        $result = $this->normalizer->normalize(
            schedule: $this->validSchedule(),
            amortizationId: 101
        );

        $this->assertCount(2, $result);

        $this->assertSame(
            1,
            $result[0]['installment_number']
        );

        $this->assertSame(
            '2026-08-05',
            $result[0]['due_date']
        );

        $this->assertSame(
            '29900.00',
            $result[0]['opening_principal']
        );

        $this->assertSame(
            '504.91',
            $result[0]['principal_due']
        );

        $this->assertSame(
            '149.50',
            $result[0]['interest_due']
        );

        $this->assertSame(
            '654.41',
            $result[0]['installment_amount']
        );

        $this->assertSame(
            '29395.09',
            $result[0]['closing_principal']
        );

        $this->assertSame(
            $this->validSchedule()[0],
            $result[0]['schedule_snapshot']
        );
    }

    public function test_it_rejects_an_invalid_payment_date(): void
    {
        $schedule = $this->validSchedule();

        $schedule[0]['payment_date'] = 'invalid-date';

        $this->expectException(
            RepaymentActivationException::class
        );

        $this->expectExceptionMessage(
            'payment_date must be a valid date in Y-m-d format'
        );

        $this->normalizer->normalize(
            schedule: $schedule,
            amortizationId: 101
        );
    }

    public function test_it_rejects_a_component_total_mismatch(): void
    {
        $schedule = $this->validSchedule();

        /*
         * Principal + interest = 654.41,
         * but installment amount is intentionally incorrect.
         */
        $schedule[0]['installment_amount'] = 700.00;

        $this->expectException(
            RepaymentActivationException::class
        );

        $this->expectExceptionMessage(
            'principal plus interest must equal installment_amount'
        );

        $this->normalizer->normalize(
            schedule: $schedule,
            amortizationId: 101
        );
    }

    public function test_it_rejects_broken_balance_continuity(): void
    {
        $schedule = $this->validSchedule();

        /*
         * EMI 1 closing balance is 29395.09.
         * EMI 2 opening balance must match it.
         */
        $schedule[1]['opening_balance'] = 29000.00;

        /*
         * Keep row-level closing calculation internally valid,
         * so the continuity validation is specifically tested.
         */
        $schedule[1]['closing_balance'] = 28492.57;

        $this->expectException(
            RepaymentActivationException::class
        );

        $this->expectExceptionMessage(
            'opening_balance must equal previous closing_balance'
        );

        $this->normalizer->normalize(
            schedule: $schedule,
            amortizationId: 101
        );
    }

    /**
     * @return array<int, array<string, int|float|string>>
     */
    private function validSchedule(): array
    {
        return [
            [
                'installment_no' => 1,
                'payment_date' => '2026-08-05',
                'opening_balance' => 29900.00,
                'installment_amount' => 654.41,
                'principal' => 504.91,
                'interest' => 149.50,
                'closing_balance' => 29395.09,
            ],
            [
                'installment_no' => 2,
                'payment_date' => '2026-08-12',
                'opening_balance' => 29395.09,
                'installment_amount' => 654.41,
                'principal' => 507.43,
                'interest' => 146.98,
                'closing_balance' => 28887.66,
            ],
        ];
    }
}