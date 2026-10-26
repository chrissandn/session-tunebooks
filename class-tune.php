<?php

/**
 * Find a tune's scan attachment (image-Tune-Typ, step 4a): the featured image if
 * set, else the first child attachment of an allowed mime type. Lightweight
 * existence lookup — unlike tune_collect_attachments() (tune-export.php) this
 * does not hash files or return every attachment, it just answers "is there one".
 *
 * Images win over PDFs among the children. A tune can legitimately carry both
 * once Scan Review has rasterized a PDF scan for cropping: it keeps the PDF as
 * the archived original, and the JPG is the one to render. The featured image
 * (set during rasterization) already decides that — this is the belt to that
 * suspenders, for the case where no featured image is set.
 *
 * @return int  attachment id, or 0 if none
 */
function tune_find_scan_attachment($post_id)
{
    global $isg_scan_children;

    $thumb = get_post_thumbnail_id($post_id);
    if ($thumb) {
        return (int) $thumb;
    }

    // Filled by tune_prime_caches() for list pages; anything else still queries.
    $children = isset($isg_scan_children[(int) $post_id]) ? $isg_scan_children[(int) $post_id] : get_children([
        'post_parent'    => $post_id,
        'post_type'      => 'attachment',
        'post_mime_type' => ['image/jpeg', 'image/jpg', 'application/pdf'],
        'numberposts'    => -1,
        'fields'         => 'ids',
    ]);
    if (!$children) {
        return 0;
    }

    foreach ($children as $cid) {
        if (get_post_mime_type($cid) !== 'application/pdf') {
            return (int) $cid;
        }
    }

    return (int) reset($children);
}

/**
 * Load everything `new tune()` reads for a whole list of tunes in a handful of
 * queries: the posts with meta and terms, their scan-attachment candidates, and
 * those attachments' posts and meta. Without it a list page costs several
 * queries per tune (~480 on /tunes/, ~920 on /sets/).
 */
function tune_prime_caches(array $ids)
{
    global $wpdb, $isg_scan_children;

    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return;
    }
    _prime_post_caches($ids, true, true);

    $attachments = [];
    foreach ($ids as $id) {
        $thumb = (int) get_post_meta($id, '_thumbnail_id', true);
        if ($thumb) {
            $attachments[] = $thumb;
        }
    }

    // Same set and order as the get_children() call in tune_find_scan_attachment().
    $rows = $wpdb->get_results(
        "SELECT ID, post_parent FROM {$wpdb->posts}
         WHERE post_type = 'attachment'
           AND post_parent IN (" . implode(',', $ids) . ")
           AND post_mime_type IN ('image/jpeg', 'image/jpg', 'application/pdf')
           AND post_status NOT IN ('trash', 'auto-draft')
         ORDER BY post_date DESC"
    );
    $children = array_fill_keys($ids, []);
    foreach ($rows as $row) {
        $children[(int) $row->post_parent][] = (int) $row->ID;
        $attachments[] = (int) $row->ID;
    }
    $isg_scan_children = $children + (array) $isg_scan_children;

    if ($attachments) {
        _prime_post_caches(array_unique($attachments), false, true);
    }
}

/**
 * The tunes of a set, slots tune_1..tune_5, as WP_Post or null. Replaces
 * get_field('tune_N'): ACF runs a query per slot to format a post object, while
 * the raw meta is already cached with the set.
 */
function set_get_tunes($set_id)
{
    $tunes = [];
    for ($i = 1; $i <= 5; $i++) {
        $id = (int) get_post_meta($set_id, 'tune_' . $i, true);
        $post = $id ? get_post($id) : null;
        $tunes[] = ($post && !in_array($post->post_status, ['trash', 'auto-draft'], true)) ? $post : null;
    }
    return $tunes;
}

/**
 * Prime caches for every tune referenced by the given sets (see tune_prime_caches()).
 */
function set_prime_tune_caches(array $set_ids)
{
    $ids = [];
    foreach ($set_ids as $set_id) {
        for ($i = 1; $i <= 5; $i++) {
            $ids[] = (int) get_post_meta($set_id, 'tune_' . $i, true);
        }
    }
    tune_prime_caches($ids);
}

class tune
{
    public $name;
    public $key;
    public $tuneType;
    public $abc;
    public $settingNumber;
    public $sessionJson;
    public $source;
    public $postId;
    public $imageAttachmentId;
    public $imageUrl;
    public $imageKind; // 'jpg' | 'pdf', only set when source === 'image'
    public $sessionId; // thesession.org tune id, if known — independent of $source (see getSessionLink)
    public $abcOverride = false; // session tune whose `abc` field replaces the setting's ABC (#13)

