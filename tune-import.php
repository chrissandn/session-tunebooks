<?php

/**
 * Tune Sync — step 3: import (ZIP upload, upsert over tune_uid, batching).
 *
 * The mirror image of tune-export.php: read a `tunes-export` ZIP and merge its
 * records into this site additively. Keyed by the stable `tune_uid` (step 1), so
 * a re-import upserts instead of duplicating. Never destructive — tunes missing
 * from the import are left untouched in every mode (concept §3).
 *
 * Flow (browser-based, no SSH/WP-CLI on live — concept §5):
 *   1. Upload  (admin-post `tune_import_upload`): validate the ZIP, stash it
 *      under uploads/tune-import/<token>.zip, read the manifest for a count,
 *      remember the job in a transient, redirect back with the token.
 *   2. Batch   (ajax `tune_import_batch`): re-open the ZIP, slice the records by
 *      offset, upsert ~20 per request, report progress. JS loops to the end.
 *   3. Cleanup: delete the ZIP + transient once the loop finishes.
 *
 * Modes (concept §3):
 *   sync (default)  update an existing tune only when record.updated is newer
 *                   ("newer wins"); idempotent on re-run.
 *   add             never touch existing tunes; only insert new ones.
 *
 * See tune-import-export-concept.md (§2, §3, §8).
 */

const TUNE_IMPORT_BATCH      = 20;
const TUNE_IMPORT_DIR        = 'tune-import';      // sub-folder of wp uploads
const TUNE_IMPORT_TRANSIENT  = 'tune_import_job_'; // + token
const TUNE_FILE_SHA1_META    = '_tune_file_sha1';  // dedupe marker on attachments

/* ------------------------------------------------------------------ *
 * Upsert core
 * ------------------------------------------------------------------ */

/**
 * Locate the local post a record maps to.
 *
 *   1. match on postmeta tune_uid == record.uid
 *   2. fallback (legacy rows without uid): match on session_id, then "adopt"
 *      by writing the record's uid onto the found post (concept §3.2)
 *
 * @return array{id:int, adopted:bool}  id 0 means no match -> insert
 */
function tune_import_find_post($record)
{
    $uid = isset($record['uid']) ? (string) $record['uid'] : '';
    if ($uid !== '') {
        $hit = get_posts([
            'post_type'   => 'post',
            'post_status' => 'any',
            'numberposts' => 1,
            'fields'      => 'ids',
            'meta_key'    => TUNE_UID_META,
            'meta_value'  => $uid,
        ]);
        if ($hit) {
            return ['id' => (int) $hit[0], 'adopted' => false];
        }
    }

    $sid = isset($record['session_id']) ? $record['session_id'] : null;
    if ($sid !== null && $sid !== '') {
        $hit = get_posts([
            'post_type'   => 'post',
            'post_status' => 'any',
            'numberposts' => 1,
            'fields'      => 'ids',
            'meta_key'    => 'session_id',
            'meta_value'  => (string) $sid,
        ]);
        if ($hit) {
            $pid = (int) $hit[0];
            if ($uid !== '') {
                update_post_meta($pid, TUNE_UID_META, $uid);
            }
            return ['id' => $pid, 'adopted' => true];
        }
    }

    return ['id' => 0, 'adopted' => false];
}

/**
 * Write a record's content fields onto a post. Mirrors the storage the rest of
 * the theme expects: json/abc/session_id via ACF (update_field), setting as
 * plain postmeta (same as page-add-tune.php).
 */
