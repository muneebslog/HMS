<?php

use App\Http\Middleware\EnsureDisplayKioskSession;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

afterEach(function () {
    config([
        'display.session_lifetime_minutes' => 10080,
        'session.lifetime' => 120,
        'session.secure' => null,
        'app.force_https_host' => 'mednexus.space',
    ]);
});

it('extends session lifetime on display routes', function () {
    $request = Request::create('http://192.168.100.104/display/er');

    (new EnsureDisplayKioskSession)->handle($request, fn (Request $req) => new Response('OK'));

    expect(config('session.lifetime'))->toBe(10080);
});

it('forces non-secure session cookies for local ip display access', function () {
    $request = Request::create('http://192.168.100.104/display/stock');

    (new EnsureDisplayKioskSession)->handle($request, fn (Request $req) => new Response('OK'));

    expect(config('session.secure'))->toBeFalse();
});

it('leaves secure session cookies enabled on the public tunnel host', function () {
    $request = Request::create('https://mednexus.space/display/er');

    (new EnsureDisplayKioskSession)->handle($request, fn (Request $req) => new Response('OK'));

    expect(config('session.secure'))->toBeNull();
});

it('does not change session settings for non-display routes', function () {
    $request = Request::create('http://192.168.100.104/login');

    (new EnsureDisplayKioskSession)->handle($request, fn (Request $req) => new Response('OK'));

    expect(config('session.lifetime'))->toBe(120)
        ->and(config('session.secure'))->toBeNull();
});
