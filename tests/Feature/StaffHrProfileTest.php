<?php

use App\Enums\StaffCategory;
use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceUser;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\HealthAide;
use App\Models\User;
use App\Services\AttendanceSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('new employees default to non-medical staff who punch the machine', function () {
    $employee = Employee::factory()->create();

    expect($employee->fresh())
        ->staff_category->toBe(StaffCategory::NonMedical)
        ->tracks_attendance->toBeTrue();
});

test('admin can create a doctor profile that does not track attendance', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.employees')
        ->set('name', 'Dr. Farah Naz')
        ->set('staffCategory', StaffCategory::Doctor->value)
        ->assertSet('tracksAttendance', false)
        ->call('saveEmployee')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('employees', [
        'name' => 'Dr. Farah Naz',
        'staff_category' => 'doctor',
        'tracks_attendance' => false,
    ]);
});

test('staff list filters by category and shows counts', function () {
    $admin = User::factory()->admin()->create();
    Employee::factory()->healthAide()->create(['name' => 'Minahil Khan']);
    Employee::factory()->category(StaffCategory::Medical)->create(['name' => 'Asma Bibi']);

    Livewire::actingAs($admin)
        ->test('pages::admin.employees')
        ->assertSee('Health Aide (1)')
        ->set('categoryFilter', StaffCategory::HealthAide->value)
        ->assertSee('Minahil Khan')
        ->assertDontSee('Asma Bibi');
});

test('staff list shows birthdays in the next seven days', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00:00'));
    $admin = User::factory()->admin()->create();
    Employee::factory()->create(['name' => 'Asma Bibi', 'date_of_birth' => '1996-10-17']);
    Employee::factory()->create(['name' => 'Far Away', 'date_of_birth' => '1990-12-01']);

    Livewire::actingAs($admin)
        ->test('pages::admin.employees')
        ->assertSee('Birthdays this week')
        ->assertSee('Asma Bibi — Sat 17 Oct')
        ->assertDontSee('Far Away —');
});

test('next birthday handles year rollover and leap days', function () {
    $employee = Employee::factory()->make(['date_of_birth' => '2000-02-29']);

    expect($employee->nextBirthday(CarbonImmutable::parse('2026-10-16'))->toDateString())->toBe('2027-02-28')
        ->and($employee->nextBirthday(CarbonImmutable::parse('2027-12-31'))->toDateString())->toBe('2028-02-29');
});

test('admin can save attendance settings on the profile', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.employee-profile', ['employee' => $employee])
        ->set('staffCategory', StaffCategory::HealthAide->value)
        ->set('expectedMonthlyDuties', '26')
        ->call('saveAttendanceSettings')
        ->assertHasNoErrors();

    expect($employee->fresh())
        ->staff_category->toBe(StaffCategory::HealthAide)
        ->expected_monthly_duties->toBe(26)
        ->expectedDutiesFor(CarbonImmutable::parse('2026-10-01'))->toBe(26);
});

test('expected duties default to the days in the month', function () {
    $employee = Employee::factory()->create();

    expect($employee->expectedDutiesFor(CarbonImmutable::parse('2026-09-10')))->toBe(30)
        ->and($employee->expectedDutiesFor(CarbonImmutable::parse('2026-10-10')))->toBe(31);
});

test('admin can record salary history and the salary in force is picked by date', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create();

    $component = Livewire::actingAs($admin)
        ->test('pages::admin.employee-profile', ['employee' => $employee]);

    $component->call('openSalaryModal')
        ->set('salaryAmount', '38000')
        ->set('salaryEffectiveFrom', '2023-02-01')
        ->call('saveSalary')
        ->assertHasNoErrors();

    $component->call('openSalaryModal')
        ->set('salaryAmount', '45000')
        ->set('salaryEffectiveFrom', '2026-07-01')
        ->call('saveSalary')
        ->assertHasNoErrors()
        ->assertSee('Rs 45,000');

    $component->call('openSalaryModal')
        ->set('salaryAmount', '1')
        ->set('salaryEffectiveFrom', '2026-07-01')
        ->call('saveSalary')
        ->assertHasErrors('salaryEffectiveFrom');

    expect($employee->salaryOn(CarbonImmutable::parse('2026-06-30'))->monthly_salary)->toBe(38000)
        ->and($employee->salaryOn(CarbonImmutable::parse('2026-07-01'))->monthly_salary)->toBe(45000)
        ->and($employee->salaryOn(CarbonImmutable::parse('2020-01-01')))->toBeNull()
        ->and(EmployeeSalary::query()->where('employee_id', $employee->id)->value('created_by'))->toBe($admin->id);
});

test('admin can link and unlink a machine user from the profile', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create();
    $deviceUser = AttendanceDeviceUser::factory()->create(['name' => 'ASMA']);

    $component = Livewire::actingAs($admin)
        ->test('pages::admin.employee-profile', ['employee' => $employee])
        ->assertSee('ASMA')
        ->call('toggleMachineUser', $deviceUser->id);

    expect($deviceUser->fresh()->employee_id)->toBe($employee->id);

    $component->call('toggleMachineUser', $deviceUser->id);

    expect($deviceUser->fresh()->employee_id)->toBeNull();
});

