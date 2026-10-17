<?php

use App\Models\AppSetting;
use App\Models\AttendanceDayReview;
use App\Models\AttendanceDeviceUser;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\DutyPairingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * Create a staff member linked to a machine user, with punches at the given times.
 *
 * @param  list<string>  $times
 * @return array{0: Employee, 1: list<AttendancePunch>}
 */
function staffWithPunches(array $times, array $employeeAttributes = []): array
{
    $employee = Employee::factory()->create($employeeAttributes);
    $deviceUser = AttendanceDeviceUser::factory()->linked($employee)->create();

    $punches = array_map(fn (string $time): AttendancePunch => AttendancePunch::factory()->create([
        'attendance_device_user_id' => $deviceUser->id,
        'punched_at' => $time,
    ]), $times);

    return [$employee, $punches];
}

function pairing(): DutyPairingService
{
    return app(DutyPairingService::class);
}

function shiftNamed(string $name): ShiftTemplate
{
    return ShiftTemplate::query()->where('name', $name)->firstOrFail();
}

function dutyDay(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date);
}

test('the three default shifts are seeded', function () {
    expect(ShiftTemplate::query()->active()->get()->map->label()->all())
        ->toBe(['Morning 08:00–15:00', 'Evening 15:00–22:00', 'Night 22:00–08:00']);
});

test('a normal morning duty is paired as one morning duty', function () {
    [$employee, [$in, $out]] = staffWithPunches(['2026-10-15 07:58:00', '2026-10-15 15:05:00']);

    $suggestions = pairing()->suggestionsFor($employee, dutyDay('2026-10-15'));

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]['in_punch_id'])->toBe($in->id)
        ->and($suggestions[0]['out_punch_id'])->toBe($out->id)
        ->and($suggestions[0]['flags'])->toBe([])
        ->and($suggestions[0]['duty_template_ids'])->toBe([shiftNamed('Morning')->id])
        ->and($suggestions[0]['duties'][0]['late_minutes'])->toBe(0)
        ->and($suggestions[0]['duties'][0]['early_leave_minutes'])->toBe(0);
});

test('late arrival and early leaving are measured against the shift', function () {
    [$employee] = staffWithPunches(['2026-10-15 08:31:00', '2026-10-15 14:40:00']);

    $duty = pairing()->suggestionsFor($employee, dutyDay('2026-10-15'))[0]['duties'][0];

    expect($duty['late_minutes'])->toBe(31)
        ->and($duty['early_leave_minutes'])->toBe(20);
});

test('a night duty crossing midnight belongs to the day it started', function () {
    [$employee] = staffWithPunches(['2026-10-15 21:52:00', '2026-10-16 08:04:00']);

    $onStartDay = pairing()->suggestionsFor($employee, dutyDay('2026-10-15'));
    $onNextDay = pairing()->suggestionsFor($employee, dutyDay('2026-10-16'));

    expect($onStartDay)->toHaveCount(1)
        ->and($onStartDay[0]['duty_template_ids'])->toBe([shiftNamed('Night')->id])
        ->and($onNextDay)->toBe([]);
});

test('staying on through the night is split into several duties and flagged', function () {
    [$employee] = staffWithPunches(['2026-10-15 08:02:00', '2026-10-16 08:11:00']);

    $suggestion = pairing()->suggestionsFor($employee, dutyDay('2026-10-15'))[0];

    expect($suggestion['duty_template_ids'])->toBe([shiftNamed('Morning')->id, shiftNamed('Evening')->id, shiftNamed('Night')->id])
        ->and($suggestion['flags'])->toBe(['long'])
        ->and(pairing()->needsAttention($suggestion))->toBeTrue();
});

test('a custom 12 hour shift is preferred when it matches the arrival better', function () {
    $dayShift = ShiftTemplate::factory()->timed('10:00', '22:00')->create(['name' => 'Day 12h']);
    [$employee] = staffWithPunches(['2026-10-15 10:02:00', '2026-10-16 08:11:00']);

    $suggestion = pairing()->suggestionsFor($employee, dutyDay('2026-10-15'))[0];

    expect($suggestion['duty_template_ids'])->toBe([$dayShift->id, shiftNamed('Night')->id])
        ->and($suggestion['duties'][0]['late_minutes'])->toBe(2);
});

