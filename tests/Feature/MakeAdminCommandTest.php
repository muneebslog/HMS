<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('command creates an admin user', function () {
    $this->artisan('user:admin', [
        'name' => 'Admin User',
        'email' => 'admin@hms.local',
        'password' => 'password123',
    ])->assertSuccessful();

    $user = User::where('email', 'admin@hms.local')->first();

    expect($user)->not->toBeNull()
        ->name->toBe('Admin User')
        ->role->toBe(UserRole::Admin)
        ->email_verified_at->not->toBeNull();

    expect(Hash::check('password123', $user->password))->toBeTrue();
});

test('command promotes an existing user to admin', function () {
    $user = User::factory()->receptionist()->create([
        'email' => 'existing@hms.local',
    ]);

    $this->artisan('user:admin', [
        'name' => 'Updated Name',
        'email' => 'existing@hms.local',
        'password' => 'newpassword123',
    ])->assertSuccessful();

    expect($user->fresh()->role)->toBe(UserRole::Admin);
});

test('command validates missing arguments', function () {
    $this->artisan('user:admin', [
        'name' => '',
        'email' => 'not-an-email',
        'password' => '',
    ])->assertFailed();
});
