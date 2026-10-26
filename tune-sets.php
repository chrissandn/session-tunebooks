<?php

/**
 * Tune Sync — phase 2: set export / import.
 *
 * A "set" (post_type `set`) is an ordered group of up to five tunes, stored in
 * ACF post-object fields tune_1..tune_5 as *local post IDs*. Those IDs are not
 * stable across sites, so sets can't simply be copied — they must travel keyed
 * by their members' stable `tune_uid` (step 1) and be re-resolved on the target.
 *
 * Mirrors the tune pipeline:
 *   - set_uid          stable per-set key  set:<uuid-v4>  (postmeta, like tune_uid)
 *   - export           fills the manifest `sets[]` via the `tune_export_sets`
 *                      filter; members are emitted as `tune_uids`
 *   - import           runs AFTER all tunes (tune-import.php drives the combined
 *                      batch offset), resolves tune_uids -> local IDs, upserts
 *                      the set by set_uid. Additive, newer-wins, never deletes.
 *
 * See tune-import-export-concept.md (§4).
 */

const TUNE_SET_UID_META = 'set_uid';
const TUNE_SET_MAX      = 5;            // tune_1..tune_5 (matches the ACF "Set" group + theme)

/* ------------------------------------------------------------------ *
 * Stable key
 * ------------------------------------------------------------------ */

/**
 * Return a set's uid, generating + storing it on demand (idempotent).
 */
function set_ensure_uid($set_id)
{
    $existing = get_post_meta($set_id, TUNE_SET_UID_META, true);
    if ($existing !== '' && $existing !== null) {
        return $existing;
    }
    $uid = 'set:' . wp_generate_uuid4();
    update_post_meta($set_id, TUNE_SET_UID_META, $uid);
    return $uid;
}

/**
 * Assign a uid whenever a set is saved in wp-admin. No ACF field is registered
 * for set_uid (unlike tune_uid), so there's no empty-on-submit blanking to guard
 * against — a plain save hook is enough.
 */
add_action('save_post_set', 'set_uid_on_save', 99, 1);
function set_uid_on_save($post_id)
{
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }
    set_ensure_uid($post_id);
}

/* ------------------------------------------------------------------ *
 * Export
 * ------------------------------------------------------------------ */

/**
 * A set's member tunes as a list of tune_uids, in slot order (tune_1..tune_5).
 * Members whose post is gone or has no uid are skipped.
 */
function set_export_member_uids($set_id)
{
    $uids = [];
    for ($i = 1; $i <= TUNE_SET_MAX; $i++) {
        $raw = get_post_meta($set_id, 'tune_' . $i, true);
        if ($raw === '' || $raw === null) {
            continue;
        }
        $uid = get_post_meta((int) $raw, TUNE_UID_META, true);
        if ($uid !== '' && $uid !== null) {
            $uids[] = $uid;
        }
    }
    return $uids;
}

/**
 * Build one manifest record for a set. Returns null for a set with no resolvable
 * members (nothing to export).
 */
function set_build_record($set_id)
{
    $tune_uids = set_export_member_uids($set_id);
    if (!$tune_uids) {
        return null;
    }

    $uid = set_ensure_uid($set_id); // idempotent backfill on export

    $tags = wp_get_post_tags($set_id, ['fields' => 'names']);
    if (is_wp_error($tags)) {
        $tags = [];
    }
    $books = wp_get_object_terms($set_id, TUNEBOOK_TAX, ['fields' => 'names']);
    if (is_wp_error($books)) {
        $books = [];
    }

    return [
        'uid'       => $uid,
        'title'     => get_the_title($set_id),
        'tune_uids' => array_values($tune_uids),
        'tags'      => array_values($tags),
        'books'     => array_values($books),
        'updated'   => get_post_field('post_modified_gmt', $set_id),
    ];
}

/**
 * Set post ids, oldest first, optionally scoped to a tunebook (0 = all).
 */
function set_export_ids($book_term_id = 0)
{
    $args = [
        'post_type'   => 'set',
        'post_status' => 'any',
        'numberposts' => -1,
        'fields'      => 'ids',
        'orderby'     => 'ID',
        'order'       => 'ASC',
    ];
    if ($book_term_id) {
        $args['tax_query'] = [[
            'taxonomy' => TUNEBOOK_TAX,
            'field'    => 'term_id',
            'terms'    => [(int) $book_term_id],
        ]];
    }
    return get_posts($args);
}

/**
 * Exportable set records, optionally scoped to a tunebook. Hooked onto the
 * export manifest via `tune_export_sets`.
 */
function set_export_all($book_term_id = 0)
{
    $out = [];
    foreach (set_export_ids($book_term_id) as $sid) {
        $rec = set_build_record($sid);
        if ($rec) {
            $out[] = $rec;
        }
    }
    return $out;
}
add_filter('tune_export_sets', 'set_export_filter', 10, 2);
function set_export_filter($sets, $book_term_id = 0)
{
    return set_export_all($book_term_id);
}

/**
 * Post ids of the member tunes of every set in a book — so a single-book export
 * can bundle those tunes even when they aren't tagged into the book themselves.
 * Hooked onto `tune_export_book_member_tunes`.
 */
