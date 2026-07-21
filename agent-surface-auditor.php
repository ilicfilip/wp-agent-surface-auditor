<?php
/**
 * Plugin Name:       Agent Surface Auditor
 * Plugin URI:        https://github.com/ilicfilip/wp-agent-surface-auditor
 * Description:       Read-only audit of the WordPress Abilities API surface exposed to AI agents (core REST run endpoint and MCP Adapter servers). Inventories every registered Ability, resolves agent exposure, and flags risky combinations. Never executes, blocks, or modifies anything.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Filip Ilic
 * Author URI:        https://github.com/ilicfilip
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       agent-surface-auditor
 *
 * @package ASA
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Plugin version.
 */
define( 'ASA_VERSION', '0.1.0' );

/**
 * Absolute path to the main plugin file.
 */
define( 'ASA_PLUGIN_FILE', __FILE__ );

/**
 * Absolute path to the plugin directory, with trailing slash.
 */
define( 'ASA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * URL to the plugin directory, with trailing slash.
 */
define( 'ASA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoloader for the \ASA\ namespace, using the WordPress file-naming
 * convention (class-{name}.php, lowercase, hyphen-separated).
 *
 * Sub-namespaces map to directories and the final class name maps to a
 * class-prefixed file, so \ASA\Registry\Ability_Registry_Reader resolves to
 * src/Registry/class-ability-registry-reader.php.
 *
 * @param string $class Fully qualified class name.
 * @return void
 */
function asa_autoload( $class ) {
	$prefix = 'ASA\\';
	$len    = strlen( $prefix );

	// Bail early for classes outside our namespace.
	if ( 0 !== strncmp( $prefix, $class, $len ) ) {
		return;
	}

	// Strip the namespace prefix, leaving e.g. "Model\Report" or "Plugin".
	$relative = substr( $class, $len );
	$parts    = explode( '\\', $relative );

	// The last segment is the class name; everything before it is the sub-path.
	$class_name = array_pop( $parts );
	$kebab_name = str_replace( '_', '-', strtolower( $class_name ) );

	$sub_path = empty( $parts ) ? '' : implode( '/', $parts ) . '/';

	// Classes live in class-*.php, interfaces in interface-*.php (WPCS naming).
	foreach ( [ 'class-', 'interface-' ] as $prefix_type ) {
		$path = ASA_PLUGIN_DIR . 'src/' . $sub_path . $prefix_type . $kebab_name . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
			return;
		}
	}
}
spl_autoload_register( 'asa_autoload' );

/**
 * Determine whether the host environment satisfies the plugin requirements.
 *
 * Requires the Abilities API, which ships in WordPress core 6.9+. Used by both
 * the activation guard and the runtime boot guard.
 *
 * @return bool True when the environment is supported.
 */
function asa_environment_ok() {
	return function_exists( 'wp_get_abilities' ) && class_exists( 'WP_Abilities_Registry' );
}

/**
 * Activation handler: verify requirements.
 *
 * If the environment is unsupported we self-deactivate and queue an admin
 * notice rather than triggering a fatal error. The auditor writes nothing on
 * activation — it is stateless by design.
 *
 * @return void
 */
function asa_activate() {
	if ( ! asa_environment_ok() ) {
		// Refuse to activate: deactivate ourselves and flag a notice.
		deactivate_plugins( plugin_basename( ASA_PLUGIN_FILE ) );
		set_transient( 'asa_activation_error', 1, 60 );
	}
}
register_activation_hook( __FILE__, 'asa_activate' );

/**
 * Render the admin notice shown when activation was refused.
 *
 * @return void
 */
function asa_activation_notice() {
	if ( ! get_transient( 'asa_activation_error' ) ) {
		return;
	}

	delete_transient( 'asa_activation_error' );

	$message = __(
		'Agent Surface Auditor requires WordPress 6.9 or later with the Abilities API (wp_get_abilities). The plugin was not activated.',
		'agent-surface-auditor'
	);

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html( $message )
	);
}
add_action( 'admin_notices', 'asa_activation_notice' );

/**
 * Boot the plugin once all plugins are loaded.
 *
 * Guards on the environment so a downgrade does not fatal an already-active
 * install — it simply stays dormant. Booting on plugins_loaded guarantees the
 * callback-capture filter is in place before the abilities registry can
 * initialize (the registry refuses to initialize before the 'init' hook).
 *
 * @return void
 */
function asa_boot() {
	if ( ! asa_environment_ok() ) {
		return;
	}

	if ( class_exists( '\\ASA\\Plugin' ) ) {
		\ASA\Plugin::boot();
	}
}
add_action( 'plugins_loaded', 'asa_boot' );
