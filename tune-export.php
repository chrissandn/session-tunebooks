<?php

/**
 * Tune Sync — step 2: export.
 *
 * Produces a portable, mergeable snapshot of all tunes as a ZIP:
 *
 *   tunes-export-YYYY-MM-DD.zip
 *   ├── tunes.json      ← manifest (envelope + records)
 *   └── files/          ← attachment binaries, named by sha1 (deduped)
 *
 * Each record carries the stable `tune_uid` (step 1) so a later import can
 * upsert instead of duplicating. The JSON blob is de-slashed into a real
 * object; the importer re-slashes it before saving.
 *
 * Browser-based + streamed from disk (no SSH/WP-CLI on live, see concept §5).
 * See tune-import-export-concept.md (§2, §6).
 */

const TUNE_EXPORT_FORMAT  = 'tunes-export';
const TUNE_EXPORT_VERSION = 1;

/**
 * Decide a tune's content type for the record's `source` field.
 *
 * Mirrors class-tune.php: a usable json blob means a thesession tune
 * (`session`) even when the `session_id` meta is missing — 6 legacy tunes are
 * exactly that case. Otherwise raw ABC means `abc`; `image` (step 4) only
 * triggers on an image/pdf attachment with no json/abc.
 */
function tune_export_source($has_json, $has_abc, $has_files)
{
    if ($has_json) {
        return 'session';
    }
    if ($has_abc) {
        return 'abc';
    }
    if ($has_files) {
        return 'image';
    }
    return 'abc';
}

/**
 * Collect a tune's exportable attachments (image/pdf): featured image plus any
 * child attachments. Returns a list of
 *   ['att_id', 'path', 'sha1', 'ext', 'kind', 'filename'].
 * Empty for the current data set — the image tune type lands in step 4 — but
 * wiring it now keeps the export format stable across steps.
 */
function tune_collect_attachments($post_id)
{
    $att_ids = [];

    $thumb = get_post_thumbnail_id($post_id);
    if ($thumb) {
        $att_ids[] = (int) $thumb;
    }

    $children = get_children([
        'post_parent' => $post_id,
        'post_type'   => 'attachment',
        'post_mime_type' => ['image/jpeg', 'image/jpg', 'application/pdf'],
        'numberposts' => -1,
        'fields'      => 'ids',
    ]);
    foreach ($children as $cid) {
        $att_ids[] = (int) $cid;
    }

    $out = [];
    foreach (array_unique($att_ids) as $att_id) {
        $path = get_attached_file($att_id);
        if (!$path || !file_exists($path)) {
            continue;
        }
        $sha1 = sha1_file($path);
        if (!$sha1) {
            continue;
        }
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $kind = ($ext === 'pdf') ? 'pdf' : 'jpg';
        $out[] = [
            'att_id'   => $att_id,
            'path'     => $path,
            'sha1'     => $sha1,
            'ext'      => ($kind === 'pdf') ? 'pdf' : 'jpg',
            'kind'     => $kind,
            'filename' => basename($path),
        ];
    }
    return $out;
}

/**
 * Build one manifest record for a tune post.
 *
 * @param int   $post_id
 * @param array $files_acc  Accumulator (by sha1) for the ZIP's files/ folder.
 */
