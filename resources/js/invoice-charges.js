/**
 * The add/remove-a-charge behaviour on the invoice form.
 *
 * The same shape as invoice-lines.js, and for the same reasons: plain DOM
 * under the CSP build of Alpine, rows cloned from a server-rendered
 * <template>, and no inline handlers. Unlike lines, every charge can be
 * removed: an invoice needs none.
 */

/** Rows are named charges[0][reason], so the index has to be rewritten. */
const INDEXED = /charges\[\d+]/;

function rowsIn(section) {
    return Array.from(section.querySelectorAll('[data-charge-row]'));
}

/** Validation addresses a charge by position, so the rows are renumbered from the top. */
function renumber(section) {
    rowsIn(section).forEach((row, index) => {
        row.querySelectorAll('[name]').forEach((input) => {
            input.name = input.name.replace(INDEXED, `charges[${index}]`);
        });
    });
}

function addRow(section) {
    const template = section.querySelector('[data-charge-template]');

    if (!template) {
        return;
    }

    const row = template.content.firstElementChild.cloneNode(true);

    section.querySelector('[data-charge-rows]').appendChild(row);
    renumber(section);

    const first = row.querySelector('select, input');

    if (first) {
        first.focus();
    }
}

export function registerInvoiceCharges() {
    const section = document.querySelector('[data-charges]');

    if (!section) {
        return;
    }

    section.addEventListener('click', (event) => {
        if (event.target.closest('[data-add-charge]')) {
            event.preventDefault();
            addRow(section);

            return;
        }

        const remove = event.target.closest('[data-remove-charge]');

        if (remove) {
            event.preventDefault();
            remove.closest('[data-charge-row]').remove();
            renumber(section);
        }
    });

    renumber(section);
}
