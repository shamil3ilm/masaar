/**
 * A long dropdown you can type into.
 *
 * Forty units of measure, two hundred and fifty countries or a company's
 * whole customer list are unreadable as a native dropdown, and on a phone
 * the native list is a wheel. x-ui.select marks a long one data-combobox; this
 * puts a text box over it that filters as you type, in Arabic or English.
 *
 * The <select> stays, hidden: it is what the form sends, what the server
 * rendered the choice into, and what the rest of the page listens to - the
 * invoice form's pickers read its chosen option's data-fields on change, as
 * they always did. Without script it is simply the dropdown it was.
 *
 * The pattern is the ARIA Authoring Practices' editable combobox with list
 * autocomplete: focus stays in the text box and aria-activedescendant names
 * the option in view. Down and Up move through the list (Alt+Down opens it
 * without moving), Home and End jump to its ends while it is open, Enter
 * chooses, Escape closes it and puts back the chosen text. Only a listed
 * option can be chosen, so the text box never sends something the select
 * would not.
 *
 * A select with data-source also asks the server as you type - for lists too
 * long to send whole, like customers and products. What comes back is added
 * to the select as options, so choosing one is the same as choosing one the
 * page came with.
 */

import { enhance, hidePanel, placeUnder, showPanel, uid } from './enhance.js';
import { t } from './strings.js';

const CHEVRON =
    '<svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9l6 6 6-6"/></svg>';

/**
 * Text as a search compares it: lower case, Latin accents and Arabic
 * vowel marks and tatweel gone, the alef, yaa and taa marbuta forms folded,
 * and Arabic-Indic digits read as the digits they are. "اسعار" finds
 * "أسعار" and "٣٠" finds "30".
 *
 * @param {string} text
 */
export function fold(text) {
    return text
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[ً-ٰٟـ]/g, '')
        .replace(/[آأإٱ]/g, 'ا')
        .replace(/ى/g, 'ي')
        .replace(/ة/g, 'ه')
        .replace(/[٠-٩]/g, (digit) => String(digit.charCodeAt(0) - 0x0660))
        .replace(/[۰-۹]/g, (digit) => String(digit.charCodeAt(0) - 0x06f0))
        .toLowerCase()
        .trim();
}

/**
 * The options a query keeps, best first: those starting with it, then those
 * containing every word of it.
 *
 * @param {Array<{text: string, search: string}>} items
 * @param {string} query
 */
function filter(items, query) {
    const wanted = fold(query);

    if (wanted === '') {
        return items;
    }

    const words = wanted.split(/\s+/);
    const starts = [];
    const contains = [];

    for (const item of items) {
        if (item.search.startsWith(wanted)) {
            starts.push(item);
        } else if (words.every((word) => item.search.includes(word))) {
            contains.push(item);
        }
    }

    return [...starts, ...contains];
}

/** @param {HTMLSelectElement} select */
function itemsOf(select) {
    return Array.from(select.options).map((option) => ({
        option,
        value: option.value,
        text: option.textContent.trim(),
        group: option.parentElement instanceof HTMLOptGroupElement ? option.parentElement.label : '',
        search: fold(`${option.textContent} ${option.value} ${option.dataset.search ?? ''}`),
    }));
}

/**
 * @param {HTMLElement} host  the element carrying data-combobox
 */
