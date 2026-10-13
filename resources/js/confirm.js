/**
 * Confirmation before a form that cannot be undone.
 *
 * This replaced `onsubmit="return confirm('...')"` on nine forms, for two
 * reasons.
 *
 * The first is that an inline handler is script in an attribute, and a
 * Content-Security-Policy strict enough to be worth setting refuses to run
 * it. Nine confirmations would have disappeared the day the header went on,
 * silently, because a blocked handler does not stop the form - it stops the
 * asking.
 *
 * The second is that it had already happened once. The message for the
 * regenerate-secret button contained an apostrophe; the escaping was wrong,
 * the apostrophe closed the JavaScript string, and the browser discarded the
 * attribute. The most destructive button on the page lost its confirmation
 * and nothing anywhere said so. A data attribute cannot fail that way: Blade
 * escapes it, and the text never becomes code.
 *
 * It now asks in our own dialog rather than through window.confirm(). The
 * native one is the browser's: its typography, a title bar naming the host,
 * and a button order the platform chooses - so the same screen puts Confirm
 * on the left on one machine and the right on another. On a button that
 * revokes a licence, where the two answers looked identical, that is worth
 * more than consistency.
 *
 * Markup:
 *   data-confirm        the question. Required; without it nothing happens.
 *   data-confirm-tone   'danger' colours the accept button red. Default primary.
 *   data-confirm-action the accept button's label. Default 'Confirm'.
 *
 * Capture phase, so it runs before anything a page adds for itself.
 */

/** Set on a form while it is being re-submitted, so we do not ask twice. */
const CONFIRMED = 'confirmed';

const FOCUSABLE = 'a[href], button:not([disabled]), input, select, textarea, [tabindex]:not([tabindex="-1"])';

function dialog() {
    return document.querySelector('[data-confirm-dialog]');
}

/**
 * Keep Tab inside the dialog.
 *
 * Without this, tabbing walks out of a modal and onto the page behind it,
 * which is still there and still has the button that opened it.
 */
function trap(panel, event) {
    if (event.key !== 'Tab') {
        return;
    }

    const focusable = Array.from(panel.querySelectorAll(FOCUSABLE)).filter((el) => !el.hasAttribute('hidden'));

    if (focusable.length === 0) {
        return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

/**
 * @param {{message: string, tone: string, action: string}} question
 * @returns {Promise<boolean>}
 */
function ask({ message, tone, action }) {
    const panel = dialog();

    // No dialog on the page - an unstyled page, or a layout that forgot the
    // component. Falling back to the browser's own is worse than asking in
    // the wrong font: it is not asking at all before something irreversible.
    if (!panel) {
        return Promise.resolve(window.confirm(message));
    }

    const returnTo = document.activeElement;

    panel.querySelector('[data-confirm-message]').textContent = message;

    const accepts = Array.from(panel.querySelectorAll('[data-confirm-accept]'));
    const wanted = tone === 'danger' ? 'danger' : 'primary';

    for (const button of accepts) {
        const matches = button.dataset.tone === wanted;
        button.toggleAttribute('hidden', !matches);

        if (matches) {
            button.textContent = action;
        }
    }

    panel.removeAttribute('hidden');

    // The page behind must not scroll under a modal.
    document.body.style.overflow = 'hidden';

    const cancel = panel.querySelector('[data-confirm-cancel]');

    // Cancel, not accept: the safe answer should be the one a stray Enter
    // reaches, on a dialog whose other answer revokes a licence.
    cancel.focus();

    return new Promise((resolve) => {
        const finish = (answer) => {
            panel.setAttribute('hidden', '');
            document.body.style.overflow = '';

            panel.removeEventListener('click', onClick);
            document.removeEventListener('keydown', onKey, true);

            if (returnTo instanceof HTMLElement) {
                returnTo.focus();
            }

            resolve(answer);
        };

        const onClick = (event) => {
            if (event.target.closest('[data-confirm-accept]:not([hidden])')) {
                finish(true);
            } else if (event.target.closest('[data-confirm-cancel], [data-confirm-backdrop]')) {
                finish(false);
            }
        };

        const onKey = (event) => {
            if (event.key === 'Escape') {
                finish(false);
            } else {
                trap(panel, event);
            }
        };

        panel.addEventListener('click', onClick);
        document.addEventListener('keydown', onKey, true);
    });
}

export function registerConfirmations() {
    document.addEventListener(
        'submit',
        (event) => {
            const form = event.target;

            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            const message = form.dataset.confirm;

            if (!message || form.dataset[CONFIRMED] === 'true') {
                return;
            }

            // The dialog answers later; a submit does not wait. So this one
            // is always stopped, and a second submit is issued on yes.
            event.preventDefault();
            event.stopPropagation();

            ask({
                message,
                tone: form.dataset.confirmTone ?? 'primary',
                action: form.dataset.confirmAction ?? 'Confirm',
            }).then((answer) => {
                if (!answer) {
                    return;
                }

                form.dataset[CONFIRMED] = 'true';

                // requestSubmit, not submit(): submit() skips validation and
                // the submit event, so a form with a required field would
                // post empty and our own validation would never run.
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            });
        },
        true
    );
}
