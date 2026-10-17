<?php

use App\Enums\LeaveKind;
use App\Enums\StaffCategory;
use App\Enums\UserRole;
use App\Models\AppSetting;
use App\Models\AttendanceDayReview;
use App\Models\AttendanceDeviceUser;
use App\Models\AttendancePunch;
use App\Models\DutySession;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\StaffLeave;
use App\Models\User;
use App\Services\AttendanceSheetService;
use App\Services\DutyPairingService;
use App\Services\PageAccessService;
use App\Services\StaffLeaveService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function recordLeave(Employee $employee, string $from, ?string $to = null, LeaveKind $kind = LeaveKind::Informed, bool $halfDay = false, bool $forcePaid = false): void
{
    app(StaffLeaveService::class)->record(
        $employee,
        CarbonImmutable::parse($from),
        CarbonImmutable::parse($to ?? $from),
        $kind,
        $halfDay,
        null,
        $forcePaid,
        User::query()->first() ?? User::factory()->admin()->create(),
    );
}

/**
 * @return array<string, float>
 */
function paidByDate(Employee $employee): array
{
    return $employee->leaves()->orderBy('leave_date')->get()
        ->mapWithKeys(fn (StaffLeave $leave) => [$leave->leave_date->toDateString() => $leave->paid_days])
        ->all();
}

test('two informed leave days a month are paid and the third is deducted', function () {
    $employee = Employee::factory()->create();

    recordLeave($employee, '2026-10-09', '2026-10-11');

    expect(paidByDate($employee))->toBe(['2026-10-09' => 1.0, '2026-10-10' => 1.0, '2026-10-11' => 0.0]);
});

test('the allowance resets every month', function () {
    $employee = Employee::factory()->create();

    recordLeave($employee, '2026-09-29', '2026-10-02');

    expect(paidByDate($employee))->toBe(['2026-09-29' => 1.0, '2026-09-30' => 1.0, '2026-10-01' => 1.0, '2026-10-02' => 1.0]);
});

test('uninformed absences are never paid and do not use the allowance', function () {
    $employee = Employee::factory()->create();

    recordLeave($employee, '2026-10-04', kind: LeaveKind::Uninformed);
    recordLeave($employee, '2026-10-07', '2026-10-08');

    expect(paidByDate($employee))->toBe(['2026-10-04' => 0.0, '2026-10-07' => 1.0, '2026-10-08' => 1.0]);
});

test('half days use half the allowance and can be partly paid', function () {
    $employee = Employee::factory()->create();

    recordLeave($employee, '2026-10-02', halfDay: true);
    recordLeave($employee, '2026-10-05');
    recordLeave($employee, '2026-10-09');

    expect(paidByDate($employee))->toBe(['2026-10-02' => 0.5, '2026-10-05' => 1.0, '2026-10-09' => 0.5])
        ->and(app(StaffLeaveService::class)->allowanceLeft($employee, CarbonImmutable::parse('2026-10-20')))->toBe(0.0);
});

test('an earlier leave added later takes the allowance in date order', function () {
    $employee = Employee::factory()->create();

    recordLeave($employee, '2026-10-20', '2026-10-21');
    recordLeave($employee, '2026-10-03');

    expect(paidByDate($employee))->toBe(['2026-10-03' => 1.0, '2026-10-20' => 1.0, '2026-10-21' => 0.0]);
});

test('removing a leave frees the allowance for the next one', function () {
    $employee = Employee::factory()->create();
    recordLeave($employee, '2026-10-09', '2026-10-11');

    app(StaffLeaveService::class)->delete($employee->leaves()->orderBy('leave_date')->first());

    expect(paidByDate($employee))->toBe(['2026-10-10' => 1.0, '2026-10-11' => 1.0]);
});

