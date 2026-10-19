/**
 * Our own date picker and time field, over the browser's.
 *
 * The native date input draws its calendar in the browser's language and
 * the operating system's style - an English calendar on an Arabic page, a
 * wheel on a phone - and knows nothing of the Hijri date a Saudi reader
 * checks a Gregorian one against. x-ui.date-input and x-ui.time-input keep
 * the native input as the value: it is what the form sends (2026-10-14,
 * 13:30), what min, max and required are checked on, and what is left
 * working when this does not run. This puts a text box in front of it that
 * takes a typed date in the forms people type, and a calendar beside it.
 *
 * The calendar follows the ARIA Authoring Practices' date picker dialog: a
 * button opens it, focus goes to the chosen day, arrows move by day and week
 * (mirrored in Arabic), Home and End go to the ends of the week, Page Up and
 * Page Down by month (with Shift, by year), Enter chooses and Escape closes,
 * returning focus to the button. Month and day names come from Intl in the
 * page's language. Where the organization keeps a second calendar
 * (data-alt-calendar: Umm al-Qura for Saudi Arabia, from the country's
 * Presentation), each day shows its date in that calendar too, and the field
 * shows the chosen day's.
 *
 * Values are Gregorian, always.
 */

import { enhance, hidePanel, placeUnder, showPanel, uid } from './enhance.js';
import { isRtl, locale, t } from './strings.js';

const CALENDAR_ICON =
    '<svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8 3v3m8-3v3M4 9h16M5 5h14a1 1 0 011 1v13a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z"/></svg>';

const CLOCK_ICON =
    '<svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';

const ARROW = (path) =>
    `<svg class="size-5 rtl:-scale-x-100" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="${path}"/></svg>`;

/* ------------------------------------------------------------ dates -- */

/** Arabic-Indic digits as the digits they are. */
function latinDigits(text) {
    return text
        .replace(/[٠-٩]/g, (digit) => String(digit.charCodeAt(0) - 0x0660))
        .replace(/[۰-۹]/g, (digit) => String(digit.charCodeAt(0) - 0x06f0));
}

const pad = (number, size = 2) => String(number).padStart(size, '0');

