/*
 * A receipt roll's page is as long as the receipt on it.
 *
 * A roll has a width and no length, and CSS cannot say "as long as the
 * content": the stylesheet (resources/css/print/80mm.css, 58mm.css) gives a
 * fallback length, and this replaces it with the receipt's measured one, so
 * the printer neither cuts a long receipt into pages nor feeds blank paper
 * after a short one.
 *
 * A constructed stylesheet rather than a <style> element, which the content
 * policy refuses. The receipt is laid out at the roll's width on screen too,
 * so what is measured here is what prints.
 */
const MM_PER_PX = 25.4 / 96;

function fitPage() {
    const receipt = document.querySelector('.receipt');
    const width = document.body.dataset.rollWidth;

    if (!receipt || !width || !('adoptedStyleSheets' in document)) {
        return;
    }

    // The receipt, the page's top and bottom margins (3 mm each), and a
    // millimetre so rounding never pushes the last line onto a second page.
    const length = Math.ceil(receipt.getBoundingClientRect().height * MM_PER_PX) + 7;
    const sheet = new CSSStyleSheet();

    sheet.replaceSync(`@page { size: ${width}mm ${length}mm; }`);
    document.adoptedStyleSheets = [...document.adoptedStyleSheets.filter((s) => !s.rollPage), sheet];
    sheet.rollPage = true;
}

window.addEventListener('load', fitPage);
window.addEventListener('beforeprint', fitPage);
