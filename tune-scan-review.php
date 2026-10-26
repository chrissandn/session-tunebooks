<?php
/**
 * Scan Review — front-end batch crop-correction for image tunes (Feature #4b
 * companion to the bulk import).
 *
 * Editors pick a tunebook and flip through every scanned tune in it, tightening
 * a crop rectangle where the automated bulk crop left slack (residual title,
 * next-tune bleed at the bottom, stray margins). Cropping is trim-only: it can
 * shrink the frame, never re-expand it (the source PDFs live only locally, not
 * on the server), which is exactly the direction this tool needs. Optionally a
 * thesession.org lookup attaches ABC to the tune, reusing the existing
 * tune_ts_search / tune_ts_detail endpoints (tune-thesession.php).
 *
 * All endpoints are logged-in + edit_posts gated. Nothing here touches the
 * source records or the import pipeline; it edits attachments already in the
 * media library.
 */

if (!defined('ABSPATH')) {
    exit;
}

const SCAN_REVIEW_SLUG  = 'scan-review';
const SCAN_REVIEW_NONCE = 'scan_review';

// PDF -> JPG rasterization (scan_review_rasterize_pdf): 300dpi renders an A4
// page at ~2480px wide, matching the bulk pipeline's `pdftoppm -scale-to-x 2480`.
// The cap keeps oversized pages from blowing up memory at that density.
const SCAN_REVIEW_PDF_DPI   = 300;
const SCAN_REVIEW_PDF_MAX_W = 2480;

/**
 * Self-provision the front-end page so the page-scan-review.php template routes
 * on both local and live without a manual "create a Page" step at deploy time.
 * Runs the DB lookup only until the page exists once (option guard).
 */
add_action('after_setup_theme', 'scan_review_ensure_page');
function scan_review_ensure_page()
{
    if (get_option('scan_review_page_id')) {
        return;
    }
    $existing = get_page_by_path(SCAN_REVIEW_SLUG);
    if ($existing) {
        update_option('scan_review_page_id', (int) $existing->ID);
        return;
    }
    $id = wp_insert_post([
        'post_title'   => 'Scan Review',
        'post_name'    => SCAN_REVIEW_SLUG,
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_content' => '',
    ]);
    if ($id && !is_wp_error($id)) {
        update_option('scan_review_page_id', (int) $id);
    }
}

/**
 * Rasterize a PDF scan into a JPG attachment on the same tune, so the crop tool
 * (and single.php's plain <img> path) can work on it.
 *
 * The PDF attachment is deliberately *kept* as the archived original — only the
 * new JPG becomes the tune's scan, via the featured image that
 * tune_find_scan_attachment() consults first. Export carries both files along
 * (tune_collect_attachments() de-dupes by id), so nothing is lost.
 *
 * Imagick with PDF support + Ghostscript are what WP itself uses to build PDF
 * previews on upload, so where a preview exists this path works too. Where it
 * doesn't, fall back to promoting that already-rendered preview JPG — lower
 * resolution (WP renders at 128dpi), but it keeps the tool usable instead of
 * dead-ending.
 *
 * @return int|WP_Error  the new JPG attachment's id
 */
