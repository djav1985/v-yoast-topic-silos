<?php

/**
 * Plugin Name: Vontainment Yoast Backend MOD
 * Description: Backend Functions For Yoast
 * Author: Vontainment
 * Author URI: https://vontainment.com
 * Version: 1.0.0
 * License: MIT
 */

// Exit if accessed directly
if (! defined('ABSPATH')) {
    exit;
}

// -----------------------------
// Automatically set Yoast social images based on featured image
// -----------------------------
add_action('save_post', 'set_yoast_social_images');

/**
 * Sets Yoast social images (Facebook, Twitter) to the post's featured image.
 *
 * @param int $post_id Post ID.
 */
function set_yoast_social_images($post_id)
{
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (wp_is_post_revision($post_id)) {
        return;
    }

    if (has_post_thumbnail($post_id)) {
        $featured_image_url = get_the_post_thumbnail_url($post_id, 'full');

        if (!empty($featured_image_url)) {
            update_post_meta($post_id, '_yoast_wpseo_opengraph-image', esc_url($featured_image_url));
            update_post_meta($post_id, '_yoast_wpseo_twitter-image', esc_url($featured_image_url));
        }
    }
}

/**
 * Disable Yoast transition words readability check
 */
add_filter(
    'wpseo_readability_analysis_active',
    function ( $active, $assessment ) {

        if (
            isset( $assessment['identifier'] )
            && $assessment['identifier'] === 'transitionWords'
        ) {
            return false;
        }

        return $active;
    },
    10,
    2
);