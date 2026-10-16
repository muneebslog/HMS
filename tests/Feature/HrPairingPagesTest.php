<?php

use App\Enums\UserRole;
use App\Models\AppSetting;
use App\Models\AttendanceDayReview;
use App\Models\AttendanceDeviceUser;
use App\Models\AttendancePunch;
use App\Models\DutySession;
use App\Models\Employee;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\PageAccessService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @param  list<string>  $times
 */
function deskStaff(string $name, array $times): Employee
{
    $employee = Employee::factory()->create(['name' => $name]);
    $deviceUser = AttendanceDeviceUser::factory()->linked($employee)->create();

    foreach ($times as $time) {
        AttendancePunch::factory()->create(['attendance_device_user_id' => $deviceUser->id, 'punched_at' => $time]);
    }

    return $employee;
}

test('admin can open the pairing desk, backlog and settings', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('hr.pairing'))->assertOk()->assertSee('Pairing desk');
    $this->actingAs($admin)->get(route('hr.backlog'))->assertOk()->assertSee('Pairing backlog');
    $this->actingAs($admin)->get(route('hr.settings'))->assertOk()->assertSee('Shift templates');
});

test('the pairing desk can be granted to the incharge nurse but settings stay admin only', function () {
    $nurse = User::factory()->create(['role' => UserRole::InchargeNurse]);

    $this->actingAs($nurse)->get(route('hr.pairing'))->assertForbidden();

    app(PageAccessService::class)->syncForRole(UserRole::InchargeNurse, ['hr.pairing', 'hr.backlog', 'hr.settings']);

    $this->actingAs($nurse)->get(route('hr.pairing'))->assertOk();
    $this->actingAs($nurse)->get(route('hr.backlog'))->assertOk();
    $this->actingAs($nurse)->get(route('hr.settings'))->assertForbidden();
});

test('the desk lists suggestions, flags and staff without punches', function () {
    $admin = User::factory()->admin()->create();
    deskStaff('Asma Bibi', ['2026-10-15 07:58:00', '2026-10-15 15:05:00']);
    deskStaff('Bilal Ahmed', ['2026-10-15 22:14:00']);
    deskStaff('Sana Javed', []);

    Livewire::actingAs($admin)
        ->test('pages::hr.pairing', ['date' => '2026-10-15'])
        ->assertSee('Asma Bibi')
        ->assertSee('Suggested (1)')
        ->assertSee('Need attention (1)')
        ->assertSee('Missing out punch')
        ->assertSee('No punches (1)');
});

test('confirming one person stores their session', function () {
    $admin = User::factory()->admin()->create();
    $asma = deskStaff('Asma Bibi', ['2026-10-15 07:58:00', '2026-10-15 15:05:00']);

    Livewire::actingAs($admin)
        ->test('pages::hr.pairing', ['date' => '2026-10-15'])
        ->call('confirmEmployee', $asma->id)
        ->assertSee('Confirmed (1)');

    expect(DutySession::query()->where('employee_id', $asma->id)->first())
        ->duty_date->toDateString()->toBe('2026-10-15')
        ->confirmed_by->toBe($admin->id);
});

test('a missing out time is typed in with a reason and confirmed', function () {
    $admin = User::factory()->admin()->create();
    $bilal = deskStaff('Bilal Ahmed', ['2026-10-15 22:14:00']);

    $component = Livewire::actingAs($admin)
        ->test('pages::hr.pairing', ['date' => '2026-10-15'])
        ->call('openEditor', $bilal->id)
        ->assertSet('drafts.0.ends_at', '')
        ->set('drafts.0.ends_at', '2026-10-16T08:00')
        ->call('suggestDuties', 0)
        ->assertSet('drafts.0.duty_template_ids', [(string) ShiftTemplate::query()->where('name', 'Night')->value('id')])
        ->call('saveDraft', 0)
        ->assertHasErrors('drafts.0.notes');

    $component->set('drafts.0.notes', 'Forgot to punch out')
        ->call('saveDraft', 0)
        ->assertHasNoErrors()
        ->assertSet('editingEmployeeId', null);

    $session = DutySession::query()->where('employee_id', $bilal->id)->firstOrFail();

    expect($session->out_punch_id)->toBeNull()
        ->and($session->in_punch_id)->not->toBeNull()
        ->and($session->ends_at->format('Y-m-d H:i'))->toBe('2026-10-16 08:00')
        ->and($session->notes)->toBe('Forgot to punch out');
});

