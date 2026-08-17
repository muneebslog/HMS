<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('orphaned cleanup migration drops leftover tables columns and migration rows', function () {
    Schema::create('stock_locations', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('stock_items', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('location_item_settings', function (Blueprint $table) {
        $table->id();
        $table->foreignId('stock_item_id')->constrained()->cascadeOnDelete();
        $table->foreignId('stock_location_id')->constrained()->cascadeOnDelete();
        $table->timestamps();
    });

    Schema::create('stock_balances', function (Blueprint $table) {
        $table->id();
        $table->foreignId('stock_item_id')->constrained()->cascadeOnDelete();
        $table->foreignId('stock_location_id')->constrained()->cascadeOnDelete();
        $table->timestamps();
    });

    Schema::create('stock_lots', function (Blueprint $table) {
        $table->id();
        $table->foreignId('stock_item_id')->constrained()->cascadeOnDelete();
        $table->foreignId('stock_location_id')->constrained()->cascadeOnDelete();
        $table->timestamps();
    });

    Schema::create('stock_movements', function (Blueprint $table) {
        $table->id();
        $table->foreignId('stock_item_id')->constrained()->cascadeOnDelete();
        $table->timestamps();
    });

    Schema::create('inventory_counts', function (Blueprint $table) {
        $table->id();
        $table->foreignId('stock_location_id')->constrained()->cascadeOnDelete();
        $table->timestamps();
    });

    Schema::create('inventory_count_lines', function (Blueprint $table) {
        $table->id();
        $table->foreignId('inventory_count_id')->constrained()->cascadeOnDelete();
        $table->timestamps();
    });

    Schema::create('refill_requests', function (Blueprint $table) {
        $table->id();
        $table->foreignId('stock_location_id')->constrained()->cascadeOnDelete();
        $table->timestamps();
    });

    Schema::create('refill_request_lines', function (Blueprint $table) {
        $table->id();
        $table->foreignId('refill_request_id')->constrained()->cascadeOnDelete();
        $table->timestamps();
    });

    Schema::create('market_demands', function (Blueprint $table) {
        $table->id();
        $table->timestamps();
    });

    Schema::create('market_demand_lines', function (Blueprint $table) {
        $table->id();
        $table->foreignId('market_demand_id')->constrained()->cascadeOnDelete();
        $table->timestamps();
    });

    Schema::create('medication_order_drip_checks', function (Blueprint $table) {
        $table->id();
        $table->string('outcome');
        $table->timestamps();
    });

    Schema::table('medication_orders', function (Blueprint $table) {
        $table->timestamp('post_drip_review_requested_at')->nullable();
        $table->unsignedBigInteger('post_drip_review_requested_by_health_aide_id')->nullable();
        $table->timestamp('post_drip_reviewed_at')->nullable();
        $table->unsignedBigInteger('post_drip_reviewed_by')->nullable();
    });

    $orphanMigration = '2026_08_16_193032_create_medication_order_drip_checks_table';
    DB::table('migrations')->insert([
        'migration' => $orphanMigration,
        'batch' => 999,
    ]);

    $tempMigration = sys_get_temp_dir().DIRECTORY_SEPARATOR.'drop_orphaned_'.uniqid('', true).'.php';
    copy(
        database_path('migrations/2026_08_17_190642_drop_orphaned_tables_and_columns.php'),
        $tempMigration
    );

    /** @var Migration $migration */
    $migration = require $tempMigration;
    unlink($tempMigration);
    $migration->up();

    foreach ([
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
    ] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }

    expect(Schema::hasColumn('medication_orders', 'post_drip_review_requested_at'))->toBeFalse()
        ->and(Schema::hasColumn('medication_orders', 'post_drip_review_requested_by_health_aide_id'))->toBeFalse()
        ->and(Schema::hasColumn('medication_orders', 'post_drip_reviewed_at'))->toBeFalse()
        ->and(Schema::hasColumn('medication_orders', 'post_drip_reviewed_by'))->toBeFalse()
        ->and(DB::table('migrations')->where('migration', $orphanMigration)->exists())->toBeFalse();
});
