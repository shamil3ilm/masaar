import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                // The invoice print view: one page size per paper, and the
                // roll's length.
                'resources/css/print/a4.css',
                'resources/css/print/letter.css',
                'resources/css/print/a5.css',
                'resources/css/print/80mm.css',
                'resources/css/print/58mm.css',
                'resources/js/receipt-page.js',
            ],
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