function setUp(host) {
    const select = host.querySelector('select');

    if (!(select instanceof HTMLSelectElement) || select.multiple) {
        return;
    }

    const listId = uid('combobox-list');
    const placeholderOption = Array.from(select.options).find((option) => option.value === '');

    const input = document.createElement('input');
    input.type = 'text';
    input.className = select.className.replace(/\b(ui-select|mt-1)\b/g, '').trim();
    input.classList.add('pe-10');
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', listId);
    input.autocomplete = 'off';
    input.spellcheck = false;
    input.dataset.face = '';
    input.placeholder = placeholderOption?.textContent.trim() || t('Type to search');

    // Its name where it has no label, and a server's error on it.
    for (const name of ['aria-label', 'aria-invalid', 'aria-describedby']) {
        if (select.hasAttribute(name)) {
            input.setAttribute(name, select.getAttribute(name));
        }
    }

    const toggle = document.createElement('span');
    toggle.className = 'pointer-events-none absolute inset-y-0 end-0 flex w-10 items-center justify-center text-subtle';
    toggle.innerHTML = CHEVRON;

    const list = document.createElement('div');
    list.id = listId;
    list.setAttribute('role', 'listbox');
    list.setAttribute('popover', 'manual');
    list.dataset.floating = '';
    list.hidden = true;
    list.className = 'max-h-72 overflow-y-auto rounded-control border border-line bg-surface p-1 text-sm text-fg shadow-raised';

    const status = document.createElement('p');
    status.className = 'sr-only';
    status.setAttribute('aria-live', 'polite');

    // The text box goes first, so the label around the field names it
    // rather than the hidden select.
    const box = document.createElement('span');
    box.className = 'relative mt-1 block';
    box.append(input, toggle);
    select.before(box);
    host.append(list, status);
    select.hidden = true;
    select.tabIndex = -1;
    select.setAttribute('aria-hidden', 'true');
    host.dataset.faceHost = '';

    let shown = [];
    let active = -1;
    let request = null;
    let timer = 0;

    const chosenText = () => {
        const option = select.selectedOptions[0];

        return option && option.value !== '' ? option.textContent.trim() : '';
    };

    const sync = () => {
        if (document.activeElement !== input) {
            input.value = chosenText();
        }

        input.disabled = select.disabled;
        input.setAttribute('aria-required', select.required ? 'true' : 'false');
    };

    const isOpen = () => input.getAttribute('aria-expanded') === 'true';

    const setActive = (index) => {
        const options = list.querySelectorAll('[role="option"]');
        options[active]?.removeAttribute('data-active');
        active = index;

        const option = options[active];

        if (option) {
            option.setAttribute('data-active', '');
            input.setAttribute('aria-activedescendant', option.id);
            option.scrollIntoView({ block: 'nearest' });
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    };

    const render = () => {
        shown = filter(itemsOf(select), input.value === chosenText() ? '' : input.value);
        list.replaceChildren();
        active = -1;
        input.removeAttribute('aria-activedescendant');

        let group = null;
        let groupLabel = null;

        shown.forEach((item, index) => {
            if (item.group !== groupLabel) {
                groupLabel = item.group;
                group = null;

                if (item.group) {
                    group = document.createElement('div');
                    group.setAttribute('role', 'group');
                    const heading = document.createElement('div');
                    heading.id = `${listId}-g${index}`;
                    heading.className = 'px-3 pb-1 pt-2 text-xs font-semibold uppercase tracking-wide text-subtle';
                    heading.textContent = item.group;
                    heading.setAttribute('role', 'presentation');
                    group.setAttribute('aria-labelledby', heading.id);
                    group.append(heading);
                    list.append(group);
                }
            }

            const row = document.createElement('div');
            row.id = `${listId}-o${index}`;
            row.setAttribute('role', 'option');
            row.dataset.index = String(index);
            row.className =
                'flex min-h-11 cursor-pointer items-center rounded-control px-3 py-2 data-active:bg-accent-soft data-active:text-fg aria-selected:font-semibold aria-disabled:cursor-not-allowed aria-disabled:opacity-50';
            row.textContent = item.text || t('None');
            row.setAttribute('aria-selected', item.option.selected && item.value !== '' ? 'true' : 'false');

            if (item.option.disabled) {
                row.setAttribute('aria-disabled', 'true');
            }

            (group ?? list).append(row);
        });

        if (shown.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'px-3 py-2 text-subtle';
            empty.textContent = request ? t('Searching...') : t('No matches');
            list.append(empty);
        }

        status.textContent = shown.length === 0 ? (request ? t('Searching...') : t('No matches')) : t(':count results', { count: shown.length });
    };

    const open = () => {
        if (input.disabled || isOpen()) {
            return;
        }

        input.setAttribute('aria-expanded', 'true');
        render();
        showPanel(list);
        placeUnder(list, input);

        const selected = shown.findIndex((item) => item.option.selected && item.value !== '');
        setActive(selected);
    };

    const close = (restore = true) => {
        if (!isOpen()) {
            return;
        }

        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        hidePanel(list);

        if (restore) {
            input.value = chosenText();
        }
    };

    const choose = (index) => {
        const item = shown[index];

        if (!item || item.option.disabled) {
            return;
        }

        const changed = select.value !== item.value;
        select.value = item.value;
        input.value = chosenText();
        close(false);

        if (changed) {
            select.dispatchEvent(new Event('input', { bubbles: true }));
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }
    };

    const move = (step) => {
        if (shown.length === 0) {
            return;
        }

        let next = active;

        for (let tries = 0; tries < shown.length; tries += 1) {
            next = (next + step + shown.length) % shown.length;

            if (!shown[next].option.disabled) {
                break;
            }
        }

        setActive(next);
    };

    // Asking the server, for the lists the page could not send whole.
    const remote = () => {
        const source = select.dataset.source;
        const query = input.value.trim();

        if (!source || query.length < 1) {
            return;
        }

        request?.abort();
        request = new AbortController();
        const url = new URL(source, window.location.href);
        url.searchParams.set('q', query);

        fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: request.signal })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error(String(response.status)))))
            .then((body) => {
                const known = new Map(Array.from(select.options).map((option) => [option.value, option]));

                for (const found of body.data ?? []) {
                    // One the page already had: the server found it by
                    // words the page's option does not carry (its Arabic
                    // name), so it learns them.
                    if (known.has(String(found.value))) {
                        const option = known.get(String(found.value));
                        option.dataset.search = `${option.dataset.search ?? ''} ${found.search ?? ''} ${query}`;

                        continue;
                    }

                    const option = new Option(found.label, String(found.value));

                    if (found.fields) {
                        option.dataset.fields = JSON.stringify(found.fields);
                    }

                    option.dataset.search = `${found.search ?? ''} ${query}`;

                    select.append(option);
                }

                request = null;

                if (isOpen()) {
                    render();
                }
            })
            .catch((error) => {
                if (error.name === 'AbortError') {
                    return;
                }

                request = null;
                status.textContent = t('Could not search just now. The list shows what is already here.');
            });
    };

    input.addEventListener('input', () => {
        if (!isOpen()) {
            open();
        } else {
            render();
        }

        setActive(shown.length > 0 && input.value !== '' ? 0 : -1);

        window.clearTimeout(timer);
        timer = window.setTimeout(remote, 250);
    });

    input.addEventListener('keydown', (event) => {
        const forward = { ArrowDown: 1, ArrowUp: -1 }[event.key];

        if (forward !== undefined) {
            event.preventDefault();

            if (!isOpen()) {
                open();

                if (event.altKey) {
                    return;
                }
            }

            move(forward);

            return;
        }

        if (!isOpen()) {
            return;
        }

        if (event.key === 'Enter') {
            if (active >= 0) {
                event.preventDefault();
                choose(active);
            }
        } else if (event.key === 'Escape') {
            event.preventDefault();
            close();
        } else if (event.key === 'Home' || event.key === 'End') {
            event.preventDefault();
            setActive(event.key === 'Home' ? 0 : shown.length - 1);
        } else if (event.key === 'Tab') {
            close();
        }
    });

    input.addEventListener('click', () => (isOpen() ? close() : open()));

    input.addEventListener('blur', () => {
        // A click on the list blurs the box first; the click itself decides.
        window.setTimeout(() => {
            if (!host.contains(document.activeElement)) {
                close();
            }
        }, 150);
    });

    // A press on the list keeps focus in the box, and its click is ours -
    // not the surrounding label's, which would pass it to the box.
    list.addEventListener('mousedown', (event) => event.preventDefault());
    list.addEventListener('click', (event) => {
        event.preventDefault();
        const row = event.target.closest('[role="option"]');

        if (row) {
            choose(Number(row.dataset.index));
        }
    });

    // Script elsewhere sets the select's value or disables it (a product
    // fills a line's unit; a VAT category disables its reasons), and the
    // box shows what the select holds.
    select.addEventListener('change', sync);
    select.addEventListener('input', sync);
    new MutationObserver(sync).observe(select, { attributes: true, attributeFilter: ['disabled', 'required'], childList: true, subtree: true });
    select.form?.addEventListener('change', () => queueMicrotask(sync));
    select.form?.addEventListener('reset', () => queueMicrotask(sync));

    window.addEventListener('resize', () => isOpen() && placeUnder(list, input));
    window.addEventListener('scroll', (event) => isOpen() && !list.contains(event.target) && placeUnder(list, input), true);

    sync();
}

export function registerComboboxes() {
    enhance('[data-combobox]', setUp);
}
