import { networkInterfaces } from 'node:os';
import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig(({ mode }) => {
    // Set VITE_DEV_HOST in .env to this PC's LAN address (e.g. 192.168.1.38)
    // to use `npm run dev` from a phone on the same network, or to `auto` to
    // detect the current LAN address each time the server starts (the router
    // may hand this PC a new one). Unset, the dev server stays on localhost.
    const configuredHost = loadEnv(mode, process.cwd(), 'VITE_').VITE_DEV_HOST;
    const devHost = configuredHost === 'auto'
        ? Object.values(networkInterfaces()).flat()
            .find((nic) => nic && nic.family === 'IPv4' && !nic.internal
                && /^(192\.168\.|10\.|172\.(1[6-9]|2\d|3[01])\.)/.test(nic.address))?.address
        : configuredHost;

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.js'],
                refresh: true,
                fonts: [
                    bunny('Poppins', {
                        weights: [400, 500, 600, 700],
                    }),
                ],
            }),
            vue({
                template: {
                    transformAssetUrls: {
                        base: null,
                        includeAbsolute: false,
                    },
                },
            }),
            tailwindcss(),
        ],
        server: {
            // The page (port 8000) loads its modules from this server (port
            // 5173): a cross-origin request, so the LAN address must be allowed.
            ...(devHost
                ? { host: '0.0.0.0', hmr: { host: devHost }, cors: { origin: [/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/, new RegExp(`^https?://${devHost.replace(/\./g, '\\.')}(:\\d+)?$`)] } }
                : {}),
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
