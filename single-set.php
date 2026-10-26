
<?php

get_header();
$tune = new tune(get_the_ID());

?>
<script src="<?php echo get_template_directory_uri() ?>/js/abcweb-1.js" async="true"></script>

<div class="container">
    <?php echo tune_nav_markup('set', get_the_ID()); ?>
    <div class="row">
        <div class="col-sm-6">
            <h1><?php echo get_the_title() ?><span class="thekey"> <?php echo $tune->tuneType ?></span></h1>
        </div>
        <div class="col-sm-1">

        </div>
    </div>



    <div class="row">
        <div class="col-xl-10">

   
    <?php
            set_prime_tune_caches([get_the_ID()]);
            $tunes = set_get_tunes(get_the_ID());

    $needsPdfViewer = false;
    $i = 0;
    foreach($tunes as $tune):
    if (!$tune) {
        continue;
    }
    $tuneData = new tune($tune->ID);
    printf('<div class="gig-tap gig-set-item" data-gig-index="%d" data-gig-title="%s">', $i, esc_attr($tune->post_title));
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
            // Rendered inline (PDF.js) - unlike the sets LISTING page
            // (page-sets.php), this template only ever shows one set's few
            // tunes at a time, so rendering N PDF canvases here is bounded
            // and safe (see js/pdf-embed.js, single.php for the same pattern).
            echo tune_pdf_viewer_markup($tuneData->imageUrl);
            $needsPdfViewer = true;
        } else {
            printf('<img src="%s" class="img-fluid" alt="%s">', esc_url($tuneData->imageUrl), esc_attr($tuneData->name));
        }
        echo '</div>';
    }
    echo '</div>'; // /gig-set-item
    $i++;
    endforeach;
    if ($needsPdfViewer) {
        printf('<script type="module" src="%s/js/pdf-embed.js"></script>', get_template_directory_uri());
    }
?>

<?php
wp_reset_postdata();
?>

</div>


</div>
</div>
<?php get_footer() ?>