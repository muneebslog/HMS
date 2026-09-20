<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

test('checklist questions route is no longer registered', function () {
    expect(Route::has('admin.supervisor-questions'))->toBeFalse();
});

test('checklist summary route is no longer registered', function () {
    expect(Route::has('admin.supervisor-checklist'))->toBeFalse();
});

test('checklist admin pages are removed from page access registry', function () {
    $pages = array_keys(config('pages.pages'));

    expect($pages)->not->toContain('admin.supervisor-questions');
    expect($pages)->not->toContain('admin.supervisor-checklist');
});

test('checklist questions url returns not found', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/admin/supervisor-questions')
        ->assertNotFound();
});

test('checklist summary url returns not found', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/admin/supervisor-checklist')
        ->assertNotFound();
});
