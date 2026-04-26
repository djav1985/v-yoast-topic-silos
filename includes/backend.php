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
 * Normalises a URL for string comparison by stripping the scheme and trailing slash.
 *
 * Used when matching hrefs found in post content against known permalinks so
 * that http:// and https:// variants, and the presence or absence of a trailing
 * slash, do not cause false negatives.
 *
 * @param string $url Raw URL string (may include query string and fragment).
 * @return string Normalised URL, or empty string when the input is blank.
 */
function vyts_normalize_url_for_comparison( $url ) {
	// Strip query string and fragment before any further processing.
	$url = strtok( trim( $url ), '?#' );
	if ( ! $url ) {
		return '';
	}
	// Remove http:// or https://.
	$url = preg_replace( '#^https?://#i', '', $url );
	// Drop the trailing slash so both forms compare equal.
	return rtrim( $url, '/' );
}

/**
 * Renders the Topic Cluster metabox content.
 *
 * Displays three summary sections:
 *
 *   Post Summary
 *     - Total related posts (same silo).
 *     - How many of those posts contain an inbound link to the current post.
 *     - How many of those posts the current post links out to.
 *     - "Remaining Post Links" – related posts not yet linked from this post.
 *
 *   Page Summary
 *     - Same metrics for related pages.
 *
 *   Unrelated Summary
 *     - Unrelated posts/pages that link inbound to the current post.
 *     - Unrelated posts/pages the current post links out to.
 *     - "Other Links To This Post/Page" list (inbound from unrelated).
 *     - "Other Links From This Post/Page" list (outbound to unrelated).
 *
 * A ⚠️ warning is appended to any zero count to flag SEO gaps.
 * Clicking a link copies its permalink to the clipboard.
 *
 * For pages, silo membership comes from the _vyts_page_category_ids meta field.
 * For posts, the standard WordPress category taxonomy is used.
 *
 * @param WP_Post $post Current post object.
 */
