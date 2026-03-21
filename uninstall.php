<?php
/**
 * Uninstall handler for Vontainment Yoast Topic Silos.
 *
 * This file is executed automatically by WordPress when the plugin is deleted
 * from the Plugins screen. It removes all options added by the plugin so that
 * no data is left behind after uninstallation.
 *
 * @package VontainmentYoastTopicSilos
 */

// Bail if WordPress did not call this file directly.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove the plugin settings option.
delete_option( 'vyts_settings' );

// For multisite installations, remove the option from every site in the network.
if ( is_multisite() ) {
	$offset = 0;
	$batch  = 100;

	do {
		$sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => $batch,
				'offset' => $offset,
			)
		);

		foreach ( $sites as $site_id ) {
			switch_to_blog( $site_id );
			delete_option( 'vyts_settings' );
			restore_current_blog();
		}

		$offset += $batch;
	} while ( count( $sites ) === $batch );
}
