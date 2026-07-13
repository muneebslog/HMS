<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
        <meta http-equiv="refresh" content="5" />

        <title>
            {{ filled($title ?? null) ? $title.' - '.config('app.name', 'HMS') : config('app.name', 'HMS') }}
        </title>

        <link rel="icon" href="/favicon.ico" sizes="any">

        <style>
            *, *::before, *::after {
                box-sizing: border-box;
            }

            html, body {
                margin: 0;
                padding: 0;
                min-height: 100%;
                width: 100%;
                overflow-x: hidden;
                overflow-y: auto;
            }

            body {
                font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                font-size: 16px;
                line-height: 16px;
                background-color: #09090b;
                color: #ffffff;
                -webkit-font-smoothing: antialiased;
            }

            h1, h2, h3, h4, h5, h6, p {
                margin: 0;
            }

            a {
                color: inherit;
                text-decoration: none;
            }

            button {
                font-family: inherit;
                font-size: inherit;
                cursor: pointer;
            }
        </style>
    </head>
    <body>
        @yield('content')
    </body>
</html>
