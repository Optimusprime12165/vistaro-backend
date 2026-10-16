<?php

namespace App\Services\Inventory;

use App\Enums\InventoryLocation;
use App\Enums\InventoryMovementType;
use App\Exceptions\ApiException;
use App\Exceptions\InsufficientStockException;
use App\Models\Admin;
use App\Models\InventoryMovement;
use App\Models\ProductSize;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY code allowed to change stock. Every change
 *  - runs in a transaction,
 *  - is applied by an atomic, conditional UPDATE (`... WHERE quantity >= n`) or under a row lock, so two buyers can
 *    never both take the last unit and quantity can never go negative (on MySQL the UNSIGNED columns are a 2nd guard),
 *  - appends one inventory_movements row.
 *
 * SHARED STOCK: there is ONE counter per (product, size): product_sizes.quantity (+ reserved for placed online orders).
 * Online orders (reserve / release / consume / return) and manual Offline shop adjustments change that same row, so both
 * channels always see the same remaining quantity. `location` on a movement only records WHERE the change came from
 * (online order flow vs. manual shop adjustment); it never selects a different stock.
 * Quantities supplied by clients are never trusted: callers pass validated numbers, locked rows decide the result.
 */
final class InventoryService
{
    private const RETRIES = 3;

    /** Admin "set stock to N" (absolute). The delta is computed from the LOCKED row, so concurrent edits cannot corrupt it. */
    public function setQuantity(int $productSizeId, int $quantity, ?Model $actor = null, ?string $note = null, InventoryMovementType $type = InventoryMovementType::Adjustment, InventoryLocation $source = InventoryLocation::Online): ProductSize
    {
        $this->assertQuantity($quantity);

        return DB::transaction(function () use ($productSizeId, $quantity, $actor, $note, $type, $source) {
            $row = ProductSize::query()->lockForUpdate()->findOrFail($productSizeId);
            $delta = $quantity - $row->quantity;

            if ($delta !== 0) {
                DB::table('product_sizes')->where('id', $row->id)->update(['quantity' => $quantity, 'updated_at' => now()]);
                $this->record($row->id, $row->product_id, $row->size, $source, $type, $delta, 0, $quantity, null, null, $actor, $note);
            }

            return $row->refresh();
        }, self::RETRIES);
    }

    /**
     * Relative change: manual shop sale (negative), correction, restock (positive). A negative delta that would go
     * below zero is refused (409) against the LATEST quantity, which also includes everything sold online meanwhile.
     */
    public function adjustQuantity(int $productSizeId, int $delta, InventoryMovementType $type = InventoryMovementType::Adjustment, ?Model $actor = null, ?string $note = null, ?string $refType = null, ?int $refId = null, InventoryLocation $source = InventoryLocation::Online): ProductSize
    {
        return DB::transaction(function () use ($productSizeId, $delta, $type, $actor, $note, $refType, $refId, $source) {
            $this->apply($productSizeId, $delta, 0, $type, $actor, $note, $refType, $refId, $source);

            return ProductSize::query()->findOrFail($productSizeId);
        }, self::RETRIES);
    }

    /** Order placed: available -> reserved. Fails (409) instead of overselling. */
    public function reserve(int $productSizeId, int $quantity, string $refType, int $refId, ?Model $actor = null): void
    {
        $this->assertPositive($quantity);
        DB::transaction(fn () => $this->apply($productSizeId, -$quantity, +$quantity, InventoryMovementType::OrderReserved, $actor, null, $refType, $refId), self::RETRIES);
    }

    /** Order cancelled before dispatch: reserved -> available. */
    public function release(int $productSizeId, int $quantity, string $refType, int $refId, ?Model $actor = null, ?string $note = null): void
    {
        $this->assertPositive($quantity);
        DB::transaction(fn () => $this->apply($productSizeId, +$quantity, -$quantity, InventoryMovementType::OrderReleased, $actor, $note, $refType, $refId), self::RETRIES);
    }

