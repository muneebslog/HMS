<?php

use App\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

afterEach(function () {
    Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
});

it('trusts X-Forwarded-Proto from any upstream proxy', function () {
    $request = Request::create(
        'http://mednexus.space/',
        'GET',
        [],
        [],
        [],
        [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]
    );

    (new TrustProxies)->handle($request, fn (Request $req) => new Response('OK'));

    expect($request->isSecure())->toBeTrue()
        ->and($request->getScheme())->toBe('https');
});

it('does not mark the request as secure when no forwarded proto is provided', function () {
    $request = Request::create(
        'http://192.168.100.104/',
        'GET',
        [],
        [],
        [],
        ['REMOTE_ADDR' => '192.168.100.1']
    );

    (new TrustProxies)->handle($request, fn (Request $req) => new Response('OK'));

    expect($request->isSecure())->toBeFalse()
        ->and($request->getScheme())->toBe('http');
});