test('double duty can be confirmed as two chosen shifts', function () {
    $admin = User::factory()->admin()->create();
    $dayShift = ShiftTemplate::factory()->timed('10:00', '22:00')->create(['name' => 'Day 12h']);
    $minahil = deskStaff('Minahil Khan', ['2026-10-15 10:02:00', '2026-10-16 08:11:00']);
    $night = ShiftTemplate::query()->where('name', 'Night')->value('id');

    Livewire::actingAs($admin)
        ->test('pages::hr.pairing', ['date' => '2026-10-15'])
        ->assertSee('Very long — check for double duty')
        ->call('openEditor', $minahil->id)
        ->assertSet('drafts.0.duty_template_ids', [(string) $dayShift->id, (string) $night])
        ->set('drafts.0.notes', 'Night staff absent')
        ->call('saveDraft', 0)
        ->assertHasNoErrors();

    $session = DutySession::query()->where('employee_id', $minahil->id)->firstOrFail();

    expect($session->duties->pluck('shift_template_id')->all())->toBe([$dayShift->id, $night])
        ->and($session->in_punch_id)->not->toBeNull()
        ->and($session->out_punch_id)->not->toBeNull();
});

test('a person with no punches can be added by hand', function () {
    $admin = User::factory()->admin()->create();
    $sana = deskStaff('Sana Javed', []);

    Livewire::actingAs($admin)
        ->test('pages::hr.pairing', ['date' => '2026-10-15'])
        ->call('openEditor', $sana->id)
        ->call('addDraft')
        ->set('drafts.0.starts_at', '2026-10-15T15:00')
        ->set('drafts.0.ends_at', '2026-10-15T22:00')
        ->set('drafts.0.notes', 'Machine was down')
        ->call('saveDraft', 0)
        ->assertHasNoErrors();

    expect(DutySession::query()->where('employee_id', $sana->id)->firstOrFail()->startIsManual())->toBeTrue();
});

test('a stray punch can be ignored from the desk and restored', function () {
    $admin = User::factory()->admin()->create();
    $asma = deskStaff('Asma Bibi', ['2026-10-15 06:00:00', '2026-10-15 08:00:00', '2026-10-15 15:00:00']);
    $stray = AttendancePunch::query()->orderBy('punched_at')->first();

    $component = Livewire::actingAs($admin)
        ->test('pages::hr.pairing', ['date' => '2026-10-15'])
        ->call('openEditor', $asma->id)
        ->call('ignorePunch', $stray->id);

    expect($stray->fresh()->isIgnored())->toBeTrue();
    $component->assertSet('drafts.0.starts_at', '2026-10-15T08:00');

    $component->call('restorePunch', $stray->id);

    expect($stray->fresh()->isIgnored())->toBeFalse();
});

test('punches of another person cannot be ignored from someone else\'s editor', function () {
    $admin = User::factory()->admin()->create();
    $asma = deskStaff('Asma Bibi', ['2026-10-15 08:00:00']);
    deskStaff('Ali Raza', ['2026-10-15 09:00:00']);
    $otherPunch = AttendancePunch::query()->orderByDesc('punched_at')->first();

    $component = Livewire::actingAs($admin)
        ->test('pages::hr.pairing', ['date' => '2026-10-15'])
        ->call('openEditor', $asma->id);

    expect(fn () => $component->call('ignorePunch', $otherPunch->id))->toThrow(ModelNotFoundException::class)
        ->and($otherPunch->fresh()->isIgnored())->toBeFalse();
});

