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
// Topic Cluster metaboxes: register, render, save, and enqueue assets.
// -----------------------------
add_action( 'add_meta_boxes', 'vyts_register_silo_metabox' );
add_action( 'add_meta_boxes', 'vyts_register_page_category_metabox' );
add_action( 'save_post_page', 'vyts_save_page_category', 10, 1 );
add_action( 'admin_enqueue_scripts', 'vyts_enqueue_metabox_assets' );

/**
 * Registers the Topic Cluster metabox on post and page edit screens.
 */
function vyts_register_silo_metabox() {
	foreach ( array( 'post', 'page' ) as $post_type ) {
		add_meta_box(
			'vyts_silo_metabox',
			__( 'Topic Cluster – Links', 'v-yoast-topic-silos' ),
			'vyts_render_silo_metabox',
			$post_type,
			'side',
			'default'
		);
	}
}

/**
 * Registers the Page Category metabox on the page edit screen.
 *
 * Pages do not support the built-in category taxonomy, so this metabox
 * provides a way to associate a page with one or more categories for the
 * purpose of topic-cluster / silo grouping.
 */
function vyts_register_page_category_metabox() {
	add_meta_box(
		'vyts_page_category_metabox',
		__( 'Topic Cluster – Page Category', 'v-yoast-topic-silos' ),
		'vyts_render_page_category_metabox',
		'page',
		'side',
		'default'
	);
}

/**
 * Renders the Page Category metabox.
 *
 * Displays a checkbox list of all WordPress categories so editors can
 * associate a page with the appropriate topic cluster / silo.
 *
 * @param WP_Post $post Current post object.
 */
function vyts_render_page_category_metabox( $post ) {
	wp_nonce_field( 'vyts_save_page_category', 'vyts_page_category_nonce' );

	$saved_ids  = get_post_meta( $post->ID, '_vyts_page_category_ids', false );
	$saved_ids  = is_array( $saved_ids ) ? array_filter( array_map( 'intval', $saved_ids ), function ( $id ) { return $id > 0; } ) : array();

	$categories = get_categories(
		array(
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		)
	);

	if ( empty( $categories ) ) {
		echo '<p class="vyts-no-items">' . esc_html__( 'No categories found. Create categories in Posts → Categories first.', 'v-yoast-topic-silos' ) . '</p>';
		return;
	}
	?>
	<p class="vyts-instructions"><?php esc_html_e( 'Select the categories this page belongs to for topic cluster grouping.', 'v-yoast-topic-silos' ); ?></p>
	<ul class="vyts-category-list">
		<?php foreach ( $categories as $category ) : ?>
			<li>
				<label>
					<input type="checkbox"
					       name="vyts_page_category_ids[]"
					       value="<?php echo esc_attr( $category->term_id ); ?>"
					       <?php checked( in_array( (int) $category->term_id, $saved_ids, true ) ); ?>>
					<?php echo esc_html( $category->name ); ?>
				</label>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php
}

/**
 * Saves the Page Category metabox data on page save.
 *
 * @param int $post_id Post ID.
 */
function vyts_save_page_category( $post_id ) {
	// Bail on autosave and revisions.
	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}

	// Verify nonce.
	if (
		! isset( $_POST['vyts_page_category_nonce'] ) ||
		! wp_verify_nonce( sanitize_key( $_POST['vyts_page_category_nonce'] ), 'vyts_save_page_category' )
	) {
		return;
	}

	// Check user capability.
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	// Sanitize and save.
	if ( isset( $_POST['vyts_page_category_ids'] ) && is_array( $_POST['vyts_page_category_ids'] ) ) {
		$category_ids = array_filter(
			array_map( 'absint', wp_unslash( $_POST['vyts_page_category_ids'] ) ),
			function ( $id ) {
				return $id > 0;
			}
		);

		// Store each category ID as a separate meta row so meta_query IN comparisons work.
		delete_post_meta( $post_id, '_vyts_page_category_ids' );
		foreach ( $category_ids as $cat_id ) {
			add_post_meta( $post_id, '_vyts_page_category_ids', $cat_id );
		}
	} else {
		// No checkboxes submitted — clear the meta.
		delete_post_meta( $post_id, '_vyts_page_category_ids' );
	}
}

