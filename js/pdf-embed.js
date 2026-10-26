// Renders every .pdf-viewer[data-pdf-url] into stacked <canvas> pages via
// PDF.js (vendored under js/pdfjs/ — see tune-import-export-concept.md §9).
// Canvas rendering is device-independent, unlike native <iframe>/<object> PDF
// support which is inconsistent on mobile.
//
// Errors are surfaced *into the page* (not just the console) so problems are
// diagnosable without devtools. For the failures this script cannot see —
// starting with "never executed" — the templates pre-render an "Open PDF" link
// inside the container that this script removes once it draws; see
// tune_pdf_viewer_markup() (functions.php) and fallbackLink() below.
//
// Hence the .js extension on this file and on the vendored pdfjs/ modules,
// despite them all being ES modules: nginx on the live host has no mime.types
// entry for .mjs and served them as text/plain, which browsers reject outright
// for type="module" — every PDF tune showed a blank scan area with no error.
// Keep new module files on .js unless that MIME mapping is fixed server-side.

// The server-rendered fallback link (tune_pdf_viewer_markup(), functions.php).
// It is present before this script runs and gets dropped once rendering starts,
// so a module that never executes still leaves the PDF reachable.
function fallbackLink(container) {
    return container.querySelector('.pdf-viewer-fallback');
}

function showError(container, msg) {
    var box = document.createElement('div');
    box.className = 'pdf-viewer-error';
    box.style.cssText = 'color:#b00;font-size:.85em;padding:.4em 0';
    box.textContent = 'PDF preview unavailable: ' + msg + ' ';
    // On failure only, offer a direct link so the PDF stays reachable — unless
    // the server-rendered one is still in place, which would double it up.
    var url = container.getAttribute('data-pdf-url');
    if (url && !fallbackLink(container)) {
        var a = document.createElement('a');
        a.href = url;
        a.target = '_blank';
        a.rel = 'noopener';
        a.textContent = 'Open PDF';
        box.appendChild(a);
    }
    container.appendChild(box);
    // eslint-disable-next-line no-console
    console.warn('pdf-embed:', msg);
}

// PDF.js kann nicht nur scheitern, sondern HÄNGEN: bei "La Tête Ailleurs" (ein
// CCITT-G4-Fax-Scan aus einem Canon) kam nie ein Canvas, aber auch nie ein Fehler —
// der Worker antwortete einfach nicht mehr. Ein abgewiesenes Promise landet in der
// Fehlerbehandlung unten, ein hängendes nirgends: die Fläche bleibt für immer leer.
// Deshalb ein Zeitlimit, das den Hänger in einen sichtbaren Fehler samt Link
// verwandelt. Grosszügig bemessen — es hängen ~3 MB Bibliothek und das Parsen des
// Dokuments dran, ein langsames Mobilnetz darf nicht fälschlich abgebrochen werden.
var LOAD_TIMEOUT_MS = 12000;

function withTimeout(promise, ms, what) {
    var timer;
    return Promise.race([
        promise.then(function (v) { clearTimeout(timer); return v; },
                     function (e) { clearTimeout(timer); throw e; }),
        new Promise(function (_, reject) {
            timer = setTimeout(function () {
                reject(new Error(what + ' did not respond within ' + Math.round(ms / 1000) + ' s'));
            }, ms);
        })
    ]);
}

async function renderViewer(pdfjsLib, container) {
    var url = container.getAttribute('data-pdf-url');
    if (!url) {
        return;
    }
    try {
        var pdf = await withTimeout(pdfjsLib.getDocument({ url: url }).promise,
                                    LOAD_TIMEOUT_MS, 'PDF.js');
        // Document is open and pages are about to be drawn — the fallback link
        // has done its job. Removed here rather than up front so that a failure
        // in getDocument() (bad URL, 404, corrupt file) still leaves it standing.
        var fb = fallbackLink(container);
        if (fb) {
            fb.remove();
        }
        for (var pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
            var page = await pdf.getPage(pageNum);
            var baseWidth = page.getViewport({ scale: 1 }).width;
            var scale = (container.clientWidth || 800) / baseWidth;
            var viewport = page.getViewport({ scale: scale });

            var canvas = document.createElement('canvas');
            canvas.width = viewport.width;
            canvas.height = viewport.height;
            canvas.style.width = '100%';
            canvas.style.height = 'auto';
            canvas.style.display = 'block';
            canvas.style.marginBottom = '1em';
            container.appendChild(canvas);

            await page.render({ canvasContext: canvas.getContext('2d'), viewport: viewport }).promise;
        }
    } catch (err) {
        showError(container, (err && err.message) ? err.message : String(err));
    }
}

(async function () {
    var containers = document.querySelectorAll('.pdf-viewer[data-pdf-url]');
    if (!containers.length) {
        return;
    }

    var pdfjsLib;
    try {
        pdfjsLib = await import('./pdfjs/pdf.js');
    } catch (err) {
        containers.forEach(function (c) {
            showError(c, 'could not load PDF.js library (' + ((err && err.message) ? err.message : String(err)) + ')');
        });
        return;
    }

    pdfjsLib.GlobalWorkerOptions.workerSrc = new URL('./pdfjs/pdf.worker.js', import.meta.url).href;

    for (var i = 0; i < containers.length; i++) {
        await renderViewer(pdfjsLib, containers[i]);
    }
})();
