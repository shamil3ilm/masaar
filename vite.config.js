import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        // An IPv4 address, not localhost: where localhost resolves to ::1 the
        // dev server's origin is http://[::1]:5173, and browsers reject an
        // IPv6 literal as a Content-Security-Policy source - so the policy
        // dropped it and blocked every stylesheet and script it serves.
        host: '127.0.0.1',
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
