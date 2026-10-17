/**
 * Our own validation messages, instead of the browser's bubble.
 *
 * A required field left empty produced a native tooltip - "Please fill out
 * this field." - in the browser's own typography, anchored wherever the
 * browser felt like, and gone the moment anything else was clicked. On the
 * sign-in form that meant the only feedback a person got looked nothing like
 * the rest of the application and could not be read twice.
 *
 * It is also inconsistent with what the server says. A field that fails
 * server-side validation comes back with a message in our styling, under the
 * control; the same field failing client-side got a floating grey box. Same
 * failure, two interfaces.
 *
 * The native constraints themselves are kept - required, type="email",
 * minlength, min and max still do the checking, so there is no second set of
 * rules to drift out of step with the server's. Only the bubble is replaced:
 * preventDefault on the invalid event suppresses it, and nothing else about
 * constraint validation changes. A form that is invalid still does not
 * submit.
 *
 * Capture phase, because `invalid` does not bubble. It fires on the control,
 * so a listener on the document only sees it on the way down.
 */

const MESSAGE_CLASS = 'mt-1 text-xs font-medium text-danger';

const INVALID_CONTROL_CLASSES = ['border-danger'];

/**
 * What to say, in order of specificity.
 *
 * validationMessage is the fallback rather than the first choice: it is the
 * browser's wording, so it varies between browsers and ignores the language
 * the rest of the page is in.
 *
 * @param {HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement} control
 * @returns {string}
 */
function messageFor(control) {
    const name = labelFor(control);
    const validity = control.validity;

    if (validity.valueMissing) {
        return `${name} is required.`;
    }

    if (validity.typeMismatch) {
        return control.type === 'email'
            ? 'Enter an email address, like name@example.com.'
            : `Enter a valid ${name.toLowerCase()}.`;
    }

    if (validity.tooShort) {
        return `${name} must be at least ${control.minLength} characters.`;
    }

    if (validity.tooLong) {
        return `${name} must be ${control.maxLength} characters or fewer.`;
    }

    if (validity.rangeUnderflow) {
        return `${name} must be ${control.min} or more.`;
    }

    if (validity.rangeOverflow) {
        return `${name} must be ${control.max} or less.`;
    }

    if (validity.stepMismatch) {
        return `${name} is not in steps of ${control.step}.`;
    }

    if (validity.patternMismatch) {
        return `${name} is not in the expected format.`;
    }

    return control.validationMessage;
}

/**
 * The control's visible name, so the message says which field it is about.
 *
 * Three ways, because the codebase labels controls in three ways for good
 * reasons: paired by for/id on the long create forms, nested inside the
 * label by x-ui.field and the invoice line row, or aria-label.
 *
 * @param {HTMLElement} control
 * @returns {string}
 */
function labelFor(control) {
    const aria = control.getAttribute('aria-label');

    if (aria) {
        return aria.trim();
    }

    const paired = control.id
        ? document.querySelector(`label[for="${CSS.escape(control.id)}"]`)
        : null;

    const label = paired ?? control.closest('label');

    // x-ui.field marks its label's words, which sit in a span beside a
    // hint and an error rather than as the label's own text.
    const marked = label?.querySelector('[data-field-label]');

    if (marked?.textContent.trim()) {
        return marked.textContent.trim();
    }

    if (label) {
        // A nested label contains the control, and on x-ui.field it contains
        // the option list too. Only the label's own text is its name.
        const text = Array.from(label.childNodes)
            .filter((node) => node.nodeType === Node.TEXT_NODE)
            .map((node) => node.textContent)
            .join(' ')
            .trim();

        if (text) {
            return text;
        }
    }

    return 'This field';
}

/**
 * What the message goes after.
 *
 * x-ui.field and the invoice line row nest the control inside its <label>,
 * and a <label> takes phrasing content only - a <p> inside one is invalid
 * markup, and a browser may move it out from under the label and leave the
 * message pointing at nothing. So for a nested control the message goes
 * after the label, not after the control.
 *
 * @param {HTMLElement} control
 * @returns {HTMLElement}
 */
function anchorFor(control) {
    const label = control.closest('label');

    return label ?? control;
}

/**
 * @param {HTMLElement} control
 * @returns {string}
 */
function errorId(control) {
    return `${control.name || control.id || 'field'}-error`;
}

/**
 * @param {HTMLElement} control
 * @returns {HTMLElement|null}
 */
function findMessage(control) {
    const anchor = anchorFor(control);
    const found = anchor.parentElement?.querySelector(
        `[data-error-for="${CSS.escape(errorId(control))}"]`
    );

    return found instanceof HTMLElement ? found : null;
}

/**
 * @param {HTMLElement} control
 * @returns {HTMLElement}
 */
function messageElement(control) {
    const existing = findMessage(control);

    if (existing) {
        return existing;
    }

    const element = document.createElement('p');
    element.className = MESSAGE_CLASS;
    element.id = errorId(control);
    element.dataset.errorFor = element.id;

    // aria-live so the message is announced when it appears, rather than
    // only being found by someone who goes looking for it.
    element.setAttribute('aria-live', 'polite');

    anchorFor(control).insertAdjacentElement('afterend', element);

    return element;
}

/**
 * @param {HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement} control
 */
function show(control) {
    const element = messageElement(control);

    element.textContent = messageFor(control);

    control.setAttribute('aria-invalid', 'true');
    control.setAttribute('aria-describedby', element.id);
    control.classList.add(...INVALID_CONTROL_CLASSES);
}

/**
 * @param {HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement} control
 */
function clear(control) {
    findMessage(control)?.remove();

    control.removeAttribute('aria-invalid');
    control.removeAttribute('aria-describedby');
    control.classList.remove(...INVALID_CONTROL_CLASSES);
}

export function registerValidation() {
    document.addEventListener(
        'invalid',
        (event) => {
            const control = event.target;

            if (!(control instanceof HTMLElement) || !('validity' in control)) {
                return;
            }

            // This is the whole suppression: without it the browser draws its
            // own bubble on top of ours.
            event.preventDefault();

            show(control);

            // The browser would have focused the first invalid control to
            // anchor its bubble. It no longer does, so the form would stay
            // where it is with a message somewhere below the fold.
            if (control.form && control.form.querySelector('[aria-invalid="true"]') === control) {
                control.focus({ preventScroll: false });
            }
        },
        true
    );

    // Clear as soon as it is right, rather than on the next submit. A message
    // that outlives the problem reads as a message about something else.
    for (const type of ['input', 'change']) {
        document.addEventListener(
            type,
            (event) => {
                const control = event.target;

                if (!(control instanceof HTMLElement) || !('validity' in control)) {
                    return;
                }

                if (control.getAttribute('aria-invalid') === 'true' && control.validity.valid) {
                    clear(control);
                }
            },
            true
        );
    }
}
