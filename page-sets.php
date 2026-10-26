<?php
get_header();

$order = isset($_GET['order']) ? $_GET['order'] : 'alpha';

?>
    <script src="<?php echo get_template_directory_uri() ?>/js/abcweb-1.js" async="true"></script>

    <div class="filter-bar">
        <div class="container pt-2">
            <div class="row justify-content-end align-items-center">
                <div class="d-none d-lg-block col-md-4">
                    <h1>Sets</h1>
                </div>
                <div class="col-6 col-md-3 col-lg-2 pb-2">
                    <select class="form-select type-select" aria-label="Filter by type">
                        <?php tune_type_filter_option_tags(); ?>
                    </select>
                </div>
                <?php if (function_exists('tunebook_filter_dropdown')) : ?>
                <div class="col-6 col-md-3 col-lg-2 pb-2">
                    <?php tunebook_filter_dropdown(); ?>
                </div>
                <?php endif; ?>
                <div class="col-6 col-md-6 col-lg-5 order-lg-last d-flex justify-content-end align-items-center pb-2">
                    <div class="input-group me-3">
                        <input class="form-control" type="text" placeholder="Name" name="search" id="search">
                        <span class="input-group-text clear-field">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-x" viewBox="0 0 16 16">
                                <path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708"/>
                            </svg>
                        </span>
                    </div>
                    <a id="favswitch" class="btn-outline-primary" type="button" data-bs-toggle="tooltip" data-bs-placement="top" title="Filter by bookmarks">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-bookmark-star" viewBox="0 0 16 16">
                            <path d="M7.84 4.1a.178.178 0 0 1 .32 0l.634 1.285a.18.18 0 0 0 .134.098l1.42.206c.145.021.204.2.098.303L9.42 6.993a.18.18 0 0 0-.051.158l.242 1.414a.178.178 0 0 1-.258.187l-1.27-.668a.18.18 0 0 0-.165 0l-1.27.668a.178.178 0 0 1-.257-.187l.242-1.414a.18.18 0 0 0-.05-.158l-1.03-1.001a.178.178 0 0 1 .098-.303l1.42-.206a.18.18 0 0 0 .134-.098z"/>
                            <path d="M2 2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v13.5a.5.5 0 0 1-.777.416L8 13.101l-5.223 2.815A.5.5 0 0 1 2 15.5zm2-1a1 1 0 0 0-1 1v12.566l4.723-2.482a.5.5 0 0 1 .554 0L13 14.566V2a1 1 0 0 0-1-1z"/>
                        </svg>
                    </a>
                </div>
                <div class="col-6 col-md-3 col-lg-2 pb-2">
                    <select class="form-select age-select">
                        <option value="alpha" <?php echo $order === 'alpha' ? 'selected' : ''; ?>>Alphabetical</option>
                        <option value="DESC" <?php echo $order === 'DESC' ? 'selected' : ''; ?>>Newest first</option>
                        <option value="ASC" <?php echo $order === 'ASC' ? 'selected' : ''; ?>>Oldest first</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <?php tune_tag_filter_chip(); ?>

    <?php if (function_exists('ub_bulk_bar')) ub_bulk_bar('set'); ?>

    <div class="the-sets">
        <?php

        if ($order === 'alpha') {
            $queryArgs = [
                'post_type'      => 'set',
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ];
        } else {
            $queryArgs = [
                'post_type'      => 'set',
                'posts_per_page' => -1,
                'orderby'        => 'date',
                'order'          => strtoupper($order),
            ];
        }
        $query = new WP_Query($queryArgs);
        set_prime_tune_caches(wp_list_pluck($query->posts, 'ID'));

        if ($query->have_posts()) : while ($query->have_posts()) : $query->the_post();
            $tunes = set_get_tunes(get_the_ID());

            $tunetypes = [];
            $tuneObjects = [];
            foreach($tunes as $tune){
                if (!$tune){
                    continue;
                }
                $tuneObjects[$tune->ID] = new tune($tune->ID);

                array_push($tunetypes, $tuneObjects[$tune->ID]->tuneType );
            };
                
            ?>
            <?php
            // Collect tags from the tunes within this set (not from the set post
            // itself), de-duplicated by slug.
            $setTags = [];
            foreach ($tunes as $tune) {
                if (!$tune) continue;
                $tuneTags = get_the_tags($tune->ID);
                if ($tuneTags) {
                    foreach ($tuneTags as $tag) {
                        $setTags[$tag->slug] = $tag;
                    }
                }
            }
            $setTagsStr = tune_tag_slugs_attr($setTags);
            $setBooksStr = function_exists('tunebook_slugs_attr') ? tunebook_slugs_attr(get_the_ID()) : '';
            $setUserBooksStr = function_exists('ub_item_userbooks_attr') ? ub_item_userbooks_attr('set', get_the_ID()) : '';
            ?>
            <div class="set container-fluid py-2" setid="<?php echo get_the_ID() ?>" data-navid="<?php echo get_the_ID() ?>" types="<?php echo implode(' ', $tunetypes) ?>" data-tags="<?php echo esc_attr($setTagsStr); ?>" data-books="<?php echo esc_attr($setBooksStr); ?>" data-userbooks="<?php echo esc_attr($setUserBooksStr); ?>">
                <div class="container">
                    <div class="row my-4 justify-content-between">
                        <div class="col-11">
                            <h5 class=""><span class="settitle"> <a href="<?php the_permalink(); ?>"><?php echo get_the_title() ?></a></span>  <?php if (is_user_logged_in()) { printf('<a class="editlink" href="%s">(edit set)</a>',  get_edit_post_link()) ; } ?>
                            <?php
                            if (!empty($setTags)) {
                                echo '<span class="set-tags ms-2">';
                                tune_tag_badges($setTags);
                                echo '</span>';
                            }
                            ?>
                            </h5>
                        </div>
                        <div class="col-1 d-flex justify-content-end align-items-start align-items-md-center">
                            <div class="fav-button" data-bs-toggle="tooltip" data-bs-placement="top" title="Bookmark set">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-bookmark-star" viewBox="0 0 16 16">
                                    <path d="M7.84 4.1a.178.178 0 0 1 .32 0l.634 1.285a.18.18 0 0 0 .134.098l1.42.206c.145.021.204.2.098.303L9.42 6.993a.18.18 0 0 0-.051.158l.242 1.414a.178.178 0 0 1-.258.187l-1.27-.668a.18.18 0 0 0-.165 0l-1.27.668a.178.178 0 0 1-.257-.187l.242-1.414a.18.18 0 0 0-.05-.158l-1.03-1.001a.178.178 0 0 1 .098-.303l1.42-.206a.18.18 0 0 0 .134-.098z"/>
                                    <path d="M2 2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v13.5a.5.5 0 0 1-.777.416L8 13.101l-5.223 2.815A.5.5 0 0 1 2 15.5zm2-1a1 1 0 0 0-1 1v12.566l4.723-2.482a.5.5 0 0 1 .554 0L13 14.566V2a1 1 0 0 0-1-1z"/>
                                </svg>
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-bookmark-star-fill" viewBox="0 0 16 16">
                                    <path fill-rule="evenodd" d="M2 15.5V2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v13.5a.5.5 0 0 1-.74.439L8 13.069l-5.26 2.87A.5.5 0 0 1 2 15.5M8.16 4.1a.178.178 0 0 0-.32 0l-.634 1.285a.18.18 0 0 1-.134.098l-1.42.206a.178.178 0 0 0-.098.303L6.58 6.993c.042.041.061.1.051.158L6.39 8.565a.178.178 0 0 0 .258.187l1.27-.668a.18.18 0 0 1 .165 0l1.27.668a.178.178 0 0 0 .257-.187L9.368 7.15a.18.18 0 0 1 .05-.158l1.028-1.001a.178.178 0 0 0-.098-.303l-1.42-.206a.18.18 0 0 1-.134-.098z"/>
                                </svg>
                            </div>

                        </div>
                    </div>

                    <?php foreach($tunes as $tune):
                        if (!$tune){
                            continue;
                        }
                        $tuneData = $tuneObjects[$tune->ID];
                        ?>

                        <div class="row mb-3">
                            <div class="col-9 col-md-6 col-lg-2"><a href="<?php the_permalink($tune->ID); ?>"><?php echo get_the_title($tune->ID) ?> </a><?php if (is_user_logged_in()) { printf('<a class="editlink" href="%s">(edit tune)</a>',  get_edit_post_link($tune->ID)) ; } ?> </div>
                            <div class=" col  col-lg-1"><?php echo $tuneData->tuneType;  ?></div>
                            <div class="d-none d-lg-block col-md-1"><?php echo $tuneData->key;  ?></div>
                            <div class="col-lg-8">
                                <?php
                                // Feature #8 stage 2: a tune can carry both abc-ish data and a
                                // scan (hybrid) - show both independently instead of the old
                                // abc-XOR-image branch. Scan is gated by the "Show original
                                // scans" toggle only when an ABC render also exists; a pure
                                // image-tune has nothing else to show, so its scan is never hidden.
                                $hasAbc = ($tuneData->source !== 'image');
                                $hasScan = !empty($tuneData->imageUrl);
                                ?>
                                <?php if ($hasAbc) : ?>
                                <div class="abc-container<?php echo $hasScan ? ' abc-hide-toggle' : ''; ?>">
                                    <?php $tuneData->printABC2SVG(); ?>
                                    <div class="expand-arrow">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" class="bi bi-caret-down-square-fill" viewBox="0 0 16 16">
                                            <path d="M0 2a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm4 4a.5.5 0 0 0-.374.832l4 4.5a.5.5 0 0 0 .748 0l4-4.5A.5.5 0 0 0 12 6z"/>
                                        </svg>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <?php if ($hasScan) : ?>
                                    <?php if ($tuneData->imageKind === 'pdf') : ?>
                                        <a class="button scan-block<?php echo $hasAbc ? ' scan-toggle' : ''; ?>" href="<?php echo esc_url($tuneData->imageUrl); ?>" target="_blank" rel="noopener">Open PDF</a>
                                    <?php else : ?>
                                    <div class="abc-container scan-block<?php echo $hasAbc ? ' scan-toggle' : ''; ?>">
                                        <img src="<?php echo esc_url($tuneData->imageUrl); ?>" loading="lazy" style="width:100%" alt="<?php echo esc_attr($tuneData->name); ?>">
                                        <div class="expand-arrow">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" class="bi bi-caret-down-square-fill" viewBox="0 0 16 16">
                                                <path d="M0 2a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm4 4a.5.5 0 0 0-.374.832l4 4.5a.5.5 0 0 0 .748 0l4-4.5A.5.5 0 0 0 12 6z"/>
                                            </svg>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                    <?php endforeach;?>
                </div>
            </div>
        <?php endwhile; endif;
        wp_reset_postdata(); ?>

    </div>

    <div class="nosets container mt-4" style="display:none">
        <h4>
        Not a drop left. Please change your filter settings.

        </h4>
    </div>

<?php

get_footer();

?>