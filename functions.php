<?php
include_once 'class-tune.php';
include_once 'tune-sync.php';
include_once 'tune-export.php';
include_once 'tune-import.php';
include_once 'tune-thesession.php';
include_once 'tune-books.php';
include_once 'tune-sets.php';
include_once 'tune-scan-review.php';
include_once 'user-books.php';
include_once 'help.php';
include_once 'setup.php';
/**
 * Ist die Ein-Zeilen-Notenvorschau in der Tunes-Liste verfuegbar?
 *
 * Abgeschaltet am 2026-07-31: die Vorschau sah in der Liste unruhig aus. Die
 * Scan-Variante ist der Hauptgrund — ein Scan ist ein ganzes Blatt (Titel,
 * mehrere Systeme, teils Liedtext), das auf ~50px Zeilenhoehe hart abgeschnitten
 * wird und dadurch nur einen unlesbaren Streifen zeigt, waehrend die abc-Variante
 * wenigstens sauber bei der ersten Notenzeile klemmt.
 *
 * Bewusst ein Schalter statt geloeschtem Code: Markup, CSS und die Settings-
 * Option bleiben vollstaendig erhalten, `return true` holt alles zurueck. Der
 * naechste Schritt waere — falls die Vorschau wiederkommen soll — sie auf abc zu
 * beschraenken (die 'all'-Option in page-settings.php entfernen), statt sie ganz
 * zu reaktivieren.
 */
function isg_tunes_preview_enabled()
{
    return false;
}

// ===== ACF configuration shipped with the theme (acf-json/) =====
// The Tune/Set field groups and the `set` post type live as Local JSON in the
// theme, so a fresh install gets them without a DB export. ACF loads acf-json/ on
// its own; writing happens only locally (Local sets WP_ENVIRONMENT_TYPE=local). On
// live, editing in the admin would otherwise write into the git checkout and block
// the next Plesk pull — there, changes keep landing in the DB as before.
add_filter('acf/json/save_paths', function ($paths) {
    return wp_get_environment_type() === 'local' ? $paths : [];
});

// ===== Web-App-Manifest: "Zum Home-Bildschirm" ohne Safari-Chrome =====
// Vom iPhone-Home-Bildschirm gestartet laeuft die Site standalone (keine
// Adress-/Tabzeile) — der einzige verlaessliche Weg zu echtem Vollbild fuer
// den Session-Gig-Modus (main.js gigMode). Als oeffentlicher Query-Var statt
// statischer Datei im Theme: braucht keinen eigenen Rewrite-Regel/-Flush und
// zeigt live wie lokal auf das jeweils aktuell gesetzte WP-Site-Icon (das
// Customizer > Website-Icon liefert bereits eine quadratische 512x512-Original-
// datei plus registrierte 192er-Groesse — keine eigenen Icon-Dateien noetig).
// Ohne gesetztes Site-Icon faellt es auf logo.svg zurueck (skaliert verlustfrei).
add_filter('query_vars', function ($vars) {
    $vars[] = 'isg_manifest';
    return $vars;
});
add_action('template_redirect', function () {
    if (!get_query_var('isg_manifest')) {
        return;
    }
    $icons = [];
    $icon_id = (int) get_option('site_icon');
    if ($icon_id) {
        $icon192 = wp_get_attachment_image_url($icon_id, [192, 192]);
        $meta = wp_get_attachment_metadata($icon_id);
        $full = wp_get_attachment_image_url($icon_id, 'full');
        if ($icon192) {
            $icons[] = ['src' => $icon192, 'sizes' => '192x192', 'type' => 'image/jpeg'];
        }
        if ($full && !empty($meta['width']) && !empty($meta['height'])) {
            $icons[] = ['src' => $full, 'sizes' => $meta['width'] . 'x' . $meta['height'], 'type' => 'image/jpeg'];
        }
    }
    if (!$icons) {
        $icons[] = ['src' => get_template_directory_uri() . '/logo.svg', 'sizes' => 'any', 'type' => 'image/svg+xml'];
    }
    $manifest = [
        // html_entity_decode noetig: die blogname-Option liegt bereits HTML-
        // kodiert in der DB ("Chris&#039; Tunebooks" - WP kodiert Anfuehrungs-
        // zeichen/Sonderzeichen schon beim Speichern im wp-admin, nicht erst
        // beim Ausgeben). Unkodiert wuerde das woertlich als App-Name auf dem
        // Home-Bildschirm landen statt als "Chris' Tunebooks" gerendert zu sein.
        'name'             => html_entity_decode(get_bloginfo('name', 'raw'), ENT_QUOTES, 'UTF-8'),
        'short_name'       => 'Tunes',
        'start_url'        => home_url('/'),
        'scope'            => home_url('/'),
        'display'          => 'standalone',
        'background_color' => '#ffffff',
        'theme_color'      => '#3f6b56',
        'icons'            => $icons,
    ];
    header('Content-Type: application/manifest+json; charset=utf-8');
    echo wp_json_encode($manifest);
    exit;
});

