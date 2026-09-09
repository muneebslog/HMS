import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

/**
 * Support both LAN access (http://192.168.x.x) and public HTTPS
 * (https://mednexus.space via Cloudflare) with one asset build.
 */
const hostname = window.location.hostname;
const isSecure = window.location.protocol === 'https:';
const isLanHost = hostname === 'localhost'
    || hostname === '127.0.0.1'
    || /^\d{1,3}(?:\.\d{1,3}){3}$/.test(hostname);

const localPort = Number(import.meta.env.VITE_REVERB_LOCAL_PORT ?? 8081);
const publicPort = Number(import.meta.env.VITE_REVERB_PORT ?? (isSecure ? 443 : 80));
const port = isLanHost ? localPort : publicPort;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: hostname,
    wsPort: port,
    wssPort: port,
    forceTLS: isSecure,
    enabledTransports: isSecure ? ['wss'] : ['ws'],
});
