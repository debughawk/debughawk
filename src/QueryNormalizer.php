<?php

namespace DebugHawk;

/**
 * Normalizes SQL so that literal values (emails, order IDs, search terms)
 * never leave the site.
 *
 * The output follows ClickHouse's normalizeQuery(), with a few MySQL-specific
 * differences: double-quoted strings are literals, multi-row VALUES collapse to
 * a single row, identifiers are never replaced, and trailing semicolons are
 * dropped. The same statement always produces the same output, byte for byte,
 * and normalizing that output again changes nothing, because the dashboard
 * groups queries by hashing the normalized text.
 */
final class QueryNormalizer {
	/** A string, number or existing placeholder. */
	private const LITERAL = 1;

	/** A keyword or bare identifier. */
	private const WORD = 2;

	/** A backtick-quoted identifier. */
	private const QUOTED = 3;

	/** Any other single character. */
	private const PUNCT = 4;

	/** A collapsed literal-only list: "(?..)". */
	private const GROUP = 5;

	private const WHITESPACE = " \t\n\r\x0B\x0C";

	private const DIGITS = '0123456789';

	/** Keywords after which a "-" or "+" is a sign rather than an operator. */
	private const KEYWORDS_BEFORE_SIGN = [
		'AND', 'BETWEEN', 'BY', 'CASE', 'DIV', 'ELSE', 'ESCAPE', 'HAVING', 'IN', 'INTERVAL', 'IS',
		'LIKE', 'LIMIT', 'MOD', 'NOT', 'OFFSET', 'ON', 'OR', 'REGEXP', 'RLIKE', 'SELECT', 'SET',
		'THEN', 'VALUES', 'WHEN', 'WHERE', 'XOR',
	];

	private static ?string $word_chars = null;

	public static function normalize( string $sql ): string {
		try {
			$tokens = self::tokenize( $sql );
			$tokens = self::drop_trailing_semicolons( $tokens );
			$tokens = self::attach_signs( $tokens );
			$tokens = self::collapse_literal_lists( $tokens );
			$tokens = self::collapse_literal_sequences( $tokens );
			$tokens = self::collapse_values_rows( $tokens );

			return self::render( $tokens );
		} catch ( \Throwable $e ) {
			return self::fallback( $sql );
		}
	}

	/**
	 * Splits SQL into tokens. Whitespace and comments are not kept as tokens:
	 * each token records whether whitespace preceded it instead.
	 *
	 * @return array<int, array{type: int, text: string, space: bool, sign: string}>
	 */
	private static function tokenize( string $sql ): array {
		$tokens = [];
		$length = strlen( $sql );
		$space  = false;
		$i      = 0;

		$push = static function ( int $type, string $text ) use ( &$tokens, &$space ): void {
			// "--" followed by whitespace would read as a comment next time, so a
			// "--" that wasn't a comment (as in "a--/* x */b") never gets a space.
			$count = count( $tokens );

			if ( $space && self::is_punct( $tokens[ $count - 1 ] ?? null, '-' ) && ! $tokens[ $count - 1 ]['space'] && self::is_punct( $tokens[ $count - 2 ] ?? null, '-' ) ) {
				$space = false;
			}

			self::push_token( $tokens, [
				'type'  => $type,
				'text'  => $text,
				'space' => $space,
				'sign'  => '',
			] );
			$space = false;
		};

		while ( $i < $length ) {
			$char = $sql[ $i ];
			$next = $sql[ $i + 1 ] ?? '';

			if ( strpos( self::WHITESPACE, $char ) !== false ) {
				$space = true;
				$i ++;
				continue;
			}

			// Line comments. MySQL only treats "--" as a comment when whitespace or
			// a control character follows it. A bare "--" at the very end is kept,
			// so that normalizing the output again gives the same result.
			if ( $char === '#' || ( $char === '-' && $next === '-' && $i + 2 < $length && ord( $sql[ $i + 2 ] ) <= 32 ) ) {
				$end   = strpos( $sql, "\n", $i );
				$i     = $end === false ? $length : $end;
				$space = true;
				continue;
			}

			// Block comments, including /*! ... */ and /*+ ... */.
			if ( $char === '/' && $next === '*' ) {
				$end   = strpos( $sql, '*/', $i + 2 );
				$i     = $end === false ? $length : $end + 2;
				$space = true;
				continue;
			}

			// Strings, plus N'..', X'..' and b'..' literals.
			if ( $char === "'" || $char === '"' || ( $next === "'" && strpos( 'NnXxBb', $char ) !== false ) ) {
				$quote_at = $char === "'" || $char === '"' ? $i : $i + 1;
				$i        = self::skip_string( $sql, $quote_at );
				$push( self::LITERAL, '?' );
				continue;
			}

			if ( $char === '`' ) {
				$end = self::find_backtick_end( $sql, $i );

				if ( $end === null ) {
					// Unterminated: keep going so any literals after it are still replaced.
					$push( self::PUNCT, '`' );
					$i ++;
					continue;
				}

				$push( self::QUOTED, substr( $sql, $i, $end - $i ) );
				$i = $end;
				continue;
			}

			// Existing placeholders, so normalizing twice changes nothing.
			if ( $char === '?' ) {
				$is_sequence = substr( $sql, $i, 3 ) === '?..';
				$push( self::LITERAL, $is_sequence ? '?..' : '?' );
				$i += $is_sequence ? 3 : 1;
				continue;
			}

			$is_digit = $char >= '0' && $char <= '9';

			if ( $is_digit || ( $char === '.' && $next >= '0' && $next <= '9' && ! self::ends_with_operand( $tokens ) ) ) {
				$end = self::skip_number( $sql, $i );

				// Something like "123abc" is an identifier, not a number. A decimal
				// point means it's a number, whatever follows.
				$is_decimal = strpos( substr( $sql, $i, $end - $i ), '.' ) !== false;

				if ( $is_decimal || $end >= $length || ! self::is_word_char( $sql[ $end ] ) ) {
					$push( self::LITERAL, '?' );
					$i = $end;
					continue;
				}
			}

			if ( $is_digit || self::is_word_char( $char ) ) {
				$end = $i + strspn( $sql, self::word_chars(), $i );
				$push( self::WORD, substr( $sql, $i, $end - $i ) );
				$i = $end;
				continue;
			}

			$push( self::PUNCT, $char );
			$i ++;
		}

		return $tokens;
	}

