<?php

namespace App\Actions;

use App\Models\StockMovement;
use App\Models\User;
use LogicException;

class BulkInventoryAccounting
{
    public function __construct(private readonly PostFinancialJournal $journal) {}

    public static function costForQuantity(int $inventoryValueMinor, int $onHand, int $quantity): int
    {
        if ($quantity < 0 || $onHand < $quantity || $inventoryValueMinor < 0) {
            throw new LogicException('Bulk inventory valuation is inconsistent with the stock quantity.');
        }

        if ($quantity === 0 || $onHand === 0) {
            return 0;
        }

        return $quantity === $onHand ? $inventoryValueMinor : intdiv($inventoryValueMinor * $quantity, $onHand);
    }

    public static function costForSlice(int $totalCostMinor, int $totalQuantity, int $offset, int $quantity): int
    {
        if ($totalCostMinor < 0 || $totalQuantity < 1 || $offset < 0 || $quantity < 0 || $offset + $quantity > $totalQuantity) {
            throw new LogicException('Bulk sale cost allocation is inconsistent with its original quantity.');
        }

        return intdiv($totalCostMinor * ($offset + $quantity), $totalQuantity) - intdiv($totalCostMinor * $offset, $totalQuantity);
    }

    public function postMovement(StockMovement $movement, int $costMinor, User $actor): void
    {
        if ($costMinor === 0) {
            return;
        }

        $common = fn (string $account, int $debit, int $credit, ?int $location = null): array => [
            'account' => $account, 'debit_minor' => $debit, 'credit_minor' => $credit,
            'location_id' => $location ?? $movement->to_location_id ?? $movement->from_location_id,
        ];
        $lines = match ($movement->movement_type) {
            'bulk_opening_stock' => [$common('1200', $costMinor, 0), $common('3000', 0, $costMinor)],
            'bulk_received' => [$common('1200', $costMinor, 0), $common('2100', 0, $costMinor)],
            'bulk_adjustment_in', 'bulk_cycle_count_in' => [$common('1200', $costMinor, 0), $common('4900', 0, $costMinor)],
            'bulk_adjustment_out', 'bulk_cycle_count_out' => [$common('6200', $costMinor, 0), $common('1200', 0, $costMinor)],
            'bulk_revaluation' => $movement->from_status === 'increase'
                ? [$common('1200', $costMinor, 0), $common('3000', 0, $costMinor)]
                : [$common('6200', $costMinor, 0), $common('1200', 0, $costMinor)],
            'bulk_transfer' => [
                $common('1200', $costMinor, 0, $movement->to_location_id),
                $common('1200', 0, $costMinor, $movement->from_location_id),
            ],
            'bulk_sale' => [$common('5000', $costMinor, 0), $common('1200', 0, $costMinor)],
            'bulk_sale_return_pending' => [$common('1200', $costMinor, 0), $common('5000', 0, $costMinor)],
            default => throw new LogicException('This bulk stock movement has no inventory valuation posting.'),
        };

        $this->journal->handle(
            StockMovement::class,
            $movement->id,
            'inventory_valuation',
            $movement->idempotency_key ?? 'STOCK-MOVEMENT-'.$movement->id,
            'Bulk inventory movement: '.str_replace('_', ' ', $movement->movement_type),
            null,
            $actor->id,
            $movement->occurred_at?->toDateString() ?? now()->toDateString(),
            $lines,
        );
    }
}
