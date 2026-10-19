/**
 * A hex field beside x-ui.color-input's swatch.
 *
 * The swatch is the browser's colour input, and the value; this field shows
 * its code and takes a typed or pasted one (#14B8A6, 14b8a6, #1bc), setting
 * the swatch as soon as it is a colour. A code that is not one is said in
 * our words (setCustomValidity), and the form will not send it.
 */

import { enhance } from './enhance.js';
import { t } from './strings.js';

/** @returns {string|null}  #rrggbb */
export function hex(text) {
    const match = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(text.trim());

    if (!match) {
        return null;
    }

    const digits = match[1].length === 3 ? match[1].replace(/./g, (digit) => digit + digit) : match[1];

    return `#${digits.toLowerCase()}`;
}

/** @param {HTMLElement} host  the element carrying data-color-field */
function setUp(host) {
    const swatch = host.querySelector('input[type="color"]');

    if (!(swatch instanceof HTMLInputElement)) {
        return;
    }

    const field = document.createElement('input');
    field.type = 'text';
    field.dir = 'ltr';
    field.spellcheck = false;
    field.autocomplete = 'off';
    field.maxLength = 7;
    field.className =
        'block min-h-11 w-28 rounded-control border border-line-strong bg-surface px-3 py-2 font-mono text-sm uppercase text-fg shadow-card focus:border-focus focus:outline-none focus:ring-3 focus:ring-focus/25 aria-invalid:border-danger';
    field.setAttribute('aria-label', t(':name, hex code', { name: swatch.getAttribute('aria-label') || t('Colour') }));
    field.value = swatch.value.toUpperCase();
    swatch.after(field);

    swatch.addEventListener('input', () => {
        field.value = swatch.value.toUpperCase();
        field.removeAttribute('aria-invalid');
        swatch.setCustomValidity('');
    });

    field.addEventListener('input', () => {
        const value = hex(field.value);

        if (value) {
            swatch.value = value;
            swatch.setCustomValidity('');
            field.removeAttribute('aria-invalid');
            swatch.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });

    field.addEventListener('change', () => {
        const value = hex(field.value);

        if (value) {
            field.value = value.toUpperCase();
        } else {
            swatch.setCustomValidity(t('Enter a colour as a six-digit hex code.'));
            field.setAttribute('aria-invalid', 'true');
        }
    });
}

export function registerColorFields() {
    enhance('[data-color-field]', setUp);
}