function tune_build_record($post_id, array &$files_acc)
{
    $uid = tune_ensure_uid($post_id); // idempotent; backfill already ran on live
    if (!$uid) {
        return null; // not a tune
    }

    $attachments = tune_collect_attachments($post_id);
    foreach ($attachments as $a) {
        // Dedupe identical binaries across tunes — same sha1 ships once.
        $files_acc[$a['sha1']] = ['path' => $a['path'], 'ext' => $a['ext']];
    }

    $session_id = get_post_meta($post_id, 'session_id', true);
    $json_str   = get_post_meta($post_id, 'json', true);
    $abc        = get_post_meta($post_id, 'abc', true);
    $setting    = get_post_meta($post_id, 'setting', true);
    $tune_type  = get_post_meta($post_id, '_tune_type', true);
    $tune_key   = get_post_meta($post_id, '_tune_key', true);

    $json     = ($json_str !== '' && $json_str !== null) ? json_decode($json_str) : null;
    $has_json = ($json !== null);
    $has_sid  = ($session_id !== '' && $session_id !== null && $session_id !== false);
    $has_abc  = (bool) $abc;
    $source   = tune_export_source($has_json, $has_abc, !empty($attachments));

    $tags = wp_get_post_tags($post_id, ['fields' => 'names']);
    if (is_wp_error($tags)) {
        $tags = [];
    }

    // Tunebooks (step 2.5): exported as names, merged additively on import.
    $books = wp_get_object_terms($post_id, TUNEBOOK_TAX, ['fields' => 'names']);
    if (is_wp_error($books)) {
        $books = [];
    }

    // Faithful DB snapshot: every present field is exported (and only those).
    // `source` is a hint; matching on import is by `uid`, never by source.
    return [
        'uid'        => $uid,
        'title'      => get_the_title($post_id),
        'source'     => $source,
        'type'       => ($tune_type !== '' && $tune_type !== false) ? $tune_type : null,
        'key'        => ($tune_key !== '' && $tune_key !== false) ? $tune_key : null,
        'session_id' => $has_sid ? (int) $session_id : null,
        'setting'    => $has_json ? (int) $setting : null,
        // De-slash the blob into a real object; importer re-slashes on save.
        'json'       => $json,
        'abc'        => $has_abc ? $abc : null,
        'tags'       => array_values($tags),
        'books'      => array_values($books),
        'files'      => array_map(function ($a) {
            return ['filename' => $a['filename'], 'sha1' => $a['sha1'], 'kind' => $a['kind']];
        }, $attachments),
        'updated'    => get_post_field('post_modified_gmt', $post_id),
    ];
}

/**
 * All exportable tune ids: any post that carries a non-empty tune_uid.
 * (Non-tunes never get a uid; see backfill in tune-sync.php.)
 *
 * @param int $book_term_id  Optional tunebook term to scope to (0 = all tunes).
 */
function tune_export_ids($book_term_id = 0)
{
    $args = [
        'post_type'      => 'post',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'meta_query'     => [
            ['key' => TUNE_UID_META, 'value' => '', 'compare' => '!='],
        ],
    ];
    if ($book_term_id) {
        $args['tax_query'] = [[
            'taxonomy' => TUNEBOOK_TAX,
            'field'    => 'term_id',
            'terms'    => [(int) $book_term_id],
        ]];
    }
    $q = new WP_Query($args);
    return $q->posts;
}

/**
 * Collect the export payload (tune + set records + attachment accumulator) for a
 * scope. Pure/testable: no request state, no output.
 *
 *   - book 0  → everything.
 *   - book >0 → that tunebook only. Self-contained: the member tunes of the
 *     book's sets are pulled in even when not tagged into the book, so every
 *     exported set resolves on import. Each record's books[] is then narrowed to
 *     this book (isolation) — pulled-in members get an empty books[].
 *
 * @return array{book_name:?string, tunes:array, sets:array, files:array}
 */
function tune_export_collect($book)
{
    $book = (int) $book;
    if ($book && !term_exists($book, TUNEBOOK_TAX)) {
        $book = 0;
    }
    $book_name = $book ? get_term($book, TUNEBOOK_TAX)->name : null;

    $tune_ids = tune_export_ids($book);
    if ($book) {
        $member_ids = apply_filters('tune_export_book_member_tunes', [], $book);
        $tune_ids   = array_values(array_unique(array_merge($tune_ids, $member_ids)));
    }

    // Build tunes first: this mints uids on any legacy member so the set records
    // built next are guaranteed to find every member's tune_uid.
    $records   = [];
    $files_acc = [];
    foreach ($tune_ids as $pid) {
        $rec = tune_build_record($pid, $files_acc);
        if ($rec) {
            $records[] = $rec;
        }
    }

    // Sets (phase 2) via tune-sets.php, scoped to the same book.
    $set_records = apply_filters('tune_export_sets', [], $book);

    // Book isolation (single-book export only): narrow every record's books[] to
    // this book, so importing it elsewhere doesn't leak/recreate a set's *other*
    // book memberships. Pulled-in member tunes (not in the book) → empty books[].
    if ($book) {
        $keep = [$book_name];
        foreach ($records as &$r) {
            $r['books'] = array_values(array_intersect($r['books'], $keep));
        }
        unset($r);
        foreach ($set_records as &$s) {
            $s['books'] = array_values(array_intersect($s['books'], $keep));
        }
        unset($s);
    }

    return [
        'book_name' => $book_name,
        'tunes'     => $records,
        'sets'      => $set_records,
        'files'     => $files_acc,
    ];
}

