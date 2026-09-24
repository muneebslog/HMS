<?php

use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use App\Models\LabSampleRetake;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RolePagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePagePermissionSeeder::class);
    $this->labTechnician = User::factory()->labTechnician()->create(['name' => 'Ayesha Tech']);

    $patient = Patient::factory()->create(['name' => 'Dash Patient']);
    $this->invoice = LabInvoice::factory()->paid()->create(['patient_id' => $patient->id, 'invoice_number' => '929000']);
});

test('lab technicians land on the lab dashboard after login and from the main dashboard', function () {
    $this->actingAs($this->labTechnician)->get(route('dashboard'))->assertRedirect(route('lab.dashboard'));
    $this->actingAs($this->labTechnician)->get(route('lab.dashboard'))->assertOk()->assertSee('Lab Dashboard');
});

test('reception cannot open the lab dashboard', function () {
    $this->actingAs(User::factory()->receptionist()->create())->get(route('lab.dashboard'))->assertForbidden();
});

test('the dashboard shows today\'s lab work at a glance', function () {
    $this->travelTo(today()->setTime(14, 0));

    LabInvoiceItem::factory()->inHouse()->create([
        'lab_invoice_id' => $this->invoice->id, 'test_name' => 'CBC', 'sample' => 'EDTA',
        'created_at' => now()->subHours(3), 'sample_received_at' => now()->subHours(3), 'sample_received_by' => $this->labTechnician->id,
        'results_completed_at' => now()->subHours(2), 'results_completed_by' => $this->labTechnician->id,
    ]);
    LabInvoiceItem::factory()->inHouse()->create([
        'lab_invoice_id' => $this->invoice->id, 'test_name' => 'LFT', 'sample' => 'Clotted',
        'created_at' => now()->subHours(5), 'sample_received_at' => now()->subHours(5),
    ]);
    LabInvoiceItem::factory()->inHouse()->create([
        'lab_invoice_id' => $this->invoice->id, 'test_name' => 'Urine C/E', 'sample' => 'Urine',
    ]);
    LabInvoiceItem::factory()->outgoing()->create(['lab_invoice_id' => $this->invoice->id, 'test_name' => 'TSH']);
    $retakeItem = LabInvoiceItem::factory()->inHouse()->create(['lab_invoice_id' => $this->invoice->id, 'test_name' => 'Sugar']);
    LabSampleRetake::factory()->create(['lab_invoice_item_id' => $retakeItem->id]);

    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.dashboard')
        ->assertSet('headline', [
            'to_receive' => 1,
            'awaiting_results' => 1,
            'completed_today' => 1,
            'open_retakes' => 1,
            'with_rider' => 1,
            'billed_today' => 5,
        ])
        ->assertSee('DASH PATIENT')
        ->assertSee('LFT')
        ->assertSee('5h 00m')
        ->assertSee('Urine')
        ->assertSee('Ayesha Tech')
        ->assertSee('1h 00m');
});

test('an empty day still renders', function () {
    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.dashboard')
        ->assertOk()
        ->assertSee('All caught up')
        ->assertSee('No tests billed yet today.');
});

test('lab technicians get one Dashboard link, no MR Lookup, and Lab Tests under Extras', function () {
    $html = $this->actingAs($this->labTechnician)->get(route('lab.dashboard'))->assertOk()->getContent();

    expect(substr_count($html, '>Dashboard<') + substr_count($html, ">\n                            Dashboard\n"))->toBeLessThanOrEqual(1)
        ->and($html)->not->toContain('href="'.route('reception.mr-lookup').'"')
        ->and($html)->not->toContain('href="'.route('lab.tests').'"')
        ->and($html)->toContain('href="'.route('extras').'"');

    $this->actingAs($this->labTechnician)->get(route('reception.mr-lookup'))->assertForbidden();
    $this->actingAs($this->labTechnician)->get(route('extras'))->assertOk()->assertSee('Lab Tests')->assertSee('Lab Fields');
});
