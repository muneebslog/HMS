<?php

use App\Http\Middleware\ForceHttpsForDomain;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

beforeEach(fn () => URL::forceScheme(null));

afterEach(fn () => URL::forceScheme(null));

it('forces HTTPS URL generation for the public tunnel domain', function () {
    $request = Request::create('http://mednexus.space/');

    (new ForceHttpsForDomain)->handle($request, fn (Request $req) => new Response('OK'));

    expect(URL::to('/'))->toStartWith('https://');
});

it('leaves HTTP URL generation unchanged for local IP access', function () {
    $request = Request::create('http://192.168.100.104/');

    (new ForceHttpsForDomain)->handle($request, fn (Request $req) => new Response('OK'));

    expect(URL::to('/'))->toStartWith('http://');
});

it('leaves HTTP URL generation unchanged for other hosts', function () {
    $request = Request::create('http://example.test/');

    (new ForceHttpsForDomain)->handle($request, fn (Request $req) => new Response('OK'));

    expect(URL::to('/'))->toStartWith('http://');
});
