<?php

use App\Enums\StockLocation;
use App\Models\DripBase;
use App\Models\Injection;
use App\Models\Medicine;
use App\Models\StockBalance;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->migrateCatalogStock(Medicine::class);
        $this->migrateCatalogStock(Injection::class);
        $this->migrateCatalogStock(DripBase::class);

        Schema::table('medicines', function (Blueprint $table) {
            $table->dropColumn('stock_quantity');
        });

        Schema::table('injections', function (Blueprint $table) {
            $table->dropColumn('stock_quantity');
        });

        Schema::table('drip_bases', function (Blueprint $table) {
            $table->dropColumn('stock_quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medicines', function (Blueprint $table) {
            $table->integer('stock_quantity')->default(0)->after('is_active');
        });

        Schema::table('injections', function (Blueprint $table) {
            $table->integer('stock_quantity')->default(0)->after('is_active');
        });

        Schema::table('drip_bases', function (Blueprint $table) {
            $table->integer('stock_quantity')->default(0)->after('is_active');
        });

        foreach ([Medicine::class, Injection::class, DripBase::class] as $modelClass) {
            StockBalance::query()
                ->where('stockable_type', $modelClass)
                ->where('location', StockLocation::BackStorage->value)
                ->each(function (StockBalance $balance) use ($modelClass): void {
                    $modelClass::query()
                        ->whereKey($balance->stockable_id)
                        ->update(['stock_quantity' => $balance->quantity]);
                });
        }

        StockBalance::query()->delete();
    }

    /**
     * @param  class-string<Medicine|Injection|DripBase>  $modelClass
     */
    private function migrateCatalogStock(string $modelClass): void
    {
        $modelClass::query()->each(function ($item) use ($modelClass): void {
            StockBalance::query()->create([
                'stockable_type' => $modelClass,
                'stockable_id' => $item->id,
                'location' => StockLocation::BackStorage->value,
                'quantity' => (int) $item->stock_quantity,
            ]);

            StockBalance::query()->create([
                'stockable_type' => $modelClass,
                'stockable_id' => $item->id,
                'location' => StockLocation::FrontWorking->value,
                'quantity' => 0,
            ]);
        });
    }
};
