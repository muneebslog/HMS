<?php

test('welcome page shows token display link', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertSee(route('display.tokens'));
    $response->assertSee('Token Display');
});
