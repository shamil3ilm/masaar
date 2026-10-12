/**
 * The admin sidebar's collapse, as an Alpine component rather than an
 * expression.
 *
 * Alpine's standard build compiles the strings in x-show, @click and :class
 * with `new Function`, which a content policy can only permit by allowing
 * 'unsafe-eval' for every script on the page. The CSP build evaluates
 * nothing: an attribute may name a property or a method on the data object
 * and that is all. So `:class="sidebarOpen ? 'w-64' : 'w-20'"` becomes a
 * getter here, and the markup refers to it by name.
 *
 * That is the whole reason this file exists. One toggle does not need a
 * module; a policy without 'unsafe-eval' does.
 */
export default () => ({
    open: true,

    toggle() {
        this.open = !this.open;
    },

    get width() {
        return this.open ? 'w-64' : 'w-20';
    },
});
