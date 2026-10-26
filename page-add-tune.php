<?php
// Creating tunes/sets is for contributing users (editors/admins). Members
// (personal books only) must not reach this page — not even by direct URL.
if (!current_user_can('edit_posts')) {
	wp_safe_redirect(home_url('/'));
	exit;
}
get_header();

function createImportMessage($status, $message, $extra_fields = []){
    return array(
        'status' => $status,
        'message' => $message,
        'extra_fields' => $extra_fields,
       );
}

/**
 * Re-emit the current POST fields as hidden inputs, so a "import anyway"
 * resubmit (duplicate-tune warning) doesn't lose what the user already
 * filled in (tags, books, addset, ...).
 */
function add_tune_hidden_repost_fields($exclude = [])
{
    foreach ($_POST as $key => $value) {
        if (in_array($key, $exclude, true)) {
            continue;
        }
        $isArray = is_array($value);
        foreach ($isArray ? $value : [$value] as $v) {
            printf(
                '<input type="hidden" name="%s" value="%s">',
                esc_attr($isArray ? $key . '[]' : $key),
                esc_attr(wp_unslash($v))
            );
        }
    }
}

/**
 * Shared "import anyway" confirm-resubmit UI for the duplicate-tune warning,
 * used by both the batch thesession import and the merged single-tune submit
 * (Feature #8 stage 2). `extra_fields` carries anything that can't survive a
 * plain $_POST repost — namely a staged scan file path (see
 * tune_merged_stage_upload()), since browsers cannot refill a file input.
 */
function render_import_confirm($response)
{
    if (!$response || $response['status'] !== 'confirm') {
        return;
    }
    ?>
    <div class="alert alert-warning" role="alert">
        <?php echo $response['message'] ?>
        <form action="" method="post" class="mt-2">
            <?php add_tune_hidden_repost_fields(['confirm_duplicates']); ?>
            <?php foreach (($response['extra_fields'] ?: []) as $k => $v) : ?>
                <input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($v); ?>">
            <?php endforeach; ?>
            <input type="hidden" name="confirm_duplicates" value="1">
            <button type="submit" class="btn btn-sm btn-warning">Import anyway (add as duplicate)</button>
        </form>
    </div>
    <?php
}



function importTunes(){
    $sessionid = isset($_POST['id']) ? $_POST['id'] : false;
    $setting = isset($_POST['setting']) ? $_POST['setting'] : false;
    $tags = isset($_POST['tags']) ? sanitize_text_field($_POST['tags']) : '';
    $successMessage = [];
    $failMessage = false;
    $sessionids = explode(',', $sessionid);
    $setting = explode(',', $setting);
    $addset = isset($_POST['addset']) ? $_POST['addset'] : false;

    // Tunebooks (step 2.5): chosen existing books + optional new ones.
    $bookTerms = tunebook_resolve_selection(
        isset($_POST['books']) ? (array) $_POST['books'] : [],
        isset($_POST['new_books']) ? sanitize_text_field(wp_unslash($_POST['new_books'])) : ''
    );

    $messages = [];

    if (count($setting) > 5){
        return createImportMessage('fail', 'Too many Tunes, max is 5' );       
    }

    $addedPosts = [];

    // Duplicate check (#8 follow-up): warn instead of hard-aborting, so a
    // deliberate 2nd variant/setting of an already-imported tune can still be
    // added via the "import anyway" resubmit rendered below.
    $confirmDuplicates = isset($_POST['confirm_duplicates']) && $_POST['confirm_duplicates'] === '1';
    if (!$confirmDuplicates) {
        $duplicates = [];
        foreach ($sessionids as $sessionid) {
            $existing_post = new WP_Query([
                'posts_per_page' => 1,
                'post_type'      => 'post',
                'post_status'    => 'any',
                'fields'         => 'ids',
                'meta_key'       => 'session_id',
                'meta_value'     => $sessionid,
            ]);
            if ($existing_post->posts) {
                $duplicates[$sessionid] = $existing_post->posts[0];
            }
        }
        if ($duplicates) {
            $lines = [];
            foreach ($duplicates as $sid => $pid) {
                $lines[] = sprintf(
                    'Tune ID %s is already in the database: <a href="%s" target="_blank">%s</a>.',
                    esc_html($sid),
                    esc_url(get_permalink($pid)),
                    esc_html(get_the_title($pid))
                );
            }
            $lines[] = 'Import anyway to add another variant/setting, or change the tune IDs above.';
            return createImportMessage('confirm', implode('<br>', $lines));
        }
    }

    foreach ($sessionids as $key=>$sessionid){
        $sessionJsonString = file_get_contents('https://thesession.org/tunes/' . (string) $sessionid . "?format=json" );

        if (!$sessionJsonString){
            return createImportMessage('fail', 'Failed response from the Session from tune ' . $sessionid );
        }

        if ($sessionJsonString){
            $sessionJson = json_decode($sessionJsonString);
            $id = wp_insert_post([
                "post_title" => $sessionJson->name,
                "post_status" => 'publish',
            ]);
            if ($id) {
               $theSetting = isset($setting[$key]) && intval($setting[$key]) > 0 ? intval($setting[$key]) : 1;
                update_post_meta($id, 'setting', $theSetting - 1);
                // Drop the bulky user-comments thread before storing (#8).
                update_field('json', wp_slash(tune_strip_session_json($sessionJsonString)), $id);
                update_field('session_id', $sessionid, $id);
                tune_ensure_uid($id); // assign the stable key session:<id> right away
                if ($tags) {
                    wp_set_post_tags($id, $tags, false);
                }
                if ($bookTerms) {
                    tunebook_assign($id, $bookTerms);
                }
                $addedPosts[] = $id;
                $successMessage[] = $sessionJson->name . ' added!';
            } else {
                return createImportMessage('fail', 'Erros saving WP Post' );
            }
        } 
    }

    if ($addset == "on" && count($addedPosts) > 0){
        $setTitleArray = [];
        foreach ($addedPosts as $post){
            $title = get_the_title($post);
            $setTitleArray[] = $title;
        }

        $setTitle = implode('-',$setTitleArray);

        $setId = wp_insert_post([
            "post_title" => $setTitle,
            "post_status" => 'publish',
            "post_type" => "set"
        ]);

        if ($setId){
            foreach ($addedPosts as $key=>$post){
                update_field('tune_'.($key + 1),$post,  $setId);
            }
            if ($tags) {
                wp_set_post_tags($setId, $tags, false);
            }
            if ($bookTerms) {
                tunebook_assign($setId, $bookTerms);
            }
            $successMessage[] = 'Set ' . $setTitle . ' added!';
        }
    }

    return createImportMessage('success', implode('<br>', $successMessage));
}