    public function __construct($id)
    {
        // Plain post meta, not get_field(): these are unformatted text fields, and
        // ACF's per-call overhead dominated rendering the tune list (~90 ms / 300 tunes).
        $this->postId = $id;
        $this->sessionJson = json_decode((string) get_post_meta($id, 'json', true));
        $this->abc = metadata_exists('post', $id, 'abc') ? get_post_meta($id, 'abc', true) : null;
        $this->name = get_the_title($id);
        $sid = get_post_meta($id, 'session_id', true);
        $setting = get_post_meta($id, 'setting', true);
        $this->sessionId = ($sid !== null && $sid !== '') ? $sid : false;

        if ($this->sessionJson) {
            $this->source = 'session';
            // A few pre-existing tunes have a json blob but no ACF session_id
            // (older import path never set it) -- fall back to the id embedded in
            // the blob itself, so getSessionLink() doesn't regress for them.
            if (!$this->sessionId && isset($this->sessionJson->id)) {
                $this->sessionId = $this->sessionJson->id;
            }
            $this->settingNumber = $setting ? intval($setting) : 0;
            $this->tuneType = $this->sessionJson->type;
            $this->key = $this->sessionJson->settings[$this->settingNumber]->key;

            // Feature #13: a non-empty `abc` field is a hand-corrected override and
            // wins over the setting's ABC from the json blob. Until 2026-07-31 this
            // spot overwrote the field unconditionally, which made it editable but
            // completely inert on every session tune — typing in it changed nothing
            // and reported nothing. `source` stays 'session': the thesession link,
            // the setting number and the settings picker all remain valid.
            $override = is_string($this->abc) ? trim($this->abc) : '';
            if ($override !== '') {
                $this->abcOverride = true;
                $this->abc = $override;
                // A full ABC carries its own R:/K:. Those describe what is actually
                // rendered, so they outrank the blob's type/key.
                if (preg_match('/^R:\s*(\S.*?)\s*$/m', $override, $matches)) {
                    $this->tuneType = $matches[1];
                }
                if (preg_match('/^K:\s*(\S.*?)\s*$/m', $override, $matches)) {
                    $this->key = $matches[1];
                }
            } else {
                $this->abc = $this->sessionJson->settings[$this->settingNumber]->abc;
            }
        } elseif ($this->abc) {
            $this->source = 'abc';
            // Bulk/hybrid imports (thesession-matched scan + extracted ABC) store
            // session_id + setting without the full json blob — still a real
            // setting index, not "unknown", so surface it instead of `false`.
            $this->settingNumber = $setting ? intval($setting) : false;
            $this->key = false;
            if (preg_match('/R:\s*(.*?)\r?\n/', $this->abc, $matches)) {
                // $matches[0] will contain the full matched string
                $this->tuneType = $matches[1];;
            } else {
                $this->tuneType = false;
            }
            if (preg_match('/K:\s*(.*?)\r?\n/', $this->abc, $matches)) {
                // $matches[0] will contain the full matched string
                $this->key = $matches[1];;
            } else {
                $this->key = false;
            }
        } else {
            $att_id = tune_find_scan_attachment($id);
            if ($att_id) {
                $this->source = 'image';
                $this->settingNumber = $setting ? intval($setting) : false;
                $type = get_post_meta($id, '_tune_type', true);
                $this->tuneType = ($type !== '' && $type !== false) ? $type : false;
                $key = get_post_meta($id, '_tune_key', true);
                $this->key = ($key !== '' && $key !== false) ? $key : false;
                $this->imageAttachmentId = $att_id;
                $this->imageUrl = wp_get_attachment_url($att_id);
                $this->imageKind = (get_post_mime_type($att_id) === 'application/pdf') ? 'pdf' : 'jpg';
            }
        }

        // Feature #8 stage 2: a session/abc tune can ALSO carry a scan (hybrid
        // tune). The branch above only looks for a scan when there's neither
        // session json nor abc — so for those two branches, check separately
        // (source stays 'session'/'abc', it's still the primary type).
        if ($this->source !== 'image' && empty($this->imageUrl)) {
            $att_id = tune_find_scan_attachment($id);
            if ($att_id) {
                $this->imageAttachmentId = $att_id;
                $this->imageUrl = wp_get_attachment_url($att_id);
                $this->imageKind = (get_post_mime_type($att_id) === 'application/pdf') ? 'pdf' : 'jpg';
            }
        }
    }

    /**
     * @return false|mixed
     */
    public function getAbc(): mixed
    {
        return $this->abc;
    }