add_action('after_setup_theme', 'blankslate_setup');
function blankslate_setup()
{
    load_theme_textdomain('blankslate', get_template_directory() . '/languages');
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('responsive-embeds');
    add_theme_support('automatic-feed-links');
    add_theme_support('html5', array('search-form', 'navigation-widgets'));
    add_theme_support('appearance-tools');
    add_theme_support('woocommerce');
    global $content_width;
    if (!isset($content_width)) {
        $content_width = 1920;
    }
    register_nav_menus(array('main-menu' => esc_html__('Main Menu', 'blankslate')));
}
add_action('admin_notices', 'blankslate_notice');
function blankslate_notice()
{
    $user_id = get_current_user_id();
    $admin_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    $param = (count($_GET)) ? '&' : '?';
    if (!get_user_meta($user_id, 'blankslate_notice_dismissed_11') && current_user_can('manage_options'))
        echo '<div class="notice notice-info"><p><a href="' . esc_url($admin_url), esc_html($param) . 'dismiss" class="alignright" style="text-decoration:none"><big>' . esc_html__('Ⓧ', 'blankslate') . '</big></a>' . wp_kses_post(__('<big><strong>🏆 Thank you for using BlankSlate!</strong></big>', 'blankslate')) . '<p>' . esc_html__('Powering over 10k websites! Buy me a sandwich! 🥪', 'blankslate') . '</p><a href="https://github.com/bhadaway/blankslate/issues/57" class="button-primary" target="_blank"><strong>' . esc_html__('How do you use BlankSlate?', 'blankslate') . '</strong></a> <a href="https://opencollective.com/blankslate" class="button-primary" style="background-color:green;border-color:green" target="_blank"><strong>' . esc_html__('Donate', 'blankslate') . '</strong></a> <a href="https://wordpress.org/support/theme/blankslate/reviews/#new-post" class="button-primary" style="background-color:purple;border-color:purple" target="_blank"><strong>' . esc_html__('Review', 'blankslate') . '</strong></a> <a href="https://github.com/bhadaway/blankslate/issues" class="button-primary" style="background-color:orange;border-color:orange" target="_blank"><strong>' . esc_html__('Support', 'blankslate') . '</strong></a></p></div>';
}
add_action('admin_init', 'blankslate_notice_dismissed');
function blankslate_notice_dismissed()
{
    $user_id = get_current_user_id();
    if (isset($_GET['dismiss']))
        add_user_meta($user_id, 'blankslate_notice_dismissed_11', 'true', true);
}
add_action('wp_enqueue_scripts', 'blankslate_enqueue');
function blankslate_enqueue()
{
    wp_enqueue_style('blankslate-style', get_stylesheet_uri());
    wp_enqueue_script('jquery');
    wp_enqueue_script('main', get_template_directory_uri() . '/main.js');
    // Targets for the tag-badge popup (PIC05) — the list pages aren't at a
    // fixed path, so hand the resolved permalinks to main.js.
    wp_localize_script('main', 'isgTagFilter', [
        'tunesUrl' => home_url('/tunes/'),
        'setsUrl'  => home_url('/sets/'),
    ]);
}

