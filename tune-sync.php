<?php

/**
 * Tune Sync — step 1: stable key `tune_uid` + backfill.
 *
 * tune_uid is the immutable per-tune key that survives a re-import
 * (even when title/tags/abc are edited):
 *   - session:<session_id>  for thesession.org tunes (deterministic)
 *   - local:<uuid-v4>       for abc/image tunes (generated once)
 *
 * See tune-import-export-concept.md (§1, §7.4).
 */

const TUNE_UID_META  = 'tune_uid';
const TUNE_SKIP_META = '_tune_uid_skip'; // marks non-tunes so the backfill stops requerying them

/**
 * Capability required for the Tune Sync tools (backfill / export / import).
 * `edit_others_posts` = editors and admins, not authors/members — matches the
 * "contributing users" group, who already edit all tunes/sets anyway. Single
 * point of control for tune-sync.php, tune-export.php and tune-import.php.
 */
function tune_sync_cap()
{
    return 'edit_others_posts';
}

/**
 * Strip the bulky `comments` array from a thesession.org tune JSON blob before
 * storing it. thesession returns the full user-discussion thread inline — for
 * "The Butterfly" that's 55 KB of 66 KB (83 %), and nothing in the theme reads
 * it (class-tune.php only uses type, settings[].key/abc, id). Feature #8.
 *
 * Null-safe: returns the input unchanged if it is not decodable JSON or has no
 * `comments` key, so callers can pipe any value through it.
 *
 * @param string $json_string  raw JSON as fetched/stored
 * @return string              JSON without `comments` (or the original)
 */
function tune_strip_session_json($json_string)
{
    if (!is_string($json_string) || $json_string === '') {
        return $json_string;
    }
    $data = json_decode($json_string, true);
    if (!is_array($data) || !array_key_exists('comments', $data)) {
        return $json_string;
    }
    unset($data['comments']);
    $reencoded = wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $reencoded !== false ? $reencoded : $json_string;
}

/**
 * Determine a post's uid without storing it.
 * Returns null if the post is not a tune (neither session_id nor abc/json).
 */
function tune_compute_uid($post_id)
{
    $session_id = get_post_meta($post_id, 'session_id', true);
    if ($session_id !== '' && $session_id !== null && $session_id !== false) {
        return 'session:' . $session_id;
    }

    // Non-session: a tune if it carries ABC, a json blob, or (image-Tune-Typ,
    // step 4a) a scanned image/pdf attachment with neither of those.
    $abc  = get_post_meta($post_id, 'abc', true);
    $json = get_post_meta($post_id, 'json', true);
    if ($abc || $json || tune_find_scan_attachment($post_id)) {
        return 'local:' . wp_generate_uuid4();
    }

    return null;
}

/**
 * Return a tune's uid, generating + storing it on demand (idempotent).
 * Returns null if the post is not a tune.
 */
function tune_ensure_uid($post_id)
{
    $existing = get_post_meta($post_id, TUNE_UID_META, true);
    if ($existing !== '' && $existing !== null) {
        return $existing;
    }

    $uid = tune_compute_uid($post_id);
    if ($uid) {
        update_post_meta($post_id, TUNE_UID_META, $uid);
    }
    return $uid;
}

/**
 * Show tune_uid as a read-only text field on the post editor.
 * Registered as a local ACF field group so it sits next to the other
 * tune fields; the value is read from the existing `tune_uid` postmeta.
 * `readonly` keeps it visible but uneditable (and still submitted, so the
 * value is preserved on save).
 */
add_action('acf/init', 'tune_uid_register_acf_field');
function tune_uid_register_acf_field()
{
    if (!function_exists('acf_add_local_field_group')) {
        return;
    }

    acf_add_local_field_group([
        'key'      => 'group_tune_uid',
        'title'    => 'Tune Sync',
        'fields'   => [
            [
                'key'          => 'field_tune_uid',
                'label'        => 'Tune UID',
                'name'         => 'tune_uid',
                'type'         => 'text',
                'instructions' => 'Stable import/export key. Auto-generated, read-only.',
                'readonly'     => 1,
            ],
        ],
        'location' => [
            [
                ['param' => 'post_type', 'operator' => '==', 'value' => 'post'],
            ],
        ],
        'position'   => 'side',
        'menu_order' => 100,
        'active'     => true,
    ]);
}

/**
 * Assign the uid whenever a tune is saved in wp-admin.
 *
 * Priority 99 so this runs AFTER ACF (priority 10) has written its form values.
 * The read-only ACF field submits an empty value whenever the meta was empty at
 * page load, which would otherwise blank the uid — running last lets us (re)fill
 * it. We hook the generic `save_post` (not `save_post_post`, which fires *before*
 * the generic one and thus before ACF).
 */
add_action('save_post', 'tune_uid_on_save', 99, 1);
function tune_uid_on_save($post_id)
{
    if (get_post_type($post_id) !== 'post') {
        return;
    }
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }
    tune_ensure_uid($post_id);
}

/**
 * Meta query matching tunes that still need a uid: the uid meta is missing OR
 * empty (ACF can leave an empty value behind), and not flagged as a non-tune.
 */
function tune_pending_meta_query()
{
    return [
        'relation' => 'AND',
        ['key' => TUNE_SKIP_META, 'compare' => 'NOT EXISTS'],
        [
            'relation' => 'OR',
            ['key' => TUNE_UID_META, 'compare' => 'NOT EXISTS'],
            ['key' => TUNE_UID_META, 'value' => '', 'compare' => '='],
        ],
    ];
}

