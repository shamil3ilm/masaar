/**
 * What CSS cannot do for x-ui.tooltip: describe a trigger the slot brought,
 * show on a long press, put it away with Escape, and keep it on screen.
 *
 * Hover and focus need no script (group-hover, group-focus-within). A touch
 * screen has neither, so a press held for half a second shows the note
 * until the next touch elsewhere - the pattern phones use for a label on a
 * long-pressed icon. Escape hides it while the pointer or focus stays, as
 * WCAG 1.4.13 asks of content that appears on hover or focus.
 */

import { enhance } from './enhance.js';

const HOLD = 500;

/** @param {HTMLElement} host  the element carrying data-tooltip */
function setUp(host) {
    const tip = document.getElementById(host.dataset.tooltipFor);
    const trigger = host.querySelector('a, button, input, select, textarea, [tabindex]');

    if (!tip) {
        return;
    }

    if (trigger && trigger !== tip && !trigger.hasAttribute('aria-describedby')) {
        trigger.setAttribute('aria-describedby', tip.id);
    } else if (!trigger) {
        // Plain text as the trigger: reachable by keyboard so the note is.
        host.tabIndex = 0;
        host.setAttribute('aria-describedby', tip.id);
    }

    // Kept inside the viewport: shifted along the line by what it overhangs.
    const fit = () => {
        tip.style.marginInlineStart = '';
        const box = tip.getBoundingClientRect();
        const overhang = box.left < 8 ? 8 - box.left : box.right > window.innerWidth - 8 ? window.innerWidth - 8 - box.right : 0;

        if (overhang !== 0) {
            const rtl = getComputedStyle(host).direction === 'rtl';
            tip.style.marginInlineStart = `${rtl ? -overhang : overhang}px`;
        }
    };

    host.addEventListener('pointerenter', fit);
    host.addEventListener('focusin', fit);

    let timer = 0;

    host.addEventListener('touchstart', () => {
        timer = window.setTimeout(() => {
            host.setAttribute('data-shown', '');
            fit();
        }, HOLD);
    }, { passive: true });

    for (const type of ['touchend', 'touchmove', 'touchcancel']) {
        host.addEventListener(type, () => window.clearTimeout(timer), { passive: true });
    }

    // The long press would otherwise open the browser's own menu over it.
    host.addEventListener('contextmenu', (event) => {
        if (host.hasAttribute('data-shown')) {
            event.preventDefault();
        }
    });

    document.addEventListener('touchstart', (event) => {
        if (!host.contains(event.target)) {
            host.removeAttribute('data-shown');
        }
    }, { passive: true });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && (host.matches(':hover') || host.contains(document.activeElement) || host.hasAttribute('data-shown'))) {
            host.removeAttribute('data-shown');
            host.setAttribute('data-dismissed', '');
        }
    });

    host.addEventListener('pointerleave', () => host.removeAttribute('data-dismissed'));
    host.addEventListener('focusout', () => host.removeAttribute('data-dismissed'));
}

export function registerTooltips() {
    enhance('[data-tooltip]', setUp);
}
