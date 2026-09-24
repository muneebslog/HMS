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

test('saved results can be discarded, putting the test back to awaiting results', function () {
    $this->item->update(['result_comment' => 'Old comment', 'results_completed_at' => now(), 'results_completed_by' => $this->labTechnician->id]);
    LabResult::factory()->create(['lab_invoice_item_id' => $this->item->id, 'lab_field_id' => $this->hb->id, 'value' => '14']);
    LabResult::factory()->create(['lab_invoice_item_id' => $this->item->id, 'lab_field_id' => $this->group->id, 'value' => 'O']);

    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.case', ['labInvoice' => $this->invoice])
        ->call('openResults', $this->item->id)
        ->assertSee('Discard results')
        ->call('discardResults')
        ->assertSet('showResultsModal', false)
        ->assertSee('Awaiting results')
        ->assertSee('Add results');

    $item = $this->item->fresh();

    expect($item->results()->count())->toBe(0)
        ->and($item->result_comment)->toBeNull()
        ->and($item->results_completed_at)->toBeNull()
        ->and($item->results_completed_by)->toBeNull()
        ->and($item->isDone())->toBeFalse();
});

test('the discard button only shows once results have been saved', function () {
    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.case', ['labInvoice' => $this->invoice])
        ->call('openResults', $this->item->id)
        ->assertDontSee('Discard results');
});

test('pressing enter in the results form moves to the next field instead of submitting', function () {
    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.case', ['labInvoice' => $this->invoice])
        ->call('openResults', $this->item->id)
        ->assertSeeHtml('x-on:keydown.enter="focusNextResultField($event)"')
        ->assertSeeHtml('data-result-field');
});

test('typing n in a text field becomes Nil', function () {
    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.case', ['labInvoice' => $this->invoice])
        ->call('openResults', $this->item->id)
        ->set("resultValues.{$this->note->id}", 'n')
        ->assertSet("resultValues.{$this->note->id}", 'Nil')
        ->set("resultValues.{$this->note->id}", ' N ')
        ->assertSet("resultValues.{$this->note->id}", 'Nil')
        ->set("resultValues.{$this->note->id}", 'no casts seen')
        ->assertSet("resultValues.{$this->note->id}", 'no casts seen')
        ->set("resultValues.{$this->note->id}", 'n')
        ->call('saveResults', true)
        ->assertHasNoErrors();

    expect($this->item->results()->where('lab_field_id', $this->note->id)->value('value'))->toBe('Nil');
});

test('n is not turned into Nil for number fields', function () {
    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.case', ['labInvoice' => $this->invoice])
        ->call('openResults', $this->item->id)
        ->set("resultValues.{$this->hb->id}", 'n')
        ->assertSet("resultValues.{$this->hb->id}", 'n')
        ->call('saveResults', true)
        ->assertHasErrors("resultValues.{$this->hb->id}");
});

test('the case page tags each test as in-house or outsourced', function () {
    LabInvoiceItem::factory()->outgoing()->create(['lab_invoice_id' => $this->invoice->id, 'test_name' => 'TSH']);

    $this->actingAs($this->labTechnician)
        ->get(route('lab.cases.show', $this->invoice))
        ->assertOk()
        ->assertSeeInOrder(['CBC', 'In-house'])
        ->assertSeeInOrder(['TSH', 'Outsourced']);
});

test('show report buttons appear only once results are completed', function () {
    $this->actingAs($this->labTechnician)
        ->get(route('lab.cases.show', $this->invoice))
        ->assertDontSee('Show report');

    $this->item->update(['results_completed_at' => now(), 'results_completed_by' => $this->labTechnician->id]);
    LabResult::factory()->create(['lab_invoice_item_id' => $this->item->id, 'lab_field_id' => $this->hb->id, 'value' => '10.2']);

    $this->actingAs($this->labTechnician)
        ->get(route('lab.cases.show', $this->invoice))
        ->assertSee('Show report')
        ->assertSee(route('lab.cases.report', ['labInvoice' => $this->invoice, 'item' => $this->item->id]), false)
        ->assertSee('target="_blank"', false);
});