	/**
	 * Adds a token, collapsing literal runs, literal-only lists and VALUES rows
	 * as soon as they're complete. The passes in normalize() would give the
	 * same result later, but doing it here keeps memory flat: a list of 50,000
	 * IDs only ever holds three tokens, so long queries can be normalized in
	 * full, including whatever follows the list.
	 */
	private static function push_token( array &$tokens, array $token ): void {
		if ( $token['type'] === self::LITERAL ) {
			// "1, 2" and "1, -2" fold into the previous literal.
			$comma = count( $tokens ) - self::count_trailing_signs( $tokens, count( $tokens ) ) - 1;

			if ( self::is_punct( $tokens[ $comma ] ?? null, ',' ) && self::is_literal( $tokens[ $comma - 1 ] ?? null ) ) {
				array_splice( $tokens, $comma );
				$tokens[ $comma - 1 ]['text'] = '?..';

				return;
			}
		}

		if ( self::is_punct( $token, ')' ) && self::is_literal( end( $tokens ) ?: null ) ) {
			// "(1)", "(-1)" and "(1, 2)", the last already folded to "(?..".
			$literal = count( $tokens ) - 1;
			$open    = $literal - self::count_trailing_signs( $tokens, $literal ) - 1;

			if ( self::is_punct( $tokens[ $open ] ?? null, '(' ) ) {
				$token = [
					'type'  => self::GROUP,
					'text'  => '(?..)',
					'space' => $tokens[ $open ]['space'],
					'sign'  => '',
				];
				array_splice( $tokens, $open );

				// "VALUES (?..), (?..)" keeps only the first row.
				$count = count( $tokens );

				if (
					self::is_punct( $tokens[ $count - 1 ] ?? null, ',' )
					&& ( $tokens[ $count - 2 ]['type'] ?? null ) === self::GROUP
					&& self::is_values_keyword( $tokens[ $count - 3 ] ?? null )
				) {
					array_pop( $tokens );

					return;
				}
			}
		}

		$tokens[] = $token;
	}

	/**
	 * Counts the chain of signs that ends just before $end, using the same rule
	 * as attach_signs(): only the first sign in a chain may follow whitespace.
	 */
	private static function count_trailing_signs( array $tokens, int $end ): int {
		$count = 0;

		for ( $k = $end - 1; self::is_sign( $tokens[ $k ] ?? null ); $k -- ) {
			$count ++;

			if ( $tokens[ $k ]['space'] ) {
				break;
			}
		}

		return $count;
	}

	/**
	 * Returns the offset just past the closing quote, or the end of the SQL if
	 * the string is never closed, so an unterminated string swallows the rest.
	 */
	private static function skip_string( string $sql, int $quote_at ): int {
		$quote  = $sql[ $quote_at ];
		$length = strlen( $sql );
		$i      = $quote_at + 1;

		while ( $i < $length ) {
			$i += strcspn( $sql, $quote . '\\', $i );

			if ( $i >= $length ) {
				break;
			}

			if ( $sql[ $i ] === '\\' ) {
				$i += 2;
			} elseif ( ( $sql[ $i + 1 ] ?? '' ) === $quote ) {
				$i += 2;
			} else {
				return $i + 1;
			}
		}

		return $length;
	}

