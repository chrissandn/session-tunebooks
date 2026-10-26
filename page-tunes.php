<?php

get_header();

$tuneOrder = isset($_GET['order']) ? $_GET['order'] : 'alpha';

?>
    <?php if (isg_tunes_preview_enabled()) : ?>
    <script>
    (function () {
        // The one-liner preview is opt-in (Settings). Read the preference here,
        // inline and before the list is parsed, for two reasons:
        //  - sets the body class up front, so the previews never flash into view
        //    on a page where they're switched off;
        //  - only pulls abcweb-1.js in when a preview is actually wanted. Loading
        //    it regardless would be worse than wasteful: its lazy check measures a
        //    display:none preview as sitting at the top of the viewport, so it
        //    would render all 140+ hidden previews at once, on load.
        // Without abcweb, the <script type="text/vnd.abc"> blocks below are inert
        // — the browser doesn't execute an unknown script type.
        var v = 'off';
        try { v = localStorage.getItem('isgTunesPreview') || 'off'; } catch (e) {}
        if (v !== 'abc' && v !== 'all') { v = 'off'; }
        document.body.classList.add('tunes-preview-' + v);
        if (v !== 'off') {
            var s = document.createElement('script');
            s.src = <?php echo wp_json_encode(get_template_directory_uri() . '/js/abcweb-1.js'); ?>;
            document.head.appendChild(s);
        }
    })();
    </script>
    <?php endif; ?>

<div class="filter-bar">
    <div class="container pt-2">
        <div class="row justify-content-end align-items-center">
            <div class="d-none d-lg-block col-md-4">
                <h1>Tunes</h1>
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
                    <input class="form-control" type="text" placeholder="Name" name="search" id="thesearch">
                    <span class="input-group-text clear-field">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-x" viewBox="0 0 16 16">
                            <path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708"/>
                        </svg>
                    </span>
                </div>
                <a id="tunesfavswitch" class="btn-outline-primary" type="button" data-bs-toggle="tooltip" data-bs-placement="top" title="Filter by bookmarks">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-bookmark-star" viewBox="0 0 16 16">
                        <path d="M7.84 4.1a.178.178 0 0 1 .32 0l.634 1.285a.18.18 0 0 0 .134.098l1.42.206c.145.021.204.2.098.303L9.42 6.993a.18.18 0 0 0-.051.158l.242 1.414a.178.178 0 0 1-.258.187l-1.27-.668a.18.18 0 0 0-.165 0l-1.27.668a.178.178 0 0 1-.257-.187l.242-1.414a.18.18 0 0 0-.05-.158l-1.03-1.001a.178.178 0 0 1 .098-.303l1.42-.206a.18.18 0 0 0 .134-.098z"/>
                        <path d="M2 2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v13.5a.5.5 0 0 1-.777.416L8 13.101l-5.223 2.815A.5.5 0 0 1 2 15.5zm2-1a1 1 0 0 0-1 1v12.566l4.723-2.482a.5.5 0 0 1 .554 0L13 14.566V2a1 1 0 0 0-1-1z"/>
                    </svg>
                </a>
            </div>
            <div class="col-6 col-md-3 col-lg-2 pb-2">
                <select class="form-select age-select">
                    <option value="alpha" <?php echo $tuneOrder === 'alpha' ? 'selected' : ''; ?>>Alphabetical</option>
                    <option value="DESC" <?php echo $tuneOrder === 'DESC' ? 'selected' : ''; ?>>Newest first</option>
                    <option value="ASC" <?php echo $tuneOrder === 'ASC' ? 'selected' : ''; ?>>Oldest first</option>
                </select>
            </div>
        </div>
    </div>
