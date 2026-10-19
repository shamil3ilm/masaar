/**
 * A show-password button on every password field.
 *
 * Some browsers draw one (Edge's eye), most do not, and the ones that do
 * draw it their own way; app.css hides Edge's and this draws ours, the same
 * everywhere. A toggle button (aria-pressed) beside the field: pressed, the
 * field shows what was typed. The field goes back to hidden before its form
 * is sent, so a browser does not offer to remember it as plain text.
 */

import { enhance } from './enhance.js';
import { t } from './strings.js';

const EYE =
    '<svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12S6 5 12 5s9.5 7 9.5 7-3.5 7-9.5 7-9.5-7-9.5-7z"/><path d="M12 15a3 3 0 100-6 3 3 0 000 6z"/></svg>';

const EYE_OFF =
    '<svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18M10.6 5.1A9.8 9.8 0 0112 5c6 0 9.5 7 9.5 7a17 17 0 01-2.9 3.8M6.6 6.6A17 17 0 002.5 12S6 19 12 19a9.5 9.5 0 004.6-1.2M9.9 9.9a3 3 0 004.2 4.2"/></svg>';

/** @param {HTMLInputElement} input */
function setUp(input) {
    const box = document.createElement('span');
    box.className = 'relative mt-1 block';
    input.before(box);
    box.append(input);
    input.classList.remove('mt-1');
    input.classList.add('pe-12');

    const button = document.createElement('button');
    button.type = 'button';
    button.className =
        'absolute inset-y-0 end-0 m-0.5 inline-flex w-11 items-center justify-center rounded-control text-subtle hover:bg-sunken hover:text-fg focus-visible:outline-2 focus-visible:outline-focus';
    button.setAttribute('aria-label', t('Show password'));
    button.setAttribute('aria-pressed', 'false');
    button.innerHTML = EYE;
    box.append(button);

    const show = (visible) => {
        input.type = visible ? 'text' : 'password';
        button.setAttribute('aria-pressed', visible ? 'true' : 'false');
        button.innerHTML = visible ? EYE_OFF : EYE;
    };

    button.addEventListener('click', (event) => {
        event.preventDefault();
        show(input.type === 'password');
        input.focus();
    });

    input.form?.addEventListener('submit', () => show(false));
}

export function registerPasswordFields() {
    enhance('input[type="password"]', setUp);
}