/**
 * admin-post handler: build the ZIP and stream it as a download.
 */
add_action('admin_post_tune_export', 'tune_export_download');
function tune_export_download()
{
    if (!current_user_can(tune_sync_cap())) {
        wp_die('forbidden', 403);
    }
    check_admin_referer('tune_export');

    if (!class_exists('ZipArchive')) {
        wp_die('PHP ZipArchive extension is not available on this server.');
    }

    // Optional scope: a single tunebook (0 = everything).
    $data = tune_export_collect(isset($_POST['book']) ? (int) $_POST['book'] : 0);

    $manifest = [
        'format'      => TUNE_EXPORT_FORMAT,
        'version'     => TUNE_EXPORT_VERSION,
        'exported_at' => gmdate('c'),
        'source_site' => wp_parse_url(home_url(), PHP_URL_HOST),
        'book'        => $data['book_name'], // scope hint; null = full export
        'tunes'       => $data['tunes'],
        'sets'        => $data['sets'],
    ];

    $json = wp_json_encode(
        $manifest,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    $tmp = wp_tempnam('tunes-export');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        wp_die('Could not create the export archive.');
    }
    $zip->addFromString('tunes.json', $json);
    foreach ($data['files'] as $sha1 => $f) {
        $zip->addFile($f['path'], 'files/' . $sha1 . '.' . $f['ext']);
    }
    $zip->close();

    $slug     = $data['book_name'] ? '-' . sanitize_title($data['book_name']) : '';
    $filename = 'tunes-export' . $slug . '-' . gmdate('Y-m-d') . '.zip';

    nocache_headers();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));

    readfile($tmp);
    @unlink($tmp);
    exit;
}

/**
 * Render the export section on the Tune Sync tools page.
 */
add_action('tune_sync_sections', 'tune_export_render_section');
function tune_export_render_section()
{
    $count = tune_count_with_uid();
    $sets  = function_exists('set_count_exportable') ? set_count_exportable() : 0;
    $books = get_terms(['taxonomy' => TUNEBOOK_TAX, 'hide_empty' => true]);
    if (is_wp_error($books)) {
        $books = [];
    }
?>
    <hr style="margin:2em 0">
    <h2>Export</h2>
    <p>Download all <strong><?php echo esc_html($count); ?></strong> tunes<?php
        if ($sets) {
            echo ' and <strong>' . esc_html($sets) . '</strong> sets';
        } ?> — or a single tunebook — as a
        portable <code>.zip</code> (manifest <code>tunes.json</code> + attached
        files). Additive snapshot — keyed by <code>tune_uid</code>/<code>set_uid</code>,
        safe to re-import. Also a versionable content backup for git.</p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="tune_export">
        <?php wp_nonce_field('tune_export'); ?>
        <?php if ($books) : ?>
            <p>
                <label for="tune-export-book">Scope:</label>
                <select name="book" id="tune-export-book">
                    <option value="0">All tunes &amp; sets</option>
                    <?php foreach ($books as $b) : ?>
                        <option value="<?php echo (int) $b->term_id; ?>">
                            <?php echo esc_html($b->name); ?> (<?php echo (int) $b->count; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="description">A single book also carries its sets' member tunes, so it imports cleanly.</span>
            </p>
        <?php endif; ?>
        <button class="button button-primary" <?php disabled($count, 0); ?>>Download export ZIP</button>
    </form>
<?php
}
