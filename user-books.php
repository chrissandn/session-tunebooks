<?php

/**
 * Personal user books — backlog #2 (Frontend-Login + eigene Books).
 *
 * A "user book" is a per-user, private collection of existing tunes and sets —
 * separate from the global `tunebook` taxonomy (Graz-Session etc.), which stays
 * editor/admin-curated. Members only reference existing tunes/sets by id; they
 * never edit the tunes/sets themselves.
 *
 * Roles (see benutzerrollen.md):
 *   - administrator  everything, incl. others' user books
 *   - editor         tunes/sets/global books (built-in caps) + own user books
 *   - member (new)   read + own user books only
 *
 * Storage: post_type `user_book` (private, no wp-admin UI), owner = post_author,
 * members = ordered meta list `_ub_items` of "post:<id>" / "set:<id>" strings.
 *
 * This file: post type + roles/caps, data helpers, admin-post + AJAX endpoints,
 * and the frontend manager rendered by page-my-books.php.
 */

const UB_POST_TYPE   = 'user_book';
const UB_ITEMS_META  = '_ub_items';
const UB_ROLES_VER   = '1';                 // bump to re-run role/cap setup

/* ------------------------------------------------------------------ *
 * Post type + roles
 * ------------------------------------------------------------------ */

add_action('init', 'ub_register_post_type', 5);
function ub_register_post_type()
{
    register_post_type(UB_POST_TYPE, [
        'label'             => 'User Books',
        'public'            => false,   // no public archive/single; shown via our UI
        'show_ui'           => false,   // not in wp-admin — frontend only
        'show_in_rest'      => false,
        'hierarchical'      => false,
        'supports'          => ['title', 'author'],
        'capability_type'   => ['user_book', 'user_books'],
        'map_meta_cap'      => true,     // enforce owner (own vs others) by post_author
        'capabilities'      => ['create_posts' => 'edit_user_books'],
    ]);
}

// Own-book caps (apply to member, editor, admin).
function ub_own_caps()
{
    return [
        'edit_user_books',
        'edit_published_user_books',
        'publish_user_books',
        'delete_user_books',
        'delete_published_user_books',
    ];
}

// Cross-owner caps (admin only).
function ub_others_caps()
{
    return [
        'edit_others_user_books',
        'delete_others_user_books',
        'read_private_user_books',
    ];
}

add_action('init', 'ub_setup_roles', 6);
function ub_setup_roles()
{
    if (get_option('ub_roles_version') === UB_ROLES_VER) {
        return;
    }

    // The "member" role: a logged-in user who only manages their own books.
    add_role('member', 'Member', ['read' => true]);
    $member = get_role('member');
    if ($member) {
        $member->add_cap('read');
        foreach (ub_own_caps() as $cap) {
            $member->add_cap($cap);
        }
    }

    // Editors (contributing users) also get their own personal books.
    $editor = get_role('editor');
    if ($editor) {
        foreach (ub_own_caps() as $cap) {
            $editor->add_cap($cap);
        }
    }

    // Admin: everything, incl. managing anyone's books.
    $admin = get_role('administrator');
    if ($admin) {
        foreach (array_merge(ub_own_caps(), ub_others_caps()) as $cap) {
            $admin->add_cap($cap);
        }
    }

    update_option('ub_roles_version', UB_ROLES_VER);
}

/* ------------------------------------------------------------------ *
 * Data helpers
 * ------------------------------------------------------------------ */

/**
 * Ordered members of a book as ['pt' => 'post'|'set', 'id' => int] entries.
 * Silently drops entries whose post no longer exists.
 */
function ub_get_items($book_id)
{
    $raw = get_post_meta($book_id, UB_ITEMS_META, true);
    $list = is_array($raw) ? $raw : (is_string($raw) && $raw !== '' ? json_decode($raw, true) : []);
    if (!is_array($list)) {
        $list = [];
    }

    $out = [];
    foreach ($list as $tok) {
        if (!is_string($tok) || strpos($tok, ':') === false) {
            continue;
        }
        [$pt, $id] = explode(':', $tok, 2);
        $id = (int) $id;
        if (($pt === 'post' || $pt === 'set') && $id && get_post_status($id)) {
            $out[] = ['pt' => $pt, 'id' => $id];
        }
    }
    return $out;
}

/**
 * Persist an items array (['pt','id'] entries) back onto a book.
 */
