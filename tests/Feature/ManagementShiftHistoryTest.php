<?php

use App\Models\Invoice;
use App\Models\LabInvoice;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('management.shift-history'));

    $response->assertRedirect(route('login'));
});

test('authenticated management users can visit the shift history page', function () {
    $user = User::factory()->management()->create();

    $response = $this->actingAs($user)->get(route('management.shift-history'));

    $response->assertOk();
});

test('closed shifts are listed on the shift history page', function () {
    $user = User::factory()->management()->create();
    $shift = Shift::factory()->for($user)->closed()->create([
        'opening_balance' => 100.00,
        'closing_balance' => 250.00,
    ]);

    Livewire::actingAs($user)
        ->test('pages::management.shift-history')
        ->assertSee($shift->opened_at->format('Y-m-d H:i'))
        ->assertSee($shift->user->name)
        ->assertSee(number_format($shift->opening_balance, 2))
        ->assertSee(number_format($shift->totalSales(), 2));
});

test('open shifts are not listed on the shift history page', function () {
    $user = User::factory()->management()->create();
    $shift = Shift::factory()->for($user)->open()->create();

    Livewire::actingAs($user)
        ->test('pages::management.shift-history')
        ->assertDontSee($shift->opened_at->format('Y-m-d H:i'));
});

test('walk-in invoices for a closed shift can be viewed', function () {
    $user = User::factory()->management()->create();
    $shift = Shift::factory()->for($user)->closed()->create();
    $invoice = Invoice::factory()->create([
        'created_by' => $user->id,
        'shift_id' => $shift->id,
        'status' => 'paid',
    ]);

    Livewire::actingAs($user)
        ->test('pages::management.shift-history')
        ->call('viewShift', $shift->id)
        ->assertSet('selectedShiftId', $shift->id)
        ->assertSet('showShiftModal', true)
        ->assertSee($invoice->invoice_number)
        ->assertSee($invoice->patient->name)
        ->assertSee(number_format($invoice->total, 2))
        ->assertSee('Paid');
});

test('lab invoices for a closed shift can be viewed', function () {
    $user = User::factory()->management()->create();
    $shift = Shift::factory()->for($user)->closed()->create();
    $invoice = LabInvoice::factory()->create([
        'created_by' => $user->id,
        'shift_id' => $shift->id,
        'status' => 'paid',
    ]);

    Livewire::actingAs($user)
        ->test('pages::management.shift-history')
        ->call('viewShift', $shift->id)
        ->assertSee($invoice->invoice_number)
        ->assertSee($invoice->patient->name)
        ->assertSee(number_format($invoice->total, 2));
});

test('invoices from other shifts are not shown in the shift detail modal', function () {
    $user = User::factory()->management()->create();
    $closedShift = Shift::factory()->for($user)->closed()->create();
    $otherShift = Shift::factory()->for($user)->closed()->create();
    $otherInvoice = Invoice::factory()->create([
        'created_by' => $user->id,
        'shift_id' => $otherShift->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::management.shift-history')
        ->call('viewShift', $closedShift->id)
        ->assertDontSee($otherInvoice->invoice_number);
});

test('modal can be closed', function () {
    $user = User::factory()->management()->create();
    $shift = Shift::factory()->for($user)->closed()->create();

    Livewire::actingAs($user)
        ->test('pages::management.shift-history')
        ->call('viewShift', $shift->id)
        ->assertSet('showShiftModal', true)
        ->call('closeShiftModal')
        ->assertSet('showShiftModal', false)
        ->assertSet('selectedShiftId', null);
});
