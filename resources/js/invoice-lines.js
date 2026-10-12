/**
 * The add/remove-a-line behaviour on the invoice form.
 *
 * Plain DOM rather than Alpine, deliberately. Alpine here is the CSP build,
 * which evaluates no attribute expressions at all - so `x-for="line in lines"`
 * is not available, and a repeater written that way would simply not run under
 * the content policy. Rows are server-rendered from a <template> and cloned,
 * which is what this actually is: appending markup, not reacting to state.
 *
 * Nothing here is an inline handler either. The policy refuses those, and a
 * blocked handler does not announce itself - it just stops working.
 *
 * The server renders the first row and any rows a failed submission is
 * repopulating, so the form is usable with JavaScript switched off entirely:
 * one line, which is the common case for a simplified invoice.
 */

/** Rows are named lines[0][description], so the index has to be rewritten. */
const INDEXED = /lines\[\d+]/g;

function rowsIn(form) {
    return Array.from(form.querySelectorAll('[data-line-row]'));
}

/**
 * Renumber every row from the top.
 *
 * Called after an add or a remove, because validation addresses a line by
 * position: a gap in the numbering puts the error message on a field the form
 * does not have.
 */
function renumber(form) {
    rowsIn(form).forEach((row, index) => {
        row.querySelectorAll('[name]').forEach((input) => {
            input.name = input.name.replace(INDEXED, `lines[${index}]`);
        });

        const label = row.querySelector('[data-line-number]');

        if (label) {
            label.textContent = String(index + 1);
        }
    });

    // The last remaining row keeps its remove button, but disabled: an
    // invoice with no lines cannot be saved, and a button that removes the
    // thing the form requires is a trap.
    const rows = rowsIn(form);

    rows.forEach((row) => {
        const remove = row.querySelector('[data-remove-line]');

        if (remove) {
            remove.disabled = rows.length === 1;
        }
    });
}

function addRow(form) {
    const template = form.querySelector('[data-line-template]');

    if (!template) {
        return;
    }

    const row = template.content.firstElementChild.cloneNode(true);

    form.querySelector('[data-line-rows]').appendChild(row);
    renumber(form);

    const first = row.querySelector('input, select');

    if (first) {
        first.focus();
    }
}

export function registerInvoiceLines() {
    const form = document.querySelector('[data-invoice-form]');

    if (!form) {
        return;
    }

    form.addEventListener('click', (event) => {
        const add = event.target.closest('[data-add-line]');

        if (add) {
            event.preventDefault();
            addRow(form);

            return;
        }

        const remove = event.target.closest('[data-remove-line]');

        if (remove && rowsIn(form).length > 1) {
            event.preventDefault();
            remove.closest('[data-line-row]').remove();
            renumber(form);
        }
    });

    renumber(form);
}
