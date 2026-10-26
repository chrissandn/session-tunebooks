<?php

/**
 * Settings — per-browser display preferences.
 *
 * Assign this template to a Page with slug "settings". The preferences here are
 * purely client-side (localStorage, applied as body classes in main.js); nothing
 * is stored server-side. Currently hosts the "Show original scans" toggle that
 * used to sit in every filter bar (moved here to save space on phones, PIC01a).
 */

get_header();
?>

<div class="filter-bar">
    <div class="container pt-2">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h1>Settings</h1>
            </div>
        </div>
    </div>
</div>

<div class="container the-tunes">
    <div class="row tune-row py-3">
        <div class="col-12 form-check">
            <input class="form-check-input show-scans-toggle" type="checkbox" id="show-scans-toggle-settings">
            <label class="form-check-label" for="show-scans-toggle-settings">Show original scans</label>
            <p class="text-muted small mb-0">
                When on, scanned sheet-music images are shown alongside (or instead of)
                the notation on tunes and sets. This preference is saved in this browser.
            </p>
        </div>
    </div>

    <?php // Ausgeblendet solange die Vorschau abgeschaltet ist — sonst stellt man
          // hier etwas ein, das auf der Tunes-Liste folgenlos bleibt. ?>
    <?php if (isg_tunes_preview_enabled()) : ?>
    <div class="row tune-row py-3">
        <div class="col-12">
            <label class="form-label mb-1" for="tunes-preview-select">Tunes one-liner preview</label>
            <select class="form-select tunes-preview-select" id="tunes-preview-select" style="max-width:320px">
                <option value="off">Off</option>
                <option value="abc">abc only</option>
                <option value="all">abc and scans</option>
            </select>
            <p class="text-muted small mb-0 mt-1">
                Shows a one-line preview under each tune in the Tunes list, which the
                arrow expands. Off by default: the list is long, and a preview per row
                makes it a good deal longer to scroll. Takes effect the next time the
                Tunes list is loaded. This preference is saved in this browser.
            </p>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php get_footer(); ?>
