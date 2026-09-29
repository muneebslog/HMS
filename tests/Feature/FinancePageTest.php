<?php

use App\Actions\SettleShift;
use App\Actions\UpdateShiftSettlement;
use App\Enums\ApprovalStatus;
use App\Enums\FinanceExpenseCategory;
use App\Enums\FinanceShiftPeriod;
use App\Enums\PaymentMode;
use App\Enums\UserRole;
use App\Models\Expense;
use App\Models\FinanceExpense;
use App\Models\Invoice;
use App\Models\ProcedurePayment;
use App\Models\Shift;
use App\Models\ShiftSettlement;
use App\Models\User;
use Database\Seeders\RolePagePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePagePermissionSeeder::class);
});

test('admins can visit the finance page', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.finance'))
        ->assertOk();
});

test('management users can visit the finance page', function () {
    $user = User::factory()->management()->create();

    $this->actingAs($user)
        ->get(route('admin.finance'))
        ->assertOk();
});

test('non-privileged users cannot visit the finance page', function (UserRole $role) {
    $user = User::factory()->{$role->value}()->create();

    $this->actingAs($user)
        ->get(route('admin.finance'))
        ->assertForbidden();
})->with([
    'receptionist' => [UserRole::Receptionist],
    'doctor' => [UserRole::Doctor],
]);

test('finance appears in the sidebar for admin and management', function (string $factory) {
    $user = User::factory()->{$factory}()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee(__('Finance'))
        ->assertSee(route('admin.finance', absolute: false), false);
})->with([
    'admin' => ['admin'],
    'management' => ['management'],
]);

test('finance does not appear in the sidebar for receptionists', function () {
    $user = User::factory()->receptionist()->create();

    $html = $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->getContent();

    expect(str_contains($html, 'href="'.route('admin.finance').'"'))->toBeFalse();
});

test('shifts are grouped into night, morning and evening for a business date', function () {
    $night = Shift::factory()->create(['opened_at' => '2026-09-28 20:35:00', 'closed_at' => '2026-09-29 05:55:00']);
    $morning = Shift::factory()->create(['opened_at' => '2026-09-29 05:57:00', 'closed_at' => '2026-09-29 15:03:00']);
    $evening = Shift::factory()->create(['opened_at' => '2026-09-29 15:03:30', 'closed_at' => '2026-09-29 20:34:00']);
    $nextNight = Shift::factory()->open()->create(['opened_at' => '2026-09-29 20:35:00']);

    expect($night->period())->toBe(FinanceShiftPeriod::Night)
        ->and($night->businessDate()->toDateString())->toBe('2026-09-29')
        ->and($morning->period())->toBe(FinanceShiftPeriod::Morning)
        ->and($evening->period())->toBe(FinanceShiftPeriod::Evening)
        ->and($nextNight->businessDate()->toDateString())->toBe('2026-09-30');

    $groups = Livewire::actingAs(User::factory()->management()->create())
        ->test('pages::admin.finance')
        ->set('date', '2026-09-29')
        ->instance()
        ->shiftsByPeriod;

    expect($groups->keys()->all())->toBe(['night', 'morning', 'evening'])
        ->and($groups['night']->pluck('id')->all())->toBe([$night->id])
        ->and($groups['morning']->pluck('id')->all())->toBe([$morning->id])
        ->and($groups['evening']->pluck('id')->all())->toBe([$evening->id]);
});

test('management can approve all pending expenses and returns on a shift', function () {
    $manager = User::factory()->management()->create();
    $shift = Shift::factory()->closed()->create();
    $otherShift = Shift::factory()->closed()->create();

    $expenses = Expense::factory()->count(2)->for($shift)->create();
    $otherExpense = Expense::factory()->for($otherShift)->create();
    $invoice = Invoice::factory()->returned()->create(['shift_id' => $shift->id]);
    $payment = ProcedurePayment::factory()->returned()->create(['shift_id' => $shift->id]);

    Livewire::actingAs($manager)
        ->test('pages::admin.finance')
        ->call('selectShift', $shift->id)
        ->assertSet('selectedPendingCount', 4)
        ->call('approveAll')
        ->assertSet('selectedPendingCount', 0);

    expect($expenses->every(fn (Expense $expense) => $expense->refresh()->approval_status === ApprovalStatus::Approved))->toBeTrue()
        ->and($invoice->refresh()->return_approval_status)->toBe(ApprovalStatus::Approved)
        ->and($payment->refresh()->return_approval_status)->toBe(ApprovalStatus::Approved)
        ->and($otherExpense->refresh()->approval_status)->toBe(ApprovalStatus::Pending);
});

