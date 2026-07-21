<?php
/**
 * Rule_Engine orchestration tests.
 *
 * @package ASA
 */

use ASA\Model\Ability_Descriptor;
use ASA\Model\Exposure;
use ASA\Model\Finding;
use ASA\Rules\Rule;
use ASA\Rules\Rule_Engine;

/**
 * @covers \ASA\Rules\Rule_Engine
 */
class Rule_Engine_Test extends ASA_TestCase {

	public function test_clean_ability_yields_no_findings() {
		$engine = new Rule_Engine();

		$this->assertSame( [], $engine->evaluate( $this->descriptor(), $this->exposure() ) );
	}

	public function test_analysis_errors_produce_asa000() {
		$engine = new Rule_Engine( [] );

		$descriptor = $this->descriptor( [ 'analysis_errors' => [ 'Could not read callbacks: boom.' ] ] );

		$findings = $engine->evaluate( $descriptor, $this->exposure() );

		$this->assertCount( 1, $findings );
		$this->assertSame( Rule_Engine::COULD_NOT_ANALYZE, $findings[0]->rule_id );
		$this->assertSame( Finding::SEVERITY_INFO, $findings[0]->severity );
		$this->assertStringContainsString( 'boom', $findings[0]->message );
	}

	public function test_throwing_rule_degrades_to_asa000_and_others_still_run() {
		$throwing = new class() implements Rule {
			public function id() {
				return 'ASA999';
			}
			public function evaluate( Ability_Descriptor $descriptor, Exposure $exposure, array $prior_findings = [] ) {
				throw new RuntimeException( 'rule exploded' );
			}
		};
		$working  = new class() implements Rule {
			public function id() {
				return 'ASA998';
			}
			public function evaluate( Ability_Descriptor $descriptor, Exposure $exposure, array $prior_findings = [] ) {
				return [ new Finding( 'ASA998', Finding::SEVERITY_LOW, Finding::CONFIDENCE_HIGH, 'm', 'r' ) ];
			}
		};

		$engine   = new Rule_Engine( [ $throwing, $working ] );
		$findings = $engine->evaluate( $this->descriptor(), $this->exposure() );

		$this->assertCount( 2, $findings );
		$this->assertSame( Rule_Engine::COULD_NOT_ANALYZE, $findings[0]->rule_id );
		$this->assertStringContainsString( 'ASA999', $findings[0]->message );
		$this->assertSame( 'ASA998', $findings[1]->rule_id );
	}

	public function test_later_rules_receive_prior_findings() {
		$producer = new class() implements Rule {
			public function id() {
				return 'ASA001';
			}
			public function evaluate( Ability_Descriptor $descriptor, Exposure $exposure, array $prior_findings = [] ) {
				return [ new Finding( 'ASA001', Finding::SEVERITY_CRITICAL, Finding::CONFIDENCE_HIGH, 'm', 'r' ) ];
			}
		};
		$consumer = new class() implements Rule {
			/**
			 * Rule IDs seen in prior findings.
			 *
			 * @var string[]
			 */
			public $seen = [];
			public function id() {
				return 'ASA997';
			}
			public function evaluate( Ability_Descriptor $descriptor, Exposure $exposure, array $prior_findings = [] ) {
				foreach ( $prior_findings as $finding ) {
					$this->seen[] = $finding->rule_id;
				}
				return [];
			}
		};

		$engine = new Rule_Engine( [ $producer, $consumer ] );
		$engine->evaluate( $this->descriptor(), $this->exposure() );

		$this->assertSame( [ 'ASA001' ], $consumer->seen );
	}

	public function test_default_catalog_flags_the_worst_case_fixture() {
		$engine = new Rule_Engine();

		// The deliberately-unsafe fixture: exposed everywhere, no permission
		// callback, destructive, loose schema, listed on a custom server.
		$descriptor = $this->descriptor(
			[
				'class_name'          => 'Evil_Ability',
				'permission_callback' => [ 'type' => 'none' ],
				'annotations'         => [ 'readonly' => null, 'destructive' => true, 'idempotent' => null ],
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [ 'sql' => [ 'type' => 'string' ] ],
				],
			]
		);
		$exposure   = $this->exposure(
			[
				'rest'               => true,
				'mcp_public'         => true,
				'mcp_default_server' => true,
				'adapter_active'     => true,
				'mcp_servers'        => [ 'mcp-adapter-default-server', 'acme' ],
				'mcp_custom_servers' => [ 'acme' ],
			]
		);

		$rule_ids = array_map(
			static function ( $finding ) {
				return $finding->rule_id;
			},
			$engine->evaluate( $descriptor, $exposure )
		);

		$this->assertSame( [ 'ASA001', 'ASA004', 'ASA007', 'ASA010', 'ASA005' ], $rule_ids );
	}
}
