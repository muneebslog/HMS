<?php

use App\Enums\OutgoingSampleStatus;
use App\Models\LabField;
use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use App\Models\LabResult;
use App\Models\LabTest;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->cbc = LabTest::factory()->create(['test_name' => 'CBC', 'display_name' => 'Complete Blood Count', 'is_in_house' => true]);
    $this->hb = LabField::factory()->create(['name' => 'HB', 'unit' => 'g/dL']);
    $this->cbc->fields()->attach($this->hb->id, ['display_order' => 1]);

    $this->patient = Patient::factory()->create(['name' => 'Qr Patient', 'gender' => 'female', 'age' => 28]);
    $this->invoice = LabInvoice::factory()->paid()->create(['patient_id' => $this->patient->id, 'invoice_number' => '928800']);

    $this->ready = LabInvoiceItem::factory()->inHouse()->create([
        'lab_invoice_id' => $this->invoice->id, 'lab_test_id' => $this->cbc->id, 'test_name' => 'CBC', 'results_completed_at' => now(),
    ]);
    LabResult::factory()->create(['lab_invoice_item_id' => $this->ready->id, 'lab_field_id' => $this->hb->id, 'value' => '11.8']);

    $sugar = LabTest::factory()->create(['test_name' => 'Blood Sugar', 'is_in_house' => true]);
    $this->waiting = LabInvoiceItem::factory()->inHouse()->create([
        'lab_invoice_id' => $this->invoice->id, 'lab_test_id' => $sugar->id, 'test_name' => 'Blood Sugar',
    ]);

    $tsh = LabTest::factory()->create(['test_name' => 'TSH', 'is_in_house' => false]);
    $this->outsourced = LabInvoiceItem::factory()->outgoing()->create([
        'lab_invoice_id' => $this->invoice->id, 'lab_test_id' => $tsh->id, 'test_name' => 'TSH', 'outgoing_status' => OutgoingSampleStatus::Given,
    ]);
});

test('every new lab invoice gets a random public code used in its results link', function () {
    expect($this->invoice->public_token)->toHaveLength(20)
        ->and($this->invoice->publicReportsUrl())->toBe(route('lab.public.show', $this->invoice->public_token))
        ->and($this->invoice->publicReportsUrl())->not->toContain('928800')
        ->and(LabInvoice::factory()->create()->public_token)->not->toBe($this->invoice->public_token);
});

test('the patient can see their tests and statuses without logging in', function () {
    $this->get(route('lab.public.show', $this->invoice->public_token))
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('QR PATIENT')
        ->assertSee('Receipt 928800')
        ->assertSee('Complete Blood Count')
        ->assertSee('Ready')
        ->assertSee('Blood Sugar')
        ->assertSee('In process')
        ->assertSee('TSH')
        ->assertSee('Sent to partner lab')
        ->assertSee('1 of 3 results are ready')
        ->assertSee(route('lab.public.report', ['token' => $this->invoice->public_token, 'item' => $this->ready->id]), false)
        ->assertDontSee(route('lab.public.report', ['token' => $this->invoice->public_token, 'item' => $this->waiting->id]), false);
});

test('the patient can open the report for a ready test', function () {
    $this->get(route('lab.public.report', ['token' => $this->invoice->public_token, 'item' => $this->ready->id]))
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('Complete Blood Count')
        ->assertSee('11.8')
        ->assertSee('QR PATIENT');
});

test('reports for tests that are not ready, or from another case, are not shown', function () {
    $other = LabInvoiceItem::factory()->inHouse()->create(['lab_test_id' => $this->cbc->id, 'results_completed_at' => now()]);

    $this->get(route('lab.public.report', ['token' => $this->invoice->public_token, 'item' => $this->waiting->id]))->assertNotFound();
    $this->get(route('lab.public.report', ['token' => $this->invoice->public_token, 'item' => $other->id]))->assertNotFound();
});

test('unknown codes, receipt numbers and returned cases are not found', function () {
    $this->get(route('lab.public.show', 'doesnotexist12345678'))->assertNotFound();
    $this->get(route('lab.public.show', '928800'))->assertNotFound();
    $this->get(route('lab.public.report', '928800'))->assertNotFound();

    $this->invoice->update(['status' => 'returned']);

    $this->get(route('lab.public.show', $this->invoice->public_token))->assertNotFound();
});

test('the migration gives every existing invoice its own code', function () {
    LabInvoice::factory()->count(3)->create();
    $migration = require database_path('migrations/2026_09_24_030548_add_public_token_to_lab_invoices_table.php');

    $migration->down();
    $migration->up();

    $tokens = DB::table('lab_invoices')->pluck('public_token');

    expect($tokens)->toHaveCount(4)
        ->and($tokens->filter()->count())->toBe(4)
        ->and($tokens->unique()->count())->toBe(4);
});
