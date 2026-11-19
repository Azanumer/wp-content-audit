<?php
/**
 * Plugin Name:       WP Content Audit
 * Plugin URI:        https://github.com/Azanumer/wp-content-audit
 * Description:       Audits your content for SEO gaps — posts with no featured image, thin content, missing excerpts, no internal links and stale posts — with a batched scan and an actionable report.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Azan Umer
 * Author URI:        https://azanumer.cv
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-content-audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WCA_VERSION', '1.0.0' );
define( 'WCA_OPTION', 'wca_results' );
define( 'WCA_BATCH', 50 );

/**
 * Minimum word count before content is flagged as thin.
 *
 * @param int $words Default 300.
 * @return int
 */
function wca_min_words() {
	return (int) apply_filters( 'wca_min_words', 300 );
}

/**
 * Months since the last modification before a post is flagged as stale.
 *
 * @param int $months Default 12.
 * @return int
 */
function wca_stale_months() {
	return (int) apply_filters( 'wca_stale_months', 12 );
}

/**
 * Post types included in the audit.
 *
 * @return string[]
 */
function wca_post_types() {
	return (array) apply_filters( 'wca_post_types', array( 'post', 'page' ) );
}

/**
 * Run the checks for a single post and return its issue codes.
 *
 * Issue codes: no_featured_image, thin_content, no_excerpt,
 * no_internal_links, stale.
 *
 * @param WP_Post $post Post to check.
 * @return string[]
 */
function wca_check_post( $post ) {
	$issues = array();

	// No featured image (only for types that support thumbnails).
	if ( post_type_supports( $post->post_type, 'thumbnail' ) && ! has_post_thumbnail( $post->ID ) ) {
		$issues[] = 'no_featured_image';
	}

	// Thin content.
	$words = str_word_count( wp_strip_all_tags( $post->post_content ) );
	if ( $words < wca_min_words() ) {
		$issues[] = 'thin_content';
	}

	// Missing excerpt.
	if ( '' === trim( $post->post_excerpt ) ) {
		$issues[] = 'no_excerpt';
	}

	// No internal links: count links to the site's own host.
	$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
	if ( $home_host ) {
		$internal = 0;
		if ( preg_match_all( '/<a\s[^>]*href=(["\'])(.*?)\1/i', $post->post_content, $matches ) ) {
			foreach ( $matches[2] as $href ) {
				$href = trim( $href );
				if ( '' === $href || '#' === $href[0] ) {
					continue;
				}
				// Relative URLs are internal by definition (protocol-relative //host is not).
				if ( 0 === strpos( $href, '/' ) && 0 !== strpos( $href, '//' ) ) {
					$internal++;
					continue;
				}
				$link_host = wp_parse_url( $href, PHP_URL_HOST );
				if ( $link_host && strtolower( $link_host ) === strtolower( $home_host ) ) {
					$internal++;
				}
			}
		}
		if ( 0 === $internal ) {
			$issues[] = 'no_internal_links';
		}
	}

	// Stale: not modified for a long time.
	$modified = strtotime( $post->post_modified_gmt . ' GMT' );
	if ( false !== $modified && $modified < time() - ( wca_stale_months() * 30 * DAY_IN_SECONDS ) ) {
		$issues[] = 'stale';
	}

	return $issues;
}

/**
 * Human labels for issue codes.
 *
 * @param string $code Issue code.
 * @return string
 */
function wca_issue_label( $code ) {
	$labels = array(
		'no_featured_image' => __( 'No featured image', 'wp-content-audit' ),
		'thin_content'      => __( 'Thin content', 'wp-content-audit' ),
		'no_excerpt'        => __( 'Missing excerpt', 'wp-content-audit' ),
		'no_internal_links' => __( 'No internal links', 'wp-content-audit' ),
		'stale'             => __( 'Stale (not updated)', 'wp-content-audit' ),
	);
	return isset( $labels[ $code ] ) ? $labels[ $code ] : $code;
}

/**
 * Register the Tools page.
 */
function wca_register_page() {
	add_management_page(
		__( 'Content Audit', 'wp-content-audit' ),
		__( 'Content Audit', 'wp-content-audit' ),
		'manage_options',
		'wp-content-audit',
		'wca_render_page'
	);
}
add_action( 'admin_menu', 'wca_register_page' );

/**
 * Scan one batch of posts and append their issues to the stored results.
 *
 * @param int $offset Offset into the full post list.
 * @return array{scanned: int, total: int} Progress counters.
 */
function wca_scan_batch( $offset ) {
	$types  = wca_post_types();
	$query  = new WP_Query(
		array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => WCA_BATCH,
			'offset'         => $offset,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => false,
		)
	);
	$total  = (int) $query->found_posts;

	$results = get_option( WCA_OPTION, array() );
	if ( 0 === $offset || ! is_array( $results ) ) {
		$results = array(
			'scanned_at' => time(),
			'total'      => $total,
			'issues'     => array(),
		);
	}

	foreach ( $query->posts as $post_id ) {
		$post   = get_post( $post_id );
		$issues = wca_check_post( $post );
		if ( ! empty( $issues ) ) {
			$results['issues'][ $post_id ] = array(
				'title'  => get_the_title( $post ),
				'type'   => $post->post_type,
				'issues' => $issues,
			);
		}
	}

	$results['total'] = $total;
	update_option( WCA_OPTION, $results );

	return array(
		'scanned' => min( $offset + WCA_BATCH, $total ),
		'total'   => $total,
	);
}