// Known tune types (union of the page-tunes.php type filter and the M: mapping
// in class-tune.php) — used for the datalist + the client-side "new type?"
// nudge below. Freeform field, this list is a soft hint, not an allowlist.
$tuneKnownTypes = ['jig', 'reel', 'hornpipe', 'slide', 'slip jig', 'song', 'polka', 'mazurka', 'waltz', 'barndance', 'strathspey', 'three-two', 'march', 'slow air'];

/**
 * Shared Tunebooks field (existing books select + optional new-book textbox),
 * reused by both the session-import and the image-upload form.
 */
function add_tune_render_books_field()
{
    $allBooks = get_terms(['taxonomy' => 'tunebook', 'hide_empty' => false]);
    if (is_wp_error($allBooks)) {
        $allBooks = [];
    }
    ?>
    <div class="form-group mt-3">
        <label class="form-label">Tunebooks <span class="text-muted fw-normal">(optional)</span></label>
        <?php if ($allBooks) : ?>
            <select class="form-select" name="books[]" multiple size="<?php echo min(5, max(2, count($allBooks))); ?>">
                <?php foreach ($allBooks as $b) : ?>
                    <option value="<?php echo (int) $b->term_id; ?>"><?php echo esc_html($b->name); ?> (<?php echo (int) $b->count; ?>)</option>
                <?php endforeach; ?>
            </select>
            <div class="form-text">Hold Ctrl/Cmd to select multiple books. Applied to all tunes and the set.</div>
        <?php endif; ?>
        <?php if (current_user_can('manage_categories')) : ?>
            <input class="form-control mt-2" name="new_books" type="text" placeholder="New book, or several: Graz-Session, Villach">
            <div class="form-text">Create new book(s), comma-separated. Created and assigned on save.</div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Post-attach bookkeeping shared by every scan-attach path: the sha1 dedupe
 * marker (tune_import_files() / tune_collect_attachments() in tune-import.php
 * rely on the same TUNE_FILE_SHA1_META constant) and jpg -> featured image.
 */
function tune_mark_scan_attachment($att_id, $post_id)
{
    $path = get_attached_file($att_id);
    if ($path && file_exists($path)) {
        update_post_meta($att_id, TUNE_FILE_SHA1_META, sha1_file($path));
    }
    if (get_post_mime_type($att_id) === 'image/jpeg') {
        set_post_thumbnail($post_id, $att_id);
    }
}

/**
 * Attach an uploaded scan file (jpg/pdf, from $_FILES) to a tune post. Shared
 * by the pure image-tune upload and the merged hybrid flow (Feature #8 stage
 * 2), where a session-imported tune can also get a scan in the same submit.
 *
 * @return int|WP_Error  attachment id, or WP_Error on failure. The caller
 *                       decides what to do with $post_id on failure (delete it
 *                       if the file was mandatory, or keep it otherwise).
 */
function tune_attach_scan_upload($post_id, $file_field = 'tune_image_file')
{
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    // Defense in depth: restrict to jpg/pdf even though both are already in
    // WP's default upload whitelist.
    $restrict_mimes = function () {
        return ['jpg|jpeg' => 'image/jpeg', 'pdf' => 'application/pdf'];
    };
    add_filter('upload_mimes', $restrict_mimes);
    $att_id = media_handle_upload($file_field, $post_id);
    remove_filter('upload_mimes', $restrict_mimes);

    if (is_wp_error($att_id)) {
        return $att_id;
    }
    tune_mark_scan_attachment($att_id, $post_id);
    return $att_id;
}

/**
 * Directory used to stage an uploaded scan across a duplicate-confirm
 * round-trip (see tune_merged_stage_upload() below).
 */
function tune_merged_staging_dir()
{
    $dir = wp_upload_dir();
    return trailingslashit($dir['basedir']) . 'tune-merged-staging';
}

/**
 * Confirms a staged-file path is actually inside our staging dir (the path
 * comes back to us via a hidden form field on resubmit — user-controlled
 * input — so this guards against path traversal before we touch the file).
 */
function tune_merged_staged_path_is_valid($path)
{
    $stage_dir = wp_normalize_path(tune_merged_staging_dir());
    $real = $path ? wp_normalize_path(realpath($path)) : false;
    return $real && strpos($real, $stage_dir) === 0;
}

/**
 * Stage an uploaded scan file to survive the duplicate-confirm round-trip
 * (Feature #8 stage 2): browsers cannot refill a <input type=file> on a
 * repost, so add_tune_hidden_repost_fields() alone would silently drop it.
 * Call this BEFORE returning the 'confirm' response, then carry the returned
 * path through via createImportMessage()'s $extra_fields.
 *
 * @return string  the staged file's absolute path, or '' on failure/no file
 */
function tune_merged_stage_upload($file_field = 'tune_image_file')
{
    if (empty($_FILES[$file_field]['name']) || !empty($_FILES[$file_field]['error'])) {
        return '';
    }
    $stage_dir = tune_merged_staging_dir();
    if (!wp_mkdir_p($stage_dir)) {
        return '';
    }
    $ext = strtolower(pathinfo($_FILES[$file_field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'pdf'], true)) {
        return '';
    }
    $dest = trailingslashit($stage_dir) . wp_generate_uuid4() . '.' . $ext;
    if (!@move_uploaded_file($_FILES[$file_field]['tmp_name'], $dest)) {
        return '';
    }
    return $dest;
}

/**
 * Same as tune_attach_scan_upload() but the file already sits on disk (staged
 * across a duplicate-confirm resubmit) rather than in $_FILES. Deletes the
 * staged file afterward either way (success or failure) — it's a one-shot
 * temp copy, not meant to linger.
 */
function tune_attach_scan_from_path($post_id, $path)
{
    if (!$path || !file_exists($path)) {
        return new WP_Error('tune_stage_missing', 'Staged scan file is missing.');
    }
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $file_array = ['name' => basename($path), 'tmp_name' => $path];
    $restrict_mimes = function () {
        return ['jpg|jpeg' => 'image/jpeg', 'pdf' => 'application/pdf'];
    };
    add_filter('upload_mimes', $restrict_mimes);
    $att_id = media_handle_sideload($file_array, $post_id);
    remove_filter('upload_mimes', $restrict_mimes);
    @unlink($path);

    if (is_wp_error($att_id)) {
        return $att_id;
    }
    tune_mark_scan_attachment($att_id, $post_id);
    return $att_id;
}

/**
 * image-Tune-Typ (step 4a): manual single upload of a scanned .jpg/.pdf tune.
 * Mirrors importTunes() but the content is a file, not a thesession fetch, and
 * type/key can't be derived — they're explicit form fields (concept §9).
 * Also reused (unchanged) as the "no thesession match" branch of the merged
 * add-tune flow (Feature #8 stage 2, handleMergedTuneSubmit()).
 */
function handleImageTuneUpload()
{
    if (!isset($_POST['tune_image_nonce']) || !wp_verify_nonce($_POST['tune_image_nonce'], 'tune_image_upload')) {
        return createImportMessage('fail', 'Security check failed. Please reload the page and try again.');
    }

    $title = isset($_POST['image_title']) ? sanitize_text_field(wp_unslash($_POST['image_title'])) : '';
    $type  = isset($_POST['tune_type']) ? sanitize_text_field(wp_unslash($_POST['tune_type'])) : '';
    $key   = isset($_POST['tune_key']) ? sanitize_text_field(wp_unslash($_POST['tune_key'])) : '';
    $tags  = isset($_POST['tags']) ? sanitize_text_field($_POST['tags']) : '';

    if ($title === '') {
        return createImportMessage('fail', 'Title is required.');
    }
    if ($type === '') {
        return createImportMessage('fail', 'Type is required.');
    }
    if (empty($_FILES['tune_image_file']['name'])) {
        return createImportMessage('fail', 'Please choose a file to upload.');
    }

    $bookTerms = tunebook_resolve_selection(
        isset($_POST['books']) ? (array) $_POST['books'] : [],
        isset($_POST['new_books']) ? sanitize_text_field(wp_unslash($_POST['new_books'])) : ''
    );

    $post_id = wp_insert_post([
        'post_title'  => $title,
        'post_status' => 'publish',
        'post_type'   => 'post',
    ], true);

    if (is_wp_error($post_id) || !$post_id) {
        return createImportMessage('fail', 'Could not create the tune post.');
    }

    $att_id = tune_attach_scan_upload($post_id);

    if (is_wp_error($att_id)) {
        wp_delete_post($post_id, true);
        return createImportMessage('fail', 'Upload failed: ' . $att_id->get_error_message());
    }

    update_post_meta($post_id, '_tune_type', $type);
    if ($key !== '') {
        update_post_meta($post_id, '_tune_key', $key);
    }

    if ($tags) {
        wp_set_post_tags($post_id, $tags, false);
    }
    if ($bookTerms) {
        tunebook_assign($post_id, $bookTerms);
    }

    tune_ensure_uid($post_id);

    return createImportMessage(
        'success',
        sprintf('"%s" added! <a href="%s">View tune</a>', esc_html($title), esc_url(get_permalink($post_id)))
    );
}

/**
 * Feature #8 stage 2: unified single-tune submit dispatch. Merges the
 * thesession-import path (optionally with an attached scan = hybrid) and the
 * pure image-tune path into one handler, routed by an explicit
 * `tune_resolution` value rather than by presence of overlapping POST keys —
 * a naive merge (checking isset($_POST['id']) alongside isset($_POST
 * ['tune_image_submit'])) would fire two handlers and create two posts for
 * one submission.
 */
function handleMergedTuneSubmit()
{
    if (!isset($_POST['tune_merged_nonce']) || !wp_verify_nonce($_POST['tune_merged_nonce'], 'tune_merged_add')) {
        return createImportMessage('fail', 'Security check failed. Please reload the page and try again.');
    }

    $resolution = isset($_POST['tune_resolution']) ? sanitize_text_field($_POST['tune_resolution']) : '';

    if ($resolution === 'image_only') {
        // No thesession match — reuse handleImageTuneUpload() unchanged (same
        // field names: image_title, tune_type, tune_key, tune_image_file,
        // tags, books[]/new_books — the merged form uses these directly).
        return handleImageTuneUpload();
    }

    if ($resolution === 'hybrid' || $resolution === 'abc_only') {
        return handleMergedSessionImport($resolution === 'hybrid');
    }

    return createImportMessage('fail', 'Please choose how to save this tune.');
}

/**
 * Single-tune thesession import for the merged flow (Feature #8 stage 2) —
 * like importTunes()'s per-id body, but always exactly one id/setting (the
 * merged flow never batches; batching stays on the separate "Advanced: bulk
 * add by ID" form/importTunes()), and optionally attaches an uploaded scan
 * afterward ($withScan = hybrid).
 */
function handleMergedSessionImport($withScan)
{
    $sessionid  = isset($_POST['merged_id']) ? sanitize_text_field(wp_unslash($_POST['merged_id'])) : '';
    $settingRaw = isset($_POST['merged_setting']) ? intval($_POST['merged_setting']) : 1;
    $tags       = isset($_POST['tags']) ? sanitize_text_field($_POST['tags']) : '';

    if ($sessionid === '' || !ctype_digit($sessionid)) {
        return createImportMessage('fail', 'Pick a thesession.org match first.');
    }
    if ($withScan && empty($_FILES['tune_image_file']['name']) && empty($_POST['staged_scan_path'])) {
        return createImportMessage('fail', 'Please choose a scan file, or use "ABC only" instead.');
    }

    $bookTerms = tunebook_resolve_selection(
        isset($_POST['books']) ? (array) $_POST['books'] : [],
        isset($_POST['new_books']) ? sanitize_text_field(wp_unslash($_POST['new_books'])) : ''
    );

    // Duplicate check + confirm-resubmit, same convention as importTunes().
    $confirmDuplicates = isset($_POST['confirm_duplicates']) && $_POST['confirm_duplicates'] === '1';
    if (!$confirmDuplicates) {
        $existing_post = new WP_Query([
            'posts_per_page' => 1,
            'post_type'      => 'post',
            'post_status'    => 'any',
            'fields'         => 'ids',
            'meta_key'       => 'session_id',
            'meta_value'     => $sessionid,
        ]);
        if ($existing_post->posts) {
            $pid = $existing_post->posts[0];
            $msg = sprintf(
                'Tune ID %s is already in the database: <a href="%s" target="_blank">%s</a>. Import anyway to add another variant/setting, or pick a different match.',
                esc_html($sessionid),
                esc_url(get_permalink($pid)),
                esc_html(get_the_title($pid))
            );
            // Stage the scan NOW, before the confirm round-trip — a resubmit
            // can't refill a file input, so without this the scan would
            // silently vanish if the user clicks "Import anyway".
            $extra = [];
            if ($withScan) {
                $staged = tune_merged_stage_upload();
                if ($staged !== '') {
                    $extra['staged_scan_path'] = $staged;
                }
            }
            return createImportMessage('confirm', $msg, $extra);
        }
    }

    $sessionJsonString = file_get_contents('https://thesession.org/tunes/' . $sessionid . '?format=json');
    if (!$sessionJsonString) {
        return createImportMessage('fail', 'Failed response from thesession.org for tune ' . $sessionid);
    }

    $sessionJson = json_decode($sessionJsonString);
    $post_id = wp_insert_post([
        'post_title'  => $sessionJson->name,
        'post_status' => 'publish',
    ]);
    if (!$post_id) {
        return createImportMessage('fail', 'Error saving WP Post');
    }

    $theSetting = $settingRaw > 0 ? $settingRaw : 1;
    update_post_meta($post_id, 'setting', $theSetting - 1);
    update_field('json', wp_slash(tune_strip_session_json($sessionJsonString)), $post_id);
    update_field('session_id', $sessionid, $post_id);
    tune_ensure_uid($post_id); // session:<id>, independent of the scan below

    if ($tags) {
        wp_set_post_tags($post_id, $tags, false);
    }
    if ($bookTerms) {
        tunebook_assign($post_id, $bookTerms);
    }

    $scanNote = '';
    if ($withScan) {
        if (!empty($_FILES['tune_image_file']['name'])) {
            $att_id = tune_attach_scan_upload($post_id);
        } else {
            $staged = isset($_POST['staged_scan_path']) ? sanitize_text_field(wp_unslash($_POST['staged_scan_path'])) : '';
            $att_id = tune_merged_staged_path_is_valid($staged)
                ? tune_attach_scan_from_path($post_id, $staged)
                : new WP_Error('tune_no_scan', 'No scan file found to attach.');
        }
        if (is_wp_error($att_id)) {
            $scanNote = ' (scan upload failed: ' . esc_html($att_id->get_error_message()) . ')';
        }
    }

    return createImportMessage(
        'success',
        esc_html($sessionJson->name) . ' added!' . $scanNote . ' <a href="' . esc_url(get_permalink($post_id)) . '">View tune</a>'
    );
}

/**
 * Add a tune from raw ABC notation (source = 'abc'). No thesession fetch, no
 * upload — just the pasted ABC stored in the `abc` ACF field. Type/key are
 * derived from the ABC's R:/K: headers at display time (class-tune.php), same
 * as every other abc tune, so no separate type/key input is needed here.
 */
function handleAbcTuneUpload()
{
    if (!isset($_POST['tune_abc_nonce']) || !wp_verify_nonce($_POST['tune_abc_nonce'], 'tune_abc_add')) {
        return createImportMessage('fail', 'Security check failed. Please reload the page and try again.');
    }

    $title = isset($_POST['abc_title']) ? sanitize_text_field(wp_unslash($_POST['abc_title'])) : '';
    $abc   = isset($_POST['abc_body']) ? sanitize_textarea_field(wp_unslash($_POST['abc_body'])) : '';
    $tags  = isset($_POST['tags']) ? sanitize_text_field($_POST['tags']) : '';

    if ($title === '') {
        return createImportMessage('fail', 'Title is required.');
    }
    if (trim($abc) === '') {
        return createImportMessage('fail', 'ABC notation is required.');
    }

    $bookTerms = tunebook_resolve_selection(
        isset($_POST['books']) ? (array) $_POST['books'] : [],
        isset($_POST['new_books']) ? sanitize_text_field(wp_unslash($_POST['new_books'])) : ''
    );

    $post_id = wp_insert_post([
        'post_title'  => $title,
        'post_status' => 'publish',
        'post_type'   => 'post',
    ], true);

    if (is_wp_error($post_id) || !$post_id) {
        return createImportMessage('fail', 'Could not create the tune post.');
    }

    update_field('abc', $abc, $post_id);

    if ($tags) {
        wp_set_post_tags($post_id, $tags, false);
    }
    if ($bookTerms) {
        tunebook_assign($post_id, $bookTerms);
    }

    tune_ensure_uid($post_id);

    return createImportMessage(
        'success',
        sprintf('"%s" added! <a href="%s">View tune</a>', esc_html($title), esc_url(get_permalink($post_id)))
    );
}

$response = false;
if (isset($_POST['id'])){
   $response = importTunes();
}

$mergedResponse = false;
if (isset($_POST['tune_merged_submit'])) {
    $mergedResponse = handleMergedTuneSubmit();
}

$abcResponse = false;
if (isset($_POST['tune_abc_submit'])) {
    $abcResponse = handleAbcTuneUpload();
}

?>
    <div class="container">
        <h2>Add a tune</h2>
        <p class="text-muted">Upload a scan (optional), find its match on thesession.org, and decide how to save it — as a verified ABC transcription, the original scan, or both.</p>

        <form action="" method="post" class="mb-3" enctype="multipart/form-data" id="tune-merged-form">
            <?php wp_nonce_field('tune_merged_add', 'tune_merged_nonce'); ?>
            <?php wp_nonce_field('tune_image_upload', 'tune_image_nonce'); ?>
            <input type="hidden" name="tune_merged_submit" value="1">
            <input type="hidden" name="tune_resolution" id="merged-resolution-field" value="">
            <input type="hidden" name="merged_id" id="merged-id-field" value="">
            <input type="hidden" name="merged_setting" id="merged-setting-field" value="">

            <div class="form-group">
                <label class="form-label">Scan <span class="text-muted fw-normal">(optional, .jpg or .pdf)</span></label>
                <input class="form-control" name="tune_image_file" id="merged-file-input" type="file" accept=".jpg,.jpeg,.pdf">
                <div class="form-text">Upload a scanned sheet to help find/confirm the tune, or to save as a standalone scan if no match is found.</div>
            </div>
            <div id="merged-scan-preview" class="mt-2"></div>

            <div class="form-group mt-3">
                <label class="form-label">Title <span class="text-muted fw-normal">(also used to search thesession.org)</span></label>
                <input class="form-control" name="image_title" id="ts-search-input" type="text" placeholder="e.g. The Butterfly">
                <div class="form-text" id="merged-title-hint"></div>
            </div>

            <div class="form-group mt-2">
                <button class="btn btn-outline-secondary" type="button" id="ts-search-btn">Search thesession.org</button>
                <span id="ts-search-status" class="small text-muted ms-2"></span>
            </div>

            <div id="ts-search-results" class="mt-2"></div>

            <div id="merged-image-only-fields">
                <div class="form-group mt-3">
                    <label class="form-label">Type <span class="text-muted fw-normal">(used only if you save as a scanned tune below, without a thesession match)</span></label>
                    <input class="form-control" name="tune_type" type="text" list="tune-type-list" id="tune-type-input">
                    <datalist id="tune-type-list">
                        <?php foreach ($tuneKnownTypes as $t) : ?>
                            <option value="<?php echo esc_attr($t); ?>">
                        <?php endforeach; ?>
                    </datalist>
                    <div class="form-text">e.g. reel, jig, slip jig, hornpipe, song, … — guessed from the filename when possible, always editable.</div>
                </div>
                <div class="form-group mt-3">
                    <label class="form-label">Key <span class="text-muted fw-normal">(optional)</span></label>
                    <input class="form-control" name="tune_key" type="text" placeholder="e.g. Gmaj, Dmix">
                </div>
                <div class="form-group mt-3">
                    <button type="button" class="btn btn-primary" id="merged-save-image-only">Save as scanned tune (no thesession match)</button>
                </div>
                <div class="form-text">Have the ABC yourself instead? <a href="#tune-abc-section">Paste it directly</a>.</div>
            </div>

            <div class="form-group mt-3">
                <label class="form-label">Tags <span class="text-muted fw-normal">(optional)</span></label>
                <input class="form-control" name="tags" type="text" placeholder="Villach, whistle, harp">
            </div>

            <?php add_tune_render_books_field(); ?>
        </form>

        <?php render_import_confirm($mergedResponse); ?>
        <?php if ($mergedResponse && $mergedResponse['status'] !== 'confirm'): ?>
            <div class="alert <?php echo ($mergedResponse['status'] === 'success') ? 'alert-success' : 'alert-danger'; ?>" role="alert">
                <?php echo $mergedResponse['message'] ?>
            </div>
        <?php endif; ?>

        <script>
            // Feature #8 stage 2: merged add-tune flow. File -> guessed title
            // (and, best-effort, type) -> thesession search -> pick a setting
            // -> resolve as hybrid (abc+scan) / abc-only / image-only.
            (function () {
                var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                var nonce = <?php echo wp_json_encode(wp_create_nonce('tune_ts')); ?>;
                var knownTypes = <?php echo wp_json_encode(array_map('strtolower', $tuneKnownTypes)); ?>;

                var form = document.getElementById('tune-merged-form');
                var fileInput = document.getElementById('merged-file-input');
                var scanPreview = document.getElementById('merged-scan-preview');
                var titleInput = document.getElementById('ts-search-input');
                var titleHint = document.getElementById('merged-title-hint');
                var searchBtn = document.getElementById('ts-search-btn');
                var status = document.getElementById('ts-search-status');
                var results = document.getElementById('ts-search-results');
                var resolutionField = document.getElementById('merged-resolution-field');
                var idField = document.getElementById('merged-id-field');
                var settingField = document.getElementById('merged-setting-field');
                var imageOnlyFields = document.getElementById('merged-image-only-fields');
                var typeInput = document.getElementById('tune-type-input');
                var saveImageOnlyBtn = document.getElementById('merged-save-image-only');

                function esc(s) {
                    var d = document.createElement('div');
                    d.textContent = s == null ? '' : String(s);
                    return d.innerHTML;
                }

                // SVG preview of a thesession.org setting, rendered with the same
                // abc2svg engine used for real tunes (class-tune.php printABC2SVG()) —
                // but via its low-level Abc/tosvg API, NOT abcweb-1.js. abcweb-1.js
                // hijacks the whole page (document.body.innerHTML = ...) on window
                // load, which previously broke this very search UI when tried; the
                // low-level API only ever touches the one preview container we pass it.
                var ABC_METER_BY_TYPE = {
                    'jig': '6/8', 'slip jig': '9/8', 'polka': '2/4',
                    'mazurka': '3/4', 'slide': '12/8', 'waltz': '3/4'
                };
                var abcEnginePromise = null;
                var abcRenderBuf = '';
                var abcRenderErr = '';

                function loadAbcEngine() {
                    if (abcEnginePromise) { return abcEnginePromise; }
                    abcEnginePromise = new Promise(function (resolve, reject) {
                        if (window.abc2svg && abc2svg.Abc) { resolve(); return; }
                        var s = document.createElement('script');
                        s.src = <?php echo wp_json_encode(get_template_directory_uri() . '/js/abc2svg-1.js'); ?>;
                        s.onload = resolve;
                        s.onerror = reject;
                        document.head.appendChild(s);
                    });
                    return abcEnginePromise;
                }

                // A fresh Abc() per render, NOT a shared/reused instance: a single
                // malformed setting (thesession.org has plenty -- slash chords,
                // stray decorations, ...) can leave the abc2svg engine's internal
                // per-tune state (guitar-chord layout, concretely) corrupted, which
                // then breaks every later tosvg() call on that same instance too.
                // Reproduced+confirmed against a real "The Butterfly" search (23
                // settings): with a shared instance, one bad setting cascaded into
                // 18 more false failures; with a fresh instance per call, failures
                // stay confined to the settings that are actually malformed.
                function newAbcEngine() {
                    return new abc2svg.Abc({
                        errmsg: function (msg) { abcRenderErr += (abcRenderErr ? '\n' : '') + msg; },
                        img_out: function (str) { abcRenderBuf += str; }
                    });
                }

                // Mirrors class-tune.php's getABCHeader() + printABC2SVG() header
                // logic exactly (including its hardcoded "R:jig"), so the preview
                // matches what the tune will actually look like once imported.
                function buildPreviewAbc(type, key, abcBody) {
                    var meter = ABC_METER_BY_TYPE[(type || '').toLowerCase()] || '4/4';
                    var lines = String(abcBody || '').split('! ');
                    return '%%unsizedsvg 1\n%%fgcolor black\n%%topspace 0\n%%topmargin 0\n' +
                        '%%titlespace 0\n%%composerspace 0\n%%musicspace 0\n%%stretchlast 1\n' +
                        '%%leftmargin 0\n%%rightmargin 0\nX:1\nR:jig\nM:' + meter + '\nL:1/8\nK:' +
                        String(key || '').slice(0, 4) + '\n' + lines.join('\n') + '\n';
                }

                // Renders into `container`; falls back to the plain-text ABC (the old
                // behaviour) if the engine can't load or the ABC fails to parse.
                function renderAbcPreview(container, type, key, abcBody) {
                    function fallback(errMsg) {
                        container.innerHTML =
                            '<pre class="small mb-0" style="max-height:150px;overflow:auto">' + esc(abcBody) + '</pre>' +
                            (errMsg ? '<div class="text-danger small">Preview error: ' + esc(errMsg) + '</div>' : '');
                    }
                    loadAbcEngine().then(function () {
                        abcRenderBuf = '';
                        abcRenderErr = '';
                        try {
                            newAbcEngine().tosvg('ts-preview', buildPreviewAbc(type, key, abcBody));
                        } catch (e) {
                            fallback(e.message);
                            return;
                        }
                        if (abcRenderBuf) {
                            container.innerHTML = abcRenderBuf;
                        } else {
                            fallback(abcRenderErr);
                        }
                    }).catch(function () { fallback(); });
                }

                // Best-effort filename parsing. Scan filenames in the backlog
                // are inconsistent about where the type sits — start, end, or
                // in (brackets) — e.g. "Reel - The Sailor's Bonnet.jpg",
                // "The Sailor's Bonnet - Reel.jpg", "The Sailor's Bonnet (reel).jpg".
                // Never authoritative, always editable.
                function guessFromFilename(name) {
                    var base = name.replace(/\.[^.]+$/, '');
                    var typeGuess = '';
                    var bracket = base.match(/\(([^)]+)\)\s*$/);
                    if (bracket && knownTypes.indexOf(bracket[1].trim().toLowerCase()) !== -1) {
                        typeGuess = bracket[1].trim();
                        base = base.replace(/\s*\([^)]+\)\s*$/, '').trim();
                    }
                    var parts = base.split(' - ');
                    var title = base;
                    if (parts.length >= 2) {
                        var first = parts[0].trim();
                        var last = parts[parts.length - 1].trim();
                        if (!typeGuess && knownTypes.indexOf(first.toLowerCase()) !== -1) {
                            typeGuess = first;
                            title = parts.slice(1).join(' - ').trim();
                        } else if (!typeGuess && knownTypes.indexOf(last.toLowerCase()) !== -1) {
                            typeGuess = last;
                            title = parts.slice(0, -1).join(' - ').trim();
                        } else {
                            // Unknown split token (e.g. a composer name) — the
                            // part before " - " is still the best title guess.
                            title = first;
                        }
                    }
                    return { title: title, type: typeGuess };
                }

                fileInput.addEventListener('change', function () {
                    scanPreview.innerHTML = '';
                    if (!fileInput.files.length) { return; }
                    var file = fileInput.files[0];
                    var guess = guessFromFilename(file.name);
                    if (guess.title && !titleInput.value) {
                        titleInput.value = guess.title;
                    }
                    if (guess.type) {
                        typeInput.value = guess.type;
                        titleHint.textContent = 'Guessed from filename — title "' + guess.title + '", type "' + guess.type + '". Edit if wrong.';
                    } else if (guess.title) {
                        titleHint.textContent = 'Guessed title "' + guess.title + '" from filename. Edit if wrong.';
                    }
                    if (file.type === 'application/pdf') {
                        scanPreview.innerHTML = '<div class="text-muted small">PDF selected: ' + esc(file.name) + '</div>';
                    } else {
                        scanPreview.innerHTML = '<img src="' + URL.createObjectURL(file) + '" style="max-width:250px;max-height:250px" alt="scan preview">';
                    }
                });

                function post(action, params) {
                    var body = new URLSearchParams();
                    body.append('action', action);
                    body.append('_wpnonce', nonce);
                    Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
                    return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                        .then(function (r) { return r.json(); });
                }

                function submitWith(resolution, id, settingIndex) {
                    resolutionField.value = resolution;
                    idField.value = id || '';
                    settingField.value = settingIndex || '';
                    typeInput.required = (resolution === 'image_only');
                    form.submit();
                }

                function showDetail(t) {
                    status.textContent = 'Loading settings for "' + t.name + '"…';
                    post('tune_ts_detail', { id: t.id }).then(function (j) {
                        if (!j.success) { status.textContent = 'Error: ' + (j.data || 'failed'); return; }
                        var d = j.data;
                        var wrap = document.createElement('div');
                        wrap.className = 'border rounded p-2 mt-2';
                        wrap.innerHTML = '<strong>' + esc(d.name) + '</strong> <span class="text-muted">(' + esc(d.type) + ')</span>';
                        if (!d.settings.length) {
                            wrap.innerHTML += '<br><span class="text-muted small">No settings found.</span>';
                        }
                        d.settings.forEach(function (s) {
                            var row = document.createElement('div');
                            row.className = 'mt-2 pt-2 border-top';
                            row.innerHTML = '<div class="small text-muted">Setting ' + s.index + (s.key ? ' — ' + esc(s.key) : '') + '</div>';
                            var preview = document.createElement('div');
                            preview.className = 'abc-preview-box border rounded p-2 bg-white';
                            preview.style.overflow = 'auto';
                            preview.style.maxHeight = '200px';
                            preview.innerHTML = '<span class="text-muted small">Rendering preview…</span>';
                            row.appendChild(preview);
                            renderAbcPreview(preview, d.type, s.key, s.abc);
                            var hasFile = fileInput.files.length > 0;
                            var primaryBtn = document.createElement('button');
                            primaryBtn.type = 'button';
                            primaryBtn.className = 'btn btn-sm btn-success me-1';
                            primaryBtn.textContent = hasFile ? 'Keep ABC + scan' : 'Use this ABC';
                            primaryBtn.addEventListener('click', function () { submitWith(hasFile ? 'hybrid' : 'abc_only', d.id, s.index); });
                            row.appendChild(primaryBtn);
                            if (hasFile) {
                                var abcOnlyBtn = document.createElement('button');
                                abcOnlyBtn.type = 'button';
                                abcOnlyBtn.className = 'btn btn-sm btn-outline-secondary';
                                abcOnlyBtn.textContent = 'ABC only, discard scan';
                                abcOnlyBtn.addEventListener('click', function () { submitWith('abc_only', d.id, s.index); });
                                row.appendChild(abcOnlyBtn);
                            }
                            wrap.appendChild(row);
                        });
                        results.innerHTML = '';
                        results.appendChild(wrap);
                        status.textContent = '';
                    }).catch(function (e) { status.textContent = 'Error: ' + e.message; });
                }

                function runSearch() {
                    var q = titleInput.value.trim();
                    if (!q) { status.textContent = 'Enter a title to search.'; return; }
                    status.textContent = 'Searching…';
                    results.innerHTML = '';
                    post('tune_ts_search', { q: q }).then(function (j) {
                        if (!j.success) { status.textContent = 'Error: ' + (j.data || 'failed'); return; }
                        var d = j.data;
                        if (!d.tunes.length) {
                            status.textContent = 'No matches on thesession.org.';
                            return;
                        }
                        status.textContent = d.total + ' match(es)' + (d.pages > 1 ? ' (showing first page)' : '') + ' — pick one:';
                        var list = document.createElement('div');
                        list.className = 'list-group';
                        d.tunes.forEach(function (t) {
                            var a = document.createElement('button');
                            a.type = 'button';
                            a.className = 'list-group-item list-group-item-action';
                            a.innerHTML = esc(t.name) +
                                ' <span class="text-muted">(' + esc(t.type) + ')</span>' +
                                (t.alias ? ' <span class="text-muted small">aka ' + esc(t.alias) + '</span>' : '') +
                                ' <span class="text-muted small">#' + t.id + '</span>';
                            a.addEventListener('click', function () { showDetail(t); });
                            list.appendChild(a);
                        });
                        results.appendChild(list);
                    }).catch(function (e) { status.textContent = 'Error: ' + e.message; });
                }

                searchBtn.addEventListener('click', runSearch);
                titleInput.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') { e.preventDefault(); runSearch(); }
                });

                saveImageOnlyBtn.addEventListener('click', function () {
                    var val = typeInput.value.trim().toLowerCase();
                    if (!fileInput.files.length) { alert('Please choose a file to upload.'); return; }
                    if (!typeInput.value.trim()) { alert('Type is required.'); return; }
                    if (val && knownTypes.indexOf(val) === -1) {
                        var ok = confirm('New tune type "' + typeInput.value.trim() + '" is not in the known list (' + knownTypes.join(', ') + '). Save anyway?');
                        if (!ok) { return; }
                    }
                    submitWith('image_only', '', '');
                });
            })();
        </script>

        <hr class="my-5">

        <h2>Advanced: bulk add multiple tunes by ID</h2>
        <p class="text-muted">For adding several thesession.org tunes at once (and optionally building a set from them). For a single tune, use "Add a tune" above instead.</p>

        <form action="" method="post" class="mb-5">
            <div class="form-group">
                <label class="form-label">Tune IDs</label>
                <input class="form-control" name="id" id="ts-id-field" type="text" placeholder="405,3,446">
                <div class="form-text">Enter tune ids from the session (see session url). Multiple tunes can be added here seperated with commas and a set will automatically be created. z.b</div>
            </div>
            <div class="form-group">
                <label>Settings</label>
                <input class="form-control" name="setting" id="ts-setting-field" type="text" placeholder="1,4,2">
                <div class="form-text">Additionally the settings from the session can be defined here also separated by commas.</div>
            </div>

            <div class="form-group mt-3">
                <label class="form-label">Tags <span class="text-muted fw-normal">(optional)</span></label>
                <input class="form-control" name="tags" type="text" placeholder="Villach, whistle, harp">
                <div class="form-text">Comma-separated tags. Use for location (e.g. Villach) or instrument (e.g. whistle). Applied to all tunes and the set.</div>
            </div>

            <?php add_tune_render_books_field(); ?>

            <div class="form-group mt-4">
                <input type="checkbox" name="addset" class="me-2"><label>Create a new set for these tune/s.</label>
            </div>

            <div class="form-group mt-4">
                <button type="submit" value="save" class="btn btn-primary">Save</button>
            </div>

        </form>

        <?php render_import_confirm($response); ?>
        <?php if ($response && $response['status'] !== 'confirm'):
            $alertClass = ($response['status'] === 'success') ? 'alert-success' : 'alert-danger';
        ?>
            <div class="alert <?php echo $alertClass; ?>" role="alert">
                <?php echo $response['message'] ?>
            </div>
        <?php endif; ?>

        <hr class="my-5">

        <h2 id="tune-abc-section">Add a tune from ABC notation</h2>
        <p class="text-muted">Paste raw ABC. Type and key are read from the ABC's <code>R:</code> and <code>K:</code> headers, so include them for the tune to show its type/key.</p>

        <form action="" method="post" class="mb-5">
            <?php wp_nonce_field('tune_abc_add', 'tune_abc_nonce'); ?>

            <div class="form-group">
                <label class="form-label">Title</label>
                <input class="form-control" name="abc_title" type="text" required>
            </div>

            <div class="form-group mt-3">
                <label class="form-label">ABC notation</label>
                <textarea class="form-control" name="abc_body" rows="10" required placeholder="X:1&#10;T:The Kesh&#10;R:jig&#10;M:6/8&#10;L:1/8&#10;K:Gmaj&#10;GAB AGA|..."></textarea>
                <div class="form-text">Include <code>R:</code> (type) and <code>K:</code> (key) headers.</div>
            </div>

            <div class="form-group mt-3">
                <label class="form-label">Tags <span class="text-muted fw-normal">(optional)</span></label>
                <input class="form-control" name="tags" type="text" placeholder="Villach, whistle, harp">
            </div>

            <?php add_tune_render_books_field(); ?>

            <div class="form-group mt-4">
                <button type="submit" name="tune_abc_submit" value="1" class="btn btn-primary">Save</button>
            </div>
        </form>

        <?php if ($abcResponse): ?>
            <div class="alert <?php echo ($abcResponse['status'] == 'success') ? 'alert-success' : 'alert-danger'; ?>" role="alert">
                <?php echo $abcResponse['message'] ?>
            </div>
        <?php endif; ?>

    </div>


<?php

get_footer();