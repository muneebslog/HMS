<?php

use App\Enums\StationType;
use App\Enums\StockLocation;
use App\Models\DripBase;
use App\Models\HealthAide;
use App\Models\Injection;
use App\Models\Medicine;
use App\Models\StationSession;
use App\Models\Supply;
use App\Services\InventoryStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('catalog stock page is publicly accessible and requires pin', function () {
    $this->get(route('display.stock'))
        ->assertSuccessful()
        ->assertSee(__('Enter PIN'));

    Livewire::test('pages::display.catalog-stock')
        ->assertSet('showPinModal', true)
        ->assertSee(__('Catalog Stock'));
});

test('health aide can unlock stock page receive issue and replenish stock', function () {
    $aide = HealthAide::factory()->create(['pin' => '1234', 'name' => 'Stock Aide']);
    $medicine = Medicine::factory()->create(['name' => 'Paracetamol']);
    $injection = Injection::factory()->create(['name' => 'Diclofenac']);
    $dripBase = DripBase::factory()->create(['name' => 'Normal Saline']);
    $supply = Supply::factory()->create(['name' => 'Cotton']);
    app(InventoryStockService::class)->adjust($supply, StockLocation::FrontWorking, 100);

    app(InventoryStockService::class)->adjust($medicine, StockLocation::BackStorage, 10);
    app(InventoryStockService::class)->adjust($injection, StockLocation::BackStorage, 5);
    app(InventoryStockService::class)->adjust($dripBase, StockLocation::BackStorage, 3);

    $component = Livewire::test('pages::display.catalog-stock')
        ->set('pin', '1234')
        ->call('verifyPin')
        ->assertHasNoErrors()
        ->assertSet('showPinModal', false)
        ->assertSee('Stock Aide')
        ->assertSee('Paracetamol');

    expect(StationSession::query()->where('station', StationType::Stock)->first()?->health_aide_id)->toBe($aide->id);

    $component
        ->set('activeMode', 'back')
        ->set("quantities.medicine.{$medicine->id}", '15')
        ->call('adjustBackStock', 'medicine', $medicine->id)
        ->assertHasNoErrors()
        ->set('activeMode', 'issue')
        ->set("quantities.medicine.{$medicine->id}", '6')
        ->call('issueToFront', 'medicine', $medicine->id)
        ->assertHasNoErrors()
        ->set('activeTab', 'injections')
        ->set('activeMode', 'replenish')
        ->set("quantities.injection.{$injection->id}", '2')
        ->call('replenishFront', 'injection', $injection->id)
        ->assertHasNoErrors()
        ->set('activeTab', 'dripBases')
        ->set('activeMode', 'back')
        ->set("quantities.dripBase.{$dripBase->id}", '5')
        ->call('receiveStock', 'dripBase', $dripBase->id)
        ->assertHasNoErrors()
        ->set('activeTab', 'supplies')
        ->set('activeMode', 'front')
        ->set("quantities.supply.{$supply->id}", '2')
        ->call('useFromFront', 'supply', $supply->id)
        ->assertHasNoErrors();

    expect($medicine->fresh()->stockBalance(StockLocation::BackStorage))->toBe(9)
        ->and($medicine->fresh()->stockBalance(StockLocation::FrontWorking))->toBe(6)
        ->and($injection->fresh()->stockBalance(StockLocation::FrontWorking))->toBe(2)
        ->and($dripBase->fresh()->stockBalance(StockLocation::BackStorage))->toBe(8)
        ->and($supply->fresh()->stockBalance(StockLocation::FrontWorking))->toBe(98);
});

test('invalid pin is rejected on stock page', function () {
    HealthAide::factory()->create(['pin' => '1234']);

    Livewire::test('pages::display.catalog-stock')
        ->set('pin', '9999')
        ->call('verifyPin')
        ->assertHasErrors(['pin'])
        ->assertSet('showPinModal', true);
});

test('saving stock without pin session prompts for unlock', function () {
    $medicine = Medicine::factory()->create();

    app(InventoryStockService::class)->adjust($medicine, StockLocation::BackStorage, 10);

    Livewire::test('pages::display.catalog-stock')
        ->set('activeMode', 'back')
        ->set("quantities.medicine.{$medicine->id}", '20')
        ->call('adjustBackStock', 'medicine', $medicine->id)
        ->assertSet('showPinModal', true);

    expect($medicine->fresh()->stockBalance(StockLocation::BackStorage))->toBe(10);
});
