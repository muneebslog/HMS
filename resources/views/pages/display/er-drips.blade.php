<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ __('ER & Drip Stations') }} - {{ config('app.name', 'HMS') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">

        @vite(['resources/css/app.css'])
    </head>
    <body class="h-screen overflow-hidden bg-zinc-950">
        <main class="grid h-full grid-cols-2 divide-x divide-zinc-700">
            <iframe
                src="{{ route('display.er') }}"
                title="{{ __('ER Station') }}"
                class="h-full w-full border-0"
            ></iframe>
            <iframe
                src="{{ route('display.drips') }}"
                title="{{ __('Drip Delivery') }}"
                class="h-full w-full border-0"
            ></iframe>
        </main>
    </body>
</html>
