<?php

use App\Enums\StockLocation;
use App\Models\Supply;
use App\Models\User;
use Database\Seeders\SupplySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('supply seeder creates crash cart and common consumables', function () {
    $this->seed(SupplySeeder::class);

    expect(Supply::query()->where('name', 'Cotton')->exists())->toBeTrue()
        ->and(Supply::query()->where('name', 'IV Cannula 18G')->exists())->toBeTrue()
        ->and(Supply::query()->count())->toBeGreaterThan(20);
});

test('admin can create and update supplies with back stock', function () {
    $user = User::factory()->admin()->create();

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'supplies')
        ->call('create')
        ->set('supplyName', 'Gauze Pads')
        ->set('supplyCategory', 'consumables')
        ->set('supplyUnit', 'pack')
        ->set('supplyDefaultPar', '10')
        ->set('supplyBackStockQuantity', '25')
        ->call('save')
        ->assertHasNoErrors();

    $supply = Supply::query()->where('name', 'Gauze Pads')->firstOrFail();

    expect($supply->stockBalance(StockLocation::BackStorage))->toBe(25)
        ->and($supply->stockBalance(StockLocation::FrontWorking))->toBe(0);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'supplies')
        ->call('edit', $supply->id)
        ->set('supplyBackStockQuantity', '40')
        ->call('save')
        ->assertHasNoErrors();

    expect($supply->fresh()->stockBalance(StockLocation::BackStorage))->toBe(40);
});

test('supplies tab lists back front and total stock columns', function () {
    $user = User::factory()->admin()->create();
    $supply = Supply::factory()->withFrontStock(5)->create(['name' => 'Alcohol Swabs']);

    Livewire::actingAs($user)
        ->test('pages::management.crud')
        ->set('activeTab', 'supplies')
        ->assertSee('Alcohol Swabs')
        ->assertSee((string) $supply->stockBalance(StockLocation::BackStorage))
        ->assertSee('5')
        ->assertSee((string) $supply->totalStock());
});
