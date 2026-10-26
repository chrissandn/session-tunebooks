<?php

/**
 * Page template for the Help / user manual. Applies to a Page with the slug
 * `help`. All content lives in help.php (help_render()).
 */

get_header();

if (function_exists('help_render')) {
    help_render();
}

get_footer();
