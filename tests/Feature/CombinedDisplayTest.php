<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('combined display shows er and drip stations side by side', function () {
    $this->get(route('display.er_drips'))
        ->assertSuccessful()
        ->assertSee('grid-cols-2', false)
        ->assertSee('src="'.route('display.er').'"', false)
        ->assertSee('src="'.route('display.drips').'"', false);
});

test('combined display link appears in the system sidebar', function () {
    $user = User::factory()->admin()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee(__('ER + Drips'))
        ->assertSee(route('display.er_drips'), false);
});

test('indoor staff see system pages in the sidebar', function () {
    $user = User::factory()->indoor()->create();

    $this->actingAs($user)
        ->get(route('indoor.ward'))
        ->assertSuccessful()
        ->assertSee(__('System'))
        ->assertSee(__('Token Display'))
        ->assertSee(__('ER Station'))
        ->assertSee(__('Drip Delivery'))
        ->assertSee(__('ER + Drips'))
        ->assertSee(route('display.er_drips'), false)
        ->assertDontSee(route('reception.shift'), false);
});
