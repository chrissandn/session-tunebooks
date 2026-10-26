<?php
/**
 * Front-end batch scan-crop tool (Feature #4b companion). Editors flip through
 * every scanned tune in a chosen book and tighten its crop; optionally attach
 * ABC from thesession.org. Backend + endpoints: tune-scan-review.php.
 */
if (!current_user_can('edit_posts')) {
    wp_safe_redirect(home_url('/'));
    exit;
}
get_header();

$sr_books = get_terms(['taxonomy' => TUNEBOOK_TAX, 'hide_empty' => false]);
?>
<style>
#sr-wrap { max-width: 1000px; margin: 0 auto; padding: 12px; }
#sr-viewer { display: none; }
#sr-stage { position: relative; display: inline-block; max-width: 100%; line-height: 0;
            touch-action: none; user-select: none; background: #f4f4f4; }
#sr-img { display: block; max-width: 100%; height: auto; }
#sr-crop { position: absolute; box-sizing: border-box; border: 2px dashed #0a58ca;
           box-shadow: 0 0 0 9999px rgba(0,0,0,.35); cursor: move; }
.sr-h { position: absolute; width: 14px; height: 14px; background: #0a58ca; border: 2px solid #fff;
        border-radius: 50%; box-sizing: border-box; }
.sr-h.nw { left: -8px;  top: -8px;  cursor: nwse-resize; }
.sr-h.n  { left: 50%;   top: -8px;  margin-left: -7px; cursor: ns-resize; }
.sr-h.ne { right: -8px; top: -8px;  cursor: nesw-resize; }
.sr-h.e  { right: -8px; top: 50%;   margin-top: -7px; cursor: ew-resize; }
.sr-h.se { right: -8px; bottom: -8px; cursor: nwse-resize; }
.sr-h.s  { left: 50%;   bottom: -8px; margin-left: -7px; cursor: ns-resize; }
.sr-h.sw { left: -8px;  bottom: -8px; cursor: nesw-resize; }
.sr-h.w  { left: -8px;  top: 50%;   margin-top: -7px; cursor: ew-resize; }
#sr-ts-results { max-height: 220px; overflow-y: auto; }
#sr-ts-results button, #sr-ts-settings button { text-align: left; }
.sr-busy { opacity: .5; pointer-events: none; }
</style>