/**
 * Process one batch of tunes without a uid. Browser-based + batched, because
 * the live site has no WP-CLI (no SSH) — see concept §5.
 *
 * @return array{processed:int, assigned:int, remaining:int}
 */
function tune_backfill_batch($batch = 25)
{
    $q = new WP_Query([
        'post_type'      => 'post',
        'post_status'    => 'any',
        'posts_per_page' => $batch,
        'fields'         => 'ids',
        'meta_query'     => tune_pending_meta_query(),
    ]);

    $processed = 0;
    $assigned  = 0;

    foreach ($q->posts as $pid) {
        $uid = tune_compute_uid($pid);
        if ($uid) {
            update_post_meta($pid, TUNE_UID_META, $uid);
            $assigned++;
        } else {
            // Not a tune — mark it so the next batch skips it.
            update_post_meta($pid, TUNE_SKIP_META, 1);
        }
        $processed++;
    }

    $remaining = max(0, (int) $q->found_posts - $processed);

    return [
        'processed' => $processed,
        'assigned'  => $assigned,
        'remaining' => $remaining,
    ];
}

/**
 * Count posts that still have no uid (and no skip marker).
 */
function tune_count_pending()
{
    $q = new WP_Query([
        'post_type'      => 'post',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => tune_pending_meta_query(),
    ]);
    return (int) $q->found_posts;
}

/**
 * Count posts that already have a non-empty uid.
 */
function tune_count_with_uid()
{
    $q = new WP_Query([
        'post_type'      => 'post',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => [
            ['key' => TUNE_UID_META, 'value' => '', 'compare' => '!='],
        ],
    ]);
    return (int) $q->found_posts;
}

/**
 * AJAX endpoint: process one batch and report progress back.
 */
add_action('wp_ajax_tune_backfill', 'tune_ajax_backfill');
function tune_ajax_backfill()
{
    if (!current_user_can(tune_sync_cap())) {
        wp_send_json_error('forbidden', 403);
    }
    check_ajax_referer('tune_backfill');

    wp_send_json_success(tune_backfill_batch(25));
}

/**
 * Admin page under "Tools → Tune Sync".
 */
add_action('admin_menu', function () {
    add_management_page(
        'Tune Sync',
        'Tune Sync',
        tune_sync_cap(),
        'tune-sync',
        'tune_sync_render_page'
    );
});

function tune_sync_render_page()
{
    $with_uid = tune_count_with_uid();
    $pending  = tune_count_pending();
    $nonce    = wp_create_nonce('tune_backfill');
    $ajax_url = admin_url('admin-ajax.php');
?>
    <div class="wrap">
        <h1>Tune Sync</h1>

        <h2>tune_uid backfill</h2>
        <p>Assigns every tune a stable key: <code>session:&lt;id&gt;</code> or
            <code>local:&lt;uuid&gt;</code>. Runs in batches (browser-based, no CLI needed)
            and is idempotent — running it again does no harm.
        </p>

        <table class="widefat striped" style="max-width:480px">
            <tbody>
                <tr>
                    <td>Tunes with uid</td>
                    <td id="tune-uid-have"><strong><?php echo esc_html($with_uid); ?></strong></td>
                </tr>
                <tr>
                    <td>Pending</td>
                    <td id="tune-uid-pending"><strong><?php echo esc_html($pending); ?></strong></td>
                </tr>
            </tbody>
        </table>

        <p style="margin-top:1em">
            <button class="button button-primary" id="tune-backfill-btn"
                <?php disabled($pending, 0); ?>>Start backfill</button>
            <span id="tune-backfill-status" style="margin-left:1em"></span>
        </p>

        <?php
        /**
         * Extension point: other sync modules (export, import) append their
         * sections here so they share this single Tools page.
         */
        do_action('tune_sync_sections');
        ?>

        <script>
            (function() {
                const btn = document.getElementById('tune-backfill-btn');
                const status = document.getElementById('tune-backfill-status');
                const pendingCell = document.getElementById('tune-uid-pending');
                const haveCell = document.getElementById('tune-uid-have');
                const ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;
                const nonce = <?php echo wp_json_encode($nonce); ?>;

                let totalAssigned = 0;

                async function runBatch() {
                    const body = new URLSearchParams({
                        action: 'tune_backfill',
                        _wpnonce: nonce
                    });
                    const res = await fetch(ajaxUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        body
                    });
                    const data = await res.json();
                    if (!data.success) {
                        throw new Error(data.data || 'error');
                    }
                    return data.data; // {processed, assigned, remaining}
                }

                btn.addEventListener('click', async function() {
                    btn.disabled = true;
                    totalAssigned = 0;
                    try {
                        while (true) {
                            const r = await runBatch();
                            totalAssigned += r.assigned;
                            pendingCell.querySelector('strong').textContent = r.remaining;
                            haveCell.querySelector('strong').textContent =
                                (parseInt(haveCell.querySelector('strong').textContent, 10) || 0) + r.assigned;
                            status.textContent = 'processed: ' + totalAssigned + ' — pending: ' + r.remaining;
                            if (r.remaining <= 0 || r.processed === 0) break;
                        }
                        status.textContent = 'Done. Assigned uid to ' + totalAssigned + ' tune(s).';
                    } catch (e) {
                        status.textContent = 'Error: ' + e.message;
                        btn.disabled = false;
                    }
                });
            })();
        </script>
    </div>
<?php
}
