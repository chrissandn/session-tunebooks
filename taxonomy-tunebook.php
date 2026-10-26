<?php

/**
 * Tunebook archive: all tunes and sets collected in one book.
 * Used automatically by WordPress at /tunebook/<slug>/.
 * Shares its list rendering with the Books page via tunebook_render_contents().
 */

get_header();

$book = get_queried_object();
?>

<div class="filter-bar">
    <div class="container pt-2">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h1>Tunebook: <?php echo esc_html($book->name); ?></h1>
            </div>
        </div>
    </div>
</div>

<div class="container the-tunes">
    <?php tunebook_render_contents($book); ?>
</div>

<?php get_footer(); ?>
