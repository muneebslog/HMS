<?php

use App\Models\LabField;
use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use App\Models\LabResult;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RolePagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePagePermissionSeeder::class);
    $this->labTechnician = User::factory()->labTechnician()->create();

    $this->labTest = LabTest::factory()->create(['test_name' => 'CBC', 'display_name' => 'Complete Blood Count', 'is_in_house' => true]);
    $this->hb = LabField::factory()->create(['name' => 'HB', 'unit' => 'g/dL']);
    $this->hb->ranges()->createMany([
        ['category' => 'male', 'value_low' => '13', 'value_high' => '17'],
        ['category' => 'female', 'value_low' => '12', 'value_high' => '15.5'],
    ]);
    $this->group = LabField::factory()->choice(['A', 'B', 'AB', 'O'])->create(['name' => 'Blood Group']);
    $this->note = LabField::factory()->text()->create(['name' => 'Remarks']);
    $this->labTest->fields()->attach($this->hb->id, ['display_order' => 1]);
    $this->labTest->fields()->attach($this->group->id, ['display_order' => 2]);
    $this->labTest->fields()->attach($this->note->id, ['display_order' => 3]);

    $this->patient = Patient::factory()->create(['name' => 'Result Patient', 'gender' => 'female', 'age' => 34]);
    $this->invoice = LabInvoice::factory()->paid()->create(['patient_id' => $this->patient->id]);
    $this->item = LabInvoiceItem::factory()->inHouse()->create([
        'lab_invoice_id' => $this->invoice->id,
        'lab_test_id' => $this->labTest->id,
        'test_name' => 'CBC',
    ]);
});

test('in-house tests with fields show an add results button', function () {
    $this->actingAs($this->labTechnician)
        ->get(route('lab.cases.show', $this->invoice))
        ->assertOk()
        ->assertSee('Add results');
});

test('in-house tests without fields and send-out tests have no results button', function () {
    $invoice = LabInvoice::factory()->paid()->create(['patient_id' => $this->patient->id]);
    $emptyTest = LabTest::factory()->create(['is_in_house' => true]);
    LabInvoiceItem::factory()->inHouse()->create(['lab_invoice_id' => $invoice->id, 'lab_test_id' => $emptyTest->id]);
    LabInvoiceItem::factory()->outgoing()->create(['lab_invoice_id' => $invoice->id]);

    $this->actingAs($this->labTechnician)
        ->get(route('lab.cases.show', $invoice))
        ->assertOk()
        ->assertDontSee('Add results')
        ->assertSee('No fields set up for this test');
});

test('results can be saved and completed', function () {
    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.case', ['labInvoice' => $this->invoice])
        ->call('openResults', $this->item->id)
        ->assertSet('showResultsModal', true)
        ->assertSee('Complete Blood Count')
        ->assertSee('12 – 15.5')
        ->set("resultValues.{$this->hb->id}", '10.2')
        ->assertSee('Low')
        ->set("resultValues.{$this->group->id}", 'B')
        ->set('resultComment', 'Slightly haemolysed')
        ->call('saveResults', true)
        ->assertHasNoErrors()
        ->assertSet('showResultsModal', false)
        ->assertSee('Results complete')
        ->assertSee('Edit results');

    $item = $this->item->fresh('results');

    expect($item->isDone())->toBeTrue()
        ->and($item->results_completed_by)->toBe($this->labTechnician->id)
        ->and($item->result_comment)->toBe('Slightly haemolysed')
        ->and($item->results->pluck('value', 'lab_field_id')->all())->toBe([$this->hb->id => '10.2', $this->group->id => 'B'])
        ->and($item->results->first()->entered_by)->toBe($this->labTechnician->id);
});

test('saving as pending keeps values but does not complete the test', function () {
    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.case', ['labInvoice' => $this->invoice])
        ->call('openResults', $this->item->id)
        ->set("resultValues.{$this->hb->id}", '13')
        ->call('saveResults', false)
        ->assertHasNoErrors()
        ->assertSee('Results pending');

    $item = $this->item->fresh('results');

    expect($item->isDone())->toBeFalse()
        ->and($item->results)->toHaveCount(1);
});

test('reopening loads saved values and clearing a value deletes it', function () {
    LabResult::factory()->create(['lab_invoice_item_id' => $this->item->id, 'lab_field_id' => $this->hb->id, 'value' => '14']);
    LabResult::factory()->create(['lab_invoice_item_id' => $this->item->id, 'lab_field_id' => $this->group->id, 'value' => 'O']);

    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.case', ['labInvoice' => $this->invoice])
        ->call('openResults', $this->item->id)
        ->assertSet("resultValues.{$this->hb->id}", '14')
        ->assertSet("resultValues.{$this->group->id}", 'O')
        ->set("resultValues.{$this->group->id}", '')
        ->call('saveResults', true)
        ->assertHasNoErrors();

    expect($this->item->results()->pluck('value', 'lab_field_id')->all())->toBe([$this->hb->id => '14']);
});

test('invalid values are rejected', function () {
    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.case', ['labInvoice' => $this->invoice])
        ->call('openResults', $this->item->id)
        ->set("resultValues.{$this->hb->id}", 'twelve')
        ->set("resultValues.{$this->group->id}", 'Z')
        ->call('saveResults', true)
        ->assertHasErrors(["resultValues.{$this->hb->id}", "resultValues.{$this->group->id}"]);

    expect($this->item->results()->count())->toBe(0)
        ->and($this->item->fresh()->isDone())->toBeFalse();
});

test('a test cannot be completed with no results entered', function () {
    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.case', ['labInvoice' => $this->invoice])
        ->call('openResults', $this->item->id)
        ->call('saveResults', true)
        ->assertHasErrors('resultValues');

    expect($this->item->fresh()->isDone())->toBeFalse();
});

test('results cannot be opened for a test from another case', function () {
    $otherItem = LabInvoiceItem::factory()->inHouse()->create(['lab_test_id' => $this->labTest->id]);

    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.case', ['labInvoice' => $this->invoice])
        ->call('openResults', $otherItem->id)
        ->assertSet('showResultsModal', false)
        ->assertSet('editingItemId', null);
});

test('completing results moves the case to complete on the cases list', function () {
    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.case', ['labInvoice' => $this->invoice])
        ->call('openResults', $this->item->id)
        ->set("resultValues.{$this->hb->id}", '13')
        ->call('saveResults', true);

    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.cases')
        ->assertSee('RESULT PATIENT')
        ->assertSee('1/1')
        ->assertSee('Complete');
});