test('the usual shift of a person wins a near tie', function () {
    $dayShift = ShiftTemplate::factory()->timed('09:00', '17:00')->create(['name' => 'Office']);
    [$employee] = staffWithPunches(['2026-10-15 08:20:00', '2026-10-15 17:00:00'], ['default_shift_template_id' => $dayShift->id]);

    expect(pairing()->suggestionsFor($employee, dutyDay('2026-10-15'))[0]['duty_template_ids'])->toBe([$dayShift->id]);
});

test('a missing out punch is flagged and has no duties yet', function () {
    [$employee] = staffWithPunches(['2026-10-15 22:14:00']);

    $suggestion = pairing()->suggestionsFor($employee, dutyDay('2026-10-15'))[0];

    expect($suggestion['ends_at'])->toBeNull()
        ->and($suggestion['flags'])->toBe(['missing_out'])
        ->and($suggestion['duties'])->toBe([]);
});

test('a forgotten out punch does not pair with the next day when it is too far apart', function () {
    AppSetting::set(AppSetting::MaxSessionHours, '20');
    [$employee] = staffWithPunches(['2026-10-15 08:00:00', '2026-10-16 07:55:00', '2026-10-16 15:00:00']);

    expect(pairing()->suggestionsFor($employee, dutyDay('2026-10-15'))[0]['flags'])->toBe(['missing_out'])
        ->and(pairing()->suggestionsFor($employee, dutyDay('2026-10-16'))[0]['duty_template_ids'])->toBe([shiftNamed('Morning')->id]);
});

test('double taps within the duplicate window are dropped', function () {
    [$employee, [$in, $tap, $out]] = staffWithPunches(['2026-10-15 14:52:00', '2026-10-15 14:52:40', '2026-10-15 22:03:00']);

    $suggestion = pairing()->suggestionsFor($employee, dutyDay('2026-10-15'))[0];

    expect($suggestion['in_punch_id'])->toBe($in->id)
        ->and($suggestion['out_punch_id'])->toBe($out->id)
        ->and($suggestion['duplicate_punch_ids'])->toBe([$tap->id]);
});

test('going out for a short break joins both halves into one duty', function () {
    [$employee, $punches] = staffWithPunches(['2026-10-15 08:00:00', '2026-10-15 12:10:00', '2026-10-15 13:05:00', '2026-10-15 15:02:00']);

    $suggestions = pairing()->suggestionsFor($employee, dutyDay('2026-10-15'));

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]['in_punch_id'])->toBe($punches[0]->id)
        ->and($suggestions[0]['out_punch_id'])->toBe($punches[3]->id)
        ->and($suggestions[0]['break_minutes'])->toBe(55)
        ->and($suggestions[0]['flags'])->toBe(['break'])
        ->and($suggestions[0]['duty_template_ids'])->toBe([shiftNamed('Morning')->id]);
});

test('coming back later for another shift stays a separate session', function () {
    [$employee] = staffWithPunches(['2026-10-15 08:00:00', '2026-10-15 15:00:00', '2026-10-15 22:00:00', '2026-10-16 08:00:00']);

    $suggestions = pairing()->suggestionsFor($employee, dutyDay('2026-10-15'));

    expect($suggestions)->toHaveCount(2)
        ->and($suggestions[1]['duty_template_ids'])->toBe([shiftNamed('Night')->id]);
});

test('ignored punches are skipped', function () {
    $admin = User::factory()->admin()->create();
    [$employee, [$mistake, $in, $out]] = staffWithPunches(['2026-10-15 06:00:00', '2026-10-15 08:00:00', '2026-10-15 15:00:00']);

    pairing()->ignorePunch($mistake, $admin, 'Wrong finger');

    $suggestion = pairing()->suggestionsFor($employee, dutyDay('2026-10-15'))[0];

    expect($suggestion['in_punch_id'])->toBe($in->id)
        ->and($mistake->fresh()->ignore_reason)->toBe('Wrong finger');
});

test('confirming stores the session and its duties and removes the suggestion', function () {
    $admin = User::factory()->admin()->create();
    [$employee, [$in, $out]] = staffWithPunches(['2026-10-15 10:02:00', '2026-10-16 08:11:00']);
    $suggestion = pairing()->suggestionsFor($employee, dutyDay('2026-10-15'))[0];

    $session = pairing()->confirmSuggestion($employee, $suggestion, $admin);

    expect($session->duty_date->toDateString())->toBe('2026-10-15')
        ->and($session->in_punch_id)->toBe($in->id)
        ->and($session->out_punch_id)->toBe($out->id)
        ->and($session->confirmed_by)->toBe($admin->id)
        ->and($session->duties)->toHaveCount(3)
        ->and(app(DutyPairingService::class)->suggestionsFor($employee, dutyDay('2026-10-15')))->toBe([]);
});

