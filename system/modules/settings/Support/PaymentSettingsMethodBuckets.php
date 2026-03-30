<?php

declare(strict_types=1);

namespace Modules\Settings\Support;

use Modules\Sales\Support\PaymentMethodFamily;

/**
 * Read-only bucketing of active payment methods for Payment Settings summaries.
 * Acceptance comes from {@see \Modules\Sales\Repositories\PaymentMethodRepository::listActive} rows.
 * Grouping uses {@see PaymentMethodFamily} only—no duplicate heuristics here.
 */
final class PaymentSettingsMethodBuckets
{
    /**
     * @param list<array{code:string,name:string}> $activeMethods Active methods (e.g. {@see \Modules\Sales\Services\PaymentMethodService::listForPaymentForm} output shape; gift_card already excluded).
     * @return array{
     *   checks: list<array{code:string,name:string}>,
     *   cash: list<array{code:string,name:string}>,
     *   other: list<array{code:string,name:string}>
     * }
     */
    public static function bucket(array $activeMethods): array
    {
        $checks = [];
        $cash = [];
        $other = [];
        foreach ($activeMethods as $row) {
            $code = (string) ($row['code'] ?? '');
            $name = (string) ($row['name'] ?? '');
            if (trim($code) === '' && trim($name) === '') {
                continue;
            }
            $entry = ['code' => $code, 'name' => $name];
            $family = PaymentMethodFamily::resolve($code, $name);
            if ($family === PaymentMethodFamily::GIFT_CARD) {
                continue;
            }
            if ($family === PaymentMethodFamily::CHECK) {
                $checks[] = $entry;
            } elseif ($family === PaymentMethodFamily::CASH) {
                $cash[] = $entry;
            } else {
                $other[] = $entry;
            }
        }

        return ['checks' => $checks, 'cash' => $cash, 'other' => $other];
    }
}
