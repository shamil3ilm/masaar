/**
 * The invoice form's folded parts (data-invoice-part): a credit or debit
 * note needs the invoice it corrects and why, so choosing one opens that
 * part. The page itself opens a part that holds a value or an error.
 */
const NOTES = ['credit_note', 'debit_note'];

export function registerInvoiceParts() {
    const form = document.querySelector('[data-invoice-form]');
    const part = form?.querySelector('details[data-invoice-part="note"]');

    if (!part) {
        return;
    }

    form.addEventListener('change', (event) => {
        if (event.target instanceof HTMLSelectElement && event.target.name === 'document_type' && NOTES.includes(event.target.value)) {
            part.open = true;
        }
    });
}
