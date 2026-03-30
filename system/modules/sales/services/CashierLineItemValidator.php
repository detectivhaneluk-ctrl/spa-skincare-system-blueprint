<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Core\Contracts\ServiceListProvider;
use Modules\Clients\Repositories\ClientRepository;
use Modules\Inventory\Repositories\ProductRepository;
use Modules\Memberships\Repositories\MembershipDefinitionRepository;
use Modules\Packages\Repositories\PackageRepository;

/**
 * Validates cashier-normalized invoice lines for branch/org safety and type-specific rules.
 */
final class CashierLineItemValidator
{
    public function __construct(
        private ProductRepository $products,
        private ServiceListProvider $services,
        private PackageRepository $packages,
        private MembershipDefinitionRepository $membershipDefinitions,
        private ClientRepository $clients
    ) {
    }

    /**
     * @param list<array<string, mixed>> $items Output of {@see CashierLineItemParser::parseItemsFromPost}
     * @param array{branch_id: ?int, client_id: ?int} $invoice
     * @return array<string, string> field => message
     */
    public function validate(array $items, array $invoice): array
    {
        $errors = [];
        $branchId = isset($invoice['branch_id']) && $invoice['branch_id'] !== '' && $invoice['branch_id'] !== null
            ? (int) $invoice['branch_id']
            : null;
        $clientId = isset($invoice['client_id']) && $invoice['client_id'] !== '' && $invoice['client_id'] !== null
            ? (int) $invoice['client_id']
            : null;

        $membershipLines = 0;
        foreach ($items as $idx => $item) {
            if (!is_array($item)) {
                continue;
            }
            $lineNo = $idx + 1;
            $prefix = 'items[' . $idx . ']';
            $type = (string) ($item['item_type'] ?? CashierInvoiceLineType::MANUAL);

            if ($type === CashierInvoiceLineType::MEMBERSHIP) {
                $membershipLines++;
            }

            if ($type === CashierInvoiceLineType::GIFT_CARD) {
                if ($branchId === null || $branchId <= 0) {
                    $errors[$prefix] = 'Line #' . $lineNo . ': gift card sale requires a concrete invoice branch.';
                }
                if ((float) ($item['tax_rate'] ?? 0) !== 0.0) {
                    $errors[$prefix . '.tax'] = 'Line #' . $lineNo . ': gift card lines must use 0% tax.';
                }
                $face = $this->lineMonetaryBase($item);
                if ($face <= 0) {
                    $errors[$prefix . '.amount'] = 'Line #' . $lineNo . ': gift card face value must be greater than zero.';
                }
            }

            if ($type === CashierInvoiceLineType::SERIES) {
                if ($clientId === null || $clientId <= 0) {
                    $errors[$prefix] = 'Line #' . $lineNo . ': series (package) sale requires a client on the invoice.';
                }
                if ($branchId === null || $branchId <= 0) {
                    $errors[$prefix . '.branch'] = 'Line #' . $lineNo . ': series sale requires invoice branch.';
                }
                $pkgId = isset($item['source_id']) ? (int) $item['source_id'] : 0;
                if ($pkgId <= 0) {
                    $errors[$prefix . '.source'] = 'Line #' . $lineNo . ': select a package (series).';
                } elseif ($branchId !== null && $branchId > 0) {
                    $pkg = $this->packages->findInTenantScope($pkgId, $branchId);
                    if (!$pkg || ($pkg['status'] ?? '') !== 'active') {
                        $errors[$prefix . '.source'] = 'Line #' . $lineNo . ': package not found or inactive for this branch.';
                    }
                }
                $sessions = (int) round((float) ($item['quantity'] ?? 0));
                if ($sessions < 1) {
                    $errors[$prefix . '.qty'] = 'Line #' . $lineNo . ': assigned sessions must be at least 1.';
                }
            }

            if ($type === CashierInvoiceLineType::CLIENT_ACCOUNT) {
                $errors[$prefix] = 'Line #' . $lineNo . ': Client account (AR) posting is not enabled. Remove this line or change its type.';
            }

            if ($type === CashierInvoiceLineType::GIFT_VOUCHER) {
                $sid = isset($item['source_id']) ? (int) $item['source_id'] : 0;
                if ($sid > 0 && $branchId !== null && $branchId > 0) {
                    $p = $this->products->findInTenantScope($sid, $branchId);
                    if ($p === null) {
                        $errors[$prefix . '.source'] = 'Line #' . $lineNo . ': voucher catalog reference is not valid for this branch.';
                    }
                }
                if ($this->lineMonetaryBase($item) <= 0) {
                    $errors[$prefix . '.amount'] = 'Line #' . $lineNo . ': gift voucher amount must be greater than zero.';
                }
            }

            if ($type === CashierInvoiceLineType::MEMBERSHIP) {
                if ($clientId === null || $clientId <= 0) {
                    $errors[$prefix] = 'Line #' . $lineNo . ': membership requires a client on the invoice.';
                }
                if ($branchId === null || $branchId <= 0) {
                    $errors[$prefix . '.branch'] = 'Line #' . $lineNo . ': membership requires invoice branch.';
                }
                $defId = isset($item['source_id']) ? (int) $item['source_id'] : 0;
                if ($defId <= 0) {
                    $errors[$prefix . '.source'] = 'Line #' . $lineNo . ': membership definition is required.';
                } elseif ($branchId !== null && $branchId > 0) {
                    $def = $this->membershipDefinitions->findInTenantScope($defId, $branchId);
                    if ($def === null) {
                        $errors[$prefix . '.source'] = 'Line #' . $lineNo . ': membership plan not found for this branch.';
                    }
                }
                $meta = $item['line_meta'] ?? null;
                $starts = is_array($meta) ? trim((string) ($meta['membership_starts_at'] ?? '')) : '';
                if ($starts !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $starts) !== 1) {
                    $errors[$prefix . '.starts'] = 'Line #' . $lineNo . ': membership start must be empty or YYYY-MM-DD.';
                }
            }

            if ($type === CashierInvoiceLineType::TIP) {
                if ((float) ($item['quantity'] ?? 0) !== 1.0) {
                    $errors[$prefix . '.qty'] = 'Line #' . $lineNo . ': tip quantity must be 1.';
                }
                if ((float) ($item['tax_rate'] ?? 0) !== 0.0) {
                    $errors[$prefix . '.tax'] = 'Line #' . $lineNo . ': tips use 0% line tax (invoice tax is separate).';
                }
                if ((float) ($item['unit_price'] ?? 0) <= 0) {
                    $errors[$prefix . '.amount'] = 'Line #' . $lineNo . ': tip amount must be greater than zero.';
                }
            }

            if ($type === CashierInvoiceLineType::PRODUCT) {
                $pid = isset($item['source_id']) ? (int) $item['source_id'] : 0;
                if ($pid <= 0) {
                    $errors[$prefix . '.source'] = 'Line #' . $lineNo . ': product line requires source_id.';
                }
            }

            if ($type === CashierInvoiceLineType::SERVICE) {
                $sid = isset($item['source_id']) ? (int) $item['source_id'] : 0;
                if ($sid > 0 && $this->services->find($sid) === null) {
                    $errors[$prefix . '.source'] = 'Line #' . $lineNo . ': service not found.';
                }
            }
        }

        if ($membershipLines > 1) {
            $errors['items'] = 'At most one membership line is allowed per invoice.';
        }

        if ($membershipLines === 1 && count($items) > 1) {
            $errors['items'] = 'Membership checkout must be the only line on the invoice.';
        }

        if ($clientId !== null && $clientId > 0 && $branchId !== null && $branchId > 0) {
            if ($this->clients->findLiveReadOnBranch($clientId, $branchId) === null) {
                $errors['client_id'] = 'Client is not available on this branch.';
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function lineMonetaryBase(array $item): float
    {
        $qty = (float) ($item['quantity'] ?? 0);
        $unit = (float) ($item['unit_price'] ?? 0);
        $disc = (float) ($item['discount_amount'] ?? 0);

        return round($qty * $unit - $disc, 2);
    }
}