function scan_review_rasterize_pdf($att_id)
{
    $src_path = get_attached_file($att_id);
    if (!$src_path || !file_exists($src_path)) {
        return new WP_Error('missing', 'PDF file missing on disk.');
    }
    $post_id = wp_get_post_parent_id($att_id);
    if (!$post_id) {
        return new WP_Error('orphan', 'PDF is not attached to a tune.');
    }

    $dir  = dirname($src_path);
    $base = pathinfo($src_path, PATHINFO_FILENAME) . '.jpg';
    $dest = trailingslashit($dir) . wp_unique_filename($dir, $base);

    $rendered = false;
    if (extension_loaded('imagick') && class_exists('Imagick') && Imagick::queryFormats('PDF')) {
        try {
            $im = new Imagick();
            // Resolution must be set *before* reading: it decides the raster
            // density. [0] = first page — a tune scan is always a single page.
            $im->setResolution(SCAN_REVIEW_PDF_DPI, SCAN_REVIEW_PDF_DPI);
            $im->readImage($src_path . '[0]');
            $im->setImageBackgroundColor('white');
            $flat = method_exists($im, 'mergeImageLayers')
                ? $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN)
                : $im->flattenImages();
            if ($flat->getImageWidth() > SCAN_REVIEW_PDF_MAX_W) {
                $flat->resizeImage(SCAN_REVIEW_PDF_MAX_W, 0, Imagick::FILTER_LANCZOS, 1);
            }
            $flat->setImageFormat('jpeg');
            $flat->setImageCompressionQuality(88);
            $flat->stripImage();
            $rendered = $flat->writeImage($dest);
            $flat->clear();
            $im->clear();
        } catch (Exception $e) {
            $rendered = false;
        }
    }

    if (!$rendered) {
        // Fallback: WP's own upload-time preview render.
        $meta = wp_get_attachment_metadata($att_id);
        $prev = !empty($meta['sizes']['full']['file']) ? trailingslashit($dir) . $meta['sizes']['full']['file'] : '';
        if (!$prev || !is_file($prev) || !@copy($prev, $dest)) {
            return new WP_Error('render_failed', 'Could not render the PDF to an image on this server.');
        }
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';

    $new_id = wp_insert_attachment([
        'post_mime_type' => 'image/jpeg',
        'post_title'     => get_the_title($att_id),
        'post_content'   => '',
        'post_status'    => 'inherit',
    ], $dest, $post_id);
    if (!$new_id || is_wp_error($new_id)) {
        @unlink($dest);
        return is_wp_error($new_id) ? $new_id : new WP_Error('insert_failed', 'Could not create the image attachment.');
    }

    wp_update_attachment_metadata($new_id, wp_generate_attachment_metadata($new_id, $dest));
    // Same bookkeeping as tune_mark_scan_attachment() (page-add-tune.php) —
    // inlined because that file is a page template, not loaded during AJAX.
    update_post_meta($new_id, TUNE_FILE_SHA1_META, sha1_file($dest));
    set_post_thumbnail($post_id, $new_id);

    return (int) $new_id;
}

/**
 * AJAX: list the croppable scans in a book — every tune that carries a scan
 * attachment. Returns the slim payload the viewer flips through.
 *
 * PDF scans are listed too: they show WP's rendered page preview and get
 * rasterized to a real JPG on the first save (see scan_review_ajax_crop()).
 */
add_action('wp_ajax_scan_review_list', 'scan_review_ajax_list');
function scan_review_ajax_list()
{
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('forbidden', 403);
    }
    check_ajax_referer(SCAN_REVIEW_NONCE);

    $book = isset($_POST['book']) ? (int) $_POST['book'] : 0;
    if ($book <= 0) {
        wp_send_json_error('Please choose a book.');
    }

    $q = new WP_Query([
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'orderby'        => 'title',
        'order'          => 'ASC',
        'tax_query'      => [[
            'taxonomy' => TUNEBOOK_TAX,
            'field'    => 'term_id',
            'terms'    => $book,
        ]],
    ]);

    $items = [];
    foreach ($q->posts as $pid) {
        $att = tune_find_scan_attachment($pid);
        if (!$att) {
            continue;
        }
        // For a PDF this returns WP's rendered page preview (image_downsize()
        // substitutes sizes['full']), which is exactly what we want to show —
        // the crop rectangle travels as fractions, so display resolution and
        // the resolution actually cropped need not match. No preview means the
        // server could not render the PDF at upload time: not croppable.
        $src = wp_get_attachment_image_src($att, 'full');
        if (!$src) {
            continue;
        }
        $items[] = [
            'post_id' => $pid,
            'title'   => get_the_title($pid),
            'att_id'  => (int) $att,
            'url'     => $src[0],
            'w'       => (int) $src[1],
            'h'       => (int) $src[2],
            'is_pdf'  => (get_post_mime_type($att) === 'application/pdf'),
            'has_abc' => (bool) get_field('abc', $pid),
            'link'    => get_permalink($pid),
        ];
    }

    $term = get_term($book, TUNEBOOK_TAX);
    wp_send_json_success([
        'book'  => ($term && !is_wp_error($term)) ? $term->name : '',
        'items' => $items,
    ]);
}

/**
 * AJAX: crop one attachment to the given rectangle (fractions 0..1 of the full
 * image), overwrite the original, regenerate its thumbnails and refresh the
 * dedupe sha1. Trim-only by nature — the selection is always inside the frame.
 *
 * A PDF scan is rasterized to a JPG attachment first (see
 * scan_review_rasterize_pdf()) and the crop then applies to that JPG. The
 * response carries the new att_id so the viewer keeps working on it. Because
 * the conversion is a change in its own right, a whole-frame selection is
 * accepted in that case — "convert, don't trim".
 */