test('declining an expense removes it from the expected cash', function () {
    $manager = User::factory()->management()->create();
    $shift = Shift::factory()->closed()->create(['opening_balance' => 5000]);
    $expense = Expense::factory()->for($shift)->create(['amount' => 300]);

    Livewire::actingAs($manager)
        ->test('pages::admin.finance')
        ->call('selectShift', $shift->id)
        ->assertSet('shiftSummary.expected', 4700.0)
        ->call('declineExpense', $expense->id)
        ->assertSet('shiftSummary.expected', 5000.0);

    expect($expense->refresh()->approval_status)->toBe(ApprovalStatus::Rejected);
});

test('a shift cannot be settled while items are pending', function () {
    $manager = User::factory()->management()->create();
    $shift = Shift::factory()->closed()->create();
    Expense::factory()->for($shift)->create();

    Livewire::actingAs($manager)
        ->test('pages::admin.finance')
        ->call('selectShift', $shift->id)
        ->set('receivedAmount', '1000')
        ->call('settle');

    expect(ShiftSettlement::query()->count())->toBe(0);
});

test('an open shift cannot be settled', function () {
    $manager = User::factory()->management()->create();
    $shift = Shift::factory()->open()->create();

    expect(fn () => app(SettleShift::class)->handle($manager, $shift, 1000))
        ->toThrow(InvalidArgumentException::class);
});

test('receiving cash settles and locks the shift including the float', function () {
    $manager = User::factory()->management()->create();
    $shift = Shift::factory()->closed()->create([
        'opened_at' => '2026-09-29 06:00:00',
        'opening_balance' => 5000,
        'closing_balance' => 14800,
    ]);
    Invoice::factory()->paid()->create(['shift_id' => $shift->id, 'total' => 10000]);
    Invoice::factory()->paid()->create(['shift_id' => $shift->id, 'total' => 700, 'payment_mode' => PaymentMode::Online->value]);
    $expense = Expense::factory()->for($shift)->approved()->create(['amount' => 200]);

    Livewire::actingAs($manager)
        ->test('pages::admin.finance')
        ->call('selectShift', $shift->id)
        ->set('receivedAmount', '14750')
        ->set('settlementNotes', 'Counted twice')
        ->call('settle')
        ->assertHasNoErrors();

    $settlement = $shift->refresh()->settlement;

    expect($settlement)->not->toBeNull()
        ->and($settlement->settled_by)->toBe($manager->id)
        ->and($settlement->business_date->toDateString())->toBe('2026-09-29')
        ->and($settlement->period)->toBe(FinanceShiftPeriod::Morning)
        ->and($settlement->expected_amount)->toBe(14800.0)
        ->and($settlement->online_sales)->toBe(700.0)
        ->and($settlement->received_amount)->toBe(14750.0)
        ->and($settlement->difference)->toBe(-50.0)
        ->and($settlement->isShort())->toBeTrue()
        ->and($settlement->notes)->toBe('Counted twice');

    expect(fn () => app(SettleShift::class)->handle($manager, $shift, 99999))
        ->toThrow(InvalidArgumentException::class);

    Livewire::actingAs($manager)
        ->test('pages::admin.finance')
        ->call('selectShift', $shift->id)
        ->call('declineExpense', $expense->id);

    expect($expense->refresh()->approval_status)->toBe(ApprovalStatus::Approved)
        ->and($shift->settlement()->count())->toBe(1)
        ->and($shift->settlement()->first()->received_amount)->toBe(14750.0);
});

test('settling requires a valid received amount', function () {
    $manager = User::factory()->management()->create();
    $shift = Shift::factory()->closed()->create();

    Livewire::actingAs($manager)
        ->test('pages::admin.finance')
        ->call('selectShift', $shift->id)
        ->set('receivedAmount', '')
        ->call('settle')
        ->assertHasErrors(['receivedAmount' => 'required'])
        ->set('receivedAmount', '-5')
        ->call('settle')
        ->assertHasErrors(['receivedAmount' => 'min']);

    expect(ShiftSettlement::query()->count())->toBe(0);
});

