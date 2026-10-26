<?php

/**
 * Self-setup for a fresh install: the theme renders its screens through page
 * templates bound to slugs (page-tunes.php, page-sets.php, …) and the menu in
 * header.php links to those slugs, so a new site needs those Pages to exist.
 *
 * On activation (after_switch_theme) this creates every missing Page, sets
 * "home" as the static front page and pretty permalinks — each only if the site
 * has not already chosen otherwise. Idempotent: running it again creates
 * nothing new. A site where the theme is already active (tunes.chrissandner.at)
 * never sees the hook fire, and the notices below stay silent there because ACF
 * is active and tunes exist.
 *
 * Also here: the footer note (Customizer) and the ACF dependency guard.
 */

/** Pages the templates and menu expect, slug => title. */
const ISG_SETUP_PAGES = [
    'home'        => 'Home',
    'tunes'       => 'Tunes',
    'sets'        => 'Sets',
    'books'       => 'Books',
    'my-books'    => 'My Books',
    'add-tune'    => 'Add Tune',
    'scan-review' => 'Scan Review',
    'random-set'  => 'Random Set',
    'settings'    => 'Settings',
    'help'        => 'Help',
];

/** Option holding the last setup report (shown once as an admin notice). */
const ISG_SETUP_OPTION = 'isg_setup_report';

/** ACF free since 6.1 registers post types; the `set` type comes from acf-json/. */
const ISG_ACF_MIN = '6.1';

/**
 * Where a fresh site can load the starter collection (a tunes-export ZIP) from:
 * the asset of the latest release of the public theme repo, so the URL stays the
 * same when the collection is refreshed. '' = no starter offered. Override with
 * the `isg_starter_url` filter.
 */
const ISG_STARTER_URL = 'https://github.com/chrissandn/session-tunebooks/releases/latest/download/tunes-starter.zip';

function isg_starter_url()
{
    return (string) apply_filters('isg_starter_url', ISG_STARTER_URL);
}

add_action('after_switch_theme', 'isg_setup_run');
function isg_setup_run()
{
    $report = ['created' => [], 'front_page' => false, 'permalinks' => false];
    $author = get_current_user_id() ?: 1;

    foreach (ISG_SETUP_PAGES as $slug => $title) {
        if (get_page_by_path($slug)) {
            continue;
        }
        $id = wp_insert_post([
            'post_type'   => 'page',
            'post_name'   => $slug,
            'post_title'  => $title,
            'post_status' => 'publish',
            'post_author' => $author,
        ]);
        if ($id && !is_wp_error($id)) {
            $report['created'][] = $title;
        }
    }

    // Impressum as a draft, deliberately German (Austrian/German legal notice,
    // GDPR). Its text is site-specific, so the footer link stays hidden until the
    // owner fills it in and publishes (see isg_impressum_page()).
    if (!get_page_by_path('impressum')) {
        $id = wp_insert_post([
            'post_type'    => 'page',
            'post_name'    => 'impressum',
            'post_title'   => 'Impressum',
            'post_status'  => 'draft',
            'post_author'  => $author,
            'post_content' => '<p>Name, Adresse, Kontakt — und die Datenschutzerklärung.</p>',
        ]);
        if ($id && !is_wp_error($id)) {
            $report['created'][] = 'Impressum (draft)';
        }
    }

    $home = get_page_by_path('home');
    if ($home && (get_option('show_on_front') !== 'page' || !get_option('page_on_front'))) {
        update_option('show_on_front', 'page');
        update_option('page_on_front', $home->ID);
        $report['front_page'] = true;
    }

    if (get_option('permalink_structure') === '') {
        update_option('permalink_structure', '/%postname%/');
        $report['permalinks'] = true;
    }
    // Also needed for the `set` post type's URLs, registered via ACF on init.
    flush_rewrite_rules();

    update_option(ISG_SETUP_OPTION, $report, false);
}

/**
 * The published Impressum page (slug impressum), or null. The footer links it
 * under its own title.
 */
function isg_impressum_page()
{
    $page = get_page_by_path('impressum');
    return ($page && $page->post_status === 'publish') ? $page : null;
}

