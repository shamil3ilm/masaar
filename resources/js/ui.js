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

import { describe, undescribe } from './enhance.js';

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
 * Check the part being left with the browser's own validation, and point
 * at the first problem unless only asked whether there is one.
 *
 * @param {HTMLElement} part
 */
function valid(part, report = true) {
    const controls = Array.from(part.querySelectorAll('input, select, textarea')).filter((c) => !c.disabled);

    for (const control of controls) {
        if (!control.checkValidity()) {
            if (report) {
                control.reportValidity();
                control.focus();
            }

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
            // Only what is sent: a searchable list's text box has no name,
            // and listed beside its select it showed the choice twice.
            if (control.disabled || !control.name || ['hidden', 'password', 'submit', 'button'].includes(control.type)) {
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

        // Back from a failed submission: open where the first error is. The
        // server names the fields (data-step-errors), since a field shared
        // with another form may not draw its own message.
        const all = parts(form);
        const named = JSON.parse(form.dataset.stepErrors || '[]');

        for (const name of named) {
            for (const control of form.querySelectorAll(`[name="${CSS.escape(name)}"]:not(:disabled)`)) {
                control.setAttribute('aria-invalid', 'true');
                control.dataset.serverInvalid = '';
            }
        }

        const failed = all.findIndex((part) => part.querySelector('[data-field-error], [aria-invalid="true"]'));

        showStep(form, failed === -1 ? 0 : failed, false);

        // A step left invalid after going back is somewhere the browser
        // cannot point to while it is hidden, so it would refuse to send
        // without saying why. Open that step and say it there instead. On the
        // click, because the browser validates before any submit event.
        form.addEventListener(
            'click',
            (event) => {
                if (!event.target.closest('[type="submit"]')) {
                    return;
                }

                const broken = all.findIndex((part) => !valid(part, false));

                if (broken !== -1 && all[broken].hidden) {
                    event.preventDefault();
                    showStep(form, broken);
                    valid(all[broken]);
                }
            },
            true
        );
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
            control.dataset.serverInvalid = '';
            describe(control, message.id);
        }
    }
}

/**
 * A message the server sent back goes once its field is changed, and the
 * summary above the form (data-error-summary) once none of its messages is
 * left: the "email has already been taken" alert stayed on top of the
 * sign-up form after the email was corrected. Each summary item names its
 * field in the error bag's terms (data-error-for), so a control's name is
 * read the same way - "address[city]" is "address.city", and a
 * confirmation field answers for the field it confirms.
 *
 * Keyed on the server's own mark (data-server-invalid), not on
 * aria-invalid: validate.js clears aria-invalid first on a change that
 * makes a field valid - any change to an optional one - so a check on it
 * found nothing to do and the commercial registration kept its message.
 * The message's id leaves aria-describedby too, the field's other
 * descriptions staying; a screen reader kept reading a corrected field's
 * hidden error. A new error, the server's or the browser's, describes it
 * again (markServerErrors, validate.js).
 */
function registerCorrections() {
    document.addEventListener('input', (event) => {
        const control = event.target;

        if (!(control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement) || !control.name) {
            return;
        }

        const key = control.name.replace(/\[([^\]]*)\]/g, '.$1').replace(/_confirmation$/, '');

        if (control.hasAttribute('data-server-invalid')) {
            delete control.dataset.serverInvalid;

            const message = control.closest('label')?.querySelector('[data-field-error]');
            // A searchable list's or a date's text box carries the marks too.
            const face = control.closest('[data-face-host]')?.querySelector('[data-face]');

            for (const target of [control, face]) {
                if (!(target instanceof HTMLElement)) {
                    continue;
                }

                target.removeAttribute('aria-invalid');

                if (message?.id) {
                    undescribe(target, message.id);
                }
            }

            if (message) {
                message.hidden = true;
            }
        }

        for (const item of document.querySelectorAll(`[data-error-for="${CSS.escape(key)}"]`)) {
            const summary = item.closest('[data-error-summary]');
            item.remove();

            if (summary && !summary.querySelector('[data-error-for]')) {
                summary.hidden = true;
            }
        }
    });
}

/* ---------------------------------------------------- small screens -- */

/** Tailwind's sm. Below it, tables collapse and optional sections close. */
const SMALL = '(max-width: 639.98px)';

let rowSequence = 0;

const CHEVRON =
    '<svg class="size-5 transition-transform group-aria-expanded:rotate-180" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>';

/**
 * Prepare one x-ui.table for compact rows: label each cell with its column,
 * mark the key cells, and give every row with something to hide a real
 * disclosure button. The row is a <tr>, so the button sits in a cell of its
 * own that only shows when the table is compact.
 *
 * @param {HTMLElement} box  the element carrying data-collapsible
 */
