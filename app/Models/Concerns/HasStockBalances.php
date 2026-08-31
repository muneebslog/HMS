<?php

namespace App\Models\Concerns;

use App\Enums\StockLocation;
use App\Models\StockBalance;
use App\Services\InventoryStockService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasStockBalances
{
    protected static function bootHasStockBalances(): void
    {
        static::created(function (Model $model): void {
            app(InventoryStockService::class)->initializeBalances($model);
        });
    }

    /**
     * @return MorphMany<StockBalance, $this>
     */
    public function stockBalances(): MorphMany
    {
        return $this->morphMany(StockBalance::class, 'stockable');
    }

    public function stockBalance(StockLocation $location): int
    {
        $balance = $this->stockBalances()
            ->where('location', $location->value)
            ->value('quantity');

        return (int) ($balance ?? 0);
    }

    public function totalStock(): int
    {
        return $this->stockBalance(StockLocation::BackStorage)
            + $this->stockBalance(StockLocation::FrontWorking);
    }
}
