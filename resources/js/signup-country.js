/**
 * Sign-up's country choice: only the chosen country's section shows, and
 * only its fields are sent.
 *
 * Both sections are on the page, and both name their address fields street,
 * city and so on, so the hidden one is disabled as well as hidden - a
 * disabled field is neither submitted nor checked by the browser's
 * required. The server renders the same state from old('country'), so a
 * form sent back with errors opens on the country that was chosen.
 *
 * The choice is native radio buttons, so the keyboard moves through it as
 * it would any radio group. Markup:
 *   x-data="signupCountry" data-country="SA"   on the form
 *   @change="choose"                           on the radio group
 *   :hidden / :disabled="notSaudi" / "notUae"  on each section
 */
export function signupCountry() {
    return {
        country: 'SA',

        init() {
            this.country = this.$el.dataset.country || 'SA';
        },

        /** @param {Event} event */
        choose(event) {
            const radio = event.target;

            if (radio instanceof HTMLInputElement && radio.name === 'country' && radio.checked) {
                this.country = radio.value;
            }
        },

        get notSaudi() {
            return this.country !== 'SA';
        },

        get notUae() {
            return this.country !== 'AE';
        },
    };
}
