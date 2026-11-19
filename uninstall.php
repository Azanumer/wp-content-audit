<?php
/**
 * Uninstall WP Content Audit.
 *
 * Removes the stored audit results.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wca_results' );