test('a manual session can be confirmed with chosen shifts', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create();

    $session = pairing()->confirm($employee, [
        'starts_at' => CarbonImmutable::parse('2026-10-15 10:00'),
        'ends_at' => CarbonImmutable::parse('2026-10-15 22:00'),
        'in_punch_id' => null,
        'out_punch_id' => null,
        'notes' => 'Machine was down',
        'duty_template_ids' => [null],
    ], $admin);

    expect($session->startIsManual())->toBeTrue()
        ->and($session->duties)->toHaveCount(1)
        ->and($session->duties[0]->label())->toBe('Custom 10:00–22:00');
});

test('sessions cannot overlap or reuse another person\'s punches', function () {
    $admin = User::factory()->admin()->create();
    [$employee] = staffWithPunches(['2026-10-15 08:00:00', '2026-10-15 15:00:00']);
    [, [$otherPunch]] = staffWithPunches(['2026-10-15 09:00:00']);

    pairing()->confirmSuggestion($employee, pairing()->suggestionsFor($employee, dutyDay('2026-10-15'))[0], $admin);

    expect(fn () => pairing()->confirm($employee, [
        'starts_at' => CarbonImmutable::parse('2026-10-15 14:00'),
        'ends_at' => CarbonImmutable::parse('2026-10-15 16:00'),
        'in_punch_id' => null, 'out_punch_id' => null, 'duty_template_ids' => [null],
    ], $admin))->toThrow(ValidationException::class);

    expect(fn () => pairing()->confirm($employee, [
        'starts_at' => CarbonImmutable::parse('2026-10-16 09:00'),
        'ends_at' => CarbonImmutable::parse('2026-10-16 15:00'),
        'in_punch_id' => $otherPunch->id, 'out_punch_id' => null, 'duty_template_ids' => [null],
    ], $admin))->toThrow(ValidationException::class);
});

test('confirm all clean confirms only suggestions that need no judgement', function () {
    $admin = User::factory()->admin()->create();
    staffWithPunches(['2026-10-15 07:58:00', '2026-10-15 15:05:00']);
    [$missingOut] = staffWithPunches(['2026-10-15 22:14:00']);

    expect(pairing()->confirmCleanSuggestions(dutyDay('2026-10-15'), $admin))->toBe(1)
        ->and(pairing()->pendingCount(dutyDay('2026-10-15')))->toBe(1)
        ->and(pairing()->suggestionsFor($missingOut, dutyDay('2026-10-15'))[0]['flags'])->toBe(['missing_out']);
});

test('a day can only be marked reviewed once nothing is left to confirm', function () {
    $admin = User::factory()->admin()->create();
    [$employee] = staffWithPunches(['2026-10-15 07:58:00', '2026-10-15 15:05:00']);

    expect(fn () => pairing()->markReviewed(dutyDay('2026-10-15'), $admin))->toThrow(ValidationException::class);

    pairing()->confirmCleanSuggestions(dutyDay('2026-10-15'), $admin);
    pairing()->markReviewed(dutyDay('2026-10-15'), $admin);

    expect(AttendanceDayReview::query()->whereDate('review_date', '2026-10-15')->value('reviewed_by'))->toBe($admin->id);

    pairing()->markReviewed(dutyDay('2026-10-15'), $admin);

    expect(AttendanceDayReview::query()->count())->toBe(1);
});

test('the last day of the month shows its review on the backlog', function () {
    $admin = User::factory()->admin()->create();
    AttendanceDayReview::factory()->create(['review_date' => '2026-09-30', 'reviewed_by' => $admin->id]);

    $days = Livewire\Livewire::actingAs($admin)->test('pages::hr.backlog', ['month' => '2026-09'])->instance()->days;

    expect($days->last()['status'])->toBe('reviewed');
});

test('staff who do not punch are left out of the day', function () {
    Employee::factory()->create(['tracks_attendance' => false, 'name' => 'Dr. No Punch']);
    Employee::factory()->create(['name' => 'Ali Raza']);

    $names = pairing()->day(dutyDay('2026-10-15'))->map(fn (array $row) => $row['employee']->name)->values()->all();

    expect($names)->toBe(['Ali Raza']);
});
