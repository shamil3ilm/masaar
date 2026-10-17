/**
 * Saved customers and products filling in the invoice form.
 *
 * Each option carries the values it fills as JSON in data-fields, rendered by
 * the server. The pickers have no name, so they submit nothing: what is sent
 * is whatever the fields hold after any edit the user makes on top.
 *
 * Delegated from the form rather than bound per row, because line rows are
 * added after this runs (invoice-lines.js) and each brings its own picker.
 */

/**
 * @param {HTMLSelectElement} select
 * @returns {Record<string, string>}
 */
function fieldsOf(select) {
    const option = select.selectedOptions[0];

    if (!option || !option.dataset.fields) {
        return {};
    }

    try {
        return JSON.parse(option.dataset.fields);
    } catch {
        return {};
    }
}

/**
 * @param {HTMLInputElement|HTMLSelectElement|null} input
 * @param {string} value
 */
function fill(input, value) {
    if (!input) {
        return;
    }

    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
}

/** The buyer fields are found by id: buyer_name, buyer_address_city, ... */
function fillBuyer(form, fields) {
    Object.entries(fields).forEach(([id, value]) => {
        fill(form.querySelector(`#${CSS.escape(id)}`), value);
    });
}

/** A line's fields are found by the end of their name: lines[2][unit_price]. */
function fillLine(row, fields) {
    Object.entries(fields).forEach(([key, value]) => {
        fill(row.querySelector(`[name$="[${CSS.escape(key)}]"]`), value);
    });

    const quantity = row.querySelector('[name$="[quantity]"]');

    if (quantity && quantity.value === '') {
        fill(quantity, '1');
    }
}

export function registerCataloguePickers() {
    const form = document.querySelector('[data-invoice-form]');

    if (!form) {
        return;
    }

    form.addEventListener('change', (event) => {
        const target = event.target;

        if (!(target instanceof HTMLSelectElement)) {
            return;
        }

        if (target.matches('[data-customer-picker]')) {
            fillBuyer(form, fieldsOf(target));

            return;
        }

        if (target.matches('[data-product-picker]')) {
            const row = target.closest('[data-line-row]');

            if (row) {
                fillLine(row, fieldsOf(target));
            }

            return;
        }

        // A common charge fills its row the way a product fills a line,
        // by the end of each field's name: charges[1][reason].
        if (target.matches('[data-charge-picker]')) {
            const row = target.closest('[data-charge-row]');

            if (row) {
                Object.entries(fieldsOf(target)).forEach(([key, value]) => {
                    fill(row.querySelector(`[name$="[${CSS.escape(key)}]"]`), String(value));
                });
            }
        }
    });
}