/* ------------------------------------------------------------------ *
 * Admin notices: setup report, missing ACF, empty collection
 * ------------------------------------------------------------------ */

add_action('admin_notices', 'isg_setup_notices');
function isg_setup_notices()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    if (!defined('ACF_VERSION') || version_compare(ACF_VERSION, ISG_ACF_MIN, '<')) {
        $install = admin_url('plugin-install.php?s=advanced-custom-fields&tab=search&type=term');
        echo '<div class="notice notice-error"><p><strong>Session Tunebooks theme:</strong> needs the plugin '
            . '<em>Advanced Custom Fields</em> (' . esc_html(ISG_ACF_MIN) . ' or later, the free version is enough). '
            . '<a href="' . esc_url($install) . '">Install and activate it now</a>.</p></div>';
    }

    $report = get_option(ISG_SETUP_OPTION);
    if (is_array($report)) {
        delete_option(ISG_SETUP_OPTION);
        $lines = [];
        if ($report['created']) {
            $lines[] = 'Pages created: ' . esc_html(implode(', ', $report['created'])) . '.';
        }
        if ($report['front_page']) {
            $lines[] = '“Home” is now the front page.';
        }
        if ($report['permalinks']) {
            $lines[] = 'Permalinks set to <code>/%postname%/</code>.';
        }
        $lines[] = 'Adjust the footer in the <a href="' . esc_url(admin_url('customize.php?autofocus[control]=isg_footer_note')) . '">Customizer</a>.';
        echo '<div class="notice notice-success is-dismissible"><p><strong>Theme set up.</strong> '
            . implode(' ', $lines) . '</p></div>';
    }

    if (function_exists('tune_sync_cap') && current_user_can(tune_sync_cap()) && !isg_has_tunes()) {
        $args = ['page' => 'tune-sync'];
        if (isg_starter_url() !== '') {
            $args['tune_import_url'] = rawurlencode(isg_starter_url());
        }
        $link = add_query_arg($args, admin_url('tools.php'));
        $what = isg_starter_url() !== ''
            ? 'load the starter collection (the URL is already filled in, just click “prepare import”)'
            : 'import a <code>tunes-export</code> ZIP';
        echo '<div class="notice notice-info"><p><strong>No tunes yet.</strong> '
            . 'Under <a href="' . esc_url($link) . '">Tools → Tune Sync</a> ' . $what
            . ' — or add them one by one via <a href="' . esc_url(home_url('/add-tune')) . '">Add Tune</a>.</p></div>';
    }
}

/** Does the site hold any tune (a post carrying a tune_uid)? One indexed lookup. */
function isg_has_tunes()
{
    global $wpdb;
    return (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' LIMIT 1",
        TUNE_UID_META
    ));
}

/**
 * Without ACF the templates fatal on get_field(). Show a plain message instead,
 * so a half-set-up site says what is missing rather than "critical error".
 */
add_action('template_redirect', function () {
    if (function_exists('get_field')) {
        return;
    }
    $msg = current_user_can('manage_options')
        ? 'This theme needs the plugin “Advanced Custom Fields”. <a href="' . esc_url(admin_url('plugins.php')) . '">Go to Plugins</a>'
        : 'This site is being set up.';
    wp_die($msg, 'Setting up', ['response' => 503]);
});

/* ------------------------------------------------------------------ *
 * Footer note (Customizer)
 * ------------------------------------------------------------------ */

add_action('customize_register', function ($wp_customize) {
    $wp_customize->add_section('isg_footer', [
        'title'    => 'Footer',
        'priority' => 160,
    ]);
    $wp_customize->add_setting('isg_footer_note', [
        'default'           => '',
        'sanitize_callback' => 'wp_kses_post',
    ]);
    $wp_customize->add_control('isg_footer_note', [
        'section'     => 'isg_footer',
        'label'       => 'Text in the middle of the footer',
        'description' => 'HTML allowed, e.g. links to your sessions. Leave empty for none.',
        'type'        => 'textarea',
    ]);
});
