<?php
/**
 * Plugin orchestrator: wires the subsystems together.
 *
 * @package ASA
 */

namespace ASA;

use ASA\Admin\Rest_Controller;
use ASA\Admin\Settings_Page;
use ASA\Analysis\Callback_Capture;

defined( 'ABSPATH' ) || exit;

/**
 * Boots the auditor's components.
 *
 * The auditor is read-only and stateless: nothing here writes to the
 * database except the optional short-lived report transient (see
 * Report\Audit_Runner). No Abilities are registered, no hooks mutate
 * anything — every registration below is either a passive observer
 * (Callback_Capture) or a manage_options-gated reader (REST routes).
 */
class Plugin {

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Boot the plugin exactly once.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		// Passive observer: captures raw ability args (incl. callbacks, which
		// have no public getters on WP_Ability) as abilities register. Must be
		// in place before the abilities registry first initializes.
		Callback_Capture::register();

		// manage_options-gated read routes under asa/v1.
		$rest = new Rest_Controller();
		$rest->register();

		// Tools -> Agent Surface (React dashboard shell).
		if ( is_admin() ) {
			$page = new Settings_Page();
			$page->register();
		}
	}
}
