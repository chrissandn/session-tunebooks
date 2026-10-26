<?php

/**
 * Page template for Impressum / Datenschutzerklärung. Applies to a Page with
 * the slug `impressum`. Text lives in the post content, editable in wp-admin
 * (previously hardcoded here, which made the block editor show empty content).
 */

get_header();
?>
<div class="container my-5" id="impressum-page" style="max-width:820px">
    <h1><?php the_title(); ?></h1>
    <?php the_content(); ?>
</div>
<?php
get_footer();