function ub_save_items($book_id, array $items)
{
    $tokens = [];
    foreach ($items as $it) {
        $pt = ($it['pt'] === 'set') ? 'set' : 'post';
        $id = (int) $it['id'];
        if ($id) {
            $tokens[] = $pt . ':' . $id;
        }
    }
    update_post_meta($book_id, UB_ITEMS_META, wp_json_encode(array_values(array_unique($tokens))));
}

/**
 * All books owned by a user (newest first).
 */
function ub_user_books($user_id)
{
    return get_posts([
        'post_type'   => UB_POST_TYPE,
        'post_status' => 'publish',
        'author'      => (int) $user_id,
        'numberposts' => -1,
        'orderby'     => 'date',
        'order'       => 'DESC',
    ]);
}

/**
 * True if the current user may edit this specific book (owner or admin).
 * Relies on map_meta_cap -> edit_user_books / edit_others_user_books.
 */
function ub_can_edit($book_id)
{
    $book_id = (int) $book_id;
    return $book_id
        && get_post_type($book_id) === UB_POST_TYPE
        && current_user_can('edit_post', $book_id);
}

/**
 * Validate that an item is a real tune (post + tune_uid) or a real set.
 */
function ub_valid_item($pt, $id)
{
    $id = (int) $id;
    if (!$id) {
        return false;
    }
    if ($pt === 'set') {
        return get_post_type($id) === 'set';
    }
    if ($pt === 'post') {
        return get_post_type($id) === 'post'
            && get_post_meta($id, TUNE_UID_META, true) !== '';
    }
    return false;
}

/**
 * Human label + title for an item, for the UI.
 */
function ub_item_label($pt, $id)
{
    return [
        'pt'    => $pt,
        'id'    => (int) $id,
        'title' => get_the_title($id),
        'kind'  => $pt === 'set' ? 'Set' : 'Tune',
        'url'   => get_permalink($id),
    ];
}

/* ------------------------------------------------------------------ *
 * Overview integration: personal books in the tunebook filter dropdown
 * ------------------------------------------------------------------ */

/**
 * Map of "pt:id" -> [book_id, ...] for the current user's personal books.
 * Built once per request (static cache) so the overview can tag rows cheaply.
 */
function ub_current_user_membership()
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    if (!is_user_logged_in()) {
        return $map;
    }
    foreach (ub_user_books(get_current_user_id()) as $b) {
        foreach (ub_get_items($b->ID) as $it) {
            $map[$it['pt'] . ':' . $it['id']][] = (int) $b->ID;
        }
    }
    return $map;
}

/**
 * Space-separated ids of the current user's books that contain this item, for a
 * row's data-userbooks attribute. Empty when in none.
 */
function ub_item_userbooks_attr($pt, $id)
{
    $map = ub_current_user_membership();
    $key = (($pt === 'set') ? 'set' : 'post') . ':' . (int) $id;
    return isset($map[$key]) ? implode(' ', $map[$key]) : '';
}

/**
 * Append a "My Books" optgroup (value = ub:<id>) to the tunebook filter dropdown.
 */
add_action('tunebook_filter_mybooks', 'ub_filter_dropdown_options');
function ub_filter_dropdown_options()
{
    if (!is_user_logged_in()) {
        return;
    }
    $books = ub_user_books(get_current_user_id());
    if (!$books) {
        return;
    }
    echo '<optgroup label="My Books">';
    foreach ($books as $b) {
        printf('<option value="ub:%d">%s</option>', (int) $b->ID, esc_html($b->post_title));
    }
    echo '</optgroup>';
}

/* ------------------------------------------------------------------ *
 * admin-post handlers (create / rename / delete) — page reloads
 * ------------------------------------------------------------------ */

function ub_manager_url()
{
    // Personal-book management now lives on the Books page (My Books merged in).
    $page = get_page_by_path('books');
    return $page ? get_permalink($page) : home_url('/books/');
}

/**
 * Books-page URL for a specific personal book (?book=ub:<id>), so post-action
 * redirects land back on the book the user was working with.
 */
function ub_book_url($book_id)
{
    return $book_id
        ? add_query_arg('book', 'ub:' . (int) $book_id, ub_manager_url())
        : ub_manager_url();
}

/**
 * Stateless share token for a book, derived from the site's secret salts. Only
 * the server can mint/verify it, so books can't be cloned by iterating ids.
 * Stable per book (not revocable — acceptable for this small site).
 */
