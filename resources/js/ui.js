/**
 * The behaviour behind the design system's components: tabs, menus, modals
 * and a form taken a step at a time.
 *
 * Plain listeners on data attributes, like confirm.js beside it, rather than
 * Alpine components: each of these is the same few lines on every page, and
 * the content policy refuses an inline handler, so the markup can only name
 * the behaviour it wants. Delegated from the document, so markup added after
 * load - a cloned invoice line - is covered without registering again.
 *
 * Each follows the ARIA Authoring Practices pattern of the same name, which
 * is what a screen reader user has learned to expect from it.
 */

/** Which way "next" is along a row: the reading direction of the page. */
function forwardKey() {
    return document.documentElement.dir === 'rtl' ? 'ArrowLeft' : 'ArrowRight';
}

function backwardKey() {
    return document.documentElement.dir === 'rtl' ? 'ArrowRight' : 'ArrowLeft';
}

/* ---------------------------------------------------------------- tabs -- */

/** @param {HTMLElement} tab */
function selectTab(tab) {
    const list = tab.closest('[role="tablist"]');

    for (const other of list.querySelectorAll('[role="tab"]')) {
        const chosen = other === tab;
        other.setAttribute('aria-selected', chosen ? 'true' : 'false');
        other.tabIndex = chosen ? 0 : -1;

        const panel = document.getElementById(other.getAttribute('aria-controls'));
        panel?.toggleAttribute('hidden', !chosen);
    }
}

function registerTabs() {
    document.addEventListener('click', (event) => {
        const tab = event.target.closest('[data-tabs] [role="tab"]');

        if (tab) {
            selectTab(tab);
        }
    });

    document.addEventListener('keydown', (event) => {
        const tab = event.target.closest?.('[data-tabs] [role="tab"]');

        if (!tab) {
            return;
        }

        const tabs = Array.from(tab.closest('[role="tablist"]').querySelectorAll('[role="tab"]'));
        const at = tabs.indexOf(tab);
        const target = {
            [forwardKey()]: tabs[(at + 1) % tabs.length],
            [backwardKey()]: tabs[(at - 1 + tabs.length) % tabs.length],
            Home: tabs[0],
            End: tabs[tabs.length - 1],
        }[event.key];

        if (target) {
            event.preventDefault();
            target.focus();
            selectTab(target);
        }
    });
}

/* --------------------------------------------------------------- menus -- */

/** @param {HTMLElement} menu */
function items(menu) {
    return Array.from(menu.querySelectorAll('[role="menuitem"]'));
}

/** @param {HTMLElement} menu */
function openMenu(menu, focusLast = false) {
    const trigger = menu.querySelector('[data-menu-trigger]');
    menu.querySelector('[data-menu-panel]').removeAttribute('hidden');
    trigger.setAttribute('aria-expanded', 'true');

    const entries = items(menu);
    (focusLast ? entries[entries.length - 1] : entries[0])?.focus();
}

/** @param {HTMLElement} menu */
function closeMenu(menu, returnFocus = false) {
    const trigger = menu.querySelector('[data-menu-trigger]');
    menu.querySelector('[data-menu-panel]').setAttribute('hidden', '');
    trigger.setAttribute('aria-expanded', 'false');

    if (returnFocus) {
        trigger.focus();
    }
}

function closeAllMenus(except = null) {
    for (const menu of document.querySelectorAll('[data-menu]')) {
        if (menu !== except && menu.querySelector('[data-menu-trigger]')?.getAttribute('aria-expanded') === 'true') {
            closeMenu(menu);
        }
    }
}