test('profile does not steal a machine user linked to someone else', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create();
    $deviceUser = AttendanceDeviceUser::factory()->linked()->create();
    $ownerId = $deviceUser->employee_id;

    Livewire::actingAs($admin)
        ->test('pages::admin.employee-profile', ['employee' => $employee])
        ->call('toggleMachineUser', $deviceUser->id);

    expect($deviceUser->fresh()->employee_id)->toBe($ownerId);
});

test('linking moves an employee off their previous machine user on the same device', function () {
    $device = AttendanceDevice::factory()->create();
    $employee = Employee::factory()->create();
    $old = AttendanceDeviceUser::factory()->for($device, 'device')->linked($employee)->create();
    $new = AttendanceDeviceUser::factory()->for($device, 'device')->create();

    app(AttendanceSyncService::class)->link($new, $employee);

    expect($old->fresh()->employee_id)->toBeNull()
        ->and($new->fresh()->employee_id)->toBe($employee->id);
});

test('attendance page links machine users to any staff profile', function () {
    $admin = User::factory()->admin()->create();
    $receptionist = Employee::factory()->create(['name' => 'Ali Raza']);
    Employee::factory()->category(StaffCategory::Doctor)->create(['name' => 'Dr. Not Punching']);
    $deviceUser = AttendanceDeviceUser::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.attendance')
        ->assertSee('Ali Raza — Non-medical')
        ->assertDontSee('Dr. Not Punching')
        ->call('linkEmployee', $deviceUser->id, (string) $receptionist->id);

    expect($deviceUser->fresh()->employee_id)->toBe($receptionist->id);
});

test('creating a health aide also creates its staff profile', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.health-aides')
        ->call('openCreateModal')
        ->set('name', 'Minahil Khan')
        ->set('pin', '4821')
        ->call('saveAide')
        ->assertHasNoErrors();

    $aide = HealthAide::query()->where('name', 'Minahil Khan')->firstOrFail();

    expect($aide->employee)
        ->not->toBeNull()
        ->name->toBe('Minahil Khan')
        ->staff_category->toBe(StaffCategory::HealthAide)
        ->tracks_attendance->toBeTrue()
        ->created_by->toBe($admin->id);
});

test('opening an older aide profile creates it once and redirects there', function () {
    $admin = User::factory()->admin()->create();
    $aide = HealthAide::factory()->create(['name' => 'Bilal Ahmed']);

    Livewire::actingAs($admin)
        ->test('pages::admin.health-aides')
        ->assertSee('Create staff profile')
        ->call('openProfile', $aide->id)
        ->assertRedirect(route('admin.employees.profile', $aide->fresh()->employee_id));

    $aide->refresh()->ensureEmployee($admin->id);

    expect(Employee::query()->where('name', 'Bilal Ahmed')->count())->toBe(1);
});

test('migration gives every health aide a staff profile and carries machine links over', function () {
    $linkMigration = require database_path('migrations/2026_10_16_204349_link_attendance_device_users_to_employees.php');
    $aideMigration = require database_path('migrations/2026_10_16_204348_add_employee_id_to_health_aides_table.php');
    $linkMigration->down();
    $aideMigration->down();

    User::factory()->admin()->create();
    $existing = Employee::factory()->create(['name' => 'Minahil Khan']);
    $minahilId = DB::table('health_aides')->insertGetId(['name' => ' minahil khan ', 'pin' => 'x', 'is_active' => true]);
    $bilalId = DB::table('health_aides')->insertGetId(['name' => 'Bilal Ahmed', 'pin' => 'x', 'is_active' => false]);
    $device = AttendanceDevice::factory()->create();
    $deviceUserId = DB::table('attendance_device_users')->insertGetId([
        'attendance_device_id' => $device->id, 'device_uid' => 31, 'device_user_id' => '31', 'privilege' => 0, 'health_aide_id' => $bilalId,
    ]);
    $punchId = DB::table('attendance_punches')->insertGetId([
        'attendance_device_id' => $device->id, 'attendance_device_user_id' => $deviceUserId, 'device_user_id' => '31', 'punched_at' => '2026-10-15 10:02:14',
    ]);

    $aideMigration->up();
    $linkMigration->up();

    $bilalEmployee = Employee::query()->findOrFail(HealthAide::query()->findOrFail($bilalId)->employee_id);

    expect(HealthAide::query()->findOrFail($minahilId)->employee_id)->toBe($existing->id)
        ->and($existing->fresh()->staff_category)->toBe(StaffCategory::HealthAide)
        ->and($bilalEmployee->name)->toBe('Bilal Ahmed')
        ->and($bilalEmployee->status)->toBe('inactive')
        ->and(AttendanceDeviceUser::query()->findOrFail($deviceUserId)->employee_id)->toBe($bilalEmployee->id)
        ->and(DB::table('attendance_punches')->where('id', $punchId)->value('attendance_device_user_id'))->toBe($deviceUserId);
});

test('health aide profiles show training instead of degrees', function () {
    $admin = User::factory()->admin()->create();
    $aide = Employee::factory()->healthAide()->create();
    $nurse = Employee::factory()->category(StaffCategory::Medical)->create();

    $this->actingAs($admin)->get(route('admin.employees.profile', $aide))->assertSee('Training &amp; Courses', false);
    $this->actingAs($admin)->get(route('admin.employees.profile', $nurse))->assertSee('Degrees &amp; Qualifications', false);
});
