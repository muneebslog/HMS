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
        <style>
            #tv-fullscreen-toggle {
                position: fixed;
                right: 24px;
                bottom: 24px;
                z-index: 9999;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 48px;
                height: 48px;
                padding: 0;
                color: #ffffff;
                background-color: #18181b;
                border: 1px solid #3f3f46;
                border-radius: 12px;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
                cursor: pointer;
            }

            @media (max-width: 1023px) {
                #tv-fullscreen-toggle {
                    bottom: 80px;
                }
            }
        </style>
    </head>
    <body>
        @yield('content')

        <button id="tv-fullscreen-toggle" type="button" aria-label="Enter fullscreen"></button>

        <script>
            (function () {
                var btn = document.getElementById('tv-fullscreen-toggle');
                if (! btn) {
                    return;
                }

                var enterIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8V4h4M20 8V4h-4M4 16v4h4M20 16v4h-4"/></svg>';
                var exitIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M8 4v4H4M16 4v4h4M8 20v-4H4M16 20v-4h4"/></svg>';

                function isFullscreen() {
                    var doc = document;
                    return !!(doc.fullscreenElement || doc.webkitFullscreenElement || doc.msFullscreenElement);
                }

                function updateIcon() {
                    btn.innerHTML = isFullscreen() ? exitIcon : enterIcon;
                    btn.setAttribute('aria-label', isFullscreen() ? 'Exit fullscreen' : 'Enter fullscreen');
                }

                btn.addEventListener('click', function () {
                    var doc = document;
                    var el = doc.documentElement;

                    if (! isFullscreen()) {
                        if (el.requestFullscreen) {
                            el.requestFullscreen();
                        } else if (el.webkitRequestFullscreen) {
                            el.webkitRequestFullscreen();
                        } else if (el.msRequestFullscreen) {
                            el.msRequestFullscreen();
                        }
                    } else {
                        if (doc.exitFullscreen) {
                            doc.exitFullscreen();
                        } else if (doc.webkitExitFullscreen) {
                            doc.webkitExitFullscreen();
                        } else if (doc.msExitFullscreen) {
                            doc.msExitFullscreen();
                        }
                    }
                });

                doc.addEventListener('fullscreenchange', updateIcon);
                doc.addEventListener('webkitfullscreenchange', updateIcon);
                doc.addEventListener('MSFullscreenChange', updateIcon);

                updateIcon();
            })();
        </script>
    </body>
</html>
