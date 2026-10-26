<?php

/**
 * Help / user manual — backlog #5 (Hilfe-Seite als Login-Landeseite).
 *
 * Two things in one feature:
 *   1. help_render() — a role-aware user manual, shown by page-help.php on a Page
 *      with slug `help`.
 *   2. login_redirect — non-admins (members + editors) land on that Help page right
 *      after login instead of the wp-admin dashboard.
 *
 * The content is gated by capability so each role only sees what applies to it:
 *   - everyone logged in (member+)   : books, bookmarks, filters, login/logout
 *   - editors  (edit_posts)          : + add tunes/sets, curate global tunebooks
 *   - editors+admins (edit_others_posts) : + Tools -> Tune Sync (backfill/export/import)
 */

/* ------------------------------------------------------------------ *
 * URL + login redirect
 * ------------------------------------------------------------------ */

/**
 * Permalink of the Help page (Page with slug `help`). Falls back to the site
 * home while that page has not been created yet on a given site, so the login
 * redirect never lands on a 404.
 */
function help_page_url()
{
    $page = get_page_by_path('help');
    return $page ? get_permalink($page) : home_url('/');
}

/**
 * Send members + editors to the Help page after login; admins keep the default
 * (dashboard). An explicit, non-admin requested redirect is honoured — e.g. a
 * member who clicked "log in" from My Books still ends up on My Books.
 */
add_filter('login_redirect', 'help_login_redirect', 10, 3);
function help_login_redirect($redirect_to, $requested, $user)
{
    if (!($user instanceof WP_User) || user_can($user, 'manage_options')) {
        return $redirect_to; // admins: unchanged
    }
    $requested = is_string($requested) ? $requested : '';
    if ($requested === '' || strpos($requested, 'wp-admin') !== false) {
        return help_page_url();
    }
    return $redirect_to;
}

/* ------------------------------------------------------------------ *
 * Content (rendered by page-help.php)
 * ------------------------------------------------------------------ */

function help_render()
{
    $tunes_url = esc_url(home_url('/tunes'));
    $sets_url  = esc_url(home_url('/sets'));
    $books_url = function_exists('ub_manager_url') ? esc_url(ub_manager_url()) : esc_url(home_url('/my-books/'));
    $tb_url    = esc_url(home_url('/tunebooks'));
    $add_url   = esc_url(home_url('/add-tune'));
    ?>
    <div class="container my-5" id="help-page" style="max-width:820px">
        <h1>Help</h1>

        <?php if (!is_user_logged_in()) : ?>
            <p class="lead">This is the user manual for the Carinthia Tunebook. Please
                <a href="<?php echo esc_url(wp_login_url(help_page_url())); ?>">log in</a>
                to see the parts that apply to your account.</p>
        <?php endif; ?>

        <p class="lead">The site is a shared collection of Irish tunes and sets. You can
            browse everything without an account; logging in lets you collect tunes and
            sets into your own personal books.</p>

        <?php if (is_user_logged_in()) :
            $user       = wp_get_current_user();
            $role_names = wp_roles()->get_names();
            $roles      = array_map(function ($r) use ($role_names) {
                return translate_user_role($role_names[$r] ?? $r);
            }, (array) $user->roles);
            ?>
            <p class="text-muted">
                You are logged in as <strong><?php echo esc_html($user->display_name); ?></strong>
                (<?php echo esc_html($user->user_login); ?>) —
                role: <strong><?php echo esc_html($roles ? implode(', ', $roles) : 'none'); ?></strong>.
            </p>
        <?php endif; ?>

        <h2 class="mt-4">Browsing</h2>
        <ul>
            <li><a href="<?php echo $tunes_url; ?>">Tunes</a> and
                <a href="<?php echo $sets_url; ?>">Sets</a> list everything. Use the filter
                bar at the top to narrow by type, tag or tunebook — filtering happens live,
                without reloading the page.</li>
            <li><a href="<?php echo $tb_url; ?>">Tunebooks</a> are curated collections
                (e.g. <em>Graz-Session</em>). Opening a book shows just its tunes and sets.</li>
        </ul>

        <?php if (is_user_logged_in()) : ?>
            <h2 class="mt-4">Bookmarks &amp; your personal books</h2>
            <p>Once logged in you get two things: a private bookmark list and your own
                <strong>personal books</strong>. Personal books are yours alone — separate
                from the global tunebooks above, so they never collide with anyone else's.</p>
            <ul>
                <li><strong>Bookmark</strong> any tune or set with the ★ button on its row.
                    The ★ switch in the filter bar then narrows the list to just your
                    bookmarks.</li>
                <li>On <a href="<?php echo $books_url; ?>">My Books</a> you create books and
                    add tunes/sets to them — either one at a time by searching, or many at
                    once: bookmark several on the <a href="<?php echo $tunes_url; ?>">Tunes</a>
                    / <a href="<?php echo $sets_url; ?>">Sets</a> page, then use the
                    <em>“Add bookmarked … to [book]”</em> bar at the top of that page.</li>
                <li>Your personal books also appear in the tunebook filter dropdown (under
                    <em>My Books</em>), so you can view one of your own collections just like
                    a global book.</li>
                <li><strong>Share a book:</strong> click <em>Share…</em> on any of your books
                    in <a href="<?php echo $books_url; ?>">My Books</a> to get a link. Send it
                    to anyone — when they open it (and log in) they can add their own
                    <strong>independent copy</strong> with one click. Your original stays
                    private and unchanged, and later edits on either side don't affect the
                    other.</li>
                <li><strong>Login / Logout</strong> live at the top-right of every page.</li>
            </ul>
        <?php endif; ?>

        <?php if (current_user_can('edit_posts')) : ?>
            <h2 class="mt-4">For contributors (editors)</h2>
            <p>As an editor you can add content and curate the shared, global tunebooks —
                these changes are visible to everyone.</p>
            <ul>
                <li><strong>Add tunes &amp; sets</strong> on <a href="<?php echo $add_url; ?>">Add
                    Tune/Set</a>: enter one or more thesession.org tune ids (comma-separated
                    creates a set automatically), optionally with settings, tags and tunebooks.</li>
                <li><strong>Global tunebooks:</strong> assign tunes/sets to existing books, or
                    create new ones right on the Add Tune/Set form (the “New book(s)” field).</li>
            </ul>
        <?php endif; ?>

        <?php if (function_exists('tune_sync_cap') && current_user_can(tune_sync_cap())) : ?>
            <h2 class="mt-4">Maintenance tools (editors &amp; admins)</h2>
            <p>The sync tools live in <strong>wp-admin → Tools → Tune Sync</strong>:</p>
            <ul>
                <li><strong>tune_uid backfill</strong> — assigns every tune/set its stable key;
                    idempotent and batched (no CLI needed).</li>
                <li><strong>Export</strong> a ZIP of all tunes/sets (or a single tunebook), and
                    <strong>Import</strong> such a ZIP — additive upsert keyed by
                    <code>tune_uid</code>/<code>set_uid</code>, so re-importing is safe.</li>
            </ul>
        <?php endif; ?>
    </div>
    <?php
}
