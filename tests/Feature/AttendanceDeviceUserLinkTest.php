<?php

use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceUser;
use App\Models\HealthAide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('link button opens modal and linking health aide works', function () {
    $admin = User::factory()->admin()->create();
    $device = AttendanceDevice::factory()->create([
        'ip_address' => '192.168.100.201',
        'port' => 4370,
    ]);
    $deviceUser = AttendanceDeviceUser::factory()->create([
        'attendance_device_id' => $device->id,
        'device_user_id' => '6',
        'name' => 'Aleezy',
    ]);
    $aide = HealthAide::factory()->create(['name' => 'Aleezy HMS']);

    Livewire::actingAs($admin)
        ->test('pages::admin.attendance-device')
        ->call('startLinkDeviceUser', $deviceUser->id)
        ->assertSet('showLinkModal', true)
        ->assertSet('linkingDeviceUserId', $deviceUser->id)
        ->set('linkHealthAideId', $aide->id)
        ->call('saveDeviceUserLink')
        ->assertHasNoErrors()
        ->assertSet('showLinkModal', false);

    expect($aide->fresh()->device_user_id)->toBe('6')
        ->and($deviceUser->fresh()->health_aide_id)->toBe($aide->id);
});
