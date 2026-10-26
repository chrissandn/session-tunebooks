<!DOCTYPE html>
<html <?php language_attributes(); ?>>

<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <?php
    // viewport-fit=cover: Layout darf unter Notch/Dynamic Island zeichnen — sonst
    // bleibt der Bereich im Querformat ungenutzt weiss. Der Gig-Modus (main.js)
    // gleicht das per env(safe-area-inset-*)-Padding wieder aus.
    //
    // Vom Home-Bildschirm gestartet laeuft die Site ganz ohne Safari-Leisten —
    // das ist auf dem iPhone der einzige verlaessliche Weg zu echtem Vollbild
    // fuer den Session-Gig-Modus (main.js). Manifest kommt aus isg_manifest()
    // (functions.php) statt einer statischen Datei im Theme, weil es dynamisch
    // ins aktuelle WP-Site-Icon zeigt (das je nach Environment eine andere URL
    // hat) und ohne eigenen Rewrite-Regel/Flush auskommt — ein oeffentlicher
    // Query-Var reicht. apple-touch-icon liefert WordPress bereits selbst ueber
    // wp_head(), sobald ein Site-Icon gesetzt ist (Customizer > Website-Icon).
    ?>
    <link rel="manifest" href="<?php echo esc_url(home_url('/?isg_manifest=1')); ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Tunes">
    <meta name="theme-color" content="#3f6b56">
    <!--    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">-->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Rokkitt:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
    <?php wp_head(); ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

    <!--    <script src="http://moinejf.free.fr/js/snd-1.js"></script>-->
    <!---->
    <style>
        .abcr {
            fill: #d00000;
            fill-opacity: 0;
            z-index: 15
        }
    </style>
    <style>
        svg {
            display: block
        }
    </style>
    <style>
        /* Safe-Area-Ausgleich fuer viewport-fit=cover (oben gesetzt).
           Vom Home-Bildschirm gestartet (standalone) zeichnet die Seite bis
           unter Statusleiste und Notch — ohne das hier liegt die Menuezeile
           unter der Uhr und ist dort nicht antippbar. Im normalen Safari-Tab
           sind alle env()-Werte 0, dort aendert sich also nichts.
           Absichtlich am gruenen Menuebalken statt am body: so laeuft der
           Hintergrund randlos bis unter die Statusleiste durch (nativer Look),
           nur der Inhalt rueckt nach. */
        nav#menu {
            padding-top: env(safe-area-inset-top);
        }
        /* Querformat: Inhalt aus der Notch holen. max() haelt im Normalfall
           (inset = 0) exakt Bootstraps eigenes Gutter-Padding. */
        .container,
        .container-fluid {
            padding-left: max(calc(var(--bs-gutter-x, 1.5rem) * 0.5), env(safe-area-inset-left));
            padding-right: max(calc(var(--bs-gutter-x, 1.5rem) * 0.5), env(safe-area-inset-right));
        }
        /* Home-Indicator unten nicht ueberkleben. */
        body {
            padding-bottom: env(safe-area-inset-bottom);
        }

        /* One consolidated top menu (see header.php), same on every width:
           a single line "☰ Tunes Sets Books"; the ☰ opens the rest (Home,
           My Books, … Logout) as a dropdown below. Keeps the top bar one row
           tall so the fixed header never covers the page headline. */
        nav#menu .menu-inner {
            display: flex;
            align-items: center;
            flex-wrap: wrap;      /* lets the secondary menu drop to its own line */
            column-gap: 4px;
        }
        nav#menu .menu-toggle {
            order: 0;
            display: inline-flex;
            align-items: center;
            background: none;
            border: 0;
            color: #fff;
            padding: 6px 10px 6px 0;
            line-height: 1;
            cursor: pointer;
        }
        nav#menu .primary-menu {
            order: 1;
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            flex-wrap: nowrap;    /* Tunes / Sets / Books stay on one line */
        }
        nav#menu .secondary-menu {
            order: 2;
            list-style: none;
            margin: 0;
            padding: 0;
            width: 100%;          /* forces onto its own line below the primary row */
            display: none;        /* hidden until the ☰ opens it */
        }
        nav#menu.open .secondary-menu {
            display: flex;
            flex-direction: column;
            padding-top: 6px;
        }
        nav#menu.open .secondary-menu li { padding: 6px 0; }
    </style>
