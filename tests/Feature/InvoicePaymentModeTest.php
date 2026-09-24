<?php

use App\Enums\PaymentMode;
use App\Models\Invoice;
use App\Models\LabInvoice;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->receptionist = User::factory()->receptionist()->create();
    $this->shift = Shift::factory()->for($this->receptionist)->open()->create(['opening_balance' => 1000]);
});

test('reception switches a walk-in invoice from cash to online and back', function () {
    $invoice = Invoice::factory()->paid()->create(['shift_id' => $this->shift->id, 'total' => 500]);

    $page = Livewire::actingAs($this->receptionist)->test('pages::reception.shift')->call('setActiveTab', 'invoices');

    $page->call('setPaymentMode', $invoice->id, 'walkin', 'online');

    expect($invoice->fresh())
        ->payment_mode->toBe(PaymentMode::Online)
        ->payment_mode_changed_by->toBe($this->receptionist->id)
        ->payment_mode_changed_at->not->toBeNull();

    $page->call('setPaymentMode', $invoice->id, 'walkin', 'cash');

    expect($invoice->fresh()->payment_mode)->toBe(PaymentMode::Cash);
});

test('reception switches a lab invoice to online', function () {
    $labInvoice = LabInvoice::factory()->paid()->create(['shift_id' => $this->shift->id, 'total' => 800]);

    Livewire::actingAs($this->receptionist)
        ->test('pages::reception.shift')
        ->call('setPaymentMode', $labInvoice->id, 'lab', 'online');

    expect($labInvoice->fresh()->payment_mode)->toBe(PaymentMode::Online);
});

test('invoices from another shift, or returned ones, cannot be switched', function () {
    $oldShift = Shift::factory()->for($this->receptionist)->create(['status' => 'closed', 'closed_at' => now()->subDay()]);
    $old = Invoice::factory()->paid()->create(['shift_id' => $oldShift->id]);
    $returned = Invoice::factory()->returned()->create(['shift_id' => $this->shift->id]);

    $page = Livewire::actingAs($this->receptionist)->test('pages::reception.shift');
    $page->call('setPaymentMode', $old->id, 'walkin', 'online');
    $page->call('setPaymentMode', $returned->id, 'walkin', 'online');

    expect($old->fresh()->payment_mode)->toBe(PaymentMode::Cash)
        ->and($returned->fresh()->payment_mode)->toBe(PaymentMode::Cash);
});

test('online payments are shown on their own and kept out of cash to receive', function () {
    Invoice::factory()->paid()->create(['shift_id' => $this->shift->id, 'total' => 500]);
    Invoice::factory()->paid()->create(['shift_id' => $this->shift->id, 'total' => 300, 'payment_mode' => PaymentMode::Online]);
    LabInvoice::factory()->paid()->create(['shift_id' => $this->shift->id, 'total' => 700]);
    LabInvoice::factory()->paid()->create(['shift_id' => $this->shift->id, 'total' => 200, 'payment_mode' => PaymentMode::Online]);

    $shift = $this->shift->fresh();

    expect($shift->totalSales())->toBe(1700.0)
        ->and($shift->totalOnlineSales())->toBe(500.0)
        ->and($shift->totalCashSales())->toBe(1200.0)
        ->and($shift->expectedCash())->toBe(2200.0);

    Livewire::actingAs($this->receptionist)
        ->test('pages::reception.shift')
        ->assertSee('Online Payments')
        ->assertSee('500.00')
        ->assertSee('2,200.00')
        ->assertSee('1,700.00');
});

test('the shift history lists online totals', function () {
    $closed = Shift::factory()->for($this->receptionist)->create(['status' => 'closed', 'closed_at' => now()]);
    Invoice::factory()->paid()->create(['shift_id' => $closed->id, 'total' => 450, 'payment_mode' => PaymentMode::Online]);

    Livewire::actingAs(User::factory()->management()->create())
        ->test('pages::management.shift-history')
        ->assertSee('Online')
        ->assertSee('450.00');
});
