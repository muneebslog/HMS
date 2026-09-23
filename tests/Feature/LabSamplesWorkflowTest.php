<?php

use App\Actions\CreatePrintJob;
use App\Enums\OutgoingSampleStatus;
use App\Models\Family;
use App\Models\LabField;
use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use App\Models\LabSampleRetake;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\PrintJob;
use App\Models\User;
use Database\Seeders\RolePagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePagePermissionSeeder::class);
    config()->set('services.print_agent.token', 'test-agent-token');

    $this->receptionist = User::factory()->receptionist()->create();
    $this->labTechnician = User::factory()->labTechnician()->create();

    $this->patient = Patient::factory()->for(Family::factory()->state(['phone' => '03001112233']))->create(['name' => 'Sample Patient']);
    $this->invoice = LabInvoice::factory()->paid()->create(['patient_id' => $this->patient->id, 'invoice_number' => '928900']);

    $this->cbc = LabInvoiceItem::factory()->inHouse()->create([
        'lab_invoice_id' => $this->invoice->id, 'test_name' => 'CBC', 'sample' => 'EDTA Blood', 'price' => 800,
    ]);
    $this->sugar = LabInvoiceItem::factory()->inHouse()->create([
        'lab_invoice_id' => $this->invoice->id, 'test_name' => 'Blood Sugar', 'sample' => 'Fluoride', 'price' => 300,
    ]);
    $this->tsh = LabInvoiceItem::factory()->outgoing()->create([
        'lab_invoice_id' => $this->invoice->id, 'test_name' => 'TSH', 'sample' => 'Serum',
    ]);
});

test('reception sees outsourced samples to call the rider for, and not in-house ones', function () {
    Livewire::actingAs($this->receptionist)
        ->test('pages::reception.lab-samples')
        ->assertOk()
        ->assertSee('SAMPLE PATIENT')
        ->assertSee('TSH')
        ->assertSee('Serum')
        ->assertDontSee('Blood Sugar');
});

test('reception marks the rider called, then hands the sample over', function () {
    $page = Livewire::actingAs($this->receptionist)->test('pages::reception.lab-samples');

    $page->call('markRiderCalled');

    expect($this->tsh->fresh())
        ->outgoing_status->toBe(OutgoingSampleStatus::Asked)
        ->asked_by->toBe($this->receptionist->id)
        ->asked_at->not->toBeNull();

    $page->call('handOver', null, $this->tsh->id);

    expect($this->tsh->fresh())
        ->outgoing_status->toBe(OutgoingSampleStatus::Given)
        ->given_by->toBe($this->receptionist->id);

    $page->set('tab', 'today')->assertSee('Handed to rider today')->assertSee('TSH');
    expect(LabInvoiceItem::query()->awaitingRider()->count())->toBe(0);
});

test('handing over everything only hands over samples the rider was called for', function () {
    $other = LabInvoiceItem::factory()->outgoing()->create(['test_name' => 'Vitamin D']);
    $this->tsh->update(['outgoing_status' => OutgoingSampleStatus::Asked, 'asked_at' => now()]);

    Livewire::actingAs($this->receptionist)->test('pages::reception.lab-samples')->call('handOver');

    expect($this->tsh->fresh()->outgoing_status)->toBe(OutgoingSampleStatus::Given)
        ->and($other->fresh()->outgoing_status)->toBe(OutgoingSampleStatus::Pending);
});

test('a sample collected without calling the rider can still be handed over for its case', function () {
    Livewire::actingAs($this->receptionist)->test('pages::reception.lab-samples')->call('handOver', $this->invoice->id);

    expect($this->tsh->fresh())
        ->outgoing_status->toBe(OutgoingSampleStatus::Given)
        ->asked_at->not->toBeNull();
});

test('returned cases are left out of the sample queues', function () {
    $this->invoice->update(['status' => 'returned']);

    expect(LabInvoiceItem::query()->awaitingRider()->count())->toBe(0)
        ->and(LabInvoiceItem::query()->awaitingSample()->count())->toBe(0);
});