	private static function find_backtick_end( string $sql, int $start ): ?int {
		$length = strlen( $sql );
		$i      = $start + 1;

		while ( ( $end = strpos( $sql, '`', $i ) ) !== false ) {
			if ( ( $sql[ $end + 1 ] ?? '' ) !== '`' ) {
				return $end + 1;
			}

			$i = $end + 2;

			if ( $i >= $length ) {
				break;
			}
		}

		return null;
	}

	private static function skip_number( string $sql, int $start ): int {
		$prefix = strtolower( substr( $sql, $start, 2 ) );

		if ( $prefix === '0x' || $prefix === '0b' ) {
			$allowed = $prefix === '0x' ? '0123456789abcdefABCDEF' : '01';
			$digits  = strspn( $sql, $allowed, $start + 2 );

			if ( $digits > 0 ) {
				return $start + 2 + $digits;
			}
		}

		$i = $start + strspn( $sql, self::DIGITS, $start );

		if ( ( $sql[ $i ] ?? '' ) === '.' ) {
			$i ++;
			$i += strspn( $sql, self::DIGITS, $i );
		}

		if ( ( $sql[ $i ] ?? '' ) === 'e' || ( $sql[ $i ] ?? '' ) === 'E' ) {
			$exponent = $i + 1;

			if ( ( $sql[ $exponent ] ?? '' ) === '+' || ( $sql[ $exponent ] ?? '' ) === '-' ) {
				$exponent ++;
			}

			$digits = strspn( $sql, self::DIGITS, $exponent );

			if ( $digits > 0 ) {
				$i = $exponent + $digits;
			}
		}

		return $i;
	}

	/**
	 * Whether the last token can end an expression, in which case a following
	 * "." is a qualifier (table.column) rather than the start of a number.
	 */
	private static function ends_with_operand( array $tokens ): bool {
		$last = end( $tokens );

		if ( $last === false ) {
			return false;
		}

		return $last['type'] !== self::PUNCT || $last['text'] === ')';
	}

	private static function is_word_char( string $char ): bool {
		return strpos( self::word_chars(), $char ) !== false;
	}

	private static function word_chars(): string {
		if ( self::$word_chars === null ) {
			self::$word_chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_$';

			// Multibyte UTF-8 characters are valid in unquoted identifiers.
			for ( $byte = 0x80; $byte <= 0xFF; $byte ++ ) {
				self::$word_chars .= chr( $byte );
			}
		}

		return self::$word_chars;
	}

	/**
	 * "SELECT 1; ;" becomes "SELECT 1". Whitespace and comments between the
	 * semicolons are already gone at this point.
	 */
	private static function drop_trailing_semicolons( array $tokens ): array {
		while ( self::is_punct( end( $tokens ) ?: null, ';' ) ) {
			array_pop( $tokens );
		}

		return $tokens;
	}

	/**
	 * Folds a unary "-" or "+" into the literal it precedes, so "-5" renders as
	 * "-?" and "(-1, 2)" still counts as a literal-only list.
	 */
	private static function attach_signs( array $tokens ): array {
		$result = [];
		$count  = count( $tokens );

		for ( $i = 0; $i < $count; $i ++ ) {
			$token = $tokens[ $i ];

			if ( self::is_sign( $token ) && self::is_sign_position( end( $result ) ) ) {
				// Take the whole chain of adjacent signs, as in "--5".
				$sign = $token['text'];
				$j    = $i + 1;

				while ( self::is_sign( $tokens[ $j ] ?? null ) && ! $tokens[ $j ]['space'] ) {
					$sign .= $tokens[ $j ]['text'];
					$j ++;
				}

				if ( self::is_literal( $tokens[ $j ] ?? null ) ) {
					$literal          = $tokens[ $j ];
					$literal['sign']  = $sign;
					$literal['space'] = $token['space'];
					$result[]         = $literal;
					$i                = $j;
					continue;
				}
			}

			$result[] = $token;
		}

		return $result;
	}

	/**
	 * @param array|false $previous
	 */
	private static function is_sign_position( $previous ): bool {
		if ( $previous === false ) {
			return true;
		}

		if ( $previous['type'] === self::PUNCT ) {
			return $previous['text'] !== ')';
		}

		if ( $previous['type'] === self::WORD ) {
			return in_array( strtoupper( $previous['text'] ), self::KEYWORDS_BEFORE_SIGN, true );
		}

		return false;
	}

