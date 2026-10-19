/**
 * Two quiet signs of just let me know., the business behind the product.
 *
 * Only where the page asks for them - <body data-easter-eggs>, which the
 * public layout and the not-found page set when the admin console's
 * site.easter_eggs setting is on - so never on a document, an email or a
 * portal or console screen. Nothing here makes a request or records
 * anything.
 *
 *   - one line in the browser console;
 *   - typing j, l, m, k anywhere that is not a field shows a small note
 *     that closes on Escape, on its button, or by itself.
 */

const SEQUENCE = 'jlmk';

const SITE = 'https://justletmeknow.app';

/** How long the note stays, unless someone is reading it. */
const LINGER = 6000;

function typingInField(target) {
    return target instanceof HTMLElement && (target.isContentEditable || target.closest('input, textarea, select'));
}

function showNote(body) {
    if (document.querySelector('[data-jlmk-note]')) {
        return;
    }

    const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const note = document.createElement('div');
    note.dataset.jlmkNote = '';
    note.setAttribute('role', 'status');
    note.className =
        'fixed bottom-4 end-4 z-50 flex items-center gap-3 rounded-tile bg-jlmk-ink px-4 py-3 text-sm text-jlmk-paper shadow-raised' +
        (still ? '' : ' transition-opacity duration-300 starting:opacity-0');

    const link = document.createElement('a');
    link.href = SITE;
    link.rel = 'noopener';
    link.lang = 'en';
    link.dir = 'ltr';
    link.className = 'font-semibold lowercase tracking-tight hover:underline underline-offset-4';
    link.append('just let me know');

    const period = document.createElement('span');
    period.className = 'text-jlmk-coral';
    period.textContent = '.';
    link.append(period);

    const close = document.createElement('button');
    close.type = 'button';
    close.className =
        'inline-flex size-11 items-center justify-center rounded-button text-jlmk-paper/70 hover:text-jlmk-paper focus-visible:outline-2 focus-visible:outline-jlmk-coral';
    close.setAttribute('aria-label', body.dataset.easterClose || 'Close');
    close.innerHTML =
        '<svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>';

    note.append(link, close);
    document.body.append(note);

    let timer = window.setTimeout(dismiss, LINGER);

    function dismiss() {
        window.clearTimeout(timer);
        document.removeEventListener('keydown', onKey);
        note.remove();
    }

    function onKey(event) {
        if (event.key === 'Escape') {
            dismiss();
        }
    }

    // Someone pointing at it, or tabbing into it, is reading it.
    note.addEventListener('pointerenter', () => window.clearTimeout(timer));
    note.addEventListener('focusin', () => window.clearTimeout(timer));
    note.addEventListener('pointerleave', () => {
        timer = window.setTimeout(dismiss, LINGER);
    });

    close.addEventListener('click', dismiss);
    document.addEventListener('keydown', onKey);
}

export function registerEasterEggs() {
    const body = document.body;

    if (!body?.hasAttribute('data-easter-eggs')) {
        return;
    }

    console.info(`Built by just let me know. — ${SITE.replace('https://', '')}`);

    let typed = '';

    document.addEventListener('keydown', (event) => {
        if (event.metaKey || event.ctrlKey || event.altKey || typingInField(event.target) || event.key.length !== 1) {
            return;
        }

        typed = (typed + event.key.toLowerCase()).slice(-SEQUENCE.length);

        if (typed === SEQUENCE) {
            typed = '';
            showNote(body);
        }
    });
}