test('admin override pays a leave beyond the allowance', function () {
    $employee = Employee::factory()->create();

    recordLeave($employee, '2026-10-09', '2026-10-10');
    recordLeave($employee, '2026-10-11', forcePaid: true);

    expect(paidByDate($employee)['2026-10-11'])->toBe(1.0);
});

test('the allowance follows the setting', function () {
    AppSetting::set(AppSetting::PaidLeavesPerMonth, '1');
    $employee = Employee::factory()->create();

    recordLeave($employee, '2026-10-09', '2026-10-10');

    expect(paidByDate($employee))->toBe(['2026-10-09' => 1.0, '2026-10-10' => 0.0]);
});

test('holidays are skipped when leave spans them', function () {
    Holiday::factory()->create(['holiday_date' => '2026-10-10', 'name' => 'Test holiday']);
    $employee = Employee::factory()->create();

    recordLeave($employee, '2026-10-09', '2026-10-11');

    expect(array_keys(paidByDate($employee)))->toBe(['2026-10-09', '2026-10-11']);
});

test('leave cannot be recorded twice for a day or on a day with a confirmed duty', function () {
    $employee = Employee::factory()->create();
    recordLeave($employee, '2026-10-09');
    DutySession::factory()->create(['employee_id' => $employee->id, 'duty_date' => '2026-10-12', 'starts_at' => '2026-10-12 08:00', 'ends_at' => '2026-10-12 15:00']);

    expect(fn () => recordLeave($employee, '2026-10-08', '2026-10-09'))->toThrow(ValidationException::class)
        ->and(fn () => recordLeave($employee, '2026-10-12'))->toThrow(ValidationException::class)
        ->and(fn () => recordLeave($employee, '2026-10-12', halfDay: true))->not->toThrow(ValidationException::class);
});

test('a half day covers only one date', function () {
    expect(fn () => recordLeave(Employee::factory()->create(), '2026-10-09', '2026-10-10', halfDay: true))->toThrow(ValidationException::class);
});

test('the pairing desk refuses a duty on a full day of leave', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create();
    recordLeave($employee, '2026-10-09');

    expect(fn () => app(DutyPairingService::class)->confirm($employee, [
        'starts_at' => CarbonImmutable::parse('2026-10-09 08:00'),
        'ends_at' => CarbonImmutable::parse('2026-10-09 15:00'),
        'in_punch_id' => null, 'out_punch_id' => null, 'duty_template_ids' => [null],
    ], $admin))->toThrow(ValidationException::class);
});

test('leaves page records leave and warns when it will be deducted', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create(['name' => 'Bilal Ahmed']);
    recordLeave($employee, '2026-10-09', '2026-10-10');

    Livewire::actingAs($admin)
        ->test('pages::hr.leaves', ['month' => '2026-10'])
        ->set('employeeId', (string) $employee->id)
        ->set('from', '2026-10-11')
        ->assertSee('0 paid leave left in October.')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Deducted 1 day');

    expect($employee->leaves()->count())->toBe(3);
});

test('leaves page shows validation errors from the service', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create();
    recordLeave($employee, '2026-10-09');

    Livewire::actingAs($admin)
        ->test('pages::hr.leaves', ['month' => '2026-10'])
        ->set('employeeId', (string) $employee->id)
        ->set('from', '2026-10-09')
        ->set('to', '2026-10-09')
        ->call('save')
        ->assertHasErrors('from');
});

test('pairing desk marks a person without punches absent or on leave', function () {
    $admin = User::factory()->admin()->create();
    $sana = Employee::factory()->create(['name' => 'Sana Javed']);
    $hina = Employee::factory()->create(['name' => 'Hina Tariq']);

    $component = Livewire::actingAs($admin)
        ->test('pages::hr.pairing', ['date' => '2026-10-15'])
        ->assertSee('No punches (2)')
        ->call('recordDayOff', $sana->id, 'uninformed')
        ->call('recordDayOff', $hina->id, 'informed')
        ->assertSee('On leave (2)');

    expect($sana->leaves()->first()->kind)->toBe(LeaveKind::Uninformed)
        ->and($hina->leaves()->first()->paid_days)->toBe(1.0);

    $component->call('removeDayOff', $sana->leaves()->first()->id)->assertSee('No punches (1)');
});