function set_export_book_member_tunes($ids, $book_term_id)
{
    foreach (set_export_ids((int) $book_term_id) as $sid) {
        for ($i = 1; $i <= TUNE_SET_MAX; $i++) {
            $raw = get_post_meta($sid, 'tune_' . $i, true);
            if ($raw !== '' && $raw !== null) {
                $ids[] = (int) $raw;
            }
        }
    }
    return array_values(array_unique($ids));
}
add_filter('tune_export_book_member_tunes', 'set_export_book_member_tunes', 10, 2);

/**
 * Count of exportable sets (those with at least one resolvable member). Read-only
 * — unlike set_export_all() it does NOT mint set_uids, so it's safe to call on a
 * page view (the uid backfill happens on the actual export click).
 */
function set_count_exportable()
{
    $ids = get_posts([
        'post_type'   => 'set',
        'post_status' => 'any',
        'numberposts' => -1,
        'fields'      => 'ids',
    ]);
    $n = 0;
    foreach ($ids as $sid) {
        if (set_export_member_uids($sid)) {
            $n++;
        }
    }
    return $n;
}

/* ------------------------------------------------------------------ *
 * Import (driven by tune-import.php's combined batch — sets come after tunes)
 * ------------------------------------------------------------------ */

/**
 * Find the local set a record maps to, matched on set_uid. 0 = none -> insert.
 */
function set_import_find_post($record)
{
    $uid = isset($record['uid']) ? (string) $record['uid'] : '';
    if ($uid === '') {
        return 0;
    }
    $hit = get_posts([
        'post_type'   => 'set',
        'post_status' => 'any',
        'numberposts' => 1,
        'fields'      => 'ids',
        'meta_key'    => TUNE_SET_UID_META,
        'meta_value'  => $uid,
    ]);
    return $hit ? (int) $hit[0] : 0;
}

/**
 * Resolve a list of tune_uids to local post IDs (order preserved).
 *
 * @return array{ids:int[], missing:int}
 */
function set_resolve_members($tune_uids)
{
    $ids     = [];
    $missing = 0;
    foreach ((array) $tune_uids as $u) {
        $u = (string) $u;
        if ($u === '') {
            continue;
        }
        $hit = get_posts([
            'post_type'   => 'post',
            'post_status' => 'any',
            'numberposts' => 1,
            'fields'      => 'ids',
            'meta_key'    => TUNE_UID_META,
            'meta_value'  => $u,
        ]);
        if ($hit) {
            $ids[] = (int) $hit[0];
        } else {
            $missing++;
        }
    }
    return ['ids' => $ids, 'missing' => $missing];
}

/**
 * Write member post IDs into tune_1..tune_N (via ACF, like page-add-tune.php),
 * clearing any higher slots left over from a previous, longer membership.
 */
function set_write_members($set_id, array $ids)
{
    $ids = array_slice($ids, 0, TUNE_SET_MAX);
    $n   = 0;
    foreach ($ids as $pid) {
        $n++;
        update_field('tune_' . $n, $pid, $set_id);
    }
    for ($i = $n + 1; $i <= TUNE_SET_MAX; $i++) {
        delete_post_meta($set_id, 'tune_' . $i);
        delete_post_meta($set_id, '_tune_' . $i);
    }
}

/**
 * Upsert one set record. Returns one of inserted | updated | skipped | error,
 * plus how many members could not be resolved.
 *
 * Tags + books are merged additively (reusing tune_import_merge_terms, which is
 * post-type agnostic). "newer wins" on update, like tunes; `add` mode never
 * touches an existing set.
 *
 * @return array{action:string, missing:int, post_id:int}
 */
function set_import_record($record, $mode)
{
    $title = isset($record['title']) ? wp_strip_all_tags((string) $record['title']) : '';
    if ($title === '') {
        $title = '(untitled set)';
    }

    $res = set_resolve_members($record['tune_uids'] ?? []);
    if (!$res['ids']) {
        // No member resolves locally — don't create an empty set.
        return ['action' => 'skipped', 'missing' => $res['missing'], 'post_id' => 0];
    }

    $set_id = set_import_find_post($record);

    // --- INSERT ---------------------------------------------------------
    if (!$set_id) {
        $set_id = wp_insert_post([
            'post_title'  => $title,
            'post_status' => 'publish',
            'post_type'   => 'set',
        ], true);
        if (is_wp_error($set_id) || !$set_id) {
            return ['action' => 'error', 'missing' => $res['missing'], 'post_id' => 0];
        }

        $uid = isset($record['uid']) ? (string) $record['uid'] : '';
        if ($uid === '') {
            $uid = 'set:' . wp_generate_uuid4();
        }
        update_post_meta($set_id, TUNE_SET_UID_META, $uid);

        set_write_members($set_id, $res['ids']);
        tune_import_merge_terms($set_id, $record);
        return ['action' => 'inserted', 'missing' => $res['missing'], 'post_id' => (int) $set_id];
    }

    // --- UPDATE ---------------------------------------------------------
    if ($mode === 'add') {
        return ['action' => 'skipped', 'missing' => $res['missing'], 'post_id' => (int) $set_id];
    }

    $local_modified = get_post_field('post_modified_gmt', $set_id);
    $rec_updated    = isset($record['updated']) ? (string) $record['updated'] : '';
    $rec_ts         = $rec_updated !== '' ? strtotime($rec_updated . ' UTC') : 0;
    $loc_ts         = $local_modified ? strtotime($local_modified . ' UTC') : 0;

    if ($rec_ts <= $loc_ts) {
        // Older/equal: still merge memberships additively (cheap, never destructive).
        tune_import_merge_terms($set_id, $record);
        return ['action' => 'skipped', 'missing' => $res['missing'], 'post_id' => (int) $set_id];
    }

    set_write_members($set_id, $res['ids']);
    tune_import_merge_terms($set_id, $record);
    wp_update_post(['ID' => $set_id, 'post_title' => $title]); // touch -> idempotent

    return ['action' => 'updated', 'missing' => $res['missing'], 'post_id' => (int) $set_id];
}

