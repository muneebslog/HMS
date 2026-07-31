<?php

use App\Enums\UserRole;
use App\Models\Doctor;
use App\Models\DoctorPayout;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LabInvoice;
use App\Models\MonthlyExpense;
use App\Models\ProcedurePayment;
use App\Models\Shift;
use App\Models\User;
use App\Services\MonthlyReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('admins can visit the monthly report page', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.monthly-report'))
        ->assertOk();
});

test('non-admin users cannot visit the monthly report page', function (UserRole $role) {
    $user = User::factory()->{$role->value}()->create();

    $this->actingAs($user)
        ->get(route('admin.monthly-report'))
        ->assertForbidden();
})->with([
    'receptionist' => [UserRole::Receptionist],
    'management' => [UserRole::Management],
    'doctor' => [UserRole::Doctor],
]);

test('monthly report aggregates income expenses and hospital net for the selected month', function () {
    $admin = User::factory()->admin()->create();
    $shift = Shift::factory()->for($admin)->closed()->create();

    Invoice::factory()->create([
        'created_by' => $admin->id,
        'shift_id' => $shift->id,
        'status' => 'paid',
        'total' => 1000,
        'created_at' => Carbon::parse('2026-07-10 10:00:00'),
    ]);

    Invoice::factory()->cancelled()->create([
        'created_by' => $admin->id,
        'shift_id' => $shift->id,
        'total' => 500,
        'created_at' => Carbon::parse('2026-07-11 10:00:00'),
    ]);

    LabInvoice::factory()->create([
        'created_by' => $admin->id,
        'shift_id' => $shift->id,
        'total' => 300,
        'created_at' => Carbon::parse('2026-07-12 10:00:00'),
    ]);

    ProcedurePayment::factory()->create([
        'created_by' => $admin->id,
        'shift_id' => $shift->id,
        'amount' => 200,
        'created_at' => Carbon::parse('2026-07-13 10:00:00'),
    ]);

    Expense::factory()->create([
        'shift_id' => $shift->id,
        'user_id' => $admin->id,
        'amount' => 50,
        'created_at' => Carbon::parse('2026-07-14 10:00:00'),
    ]);

    MonthlyExpense::factory()->create([
        'user_id' => $admin->id,
        'name' => 'Electricity',
        'amount' => 150,
        'expense_date' => '2026-07-15',
    ]);

    DoctorPayout::factory()->create([
        'created_by' => $admin->id,
        'shift_id' => $shift->id,
        'share_amount' => 100,
        'paid_at' => Carbon::parse('2026-07-16 10:00:00'),
    ]);

    $report = app(MonthlyReportService::class)->forMonth(Carbon::parse('2026-07-01'));

    expect($report['receipts_total'])->toBe(1000.0)
        ->and($report['lab_total'])->toBe(300.0)
        ->and($report['procedure_total'])->toBe(200.0)
        ->and($report['total_income'])->toBe(1500.0)
        ->and($report['shift_expenses_total'])->toBe(50.0)
        ->and($report['monthly_expenses_total'])->toBe(150.0)
        ->and($report['doctor_payouts_total'])->toBe(100.0)
        ->and($report['total_outflow'])->toBe(300.0)
        ->and($report['hospital_net'])->toBe(1200.0);
});

test('admins can add monthly expenses that do not affect shift expected cash', function () {
    $admin = User::factory()->admin()->create();
    $shift = Shift::factory()->for($admin)->open()->create([
        'opening_balance' => 100,
    ]);

    $expectedBefore = $shift->expectedCash();

    Livewire::actingAs($admin)
        ->test('pages::admin.monthly-report')
        ->call('openExpenseModal')
        ->set('expenseName', 'Electricity')
        ->set('expenseAmount', '2500')
        ->set('expenseDate', now()->toDateString())
        ->set('expenseNotes', 'July bill')
        ->call('addExpense')
        ->assertHasNoErrors();

    expect(MonthlyExpense::query()->count())->toBe(1)
        ->and(MonthlyExpense::query()->first()->name)->toBe('Electricity')
        ->and(Expense::query()->count())->toBe(0)
        ->and($shift->fresh()->expectedCash())->toBe($expectedBefore);
});

test('admins can delete monthly overhead expenses', function () {
    $admin = User::factory()->admin()->create();
    $expense = MonthlyExpense::factory()->create([
        'user_id' => $admin->id,
        'expense_date' => now()->toDateString(),
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.monthly-report')
        ->call('deleteExpense', $expense->id)
        ->assertHasNoErrors();

    expect(MonthlyExpense::query()->whereKey($expense->id)->exists())->toBeFalse();
});

test('monthly report shows doctor shares accrued from invoice items', function () {
    $admin = User::factory()->admin()->create();
    $doctor = Doctor::factory()->create([
        'get_full_slips' => false,
    ]);
    $shift = Shift::factory()->for($admin)->closed()->create();
    $invoice = Invoice::factory()->create([
        'created_by' => $admin->id,
        'shift_id' => $shift->id,
        'status' => 'paid',
        'total' => 1000,
        'created_at' => now(),
    ]);

    InvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'doctor_id' => $doctor->id,
        'doctor_name' => $doctor->name,
        'price' => 1000,
        'doctor_share' => 40,
        'created_at' => now(),
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.monthly-report')
        ->assertSee($doctor->name)
        ->assertSee(number_format(400, 2));
});
