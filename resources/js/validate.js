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
 * every form is marked novalidate, and on submit the form is checked here
 * instead - each failing field gets its message under it, in the page's
 * language, with aria-invalid and aria-describedby, and a summary at the
 * top of the form takes focus and links to each. A form that is invalid
 * still does not submit.
 */

import { t } from './strings.js';

const MESSAGE_CLASS = 'mt-1 block text-xs font-medium text-danger';

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

    // A component's own words (a date that is not a date, a file too large).
    if (validity.customError) {
        return control.validationMessage;
    }

    if (validity.valueMissing) {
        return t(':field is required.', { field: name });
    }

    if (validity.badInput) {
        return control.type === 'number' ? t('Enter a number.') : t('Enter a valid :field.', { field: name });
    }

    if (validity.typeMismatch) {
        return control.type === 'email'
            ? t('Enter an email address, like name@example.com.')
            : t('Enter a valid :field.', { field: name });
    }

    if (validity.tooShort) {
        return t(':field must be at least :count characters.', { field: name, count: control.minLength });
    }

    if (validity.tooLong) {
        return t(':field must be :count characters or fewer.', { field: name, count: control.maxLength });
    }

    if (validity.rangeUnderflow) {
        return t(':field must be :min or more.', { field: name, min: control.min });
    }

    if (validity.rangeOverflow) {
        return t(':field must be :max or less.', { field: name, max: control.max });
    }

    if (validity.stepMismatch) {
        return t(':field is not in steps of :step.', { field: name, step: control.step });
    }

    if (validity.patternMismatch) {
        // The field's own words for its format, where it gives them.
        return control.dataset.patternMessage || t(':field is not in the expected format.', { field: name });
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

    return t('This field');
}

/**
 * Where the message goes.
 *
 * x-ui.field and the invoice line row nest the control inside its <label>,
 * and the message goes at the end of that label, under the control - as a
 * span, since a <label> takes phrasing content only, and as the label's own
 * so it stays in the label's grid cell rather than taking the next one. A
 * control paired with its label by id gets it straight after.
 *
 * @param {HTMLElement} control
 */
function place(control, element) {
    const label = control.closest('label');

    if (label) {
        label.append(element);
    } else {
        control.insertAdjacentElement('afterend', element);
    }
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
    const scope = control.closest('label') ?? control.parentElement;
    const found = scope?.querySelector(`[data-error-for="${CSS.escape(errorId(control))}"]`);

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

    const element = document.createElement('span');
    element.className = MESSAGE_CLASS;
    element.id = errorId(control);
    element.dataset.errorFor = element.id;

    // aria-live so the message is announced when it appears, rather than
    // only being found by someone who goes looking for it.
    element.setAttribute('aria-live', 'polite');

    place(control, element);

    return element;
}

/**
 * What a person sees and focuses for a control: the control, or the text
 * box a component put in front of a hidden one (a searchable dropdown, a
 * date, a file - data-face inside data-face-host).
 *
 * @param {HTMLElement} control
 * @returns {HTMLElement}
 */
function faceOf(control) {
    const face = control.closest('[data-face-host]')?.querySelector('[data-face]');

    return face instanceof HTMLElement && control.hidden ? face : control;
}

/**
 * @param {HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement} control
 */
function show(control) {
    const element = messageElement(control);

    element.textContent = messageFor(control);

    for (const target of new Set([control, faceOf(control)])) {
        target.setAttribute('aria-invalid', 'true');
        target.setAttribute('aria-describedby', element.id);
        target.classList.add(...INVALID_CONTROL_CLASSES);
    }
}

/**
 * @param {HTMLInputElement|HTMLSelectElement|HTMLTextAreaElement} control
 */
function clear(control) {
    findMessage(control)?.remove();

    for (const target of new Set([control, faceOf(control)])) {
        target.removeAttribute('aria-invalid');
        target.removeAttribute('aria-describedby');
        target.classList.remove(...INVALID_CONTROL_CLASSES);
    }
}

let summaryLinks = 0;

/**
 * The form's problems, listed at its top and focused, so a screen reader
 * hears them at once and anyone can jump to each: every entry links to the
 * field it is about.
 *
 * @param {HTMLFormElement} form
 * @param {Array<HTMLElement>} controls  the invalid ones, in order
 */
function summarise(form, controls) {
    form.querySelector(':scope > [data-error-summary]')?.remove();

    const summary = document.createElement('div');
    summary.dataset.errorSummary = '';
    summary.tabIndex = -1;
    summary.setAttribute('role', 'alert');
    summary.className =
        'col-span-full mb-4 w-full basis-full rounded-tile border border-danger-line bg-danger-soft p-4 text-sm text-fg focus-visible:outline-2 focus-visible:outline-focus';

    const title = document.createElement('p');
    title.className = 'font-semibold text-danger';
    title.textContent = t('Please correct the following:');

    const list = document.createElement('ul');
    list.className = 'mt-2 list-disc space-y-1 ps-5';

    for (const control of controls) {
        const face = faceOf(control);
        face.id ||= `invalid-field-${++summaryLinks}`;

        const item = document.createElement('li');
        const link = document.createElement('a');
        link.href = `#${face.id}`;
        link.className = 'underline underline-offset-2 hover:text-danger';
        link.textContent = messageFor(control);
        link.addEventListener('click', (event) => {
            event.preventDefault();
            face.closest('details')?.setAttribute('open', '');
            face.focus();
        });
        item.append(link);
        list.append(item);
    }

    summary.append(title, list);
    form.prepend(summary);
    summary.focus();
}

export function registerValidation() {
    // The browser's own checking is switched off on every form - its bubble
    // is the browser's, not ours - and done here instead, on submit, by the
    // same constraints. Without script the browser checks as it always did.
    const quiet = (form) => {
        if (!form.hasAttribute('data-native-validation')) {
            form.noValidate = true;
        }
    };

    document.querySelectorAll('form').forEach(quiet);

    new MutationObserver((records) => {
        for (const record of records) {
            for (const node of record.addedNodes) {
                if (node instanceof HTMLFormElement) {
                    quiet(node);
                } else if (node instanceof HTMLElement) {
                    node.querySelectorAll('form').forEach(quiet);
                }
            }
        }
    }).observe(document.documentElement, { childList: true, subtree: true });

    // On the window, in the capture phase, so it runs before anything else
    // that acts on a submit - the confirmation dialog, the upload - and a
    // form with a mistake in it is neither asked about nor sent.
    window.addEventListener(
        'submit',
        (event) => {
            const form = event.target;

            if (!(form instanceof HTMLFormElement) || !form.noValidate) {
                return;
            }

            // A button that sends without checking is the author's choice.
            if (event.submitter?.hasAttribute('formnovalidate')) {
                return;
            }

            if (form.checkValidity()) {
                form.querySelector(':scope > [data-error-summary]')?.remove();

                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();

            summarise(
                form,
                Array.from(form.elements).filter((control) => 'validity' in control && control.willValidate && !control.validity.valid)
            );
        },
        true
    );

    // checkValidity and reportValidity fire this on each failing control.
    // Capture phase, because it does not bubble.
    document.addEventListener(
        'invalid',
        (event) => {
            const control = event.target;

            if (!(control instanceof HTMLElement) || !('validity' in control)) {
                return;
            }

            // This is the whole suppression: without it the browser draws
            // its own bubble wherever reportValidity is called.
            event.preventDefault();

            show(control);
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
