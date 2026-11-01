<?php

namespace Modules\Repayment\Services;

use Carbon\CarbonImmutable;
use Modules\Repayment\Enums\PaymentMode;
use Modules\Repayment\Enums\RepaymentImportRowStatus;
use Modules\Repayment\Enums\RepaymentTransactionStatus;
use Modules\Repayment\Enums\RepaymentTransactionType;
use Modules\Repayment\Models\RepaymentAccount;
use Modules\Repayment\Models\RepaymentTransaction;
use Throwable;

final class RepaymentImportValidationService
{
    private const GST = '18.00';
    private const SCALE = 2;

    public function validateRows(array $rows): array
    {
        $validated = [];
        $validRows = 0;
        $errorRows = 0;
        $total = '0.00';
        $seenReferences = [];

        foreach ($rows as $index => $raw) {
            $row = $this->validateRow($raw, (int) ($raw['row_number'] ?? $index + 2), $seenReferences);
            $validated[] = $row;
            $row['validation_status'] === RepaymentImportRowStatus::VALID->value ? $validRows++ : $errorRows++;
            if ($row['amount_collected'] !== null) {
                $total = bcadd($total, $row['amount_collected'], self::SCALE);
            }
        }

        return [
            'total_rows' => count($validated),
            'valid_rows' => $validRows,
            'error_rows' => $errorRows,
            'total_amount' => $total,
            'rows' => $validated,
        ];
    }

