<?php

/**
 * Books — one page to browse AND manage books (replaces the old separate
 * "My Books" menu entry, which is merged in here). A dropdown picks any
 * accessible book:
 *   - a public tunebook (e.g. KTN-Session)  -> shows its sets & tunes
 *   - one of your own personal books        -> shows its items + the
 *     rename / share / delete / add controls (reused from the manager)
 *
 * Reload-based (?book=<slug|ub:ID>): only the chosen book is rendered, so a
 * 140-tune session book isn't built until it's actually selected. Assign this
 * template to a Page with slug "books".
 */

get_header();

$sel = isset($_GET['book']) ? sanitize_text_field(wp_unslash($_GET['book'])) : '';

$public = get_terms(['taxonomy' => 'tunebook', 'hide_empty' => true, 'orderby' => 'name']);
if (is_wp_error($public)) {
    $public = [];
}
$can_books = is_user_logged_in() && current_user_can('edit_user_books');
$mybooks   = $can_books ? ub_user_books(get_current_user_id()) : [];
?>

<div class="filter-bar">
    <div class="container pt-2">
        <div class="row align-items-center">
            <div class="d-none d-lg-block col-lg-4">
                <h1>Books</h1>
            </div>
            <div class="col-12 col-lg-5 pb-2">
                <select class="form-select" id="books-select" aria-label="Choose a book">
                    <option value="">Choose a book…</option>
                    <?php if ($public) : ?>
                        <optgroup label="Session books">
                            <?php foreach ($public as $b) : ?>
                                <option value="<?php echo esc_attr($b->slug); ?>" <?php selected($sel, $b->slug); ?>>
                                    <?php echo esc_html($b->name); ?> (<?php echo (int) $b->count; ?>)
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                    <?php if ($mybooks) : ?>
                        <optgroup label="My Books">
                            <?php foreach ($mybooks as $mb) : $v = 'ub:' . $mb->ID; ?>
                                <option value="<?php echo esc_attr($v); ?>" <?php selected($sel, $v); ?>>
                                    <?php echo esc_html($mb->post_title); ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                </select>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        var sel = document.getElementById('books-select');
        if (!sel) return;
        sel.addEventListener('change', function () {
            var u = new URL(window.location.href);
            if (this.value) {
                u.searchParams.set('book', this.value);
            } else {
                u.searchParams.delete('book');
            }
            window.location.href = u.toString();
        });
    })();
</script>

<div class="container the-tunes">
    <?php
    // Share link (?share=<id>&t=<token>): offer to copy that book into the
    // visitor's account (same flow the manager used to handle).
    if (isset($_GET['share'], $_GET['t']) && function_exists('ub_render_share_prompt')) {
        $src_id = (int) $_GET['share'];
        if ($src_id && get_post_type($src_id) === 'user_book'
            && hash_equals(ub_share_token($src_id), sanitize_text_field(wp_unslash($_GET['t'])))) {
            ub_render_share_prompt($src_id);
        }
    }

    if ($sel === '') {
        echo '<p class="mt-3 text-muted">Choose a book above to see its tunes and sets.</p>';
        if ($can_books) {
            echo '<hr class="my-4"><h2 class="h5">Create a personal book</h2>';
            echo '<p class="text-muted small">A personal book is private to you — collect tunes and sets, then share a copy by link.</p>';
            ub_render_create_form();
        }
    } elseif (strpos($sel, 'ub:') === 0) {
        // Personal book: only the owner (or admin) may open it here.
        $bid  = (int) substr($sel, 3);
        $book = get_post($bid);
        if ($book && get_post_type($book) === 'user_book' && ub_can_edit($bid)) {
            ub_render_book_card($book, null, true);
            ub_manager_assets();
            if ($can_books) {
                echo '<hr class="my-4"><h2 class="h5">Create another personal book</h2>';
                ub_render_create_form();
            }
        } else {
            echo '<p class="mt-3">This book is not available.</p>';
        }
    } else {
        // Public tunebook by slug.
        $term = get_term_by('slug', $sel, 'tunebook');
        if (!$term || is_wp_error($term)) {
            echo '<p class="mt-3">Book not found.</p>';
        } else {
            printf('<h2 class="mt-2">%s</h2>', esc_html($term->name));
            tunebook_render_contents($term);
        }
    }
    ?>
</div>

<?php get_footer(); ?>