test('the lab sees in-house samples to receive and confirms them', function () {
    $page = Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.samples')
        ->assertSee('SAMPLE PATIENT')
        ->assertSee('CBC')
        ->assertSee('EDTA Blood')
        ->assertSee('Blood Sugar')
        ->assertDontSee('TSH');

    $page->call('receive', null, $this->cbc->id);

    expect($this->cbc->fresh())
        ->sample_received_at->not->toBeNull()
        ->sample_received_by->toBe($this->labTechnician->id)
        ->and($this->sugar->fresh()->sample_received_at)->toBeNull();

    $page->call('receive', $this->invoice->id);

    expect($this->sugar->fresh()->sample_received_at)->not->toBeNull()
        ->and(LabInvoiceItem::query()->awaitingSample()->count())->toBe(0);

    $page->set('tab', 'received')->assertSee('CBC')->assertSee('Blood Sugar');
});

test('the full retake loop: lab asks, reception calls the patient and prints a no-charge slip, lab receives again', function () {
    $this->cbc->update(['sample_received_at' => now(), 'sample_received_by' => $this->labTechnician->id]);

    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.samples')
        ->call('openRetake', $this->cbc->id)
        ->set('retakeReason', 'Clotted')
        ->call('requestRetake')
        ->assertHasNoErrors();

    $retake = LabSampleRetake::sole();
    expect($retake)
        ->lab_invoice_item_id->toBe($this->cbc->id)
        ->reason->toBe('Clotted')
        ->requested_by->toBe($this->labTechnician->id)
        ->and($this->cbc->fresh()->sample_received_at)->toBeNull()
        ->and(LabInvoiceItem::query()->awaitingSample()->pluck('id')->all())->toBe([$this->sugar->id]);

    $reception = Livewire::actingAs($this->receptionist)
        ->test('pages::reception.lab-samples')
        ->set('tab', 'retakes')
        ->assertSee('SAMPLE PATIENT')
        ->assertSee('03001112233')
        ->assertSee('Clotted')
        ->assertSee('Call patient');

    $reception->call('markPatientContacted', $this->invoice->id);
    expect($retake->fresh()->patient_contacted_by)->toBe($this->receptionist->id);

    $reception->call('printRetakeSlip', $this->invoice->id);

    expect($retake->fresh())
        ->slip_printed_at->not->toBeNull()
        ->slip_printed_by->toBe($this->receptionist->id);

    $job = PrintJob::sole();
    expect($job->lab_invoice_id)->toBe($this->invoice->id)
        ->and($job->payload)->toMatchArray(['type' => 'lab_invoice', 'copy_for' => 'retake', 'item_ids' => [$this->cbc->id]]);

    expect(LabInvoiceItem::query()->awaitingSample()->pluck('id')->sort()->values()->all())->toBe([$this->cbc->id, $this->sugar->id]);

    Livewire::actingAs($this->labTechnician)->test('pages::lab.samples')->assertSee('Retake: Clotted');
});

test('the retake slip sent to the printer lists only the retake tests, with no charge', function () {
    LabSampleRetake::factory()->slipPrinted()->create(['lab_invoice_item_id' => $this->cbc->id, 'reason' => 'Hemolysed']);
    app(CreatePrintJob::class)->createLabSampleRetakeSlip($this->invoice, [$this->cbc->id]);

    $response = $this->getJson('/api/print-jobs/pending', ['Authorization' => 'Bearer test-agent-token'])->assertOk();

    $invoice = $response->json('data.0.invoice');
    expect($invoice['copy_for'])->toBe('retake')
        ->and((float) $invoice['total'])->toBe(0.0)
        ->and($invoice['items'])->toHaveCount(1)
        ->and($invoice['items'][0])->toMatchArray(['service_name' => 'CBC', 'sample' => 'EDTA Blood', 'retake_reason' => 'Hemolysed'])
        ->and((float) $invoice['items'][0]['price'])->toBe(0.0);
});

