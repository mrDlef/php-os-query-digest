/*
 * Loads the playground where the playground is, and nowhere else.
 *
 * This has to be a site-wide script, and that is not a preference. Instant
 * navigation swaps the parts of the document Material knows about — it does not
 * re-run the <head> or the scripts at the end of the body — so a page that
 * carries its own <script> is arrived at with that script never executed. Only
 * something already running can notice the page has changed.
 *
 * So this: nine lines on every page, and the 19 KB module fetched the first time
 * a document actually contains the playground. import() caches by URL, so
 * arriving a second time costs a resolved promise; booting again is the module's
 * own business, and it subscribes for that itself.
 */
const MODULE = new URL('../playground/playground.js', import.meta.url).href;

const load = () => {
    if (document.getElementById('pg-app') !== null) {
        import(MODULE);
    }
};

load();
window.document$?.subscribe(load);
