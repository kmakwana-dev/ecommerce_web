<?php

namespace App\Services;

use App\Constants\Status;
use App\Models\Deposit;

/**
 * Static UPI/VPA velocity protection.
 *
 * No migration/configuration is required. It only acts when the gateway
 * response actually contains a payer VPA.
 */
class PaymentVelocityValidator
{
    // A single VPA may complete only one transaction in this rolling window.
    private const WINDOW_MINUTES = 10;
    private const MAX_SUCCESSFUL_TRANSACTIONS = 1;

    public static function allows(object $deposit, array $gatewayPayload): bool
    {
        $payerVpa = self::extractPayerVpa($gatewayPayload);

        // Do not block when the provider did not return a payer VPA.
        if (!$payerVpa) {
            return true;
        }

        $cutoff = now()->subMinutes(self::WINDOW_MINUTES);

        $recentDeposits = Deposit::query()
            ->where('id', '!=', $deposit->id)
            ->where('status', Status::PAYMENT_SUCCESS)
            ->where('updated_at', '>=', $cutoff)
            ->latest('id')
            ->limit(100)
            ->get(['id', 'detail']);

        $matches = 0;

        foreach ($recentDeposits as $recentDeposit) {
            $detail = $recentDeposit->detail;
            if (is_object($detail)) {
                $detail = json_decode(json_encode($detail), true);
            }
            if (!is_array($detail)) {
                continue;
            }

            if (self::extractPayerVpa($detail) === $payerVpa) {
                $matches++;
                if ($matches >= self::MAX_SUCCESSFUL_TRANSACTIONS) {
                    return false;
                }
            }
        }

        return true;
    }

    public static function extractPayerVpa(array $payload): ?string
    {
        $candidateKeys = [
            'payer_vpa', 'payerVPA', 'payerVpa',
            'payer_vpa_id', 'payerVpaId',
            'customer_vpa', 'customerVPA', 'customerVpa',
            'vpa', 'upi_vpa', 'upiVpa',
        ];

        $stack = [$payload];

        while ($stack) {
            $current = array_pop($stack);

            foreach ($candidateKeys as $key) {
                if (array_key_exists($key, $current) && is_scalar($current[$key])) {
                    $vpa = strtolower(trim((string) $current[$key]));
                    if (self::isValidVpa($vpa)) {
                        return $vpa;
                    }
                }
            }

            foreach ($current as $value) {
                if (is_array($value)) {
                    $stack[] = $value;
                }
            }
        }

        return null;
    }

    private static function isValidVpa(string $vpa): bool
    {
        return (bool) preg_match('/^[a-z0-9._-]{2,256}@[a-z0-9.-]{2,64}$/i', $vpa);
    }
}