add_action('wp_ajax_scan_review_crop', 'scan_review_ajax_crop');
function scan_review_ajax_crop()
{
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('forbidden', 403);
    }
    check_ajax_referer(SCAN_REVIEW_NONCE);

    $att_id = isset($_POST['att_id']) ? (int) $_POST['att_id'] : 0;
    if ($att_id <= 0 || get_post_type($att_id) !== 'attachment') {
        wp_send_json_error('Unknown attachment.');
    }

    $converted = false;
    if (get_post_mime_type($att_id) === 'application/pdf') {
        $jpg_id = scan_review_rasterize_pdf($att_id);
        if (is_wp_error($jpg_id)) {
            wp_send_json_error($jpg_id->get_error_message());
        }
        $att_id    = $jpg_id;
        $converted = true;
    }

    $fx = isset($_POST['x']) ? (float) $_POST['x'] : 0.0;
    $fy = isset($_POST['y']) ? (float) $_POST['y'] : 0.0;
    $fw = isset($_POST['w']) ? (float) $_POST['w'] : 1.0;
    $fh = isset($_POST['h']) ? (float) $_POST['h'] : 1.0;

    $path = get_attached_file($att_id);
    if (!$path || !file_exists($path)) {
        wp_send_json_error('Image file missing on disk.');
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';

    $editor = wp_get_image_editor($path);
    if (is_wp_error($editor)) {
        wp_send_json_error('Cannot open image: ' . $editor->get_error_message());
    }

    $size = $editor->get_size();
    $W = (int) $size['width'];
    $H = (int) $size['height'];

    $cx = max(0, (int) round($fx * $W));
    $cy = max(0, (int) round($fy * $H));
    $cw = (int) round($fw * $W);
    $ch = (int) round($fh * $H);
    $cw = min($cw, $W - $cx);
    $ch = min($ch, $H - $cy);

    if ($cw < 20 || $ch < 20) {
        wp_send_json_error('Selection too small.');
    }
    $whole = ($cx === 0 && $cy === 0 && $cw === $W && $ch === $H);
    if ($whole && !$converted) {
        wp_send_json_error('Nothing to trim — selection is the whole image.');
    }

    if (!$whole) {
        // Drop the old intermediate sizes first: a crop changes dimensions, so
        // the regenerated thumbnails get new WxH filenames and the old ones
        // would be orphaned otherwise.
        $old = wp_get_attachment_metadata($att_id);
        if (!empty($old['sizes']) && is_array($old['sizes'])) {
            $dir = dirname($path);
            foreach ($old['sizes'] as $s) {
                if (!empty($s['file'])) {
                    $f = $dir . '/' . $s['file'];
                    if (is_file($f)) {
                        @unlink($f);
                    }
                }
            }
        }

        $editor->crop($cx, $cy, $cw, $ch);
        $saved = $editor->save($path);
        if (is_wp_error($saved)) {
            wp_send_json_error('Save failed: ' . $saved->get_error_message());
        }
        $newpath = isset($saved['path']) ? $saved['path'] : $path;

        update_attached_file($att_id, $newpath);
        wp_update_attachment_metadata($att_id, wp_generate_attachment_metadata($att_id, $newpath));
        update_post_meta($att_id, TUNE_FILE_SHA1_META, sha1_file($newpath));
    }

    $src = wp_get_attachment_image_src($att_id, 'full');
    wp_send_json_success([
        'att_id'    => (int) $att_id,
        'converted' => $converted,
        'url'       => ($src ? $src[0] : wp_get_attachment_url($att_id)) . '?v=' . time(),
        'w'         => $src ? (int) $src[1] : $cw,
        'h'         => $src ? (int) $src[2] : $ch,
    ]);
}

/**
 * AJAX: attach ABC notation to an existing (image) tune, turning it into a
 * hybrid abc+scan tune — the scan stays as the featured image, the ABC renders
 * as notation. Mirrors handleAbcTuneUpload()'s single write (page-add-tune.php).
 */
add_action('wp_ajax_scan_review_attach_abc', 'scan_review_ajax_attach_abc');
function scan_review_ajax_attach_abc()
{
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('forbidden', 403);
    }
    check_ajax_referer(SCAN_REVIEW_NONCE);

    $pid = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
    $abc = isset($_POST['abc']) ? sanitize_textarea_field(wp_unslash($_POST['abc'])) : '';

    if ($pid <= 0 || get_post_type($pid) !== 'post') {
        wp_send_json_error('Unknown tune.');
    }
    if (trim($abc) === '') {
        wp_send_json_error('No ABC to attach.');
    }

    update_field('abc', $abc, $pid);

    wp_send_json_success(['has_abc' => true]);
}
