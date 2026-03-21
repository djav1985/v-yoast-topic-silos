<?php
/**
 * Frontend functionality for Vontainment Yoast Topic Silos.
 *
 * Handles schema graph modifications and Dublin Core metadata output.
 *
 * @package VontainmentYoastTopicSilos
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// -----------------------------
// Add aggregate rating to local business schema.
// -----------------------------
add_filter( 'wpseo_schema_organization', 'vyts_add_aggregate_rating_to_local_business' );

/**
 * Adds aggregate rating to local business schema for specific pages.
 *
 * @param array $data Schema data.
 * @return array Modified schema data.
 */
function vyts_add_aggregate_rating_to_local_business( $data ) {
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

	if (
		is_front_page() ||
		preg_match( '#^/services/web-design(/|$)#', $request_uri ) ||
		is_page( 'web-design-portfolio' )
	) {
		$settings = wp_parse_args( (array) get_option( 'vyts_settings', array() ), vyts_default_settings() );

		$data['aggregateRating'] = array(
			'@type'       => 'AggregateRating',
			'ratingValue' => $settings['rating_value'],
			'reviewCount' => $settings['rating_count'],
			'bestRating'  => '5',
			'worstRating' => '1',
		);
	}

	return $data;
}

// -----------------------------
// Remove "Place" type from schema graph nodes.
// -----------------------------
add_filter( 'wpseo_schema_graph', 'vyts_replace_place_with_localbusiness', 10, 2 );

/**
 * Removes the "Place" type from the @type array in schema graph nodes.
 *
 * This ensures the organisation is not typed as Place (which is incorrect for
 * a LocalBusiness). The entry is removed rather than replaced; Yoast already
 * outputs the correct LocalBusiness type independently.
 *
 * @param array $data    Schema graph data.
 * @param mixed $context Context object.
 * @return array Modified schema graph data.
 */
function vyts_replace_place_with_localbusiness( $data, $context ) {
	foreach ( $data as $key => $value ) {
		if ( is_array( $value['@type'] ) && in_array( 'Place', $value['@type'], true ) ) {
			$data[ $key ]['@type'] = array_values( array_diff( $value['@type'], array( 'Place' ) ) );
		}
	}
	return $data;
}

// -----------------------------
// Add Dublin Core metadata to the head section.
// -----------------------------
add_action( 'wp_head', 'vyts_add_dublin_core_metadata', 11 );

/**
 * Outputs Dublin Core metadata tags in the page head.
 */
function vyts_add_dublin_core_metadata() {
	$yoast_meta_description = get_post_meta( get_the_ID(), '_yoast_wpseo_metadesc', true );
	$yoast_focus_keywords   = get_post_meta( get_the_ID(), '_yoast_wpseo_focuskw', true );
	$site_name              = get_bloginfo( 'name' );
	$modified_date          = get_the_modified_date( 'Y-m-d' );
	?>
	<meta name="DC.Title" content="<?php echo esc_attr( get_the_title() ); ?>">
	<meta name="DC.Creator" content="<?php echo esc_attr( $site_name ); ?>">
	<meta name="DC.Subject" content="<?php echo esc_attr( $yoast_focus_keywords ); ?>">
	<meta name="DC.Description" content="<?php echo esc_attr( $yoast_meta_description ); ?>">
	<meta name="DC.Publisher" content="<?php echo esc_attr( $site_name ); ?>">
	<meta name="DC.Date" content="<?php echo esc_attr( $modified_date ); ?>">
	<meta name="DC.Format" content="text/html">
	<meta name="DC.Language" content="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
	<?php
}

// -----------------------------
// Force organization as author in schema graph.
// -----------------------------
add_filter( 'wpseo_schema_graph', 'vyts_force_org_as_author', 20 );

/**
 * Forces the author in schema graph to be an organization and removes standalone Person schema nodes.
 *
 * @param array $graph Schema graph data.
 * @return array Modified schema graph data.
 */
function vyts_force_org_as_author( $graph ) {
	$settings = wp_parse_args( (array) get_option( 'vyts_settings', array() ), vyts_default_settings() );

	foreach ( $graph as $key => &$node ) {
		// Remove standalone @type: Person blocks.
		if ( isset( $node['@type'] ) && 'Person' === $node['@type'] ) {
			unset( $graph[ $key ] );
			continue;
		}

		// Replace author in Article or BlogPosting.
		if (
			isset( $node['@type'] ) &&
			( in_array( 'Article', (array) $node['@type'], true ) || in_array( 'BlogPosting', (array) $node['@type'], true ) ) &&
			isset( $node['author'] )
		) {
			$node['author'] = array(
				'@type'       => 'Organization',
				'@id'         => $settings['org_schema_id'],
				'name'        => $settings['org_name'],
				'url'         => $settings['org_url'],
				'description' => $settings['org_description'],
				'logo'        => array(
					'@type'      => 'ImageObject',
					'@id'        => $settings['org_logo_id'],
					'url'        => $settings['org_logo_url'],
					'contentUrl' => $settings['org_logo_url'],
					'width'      => (int) $settings['org_logo_width'],
					'height'     => (int) $settings['org_logo_height'],
					'caption'    => $settings['org_logo_caption'],
				),
				'sameAs'      => (array) $settings['org_same_as'],
			);
		}
	}

	return array_values( $graph ); // Re-index array to avoid gaps.
}

// -----------------------------
// Add name to BreadcrumbList in schema graph.
// -----------------------------
add_filter( 'wpseo_schema_graph', 'vyts_name_breadcrumb_list', 20 );

/**
 * Adds a name property to BreadcrumbList nodes in the schema graph if not already set.
 *
 * @param array $graph Schema graph data.
 * @return array Modified schema graph data.
 */
function vyts_name_breadcrumb_list( $graph ) {
	foreach ( $graph as &$node ) {
		if ( isset( $node['@type'] ) && 'BreadcrumbList' === $node['@type'] && ! isset( $node['name'] ) ) {
			$node['name'] = 'Site Breadcrumbs';
		}
	}
	return $graph;
}

// -----------------------------
// Modify author meta tag in output buffer.
// -----------------------------
add_action( 'template_redirect', 'vyts_buffer_author_meta_tag' );

/**
 * Starts output buffering to replace the Yoast author meta tag with the site name.
 */
function vyts_buffer_author_meta_tag() {
	ob_start( 'vyts_replace_author_meta_tag' );
}

/**
 * Callback for ob_start: replaces the Yoast author meta tag value with the site name.
 *
 * @param string $buffer The full page output buffer.
 * @return string Modified buffer.
 */
function vyts_replace_author_meta_tag( $buffer ) {
	return preg_replace(
		'/<meta name="author" content="[^"]*" class="yoast-seo-meta-tag"\s*\/?>/',
		'<meta name="author" content="' . esc_attr( get_bloginfo( 'name' ) ) . '" class="yoast-seo-meta-tag" />',
		$buffer
	);
}