    /**
     * @return mixed
     */
    public function getSessionLink()
    {
        // Independent of $source: bulk/hybrid imports store session_id + extracted
        // ABC without the full json blob, but the tune is still a verified
        // thesession.org match and should still link out (see $sessionId above).
        if (!empty($this->sessionId)) {
            return sprintf('<a href="https://thesession.org/tunes/%s" target="_blank"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-box-arrow-up-right" viewBox="0 0 16 16">
  <path fill-rule="evenodd" d="M8.636 3.5a.5.5 0 0 0-.5-.5H1.5A1.5 1.5 0 0 0 0 4.5v10A1.5 1.5 0 0 0 1.5 16h10a1.5 1.5 0 0 0 1.5-1.5V7.864a.5.5 0 0 0-1 0V14.5a.5.5 0 0 1-.5.5h-10a.5.5 0 0 1-.5-.5v-10a.5.5 0 0 1 .5-.5h6.636a.5.5 0 0 0 .5-.5"/>
  <path fill-rule="evenodd" d="M16 .5a.5.5 0 0 0-.5-.5h-5a.5.5 0 0 0 0 1h3.793L6.146 9.146a.5.5 0 1 0 .708.708L15 1.707V5.5a.5.5 0 0 0 1 0z"/>
</svg></a>', $this->sessionId);
        } else {
            return false;
        }
    }

private function getABCHeader()
{
?>
%%unsizedsvg 1
%%fgcolor black
%%topspace 0
%%topmargin 0
%%titlespace 0
%%composerspace 0
%%musicspace 0
%%stretchlast 1
%%leftmargin 0
%%rightmargin 0
<?php
}

/**
 * The meter an ABC header should declare for a tune type. Unknown types fall
 * back to 4/4 (reel, hornpipe, barndance and friends all sit there).
 */
private static function abcMeter($tuneType)
{
switch ($tuneType) {
case 'jig':
return '6/8';
case 'slip jig':
return '9/8';
case 'polka':
return '2/4';
case 'mazurka':
case 'waltz':
return '3/4';
case 'slide':
return '12/8';
default:
return '4/4';
}
}

/**
 * Does this ABC bring its own header, or is it a bare thesession setting body?
 *
 * A complete ABC tune opens with an information field (`X:`, `M:`, `K:`, …);
 * a setting body out of the json blob opens straight into notes and barlines.
 * Deciding on the first meaningful line — rather than searching anywhere for a
 * `K:` — keeps a mid-tune key change from being mistaken for a header.
 */
private static function abcHasHeader($abc)
{
foreach (preg_split('/\r\n|\r|\n/', (string) $abc) as $line) {
$line = trim($line);
if ($line === '' || $line[0] === '%') {
continue;
}
return (bool) preg_match('/^[A-Za-z]:/', $line);
}
return false;
}

public function printABC2SVG($debug = false, $setting = false)
{
// An explicit $setting means "render exactly this variant" (the settings
// picker in the editor) — an ABC override must not hijack that view.
if ($this->sessionJson && $setting !== false) {
$raw = $this->sessionJson->settings[$setting]->abc;
$key = $this->sessionJson->settings[$setting]->key;
} else {
$raw = $this->abc;
$key = $this->key;
}
$hasHeader = self::abcHasHeader($raw);
$lines = explode('! ',  $raw);
if ($debug) {
echo '<pre>';
} else {
echo '<script type="text/vnd.abc">';
}

$this->getABCHeader();
echo PHP_EOL;

// Bare setting body: build the header around it. A session tune whose ABC
// override brings its own header skips this and is printed verbatim below.
if ($this->source == 'session' && !$hasHeader) {
echo 'X: 1' . PHP_EOL;
// Was hard-wired to `R: jig` until 2026-07-31 — it read as "jig" on every
// session tune, reels included. Harmless for abc2svg (which goes by M:/L:/K:),
// but wrong for anyone copying the text out of the "Show ABC" panel.
if ($this->tuneType) {
echo 'R: ' . $this->tuneType . PHP_EOL;
}
echo 'M: ' . self::abcMeter($this->tuneType) . PHP_EOL;
echo 'L: 1/8' . PHP_EOL;
echo 'K: ' . substr((string) $key, 0, 4) . PHP_EOL;

foreach ($lines as $line) {
echo $line;
echo PHP_EOL;
}
}

if ($this->source == 'abc' || ($this->source == 'session' && $hasHeader)) {
echo $raw;
}

if ($debug) {
echo '</pre>';
} else {
echo '</script>';
}
}
}

?>