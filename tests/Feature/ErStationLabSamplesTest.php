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
use Illuminate\Support\Facades\DB;
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

test('the ER station lists in-house lab samples to collect', function () {
    Livewire::test('pages::display.medication-delivery')
        ->assertSee('Lab samples to collect')
        ->assertSee('ER LAB PATIENT')
        ->assertSee('929100')
        ->assertSee('CBC')
        ->assertSee('EDTA 2cc')
        ->assertSee('LFT')
        ->assertDontSee('TSH');
});

test('an aide enters their PIN and marks a sample collected, which does not receive it in the lab', function () {
    Livewire::test('pages::display.medication-delivery')
        ->call('requestReceiveSamples', null, $this->cbc->id)
        ->assertSet('showPinModal', true)
        ->set('pin', '4321')
        ->call('verifyPin')
        ->assertHasNoErrors();

    expect($this->cbc->fresh())
        ->sample_collected_at->not->toBeNull()
        ->sample_collected_by_health_aide_id->toBe($this->aide->id)
        ->sample_received_at->toBeNull()
        ->and(LabInvoiceItem::query()->awaitingSample()->pluck('id')->sort()->values()->all())->toBe([$this->cbc->id, $this->lft->id])
        ->and(LabInvoiceItem::query()->awaitingCollection()->pluck('id')->all())->toBe([$this->lft->id]);
});

test('a signed-in aide can collect every sample of a patient at once', function () {
    $page = Livewire::test('pages::display.medication-delivery')->set('pin', '4321')->call('verifyPin');

    $page->call('requestReceiveSamples', $this->invoice->id)->assertSet('showPinModal', false);

    expect(LabInvoiceItem::query()->awaitingCollection()->count())->toBe(0)
        ->and(LabInvoiceItem::query()->awaitingSample()->count())->toBe(2);

    $page->assertDontSee('Lab samples to collect');
});

test('the lab still receives samples the ER collected, and sees who collected them', function () {
    $this->seed(RolePagePermissionSeeder::class);
    $this->cbc->update(['sample_collected_at' => now(), 'sample_collected_by_health_aide_id' => $this->aide->id]);
    $technician = User::factory()->labTechnician()->create(['name' => 'Lab Tech']);

    $page = Livewire::actingAs($technician)
        ->test('pages::lab.samples')
        ->assertSee('CBC')
        ->assertSee('Collected at ER')
        ->assertSee('Nasreen')
        ->call('receive', null, $this->cbc->id);

    expect($this->cbc->fresh())
        ->sample_received_by->toBe($technician->id)
        ->sample_collected_by_health_aide_id->toBe($this->aide->id);

    $page->set('tab', 'received')->assertSee('Lab Tech');

    expect(app(CeoLabOverview::class)->people(today()->toImmutable())['lab']->firstWhere('name', 'Nasreen (ER)'))
        ->toMatchArray(['received' => 1]);
});

test('the case page shows a sample collected at the ER that the lab has not received', function () {
    $this->seed(RolePagePermissionSeeder::class);
    $this->cbc->update(['sample_collected_at' => now(), 'sample_collected_by_health_aide_id' => $this->aide->id]);

    Livewire::actingAs(User::factory()->labTechnician()->create())
        ->test('pages::lab.case', ['labInvoice' => $this->invoice])
        ->assertSee('Collected at ER, not in lab yet');
});

test('the migration turns earlier ER receipts into collections and puts unfinished ones back for the lab', function () {
    $migration = require database_path('migrations/2026_09_24_182711_split_er_sample_collection_from_lab_receiving.php');
    $migration->down();

    DB::table('lab_invoice_items')->where('id', $this->cbc->id)->update(['sample_received_at' => now(), 'sample_received_by_health_aide_id' => $this->aide->id]);
    DB::table('lab_invoice_items')->where('id', $this->lft->id)->update(['sample_received_at' => now(), 'sample_received_by_health_aide_id' => $this->aide->id, 'results_completed_at' => now()]);

    $migration->up();

    expect($this->cbc->fresh())
        ->sample_collected_by_health_aide_id->toBe($this->aide->id)
        ->sample_collected_at->not->toBeNull()
        ->sample_received_at->toBeNull()
        ->and($this->lft->fresh()->sample_received_at)->not->toBeNull();
});