function registerMenus() {
    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-menu-trigger]');
        const menu = event.target.closest('[data-menu]');

        // A click anywhere outside an open menu closes it.
        closeAllMenus(menu);

        if (trigger) {
            if (trigger.getAttribute('aria-expanded') === 'true') {
                closeMenu(menu);
            } else {
                openMenu(menu);
            }
        }
    });

    document.addEventListener('keydown', (event) => {
        const menu = event.target.closest?.('[data-menu]');

        if (!menu) {
            return;
        }

        const onTrigger = event.target.matches('[data-menu-trigger]');

        if (onTrigger && ['ArrowDown', 'ArrowUp'].includes(event.key)) {
            event.preventDefault();
            openMenu(menu, event.key === 'ArrowUp');

            return;
        }

        if (onTrigger) {
            return;
        }

        const entries = items(menu);
        const at = entries.indexOf(event.target);
        const target = {
            ArrowDown: entries[(at + 1) % entries.length],
            ArrowUp: entries[(at - 1 + entries.length) % entries.length],
            Home: entries[0],
            End: entries[entries.length - 1],
        }[event.key];

        if (target) {
            event.preventDefault();
            target.focus();
        } else if (event.key === 'Escape') {
            event.preventDefault();
            closeMenu(menu, true);
        } else if (event.key === 'Tab') {
            closeMenu(menu);
        }
    });
}

/* -------------------------------------------------------------- modals -- */

function registerModals() {
    document.addEventListener('click', (event) => {
        const opener = event.target.closest('[data-modal-open]');

        if (opener) {
            const dialog = document.getElementById(opener.dataset.modalOpen);

            if (dialog instanceof HTMLDialogElement) {
                event.preventDefault();
                dialog.showModal();
            }

            return;
        }

        const dialog = event.target.closest('dialog[data-modal]');

        if (!dialog) {
            return;
        }

        // A click on the dialog element itself, outside its content, is a
        // click on the backdrop.
        if (event.target.closest('[data-modal-close]') || event.target === dialog) {
            dialog.close();
        }
    });
}

/* --------------------------------------------------------------- steps -- */

/**
 * A long form shown one part at a time, with a stepper above it.
 *
 * Markup: data-steps on the form, data-step="n" on each part, and inside each
 * part a data-step-back and data-step-next button (hidden until this runs).
 * A data-step-review element in the last part is filled with what was
 * entered, to read through before sending.
 *
 * Without script the whole form shows at once and sends as it always did -
 * nothing here is needed for it to work. Moving on checks only the part being
 * left, with the browser's own validation, so a mistake is pointed out where
 * it was made rather than three screens later. A form sent back by the server
 * opens on the first part with an error in it.
 */

/** @param {HTMLFormElement} form */
function parts(form) {
    return Array.from(form.querySelectorAll('[data-step]'));
}

/**
 * @param {HTMLFormElement} form
 * @param {number} index
 */
function showStep(form, index, focus = true) {
    const all = parts(form);

    all.forEach((part, at) => part.toggleAttribute('hidden', at !== index));
    form.dataset.current = String(index);

    for (const indicator of document.querySelectorAll('[data-step-indicator]')) {
        const at = Number(indicator.dataset.stepIndicator);
        indicator.dataset.state = at < index ? 'done' : at === index ? 'current' : 'upcoming';
        indicator.toggleAttribute('aria-current', at === index);

        if (at === index) {
            indicator.setAttribute('aria-current', 'step');
        }
    }

    if (all[index]?.querySelector('[data-step-review]')) {
        review(form);
    }

    if (focus) {
        // The part's heading, so a screen reader starts reading the new part
        // from its top rather than from wherever focus was left.
        const heading = all[index]?.querySelector('[data-step-heading]');
        heading?.focus();
        heading?.scrollIntoView({ block: 'nearest' });
    }
}

/**
 * Check the part being left with the browser's own validation.
 *
 * @param {HTMLElement} part
 */
function valid(part) {
    const controls = Array.from(part.querySelectorAll('input, select, textarea')).filter((c) => !c.disabled);

    for (const control of controls) {
        if (!control.checkValidity()) {
            control.reportValidity();
            control.focus();

            return false;
        }
    }

    return true;
}

/** The words someone read beside a control. */
function labelOf(control) {
    const label = control.closest('label');
    const name = label?.querySelector('[data-field-label]') ?? label?.querySelector('span');

    return (name?.textContent ?? control.name).trim();
}

