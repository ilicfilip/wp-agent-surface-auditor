<?php
/**
 * PHPUnit bootstrap: pure unit mode by default, WP integration mode when
 * WP_TESTS_DIR points at an installed wordpress-tests-lib.
 *
 * Unit mode (tests/Unit): the rules, engine, scorer, and model classes call
 * no WordPress functions by design, so we only satisfy the ABSPATH guard and
 * register the plugin's autoloader mapping.
 *
 * Integration mode (tests/Integration): loads the WordPress test library,
 * loads the plugin on muplugins_loaded, and registers the fixture abilities
 * on the real wp_abilities_api_init hook — that hook fires exactly once, on
 * the registry's first lazy initialization, so fixtures MUST be hooked here
 * in the bootstrap, not inside individual tests.
 *
 * @package ASA
 */

$asa_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( $asa_tests_dir && file_exists( $asa_tests_dir . '/includes/functions.php' ) ) {
	// ---------------------------------------------------------- integration.
	$asa_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
	if ( false !== $asa_polyfills_path ) {
		define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $asa_polyfills_path );
	}

	require_once $asa_tests_dir . '/includes/functions.php';

	tests_add_filter(
		'muplugins_loaded',
		static function () {
			require dirname( __DIR__ ) . '/agent-surface-auditor.php';
		}
	);

	// Fixture ability category + abilities on the real registration hooks.
	tests_add_filter(
		'wp_abilities_api_categories_init',
		static function () {
			wp_register_ability_category(
				'asa-fixtures',
				[
					'label'       => 'ASA Fixtures',
					'description' => 'Deliberately-unsafe fixtures for integration tests.',
				]
			);
		}
	);
	tests_add_filter(
		'wp_abilities_api_init',
		static function () {
			require_once __DIR__ . '/fixtures/register-fixture-abilities.php';
			asa_tests_register_fixture_abilities();
		}
	);

	require $asa_tests_dir . '/includes/bootstrap.php';

	return;
}

// ----------------------------------------------------------------- unit.
// Satisfy the `defined( 'ABSPATH' ) || exit;` guard in every source file.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}

// Time constant used by Audit_Runner; defined by WP at runtime.
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

/**
 * Autoloader mirroring asa_autoload() from the main plugin file, which is not
 * loaded here because it calls WP functions at file scope.
 *
 * @param string $class_fqcn Fully qualified class name.
 * @return void
 */
spl_autoload_register(
	static function ( $class_fqcn ) {
		$prefix = 'ASA\\';
		$len    = strlen( $prefix );

		if ( 0 !== strncmp( $prefix, $class_fqcn, $len ) ) {
			return;
		}

		$parts      = explode( '\\', substr( $class_fqcn, $len ) );
		$class_name = array_pop( $parts );
		$kebab_name = str_replace( '_', '-', strtolower( $class_name ) );
		$sub_path   = empty( $parts ) ? '' : implode( '/', $parts ) . '/';

		foreach ( [ 'class-', 'interface-' ] as $prefix_type ) {
			$path = dirname( __DIR__ ) . '/src/' . $sub_path . $prefix_type . $kebab_name . '.php';
			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);

require_once __DIR__ . '/class-asa-testcase.php';
