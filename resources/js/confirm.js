/**
 * Confirmation before a form that changes or destroys something.
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
 * It asks in our own dialog (x-ui.confirm-dialog) rather than through
 * window.confirm(). The native one is the browser's: its typography, a title
 * bar naming the host, and a button order the platform chooses - so the same
 * screen puts Confirm on the left on one machine and the right on another.
 *
 * Markup, on the form - or on one submit button, when only that one of a
 * form's buttons needs asking (Save and Reset to inherited share a form):
 *   data-confirm         the question, naming the thing. Required; without
 *                        it nothing happens. "Remove customer Acme?"
 *   data-confirm-detail  one sentence: what happens, and whether it can be
 *                        undone.
 *   data-confirm-action  the accept button's verb. "Remove", never "OK".
 *   data-confirm-tone    'danger' for an action that destroys or stops
 *                        something: the accept button is red and Cancel
 *                        takes focus. Otherwise accept is primary and takes
 *                        focus.
 *   data-confirm-type    a word or name to type before accept enables, for
 *                        the irreversible few. The field takes focus.
 *
 * A form holding the approval reason (admin.partials.approval-reason, there
 * only while the action's approval policy is on) is told that it will be
 * sent for approval, without saying so twice in every view. A form whose
 * own reason doubles as the request's (marking a certificate revoked) says
 * so with data-confirm-approval instead.
 *
 * Capture phase, so it runs before anything a page adds for itself.
 */

/** Set on a form while it is being re-submitted, so we do not ask twice. */
const CONFIRMED = 'confirmed';

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])';

function dialog() {
    return document.querySelector('[data-confirm-dialog]');
}

/** Typed words match regardless of case, spacing at the ends, or how an Arabic letter was composed. */
function normalise(text) {
    return text.normalize('NFC').trim().toLocaleLowerCase();
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

    const focusable = Array.from(panel.querySelectorAll(FOCUSABLE)).filter((el) => !el.closest('[hidden]'));

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
 * The browser's own dialog, with the same words, for a page whose layout
 * forgot the component. Worse than ours, but not asking at all before
 * something irreversible is worse still.
 */
function askNatively({ title, detail, approval, word }) {
    const text = [title, detail, approval ? 'This will be sent for approval.' : ''].filter(Boolean).join('\n\n');

    if (word) {
        const typed = window.prompt(`${text}\n\nType "${word}" to confirm.`);

        return Promise.resolve(typed !== null && normalise(typed) === normalise(word));
    }

    return Promise.resolve(window.confirm(text));
}

/** "Type :word to confirm." with the word in bold, built without innerHTML. */
function fillTypedLabel(label, word) {
    const [before, after = ''] = (label.dataset.template ?? ':word').split(':word');
    const strong = document.createElement('strong');
    strong.textContent = word;
    strong.setAttribute('dir', 'auto');

    label.replaceChildren(before, strong, after);
}

/**
 * @param {{title: string, detail: string, tone: string, action: string, word: string, approval: boolean}} question
 * @returns {Promise<boolean>}
 */
function ask(question) {
    const panel = dialog();

    if (!panel) {
        return askNatively(question);
    }

    const { title, detail, tone, action, word, approval } = question;
    const returnTo = document.activeElement;

    panel.querySelector('[data-confirm-title]').textContent = title;

    const message = panel.querySelector('[data-confirm-message]');
    message.textContent = detail;
    message.toggleAttribute('hidden', detail === '');

    panel.querySelector('[data-confirm-approval]').toggleAttribute('hidden', !approval);

    const typed = panel.querySelector('[data-confirm-typed]');
    const input = panel.querySelector('[data-confirm-typed-input]');
    typed.toggleAttribute('hidden', !word);
    input.value = '';

    if (word) {
        fillTypedLabel(panel.querySelector('[data-confirm-typed-label]'), word);
    }

    const wanted = tone === 'danger' ? 'danger' : 'primary';
    let accept = null;

    for (const button of panel.querySelectorAll('[data-confirm-accept]')) {
        const matches = button.dataset.tone === wanted;
        button.toggleAttribute('hidden', !matches);

        if (matches) {
            button.textContent = action;
            button.disabled = Boolean(word);
            accept = button;
        }
    }

    panel.removeAttribute('hidden');

    // The page behind must not scroll under a modal.
    document.body.style.overflow = 'hidden';

    const cancel = panel.querySelector('[data-confirm-cancel]');

    // The safe answer is the one a stray Enter reaches on a dialog whose
    // other answer destroys something; on one that only asks, the answer is
    // the action. A typed confirmation starts in its field, with accept
    // disabled, so Enter there cannot reach it early.
    if (word) {
        input.focus();
    } else if (wanted === 'danger') {
        cancel.focus();
    } else {
        accept.focus();
    }

    return new Promise((resolve) => {
        const matched = () => !word || normalise(input.value) === normalise(word);

        const finish = (answer) => {
            panel.setAttribute('hidden', '');
            document.body.style.overflow = '';

            panel.removeEventListener('click', onClick);
            input.removeEventListener('input', onInput);
            document.removeEventListener('keydown', onKey, true);

            if (returnTo instanceof HTMLElement) {
                returnTo.focus();
            }

            resolve(answer);
        };

        const onInput = () => {
            accept.disabled = !matched();
        };

        const onClick = (event) => {
            if (event.target.closest('[data-confirm-accept]:not([hidden]):not([disabled])')) {
                finish(true);
            } else if (event.target.closest('[data-confirm-cancel], [data-confirm-backdrop]')) {
                finish(false);
            }
        };

        const onKey = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                finish(false);
            } else if (event.key === 'Enter' && event.target === input) {
                event.preventDefault();

                if (matched()) {
                    finish(true);
                }
            } else {
                trap(panel, event);
            }
        };

        panel.addEventListener('click', onClick);
        input.addEventListener('input', onInput);
        document.addEventListener('keydown', onKey, true);
    });
}

