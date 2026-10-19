/**
 * A drop zone over the browser's file input (x-ui.file).
 *
 * The native button reads "Choose File - No file chosen" in the browser's
 * language whatever the page's, and says nothing of what it will take until
 * the server refuses it. The input stays - visually hidden but focusable, so
 * Tab reaches it and Enter or Space opens the picker as always - and a zone
 * is drawn beside it: it takes a dropped file, names what was chosen, shows
 * an image, refuses a wrong type or too large a file in our words (by
 * setCustomValidity, so the form will not send it and validate.js says why)
 * and lets one be taken off again.
 *
 * A form sending a chosen file shows its progress: it is sent with
 * XMLHttpRequest, whose upload reports it, and the page then goes where the
 * server sends it, as a normal submission's would. If that fails, the form
 * is sent the ordinary way.
 */

import { enhance } from './enhance.js';
import { t } from './strings.js';

const UPLOAD_ICON =
    '<svg class="size-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0l-4 4m4-4l4 4M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2"/></svg>';

/** @param {number} bytes */
function size(bytes) {
    return bytes >= 1048576 ? t(':count MB', { count: (bytes / 1048576).toFixed(1).replace(/\.0$/, '') }) : t(':count KB', { count: Math.max(1, Math.round(bytes / 1024)) });
}

/**
 * Whether a file is one the accept attribute names: a type (image/png), a
 * family (image/*) or an extension (.pdf).
 */
function accepted(file, accept) {
    if (!accept) {
        return true;
    }

    const name = file.name.toLowerCase();
    const type = (file.type || '').toLowerCase();

    return accept
        .split(',')
        .map((entry) => entry.trim().toLowerCase())
        .some((entry) => (entry.startsWith('.') ? name.endsWith(entry) : entry.endsWith('/*') ? type.startsWith(entry.slice(0, -1)) : type === entry));
}

/** @param {HTMLElement} host  the element carrying data-file-field */
function setUp(host) {
    const input = host.querySelector('input[type="file"]');

    if (!(input instanceof HTMLInputElement)) {
        return;
    }

    const maxBytes = Number(host.dataset.maxBytes) || 0;
    const maxFiles = Number(host.dataset.maxFiles) || 0;

    input.classList.add('sr-only', 'peer');
    host.dataset.faceHost = '';
    host.classList.add('relative');
    host.querySelector('[data-file-hint]')?.remove();

    const zone = document.createElement('span');
    zone.dataset.dropZone = '';
    zone.className =
        'mt-1 flex min-h-24 cursor-pointer flex-col items-center justify-center gap-1 rounded-control border-2 border-dashed border-line-strong bg-sunken/50 px-4 py-5 text-center text-sm font-normal text-muted transition-colors hover:border-accent hover:text-fg peer-focus-visible:border-focus peer-focus-visible:ring-3 peer-focus-visible:ring-focus/25 data-dragging:border-accent data-dragging:bg-accent-soft data-invalid:border-danger';
    zone.setAttribute('aria-hidden', 'true');
    zone.innerHTML = `<span class="text-accent">${UPLOAD_ICON}</span>`;

    const lead = document.createElement('span');
    lead.className = 'font-medium text-fg';
    lead.textContent = input.multiple ? t('Drop files here, or choose them') : t('Drop a file here, or choose one');

    const hint = document.createElement('span');
    hint.className = 'text-xs text-subtle';
    hint.textContent = host.dataset.hint ?? '';

    zone.append(lead, hint);

    const list = document.createElement('ul');
    list.className = 'mt-2 space-y-2';
    list.setAttribute('aria-live', 'polite');

    const error = document.createElement('span');
    error.className = 'mt-1 block text-xs font-medium text-danger';
    error.setAttribute('role', 'alert');
    error.hidden = true;

    const progress = document.createElement('span');
    progress.className = 'mt-2 block';
    progress.hidden = true;
    progress.innerHTML = '<progress max="100" value="0" class="ui-progress w-full"></progress><span class="mt-1 block text-xs text-subtle"></span>';
    host.dataset.fileProgress = '';

    input.after(zone, list, error, progress);

    // The zone is a picture of the input: a click on it is a click on it.
    zone.addEventListener('click', (event) => {
        event.preventDefault();
        input.click();
    });

    const check = () => {
        const files = Array.from(input.files ?? []);
        let problem = '';

        if (maxFiles && files.length > maxFiles) {
            problem = t('Choose at most :count files.', { count: maxFiles });
        }

        for (const file of files) {
            if (!accepted(file, input.accept)) {
                problem = t(':name is not a type this takes.', { name: file.name });
            } else if (maxBytes && file.size > maxBytes) {
                problem = t(':name is larger than :size.', { name: file.name, size: size(maxBytes) });
            }
        }

        input.setCustomValidity(problem);
        error.textContent = problem;
        error.hidden = problem === '';
        zone.toggleAttribute('data-invalid', problem !== '');
    };

    const render = () => {
        list.replaceChildren();

        Array.from(input.files ?? []).forEach((file, index) => {
            const item = document.createElement('li');
            item.className = 'flex items-center gap-3 rounded-control border border-line bg-surface p-2 text-sm';

            if (file.type.startsWith('image/') && file.size < 5 * 1048576) {
                const image = document.createElement('img');
                image.alt = '';
                image.className = 'size-12 shrink-0 rounded border border-line bg-paper object-contain';
                const reader = new FileReader();
                reader.addEventListener('load', () => {
                    image.src = String(reader.result);
                });
                reader.readAsDataURL(file);
                item.append(image);
            }

            const name = document.createElement('span');
            name.className = 'min-w-0 flex-1';
            name.innerHTML = '<span class="block truncate font-medium text-fg" dir="auto"></span><span class="block text-xs text-subtle"></span>';
            name.firstElementChild.textContent = file.name;
            name.lastElementChild.textContent = size(file.size);
            item.append(name);

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'inline-flex size-11 shrink-0 items-center justify-center rounded-button text-subtle hover:bg-sunken hover:text-danger focus-visible:outline-2 focus-visible:outline-focus';
            remove.setAttribute('aria-label', t('Remove :name', { name: file.name }));
            remove.innerHTML = '<svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>';
            remove.addEventListener('click', (event) => {
                event.preventDefault();
                const kept = new DataTransfer();
                Array.from(input.files ?? []).forEach((other, at) => at !== index && kept.items.add(other));
                input.files = kept.files;
                input.dispatchEvent(new Event('change', { bubbles: true }));
                input.focus();
            });
            item.append(remove);

            list.append(item);
        });
    };

    input.addEventListener('change', () => {
        check();
        render();
    });

    for (const type of ['dragenter', 'dragover']) {
        zone.addEventListener(type, (event) => {
            event.preventDefault();
            zone.setAttribute('data-dragging', '');
        });
    }

    for (const type of ['dragleave', 'drop']) {
        zone.addEventListener(type, () => zone.removeAttribute('data-dragging'));
    }

    zone.addEventListener('drop', (event) => {
        event.preventDefault();
        const dropped = Array.from(event.dataTransfer?.files ?? []);

        if (dropped.length === 0) {
            return;
        }

        const chosen = new DataTransfer();
        const keep = input.multiple ? [...Array.from(input.files ?? []), ...dropped] : dropped.slice(0, 1);
        keep.forEach((file) => chosen.items.add(file));
        input.files = chosen.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
        input.focus();
    });
}

