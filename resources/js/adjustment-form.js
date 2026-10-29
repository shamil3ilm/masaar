/**
 * The return, exchange or replace form shows only the parts the chosen kind
 * uses.
 *
 * The page is drawn for the kind already chosen: each part carries the kinds
 * that use it (data-for-kinds, from AdjustmentKind::formParts) and is hidden
 * for the others. This keeps it so as another kind is picked. Plain DOM
 * under the CSP build of Alpine, like invoice-lines.js. A hidden part's
 * values are still sent; the server keeps only what the kind uses.
 */
function show(form) {
    const kind = form.querySelector('input[name="kind"]:checked')?.value;

    if (!kind) {
        return;
    }

    form.querySelectorAll('[data-for-kinds]').forEach((part) => {
        part.toggleAttribute('hidden', !part.dataset.forKinds.split(' ').includes(kind));
    });
}

export function registerAdjustmentForm() {
    const form = document.querySelector('[data-adjustment-form]');

    if (!form) {
        return;
    }

    form.addEventListener('change', (event) => {
        if (event.target instanceof HTMLInputElement && event.target.name === 'kind') {
            show(form);
        }
    });

    show(form);
}
