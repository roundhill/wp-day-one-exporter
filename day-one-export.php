<?php
/*
Plugin Name: Day One Export
Description: Export posts and media to Day One JSON format.
Version: 0.1
Author: Dan Roundhill
*/

// Add an admin menu
add_action('admin_menu', 'day_one_export_menu');
function day_one_export_menu()
{
    add_menu_page('Day One Export', 'Day One Export', 'manage_options', 'day-one-export', 'day_one_export_page');
}

// Display the admin page
function day_one_export_page()
{
?>
    <div class="wrap">
        <h1>Day One Export</h1>
        <button id="start-export" class="button button-primary">Start Export</button>
        <p id="export-progress"></p>
    </div>
    <script>
        jQuery(document).ready(function($) {
            $('#start-export').on('click', function() {
                $('#export-progress').html('Starting export...');
                startExport(0);
            });

            function startExport(offset) {
                var data = {
                    action: 'start_day_one_export',
                    nonce: '<?php echo wp_create_nonce('day_one_export_nonce'); ?>',
                    offset: offset
                };
                $.post(ajaxurl, data, function(response) {
                    $('#export-progress').append(response);
                });
            }
        });
    </script>
<?php
}

// Export function
add_action('wp_ajax_start_day_one_export', 'start_day_one_export');
function start_day_one_export()
{
    // Check nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'day_one_export_nonce')) {
        die('Unauthorized access.');
    }

    // Check user capability
    if (!current_user_can('manage_options')) {
        die('Unauthorized access.');
    }

    // Set initial variables
    $batch_size = 100;
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;

    $do_dir = WP_CONTENT_DIR . '/uploads/day-one/';
    $do_media_dir = WP_CONTENT_DIR . '/uploads/day-one-media/';
    if (!file_exists($do_dir)) {
        wp_mkdir_p($do_dir);
    }
    if (!file_exists($do_media_dir)) {
        wp_mkdir_p($do_media_dir);
    }
    // Todo: cleanup files from previous exports

    // Initialize or load export data
    $site_title = sanitize_file_name(get_bloginfo('name'));
    $export_file = $do_dir . $site_title . '-' . date('Y-m-d') . '.json';
    if (file_exists($export_file)) {
        $export_data = json_decode(file_get_contents($export_file), true);
    } else {
        $export_data = array('metadata' => array('version' => '1.0'), 'entries' => array());
    }

    // Get posts for the batch
    $posts = get_posts(array(
        'post_type' => 'post',
        'post_status' => 'publish',
        'posts_per_page' => $batch_size,
        'offset' => $offset,
    ));

    // Process posts for the batch
    foreach ($posts as $post) {
        // Generate UUID from post title hash
        $uuid = strtoupper(substr(str_replace('-', '', wp_generate_uuid4()), 0, 32)); // Remove dashes, convert to uppercase, and limit length

        // Process content like shortcodes
        $post_content = apply_filters( 'the_content', $post->post_content );

        $post_data = array(
            'creationDate' => date('Y-m-d\TH:i:s\Z', strtotime(get_post_time('c', true, $post->ID))),
            'uuid' => $uuid,
            'starred' => false, // No equivalent in WordPress
            'text' => '', // Empty for now, we'll fill it later
            'tags' => array(), // Initialize tags array
            'photos' => array(), // Initialize photos array
            'videos' => array(), // Initialize videos array
        );

        // Get post title and add it as a markdown heading in the post content
        $post_title = $post->post_title;
        if (!empty($post_title)) {
            $post_data['text'] = "# $post_title\n\n";
        }

        // Get post tags
        $post_tags = wp_get_post_tags($post->ID);
        if ($post_tags) {
            foreach ($post_tags as $tag) {
                $post_data['tags'][] = $tag->name;
            }
        }

        // Process HTML, replace media with Day One URLs, and add to export data
        process_post_html($post_content, $post_data, $export_data, $do_media_dir);
    }

    // Convert to JSON
    $json_data = json_encode($export_data, JSON_PRETTY_PRINT);

    // Save JSON to file
    file_put_contents($export_file, $json_data);

    // If no more posts, export is completed
    if (count($posts) < $batch_size) {
        echo 'Export completed to the wp-content/uploads folder. Now, <a href="' . admin_url('admin-post.php?action=finalize_day_one_export') . '">Create a Zip</a>. (Be patient).';
        die();
    }

    // Update progress message and start next batch
    $next_offset = $offset + $batch_size;
    $progress_message = 'Batch completed. Starting next batch with offset ' . $next_offset . '...<br>';
    $progress_message .= '<script>jQuery.post(ajaxurl, { action: "start_day_one_export", nonce: "' . wp_create_nonce('day_one_export_nonce') . '", offset: ' . $next_offset . ' }, function(response) { jQuery("#export-progress").html(response); });</script>';
    echo $progress_message;
    die();
}