function ub_share_token($book_id)
{
    return wp_hash('ub_share_' . (int) $book_id);
}

/**
 * Shareable URL for a book: the My Books page with ?share=<id>&t=<token>.
 * Opening it (logged in) offers to copy the book into the visitor's account.
 */
function ub_share_url($book_id)
{
    return add_query_arg(
        ['share' => (int) $book_id, 't' => ub_share_token($book_id)],
        ub_manager_url()
    );
}

add_action('admin_post_ub_create', 'ub_handle_create');
function ub_handle_create()
{
    if (!is_user_logged_in() || !current_user_can('edit_user_books')) {
        wp_die('forbidden', 403);
    }
    check_admin_referer('ub_create');

    $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
    $new_id = 0;
    if ($name !== '') {
        $new_id = wp_insert_post([
            'post_title'  => $name,
            'post_type'   => UB_POST_TYPE,
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
        ]);
    }
    wp_safe_redirect(ub_book_url(is_wp_error($new_id) ? 0 : (int) $new_id));
    exit;
}

add_action('admin_post_ub_rename', 'ub_handle_rename');
function ub_handle_rename()
{
    $book_id = isset($_POST['book_id']) ? (int) $_POST['book_id'] : 0;
    check_admin_referer('ub_rename_' . $book_id);
    if (!ub_can_edit($book_id)) {
        wp_die('forbidden', 403);
    }
    $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
    if ($name !== '') {
        wp_update_post(['ID' => $book_id, 'post_title' => $name]);
    }
    wp_safe_redirect(ub_book_url($book_id));
    exit;
}

add_action('admin_post_ub_delete', 'ub_handle_delete');
function ub_handle_delete()
{
    $book_id = isset($_POST['book_id']) ? (int) $_POST['book_id'] : 0;
    check_admin_referer('ub_delete_' . $book_id);
    if (!ub_can_edit($book_id)) {
        wp_die('forbidden', 403);
    }
    wp_delete_post($book_id, true);
    wp_safe_redirect(ub_manager_url());
    exit;
}

/**
 * Copy a shared book into the current user's account (from a share link).
 * Validates the stateless share token, then creates a new user_book owned by
 * the visitor with the same title and item refs — an independent copy.
 */
add_action('admin_post_ub_clone', 'ub_handle_clone');
function ub_handle_clone()
{
    if (!is_user_logged_in() || !current_user_can('edit_user_books')) {
        wp_die('forbidden', 403);
    }
    $src_id = isset($_POST['src']) ? (int) $_POST['src'] : 0;
    check_admin_referer('ub_clone_' . $src_id);

    $token = isset($_POST['t']) ? sanitize_text_field(wp_unslash($_POST['t'])) : '';
    if (!$src_id
        || get_post_type($src_id) !== UB_POST_TYPE
        || !hash_equals(ub_share_token($src_id), $token)) {
        wp_die('invalid share link', 403);
    }

    $src   = get_post($src_id);
    $title = ($src && $src->post_title !== '') ? $src->post_title : 'Shared book';
    $new_id = wp_insert_post([
        'post_title'  => $title,
        'post_type'   => UB_POST_TYPE,
        'post_status' => 'publish',
        'post_author' => get_current_user_id(),
    ]);
    if ($new_id && !is_wp_error($new_id)) {
        // ub_get_items already drops refs whose tune/set no longer exists.
        ub_save_items($new_id, ub_get_items($src_id));
    }
    wp_safe_redirect(ub_book_url(is_wp_error($new_id) ? 0 : (int) $new_id));
    exit;
}

/* ------------------------------------------------------------------ *
 * AJAX (search / add / remove item) — no reload
 * ------------------------------------------------------------------ */

add_action('wp_ajax_ub_search', 'ub_ajax_search');
function ub_ajax_search()
{
    if (!current_user_can('edit_user_books')) {
        wp_send_json_error('forbidden', 403);
    }
    check_ajax_referer('ub_ajax');

    $q = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
    if (strlen($q) < 2) {
        wp_send_json_success([]);
    }

    $results = [];
    // tunes (real: with tune_uid) + sets, title match
    $tunes = get_posts([
        'post_type'   => 'post',
        'numberposts' => 10,
        's'           => $q,
        'meta_query'  => [['key' => TUNE_UID_META, 'value' => '', 'compare' => '!=']],
    ]);
    foreach ($tunes as $p) {
        $results[] = ub_item_label('post', $p->ID);
    }
    $sets = get_posts([
        'post_type'   => 'set',
        'numberposts' => 10,
        's'           => $q,
    ]);
    foreach ($sets as $p) {
        $results[] = ub_item_label('set', $p->ID);
    }

    wp_send_json_success($results);
}