/** @param {Date} date  a UTC midnight */
export function iso(date) {
    return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}`;
}

/** @returns {Date|null} */
export function fromIso(value) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value ?? '');

    if (!match) {
        return null;
    }

    const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));

    return iso(date) === value ? date : null;
}

/**
 * A date as people type one: 2026-10-14, 14/10/2026 (day first, as written
 * in the Gulf), 20261014, in either set of digits. Null when it is not a day
 * that exists.
 *
 * @returns {string|null}  year-month-day
 */
export function parseTyped(text) {
    const digits = latinDigits(text.trim());

    if (digits === '') {
        return null;
    }

    let parts = digits.split(/\D+/).filter(Boolean);

    if (parts.length === 1 && parts[0].length === 8) {
        parts = [parts[0].slice(0, 4), parts[0].slice(4, 6), parts[0].slice(6)];
    }

    if (parts.length !== 3) {
        return null;
    }

    const [year, month, day] = parts[0].length === 4 ? parts : parts[2].length === 4 ? [parts[2], parts[1], parts[0]] : [];

    if (!year) {
        return null;
    }

    const value = `${year}-${pad(Number(month))}-${pad(Number(day))}`;

    return fromIso(value) ? value : null;
}

function addDays(date, days) {
    return new Date(date.getTime() + days * 86400000);
}

function addMonths(date, months) {
    const target = new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth() + months, 1));
    const last = new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0)).getUTCDate();

    return new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth(), Math.min(date.getUTCDate(), last)));
}

function today() {
    const now = new Date();

    return new Date(Date.UTC(now.getFullYear(), now.getMonth(), now.getDate()));
}

/** The first day of the week where the reader is: Sunday unless told otherwise. */
function firstWeekday() {
    try {
        const info = new Intl.Locale(navigator.language);
        const week = info.getWeekInfo?.() ?? info.weekInfo;

        return (week?.firstDay ?? 7) % 7;
    } catch {
        return 0;
    }
}

/**
 * The second calendar's date, in the page's language - Umm al-Qura for a
 * Saudi organization. Null where there is none, or the browser cannot.
 */
function altFormatter(calendar, options) {
    if (!calendar) {
        return null;
    }

    try {
        const format = new Intl.DateTimeFormat(locale(`-ca-${calendar}`), { ...options, timeZone: 'UTC' });

        return format.resolvedOptions().calendar === calendar ? format : null;
    } catch {
        return null;
    }
}

/* ---------------------------------------------------------- the face -- */

/**
 * The text box and its button, in front of a native input.
 *
 * @param {HTMLInputElement} native
 * @param {string} icon
 * @param {string} buttonLabel
 */
function face(native, icon, buttonLabel) {
    const box = document.createElement('span');
    box.className = 'relative mt-1 block';

    const input = document.createElement('input');
    input.type = 'text';
    input.dir = 'ltr';
    input.autocomplete = 'off';
    input.spellcheck = false;
    input.dataset.face = '';
    input.className = native.className.replace(/\bmt-1\b/g, '').trim();
    input.classList.add('pe-12', 'text-start', 'rtl:text-end');

    if (native.disabled) {
        input.disabled = true;
    }

    for (const name of ['aria-label', 'aria-describedby', 'placeholder']) {
        if (native.hasAttribute(name)) {
            input.setAttribute(name, native.getAttribute(name));
        }
    }

    input.setAttribute('aria-required', native.required ? 'true' : 'false');

    const button = document.createElement('button');
    button.type = 'button';
    button.className =
        'absolute inset-y-0 end-0 m-0.5 inline-flex w-11 items-center justify-center rounded-control text-subtle hover:bg-sunken hover:text-fg focus-visible:outline-2 focus-visible:outline-focus disabled:pointer-events-none';
    button.setAttribute('aria-label', buttonLabel);
    button.setAttribute('aria-haspopup', 'dialog');
    button.setAttribute('aria-expanded', 'false');
    button.innerHTML = icon;
    button.disabled = native.disabled;

    box.append(input, button);
    native.before(box);
    native.hidden = true;
    native.tabIndex = -1;
    native.closest('[data-date-field], [data-time-field]').dataset.faceHost = '';

    // Disabled with its fieldset, or by another script: the face follows.
    new MutationObserver(() => {
        input.disabled = native.disabled || native.matches(':disabled');
        button.disabled = input.disabled;
    }).observe(native, { attributes: true, attributeFilter: ['disabled'] });

    return { input, button };
}

/** Put a value into the native input as a person would, so listeners hear it. */
function commit(native, value) {
    if (native.value === value) {
        return;
    }

    native.value = value;
    native.dispatchEvent(new Event('input', { bubbles: true }));
    native.dispatchEvent(new Event('change', { bubbles: true }));
}

/* ------------------------------------------------------- date picker -- */

/** @param {HTMLElement} host  the element carrying data-date-field */
function setUpDate(host) {
    const native = host.querySelector('input[type="date"]');

    if (!(native instanceof HTMLInputElement)) {
        return;
    }

    const { input, button } = face(native, CALENDAR_ICON, t('Choose a date'));
    input.inputMode = 'numeric';
    input.placeholder ||= t('YYYY-MM-DD');

    const calendar = host.closest('[data-alt-calendar]')?.dataset.altCalendar || '';
    const altLong = altFormatter(calendar, { day: 'numeric', month: 'long', year: 'numeric' });
    const altDay = altFormatter(calendar, { day: 'numeric' });
    const altMonth = altFormatter(calendar, { month: 'long', year: 'numeric' });

    // The chosen day in the second calendar, under the field.
    let alt = null;

    if (altLong) {
        alt = document.createElement('span');
        alt.id = uid('date-alt');
        alt.className = 'mt-1 block text-xs font-normal text-subtle';
        alt.setAttribute('aria-live', 'polite');
        host.append(alt);
        input.setAttribute('aria-describedby', [input.getAttribute('aria-describedby'), alt.id].filter(Boolean).join(' '));
    }

    const monthName = new Intl.DateTimeFormat(locale(), { month: 'long', year: 'numeric', timeZone: 'UTC' });
    const dayName = new Intl.DateTimeFormat(locale(), { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' });
    const weekdayShort = new Intl.DateTimeFormat(locale(), { weekday: 'short', timeZone: 'UTC' });
    const weekdayLong = new Intl.DateTimeFormat(locale(), { weekday: 'long', timeZone: 'UTC' });

    const show = () => {
        input.value = native.value;

        if (alt) {
            const date = fromIso(native.value);
            alt.textContent = date ? `${t('Hijri')}: ${altLong.format(date)}` : '';
        }
    };

    const min = () => fromIso(native.min);
    const max = () => fromIso(native.max);
    const allowed = (date) => (!min() || date >= min()) && (!max() || date <= max());

    // Typing: a valid date goes straight in; anything else is said when
    // the field is left, in our words.
    input.addEventListener('input', () => {
        const value = parseTyped(input.value);

        if (value) {
            native.setCustomValidity('');
            commit(native, value);
            show();
            input.value = input.value.trim();
        }
    });

    input.addEventListener('change', () => {
        if (input.value.trim() === '') {
            native.setCustomValidity('');
            commit(native, '');
        } else {
            const value = parseTyped(input.value);

            native.setCustomValidity(value ? '' : t('Enter a date like 2026-10-14.'));

            if (value) {
                commit(native, value);
            }
        }

        show();
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' && event.altKey) {
            event.preventDefault();
            open();
        }
    });

    native.addEventListener('input', show);
    native.addEventListener('change', show);
    native.form?.addEventListener('reset', () => queueMicrotask(show));

    /* The dialog. */
    const dialog = document.createElement('div');
    dialog.setAttribute('role', 'dialog');
    dialog.setAttribute('aria-modal', 'true');
    dialog.setAttribute('popover', 'manual');
    dialog.dataset.floating = '';
    dialog.hidden = true;
    dialog.dir = isRtl(host) ? 'rtl' : 'ltr';
    dialog.className = 'w-[min(21rem,calc(100vw-1rem))] rounded-card border border-line bg-surface p-3 text-sm text-fg shadow-raised';

    const heading = document.createElement('h2');
    heading.id = uid('date-heading');
    heading.className = 'text-center text-sm font-semibold';
    heading.setAttribute('aria-live', 'polite');
    dialog.setAttribute('aria-labelledby', heading.id);

    const subheading = document.createElement('p');
    subheading.className = 'text-center text-xs text-subtle';

    const navButton = (label, path) => {
        const element = document.createElement('button');
        element.type = 'button';
        element.className =
            'inline-flex size-11 shrink-0 items-center justify-center rounded-button text-muted hover:bg-sunken hover:text-fg focus-visible:outline-2 focus-visible:outline-focus disabled:opacity-40';
        element.setAttribute('aria-label', label);
        element.innerHTML = ARROW(path);

        return element;
    };

    const previous = navButton(t('Previous month'), 'M15 6l-6 6 6 6');
    const next = navButton(t('Next month'), 'M9 6l6 6-6 6');

    const header = document.createElement('div');
    header.className = 'flex items-center justify-between gap-2';
    const titles = document.createElement('div');
    titles.className = 'min-w-0';
    titles.append(heading, subheading);
    header.append(previous, titles, next);

    const grid = document.createElement('table');
    grid.setAttribute('role', 'grid');
    grid.setAttribute('aria-labelledby', heading.id);
    grid.className = 'mt-2 w-full table-fixed border-collapse text-center';

    const footer = document.createElement('div');
    footer.className = 'mt-2 flex items-center justify-between gap-2 border-t border-line pt-2';
    const todayButton = document.createElement('button');
    todayButton.type = 'button';
    todayButton.className = 'inline-flex min-h-11 items-center rounded-button px-3 font-medium text-accent hover:bg-accent-soft focus-visible:outline-2 focus-visible:outline-focus';
    todayButton.textContent = t('Today');
    const clearButton = document.createElement('button');
    clearButton.type = 'button';
    clearButton.className = 'inline-flex min-h-11 items-center rounded-button px-3 font-medium text-muted hover:bg-sunken hover:text-fg focus-visible:outline-2 focus-visible:outline-focus';
    clearButton.textContent = t('Clear');
    footer.append(todayButton, clearButton);

    dialog.append(header, grid, footer);
    host.append(dialog);

    let focused = today();

    const render = () => {
        const first = new Date(Date.UTC(focused.getUTCFullYear(), focused.getUTCMonth(), 1));
        const start = addDays(first, -((first.getUTCDay() - firstWeekday() + 7) % 7));
        const chosen = native.value;
        const now = iso(today());

        heading.textContent = monthName.format(first);

        if (altMonth) {
            const last = new Date(Date.UTC(first.getUTCFullYear(), first.getUTCMonth() + 1, 0));
            const from = altMonth.format(first);
            const to = altMonth.format(last);
            subheading.textContent = from === to ? from : `${from} - ${to}`;
        }

        previous.disabled = min() !== null && addDays(first, -1) < min();
        next.disabled = max() !== null && new Date(Date.UTC(first.getUTCFullYear(), first.getUTCMonth() + 1, 1)) > max();

        const head = document.createElement('thead');
        const headRow = document.createElement('tr');

        for (let index = 0; index < 7; index += 1) {
            const day = addDays(start, index);
            const cell = document.createElement('th');
            cell.scope = 'col';
            cell.className = 'pb-1 text-xs font-medium text-subtle';
            cell.abbr = weekdayLong.format(day);
            cell.textContent = weekdayShort.format(day);
            headRow.append(cell);
        }

        head.append(headRow);

        const body = document.createElement('tbody');

        for (let week = 0; week < 6; week += 1) {
            const row = document.createElement('tr');

            for (let index = 0; index < 7; index += 1) {
                const day = addDays(start, week * 7 + index);
                const value = iso(day);
                const inMonth = day.getUTCMonth() === first.getUTCMonth();
                const cell = document.createElement('td');
                cell.setAttribute('role', 'gridcell');
                cell.className = 'p-0.5';
                cell.setAttribute('aria-selected', value === chosen ? 'true' : 'false');

                const dayButton = document.createElement('button');
                dayButton.type = 'button';
                dayButton.dataset.day = value;
                dayButton.tabIndex = value === iso(focused) ? 0 : -1;
                dayButton.className =
                    'flex h-11 w-full flex-col items-center justify-center rounded-control tabular-nums leading-tight hover:bg-sunken focus-visible:outline-2 focus-visible:outline-focus aria-disabled:cursor-not-allowed aria-disabled:opacity-35 aria-pressed:bg-action aria-pressed:text-on-action aria-[current=date]:ring-1 aria-[current=date]:ring-accent';
                dayButton.classList.add(inMonth ? 'text-fg' : 'text-subtle');
                dayButton.setAttribute('aria-pressed', value === chosen ? 'true' : 'false');
                dayButton.setAttribute('aria-label', `${dayName.format(day)}${altLong ? ` (${altLong.format(day)})` : ''}`);

                if (value === now) {
                    dayButton.setAttribute('aria-current', 'date');
                }

                if (!allowed(day)) {
                    dayButton.setAttribute('aria-disabled', 'true');
                }

                const number = document.createElement('span');
                number.className = 'text-sm';
                number.textContent = String(day.getUTCDate());
                dayButton.append(number);

                if (altDay) {
                    const second = document.createElement('span');
                    second.className = 'text-[0.625rem] opacity-70';
                    second.textContent = altDay.format(day);
                    second.setAttribute('aria-hidden', 'true');
                    dayButton.append(second);
                }

                cell.append(dayButton);
                row.append(cell);
            }

            body.append(row);
        }

        grid.replaceChildren(head, body);
        clearButton.hidden = native.required;
        todayButton.disabled = !allowed(today());
    };

    const focusDay = () => grid.querySelector(`[data-day="${iso(focused)}"]`)?.focus();

    const isOpen = () => button.getAttribute('aria-expanded') === 'true';

    const open = () => {
        if (input.disabled || isOpen()) {
            return;
        }

        focused = fromIso(native.value) ?? (allowed(today()) ? today() : (min() ?? max() ?? today()));
        render();
        button.setAttribute('aria-expanded', 'true');
        showPanel(dialog);
        placeUnder(dialog, input);
        focusDay();
    };

    const close = (returnFocus = true) => {
        if (!isOpen()) {
            return;
        }

        button.setAttribute('aria-expanded', 'false');
        hidePanel(dialog);

        if (returnFocus) {
            button.focus();
        }
    };

    const choose = (date) => {
        if (!allowed(date)) {
            return;
        }

        native.setCustomValidity('');
        commit(native, iso(date));
        show();
        close();
        input.focus();
    };

    const moveTo = (date) => {
        focused = date;
        render();
        focusDay();
    };

    button.addEventListener('click', () => (isOpen() ? close() : open()));
    previous.addEventListener('click', () => moveTo(addMonths(focused, -1)));
    next.addEventListener('click', () => moveTo(addMonths(focused, 1)));
    todayButton.addEventListener('click', () => choose(today()));
    clearButton.addEventListener('click', () => {
        commit(native, '');
        show();
        close();
        input.focus();
    });

    // A click in the dialog is ours, not the surrounding label's (which
    // would pass it on to the text box). The month and footer buttons have
    // run their own listeners by the time it gets here.
    dialog.addEventListener('click', (event) => {
        event.preventDefault();
        const day = event.target.closest('[data-day]');

        if (day && day.getAttribute('aria-disabled') !== 'true') {
            choose(fromIso(day.dataset.day));
        }
    });

    dialog.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            close();

            return;
        }

        if (event.key === 'Tab') {
            const stops = Array.from(dialog.querySelectorAll('button')).filter(
                (element) => !element.disabled && !element.hidden && (element.tabIndex === 0 || !element.dataset.day)
            );
            const at = stops.indexOf(document.activeElement);
            const target = event.shiftKey ? stops[(at - 1 + stops.length) % stops.length] : stops[(at + 1) % stops.length];
            event.preventDefault();
            target?.focus();

            return;
        }

        const day = event.target.closest?.('[data-day]');

        if (!day) {
            return;
        }

        const rtl = dialog.dir === 'rtl';
        const weekStart = addDays(focused, -((focused.getUTCDay() - firstWeekday() + 7) % 7));
        const target = {
            ArrowLeft: addDays(focused, rtl ? 1 : -1),
            ArrowRight: addDays(focused, rtl ? -1 : 1),
            ArrowUp: addDays(focused, -7),
            ArrowDown: addDays(focused, 7),
            Home: weekStart,
            End: addDays(weekStart, 6),
            PageUp: addMonths(focused, event.shiftKey ? -12 : -1),
            PageDown: addMonths(focused, event.shiftKey ? 12 : 1),
        }[event.key];

        if (target) {
            event.preventDefault();
            moveTo(target);
        } else if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            choose(fromIso(day.dataset.day));
        }
    });

    document.addEventListener('pointerdown', (event) => {
        if (isOpen() && !dialog.contains(event.target) && !button.contains(event.target)) {
            close(false);
        }
    });

    window.addEventListener('resize', () => isOpen() && placeUnder(dialog, input));
    window.addEventListener('scroll', (event) => isOpen() && !dialog.contains(event.target) && placeUnder(dialog, input), true);

    show();
}

/* -------------------------------------------------------- time field -- */

/**
 * A time as people type one: 13:30, 1330, 1:30 pm, ١:٣٠ م.
 *
 * @returns {string|null}  hours:minutes, 24-hour
 */
export function parseTime(text) {
    const typed = latinDigits(text.trim().toLowerCase());
    const match = /^(\d{1,2})(?:[:.\s]?(\d{2}))?\s*(am|pm|a|p|ص|م)?$/.exec(typed);

    if (!match) {
        return null;
    }

    let hours = Number(match[1]);
    const minutes = Number(match[2] ?? 0);
    const half = match[3];

    if (half) {
        if (hours < 1 || hours > 12) {
            return null;
        }

        const afternoon = ['pm', 'p', 'م'].includes(half);
        hours = (hours % 12) + (afternoon ? 12 : 0);
    }

    return hours < 24 && minutes < 60 ? `${pad(hours)}:${pad(minutes)}` : null;
}

/** @param {HTMLElement} host  the element carrying data-time-field */
function setUpTime(host) {
    const native = host.querySelector('input[type="time"]');

    if (!(native instanceof HTMLInputElement)) {
        return;
    }

    const { input, button } = face(native, CLOCK_ICON, t('Choose a time'));
    input.placeholder ||= t('HH:MM');
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    button.tabIndex = -1;
    button.removeAttribute('aria-haspopup');

    const list = document.createElement('div');
    list.id = uid('time-list');
    list.setAttribute('role', 'listbox');
    list.setAttribute('popover', 'manual');
    list.dataset.floating = '';
    list.hidden = true;
    list.className = 'max-h-64 w-40 overflow-y-auto rounded-control border border-line bg-surface p-1 text-sm text-fg shadow-raised';
    input.setAttribute('aria-controls', list.id);
    host.append(list);

    // Every step (the native input's, in seconds) from midnight; half an hour unless it says.
    const step = Math.max(60, Number(native.step) || 1800);
    const times = [];

    for (let minute = 0; minute < 1440; minute += step / 60) {
        times.push(`${pad(Math.floor(minute / 60))}:${pad(minute % 60)}`);
    }

    let active = -1;

    const isOpen = () => input.getAttribute('aria-expanded') === 'true';

    const show = () => {
        input.value = native.value.slice(0, 5);
    };

    const setActive = (index) => {
        const options = list.querySelectorAll('[role="option"]');
        options[active]?.removeAttribute('data-active');
        active = Math.max(0, Math.min(index, options.length - 1));
        options[active]?.setAttribute('data-active', '');
        options[active]?.scrollIntoView({ block: 'nearest' });
        input.setAttribute('aria-activedescendant', options[active]?.id ?? '');
    };

    const open = () => {
        if (input.disabled || isOpen()) {
            return;
        }

        list.replaceChildren(
            ...times
                .filter((time) => (!native.min || time >= native.min) && (!native.max || time <= native.max))
                .map((time, index) => {
                    const option = document.createElement('div');
                    option.id = `${list.id}-${index}`;
                    option.setAttribute('role', 'option');
                    option.dataset.time = time;
                    option.dir = 'ltr';
                    option.className = 'flex min-h-11 cursor-pointer items-center justify-center rounded-control px-3 tabular-nums data-active:bg-accent-soft aria-selected:font-semibold';
                    option.setAttribute('aria-selected', time === native.value.slice(0, 5) ? 'true' : 'false');
                    option.textContent = time;

                    return option;
                })
        );

        input.setAttribute('aria-expanded', 'true');
        showPanel(list);
        placeUnder(list, input);

        const current = native.value.slice(0, 5) || '09:00';
        const index = times.findIndex((time) => time >= current);
        setActive(index === -1 ? 0 : index);
    };

    const close = () => {
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        hidePanel(list);
    };

    const choose = (time) => {
        native.setCustomValidity('');
        commit(native, time);
        show();
        close();
    };

    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();

            if (!isOpen()) {
                open();
            } else {
                setActive(active + (event.key === 'ArrowDown' ? 1 : -1));
            }
        } else if (isOpen() && event.key === 'Enter') {
            event.preventDefault();
            const option = list.querySelectorAll('[role="option"]')[active];

            if (option) {
                choose(option.dataset.time);
            }
        } else if (isOpen() && event.key === 'Escape') {
            event.preventDefault();
            close();
        } else if (isOpen() && (event.key === 'Home' || event.key === 'End')) {
            event.preventDefault();
            setActive(event.key === 'Home' ? 0 : times.length);
        }
    });

    input.addEventListener('change', () => {
        if (input.value.trim() === '') {
            native.setCustomValidity('');
            commit(native, '');

            return;
        }

        const value = parseTime(input.value);
        native.setCustomValidity(value ? '' : t('Enter a time like 13:30.'));

        if (value) {
            commit(native, value);
            show();
        }
    });

    input.addEventListener('blur', () => window.setTimeout(close, 150));
    button.addEventListener('click', () => (isOpen() ? close() : (input.focus(), open())));
    list.addEventListener('mousedown', (event) => event.preventDefault());
    list.addEventListener('click', (event) => {
        event.preventDefault();
        const option = event.target.closest('[role="option"]');

        if (option) {
            choose(option.dataset.time);
        }
    });

    native.addEventListener('change', show);
    show();
}

export function registerDateFields() {
    enhance('[data-date-field]', setUpDate);
    enhance('[data-time-field]', setUpTime);
}
