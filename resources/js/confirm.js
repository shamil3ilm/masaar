/**
 * Confirmation before a form that cannot be undone.
 *
 * This replaces `onsubmit="return confirm('...')"` on four forms, for two
 * reasons.
 *
 * The first is that an inline handler is script in an attribute, and a
 * Content-Security-Policy strict enough to be worth setting refuses to run
 * it. Four confirmations would have disappeared the day the header went on,
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
 * Capture phase, so it runs before anything a page adds for itself.
 */
export function registerConfirmations() {
    document.addEventListener(
        'submit',
        (event) => {
            const form = event.target;

            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            const message = form.dataset.confirm;

            if (!message) {
                return;
            }

            if (!window.confirm(message)) {
                event.preventDefault();
                event.stopPropagation();
            }
        },
        true
    );
}