</div>

    <?php tune_tag_filter_chip(); ?>

    <?php if (function_exists('ub_bulk_bar')) ub_bulk_bar('tune'); ?>

    <div class="container the-tunes">
        <div class="row header d-none d-md-flex justify-content-between">
            <div class="col-3">Name</div>
            <div class="col-2">Type</div>
            <div class="col-2">Key</div>
            <div class="col-1">Setting</div>
            <div class="col-1">Session</div>
            <div class="col-2">Tags</div>
            <div class="col-1"></div>
        </div>

        <?php
        if ($tuneOrder === 'alpha') {
            $queryArgs = [
                'post_type'      => 'post',
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ];
        } else {
            $queryArgs = [
                'post_type'      => 'post',
                'posts_per_page' => -1,
                'orderby'        => 'date',
                'order'          => strtoupper($tuneOrder),
            ];
        }
        $query = new WP_Query($queryArgs);
        tune_prime_caches(wp_list_pluck($query->posts, 'ID'));

        if ($query->have_posts()) : while ($query->have_posts()) : $query->the_post();
            $tune = new tune(get_the_ID());
            $rowTags = get_the_tags();
            $rowTagsStr = tune_tag_slugs_attr($rowTags);
            $rowBooksStr = function_exists('tunebook_slugs_attr') ? tunebook_slugs_attr(get_the_ID()) : '';
            $rowUserBooksStr = function_exists('ub_item_userbooks_attr') ? ub_item_userbooks_attr('post', get_the_ID()) : '';
            ?>
            <div class="row tune-row py-2"
                 data-tags="<?php echo esc_attr($rowTagsStr); ?>"
                 data-type="<?php echo esc_attr(strtolower($tune->tuneType)); ?>"
                 data-books="<?php echo esc_attr($rowBooksStr); ?>"
                 data-userbooks="<?php echo esc_attr($rowUserBooksStr); ?>"
                 data-tuneid="<?php the_ID(); ?>"
                 data-navid="<?php the_ID(); ?>">
                <div class="col-9 col-md-3">
                    <a href="<?php the_permalink(); ?>" class="tunetitle"><?php the_title(); ?></a>
                </div>
                <div class="col-3 col-md-1 order-md-last d-flex justify-content-end">
                    <div class="fav-button" data-bs-toggle="tooltip" data-bs-placement="top" title="Bookmark tune">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-bookmark-star" viewBox="0 0 16 16">
                            <path d="M7.84 4.1a.178.178 0 0 1 .32 0l.634 1.285a.18.18 0 0 0 .134.098l1.42.206c.145.021.204.2.098.303L9.42 6.993a.18.18 0 0 0-.051.158l.242 1.414a.178.178 0 0 1-.258.187l-1.27-.668a.18.18 0 0 0-.165 0l-1.27.668a.178.178 0 0 1-.257-.187l.242-1.414a.18.18 0 0 0-.05-.158l-1.03-1.001a.178.178 0 0 1 .098-.303l1.42-.206a.18.18 0 0 0 .134-.098z"/>
                            <path d="M2 2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v13.5a.5.5 0 0 1-.777.416L8 13.101l-5.223 2.815A.5.5 0 0 1 2 15.5zm2-1a1 1 0 0 0-1 1v12.566l4.723-2.482a.5.5 0 0 1 .554 0L13 14.566V2a1 1 0 0 0-1-1z"/>
                        </svg>
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-bookmark-star-fill" viewBox="0 0 16 16">
                            <path fill-rule="evenodd" d="M2 15.5V2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v13.5a.5.5 0 0 1-.74.439L8 13.069l-5.26 2.87A.5.5 0 0 1 2 15.5M8.16 4.1a.178.178 0 0 0-.32 0l-.634 1.285a.18.18 0 0 1-.134.098l-1.42.206a.178.178 0 0 0-.098.303L6.58 6.993c.042.041.061.1.051.158L6.39 8.565a.178.178 0 0 0 .258.187l1.27-.668a.18.18 0 0 1 .165 0l1.27.668a.178.178 0 0 0 .257-.187L9.368 7.15a.18.18 0 0 1 .05-.158l1.028-1.001a.178.178 0 0 0-.098-.303l-1.42-.206a.18.18 0 0 1-.134-.098z"/>
                        </svg>
                    </div>
                </div>
                <div class="col-auto col-md-2 small"><?php echo $tune->tuneType; ?></div>
                <div class="col-auto col-md-2 small"><?php echo $tune->key; ?></div>
                <div class="d-none d-md-block col-md-1"><?php echo ($tune->settingNumber !== false) ? $tune->settingNumber + 1 : ''; ?></div>
                <div class="col-auto col-md-1">
                    <?php echo $tune->getSessionLink(); ?>
                </div>
                <div class="col-auto col-md-2">
                    <?php
                    tune_tag_badges($rowTags);
                    tunebook_badges(get_the_terms(get_the_ID(), 'tunebook'));
                    ?>
                </div>
                <?php
                // One-line notation preview (PIC01b) — same affordance as the Sets
                // list: collapsed by CSS max-height, the caret expands it in place.
                // Rendering is abcweb-1.js's job, and it already renders lazily on
                // its own (it converts previews down to the fold, then follows the
                // scroll), which is what makes 280+ of these affordable here.
                //
                // Hinter isg_tunes_preview_enabled(): abgeschaltet erspart das auch
                // das Markup selbst (~193 KB HTML auf dieser Liste), nicht nur die
                // Anzeige.
                if (isg_tunes_preview_enabled()) :
                    $hasAbc  = ($tune->source !== 'image');
                    $hasScan = !empty($tune->imageUrl);
                ?>
                <div class="col-12 order-last tune-preview <?php echo $hasAbc ? 'preview-abc' : 'preview-scan'; ?>">
                    <?php if ($hasAbc) : ?>
                        <div class="abc-container">
                            <?php $tune->printABC2SVG(); ?>
                            <?php echo isg_expand_arrow(); ?>
                        </div>
                    <?php elseif ($hasScan) : ?>
                        <?php if ($tune->imageKind === 'pdf') : ?>
                            <a class="small" href="<?php echo esc_url($tune->imageUrl); ?>" target="_blank" rel="noopener">Open PDF</a>
                        <?php else : ?>
                            <?php
                            // Full-size URL, like single.php and page-sets.php — deliberately
                            // NOT a 'medium' thumbnail: for a good number of the imported
                            // scans the attachment metadata lists sub-sizes whose files were
                            // never written, so asking for 'medium' yields a 404 and a broken
                            // image. loading="lazy" keeps the off-screen ones off the wire.
                            ?>
                            <div class="abc-container">
                                <img src="<?php echo esc_url($tune->imageUrl); ?>"
                                     loading="lazy" style="width:100%" alt="<?php echo esc_attr($tune->name); ?>">
                                <?php echo isg_expand_arrow(); ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endif; // isg_tunes_preview_enabled ?>
            </div>
        <?php endwhile; endif;
        wp_reset_postdata();
        ?>

        <div class="notunes mt-4" style="display:none">
            <h4>No tunes match your filter settings.</h4>
        </div>
    </div>
<?php

get_footer()

?>