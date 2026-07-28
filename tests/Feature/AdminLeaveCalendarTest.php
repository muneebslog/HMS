<?php

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('admin can visit the leave calendar page', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.leave-calendar'))
        ->assertOk()
        ->assertSee('Leave Calendar');
});

test('non-admin users cannot visit the leave calendar page', function (UserRole $role) {
    $user = User::factory()->{$role->value}()->create();

    $this->actingAs($user)
        ->get(route('admin.leave-calendar'))
        ->assertForbidden();
})->with([
    'receptionist' => [UserRole::Receptionist],
    'management' => [UserRole::Management],
    'doctor' => [UserRole::Doctor],
    'supervisor' => [UserRole::Supervisor],
]);

test('users with the default user role are redirected to the pending role page', function () {
    $user = User::factory()->user()->create();

    $this->actingAs($user)
        ->get(route('admin.leave-calendar'))
        ->assertRedirect(route('pending-role'));
});

test('guests are redirected to the login page', function () {
    $this->get(route('admin.leave-calendar'))
        ->assertRedirect(route('login'));
});

test('admin can create a leave entry for a date', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create();
    $replacement = Employee::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.leave-calendar')
        ->call('selectDate', '2026-07-15')
        ->set('employeeId', $employee->id)
        ->set('replacementEmployeeId', $replacement->id)
        ->set('dutyStartTime', '09:00')
        ->set('dutyEndTime', '17:00')
        ->set('isInformed', true)
        ->set('informedBy', 'Manager A')
        ->set('notes', 'Covering morning shift')
        ->call('saveLeave')
        ->assertHasNoErrors();

    $leave = EmployeeLeave::first();

    expect($leave)->not->toBeNull()
        ->and($leave->employee_id)->toBe($employee->id)
        ->and($leave->leave_date->format('Y-m-d'))->toBe('2026-07-15')
        ->and($leave->replacement_employee_id)->toBe($replacement->id)
        ->and($leave->duty_start_time->format('H:i:s'))->toBe('09:00:00')
        ->and($leave->duty_end_time->format('H:i:s'))->toBe('17:00:00')
        ->and($leave->is_informed)->toBeTrue()
        ->and($leave->informed_by)->toBe('Manager A')
        ->and($leave->notes)->toBe('Covering morning shift')
        ->and($leave->created_by)->toBe($admin->id);
});

test('admin cannot create duplicate leave entries for the same employee and date', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create();

    EmployeeLeave::factory()->create([
        'employee_id' => $employee->id,
        'leave_date' => '2026-07-15',
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.leave-calendar')
        ->call('selectDate', '2026-07-15')
        ->set('employeeId', $employee->id)
        ->call('saveLeave')
        ->assertHasErrors(['employeeId']);

    expect(EmployeeLeave::count())->toBe(1);
});

test('admin can update a leave entry', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create();
    $replacement = Employee::factory()->create();
    $leave = EmployeeLeave::factory()->create([
        'employee_id' => $employee->id,
        'leave_date' => '2026-07-15',
        'replacement_employee_id' => null,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.leave-calendar')
        ->call('selectDate', '2026-07-15')
        ->call('editLeave', $leave->id)
        ->set('replacementEmployeeId', $replacement->id)
        ->set('isInformed', true)
        ->set('informedBy', 'HR')
        ->call('saveLeave')
        ->assertHasNoErrors();

    $leave->refresh();

    expect($leave->replacement_employee_id)->toBe($replacement->id)
        ->and($leave->is_informed)->toBeTrue()
        ->and($leave->informed_by)->toBe('HR');
});

test('admin can delete a leave entry', function () {
    $admin = User::factory()->admin()->create();
    $leave = EmployeeLeave::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.leave-calendar')
        ->call('selectDate', $leave->leave_date->format('Y-m-d'))
        ->call('confirmDelete', $leave->id)
        ->call('deleteLeave')
        ->assertHasNoErrors();

    expect(EmployeeLeave::find($leave->id))->toBeNull();
});

test('calendar displays leave entries for the selected date', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create(['name' => 'Ali Khan']);
    EmployeeLeave::factory()->create([
        'employee_id' => $employee->id,
        'leave_date' => '2026-07-15',
    ]);

    Livewire::actingAs($admin)
        ->test('pages::admin.leave-calendar')
        ->call('selectDate', '2026-07-15')
        ->assertSee('Ali Khan');
});

test('leave entry cannot be replaced by the same employee', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.leave-calendar')
        ->call('selectDate', '2026-07-15')
        ->set('employeeId', $employee->id)
        ->set('replacementEmployeeId', $employee->id)
        ->call('saveLeave')
        ->assertHasErrors(['replacementEmployeeId']);

    expect(EmployeeLeave::count())->toBe(0);
});