// Finalize export and create zip file
add_action('admin_post_finalize_day_one_export', 'finalize_day_one_export');
function finalize_day_one_export()
{
    // Check user capability
    if (!current_user_can('manage_options')) {
        die('Unauthorized access.');
    }

    // Get site title
    $site_title = sanitize_file_name(get_bloginfo('name'));

    // JSON file created over the batches
    $json_filename = $site_title . '-' . date('Y-m-d') . '.json';
    $json_filepath = WP_CONTENT_DIR . '/uploads/day-one/' . $json_filename;

    // Create a zip archive
    $zip = new ZipArchive();
    $zip_filename = $site_title . '-' . date('Y-m-d') . '.zip';
    $zip_filepath = WP_CONTENT_DIR . '/uploads/day-one/' . $zip_filename;
    if ($zip->open($zip_filepath, ZipArchive::CREATE) === TRUE) {
        // Add JSON file to the zip archive
        $zip->addFile($json_filepath, basename($json_filepath));

        // Add media files to the zip archive
        $media_folder = WP_CONTENT_DIR . '/uploads/day-one-media/';
        $media_files = glob($media_folder . '*');
        foreach ($media_files as $media_file) {
            $ext = strtolower(pathinfo($media_file, PATHINFO_EXTENSION));
            $folder_name = in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp')) ? 'photos' : 'videos';
            $zip->addFile($media_file, $folder_name . '/' . basename($media_file));
        }

        $zip->close();
    } else {
        die('Failed to create zip archive.');
    }

    // Output zip file download link
    echo 'Export completed. <a href="' . esc_url(content_url('/uploads/day-one/' . $zip_filename)) . '">Download ZIP file</a>.';
    die();
}

// Function to convert HTML tags to Markdown
function process_post_html($html_content, &$post_data, &$export_data, $do_media_dir = '')
{
    $p = new WP_HTML_Tag_Processor($html_content);

    $text_content  = '';
    $in_pre        = false;
    $needs_newline = false;
    $prev_was_li   = false;

    while ($p->next_token()) {
        $node_name = $p->get_token_name();

        $node_text = $p->get_modifiable_text();
        $tag_name  = '#tag' === $p->get_token_type() ? ($p->is_tag_closer() ? '-' : '+') . $node_name : $node_name;
        $href      = '';

        if ('#tag' === $p->get_token_type() && !$p->is_tag_closer() && is_line_breaker($node_name)) {
            $needs_newline = !$prev_was_li;
        }

        switch ($tag_name) {
            case '+LI':
                $text_content .= "\n - ";
                $needs_newline = false;
                break;

            case '+H1':
            case '+H2':
            case '+H3':
            case '+H4':
            case '+H5':
            case '+H6':
                $text_content .= "\n\n" . str_pad('', intval($node_name[1]), '#') . ' ';
                break;

            case '+IMG':
            case '+SOURCE':
                $media_url = $p->get_attribute( 'src' );
                $media_url = str_replace('danroundhill.com', 'roundhill.blog', $media_url);
                if ( is_string( $media_url ) && ! empty( $media_url ) ) {
                    $parsed_url = parse_url($media_url);
                    $media_filename = basename($parsed_url['path']);

                    $media_content = file_get_contents($media_url);
                    if ( !$media_content ) {
                        break;
                    }

                    $media_md5 = md5($media_content);
                    $media_ext = pathinfo($media_filename, PATHINFO_EXTENSION);
                    $media_uuid = $media_uuid = strtoupper(substr(str_replace('-', '', wp_generate_uuid4()), 0, 32));
                    $media_filepath = $do_media_dir . $media_md5 . '.' . $media_ext;

                    // Download media file
                    file_put_contents($media_filepath, $media_content);

                    // Replace entire media tag with "dayone-moment://" URL directly in the post content
                    $text_content .= "\n\n![](dayone-moment://$media_uuid)";

                    // Add media to photos or videos array based on file extension
                    $media_type = $media_ext;
                    $media_date = date('Y-m-d\TH:i:s\Z', filemtime($media_filepath));
                    $media_item = array(
                        'identifier' => $media_uuid, // Use UUID for identifier
                        'date' => $media_date,
                        'type' => $media_type,
                        'md5' => $media_md5,
                    );

                    if (in_array($media_type, array('jpg', 'jpeg', 'png', 'gif'))) {
                        $post_data['photos'][] = $media_item;
                    } elseif (in_array($media_type, array('mp4', 'mov', 'avi', 'wmv'))) {
                        $post_data['videos'][] = $media_item;
                    }
                }
                break;

            case '+STRONG':
            case '+B':
                $text_content .= '**';
                break;

            case '-STRONG':
            case '-B':
                $text_content .= '**';
                break;

            case '+EM':
            case '+I':
                $text_content .= '*';
                break;

            case '-EM':
            case '-I':
                $text_content .= '*';
                break;

            case '+U':
                // Underline is often interpreted as bold in Markdown, so let's use double underscores
                $text_content .= '__';
                break;

            case '-U':
                $text_content .= '__';
                break;

            case '+A':
                $href = $p->get_attribute('href');
                $text = '';
                $p->next_token();
                if ('#text' === $p->get_token_name()) {
                    $text = "[{$p->get_modifiable_text()}]";
                }

                $text_content .= "$text($href)";

                break;

            case '#text':
                if ($needs_newline) {
                    $text_content .= "\n\n";
                    $needs_newline = false;
                }
                $text_content .= $in_pre ? $node_text : preg_replace('~[ \t\r\f\n]+~', ' ', $node_text);
                break;
        }
    }

    $post_data['text'] .= trim($text_content);

    $export_data['entries'][] = $post_data;
}

function is_line_breaker($tag_name)
{
    switch ($tag_name) {
        case 'BLOCKQUOTE':
        case 'BR':
        case 'DD':
        case 'DIV':
        case 'DL':
        case 'DT':
        case 'FIGCAPTION':
        case 'H1':
        case 'H2':
        case 'H3':
        case 'H4':
        case 'H5':
        case 'H6':
        case 'HR':
        case 'LI':
        case 'OL':
        case 'P':
        case 'UL':
            return true;
    }

    return false;
}
