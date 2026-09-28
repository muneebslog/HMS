<?php

use App\Enums\OutgoingSampleStatus;
use App\Models\LabField;
use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RolePagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolePagePermissionSeeder::class);
    $this->labTechnician = User::factory()->labTechnician()->create();

    $this->tsh = LabTest::factory()->create(['test_name' => 'TSH', 'display_name' => 'Thyroid Stimulating Hormone', 'is_in_house' => false]);
    $this->tshField = LabField::factory()->create(['name' => 'TSH', 'unit' => 'uIU/mL']);
    $this->tsh->fields()->attach($this->tshField->id, ['display_order' => 1]);

    $this->patient = Patient::factory()->create(['name' => 'Partner Patient', 'gender' => 'female', 'age' => 40]);
    $this->invoice = LabInvoice::factory()->paid()->create(['patient_id' => $this->patient->id]);
    $this->item = LabInvoiceItem::factory()->outgoing()->create([
        'lab_invoice_id' => $this->invoice->id,
        'lab_test_id' => $this->tsh->id,
        'test_name' => 'TSH',
        'outgoing_status' => OutgoingSampleStatus::Given,
        'given_at' => now()->subDay(),
    ]);
});

test('outsourced tests offer both uploading the report and adding results', function () {
    $this->actingAs($this->labTechnician)
        ->get(route('lab.cases.show', $this->invoice))
        ->assertOk()
        ->assertSee('Upload report')
        ->assertSee('Add results');
});

test('staff without results entry cannot upload or add results', function () {
    $receptionist = User::factory()->receptionist()->create();

    $this->actingAs($receptionist)
        ->get(route('lab.cases.show', $this->invoice))
        ->assertOk()
        ->assertDontSee('Upload report')
        ->assertDontSee('Add results');

    Livewire::actingAs($receptionist)
        ->test('pages::lab.case', ['labInvoice' => $this->invoice])
        ->call('openUpload', $this->item->id)
        ->assertForbidden();
});

test('uploading the partner report stores it and marks the result received', function () {
    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.case', ['labInvoice' => $this->invoice])
        ->call('openUpload', $this->item->id)
        ->assertSet('showUploadModal', true)
        ->set('reportUpload', UploadedFile::fake()->create('tsh-report.pdf', 200, 'application/pdf'))
        ->call('uploadReport')
        ->assertHasNoErrors()
        ->assertSet('showUploadModal', false)
        ->assertSee('Report uploaded')
        ->assertSee('Partner report');

    $item = $this->item->fresh();

    expect($item->report_original_name)->toBe('tsh-report.pdf')
        ->and($item->report_uploaded_by)->toBe($this->labTechnician->id)
        ->and($item->outgoing_status)->toBe(OutgoingSampleStatus::Received)
        ->and($item->received_by)->toBe($this->labTechnician->id)
        ->and($item->isDone())->toBeTrue();

    Storage::disk('local')->assertExists($item->report_path);

    $this->actingAs($this->labTechnician)
        ->get(route('lab.cases.report-file', ['labInvoice' => $this->invoice, 'item' => $item]))
        ->assertOk();
});

test('only pdf and image files are accepted', function () {
    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.case', ['labInvoice' => $this->invoice])
        ->call('openUpload', $this->item->id)
        ->set('reportUpload', UploadedFile::fake()->create('report.docx', 50))
        ->call('uploadReport')
        ->assertHasErrors('reportUpload');

    expect($this->item->fresh()->report_path)->toBeNull();
});

test('replacing the report deletes the old file, and removing it reopens the test', function () {
    $component = Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.case', ['labInvoice' => $this->invoice])
        ->call('openUpload', $this->item->id)
        ->set('reportUpload', UploadedFile::fake()->create('first.pdf', 10, 'application/pdf'))
        ->call('uploadReport');

    $firstPath = $this->item->fresh()->report_path;

    $component->call('openUpload', $this->item->id)
        ->set('reportUpload', UploadedFile::fake()->image('second.jpg'))
        ->call('uploadReport')
        ->assertHasNoErrors();

    $secondPath = $this->item->fresh()->report_path;

    Storage::disk('local')->assertMissing($firstPath);
    Storage::disk('local')->assertExists($secondPath);

    $component->call('removeReport', $this->item->id);

    $item = $this->item->fresh();

    Storage::disk('local')->assertMissing($secondPath);
    expect($item->report_path)->toBeNull()
        ->and($item->outgoing_status)->toBe(OutgoingSampleStatus::Given)
        ->and($item->isDone())->toBeFalse();
});

test('results typed in from the partner report complete the test and print on the lab report', function () {
    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.case', ['labInvoice' => $this->invoice])
        ->call('openResults', $this->item->id)
        ->assertSet('showResultsModal', true)
        ->set("resultValues.{$this->tshField->id}", '3.2')
        ->call('saveResults', true)
        ->assertHasNoErrors()
        ->assertSee('Results complete')
        ->assertSee('Show report');

    $item = $this->item->fresh();

    expect($item->results_completed_at)->not->toBeNull()
        ->and($item->outgoing_status)->toBe(OutgoingSampleStatus::Received)
        ->and($item->sample_received_at)->toBeNull()
        ->and($item->isDone())->toBeTrue();

    $this->actingAs($this->labTechnician)
        ->get(route('lab.cases.report', ['labInvoice' => $this->invoice, 'item' => $item->id]))
        ->assertOk()
        ->assertSee('Thyroid Stimulating Hormone')
        ->assertSee('3.2');
});

test('discarding typed-in results puts the test back with the partner lab', function () {
    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.case', ['labInvoice' => $this->invoice])
        ->call('openResults', $this->item->id)
        ->set("resultValues.{$this->tshField->id}", '3.2')
        ->call('saveResults', true)
        ->call('openResults', $this->item->id)
        ->call('discardResults');

    $item = $this->item->fresh();

    expect($item->results_completed_at)->toBeNull()
        ->and($item->outgoing_status)->toBe(OutgoingSampleStatus::Given)
        ->and($item->isDone())->toBeFalse();
});

test('the patient can open an uploaded partner report from the public results page', function () {
    Storage::disk('local')->put('lab-reports/tsh.pdf', 'pdf');
    $this->item->update([
        'report_path' => 'lab-reports/tsh.pdf',
        'report_original_name' => 'tsh.pdf',
        'outgoing_status' => OutgoingSampleStatus::Received,
    ]);
    $fileUrl = route('lab.public.file', ['token' => $this->invoice->public_token, 'item' => $this->item->id]);

    $this->get(route('lab.public.show', $this->invoice->public_token))
        ->assertOk()
        ->assertSee('All your results are ready')
        ->assertSee($fileUrl, false);

    $this->get($fileUrl)
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

test('public partner report links only work for the case they belong to', function () {
    $other = LabInvoiceItem::factory()->outgoing()->create(['report_path' => 'lab-reports/other.pdf']);
    Storage::disk('local')->put('lab-reports/other.pdf', 'pdf');

    $this->get(route('lab.public.file', ['token' => $this->invoice->public_token, 'item' => $other->id]))->assertNotFound();
    $this->get(route('lab.public.file', ['token' => $this->invoice->public_token, 'item' => $this->item->id]))->assertNotFound();
});
