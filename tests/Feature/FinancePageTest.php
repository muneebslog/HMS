<?php

use App\Enums\FinanceExpenseCategory;
use App\Enums\FinanceShiftPeriod;
use App\Enums\UserRole;
use App\Models\FinanceCashEntry;
use App\Models\FinanceExpense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('admins can visit the finance page', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.finance'))
        ->assertOk();
});

test('non-admin users cannot visit the finance page', function (UserRole $role) {
    $user = User::factory()->{$role->value}()->create();

    $this->actingAs($user)
        ->get(route('admin.finance'))
        ->assertForbidden();
})->with([
    'receptionist' => [UserRole::Receptionist],
    'management' => [UserRole::Management],
    'doctor' => [UserRole::Doctor],
]);

test('admin can create a cash collection entry', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.finance')
        ->call('openCashModal')
        ->set('cashEntryDate', '2026-09-23')
        ->set('cashPeriod', FinanceShiftPeriod::Morning->value)
        ->set('cashAmountCollected', '15000.50')
        ->set('cashAmountShort', '200')
        ->set('cashNotes', 'Morning till count')
        ->call('saveCashEntry')
        ->assertHasNoErrors();

    $entry = FinanceCashEntry::query()->first();

    expect($entry)->not->toBeNull()
        ->and($entry->user_id)->toBe($admin->id)
        ->and($entry->entry_date->toDateString())->toBe('2026-09-23')
        ->and($entry->period)->toBe(FinanceShiftPeriod::Morning)
        ->and($entry->amount_collected)->toBe(15000.50)
        ->and($entry->amount_short)->toBe(200.0)
        ->and($entry->notes)->toBe('Morning till count')
        ->and($entry->netAmount())->toBe(14800.50);
});

test('admin cannot create duplicate cash entry for same date and period', function () {
    $admin = User::factory()->admin()->create();

    FinanceCashEntry::factory()->create([
        'user_id' => $admin->id,
        'entry_date' => '2026-09-23',
        'period' => FinanceShiftPeriod::Evening,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.finance')
        ->call('openCashModal')
        ->set('cashEntryDate', '2026-09-23')
        ->set('cashPeriod', FinanceShiftPeriod::Evening->value)
        ->set('cashAmountCollected', '1000')
        ->set('cashAmountShort', '0')
        ->call('saveCashEntry')
        ->assertHasErrors(['cashPeriod']);

    expect(FinanceCashEntry::query()->count())->toBe(1);
});

test('admin can update and delete a cash entry', function () {
    $admin = User::factory()->admin()->create();
    $entry = FinanceCashEntry::factory()->create([
        'user_id' => $admin->id,
        'entry_date' => now()->toDateString(),
        'period' => FinanceShiftPeriod::Night,
        'amount_collected' => 5000,
        'amount_short' => 100,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.finance')
        ->call('editCashEntry', $entry->id)
        ->set('cashAmountCollected', '5500')
        ->set('cashAmountShort', '50')
        ->call('saveCashEntry')
        ->assertHasNoErrors();

    $entry->refresh();

    expect($entry->amount_collected)->toBe(5500.0)
        ->and($entry->amount_short)->toBe(50.0);

    Livewire::actingAs($admin)
        ->test('pages::admin.finance')
        ->call('deleteCashEntry', $entry->id)
        ->assertHasNoErrors();

    expect(FinanceCashEntry::query()->whereKey($entry->id)->exists())->toBeFalse();
});

test('admin can create update and delete a finance expense', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.finance')
        ->call('setTab', 'expenses')
        ->call('openExpenseModal')
        ->set('expenseName', 'Staff Salaries')
        ->set('expenseCategory', FinanceExpenseCategory::Salary->value)
        ->set('expenseAmount', '75000')
        ->set('expenseDate', now()->toDateString())
        ->set('expenseNotes', 'September payroll')
        ->call('saveExpense')
        ->assertHasNoErrors();

    $expense = FinanceExpense::query()->first();

    expect($expense)->not->toBeNull()
        ->and($expense->user_id)->toBe($admin->id)
        ->and($expense->name)->toBe('Staff Salaries')
        ->and($expense->category)->toBe(FinanceExpenseCategory::Salary)
        ->and($expense->amount)->toBe(75000.0);

    Livewire::actingAs($admin)
        ->test('pages::admin.finance')
        ->call('editExpense', $expense->id)
        ->set('expenseCategory', FinanceExpenseCategory::Other->value)
        ->set('expenseAmount', '80000')
        ->call('saveExpense')
        ->assertHasNoErrors();

    $expense->refresh();

    expect($expense->category)->toBe(FinanceExpenseCategory::Other)
        ->and($expense->amount)->toBe(80000.0);

    Livewire::actingAs($admin)
        ->test('pages::admin.finance')
        ->call('deleteExpense', $expense->id)
        ->assertHasNoErrors();

    expect(FinanceExpense::query()->whereKey($expense->id)->exists())->toBeFalse();
});

test('cash entry and expense forms reject invalid enum values', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.finance')
        ->call('openCashModal')
        ->set('cashEntryDate', now()->toDateString())
        ->set('cashPeriod', 'afternoon')
        ->set('cashAmountCollected', '100')
        ->set('cashAmountShort', '0')
        ->call('saveCashEntry')
        ->assertHasErrors(['cashPeriod']);

    Livewire::actingAs($admin)
        ->test('pages::admin.finance')
        ->call('openExpenseModal')
        ->set('expenseName', 'Rent')
        ->set('expenseCategory', 'utilities')
        ->set('expenseAmount', '100')
        ->set('expenseDate', now()->toDateString())
        ->call('saveExpense')
        ->assertHasErrors(['expenseCategory']);
});

test('cash summary totals collected short and net for the selected month', function () {
    $admin = User::factory()->admin()->create();

    FinanceCashEntry::factory()->create([
        'user_id' => $admin->id,
        'entry_date' => '2026-09-10',
        'period' => FinanceShiftPeriod::Morning,
        'amount_collected' => 1000,
        'amount_short' => 50,
    ]);

    FinanceCashEntry::factory()->create([
        'user_id' => $admin->id,
        'entry_date' => '2026-09-10',
        'period' => FinanceShiftPeriod::Evening,
        'amount_collected' => 2000,
        'amount_short' => 100,
    ]);

    FinanceCashEntry::factory()->create([
        'user_id' => $admin->id,
        'entry_date' => '2026-08-10',
        'period' => FinanceShiftPeriod::Morning,
        'amount_collected' => 9999,
        'amount_short' => 0,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.finance')
        ->set('month', 9)
        ->set('year', 2026)
        ->assertSet('cashSummary.collected', 3000.0)
        ->assertSet('cashSummary.short', 150.0)
        ->assertSet('cashSummary.net', 2850.0);
});
