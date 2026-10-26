<?php

/**
 * thesession.org search assist (Feature #8, stage 1).
 *
 * Two logged-in AJAX endpoints that proxy thesession.org's public JSON API
 * (server-side, so no browser CORS issue — same reason importTunes() fetches
 * server-side). They only *assist*: the search fills the existing Tune-ID /
 * Setting fields on the add-tune form, which then run the proven importTunes()
 * path. No new storage code here.
 *
 * API (verified 2026-07-05):
 *   GET /tunes/search?q=<q>&format=json
 *     -> { pages, page, total, tunes:[{id,name,alias?,url,member,date,type}] }  (no ABC)
 *   GET /tunes/<id>?format=json
 *     -> { ..., type, settings:[{id,url,key,abc,member,date}], comments:[…] }
 *
 * See skill tunes-thesession-abc for the full API contract.
 */

const TUNE_TS_BASE     = 'https://thesession.org';
const TUNE_TS_TIMEOUT  = 12; // seconds

/**
 * Fetch + decode a thesession.org JSON endpoint. Returns the decoded array or a
 * WP_Error. Uses the WP HTTP API (timeouts / TLS handled for us).
 */
function tune_ts_get($path)
{
    $res = wp_remote_get(TUNE_TS_BASE . $path, [
        'timeout' => TUNE_TS_TIMEOUT,
        'headers' => ['Accept' => 'application/json'],
    ]);
    if (is_wp_error($res)) {
        return $res;
    }
    $code = wp_remote_retrieve_response_code($res);
    if ($code !== 200) {
        return new WP_Error('ts_http', 'thesession.org returned HTTP ' . $code);
    }
    $data = json_decode(wp_remote_retrieve_body($res), true);
    if (!is_array($data)) {
        return new WP_Error('ts_json', 'Could not decode thesession.org response.');
    }
    return $data;
}

/**
 * AJAX: search tunes by title. Returns a slim list for the picker.
 */
add_action('wp_ajax_tune_ts_search', 'tune_ts_ajax_search');
function tune_ts_ajax_search()
{
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('forbidden', 403);
    }
    check_ajax_referer('tune_ts');

    $q = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
    if ($q === '') {
        wp_send_json_error('Enter a title to search.');
    }

    $data = tune_ts_get('/tunes/search?q=' . rawurlencode($q) . '&format=json');
    if (is_wp_error($data)) {
        wp_send_json_error($data->get_error_message());
    }

    $tunes = [];
    foreach ((isset($data['tunes']) && is_array($data['tunes'])) ? $data['tunes'] : [] as $t) {
        $tunes[] = [
            'id'    => isset($t['id']) ? (int) $t['id'] : 0,
            'name'  => isset($t['name']) ? (string) $t['name'] : '',
            'alias' => isset($t['alias']) ? (string) $t['alias'] : '',
            'type'  => isset($t['type']) ? (string) $t['type'] : '',
            'url'   => isset($t['url']) ? (string) $t['url'] : '',
        ];
    }

    wp_send_json_success([
        'total' => isset($data['total']) ? (int) $data['total'] : count($tunes),
        'pages' => isset($data['pages']) ? (int) $data['pages'] : 1,
        'tunes' => $tunes,
    ]);
}

/**
 * AJAX: fetch one tune's settings (the variants) for the setting picker.
 * Comments are stripped (not needed, and huge). ABC is included (stage 2) so
 * the add-tune page can render a preview of the candidate setting next to an
 * uploaded scan before the user decides hybrid/abc-only/image-only.
 */
add_action('wp_ajax_tune_ts_detail', 'tune_ts_ajax_detail');
function tune_ts_ajax_detail()
{
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('forbidden', 403);
    }
    check_ajax_referer('tune_ts');

    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    if ($id <= 0) {
        wp_send_json_error('Missing tune id.');
    }

    $data = tune_ts_get('/tunes/' . $id . '?format=json');
    if (is_wp_error($data)) {
        wp_send_json_error($data->get_error_message());
    }

    $settings = [];
    foreach ((isset($data['settings']) && is_array($data['settings'])) ? $data['settings'] : [] as $i => $s) {
        $settings[] = [
            'index' => $i + 1, // 1-based, matches the Settings form field (importTunes subtracts 1)
            'key'   => isset($s['key']) ? (string) $s['key'] : '',
            'abc'   => isset($s['abc']) ? (string) $s['abc'] : '',
        ];
    }

    wp_send_json_success([
        'id'       => $id,
        'name'     => isset($data['name']) ? (string) $data['name'] : '',
        'type'     => isset($data['type']) ? (string) $data['type'] : '',
        'settings' => $settings,
    ]);
}

