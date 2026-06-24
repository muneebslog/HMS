<?php

use App\Enums\UserRole;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

$routeMap = [
    'admin' => [
        'management.crud',
        'admin.users',
    ],
    'management' => [
        'reception.invoices',
        'payout.doctor',
    ],
    'receptionist' => [
        'reception.walkin',
        'reception.reservation',
        'reception.lab-entry',
        'reception.procedures',
        'reception.queue',
        'payout.daily',
    ],
    'shared' => [
        'reception.shift',
        'dashboard',
    ],
];

test('admins can access all protected routes', function () use ($routeMap) {
    $user = User::factory()->admin()->create();

    foreach (array_merge($routeMap['admin'], $routeMap['management'], $routeMap['receptionist'], $routeMap['shared']) as $route) {
        if ($route === 'reception.walkin' || $route === 'reception.reservation' || $route === 'reception.lab-entry' || $route === 'reception.procedures' || $route === 'reception.queue' || $route === 'reception.invoices') {
            Shift::factory()->for($user)->open()->create();
        }

        $this->actingAs($user)
            ->get(route($route))
            ->assertSuccessful();
    }
});

test('receptionists are blocked from admin and management routes', function () use ($routeMap) {
    $user = User::factory()->receptionist()->create();

    foreach (array_merge($routeMap['admin'], $routeMap['management']) as $route) {
        $this->actingAs($user)
            ->get(route($route))
            ->assertForbidden();
    }
});

test('receptionists can access their own routes', function () use ($routeMap) {
    $user = User::factory()->receptionist()->create();
    Shift::factory()->for($user)->open()->create();

    foreach (array_merge($routeMap['receptionist'], $routeMap['shared']) as $route) {
        $this->actingAs($user)
            ->get(route($route))
            ->assertSuccessful();
    }
});

test('management can access their own routes', function () use ($routeMap) {
    $user = User::factory()->management()->create();
    Shift::factory()->for($user)->open()->create();

    foreach (array_merge($routeMap['management'], $routeMap['shared']) as $route) {
        $this->actingAs($user)
            ->get(route($route))
            ->assertSuccessful();
    }
});

test('management is blocked from admin and receptionist routes', function () use ($routeMap) {
    $user = User::factory()->management()->create();

    foreach (array_merge($routeMap['admin'], $routeMap['receptionist']) as $route) {
        $this->actingAs($user)
            ->get(route($route))
            ->assertForbidden();
    }
});

test('unauthenticated users are redirected to login', function () use ($routeMap) {
    foreach (array_merge($routeMap['admin'], $routeMap['management'], $routeMap['receptionist'], $routeMap['shared']) as $route) {
        $this->get(route($route))
            ->assertRedirect(route('login'));
    }
});
