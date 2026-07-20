<?php                                                                                                           
// DO NOT TOUCH THIS FILE 


define( 'SNN_PATH', trailingslashit( get_stylesheet_directory() ) );    
define( 'SNN_PATH_ASSETS', trailingslashit( SNN_PATH . 'assets' ) );    
define( 'SNN_URL', trailingslashit( get_stylesheet_directory_uri() ) ); 
define( 'SNN_URL_ASSETS', trailingslashit( SNN_URL . 'assets' ) );  


// Main Features and Settings
require_once SNN_PATH . 'includes/features/settings-page.php';

require_once SNN_PATH . 'includes/features/other-settings.php';
require_once SNN_PATH . 'includes/features/security-page.php';
require_once SNN_PATH . 'includes/features/post-types-settings.php';
require_once SNN_PATH . 'includes/features/custom-field-settings.php';
require_once SNN_PATH . 'includes/features/taxonomy-settings.php';
require_once SNN_PATH . 'includes/features/admin-ui-design.php';
require_once SNN_PATH . 'includes/features/login-settings.php';
require_once SNN_PATH . 'includes/features/remove-wp-version.php';
require_once SNN_PATH . 'includes/features/disable-xmlrpc.php';
require_once SNN_PATH . 'includes/features/disable-file-editing.php';
require_once SNN_PATH . 'includes/features/remove-rss.php';
require_once SNN_PATH . 'includes/features/disable-wp-json-if-not-logged-in.php';
require_once SNN_PATH . 'includes/features/login-logo-change-url-change.php';
require_once SNN_PATH . 'includes/features/enqueue-scripts.php';
require_once SNN_PATH . 'includes/features/file-size-column-media.php';
require_once SNN_PATH . 'includes/features/smtp-settings.php';
require_once SNN_PATH . 'includes/features/disable-emojis.php';
require_once SNN_PATH . 'includes/features/disable-gravatar.php';
require_once SNN_PATH . 'includes/features/custom-code-snippets.php';
require_once SNN_PATH . 'includes/features/analytics.php';
require_once SNN_PATH . 'includes/features/redirects.php';
require_once SNN_PATH . 'includes/features/cookie-banner.php';
require_once SNN_PATH . 'includes/features/interactions.php';


// Utils
require_once SNN_PATH . 'includes/features/utils.php';
// Auto-updater, repointed at our own fork (digital-workshop/bricks-child-theme)
// instead of the upstream repo -- see includes/features/auto-update-snn-brx-github.php
require_once SNN_PATH . 'includes/features/auto-update-snn-brx-github.php';



// Load Translations
add_action('after_setup_theme', function() {
    load_theme_textdomain('snn', SNN_PATH . '/languages');
});








// ------------------------------------------------------------------------------
// Bricks Features
// ------------------------------------------------------------------------------

require_once SNN_PATH . 'includes/features/editor-settings-bricks.php';
require_once SNN_PATH . 'includes/features/editor-class-generator.php';
require_once SNN_PATH . 'includes/features/editor-custom-css.php';

require_once SNN_PATH . 'includes/features/media-image-opt.php';
require_once SNN_PATH . 'includes/features/wp-admin-dashboard-widgets.php';

// Register Custom Dynamic Data Tags
require_once SNN_PATH . 'includes/dynamic-data-tags/estimated-post-read-time.php';
require_once SNN_PATH . 'includes/dynamic-data-tags/get-contextual-id.php';
require_once SNN_PATH . 'includes/dynamic-data-tags/contextual-slug.php';
require_once SNN_PATH . 'includes/dynamic-data-tags/get-contextual-content.php';
require_once SNN_PATH . 'includes/dynamic-data-tags/parent-link.php';
require_once SNN_PATH . 'includes/dynamic-data-tags/parent-id.php';
require_once SNN_PATH . 'includes/dynamic-data-tags/parent-detection.php';
require_once SNN_PATH . 'includes/dynamic-data-tags/child-link.php';
require_once SNN_PATH . 'includes/dynamic-data-tags/first-child-post.php';
require_once SNN_PATH . 'includes/dynamic-data-tags/post-term-count.php';
require_once SNN_PATH . 'includes/dynamic-data-tags/user-author-fields.php';
require_once SNN_PATH . 'includes/dynamic-data-tags/custom-field-repeater-first-item.php';
require_once SNN_PATH . 'includes/dynamic-data-tags/attachment-metadata.php';
require_once SNN_PATH . 'includes/dynamic-data-tags/raw-all-custom-fields.php';
require_once SNN_PATH . 'includes/dynamic-data-tags/day-count-since-the-current-post-published.php';
require_once SNN_PATH . 'includes/dynamic-data-tags/current-loop-item-count.php';
require_once SNN_PATH . 'includes/dynamic-data-tags/total-video-duration.php';
require_once SNN_PATH . 'includes/dynamic-data-tags/single-video-duration.php';
require_once SNN_PATH . 'includes/dynamic-data-tags/options-and-fields.php';
require_once SNN_PATH . 'includes/dynamic-data-tags/average-comment-rating.php';
require_once SNN_PATH . 'includes/dynamic-data-tags/comment-count-current-user.php';
require_once SNN_PATH . 'includes/dynamic-data-tags/child-post-count.php';
require_once SNN_PATH . 'includes/dynamic-data-tags/comment-count-current-post.php';
require_once SNN_PATH . 'includes/dynamic-data-tags/get-parent-and-child-posts-list.php';
require_once SNN_PATH . 'includes/dynamic-data-tags/current-post-custom-field-output.php';
require_once SNN_PATH . 'includes/dynamic-data-tags/current-author.php';
require_once SNN_PATH . 'includes/dynamic-data-tags/get_id_from_url_output_content.php';
require_once SNN_PATH . 'includes/dynamic-data-tags/parent-child-count.php';
require_once SNN_PATH . 'includes/dynamic-data-tags/parent-post-count.php';



require_once SNN_PATH . 'includes/query/snn-repeaters-and-queries.php';
require_once SNN_PATH . 'includes/query/snn-double-repeaters-and-queries.php';






// Register Custom Bricks Builder Elements
add_action('init', function () {
\Bricks\Elements::register_element(SNN_PATH . 'includes/elements/custom-maps.php');


// if GSAP setting is enabled Register Elements
$options = snn_get_interactions_settings();
if (!empty($options['enqueue_gsap'])) {
    \Bricks\Elements::register_element(SNN_PATH . 'includes/elements/gsap-animations.php');
    \Bricks\Elements::register_element(SNN_PATH . 'includes/elements/gsap-animations-code.php');
    \Bricks\Elements::register_element(SNN_PATH . 'includes/elements/gsap-text-animations.php');
    \Bricks\Elements::register_element(SNN_PATH . 'includes/elements/svg-animation.php');
    
}



}, 11);


$options = snn_get_interactions_settings();
if (!empty($options['enqueue_gsap'])) {

    require_once SNN_PATH . 'includes/elements/gsap-multi-element-register.php';

}