<div id="sr-wrap">
    <h1>Scan Review</h1>
    <p class="text-muted">Flip through the scanned tunes of a book and tighten each crop
        (drag the handles to <strong>trim</strong> — the frame can only get smaller). Optionally
        attach ABC from thesession.org. A scan marked <span class="badge bg-warning text-dark">PDF</span>
        is converted to an image on the first save — with or without a trim; the PDF is kept as
        the original.</p>

    <div class="row g-2 align-items-end mb-3">
        <div class="col-auto">
            <label for="sr-book" class="form-label mb-0">Book</label>
            <select id="sr-book" class="form-select">
                <option value="">— choose —</option>
                <?php foreach ($sr_books as $b) : ?>
                    <option value="<?php echo (int) $b->term_id; ?>">
                        <?php echo esc_html($b->name . ' (' . $b->count . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <button id="sr-load" class="btn btn-primary">Load scans</button>
        </div>
        <div class="col-auto"><span id="sr-loadmsg" class="text-muted"></span></div>
    </div>

    <div id="sr-viewer">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div><strong id="sr-title"></strong> <a id="sr-link" href="#" target="_blank" class="small">↗</a>
                <span id="sr-pdf-note" class="badge bg-warning text-dark" style="display:none">PDF</span></div>
            <div><span id="sr-counter" class="badge bg-secondary"></span></div>
        </div>

        <div class="text-center mb-2">
            <div id="sr-stage">
                <img id="sr-img" alt="scan">
                <div id="sr-crop">
                    <span class="sr-h nw"></span><span class="sr-h n"></span><span class="sr-h ne"></span>
                    <span class="sr-h e"></span><span class="sr-h se"></span><span class="sr-h s"></span>
                    <span class="sr-h sw"></span><span class="sr-h w"></span>
                </div>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mb-1">
            <button id="sr-prev" class="btn btn-outline-secondary">← Prev</button>
            <button id="sr-reset" class="btn btn-outline-secondary">Reset box</button>
            <button id="sr-save" class="btn btn-success">Save crop &amp; next →</button>
            <button id="sr-skip" class="btn btn-outline-secondary">Next →</button>
            <span id="sr-savemsg" class="align-self-center text-muted"></span>
        </div>
        <p class="small text-muted mb-3">Crop and ABC each save on their own button
            (<em>Save crop</em>, <em>Attach this ABC</em>) and persist immediately.
            <em>Prev</em>/<em>Next</em> only navigate — nothing already saved is lost.</p>

        <details id="sr-abc-panel" class="mb-4" open>
            <summary>Attach ABC from thesession.org <span id="sr-abc-state" class="badge bg-light text-dark"></span></summary>
            <div class="row g-2 mt-1">
                <div class="col-auto">
                    <input id="sr-ts-q" class="form-control" type="text" placeholder="tune title">
                </div>
                <div class="col-auto"><button id="sr-ts-search" class="btn btn-outline-primary">Search</button></div>
                <div class="col-auto"><span id="sr-ts-msg" class="text-muted"></span></div>
            </div>
            <div id="sr-ts-results" class="list-group mt-2"></div>
            <div id="sr-ts-settings" class="mt-2"></div>
        </details>
    </div>
</div>

<script>
(function () {
    var AJAX  = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
    var NONCE = <?php echo wp_json_encode(wp_create_nonce(SCAN_REVIEW_NONCE)); ?>;
    var TSNON = <?php echo wp_json_encode(wp_create_nonce('tune_ts')); ?>;

    var items = [], idx = 0, cur = null;
    var crop = { x: 0, y: 0, w: 1, h: 1 }; // fractions of the displayed image

    var $ = function (id) { return document.getElementById(id); };
    function post(data) {
        var body = new URLSearchParams(data);
        return fetch(AJAX, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) { return r.json(); });
    }

    // ---- crop overlay ----------------------------------------------------
    var img = $('sr-img'), box = $('sr-crop');
    function imgRect() { return img.getBoundingClientRect(); }
    function render() {
        var r = imgRect();
        box.style.left   = (crop.x * r.width)  + 'px';
        box.style.top    = (crop.y * r.height) + 'px';
        box.style.width  = (crop.w * r.width)  + 'px';
        box.style.height = (crop.h * r.height) + 'px';
    }
    function resetBox() { crop = { x: 0, y: 0, w: 1, h: 1 }; render(); }

    var drag = null;
    function startDrag(mode, e) {
        e.preventDefault();
        var r = imgRect();
        drag = { mode: mode, r: r,
                 sx: e.clientX, sy: e.clientY,
                 ox: crop.x, oy: crop.y, ow: crop.w, oh: crop.h };
        document.addEventListener('pointermove', onMove);
        document.addEventListener('pointerup', endDrag);
    }
    function onMove(e) {
        if (!drag) return;
        var r = drag.r;
        var dx = (e.clientX - drag.sx) / r.width;
        var dy = (e.clientY - drag.sy) / r.height;
        var x = drag.ox, y = drag.oy, w = drag.ow, h = drag.oh;
        var min = 0.03;
        if (drag.mode === 'move') {
            x = Math.min(Math.max(0, drag.ox + dx), 1 - w);
            y = Math.min(Math.max(0, drag.oy + dy), 1 - h);
        } else {
            var m = drag.mode;
            if (m.indexOf('w') > -1) { var nx = Math.min(Math.max(0, drag.ox + dx), drag.ox + drag.ow - min); w = drag.ow - (nx - drag.ox); x = nx; }
            if (m.indexOf('n') > -1) { var ny = Math.min(Math.max(0, drag.oy + dy), drag.oy + drag.oh - min); h = drag.oh - (ny - drag.oy); y = ny; }
            if (m.indexOf('e') > -1) { w = Math.min(Math.max(min, drag.ow + dx), 1 - drag.ox); }
            if (m.indexOf('s') > -1) { h = Math.min(Math.max(min, drag.oh + dy), 1 - drag.oy); }
        }
        crop = { x: x, y: y, w: w, h: h };
        render();
    }
    function endDrag() {
        drag = null;
        document.removeEventListener('pointermove', onMove);
        document.removeEventListener('pointerup', endDrag);
    }
    box.addEventListener('pointerdown', function (e) {
        if (e.target === box) startDrag('move', e);
    });
    Array.prototype.forEach.call(box.querySelectorAll('.sr-h'), function (h) {
        var mode = h.className.replace('sr-h', '').trim();
        h.addEventListener('pointerdown', function (e) { e.stopPropagation(); startDrag(mode, e); });
    });
    window.addEventListener('resize', render);

    // ---- navigation ------------------------------------------------------
    function show() {
        cur = items[idx];
        $('sr-title').textContent = cur.title;
        $('sr-link').href = cur.link;
        $('sr-counter').textContent = (idx + 1) + ' / ' + items.length;
        // A PDF scan is shown via the page preview WP rendered on upload; saving
        // rasterizes it to a real image first (server side), so say so — and
        // there the whole frame is a valid selection ("convert, don't trim").
        $('sr-pdf-note').style.display = cur.is_pdf ? '' : 'none';
        $('sr-pdf-note').title = 'PDF scan — saving converts it to an image (the PDF stays as the original)';
        $('sr-save').textContent = cur.is_pdf ? 'Save & convert → next' : 'Save crop & next →';
        $('sr-savemsg').textContent = '';
        $('sr-abc-state').textContent = cur.has_abc ? 'ABC present' : '';
        $('sr-ts-results').innerHTML = '';
        $('sr-ts-settings').innerHTML = '';
        $('sr-ts-msg').textContent = '';
        $('sr-ts-q').value = cur.title;
        img.onload = function () { resetBox(); };
        img.src = cur.url;
        $('sr-prev').disabled = (idx === 0);
        maybeAutoSearch(); // re-search this tune's title if the ABC panel is open
    }
    function next() {
        if (idx < items.length - 1) { idx++; show(); }
        else { $('sr-savemsg').textContent = 'Done — last scan in this book.'; }
    }

    $('sr-load').addEventListener('click', function () {
        var book = $('sr-book').value;
        if (!book) { $('sr-loadmsg').textContent = 'Pick a book first.'; return; }
        $('sr-loadmsg').textContent = 'Loading…';
        post({ action: 'scan_review_list', _ajax_nonce: NONCE, book: book }).then(function (res) {
            if (!res.success) { $('sr-loadmsg').textContent = res.data || 'Error.'; return; }
            items = res.data.items; idx = 0;
            if (!items.length) { $('sr-loadmsg').textContent = 'No croppable scans in this book.'; $('sr-viewer').style.display = 'none'; return; }
            $('sr-loadmsg').textContent = res.data.book + ': ' + items.length + ' scans';
            $('sr-viewer').style.display = 'block';
            show();
        });
    });

    $('sr-prev').addEventListener('click', function () { if (idx > 0) { idx--; show(); } });
    $('sr-skip').addEventListener('click', next);
    $('sr-reset').addEventListener('click', resetBox);

    $('sr-save').addEventListener('click', function () {
        if (!cur) return;
        var untouched = (crop.x === 0 && crop.y === 0 && crop.w === 1 && crop.h === 1);
        // Untouched box = nothing to do — except on a PDF, where saving is also
        // the conversion to an image and therefore worth doing on its own.
        if (untouched && !cur.is_pdf) {
            $('sr-savemsg').textContent = 'Move the box in first to trim (or just Next).'; return;
        }
        $('sr-savemsg').textContent = cur.is_pdf ? 'Converting…' : 'Saving…';
        $('sr-viewer').classList.add('sr-busy');
        post({ action: 'scan_review_crop', _ajax_nonce: NONCE, att_id: cur.att_id,
               x: crop.x, y: crop.y, w: crop.w, h: crop.h }).then(function (res) {
            $('sr-viewer').classList.remove('sr-busy');
            if (!res.success) { $('sr-savemsg').textContent = res.data || 'Error.'; return; }
            // After a conversion the tune carries a JPG: keep working on that
            // attachment, so a second pass on this scan crops instead of
            // rasterizing again.
            cur.att_id = res.data.att_id;
            cur.is_pdf = false;
            cur.url = res.data.url;
            if (idx < items.length - 1) {
                next();
            } else {
                // Last scan: stay, but re-render so badge, button label and the
                // image reflect what was just saved.
                show();
                $('sr-savemsg').textContent = 'Saved — last scan in this book.';
            }
        });
    });

    // ---- thesession ABC --------------------------------------------------
    // abc2svg SVG preview — same low-level Abc/tosvg approach as the add-tune
    // page (page-add-tune.php); duplicated inline here to keep that working
    // page untouched. A fresh engine per render, because one malformed setting
    // corrupts a reused instance's state (see the add-tune note).
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    var ABC_METER_BY_TYPE = { 'jig': '6/8', 'slip jig': '9/8', 'polka': '2/4', 'mazurka': '3/4', 'slide': '12/8', 'waltz': '3/4' };
    var abcEnginePromise = null, abcRenderBuf = '', abcRenderErr = '';
    function loadAbcEngine() {
        if (abcEnginePromise) { return abcEnginePromise; }
        abcEnginePromise = new Promise(function (resolve, reject) {
            if (window.abc2svg && abc2svg.Abc) { resolve(); return; }
            var s = document.createElement('script');
            s.src = <?php echo wp_json_encode(get_template_directory_uri() . '/js/abc2svg-1.js'); ?>;
            s.onload = resolve; s.onerror = reject; document.head.appendChild(s);
        });
        return abcEnginePromise;
    }
    function newAbcEngine() {
        return new abc2svg.Abc({
            errmsg: function (msg) { abcRenderErr += (abcRenderErr ? '\n' : '') + msg; },
            img_out: function (str) { abcRenderBuf += str; }
        });
    }
    function buildPreviewAbc(type, key, abcBody) {
        var meter = ABC_METER_BY_TYPE[(type || '').toLowerCase()] || '4/4';
        var lines = String(abcBody || '').split('! ');
        return '%%unsizedsvg 1\n%%fgcolor black\n%%topspace 0\n%%topmargin 0\n' +
            '%%titlespace 0\n%%composerspace 0\n%%musicspace 0\n%%stretchlast 1\n' +
            '%%leftmargin 0\n%%rightmargin 0\nX:1\nR:jig\nM:' + meter + '\nL:1/8\nK:' +
            String(key || '').slice(0, 4) + '\n' + lines.join('\n') + '\n';
    }
    function renderAbcPreview(container, type, key, abcBody) {
        function fallback(errMsg) {
            container.innerHTML = '<pre class="small mb-0" style="max-height:150px;overflow:auto">' + esc(abcBody) + '</pre>' +
                (errMsg ? '<div class="text-danger small">Preview error: ' + esc(errMsg) + '</div>' : '');
        }
        loadAbcEngine().then(function () {
            abcRenderBuf = ''; abcRenderErr = '';
            try { newAbcEngine().tosvg('ts-preview', buildPreviewAbc(type, key, abcBody)); }
            catch (e) { fallback(e.message); return; }
            if (abcRenderBuf) { container.innerHTML = abcRenderBuf; } else { fallback(abcRenderErr); }
        }).catch(function () { fallback(); });
    }

    var lastSearch = null;
    function runSearch(q) {
        q = (q || '').trim();
        if (!q) return;
        $('sr-ts-q').value = q;
        lastSearch = q;
        $('sr-ts-msg').textContent = 'Searching…';
        $('sr-ts-results').style.display = ''; // may have been hidden after a pick
        $('sr-ts-results').innerHTML = ''; $('sr-ts-settings').innerHTML = '';
        post({ action: 'tune_ts_search', _ajax_nonce: TSNON, q: q }).then(function (res) {
            if (!res.success) { $('sr-ts-msg').textContent = res.data || 'Error.'; return; }
            $('sr-ts-msg').textContent = res.data.tunes.length + ' hits — pick the matching tune';
            res.data.tunes.forEach(function (t) {
                var b = document.createElement('button');
                b.className = 'list-group-item list-group-item-action';
                b.textContent = t.name + (t.type ? ' — ' + t.type : '');
                b.addEventListener('click', function () { loadSettings(t.id); });
                $('sr-ts-results').appendChild(b);
            });
        });
    }
    // Auto-search on the tune's title the moment the panel is opened (and when
    // moving to another tune while it stays open), so a human doesn't retype.
    function maybeAutoSearch() {
        var panel = $('sr-abc-panel');
        if (panel && panel.open && cur && lastSearch !== cur.title) { runSearch(cur.title); }
    }
    $('sr-abc-panel').addEventListener('toggle', function () { if (this.open) maybeAutoSearch(); });
    $('sr-ts-search').addEventListener('click', function () { runSearch($('sr-ts-q').value); });

    // After picking a tune we hide the hit list and show ONE setting at a time,
    // right under the scan, so scan vs. notation can be compared directly.
    // ‹ › page through settings, Back returns to the hit list.
    var tsSettings = [], tsIdx = 0, tsType = '';
    function loadSettings(id) {
        $('sr-ts-settings').innerHTML = '<div class="text-muted p-2">Loading settings…</div>';
        post({ action: 'tune_ts_detail', _ajax_nonce: TSNON, id: id }).then(function (res) {
            if (!res.success) { $('sr-ts-settings').textContent = res.data || 'Error.'; return; }
            tsSettings = res.data.settings || [];
            tsType = res.data.type || '';
            tsIdx = 0;
            $('sr-ts-results').style.display = 'none'; // (a) hide the hit list after a pick
            if (!tsSettings.length) {
                $('sr-ts-settings').innerHTML = '<div class="text-muted p-2 d-flex gap-2 align-items-center">No settings for this tune.</div>';
                $('sr-ts-settings').firstChild.appendChild(makeBackBtn());
                return;
            }
            renderSetting();
        });
    }
    function makeBackBtn() {
        var back = document.createElement('button');
        back.className = 'btn btn-sm btn-outline-secondary';
        back.textContent = '← Back to results';
        back.addEventListener('click', showResults);
        return back;
    }
    function showResults() {
        $('sr-ts-settings').innerHTML = '';
        $('sr-ts-results').style.display = '';
    }
    function renderSetting() {
        var s = tsSettings[tsIdx];
        $('sr-ts-settings').innerHTML = '';
        var bar = document.createElement('div');
        bar.className = 'd-flex flex-wrap gap-2 align-items-center mb-2';
        bar.appendChild(makeBackBtn());
        var prevNav = document.createElement('button');
        prevNav.className = 'btn btn-sm btn-outline-secondary';
        prevNav.textContent = '‹'; prevNav.disabled = (tsIdx === 0);
        prevNav.addEventListener('click', function () { if (tsIdx > 0) { tsIdx--; renderSetting(); } });
        var cnt = document.createElement('span');
        cnt.className = 'small';
        cnt.textContent = 'Setting ' + s.index + ' / ' + tsSettings.length + (s.key ? ' — key ' + s.key : '');
        var nextNav = document.createElement('button');
        nextNav.className = 'btn btn-sm btn-outline-secondary';
        nextNav.textContent = '›'; nextNav.disabled = (tsIdx === tsSettings.length - 1);
        nextNav.addEventListener('click', function () { if (tsIdx < tsSettings.length - 1) { tsIdx++; renderSetting(); } });
        var att = document.createElement('button');
        att.className = 'btn btn-sm btn-success';
        att.textContent = 'Attach this ABC';
        att.addEventListener('click', function () { attachAbc(s.abc); });
        bar.appendChild(prevNav); bar.appendChild(cnt); bar.appendChild(nextNav); bar.appendChild(att);
        var prevBox = document.createElement('div');
        prevBox.className = 'border rounded p-2 bg-white';
        prevBox.style.overflow = 'auto';
        prevBox.innerHTML = '<span class="text-muted small">Rendering…</span>';
        $('sr-ts-settings').appendChild(bar);
        $('sr-ts-settings').appendChild(prevBox);
        renderAbcPreview(prevBox, tsType, s.key, s.abc);
    }

    function attachAbc(abc) {
        if (!cur || !abc) return;
        $('sr-ts-msg').textContent = 'Attaching…';
        post({ action: 'scan_review_attach_abc', _ajax_nonce: NONCE, post_id: cur.post_id, abc: abc })
            .then(function (res) {
                if (!res.success) { $('sr-ts-msg').textContent = res.data || 'Error.'; return; }
                cur.has_abc = true;
                $('sr-abc-state').textContent = 'ABC present';
                $('sr-ts-msg').innerHTML = '<span class="text-success fw-bold">ABC attached &amp; saved ✓</span>';
            });
    }
})();
</script>
<?php get_footer(); ?>