/**
 * The question a submit asks: the clicked button's, when it carries one,
 * otherwise the form's.
 *
 * @param {HTMLFormElement} form
 * @param {HTMLElement|null} submitter
 */
function questionFor(form, submitter) {
    const source = submitter?.dataset.confirm ? submitter : form;

    if (!source.dataset.confirm) {
        return null;
    }

    return {
        title: source.dataset.confirm,
        detail: source.dataset.confirmDetail ?? '',
        tone: source.dataset.confirmTone ?? 'primary',
        action: source.dataset.confirmAction ?? 'Confirm',
        word: source.dataset.confirmType ?? '',
        approval: form.hasAttribute('data-confirm-approval') || form.querySelector('[name="approval_reason"]') !== null,
    };
}

export function registerConfirmations() {
    document.addEventListener(
        'submit',
        (event) => {
            const form = event.target;

            if (!(form instanceof HTMLFormElement) || form.dataset[CONFIRMED] === 'true') {
                return;
            }

            const submitter = event.submitter instanceof HTMLElement ? event.submitter : null;
            const question = questionFor(form, submitter);

            if (!question) {
                return;
            }

            // The dialog answers later; a submit does not wait. So this one
            // is always stopped, and a second submit is issued on yes.
            event.preventDefault();
            event.stopPropagation();

            ask(question).then((answer) => {
                if (!answer) {
                    return;
                }

                form.dataset[CONFIRMED] = 'true';

                // requestSubmit, not submit(): submit() skips validation and
                // the submit event, so a form with a required field would
                // post empty and our own validation would never run. The
                // submitter goes with it, so a button's name and value
                // (reset=1) still reach the server.
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
                } else {
                    form.submit();
                }

                // requestSubmit dispatches synchronously, so the flag has
                // done its job: cleared, a page restored from the back-forward
                // cache asks again.
                delete form.dataset[CONFIRMED];
            });
        },
        true
    );
}