add_action('wp_ajax_ub_add_item', 'ub_ajax_add_item');
function ub_ajax_add_item()
{
    check_ajax_referer('ub_ajax');
    $book_id = isset($_POST['book_id']) ? (int) $_POST['book_id'] : 0;
    $pt      = isset($_POST['pt']) && $_POST['pt'] === 'set' ? 'set' : 'post';
    $id      = isset($_POST['id']) ? (int) $_POST['id'] : 0;

    if (!ub_can_edit($book_id)) {
        wp_send_json_error('forbidden', 403);
    }
    if (!ub_valid_item($pt, $id)) {
        wp_send_json_error('invalid item');
    }

    $items = ub_get_items($book_id);
    foreach ($items as $it) {
        if ($it['pt'] === $pt && $it['id'] === $id) {
            wp_send_json_success(['already' => true, 'item' => ub_item_label($pt, $id)]);
        }
    }
    $items[] = ['pt' => $pt, 'id' => $id];
    ub_save_items($book_id, $items);

    wp_send_json_success(['item' => ub_item_label($pt, $id), 'count' => count($items)]);
}

add_action('wp_ajax_ub_remove_item', 'ub_ajax_remove_item');
function ub_ajax_remove_item()
{
    check_ajax_referer('ub_ajax');
    $book_id = isset($_POST['book_id']) ? (int) $_POST['book_id'] : 0;
    $pt      = isset($_POST['pt']) && $_POST['pt'] === 'set' ? 'set' : 'post';
    $id      = isset($_POST['id']) ? (int) $_POST['id'] : 0;

    if (!ub_can_edit($book_id)) {
        wp_send_json_error('forbidden', 403);
    }

    $items = array_values(array_filter(ub_get_items($book_id), function ($it) use ($pt, $id) {
        return !($it['pt'] === $pt && $it['id'] === $id);
    }));
    ub_save_items($book_id, $items);

    wp_send_json_success(['count' => count($items)]);
}

/**
 * Bulk-add many items of one type (from the bookmark selection) to a book.
 * Additive + deduped; reports how many were added / already present / invalid.
 */
/**
 * Add many items of one type to a book, additive + deduped. Pure (no request
 * state), so it's unit-testable. Returns counts.
 *
 * @return array{added:int, already:int, invalid:int, count:int}
 */
function ub_add_items_bulk($book_id, $pt, array $ids)
{
    $pt       = ($pt === 'set') ? 'set' : 'post';
    $items    = ub_get_items($book_id);
    $existing = [];
    foreach ($items as $it) {
        $existing[$it['pt'] . ':' . $it['id']] = true;
    }

    $added = 0;
    $already = 0;
    $invalid = 0;
    foreach (array_unique(array_map('intval', $ids)) as $id) {
        if (!ub_valid_item($pt, $id)) {
            $invalid++;
            continue;
        }
        $key = $pt . ':' . $id;
        if (isset($existing[$key])) {
            $already++;
            continue;
        }
        $items[] = ['pt' => $pt, 'id' => $id];
        $existing[$key] = true;
        $added++;
    }
    ub_save_items($book_id, $items);

    return ['added' => $added, 'already' => $already, 'invalid' => $invalid, 'count' => count($items)];
}

add_action('wp_ajax_ub_add_bulk', 'ub_ajax_add_bulk');
function ub_ajax_add_bulk()
{
    check_ajax_referer('ub_ajax');
    $book_id = isset($_POST['book_id']) ? (int) $_POST['book_id'] : 0;
    $pt      = isset($_POST['pt']) && $_POST['pt'] === 'set' ? 'set' : 'post';
    $ids     = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];

    if (!ub_can_edit($book_id)) {
        wp_send_json_error('forbidden', 403);
    }

    wp_send_json_success(ub_add_items_bulk($book_id, $pt, $ids));
}

