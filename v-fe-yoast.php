<?php

/**
 * Plugin Name: Vontainment Yoast Frontend MOD
 * Description: Frontend Functions For Yoast
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
// Add aggregate rating to local business schema
// -----------------------------
add_filter('wpseo_schema_organization', 'vontmnt_add_aggregate_rating_to_local_business');

/**
 * Adds aggregate rating to local business schema for specific pages.
 *
 * @param array $data Schema data.
 * @return array Modified schema data.
 */
function vontmnt_add_aggregate_rating_to_local_business($data)
{
    if (
        is_front_page() ||
        preg_match("#^/services/web-design(/|$)#", $_SERVER['REQUEST_URI']) ||
        is_page('web-design-portfolio')
    ) {
        $data['aggregateRating'] = [
            '@type'       => 'AggregateRating',
            'ratingValue' => '4.8',
            'reviewCount' => '32',
            'bestRating'  => '5',
            'worstRating' => '1'
        ];
    }

    return $data;
}

// -----------------------------
// Replace "Place" with "LocalBusiness" in schema graph
// -----------------------------
add_filter('wpseo_schema_graph', 'replace_place_with_localbusiness_correctly', 10, 2);

/**
 * Replaces "Place" with "LocalBusiness" in schema graph.
 *
 * @param array $data Schema graph data.
 * @param Meta_Tags_Context $context Context object.
 * @return array Modified schema graph data.
 */
function replace_place_with_localbusiness_correctly($data, $context)
{
    foreach ($data as $key => $value) {
        if (is_array($value['@type']) && in_array('Place', $value['@type'], true)) {
            $data[$key]['@type'] = array_values(array_diff($value['@type'], ['Place']));
        }
    }
    return $data;
}

// -----------------------------
// Add Dublin Core metadata to the head section
// -----------------------------
add_action('wp_head', 'add_dublin_core_metadata', 11);

/**
 * Adds Dublin Core metadata to the head section.
 */
function add_dublin_core_metadata()
{
    $yoast_meta_description = get_post_meta(get_the_ID(), '_yoast_wpseo_metadesc', true);
    $yoast_focus_keywords = get_post_meta(get_the_ID(), '_yoast_wpseo_focuskw', true);
    $site_name = get_bloginfo('name');
    $modified_date = get_the_modified_date('Y-m-d');

?>
    <meta name="DC.Title" content="<?php echo esc_attr(get_the_title()); ?>">
    <meta name="DC.Creator" content="<?php echo esc_attr($site_name); ?>">
    <meta name="DC.Subject" content="<?php echo esc_attr($yoast_focus_keywords); ?>">
    <meta name="DC.Description" content="<?php echo esc_attr($yoast_meta_description); ?>">
    <meta name="DC.Publisher" content="<?php echo esc_attr($site_name); ?>">
    <meta name="DC.Date" content="<?php echo esc_attr($modified_date); ?>">
    <meta name="DC.Format" content="text/html">
    <meta name="DC.Language" content="<?php echo esc_attr(get_bloginfo('language')); ?>">
<?php
}

// -----------------------------
// Force organization as author in schema graph
// -----------------------------
add_filter('wpseo_schema_graph', 'vontmnt_force_org_as_author', 20);

/**
 * Forces the author in schema graph to be an organization and removes person schema.
 *
 * @param array $graph Schema graph data.
 * @return array Modified schema graph data.
 */
function vontmnt_force_org_as_author($graph)
{
    foreach ($graph as $key => &$node) {
        // Remove standalone @type: Person blocks
        if (isset($node['@type']) && $node['@type'] === 'Person') {
            unset($graph[$key]);
            continue;
        }

        // Replace author in Article or BlogPosting
        if (
            isset($node['@type']) &&
            (in_array('Article', (array) $node['@type']) || in_array('BlogPosting', (array) $node['@type'])) &&
            isset($node['author'])
        ) {
            $node['author'] = [
                '@type' => 'Organization',
                '@id' => 'https://vontainment.com/#/schema/organization/932a68de94362ace3f0c11b0554c73e4',
                'name' => 'Vontainment',
                'url' => 'https://vontainment.com/',
                'description' => 'Vontainment is a digital design and IT firm in Port Charlotte, Florida, offering web design, SEO, social media, and tech services tailored to small businesses.',
                'logo' => [
                    '@type' => 'ImageObject',
                    '@id' => 'https://vontainment.com/#organizationlogo',
                    'url' => 'https://vontainment.com/wp-content/uploads/2023/01/vontainment-logo.png',
                    'contentUrl' => 'https://vontainment.com/wp-content/uploads/2023/01/vontainment-logo.png',
                    'width' => 600,
                    'height' => 120,
                    'caption' => 'Vontainment'
                ],
                'sameAs' => [
                    'https://www.facebook.com/vontainmentswfl/',
                    'https://x.com/VontainmentSWFL',
                    'https://www.instagram.com/vontainmentswfl/',
                    'https://www.youtube.com/c/VontainmentPuntaGorda',
                    'https://github.com/djav1985'
                ]
            ];
        }
    }

    return array_values($graph); // reindex array to avoid gaps
}

// -----------------------------
// Add name to BreadcrumbList in schema graph
// -----------------------------
add_filter('wpseo_schema_graph', 'vontmnt_name_breadcrumb_list', 20);

/**
 * Adds a name to BreadcrumbList in schema graph if not set.
 *
 * @param array $graph Schema graph data.
 * @return array Modified schema graph data.
 */
function vontmnt_name_breadcrumb_list($graph)
{
    foreach ($graph as &$node) {
        if (isset($node['@type']) && $node['@type'] === 'BreadcrumbList' && !isset($node['name'])) {
            $node['name'] = 'Site Breadcrumbs';
        }
    }
    return $graph;
}

// -----------------------------
// Modify author meta tag in output buffer
// -----------------------------
add_action('template_redirect', function () {
    ob_start(function ($buffer) {
        return preg_replace(
            '/<meta name="author" content="[^"]*" class="yoast-seo-meta-tag"\s*\/?>/',
            '<meta name="author" content="' . esc_attr(get_bloginfo('name')) . '" class="yoast-seo-meta-tag" />',
            $buffer
        );
    });
});
