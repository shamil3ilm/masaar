/**
 * The invoice form's running figures: each line's VAT and total, and the
 * VAT by tax category, as the draft will be saved.
 *
 * The server does the arithmetic (POST data-preview-url, the same
 * InvoiceTotals the draft is saved with), so a mixed basket shows here
 * exactly what it is issued with - nothing is rounded twice in two
 * languages. Asked a moment after typing stops; an answer overtaken by a
 * newer question is dropped. Without script, or without an answer, the form
 * works as it did and the invoice page shows the figures once saved.
 *
 * Text only, never markup: every value is set with textContent.
 */

const DELAY = 300;

/**
 * @param {string} text
 * @param {boolean} [numeric]
 */
function cell(text, numeric = false) {
    const td = document.createElement('td');

    td.className = numeric ? 'px-6 py-3 text-end tabular-nums' : 'px-6 py-3';
    td.textContent = text;

    if (numeric) {
        td.dir = 'ltr';
    }

    return td;
}

/**
 * @param {HTMLFormElement} form
 * @param {{lines: Array<?{tax: string, total: string}>, categories: Array<{label: string, rate: string, reasons: string[], base: string, tax: string}>, subtotal: string, discount: string, charges: string, tax: string, total: string}} preview
 */
function render(form, preview) {
    const summary = form.querySelector('[data-vat-summary]');
    const vat = summary?.dataset.labelVat || 'VAT';
    const total = summary?.dataset.labelTotal || 'Total';

    form.querySelectorAll('[data-line-row]').forEach((row, index) => {
        const target = row.querySelector('[data-line-vat]');
        const line = preview.lines[index];

        if (target) {
            target.textContent = line ? `${vat} ${line.tax} · ${total} ${line.total}` : '';
        }
    });

    if (!summary) {
        return;
    }

    const body = summary.querySelector('tbody');

    if (body) {
        body.replaceChildren(...preview.categories.map((category) => {
            const tr = document.createElement('tr');
            const reasons = category.reasons.length ? ` · ${category.reasons.join(', ')}` : '';

            tr.append(cell(`${category.label}${reasons}`), cell(category.base, true), cell(category.tax, true));

            return tr;
        }));
    }

    summary.querySelectorAll('[data-vat-total]').forEach((target) => {
        target.textContent = preview[target.dataset.vatTotal] ?? '';
    });

    summary.hidden = preview.categories.length === 0;
}

export function registerInvoicePreview() {
    const form = document.querySelector('[data-invoice-form][data-preview-url]');

    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    let timer = 0;
    let latest = 0;

    const ask = async () => {
        const asked = ++latest;

        try {
            const response = await fetch(form.dataset.previewUrl, {
                method: 'POST',
                body: new FormData(form),
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });

            if (response.ok && asked === latest) {
                render(form, await response.json());
            }
        } catch {
            // Offline or refused: the figures stay as they were.
        }
    };

    const later = () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(ask, DELAY);
    };

    form.addEventListener('input', later);
    form.addEventListener('change', later);
    form.addEventListener('click', (event) => {
        if (event.target instanceof Element && event.target.closest('[data-add-line], [data-remove-line], [data-add-charge], [data-remove-charge]')) {
            later();
        }
    });

    ask();
}
