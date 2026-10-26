<?php

/**
 * Tunebooks — step 2.5: collect tunes and sets into named books.
 *
 * A tunebook ("Graz-Session") is membership, like a tag, in its own namespace.
 * Modelled as a flat custom taxonomy `tunebook` on both `post` (tunes) and
 * `set`. Many-to-many: a tune/set can sit in several books. The term slug is
 * the stable merge key for export/import (no separate uid needed).
 *
 * See tune-import-export-concept.md (§8).
 */

const TUNEBOOK_TAX = 'tunebook';

/**
 * Register the tunebook taxonomy for tunes and sets.
 */
add_action('init', 'tunebook_register_taxonomy');
function tunebook_register_taxonomy()
{
    register_taxonomy(TUNEBOOK_TAX, ['post', 'set'], [
        'labels' => [
            'name'          => 'Tunebooks',
            'singular_name' => 'Tunebook',
            'menu_name'     => 'Tunebooks',
            'all_items'     => 'All Tunebooks',
            'edit_item'     => 'Edit Tunebook',
            'add_new_item'  => 'Add New Tunebook',
            'new_item_name' => 'New Tunebook Name',
            'search_items'  => 'Search Tunebooks',
        ],
        'public'            => true,
        'hierarchical'      => false, // flat, like tags
        'show_ui'           => true,  // native wp-admin box stays available
        'show_admin_column' => true,
        'show_in_rest'      => true,
        'rewrite'           => ['slug' => 'tunebook'],
        // Editors (manage_categories) may create/assign; mirrors that cap.
        'capabilities'      => [
            'manage_terms' => 'manage_categories',
            'edit_terms'   => 'manage_categories',
            'delete_terms' => 'manage_categories',
            'assign_terms' => 'edit_posts',
        ],
    ]);
}

/**
 * Resolve a book name to a term id, creating the term if needed.
 * Caller must ensure the user is allowed to create terms.
 *
 * @return int|null term_id, or null on empty/error.
 */
function tunebook_get_or_create($name)
{
    $name = trim(wp_strip_all_tags((string) $name));
    if ($name === '') {
        return null;
    }
    $term = get_term_by('name', $name, TUNEBOOK_TAX);
    if ($term) {
        return (int) $term->term_id;
    }
    $res = wp_insert_term($name, TUNEBOOK_TAX);
    if (is_wp_error($res)) {
        // Race / already exists: fall back to a fresh lookup.
        $term = get_term_by('name', $name, TUNEBOOK_TAX);
        return $term ? (int) $term->term_id : null;
    }
    return (int) $res['term_id'];
}

/**
 * Add a post (tune or set) to one or more books, additively (never removes
 * existing book memberships).
 *
 * @param int          $post_id
 * @param int[]|int    $term_ids
 */
function tunebook_assign($post_id, $term_ids)
{
    $term_ids = array_values(array_filter(array_map('intval', (array) $term_ids)));
    if (!$term_ids) {
        return;
    }
    wp_set_object_terms($post_id, $term_ids, TUNEBOOK_TAX, true /* append */);
}

/**
 * Resolve a frontend book selection into term ids: chosen existing books plus
 * (if the user may create terms) any new comma-separated names.
 *
 * @param array  $existing_ids  term ids picked from the multiselect
 * @param string $new_names      comma-separated new book names
 * @return int[] unique term ids
 */
function tunebook_resolve_selection($existing_ids, $new_names)
{
    $ids = [];

    foreach ((array) $existing_ids as $tid) {
        $tid = (int) $tid;
        if ($tid && term_exists($tid, TUNEBOOK_TAX)) {
            $ids[] = $tid;
        }
    }

    if ($new_names !== '' && current_user_can('manage_categories')) {
        foreach (explode(',', $new_names) as $name) {
            $tid = tunebook_get_or_create($name);
            if ($tid) {
                $ids[] = $tid;
            }
        }
    }

    return array_values(array_unique($ids));
}

/**
 * Echo a <select> of the existing (non-empty) tunebooks, for the client-side
 * filter on the Tunes / Sets overview pages. Value = term slug; matched in JS
 * against each row's data-books. Renders nothing when there are no books.
 */
function tunebook_filter_dropdown()
{
    $books = get_terms(['taxonomy' => TUNEBOOK_TAX, 'hide_empty' => true]);
    if (is_wp_error($books)) {
        $books = [];
    }

    // Personal books (user_book) contribute their own <optgroup> via this hook.
    ob_start();
    do_action('tunebook_filter_mybooks');
    $extra = ob_get_clean();

    if (!$books && $extra === '') {
        return;
    }

    echo '<select class="form-select book-select" aria-label="Filter by tunebook">';
    echo '<option value="all" selected>All Books</option>';
    foreach ($books as $b) {
        printf(
            '<option value="%s">%s</option>',
            esc_attr($b->slug),
            esc_html($b->name)
        );
    }
    echo $extra; // trusted: our own hook output, already escaped
    echo '</select>';
}

