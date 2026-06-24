<?php

use App\Enums\TokenResetType;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LabInvoice;
use App\Models\LabInvoiceItem;
use App\Models\Patient;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\Shift;
use App\Models\User;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('reception.invoices'));

    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the invoices page', function () {
    $user = User::factory()->management()->create();
    Shift::factory()->for($user)->open()->create();

    $response = $this->actingAs($user)->get(route('reception.invoices'));

    $response->assertOk();
});

test('walk-in invoices are listed for the current shift', function () {
    $user = User::factory()->management()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $invoice = Invoice::factory()->create([
        'created_by' => $user->id,
        'shift_id' => $shift->id,
        'status' => 'pending',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.invoices')
        ->assertSee($invoice->invoice_number)
        ->assertSee($invoice->patient->name)
        ->assertSee(number_format($invoice->total, 2))
        ->assertSee('Pending');
});

test('walk-in invoices from other shifts are not listed', function () {
    $user = User::factory()->management()->create();
    Shift::factory()->for($user)->open()->create();
    $otherUser = User::factory()->management()->create();
    $otherShift = Shift::factory()->for($otherUser)->open()->create();
    $invoice = Invoice::factory()->create([
        'created_by' => $otherUser->id,
        'shift_id' => $otherShift->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.invoices')
        ->assertDontSee($invoice->invoice_number);
});

test('walk-in invoice total is shown for the current shift', function () {
    $user = User::factory()->management()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $invoiceOne = Invoice::factory()->create(['created_by' => $user->id, 'shift_id' => $shift->id, 'total' => 100.00]);
    $invoiceTwo = Invoice::factory()->create(['created_by' => $user->id, 'shift_id' => $shift->id, 'total' => 250.00]);
    $expectedTotal = number_format($invoiceOne->total + $invoiceTwo->total, 2);

    Livewire::actingAs($user)
        ->test('pages::reception.invoices')
        ->assertSee($expectedTotal);
});

test('lab invoices are listed for the current shift', function () {
    $user = User::factory()->management()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $invoice = LabInvoice::factory()->create([
        'created_by' => $user->id,
        'shift_id' => $shift->id,
        'status' => 'paid',
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.invoices')
        ->assertSee($invoice->invoice_number)
        ->assertSee($invoice->patient->name)
        ->assertSee(number_format($invoice->total, 2))
        ->assertSee('Paid');
});

test('lab invoice total is shown for the current shift', function () {
    $user = User::factory()->management()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $invoiceOne = LabInvoice::factory()->create(['created_by' => $user->id, 'shift_id' => $shift->id, 'total' => 300.00]);
    $invoiceTwo = LabInvoice::factory()->create(['created_by' => $user->id, 'shift_id' => $shift->id, 'total' => 450.00]);
    $expectedTotal = number_format($invoiceOne->total + $invoiceTwo->total, 2);

    Livewire::actingAs($user)
        ->test('pages::reception.invoices')
        ->assertSee($expectedTotal);
});

test('walk-in invoice details can be viewed', function () {
    $user = User::factory()->management()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $invoice = Invoice::factory()->create(['created_by' => $user->id, 'shift_id' => $shift->id]);
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
    $user = User::factory()->management()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $invoice = LabInvoice::factory()->create(['created_by' => $user->id, 'shift_id' => $shift->id]);
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
    $user = User::factory()->management()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $invoice = Invoice::factory()->create(['created_by' => $user->id, 'shift_id' => $shift->id]);

    Livewire::actingAs($user)
        ->test('pages::reception.invoices')
        ->call('viewInvoice', $invoice->id, 'walkin')
        ->assertSet('showViewModal', true)
        ->call('closeViewModal')
        ->assertSet('showViewModal', false)
        ->assertSet('viewingInvoiceId', null)
        ->assertSet('viewingType', null);
});

test('walk-in invoice details show the token number', function () {
    $user = User::factory()->management()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = Service::factory()->create([
        'is_standalone' => true,
        'token_reset_type' => TokenResetType::Shift,
    ]);
    ServicePrice::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => null,
        'price' => 100.00,
    ]);

    $invoice = DB::transaction(function () use ($user, $shift, $service) {
        $patient = Patient::factory()->create();
        $invoice = Invoice::create([
            'patient_id' => $patient->id,
            'invoice_number' => Invoice::generateNumber(),
            'total' => 100.00,
            'status' => 'paid',
            'created_by' => $user->id,
            'shift_id' => $shift->id,
        ]);
        $item = InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'service_id' => $service->id,
            'doctor_id' => null,
            'service_name' => $service->name,
            'doctor_name' => null,
            'price' => 100.00,
        ]);
        app(QueueService::class)->generateToken($item);

        return $invoice;
    });

    $tokenNumber = $invoice->items->first()->queueToken->token_number;

    Livewire::actingAs($user)
        ->test('pages::reception.invoices')
        ->call('viewInvoice', $invoice->id, 'walkin')
        ->assertSee((string) $tokenNumber);
});
