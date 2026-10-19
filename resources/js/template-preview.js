/**
 * The invoice template's live preview.
 *
 * The preview is the print view itself, drawn by the server from the form's
 * values (data-preview-url): what is shown is what prints, in every layout
 * and on every paper, and nothing about the layouts is written twice. It is
 * fetched and shown in a sandboxed frame by its markup (srcdoc) rather than
 * by its address: every page here refuses to be framed, the preview
 * included, and stays that way. Asked again a moment after a field changes;
 * an answer overtaken by a newer question is dropped. The "Open" and
 * "Download sample PDF" links follow the same values, so without script the
 * preview still opens in its own tab.
 */

const DELAY = 400;

/** The form's values as a query string, without the CSRF token. */
function query(form, paper) {
    const params = new URLSearchParams();

    for (const [name, value] of new FormData(form)) {
        if (name !== '_token' && typeof value === 'string') {
            params.append(name, value);
        }
    }

    params.set('paper', paper);

    return params.toString();
}

export function registerTemplatePreview() {
    const form = document.querySelector('form[data-template-form]');
    const frame = document.querySelector('iframe[data-template-preview]');

    if (!(form instanceof HTMLFormElement) || !(frame instanceof HTMLIFrameElement)) {
        return;
    }

    const paper = document.querySelector('[data-template-paper]');
    const links = document.querySelectorAll('a[data-template-link]');
    let timer = 0;
    let latest = 0;

    const refresh = async () => {
        const asked = ++latest;
        const values = query(form, paper instanceof HTMLSelectElement ? paper.value : 'a4');

        links.forEach((link) => {
            if (link instanceof HTMLAnchorElement) {
                link.href = `${link.dataset.templateLink}?${values}`;
            }
        });

        try {
            const response = await fetch(`${frame.dataset.previewUrl}?${values}`, { headers: { Accept: 'text/html' } });

            if (response.ok && asked === latest) {
                frame.srcdoc = await response.text();
            }
        } catch {
            // The links still open the preview; the frame keeps the last one.
        }
    };

    const later = () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(refresh, DELAY);
    };

    form.addEventListener('input', later);
    form.addEventListener('change', later);

    if (paper instanceof HTMLSelectElement) {
        paper.addEventListener('change', refresh);
    }

    refresh();
}