/* ------------------------------------------------------------------ *
 * One-time backfill: strip `comments` from already-stored session tunes.
 * Idempotent + batched (browser-based, no CLI on live — like the uid backfill).
 * ------------------------------------------------------------------ */

/**
 * Meta query for tunes whose stored json still contains a `comments` key.
 * Once stripped they no longer match, so `remaining` shrinks to zero.
 */
function tune_ts_comments_pending_query()
{
    return [
        ['key' => 'json', 'value' => '"comments"', 'compare' => 'LIKE'],
    ];
}

/**
 * Strip comments from one batch of stored session tunes.
 *
 * @return array{processed:int, stripped:int, remaining:int}
 */
function tune_ts_strip_batch($batch = 15)
{
    $q = new WP_Query([
        'post_type'      => 'post',
        'post_status'    => 'any',
        'posts_per_page' => $batch,
        'fields'         => 'ids',
        'meta_query'     => tune_ts_comments_pending_query(),
    ]);

    $processed = 0;
    $stripped  = 0;
    foreach ($q->posts as $pid) {
        $json = get_post_meta($pid, 'json', true);
        $lean = tune_strip_session_json($json);
        if ($lean !== $json) {
            update_field('json', wp_slash($lean), $pid);
            $stripped++;
        }
        $processed++;
    }

    return [
        'processed' => $processed,
        'stripped'  => $stripped,
        'remaining' => max(0, (int) $q->found_posts - $processed),
    ];
}

function tune_ts_count_comments_pending()
{
    $q = new WP_Query([
        'post_type'      => 'post',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => tune_ts_comments_pending_query(),
    ]);
    return (int) $q->found_posts;
}

add_action('wp_ajax_tune_ts_strip', 'tune_ts_ajax_strip');
function tune_ts_ajax_strip()
{
    if (!current_user_can(tune_sync_cap())) {
        wp_send_json_error('forbidden', 403);
    }
    check_ajax_referer('tune_ts_strip');
    wp_send_json_success(tune_ts_strip_batch(15));
}

/**
 * Backfill section on the Tune Sync tools page.
 */
add_action('tune_sync_sections', 'tune_ts_render_strip_section');
function tune_ts_render_strip_section()
{
    $pending  = tune_ts_count_comments_pending();
    $nonce    = wp_create_nonce('tune_ts_strip');
    $ajax_url = admin_url('admin-ajax.php');
?>
    <hr style="margin:2em 0">
    <h2>Strip thesession comments</h2>
    <p>Removes the bulky user-<code>comments</code> thread from stored session tunes
        (not shown anywhere; ~83&nbsp;% of a tune's JSON). Batched + idempotent.</p>
    <table class="widefat striped" style="max-width:480px">
        <tbody>
            <tr><td>Tunes still carrying comments</td><td id="tune-ts-strip-pending"><strong><?php echo esc_html($pending); ?></strong></td></tr>
        </tbody>
    </table>
    <p style="margin-top:1em">
        <button class="button button-primary" id="tune-ts-strip-btn" <?php disabled($pending, 0); ?>>Strip comments</button>
        <span id="tune-ts-strip-status" style="margin-left:1em"></span>
    </p>
    <script>
        (function () {
            var btn = document.getElementById('tune-ts-strip-btn');
            var status = document.getElementById('tune-ts-strip-status');
            var pendingCell = document.getElementById('tune-ts-strip-pending');
            var ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;
            var nonce = <?php echo wp_json_encode($nonce); ?>;

            async function runBatch() {
                var body = new URLSearchParams({ action: 'tune_ts_strip', _wpnonce: nonce });
                var res = await fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body });
                var data = await res.json();
                if (!data.success) throw new Error(data.data || 'error');
                return data.data;
            }

            btn.addEventListener('click', async function () {
                btn.disabled = true;
                var total = 0;
                try {
                    while (true) {
                        var r = await runBatch();
                        total += r.stripped;
                        pendingCell.querySelector('strong').textContent = r.remaining;
                        status.textContent = 'stripped: ' + total + ' — remaining: ' + r.remaining;
                        if (r.remaining <= 0 || r.processed === 0) break;
                    }
                    status.textContent = 'Done. Stripped comments from ' + total + ' tune(s).';
                } catch (e) {
                    status.textContent = 'Error: ' + e.message;
                    btn.disabled = false;
                }
            });
        })();
    </script>
<?php
}
