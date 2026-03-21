<?php
/**
 * Plugin Name: Vontainment Yoast Topic Silos
 * Plugin URI:  https://vontainment.com
 * Description: Manages Yoast SEO topic silos, social images, schema modifications, and Dublin Core metadata. Requires Yoast SEO.
 * Version:     1.0.0
 * Author:      Vontainment
 * Author URI:  https://vontainment.com
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: v-yoast-topic-silos
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VYTS_VERSION', '1.0.0' );
define( 'VYTS_PLUGIN_FILE', __FILE__ );
define( 'VYTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/** Organization schema ID used across schema graph filters. */
define( 'VYTS_ORG_SCHEMA_ID', 'https://vontainment.com/#/schema/organization/932a68de94362ace3f0c11b0554c73e4' );

/** Aggregate rating defaults (update via constants or override in a child plugin). */
define( 'VYTS_RATING_VALUE', '4.8' );
define( 'VYTS_RATING_COUNT', '32' );

/**
 * Check that Yoast SEO is active. If not, deactivate this plugin and show an admin notice.
 */
function vyts_check_yoast_dependency() {
	if ( ! defined( 'WPSEO_VERSION' ) ) {
		add_action( 'admin_notices', 'vyts_missing_yoast_notice' );

		// Deactivate this plugin gracefully.
		deactivate_plugins( plugin_basename( VYTS_PLUGIN_FILE ) );

		// Prevent the "Plugin activated" notice from showing by redirecting without the activate param.
		if ( isset( $_GET['activate'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_safe_redirect( remove_query_arg( 'activate' ) );
			exit;
		}
	}
}
add_action( 'admin_init', 'vyts_check_yoast_dependency' );

/**
 * Admin notice displayed when Yoast SEO is not active.
 */
function vyts_missing_yoast_notice() {
	echo '<div class="notice notice-error"><p>' .
		wp_kses(
			sprintf(
				/* translators: %s: Yoast SEO plugin link */
				__( '<strong>Vontainment Yoast Topic Silos</strong> requires %s to be installed and activated.', 'v-yoast-topic-silos' ),
				'<a href="https://wordpress.org/plugins/wordpress-seo/" target="_blank" rel="noopener noreferrer">Yoast SEO</a>'
			),
			array(
				'strong' => array(),
				'a'      => array(
					'href'   => array(),
					'target' => array(),
					'rel'    => array(),
				),
			)
		) .
		'</p></div>';
}

/**
 * Load plugin functionality only when Yoast SEO is available.
 */
function vyts_load() {
	if ( ! defined( 'WPSEO_VERSION' ) ) {
		return;
	}

	require_once VYTS_PLUGIN_DIR . 'includes/backend.php';
	require_once VYTS_PLUGIN_DIR . 'includes/frontend.php';
}
add_action( 'plugins_loaded', 'vyts_load' );
