<?php

use App\Enums\UserRole;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\LabInvoice;
use App\Models\LabTest;
use App\Models\Service;
use App\Models\ServicePrice;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('reception.shift'));

    $response->assertRedirect(route('login'));
});

test('authenticated receptionists and management can visit the shift page', function (UserRole $role) {
    $user = User::factory()->{$role->value}()->create();

    $response = $this->actingAs($user)->get(route('reception.shift'));

    $response->assertOk();
})->with([
    'receptionist' => [UserRole::Receptionist],
    'management' => [UserRole::Management],
]);

test('user sees open shift form when no shift is active', function () {
    $user = User::factory()->receptionist()->create();

    Livewire::actingAs($user)
        ->test('pages::reception.shift')
        ->assertSee('Open Shift')
        ->assertDontSee('Close Shift');
});

test('user can open a shift with an opening balance', function () {
    $user = User::factory()->receptionist()->create();

    Livewire::actingAs($user)
        ->test('pages::reception.shift')
        ->set('openingBalance', '500.00')
        ->call('openShift')
        ->assertHasNoErrors();

    $shift = Shift::first();
    expect($shift)->not->toBeNull()
        ->user_id->toBe($user->id)
        ->opening_balance->toBe(500.00)
        ->status->toBe('open')
        ->closed_at->toBeNull();
});

test('opening balance must be a non-negative number', function () {
    $user = User::factory()->receptionist()->create();

    Livewire::actingAs($user)
        ->test('pages::reception.shift')
        ->set('openingBalance', '-10')
        ->call('openShift')
        ->assertHasErrors(['openingBalance']);

    expect(Shift::count())->toBe(0);
});

test('only one open shift can exist at a time', function () {
    $firstUser = User::factory()->receptionist()->create();
    $secondUser = User::factory()->receptionist()->create();
    Shift::factory()->for($firstUser)->open()->create();

    Livewire::actingAs($secondUser)
        ->test('pages::reception.shift')
        ->set('openingBalance', '100.00')
        ->call('openShift')
        ->assertHasNoErrors();

    expect(Shift::where('status', 'open')->count())->toBe(1);
});