/**
 * Space-separated tunebook slugs of a post (tune or set), for the data-books
 * attribute the client-side filter reads. Empty string when in no book.
 */
function tunebook_slugs_attr($post_id)
{
    $terms = get_the_terms($post_id, TUNEBOOK_TAX);
    if (!$terms || is_wp_error($terms)) {
        return '';
    }
    return implode(' ', wp_list_pluck($terms, 'slug'));
}

/**
 * Badge colours for session books. Dark enough for white badge text (all
 * >= 4.5:1 against white); no red — that reads as "delete" next to the
 * trash affordance.
 */
function tunebook_badge_palette()
{
    return ['#0a58ca', '#146c43', '#5b2d90', '#9d174d', '#0f766e', '#92400e'];
}

/**
 * Colour for a book's badge, assigned by the book's position in creation
 * order (term_id), not hashed from the slug: a hash can collide and give two
 * books the same colour, and alphabetical order would re-colour every
 * existing book the moment one named "Burwell" is added.
 */
function tunebook_badge_color($term)
{
    static $order = null;
    if ($order === null) {
        $ids = get_terms([
            'taxonomy'   => TUNEBOOK_TAX,
            'hide_empty' => false,
            'orderby'    => 'term_id',
            'order'      => 'ASC',
            'fields'     => 'ids',
        ]);
        $order = is_wp_error($ids) ? [] : array_flip($ids);
    }
    $palette = tunebook_badge_palette();
    $idx = isset($order[$term->term_id]) ? $order[$term->term_id] : 0;
    return $palette[$idx % count($palette)];
}

/**
 * Badge text for a session book: "KTN-Session" -> "KTN". Every book carries
 * the same "-Session" suffix, so it eats width in the tune list without
 * distinguishing anything. Falls back to the full name if stripping would
 * leave nothing (a book literally called "Session").
 */
function tunebook_badge_label($name)
{
    $short = preg_replace('/[\s-]*sessions?$/i', '', $name);
    return ($short === null || $short === '') ? $name : $short;
}

/**
 * Render a post's session-book badges — shortened, colour-coded, and titled
 * with the full book name since the label is abbreviated.
 */
function tunebook_badges($terms)
{
    if (!$terms || is_wp_error($terms)) {
        return;
    }
    foreach ($terms as $book) {
        printf(
            '<a href="%s" class="badge book-badge text-white me-1 text-decoration-none" style="background-color:%s" title="%s">%s</a>',
            esc_url(get_term_link($book)),
            esc_attr(tunebook_badge_color($book)),
            esc_attr($book->name),
            esc_html(tunebook_badge_label($book->name))
        );
    }
}

/**
 * Shared red trash button used by the swipe-/hover-to-remove UI (step 5), for
 * both personal books and public tunebooks. Behaviour lives in main.js.
 */
function isg_swipe_del_btn($label = 'Remove from book')
{
    return '<button type="button" class="swipe-del-btn" aria-label="' . esc_attr($label) . '" title="' . esc_attr($label) . '">'
        . '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">'
        . '<path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0z"/>'
        . '<path d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4zM2.5 3h11V2h-11z"/></svg></button>';
}

/**
 * AJAX: remove a tune/set from a PUBLIC tunebook (its taxonomy term). Session
 * books are shared, so this is gated to contributors (edit_posts), unlike the
 * owner-only ub_remove_item for personal books. The tune/set itself is never
 * deleted — only its membership in this book (decision 2026-07-14).
 */
add_action('wp_ajax_tunebook_remove_item', 'tunebook_ajax_remove_item');
function tunebook_ajax_remove_item()
{
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('forbidden', 403);
    }
    check_ajax_referer('tunebook_remove');
    $post_id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $term_id = isset($_POST['term']) ? (int) $_POST['term'] : 0;
    if (!$post_id || !$term_id) {
        wp_send_json_error('bad params');
    }
    wp_remove_object_terms($post_id, $term_id, TUNEBOOK_TAX);
    wp_send_json_success(['id' => $post_id, 'term' => $term_id]);
}

/**
 * Where else is this tune/set used? Powers the confirm dialog before a delete
 * from a session book, so the decision is made with the consequences on screen
 * rather than guessed at.
 *
 * $exclude_term_id is the book being edited — listing it back would be noise.
 * For a set, 'sets' is always empty (sets don't nest).
 */