/**
 * Send a form carrying a chosen file with its progress shown, and show the
 * page the server answers with, as a normal submission would.
 *
 * @param {HTMLFormElement} form
 * @param {HTMLElement|null} submitter
 */
function upload(form, submitter) {
    const bars = Array.from(form.querySelectorAll('[data-file-progress]')).filter((host) => host.querySelector('input[type="file"]')?.files?.length);
    const body = new FormData(form, submitter ?? undefined);
    const request = new XMLHttpRequest();

    request.open((form.getAttribute('method') || 'GET').toUpperCase(), form.action);
    // HTML, so a failed validation redirects back with its errors as usual.
    request.setRequestHeader('Accept', 'text/html');
    request.setRequestHeader('X-Redirect-Manually', '1');

    for (const host of bars) {
        const box = host.querySelector('progress')?.parentElement;
        box.hidden = false;
    }

    submitter?.setAttribute('disabled', '');
    form.setAttribute('aria-busy', 'true');

    request.upload.addEventListener('progress', (event) => {
        if (!event.lengthComputable) {
            return;
        }

        const percent = Math.round((event.loaded / event.total) * 100);

        for (const host of bars) {
            host.querySelector('progress').value = percent;
            host.querySelector('progress').nextElementSibling.textContent = t('Uploading... :percent%', { percent });
        }
    });

    // The ordinary way, when this way did not work: the page is sent again
    // as the browser would have sent it.
    const fallBack = () => {
        submitter?.removeAttribute('disabled');
        form.removeAttribute('aria-busy');

        for (const host of bars) {
            host.querySelector('progress').nextElementSibling.textContent = t('The upload did not finish. Sending it again the usual way.');
        }

        form.submit();
    };

    // The server names its redirect (NameRedirectForScript) rather than
    // the request following it out of sight, which would spend the flash
    // message; the page goes there itself.
    request.addEventListener('load', () => {
        let target = null;

        try {
            target = JSON.parse(request.responseText).redirect ?? null;
        } catch {
            target = null;
        }

        if (target) {
            window.location.assign(target);
        } else {
            fallBack();
        }
    });

    request.addEventListener('error', fallBack);

    request.send(body);
}

export function registerFileFields() {
    enhance('[data-file-field]', setUp);

    // After everything else has had its say: validation, a confirmation.
    document.addEventListener('submit', (event) => {
        const form = event.target;

        if (event.defaultPrevented || !(form instanceof HTMLFormElement) || form.method.toLowerCase() !== 'post') {
            return;
        }

        const chosen = Array.from(form.querySelectorAll('[data-file-field] input[type="file"]')).some((input) => input.files?.length);

        if (!chosen || typeof XMLHttpRequest === 'undefined') {
            return;
        }

        event.preventDefault();
        upload(form, event.submitter);
    });
}
