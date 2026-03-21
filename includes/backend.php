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
// Topic-silo metabox: register, render, and enqueue assets.
// -----------------------------
add_action( 'add_meta_boxes', 'vyts_register_silo_metabox' );
add_action( 'admin_enqueue_scripts', 'vyts_enqueue_metabox_assets' );

/**
 * Registers the Topic Silo metabox on post and page edit screens.
 */
function vyts_register_silo_metabox() {
	foreach ( array( 'post', 'page' ) as $post_type ) {
		add_meta_box(
			'vyts_silo_metabox',
			__( 'Topic Silo – Related Links', 'v-yoast-topic-silos' ),
			'vyts_render_silo_metabox',
			$post_type,
			'side',
			'default'
		);
	}
}

/**
 * Renders the Topic Silo metabox content.
 *
 * Lists posts and pages that share a category or tag with the current post.
 * Each link copies the permalink to the clipboard instead of navigating away.
 *
 * @param WP_Post $post Current post object.
 */
function vyts_render_silo_metabox( $post ) {
	// Collect term IDs from both categories and tags.
	$term_ids = array();

	$categories = get_the_category( $post->ID );
	foreach ( $categories as $cat ) {
		$term_ids[] = (int) $cat->term_id;
	}

	$tags = get_the_tags( $post->ID );
	if ( is_array( $tags ) ) {
		foreach ( $tags as $tag ) {
			$term_ids[] = (int) $tag->term_id;
		}
	}

	// Build the query: posts and pages in the same silo, excluding the current one.
	$query_args = array(
		'post_type'           => array( 'post', 'page' ),
		'post_status'         => 'publish',
		'posts_per_page'      => 20,
		'post__not_in'        => array( $post->ID ),
		'ignore_sticky_posts' => true,
		'orderby'             => 'title',
		'order'               => 'ASC',
	);

	if ( ! empty( $term_ids ) ) {
		$query_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			'relation' => 'OR',
			array(
				'taxonomy'         => 'category',
				'field'            => 'term_id',
				'terms'            => $term_ids,
				'operator'         => 'IN',
				'include_children' => false,
			),
			array(
				'taxonomy'         => 'post_tag',
				'field'            => 'term_id',
				'terms'            => $term_ids,
				'operator'         => 'IN',
				'include_children' => false,
			),
		);
	}

	$silo_query = new WP_Query( $query_args );

	if ( ! $silo_query->have_posts() ) {
		echo '<p class="vyts-no-items">' . esc_html__( 'No related posts or pages found in the same silo.', 'v-yoast-topic-silos' ) . '</p>';
		wp_reset_postdata();
		return;
	}
	?>
	<p class="vyts-instructions"><?php esc_html_e( 'Click a link to copy its URL to your clipboard.', 'v-yoast-topic-silos' ); ?></p>
	<ul class="vyts-silo-list">
		<?php while ( $silo_query->have_posts() ) : ?>
			<?php $silo_query->the_post(); ?>
			<li>
				<a href="#"
				   class="vyts-copy-link"
				   data-copy-url="<?php echo esc_url( get_permalink() ); ?>"
				   title="<?php echo esc_attr( get_the_title() ); ?>">
					<?php echo esc_html( get_the_title() ); ?>
				</a>
			</li>
		<?php endwhile; ?>
	</ul>
	<span class="vyts-copied-notice" style="display:none;" aria-live="polite">
		<?php esc_html_e( 'Copied!', 'v-yoast-topic-silos' ); ?>
	</span>
	<?php
	wp_reset_postdata();
}

/**
 * Enqueues inline CSS and JS for the Topic Silo metabox on post/page edit screens.
 *
 * @param string $hook_suffix Current admin page hook.
 */
function vyts_enqueue_metabox_assets( $hook_suffix ) {
	if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}

	$css = '
		.vyts-silo-list { margin: 0; padding: 0; list-style: none; }
		.vyts-silo-list li { margin: 4px 0; }
		.vyts-copy-link { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #2271b1; text-decoration: none; }
		.vyts-copy-link:hover { text-decoration: underline; }
		.vyts-copied-notice { display: inline-block; margin-top: 6px; padding: 2px 8px; background: #00a32a; color: #fff; border-radius: 3px; font-size: 12px; }
		.vyts-instructions { color: #646970; font-style: italic; margin-bottom: 6px; }
	';
	wp_add_inline_style( 'wp-admin', $css );

	$js = '
		document.addEventListener( "DOMContentLoaded", function () {
			var metabox = document.getElementById( "vyts_silo_metabox" );
			if ( ! metabox ) { return; }

			var notice = metabox.querySelector( ".vyts-copied-notice" );

			metabox.addEventListener( "click", function ( e ) {
				var link = e.target.closest( ".vyts-copy-link" );
				if ( ! link ) { return; }

				e.preventDefault();

				var url = link.getAttribute( "data-copy-url" );
				if ( ! url ) { return; }

				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( url ).then( function () {
						showNotice();
					} ).catch( function () {
						fallbackCopy( url );
					} );
				} else {
					fallbackCopy( url );
				}
			} );

			function showNotice() {
				if ( ! notice ) { return; }
				notice.style.display = "inline-block";
				setTimeout( function () {
					notice.style.display = "none";
				}, 1500 );
			}

			function fallbackCopy( url ) {
				var el = document.createElement( "textarea" );
				el.value = url;
				el.setAttribute( "readonly", "" );
				el.style.cssText = "position:absolute;left:-9999px;";
				document.body.appendChild( el );
				el.select();
				try {
					document.execCommand( "copy" );
					showNotice();
				} catch ( err ) {
					/* silent fail */
				}
				document.body.removeChild( el );
			}
		} );
	';
	wp_add_inline_script( 'wp-api', $js );
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
