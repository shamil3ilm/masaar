/**
 * Short-lived messages (x-ui.toasts), and the busy state of a form being
 * sent.
 *
 * A toast stays six seconds, longer while the pointer or focus is on it
 * (WCAG 2.2.1), and has its own close button; it is an x-ui.alert, so a
 * danger one is announced at once and the rest politely.
 *
 * A form being sent says so: its submit button shows a spinner and stops
 * taking presses, so a slow network does not get the invoice issued twice.
 * The button is disabled a moment after the submit rather than during it -
 * a button disabled during the submit event is left out of what is sent,
 * and some forms read which button was pressed.
 */

const STAY = 6000;

const SPINNER =
    '<svg data-busy-spinner class="size-4 shrink-0 motion-safe:animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3"/><path d="M21 12a9 9 0 00-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>';

/**
 * @param {string} message
 * @param {'success'|'warning'|'danger'|'info'} tone
 */
export function showToast(message, tone = 'success') {
    const region = document.querySelector('[data-toasts]');
    const template = region?.querySelector(`template[data-toast-template="${tone}"]`);

    if (!template) {
        return;
    }

    const toast = template.content.firstElementChild.cloneNode(true);
    toast.querySelector('[data-toast-text]').textContent = message;
    region.append(toast);

    let timer = 0;
    const leave = () => toast.remove();
    const wait = () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(leave, STAY);
    };

    toast.querySelector('[data-toast-close]').addEventListener('click', leave);
    toast.addEventListener('pointerenter', () => window.clearTimeout(timer));
    toast.addEventListener('focusin', () => window.clearTimeout(timer));
    toast.addEventListener('pointerleave', wait);
    toast.addEventListener('focusout', wait);
    wait();
}

function busy(button) {
    button.setAttribute('aria-busy', 'true');
    button.dataset.busy = '';

    if (!button.querySelector('[data-busy-spinner]')) {
        button.insertAdjacentHTML('afterbegin', SPINNER);
    }

    window.setTimeout(() => {
        button.disabled = true;
    }, 0);

    // A form that answers with a download leaves the page where it is; its
    // button comes back rather than staying busy for good.
    window.setTimeout(idle, 15000);
}

function idle() {
    for (const button of document.querySelectorAll('[data-busy]')) {
        button.removeAttribute('aria-busy');
        delete button.dataset.busy;
        button.disabled = false;
        button.querySelector('[data-busy-spinner]')?.remove();
    }
}

export function registerToasts() {
    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-toast]');

        if (trigger) {
            showToast(trigger.dataset.toast, trigger.dataset.toastTone || 'success');
        }
    });

    // Last of the submit listeners: only a submit nothing else stopped is
    // really on its way (an upload in progress marks its form busy itself).
    document.addEventListener('submit', (event) => {
        const form = event.target;

        // A GET form is a search or a filter: a navigation, not a change.
        if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() !== 'post' || (event.defaultPrevented && form.getAttribute('aria-busy') !== 'true')) {
            return;
        }

        const button = event.submitter instanceof HTMLButtonElement ? event.submitter : form.querySelector('button:not([type="button"])');

        if (button && !form.hasAttribute('data-no-busy')) {
            busy(button);
        }
    });

    // Back to a page the browser kept: its buttons work again.
    window.addEventListener('pageshow', idle);
}
