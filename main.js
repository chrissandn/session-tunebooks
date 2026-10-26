(function($) {

    $(document).ready(function () {
        // Both lists have previews now (step 4), so this is no longer .the-sets-only.
        $(document).on('click', '.expand-arrow', function () {
            $(this).closest('.abc-container').toggleClass('expanded')
        })

        if(!window.matchMedia("(pointer: coarse)").matches) {
            $("[data-bs-toggle=tooltip").tooltip();
        }
       

        // Active tag filter (PIC05), or '' for none. The chip is rendered by
        // PHP only for a tag that actually exists, so reading the slug off it
        // — rather than off the URL — keeps a bogus ?tag= from hiding every row.
        function activeTag() {
            return $('.tag-filter-chip').attr('data-tag') || '';
        }

        function filter_sets(){
            var $sets = $('.set')
            var $favSwitch = $('#favswitch')
            var favSwitchActive = $('#favswitch').hasClass('active');
            var type = $('.type-select').val();
            var book = $('.page-id-44 .book-select').val() || 'all';
            var tag = activeTag();

            var search = $('input#search').val().toLowerCase()
            var searchActive =$('input#search').val().length > 0 ? true :false;
            var favPassedFilter = false;
            var activeSets = 0;

            $sets.each(function (index, el) {
                var passedFavFilter = false;
                var passedTitleFilter = false;
                var passedTypeFilter = false;
                var passedBookFilter = false;
                var passedTagFilter = false;


                if ( !favSwitchActive ){
                    passedFavFilter = true
                } else {
                    // var id = $(this).find('fav-button')
                    if ($(this).find('.fav-button').hasClass('active')) {
                        passedFavFilter = true
                    }
                }

                var title = $(this).find('.settitle').text().toLowerCase();

                if (searchActive){
                    if (title.indexOf(search) > -1 ){
                        passedTitleFilter = true
                    }
                } else passedTitleFilter = true

                if (type == 'all'){
                    passedTypeFilter = true;
                } else {
                    var types = $(el).attr('types')
                    if ( types.indexOf(type) > -1 ){
                        passedTypeFilter = true
                    }
                }

                if (book === 'all') {
                    passedBookFilter = true;
                } else if (book.indexOf('ub:') === 0) {
                    var ubooks = ($(el).attr('data-userbooks') || '').split(' ').filter(Boolean);
                    if (ubooks.indexOf(book.slice(3)) > -1) {
                        passedBookFilter = true;
                    }
                } else {
                    var books = ($(el).attr('data-books') || '').split(' ').filter(Boolean);
                    if (books.indexOf(book) > -1) {
                        passedBookFilter = true;
                    }
                }

                if (tag === '') {
                    passedTagFilter = true;
                } else {
                    var tags = ($(el).attr('data-tags') || '').split(' ').filter(Boolean);
                    if (tags.indexOf(tag) > -1) {
                        passedTagFilter = true;
                    }
                }

                if ( passedFavFilter && passedTitleFilter && passedTypeFilter && passedBookFilter && passedTagFilter ){
                    $(el).slideDown()
                    activeSets ++;
                } else {
                    $(el).slideUp()
                }

            });

            if (activeSets < 1){
                $('.nosets').show()
            } else {
                $('.nosets').hide()
            }

            // $("html, body").animate({scrollTop: 10}, 10, 'swing');
            window.setTimeout(function () {
                window.scrollBy(0, 10);
            }, 400)
            

        }

        $('.page-id-44 #search').on('change keyup', function () {
            filter_sets();
        })

        function filterTunes() {
            var titleSearch = $('#thesearch').val().toLowerCase();
            var type = $('.page-id-62 .type-select').val() || 'all';
            var book = $('.page-id-62 .book-select').val() || 'all';
            var tag = activeTag();
            var favSwitchActive = $('#tunesfavswitch').hasClass('active');
            var activeTunes = 0;

            $('.row.tune-row').each(function () {
                var title = $(this).find('.tunetitle').text().toLowerCase();
                var tags = ($(this).attr('data-tags') || '').split(' ').filter(Boolean);
                var tuneType = ($(this).attr('data-type') || '').toLowerCase();
                var books = ($(this).attr('data-books') || '').split(' ').filter(Boolean);
                var userBooks = ($(this).attr('data-userbooks') || '').split(' ').filter(Boolean);

                var passTitle = titleSearch === '' || title.indexOf(titleSearch) >= 0;
                var passType = type === 'all' || tuneType === type;
                var passBook = book === 'all'
                    || (book.indexOf('ub:') === 0 ? userBooks.indexOf(book.slice(3)) >= 0 : books.indexOf(book) >= 0);
                var passTag = tag === '' || tags.indexOf(tag) >= 0;
                var passFav = !favSwitchActive || $(this).find('.fav-button').hasClass('active');

                if (passTitle && passType && passBook && passTag && passFav) {
                    $(this).show();
                    activeTunes++;
                } else {
                    $(this).hide();
                }
            });

            if (activeTunes < 1) {
                $('.notunes').show();
            } else {
                $('.notunes').hide();
            }
        }

        $('.page-id-62 #thesearch').on('keyup', function () {
            filterTunes();
        })

        $('#favswitch').on('click', function (){
            $(this).toggleClass('active')
            filter_sets();
        })

        $('.clear-field').on('click', function () {
            $(this).siblings('input').val('').trigger('keyup');
        })

        $(window).scroll(function() {
            if ($(this).scrollTop() > 100) {
                $('.totop-button').fadeIn();
            } else {
                $('.totop-button').fadeOut();
            }
        });

        $(".totop-button").click(function() {
            $("html, body").animate({scrollTop: 0}, 200);
        });

        var currentFavs = [];
        const isgfavs = localStorage.getItem("isgfavs");
        if (isgfavs){
            currentFavs = JSON.parse(isgfavs)
        }
        currentFavs.forEach(function (el) {
            if (typeof el === 'number'){
                $('.set[setid='+el+']').find('.fav-button').toggleClass('active')
            }
            
        })

        $('.set .fav-button').on('click', function (e) {
            var id = $(this).closest('.set').attr('setid');

            if (currentFavs.includes(id)) {
                currentFavs = currentFavs.filter(function(item) { return item !== id; });
            } else {
                currentFavs.push(id);
            }
            localStorage.setItem('isgfavs', JSON.stringify(currentFavs));
            $(this).toggleClass('active');

            e.preventDefault();
        })

        var currentTuneFavs = [];
        var itcfavs = localStorage.getItem('itcfavs');
        if (itcfavs) {
            currentTuneFavs = JSON.parse(itcfavs);
        }
        currentTuneFavs.forEach(function (el) {
            if (el) {
                $('.tune-row[data-tuneid="' + el + '"]').find('.fav-button').addClass('active');
            }
        });

        $('.tune-row .fav-button').on('click', function (e) {
            var id = $(this).closest('.tune-row').attr('data-tuneid');

            if (currentTuneFavs.includes(id)) {
                currentTuneFavs = currentTuneFavs.filter(function(item) { return item !== id; });
            } else {
                currentTuneFavs.push(id);
            }
            localStorage.setItem('itcfavs', JSON.stringify(currentTuneFavs));
            $(this).toggleClass('active');

            e.preventDefault();
        });

        $('#tunesfavswitch').on('click', function () {
            $(this).toggleClass('active');
            filterTunes();
        });

        $('.page-id-44 .type-select').on('change', function(){
            filter_sets();
        });

        $('.page-id-62 .type-select').on('change', function(){
            filterTunes();
        });

        $('.page-id-44 .book-select').on('change', function(){
            sessionStorage.setItem('isgBookFilter', $(this).val());
            filter_sets();
        });

        $('.page-id-62 .book-select').on('change', function(){
            sessionStorage.setItem('isgBookFilter', $(this).val());
            filterTunes();
        });

        // Keep the tunebook filter in sync across the Tunes and Sets pages,
        // for the current browser session only (sessionStorage, not persisted).
        var storedBook = sessionStorage.getItem('isgBookFilter');
        if (storedBook && storedBook !== 'all') {
            var $bookSel = $('.book-select');
            if ($bookSel.length && $bookSel.find('option[value="' + storedBook + '"]').length) {
                $bookSel.val(storedBook);
                if ($('body').hasClass('page-id-62')) filterTunes();
                if ($('body').hasClass('page-id-44')) filter_sets();
            }
        }

        // ---- Prev/next tune & set navigation ----
        // single.php/single-set.php are plain full-page loads with no query-string
        // context about "which list was I browsing" — type/book/search/favorite
        // filters on the Tunes/Sets pages live only in the DOM + localStorage,
        // never the URL (see filterTunes()/filter_sets() above). So the only way
        // for the single view to know its neighbours is to capture the visible,
        // already-filtered row order at the moment of the click and hand it
        // forward via sessionStorage, keyed by kind so tunes and sets don't mix.
        // Doesn't survive opening the link in a new tab (fresh sessionStorage
        // there) — prev/next simply don't render then, which is an acceptable
        // fallback rather than a bug.
        function captureNavList($rows, linkSel, kind) {
            var list = [];
            $rows.each(function () {
                if (!$(this).is(':visible')) return;
                var id = parseInt($(this).attr('data-navid'), 10);
                if (!id) return;
                var $link = $(this).find(linkSel).first();
                if (!$link.length) return;
                list.push({ id: id, title: $link.text().trim(), url: $link.attr('href') });
            });
            try { sessionStorage.setItem('navList_' + kind, JSON.stringify(list)); } catch (e) {}
        }

        // Tunes list (page-tunes.php) — whole page is tunes.
        $(document).on('click', '.page-id-62 .row.tune-row .tunetitle', function () {
            captureNavList($('.page-id-62 .row.tune-row'), '.tunetitle', 'tune');
        });

        // Sets list (page-sets.php) — whole page is sets.
        $(document).on('click', '.page-id-44 .set .settitle a', function () {
            captureNavList($('.page-id-44 .set'), '.settitle a', 'set');
        });

        // Book contents (tune-books.php, shared by page-books.php and
        // taxonomy-tunebook.php) — tunes and sets share the same .tune-row
        // markup there, split by data-navkind. No client-side filtering on
        // this view, so every row is already :visible.
        $(document).on('click', '.row.tune-row[data-navkind="tune"] .tunetitle', function () {
            captureNavList($('.row.tune-row[data-navkind="tune"]'), '.tunetitle', 'tune');
        });
        $(document).on('click', '.row.tune-row[data-navkind="set"] .tunetitle', function () {
            captureNavList($('.row.tune-row[data-navkind="set"]'), '.tunetitle', 'set');
        });

        // single.php/single-set.php set window.__navCurrentId/__navKind inline.
        if (window.__navCurrentId && window.__navKind) {
            var navRaw = null;
            try { navRaw = sessionStorage.getItem('navList_' + window.__navKind); } catch (e) {}
            if (navRaw) {
                var navList = [];
                try { navList = JSON.parse(navRaw); } catch (e) {}
                var navIdx = -1;
                for (var ni = 0; ni < navList.length; ni++) {
                    if (navList[ni].id === window.__navCurrentId) { navIdx = ni; break; }
                }
                if (navIdx > 0) {
                    var navPrev = navList[navIdx - 1];
                    $('.tune-nav-prev').attr('href', navPrev.url).text('‹ ' + navPrev.title).show();
                }
                if (navIdx > -1 && navIdx < navList.length - 1) {
                    var navNext = navList[navIdx + 1];
                    $('.tune-nav-next').attr('href', navNext.url).text(navNext.title + ' ›').show();
                }
            }
        }

        // ---- Tag popup filter (PIC05) ----
        // A tag badge asks a question the badge itself can't answer: show this
        // tag's tunes, or its sets? So a click opens a two-choice popup rather
        // than filtering straight away. One shared popup element, delegated —
        // the Tunes page alone renders 140+ badges, and per-badge Bootstrap
        // dropdown markup for all of them would be pure DOM weight.
        // Chosen tag -> /tunes|sets/?tag=<slug>; the page then renders the chip
        // and filters on load. Clearing is the chip's own plain ?tag=-less link.
        if (!document.getElementById('tag-pop-css')) {
            var tagCss = document.createElement('style');
            tagCss.id = 'tag-pop-css';
            tagCss.textContent =
                '.tag-badge{cursor:pointer}' +
                '.tag-filter-chip .tag-chip-clear{color:inherit;text-decoration:none;padding-left:6px;font-weight:700}' +
                '.tag-pop{position:absolute;z-index:1080;min-width:150px;background:var(--bs-body-bg,#fff);' +
                'border:1px solid rgba(0,0,0,.18);border-radius:6px;box-shadow:0 6px 18px rgba(0,0,0,.18);padding:4px 0}' +
                '.tag-pop .tag-pop-head{padding:4px 12px;font-size:.75rem;opacity:.6;white-space:nowrap}' +
                '.tag-pop a{display:block;padding:6px 12px;text-decoration:none;color:inherit;white-space:nowrap}' +
                '.tag-pop a:hover{background:rgba(0,0,0,.07)}';
            document.head.appendChild(tagCss);
        }

        function closeTagPop() {
            $('.tag-pop').remove();
        }

        function tagUrl(base, slug) {
            var url = new URL(base, window.location.origin);
            url.searchParams.set('tag', slug);
            return url.toString();
        }

        $(document).on('click', '.tag-badge', function (e) {
            e.preventDefault();
            e.stopPropagation();

            var $badge = $(this);
            var slug = $badge.attr('data-tag');
            var name = $badge.attr('data-tag-name') || slug;
            var cfg = window.isgTagFilter || {};
            if (!slug || !cfg.tunesUrl) return;

            var wasOpen = $('.tag-pop').data('tag') === slug;
            closeTagPop();
            if (wasOpen) return;   // second click on the same badge closes it

            var $pop = $('<div class="tag-pop"></div>').data('tag', slug);
            $('<div class="tag-pop-head"></div>').text(name).appendTo($pop);
            $('<a></a>').attr('href', tagUrl(cfg.tunesUrl, slug)).text('Show in Tunes').appendTo($pop);
            $('<a></a>').attr('href', tagUrl(cfg.setsUrl, slug)).text('Show in Sets').appendTo($pop);

            var off = $badge.offset();
            $pop.appendTo('body').css({ top: off.top + $badge.outerHeight() + 2, left: off.left });

            // Keep it on screen on narrow viewports.
            var overflow = (off.left + $pop.outerWidth()) - ($(window).width() - 8);
            if (overflow > 0) $pop.css('left', Math.max(8, off.left - overflow));
        });

        // Clicks inside the popup must not reach the close-on-outside-click
        // handler below: tearing the <a> out of the DOM mid-dispatch can cancel
        // the navigation it was meant to trigger.
        $(document).on('click', '.tag-pop', function (e) { e.stopPropagation(); });
        $(document).on('click', function () { closeTagPop(); });
        $(document).on('keydown', function (e) { if (e.key === 'Escape') closeTagPop(); });
        $(document).on('keydown', '.tag-badge', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); $(this).trigger('click'); }
        });

        // ?tag= is server-validated into the chip; apply it on load.
        if (activeTag()) {
            if ($('body').hasClass('page-id-62')) filterTunes();
            if ($('body').hasClass('page-id-44')) filter_sets();
        }

        // "Show original scans" toggle (Feature #8 stage 2): a display mode,
        // not a row filter, so it applies on every page that can render a
        // scan-block (single.php/single-set.php/page-random-set.php have no
        // control of their own, they only read the stored value). The control
        // itself now lives on the Settings page (PIC01a); it's a persistent
        // per-browser preference, so it's kept in localStorage (not session).
        $('.show-scans-toggle').on('change', function () {
            var checked = $(this).is(':checked');
            localStorage.setItem('isgShowScans', checked ? '1' : '0');
            $('body').toggleClass('show-scans', checked);
        });
        var storedShowScans = localStorage.getItem('isgShowScans') === '1';
        if (storedShowScans) {
            $('body').addClass('show-scans');
        }
        $('.show-scans-toggle').prop('checked', storedShowScans);

        // Tunes one-liner preview: off (default) | abc | all. Unlike show-scans
        // this isn't applied here — page-tunes.php reads it inline while parsing,
        // because it decides whether abcweb-1.js gets loaded at all. So this only
        // stores the choice; the Tunes list picks it up on its next load.
        var storedPreview = localStorage.getItem('isgTunesPreview') || 'off';
        $('.tunes-preview-select').val(
            $('.tunes-preview-select option[value="' + storedPreview + '"]').length ? storedPreview : 'off'
        );
        $('.tunes-preview-select').on('change', function () {
            localStorage.setItem('isgTunesPreview', $(this).val());
        });

        jQuery.fn.reverse = [].reverse;

        $('.age-select').on('change', function(){
           var age = $(this).val()
           let url = new URL(window.location.href);
           url.searchParams.set('order', age);
           window.location.href = url.toString();

        });

        // Add the current bookmark selection (localStorage) to a personal book.
        $('.ub-add-bookmarked').on('click', function () {
            var $bar = $(this).closest('.ub-bulkbar');
            var type = $bar.data('type');                 // 'tune' | 'set'
            var pt = type === 'set' ? 'set' : 'post';
            var storeKey = type === 'set' ? 'isgfavs' : 'itcfavs';
            var favSelector = type === 'set' ? '.set .fav-button' : '.tune-row .fav-button';
            var bookId = $bar.find('.ub-book-target').val();
            var $status = $bar.find('.ub-bulk-status');

            var ids = [];
            try { ids = (JSON.parse(localStorage.getItem(storeKey) || '[]') || []).filter(Boolean); } catch (e) {}
            if (!ids.length) { $status.text('No bookmarked ' + type + 's — mark some first.'); return; }

            var body = new URLSearchParams();
            body.append('action', 'ub_add_bulk');
            body.append('_wpnonce', $bar.data('nonce'));
            body.append('book_id', bookId);
            body.append('pt', pt);
            ids.forEach(function (id) { body.append('ids[]', id); });

            $status.text('Adding…');
            fetch($bar.data('ajax'), { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (!j.success) { $status.text('Error: ' + (j.data || 'failed')); return; }
                    var d = j.data;
                    $status.text('Added ' + d.added +
                        (d.already ? ', ' + d.already + ' already in book' : '') +
                        (d.invalid ? ', ' + d.invalid + ' skipped' : '') + '.');
                    if (d.added > 0) {
                        localStorage.removeItem(storeKey);
                        $(favSelector).removeClass('active');
                    }
                })
                .catch(function (e) { $status.text('Error: ' + e.message); });
        });

        // Create a new set from the currently bookmarked TUNES (localStorage
        // itcfavs). Tunes-page only; editor/admin-gated server-side
        // (ub_ajax_add_bulk_to_set). Appending to an existing set isn't
        // exposed here - that's already possible on the Sets page itself.
        $('.ub-add-bookmarked-set').on('click', function () {
            var $bar = $(this).closest('.ub-bulkbar');
            var $status = $bar.find('.ub-bulk-set-status');
            var newTitle = $bar.find('.ub-new-set-title').val() || '';

            var ids = [];
            try { ids = (JSON.parse(localStorage.getItem('itcfavs') || '[]') || []).filter(Boolean); } catch (e) {}
            if (!ids.length) { $status.text('No bookmarked tunes — mark some first.'); return; }
            if (!newTitle.trim()) { $status.text('Enter a name for the new set.'); return; }

            var body = new URLSearchParams();
            body.append('action', 'ub_add_bulk_to_set');
            body.append('_wpnonce', $bar.data('nonce'));
            body.append('set_id', '');
            body.append('new_set_title', newTitle);
            ids.forEach(function (id) { body.append('ids[]', id); });

            $status.text('Adding…');
            fetch($bar.data('ajax'), { method: 'POST', credentials: 'same-origin', body: body })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (!j.success) { $status.text('Error: ' + (j.data || 'failed')); return; }
                    var d = j.data;
                    var $link = $('<a target="_blank" rel="noopener"></a>').attr('href', d.url).text(d.title);
                    $status.empty().append('Added to ').append($link).append(' (' + d.count + '/5).');
                    localStorage.removeItem('itcfavs');
                    $('.tune-row .fav-button').removeClass('active');
                })
                .catch(function (e) { $status.text('Error: ' + e.message); });
        });

        // ---- Remove-from-book: swipe (mobile) / hover trash (desktop) ----
        // One mechanism for both book types. Each removable row is a .swipe-del
        // carrying data-del-action (ub_remove_item = personal book, owner-only;
        // tunebook_remove_item = public session book, editor-only) plus the params
        // and nonce. Only the book membership is removed — never the tune/set.
        (function () {
            // Inject the CSS once.
            if (!document.getElementById('swipe-del-css')) {
                var css = document.createElement('style');
                css.id = 'swipe-del-css';
                css.textContent =
                    '.swipe-del{position:relative;overflow:hidden}' +
                    '.swipe-del .swipe-del-content{transition:transform .2s ease;position:relative;z-index:1;background:var(--bs-body-bg,#fff)}' +
                    '.swipe-del.swiped .swipe-del-content{transform:translateX(-72px)}' +
                    '.swipe-del .swipe-del-btn{position:absolute;top:0;right:0;height:100%;width:72px;border:0;padding:0;background:#dc3545;color:#fff;display:flex;align-items:center;justify-content:center;z-index:0}' +
                    '@media (hover:hover) and (pointer:fine){.swipe-del .swipe-del-btn{opacity:0;z-index:2;transition:opacity .12s}.swipe-del:hover .swipe-del-btn{opacity:1}}';
                document.head.appendChild(css);
            }

            function closeAll(except) {
                document.querySelectorAll('.swipe-del.swiped').forEach(function (r) {
                    if (r !== except) r.classList.remove('swiped');
                });
            }

            function delBody(row, action) {
                var a = action || row.dataset.delAction;
                var p = new URLSearchParams();
                p.append('action', a);
                p.append('_wpnonce', row.dataset.delNonce);
                p.append('id', row.dataset.delId);
                if (row.dataset.delAction === 'ub_remove_item') {
                    p.append('book_id', row.dataset.delBook);
                    p.append('pt', row.dataset.delPt);
                } else {
                    p.append('term', row.dataset.delTerm);
                }
                return p;
            }

            function post(row, action) {
                return fetch(row.dataset.delAjax, {
                    method: 'POST', credentials: 'same-origin', body: delBody(row, action)
                }).then(function (r) { return r.json(); })
                  .then(function (j) {
                      if (!j.success) throw new Error((j.data && j.data.message) || j.data || 'failed');
                      return j.data;
                  });
            }

            function dropRow(row) {
                var book = row.closest('.ub-book');
                row.remove();
                if (book) {
                    var c = book.querySelector('.ub-count');
                    if (c) c.textContent = book.querySelectorAll('.ub-items li').length;
                }
            }

            // Session-book dialog CSS (injected like the swipe CSS above).
            if (!document.getElementById('book-del-css')) {
                var dcss = document.createElement('style');
                dcss.id = 'book-del-css';
                dcss.textContent =
                    '.bd-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1090;display:flex;align-items:center;justify-content:center;padding:16px}' +
                    '.bd-dialog{background:var(--bs-body-bg,#fff);border-radius:8px;max-width:460px;width:100%;padding:18px;box-shadow:0 10px 40px rgba(0,0,0,.3)}' +
                    '.bd-dialog h5{margin:0 0 10px}' +
                    '.bd-usage{font-size:.9rem;margin:0 0 14px;padding:10px;border-radius:6px;background:rgba(0,0,0,.05)}' +
                    '.bd-usage.bd-warn{background:#fff3cd;color:#664d03}' +
                    '.bd-usage ul{margin:4px 0 0;padding-left:18px}' +
                    '.bd-actions{display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end}' +
                    '.bd-actions button{border:0;border-radius:6px;padding:8px 12px;cursor:pointer}' +
                    '.bd-cancel{background:rgba(0,0,0,.08)}' +
                    '.bd-remove{background:#0a58ca;color:#fff}' +
                    '.bd-delete{background:#dc3545;color:#fff}' +
                    '.bd-busy{opacity:.6;pointer-events:none}';
                document.head.appendChild(dcss);
            }

            function closeDialog() {
                var d = document.querySelector('.bd-backdrop');
                if (d) d.remove();
            }

            // Two different destructive acts hide behind one trash icon, so the
            // dialog names both and lets the user pick: drop the book membership
            // (the KTN-and-Graz case) or bin the tune itself. Counts come from
            // the server right before the decision, never from stale markup.
            function openBookDialog(row, info) {
                closeDialog();
                var isSet = info.type === 'set';
                var backdrop = document.createElement('div');
                backdrop.className = 'bd-backdrop';
                var dlg = document.createElement('div');
                dlg.className = 'bd-dialog';
                backdrop.appendChild(dlg);

                var h = document.createElement('h5');
                h.textContent = (info.title || 'This item');
                dlg.appendChild(h);

                var usage = document.createElement('div');
                usage.className = 'bd-usage';
                var used = (info.books.length + info.sets.length) > 0;
                if (used) usage.classList.add('bd-warn');

                function line(text) {
                    var pEl = document.createElement('div');
                    pEl.textContent = text;
                    usage.appendChild(pEl);
                }
                function list(items) {
                    var ul = document.createElement('ul');
                    items.forEach(function (n) {
                        var li = document.createElement('li');
                        li.textContent = n;
                        ul.appendChild(li);
                    });
                    usage.appendChild(ul);
                }

                if (info.books.length) {
                    line('Also in ' + info.books.length + ' other book' + (info.books.length > 1 ? 's' : '') + ':');
                    list(info.books);
                } else {
                    line('In no other book.');
                }
                if (!isSet) {
                    if (info.sets.length) {
                        line('Used in ' + info.sets.length + ' set' + (info.sets.length > 1 ? 's' : '') + ':');
                        list(info.sets);
                        line('Deleting it removes it from those sets too.');
                    } else {
                        line('Used in no set.');
                    }
                } else {
                    line('Deleting the set does not delete its tunes.');
                }
                dlg.appendChild(usage);

                var actions = document.createElement('div');
                actions.className = 'bd-actions';

                var cancel = document.createElement('button');
                cancel.className = 'bd-cancel';
                cancel.textContent = 'Cancel';
                cancel.onclick = function () { row.classList.remove('swiped'); closeDialog(); };
                actions.appendChild(cancel);

                var remove = document.createElement('button');
                remove.className = 'bd-remove';
                remove.textContent = info.book ? 'Remove from ' + info.book : 'Remove from book';
                remove.onclick = function () { run('tunebook_remove_item'); };
                actions.appendChild(remove);

                if (info.can_delete) {
                    var del = document.createElement('button');
                    del.className = 'bd-delete';
                    del.textContent = isSet ? 'Delete set' : 'Delete tune';
                    del.onclick = function () { run('tunebook_delete_item'); };
                    actions.appendChild(del);
                }
                dlg.appendChild(actions);

                function run(action) {
                    dlg.classList.add('bd-busy');
                    post(row, action)
                        .then(function () { closeDialog(); dropRow(row); })
                        .catch(function (err) {
                            dlg.classList.remove('bd-busy');
                            alert('Could not complete: ' + err.message);
                        });
                }

                backdrop.addEventListener('click', function (e) {
                    if (e.target === backdrop) { row.classList.remove('swiped'); closeDialog(); }
                });
                document.addEventListener('keydown', function esc(e) {
                    if (e.key === 'Escape') { row.classList.remove('swiped'); closeDialog(); document.removeEventListener('keydown', esc); }
                });

                document.body.appendChild(backdrop);
                cancel.focus();
            }

            // Trash click. Personal books stay a plain confirm (removing an item
            // from your own list is trivially reversible — re-add it). Session
            // books open the dialog, because "delete for everyone" hides there.
            document.addEventListener('click', function (e) {
                var btn = e.target.closest('.swipe-del-btn');
                if (!btn) return;
                var row = btn.closest('.swipe-del');
                if (!row) return;
                e.preventDefault();

                if (row.dataset.delAction !== 'tunebook_remove_item') {
                    if (!window.confirm('Remove this from the book?')) { row.classList.remove('swiped'); return; }
                    post(row).then(function () { dropRow(row); })
                        .catch(function (err) { alert('Could not remove: ' + err.message); });
                    return;
                }

                post(row, 'tunebook_item_usage')
                    .then(function (info) { openBookDialog(row, info); })
                    .catch(function (err) { alert('Could not load usage: ' + err.message); });
            });

            // Touch swipe-left to reveal the trash.
            var sx = null, srow = null, scontent = null, dx = 0;
            document.addEventListener('touchstart', function (e) {
                var row = e.target.closest('.swipe-del');
                closeAll(row);
                if (!row || e.target.closest('.swipe-del-btn')) { srow = null; return; }
                sx = e.touches[0].clientX;
                srow = row;
                scontent = row.querySelector('.swipe-del-content');
                dx = 0;
            }, { passive: true });
            document.addEventListener('touchmove', function (e) {
                if (!srow || !scontent) return;
                dx = e.touches[0].clientX - sx;
                var t = Math.max(-72, Math.min(0, dx));
                scontent.style.transform = 'translateX(' + t + 'px)';
            }, { passive: true });
            document.addEventListener('touchend', function () {
                if (!srow) return;
                if (scontent) scontent.style.transform = '';
                if (dx < -36) srow.classList.add('swiped'); else srow.classList.remove('swiped');
                sx = null; srow = null; scontent = null; dx = 0;
            });
        })();

        // ===== Session-Gig: ablenkungsfreier Fullscreen-Modus =====
        // Tipp in die Mitte eines angezeigten Tunes/Set-Tunes -> Overlay,
        // auto-fit (Hoch/Quer), 2-Finger-Pinch/Pan, Tipp links/rechts = Prev/Next.
        // "Fullscreen" ist ein CSS-Overlay (iOS Safari kann die Fullscreen-API
        // nicht fuer Nicht-<video>-Elemente, und das Overlay muss den
        // reload-basierten Tune-Wechsel ueberleben). Sets blaettern in-place
        // (single-set.php hat alle Tunes schon im DOM); Einzeltunes reklicken die
        // bestehenden .tune-nav-*-Links + #gig.
        (function gigMode() {
            if (window.__gigInit) return; window.__gigInit = true;

            // CSS per JS injizieren (gleiches Muster wie tag-pop-css oben) -
            // spart den Gulp/SCSS-Build.
            if (!document.getElementById('gig-css')) {
                var css = document.createElement('style');
                css.id = 'gig-css';
                css.textContent =
                    // 100dvh statt inset:0 (~= 100vh): dvh verfolgt live, wieviel
                    // Flaeche iOS Safari nach Adress-/Tabzeile tatsaechlich freigibt.
                    // vh/inset sind auf die "grosse" Ansicht bei eingeklappter Chrome
                    // geeicht -> das Notenbild ragte sonst unerreichbar (overflow:
                    // hidden, kein Scroll) hinter die sichtbare Leiste.
                    '#gig-overlay{position:fixed;inset:0;height:100dvh;z-index:20000;background:#fff;display:none;flex-direction:column;overflow:hidden;touch-action:none}' +
                    // Buehne ist ein normales Flex-Kind (nicht mehr absolut positioniert)
                    // - blendet main.js die Leiste ein/aus, waechst/schrumpft die
                    // Buehne automatisch mit; fit()/apply() lesen ihre Groesse direkt
                    // von stage.getBoundingClientRect(), keine manuelle Hoehenrechnung.
                    '.gig-stage{position:relative;flex:1 1 auto;min-height:0;display:flex;align-items:center;justify-content:center;overflow:hidden;touch-action:none;-webkit-user-select:none;user-select:none;cursor:pointer}' +
                    '.gig-content{transform-origin:center center;will-change:transform;line-height:0;opacity:0;transition:opacity .15s}' +
                    '.gig-content>svg,.gig-content>img,.gig-content>canvas{display:block;max-width:none!important;max-height:none!important;margin:0!important}' +
                    '.gig-content .abc-block{display:inline-block!important;width:auto!important;max-width:none!important;margin:0!important;padding:0!important;background:transparent!important}' +
                    '.gig-content .abc-block svg{display:block!important;max-width:none!important;margin:0 auto 6px auto!important}' +
                    // Leiste (Tune-Name + ABC/Scan-Umschalter + X) ist der Standard-
                    // zustand - immer sichtbar, in dieser Groesse, kein Overlay mehr
                    // auf dem Notenbild. Mitte-Tap blendet sie zum Platzsparen aus
                    // (.hide), siehe toggleBar(). safe-area-inset haelt sie unter
                    // Notch/Dynamic Island im Querformat lesbar.
                    '.gig-bar{flex:0 0 auto;display:flex;align-items:center;gap:.4rem;padding:.5rem .7rem;padding-top:calc(.5rem + env(safe-area-inset-top));padding-left:calc(.7rem + env(safe-area-inset-left));padding-right:calc(.7rem + env(safe-area-inset-right));background:rgba(255,255,255,.97);border-bottom:1px solid rgba(0,0,0,.12);z-index:2}' +
                    '.gig-bar.hide{display:none}' +
                    '.gig-title{flex:1;min-width:0;font-weight:600;font-size:1rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#111}' +
                    '.gig-bar button{border:0;background:transparent;font-size:1rem;padding:.25rem .55rem;cursor:pointer;color:#111;border-radius:6px;line-height:1}' +
                    '.gig-bar button:active{background:rgba(0,0,0,.09)}' +
                    '.gig-close{font-size:1.5rem!important}' +
                    '.gig-toggle{border:1px solid rgba(0,0,0,.2)!important;font-size:.8rem!important}' +
                    '.gig-hint{position:absolute;left:0;right:0;bottom:calc(14px + env(safe-area-inset-bottom));text-align:center;font-size:.8rem;color:rgba(0,0,0,.45);pointer-events:none;transition:opacity .5s}' +
                    // Home-Bildschirm-Tipp: eigene Flex-Zeile direkt unter der Leiste
                    // (nicht absolut positioniert) - erscheint nur kurz und einmalig,
                    // siehe maybeShowHomeTip(); die Buehne reklamiert den Platz danach
                    // automatisch übers Flex-Layout, kein manuelles Refit-Rechnen noetig.
                    '.gig-hstip{display:none;flex:0 0 auto;align-items:center;gap:.6rem;padding:.5rem .8rem;padding-left:calc(.8rem + env(safe-area-inset-left));padding-right:calc(.8rem + env(safe-area-inset-right));background:#222;color:#fff;font-size:.78rem;line-height:1.35}' +
                    '.gig-hstip.show{display:flex}' +
                    '.gig-hstip span{flex:1;min-width:0}' +
                    '.gig-hstip button{flex:0 0 auto;border:0;background:rgba(255,255,255,.18);color:#fff;border-radius:6px;padding:.15rem .5rem;font-size:.9rem;cursor:pointer;line-height:1}';
                document.head.appendChild(css);
            }

            var overlay, stage, content, bar, titleEl, toggleBtn, hintEl, homeTip;
            // Scan-vs-ABC-Wahl haelt ueber Tunes UND ueber Seitenwechsel hinweg: das
            // Blaettern durch Einzeltunes laedt die Seite neu (location.href + '#gig',
            // siehe navigate()), womit ein reiner In-Memory-Zustand jedes Mal verfiel
            // und der Gig-Modus zurueck auf ABC sprang. Darum in localStorage, wie die
            // "Show original scans"-Einstellung: eine Geraete-Vorliebe, keine Session.
            // Der Fallback bleibt in pickContent(): fehlt die bevorzugte Variante bei
            // einem Tune, wird die andere gezeigt -- die Vorliebe selbst bleibt gesetzt
            // und greift beim naechsten Tune wieder.
            var GIG_PREF_KEY = 'isgGigPref';
            function readPref() {
                try {
                    return localStorage.getItem(GIG_PREF_KEY) === 'scan' ? 'scan' : 'abc';
                } catch (e) { return 'abc'; }
            }
            function setPref(p) {
                state.pref = (p === 'scan') ? 'scan' : 'abc';
                try { localStorage.setItem(GIG_PREF_KEY, state.pref); } catch (e) {}
            }
            var state = { mode: null, items: [], idx: 0, pref: readPref(), node: null,
                          fit: 1, userScale: 1, tx: 0, ty: 0, nw: 1, nh: 1 };
            var savedScrollY = 0;

            // Screen Wake Lock: Notenblatt soll beim Spielen nicht abdunkeln. Rein
            // optional - auf nicht unterstuetzten oder verweigernden Browsern (auch
            // iOS-Safari-Versionen ohne die API) bleibt der Gig-Modus unveraendert
            // nutzbar, daher die stillen catch()s.
            var wakeLock = null;
            function requestWakeLock() {
                if (!('wakeLock' in navigator)) return;
                navigator.wakeLock.request('screen').then(function (wl) {
                    wakeLock = wl;
                    wakeLock.addEventListener('release', function () { wakeLock = null; });
                }).catch(function () {});
            }
            function releaseWakeLock() {
                if (wakeLock) { wakeLock.release().catch(function () {}); wakeLock = null; }
            }
            // Der Browser gibt den Wake Lock automatisch frei, sobald der Tab in den
            // Hintergrund geht (App-Wechsel, Sperrbildschirm) - beim Zurueckkehren
            // waehrend der Gig-Modus noch offen ist, erneut anfordern.
            document.addEventListener('visibilitychange', function () {
                if (document.visibilityState === 'visible' && overlay && overlay.style.display !== 'none') {
                    requestWakeLock();
                }
            });

            // ===== Home-Bildschirm-Tipp (nur iOS, nicht standalone, nur einmal) =====
            // Vom Home-Bildschirm gestartet laeuft die Site ganz ohne Safari-Chrome -
            // der einzige verlaessliche Weg zu echtem Vollbild auf dem iPhone
            // (Manifest siehe functions.php isg_manifest()). Reiner Hinweis, kein
            // Blocker: wer ihn verpasst oder wegtippt, nutzt den Gig-Modus im Tab
            // unveraendert weiter.
            var HOME_TIP_KEY = 'isgGigHomeTipSeen';
            function isIOSish() {
                return /iPad|iPhone|iPod/.test(navigator.userAgent) ||
                    (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1); // iPadOS 13+
            }
            function isStandalone() {
                return window.navigator.standalone === true ||
                    (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);
            }
            function maybeShowHomeTip() {
                if (!homeTip || !isIOSish() || isStandalone()) return;
                try {
                    if (localStorage.getItem(HOME_TIP_KEY)) return;
                    localStorage.setItem(HOME_TIP_KEY, '1');
                } catch (e) { return; } // Private-Mode o.ae. verweigert Storage -> lieber gar nicht zeigen als jedes Mal neu
                homeTip.classList.add('show');
                fit();
                setTimeout(hideHomeTip, 7000);
            }
            function hideHomeTip() {
                if (!homeTip || !homeTip.classList.contains('show')) return;
                homeTip.classList.remove('show');
                fit();
            }

            function qs(s, r) { return (r || document).querySelector(s); }
            function qsa(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
            function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }
            function dist(a, b) { return Math.hypot(a.x - b.x, a.y - b.y); }

            function build() {
                if (overlay) return;
                overlay = document.createElement('div');
                overlay.id = 'gig-overlay';
                stage = document.createElement('div'); stage.className = 'gig-stage';
                content = document.createElement('div'); content.className = 'gig-content';
                stage.appendChild(content);
                bar = document.createElement('div'); bar.className = 'gig-bar';
                titleEl = document.createElement('span'); titleEl.className = 'gig-title';
                toggleBtn = document.createElement('button'); toggleBtn.className = 'gig-toggle'; toggleBtn.type = 'button'; toggleBtn.textContent = 'ABC / Scan';
                var closeBtn = document.createElement('button'); closeBtn.className = 'gig-close'; closeBtn.type = 'button'; closeBtn.setAttribute('aria-label', 'Close'); closeBtn.innerHTML = '&times;';
                bar.appendChild(titleEl); bar.appendChild(toggleBtn); bar.appendChild(closeBtn);
                homeTip = document.createElement('div'); homeTip.className = 'gig-hstip';
                var tipText = document.createElement('span'); tipText.textContent = 'For true full screen without Safari bars: Share icon → “Add to Home Screen”';
                var tipClose = document.createElement('button'); tipClose.type = 'button'; tipClose.setAttribute('aria-label', 'Close'); tipClose.innerHTML = '&times;';
                tipClose.addEventListener('click', function (e) { e.stopPropagation(); hideHomeTip(); });
                homeTip.appendChild(tipText); homeTip.appendChild(tipClose);
                hintEl = document.createElement('div'); hintEl.className = 'gig-hint'; hintEl.textContent = '‹ tap: turn page  ·  middle: toggle bar  ›';
                // Reihenfolge ist hier layoutrelevant (#gig-overlay ist jetzt
                // flex-direction:column, keine absolute Positionierung mehr für
                // Leiste/Buehne) - Leiste + Tipp zuerst, Buehne nimmt den Rest.
                overlay.appendChild(bar); overlay.appendChild(homeTip); overlay.appendChild(stage); overlay.appendChild(hintEl);
                document.body.appendChild(overlay);

                closeBtn.addEventListener('click', function (e) { e.stopPropagation(); close(); });
                toggleBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    setPref((state.pref === 'scan') ? 'abc' : 'scan');
                    showContent(state.items[state.idx]);
                });
                wireGestures();
            }

            // Auf eine noch nicht fertige Darstellung warten: MutationObserver plus
            // Polling (manche Renderer melden sich nicht ueber DOM-Mutationen im
            // beobachteten Teilbaum), mit Deadline.
            function waitFor(root, test, timeout, tick) {
                return new Promise(function (resolve) {
                    if (test()) return resolve();
                    var done = false;
                    function finish() { if (done) return; done = true; obs.disconnect(); clearInterval(iv); clearTimeout(to); resolve(); }
                    function check() { if (test()) finish(); }
                    var obs = new MutationObserver(check);
                    obs.observe(root, { childList: true, subtree: true });
                    var iv = setInterval(function () { if (tick) tick(); check(); }, 70);
                    var to = setTimeout(finish, timeout);
                    check();
                });
            }

            // abc2svg rendert viewport-lazy (js/abcweb-1.js) - ein Set-Tune unter
            // dem Fold ist evtl. noch nicht gerendert. In Sicht scrollen (unsichtbar
            // unter dem opaken Overlay) + auf das <svg> warten.
            //
            // PDF-Scans brauchen dieselbe Behandlung: js/pdf-embed.js zeichnet erst
            // <canvas>-Seiten, nachdem ~3 MB PDF.js geladen und das Dokument geparst
            // sind. Darauf wurde hier nie gewartet -- bei einem Tune OHNE ABC fand
            // pickContent() dann weder svg noch canvas, showContent() brach ab und der
            // Gig-Modus zeigte eine weisse Buehne (2026-08-15 an "La Tête Ailleurs"
            // aufgeschlagen; vorher nie gesehen, weil PDF-Scans wegen des
            // .mjs-MIME-Fehlers ueberhaupt nie gerendert haben, siehe BACKLOG #15).
            // Deadline hier groesszuegiger als bei abc2svg: es haengt ein echter
            // Netzwerk-Download dran.
            function ensureRendered(item) {
                var jobs = [];
                var abc = item.querySelector('.abc-block');
                if (abc && !abc.querySelector('svg')) {
                    try { item.scrollIntoView({ block: 'center' }); } catch (e) {}
                    try { window.dispatchEvent(new Event('scroll')); } catch (e) {}
                    jobs.push(waitFor(abc, function () { return !!abc.querySelector('svg'); }, 2200,
                        function () { try { window.dispatchEvent(new Event('scroll')); } catch (e) {} }));
                }
                // Ein noch nicht geladenes <img> hat keine Eigengroesse: normalizeNode()
                // setzt dann wegen naturalWidth === 0 keine px-Masse, fit() misst per
                // getBoundingClientRect() 1x1 und skaliert um Faktor ~1000 -- die Buehne
                // sieht leer aus. Klassischer Wettlauf, der "beim zweiten Refresh gehts"
                // erzeugt (dann liegt das Bild im Cache). Mit den hochaufloesenden Scans
                // seit 2026-08-15 trifft er zuverlaessig, vorher nur selten.
                var simg = item.querySelector('.scan-block img');
                if (simg && !(simg.complete && simg.naturalWidth)) {
                    jobs.push(new Promise(function (resolve) {
                        var done = false;
                        function fin() {
                            if (done) return; done = true;
                            simg.removeEventListener('load', fin); simg.removeEventListener('error', fin);
                            clearTimeout(to); resolve();
                        }
                        simg.addEventListener('load', fin);
                        simg.addEventListener('error', fin);   // kaputtes Bild darf nicht blockieren
                        var to = setTimeout(fin, 8000);
                    }));
                }
                var pdf = item.querySelector('.pdf-viewer');
                if (pdf && !pdf.querySelector('canvas')) {
                    // Auch die Fehlerbox von pdf-embed.js beendet das Warten - dann ist
                    // klar, dass kein Canvas mehr kommt, und der Fallback unten greift.
                    jobs.push(waitFor(pdf, function () {
                        return !!(pdf.querySelector('canvas') || pdf.querySelector('.pdf-viewer-error'));
                    }, 8000));
                }
                return jobs.length ? Promise.all(jobs) : Promise.resolve();
            }

            function prewarm(items) {
                return items.reduce(function (p, it) { return p.then(function () { return ensureRendered(it); }); }, Promise.resolve());
            }

            function pickContent(item) {
                // abc2svg emits one <svg> per music line (unsizedsvg) plus, for
                // tunes with a T: field, a title <svg> first. So show the whole
                // .abc-block container (all line svgs), NOT just the first svg -
                // otherwise multi-line tunes lose every line but the first, and a
                // titled tune shows only its title.
                var abcBlock = item.querySelector('.abc-block');
                var scanBlock = item.querySelector('.scan-block');
                var hasAbc = !!(abcBlock && abcBlock.querySelector('svg'));
                var scanNode = scanBlock ? (scanBlock.querySelector('img') || scanBlock.querySelector('canvas')) : null;
                var hasScan = !!scanNode;
                var useScan = (state.pref === 'scan' && hasScan) || (!hasAbc && hasScan);
                return { hasAbc: hasAbc, hasScan: hasScan, useScan: useScan, node: useScan ? scanNode : (hasAbc ? abcBlock : scanNode) };
            }

            // Move-statt-Clone: den echten gerenderten Knoten ins Overlay schieben
            // (Platzhalter merkt die Ursprungsstelle). Vermeidet Canvas-Bitmap-
            // Verlust beim Klonen und doppelte <defs>-IDs bei abc2svg-SVGs.
            function moveIn(node) {
                if (node.parentNode === content) return;
                node.__gigPh = document.createComment('gig');
                node.parentNode.insertBefore(node.__gigPh, node);
                node.__gigStyle = node.getAttribute('style');
                content.appendChild(node);
            }
            function moveOut(node) {
                if (node.__gigPh && node.__gigPh.parentNode) {
                    node.__gigPh.parentNode.insertBefore(node, node.__gigPh);
                    node.__gigPh.parentNode.removeChild(node.__gigPh);
                }
                node.__gigPh = null;
                // Restore per-svg styles we set in normalizeNode, so the source
                // .abc-block goes back to its responsive on-page rendering.
                if (node.__gigSvgStyles) {
                    node.__gigSvgStyles.forEach(function (pair) {
                        if (pair[1] != null) pair[0].setAttribute('style', pair[1]); else pair[0].removeAttribute('style');
                    });
                    node.__gigSvgStyles = null;
                }
                if (node.__gigStyle != null) node.setAttribute('style', node.__gigStyle); else node.removeAttribute('style');
            }

            // abc2svg-SVGs tragen nur eine viewBox (kein width/height-Attribut) und
            // saugen ihre Groesse sonst aus der Theme-CSS des .abc-block - im Overlay
            // kollabieren sie damit auf 0x0. Also explizite px-Masse geben, damit fit()
            // eine echte natuerliche Groesse messen kann.
            function sizeSvgFromViewBox(s) {
                var vb = s.getAttribute('viewBox');
                if (vb) { var p = vb.split(/[ ,]+/), w = parseFloat(p[2]), h = parseFloat(p[3]);
                          if (w > 0 && h > 0) { s.style.width = w + 'px'; s.style.height = h + 'px'; } }
                s.style.display = 'block';
                s.style.maxWidth = 'none';
            }

            function normalizeNode(node) {
                var tag = node.tagName ? node.tagName.toLowerCase() : '';
                if (node.classList && node.classList.contains('abc-block')) {
                    // Size every line svg from its viewBox so the whole stack has a
                    // real height; remember prior styles to restore in moveOut.
                    node.__gigSvgStyles = [];
                    Array.prototype.forEach.call(node.querySelectorAll('svg'), function (s) {
                        node.__gigSvgStyles.push([s, s.getAttribute('style')]);
                        sizeSvgFromViewBox(s);
                    });
                    return;
                }
                if (tag === 'svg') {
                    sizeSvgFromViewBox(node);
                } else if (tag === 'img') {
                    if (node.naturalWidth) { node.style.width = node.naturalWidth + 'px'; node.style.height = node.naturalHeight + 'px'; }
                } else if (tag === 'canvas') {
                    node.style.width = node.width + 'px'; node.style.height = node.height + 'px';
                }
            }

            function showContent(item) {
                // Return whatever is currently shown to its source item BEFORE
                // picking: the shown node was moved into the overlay, so it is no
                // longer a descendant of `item` and pickContent wouldn't find it -
                // that made hasAbc read false after the first ABC->Scan switch and
                // hid the toggle button.
                if (state.node) { moveOut(state.node); state.node = null; }
                var stale = content.querySelector('.gig-empty');
                if (stale) { content.removeChild(stale); }
                var pick = pickContent(item);
                titleEl.textContent = item.getAttribute('data-gig-title') || document.title;
                if (!pick.node) {
                    // Es gibt nichts Darstellbares - etwa wenn PDF.js den Scan nicht
                    // zeichnen konnte (kaputte Datei, Netz weg). Frueher kehrte diese
                    // Funktion hier stumm zurueck und hinterliess eine weisse Buehne
                    // samt Leiste im Ausgangszustand; das sah nach einem Totalausfall
                    // der Seite aus. Lieber eine Zeile Klartext und, wenn vorhanden,
                    // der Direktlink auf das PDF.
                    var box = document.createElement('div');
                    box.className = 'gig-empty';
                    box.style.cssText = 'font:400 .95rem/1.5 system-ui,sans-serif;color:#555;text-align:center;padding:1.2rem';
                    box.textContent = 'Nothing to display for this tune right now. ';
                    var link = item.querySelector('.pdf-viewer[data-pdf-url]');
                    if (link) {
                        var a = document.createElement('a');
                        a.href = link.getAttribute('data-pdf-url');
                        a.target = '_blank'; a.rel = 'noopener';
                        a.textContent = 'Open PDF';
                        box.appendChild(a);
                    }
                    content.appendChild(box);
                    toggleBtn.style.display = 'none';
                    content.style.opacity = '1';
                    return;
                }
                state.node = pick.node;
                moveIn(pick.node);
                normalizeNode(pick.node);
                // Sicherheitsnetz zur Wartezeit in ensureRendered(): trifft das Bild
                // erst nach der Deadline ein (langsames Netz) oder wurde ohne Vorwaermen
                // gewechselt, dann jetzt nachmessen und neu einpassen, statt es bei der
                // 1x1-Fehlmessung zu lassen.
                if (pick.node.tagName && pick.node.tagName.toLowerCase() === 'img'
                    && !(pick.node.complete && pick.node.naturalWidth)) {
                    (function (img) {
                        img.addEventListener('load', function onLoad() {
                            img.removeEventListener('load', onLoad);
                            if (state.node !== img) return;   // inzwischen weitergeblaettert
                            normalizeNode(img);
                            fit();
                        });
                    }(pick.node));
                }
                toggleBtn.style.display = (pick.hasAbc && pick.hasScan) ? '' : 'none';
                toggleBtn.textContent = pick.useScan ? 'ABC' : 'Scan';
                state.userScale = 1; state.tx = 0; state.ty = 0;
                content.style.opacity = '0';
                requestAnimationFrame(function () { fit(); content.style.opacity = '1'; });
            }

            // Buehnengroesse IMMER von stage.getBoundingClientRect() lesen, nicht von
            // window.innerWidth/innerHeight: die Buehne ist ein Flex-Kind unter der
            // (jetzt permanenten, ein-/ausblendbaren) Leiste, ihre Rect spiegelt also
            // schon korrekt wieder, wie viel Platz nach Leiste + iOS-Safari-Chrome
            // (dank #gig-overlay{height:100dvh}) tatsaechlich sichtbar ist.
            function fit() {
                if (!state.node) return;
                content.style.transition = 'none';
                content.style.transform = 'none';
                var r = state.node.getBoundingClientRect();
                state.nw = r.width || 1; state.nh = r.height || 1;
                var sr = stage.getBoundingClientRect();
                state.fit = Math.min((sr.width * 0.98) / state.nw, (sr.height * 0.98) / state.nh);
                if (!isFinite(state.fit) || state.fit <= 0) state.fit = 1;
                apply();
                requestAnimationFrame(function () { content.style.transition = ''; });
            }

            function apply() {
                var s = state.fit * state.userScale;
                var sr = stage.getBoundingClientRect();
                var maxX = Math.max(0, (state.nw * s - sr.width) / 2 + 30);
                var maxY = Math.max(0, (state.nh * s - sr.height) / 2 + 30);
                state.tx = clamp(state.tx, -maxX, maxX);
                state.ty = clamp(state.ty, -maxY, maxY);
                content.style.transform = 'translate(' + state.tx + 'px,' + state.ty + 'px) scale(' + s + ')';
            }

            function toggleBar() {
                // Buehne ist jetzt Teil des Flex-Layouts (kein Overlay mehr auf dem
                // Notenbild) - Ein-/Ausblenden aendert also die tatsaechlich
                // verfuegbare Flaeche, daher hier neu einpassen. userScale/tx/ty
                // bleiben erhalten, nur der Fit-Basiswert veraendert sich.
                bar.classList.toggle('hide');
                fit();
            }

            function gotoDelta(dir) {
                if (state.mode === 'set') {
                    var n = state.idx + dir;
                    if (n < 0 || n >= state.items.length) { bounce(dir); return; }
                    state.idx = n;
                    showContent(state.items[n]);
                } else {
                    var a = qs(dir < 0 ? '.tune-nav-prev' : '.tune-nav-next');
                    if (a && getComputedStyle(a).display !== 'none') {
                        var h = a.getAttribute('href');
                        if (h && h !== '#') { location.href = h.split('#')[0] + '#gig'; return; }
                    }
                    bounce(dir);
                }
            }

            function bounce(dir) {
                var d = dir < 0 ? 22 : -22;
                content.style.transition = 'transform .12s';
                content.style.transform = 'translate(' + (state.tx + d) + 'px,' + state.ty + 'px) scale(' + (state.fit * state.userScale) + ')';
                setTimeout(function () { content.style.transition = ''; apply(); }, 120);
            }

            function open(mode, startIdx) {
                build();
                // Bail only if already open. Check for the explicit 'flex' we set
                // below, not "!== none": the initial hidden state comes from the
                // injected CSS, so the inline style is '' and "!== none" misfires.
                if (overlay.style.display === 'flex') return;
                savedScrollY = window.scrollY;
                overlay.style.display = 'flex';
                // Leiste startet jedes Mal sichtbar (Standardzustand), auch wenn eine
                // vorige Session sie per Mitte-Tap ausgeblendet hatte.
                bar.classList.remove('hide');
                content.style.opacity = '0';
                var items = (mode === 'set') ? qsa('.gig-set-item') : [qs('.gig-tap')];
                if (!items.length || !items[0]) { overlay.style.display = 'none'; return; }
                prewarm(items).then(function () {
                    document.body.style.overflow = 'hidden';
                    window.scrollTo(0, savedScrollY);
                    state.mode = mode; state.items = items;
                    state.idx = (mode === 'set') ? clamp(startIdx || 0, 0, items.length - 1) : 0;
                    state.pref = readPref(); state.node = null;
                    showContent(items[state.idx]);
                    requestWakeLock();
                    maybeShowHomeTip();
                    if (!window.__gigHintShown) {
                        window.__gigHintShown = true;
                        hintEl.style.opacity = '1';
                        setTimeout(function () { hintEl.style.opacity = '0'; }, 2400);
                    } else { hintEl.style.opacity = '0'; }
                });
            }

            function close() {
                if (!overlay || overlay.style.display === 'none') return;
                if (state.node) moveOut(state.node);
                state.node = null;
                overlay.style.display = 'none';
                bar.classList.remove('hide');
                if (homeTip) homeTip.classList.remove('show');
                releaseWakeLock();
                document.body.style.overflow = '';
                window.scrollTo(0, savedScrollY);
                if (location.hash === '#gig') {
                    try { history.replaceState(null, '', location.pathname + location.search); } catch (e) {}
                }
            }

            function wireGestures() {
                var pts = new Map(), g = null, pinchD = 0, pinchS = 1;
                stage.addEventListener('pointerdown', function (e) {
                    try { stage.setPointerCapture(e.pointerId); } catch (x) {}
                    pts.set(e.pointerId, { x: e.clientX, y: e.clientY });
                    if (pts.size === 1) g = { sx: e.clientX, st: Date.now(), moved: 0, multi: false };
                    if (pts.size === 2) { if (g) g.multi = true; var a = Array.from(pts.values()); pinchD = dist(a[0], a[1]); pinchS = state.userScale; }
                });
                stage.addEventListener('pointermove', function (e) {
                    var p = pts.get(e.pointerId); if (!p) return;
                    var dx = e.clientX - p.x, dy = e.clientY - p.y;
                    p.x = e.clientX; p.y = e.clientY;
                    if (g) g.moved += Math.abs(dx) + Math.abs(dy);
                    if (pts.size >= 2) {
                        e.preventDefault();
                        var a = Array.from(pts.values());
                        if (pinchD > 0) { state.userScale = clamp(pinchS * (dist(a[0], a[1]) / pinchD), 1, 6); apply(); }
                        return;
                    }
                    if (state.userScale > 1) { e.preventDefault(); state.tx += dx; state.ty += dy; apply(); }
                });
                function up(e) {
                    if (!pts.has(e.pointerId)) return;
                    pts.delete(e.pointerId);
                    try { stage.releasePointerCapture(e.pointerId); } catch (x) {}
                    if (pts.size === 0 && g) {
                        var dt = Date.now() - g.st;
                        if (!g.multi && g.moved < 12 && dt < 350) {
                            var w = window.innerWidth;
                            if (e.clientX < w * 0.28) gotoDelta(-1);
                            else if (e.clientX > w * 0.72) gotoDelta(1);
                            else toggleBar();
                        }
                        g = null;
                    }
                }
                stage.addEventListener('pointerup', up);
                stage.addEventListener('pointercancel', up);
            }

            window.addEventListener('resize', function () {
                if (overlay && overlay.style.display !== 'none' && state.node) {
                    state.userScale = 1; state.tx = 0; state.ty = 0; fit();
                }
            });
            // iOS Safari feuert beim Ein-/Ausblenden der Adress-/Tabzeile nicht immer
            // ein 'resize' auf window, wohl aber auf visualViewport. Zoom/Pan bleiben
            // hier bewusst unangetastet (kein Nutzer-Grund fuer einen Reset, das
            // passiert beim blossen Weiterspielen), nur neu eingepasst.
            if (window.visualViewport) {
                window.visualViewport.addEventListener('resize', function () {
                    if (overlay && overlay.style.display !== 'none' && state.node) fit();
                });
            }

            // Eintritt: Tipp auf einen angezeigten Tune. Echte Bedienelemente
            // (Show-ABC-Button, "In set"-/thesession-Links, Nav) nicht kapern.
            document.addEventListener('click', function (e) {
                var tap = e.target.closest('.gig-tap');
                if (!tap) return;
                if (e.target.closest('a,button,input,textarea,select,label,.collapse,.btn')) return;
                if (overlay && overlay.style.display !== 'none') return;
                e.preventDefault();
                if (tap.classList.contains('gig-set-item')) open('set', parseInt(tap.getAttribute('data-gig-index'), 10) || 0);
                else open('tune', 0);
            });

            // Reload-basiertes Tune-Blaettern landet mit #gig hier -> Overlay
            // sofort wieder oeffnen (ABC rendert async, ensureRendered wartet).
            if (location.hash === '#gig') {
                if (qs('.gig-set-item')) open('set', 0);
                else if (qs('.gig-tap')) open('tune', 0);
            }
        })();

    })

})(jQuery);
