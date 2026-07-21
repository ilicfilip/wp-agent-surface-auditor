<?php
/**
 * Fixture abilities for the WP integration suite.
 *
 * Mirrors the deliberately-unsafe shapes: a safe reader, an annotation liar,
 * an unconditional-allow destructive ability, and an auth-only un-annotated
 * writer. Called from the bootstrap on the real wp_abilities_api_init hook.
 *
 * @package ASA
 */

/**
 * Register the four fixture abilities. Must run inside wp_abilities_api_init.
 *
 * @return void
 */
function asa_tests_register_fixture_abilities() {
	// A well-behaved read-only ability: should produce zero findings.
	wp_register_ability(
		'asa-fixture/safe-reader',
		[
			'label'               => 'Safe Reader',
			'description'         => 'Reads an option behind a capability gate.',
			'category'            => 'asa-fixtures',
			'execute_callback'    => static function () {
				return get_option( 'blogname' );
			},
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'input_schema'        => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => [
					'mode' => [
						'type' => 'string',
						'enum' => [ 'a', 'b' ],
					],
				],
			],
			'meta'                => [
				'annotations' => [
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				],
			],
		]
	);

	// The liar: claims readonly, implementation writes (ASA006).
	wp_register_ability(
		'asa-fixture/liar',
		[
			'label'               => 'Liar',
			'description'         => 'Claims to be read-only but writes an option.',
			'category'            => 'asa-fixtures',
			'execute_callback'    => static function () {
				update_option( 'asa_fixture_liar', 1 );
				return true;
			},
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'meta'                => [
				'annotations'  => [
					'readonly'    => true,
					'destructive' => false,
				],
				'show_in_rest' => true,
			],
		]
	);

	// The open door: __return_true on a destructive, REST-exposed ability
	// (ASA002 + ASA004 + ASA005).
	wp_register_ability(
		'asa-fixture/open-door',
		[
			'label'               => 'Open Door',
			'description'         => 'Destructive ability with an unconditional-allow gate.',
			'category'            => 'asa-fixtures',
			'execute_callback'    => static function () {
				delete_option( 'asa_fixture_liar' );
				return true;
			},
			'permission_callback' => '__return_true',
			'meta'                => [
				'annotations'  => [ 'destructive' => true ],
				'show_in_rest' => true,
			],
		]
	);

	// Auth-only un-annotated writer (ASA003 + ASA004 heuristic + ASA009).
	wp_register_ability(
		'asa-fixture/auth-only',
		[
			'label'               => 'Auth Only',
			'description'         => 'Un-annotated writer gated only on authentication.',
			'category'            => 'asa-fixtures',
			'execute_callback'    => static function () {
				wp_insert_post( [ 'post_title' => 'asa-fixture' ] );
				return true;
			},
			'permission_callback' => 'is_user_logged_in',
			'meta'                => [
				'show_in_rest' => true,
			],
		]
	);
}
