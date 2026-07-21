<?php
/**
 * Enumerates the abilities registry into normalized descriptors.
 *
 * @package ASA
 */

namespace ASA\Registry;

use ASA\Analysis\Callback_Capture;
use ASA\Analysis\Callback_Inspector;
use ASA\Model\Ability_Descriptor;
use ReflectionProperty;
use Throwable;
use WP_Ability;

defined( 'ABSPATH' ) || exit;

/**
 * Reads every registered ability via wp_get_abilities() and normalizes each
 * WP_Ability into an Ability_Descriptor.
 *
 * Fails safe per ability: a Throwable while reading one ability produces a
 * stub descriptor carrying the error message instead of fataling the request.
 * Nothing here executes an ability.
 */
class Ability_Registry_Reader {

	/**
	 * Enumerate all registered abilities.
	 *
	 * Calling wp_get_abilities() triggers the registry's lazy initialization,
	 * which fires wp_abilities_api_init — so by the time this returns, the
	 * Callback_Capture filter has observed every registration made on that
	 * hook.
	 *
	 * @return Ability_Descriptor[] Descriptors keyed by ability name.
	 */
	public function read() {
		$descriptors = [];

		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return $descriptors;
		}

		foreach ( wp_get_abilities() as $ability ) {
			if ( ! $ability instanceof WP_Ability ) {
				continue;
			}

			try {
				$descriptor = $this->describe( $ability );
			} catch ( Throwable $t ) {
				$descriptor                  = new Ability_Descriptor();
				$descriptor->name            = $this->safe_name( $ability );
				$descriptor->analysis_errors = [
					'Could not analyze this ability: ' . $t->getMessage(),
				];
			}

			$descriptors[ $descriptor->name ] = $descriptor;
		}

		return $descriptors;
	}

	/**
	 * Normalize one ability into a descriptor.
	 *
	 * @param WP_Ability $ability The registered ability instance.
	 * @return Ability_Descriptor The normalized snapshot.
	 */
	private function describe( WP_Ability $ability ) {
		$descriptor = new Ability_Descriptor();

		$descriptor->name          = $ability->get_name();
		$descriptor->label         = $ability->get_label();
		$descriptor->description   = $ability->get_description();
		$descriptor->category      = $ability->get_category();
		$descriptor->input_schema  = $ability->get_input_schema();
		$descriptor->output_schema = $ability->get_output_schema();
		$descriptor->meta          = $ability->get_meta();
		$descriptor->class_name    = get_class( $ability );

		// Annotations: merge over null defaults so "declared false" stays
		// distinguishable from "never declared" (both matter to the rules).
		$annotations = $descriptor->meta['annotations'] ?? [];
		if ( is_array( $annotations ) ) {
			foreach ( [ 'readonly', 'destructive', 'idempotent' ] as $key ) {
				if ( array_key_exists( $key, $annotations ) && ( is_bool( $annotations[ $key ] ) || $annotations[ $key ] === null ) ) {
					$descriptor->annotations[ $key ] = $annotations[ $key ];
				}
			}
		}

		$descriptor->show_in_rest = ! empty( $descriptor->meta['show_in_rest'] );

		$mcp_meta = $descriptor->meta['mcp'] ?? [];
		if ( is_array( $mcp_meta ) ) {
			$descriptor->mcp_public = ! empty( $mcp_meta['public'] );
			if ( isset( $mcp_meta['type'] ) && is_string( $mcp_meta['type'] ) && $mcp_meta['type'] !== '' ) {
				$descriptor->mcp_type = $mcp_meta['type'];
			}
		}

		$this->attach_callbacks( $ability, $descriptor );

		return $descriptor;
	}

	/**
	 * Resolve the ability's callbacks without executing them.
	 *
	 * Preferred source is the documented wp_register_ability_args filter
	 * (Callback_Capture). When the registration was not observed — e.g. the
	 * registry initialized before this plugin loaded — we fall back to
	 * reflecting WP_Ability's protected properties, and record which path
	 * was used so findings can carry honest confidence.
	 *
	 * @param WP_Ability         $ability    The ability instance.
	 * @param Ability_Descriptor $descriptor The descriptor being built.
	 * @return void
	 */
	private function attach_callbacks( WP_Ability $ability, Ability_Descriptor $descriptor ) {
		$captured = Callback_Capture::get( $descriptor->name );

		if ( $captured !== null ) {
			$descriptor->callback_origin     = 'filter';
			$descriptor->permission_callback = Callback_Inspector::describe( $captured['permission_callback'] );
			$descriptor->execute_callback    = Callback_Inspector::describe( $captured['execute_callback'] );
		} else {
			try {
				$descriptor->callback_origin     = 'reflection';
				$descriptor->permission_callback = Callback_Inspector::describe(
					$this->read_protected( $ability, 'permission_callback' )
				);
				$descriptor->execute_callback    = Callback_Inspector::describe(
					$this->read_protected( $ability, 'execute_callback' )
				);
			} catch ( Throwable $t ) {
				$descriptor->callback_origin     = 'unavailable';
				$descriptor->permission_callback = Callback_Inspector::describe( null );
				$descriptor->execute_callback    = Callback_Inspector::describe( null );
				$descriptor->analysis_errors[]   = 'Could not read callbacks: ' . $t->getMessage();
			}
		}

		$descriptor->permission_analysis = Callback_Inspector::analyze( $descriptor->permission_callback );
		$descriptor->execute_analysis    = Callback_Inspector::analyze( $descriptor->execute_callback );
	}

	/**
	 * Read a protected property off a WP_Ability instance.
	 *
	 * WP_Ability exposes no getters for its callbacks, so this reflection read
	 * is the documented fallback. It reads state only — nothing is modified or
	 * invoked.
	 *
	 * @param WP_Ability $ability  The ability instance.
	 * @param string     $property The property name.
	 * @return mixed The property value.
	 */
	private function read_protected( WP_Ability $ability, $property ) {
		$reflection = new ReflectionProperty( WP_Ability::class, $property );
		$reflection->setAccessible( true );

		return $reflection->getValue( $ability );
	}

	/**
	 * Best-effort name for an ability that failed normalization.
	 *
	 * @param WP_Ability $ability The ability instance.
	 * @return string The ability name, or a placeholder when unreadable.
	 */
	private function safe_name( WP_Ability $ability ) {
		try {
			return $ability->get_name();
		} catch ( Throwable $t ) {
			return 'unknown-ability-' . spl_object_id( $ability );
		}
	}
}