/**
 * Renders the Topic Cluster metabox content.
 *
 * Displays two sections:
 *   - Related Links: posts and pages that share a category with the
 *     current post (i.e. within the same topic cluster / silo).
 *   - Other Links: all other published posts and pages that are not in the
 *     current category-based silo, useful for cross-silo internal linking.
 *
 * Each link copies the permalink to the clipboard instead of navigating away.
 *
 * For pages, the category affiliation is taken from the custom
 * _vyts_page_category_ids meta field (set via the Page Category metabox).
 * For posts, the standard WordPress category taxonomy is used.
 *
 * @param WP_Post $post Current post object.
 */
function vyts_render_silo_metabox( $post ) {
	// Silo grouping is category-only; tags play no part.
	$category_ids = array();

	if ( 'page' === $post->post_type ) {
		// Pages don't have built-in category support; use the custom meta field.
		// Values are stored as individual meta rows, so pass false to get all rows.
		$saved = get_post_meta( $post->ID, '_vyts_page_category_ids', false );
		if ( is_array( $saved ) ) {
			$category_ids = array_filter( array_map( 'intval', $saved ), function ( $id ) { return $id > 0; } );
		}
	} else {
		// Posts: use the standard WordPress category taxonomy.
		$categories = get_the_category( $post->ID );
		foreach ( $categories as $cat ) {
			$category_ids[] = (int) $cat->term_id;
		}
	}

	// --- Build related-content queries (same category) ---
	// Posts are in the WP category taxonomy; pages are not, so they are queried
	// separately via the _vyts_page_category_ids meta field.
	$related_post_ids = array();
	$related_page_ids = array();

	if ( ! empty( $category_ids ) ) {
		// Sub-query A: regular posts matched by category taxonomy.
		$related_post_ids = get_posts( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			array(
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'posts_per_page'      => 50,
				'post__not_in'        => array( $post->ID ),
				'ignore_sticky_posts' => true,
				'orderby'             => 'title',
				'order'               => 'ASC',
				'fields'              => 'ids',
				'tax_query'           => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy'         => 'category',
						'field'            => 'term_id',
						'terms'            => $category_ids,
						'operator'         => 'IN',
						'include_children' => false,
					),
				),
			)
		);
	}

	// Sub-query B: pages matched by _vyts_page_category_ids meta.
	// Pages are not part of the WP category taxonomy, so tax_query cannot find
	// them; instead compare against the custom meta rows stored per category ID.
	if ( ! empty( $category_ids ) ) {
		$related_page_ids = get_posts( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			array(
				'post_type'           => 'page',
				'post_status'         => 'publish',
				'posts_per_page'      => 50,
				'post__not_in'        => array( $post->ID ),
				'ignore_sticky_posts' => true,
				'orderby'             => 'title',
				'order'               => 'ASC',
				'fields'              => 'ids',
				'meta_query'          => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_vyts_page_category_ids',
						'value'   => $category_ids,
						'compare' => 'IN',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

	}

	$all_related_ids = array_unique( array_merge( $related_post_ids, $related_page_ids ) );

	// --- Build unrelated query (all published posts/pages outside this silo) ---
	$excluded_ids = array_merge( array( $post->ID ), $all_related_ids );

	$other_query = new WP_Query(
		array(
			'post_type'              => array( 'post', 'page' ),
			'post_status'            => 'publish',
			'posts_per_page'         => 50,
			'post__not_in'           => $excluded_ids,
			'ignore_sticky_posts'    => true,
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	$other_ids = array();
	if ( $other_query->have_posts() ) {
		while ( $other_query->have_posts() ) {
			$other_query->the_post();
			$other_ids[] = get_the_ID();
		}
	}
	wp_reset_postdata();

	$link_map = vyts_extract_internal_post_links( $post->post_content );

	$related_posts_linking_to_current = vyts_count_posts_linking_to_target( $related_post_ids, $post->ID );
	$related_pages_linking_to_current = vyts_count_posts_linking_to_target( $related_page_ids, $post->ID );
	$other_items_linking_to_current   = vyts_count_posts_linking_to_target( $other_ids, $post->ID );

	$outbound_related_posts = vyts_count_outbound_links_to_ids( $link_map, $related_post_ids );
	$outbound_related_pages = vyts_count_outbound_links_to_ids( $link_map, $related_page_ids );
	$outbound_other_items   = vyts_count_outbound_links_to_ids( $link_map, $other_ids );

	$remaining_related_posts = array_values(
		array_filter(
			$related_post_ids,
			function ( $id ) use ( $link_map ) {
				return empty( $link_map[ $id ] );
			}
		)
	);
	$remaining_related_pages = array_values(
		array_filter(
			$related_page_ids,
			function ( $id ) use ( $link_map ) {
				return empty( $link_map[ $id ] );
			}
		)
	);

	$other_links_to_current = vyts_get_ids_linking_to_target( $other_ids, $post->ID );
	$other_links_from_page  = array_values(
		array_filter(
			array_keys( $link_map ),
			function ( $id ) use ( $other_ids ) {
				return in_array( (int) $id, $other_ids, true );
			}
		)
	);
	?>
	<p class="vyts-instructions"><?php esc_html_e( 'Click a link to copy its URL to your clipboard.', 'v-yoast-topic-silos' ); ?></p>

	<p class="vyts-section-heading"><?php esc_html_e( 'Post Summary:', 'v-yoast-topic-silos' ); ?></p>
	<p><?php echo esc_html( count( $related_post_ids ) . ' related posts' ); ?></p>
	<p><?php echo esc_html( $related_posts_linking_to_current . ' posts link to this page' ); ?></p>
	<p><?php echo esc_html( $outbound_related_posts . ' outbound links to posts from this page' ); ?></p>

	<p class="vyts-section-heading"><?php esc_html_e( 'Remaining Post Links:', 'v-yoast-topic-silos' ); ?></p>
	<?php vyts_render_copy_link_list( $remaining_related_posts ); ?>

	<p class="vyts-section-heading"><?php esc_html_e( 'Page Summary:', 'v-yoast-topic-silos' ); ?></p>
	<p><?php echo esc_html( count( $related_page_ids ) . ' related pages' ); ?></p>
	<p><?php echo esc_html( $related_pages_linking_to_current . ' pages link to this page' ); ?></p>
	<p><?php echo esc_html( $outbound_related_pages . ' outbound links to pages from this page' ); ?></p>

	<p class="vyts-section-heading"><?php esc_html_e( 'Remaining Page Links:', 'v-yoast-topic-silos' ); ?></p>
	<?php vyts_render_copy_link_list( $remaining_related_pages ); ?>

	<p class="vyts-section-heading"><?php esc_html_e( 'Unrelated Summary:', 'v-yoast-topic-silos' ); ?></p>
	<p><?php echo esc_html( $other_items_linking_to_current . ' posts link to this posts/pages' ); ?></p>
	<p><?php echo esc_html( $outbound_other_items . ' outbound links to posts/pages from this page/Posts' ); ?></p>

	<p class="vyts-section-heading"><?php esc_html_e( 'Other Links To This post/page:', 'v-yoast-topic-silos' ); ?></p>
	<?php vyts_render_copy_link_list( $other_links_to_current ); ?>

	<p class="vyts-section-heading"><?php esc_html_e( 'Other Links From This Post/Page:', 'v-yoast-topic-silos' ); ?></p>
	<?php vyts_render_copy_link_list( $other_links_from_page ); ?>

	<span class="vyts-copied-notice" style="display:none;" aria-live="polite">
		<?php esc_html_e( 'Copied!', 'v-yoast-topic-silos' ); ?>
	</span>
	<?php
	wp_reset_postdata();
}

/**
 * Extracts internal links from content and maps target post IDs to occurrence counts.
 *
 * @param string $content Raw post content.
 * @return array<int,int> Array in the form [ post_id => count ].
 */
function vyts_extract_internal_post_links( $content ) {
	$map = array();
	if ( empty( $content ) ) {
		return $map;
	}

	if ( preg_match_all( '/<a\b[^>]*href=(["\'])(.*?)\1/i', $content, $matches ) ) {
		foreach ( $matches[2] as $href ) {
			$target_id = url_to_postid( $href );
			if ( $target_id > 0 ) {
				if ( ! isset( $map[ $target_id ] ) ) {
					$map[ $target_id ] = 0;
				}
				++$map[ $target_id ];
			}
		}
	}

	return $map;
}

/**
 * Counts how many posts in a list link to a target post ID.
 *
 * @param int[] $source_ids Source post IDs.
 * @param int   $target_id  Target post ID.
 * @return int
 */
function vyts_count_posts_linking_to_target( $source_ids, $target_id ) {
	return count( vyts_get_ids_linking_to_target( $source_ids, $target_id ) );
}

/**
 * Gets source post IDs that link to a target post ID.
 *
 * @param int[] $source_ids Source post IDs.
 * @param int   $target_id  Target post ID.
 * @return int[]
 */
function vyts_get_ids_linking_to_target( $source_ids, $target_id ) {
	$linking_ids = array();
	foreach ( $source_ids as $source_id ) {
		$content = (string) get_post_field( 'post_content', $source_id );
		$links   = vyts_extract_internal_post_links( $content );
		if ( ! empty( $links[ $target_id ] ) ) {
			$linking_ids[] = (int) $source_id;
		}
	}
	return $linking_ids;
}

/**
 * Counts outbound links from a link map into a specific set of post IDs.
 *
 * @param array<int,int> $link_map   Source link map [post_id => count].
 * @param int[]          $target_ids Target post IDs.
 * @return int
 */
function vyts_count_outbound_links_to_ids( $link_map, $target_ids ) {
	$count = 0;
	foreach ( $target_ids as $target_id ) {
		if ( isset( $link_map[ $target_id ] ) ) {
			$count += (int) $link_map[ $target_id ];
		}
	}
	return $count;
}

/**
 * Renders a copy-link list from post IDs.
 *
 * @param int[] $post_ids Post IDs to render.
 */
function vyts_render_copy_link_list( $post_ids ) {
	if ( empty( $post_ids ) ) {
		echo '<p class="vyts-no-items">' . esc_html__( 'None.', 'v-yoast-topic-silos' ) . '</p>';
		return;
	}
	?>
	<ul class="vyts-silo-list">
		<?php foreach ( $post_ids as $listed_id ) : ?>
			<li>
				<button type="button"
					class="vyts-copy-link"
					data-copy-url="<?php echo esc_url( get_permalink( $listed_id ) ); ?>"
					title="<?php echo esc_attr( get_the_title( $listed_id ) ); ?>">
					<?php echo esc_html( get_the_title( $listed_id ) ); ?>
				</button>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php
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

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! isset( $screen->post_type ) || ! in_array( $screen->post_type, array( 'post', 'page' ), true ) ) {
		return;
	}
	$css = '
		.vyts-silo-list { margin: 0; padding: 0; list-style: none; }
		.vyts-silo-list li { margin: 4px 0; }
		.vyts-copy-link { display: block; width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #2271b1; background: none; border: none; padding: 0; margin: 0; font: inherit; cursor: pointer; text-align: left; text-decoration: none; }
		.vyts-copy-link:hover { text-decoration: underline; }
		.vyts-copied-notice { display: inline-block; margin-top: 6px; padding: 2px 8px; background: #00a32a; color: #fff; border-radius: 3px; font-size: 12px; }
		.vyts-instructions { color: #646970; font-style: italic; margin-bottom: 6px; }
		.vyts-section-heading { font-weight: 600; margin: 10px 0 4px; border-bottom: 1px solid #dcdcde; padding-bottom: 4px; }
		.vyts-category-list { margin: 0; padding: 0; list-style: none; }
		.vyts-category-list li { margin: 4px 0; }
		.vyts-category-list label { display: flex; align-items: center; gap: 6px; cursor: pointer; }
	';

	// Register a plugin-specific handle so wp_add_inline_style is guaranteed to print.
	wp_register_style( 'vyts-metabox', false, array(), VYTS_VERSION );
	wp_enqueue_style( 'vyts-metabox' );
	wp_add_inline_style( 'vyts-metabox', $css );

	$js = '
		( function () {
			function init() {
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
			}

			if ( document.readyState === "loading" ) {
				document.addEventListener( "DOMContentLoaded", init );
			} else {
				init();
			}
		}() );
	';

	// Register a plugin-specific handle in the footer so the inline script is always printed.
	wp_register_script( 'vyts-metabox', false, array(), VYTS_VERSION, true );
	wp_enqueue_script( 'vyts-metabox' );
	wp_add_inline_script( 'vyts-metabox', $js );
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
// Add anchor IDs to headers in post content on save.
// -----------------------------
add_action( 'save_post', 'vyts_add_anchor_ids_to_headers', 10, 3 );

/**
 * Adds id attributes to h1–h6 tags in post/page content on save.
 *
 * Each heading that does not already carry an id attribute receives one
 * generated from its inner text via sanitize_title(). The post content is
 * updated only when at least one heading was changed.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 * @param bool    $update  Whether this is an existing post being updated.
 */
function vyts_add_anchor_ids_to_headers( $post_id, $post, $update ) {
	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}

	if ( 'post' !== $post->post_type && 'page' !== $post->post_type ) {
		return;
	}

	// Unhook to prevent recursion when wp_update_post fires save_post again.
	remove_action( 'save_post', 'vyts_add_anchor_ids_to_headers', 10 );

	$content = $post->post_content;

	$used_ids    = array();
	$new_content = preg_replace_callback(
		'/<(h[1-6])([^>]*)>(.*?)<\/\1>/is',
		function ( $matches ) use ( &$used_ids ) {
			$tag   = $matches[1];
			$attrs = $matches[2];
			$inner = $matches[3];

			// Leave headings that already have an id attribute untouched.
			if ( preg_match( '/\bid\s*=/i', $attrs ) ) {
				return $matches[0];
			}

			$base_id = 'h-' . sanitize_title( wp_strip_all_tags( $inner ) );

			if ( '' === $base_id ) {
				return $matches[0];
			}

			// Ensure uniqueness within the document by appending a counter.
			$id      = $base_id;
			$counter = 1;
			while ( in_array( $id, $used_ids, true ) ) {
				$id = $base_id . '-' . $counter;
				++$counter;
			}
			$used_ids[] = $id;

			$trimmed_attrs = trim( $attrs );
			$attr_sep      = '' !== $trimmed_attrs ? ' ' . $trimmed_attrs : '';

			return '<' . $tag . $attr_sep . ' id="' . esc_attr( $id ) . '">' . $inner . '</' . $tag . '>';
		},
		$content
	);

	if ( $new_content !== $content ) {
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $new_content,
			)
		);
	}

	// Restore the hook after the update so subsequent saves are also processed.
	add_action( 'save_post', 'vyts_add_anchor_ids_to_headers', 10, 3 );
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
