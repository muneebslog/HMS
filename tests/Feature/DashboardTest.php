<?php

use App\Models\AdminNotification;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('management users see current and last closed shift finance stats', function () {
    $user = User::factory()->management()->create();

    Shift::factory()->open()->create([
        'user_id' => $user->id,
        'opening_balance' => 150.00,
    ]);

    Shift::factory()->closed()->create([
        'user_id' => $user->id,
        'opening_balance' => 100.00,
        'closing_balance' => 225.50,
        'closed_at' => now()->subHour(),
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('Current Shift')
        ->assertSee('150.00')
        ->assertSee('Last Closed Shift')
        ->assertSee('225.50');
});

test('management dashboard stats auto-refresh periodically', function () {
    $user = User::factory()->management()->create();

    Shift::factory()->open()->create([
        'user_id' => $user->id,
        'opening_balance' => 150.00,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('wire:poll.5s', false);
});

test('non-management users do not see shift finance stats', function () {
    $user = User::factory()->receptionist()->create();

    Shift::factory()->open()->create([
        'user_id' => $user->id,
        'opening_balance' => 999.00,
    ]);

    Shift::factory()->closed()->create([
        'user_id' => $user->id,
        'opening_balance' => 888.00,
        'closing_balance' => 777.00,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk()
        ->assertDontSee('Current Shift')
        ->assertDontSee('Last Closed Shift')
        ->assertDontSee('999.00')
        ->assertDontSee('777.00');
});

test('management users see unread admin notifications', function () {
    $user = User::factory()->management()->create();

    AdminNotification::factory()->create([
        'title' => 'Missing Phone Alert',
        'message' => 'A token was issued without a phone number.',
        'read_at' => null,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('Notifications')
        ->assertSee('Missing Phone Alert')
        ->assertSee('A token was issued without a phone number.');
});

test('receptionist users do not see admin notifications', function () {
    $user = User::factory()->receptionist()->create();

    AdminNotification::factory()->create([
        'title' => 'Missing Phone Alert',
        'message' => 'A token was issued without a phone number.',
        'read_at' => null,
    ]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk()
        ->assertDontSee('Notifications')
        ->assertDontSee('Missing Phone Alert');
});

test('a notification can be marked as read from the dashboard', function () {
    $user = User::factory()->management()->create();

    $notification = AdminNotification::factory()->create([
        'title' => 'Missing Phone Alert',
        'message' => 'A token was issued without a phone number.',
        'read_at' => null,
    ]);

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->call('markNotificationAsRead', $notification->id)
        ->assertHasNoErrors();

    expect($notification->fresh()->read_at)->not->toBeNull();
});

test('all notifications can be marked as read from the dashboard', function () {
    $user = User::factory()->management()->create();

    AdminNotification::factory()->count(3)->create(['read_at' => null]);

    Livewire::actingAs($user)
        ->test('pages::dashboard')
        ->call('markAllNotificationsAsRead')
        ->assertHasNoErrors();

    expect(AdminNotification::whereNull('read_at')->count())->toBe(0);
});
