<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ __('Station not registered') }} - {{ config('app.name', 'HMS') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">

        @vite(['resources/css/app.css'])
    </head>
    <body class="flex min-h-screen items-center justify-center bg-zinc-950 p-6 text-white">
        <main class="w-full max-w-md space-y-4 rounded-2xl border border-zinc-800 bg-zinc-900 p-8 text-center">
            @if ($station)
                <h1 class="text-xl font-semibold">{{ __('This page is not enabled for this station') }}</h1>
                <p class="text-sm text-zinc-400">
                    {{ __('This PC is registered as :name, which is not allowed to open this page.', ['name' => $station->name]) }}
                </p>
                @if ($station->homeRouteName())
                    <a href="{{ route($station->homeRouteName()) }}" class="inline-block rounded-lg bg-white px-4 py-2 text-sm font-medium text-zinc-900">
                        {{ __('Go to station page') }}
                    </a>
                @endif
            @else
                <h1 class="text-xl font-semibold">{{ __('This PC is not registered as a station') }}</h1>
                <p class="text-sm text-zinc-400">
                    {{ __('Ask an admin to sign in on this PC once and register it from Extras → Stations. Staff can also sign in to open this page.') }}
                </p>
                <a href="{{ route('login') }}" class="inline-block rounded-lg bg-white px-4 py-2 text-sm font-medium text-zinc-900">
                    {{ __('Sign in') }}
                </a>
            @endif
        </main>
    </body>
</html>
