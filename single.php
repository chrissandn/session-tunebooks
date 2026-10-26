<?php

get_header();
$tune = new tune(get_the_ID());

?>
<?php if ($tune->source !== 'image') : ?>
<script src="<?php echo get_template_directory_uri() ?>/js/abcweb-1.js" async="true"></script>
<?php endif; ?>

<div class="container">
    <?php echo tune_nav_markup('tune', get_the_ID()); ?>
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
// Feature #8 stage 2: a tune can carry BOTH abc-ish data and a scan (hybrid) -
// show both independently instead of the old abc-XOR-image branch, with the
// scan gated by the "Show original scans" toggle whenever an ABC render also
// exists (a pure image-tune has nothing else to show, so never hide its scan).
$hasAbc = ($tune->source !== 'image');
$hasScan = !empty($tune->imageUrl);
?>
<div class="gig-tap" data-gig-title="<?php echo esc_attr(get_the_title()); ?>">
<?php if ($hasAbc) : ?>
<div class="abc-block">
<?php
$tune->printABC2SVG();
?>
</div>

            <p>
                <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#collapseWidthExample" aria-expanded="false" aria-controls="collapseWidthExample">
                    Show ABC
                </button>
            </p>
            <div style="min-height: 120px;">
                <div class="collapse collapse-horizontal" id="collapseWidthExample">
                    <div class="card card-body">
                        <?php
                        $tune->printABC2SVG(true);
                        ?>
                    </div>
                </div>
            </div>
<?php endif; ?>

<?php if ($hasScan) : ?>
    <div class="scan-block<?php echo $hasAbc ? ' scan-toggle' : ''; ?>">
    <?php if ($tune->imageKind === 'pdf') : ?>
        <?php echo tune_pdf_viewer_markup($tune->imageUrl); ?>
        <script type="module" src="<?php echo get_template_directory_uri() ?>/js/pdf-embed.js"></script>
    <?php else : ?>
        <img src="<?php echo esc_url($tune->imageUrl); ?>" class="img-fluid" alt="<?php echo esc_attr($tune->name); ?>">
    <?php endif; ?>
    </div>
<?php endif; ?>
</div><!-- /gig-tap -->

            <div class="">
                <?php
                $setQuery = new WP_Query(array(
                    'post_type' => 'set',
                    'meta_query'    => array(
                        'relation'      => 'OR',
                        array(
                            'key'       => 'tune_1',
                            'value'     => get_the_id(),
                            'compare'   => '=',
                        ),
                        array(
                            'key'       => 'tune_2',
                            'value'     => get_the_id(),
                            'compare'   => '=',
                        ),
                        array(
                            'key'       => 'tune_3',
                            'value'     => get_the_id(),
                            'compare'   => '=',
                        ),
                        array(
                            'key'       => 'tune_4',
                            'value'     => get_the_id(),
                            'compare'   => '=',
                        ),
                        array(
                            'key'       => 'tune_5',
                            'value'     => get_the_id(),
                            'compare'   => '=',
                        ),
                    ),

                ));

                $sets = array( );
            
                if ($setQuery->have_posts()) {
                    while($setQuery->have_posts()){
                        $setQuery->the_post();
                        $sets[] = get_the_ID();
                    }
                    // the_post() above leaves $post pointing at the last set found,
                    // not this tune — restore it before anything below (or after
                    // this template) relies on get_the_ID()/get_the_title() again.
                    wp_reset_postdata();
                }


                if ($sets) {
                    echo '<div class="in-sets mt-3">';
                    // Pass $set explicitly: without it get_the_permalink()/get_the_title()
                    // fall back to the global $post (the LAST set from the loop above), so
                    // every "In set" line showed the same set - looked like a duplicate
                    // whenever a tune belonged to more than one set.
                    foreach ($sets as $set) {
                        printf(
                            '<div>In set: <a href="%s">%s</a></div>',
                            esc_url(get_the_permalink($set)),
                            esc_html(get_the_title($set))
                        );
                    }
                    echo '</div>';
                }
                ?>
                
            </div>

        </div>
    </div>


</div>

<?php get_footer() ?>