<?php
/**
 * Reflection-based, read-only description of ability callbacks.
 *
 * @package ASA
 */

namespace ASA\Analysis;

use ReflectionFunction;
use ReflectionMethod;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Describes a callable without invoking it: what kind it is, its printable
 * name, and — when resolvable — the source file and line span.
 *
 * The source span is the input for the smell heuristics (ASA002/003/006/008).
 * Everything here is best-effort and fails safe: any reflection error degrades
 * to `source_available: false`, never to a thrown exception. This class never
 * calls the callable.
 *
 * analyze() turns a described callback into heuristic signals by tokenizing
 * its source span (token_get_all — comments and strings are token types of
 * their own, so a function name inside a comment never matches). The span of
 * a closure can include surrounding code from the same lines; signals are
 * therefore smells with confidence `medium`, never proof.
 */
class Callback_Inspector {

	/**
	 * Describe a callable in JSON-safe terms.
	 *
	 * @param mixed $callback The value stored as a callback (usually callable,
	 *                        but treated as untrusted input).
	 * @return array<string, mixed> {
	 *     Description of the callback.
	 *
	 *     @type string      $type             One of 'function', 'closure', 'method',
	 *                                         'static_method', 'invokable_object',
	 *                                         'unknown', 'none'.
	 *     @type string|null $name             Printable name (function name or
	 *                                         Class::method), null for closures.
	 *     @type string|null $file             Source file path, when resolvable.
	 *     @type int|null    $line_start       First line of the callable.
	 *     @type int|null    $line_end         Last line of the callable.
	 *     @type bool        $source_available Whether a readable source span was found.
	 * }
	 */
	public static function describe( $callback ) {
		$info = [
			'type'             => 'unknown',
			'name'             => null,
			'file'             => null,
			'line_start'       => null,
			'line_end'         => null,
			'source_available' => false,
		];

		if ( $callback === null ) {
			$info['type'] = 'none';
			return $info;
		}

		try {
			$reflection = null;

			if ( is_string( $callback ) && function_exists( $callback ) ) {
				$info['type'] = 'function';
				$info['name'] = $callback;
				$reflection   = new ReflectionFunction( $callback );
			} elseif ( $callback instanceof \Closure ) {
				$info['type'] = 'closure';
				$reflection   = new ReflectionFunction( $callback );
			} elseif ( is_array( $callback ) && count( $callback ) === 2 ) {
				list( $target, $method ) = array_values( $callback );
				if ( is_object( $target ) ) {
					$info['type'] = 'method';
					$info['name'] = get_class( $target ) . '::' . $method;
					$reflection   = new ReflectionMethod( $target, $method );
				} elseif ( is_string( $target ) ) {
					$info['type'] = 'static_method';
					$info['name'] = $target . '::' . $method;
					$reflection   = new ReflectionMethod( $target, $method );
				}
			} elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
				$info['type'] = 'invokable_object';
				$info['name'] = get_class( $callback ) . '::__invoke';
				$reflection   = new ReflectionMethod( $callback, '__invoke' );
			} elseif ( is_string( $callback ) && strpos( $callback, '::' ) !== false ) {
				list( $class_name, $method ) = explode( '::', $callback, 2 );
				$info['type']                = 'static_method';
				$info['name']                = $callback;
				$reflection                  = new ReflectionMethod( $class_name, $method );
			}

			if ( $reflection !== null ) {
				$file  = $reflection->getFileName();
				$start = $reflection->getStartLine();
				$end   = $reflection->getEndLine();

				// Internal (built-in) callables report false for all three.
				if ( is_string( $file ) && is_int( $start ) && is_int( $end ) && is_readable( $file ) ) {
					$info['file']             = $file;
					$info['line_start']       = $start;
					$info['line_end']         = $end;
					$info['source_available'] = true;
				}
			}
		} catch ( Throwable $t ) {
			// Fails safe: an unreflectable callback is described, not fatal.
			unset( $t );
		}