add_filter('document_title_separator', 'blankslate_document_title_separator');
function blankslate_document_title_separator($sep)
{
    $sep = esc_html('|');
    return $sep;
}
add_filter('the_title', 'blankslate_title');
function blankslate_title($title)
{
    if ($title == '') {
        return esc_html('...');
    } else {
        return wp_kses_post($title);
    }
}


// Add the custom meta box
function setting_meta_box()
{
    add_meta_box(
        'setting_meta_box',           // ID of the meta box
        'Setting',    // Title of the meta box
        'setting_meta_box_callback',  // Callback function to render the meta box
        'post',                  // Where to show the meta box ('post', 'page', etc.)
        'normal',                // Context (normal, side, etc.)
        'low'                   // Priority (high, low, etc.)
    );
}
add_action('add_meta_boxes', 'setting_meta_box');

// Callback function to render the custom meta field
function setting_meta_box_callback($post)
{
    $tune = new tune(get_the_ID());
    if ($tune->source != 'session') {
        return;
    }

    // Feature #13: with an ABC override in place, this picker still works but no
    // longer decides what the site renders. Saying so here is the whole point --
    // otherwise the next person clicks through the settings and wonders why the
    // tune page never changes.
    if ($tune->abcOverride) {
        echo '<div class="notice notice-warning inline"><p><strong>ABC override is active.</strong> '
            . 'The ABC field replaces this tune&rsquo;s notation, so picking a setting below changes '
            . 'these previews but not the tune page. Clear the ABC field to go back to the setting.</p></div>';
    }

    echo '<script src=' . get_template_directory_uri() . '/js/abcweb-1.js" async="true"></script>';

    // Nonce field for security
    wp_nonce_field('my_meta_box_nonce', 'meta_box_nonce');

    // Get the existing value if it exists
    $value = get_post_meta($post->ID, 'setting', true);

    foreach ($tune->sessionJson->settings as $key => $setting) {

        echo '<div class="setting" style="max-width: 1000px">';
        $checked = $value == $key ? 'checked="checked"' : false;
        printf('<input type="radio" %s name="setting" value="%s" class="widefat" />', $checked, $key);
        echo '<label for="my_custom_field">' . $key + 1 . '</label>';
        $tune->printABC2SVG(false, $key);
        echo '</div>';
    }
}

// Save the custom meta field value when the post is saved
function save_my_custom_meta_field($post_id)
{
    // Check if nonce is set and valid
    if (!isset($_POST['meta_box_nonce']) || !wp_verify_nonce($_POST['meta_box_nonce'], 'my_meta_box_nonce')) {
        return $post_id;
    }

    // Check if it's an autosave
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return $post_id;
    }

    // Check if the user has permission to edit this post
    if (!current_user_can('edit_post', $post_id)) {
        return $post_id;
    }

    // Check if the custom field is set and save it
    if (isset($_POST['setting'])) {
        $value = sanitize_text_field($_POST['setting']);
        update_post_meta($post_id, 'setting', $value);
    }
}
add_action('save_post', 'save_my_custom_meta_field');

/**
 * Spell out what the ABC field does on a thesession tune (feature #13).
 *
 * The field itself lives in the ACF admin, so its description can only be set
 * from here. Worth the hook: on a session tune the field looks like an empty
 * box with an obvious meaning, and until 2026-07-31 it had none at all.
 */