/**
 * Render a "add bookmarked <type>s to [book]" bar for the Tunes / Sets overview.
 * Only for logged-in users who may manage personal books. $item_type = tune|set.
 */
function ub_bulk_bar($item_type)
{
    if (!is_user_logged_in() || !current_user_can('edit_user_books')) {
        return;
    }
    $item_type = ($item_type === 'set') ? 'set' : 'tune';
    $books     = ub_user_books(get_current_user_id());
    ?>
    <details class="ub-bulkbar container py-2"
         data-type="<?php echo esc_attr($item_type); ?>"
         data-nonce="<?php echo esc_attr(wp_create_nonce('ub_ajax')); ?>"
         data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
        <summary class="text-muted small" style="cursor:pointer">Add bookmarked <?php echo esc_html($item_type); ?>s to a book…</summary>
        <div class="ub-bulkbar-body pt-2">
        <?php if (!$books) : ?>
            <span class="text-muted">Create a book in
                <a href="<?php echo esc_url(ub_manager_url()); ?>">My Books</a>
                to add bookmarked <?php echo esc_html($item_type); ?>s here.</span>
        <?php else : ?>
            <select class="ub-book-target form-select form-select-sm d-inline-block" style="width:auto">
                <?php foreach ($books as $b) : ?>
                    <option value="<?php echo (int) $b->ID; ?>"><?php echo esc_html($b->post_title); ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-primary ub-add-bookmarked ms-1">Add</button>
            <span class="ub-bulk-status ms-2"></span>
        <?php endif; ?>
        <?php if ($item_type === 'tune' && current_user_can('edit_posts')) : ?>
            <span class="ms-3 me-1">or new set</span>
            <input class="form-control form-control-sm d-inline-block ub-new-set-title" style="width:130px" type="text" placeholder="Name">
            <button class="btn btn-sm btn-primary ub-add-bookmarked-set ms-1">Add</button>
            <span class="ub-bulk-set-status ms-2"></span>
        <?php endif; ?>
        </div>
    </details>
    <?php
}

/* ------------------------------------------------------------------ *
 * Frontend manager (rendered by page-my-books.php)
 * ------------------------------------------------------------------ */

/**
 * When someone opens a share link (?share=<id>&t=<token>), show a card offering
 * to copy that book into their own account, rendered above the normal manager.
 * Returns true if it rendered, so the caller can suppress the generic
 * "please log in" message and rely on the login CTA here instead.
 */
function ub_render_share_prompt($src_id)
{
    $book = get_post($src_id);
    if (!$book) {
        return false;
    }
    $author = get_the_author_meta('display_name', $book->post_author);
    $author = $author !== '' ? $author : 'a member';
    $count  = count(ub_get_items($src_id));
    ?>
    <div class="container mt-5" style="max-width:640px">
        <div class="card border-primary">
            <div class="card-body">
                <h2 class="h5 mb-1">Shared book: <?php echo esc_html($book->post_title); ?></h2>
                <p class="text-muted mb-3">
                    Shared by <?php echo esc_html($author); ?> —
                    <?php echo (int) $count; ?> item<?php echo $count === 1 ? '' : 's'; ?>.
                    Adding it makes your own independent copy.
                </p>
                <?php if (!is_user_logged_in()) : ?>
                    <a class="btn btn-primary" href="<?php echo esc_url(wp_login_url(ub_share_url($src_id))); ?>">
                        Log in to add it to your books</a>
                <?php elseif (!current_user_can('edit_user_books')) : ?>
                    <p class="mb-0">Your account cannot keep personal books.</p>
                <?php elseif ((int) $book->post_author === get_current_user_id()) : ?>
                    <p class="mb-0">This is your own book — it's already below.</p>
                <?php else : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="ub_clone">
                        <input type="hidden" name="src" value="<?php echo (int) $src_id; ?>">
                        <input type="hidden" name="t" value="<?php echo esc_attr(ub_share_token($src_id)); ?>">
                        <?php wp_nonce_field('ub_clone_' . $src_id); ?>
                        <button class="btn btn-primary">Add a copy to my books</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
    return true;
}