test('admin can add and remove a public holiday', function () {
    $admin = User::factory()->admin()->create();

    $component = Livewire::actingAs($admin)
        ->test('pages::hr.settings')
        ->set('holidayDate', '2026-12-25')
        ->set('holidayName', 'Quaid Day')
        ->call('addHoliday')
        ->assertHasNoErrors()
        ->assertSee('Quaid Day');

    $component->set('holidayDate', '2026-12-25')->set('holidayName', 'Again')->call('addHoliday')->assertHasErrors('holidayDate');

    $component->call('deleteHoliday', Holiday::query()->value('id'));

    expect(Holiday::query()->count())->toBe(0);
});

test('the monthly sheet shows duties, leaves, holidays and totals', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00'));
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create(['name' => 'Minahil Khan', 'staff_category' => StaffCategory::HealthAide]);
    $deviceUser = AttendanceDeviceUser::factory()->linked($employee)->create();
    $pairing = app(DutyPairingService::class);

    foreach ([['2026-10-01 08:20', '2026-10-01 15:00'], ['2026-10-02 08:00', '2026-10-03 08:00']] as [$in, $out]) {
        AttendancePunch::factory()->create(['attendance_device_user_id' => $deviceUser->id, 'punched_at' => $in]);
        AttendancePunch::factory()->create(['attendance_device_user_id' => $deviceUser->id, 'punched_at' => $out]);
    }

    $pairing->confirmCleanSuggestions(CarbonImmutable::parse('2026-10-01'), $admin);
    $pairing->confirmSuggestion($employee, $pairing->suggestionsFor($employee, CarbonImmutable::parse('2026-10-02'))[0], $admin);
    AttendanceDayReview::factory()->create(['review_date' => '2026-10-04']);
    Holiday::factory()->create(['holiday_date' => '2026-10-06', 'name' => 'Test holiday']);
    recordLeave($employee, '2026-10-07', '2026-10-09');
    recordLeave($employee, '2026-10-10', kind: LeaveKind::Uninformed);

    $sheet = app(AttendanceSheetService::class)->month(CarbonImmutable::parse('2026-10-01'));
    $row = $sheet['rows']->first();
    $codes = collect($row['cells'])->pluck('code')->all();

    expect($sheet['days'])->toHaveCount(31)
        ->and(array_slice($codes, 0, 10))->toBe(['M', '3', '?', '–', '?', 'H', 'L', 'L', 'U', 'A'])
        ->and($codes[25])->toBe('')
        ->and($row['cells'][0]['late'])->toBeTrue()
        ->and($row['duties'])->toBe(4)
        ->and($row['paid_leave'])->toBe(2.0)
        ->and($row['unpaid_leave'])->toBe(1.0)
        ->and($row['absent'])->toBe(1.0)
        ->and($row['late'])->toBe(1)
        ->and($row['expected'])->toBe(31);

    Livewire::actingAs($admin)
        ->test('pages::hr.sheet', ['month' => '2026-10'])
        ->assertSee('Minahil Khan')
        ->assertSeeHtml('4<span class="font-normal text-zinc-500">/31</span>');
});

test('leaves and sheet pages can be granted by page access', function () {
    $nurse = User::factory()->create(['role' => UserRole::InchargeNurse]);

    $this->actingAs($nurse)->get(route('hr.leaves'))->assertForbidden();

    app(PageAccessService::class)->syncForRole(UserRole::InchargeNurse, ['hr.leaves', 'hr.sheet']);

    $this->actingAs($nurse)->get(route('hr.leaves'))->assertOk();
    $this->actingAs($nurse)->get(route('hr.sheet'))->assertOk();
});
