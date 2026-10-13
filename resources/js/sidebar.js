/**
 * The navigation, at whatever width the screen is.
 *
 * Both layouts put a 256px sidebar in a flex row and left it there. On a
 * 390px phone that is two thirds of the screen: the admin console had about
 * 134px of content beside it, and the portal had none spare at all. The admin
 * sidebar had a toggle, but it started open and collapsed only to 80px, so
 * the narrow case was never actually reachable.
 *
 * Below md the sidebar is a drawer - off-canvas, over the content, with a
 * backdrop - and the page gets the whole width. At md and above it is a
 * column again, and in the admin console it still collapses to icons.
 *
 * Two registrations rather than one component with a flag, because Alpine's
 * CSP build cannot pass arguments to x-data: an attribute may name a
 * property or a method and nothing else. The admin console collapses to
 * icons because its links are labelled with them; the portal's are text
 * first and would clip, so it only has the drawer.
 *
 * Which is also why this is a module at all. The standard Alpine build
 * compiles the strings in x-show, @click and :class with `new Function`,
 * which a content policy can only permit by allowing 'unsafe-eval' for every
 * script on the page. Here the markup names a getter and the getter lives
 * in JavaScript.
 */

/** Tailwind's md. Below this the sidebar is a drawer. */
const WIDE = '(min-width: 768px)';

/**
 * @param {{collapsible: boolean}} options
 */
function create({ collapsible }) {
    return () => ({
        wide: window.matchMedia(WIDE).matches,

        // Open on a wide screen, shut on a narrow one. A drawer that starts
        // open is a screen that starts covered.
        open: window.matchMedia(WIDE).matches,

        init() {
            const query = window.matchMedia(WIDE);

            // Rotating a phone, or dragging a desktop window narrow, changes
            // which of the two this is. Without this the sidebar keeps the
            // state it booted with: a drawer stuck open over a phone, or a
            // column that has vanished on a desktop.
            query.addEventListener('change', (event) => {
                this.wide = event.matches;
                this.open = event.matches;
            });
        },

        toggle() {
            this.open = !this.open;
        },

        close() {
            this.open = false;
        },

        /**
         * Everything about the panel that changes. Nothing in this vocabulary
         * appears in the markup's own class attribute, because two utilities
         * from the same family - translate-x-0 and -translate-x-full - are
         * resolved by their order in the stylesheet, not by the order they
         * are written on the element.
         */
        get panel() {
            const width = collapsible && !this.open && this.wide ? 'w-20' : 'w-64';
            const slide = this.open || this.wide ? 'translate-x-0' : '-translate-x-full';

            return `${width} ${slide}`;
        },

        /** True only while the drawer is covering the page. */
        get covering() {
            return this.open && !this.wide;
        },

        /**
         * What the toggle announces.
         *
         * A button that opens something has to say whether it is open, or a
         * screen reader offers no way to know what pressing it just did.
         */
        get expanded() {
            return this.open;
        },

        /** Labels are hidden only by the icon collapse, never by the drawer. */
        get labelled() {
            return this.open || !this.wide;
        },
    });
}

export const sidebar = create({ collapsible: true });

export const drawer = create({ collapsible: false });