test('a retake needs a reason, and "other" needs it written', function () {
    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.samples')
        ->call('openRetake', $this->cbc->id)
        ->call('requestRetake')
        ->assertHasErrors('retakeReason')
        ->set('retakeReason', 'other')
        ->call('requestRetake')
        ->assertHasErrors('retakeOtherReason')
        ->set('retakeOtherReason', 'Patient ate before fasting test')
        ->call('requestRetake')
        ->assertHasNoErrors();

    expect(LabSampleRetake::sole()->reason)->toBe('Patient ate before fasting test');
});

test('a finished test or one already waiting on a retake cannot get another retake', function () {
    $this->cbc->update(['results_completed_at' => now()]);
    LabSampleRetake::factory()->create(['lab_invoice_item_id' => $this->sugar->id]);

    foreach ([$this->cbc->id, $this->sugar->id] as $itemId) {
        Livewire::actingAs($this->labTechnician)
            ->test('pages::lab.samples')
            ->call('openRetake', $itemId)
            ->set('retakeReason', 'Clotted')
            ->call('requestRetake');
    }

    expect(LabSampleRetake::count())->toBe(1);
});

test('the case page shows sample status, and saving results marks the sample received', function () {
    $labTest = LabTest::factory()->create(['is_in_house' => true]);
    $field = LabField::factory()->create(['name' => 'HB']);
    $labTest->fields()->attach($field->id, ['display_order' => 1]);
    $this->cbc->update(['lab_test_id' => $labTest->id]);
    LabSampleRetake::factory()->create(['lab_invoice_item_id' => $this->sugar->id, 'reason' => 'Insufficient quantity']);

    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.case', ['labInvoice' => $this->invoice])
        ->assertSee('Sample not received')
        ->assertSee('Retake requested: Insufficient quantity')
        ->call('openResults', $this->cbc->id)
        ->set("resultValues.{$field->id}", '12.5')
        ->call('saveResults', true)
        ->assertHasNoErrors();

    expect($this->cbc->fresh())
        ->sample_received_at->not->toBeNull()
        ->sample_received_by->toBe($this->labTechnician->id);
});

test('reception and the lab only get their own sample page', function () {
    $this->actingAs($this->receptionist)->get(route('reception.lab-samples'))->assertOk();
    $this->actingAs($this->receptionist)->get(route('lab.samples'))->assertForbidden();
    $this->actingAs($this->labTechnician)->get(route('lab.samples'))->assertOk();
    $this->actingAs($this->labTechnician)->get(route('reception.lab-samples'))->assertForbidden();

    Livewire::actingAs($this->receptionist)->test('pages::lab.samples')->call('receive', $this->invoice->id)->assertForbidden();
    Livewire::actingAs($this->labTechnician)->test('pages::reception.lab-samples')->call('markRiderCalled')->assertForbidden();
});

test('the sidebar shows each role its sample page with a count', function () {
    $this->actingAs($this->receptionist)->get(route('reception.lab-samples'))->assertSee('Lab Samples')->assertDontSee('Sample Receiving');
    $this->actingAs($this->labTechnician)->get(route('lab.samples'))->assertSee('Sample Receiving')->assertDontSee('Lab Samples');
});

test('the migration treats existing in-house tests as received so the queue starts empty', function () {
    $migration = require database_path('migrations/2026_09_24_041453_add_sample_received_to_lab_invoice_items_table.php');

    $migration->down();
    $migration->up();

    expect(DB::table('lab_invoice_items')->where('is_in_house', true)->whereNull('sample_received_at')->count())->toBe(0)
        ->and(DB::table('lab_invoice_items')->where('is_in_house', false)->whereNotNull('sample_received_at')->count())->toBe(0);
});
