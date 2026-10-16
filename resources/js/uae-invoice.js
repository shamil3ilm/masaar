/**
 * The UAE invoice form: a line's VAT rate follows its tax category.
 *
 * Choosing a category puts that category's rate in the line's rate field -
 * 5 for standard, reverse charge and the margin scheme, 0 for zero-rated,
 * nothing for exempt and out of scope, which state no rate. The rates come
 * from the server (data-default-rates on the form), so the 5 is written in
 * one place. The field stays editable, and the server checks what is sent.
 *
 * Delegated from the form, like invoice-lines.js, because rows are added
 * after this runs. A product picker fills the category with an input event,
 * not a change, so a product's own rate is left as it fills it.
 */

/** @param {HTMLFormElement} form */
function ratesOf(form) {
    try {
        return JSON.parse(form.dataset.defaultRates || '{}');
    } catch {
        return {};
    }
}

export function registerUaeInvoice() {
    const form = document.querySelector('[data-uae-invoice-form]');

    if (!form) {
        return;
    }

    const rates = ratesOf(form);

    form.addEventListener('change', (event) => {
        const select = event.target;

        if (!(select instanceof HTMLSelectElement) || !select.matches('[data-tax-category]')) {
            return;
        }

        const rate = select.closest('[data-line-row]')?.querySelector('[name$="[tax_rate]"]');

        if (rate && Object.prototype.hasOwnProperty.call(rates, select.value)) {
            rate.value = rates[select.value];
        }
    });
}
