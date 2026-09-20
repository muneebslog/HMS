<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('attendance admin routes are no longer registered', function () {
    expect(Route::has('admin.attendance'))->toBeFalse()
        ->and(Route::has('admin.attendance.roster'))->toBeFalse()
        ->and(Route::has('admin.attendance.leaves'))->toBeFalse()
        ->and(Route::has('admin.attendance.punches'))->toBeFalse()
        ->and(Route::has('admin.attendance.daily'))->toBeFalse()
        ->and(Route::has('admin.attendance.payroll'))->toBeFalse()
        ->and(Route::has('admin.attendance.device'))->toBeFalse();
});

test('attendance pages are removed from page access registry', function () {
    $pages = array_keys(config('pages.pages'));

    expect($pages)->not->toContain('admin.attendance')
        ->and($pages)->not->toContain('admin.attendance.roster')
        ->and($pages)->not->toContain('admin.attendance.device');

    $managementDefaults = config('pages.defaults.'.UserRole::Management->value);

    expect($managementDefaults)->not->toContain('admin.attendance');
});

test('attendance database tables are dropped', function () {
    expect(Schema::hasTable('attendance_devices'))->toBeFalse()
        ->and(Schema::hasTable('attendance_punches'))->toBeFalse()
        ->and(Schema::hasTable('attendance_records'))->toBeFalse()
        ->and(Schema::hasTable('attendance_work_sessions'))->toBeFalse()
        ->and(Schema::hasTable('attendance_device_users'))->toBeFalse()
        ->and(Schema::hasTable('attendance_adjustments'))->toBeFalse()
        ->and(Schema::hasTable('duty_assignments'))->toBeFalse()
        ->and(Schema::hasTable('duty_locations'))->toBeFalse()
        ->and(Schema::hasTable('duty_shift_templates'))->toBeFalse()
        ->and(Schema::hasTable('health_aide_leaves'))->toBeFalse()
        ->and(Schema::hasColumn('health_aides', 'device_user_id'))->toBeFalse()
        ->and(Schema::hasColumn('health_aides', 'attendance_enrolled_at'))->toBeFalse();
});

test('attendance urls return not found', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/admin/attendance')
        ->assertNotFound();
});
