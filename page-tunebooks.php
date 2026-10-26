<?php

/**
 * Tunebooks overview: lists every book with its tune/set count, linking to the
 * per-book archive (taxonomy-tunebook.php). Assign this template to a page with
 * slug "tunebooks".
 */

get_header();

$books = get_terms(['taxonomy' => 'tunebook', 'hide_empty' => false, 'orderby' => 'name']);
if (is_wp_error($books)) {
    $books = [];
}
?>

<div class="filter-bar">
    <div class="container pt-2">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h1>Tunebooks</h1>
            </div>
        </div>
    </div>
</div>

<div class="container the-tunes">
    <?php if ($books) : ?>
        <div class="row header d-none d-md-flex justify-content-between">
            <div class="col-8">Book</div>
            <div class="col-4">Entries</div>
        </div>
        <?php foreach ($books as $b) : ?>
            <div class="row tune-row justify-content-between py-2">
                <div class="col-md-8">
                    <a href="<?php echo esc_url(get_term_link($b)); ?>" class="tunetitle"><?php echo esc_html($b->name); ?></a>
                </div>
                <div class="col-md-4"><?php echo (int) $b->count; ?></div>
            </div>
        <?php endforeach; ?>
    <?php else : ?>
        <p class="mt-3">No tunebooks yet. Create one when adding a tune, or in wp-admin under Tunebooks.</p>
    <?php endif; ?>
</div>

<?php get_footer(); ?>
