<?php

use App\Enums\ApprovalStatus;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\LabInvoice;
use App\Models\ProcedurePayment;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\RolePagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePagePermissionSeeder::class);
});

test('guests are redirected to the login page', function () {
    $this->get(route('management.approvals'))
        ->assertRedirect(route('login'));
});

test('management and admin can visit the approvals page', function (string $role) {
    $user = User::factory()->{$role}()->create();

    $this->actingAs($user)
        ->get(route('management.approvals'))
        ->assertOk();
})->with([
    'management',
    'admin',
]);

test('receptionists cannot visit the approvals page', function () {
    $user = User::factory()->receptionist()->create();

    $this->actingAs($user)
        ->get(route('management.approvals'))
        ->assertForbidden();
});

test('pending returns and expenses are listed on the approvals page', function () {
    $receptionist = User::factory()->receptionist()->create();
    $management = User::factory()->management()->create();
    $shift = Shift::factory()->for($receptionist)->open()->create();

    $invoice = Invoice::factory()->returned()->create([
        'shift_id' => $shift->id,
        'created_by' => $receptionist->id,
        'return_requested_by' => $receptionist->id,
        'total' => 120.00,
    ]);

    $expense = Expense::factory()->for($shift)->for($receptionist)->create([
        'name' => 'Office supplies',
        'amount' => 35.00,
    ]);

    Livewire::actingAs($management)
        ->test('pages::management.approvals')
        ->assertSee($invoice->invoice_number)
        ->assertSee(number_format($invoice->total, 2))
        ->call('setActiveTab', 'expenses')
        ->assertSee('Office supplies')
        ->assertSee(number_format($expense->amount, 2));
});

test('approving a return leaves cash unchanged and stamps the reviewer', function () {
    $receptionist = User::factory()->receptionist()->create();
    $management = User::factory()->management()->create();
    $shift = Shift::factory()->for($receptionist)->open()->create([
        'opening_balance' => 100.00,
    ]);
    $invoice = Invoice::factory()->returned()->create([
        'shift_id' => $shift->id,
        'created_by' => $receptionist->id,
        'return_requested_by' => $receptionist->id,
        'total' => 150.00,
    ]);

    expect($shift->fresh()->expectedCash())->toBe(100.00);

    Livewire::actingAs($management)
        ->test('pages::management.approvals')
        ->call('approveReturn', $invoice->id, 'walkin')
        ->assertHasNoErrors();

    $invoice->refresh();
    expect($invoice)
        ->status->toBe('returned')
        ->return_approval_status->toBe(ApprovalStatus::Approved)
        ->return_reviewed_by->toBe($management->id)
        ->return_reviewed_at->not->toBeNull();

    expect($shift->fresh()->expectedCash())->toBe(100.00);
});

test('rejecting a return restores the sale to cash', function () {
    $receptionist = User::factory()->receptionist()->create();
    $management = User::factory()->management()->create();
    $shift = Shift::factory()->for($receptionist)->open()->create([
        'opening_balance' => 100.00,
    ]);
    $invoice = Invoice::factory()->returned()->create([
        'shift_id' => $shift->id,
        'created_by' => $receptionist->id,
        'return_requested_by' => $receptionist->id,
        'total' => 150.00,
    ]);

    Livewire::actingAs($management)
        ->test('pages::management.approvals')
        ->set('rejectNote', 'Customer kept the service')
        ->call('rejectReturn', $invoice->id, 'walkin')
        ->assertHasNoErrors();

    $invoice->refresh();
    expect($invoice)
        ->status->toBe('paid')
        ->return_approval_status->toBe(ApprovalStatus::Rejected)
        ->return_note->toBe('Customer kept the service');

    expect($shift->fresh()->totalWalkInSales())->toBe(150.00)
        ->and($shift->fresh()->expectedCash())->toBe(250.00);
});

