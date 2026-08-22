<?php

use App\Enums\AttendanceRecordStatus;
use App\Enums\DutyAssignmentType;
use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceUser;
use App\Models\AttendancePunch;
use App\Models\AttendanceRecord;
use App\Models\DutyAssignment;
use App\Models\DutyShiftTemplate;
use App\Models\HealthAide;
use App\Models\HealthAideLeave;
use App\Models\User;
use App\Services\AttendanceProcessingService;
use App\Services\PayrollReportService;
use App\Services\ZktecoSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('admin and management can visit attendance dashboard', function (string $role) {
    $user = match ($role) {
        'admin' => User::factory()->admin()->create(),
        'management' => User::factory()->management()->create(),
    };

    $this->actingAs($user)
        ->get(route('admin.attendance'))
        ->assertSuccessful();
})->with(['admin', 'management']);

test('receptionist cannot visit attendance dashboard', function () {
    $user = User::factory()->receptionist()->create();

    $this->actingAs($user)
        ->get(route('admin.attendance'))
        ->assertForbidden();
});

test('attendance processing pairs overnight punches using roster fallback', function () {
    $aide = HealthAide::factory()->create(['device_user_id' => '7']);
    $admin = User::factory()->admin()->create();

    $date = Carbon::parse('2026-08-21');
    $assignment = DutyAssignment::factory()->create([
        'health_aide_id' => $aide->id,
        'date' => $date->toDateString(),
        'starts_at' => $date->copy()->setTime(23, 0),
        'ends_at' => $date->copy()->addDay()->setTime(7, 0),
        'created_by' => $admin->id,
    ]);

    $device = AttendanceDevice::factory()->create();

    AttendancePunch::factory()->create([
        'attendance_device_id' => $device->id,
        'health_aide_id' => $aide->id,
        'device_user_id' => '7',
        'punched_at' => $date->copy()->setTime(22, 55),
        'punch_state' => 255,
    ]);

    AttendancePunch::factory()->create([
        'attendance_device_id' => $device->id,
        'health_aide_id' => $aide->id,
        'device_user_id' => '7',
        'punched_at' => $date->copy()->addDay()->setTime(7, 8),
        'punch_state' => 255,
    ]);

    $record = app(AttendanceProcessingService::class)->processAssignment($assignment);

    expect($record->status)->toBe(AttendanceRecordStatus::Present)
        ->and($record->first_in_at->format('H:i'))->toBe('22:55')
        ->and($record->last_out_at->format('H:i'))->toBe('07:08')
        ->and($record->worked_minutes)->toBeGreaterThan(480);
});

test('health aide on leave is marked on leave not absent', function () {
    $aide = HealthAide::factory()->create();
    $admin = User::factory()->admin()->create();

    $assignment = DutyAssignment::factory()->create([
        'health_aide_id' => $aide->id,
        'date' => today()->toDateString(),
        'created_by' => $admin->id,
    ]);

    HealthAideLeave::factory()->create([
        'health_aide_id' => $aide->id,
        'leave_date' => today(),
        'created_by' => $admin->id,
    ]);

    $record = app(AttendanceProcessingService::class)->processAssignment($assignment);

    expect($record->status)->toBe(AttendanceRecordStatus::OnLeave)
        ->and($record->payable_minutes)->toBe(0);
});

test('extra shift overtime is calculated when configured', function () {
    $aide = HealthAide::factory()->create();
    $admin = User::factory()->admin()->create();

    $startsAt = now()->setTime(18, 0);
    $endsAt = now()->setTime(22, 0);

    $assignment = DutyAssignment::factory()->create([
        'health_aide_id' => $aide->id,
        'date' => today()->toDateString(),
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'assignment_type' => DutyAssignmentType::Extra,
        'created_by' => $admin->id,
    ]);

    $device = AttendanceDevice::factory()->create();

    AttendancePunch::factory()->checkIn($startsAt->copy()->addMinutes(5))->create([
        'attendance_device_id' => $device->id,
        'health_aide_id' => $aide->id,
        'device_user_id' => (string) $aide->id,
    ]);

    AttendancePunch::factory()->checkOut($endsAt->copy()->addMinutes(30))->create([
        'attendance_device_id' => $device->id,
        'health_aide_id' => $aide->id,
        'device_user_id' => (string) $aide->id,
    ]);

    $record = app(AttendanceProcessingService::class)->processAssignment($assignment);

    expect($record->overtime_minutes)->toBeGreaterThan(0)
        ->and($record->payable_minutes)->toBeGreaterThan(0);
});

