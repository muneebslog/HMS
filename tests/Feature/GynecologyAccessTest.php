<?php

use App\Http\Middleware\EnsureGynecologist;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Route::middleware(['web', 'auth', EnsureGynecologist::class])
        ->get('/_test/gynecology', fn () => 'gynecology')
        ->name('test.gynecology');
});

test('admins can flag a doctor as a gynecologist', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::management.crud')
        ->set('activeTab', 'doctors')
        ->call('create')
        ->set('doctorName', 'Dr. Ayesha')
        ->set('doctorSpecialization', 'Obs & Gynae')
        ->set('doctorIsGynecologist', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('doctors', [
        'name' => 'Dr. Ayesha',
        'is_gynecologist' => true,
    ]);
});

test('editing a doctor loads and updates the gynecologist flag', function () {
    $admin = User::factory()->admin()->create();
    $doctor = Doctor::factory()->gynecologist()->create();

    Livewire::actingAs($admin)
        ->test('pages::management.crud')
        ->set('activeTab', 'doctors')
        ->call('edit', $doctor->id)
        ->assertSet('doctorIsGynecologist', true)
        ->set('doctorIsGynecologist', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($doctor->refresh()->is_gynecologist)->toBeFalse();
});

test('doctors are not gynecologists by default', function () {
    expect(Doctor::factory()->create()->refresh()->is_gynecologist)->toBeFalse();
});

test('gynecologists scope returns only flagged doctors', function () {
    $gynecologist = Doctor::factory()->gynecologist()->create();
    Doctor::factory()->create();

    expect(Doctor::gynecologists()->pluck('id')->all())->toBe([$gynecologist->id]);
});

test('a linked active gynecologist can access gynecology pages', function () {
    $user = User::factory()->create();
    Doctor::factory()->gynecologist()->forUser($user)->create();

    $this->actingAs($user)->get('/_test/gynecology')->assertOk();
});

test('a linked non gynecologist doctor is forbidden', function () {
    $user = User::factory()->create();
    Doctor::factory()->forUser($user)->create();

    $this->actingAs($user)->get('/_test/gynecology')->assertForbidden();
});

test('an inactive gynecologist is forbidden', function () {
    $user = User::factory()->create();
    Doctor::factory()->gynecologist()->inactive()->forUser($user)->create();

    $this->actingAs($user)->get('/_test/gynecology')->assertForbidden();
});

test('users without a doctor profile are forbidden', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)->get('/_test/gynecology')->assertForbidden();
});

test('guests are redirected to login', function () {
    $this->get('/_test/gynecology')->assertRedirect(route('login'));
});