    private function validateRow(array $raw, int $rowNumber, array &$seenReferences): array
    {
        $errors = [];
        $loanNumber = $this->text($raw['loan_number'] ?? null);
        $amount = $this->money($raw['amount_collected'] ?? null, false);
        $penalty = $this->money($raw['penalty'] ?? null, true) ?? '0.00';
        $bounce = $this->money($raw['bounce'] ?? null, true) ?? '0.00';
        $remarks = $this->text($raw['remarks'] ?? null);
        $modeRaw = strtoupper((string) ($this->text($raw['payment_mode'] ?? null) ?? ''));
        $mode = PaymentMode::tryFrom($modeRaw);
        $utr = $this->text($raw['utr_number'] ?? null);
        $date = $this->date($raw['collection_date'] ?? null, $errors);
        $account = null;

        if ($loanNumber === null) {
            $this->error($errors, 'loan_number', 'LOAN_NUMBER_REQUIRED', 'Loan Number is required.');
        } else {
            $matches = RepaymentAccount::query()->useWritePdo()->where('loan_number', $loanNumber)->limit(2)->get();
            if ($matches->isEmpty()) {
                $this->error($errors, 'loan_number', 'LOAN_NOT_FOUND', "Loan Number {$loanNumber} was not found.");
            } elseif ($matches->count() > 1) {
                $this->error($errors, 'loan_number', 'LOAN_NUMBER_NOT_UNIQUE', "Loan Number {$loanNumber} is not unique.");
            } else {
                $account = $matches->first();
                if (! $account->allowsPayment()) {
                    $this->error($errors, 'loan_number', 'ACCOUNT_NOT_PAYABLE', 'This repayment account does not allow collection.');
                }
            }
        }

        if ($amount === null || bccomp($amount, '0.00', self::SCALE) !== 1) {
            $this->error($errors, 'amount_collected', 'AMOUNT_INVALID', 'Amount Collected must be greater than zero with maximum two decimal places.');
        }
        if ($this->money($raw['penalty'] ?? null, true) === null && $this->text($raw['penalty'] ?? null) !== null) {
            $this->error($errors, 'penalty', 'PENALTY_INVALID', 'Penalty must be zero or a positive amount with maximum two decimal places.');
        }
        if ($this->money($raw['bounce'] ?? null, true) === null && $this->text($raw['bounce'] ?? null) !== null) {
            $this->error($errors, 'bounce', 'BOUNCE_INVALID', 'Bounce must be zero or a positive amount with maximum two decimal places.');
        }
        if ($mode === null) {
            $this->error($errors, 'payment_mode', 'PAYMENT_MODE_INVALID', 'Select a valid Payment Mode.');
        }
        if ($mode?->requiresReference() && $utr === null) {
            $this->error($errors, 'utr_number', 'UTR_REQUIRED', 'UTR Number is required for this Payment Mode.');
        }
        if ($remarks !== null && mb_strlen($remarks) > 2000) {
            $this->error($errors, 'remarks', 'REMARKS_TOO_LONG', 'Remarks cannot exceed 2000 characters.');
            $remarks = null;
        }

        if ($utr !== null && $mode?->requiresReference()) {
            $referenceKey = mb_strtolower($utr);
            if (isset($seenReferences[$referenceKey])) {
                $this->error($errors, 'utr_number', 'DUPLICATE_UTR_IN_FILE', "UTR {$utr} is already present in Excel row {$seenReferences[$referenceKey]}.");
            } else {
                $seenReferences[$referenceKey] = $rowNumber;
            }

            $exists = RepaymentTransaction::query()->useWritePdo()
                ->whereRaw('LOWER(transaction_reference) = ?', [$referenceKey])
                ->where('transaction_type', RepaymentTransactionType::PAYMENT->value)
                ->where('status', '!=', RepaymentTransactionStatus::FAILED->value)
                ->exists();
            if ($exists) {
                $this->error($errors, 'utr_number', 'UTR_ALREADY_USED', "UTR {$utr} has already been posted.");
            }
        }

        $penaltyGst = $this->gst($penalty);
        $bounceGst = $this->gst($bounce);
        $penaltyTotal = bcadd($penalty, $penaltyGst, self::SCALE);
        $bounceTotal = bcadd($bounce, $bounceGst, self::SCALE);
        $chargeTotal = bcadd($penaltyTotal, $bounceTotal, self::SCALE);
        $emiAmount = $amount !== null ? bcsub($amount, $chargeTotal, self::SCALE) : null;

        if ($emiAmount !== null && bccomp($emiAmount, '0.00', self::SCALE) !== 1) {
            $this->error($errors, 'amount_collected', 'EMI_AMOUNT_NOT_POSITIVE', 'Amount Collected must be greater than Penalty and Bounce including 18% GST.');
        }
        if ($account && $emiAmount && bccomp($emiAmount, (string) $account->total_outstanding, self::SCALE) === 1) {
            $this->error($errors, 'amount_collected', 'AMOUNT_EXCEEDS_OUTSTANDING', 'EMI allocation amount exceeds the loan outstanding amount.');
        }

        return [
            'row_number' => $rowNumber,
            'repayment_account_uuid' => $account?->uuid,
            'loan_number' => $loanNumber,
            'amount' => $emiAmount,
            'amount_collected' => $amount,
            'collection_date' => $date,
            'payment_mode' => $mode?->value,
            'transaction_reference' => $utr,
            'utr_number' => $utr,
            'penalty_base_amount' => $penalty,
            'penalty_gst_amount' => $penaltyGst,
            'penalty_total_amount' => $penaltyTotal,
            'bounce_base_amount' => $bounce,
            'bounce_gst_amount' => $bounceGst,
            'bounce_total_amount' => $bounceTotal,
            'emi_allocation_amount' => $emiAmount,
            'received_date' => $date,
            'value_date' => $date,
            'remarks' => $remarks,
            'validation_status' => $errors === [] ? RepaymentImportRowStatus::VALID->value : RepaymentImportRowStatus::ERROR->value,
            'validation_errors' => $errors,
            'raw_payload' => $raw,
        ];
    }

    private function gst(string $base): string
    {
        return bcdiv(bcmul($base, self::GST, 4), '100', self::SCALE);
    }

    private function money(mixed $value, bool $allowZero): ?string
    {
        if ($value === null || trim((string) $value) === '') return $allowZero ? '0.00' : null;
        $text = str_replace([',', '₹', ' '], '', trim((string) $value));
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $text)) return null;
        $normalized = bcadd($text, '0', self::SCALE);
        if (! $allowZero && bccomp($normalized, '0.00', self::SCALE) !== 1) return null;
        return $normalized;
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) return null;
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function date(mixed $value, array &$errors): string
    {
        if ($value === null || trim((string) $value) === '') return now(config('app.timezone'))->toDateString();
        try {
            $date = CarbonImmutable::parse((string) $value, config('app.timezone'))->startOfDay();
            return $date->toDateString();
        } catch (Throwable) {
            $this->error($errors, 'collection_date', 'DATE_INVALID', 'Collection Date is invalid. Use YYYY-MM-DD.');
            return now(config('app.timezone'))->toDateString();
        }
    }

    private function error(array &$errors, string $field, string $code, string $message): void
    {
        $errors[] = compact('field', 'code', 'message');
    }
}





