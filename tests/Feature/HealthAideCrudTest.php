<?php

use App\Models\HealthAide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('admin can visit health aides page', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.health-aides'))
        ->assertSuccessful();
});

test('non admin cannot visit health aides page', function () {
    $user = User::factory()->receptionist()->create();

    $this->actingAs($user)
        ->get(route('admin.health-aides'))
        ->assertForbidden();
});

test('admin can create a health aide with unique pin', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.health-aides')
        ->call('openCreateModal')
        ->set('name', 'Ayesha Khan')
        ->set('pin', '5678')
        ->set('isActive', true)
        ->call('saveAide')
        ->assertHasNoErrors();

    $aide = HealthAide::query()->where('name', 'Ayesha Khan')->first();

    expect($aide)->not->toBeNull()
        ->and($aide->is_active)->toBeTrue();
});

test('admin cannot create health aide with duplicate active pin', function () {
    $admin = User::factory()->admin()->create();
    HealthAide::factory()->create(['pin' => '5678']);

    Livewire::actingAs($admin)
        ->test('pages::admin.health-aides')
        ->call('openCreateModal')
        ->set('name', 'Duplicate Pin')
        ->set('pin', '5678')
        ->call('saveAide')
        ->assertHasErrors(['pin']);

    expect(HealthAide::query()->where('name', 'Duplicate Pin')->exists())->toBeFalse();
});

test('admin can update health aide without changing pin', function () {
    $admin = User::factory()->admin()->create();
    $aide = HealthAide::factory()->create(['name' => 'Old Name', 'pin' => '1234']);

    Livewire::actingAs($admin)
        ->test('pages::admin.health-aides')
        ->call('editAide', $aide->id)
        ->set('name', 'New Name')
        ->set('pin', '')
        ->call('saveAide')
        ->assertHasNoErrors();

    $aide->refresh();

    expect($aide->name)->toBe('New Name')
        ->and(Hash::check('1234', $aide->pin))->toBeTrue();
});
