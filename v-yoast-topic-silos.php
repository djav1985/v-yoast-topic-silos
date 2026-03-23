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

/**
 * Returns the default settings array used both for seeding options on activation
 * and as fallback values throughout the plugin.
 *
 * @return array<string, mixed>
 */
function vyts_default_settings() {
	return array(
		'rating_value'       => '4.8',
		'rating_count'       => '32',
		'org_schema_id'      => 'https://vontainment.com/#/schema/organization/932a68de94362ace3f0c11b0554c73e4',
		'org_name'           => 'Vontainment',
		'org_url'            => 'https://vontainment.com/',
		'org_description'    => 'Vontainment is a digital design and IT firm in Port Charlotte, Florida, offering web design, SEO, social media, and tech services tailored to small businesses.',
		'org_logo_id'        => 'https://vontainment.com/#organizationlogo',
		'org_logo_url'       => 'https://vontainment.com/wp-content/uploads/2023/01/vontainment-logo.png',
		'org_logo_width'     => 600,
		'org_logo_height'    => 120,
		'org_logo_caption'   => 'Vontainment',
		'org_same_as'        => array(
			'https://www.facebook.com/vontainmentswfl/',
			'https://x.com/VontainmentSWFL',
			'https://www.instagram.com/vontainmentswfl/',
			'https://www.youtube.com/c/VontainmentPuntaGorda',
			'https://github.com/djav1985',
		),
	);
}

/**
 * Plugin activation callback.
 *
 * Seeds the vyts_settings option with defaults if it does not already exist.
 * Uses add_option() so existing customised values are never overwritten on re-activation.
 */
function vyts_activate() {
	add_option( 'vyts_settings', vyts_default_settings() );
}
register_activation_hook( VYTS_PLUGIN_FILE, 'vyts_activate' );

/**
 * Check that Yoast SEO is active. If not, deactivate this plugin and show an admin notice.
 */
function vyts_check_yoast_dependency() {
	if ( ! defined( 'WPSEO_VERSION' ) ) {
		add_action( 'admin_notices', 'vyts_missing_yoast_notice' );

		// Deactivate this plugin gracefully.
		deactivate_plugins( plugin_basename( VYTS_PLUGIN_FILE ) );
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