test('user can close an open shift with a closing balance', function () {
    $user = User::factory()->receptionist()->create();
    Shift::factory()->for($user)->open()->create([
        'opening_balance' => 200.00,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.shift')
        ->set('closingBalance', '800.00')
        ->call('closeShift')
        ->assertHasNoErrors();

    $shift = Shift::first();
    expect($shift)->not->toBeNull()
        ->status->toBe('closed')
        ->closing_balance->toBe(800.00)
        ->closed_at->not->toBeNull();
});

test('receptionist is redirected to shift page when accessing reception without an open shift', function () {
    $user = User::factory()->receptionist()->create();

    $this->actingAs($user)
        ->get(route('reception.walkin'))
        ->assertRedirect(route('reception.shift'));

    $this->actingAs($user)
        ->get(route('reception.lab-entry'))
        ->assertRedirect(route('reception.shift'));
});

test('management is redirected to shift page when accessing invoices without an open shift', function () {
    $user = User::factory()->management()->create();

    $this->actingAs($user)
        ->get(route('reception.invoices'))
        ->assertRedirect(route('reception.shift'));
});

test('receptionist can access reception pages with an open shift', function () {
    $user = User::factory()->receptionist()->create();
    Shift::factory()->for($user)->open()->create();

    $this->actingAs($user)
        ->get(route('reception.walkin'))
        ->assertOk();

    $this->actingAs($user)
        ->get(route('reception.lab-entry'))
        ->assertOk();
});

test('management can access invoices with an open shift', function () {
    $user = User::factory()->management()->create();
    Shift::factory()->for($user)->open()->create();

    $this->actingAs($user)
        ->get(route('reception.invoices'))
        ->assertOk();
});

test('any authenticated user can use reception pages while a global shift is open', function () {
    $firstUser = User::factory()->receptionist()->create();
    $secondUser = User::factory()->receptionist()->create();
    Shift::factory()->for($firstUser)->open()->create();

    $this->actingAs($secondUser)
        ->get(route('reception.walkin'))
        ->assertOk();
});

test('walk-in invoice is linked to the current shift', function () {
    $user = User::factory()->receptionist()->create();
    $shift = Shift::factory()->for($user)->open()->create();
    $service = Service::factory()->create(['is_standalone' => true]);
    ServicePrice::factory()->create([
        'service_id' => $service->id,
        'doctor_id' => null,
        'price' => 75.00,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.walkin')
        ->set('patientName', 'John Doe')
        ->set('selectedServiceId', $service->id)
        ->call('add')
        ->call('saveInvoice')
        ->assertHasNoErrors();

    $invoice = Invoice::first();
    expect($invoice)->not->toBeNull()
        ->shift_id->toBe($shift->id)
        ->shift->id->toBe($shift->id);
});

test('lab invoice is linked to the current shift', function () {
    $user = User::factory()->receptionist()->create();
    $shift = Shift::factory()->for($user)->open()->create();

    $labTest = LabTest::factory()->create([
        'test_name' => 'Complete Blood Count',
        'test_code' => 'CBC-001',
        'test_price' => 1200.00,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.lab-entry')
        ->set('patientName', 'John Doe')
        ->set('patientPhone', '1234567890')
        ->set('patientGender', 'male')
        ->set('patientAge', 30)
        ->set('selectedLabTestId', $labTest->id)
        ->call('add')
        ->call('save')
        ->assertHasNoErrors();

    $invoice = LabInvoice::first();
    expect($invoice)->not->toBeNull()
        ->shift_id->toBe($shift->id)
        ->shift->id->toBe($shift->id);
});

test('shift summary reflects created invoices', function () {
    $user = User::factory()->receptionist()->create();
    $shift = Shift::factory()->for($user)->open()->create([
        'opening_balance' => 100.00,
    ]);

    Invoice::factory()->create([
        'shift_id' => $shift->id,
        'total' => 150.00,
        'created_by' => $user->id,
    ]);

    LabInvoice::factory()->create([
        'shift_id' => $shift->id,
        'total' => 250.00,
        'created_by' => $user->id,
    ]);

    expect($shift->fresh())
        ->totalWalkInSales()->toBe(150.00)
        ->totalLabSales()->toBe(250.00)
        ->totalSales()->toBe(400.00);
});

test('user can add an expense to an open shift', function () {
    $user = User::factory()->receptionist()->create();
    Shift::factory()->for($user)->open()->create();

    Livewire::actingAs($user)
        ->test('pages::reception.shift')
        ->set('expenseName', 'Stationery')
        ->set('expenseAmount', '50.00')
        ->call('addExpense')
        ->assertHasNoErrors();

    $expense = Expense::first();
    expect($expense)->not->toBeNull()
        ->name->toBe('Stationery')
        ->amount->toBe(50.00)
        ->user_id->toBe($user->id);

    expect($expense->shift)->not->toBeNull();
});

test('expense amount and name are validated', function (string $field, mixed $value, array $errors) {
    $user = User::factory()->receptionist()->create();
    Shift::factory()->for($user)->open()->create();

    Livewire::actingAs($user)
        ->test('pages::reception.shift')
        ->set($field, $value)
        ->call('addExpense')
        ->assertHasErrors($errors);

    expect(Expense::count())->toBe(0);
})->with([
    'empty name' => ['expenseName', '', ['expenseName']],
    'empty amount' => ['expenseAmount', '', ['expenseAmount']],
    'negative amount' => ['expenseAmount', '-10', ['expenseAmount']],
]);

test('user can see logged expenses and their total on the shift page', function () {
    $user = User::factory()->receptionist()->create();
    $shift = Shift::factory()->for($user)->open()->create();

    Expense::factory()->for($shift)->for($user)->create([
        'name' => 'Coffee',
        'amount' => 25.00,
    ]);

    Expense::factory()->for($shift)->for($user)->create([
        'name' => 'Taxi',
        'amount' => 75.00,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.shift')
        ->assertSee('Coffee')
        ->assertSee('25.00')
        ->assertSee('Taxi')
        ->assertSee('75.00')
        ->assertSee('Total Expenses');

    expect($shift->fresh()->totalExpenses())->toBe(100.00);
});

test('shift page shows expected cash reconciliation', function () {
    $user = User::factory()->receptionist()->create();
    $shift = Shift::factory()->for($user)->open()->create([
        'opening_balance' => 1000.00,
    ]);

    Invoice::factory()->create([
        'shift_id' => $shift->id,
        'total' => 500.00,
        'created_by' => $user->id,
    ]);

    LabInvoice::factory()->create([
        'shift_id' => $shift->id,
        'total' => 300.00,
        'created_by' => $user->id,
    ]);

    Expense::factory()->for($shift)->for($user)->create([
        'name' => 'Supplies',
        'amount' => 50.00,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reception.shift')
        ->assertSee(number_format(1000.00, 2))
        ->assertSee(number_format(500.00, 2))
        ->assertSee(number_format(300.00, 2))
        ->assertSee(number_format(50.00, 2))
        ->assertSee(number_format(1750.00, 2));

    expect($shift->fresh()->expectedCash())->toBe(1750.00);
});
