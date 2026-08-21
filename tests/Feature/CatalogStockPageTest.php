<?php

use App\Enums\StationType;
use App\Models\DripBase;
use App\Models\HealthAide;
use App\Models\Injection;
use App\Models\Medicine;
use App\Models\StationSession;
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

test('health aide can unlock stock page and update medicine injection and drip stock', function () {
    $aide = HealthAide::factory()->create(['pin' => '1234', 'name' => 'Stock Aide']);
    $medicine = Medicine::factory()->create(['name' => 'Paracetamol', 'stock_quantity' => 10]);
    $injection = Injection::factory()->create(['name' => 'Diclofenac', 'stock_quantity' => 5]);
    $dripBase = DripBase::factory()->create(['name' => 'Normal Saline', 'stock_quantity' => 3]);

    $component = Livewire::test('pages::display.catalog-stock')
        ->set('pin', '1234')
        ->call('verifyPin')
        ->assertHasNoErrors()
        ->assertSet('showPinModal', false)
        ->assertSee('Stock Aide')
        ->assertSee('Paracetamol');

    expect(StationSession::query()->where('station', StationType::Stock)->first()?->health_aide_id)->toBe($aide->id);

    $component
        ->set("medicineStocks.{$medicine->id}", '42')
        ->call('saveMedicineStock', $medicine->id)
        ->assertHasNoErrors()
        ->set('activeTab', 'injections')
        ->set("injectionStocks.{$injection->id}", '18')
        ->call('saveInjectionStock', $injection->id)
        ->assertHasNoErrors()
        ->set('activeTab', 'dripBases')
        ->set("dripBaseStocks.{$dripBase->id}", '9')
        ->call('saveDripBaseStock', $dripBase->id)
        ->assertHasNoErrors();

    expect($medicine->fresh()->stock_quantity)->toBe(42)
        ->and($injection->fresh()->stock_quantity)->toBe(18)
        ->and($dripBase->fresh()->stock_quantity)->toBe(9);
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
    $medicine = Medicine::factory()->create(['stock_quantity' => 10]);

    Livewire::test('pages::display.catalog-stock')
        ->set("medicineStocks.{$medicine->id}", '20')
        ->call('saveMedicineStock', $medicine->id)
        ->assertSet('showPinModal', true);

    expect($medicine->fresh()->stock_quantity)->toBe(10);
});