function tunebook_item_usage($post_id, $exclude_term_id = 0)
{
    $post = get_post($post_id);
    if (!$post) {
        return ['books' => [], 'sets' => []];
    }

    $books = [];
    $terms = get_the_terms($post_id, TUNEBOOK_TAX);
    if ($terms && !is_wp_error($terms)) {
        foreach ($terms as $t) {
            if ((int) $t->term_id === (int) $exclude_term_id) {
                continue;
            }
            $books[] = $t->name;
        }
    }

    $sets = [];
    if ($post->post_type === 'post') {
        // ACF stores a post-object field as the bare post ID, so the slots can
        // be matched with a plain meta query.
        $meta = ['relation' => 'OR'];
        for ($i = 1; $i <= TUNE_SET_MAX; $i++) {
            $meta[] = ['key' => 'tune_' . $i, 'value' => (string) $post_id, 'compare' => '='];
        }
        $q = new WP_Query([
            'post_type'      => 'set',
            'post_status'    => ['publish', 'private', 'draft', 'pending'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => $meta,
        ]);
        foreach ($q->posts as $sid) {
            $sets[] = get_the_title($sid);
        }
    }

    return ['books' => $books, 'sets' => $sets];
}

/**
 * AJAX: what would be affected by deleting this item? Read-only; same
 * edit_posts gate as the rest of the book-editing UI. Also reports whether
 * this user may delete at all, so the dialog can hide the option instead of
 * offering a button that would 403.
 */
add_action('wp_ajax_tunebook_item_usage', 'tunebook_ajax_item_usage');
function tunebook_ajax_item_usage()
{
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('forbidden', 403);
    }
    check_ajax_referer('tunebook_remove');
    $post_id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $term_id = isset($_POST['term']) ? (int) $_POST['term'] : 0;
    $post = $post_id ? get_post($post_id) : null;
    if (!$post) {
        wp_send_json_error('unknown item');
    }
    $usage = tunebook_item_usage($post_id, $term_id);
    $term = $term_id ? get_term($term_id, TUNEBOOK_TAX) : null;
    wp_send_json_success([
        'title'      => get_the_title($post_id),
        'type'       => $post->post_type === 'set' ? 'set' : 'tune',
        'book'       => ($term && !is_wp_error($term)) ? $term->name : '',
        'books'      => $usage['books'],
        'sets'       => $usage['sets'],
        'can_delete' => current_user_can('delete_post', $post_id),
    ]);
}

/**
 * AJAX: delete a tune/set from a SESSION book for real — the "it's gone"
 * option next to plain remove-from-book (user decision 2026-07-15: a session
 * book is the umbrella for its tunes, so throwing one out of the book usually
 * means throwing it away, and going into wp-admin for that is a detour).
 *
 * Trash, not force-delete: a trashed tune disappears from the site AND from
 * every set that used it (verified — ACF resolves a trashed post to falsy, and
 * page-sets.php skips falsy slots), but it stays restorable from wp-admin.
 * Same visible outcome as a permanent delete, minus the one-way door.
 *
 * Gated per post via delete_post (not the blanket edit_posts of the remove
 * endpoint) — this one destroys shared data.
 */
add_action('wp_ajax_tunebook_delete_item', 'tunebook_ajax_delete_item');
function tunebook_ajax_delete_item()
{
    check_ajax_referer('tunebook_remove');
    $post_id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $post = $post_id ? get_post($post_id) : null;
    if (!$post) {
        wp_send_json_error('unknown item');
    }
    if (!in_array($post->post_type, ['post', 'set'], true)) {
        wp_send_json_error('not a tune or set');
    }
    if (!current_user_can('delete_post', $post_id)) {
        wp_send_json_error('forbidden', 403);
    }
    if (!wp_trash_post($post_id)) {
        wp_send_json_error('could not delete');
    }
    wp_send_json_success(['id' => $post_id, 'trashed' => true]);
}

/**
 * Render a public tunebook's sets + tunes (the browse view). Shared by the
 * Books page (page-books.php) and the /tunebook/<slug>/ archive
 * (taxonomy-tunebook.php). For contributors, each row is wrapped so it can be
 * swiped (mobile) / hovered (desktop) to remove it from THIS book (step 5).
 */