function prepareTable(box) {
    const table = box.querySelector('table');

    if (!table || box.dataset.collapsible === 'never') {
        return;
    }

    const labels = Array.from(table.querySelectorAll('thead th')).map((th) => th.textContent.trim());
    const primary = (box.dataset.primary || '0,1').split(',').map(Number);

    for (const row of table.tBodies[0]?.rows ?? []) {
        // The empty-state row is one cell across every column.
        if (row.cells.length < 2 || row.hasAttribute('data-row')) {
            continue;
        }

        Array.from(row.cells).forEach((cell, index) => {
            if (labels[index]) {
                cell.dataset.label = labels[index];
            }

            if (primary.includes(index)) {
                cell.dataset.primary = index === primary[0] ? 'lead' : 'key';
            }
        });

        row.setAttribute('data-row', '');

        const hidden = Array.from(row.cells).some((_, index) => !primary.includes(index));

        if (!hidden) {
            continue;
        }

        const expanded = row.hasAttribute('data-expanded');
        const button = document.createElement('button');
        button.type = 'button';
        button.dataset.rowToggleButton = '';
        button.className =
            'group inline-flex size-11 items-center justify-center rounded-button text-subtle hover:bg-line hover:text-fg focus-visible:outline-2 focus-visible:outline-focus';
        button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        button.setAttribute('aria-label', expanded ? box.dataset.collapseLabel : box.dataset.expandLabel);
        row.id ||= `row-${++rowSequence}`;
        button.setAttribute('aria-describedby', primaryCellId(row));
        button.innerHTML = CHEVRON;

        const cell = document.createElement('td');
        cell.dataset.rowToggle = '';
        cell.append(button);
        row.append(cell);
    }
}

/** The row's title cell, which names the row its button belongs to. */
function primaryCellId(row) {
    const lead = row.querySelector('[data-primary="lead"]') ?? row.cells[0];
    lead.id ||= `${row.id}-title`;

    return lead.id;
}

/** @param {HTMLTableRowElement} row */
function toggleRow(row) {
    const button = row.querySelector('[data-row-toggle-button]');
    const box = row.closest('[data-collapsible]');
    const open = !row.hasAttribute('data-expanded');

    row.toggleAttribute('data-expanded', open);
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
    button.setAttribute('aria-label', open ? box.dataset.collapseLabel : box.dataset.expandLabel);
}

/**
 * Sections that can wait on a small screen (x-ui.section with collapse):
 * closed there unless they hold an error, open everywhere else.
 */
function prepareSections(small) {
    for (const section of document.querySelectorAll('details[data-section-collapse]')) {
        const failing = section.querySelector('[data-field-error], [aria-invalid="true"]');
        section.open = failing !== null || !small;
    }
}

function registerSmallScreens() {
    const query = window.matchMedia(SMALL);
    const boxes = Array.from(document.querySelectorAll('[data-collapsible]'));

    const apply = () => {
        for (const box of boxes) {
            const compact = box.dataset.collapsible === 'always' || (box.dataset.collapsible === 'small' && query.matches);
            box.toggleAttribute('data-compact', compact);
        }
    };

    boxes.forEach(prepareTable);
    apply();
    prepareSections(query.matches);

    query.addEventListener('change', () => {
        apply();
        prepareSections(query.matches);
    });

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-row-toggle-button]');

        if (button) {
            toggleRow(button.closest('tr'));

            return;
        }

        // A tap anywhere on a compact row opens it, except on something
        // that does its own thing - a link, a button, a field.
        const row = event.target.closest('[data-compact] tr[data-row]');

        if (row?.querySelector('[data-row-toggle-button]') && !event.target.closest('a, button, input, select, textarea, label, form')) {
            toggleRow(row);
        }
    });

    // A field the browser finds invalid inside a closed section opens it,
    // or the browser could not point to the field it is complaining about.
    document.addEventListener(
        'invalid',
        (event) => {
            const section = event.target.closest?.('details');

            if (section && !section.open) {
                section.open = true;
            }
        },
        true
    );
}

/**
 * "Ask us" links on the home page: on the same page, choose the topic in the
 * contact form and go to it without a reload. Without script the link's own
 * ?topic= does the same through the server.
 */
function registerContactTopics() {
    document.addEventListener('click', (event) => {
        const link = event.target.closest('[data-contact-topic]');
        const select = document.getElementById('contact-topic');

        if (!link || !(select instanceof HTMLSelectElement)) {
            return;
        }

        event.preventDefault();
        select.value = link.dataset.contactTopic;
        document.getElementById('contact')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        select.focus({ preventScroll: true });
    });
}

/**
 * The sticky top bar (glass-bar) lifts with a shadow once content has
 * scrolled under it, so the frosted edge reads as an edge.
 */
function registerStickyBars() {
    const bars = document.querySelectorAll('[data-sticky-bar]');

    if (bars.length === 0) {
        return;
    }

    const update = () => {
        for (const bar of bars) {
            bar.toggleAttribute('data-scrolled', window.scrollY > 4);
        }
    };

    update();
    window.addEventListener('scroll', update, { passive: true });
}

export function registerInterface() {
    registerStickyBars();
    markServerErrors();
    registerCorrections();
    registerContactTopics();
    registerSmallScreens();
    registerTabs();
    registerMenus();
    registerModals();
    registerSteps();
}