/** @param {HTMLFormElement} form */
function review(form) {
    const target = form.querySelector('[data-step-review]');
    const list = document.createElement('dl');
    list.className = target.dataset.stepReviewClass ?? '';

    for (const part of parts(form)) {
        if (part.contains(target)) {
            continue;
        }

        const title = part.querySelector('[data-step-heading]')?.textContent.trim();
        const rows = [];

        for (const control of part.querySelectorAll('input, select, textarea')) {
            if (control.disabled || ['hidden', 'password', 'submit', 'button'].includes(control.type)) {
                continue;
            }

            if ((control.type === 'radio' || control.type === 'checkbox') && !control.checked) {
                continue;
            }

            const value =
                control instanceof HTMLSelectElement
                    ? control.selectedOptions[0]?.textContent.trim()
                    : control.type === 'radio'
                      ? labelOf(control)
                      : control.value.trim();

            if (value) {
                rows.push([control.type === 'radio' ? (part.querySelector('legend')?.textContent.trim() ?? '') : labelOf(control), value]);
            }
        }

        if (rows.length === 0) {
            continue;
        }

        const heading = document.createElement('dt');
        heading.className = 'col-span-full mt-4 text-xs font-semibold uppercase tracking-wider text-accent first:mt-0';
        heading.textContent = title ?? '';
        list.append(heading);

        for (const [term, detail] of rows) {
            const dt = document.createElement('dt');
            dt.className = 'text-sm text-subtle';
            dt.textContent = term;

            const dd = document.createElement('dd');
            dd.className = 'text-sm font-medium text-fg break-words';
            dd.textContent = detail;

            list.append(dt, dd);
        }
    }

    target.replaceChildren(list);
}

function registerSteps() {
    for (const form of document.querySelectorAll('form[data-steps]')) {
        for (const control of form.querySelectorAll('[data-step-next], [data-step-back]')) {
            control.removeAttribute('hidden');
        }

        // Back from a failed submission: open where the first error is.
        const all = parts(form);
        const failed = all.findIndex((part) => part.querySelector('[data-field-error], [aria-invalid="true"]'));

        showStep(form, failed === -1 ? 0 : failed, false);
    }

    document.addEventListener('click', (event) => {
        const next = event.target.closest('form[data-steps] [data-step-next]');
        const back = event.target.closest('form[data-steps] [data-step-back]');

        if (!next && !back) {
            return;
        }

        event.preventDefault();

        const form = event.target.closest('form');
        const current = Number(form.dataset.current ?? 0);

        if (next && valid(parts(form)[current])) {
            showStep(form, Math.min(current + 1, parts(form).length - 1));
        } else if (back) {
            showStep(form, Math.max(current - 1, 0));
        }
    });

    // Enter in a field moves on rather than sending a half-filled form.
    document.addEventListener('keydown', (event) => {
        const form = event.target.closest?.('form[data-steps]');

        if (!form || event.key !== 'Enter' || !(event.target instanceof HTMLInputElement)) {
            return;
        }

        const current = Number(form.dataset.current ?? 0);

        if (current < parts(form).length - 1) {
            event.preventDefault();
            parts(form)[current].querySelector('[data-step-next]')?.click();
        }
    });
}

/**
 * A field the server sent back with an error is marked invalid, so its
 * border says so and a screen reader reads the message with the control.
 * x-ui.field renders the message; the control inside it is the caller's.
 */
function markServerErrors() {
    for (const message of document.querySelectorAll('[data-field-error]')) {
        const control = message.closest('label')?.querySelector('input, select, textarea');

        if (control) {
            message.id ||= `${control.name || 'field'}-server-error`;
            control.setAttribute('aria-invalid', 'true');
            control.setAttribute('aria-describedby', message.id);
        }
    }
}

export function registerInterface() {
    markServerErrors();
    registerTabs();
    registerMenus();
    registerModals();
    registerSteps();
}