	/**
	 * A list of only literals becomes "(?..)": "(1, 2, 3)" and "('a')" both
	 * collapse. A list containing anything else (column names, functions,
	 * subqueries) is kept, but any comma-separated run of literals inside it
	 * still collapses to "?.." (see collapse_literal_sequences), so
	 * "(b, 1, 2)" becomes "(b, ?..)".
	 */
	private static function collapse_literal_lists( array $tokens ): array {
		$result = [];
		$count  = count( $tokens );

		for ( $i = 0; $i < $count; $i ++ ) {
			$token = $tokens[ $i ];

			if ( self::is_punct( $token, '(' ) && self::is_literal( $tokens[ $i + 1 ] ?? null ) ) {
				$j = $i + 1;

				while ( self::is_punct( $tokens[ $j + 1 ] ?? null, ',' ) && self::is_literal( $tokens[ $j + 2 ] ?? null ) ) {
					$j += 2;
				}

				if ( self::is_punct( $tokens[ $j + 1 ] ?? null, ')' ) ) {
					$result[] = [
						'type'  => self::GROUP,
						'text'  => '(?..)',
						'space' => $token['space'],
						'sign'  => '',
					];
					$i        = $j + 1;
					continue;
				}
			}

			$result[] = $token;
		}

		return $result;
	}

	/**
	 * A comma-separated run of two or more literals becomes "?..", anywhere in
	 * the query, as in ClickHouse. This turns "LIMIT 10, 20" into "LIMIT ?.."
	 * and "FIELD(ID, 5, 3, 9)" into "FIELD(ID, ?..)", so the same query with
	 * different values groups together.
	 */
	private static function collapse_literal_sequences( array $tokens ): array {
		$result = [];
		$count  = count( $tokens );

		for ( $i = 0; $i < $count; $i ++ ) {
			$token = $tokens[ $i ];

			if ( self::is_literal( $token ) ) {
				$j = $i;

				while ( self::is_punct( $tokens[ $j + 1 ] ?? null, ',' ) && self::is_literal( $tokens[ $j + 2 ] ?? null ) ) {
					$j += 2;
				}

				if ( $j > $i ) {
					$token['text'] = '?..';
					$i             = $j;
				}
			}

			$result[] = $token;
		}

		return $result;
	}

	/**
	 * "VALUES (?..), (?..)" becomes "VALUES (?..)", so bulk inserts of
	 * different sizes group together.
	 */
	private static function collapse_values_rows( array $tokens ): array {
		$result = [];
		$count  = count( $tokens );

		for ( $i = 0; $i < $count; $i ++ ) {
			$token    = $tokens[ $i ];
			$result[] = $token;

			if ( ! self::is_values_keyword( $token ) ) {
				continue;
			}

			if ( ( $tokens[ $i + 1 ]['type'] ?? null ) !== self::GROUP ) {
				continue;
			}

			$result[] = $tokens[ $i + 1 ];
			$i ++;

			while ( self::is_punct( $tokens[ $i + 1 ] ?? null, ',' ) && ( $tokens[ $i + 2 ]['type'] ?? null ) === self::GROUP ) {
				$i += 2;
			}
		}

		return $result;
	}

	private static function is_values_keyword( ?array $token ): bool {
		return $token !== null
			&& $token['type'] === self::WORD
			&& in_array( strtoupper( $token['text'] ), [ 'VALUES', 'VALUE' ], true );
	}

	private static function is_sign( ?array $token ): bool {
		return self::is_punct( $token, '-' ) || self::is_punct( $token, '+' );
	}

	private static function is_literal( ?array $token ): bool {
		return $token !== null && $token['type'] === self::LITERAL;
	}

	private static function is_punct( ?array $token, string $text ): bool {
		return $token !== null && $token['type'] === self::PUNCT && $token['text'] === $text;
	}

	private static function render( array $tokens ): string {
		$sql = '';

		foreach ( $tokens as $index => $token ) {
			if ( $index > 0 && $token['space'] ) {
				$sql .= ' ';
			} elseif ( $token['text'] === '.' && substr( $sql, -1 ) === '?' ) {
				// Keep "?" then "." from reading as a "?.." placeholder next time.
				$sql .= ' ';
			}

			$sql .= $token['sign'] . $token['text'];
		}

		return $sql;
	}

	/**
	 * Last resort if tokenizing fails unexpectedly. Strips anything that looks
	 * like a literal rather than ever returning the raw SQL.
	 */
	private static function fallback( string $sql ): string {
		$sql = preg_replace(
			[
				'/\'(?:[^\'\\\\]|\\\\.|\'\')*\'/s',
				'/"(?:[^"\\\\]|\\\\.|"")*"/s',
				'/[\'"].*$/s',
				'/\b\d[0-9a-fA-FxX.]*\b/',
				'/\s+/',
			],
			[ '?', '?', '?', '?', ' ' ],
			$sql
		);

		return is_string( $sql ) ? trim( $sql ) : '?';
	}
}