function vyts_render_silo_metabox( $post ) {
	// -----------------------------------------------------------------------
	// Step 1 – Determine the current post's silo category IDs.
	// -----------------------------------------------------------------------
	$category_ids = array();

	if ( 'page' === $post->post_type ) {
		// Pages don't have built-in category support; use the custom meta field.
		$saved = get_post_meta( $post->ID, '_vyts_page_category_ids', false );
		if ( is_array( $saved ) ) {
			$category_ids = array_values(
				array_filter( array_map( 'intval', $saved ), function ( $id ) { return $id > 0; } )
			);
		}
	} else {
		// Posts: use the standard WordPress category taxonomy.
		foreach ( get_the_category( $post->ID ) as $cat ) {
			$category_ids[] = (int) $cat->term_id;
		}
	}

	// -----------------------------------------------------------------------
	// Step 2 – Build silo IDs split by post type.
	// -----------------------------------------------------------------------
	$silo_post_ids = array();
	$silo_page_ids = array();

	if ( ! empty( $category_ids ) ) {
		// Sub-query A: regular posts matched by category taxonomy.
		$silo_post_ids = get_posts( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
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

		// Sub-query B: pages matched by _vyts_page_category_ids meta.
		$silo_page_ids = get_posts( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
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

	// -----------------------------------------------------------------------
	// Step 3 – Build unrelated IDs (outside the silo) split by post type.
	// -----------------------------------------------------------------------
	$silo_ids = array_merge( $silo_post_ids, $silo_page_ids );
	$excluded = array_merge( array( $post->ID ), $silo_ids );

	$other_post_ids = get_posts(
		array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => 50,
			'post__not_in'        => $excluded,
			'ignore_sticky_posts' => true,
			'orderby'             => 'title',
			'order'               => 'ASC',
			'fields'              => 'ids',
		)
	);

	$other_page_ids = get_posts(
		array(
			'post_type'           => 'page',
			'post_status'         => 'publish',
			'posts_per_page'      => 50,
			'post__not_in'        => $excluded,
			'ignore_sticky_posts' => true,
			'orderby'             => 'title',
			'order'               => 'ASC',
			'fields'              => 'ids',
		)
	);

	$all_other_ids     = array_merge( $other_post_ids, $other_page_ids );
	$all_candidate_ids = array_merge( $silo_ids, $all_other_ids );

	// -----------------------------------------------------------------------
	// Step 4 – Build a normalised-permalink → ID map for all candidates.
	//          This avoids calling url_to_postid() for every href found.
	// -----------------------------------------------------------------------
	$permalink_map = array();
	foreach ( $all_candidate_ids as $cid ) {
		$norm = vyts_normalize_url_for_comparison( get_permalink( (int) $cid ) );
		if ( $norm ) {
			$permalink_map[ $norm ] = (int) $cid;
		}
	}

	// -----------------------------------------------------------------------
	// Step 5 – Detect outbound internal links FROM the current post content.
	// -----------------------------------------------------------------------
	$outbound_ids = array();

	if ( ! empty( $permalink_map ) ) {
		preg_match_all( '/\bhref=(?:"([^"]*)"|\'([^\']*)\'|([^"\'>\s]+))/i', $post->post_content, $href_matches );
		$all_hrefs = array_values( array_filter( array_merge( $href_matches[1], $href_matches[2], $href_matches[3] ) ) );
		foreach ( $all_hrefs as $href ) {
			$href = trim( $href );
			// Resolve root-relative paths to absolute URLs so normalisation works.
			if ( '/' === substr( $href, 0, 1 ) && '/' !== substr( $href, 1, 1 ) ) {
				$href = home_url( $href );
			}
			$norm = vyts_normalize_url_for_comparison( $href );
			if ( $norm && isset( $permalink_map[ $norm ] ) ) {
				$outbound_ids[] = $permalink_map[ $norm ];
			}
		}
		$outbound_ids = array_values( array_unique( $outbound_ids ) );
	}

	// -----------------------------------------------------------------------
	// Step 6 – Detect inbound links: which candidates link TO the current post.
	//          A single DB LIKE query across the candidate set is used instead
	//          of fetching every post's content individually.
	// -----------------------------------------------------------------------
	$inbound_ids = array();

	if ( ! empty( $all_candidate_ids ) ) {
		global $wpdb;
		$current_url   = get_permalink( $post->ID );
		$url_no_scheme = preg_replace( '#^https?://#', '', rtrim( $current_url, '/' ) );
		$like          = '%' . $wpdb->esc_like( $url_no_scheme ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$placeholders = implode( ',', array_fill( 0, count( $all_candidate_ids ), '%d' ) );
		$query_args   = array_merge( array( $like ), array_map( 'intval', $all_candidate_ids ) );
		$inbound_ids  = array_map( 'intval', (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s AND ID IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$query_args
			)
		) );
	}

	// -----------------------------------------------------------------------
	// Step 7 – Derive per-section metrics.
	// -----------------------------------------------------------------------
	$outbound_silo_post_ids = array_values( array_intersect( $outbound_ids, $silo_post_ids ) );
	$outbound_silo_page_ids = array_values( array_intersect( $outbound_ids, $silo_page_ids ) );
	$outbound_other_ids     = array_values( array_intersect( $outbound_ids, $all_other_ids ) );

	$inbound_silo_post_ids = array_values( array_intersect( $inbound_ids, $silo_post_ids ) );
	$inbound_silo_page_ids = array_values( array_intersect( $inbound_ids, $silo_page_ids ) );
	$inbound_other_ids     = array_values( array_intersect( $inbound_ids, $all_other_ids ) );

	// Remaining = silo items the current post does NOT yet link out to.
	$remaining_post_ids = array_values( array_diff( $silo_post_ids, $outbound_silo_post_ids ) );
	$remaining_page_ids = array_values( array_diff( $silo_page_ids, $outbound_silo_page_ids ) );

	// Sort other inbound/outbound lists alphabetically by title for display.
	$outbound_other_display = $outbound_other_ids;
	$inbound_other_display  = $inbound_other_ids;
	usort(
		$outbound_other_display,
		function ( $a, $b ) {
			return strcmp( get_the_title( $a ), get_the_title( $b ) );
		}
	);
	usort(
		$inbound_other_display,
		function ( $a, $b ) {
			return strcmp( get_the_title( $a ), get_the_title( $b ) );
		}
	);

	$type_label = 'page' === $post->post_type
		? __( 'page', 'v-yoast-topic-silos' )
		: __( 'post', 'v-yoast-topic-silos' );

	?>
	<p class="vyts-instructions"><?php esc_html_e( 'Click any link to copy its URL to your clipboard.', 'v-yoast-topic-silos' ); ?></p>

	<?php // ------------------------------------------------------------------ ?>
	<?php // Post Summary                                                        ?>
	<?php // ------------------------------------------------------------------ ?>
	<p class="vyts-section-heading"><?php esc_html_e( 'Post Summary:', 'v-yoast-topic-silos' ); ?></p>
	<ul class="vyts-summary-stats">
		<li>
			<?php
			printf(
				/* translators: %d: number of related posts */
				esc_html( _n( '%d related post', '%d related posts', count( $silo_post_ids ), 'v-yoast-topic-silos' ) ),
				count( $silo_post_ids )
			);
			?>
		</li>
		<li<?php echo empty( $inbound_silo_post_ids ) ? ' class="vyts-stat-warn"' : ''; ?>>
			<?php
			printf(
				/* translators: 1: count, 2: post type label (post or page) */
				esc_html( _n( '%1$d post links to this %2$s', '%1$d posts link to this %2$s', count( $inbound_silo_post_ids ), 'v-yoast-topic-silos' ) ),
				count( $inbound_silo_post_ids ),
				esc_html( $type_label )
			);
			if ( empty( $inbound_silo_post_ids ) ) {
				echo ' ⚠️'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</li>
		<li<?php echo empty( $outbound_silo_post_ids ) ? ' class="vyts-stat-warn"' : ''; ?>>
			<?php
			printf(
				/* translators: 1: count, 2: post type label */
				esc_html( _n( '%1$d outbound link to posts from this %2$s', '%1$d outbound links to posts from this %2$s', count( $outbound_silo_post_ids ), 'v-yoast-topic-silos' ) ),
				count( $outbound_silo_post_ids ),
				esc_html( $type_label )
			);
			if ( empty( $outbound_silo_post_ids ) ) {
				echo ' ⚠️'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</li>
	</ul>

	<?php if ( ! empty( $remaining_post_ids ) ) : ?>
		<p class="vyts-sub-heading"><?php esc_html_e( 'Remaining Post Links:', 'v-yoast-topic-silos' ); ?></p>
		<ul class="vyts-silo-list">
			<?php foreach ( $remaining_post_ids as $rid ) : ?>
				<li>
					<button type="button"
					        class="vyts-copy-link"
					        data-copy-url="<?php echo esc_url( get_permalink( $rid ) ); ?>"
					        title="<?php echo esc_attr( get_the_title( $rid ) ); ?>">
						<?php echo esc_html( get_the_title( $rid ) ); ?>
					</button>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php // ------------------------------------------------------------------ ?>
	<?php // Page Summary                                                        ?>
	<?php // ------------------------------------------------------------------ ?>
	<p class="vyts-section-heading"><?php esc_html_e( 'Page Summary:', 'v-yoast-topic-silos' ); ?></p>
	<ul class="vyts-summary-stats">
		<li>
			<?php
			printf(
				/* translators: %d: number of related pages */
				esc_html( _n( '%d related page', '%d related pages', count( $silo_page_ids ), 'v-yoast-topic-silos' ) ),
				count( $silo_page_ids )
			);
			?>
		</li>
		<li<?php echo empty( $inbound_silo_page_ids ) ? ' class="vyts-stat-warn"' : ''; ?>>
			<?php
			printf(
				/* translators: 1: count, 2: post type label */
				esc_html( _n( '%1$d page links to this %2$s', '%1$d pages link to this %2$s', count( $inbound_silo_page_ids ), 'v-yoast-topic-silos' ) ),
				count( $inbound_silo_page_ids ),
				esc_html( $type_label )
			);
			if ( empty( $inbound_silo_page_ids ) ) {
				echo ' ⚠️'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</li>
		<li<?php echo empty( $outbound_silo_page_ids ) ? ' class="vyts-stat-warn"' : ''; ?>>
			<?php
			printf(
				/* translators: 1: count, 2: post type label */
				esc_html( _n( '%1$d outbound link to pages from this %2$s', '%1$d outbound links to pages from this %2$s', count( $outbound_silo_page_ids ), 'v-yoast-topic-silos' ) ),
				count( $outbound_silo_page_ids ),
				esc_html( $type_label )
			);
			if ( empty( $outbound_silo_page_ids ) ) {
				echo ' ⚠️'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</li>
	</ul>

	<?php if ( ! empty( $remaining_page_ids ) ) : ?>
		<p class="vyts-sub-heading"><?php esc_html_e( 'Remaining Page Links:', 'v-yoast-topic-silos' ); ?></p>
		<ul class="vyts-silo-list">
			<?php foreach ( $remaining_page_ids as $rid ) : ?>
				<li>
					<button type="button"
					        class="vyts-copy-link"
					        data-copy-url="<?php echo esc_url( get_permalink( $rid ) ); ?>"
					        title="<?php echo esc_attr( get_the_title( $rid ) ); ?>">
						<?php echo esc_html( get_the_title( $rid ) ); ?>
					</button>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php // ------------------------------------------------------------------ ?>
	<?php // Unrelated Summary                                                   ?>
	<?php // ------------------------------------------------------------------ ?>
	<p class="vyts-section-heading"><?php esc_html_e( 'Unrelated Summary:', 'v-yoast-topic-silos' ); ?></p>
	<ul class="vyts-summary-stats">
		<li<?php echo empty( $inbound_other_ids ) ? ' class="vyts-stat-warn"' : ''; ?>>
			<?php
			printf(
				/* translators: 1: count, 2: post type label */
				esc_html( _n( '%1$d post/page links to this %2$s', '%1$d posts/pages link to this %2$s', count( $inbound_other_ids ), 'v-yoast-topic-silos' ) ),
				count( $inbound_other_ids ),
				esc_html( $type_label )
			);
			if ( empty( $inbound_other_ids ) ) {
				echo ' ⚠️'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</li>
		<li<?php echo empty( $outbound_other_ids ) ? ' class="vyts-stat-warn"' : ''; ?>>
			<?php
			printf(
				/* translators: 1: count, 2: post type label */
				esc_html( _n( '%1$d outbound link to posts/pages from this %2$s', '%1$d outbound links to posts/pages from this %2$s', count( $outbound_other_ids ), 'v-yoast-topic-silos' ) ),
				count( $outbound_other_ids ),
				esc_html( $type_label )
			);
			if ( empty( $outbound_other_ids ) ) {
				echo ' ⚠️'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</li>
	</ul>

	<p class="vyts-sub-heading">
		<?php
		printf(
			/* translators: %s: capitalised post type label (Post or Page) */
			esc_html__( 'Other Links To This %s:', 'v-yoast-topic-silos' ),
			esc_html( ucfirst( $type_label ) )
		);
		?>
	</p>
	<?php if ( ! empty( $inbound_other_display ) ) : ?>
		<ul class="vyts-silo-list">
			<?php foreach ( $inbound_other_display as $oid ) : ?>
				<li>
					<button type="button"
					        class="vyts-copy-link"
					        data-copy-url="<?php echo esc_url( get_permalink( $oid ) ); ?>"
					        title="<?php echo esc_attr( get_the_title( $oid ) ); ?>">
						<?php echo esc_html( get_the_title( $oid ) ); ?>
					</button>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php else : ?>
		<p class="vyts-no-items"><?php esc_html_e( 'None.', 'v-yoast-topic-silos' ); ?></p>
	<?php endif; ?>

	<p class="vyts-sub-heading">
		<?php
		printf(
			/* translators: %s: capitalised post type label (Post or Page) */
			esc_html__( 'Other Links From This %s:', 'v-yoast-topic-silos' ),
			esc_html( ucfirst( $type_label ) )
		);
		?>
	</p>
	<?php if ( ! empty( $outbound_other_display ) ) : ?>
		<ul class="vyts-silo-list">
			<?php foreach ( $outbound_other_display as $oid ) : ?>
				<li>
					<button type="button"
					        class="vyts-copy-link"
					        data-copy-url="<?php echo esc_url( get_permalink( $oid ) ); ?>"
					        title="<?php echo esc_attr( get_the_title( $oid ) ); ?>">
						<?php echo esc_html( get_the_title( $oid ) ); ?>
					</button>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php else : ?>
		<p class="vyts-no-items"><?php esc_html_e( 'None.', 'v-yoast-topic-silos' ); ?></p>
	<?php endif; ?>

	<span class="vyts-copied-notice" style="display:none;" aria-live="polite">
		<?php esc_html_e( 'Copied!', 'v-yoast-topic-silos' ); ?>
	</span>
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
		.vyts-sub-heading { font-weight: 600; margin: 8px 0 2px; font-size: 12px; color: #50575e; }
		.vyts-summary-stats { margin: 0 0 6px; padding: 0; list-style: none; }
		.vyts-summary-stats li { margin: 2px 0; font-size: 12px; }
		.vyts-stat-warn { color: #b32d2e; }
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
