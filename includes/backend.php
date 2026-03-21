<?php
/**
 * Backend functionality for Vontainment Yoast Topic Silos.
 *
 * Handles save_post hooks and readability filter modifications for Yoast SEO.
 *
 * @package VontainmentYoastTopicSilos
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// -----------------------------
// Automatically set Yoast social images based on featured image.
// -----------------------------
add_action( 'save_post', 'vyts_set_yoast_social_images' );

/**
 * Sets Yoast social images (Facebook, Twitter) to the post's featured image.
 *
 * @param int $post_id Post ID.
 */
function vyts_set_yoast_social_images( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}

	if ( has_post_thumbnail( $post_id ) ) {
		$featured_image_url = get_the_post_thumbnail_url( $post_id, 'full' );

		if ( ! empty( $featured_image_url ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_opengraph-image', esc_url_raw( $featured_image_url ) );
			update_post_meta( $post_id, '_yoast_wpseo_twitter-image', esc_url_raw( $featured_image_url ) );
		}
	}
}

// -----------------------------
// Disable Yoast transition words readability check.
// -----------------------------
add_filter( 'wpseo_readability_analysis_active', 'vyts_disable_transition_words_check', 10, 2 );

/**
 * Disables the Yoast transition words readability assessment.
 *
 * @param bool  $active     Whether the assessment is active.
 * @param array $assessment Assessment configuration.
 * @return bool False when the assessment is transitionWords, original value otherwise.
 */
function vyts_disable_transition_words_check( $active, $assessment ) {
	if ( isset( $assessment['identifier'] ) && 'transitionWords' === $assessment['identifier'] ) {
		return false;
	}

	return $active;
}