		return $info;
	}

	/**
	 * Extract heuristic signals from a described callback's source.
	 *
	 * @param array<string, mixed> $info A description from describe().
	 * @return array<string, mixed> {
	 *     Heuristic signals. All of them are smells, not proof.
	 *
	 *     @type bool      $resolved                  Source (or semantics, for
	 *                                                well-known names) was analyzable.
	 *     @type bool|null $returns_only_literal_true Every return statement in the
	 *                                                span returns literal true.
	 *                                                Null when unresolved/no returns.
	 *     @type bool      $calls_current_user_can    current_user_can()/user_can() seen.
	 *     @type bool      $calls_is_user_logged_in   is_user_logged_in() seen.
	 *     @type string[]  $capability_checks         Literal first string args of
	 *                                                capability checks.
	 *     @type string[]  $write_indicators          Matched write functions/methods.
	 *     @type bool      $has_confirmed_write       An unambiguous write was seen
	 *                                                (a named write function, a
	 *                                                $wpdb write method, or a
	 *                                                query() with literal write
	 *                                                SQL) — distinct from a bare
	 *                                                query() smell.
	 * }
	 */
	public static function analyze( array $info ) {
		$analysis = [
			'resolved'                  => false,
			'returns_only_literal_true' => null,
			'calls_current_user_can'    => false,
			'calls_is_user_logged_in'   => false,
			'capability_checks'         => [],
			'write_indicators'          => [],
			'has_confirmed_write'       => false,
		];

		$name = isset( $info['name'] ) && is_string( $info['name'] ) ? strtolower( $info['name'] ) : null;

		// Well-known names resolve semantically without reading source.
		if ( $name === '__return_true' ) {
			$analysis['resolved']                  = true;
			$analysis['returns_only_literal_true'] = true;
			return $analysis;
		}
		if ( $name === 'is_user_logged_in' ) {
			$analysis['resolved']                = true;
			$analysis['calls_is_user_logged_in'] = true;
			return $analysis;
		}

		$source = self::read_span( $info );
		if ( $source === null ) {
			return $analysis;
		}

		try {
			$tokens = token_get_all( '<?php ' . $source );
		} catch ( Throwable $t ) {
			return $analysis;
		}

		$analysis['resolved'] = true;
		self::scan_tokens( $tokens, $analysis );

		return $analysis;
	}

	/**
	 * Read the source lines of a described callback.
	 *
	 * @param array<string, mixed> $info A description from describe().
	 * @return string|null The source span, or null when unavailable.
	 */
	private static function read_span( array $info ) {
		if ( empty( $info['source_available'] ) || ! is_string( $info['file'] ?? null ) ) {
			return null;
		}

		$start = $info['line_start'];
		$end   = $info['line_end'];
		if ( ! is_int( $start ) || ! is_int( $end ) || $start < 1 || $end < $start ) {
			return null;
		}

		try {
			$lines = file( $info['file'] );
		} catch ( Throwable $t ) {
			return null;
		}

		if ( ! is_array( $lines ) || count( $lines ) < $end ) {
			return null;
		}

		return implode( '', array_slice( $lines, $start - 1, $end - $start + 1 ) );
	}

	/**
	 * Scan a token stream for the heuristic signals.
	 *
	 * @param array<int, mixed>    $tokens   token_get_all() output.
	 * @param array<string, mixed> $analysis Signals array, modified in place.
	 * @return void
	 */
	private static function scan_tokens( array $tokens, array &$analysis ) {
		$count        = count( $tokens );
		$returns      = [];
		$has_returns  = false;
		$skip_types   = [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ];
		$is_skippable = static function ( $token ) use ( $skip_types ) {
			return is_array( $token ) && in_array( $token[0], $skip_types, true );
		};

		$next_meaningful = static function ( int $index ) use ( $tokens, $count, $is_skippable ): ?int {
			for ( $j = $index + 1; $j < $count; $j++ ) {
				if ( ! $is_skippable( $tokens[ $j ] ) ) {
					return $j;
				}
			}
			return null;
		};
		$prev_meaningful = static function ( int $index ) use ( $tokens, $is_skippable ): ?int {
			for ( $j = $index - 1; $j >= 0; $j-- ) {
				if ( ! $is_skippable( $tokens[ $j ] ) ) {
					return $j;
				}
			}
			return null;
		};

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) ) {
				continue;
			}

			// Global function calls: T_STRING followed by '(' and not preceded
			// by -> / :: / function / new.
			if ( $token[0] === T_STRING ) {
				$next = $next_meaningful( $i );
				$prev = $prev_meaningful( $i );

				$is_call = $next !== null && $tokens[ $next ] === '(';
				if ( $is_call && $prev !== null ) {
					$prev_token = $tokens[ $prev ];
					if ( is_array( $prev_token )
						&& in_array( $prev_token[0], [ T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW ], true ) ) {
						$is_call = false;

						// Method call: match against the write-method list.
						if ( $prev_token[0] === T_OBJECT_OPERATOR && Write_Indicators::is_write_method( $token[1] ) ) {
							$method = strtolower( $token[1] );

							// insert/update/delete/replace are unambiguous writes;
							// query() is only confirmed when its first literal arg
							// is write SQL (else it stays a weak "->query()" smell).
							if ( 'query' === $method ) {
								$sql = self::first_string_arg( $tokens, $i, $count );
								if ( $sql !== null && Write_Indicators::is_write_sql( $sql ) ) {
									self::add_unique( $analysis['write_indicators'], '->query() [write SQL]' );
									$analysis['has_confirmed_write'] = true;
								} else {
									self::add_unique( $analysis['write_indicators'], '->query()' );
								}
							} else {
								self::add_unique( $analysis['write_indicators'], '->' . $method . '()' );
								$analysis['has_confirmed_write'] = true;
							}
						}
					}
				}

				if ( $is_call ) {
					$called = strtolower( $token[1] );

					if ( $called === 'current_user_can' || $called === 'user_can' || $called === 'current_user_can_for_site' ) {
						$analysis['calls_current_user_can'] = true;
						$capability                         = self::first_string_arg( $tokens, $i, $count );
						if ( $capability !== null ) {
							self::add_unique( $analysis['capability_checks'], $capability );
						}
					} elseif ( $called === 'is_user_logged_in' ) {
						$analysis['calls_is_user_logged_in'] = true;
					} elseif ( Write_Indicators::is_write_function( $called ) ) {
						self::add_unique( $analysis['write_indicators'], $called . '()' );
						$analysis['has_confirmed_write'] = true;
					}
				}
			}

			// Return statements: collect whether each returns literal true.
			if ( $token[0] === T_RETURN ) {
				$has_returns = true;
				$expression  = [];
				for ( $j = $i + 1; $j < $count; $j++ ) {
					if ( $tokens[ $j ] === ';' ) {
						break;
					}
					if ( ! $is_skippable( $tokens[ $j ] ) ) {
						$expression[] = $tokens[ $j ];
					}
				}
				$returns[] = count( $expression ) === 1
					&& is_array( $expression[0] )
					&& $expression[0][0] === T_STRING
					&& strtolower( $expression[0][1] ) === 'true';
			}

			// Arrow functions: fn() => true has no T_RETURN token.
			if ( defined( 'T_FN' ) && $token[0] === T_FN ) {
				for ( $j = $i + 1; $j < $count; $j++ ) {
					if ( is_array( $tokens[ $j ] ) && $tokens[ $j ][0] === T_DOUBLE_ARROW ) {
						$body = $next_meaningful( $j );
						if ( $body !== null ) {
							$has_returns = true;
							$after       = $next_meaningful( $body );
							$returns[]   = is_array( $tokens[ $body ] )
								&& $tokens[ $body ][0] === T_STRING
								&& strtolower( $tokens[ $body ][1] ) === 'true'
								&& ( $after === null || in_array( $tokens[ $after ], [ ')', ',', ';' ], true ) );
						}
						break;
					}
				}
			}
		}

		if ( $has_returns ) {
			$analysis['returns_only_literal_true'] = ! in_array( false, $returns, true );
		}
	}

	/**
	 * Capture the first literal string argument after a call's opening paren.
	 *
	 * @param array<int, mixed> $tokens Token stream.
	 * @param int               $index  Index of the call's T_STRING token.
	 * @param int               $count  Token count.
	 * @return string|null The unquoted string, or null when not a literal.
	 */
	private static function first_string_arg( array $tokens, $index, $count ) {
		$depth = 0;
		for ( $j = $index + 1; $j < $count && $j < $index + 12; $j++ ) {
			if ( $tokens[ $j ] === '(' ) {
				++$depth;
			} elseif ( $tokens[ $j ] === ')' ) {
				if ( --$depth <= 0 ) {
					return null;
				}
			} elseif ( $depth > 0 && is_array( $tokens[ $j ] ) && $tokens[ $j ][0] === T_CONSTANT_ENCAPSED_STRING ) {
				return trim( $tokens[ $j ][1], '\'"' );
			}
		}
		return null;
	}

	/**
	 * Append a value to a list if not already present.
	 *
	 * @param string[] $list  The list, modified in place.
	 * @param string   $value The value.
	 * @return void
	 */
	private static function add_unique( array &$list, $value ) {
		if ( ! in_array( $value, $list, true ) ) {
			$list[] = $value;
		}
	}
}
