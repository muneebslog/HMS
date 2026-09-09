<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
        'broadcasting.connections.reverb.options.host' => 'localhost',
        'broadcasting.connections.reverb.options.port' => 8080,
        'broadcasting.connections.reverb.options.scheme' => 'http',
        'broadcasting.connections.reverb.options.useTLS' => false,
    ]);

    Broadcast::forgetDrivers();

    Broadcast::channel('hms.staff', function (User $user) {
        if ($user->actualRole() === UserRole::User) {
            return false;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->actualRole()->value,
        ];
    });
});

test('staff can authorize the staff presence channel', function () {
    $doctor = User::factory()->doctor()->create(['name' => 'Dr Online']);

    $response = $this->actingAs($doctor)
        ->post('/broadcasting/auth', [
            'channel_name' => 'presence-hms.staff',
            'socket_id' => '1234.5678',
        ])
        ->assertSuccessful();

    $channelData = json_decode($response->json('channel_data'), true);

    expect((int) $channelData['user_id'])->toBe($doctor->id)
        ->and($channelData['user_info']['id'])->toBe($doctor->id)
        ->and($channelData['user_info']['name'])->toBe('Dr Online');
});

test('pending users cannot authorize the staff presence channel', function () {
    $user = User::factory()->user()->create();

    $this->actingAs($user)
        ->post('/broadcasting/auth', [
            'channel_name' => 'presence-hms.staff',
            'socket_id' => '1234.5678',
        ])
        ->assertForbidden();
});
