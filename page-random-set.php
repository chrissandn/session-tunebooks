<?php 
get_header();

$query =  new WP_Query([
    'post_type' => 'set',
    'posts_per_page' => 1,
    'orderby' => 'rand',
]);

if ($query->have_posts()) : while ($query->have_posts()) : $query->the_post();
set_prime_tune_caches([get_the_ID()]);
$tunes = set_get_tunes(get_the_ID());




?>
<script src="<?php echo get_template_directory_uri() ?>/js/abcweb-1.js" async="true"></script>

<div class="container">
<h1><?php echo get_the_title() ?></h1>

<div class="row">
    <div class="col-xl-10">
    <?php
$needsPdfViewer = false;
foreach($tunes as $tune){
    if (!$tune){
        continue;
    }
$tuneData = new tune($tune->ID);
printf('<h2>%s <span class="thekey">%s</span></h2>', $tune->post_title, $tuneData->tuneType);
// Feature #8 stage 2: hybrid tunes show both (scan gated by the toggle).
$hasAbc = ($tuneData->source !== 'image');
$hasScan = !empty($tuneData->imageUrl);
if ($hasAbc) {
    printf('<div class="abc-block%s">', $hasScan ? ' abc-hide-toggle' : '');
    $tuneData->printABC2SVG();
    echo '</div>';
}
if ($hasScan) {
    printf('<div class="scan-block%s">', $hasAbc ? ' scan-toggle' : '');
    if ($tuneData->imageKind === 'pdf') {
        // Inline (PDF.js) - this template shows only one set at a time, so
        // rendering its few PDFs is bounded/safe, unlike page-sets.php.
        echo tune_pdf_viewer_markup($tuneData->imageUrl);
        $needsPdfViewer = true;
    } else {
        printf('<img src="%s" class="img-fluid" alt="%s">', esc_url($tuneData->imageUrl), esc_attr($tuneData->name));
    }
    echo '</div>';
}
}
if ($needsPdfViewer) {
    printf('<script type="module" src="%s/js/pdf-embed.js"></script>', get_template_directory_uri());
}
    ?>
    </div>
</div>

</div>

<?php 
endwhile; endif;
wp_reset_postdata();
get_footer() ?>