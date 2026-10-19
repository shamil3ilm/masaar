import Alpine from '@alpinejs/csp';

import { registerCataloguePickers } from './catalogue-pickers.js';
import { registerColorFields } from './color-field.js';
import { registerComboboxes } from './combobox.js';
import { registerConfirmations } from './confirm.js';
import { registerDateFields } from './date-field.js';
import { registerEasterEggs } from './easter-eggs.js';
import { registerFileFields } from './file-field.js';
import { registerInvoiceCharges } from './invoice-charges.js';
import { registerInvoiceLines } from './invoice-lines.js';
import { registerInvoicePreview } from './invoice-preview.js';
import { registerInvoiceVat } from './invoice-vat.js';
import { registerPasswordFields } from './password.js';
import { signupCountry } from './signup-country.js';
import { registerTemplatePreview } from './template-preview.js';
import { registerToasts } from './toast.js';
import { registerTooltips } from './tooltip.js';
import { registerInterface } from './ui.js';
import { registerValidation } from './validate.js';
import { drawer, sidebar } from './sidebar.js';

/*
 * The CSP build of Alpine, deliberately.
 *
 * It refuses to evaluate expressions written in attributes, which is what the
 * standard build uses `new Function` for - and allowing that needs
 * script-src 'unsafe-eval', granted to every script on the page to serve a
 * sidebar toggle. Components are registered here by name instead and the
 * markup refers to them, so the policy in SecurityHeaders needs no 'unsafe-'
 * of any kind.
 *
 * The cost is real and worth knowing: x-data="{ open: true }" and
 * x-show="!open" will not work under this build. Anything with state belongs
 * in a file beside this one and is registered below.
 */
Alpine.data('sidebar', sidebar);
Alpine.data('drawer', drawer);
Alpine.data('signupCountry', signupCountry);

registerConfirmations();
registerInvoiceLines();
registerInvoiceCharges();
registerCataloguePickers();
registerInvoiceVat();
registerInvoicePreview();
registerTemplatePreview();
registerValidation();
registerInterface();
registerComboboxes();
registerDateFields();
registerFileFields();
registerTooltips();
registerColorFields();
registerPasswordFields();
registerToasts();
registerEasterEggs();

Alpine.start();
