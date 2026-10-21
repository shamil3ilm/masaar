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
 * A count said in the page's language, by the translation's own forms.
 *
 * The key and its translation are written as Laravel's trans_choice reads
 * them - "{1} :count result|[0,*] :count results" - each form led by the
 * counts it is for, an exact {n} or a range [from,to] with * for no end,
 * and the first that holds is used. Arabic has its own forms for one, two,
 * a few and many, so English's singular and plural could not carry it:
 * "1 results" was what both said.
 *
 * @param {string} key  the English forms, as written in __() on the server
 * @param {number} count
 * @param {Record<string, string|number>} replace  other :placeholder => value
 * @returns {string}
 */
export function choice(key, count, replace = {}) {
    const forms = t(key).split('|');
    const holds = (spec) => {
        const exact = spec.match(/^\{(\d+)\}$/);

        if (exact) {
            return count === Number(exact[1]);
        }

        const range = spec.match(/^\[(\d+|\*),(\d+|\*)\]$/);

        return range !== null && (range[1] === '*' || count >= Number(range[1])) && (range[2] === '*' || count <= Number(range[2]));
    };

    const chosen = forms.find((form) => holds(form.trim().split(/\s/, 1)[0])) ?? forms[forms.length - 1];
    let text = chosen.trim().replace(/^(\{\d+\}|\[(\d+|\*),(\d+|\*)\])\s*/, '');

    for (const [name, value] of Object.entries({ ...replace, count })) {
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
