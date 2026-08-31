<?php

use App\Enums\StockLocation;
use App\Enums\StockMovementReason;
use App\Models\HealthAide;
use App\Models\Medicine;
use App\Models\StockMovement;
use App\Models\Supply;
use App\Models\User;
use App\Services\InventoryStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('inventory stock service can receive transfer and decrement front stock', function () {
    $stock = app(InventoryStockService::class);
    $medicine = Medicine::factory()->create();
    $aide = HealthAide::factory()->create();
    $user = User::factory()->create();

    $stock->receive($medicine, 20, $aide, $user);

    expect($medicine->fresh()->stockBalance(StockLocation::BackStorage))->toBe(120)
        ->and($medicine->fresh()->stockBalance(StockLocation::FrontWorking))->toBe(0);

    $stock->transfer($medicine, 8, StockMovementReason::ShiftIssue, $aide, $user);

    expect($medicine->fresh()->stockBalance(StockLocation::BackStorage))->toBe(112)
        ->and($medicine->fresh()->stockBalance(StockLocation::FrontWorking))->toBe(8);

    $stock->decrementFront($medicine, 3, StockMovementReason::Delivery);

    expect($medicine->fresh()->stockBalance(StockLocation::FrontWorking))->toBe(5);

    $this->assertDatabaseHas('stock_movements', [
        'stockable_type' => Medicine::class,
        'stockable_id' => $medicine->id,
        'reason' => StockMovementReason::ShiftIssue->value,
        'quantity' => 8,
    ]);
});

test('transfer fails when back storage is insufficient', function () {
    $stock = app(InventoryStockService::class);
    $medicine = Medicine::factory()->create();

    $stock->adjust($medicine, StockLocation::BackStorage, 2);

    expect(fn () => $stock->transfer($medicine, 5, StockMovementReason::Replenish))
        ->toThrow(InvalidArgumentException::class);
});

test('supply consumable use decrements front stock only', function () {
    $stock = app(InventoryStockService::class);
    $supply = Supply::factory()->withFrontStock(10)->create();
    $aide = HealthAide::factory()->create();

    $stock->recordConsumableUse($supply, 4, $aide);

    expect($supply->fresh()->stockBalance(StockLocation::FrontWorking))->toBe(6)
        ->and($supply->fresh()->stockBalance(StockLocation::BackStorage))->toBe(100)
        ->and(StockMovement::query()->where('reason', StockMovementReason::ConsumableUse)->count())->toBe(1);
});

test('adjust writes movement audit for back storage corrections', function () {
    $stock = app(InventoryStockService::class);
    $medicine = Medicine::factory()->create();
    $user = User::factory()->create();

    $stock->adjust($medicine, StockLocation::BackStorage, 50, null, $user);

    expect($medicine->fresh()->stockBalance(StockLocation::BackStorage))->toBe(50)
        ->and(StockMovement::query()
            ->where('reason', StockMovementReason::Adjustment)
            ->where('stockable_id', $medicine->id)
            ->count())->toBe(2);
});
