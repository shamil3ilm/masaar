/**
 * A line's VAT treatment on the invoice forms: the category, its reason and
 * its rate kept in step.
 *
 * Choosing a category puts that category's rate in the line's rate field -
 * the standard rate, zero, or nothing where the category states none - from
 * the server (data-default-rates on the form, the module's VatTreatments),
 * so no rate is written here. Reasons from another category are disabled
 * and a chosen one cleared; a category with a single reason gets it.
 * Choosing a reason fills the reason in words with its official text, unless
 * the person wrote their own.
 *
 * Delegated from the form, like invoice-lines.js, because rows are added
 * after this runs. A product picker fills the category with an input event,
 * not a change, so a product's own rate is left as it fills it. The server
 * checks whatever is sent.
 *
 * Defaults, never more: a product that names its treatment fills it; a
 * saved customer eligible for a citizen's zero rating puts the rows that
 * have no treatment of their own in it; a person's own choice wins over
 * both.
 */

/** @param {HTMLFormElement} form */
function ratesOf(form) {
    try {
        return JSON.parse(form.dataset.defaultRates || '{}');
    } catch {
        return {};
    }
}

/**
 * Only the chosen category's reasons can be picked.
 *
 * @param {Element} row
 * @param {string} category
 */
function limitReasons(row, category) {
    const select = row.querySelector('[data-exempt-code]');

    if (!(select instanceof HTMLSelectElement)) {
        return;
    }

    const options = Array.from(select.options).filter((option) => option.dataset.category);
    const allowed = options.filter((option) => option.dataset.category === category);

    options.forEach((option) => {
        option.disabled = option.dataset.category !== category;
    });

    if (select.selectedOptions[0]?.disabled) {
        select.value = '';
    }

    if (allowed.length === 1 && select.value === '') {
        select.value = allowed[0].value;
    }

    select.disabled = allowed.length === 0;
    fillReason(row, select);
}

/**
 * The official text, unless the field holds words of the person's own.
 *
 * @param {Element} row
 * @param {HTMLSelectElement} select
 */
function fillReason(row, select) {
    const reason = row.querySelector('[data-exempt-reason]');

    if (!(reason instanceof HTMLInputElement)) {
        return;
    }

    const official = Array.from(select.options).map((option) => option.dataset.text || '');

    if (reason.value === '' || official.includes(reason.value)) {
        reason.value = select.selectedOptions[0]?.dataset.text || '';
    }
}

/**
 * What a picker's chosen option fills in (its data-fields), as
 * catalogue-pickers.js reads it.
 *
 * @param {HTMLSelectElement} select
 * @returns {Record<string, string>}
 */
function fieldsOf(select) {
    try {
        return JSON.parse(select.selectedOptions[0]?.dataset.fields || '{}');
    } catch {
        return {};
    }
}

/**
 * Put a row in a category, its rate and its reasons following.
 *
 * @param {Element} row
 * @param {string} category
 * @param {string} code
 * @param {Record<string, string>} rates
 */
function treat(row, category, code, rates) {
    const select = row.querySelector('[data-tax-category]');
    const reason = row.querySelector('[data-exempt-code]');
    const rate = row.querySelector('[name$="[tax_rate]"]');

    if (!(select instanceof HTMLSelectElement)) {
        return;
    }

    select.value = category;

    if (rate && Object.prototype.hasOwnProperty.call(rates, category)) {
        rate.value = rates[category];
    }

    if (reason instanceof HTMLSelectElement) {
        reason.value = code;
    }

    limitReasons(row, category);
}

/**
 * A saved customer's zero rating (a citizen's healthcare or education) for
 * the rows that have no treatment of their own: none chosen by hand, none
 * from a product that names one. Choosing another customer takes it back.
 *
 * @param {HTMLFormElement} form
 * @param {Element} row
 * @param {Record<string, string>} rates
 */
function applyCustomer(form, row, rates) {
    const select = row.querySelector('[data-tax-category]');

    if (!(select instanceof HTMLSelectElement) || row.dataset.ownTreatment === '1') {
        return;
    }

    const code = form.dataset.customerExemption || '';
    const option = code ? row.querySelector(`[data-exempt-code] option[value="${CSS.escape(code)}"]`) : null;

    if (option instanceof HTMLOptionElement && option.dataset.category) {
        treat(row, option.dataset.category, code, rates);
        row.dataset.customerTreatment = '1';
    } else if (row.dataset.customerTreatment === '1') {
        treat(row, select.options[0]?.value || '', '', rates);
        delete row.dataset.customerTreatment;
    }
}

export function registerInvoiceVat() {
    const form = document.querySelector('[data-invoice-form][data-default-rates]');

    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const rates = ratesOf(form);
    const rows = () => form.querySelectorAll('[data-line-row]');

    form.addEventListener('change', (event) => {
        const select = event.target;

        if (!(select instanceof HTMLSelectElement)) {
            return;
        }

        if (select.matches('[data-customer-picker]')) {
            form.dataset.customerExemption = fieldsOf(select).default_exempt_code || '';
            rows().forEach((row) => applyCustomer(form, row, rates));

            return;
        }

        const row = select.closest('[data-line-row]');

        if (!row) {
            return;
        }

        if (select.matches('[data-product-picker]')) {
            // After catalogue-pickers.js has filled the row: a product that
            // names its treatment keeps it for every buyer.
            row.dataset.ownTreatment = fieldsOf(select).tax_category ? '1' : '';
            delete row.dataset.customerTreatment;
            applyCustomer(form, row, rates);
        } else if (select.matches('[data-tax-category]')) {
            treat(row, select.value, '', rates);
            row.dataset.ownTreatment = '1';
        } else if (select.matches('[data-exempt-code]')) {
            fillReason(row, select);
            row.dataset.ownTreatment = '1';
        }
    });

    // A picker filling the category or the reason: the reasons follow, the
    // rate is the product's own.
    form.addEventListener('input', (event) => {
        const select = event.target;
        const row = select instanceof HTMLSelectElement ? select.closest('[data-line-row]') : null;

        if (row && select.matches('[data-tax-category]')) {
            limitReasons(row, select.value);
        } else if (row && select.matches('[data-exempt-code]')) {
            fillReason(row, select);
        }
    });

    // The rows the page starts with, and every row added after.
    rows().forEach((row) => {
        const category = row.querySelector('[data-tax-category]');

        if (category instanceof HTMLSelectElement) {
            limitReasons(row, category.value);
        }
    });

    form.addEventListener('click', (event) => {
        if (event.target instanceof Element && event.target.closest('[data-add-line]')) {
            const added = Array.from(rows()).pop();

            if (added) {
                delete added.dataset.ownTreatment;
                delete added.dataset.customerTreatment;
                limitReasons(added, added.querySelector('[data-tax-category]')?.value || '');
                applyCustomer(form, added, rates);
            }
        }
    });
}
