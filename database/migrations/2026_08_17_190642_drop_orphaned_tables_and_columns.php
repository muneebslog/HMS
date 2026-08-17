<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-shot cleanup for schema left behind after code was rolled back
 * past migrations that created these objects. Safe to delete this file
 * (and its `migrations` row) after it has been run on every environment.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $orphanTables = [
        'medication_order_drip_checks',
        'market_demand_lines',
        'market_demands',
        'refill_request_lines',
        'refill_requests',
        'inventory_count_lines',
        'inventory_counts',
        'stock_movements',
        'stock_lots',
        'stock_balances',
        'location_item_settings',
        'stock_items',
        'stock_locations',
    ];

    /**
     * @var list<string>
     */
    private array $orphanMigrationNames = [
        '2026_08_16_193032_create_medication_order_drip_checks_table',
        '2026_08_16_193033_add_post_drip_review_to_medication_orders_table',
        '2026_08_16_204835_create_stock_locations_table',
        '2026_08_16_204836_create_stock_items_table',
        '2026_08_16_204837_create_location_item_settings_table',
        '2026_08_16_204838_create_stock_balances_table',
        '2026_08_16_204839_create_stock_lots_table',
        '2026_08_16_204841_create_stock_movements_table',
        '2026_08_16_204842_create_inventory_counts_table',
        '2026_08_16_204843_create_inventory_count_lines_table',
        '2026_08_16_204844_create_refill_requests_table',
        '2026_08_16_204845_create_refill_request_lines_table',
        '2026_08_16_204846_create_market_demands_table',
        '2026_08_16_204847_create_market_demand_lines_table',
    ];

    /**
     * @var list<string>
     */
    private array $postDripColumns = [
        'post_drip_review_requested_at',
        'post_drip_review_requested_by_health_aide_id',
        'post_drip_reviewed_at',
        'post_drip_reviewed_by',
    ];

    /**
     * @var list<string>
     */
    private array $postDripForeignKeys = [
        'mo_post_drip_req_aide_fk',
        'mo_post_drip_reviewed_by_fk',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->orphanTables as $table) {
            Schema::dropIfExists($table);
        }

        $this->dropPostDripReviewColumns();

        DB::table('migrations')
            ->whereIn('migration', $this->orphanMigrationNames)
            ->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally empty: this is a one-shot cleanup of rolled-back schema.
    }

    private function dropPostDripReviewColumns(): void
    {
        if (! Schema::hasTable('medication_orders')) {
            return;
        }

        $columnsToDrop = array_values(array_filter(
            $this->postDripColumns,
            fn (string $column): bool => Schema::hasColumn('medication_orders', $column)
        ));

        if ($columnsToDrop === []) {
            return;
        }

        $existingForeignKeys = collect(Schema::getForeignKeys('medication_orders'))
            ->pluck('name')
            ->all();

        Schema::table('medication_orders', function (Blueprint $table) use ($columnsToDrop, $existingForeignKeys): void {
            foreach ($this->postDripForeignKeys as $foreignKey) {
                if (in_array($foreignKey, $existingForeignKeys, true)) {
                    $table->dropForeign($foreignKey);
                }
            }

            $table->dropColumn($columnsToDrop);
        });
    }
};
