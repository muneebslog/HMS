<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('welcome page shows token display and tv display links', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertSee(route('display.tokens'));
    $response->assertSee('Token Display');
    $response->assertSee(route('display.tokens.tv'));
    $response->assertSee('TV Display');
});

test('welcome page offers a direct link to the station on a registered pc', function () {
    $station = registerStationDevice(['display.er']);
    $station->update(['name' => 'ER PC 1', 'location' => 'ER Bay']);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Open ER PC 1')
        ->assertSee('ER Bay')
        ->assertSee(route('station.home'));
});

test('welcome page hides the station link on an unregistered pc', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee(route('station.home'));
});
