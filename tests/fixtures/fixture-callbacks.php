<?php
/**
 * Fixture callables for Callback_Inspector and rule tests.
 *
 * These functions are REFLECTED, never invoked — the WP functions they call
 * do not exist in the unit-test environment, and that is fine.
 *
 * @package ASA
 */

/**
 * Unconditional allow: every path returns literal true.
 *
 * @return bool Always true.
 */
function asa_fixture_return_true() {
	return true;
}

/**
 * A real capability gate.
 *
 * @return bool Whether the current user may proceed.
 */
function asa_fixture_capability_gate() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return false;
	}
	return true;
}

/**
 * Authentication-only gate.
 *
 * @return bool Whether any user is logged in.
 */
function asa_fixture_auth_only() {
	return is_user_logged_in();
}

/**
 * Broad capability gate: subscribers pass.
 *
 * @return bool Whether the user can read.
 */
function asa_fixture_broad_cap() {
	return current_user_can( 'read' );
}

/**
 * A writer: inserts a post and updates an option.
 *
 * @return int The post ID.
 */
function asa_fixture_writer() {
	$post_id = wp_insert_post( [ 'post_title' => 'fixture' ] );
	update_option( 'asa_fixture_last_post', $post_id );
	return $post_id;
}

/**
 * A $wpdb writer.
 *
 * @return mixed Query result.
 */
function asa_fixture_wpdb_writer() {
	global $wpdb;
	return $wpdb->query( 'DELETE FROM wp_posts WHERE 1=0' );
}

/**
 * A pure reader. Mentions wp_insert_post only inside a comment and a string,
 * which the token-based scan must NOT match.
 *
 * @return mixed Option value.
 */
function asa_fixture_reader() {
	// This comment mentions wp_insert_post( ) and must not count.
	$label = 'docs say wp_insert_post() writes';
	return get_option( 'blogname', $label );
}