add_filter('acf/prepare_field/name=abc', 'tune_abc_field_hint');
function tune_abc_field_hint($field)
{
    if (!is_admin() || empty($field) || !function_exists('get_current_screen')) {
        return $field;
    }
    $screen = get_current_screen();
    if (!$screen || $screen->base !== 'post') {
        return $field;
    }
    $post_id = get_the_ID();
    if (!$post_id || (string) get_post_meta($post_id, 'json', true) === '') {
        return $field; // no json blob -> the field IS the notation, nothing to explain
    }

    $field['instructions'] = 'Optional override. Leave empty to use the ABC of the selected '
        . 'setting from thesession.org; fill it in to replace that notation (a typo, a missing '
        . 'repeat, the way it is played here). Text with its own header (X:/M:/K: …) is used as '
        . 'is, otherwise the header is generated as before.';

    return $field;
}

// Register the built-in post_tag taxonomy for the 'set' custom post type
add_action('init', function () {
    register_taxonomy_for_object_type('post_tag', 'set');
});

/**
 * Canonical tune-type filter options for the Tunes/Sets list dropdowns.
 * Keys are the lowercase singular type strings stored per tune (matched
 * client-side against each row's data-type); values are the plural menu
 * labels, mirroring thesession.org's browse categories plus our additions.
 * Keep in sync with the known-types list in page-add-tune.php.
 */
function tune_type_filter_options()
{
    return [
        'jig'        => 'Jigs',
        'reel'       => 'Reels',
        'hornpipe'   => 'Hornpipes',
        'slide'      => 'Slides',
        'slip jig'   => 'Slip Jigs',
        'polka'      => 'Polkas',
        'march'      => 'Marches',
        'waltz'      => 'Waltzes',
        'mazurka'    => 'Mazurkas',
        'barndance'  => 'Barndances',
        'strathspey' => 'Strathspeys',
        'three-two'  => 'Three-Twos',
        'slow air'   => 'Slow Airs',
        'song'       => 'Songs',
    ];
}

/**
 * Render the <option> list (incl. the "All Types" default) for a type-filter
 * <select>. Shared by page-tunes.php and page-sets.php so the vocabulary
 * lives in exactly one place.
 */
function tune_type_filter_option_tags()
{
    echo '<option value="all" selected>All Types</option>';
    foreach (tune_type_filter_options() as $value => $label) {
        printf('<option value="%s">%s</option>', esc_attr($value), esc_html($label));
    }
}

/**
 * Container for an inline PDF scan, for js/pdf-embed.js to render into.
 *
 * The plain "Open PDF" link inside it is a *pre-rendered* fallback that the
 * script removes once it has the document open. That order matters: the link
 * must exist without any JavaScript having run, because the failure it guards
 * against is the module never running at all — on 2026-08-14 nginx served the
 * viewer as text/plain, browsers refused the module, and since the script's own
 * error handling lives inside the script, every PDF tune showed an empty box
 * with no hint and no way to reach the file (BACKLOG #15). Failures the script
 * *can* see still surface through showError().
 *
 * Shared by single.php, single-set.php and page-random-set.php so the three
 * cannot drift apart; each still emits the module <script> itself.
 */
function tune_pdf_viewer_markup($url)
{
    return sprintf(
        '<div class="pdf-viewer" data-pdf-url="%1$s">'
        . '<a class="pdf-viewer-fallback small" href="%1$s" target="_blank" rel="noopener">Open PDF</a>'
        . '</div>',
        esc_url($url)
    );
}

/**
 * Prev/next strip for paging through the tune or set list one item at a time.
 * main.js fills the two links from the nav list it captured on the listing page
 * (sessionStorage) — they stay hidden when there is nothing to page to.
 *
 * Sits at the TOP of the content, directly under the menu bar, and deliberately
 * so: at the bottom its position depended on how tall the tune happened to be,
 * so the links jumped somewhere else on every page turn and had to be hunted
 * down again. Right under the header they land in the same spot every time —
 * which is what makes them usable while paging through a session set.
 *
 * Shared by single.php and single-set.php; $kind selects which captured list
 * main.js reads ('tune' | 'set').
 */