test('the report shows completed results with the patient range, flag and comment', function () {
    $this->item->update(['result_comment' => 'Repeat after 2 weeks', 'results_completed_at' => now(), 'results_completed_by' => $this->labTechnician->id]);
    LabResult::factory()->create(['lab_invoice_item_id' => $this->item->id, 'lab_field_id' => $this->hb->id, 'value' => '10.2']);
    LabResult::factory()->create(['lab_invoice_item_id' => $this->item->id, 'lab_field_id' => $this->group->id, 'value' => 'B']);

    $this->actingAs($this->labTechnician)
        ->get(route('lab.cases.report', ['labInvoice' => $this->invoice, 'item' => $this->item->id]))
        ->assertOk()
        ->assertSee('Complete Blood Count')
        ->assertSee('RESULT PATIENT')
        ->assertSee('34 Years / Female')
        ->assertSee($this->invoice->invoice_number)
        ->assertSee('10.2')
        ->assertSee('12 – 15.5')
        ->assertSee('class="flag">L', false)
        ->assertSee('Repeat after 2 weeks')
        ->assertDontSee('Remarks');
});

test('the case report includes only completed tests, one sheet each', function () {
    $this->item->update(['results_completed_at' => now()]);
    LabResult::factory()->create(['lab_invoice_item_id' => $this->item->id, 'lab_field_id' => $this->hb->id, 'value' => '13']);

    $secondTest = LabTest::factory()->create(['test_name' => 'Blood Group Test', 'is_in_house' => true]);
    $secondTest->fields()->attach($this->group->id, ['display_order' => 1]);
    $second = LabInvoiceItem::factory()->inHouse()->create(['lab_invoice_id' => $this->invoice->id, 'lab_test_id' => $secondTest->id, 'results_completed_at' => now()]);
    LabResult::factory()->create(['lab_invoice_item_id' => $second->id, 'lab_field_id' => $this->group->id, 'value' => 'AB']);

    $pendingTest = LabTest::factory()->create(['test_name' => 'Pending Only Test', 'is_in_house' => true]);
    $pendingTest->fields()->attach($this->note->id, ['display_order' => 1]);
    $pending = LabInvoiceItem::factory()->inHouse()->create(['lab_invoice_id' => $this->invoice->id, 'lab_test_id' => $pendingTest->id]);
    LabResult::factory()->create(['lab_invoice_item_id' => $pending->id, 'lab_field_id' => $this->note->id, 'value' => 'draft']);

    $html = $this->actingAs($this->labTechnician)
        ->get(route('lab.cases.report', $this->invoice))
        ->assertOk()
        ->assertSee('Complete Blood Count')
        ->assertSee('Blood Group Test')
        ->assertDontSee('Pending Only Test')
        ->getContent();

    expect(substr_count($html, 'class="sheet"'))->toBe(2);
});

test('the report is unavailable until a test is completed', function () {
    LabResult::factory()->create(['lab_invoice_item_id' => $this->item->id, 'lab_field_id' => $this->hb->id, 'value' => '13']);

    $this->actingAs($this->labTechnician)
        ->get(route('lab.cases.report', $this->invoice))
        ->assertNotFound();
});

test('a report cannot be opened for a test from another case', function () {
    $other = LabInvoiceItem::factory()->inHouse()->create(['lab_test_id' => $this->labTest->id, 'results_completed_at' => now()]);

    $this->actingAs($this->labTechnician)
        ->get(route('lab.cases.report', ['labInvoice' => $this->invoice, 'item' => $other->id]))
        ->assertNotFound();
});

test('doctors cannot open lab reports', function () {
    $this->item->update(['results_completed_at' => now()]);

    $this->actingAs(User::factory()->doctor()->create())
        ->get(route('lab.cases.report', $this->invoice))
        ->assertForbidden();
});

test('the results form has no comment box, and saving keeps a comment the test already had', function () {
    $this->item->update(['result_comment' => 'Imported note']);

    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.case', ['labInvoice' => $this->invoice])
        ->call('openResults', $this->item->id)
        ->assertDontSeeHtml('wire:model="resultComment"')
        ->set("resultValues.{$this->hb->id}", '12.4')
        ->call('saveResults', true)
        ->assertHasNoErrors();

    expect($this->item->fresh()->result_comment)->toBe('Imported note');
});

test('the result form copes when all values arrive at once', function () {
    Livewire::actingAs($this->labTechnician)
        ->test('pages::lab.case', ['labInvoice' => $this->invoice])
        ->call('openResults', $this->item->id)
        ->set('resultValues', [$this->hb->id => '12.1', $this->group->id => '', $this->note->id => 'n'])
        ->assertHasNoErrors()
        ->assertSet("resultValues.{$this->note->id}", 'Nil')
        ->assertSet("resultValues.{$this->hb->id}", '12.1');
});
