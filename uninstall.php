<?php
/**
 * Uninstall handler: remove the only state the auditor ever writes.
 *
 * @package ASA
 */

// Exit if uninstall not called from WordPress.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_transient( 'asa_last_report' );

// Reserved for the opt-in snapshot feature (v1.1); harmless when unset.
delete_option( 'asa_snapshot' );