function ub_render_manager()
{
    // Arriving via a share link? Offer to copy that book in (renders above).
    $shared = false;
    if (isset($_GET['share'], $_GET['t'])) {
        $src_id = (int) $_GET['share'];
        if ($src_id
            && get_post_type($src_id) === UB_POST_TYPE
            && hash_equals(ub_share_token($src_id), sanitize_text_field(wp_unslash($_GET['t'])))) {
            $shared = ub_render_share_prompt($src_id) === true;
        }
    }

    if (!is_user_logged_in()) {
        if (!$shared) {
            printf(
                '<div class="container my-5"><p>Please <a href="%s">log in</a> to manage your books.</p></div>',
                esc_url(wp_login_url(get_permalink()))
            );
        }
        return;
    }
    if (!current_user_can('edit_user_books')) {
        echo '<div class="container my-5"><p>Your account cannot manage personal books.</p></div>';
        return;
    }

    $books    = ub_user_books(get_current_user_id());
    $post_url = admin_url('admin-post.php');
    ?>
    <div class="container my-5" id="ub-manager">
        <h1>My Books</h1>

        <?php if (function_exists('help_page_url')) : ?>
            <p class="text-muted">
                New here? See the <a href="<?php echo esc_url(help_page_url()); ?>">Help page</a>
                for how books, bookmarks and filters work.
            </p>
        <?php endif; ?>

        <?php ub_render_create_form($post_url); ?>

        <div class="alert alert-info py-2" role="note">
            <strong>Tip — add many at once:</strong> on the
            <a href="<?php echo esc_url(home_url('/tunes')); ?>">Tunes</a> and
            <a href="<?php echo esc_url(home_url('/sets')); ?>">Sets</a> pages, bookmark
            several with the ★, then use the <em>“Add bookmarked … to [book]”</em> bar
            at the top to add them all to a book in one go. Below you can also add
            single tunes/sets by searching.
        </div>

        <?php if (!$books) : ?>
            <p class="text-muted">No books yet. Create one above.</p>
        <?php endif; ?>

        <?php foreach ($books as $book) : ub_render_book_card($book, $post_url); endforeach; ?>
    </div>
    <?php
    ub_manager_assets();
}

/**
 * The "create a new book" form. Shared by the My Books manager and the Books
 * page so users can make a personal book from either place.
 */
function ub_render_create_form($post_url = null)
{
    $post_url = $post_url ?: admin_url('admin-post.php');
    ?>
    <form method="post" action="<?php echo esc_url($post_url); ?>" class="row g-2 my-3" style="max-width:520px">
        <input type="hidden" name="action" value="ub_create">
        <?php wp_nonce_field('ub_create'); ?>
        <div class="col">
            <input type="text" name="name" class="form-control" placeholder="New book name" required>
        </div>
        <div class="col-auto">
            <button class="btn btn-primary">Create book</button>
        </div>
    </form>
    <?php
}

/**
 * Render one personal-book card: rename / share / delete, item list and
 * type-to-search add. The generic JS in ub_manager_assets() wires every
 * .ub-book on the page, so this works both in the My Books list and standalone
 * on the Books page. $open renders the body expanded (used when the Books page
 * shows a single selected book).
 */
