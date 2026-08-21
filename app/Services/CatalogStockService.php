<?php

namespace App\Services;

use App\Models\DripBase;
use App\Models\Injection;
use App\Models\Medicine;
use Illuminate\Support\Facades\DB;

class CatalogStockService
{
    /**
     * Decrement catalog medicine stock. No-op when id is null.
     */
    public function decrementMedicine(?int $medicineId, int $by = 1): void
    {
        if ($medicineId === null || $by === 0) {
            return;
        }

        $this->decrement(Medicine::class, $medicineId, $by);
    }

    /**
     * Decrement catalog injection stock. No-op when id is null.
     */
    public function decrementInjection(?int $injectionId, int $by = 1): void
    {
        if ($injectionId === null || $by === 0) {
            return;
        }

        $this->decrement(Injection::class, $injectionId, $by);
    }

    /**
     * Decrement catalog drip base stock. No-op when id is null.
     */
    public function decrementDripBase(?int $dripBaseId, int $by = 1): void
    {
        if ($dripBaseId === null || $by === 0) {
            return;
        }

        $this->decrement(DripBase::class, $dripBaseId, $by);
    }

    /**
     * @param  class-string<Medicine|Injection|DripBase>  $modelClass
     */
    private function decrement(string $modelClass, int $id, int $by): void
    {
        $callback = function () use ($modelClass, $id, $by): void {
            /** @var Medicine|Injection|DripBase|null $item */
            $item = $modelClass::query()->whereKey($id)->lockForUpdate()->first();

            if ($item === null) {
                return;
            }

            $item->update([
                'stock_quantity' => $item->stock_quantity - $by,
            ]);
        };

        if (DB::transactionLevel() > 0) {
            $callback();

            return;
        }

        DB::transaction($callback);
    }
}