/* ------------------------------------------------------------------ *
 * Bookmark bulk-bar: add tunes to a set (new or existing)
 * ------------------------------------------------------------------ */

/**
 * A set's current member tunes as raw post IDs, in slot order (tune_1..tune_5).
 * Unlike set_export_member_uids() this returns local IDs, not tune_uids — needed
 * to read existing membership before appending more via set_add_tunes_bulk().
 */
function set_get_member_ids($set_id)
{
    $ids = [];
    for ($i = 1; $i <= TUNE_SET_MAX; $i++) {
        $raw = get_post_meta($set_id, 'tune_' . $i, true);
        if ($raw !== '' && $raw !== null) {
            $ids[] = (int) $raw;
        }
    }
    return $ids;
}

/**
 * Create a new set (if $set_id is falsy, using $new_title) or append to an
 * existing one, from a list of already-existing tune post IDs (e.g. bookmarked
 * on the Tunes page) — the bookmark bulk-bar's "add to Set" action.
 *
 * Reject-all on cap overflow (TUNE_SET_MAX): no partial adds, and a freshly
 * created set that would overflow is deleted again rather than left as an
 * empty orphan.
 *
 * @return array{set_id:int, title:string, count:int, url:string}|WP_Error
 */
function set_add_tunes_bulk($set_id, $new_title, array $tune_ids)
{
    $set_id = (int) $set_id;
    $created = false;

    if (!$set_id) {
        $new_title = trim((string) $new_title);
        if ($new_title === '') {
            return new WP_Error('set_add_no_target', 'Pick an existing set or enter a name for a new one.');
        }
        $set_id = wp_insert_post([
            'post_title'  => $new_title,
            'post_status' => 'publish',
            'post_type'   => 'set',
        ], true);
        if (is_wp_error($set_id) || !$set_id) {
            return new WP_Error('set_add_create_failed', 'Could not create the set.');
        }
        $created = true;
    } elseif (get_post_type($set_id) !== 'set') {
        return new WP_Error('set_add_invalid_target', 'That set no longer exists.');
    }

    $existing = set_get_member_ids($set_id);
    $valid_new = array_values(array_filter(array_unique(array_map('intval', $tune_ids)), function ($id) {
        return ub_valid_item('post', $id);
    }));
    $merged = array_values(array_unique(array_merge($existing, $valid_new)));

    if (count($merged) > TUNE_SET_MAX) {
        if ($created) {
            wp_delete_post($set_id, true); // don't leave an empty orphan set behind
        }
        return new WP_Error(
            'set_add_overflow',
            sprintf(
                'That set already has %d tune(s); adding %d more would be %d, over the %d-tune limit.',
                count($existing),
                count($valid_new),
                count($merged),
                TUNE_SET_MAX
            )
        );
    }

    if (!$merged) {
        if ($created) {
            wp_delete_post($set_id, true);
        }
        return new WP_Error('set_add_none_valid', 'None of the selected tunes could be added.');
    }

    set_write_members($set_id, $merged);

    return [
        'set_id' => $set_id,
        'title'  => get_the_title($set_id),
        'count'  => count($merged),
        'url'    => get_permalink($set_id),
    ];
}

add_action('wp_ajax_ub_add_bulk_to_set', 'ub_ajax_add_bulk_to_set');
function ub_ajax_add_bulk_to_set()
{
    check_ajax_referer('ub_ajax'); // same nonce the bulk-bar already emits

    // Real security boundary: creating/editing Sets is editorial content
    // (edit_posts = editor/admin), NOT covered by edit_user_books (which the
    // member role also has, for personal books only). Client-side hiding of
    // the Sets control is UX only — this check is what actually matters.
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('forbidden', 403);
    }

    $set_id    = isset($_POST['set_id']) ? (int) $_POST['set_id'] : 0;
    $new_title = isset($_POST['new_set_title']) ? sanitize_text_field(wp_unslash($_POST['new_set_title'])) : '';
    $ids       = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];

    $result = set_add_tunes_bulk($set_id, $new_title, $ids);
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }
    wp_send_json_success($result);
}
