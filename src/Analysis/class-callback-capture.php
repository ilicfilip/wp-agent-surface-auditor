<?php
/**
 * Passive capture of raw ability registration args.
 *
 * @package ASA
 */

namespace ASA\Analysis;

defined( 'ABSPATH' ) || exit;

/**
 * Captures the raw `$args` passed to wp_register_ability() via the documented
 * `wp_register_ability_args` filter.
 *
 * WP_Ability stores `permission_callback` and `execute_callback` in protected
 * properties with no public getters, so this filter is the only documented way
 * to read the callables without reflecting into private state. The filter
 * returns `$args` unchanged — this class never modifies a registration.
 *
 * Timing: the abilities registry initializes lazily on first access, and
 * refuses to initialize before WP 'init'. Registering our filter on
 * 'plugins_loaded' therefore guarantees we observe every registration. If an
 * ability somehow registered before our filter (e.g. a future core change),
 * the Ability_Registry_Reader falls back to reflection and records the origin.
 */
class Callback_Capture {

	/**
	 * Captured args keyed by ability name.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static $captured = [];

	/**
	 * Whether the filter was registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Hook the capture filter at the latest possible priority so we see the
	 * final args after any other filters have adjusted them.
	 *
	 * @return void
	 */
	public static function register() {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_filter( 'wp_register_ability_args', [ __CLASS__, 'capture' ], PHP_INT_MAX, 2 );
	}

	/**
	 * Filter callback: record the raw args for later inspection.
	 *
	 * @param array<string, mixed> $args The ability registration args.
	 * @param string               $name The ability name.
	 * @return array<string, mixed> The args, unchanged.
	 */
	public static function capture( $args, $name ) {
		if ( is_array( $args ) && is_string( $name ) && $name !== '' ) {
			self::$captured[ $name ] = [
				'permission_callback' => $args['permission_callback'] ?? null,
				'execute_callback'    => $args['execute_callback'] ?? null,
			];
		}

		return $args;
	}

	/**
	 * Get the captured callbacks for an ability, if observed.
	 *
	 * @param string $name The ability name.
	 * @return array<string, mixed>|null Captured `permission_callback` and
	 *                                   `execute_callback`, or null when the
	 *                                   registration was not observed.
	 */
	public static function get( $name ) {
		return self::$captured[ $name ] ?? null;
	}
}