function tune_import_write_fields($post_id, $record)
{
    // Snapshot BEFORE any writes below, for the setting-guard further down.
    $had_json_before = (bool) get_field('json', $post_id);

    if (array_key_exists('json', $record) && $record['json'] !== null) {
        // Export de-slashed the blob into a real object; re-encode + re-slash to
        // store it the way thesession import does (wp_slash of the json string).
        $json_str = wp_json_encode($record['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        update_field('json', wp_slash($json_str), $post_id);
    }

    if (!empty($record['abc'])) {
        update_field('abc', $record['abc'], $post_id);
    }

    if (isset($record['session_id']) && $record['session_id'] !== null && $record['session_id'] !== '') {
        update_field('session_id', $record['session_id'], $post_id);
    }

    if (isset($record['setting']) && $record['setting'] !== null) {
        // Guard (2026-07-22 KTN incident): `setting` is an index into
        // json.settings[] for session-sourced tunes. A record that carries
        // setting+session_id but no fresh json (e.g. a bulk/hybrid merge that
        // only re-matched via session_id) can silently point past the end of an
        // already-stored, now-stale json blob if thesession.org gained settings
        // since that blob was cached -- breaking the tune's notation display
        // without any error at import time. Only write setting when either this
        // record also brings a fresh json (both update together, staying in
        // sync), or the post had no json at all before this call (setting is
        // then just a plain display number, not an index into anything).
        $has_fresh_json = array_key_exists('json', $record) && $record['json'] !== null;
        if ($has_fresh_json || !$had_json_before) {
            update_post_meta($post_id, 'setting', (int) $record['setting']);
        }
    }

    // image-Tune-Typ (step 4a): type/key can't be derived from json/abc like the
    // other sources, so they travel as explicit meta (concept §2, §9).
    if (!empty($record['type'])) {
        update_post_meta($post_id, '_tune_type', sanitize_text_field((string) $record['type']));
    }
    if (!empty($record['key'])) {
        update_post_meta($post_id, '_tune_key', sanitize_text_field((string) $record['key']));
    }
}

/**
 * Merge a record's tags and books onto a post — additive, never removes
 * existing memberships (concept §3.4, §8). New books are created by name.
 */
function tune_import_merge_terms($post_id, $record)
{
    if (!empty($record['tags']) && is_array($record['tags'])) {
        $tags = array_values(array_filter(array_map('strval', $record['tags'])));
        if ($tags) {
            wp_set_post_tags($post_id, $tags, true /* append */);
        }
    }

    if (!empty($record['books']) && is_array($record['books'])) {
        $term_ids = [];
        foreach ($record['books'] as $name) {
            $tid = tunebook_get_or_create($name);
            if ($tid) {
                $term_ids[] = $tid;
            }
        }
        if ($term_ids) {
            tunebook_assign($post_id, $term_ids);
        }
    }
}

/**
 * sha1s of the image/pdf attachments already on a post (featured + children),
 * so we never import the same binary twice.
 */
function tune_import_existing_sha1s($post_id)
{
    $att_ids = [];

    $thumb = get_post_thumbnail_id($post_id);
    if ($thumb) {
        $att_ids[] = (int) $thumb;
    }
    $children = get_children([
        'post_parent'    => $post_id,
        'post_type'      => 'attachment',
        'numberposts'    => -1,
        'fields'         => 'ids',
    ]);
    foreach ($children as $cid) {
        $att_ids[] = (int) $cid;
    }

    $sha1s = [];
    foreach (array_unique($att_ids) as $aid) {
        $marker = get_post_meta($aid, TUNE_FILE_SHA1_META, true);
        if ($marker) {
            $sha1s[] = $marker;
            continue;
        }
        $path = get_attached_file($aid);
        if ($path && file_exists($path)) {
            $sha1s[] = sha1_file($path);
        }
    }
    return $sha1s;
}

/**
 * Import a record's sidecar files: each `files[]` entry whose sha1 is not yet on
 * the post is pulled from the ZIP (files/<sha1>.<ext>), saved to the media
 * library and attached. For image tunes without a featured image, the first jpg
 * becomes the thumbnail. Returns the number of attachments added.
 */
function tune_import_files($post_id, $record, ZipArchive $zip)
{
    if (empty($record['files']) || !is_array($record['files'])) {
        return 0;
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';

    $existing = tune_import_existing_sha1s($post_id);
    $added    = 0;

    foreach ($record['files'] as $f) {
        $sha1 = isset($f['sha1']) ? (string) $f['sha1'] : '';
        $kind = (isset($f['kind']) && $f['kind'] === 'pdf') ? 'pdf' : 'jpg';
        if ($sha1 === '' || in_array($sha1, $existing, true)) {
            continue;
        }

        $bin = $zip->getFromName('files/' . $sha1 . '.' . $kind);
        if ($bin === false) {
            continue;
        }

        $filename = !empty($f['filename']) ? sanitize_file_name($f['filename']) : ($sha1 . '.' . $kind);
        $upload   = wp_upload_bits($filename, null, $bin);
        if (!empty($upload['error']) || empty($upload['file'])) {
            continue;
        }

        $filetype = wp_check_filetype($upload['file']);
        $att_id   = wp_insert_attachment([
            'post_mime_type' => $filetype['type'],
            'post_title'     => pathinfo($filename, PATHINFO_FILENAME),
            'post_status'    => 'inherit',
        ], $upload['file'], $post_id);

        if (is_wp_error($att_id) || !$att_id) {
            continue;
        }

        wp_update_attachment_metadata(
            $att_id,
            wp_generate_attachment_metadata($att_id, $upload['file'])
        );
        update_post_meta($att_id, TUNE_FILE_SHA1_META, $sha1);

        if ($kind === 'jpg' && !has_post_thumbnail($post_id)) {
            set_post_thumbnail($post_id, $att_id);
        }

        $existing[] = $sha1;
        $added++;
    }

    return $added;
}

/**
 * Upsert one record. Returns one of: inserted | updated | skipped | error,
 * plus whether a legacy row was adopted and how many files were added.
 *
 * @return array{action:string, adopted:bool, files:int, post_id:int}
 */
function tune_import_record($record, $mode, ZipArchive $zip)
{
    $title = isset($record['title']) ? wp_strip_all_tags((string) $record['title']) : '';
    if ($title === '') {
        $title = '(untitled tune)';
    }

    $match   = tune_import_find_post($record);
    $post_id = $match['id'];

    // --- INSERT ---------------------------------------------------------
    if (!$post_id) {
        $post_id = wp_insert_post([
            'post_title'  => $title,
            'post_status' => 'publish',
            'post_type'   => 'post',
        ], true);

        if (is_wp_error($post_id) || !$post_id) {
            return ['action' => 'error', 'adopted' => false, 'files' => 0, 'post_id' => 0];
        }

        tune_import_write_fields($post_id, $record);
        if (!empty($record['uid'])) {
            update_post_meta($post_id, TUNE_UID_META, (string) $record['uid']);
        } else {
            tune_ensure_uid($post_id);
        }
        tune_import_merge_terms($post_id, $record);
        $files = tune_import_files($post_id, $record, $zip);

        return ['action' => 'inserted', 'adopted' => false, 'files' => $files, 'post_id' => (int) $post_id];
    }

    // --- UPDATE ---------------------------------------------------------
    if ($mode === 'add') {
        // Extra-safe mode: never touch an existing tune.
        return ['action' => 'skipped', 'adopted' => $match['adopted'], 'files' => 0, 'post_id' => (int) $post_id];
    }

    // sync: "newer wins" — only overwrite when the record is newer than the
    // local copy. Equal/older is a no-op, which keeps re-imports idempotent.
    $local_modified = get_post_field('post_modified_gmt', $post_id);
    $rec_updated    = isset($record['updated']) ? (string) $record['updated'] : '';
    $rec_ts         = $rec_updated !== '' ? strtotime($rec_updated . ' UTC') : 0;
    $loc_ts         = $local_modified ? strtotime($local_modified . ' UTC') : 0;

    if ($rec_ts <= $loc_ts) {
        // Still merge additive memberships (cheap, never destructive) so tags /
        // books propagate even when the body is older.
        tune_import_merge_terms($post_id, $record);
        $files = tune_import_files($post_id, $record, $zip);
        return ['action' => 'skipped', 'adopted' => $match['adopted'], 'files' => $files, 'post_id' => (int) $post_id];
    }

    tune_import_write_fields($post_id, $record);
    tune_import_merge_terms($post_id, $record);
    $files = tune_import_files($post_id, $record, $zip);

    // Touch the post so post_modified moves past record.updated -> idempotent.
    wp_update_post(['ID' => $post_id, 'post_title' => $title]);

    return ['action' => 'updated', 'adopted' => $match['adopted'], 'files' => $files, 'post_id' => (int) $post_id];
}

/* ------------------------------------------------------------------ *
 * Job storage (token -> stashed ZIP + manifest)
 * ------------------------------------------------------------------ */

/**
 * Absolute path of the uploads sub-folder that holds in-flight import ZIPs.
 */
function tune_import_storage_dir()
{
    $up  = wp_upload_dir();
    $dir = trailingslashit($up['basedir']) . TUNE_IMPORT_DIR;
    if (!file_exists($dir)) {
        wp_mkdir_p($dir);
    }
    return $dir;
}

/**
 * Open the stashed ZIP for a job and decode its manifest.
 *
 * @return array{zip:ZipArchive, tunes:array, path:string}|WP_Error
 */
function tune_import_open_job($token)
{
    $job = get_transient(TUNE_IMPORT_TRANSIENT . $token);
    if (!$job || empty($job['path']) || !file_exists($job['path'])) {
        return new WP_Error('no_job', 'Import job not found or expired.');
    }

    $zip = new ZipArchive();
    if ($zip->open($job['path']) !== true) {
        return new WP_Error('bad_zip', 'Could not open the uploaded archive.');
    }

    $json = $zip->getFromName('tunes.json');
    if ($json === false) {
        $zip->close();
        return new WP_Error('no_manifest', 'tunes.json is missing from the archive.');
    }

    $manifest = json_decode($json, true);
    if (!is_array($manifest) || ($manifest['format'] ?? '') !== TUNE_EXPORT_FORMAT) {
        $zip->close();
        return new WP_Error('bad_manifest', 'This is not a tunes-export archive.');
    }

    return [
        'zip'   => $zip,
        'tunes' => isset($manifest['tunes']) && is_array($manifest['tunes']) ? $manifest['tunes'] : [],
        'sets'  => isset($manifest['sets']) && is_array($manifest['sets']) ? $manifest['sets'] : [],
        'path'  => $job['path'],
    ];
}

/**
 * Drop a finished/abandoned job: delete the ZIP and the transient.
 */
function tune_import_cleanup($token)
{
    $job = get_transient(TUNE_IMPORT_TRANSIENT . $token);
    if ($job && !empty($job['path']) && file_exists($job['path'])) {
        @unlink($job['path']);
    }
    delete_transient(TUNE_IMPORT_TRANSIENT . $token);
}

/* ------------------------------------------------------------------ *
 * Upload handler (step 1)
 * ------------------------------------------------------------------ */

add_action('admin_post_tune_import_upload', 'tune_import_handle_upload');
function tune_import_handle_upload()
{
    if (!current_user_can(tune_sync_cap())) {
        wp_die('forbidden', 403);
    }
    check_admin_referer('tune_import_upload');

    $back = admin_url('tools.php?page=tune-sync');

    if (!class_exists('ZipArchive')) {
        wp_die('PHP ZipArchive extension is not available on this server.');
    }
    // Source: an uploaded file, or a URL the server downloads itself. The URL path
    // exists for archives above the host's upload limit — a full export with scans
    // is ~50 MB, typical upload_max_filesize is 2–64 MB (setup.php offers it for
    // the starter collection).
    $url         = isset($_POST['tune_import_url']) ? esc_url_raw(trim(wp_unslash($_POST['tune_import_url'])), ['http', 'https']) : '';
    $has_upload  = !empty($_FILES['tune_import_zip']['tmp_name']) && is_uploaded_file($_FILES['tune_import_zip']['tmp_name']);
    if (!$has_upload && $url === '') {
        wp_safe_redirect(add_query_arg('tune_import_error', rawurlencode('No file uploaded and no URL given.'), $back));
        exit;
    }

    $mode = (isset($_POST['mode']) && $_POST['mode'] === 'add') ? 'add' : 'sync';

    // Optional: assign every tune/set in this import to book(s), on top of
    // whatever books[] the manifest itself carries (concept: import-scoped,
    // not the site-wide Tunebooks backfill in tune-books.php).
    $book_ids = tunebook_resolve_selection(
        isset($_POST['books']) ? (array) $_POST['books'] : [],
        isset($_POST['new_books']) ? sanitize_text_field(wp_unslash($_POST['new_books'])) : ''
    );

    // Move the upload into our storage folder under a random token.
    $token = wp_generate_password(20, false);
    $dest  = trailingslashit(tune_import_storage_dir()) . $token . '.zip';

    if ($has_upload) {
        if (!move_uploaded_file($_FILES['tune_import_zip']['tmp_name'], $dest)) {
            wp_safe_redirect(add_query_arg('tune_import_error', rawurlencode('Could not store the upload.'), $back));
            exit;
        }
    } else {
        // wp_safe_remote_get underneath: refuses local/private addresses.
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $tmp = download_url($url, 900);
        if (is_wp_error($tmp)) {
            wp_safe_redirect(add_query_arg('tune_import_error', rawurlencode('Download failed: ' . $tmp->get_error_message()), $back));
            exit;
        }
        if (!@rename($tmp, $dest)) {
            @unlink($tmp);
            wp_safe_redirect(add_query_arg('tune_import_error', rawurlencode('Could not store the download.'), $back));
            exit;
        }
    }

    // Validate it really is a tunes-export and count the records.
    $zip = new ZipArchive();
    if ($zip->open($dest) !== true) {
        @unlink($dest);
        wp_safe_redirect(add_query_arg('tune_import_error', rawurlencode('Uploaded file is not a valid ZIP.'), $back));
        exit;
    }
    $json     = $zip->getFromName('tunes.json');
    $manifest = $json !== false ? json_decode($json, true) : null;
    $zip->close();

    if (!is_array($manifest) || ($manifest['format'] ?? '') !== TUNE_EXPORT_FORMAT) {
        @unlink($dest);
        wp_safe_redirect(add_query_arg('tune_import_error', rawurlencode('Not a tunes-export archive (tunes.json missing or wrong format).'), $back));
        exit;
    }

    $tunes_total = isset($manifest['tunes']) && is_array($manifest['tunes']) ? count($manifest['tunes']) : 0;
    $sets_total  = isset($manifest['sets']) && is_array($manifest['sets']) ? count($manifest['sets']) : 0;
    $total       = $tunes_total + $sets_total;

    set_transient(TUNE_IMPORT_TRANSIENT . $token, [
        'path'  => $dest,
        'mode'  => $mode,
        'books' => $book_ids,
    ], HOUR_IN_SECONDS);

    wp_safe_redirect(add_query_arg([
        'tune_import_token' => $token,
        'tune_import_total' => $total,
        'tune_import_tunes' => $tunes_total,
        'tune_import_sets'  => $sets_total,
        'tune_import_mode'  => $mode,
        'tune_import_src'   => rawurlencode((string) ($manifest['source_site'] ?? '')),
    ], $back));
    exit;
}

/* ------------------------------------------------------------------ *
 * Batch handler (step 2)
 * ------------------------------------------------------------------ */

add_action('wp_ajax_tune_import_batch', 'tune_import_ajax_batch');
function tune_import_ajax_batch()
{
    if (!current_user_can(tune_sync_cap())) {
        wp_send_json_error('forbidden', 403);
    }
    check_ajax_referer('tune_import_batch');

    $token  = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
    $offset = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;

    $job = tune_import_open_job($token);
    if (is_wp_error($job)) {
        wp_send_json_error($job->get_error_message());
    }

    $stored   = get_transient(TUNE_IMPORT_TRANSIENT . $token);
    $mode     = ($stored && ($stored['mode'] ?? '') === 'add') ? 'add' : 'sync';
    $book_ids = ($stored && !empty($stored['books'])) ? (array) $stored['books'] : [];

    $zip   = $job['zip'];
    $tunes = $job['tunes'];
    $sets  = $job['sets'];

    // Combined offset: tunes occupy [0, tunes_total), sets follow. Sets resolve
    // their members by tune_uid, so they MUST run after every tune is imported —
    // which the offset ordering guarantees (a straddling batch does tunes first).
    $tunes_total = count($tunes);
    $sets_total  = function_exists('set_import_record') ? count($sets) : 0;
    $total       = $tunes_total + $sets_total;

    $counts = [
        'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'adopted' => 0, 'files' => 0, 'error' => 0,
        'sets_inserted' => 0, 'sets_updated' => 0, 'sets_skipped' => 0, 'sets_error' => 0, 'sets_missing' => 0,
    ];
    $log = [];

    // --- tune portion of this batch ---
    $tunes_handled = 0;
    if ($offset < $tunes_total) {
        $slice = array_slice($tunes, $offset, TUNE_IMPORT_BATCH);
        foreach ($slice as $record) {
            $res = tune_import_record($record, $mode, $zip);
            $counts[$res['action']] = ($counts[$res['action']] ?? 0) + 1;
            if ($res['adopted']) {
                $counts['adopted']++;
            }
            $counts['files'] += $res['files'];

            if ($book_ids && !empty($res['post_id'])) {
                tunebook_assign($res['post_id'], $book_ids);
            }

            $title = isset($record['title']) ? wp_strip_all_tags((string) $record['title']) : '(untitled)';
            $log[] = $res['action'] . ': ' . $title;
        }
        $tunes_handled = count($slice);
    }

    // --- set portion of this batch (fills remaining batch capacity) ---
    $sets_handled = 0;
    $cap_left     = TUNE_IMPORT_BATCH - $tunes_handled;
    if ($cap_left > 0 && $sets_total > 0) {
        $set_from = max(0, $offset - $tunes_total);
        if ($set_from < $sets_total) {
            $slice = array_slice($sets, $set_from, $cap_left);
            foreach ($slice as $record) {
                $res = set_import_record($record, $mode);
                $key = 'sets_' . $res['action'];
                $counts[$key] = ($counts[$key] ?? 0) + 1;
                $counts['sets_missing'] += $res['missing'] ?? 0;

                if ($book_ids && !empty($res['post_id'])) {
                    tunebook_assign($res['post_id'], $book_ids);
                }

                $title = isset($record['title']) ? wp_strip_all_tags((string) $record['title']) : '(untitled set)';
                $log[] = 'set ' . $res['action'] . ': ' . $title;
            }
            $sets_handled = count($slice);
        }
    }

    $zip->close();

    $processed  = $tunes_handled + $sets_handled;
    $new_offset = $offset + $processed;
    $done       = ($new_offset >= $total) || ($processed === 0);

    if ($done) {
        tune_import_cleanup($token);
    }

    wp_send_json_success([
        'offset'    => $new_offset,
        'total'     => $total,
        'processed' => $processed,
        'counts'    => $counts,
        'log'       => $log,
        'done'      => $done,
    ]);
}

/* ------------------------------------------------------------------ *
 * UI (rendered into the Tune Sync tools page via tune_sync_sections)
 * ------------------------------------------------------------------ */

add_action('tune_sync_sections', 'tune_import_render_section');
function tune_import_render_section()
{
    $error = isset($_GET['tune_import_error']) ? sanitize_text_field(wp_unslash($_GET['tune_import_error'])) : '';
    $token = isset($_GET['tune_import_token']) ? sanitize_text_field(wp_unslash($_GET['tune_import_token'])) : '';
    $total = isset($_GET['tune_import_total']) ? (int) $_GET['tune_import_total'] : 0;
    $tunes = isset($_GET['tune_import_tunes']) ? (int) $_GET['tune_import_tunes'] : $total;
    $sets  = isset($_GET['tune_import_sets']) ? (int) $_GET['tune_import_sets'] : 0;
    $mode  = isset($_GET['tune_import_mode']) && $_GET['tune_import_mode'] === 'add' ? 'add' : 'sync';
    $src   = isset($_GET['tune_import_src']) ? sanitize_text_field(wp_unslash($_GET['tune_import_src'])) : '';

    $nonce    = wp_create_nonce('tune_import_batch');
    $ajax_url = admin_url('admin-ajax.php');
?>
    <hr style="margin:2em 0">
    <h2>Import</h2>
    <p>Upload a <code>tunes-export</code> <code>.zip</code> to merge its tunes into
        this site. Additive and keyed by <code>tune_uid</code> — existing tunes are
        upserted, never duplicated, and tunes missing from the file are left alone.</p>

    <?php if ($error) : ?>
        <div class="notice notice-error inline"><p><?php echo esc_html($error); ?></p></div>
    <?php endif; ?>

    <?php if (!$token) : ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
            <input type="hidden" name="action" value="tune_import_upload">
            <?php wp_nonce_field('tune_import_upload'); ?>
            <?php $prefillUrl = isset($_GET['tune_import_url']) ? esc_url(wp_unslash($_GET['tune_import_url'])) : ''; ?>
            <p>
                <input type="file" name="tune_import_zip" accept=".zip">
            </p>
            <p>
                <label for="tune-import-url">… or load it from a URL</label><br>
                <input type="url" name="tune_import_url" id="tune-import-url" value="<?php echo esc_attr($prefillUrl); ?>" placeholder="https://…/tunes-export.zip" style="min-width:420px">
                <br><span class="description">The server downloads the file itself — use this for archives
                    larger than the upload limit (<?php echo esc_html(size_format(wp_max_upload_size())); ?> here).</span>
            </p>
            <fieldset style="margin:.5em 0">
                <label style="margin-right:1.5em">
                    <input type="radio" name="mode" value="sync" checked>
                    <strong>sync</strong> — update when the imported tune is newer ("newer wins")
                </label>
                <br>
                <label>
                    <input type="radio" name="mode" value="add">
                    <strong>add</strong> — only add new tunes, never touch existing ones
                </label>
            </fieldset>
            <?php
            $importBooks = get_terms(['taxonomy' => TUNEBOOK_TAX, 'hide_empty' => false]);
            if (is_wp_error($importBooks)) {
                $importBooks = [];
            }
            ?>
            <fieldset style="margin:.5em 0">
                <label for="tune-import-books"><strong>Add to book(s)</strong> <span style="color:#646970">(optional)</span></label><br>
                <?php if ($importBooks) : ?>
                    <select name="books[]" id="tune-import-books" multiple size="<?php echo (int) min(5, max(2, count($importBooks))); ?>" style="min-width:260px">
                        <?php foreach ($importBooks as $b) : ?>
                            <option value="<?php echo (int) $b->term_id; ?>"><?php echo esc_html($b->name); ?> (<?php echo (int) $b->count; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <br>
                <?php endif; ?>
                <?php if (current_user_can('manage_categories')) : ?>
                    <input type="text" name="new_books" placeholder="New book, or several: Graz-Session, Villach" style="min-width:260px;margin-top:.3em">
                <?php endif; ?>
                <p class="description">Applied to every tune and set inserted or updated by this import, on
                    top of whatever books the file itself already lists. Scoped to this import only —
                    unlike the site-wide backfill below, it never touches tunes outside this file.</p>
            </fieldset>
            <p><button class="button button-primary">Upload &amp; prepare import</button></p>
        </form>
    <?php else : ?>
        <?php
        $importStored    = get_transient(TUNE_IMPORT_TRANSIENT . $token);
        $importBookNames = [];
        if ($importStored && !empty($importStored['books'])) {
            foreach ((array) $importStored['books'] as $bid) {
                $term = get_term((int) $bid, TUNEBOOK_TAX);
                if ($term && !is_wp_error($term)) {
                    $importBookNames[] = $term->name;
                }
            }
        }
        ?>
        <table class="widefat striped" style="max-width:480px;margin-bottom:1em">
            <tbody>
                <tr><td>Records in file</td><td><strong><?php echo esc_html($total); ?></strong>
                    <?php if ($sets) : ?><span style="color:#646970">(tunes <?php echo esc_html($tunes); ?>, sets <?php echo esc_html($sets); ?>)</span><?php endif; ?></td></tr>
                <tr><td>Mode</td><td><strong><?php echo esc_html($mode); ?></strong></td></tr>
                <?php if ($importBookNames) : ?>
                    <tr><td>Add to book(s)</td><td><strong><?php echo esc_html(implode(', ', $importBookNames)); ?></strong></td></tr>
                <?php endif; ?>
                <?php if ($src) : ?><tr><td>Source site</td><td><?php echo esc_html($src); ?></td></tr><?php endif; ?>
            </tbody>
        </table>

        <p>
            <button class="button button-primary" id="tune-import-btn">Run import</button>
            <a class="button" href="<?php echo esc_url(admin_url('tools.php?page=tune-sync')); ?>">Cancel / choose another file</a>
            <span id="tune-import-status" style="margin-left:1em"></span>
        </p>

        <div id="tune-import-progress" style="max-width:600px;margin-top:1em">
            <progress id="tune-import-bar" value="0" max="<?php echo esc_attr($total); ?>" style="width:100%"></progress>
            <pre id="tune-import-log" style="max-height:220px;overflow:auto;background:#f6f7f7;border:1px solid #dcdcde;padding:.5em;margin-top:.5em;display:none"></pre>
        </div>

        <script>
            (function() {
                const btn = document.getElementById('tune-import-btn');
                const status = document.getElementById('tune-import-status');
                const bar = document.getElementById('tune-import-bar');
                const logEl = document.getElementById('tune-import-log');
                const ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;
                const nonce = <?php echo wp_json_encode($nonce); ?>;
                const token = <?php echo wp_json_encode($token); ?>;
                const total = <?php echo (int) $total; ?>;

                const totals = {
                    inserted: 0, updated: 0, skipped: 0, adopted: 0, files: 0, error: 0,
                    sets_inserted: 0, sets_updated: 0, sets_skipped: 0, sets_error: 0, sets_missing: 0
                };

                async function runBatch(offset) {
                    const body = new URLSearchParams({
                        action: 'tune_import_batch',
                        _wpnonce: nonce,
                        token: token,
                        offset: offset
                    });
                    const res = await fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body });
                    const data = await res.json();
                    if (!data.success) throw new Error(data.data || 'error');
                    return data.data;
                }

                function summary() {
                    let s = 'tunes: inserted ' + totals.inserted +
                        ', updated ' + totals.updated +
                        ', skipped ' + totals.skipped +
                        (totals.adopted ? ', adopted ' + totals.adopted : '') +
                        (totals.files ? ', files +' + totals.files : '') +
                        (totals.error ? ', errors ' + totals.error : '');
                    if (totals.sets_inserted || totals.sets_updated || totals.sets_skipped || totals.sets_error) {
                        s += ' | sets: inserted ' + totals.sets_inserted +
                            ', updated ' + totals.sets_updated +
                            ', skipped ' + totals.sets_skipped +
                            (totals.sets_error ? ', errors ' + totals.sets_error : '') +
                            (totals.sets_missing ? ', unresolved members ' + totals.sets_missing : '');
                    }
                    return s;
                }

                btn.addEventListener('click', async function() {
                    btn.disabled = true;
                    logEl.style.display = 'block';
                    logEl.textContent = '';
                    let offset = 0;
                    try {
                        while (true) {
                            const r = await runBatch(offset);
                            for (const k in totals) {
                                if (r.counts && typeof r.counts[k] === 'number') totals[k] += r.counts[k];
                            }
                            offset = r.offset;
                            bar.value = offset;
                            if (r.log && r.log.length) logEl.textContent += r.log.join('\n') + '\n';
                            logEl.scrollTop = logEl.scrollHeight;
                            status.textContent = offset + ' / ' + total + ' — ' + summary();
                            if (r.done) break;
                        }
                        status.textContent = 'Done. ' + summary() + '.';
                    } catch (e) {
                        status.textContent = 'Error: ' + e.message;
                        btn.disabled = false;
                    }
                });
            })();
        </script>
    <?php endif; ?>
<?php
}
