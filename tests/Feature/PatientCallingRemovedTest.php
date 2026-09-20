<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('patient calling route is no longer registered', function () {
    expect(Route::has('reception.patient-calling'))->toBeFalse();
});

test('patient calling is removed from page access registry', function () {
    $pages = array_keys(config('pages.pages'));

    expect($pages)->not->toContain('reception.patient-calling');

    $receptionDefaults = config('pages.defaults.'.UserRole::Receptionist->value);

    expect($receptionDefaults)->not->toContain('reception.patient-calling');
});

test('patient_calls table is dropped', function () {
    expect(Schema::hasTable('patient_calls'))->toBeFalse();
});

test('patient calling url returns not found', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/reception/patient-calling')
        ->assertNotFound();
});
