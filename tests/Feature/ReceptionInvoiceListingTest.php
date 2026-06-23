<?php

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('reception.invoices'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the invoices page', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('reception.invoices'));

    $response->assertOk();
});

test('walk-in invoices are listed', function () {
    $user = User::factory()->create();
    $invoice = Invoice::factory()->create([
        'created_by' => $user->id,
        'status' => 'pending',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.invoices')
        ->assertSee($invoice->invoice_number)
        ->assertSee($invoice->patient->name)
        ->assertSee(number_format($invoice->total, 2))
        ->assertSee('Pending');
});

test('walk-in invoice total is shown', function () {
    $user = User::factory()->create();
    $invoiceOne = Invoice::factory()->create(['created_by' => $user->id, 'total' => 100.00]);
    $invoiceTwo = Invoice::factory()->create(['created_by' => $user->id, 'total' => 250.00]);
    $expectedTotal = number_format($invoiceOne->total + $invoiceTwo->total, 2);

    Livewire::actingAs($user)
        ->test('pages::reception.invoices')
        ->assertSee($expectedTotal);
});

test('lab invoices are listed', function () {
    $user = User::factory()->create();
    $invoice = LabInvoice::factory()->create([
        'created_by' => $user->id,
        'status' => 'paid',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.invoices')
        ->assertSee($invoice->invoice_number)
        ->assertSee($invoice->patient->name)
        ->assertSee(number_format($invoice->total, 2))
        ->assertSee('Paid');
});

test('lab invoice total is shown', function () {
    $user = User::factory()->create();
    $invoiceOne = LabInvoice::factory()->create(['created_by' => $user->id, 'total' => 300.00]);
    $invoiceTwo = LabInvoice::factory()->create(['created_by' => $user->id, 'total' => 450.00]);
    $expectedTotal = number_format($invoiceOne->total + $invoiceTwo->total, 2);

    Livewire::actingAs($user)
        ->test('pages::reception.invoices')
        ->assertSee($expectedTotal);
});

test('walk-in invoice details can be viewed', function () {
    $user = User::factory()->create();
    $invoice = Invoice::factory()->create(['created_by' => $user->id]);
    $item = InvoiceItem::factory()->create(['invoice_id' => $invoice->id]);

    Livewire::actingAs($user)
        ->test('pages::reception.invoices')
        ->call('viewInvoice', $invoice->id, 'walkin')
        ->assertSet('viewingInvoiceId', $invoice->id)
        ->assertSet('viewingType', 'walkin')
        ->assertSet('showViewModal', true)
        ->assertSee($invoice->invoice_number)
        ->assertSee($item->service_name)
        ->assertSee(number_format($item->price, 2));
});

test('lab invoice details can be viewed', function () {
    $user = User::factory()->create();
    $invoice = LabInvoice::factory()->create(['created_by' => $user->id]);
    $item = LabInvoiceItem::factory()->create(['lab_invoice_id' => $invoice->id]);

    Livewire::actingAs($user)
        ->test('pages::reception.invoices')
        ->call('viewInvoice', $invoice->id, 'lab')
        ->assertSet('viewingInvoiceId', $invoice->id)
        ->assertSet('viewingType', 'lab')
        ->assertSet('showViewModal', true)
        ->assertSee($invoice->invoice_number)
        ->assertSee($item->test_name)
        ->assertSee(number_format($item->price, 2));
});

test('view modal can be closed', function () {
    $user = User::factory()->create();
    $invoice = Invoice::factory()->create(['created_by' => $user->id]);

    Livewire::actingAs($user)
        ->test('pages::reception.invoices')
        ->call('viewInvoice', $invoice->id, 'walkin')
        ->assertSet('showViewModal', true)
        ->call('closeViewModal')
        ->assertSet('showViewModal', false)
        ->assertSet('viewingInvoiceId', null)
        ->assertSet('viewingType', null);
});