test('approving a lab return stamps approved without changing cash', function () {
    $receptionist = User::factory()->receptionist()->create();
    $management = User::factory()->management()->create();
    $shift = Shift::factory()->for($receptionist)->open()->create([
        'opening_balance' => 50.00,
    ]);
    $invoice = LabInvoice::factory()->returned()->create([
        'shift_id' => $shift->id,
        'created_by' => $receptionist->id,
        'return_requested_by' => $receptionist->id,
        'total' => 80.00,
    ]);

    $cashBefore = $shift->fresh()->expectedCash();

    Livewire::actingAs($management)
        ->test('pages::management.approvals')
        ->call('approveReturn', $invoice->id, 'lab')
        ->assertHasNoErrors();

    expect($invoice->fresh()->return_approval_status)->toBe(ApprovalStatus::Approved);
    expect($shift->fresh()->expectedCash())->toBe($cashBefore);
});

test('rejecting a procedure payment return restores procedure sales', function () {
    $receptionist = User::factory()->receptionist()->create();
    $management = User::factory()->management()->create();
    $shift = Shift::factory()->for($receptionist)->open()->create([
        'opening_balance' => 100.00,
    ]);
    $payment = ProcedurePayment::factory()->returned($receptionist)->create([
        'shift_id' => $shift->id,
        'amount' => 200.00,
        'created_by' => $receptionist->id,
    ]);

    expect($shift->fresh()->totalProcedureSales())->toBe(0.0);

    Livewire::actingAs($management)
        ->test('pages::management.approvals')
        ->call('rejectReturn', $payment->id, 'procedure')
        ->assertHasNoErrors();

    $payment->refresh();
    expect($payment->isReturned())->toBeFalse()
        ->and($payment->return_approval_status)->toBe(ApprovalStatus::Rejected);

    expect($shift->fresh()->totalProcedureSales())->toBe(200.00);
});

test('approving an expense leaves cash unchanged', function () {
    $receptionist = User::factory()->receptionist()->create();
    $management = User::factory()->management()->create();
    $shift = Shift::factory()->for($receptionist)->open()->create([
        'opening_balance' => 100.00,
    ]);
    $expense = Expense::factory()->for($shift)->for($receptionist)->create([
        'name' => 'Stationery',
        'amount' => 25.00,
    ]);

    expect($shift->fresh()->expectedCash())->toBe(75.00);

    Livewire::actingAs($management)
        ->test('pages::management.approvals')
        ->call('setActiveTab', 'expenses')
        ->call('approveExpense', $expense->id)
        ->assertHasNoErrors();

    expect($expense->fresh())
        ->approval_status->toBe(ApprovalStatus::Approved)
        ->reviewed_by->toBe($management->id);

    expect($shift->fresh()->expectedCash())->toBe(75.00);
});

test('rejecting an expense removes it from cash', function () {
    $receptionist = User::factory()->receptionist()->create();
    $management = User::factory()->management()->create();
    $shift = Shift::factory()->for($receptionist)->open()->create([
        'opening_balance' => 100.00,
    ]);
    $expense = Expense::factory()->for($shift)->for($receptionist)->create([
        'name' => 'Taxi',
        'amount' => 25.00,
    ]);

    Livewire::actingAs($management)
        ->test('pages::management.approvals')
        ->call('setActiveTab', 'expenses')
        ->call('rejectExpense', $expense->id)
        ->assertHasNoErrors();

    expect($expense->fresh()->approval_status)->toBe(ApprovalStatus::Rejected);
    expect($shift->fresh()->totalExpenses())->toBe(0.0)
        ->and($shift->fresh()->expectedCash())->toBe(100.00);
});

test('receptionist cannot approve returns through the action', function () {
    $receptionist = User::factory()->receptionist()->create();
    $shift = Shift::factory()->for($receptionist)->open()->create();
    $invoice = Invoice::factory()->returned()->create([
        'shift_id' => $shift->id,
        'created_by' => $receptionist->id,
        'return_requested_by' => $receptionist->id,
    ]);

    Livewire::actingAs($receptionist)
        ->test('pages::management.approvals')
        ->call('approveReturn', $invoice->id, 'walkin')
        ->assertForbidden();
});