function ub_render_book_card($book, $post_url = null, $open = false)
{
    $post_url = $post_url ?: admin_url('admin-post.php');
    $items = ub_get_items($book->ID);
    $del_nonce = wp_create_nonce('ub_ajax');
    $del_ajax  = admin_url('admin-ajax.php');
    ?>
            <div class="ub-book card my-3<?php echo $open ? ' is-open' : ''; ?>" data-book="<?php echo (int) $book->ID; ?>">
                <div class="card-body">
                    <div class="ub-book-head d-flex justify-content-between align-items-center"
                         role="button" tabindex="0" aria-expanded="<?php echo $open ? 'true' : 'false'; ?>"
                         title="Click to show or hide this book's tunes">
                        <h2 class="h6 mb-0">
                            <span class="ub-caret me-1" aria-hidden="true">▸</span>
                            <?php echo esc_html($book->post_title); ?>
                        </h2>
                        <span class="text-muted small ms-2">
                            <span class="ub-count"><?php echo count($items); ?></span>&nbsp;item<?php echo count($items) === 1 ? '' : 's'; ?>
                        </span>
                    </div>

                    <div class="ub-book-body mt-3"<?php echo $open ? '' : ' hidden'; ?>>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <form method="post" action="<?php echo esc_url($post_url); ?>" class="d-flex align-items-center gap-2">
                            <input type="hidden" name="action" value="ub_rename">
                            <input type="hidden" name="book_id" value="<?php echo (int) $book->ID; ?>">
                            <?php wp_nonce_field('ub_rename_' . $book->ID); ?>
                            <input type="text" name="name" class="form-control form-control-sm" value="<?php echo esc_attr($book->post_title); ?>" style="max-width:280px">
                            <button class="btn btn-sm btn-outline-secondary">Rename</button>
                        </form>
                        <form method="post" action="<?php echo esc_url($post_url); ?>" onsubmit="return confirm('Delete this book?');">
                            <input type="hidden" name="action" value="ub_delete">
                            <input type="hidden" name="book_id" value="<?php echo (int) $book->ID; ?>">
                            <?php wp_nonce_field('ub_delete_' . $book->ID); ?>
                            <button class="btn btn-sm btn-outline-danger">Delete book</button>
                        </form>
                    </div>

                    <div class="ub-share mb-2">
                        <button type="button" class="btn btn-sm btn-outline-primary ub-share-btn"
                                title="Get a link to this book that you can send to someone else"
                                data-url="<?php echo esc_attr(ub_share_url($book->ID)); ?>">Share…</button>
                        <div class="ub-share-box mt-2" hidden>
                            <div class="d-flex align-items-center gap-1" style="max-width:440px">
                                <input type="text" class="form-control form-control-sm" readonly value=""
                                       title="Link to this book — copy it and send it to someone">
                                <button type="button" class="btn btn-sm btn-outline-secondary ub-share-copy">Copy</button>
                            </div>
                            <small class="text-muted d-block mt-1">
                                Send this link to someone — when they open it and log in, they can add
                                their own copy of this book to their account. Your book stays private
                                and unchanged.
                            </small>
                        </div>
                    </div>

                    <ul class="ub-items list-group mb-2">
                        <?php foreach ($items as $it) :
                            $lbl = ub_item_label($it['pt'], $it['id']); ?>
                            <li class="list-group-item p-0 swipe-del"
                                data-del-action="ub_remove_item"
                                data-del-book="<?php echo (int) $book->ID; ?>"
                                data-del-pt="<?php echo esc_attr($it['pt']); ?>"
                                data-del-id="<?php echo (int) $it['id']; ?>"
                                data-del-nonce="<?php echo esc_attr($del_nonce); ?>"
                                data-del-ajax="<?php echo esc_url($del_ajax); ?>">
                                <div class="swipe-del-content d-flex justify-content-between align-items-center p-2">
                                    <span>
                                        <span class="badge bg-secondary me-2"><?php echo esc_html($lbl['kind']); ?></span>
                                        <a href="<?php echo esc_url(get_permalink($it['id'])); ?>"><?php echo esc_html($lbl['title']); ?></a>
                                    </span>
                                </div>
                                <?php echo isg_swipe_del_btn('Remove from book'); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <div class="ub-add position-relative" style="max-width:420px">
                        <input type="text" class="form-control ub-search" placeholder="Add a tune or set — type to search…" autocomplete="off">
                        <div class="ub-results list-group position-absolute w-100" style="z-index:5"></div>
                    </div>
                    </div><!-- .ub-book-body -->
                </div>
            </div>
    <?php
}

/**
 * Output the shared CSS + JS that drives every .ub-book card on the page
 * (collapsible head, share box, live search-add, remove). Idempotent — safe to
 * call from both the My Books manager and the Books page.
 */
