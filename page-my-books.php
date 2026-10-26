<?php

/**
 * Page template for the personal "My Books" manager. Applies to a Page with the
 * slug `my-books`. All logic lives in user-books.php (ub_render_manager()).
 */

get_header();

if (function_exists('ub_render_manager')) {
    ub_render_manager();
}

get_footer();