    /** Order shipped: the reservation becomes a sale (reserved -= n, sellable quantity unchanged). */
    public function consumeReserved(int $productSizeId, int $quantity, string $refType, int $refId, ?Model $actor = null): void
    {
        $this->assertPositive($quantity);
        DB::transaction(fn () => $this->apply($productSizeId, 0, -$quantity, InventoryMovementType::OrderFulfilled, $actor, null, $refType, $refId), self::RETRIES);
    }

    /** A returned item is put back on sale (admin chose to restock it). */
    public function returnToStock(int $productSizeId, int $quantity, string $refType, int $refId, ?Model $actor = null, ?string $note = null): void
    {
        $this->assertPositive($quantity);
        DB::transaction(fn () => $this->apply($productSizeId, +$quantity, 0, InventoryMovementType::OrderReturned, $actor, $note, $refType, $refId), self::RETRIES);
    }

    /**
     * quantity += $qtyDelta and reserved += $reservedDelta, atomically, never leaving either below zero.
     * The WHERE clause carries the guard, so it is evaluated against the row as it is NOW, under the row lock the UPDATE takes.
     */
    private function apply(int $id, int $qtyDelta, int $reservedDelta, InventoryMovementType $type, ?Model $actor, ?string $note, ?string $refType, ?int $refId, InventoryLocation $source = InventoryLocation::Online): void
    {
        $q = DB::table('product_sizes')->where('id', $id);

        if ($qtyDelta < 0) {
            $q->where('quantity', '>=', -$qtyDelta);
        }
        if ($reservedDelta < 0) {
            $q->where('reserved', '>=', -$reservedDelta);
        }

        $affected = $q->update([
            'quantity' => DB::raw('quantity ' . ($qtyDelta < 0 ? '- ' . (-$qtyDelta) : '+ ' . $qtyDelta)),
            'reserved' => DB::raw('reserved ' . ($reservedDelta < 0 ? '- ' . (-$reservedDelta) : '+ ' . $reservedDelta)),
            'updated_at' => now(),
        ]);

        $row = DB::table('product_sizes')->where('id', $id)->first();
        if ($row === null) {
            throw new ApiException('Stock record not found.', 404);
        }
        if ($affected === 0) {
            throw new InsufficientStockException($qtyDelta < 0 ? 'Insufficient stock.' : 'Reserved quantity does not match.', [
                'size' => [$qtyDelta < 0 ? "Only {$row->quantity} left in size {$row->size}." : 'Reservation mismatch.'],
            ]);
        }

        $this->record($id, (int) $row->product_id, (string) $row->size, $source, $type, $qtyDelta, $reservedDelta, (int) $row->quantity, $refType, $refId, $actor, $note);
    }

    private function record(?int $productSizeId, int $productId, string $size, InventoryLocation $source, InventoryMovementType $type, int $qtyChange, int $reservedChange, int $after, ?string $refType, ?int $refId, ?Model $actor, ?string $note): void
    {
        InventoryMovement::query()->create([
            'product_size_id' => $productSizeId, 'product_id' => $productId, 'size' => $size, 'location' => $source->value,
            'type' => $type->value, 'quantity_change' => $qtyChange, 'reserved_change' => $reservedChange, 'quantity_after' => $after,
            'reference_type' => $refType, 'reference_id' => $refId,
            'actor_type' => match (true) { $actor instanceof Admin => 'admin', $actor instanceof User => 'user', default => null },
            'actor_id' => $actor?->getKey(), 'note' => $note !== null ? mb_substr($note, 0, 255) : null,
        ]);
    }

    private function assertQuantity(int $q): void
    {
        if ($q < 0 || $q > 1_000_000) {
            throw new \InvalidArgumentException('Stock quantity must be between 0 and 1,000,000.');
        }
    }

    private function assertPositive(int $q): void
    {
        if ($q < 1) {
            throw new \InvalidArgumentException('Quantity must be positive.');
        }
    }
}
