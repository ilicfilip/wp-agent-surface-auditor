<?php
/**
 * Catalog of known write-function signatures.
 *
 * @package ASA
 */

namespace ASA\Analysis;

defined( 'ABSPATH' ) || exit;

/**
 * The function and method names whose presence in a callback's source
 * indicates the ability writes to the site.
 *
 * Known false-negative modes: indirect writes through helper functions (only
 * the callback's own source span is read), dynamic call names, and transients
 * (deliberately excluded — caching inside read-only abilities would
 * false-positive constantly).
 */
class Write_Indicators {

	/**
	 * Global functions that persist or destroy site state.
	 *
	 * @var string[]
	 */
	const FUNCTIONS = [
		// Posts and attachments.
		'wp_insert_post',
		'wp_update_post',
		'wp_delete_post',
		'wp_trash_post',
		'wp_untrash_post',
		'wp_publish_post',
		'wp_insert_attachment',
		'wp_delete_attachment',
		'media_handle_upload',
		'media_handle_sideload',
		// Users and roles.
		'wp_insert_user',
		'wp_update_user',
		'wp_create_user',
		'wp_delete_user',
		'add_role',
		'remove_role',
		// Options.
		'add_option',
		'update_option',
		'delete_option',
		'add_site_option',
		'update_site_option',
		'delete_site_option',
		'update_network_option',
		'update_blog_option',
		// Meta.
		'add_post_meta',
		'update_post_meta',
		'delete_post_meta',
		'add_user_meta',
		'update_user_meta',
		'delete_user_meta',
		'add_term_meta',
		'update_term_meta',
		'delete_term_meta',
		'add_metadata',
		'update_metadata',
		'delete_metadata',
		// Terms.
		'wp_insert_term',
		'wp_update_term',
		'wp_delete_term',
		'wp_set_object_terms',
		'wp_remove_object_terms',
		'wp_set_post_terms',
		// Comments.
		'wp_insert_comment',
		'wp_update_comment',
		'wp_delete_comment',
		'wp_new_comment',
		'wp_set_comment_status',
		// Plugins, themes, cron.
		'activate_plugin',
		'deactivate_plugins',
		'delete_plugins',
		'switch_theme',
		'delete_theme',
		'wp_schedule_event',
		'wp_schedule_single_event',
		'wp_unschedule_event',
		'wp_clear_scheduled_hook',
		// Filesystem.
		'file_put_contents',
		'fwrite',
		'unlink',
		'rename',
		'mkdir',
		'rmdir',
		'copy',
		'move_uploaded_file',
		'touch',
		'wp_mkdir_p',
		'wp_delete_file',
		// Database schema.
		'dbDelta',
	];

	/**
	 * Object method names (typically $wpdb) that write to the database.
	 * `query` is included because it accepts arbitrary SQL; the inspector
	 * reports it as an indicator without proving the SQL writes.
	 *
	 * @var string[]
	 */
	const WPDB_METHODS = [
		'insert',
		'update',
		'delete',
		'replace',
		'query',
	];

	/**
	 * Whether a global function name is a write indicator.
	 *
	 * @param string $name Function name (case-insensitive).
	 * @return bool True when the function writes.
	 */
	public static function is_write_function( $name ) {
		static $lookup = null;

		if ( $lookup === null ) {
			$lookup = array_fill_keys( array_map( 'strtolower', self::FUNCTIONS ), true );
		}

		return isset( $lookup[ strtolower( $name ) ] );
	}

	/**
	 * Whether an object method name is a write indicator.
	 *
	 * @param string $name Method name (case-insensitive).
	 * @return bool True when the method writes.
	 */
	public static function is_write_method( $name ) {
		return in_array( strtolower( $name ), self::WPDB_METHODS, true );
	}
}