function tune_nav_markup($kind, $post_id)
{
    return sprintf(
        '<div class="row tune-nav justify-content-between mb-3">'
        . '<div class="col-6"><a href="#" class="tune-nav-prev small text-muted" style="display:none"></a></div>'
        . '<div class="col-6 text-end"><a href="#" class="tune-nav-next small text-muted" style="display:none"></a></div>'
        . '</div>'
        . '<script>window.__navCurrentId = %d; window.__navKind = %s;</script>',
        (int) $post_id,
        wp_json_encode((string) $kind)
    );
}

/**
 * Expand caret for a collapsed notation preview. Shared by the Sets list and
 * the Tunes list (step 4); main.js toggles .expanded on the .abc-container.
 */
function isg_expand_arrow()
{
    return '<div class="expand-arrow">'
        . '<svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" class="bi bi-caret-down-square-fill" viewBox="0 0 16 16">'
        . '<path d="M0 2a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2zm4 4a.5.5 0 0 0-.374.832l4 4.5a.5.5 0 0 0 .748 0l4-4.5A.5.5 0 0 0 12 6z"/>'
        . '</svg></div>';
}

/**
 * Space-joined tag slugs for a row's data-tags attribute (PIC05 tag filter).
 * Slugs, not names: main.js splits this on spaces, and a tag name may well
 * contain one ("slow air") — that would silently shatter into two tokens.
 */
function tune_tag_slugs_attr($tags)
{
    if (!$tags || is_wp_error($tags)) {
        return '';
    }
    return implode(' ', wp_list_pluck($tags, 'slug'));
}

/**
 * Render the grey tag badges. Each one is the entry point to the tag filter:
 * clicking opens a popup offering "show in Tunes" / "show in Sets" (main.js).
 * $tags is a list of WP_Term (get_the_tags()-shaped).
 */
function tune_tag_badges($tags)
{
    if (!$tags || is_wp_error($tags)) {
        return;
    }
    foreach ($tags as $tag) {
        printf(
            '<span class="badge bg-secondary me-1 tag-badge" data-tag="%s" data-tag-name="%s" role="button" tabindex="0">%s</span>',
            esc_attr($tag->slug),
            esc_attr($tag->name),
            esc_html($tag->name)
        );
    }
}

/**
 * The tag currently filtered on, from ?tag=<slug>, or '' if absent/unknown.
 * Resolving the term here means an unknown slug filters nothing at all
 * instead of hiding every row.
 */
function tune_active_tag_filter()
{
    if (empty($_GET['tag'])) {
        return null;
    }
    $term = get_term_by('slug', sanitize_title(wp_unslash($_GET['tag'])), 'post_tag');
    return $term instanceof WP_Term ? $term : null;
}

/**
 * "Tag: Song ✕" chip above the list. Doubles as the filter's single source of
 * truth — main.js reads the active slug off this element, so a tag only ever
 * filters when the server confirmed the term exists.
 */
function tune_tag_filter_chip()
{
    $term = tune_active_tag_filter();
    if (!$term) {
        return;
    }
    printf(
        '<div class="container pt-2"><span class="badge bg-primary tag-filter-chip" data-tag="%s">Tag: %s'
            . ' <a href="%s" class="tag-chip-clear" role="button" aria-label="Remove tag filter">&times;</a></span></div>',
        esc_attr($term->slug),
        esc_html($term->name),
        esc_url(remove_query_arg('tag'))
    );
}

// $q = new WP_Query([
//     'posts_per_page' => -1
// ]);

// while($q->have_posts()){
//     $q->the_post();
//     $sessionJson = json_decode(get_field('json', $id));
//     if (isset($sessionJson->id)){
//         update_field('session_id', $sessionJson->id );
//     }

// }
// wp_reset_postdata();
