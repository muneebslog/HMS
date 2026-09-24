<?php

use App\Actions\UpdateExpense;
use App\Enums\ApprovalStatus;
use App\Models\Expense;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->receptionist = User::factory()->receptionist()->create();
    $this->shift = Shift::factory()->for($this->receptionist)->open()->create();
});

test('reception edits an approved expense and it goes back for approval', function () {
    $expense = Expense::factory()->for($this->shift)->for($this->receptionist)->approved()->create(['name' => 'Stationery', 'amount' => 500]);

    Livewire::actingAs($this->receptionist)
        ->test('pages::reception.shift')
        ->call('setActiveTab', 'expenses')
        ->call('editExpense', $expense->id)
        ->assertSet('showEditExpenseModal', true)
        ->assertSet('editExpenseName', 'Stationery')
        ->set('editExpenseName', 'Stationery and toner')
        ->set('editExpenseAmount', '650')
        ->call('updateExpense')
        ->assertHasNoErrors()
        ->assertSet('showEditExpenseModal', false)
        ->assertSee('was Stationery, 500.00');

    expect($expense->fresh())
        ->name->toBe('Stationery and toner')
        ->amount->toBe(650.0)
        ->approval_status->toBe(ApprovalStatus::Pending)
        ->reviewed_by->toBeNull()
        ->previous_name->toBe('Stationery')
        ->previous_amount->toBe(500.0)
        ->edited_by->toBe($this->receptionist->id);
});

test('a rejected expense can be corrected and resubmitted', function () {
    $expense = Expense::factory()->for($this->shift)->for($this->receptionist)->rejected()->create(['name' => 'Tea', 'amount' => 900]);

    Livewire::actingAs($this->receptionist)
        ->test('pages::reception.shift')
        ->call('editExpense', $expense->id)
        ->set('editExpenseAmount', '90')
        ->call('updateExpense');

    expect($expense->fresh())
        ->amount->toBe(90.0)
        ->approval_status->toBe(ApprovalStatus::Pending)
        ->review_note->toBeNull()
        ->and($this->shift->fresh()->totalExpenses())->toBe(90.0);
});

test('editing twice before approval keeps the original values for the approver', function () {
    $expense = Expense::factory()->for($this->shift)->for($this->receptionist)->create(['name' => 'Fuel', 'amount' => 1000]);

    $page = Livewire::actingAs($this->receptionist)->test('pages::reception.shift');
    $page->call('editExpense', $expense->id)->set('editExpenseAmount', '1200')->call('updateExpense');
    $page->call('editExpense', $expense->id)->set('editExpenseAmount', '1500')->call('updateExpense');

    expect($expense->fresh())
        ->amount->toBe(1500.0)
        ->previous_name->toBe('Fuel')
        ->previous_amount->toBe(1000.0);
});

test('saving without changes leaves the approval alone', function () {
    $expense = Expense::factory()->for($this->shift)->for($this->receptionist)->approved()->create(['name' => 'Water', 'amount' => 200]);

    Livewire::actingAs($this->receptionist)
        ->test('pages::reception.shift')
        ->call('editExpense', $expense->id)
        ->call('updateExpense');

    expect($expense->fresh())
        ->approval_status->toBe(ApprovalStatus::Approved)
        ->edited_at->toBeNull();
});

test('expenses from a closed shift cannot be edited', function () {
    $oldShift = Shift::factory()->for($this->receptionist)->create(['status' => 'closed', 'closed_at' => now()->subDay()]);
    $old = Expense::factory()->for($oldShift)->for($this->receptionist)->approved()->create(['name' => 'Old', 'amount' => 100]);

    Livewire::actingAs($this->receptionist)
        ->test('pages::reception.shift')
        ->call('editExpense', $old->id)
        ->assertSet('showEditExpenseModal', false);

    expect(fn () => app(UpdateExpense::class)->handle($this->receptionist, $old, 'Changed', 1))
        ->toThrow(InvalidArgumentException::class);

    expect($old->fresh()->name)->toBe('Old');
});

test('the edit is validated', function () {
    $expense = Expense::factory()->for($this->shift)->for($this->receptionist)->create();

    Livewire::actingAs($this->receptionist)
        ->test('pages::reception.shift')
        ->call('editExpense', $expense->id)
        ->set('editExpenseName', '')
        ->set('editExpenseAmount', '-5')
        ->call('updateExpense')
        ->assertHasErrors(['editExpenseName', 'editExpenseAmount']);
});

test('the approvals page shows what was changed', function () {
    $expense = Expense::factory()->for($this->shift)->for($this->receptionist)->approved()->create(['name' => 'Stationery', 'amount' => 500]);
    app(UpdateExpense::class)->handle($this->receptionist, $expense, 'Printer ink', 650);

    Livewire::actingAs(User::factory()->management()->create())
        ->test('pages::management.approvals')
        ->call('setActiveTab', 'expenses')
        ->assertSee('Printer ink')
        ->assertSee('Edited')
        ->assertSee('was Stationery')
        ->assertSee('650.00')
        ->assertSee('500.00')
        ->call('approveExpense', $expense->id);

    expect($expense->fresh()->approval_status)->toBe(ApprovalStatus::Approved);
});
