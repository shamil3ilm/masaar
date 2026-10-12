import Alpine from '@alpinejs/csp';

import { registerConfirmations } from './confirm.js';
import sidebar from './sidebar.js';

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

registerConfirmations();

Alpine.start();