</head>

<body <?php body_class(); ?>>
    <?php wp_body_open(); ?>
    <div class="totop-button">
        <svg xmlns="http://www.w3.org/2000/svg" fill="currentColor" class="bi bi-arrow-up-square-fill" viewBox="0 0 16 16">
            <path d="M2 16a2 2 0 0 1-2-2V2a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2zm6.5-4.5V5.707l2.146 2.147a.5.5 0 0 0 .708-.708l-3-3a.5.5 0 0 0-.708 0l-3 3a.5.5 0 1 0 .708.708L7.5 5.707V11.5a.5.5 0 0 0 1 0" />
        </svg>
    </div>
    <header>
        <nav id="menu" role="navigation" itemscope itemtype="https://schema.org/SiteNavigationElement">
            <div class="container">
                <div class="menu-inner">
                    <div class="logo" style="display: none;">
                        <a href="<?php home_url() ?>">
                            <svg id="Layer_1" data-name="Layer 1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 500 500">
                                <path d="m269.02.72c.3,11.59.58,23.22.88,35.02,4.77.96,9.42,1.7,13.96,2.85,20.96,5.31,39.01,15.13,50.84,34.03,8.93,14.25,11.39,30.08,10.73,46.6-.57,14.34-3.61,28.22-7.96,41.82-2.19,6.87-1.62,13.08,2.33,19.16,3.54,5.46.71,10.67-5.82,11.13-12.66.88-19.24,7.96-19.13,20.57.12,14.27.93,28.49,5.14,42.24,1.33,4.35,3.22,8.67,5.64,12.51,5.17,8.2,12.7,10.36,21.79,6.91,7.7-2.92,8.66-2.27,8.98,5.87.03.74.9,1.41,1.04,2.18.43,2.39,1.56,5.07.89,7.16-2.59,8.13-1.37,15.76,2.07,23.19,8.33,18,12.08,36.94,13.12,56.71,1.37,26.09-8.35,47.16-27.55,64.11-22.38,19.75-49.2,29.04-78.46,32.19-.66.07-1.32,0-.99,0-.99-15.21-2.58-30.02-2.73-44.85-.16-14.74.42-29.57,2.02-44.22,1.06-9.75,4.6-19.21,6.78-28.86.95-4.2,1.29-8.55,1.85-12.83.59-4.57-.04-5.14-4.66-4.53-.68.09-1.37.1-2.54.18,0-1.5-.04-2.85,0-4.2.55-15.48,1.18-30.96,1.62-46.45.08-2.77,1.01-3.7,3.7-3.5,2.6.19,5.22.04,7.72.04,1.67-7.59.2-9.8-6.72-9.51-3.98.17-4.48-1.49-4.56-4.87-1.14-51.49-2.38-102.98-3.64-154.47-.87-35.52-1.75-71.04-2.63-106.56-.48-.02-.97-.03-1.45-.06.39,16.09.78,32.17,1.18,48.26,1.77,71.29,3.58,142.58,5.37,213.87.02.97,0,1.94,0,3.46h-10.2c0-9.97.08-19.71-.01-29.44-.71-78.79-1.43-157.57-2.15-236.36-.46,0-.93-.01-1.39-.02.79,85.79,1.58,171.59,2.37,257.38.02,2.64,0,5.28,0,8.45h-12.62c0-2.45-.02-4.77,0-7.08.8-80.24,1.61-160.47,2.41-240.71.06-6.02.12-12.03.18-18.05-.44,0-.89,0-1.33.02-.81,83.81-1.64,167.63-2.45,251.44-.05,4.71,0,9.42,0,14.37h-9.54c0-1.62-.03-3.09,0-4.55,2-87.02,4.01-174.04,6.02-261.06-.48.02-.96.03-1.43.05-.11,5.85-.22,11.71-.33,17.56-1.38,68.24-3.06,136.48-4.6,204.72-.29,12.98-.72,25.95-.8,38.93-.02,3.63-.98,4.85-4.73,4.7-6.79-.26-8.5,2.3-6.6,9.48h11.2c.66,18.27,1.31,36.18,1.97,54.08-7.77.41-8.15.48-6.78,8.15,1.91,10.69,4.09,21.36,6.84,31.85,3.55,13.56,4.06,27.34,3.64,41.14-.38,12.4-1.85,24.76-2.59,37.15-.33,5.5-.06,11.03-.06,17.23-5.66-.91-12.13-1.69-18.49-3-21.36-4.4-40.99-12.71-58.04-26.56-23.34-18.97-33.45-43.29-30.79-73.44,1.62-18.31,5.1-35.84,13.61-52.26,3.51-6.77,4.92-13.94,1.65-21.38-.43-.98-.61-2.21-.46-3.27.45-3.28.93-6.58,1.76-9.78.91-3.52,3.33-4.67,6.73-3.12,15.13,6.91,23.18-1.11,27.68-11.85,5.77-13.78,6.65-28.43,7.37-43.09.11-2.23.09-4.47.1-6.7.05-11.11-7.02-18.72-18.13-19.51-.56-.04-1.12,0-1.67-.06-5.76-.54-8.68-5.69-5.52-10.51,4.46-6.79,4.61-13.57,2.23-21.14-5.47-17.41-8.9-35.18-7.31-53.58,2.71-31.34,19.42-52.18,48.37-63.3,8.53-3.28,17.63-5.06,27.08-7.71.3-11.43.6-22.9.9-34.39C102.27,9.94,0,118.02,0,250s111.93,250,250,250,250-111.93,250-250S398.2,10.43,269.02.72Zm-.05,338.2c-.96,2.29-1.79,4.29-2.63,6.29-.73-1.22-1.82-2.36-2.12-3.68-.6-2.66.54-3.93,4.75-2.61Zm-10.06.01c-1.05,2.35-1.93,4.32-2.82,6.29-.71-1.25-1.77-2.42-2.05-3.76-.59-2.76.75-3.78,4.87-2.53Zm-22.19-.48c-.2,2.12-.3,4.13-.68,6.09-.07.33-1.4.41-2.15.61-.53-2.11-1.06-4.22-1.69-6.69h4.52Zm-2.22,15.84c-1.05.03-2.87-1.7-3.05-2.86-.17-1.09,1.37-2.44,2.81-4.71,1.61,2.2,3.23,3.45,3.14,4.56-.1,1.15-1.84,2.98-2.9,3.01Zm.61-23.57c-.63-18.4-1.25-36.45-1.87-54.41,9.17-1.61,9.31-1.5,9.53,6.82.37,14.37.72,28.74,1.07,43.1.04,1.52,0,3.04,0,5.16-2.94-.23-5.61-.43-8.74-.67Zm12.12,7.83c-.52,2.34-.92,4.4-1.5,6.4-.05.18-1.75.19-1.8.02-.59-2-1.01-4.06-1.63-6.81,2.1.17,3.16.25,4.94.39Zm-2.47,15.73c-1.13-.04-2.92-1.76-3.06-2.91-.13-1.09,1.5-2.39,3.04-4.56,1.51,2.17,3.1,3.46,2.98,4.57-.12,1.14-1.94,2.95-2.95,2.91Zm5.7-22.66c-1.48-.19-3-.03-5.07-.03-.53-18.86-1.04-37.06-1.56-55.57h12.53c0,1.68.03,3.29,0,4.89-.28,15.19-.73,30.38-.76,45.57,0,4.01-1.11,5.65-5.13,5.14Zm5.44,22.68c-1.14-.2-2.82-2.08-2.74-3.1.08-1.06,1.96-2.71,3.11-2.76,1.12-.05,2.31,1.6,4.43,3.24-2.31,1.35-3.7,2.82-4.8,2.63Zm1.72-23.61v-54.73h9.58c.1,1.25.3,2.32.27,3.38-.5,14.76-1,29.51-1.58,44.27-.31,7.8-.5,7.9-8.26,7.08Zm8.81,16.06c1.51,2.22,3.1,3.52,2.95,4.61-.15,1.16-1.94,2.95-2.99,2.93-1.09-.02-2.86-1.78-3-2.95-.13-1.08,1.49-2.38,3.04-4.59Z" style="fill: #3f6b56; stroke-width: 0px;" />
                                <path d="m319.36,306.75c-3.23,2.64-6.37,2.41-9.17-.77-4.25-4.84-4.78-10.92-5.17-16.89-.78-12.11-1.01-24.27-1.91-36.37-.61-8.08-1.85-16.16-6.89-22.96-3.06-4.13-7.27-6.09-12.44-5.2-4.68.8-6.44,4.68-7.7,8.54-.46,1.41.17,3.54,1.03,4.87.47.72,3.3.99,3.62.51.89-1.33,1.72-3.39,1.3-4.76-.92-3.03-.15-4.9,2.52-6.15,2.85-1.34,5.26,0,6.37,2.34,1.7,3.59,3.33,7.46,3.77,11.35.63,5.51.16,11.14.16,16.72h.03c0,4.05-.16,8.1.03,12.14.46,9.5,1.02,19.13,5.38,27.74,2.26,4.45,5.69,8.54,9.37,11.96,3.23,3.01,7.87,3.34,11.75.89,4.33-2.74,7.59-6.7,8.05-12.07.26-3.07-1.43-5.72-4.53-6.13-1.89-.25-4.62.56-5.85,1.92-1.96,2.15-1.37,4.96.81,7.11,2.05,2.01,1.38,3.66-.55,5.24Z" style="fill: #3f6b56; stroke-width: 0px;" />
                                <path d="m218.68,234.6c.43,1.56,1.71,2.89,2.62,4.33,1.34-1.32,3.64-2.51,3.83-3.98.63-4.78-4.93-10.44-9.82-10.57-5.27-.14-9.44,2.32-11.58,6.85-2.29,4.85-4.5,10.09-5.06,15.34-1.36,12.88-1.67,25.86-2.59,38.79-.32,4.57-.71,9.25-1.96,13.62-.87,3.05-2.83,6.11-5.1,8.35-2.66,2.62-5.74,1.06-8.18-1.15-1.64-1.48-1.62-3.03.05-4.64,2.33-2.23,2.95-5.21.67-7.38-1.39-1.31-4.39-1.92-6.32-1.43-3.21.81-4.63,3.91-3.78,6.99,1.41,5.08,4.14,9.62,9.33,11.61,5.1,1.95,10.12.42,13.21-3.7,3.52-4.69,7.25-9.95,8.48-15.5,2.44-11.08,3.24-22.52,4.71-33.8l-1.21-.15c0-5.57-.49-11.21.16-16.7.48-4.02,1.92-8.16,3.92-11.7,2.13-3.76,6.69-3.7,8.51-.24.7,1.33-.33,3.48.1,5.07Z" style="fill: #3f6b56; stroke-width: 0px;" />
                            </svg>
                        </a>
                    </div>
                    <?php
                    // Menu is app-structural + permission-driven, so it's rendered here
                    // in one place (previously split between wp_nav_menu('main-menu')
                    // and a hard-coded login block, PIC04). Primary items stay visible
                    // on phones; everything else lives behind the ☰ toggle.
                    //
                    // Books / Settings links appear automatically once their pages
                    // exist (staged rollout: Books = step 2, Settings = step 1c).
                    $menu_link = function ($url, $label, $active = false) {
                        return sprintf(
                            '<li%s><a href="%s">%s</a></li>',
                            $active ? ' class="current-menu-item"' : '',
                            esc_url($url),
                            esc_html($label)
                        );
                    };

                    // --- primary (always visible) ---
                    $primary  = $menu_link(home_url('/tunes'), 'Tunes', is_page('tunes'));
                    $primary .= $menu_link(home_url('/sets'),  'Sets',  is_page('sets'));
                    if (get_page_by_path('books')) {
                        $primary .= $menu_link(home_url('/books'), 'Books', is_page('books'));
                    }

                    // --- secondary (behind the hamburger) ---
                    $secondary = $menu_link(home_url('/'), 'Home', is_front_page());
                    if (is_user_logged_in()) {
                        // (Personal-book management lives on the Books page now — no
                        // separate "My Books" entry.)
                        // Add Tune/Set + Scan Review: contributing users only.
                        if (current_user_can('edit_posts')) {
                            $secondary .= $menu_link(home_url('/add-tune'), 'Add Tune/Set', is_page('add-tune'));
                            $secondary .= $menu_link(home_url('/scan-review'), 'Scan Review', is_page('scan-review'));
                        }
                        if (get_page_by_path('settings')) {
                            $secondary .= $menu_link(home_url('/settings'), 'Settings', is_page('settings'));
                        }
                        if (function_exists('help_page_url')) {
                            $secondary .= $menu_link(help_page_url(), 'Help');
                        }
                        $secondary .= $menu_link(wp_logout_url(home_url()), 'Logout');
                    } else {
                        if (function_exists('help_page_url')) {
                            $secondary .= $menu_link(help_page_url(), 'Help');
                        }
                        $secondary .= $menu_link(wp_login_url(), 'Login');
                    }
                    ?>
                    <button type="button" class="menu-toggle" aria-label="Toggle menu" aria-controls="menu" aria-expanded="false">
                        <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" fill="currentColor" viewBox="0 0 16 16">
                            <path fill-rule="evenodd" d="M2.5 12a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5m0-4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5m0-4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5"/>
                        </svg>
                    </button>
                    <ul class="primary-menu"><?php echo $primary; ?></ul>
                    <ul class="secondary-menu"><?php echo $secondary; ?></ul>
                </div>
            </div>
        </nav>
            </div>
    </header>
    <script>
        (function () {
            var toggle = document.querySelector('#menu .menu-toggle');
            var nav = document.getElementById('menu');
            if (toggle && nav) {
                toggle.addEventListener('click', function () {
                    var open = nav.classList.toggle('open');
                    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                });
            }

            // The header is position:fixed only from the `lg` breakpoint up
            // (style.scss/style.css — below that it sits static in normal flow).
            // Content only needs a top offset equal to the header's height while
            // it's actually fixed; below `lg` applying it anyway pushes the
            // (static) header itself down by its own height on load/navigation,
            // since the padding lands on body, not just the content. Check the
            // computed position rather than hard-coding the breakpoint so the
            // two can't drift apart. Only sync while the menu is closed — an
            // open dropdown overlays content rather than pushing it down.
            var header = document.querySelector('header');
            function syncHeaderOffset() {
                if (!header) return;
                var isFixed = window.getComputedStyle(header).position === 'fixed';
                if (isFixed && !(nav && nav.classList.contains('open'))) {
                    document.body.style.paddingTop = header.offsetHeight + 'px';
                } else {
                    document.body.style.paddingTop = '';
                }
            }
            syncHeaderOffset();
            window.addEventListener('resize', syncHeaderOffset);
            window.addEventListener('load', syncHeaderOffset);
            if (document.fonts && document.fonts.ready) {
                document.fonts.ready.then(syncHeaderOffset);
            }
        })();
    </script>