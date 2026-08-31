<?php

use App\Enums\DutyAssignmentStatus;
use App\Models\DutyAssignment;
use App\Models\DutyLocation;
use App\Models\HealthAide;
use App\Models\User;
use App\Services\RosterSchedulingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->location = DutyLocation::factory()->create(['name' => 'ER Station']);
});

test('recurring schedule respects weekday filter', function () {
    $aide = HealthAide::factory()->create();

    $this->actingAs($this->admin);

    Livewire::test('pages::admin.attendance-roster')
        ->call('openRecurringModal')
        ->set('selectedHealthAideIds', [$aide->id])
        ->set('selectedWeekdays', [1, 2, 3, 4, 5, 6])
        ->set('dateFrom', '2026-08-17')
        ->set('dateTo', '2026-08-23')
        ->set('dutyStartAt', '2026-08-17T07:00')
        ->set('dutyEndAt', '2026-08-17T15:00')
        ->set('dutyLocationId', $this->location->id)
        ->call('saveRecurringSchedule')
        ->assertHasNoErrors();

    expect(DutyAssignment::query()->count())->toBe(6)
        ->and(DutyAssignment::query()->whereDate('date', '2026-08-23')->exists())->toBeFalse();
});

test('date override replaces base assignment for same aide and date', function () {
    $aide = HealthAide::factory()->create();

    DutyAssignment::factory()->create([
        'health_aide_id' => $aide->id,
        'duty_location_id' => $this->location->id,
        'date' => '2026-08-20',
        'starts_at' => Carbon::parse('2026-08-20 07:00'),
        'ends_at' => Carbon::parse('2026-08-20 15:00'),
        'is_override' => false,
        'created_by' => $this->admin->id,
    ]);

    $this->actingAs($this->admin);

    Livewire::test('pages::admin.attendance-roster')
        ->call('openOverrideModal', '2026-08-20', 9)
        ->set('healthAideId', $aide->id)
        ->set('dutyStartAt', '2026-08-20T09:00')
        ->set('dutyEndAt', '2026-08-20T17:00')
        ->set('dutyLocationId', $this->location->id)
        ->call('saveOverride')
        ->assertHasNoErrors();

    expect(DutyAssignment::query()->scheduled()->count())->toBe(1)
        ->and(DutyAssignment::query()->scheduled()->first())
        ->is_override->toBeTrue()
        ->starts_at->format('H:i')->toBe('09:00');
});

test('recurring schedule skips dates with existing overrides', function () {
    $aide = HealthAide::factory()->create();

    DutyAssignment::factory()->create([
        'health_aide_id' => $aide->id,
        'duty_location_id' => $this->location->id,
        'date' => '2026-08-20',
        'starts_at' => Carbon::parse('2026-08-20 09:00'),
        'ends_at' => Carbon::parse('2026-08-20 17:00'),
        'is_override' => true,
        'created_by' => $this->admin->id,
    ]);

    $this->actingAs($this->admin);

    Livewire::test('pages::admin.attendance-roster')
        ->call('openRecurringModal')
        ->set('selectedHealthAideIds', [$aide->id])
        ->set('selectedWeekdays', [1, 2, 3, 4, 5, 6, 7])
        ->set('dateFrom', '2026-08-20')
        ->set('dateTo', '2026-08-21')
        ->set('dutyStartAt', '2026-08-20T07:00')
        ->set('dutyEndAt', '2026-08-20T15:00')
        ->set('dutyLocationId', $this->location->id)
        ->call('saveRecurringSchedule')
        ->assertHasNoErrors();

    expect(DutyAssignment::query()->scheduled()->count())->toBe(2)
        ->and(DutyAssignment::query()->scheduled()->whereDate('date', '2026-08-20')->first()->starts_at->format('H:i'))->toBe('09:00');
});

test('overnight assignment is included in overlapping week query and split into segments', function () {
    $aide = HealthAide::factory()->create();
    $assignment = DutyAssignment::factory()->create([
        'health_aide_id' => $aide->id,
        'duty_location_id' => $this->location->id,
        'date' => '2026-08-22',
        'starts_at' => Carbon::parse('2026-08-22 23:00'),
        'ends_at' => Carbon::parse('2026-08-23 07:00'),
        'created_by' => $this->admin->id,
    ]);

    $service = app(RosterSchedulingService::class);
    $weekStart = Carbon::parse('2026-08-17');

    $assignments = $service->assignmentsOverlappingWeek($weekStart);
    $segments = $service->calendarSegmentsForWeek($assignments, $weekStart);

    expect($assignments)->toHaveCount(1);

    $segmentDays = collect($segments)->pluck('day')->unique()->sort()->values()->all();

    expect($segmentDays)->toBe(['2026-08-22', '2026-08-23'])
        ->and(collect($segments)->firstWhere('day', '2026-08-22')['assignment']->id)->toBe($assignment->id);
});

test('duty location is required when saving override', function () {
    $aide = HealthAide::factory()->create();

    $this->actingAs($this->admin);

    Livewire::test('pages::admin.attendance-roster')
        ->call('openOverrideModal', '2026-08-20', 7)
        ->set('healthAideId', $aide->id)
        ->set('dutyStartAt', '2026-08-20T07:00')
        ->set('dutyEndAt', '2026-08-20T15:00')
        ->call('saveOverride')
        ->assertHasErrors(['dutyLocationId']);
});

test('roster week view renders overnight shift', function () {
    $aide = HealthAide::factory()->create(['name' => 'Night Aide']);

    DutyAssignment::factory()->create([
        'health_aide_id' => $aide->id,
        'duty_location_id' => $this->location->id,
        'date' => '2026-08-22',
        'starts_at' => Carbon::parse('2026-08-22 23:00'),
        'ends_at' => Carbon::parse('2026-08-23 07:00'),
        'created_by' => $this->admin->id,
    ]);

    $this->actingAs($this->admin)
        ->get(route('admin.attendance.roster'))
        ->assertSuccessful()
        ->assertSee('Night Aide')
        ->assertSee('ER Station');
});

test('cancelled assignments are excluded from week query', function () {
    $aide = HealthAide::factory()->create();

    DutyAssignment::factory()->create([
        'health_aide_id' => $aide->id,
        'duty_location_id' => $this->location->id,
        'date' => '2026-08-20',
        'starts_at' => Carbon::parse('2026-08-20 07:00'),
        'ends_at' => Carbon::parse('2026-08-20 15:00'),
        'status' => DutyAssignmentStatus::Cancelled,
        'created_by' => $this->admin->id,
    ]);

    $assignments = app(RosterSchedulingService::class)->assignmentsOverlappingWeek(
        Carbon::parse('2026-08-17'),
    );

    expect($assignments)->toBeEmpty();
});
