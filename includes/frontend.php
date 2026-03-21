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
		$data['aggregateRating'] = array(
			'@type'       => 'AggregateRating',
			'ratingValue' => VYTS_RATING_VALUE,
			'reviewCount' => VYTS_RATING_COUNT,
			'bestRating'  => '5',
			'worstRating' => '1',
		);
	}

	return $data;
}

// -----------------------------
// Replace "Place" with "LocalBusiness" in schema graph.
// -----------------------------
add_filter( 'wpseo_schema_graph', 'vyts_replace_place_with_localbusiness', 10, 2 );

/**
 * Replaces "Place" type with "LocalBusiness" in the schema graph.
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
				'@id'         => VYTS_ORG_SCHEMA_ID,
				'name'        => 'Vontainment',
				'url'         => 'https://vontainment.com/',
				'description' => 'Vontainment is a digital design and IT firm in Port Charlotte, Florida, offering web design, SEO, social media, and tech services tailored to small businesses.',
				'logo'        => array(
					'@type'      => 'ImageObject',
					'@id'        => 'https://vontainment.com/#organizationlogo',
					'url'        => 'https://vontainment.com/wp-content/uploads/2023/01/vontainment-logo.png',
					'contentUrl' => 'https://vontainment.com/wp-content/uploads/2023/01/vontainment-logo.png',
					'width'      => 600,
					'height'     => 120,
					'caption'    => 'Vontainment',
				),
				'sameAs'      => array(
					'https://www.facebook.com/vontainmentswfl/',
					'https://x.com/VontainmentSWFL',
					'https://www.instagram.com/vontainmentswfl/',
					'https://www.youtube.com/c/VontainmentPuntaGorda',
					'https://github.com/djav1985',
				),
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