/**
 * Handle the "Run audit" request: scan batches with meta-refresh auto-continue.
 */
function wca_handle_run() {
	if ( ! isset( $_GET['wca_run'] ) || '1' !== $_GET['wca_run'] ) {
		return;
	}
	check_admin_referer( 'wca_run' );

	$offset   = isset( $_GET['wca_offset'] ) ? max( 0, (int) $_GET['wca_offset'] ) : 0;
	$progress = wca_scan_batch( $offset );

	if ( $progress['scanned'] < $progress['total'] ) {
		$next = wp_nonce_url(
			admin_url( 'tools.php?page=wp-content-audit&wca_run=1&wca_offset=' . $progress['scanned'] ),
			'wca_run'
		);
		wp_safe_redirect( $next );
		exit;
	}

	wp_safe_redirect( admin_url( 'tools.php?page=wp-content-audit&wca_done=1' ) );
	exit;
}
add_action( 'admin_init', 'wca_handle_run' );

/**
 * Render the Tools -> Content Audit page.
 */
function wca_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'wp-content-audit' ) );
	}

	// Clear stored results.
	if ( isset( $_POST['wca_clear'] ) ) {
		check_admin_referer( 'wca_clear' );
		delete_option( WCA_OPTION );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Audit results cleared.', 'wp-content-audit' ) . '</p></div>';
	}

	$results   = get_option( WCA_OPTION, array() );
	$has_data  = is_array( $results ) && ! empty( $results['issues'] );
	$run_url   = wp_nonce_url( admin_url( 'tools.php?page=wp-content-audit&wca_run=1&wca_offset=0' ), 'wca_run' );
	$counts    = array();
	$issue_tot = 0;
	if ( $has_data ) {
		foreach ( $results['issues'] as $row ) {
			foreach ( $row['issues'] as $code ) {
				$counts[ $code ] = isset( $counts[ $code ] ) ? $counts[ $code ] + 1 : 1;
				$issue_tot++;
			}
		}
	}

	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Content Audit', 'wp-content-audit' ); ?></h1>
		<p><?php esc_html_e( 'Finds published posts and pages with SEO gaps: no featured image, thin content, missing excerpts, no internal links, or content not updated in over a year.', 'wp-content-audit' ); ?></p>
		<p>
			<a href="<?php echo esc_url( $run_url ); ?>" class="button button-primary"><?php esc_html_e( 'Run audit', 'wp-content-audit' ); ?></a>
			<?php if ( $has_data ) : ?>
			<form method="post" action="" style="display:inline;">
				<?php wp_nonce_field( 'wca_clear' ); ?>
				<?php submit_button( __( 'Clear results', 'wp-content-audit' ), 'secondary', 'wca_clear', false ); ?>
			</form>
			<?php endif; ?>
		</p>

		<?php if ( isset( $_GET['wca_done'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Audit complete.', 'wp-content-audit' ); ?></p></div>
		<?php endif; ?>

		<?php if ( $has_data ) : ?>
			<p>
				<?php
				printf(
					/* translators: 1: number of posts with issues, 2: total posts scanned, 3: scan date. */
					esc_html__( '%1$d of %2$d published posts have issues (%3$d issues in total). Last scan: %4$s.', 'wp-content-audit' ),
					count( $results['issues'] ),
					(int) $results['total'],
					$issue_tot,
					date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $results['scanned_at'] )
				);
				?>
			</p>
			<p>
				<?php foreach ( $counts as $code => $count ) : ?>
					<strong><?php echo esc_html( wca_issue_label( $code ) ); ?>:</strong> <?php echo (int) $count; ?> &nbsp;
				<?php endforeach; ?>
			</p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Post', 'wp-content-audit' ); ?></th>
						<th><?php esc_html_e( 'Type', 'wp-content-audit' ); ?></th>
						<th><?php esc_html_e( 'Issues', 'wp-content-audit' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $results['issues'] as $post_id => $row ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>"><?php echo esc_html( $row['title'] ); ?></a></td>
						<td><?php echo esc_html( $row['type'] ); ?></td>
						<td>
							<?php
							$labels = array();
							foreach ( $row['issues'] as $code ) {
								$labels[] = wca_issue_label( $code );
							}
							echo esc_html( implode( ', ', $labels ) );
							?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php elseif ( is_array( $results ) && isset( $results['scanned_at'] ) ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'No issues found — every published post passed all checks.', 'wp-content-audit' ); ?></p></div>
		<?php else : ?>
			<p><?php esc_html_e( 'No audit has been run yet. Click "Run audit" to scan your content.', 'wp-content-audit' ); ?></p>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Thresholds', 'wp-content-audit' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: 1: minimum words, 2: stale months. */
				esc_html__( 'Thin content: under %1$d words. Stale: not modified in %2$d months. Change them with the wca_min_words, wca_stale_months and wca_post_types filters.', 'wp-content-audit' ),
				wca_min_words(),
				wca_stale_months()
			);
			?>
		</p>
	</div>
	<?php
}
