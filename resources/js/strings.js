/**
 * The words a script says, in the page's language.
 *
 * The server translates them (x-ui.strings, one hidden element per layout)
 * and this reads them back, so a combobox's "No matches" or a validation
 * message is Arabic on an Arabic page - the bundle carries no dictionary of
 * its own to drift from lang/ar.json. A key with no entry is its own
 * English, which is what the page would have said anyway.
 */

/** @type {Record<string, string>|null} */
let table = null;

function load() {
    if (table !== null) {
        return table;
    }

    try {
        table = JSON.parse(document.querySelector('[data-ui-strings]')?.dataset.uiStrings || '{}');
    } catch {
        table = {};
    }

    return table;
}

/**
 * @param {string} key  the English, as written in __() on the server
 * @param {Record<string, string|number>} replace  :placeholder => value
 * @returns {string}
 */
export function t(key, replace = {}) {
    let text = load()[key] ?? key;

    for (const [name, value] of Object.entries(replace)) {
        text = text.replaceAll(`:${name}`, String(value));
    }

    return text;
}

/**
 * The language an element is in (its nearest lang), for Intl: Latin digits
 * either way, as the forms take.
 */
export function locale(extension = '', element = document.documentElement) {
    const lang = element.closest('[lang]')?.lang?.startsWith('ar') ? 'ar' : 'en';

    return `${lang}-u-nu-latn${extension}`;
}

export function isRtl(element = document.documentElement) {
    return getComputedStyle(element).direction === 'rtl';
}
