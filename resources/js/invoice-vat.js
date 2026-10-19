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

export function registerInvoiceVat() {
    const form = document.querySelector('[data-invoice-form][data-default-rates]');

    if (!form) {
        return;
    }

    const rates = ratesOf(form);

    form.addEventListener('change', (event) => {
        const select = event.target;

        if (!(select instanceof HTMLSelectElement)) {
            return;
        }

        const row = select.closest('[data-line-row]');

        if (!row) {
            return;
        }

        if (select.matches('[data-tax-category]')) {
            const rate = row.querySelector('[name$="[tax_rate]"]');

            if (rate && Object.prototype.hasOwnProperty.call(rates, select.value)) {
                rate.value = rates[select.value];
            }

            limitReasons(row, select.value);
        } else if (select.matches('[data-exempt-code]')) {
            fillReason(row, select);
        }
    });

    // A picker filling the category: its reasons follow, its rate does not.
    form.addEventListener('input', (event) => {
        const select = event.target;
        const row = select instanceof HTMLSelectElement && select.matches('[data-tax-category]') ? select.closest('[data-line-row]') : null;

        if (row) {
            limitReasons(row, select.value);
        }
    });

    // The rows the page starts with, and every row added after.
    const settle = () => {
        form.querySelectorAll('[data-line-row]').forEach((row) => {
            const category = row.querySelector('[data-tax-category]');

            if (category instanceof HTMLSelectElement) {
                limitReasons(row, category.value);
            }
        });
    };

    settle();
    form.addEventListener('click', (event) => {
        if (event.target instanceof Element && event.target.closest('[data-add-line]')) {
            settle();
        }
    });
}
