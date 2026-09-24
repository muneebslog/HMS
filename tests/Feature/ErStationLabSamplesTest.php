<?php

use App\Models\HealthAide;
use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use App\Models\Patient;
use App\Models\Shift;
use App\Models\User;
use App\Services\CeoLabOverview;
use Database\Seeders\RolePagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Shift::factory()->open()->create();
    $this->aide = HealthAide::factory()->create(['pin' => '4321', 'name' => 'Nasreen']);

    $patient = Patient::factory()->create(['name' => 'Er Lab Patient']);
    $this->invoice = LabInvoice::factory()->paid()->create(['patient_id' => $patient->id, 'invoice_number' => '929100']);
    $this->cbc = LabInvoiceItem::factory()->inHouse()->create(['lab_invoice_id' => $this->invoice->id, 'test_name' => 'CBC', 'sample' => 'EDTA 2cc']);
    $this->lft = LabInvoiceItem::factory()->inHouse()->create(['lab_invoice_id' => $this->invoice->id, 'test_name' => 'LFT', 'sample' => 'Clotted 3cc']);
    $this->tsh = LabInvoiceItem::factory()->outgoing()->create(['lab_invoice_id' => $this->invoice->id, 'test_name' => 'TSH']);
});

test('the ER station lists in-house lab samples waiting to be received', function () {
    Livewire::test('pages::display.medication-delivery')
        ->assertSee('Lab samples to receive')
        ->assertSee('ER LAB PATIENT')
        ->assertSee('929100')
        ->assertSee('CBC')
        ->assertSee('EDTA 2cc')
        ->assertSee('LFT')
        ->assertDontSee('TSH');
});

test('an aide enters their PIN and marks one sample received', function () {
    Livewire::test('pages::display.medication-delivery')
        ->call('requestReceiveSamples', null, $this->cbc->id)
        ->assertSet('showPinModal', true)
        ->set('pin', '4321')
        ->call('verifyPin')
        ->assertHasNoErrors();

    expect($this->cbc->fresh())
        ->sample_received_at->not->toBeNull()
        ->sample_received_by->toBeNull()
        ->sample_received_by_health_aide_id->toBe($this->aide->id)
        ->and($this->cbc->fresh()->sampleReceiverName())->toBe('Nasreen')
        ->and($this->lft->fresh()->sample_received_at)->toBeNull();
});

test('a signed-in aide can receive every sample of a patient at once', function () {
    $page = Livewire::test('pages::display.medication-delivery')->set('pin', '4321')->call('verifyPin');

    $page->call('requestReceiveSamples', $this->invoice->id)->assertSet('showPinModal', false);

    expect(LabInvoiceItem::query()->awaitingSample()->count())->toBe(0)
        ->and($this->lft->fresh()->sample_received_by_health_aide_id)->toBe($this->aide->id);

    $page->assertDontSee('Lab samples to receive');
});

test('samples received at the ER show on the lab page and the CEO overview under the aide', function () {
    $this->seed(RolePagePermissionSeeder::class);
    $this->cbc->update(['sample_received_at' => now(), 'sample_received_by_health_aide_id' => $this->aide->id]);

    Livewire::actingAs(User::factory()->labTechnician()->create())
        ->test('pages::lab.samples')
        ->set('tab', 'received')
        ->assertSee('CBC')
        ->assertSee('Nasreen');

    expect(app(CeoLabOverview::class)->people(today()->toImmutable())['lab']->first())
        ->toMatchArray(['name' => 'Nasreen (ER)', 'received' => 1]);
});

test('the lab receiving a sample itself clears any ER receiver', function () {
    $this->seed(RolePagePermissionSeeder::class);
    $technician = User::factory()->labTechnician()->create();

    Livewire::actingAs($technician)->test('pages::lab.samples')->call('receive', null, $this->cbc->id);

    expect($this->cbc->fresh())
        ->sample_received_by->toBe($technician->id)
        ->sample_received_by_health_aide_id->toBeNull();
});
