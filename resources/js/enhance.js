/**
 * Run a component's set-up on every element that asks for it - those in the
 * page now and those added later.
 *
 * An invoice line is cloned from a <template> after load, and it brings its
 * own unit dropdown and VAT reason with it; a listener bound at load would
 * never see them. One observer watches the document for added elements and
 * hands each to the components whose selector it matches. Each element is
 * set up once (data-enhanced), so a node moved rather than added is left
 * alone.
 */

/** @type {Array<[string, (element: HTMLElement) => void]>} */
const registry = [];

let observer = null;

/** @param {ParentNode} root */
function run(root) {
    for (const [selector, setUp] of registry) {
        const found = [];

        if (root instanceof HTMLElement && root.matches(selector)) {
            found.push(root);
        }

        found.push(...root.querySelectorAll(selector));

        for (const element of found) {
            const marks = (element.dataset.enhanced ?? '').split(' ');

            if (marks.includes(selector)) {
                continue;
            }

            element.dataset.enhanced = [...marks.filter(Boolean), selector].join(' ');
            setUp(element);
        }
    }
}

/**
 * @param {string} selector
 * @param {(element: HTMLElement) => void} setUp
 */
export function enhance(selector, setUp) {
    registry.push([selector, setUp]);
    run(document);

    observer ??= new MutationObserver((records) => {
        for (const record of records) {
            for (const node of record.addedNodes) {
                if (node instanceof HTMLElement) {
                    run(node);
                }
            }
        }
    });

    observer.observe(document.body, { childList: true, subtree: true });
}

/**
 * Place a floating panel - a list, a calendar - under its anchor, flipped
 * above it when there is no room below, inside the viewport either way.
 * Fixed rather than absolute so a card's overflow cannot clip it.
 *
 * @param {HTMLElement} panel
 * @param {HTMLElement} anchor
 */
export function placeUnder(panel, anchor) {
    const gap = 4;
    const margin = 8;

    panel.style.position = 'fixed';
    panel.style.margin = '0';
    panel.style.minWidth = `${Math.min(anchor.getBoundingClientRect().width, window.innerWidth - margin * 2)}px`;

    const height = panel.offsetHeight;
    let box = anchor.getBoundingClientRect();
    const fitsBelow = () => window.innerHeight - box.bottom - gap - margin >= height;
    const fitsAbove = () => box.top - gap - margin >= height;

    // Room on neither side: bring the page up so it fits under the field,
    // rather than laying the panel over the field it belongs to.
    if (!fitsBelow() && !fitsAbove()) {
        window.scrollBy({ top: Math.min(box.top - margin, height - (window.innerHeight - box.bottom - gap - margin)), behavior: 'instant' });
        box = anchor.getBoundingClientRect();
    }

    const width = Math.min(Math.max(box.width, panel.offsetWidth || 0), window.innerWidth - margin * 2);
    const top = !fitsBelow() && fitsAbove() ? box.top - gap - height : box.bottom + gap;

    // Start-aligned with the anchor in either direction, kept on screen.
    const rtl = getComputedStyle(anchor).direction === 'rtl';
    let left = rtl ? box.right - width : box.left;
    left = Math.min(Math.max(margin, left), window.innerWidth - margin - width);

    panel.style.top = `${Math.round(top)}px`;
    panel.style.left = `${Math.round(left)}px`;
}

/**
 * Show a panel in the top layer where the browser has popovers, so no
 * stacking context or overflow hides it; otherwise just unhide it.
 *
 * @param {HTMLElement} panel
 */
export function showPanel(panel) {
    panel.hidden = false;

    if (panel.popover !== undefined && panel.hasAttribute('popover') && !panel.matches(':popover-open')) {
        try {
            panel.showPopover();
        } catch {
            // Not connected yet, or already shown by another path.
        }
    }
}

/** @param {HTMLElement} panel */
export function hidePanel(panel) {
    if (panel.hasAttribute('popover') && panel.matches?.(':popover-open')) {
        try {
            panel.hidePopover();
        } catch {
            // Already hidden.
        }
    }

    panel.hidden = true;
}

/** A unique id for markup a script builds. */
let sequence = 0;

export function uid(prefix) {
    sequence += 1;

    return `${prefix}-${sequence}`;
}
