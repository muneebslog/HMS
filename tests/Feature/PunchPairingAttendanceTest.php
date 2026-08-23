<?php

use App\Enums\AttendanceRecordStatus;
use App\Enums\PunchPairingRole;
use App\Enums\WorkSessionStatus;
use App\Models\AttendanceDevice;
use App\Models\AttendancePunch;
use App\Models\AttendanceWorkSession;
use App\Models\DutyAssignment;
use App\Models\DutyShiftTemplate;
use App\Models\HealthAide;
use App\Models\User;
use App\Services\AttendanceProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('rolling pairing creates sessions across midnight', function () {
    $aide = HealthAide::factory()->create(['device_user_id' => '7']);
    $device = AttendanceDevice::factory()->create();
    $admin = User::factory()->admin()->create();

    $night = Carbon::parse('2026-08-21 22:55:00');
    $morning = Carbon::parse('2026-08-22 07:08:00');

    AttendancePunch::factory()->create([
        'attendance_device_id' => $device->id,
        'health_aide_id' => $aide->id,
        'device_user_id' => '7',
        'punched_at' => $night,
        'punch_state' => 255,
    ]);

    AttendancePunch::factory()->create([
        'attendance_device_id' => $device->id,
        'health_aide_id' => $aide->id,
        'device_user_id' => '7',
        'punched_at' => $morning,
        'punch_state' => 255,
    ]);

    $service = app(AttendanceProcessingService::class);
    $service->rebuildSessionsForAide($aide->id);

    $session = AttendanceWorkSession::query()->where('health_aide_id', $aide->id)->first();

    expect($session)->not->toBeNull()
        ->and($session->status)->toBe(WorkSessionStatus::Suggested)
        ->and($session->starts_at->format('Y-m-d H:i'))->toBe('2026-08-21 22:55')
        ->and($session->ends_at->format('Y-m-d H:i'))->toBe('2026-08-22 07:08');

    $record = $service->confirmSession($session, $admin);

    expect($record->status)->toBe(AttendanceRecordStatus::Present)
        ->and($record->worked_minutes)->toBeGreaterThan(480)
        ->and($session->fresh()->status)->toBe(WorkSessionStatus::Confirmed);
});

test('ignored punch is skipped when pairing', function () {
    $aide = HealthAide::factory()->create();
    $device = AttendanceDevice::factory()->create();

    AttendancePunch::factory()->create([
        'attendance_device_id' => $device->id,
        'health_aide_id' => $aide->id,
        'punched_at' => Carbon::parse('2026-08-22 08:00:00'),
    ]);

    $ignored = AttendancePunch::factory()->create([
        'attendance_device_id' => $device->id,
        'health_aide_id' => $aide->id,
        'punched_at' => Carbon::parse('2026-08-22 08:05:00'),
        'pairing_role' => PunchPairingRole::Ignore,
    ]);

    AttendancePunch::factory()->create([
        'attendance_device_id' => $device->id,
        'health_aide_id' => $aide->id,
        'punched_at' => Carbon::parse('2026-08-22 16:00:00'),
    ]);

    app(AttendanceProcessingService::class)->rebuildSessionsForAide($aide->id);

    $session = AttendanceWorkSession::query()->where('health_aide_id', $aide->id)->first();

    expect(AttendanceWorkSession::query()->count())->toBe(1)
        ->and($session->status)->toBe(WorkSessionStatus::Suggested)
        ->and($session->out_punch_id)->not->toBe($ignored->id)
        ->and($session->worked_minutes)->toBe(480);
});

test('manual punch participates in pairing', function () {
    $aide = HealthAide::factory()->create();
    $admin = User::factory()->admin()->create();
    $device = AttendanceDevice::factory()->create();
    $service = app(AttendanceProcessingService::class);

    AttendancePunch::factory()->create([
        'attendance_device_id' => $device->id,
        'health_aide_id' => $aide->id,
        'punched_at' => Carbon::parse('2026-08-22 08:00:00'),
    ]);

    $service->createManualPunch(
        $aide,
        Carbon::parse('2026-08-22 16:00:00'),
        PunchPairingRole::Out,
        $admin,
        'Forgot to punch out',
    );

    $session = AttendanceWorkSession::query()->where('health_aide_id', $aide->id)->first();

    expect($session)->not->toBeNull()
        ->and($session->status)->toBe(WorkSessionStatus::Suggested)
        ->and($session->outPunch->punch_state_source->value)->toBe('manual');
});

test('confirmed sessions are not rewritten by rebuild', function () {
    $aide = HealthAide::factory()->create();
    $admin = User::factory()->admin()->create();
    $device = AttendanceDevice::factory()->create();
    $service = app(AttendanceProcessingService::class);

    AttendancePunch::factory()->create([
        'attendance_device_id' => $device->id,
        'health_aide_id' => $aide->id,
        'punched_at' => Carbon::parse('2026-08-22 08:00:00'),
    ]);

    AttendancePunch::factory()->create([
        'attendance_device_id' => $device->id,
        'health_aide_id' => $aide->id,
        'punched_at' => Carbon::parse('2026-08-22 16:00:00'),
    ]);

    $service->rebuildSessionsForAide($aide->id);
    $session = AttendanceWorkSession::query()->first();
    $service->confirmSession($session, $admin);

    AttendancePunch::factory()->create([
        'attendance_device_id' => $device->id,
        'health_aide_id' => $aide->id,
        'punched_at' => Carbon::parse('2026-08-22 18:00:00'),
    ]);

    $service->rebuildSessionsForAide($aide->id);

    expect($session->fresh()->status)->toBe(WorkSessionStatus::Confirmed)
        ->and($session->fresh()->out_punch_id)->toBe($session->out_punch_id)
        ->and(AttendanceWorkSession::query()->open()->count())->toBe(1);
});

test('bulk roster creates assignments for aides across date range', function () {
    $admin = User::factory()->admin()->create();
    $aides = HealthAide::factory()->count(2)->create();
    $template = DutyShiftTemplate::factory()->create();

    $this->actingAs($admin);

    Livewire::test('pages::admin.attendance-roster')
        ->call('openBulkModal')
        ->set('selectedHealthAideIds', $aides->pluck('id')->all())
        ->set('dateFrom', '2026-08-20')
        ->set('dateTo', '2026-08-21')
        ->set('templateId', $template->id)
        ->call('saveBulkAssignments')
        ->assertHasNoErrors();

    expect(DutyAssignment::query()->count())->toBe(4);
});

test('current staff page lists open check-ins only', function () {
    $admin = User::factory()->admin()->create();
    $aide = HealthAide::factory()->create();
    $device = AttendanceDevice::factory()->create();

    AttendancePunch::factory()->create([
        'attendance_device_id' => $device->id,
        'health_aide_id' => $aide->id,
        'punched_at' => now()->subHour(),
    ]);

    app(AttendanceProcessingService::class)->rebuildSessionsForAide($aide->id);

    expect(AttendanceWorkSession::query()->open()->count())->toBe(1);

    $this->actingAs($admin)
        ->get(route('admin.attendance'))
        ->assertSuccessful()
        ->assertSee($aide->name)
        ->assertSee(__('Current Staff'));
});

test('missing punch notify still uses roster without check-in', function () {
    $aide = HealthAide::factory()->create();
    $admin = User::factory()->admin()->create();

    DutyAssignment::factory()->create([
        'health_aide_id' => $aide->id,
        'date' => today()->toDateString(),
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addHours(3),
        'created_by' => $admin->id,
    ]);

    $this->artisan('attendance:notify-missing')
        ->assertSuccessful();
});
