<?php

namespace App\Services;

use App\Enums\StockLocation;
use App\Enums\StockMovementReason;
use App\Models\DripBase;
use App\Models\HealthAide;
use App\Models\Injection;
use App\Models\Medicine;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Supply;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InventoryStockService
{
    /**
     * Decrement front working stock for a catalog medicine. No-op when id is null.
     */
    public function decrementMedicine(?int $medicineId, int $by = 1, ?Model $reference = null): void
    {
        if ($medicineId === null || $by === 0) {
            return;
        }

        $medicine = Medicine::query()->find($medicineId);

        if ($medicine === null) {
            return;
        }

        $this->decrementFront($medicine, $by, StockMovementReason::Delivery, $reference);
    }

    /**
     * Decrement front working stock for a catalog injection. No-op when id is null.
     */
    public function decrementInjection(?int $injectionId, int $by = 1, ?Model $reference = null): void
    {
        if ($injectionId === null || $by === 0) {
            return;
        }

        $injection = Injection::query()->find($injectionId);

        if ($injection === null) {
            return;
        }

        $this->decrementFront($injection, $by, StockMovementReason::Delivery, $reference);
    }

    /**
     * Decrement front working stock for a catalog drip base. No-op when id is null.
     */
    public function decrementDripBase(?int $dripBaseId, int $by = 1, ?Model $reference = null): void
    {
        if ($dripBaseId === null || $by === 0) {
            return;
        }

        $dripBase = DripBase::query()->find($dripBaseId);

        if ($dripBase === null) {
            return;
        }

        $this->decrementFront($dripBase, $by, StockMovementReason::Delivery, $reference);
    }

    public function balance(Model $stockable, StockLocation $location, bool $lock = false): int
    {
        $query = StockBalance::query()
            ->where('stockable_type', $stockable::class)
            ->where('stockable_id', $stockable->getKey())
            ->where('location', $location->value);

        if ($lock) {
            $query->lockForUpdate();
        }

        return (int) ($query->value('quantity') ?? 0);
    }

    /**
     * Ensure back and front balance rows exist for a stockable item.
     */
    public function initializeBalances(Model $stockable, int $backQty = 0): void
    {
        $this->runInTransaction(function () use ($stockable, $backQty): void {
            $this->ensureBalanceRow($stockable, StockLocation::BackStorage, $backQty);
            $this->ensureBalanceRow($stockable, StockLocation::FrontWorking, 0);
        });
    }

    /**
     * Add stock to back storage.
     */
    public function receive(
        Model $stockable,
        int $quantity,
        ?HealthAide $healthAide = null,
        ?User $user = null,
        ?string $notes = null,
    ): void {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Receive quantity must be positive.');
        }

        $this->runInTransaction(function () use ($stockable, $quantity, $healthAide, $user, $notes): void {
            $this->changeBalance($stockable, StockLocation::BackStorage, $quantity);

            $this->recordMovement(
                stockable: $stockable,
                fromLocation: null,
                toLocation: StockLocation::BackStorage,
                quantity: $quantity,
                reason: StockMovementReason::Receive,
                healthAide: $healthAide,
                user: $user,
                notes: $notes,
            );
        });
    }

    /**
     * Transfer stock from back storage to front working stock.
     */
    public function transfer(
        Model $stockable,
        int $quantity,
        StockMovementReason $reason,
        ?HealthAide $healthAide = null,
        ?User $user = null,
        ?string $notes = null,
    ): void {
        if (! in_array($reason, [StockMovementReason::ShiftIssue, StockMovementReason::Replenish], true)) {
            throw new InvalidArgumentException('Transfer reason must be shift issue or replenish.');
        }

        if ($quantity <= 0) {
            throw new InvalidArgumentException('Transfer quantity must be positive.');
        }

        $this->runInTransaction(function () use ($stockable, $quantity, $reason, $healthAide, $user, $notes): void {
            $backBalance = $this->lockedBalance($stockable, StockLocation::BackStorage);

            if ($backBalance->quantity < $quantity) {
                throw new InvalidArgumentException(__('Insufficient back storage stock.'));
            }

            $backBalance->update(['quantity' => $backBalance->quantity - $quantity]);

            $frontBalance = $this->lockedBalance($stockable, StockLocation::FrontWorking);
            $frontBalance->update(['quantity' => $frontBalance->quantity + $quantity]);

            $this->recordMovement(
                stockable: $stockable,
                fromLocation: StockLocation::BackStorage,
                toLocation: StockLocation::FrontWorking,
                quantity: $quantity,
                reason: $reason,
                healthAide: $healthAide,
                user: $user,
                notes: $notes,
            );
        });
    }

    /**
     * Decrement front working stock.
     */
    public function decrementFront(
        Model $stockable,
        int $quantity,
        StockMovementReason $reason,
        ?Model $reference = null,
        ?HealthAide $healthAide = null,
        ?User $user = null,
    ): void {
        if ($quantity <= 0) {
            return;
        }

        $this->runInTransaction(function () use ($stockable, $quantity, $reason, $reference, $healthAide, $user): void {
            $frontBalance = $this->lockedBalance($stockable, StockLocation::FrontWorking);
            $frontBalance->update(['quantity' => $frontBalance->quantity - $quantity]);

            $this->recordMovement(
                stockable: $stockable,
                fromLocation: StockLocation::FrontWorking,
                toLocation: null,
                quantity: $quantity,
                reason: $reason,
                healthAide: $healthAide,
                user: $user,
                reference: $reference,
            );
        });
    }

    /**
     * Record manual consumable use from front working stock.
     */
    public function recordConsumableUse(
        Supply $supply,
        int $quantity,
        ?HealthAide $healthAide = null,
        ?User $user = null,
        ?string $notes = null,
    ): void {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Use quantity must be positive.');
        }

        $this->decrementFront($supply, $quantity, StockMovementReason::ConsumableUse, null, $healthAide, $user);

        if ($notes !== null) {
            StockMovement::query()
                ->where('stockable_type', Supply::class)
                ->where('stockable_id', $supply->getKey())
                ->latest('id')
                ->limit(1)
                ->update(['notes' => $notes]);
        }
    }

    /**
     * Set an absolute quantity at a location and log the adjustment delta.
     */
    public function adjust(
        Model $stockable,
        StockLocation $location,
        int $newQuantity,
        ?HealthAide $healthAide = null,
        ?User $user = null,
        ?string $notes = null,
    ): void {
        if ($newQuantity < 0) {
            throw new InvalidArgumentException('Stock quantity cannot be negative.');
        }

        $this->runInTransaction(function () use ($stockable, $location, $newQuantity, $healthAide, $user, $notes): void {
            $balance = $this->lockedBalance($stockable, $location);
            $delta = $newQuantity - $balance->quantity;

            if ($delta === 0) {
                return;
            }

            $balance->update(['quantity' => $newQuantity]);

            $this->recordMovement(
                stockable: $stockable,
                fromLocation: $delta < 0 ? $location : null,
                toLocation: $delta > 0 ? $location : null,
                quantity: abs($delta),
                reason: StockMovementReason::Adjustment,
                healthAide: $healthAide,
                user: $user,
                notes: $notes,
            );
        });
    }

    private function ensureBalanceRow(Model $stockable, StockLocation $location, int $quantity): StockBalance
    {
        return StockBalance::query()->firstOrCreate(
            [
                'stockable_type' => $stockable::class,
                'stockable_id' => $stockable->getKey(),
                'location' => $location->value,
            ],
            [
                'quantity' => $quantity,
            ]
        );
    }

    private function lockedBalance(Model $stockable, StockLocation $location): StockBalance
    {
        $balance = StockBalance::query()
            ->where('stockable_type', $stockable::class)
            ->where('stockable_id', $stockable->getKey())
            ->where('location', $location->value)
            ->lockForUpdate()
            ->first();

        if ($balance !== null) {
            return $balance;
        }

        return StockBalance::query()->create([
            'stockable_type' => $stockable::class,
            'stockable_id' => $stockable->getKey(),
            'location' => $location->value,
            'quantity' => 0,
        ]);
    }

    private function changeBalance(Model $stockable, StockLocation $location, int $delta): void
    {
        $balance = $this->lockedBalance($stockable, $location);
        $balance->update(['quantity' => $balance->quantity + $delta]);
    }

    private function recordMovement(
        Model $stockable,
        ?StockLocation $fromLocation,
        ?StockLocation $toLocation,
        int $quantity,
        StockMovementReason $reason,
        ?HealthAide $healthAide = null,
        ?User $user = null,
        ?Model $reference = null,
        ?string $notes = null,
    ): void {
        StockMovement::query()->create([
            'stockable_type' => $stockable::class,
            'stockable_id' => $stockable->getKey(),
            'from_location' => $fromLocation?->value,
            'to_location' => $toLocation?->value,
            'quantity' => $quantity,
            'reason' => $reason,
            'health_aide_id' => $healthAide?->id,
            'user_id' => $user?->id ?? auth()->id(),
            'reference_type' => $reference !== null ? $reference::class : null,
            'reference_id' => $reference?->getKey(),
            'notes' => $notes,
            'created_at' => now(),
        ]);
    }

    private function runInTransaction(callable $callback): void
    {
        if (DB::transactionLevel() > 0) {
            $callback();

            return;
        }

        DB::transaction($callback);
    }
}
