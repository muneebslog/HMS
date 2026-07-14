<?php

test('welcome page shows token display and tv display links', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertSee(route('display.tokens'));
    $response->assertSee('Token Display');
    $response->assertSee(route('display.tokens.tv'));
    $response->assertSee('TV Display');
});