test('payroll report aggregates monthly payable hours', function () {
    $aide = HealthAide::factory()->create(['name' => 'Sara Ali']);
    $admin = User::factory()->admin()->create();
    $template = DutyShiftTemplate::factory()->create();

    $assignment = DutyAssignment::factory()->create([
        'health_aide_id' => $aide->id,
        'duty_shift_template_id' => $template->id,
        'date' => now()->startOfMonth()->toDateString(),
        'created_by' => $admin->id,
    ]);

    AttendanceRecord::factory()->create([
        'health_aide_id' => $aide->id,
        'duty_assignment_id' => $assignment->id,
        'date' => now()->startOfMonth()->toDateString(),
        'payable_minutes' => 480,
        'overtime_minutes' => 60,
        'status' => AttendanceRecordStatus::Present,
    ]);

    $summary = app(PayrollReportService::class)->monthlySummary(now()->year, now()->month);

    expect($summary)->toHaveCount(1)
        ->and($summary->first()['health_aide_name'])->toBe('Sara Ali')
        ->and($summary->first()['payable_hours'])->toBe(8.0);
});

test('duplicate attendance punches are ignored on sync import', function () {
    $device = AttendanceDevice::factory()->create();
    $aide = HealthAide::factory()->create(['device_user_id' => '3']);

    AttendancePunch::factory()->create([
        'attendance_device_id' => $device->id,
        'device_punch_uid' => '9_2026-08-22 08:00:00',
        'device_user_id' => '3',
        'health_aide_id' => $aide->id,
        'punched_at' => '2026-08-22 08:00:00',
    ]);

    expect(AttendancePunch::query()->count())->toBe(1);

    AttendancePunch::query()->firstOrCreate(
        [
            'attendance_device_id' => $device->id,
            'device_punch_uid' => '9_2026-08-22 08:00:00',
        ],
        [
            'device_user_id' => '3',
            'health_aide_id' => $aide->id,
            'punched_at' => '2026-08-22 08:00:00',
        ],
    );

    expect(AttendancePunch::query()->count())->toBe(1);
});

test('linking a device user maps health aide and remaps punches', function () {
    $device = AttendanceDevice::factory()->create();
    $aide = HealthAide::factory()->create();
    $deviceUser = AttendanceDeviceUser::factory()->create([
        'attendance_device_id' => $device->id,
        'device_user_id' => '42',
        'name' => 'Ali on Device',
    ]);

    AttendancePunch::factory()->create([
        'attendance_device_id' => $device->id,
        'device_user_id' => '42',
        'health_aide_id' => null,
        'punched_at' => now(),
    ]);

    app(ZktecoSyncService::class)->linkDeviceUserToHealthAide($deviceUser, $aide);

    expect($aide->fresh()->device_user_id)->toBe('42')
        ->and($aide->fresh()->attendance_enrolled_at)->not->toBeNull()
        ->and($deviceUser->fresh()->health_aide_id)->toBe($aide->id)
        ->and(AttendancePunch::query()->where('device_user_id', '42')->where('health_aide_id', $aide->id)->count())->toBe(1);
});

test('unlinking a device user clears health aide enrollment', function () {
    $device = AttendanceDevice::factory()->create();
    $aide = HealthAide::factory()->create([
        'device_user_id' => '9',
        'attendance_enrolled_at' => now(),
    ]);
    $deviceUser = AttendanceDeviceUser::factory()->create([
        'attendance_device_id' => $device->id,
        'device_user_id' => '9',
        'name' => 'Sara',
        'health_aide_id' => $aide->id,
    ]);

    app(ZktecoSyncService::class)->unlinkDeviceUser($deviceUser);

    expect($aide->fresh()->device_user_id)->toBeNull()
        ->and($aide->fresh()->attendance_enrolled_at)->toBeNull()
        ->and($deviceUser->fresh()->health_aide_id)->toBeNull();
});