test('the day can be marked reviewed from the desk once everything is confirmed', function () {
    $admin = User::factory()->admin()->create();
    deskStaff('Asma Bibi', ['2026-10-15 07:58:00', '2026-10-15 15:05:00']);

    $component = Livewire::actingAs($admin)->test('pages::hr.pairing', ['date' => '2026-10-15']);

    $component->call('markReviewed');
    expect(AttendanceDayReview::query()->count())->toBe(0);

    $component->call('confirmAllClean')->call('markReviewed')->assertSee('Reviewed by');
    expect(AttendanceDayReview::query()->count())->toBe(1);
});

test('confirmed sessions can be sent back for pairing', function () {
    $admin = User::factory()->admin()->create();
    $asma = deskStaff('Asma Bibi', ['2026-10-15 07:58:00', '2026-10-15 15:05:00']);

    $component = Livewire::actingAs($admin)
        ->test('pages::hr.pairing', ['date' => '2026-10-15'])
        ->call('confirmEmployee', $asma->id);

    $component->call('unconfirmSession', DutySession::query()->value('id'))
        ->assertSee('Suggested (1)');

    expect(DutySession::query()->count())->toBe(0);
});

test('backlog shows reviewed, pending and changed days', function () {
    $this->travelTo(now()->setDate(2026, 10, 16)->setTime(12, 0));
    $admin = User::factory()->admin()->create();
    deskStaff('Asma Bibi', ['2026-10-14 08:00:00', '2026-10-15 08:00:00']);
    AttendanceDayReview::factory()->create(['review_date' => '2026-10-14', 'reviewed_by' => $admin->id, 'reviewed_at' => now()->subDay()]);
    AttendanceDayReview::factory()->create(['review_date' => '2026-10-13', 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);

    $days = Livewire::actingAs($admin)
        ->test('pages::hr.backlog', ['month' => '2026-10'])
        ->assertSee('October 2026')
        ->instance()
        ->days
        ->keyBy(fn (array $day) => $day['date']->toDateString());

    expect($days['2026-10-13']['status'])->toBe('reviewed')
        ->and($days['2026-10-14']['status'])->toBe('changed')
        ->and($days['2026-10-15']['status'])->toBe('pending')
        ->and($days['2026-10-12']['status'])->toBe('quiet')
        ->and($days['2026-10-16']['status'])->toBe('today')
        ->and($days['2026-10-17']['status'])->toBe('future');
});

test('admin can add a shift and change the pairing rules', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::hr.settings')
        ->call('openShiftModal')
        ->set('shiftName', 'Day 12h')
        ->set('shiftStartsAt', '10:00')
        ->set('shiftEndsAt', '22:00')
        ->call('saveShift')
        ->assertHasNoErrors()
        ->set('rules.late_grace_minutes', '20')
        ->call('saveRules')
        ->assertHasNoErrors();

    expect(ShiftTemplate::query()->where('name', 'Day 12h')->first())
        ->starts_at->toBe('10:00:00')
        ->ends_at->toBe('22:00:00')
        ->and(AppSetting::attendanceRule(AppSetting::LateGraceMinutes))->toBe(20);
});

test('pairing rules are validated', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::hr.settings')
        ->set('rules.max_session_hours', '200')
        ->call('saveRules')
        ->assertHasErrors('rules.max_session_hours');
});

test('a usual shift can be set on the staff profile', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create();
    $night = ShiftTemplate::query()->where('name', 'Night')->firstOrFail();

    Livewire::actingAs($admin)
        ->test('pages::admin.employee-profile', ['employee' => $employee])
        ->set('defaultShiftTemplateId', (string) $night->id)
        ->call('saveAttendanceSettings')
        ->assertHasNoErrors();

    expect($employee->fresh()->defaultShiftTemplate->is($night))->toBeTrue();
});