test('shifts before the finance tracking cutoff cannot be approved or settled', function () {
    config(['hospital.finance.tracking_started_at' => '2026-09-22']);

    $manager = User::factory()->management()->create();
    $oldShift = Shift::factory()->closed()->create(['opened_at' => '2026-09-21 06:00:00']);
    $firstNight = Shift::factory()->closed()->create(['opened_at' => '2026-09-21 20:30:00']);
    $expense = Expense::factory()->for($oldShift)->create();

    expect($oldShift->isBeforeFinanceTracking())->toBeTrue()
        ->and($firstNight->isBeforeFinanceTracking())->toBeFalse();

    Livewire::actingAs($manager)
        ->test('pages::admin.finance')
        ->set('date', '2026-09-21')
        ->assertSee(__('Before tracking'))
        ->call('selectShift', $oldShift->id)
        ->call('approveExpense', $expense->id)
        ->call('approveAll');

    expect($expense->refresh()->approval_status)->toBe(ApprovalStatus::Pending);

    Expense::query()->delete();

    expect(fn () => app(SettleShift::class)->handle($manager, $oldShift, 100))
        ->toThrow(InvalidArgumentException::class);

    app(SettleShift::class)->handle($manager, $firstNight, 100);

    expect($firstNight->settlement()->exists())->toBeTrue();
});

test('only admin or management may settle shifts', function () {
    $receptionist = User::factory()->receptionist()->create();
    $shift = Shift::factory()->closed()->create();

    expect(fn () => app(SettleShift::class)->handle($receptionist, $shift, 100))
        ->toThrow(HttpException::class);
});

test('day and month totals include settled cash and hospital expenses', function () {
    $admin = User::factory()->admin()->create();

    ShiftSettlement::factory()->create(['business_date' => '2026-09-10', 'expected_amount' => 1000, 'received_amount' => 950, 'difference' => -50]);
    ShiftSettlement::factory()->create(['business_date' => '2026-09-12', 'expected_amount' => 2000, 'received_amount' => 2000, 'difference' => 0]);
    ShiftSettlement::factory()->create(['business_date' => '2026-08-10', 'expected_amount' => 9999, 'received_amount' => 9999, 'difference' => 0]);
    FinanceExpense::factory()->create(['expense_date' => '2026-09-12', 'amount' => 500, 'name' => 'Electricity bill']);
    FinanceExpense::factory()->create(['expense_date' => '2026-09-20', 'amount' => 300]);

    Livewire::actingAs($admin)
        ->test('pages::admin.finance')
        ->set('date', '2026-09-12')
        ->assertSet('monthOverview.received', 2950.0)
        ->assertSet('monthOverview.difference', -50.0)
        ->assertSet('monthOverview.expenses', 800.0)
        ->assertSet('monthOverview.net', 2150.0)
        ->assertSet('dayTotals.expenses', 500.0)
        ->assertSee('Electricity bill');
});

test('admin can correct a settled shift but management cannot', function () {
    $admin = User::factory()->admin()->create();
    $manager = User::factory()->management()->create();
    $settlement = ShiftSettlement::factory()->create(['expected_amount' => 1000, 'received_amount' => 900, 'difference' => -100]);

    Livewire::actingAs($manager)
        ->test('pages::admin.finance')
        ->call('selectShift', $settlement->shift_id)
        ->call('startEditingSettlement')
        ->assertSet('editingSettlement', false);

    expect(fn () => app(UpdateShiftSettlement::class)->handle($manager, $settlement, 1000))
        ->toThrow(HttpException::class);

    Livewire::actingAs($admin)
        ->test('pages::admin.finance')
        ->call('selectShift', $settlement->shift_id)
        ->call('startEditingSettlement')
        ->assertSet('editingSettlement', true)
        ->assertSet('editReceivedAmount', '900.00')
        ->set('editReceivedAmount', '1050')
        ->set('editSettlementNotes', 'Found 150 in second envelope')
        ->call('saveSettlementEdit')
        ->assertHasNoErrors()
        ->assertSet('editingSettlement', false);

    $settlement->refresh();

    expect($settlement->received_amount)->toBe(1050.0)
        ->and($settlement->difference)->toBe(50.0)
        ->and($settlement->previous_received_amount)->toBe(900.0)
        ->and($settlement->edited_by)->toBe($admin->id)
        ->and($settlement->wasEdited())->toBeTrue()
        ->and($settlement->notes)->toBe('Found 150 in second envelope');
});

test('new expenses default to the picked date', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.finance')
        ->set('date', '2026-09-24')
        ->call('openExpenseModal')
        ->assertSet('expenseDate', '2026-09-24')
        ->set('expenseName', 'Generator diesel')
        ->set('expenseCategory', FinanceExpenseCategory::Other->value)
        ->set('expenseAmount', '2500')
        ->call('saveExpense')
        ->assertHasNoErrors()
        ->assertSet('dayTotals.expenses', 2500.0);

    expect(FinanceExpense::query()->first()->expense_date->toDateString())->toBe('2026-09-24');
});

test('admin can create update and delete a finance expense', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.finance')
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

test('expense form rejects invalid categories', function () {
    $admin = User::factory()->admin()->create();

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
