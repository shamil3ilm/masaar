import Alpine from 'alpinejs';

// Exposed on window because the consoles drive Alpine from inline
// expressions in Blade (x-data, x-show, @click), which need the global.
window.Alpine = Alpine;

Alpine.start();