function ub_manager_assets()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $nonce    = wp_create_nonce('ub_ajax');
    $ajax_url = admin_url('admin-ajax.php');
    ?>
    <style>
        .ub-book-head { cursor: pointer; }
        .ub-book-head:hover h2 { text-decoration: underline; }
        .ub-caret { display: inline-block; transition: transform .15s ease; }
        .ub-book.is-open .ub-caret { transform: rotate(90deg); }
    </style>

    <script>
        (function () {
            const ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;
            const nonce = <?php echo wp_json_encode($nonce); ?>;

            async function post(action, data) {
                const body = new URLSearchParams(Object.assign({ action, _wpnonce: nonce }, data));
                const res = await fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body });
                const json = await res.json();
                if (!json.success) throw new Error(json.data || 'error');
                return json.data;
            }

            // Trash button markup — must match isg_swipe_del_btn() in PHP so newly
            // added items behave like server-rendered ones (shared handler in main.js).
            const SWIPE_BTN = '<button type="button" class="swipe-del-btn" aria-label="Remove from book" title="Remove from book"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16"><path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0z"/><path d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4zM2.5 3h11V2h-11z"/></svg></button>';

            function itemLi(item, bookId) {
                const li = document.createElement('li');
                li.className = 'list-group-item p-0 swipe-del';
                li.dataset.delAction = 'ub_remove_item';
                li.dataset.delBook = bookId;
                li.dataset.delPt = item.pt;
                li.dataset.delId = item.id;
                li.dataset.delNonce = nonce;
                li.dataset.delAjax = ajaxUrl;
                const content = document.createElement('div');
                content.className = 'swipe-del-content d-flex justify-content-between align-items-center p-2';
                const span = document.createElement('span');
                const badge = document.createElement('span');
                badge.className = 'badge bg-secondary me-2';
                badge.textContent = item.kind;
                const a = document.createElement('a');
                a.href = item.url; a.textContent = item.title;
                span.appendChild(badge);
                span.appendChild(document.createTextNode(' '));
                span.appendChild(a);
                content.appendChild(span);
                li.appendChild(content);
                li.insertAdjacentHTML('beforeend', SWIPE_BTN);
                return li;
            }

            document.querySelectorAll('.ub-book').forEach(function (bookEl) {
                const bookId = bookEl.dataset.book;
                const search = bookEl.querySelector('.ub-search');
                const results = bookEl.querySelector('.ub-results');
                const list = bookEl.querySelector('.ub-items');
                const countEl = bookEl.querySelector('.ub-count');
                function updateCount() { if (countEl) countEl.textContent = list.children.length; }

                let timer = null;
                search.addEventListener('input', function () {
                    clearTimeout(timer);
                    const q = search.value.trim();
                    if (q.length < 2) { results.innerHTML = ''; return; }
                    timer = setTimeout(async function () {
                        try {
                            const rows = await post('ub_search', { q });
                            results.innerHTML = '';
                            rows.forEach(function (r) {
                                const a = document.createElement('button');
                                a.type = 'button';
                                a.className = 'list-group-item list-group-item-action';
                                a.textContent = r.kind + ': ' + r.title;
                                a.addEventListener('click', async function () {
                                    try {
                                        const d = await post('ub_add_item', { book_id: bookId, pt: r.pt, id: r.id });
                                        if (!d.already) { list.appendChild(itemLi(d.item, bookId)); updateCount(); }
                                        results.innerHTML = ''; search.value = '';
                                    } catch (e) { alert(e.message); }
                                });
                                results.appendChild(a);
                            });
                        } catch (e) { /* ignore */ }
                    }, 250);
                });

                document.addEventListener('click', function (e) {
                    if (!bookEl.contains(e.target)) results.innerHTML = '';
                });
                // Item removal (swipe / hover trash) is handled globally in main.js.
            });

            // Collapsible books: click the header to expand/collapse its contents.
            document.querySelectorAll('.ub-book-head').forEach(function (head) {
                const bookEl = head.closest('.ub-book');
                const body = bookEl.querySelector('.ub-book-body');
                function toggle() {
                    const opening = body.hidden;
                    body.hidden = !opening;
                    bookEl.classList.toggle('is-open', opening);
                    head.setAttribute('aria-expanded', opening ? 'true' : 'false');
                }
                head.addEventListener('click', toggle);
                head.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); }
                });
            });

            document.querySelectorAll('.ub-share-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const box = btn.parentElement.querySelector('.ub-share-box');
                    const input = box.querySelector('input');
                    input.value = btn.dataset.url;
                    box.hidden = !box.hidden;
                    if (!box.hidden) { input.focus(); input.select(); }
                });
            });
            document.querySelectorAll('.ub-share-copy').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const input = btn.closest('.ub-share-box').querySelector('input');
                    input.focus();
                    input.select();
                    input.setSelectionRange(0, input.value.length);
                    // execCommand works on plain-http local sites; navigator.clipboard
                    // only exists in secure contexts (https / real localhost).
                    let ok = false;
                    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
                    if (!ok && navigator.clipboard) {
                        navigator.clipboard.writeText(input.value).catch(function () {});
                        ok = true;
                    }
                    const label = btn.textContent;
                    btn.textContent = ok ? 'Copied!' : 'Press Ctrl+C';
                    setTimeout(function () { btn.textContent = label; }, 1500);
                });
            });
        })();
    </script>
    <?php
}