function tunebook_render_contents($term)
{
    $term_id = (int) $term->term_id;
    $tax_q = [[
        'taxonomy' => TUNEBOOK_TAX,
        'field'    => 'term_id',
        'terms'    => [$term_id],
    ]];
    $tuneQuery = new WP_Query(['post_type' => 'post', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'tax_query' => $tax_q]);
    $setQuery  = new WP_Query(['post_type' => 'set',  'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'tax_query' => $tax_q]);

    $can   = current_user_can('edit_posts');
    $nonce = wp_create_nonce('tunebook_remove');
    $ajax  = admin_url('admin-ajax.php');

    // Open/close a swipe-del wrapper around a row (only for contributors).
    $open = function ($id) use ($can, $term_id, $nonce, $ajax) {
        if (!$can) {
            return '';
        }
        return sprintf(
            '<div class="swipe-del" data-del-action="tunebook_remove_item" data-del-term="%d" data-del-id="%d" data-del-nonce="%s" data-del-ajax="%s"><div class="swipe-del-content">',
            $term_id,
            $id,
            esc_attr($nonce),
            esc_url($ajax)
        );
    };
    $close = function () use ($can) {
        return $can ? '</div>' . isg_swipe_del_btn() . '</div>' : '';
    };
    ?>
    <p class="text-muted mb-2"><?php echo (int) $tuneQuery->found_posts; ?> tunes, <?php echo (int) $setQuery->found_posts; ?> sets</p>

    <?php if ($setQuery->have_posts()) : ?>
        <h2 class="mt-3">Sets</h2>
        <?php while ($setQuery->have_posts()) : $setQuery->the_post();
            $setTags = get_the_tags();
            echo $open(get_the_ID()); ?>
            <div class="row tune-row py-2" data-navid="<?php the_ID(); ?>" data-navkind="set">
                <div class="col-9 col-md-8"><a href="<?php the_permalink(); ?>" class="tunetitle"><?php the_title(); ?></a></div>
                <div class="col-3 col-md-4"><?php if ($setTags) foreach ($setTags as $tag) { echo '<span class="badge bg-secondary me-1">' . esc_html($tag->name) . '</span>'; } ?></div>
            </div>
            <?php echo $close(); ?>
        <?php endwhile; wp_reset_postdata(); ?>
    <?php endif; ?>

    <h2 class="mt-4">Tunes</h2>
    <?php if ($tuneQuery->have_posts()) : while ($tuneQuery->have_posts()) : $tuneQuery->the_post();
        $tune = new tune(get_the_ID());
        $rowTags = get_the_tags();
        echo $open(get_the_ID()); ?>
        <div class="row tune-row py-2" data-navid="<?php the_ID(); ?>" data-navkind="tune">
            <div class="col-9 col-md-4"><a href="<?php the_permalink(); ?>" class="tunetitle"><?php the_title(); ?></a></div>
            <div class="col-auto col-md-2 small"><?php echo $tune->tuneType; ?></div>
            <div class="col-auto col-md-2 small"><?php echo $tune->key; ?></div>
            <div class="col-auto col-md-1"><?php echo $tune->getSessionLink(); ?></div>
            <div class="col-auto col-md-3"><?php if ($rowTags) foreach ($rowTags as $tag) { echo '<span class="badge bg-secondary me-1">' . esc_html($tag->name) . '</span>'; } ?></div>
        </div>
        <?php echo $close();
    endwhile; else : ?>
        <p class="mt-2">No tunes in this book yet.</p>
    <?php endif; wp_reset_postdata(); ?>
    <?php
}

/**
 * Query for items of a post type not yet in a book. Tunes are gated on a uid
 * (real tunes only); sets aren't (every set is real).
 */
function tunebook_pending_query($post_type, $term_id, $per_page)
{
    $args = [
        'post_type'      => $post_type,
        'post_status'    => 'any',
        'posts_per_page' => $per_page,
        'fields'         => 'ids',
        'tax_query'      => [[
            'taxonomy' => TUNEBOOK_TAX,
            'field'    => 'term_id',
            'terms'    => [(int) $term_id],
            'operator' => 'NOT IN',
        ]],
    ];
    if ($post_type === 'post') {
        $args['meta_query'] = [
            ['key' => TUNE_UID_META, 'value' => '', 'compare' => '!='],
        ];
    }
    return new WP_Query($args);
}

/**
 * Process one batch of tunes AND sets not yet in the given book. Tunes are
 * filled first, sets take the remaining batch capacity (like the import). A
 * "session" book (Graz-Session) thus collects both. Browser-based + batched
 * (no WP-CLI on live, concept §5); idempotent.
 *
 * @return array{processed:int, remaining:int}
 */
function tunebook_backfill_batch($term_id, $batch = 25)
{
    $term_id = (int) $term_id;

    // --- tunes ---
    $tq = tunebook_pending_query('post', $term_id, $batch);
    foreach ($tq->posts as $pid) {
        tunebook_assign($pid, $term_id);
    }
    $tunes_done      = count($tq->posts);
    $tunes_remaining = max(0, (int) $tq->found_posts - $tunes_done);

    // --- sets (remaining capacity; if the batch was all tunes, just count them) ---
    $cap = $batch - $tunes_done;
    if ($cap > 0) {
        $sq = tunebook_pending_query('set', $term_id, $cap);
        foreach ($sq->posts as $sid) {
            tunebook_assign($sid, $term_id);
        }
        $sets_done      = count($sq->posts);
        $sets_remaining = max(0, (int) $sq->found_posts - $sets_done);
    } else {
        $sets_done      = 0;
        $sets_remaining = (int) tunebook_pending_query('set', $term_id, 1)->found_posts;
    }

    return [
        'processed' => $tunes_done + $sets_done,
        'remaining' => $tunes_remaining + $sets_remaining,
    ];
}

/**
 * AJAX endpoint: assign one batch of tunes to a book (creating it if needed).
 */
add_action('wp_ajax_tunebook_backfill', 'tunebook_ajax_backfill');
function tunebook_ajax_backfill()
{
    if (!current_user_can('manage_categories')) {
        wp_send_json_error('forbidden', 403);
    }
    check_ajax_referer('tunebook_backfill');

    $name = isset($_POST['book']) ? wp_unslash($_POST['book']) : '';
    $term_id = tunebook_get_or_create($name);
    if (!$term_id) {
        wp_send_json_error('empty book name');
    }

    $res = tunebook_backfill_batch($term_id);
    $res['term_id'] = $term_id;
    wp_send_json_success($res);
}

/**
 * Render the tunebook backfill section on the Tune Sync tools page.
 */
add_action('tune_sync_sections', 'tunebook_render_section');
function tunebook_render_section()
{
    $books = get_terms(['taxonomy' => TUNEBOOK_TAX, 'hide_empty' => false]);
    if (is_wp_error($books)) {
        $books = [];
    }
    $nonce    = wp_create_nonce('tunebook_backfill');
    $ajax_url = admin_url('admin-ajax.php');
?>
    <hr style="margin:2em 0">
    <h2>Tunebooks</h2>
    <p>Assign <strong>all tunes and sets</strong> to a book (e.g. <code>Graz-Session</code>).
        Additive and idempotent — runs in batches, re-running does no harm. The
        book is created if it does not exist yet.</p>

    <?php if ($books) : ?>
        <p>Existing books:
            <?php foreach ($books as $b) : ?>
                <span class="badge" style="display:inline-block;background:#2271b1;color:#fff;border-radius:3px;padding:2px 8px;margin-right:4px">
                    <?php echo esc_html($b->name); ?> (<?php echo (int) $b->count; ?>)
                </span>
            <?php endforeach; ?>
        </p>
    <?php endif; ?>

    <p>
        <input type="text" id="tunebook-name" class="regular-text" placeholder="Graz-Session"
            list="tunebook-list">
        <datalist id="tunebook-list">
            <?php foreach ($books as $b) : ?>
                <option value="<?php echo esc_attr($b->name); ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <button class="button button-primary" id="tunebook-assign-btn">Assign all tunes &amp; sets to book</button>
        <span id="tunebook-status" style="margin-left:1em"></span>
    </p>

    <script>
        (function() {
            const btn = document.getElementById('tunebook-assign-btn');
            const input = document.getElementById('tunebook-name');
            const status = document.getElementById('tunebook-status');
            const ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;
            const nonce = <?php echo wp_json_encode($nonce); ?>;

            async function runBatch(book) {
                const body = new URLSearchParams({
                    action: 'tunebook_backfill',
                    _wpnonce: nonce,
                    book: book
                });
                const res = await fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.data || 'error');
                return data.data; // {processed, remaining, term_id}
            }

            btn.addEventListener('click', async function() {
                const book = input.value.trim();
                if (!book) {
                    status.textContent = 'Please enter a book name.';
                    return;
                }
                btn.disabled = true;
                let total = 0;
                try {
                    while (true) {
                        const r = await runBatch(book);
                        total += r.processed;
                        status.textContent = 'assigned: ' + total + ' — pending: ' + r.remaining;
                        if (r.remaining <= 0 || r.processed === 0) break;
                    }
                    status.textContent = 'Done. ' + total + ' item(s) now in "' + book + '". Reload to refresh counts.';
                } catch (e) {
                    status.textContent = 'Error: ' + e.message;
                }
                btn.disabled = false;
            });
        })();
    </script>
<?php
}
