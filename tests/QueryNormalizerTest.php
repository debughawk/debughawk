<?php

namespace DebugHawk\Tests;

use DebugHawk\QueryNormalizer;
use PHPUnit\Framework\TestCase;

class QueryNormalizerTest extends TestCase {
	/**
	 * @dataProvider examples
	 */
	public function test_normalizes_query( string $input, string $expected ): void {
		$this->assertSame( $expected, QueryNormalizer::normalize( $input ) );
	}

	/**
	 * @dataProvider examples
	 */
	public function test_normalizing_a_normalized_query_changes_nothing( string $input, string $expected ): void {
		$this->assertSame( $expected, QueryNormalizer::normalize( $expected ) );
	}

	/**
	 * @dataProvider edge_cases
	 */
	public function test_handles_edge_case( string $input, string $expected ): void {
		$this->assertSame( $expected, QueryNormalizer::normalize( $input ) );
	}

	/**
	 * @dataProvider edge_cases
	 */
	public function test_edge_case_output_is_stable( string $input, string $expected ): void {
		$this->assertSame( $expected, QueryNormalizer::normalize( $expected ) );
	}

	public function test_large_in_list_keeps_the_rest_of_the_query_in_flat_memory(): void {
		$sql    = 'SELECT * FROM wp_posts WHERE ID IN (' . implode( ', ', range( 1, 100000 ) ) . ') ORDER BY post_date DESC LIMIT 10';
		$before = $this->reset_peak_memory();

		$this->assertSame( 'SELECT * FROM wp_posts WHERE ID IN (?..) ORDER BY post_date DESC LIMIT ?', QueryNormalizer::normalize( $sql ) );
		$this->assert_peak_memory_below( 2 * 1024 * 1024, $before );
	}

	public function test_large_bulk_insert_keeps_the_rest_of_the_query_in_flat_memory(): void {
		$rows   = str_repeat( "('_transient_feed_123', 'a:1:{i:0;s:5:\"hello\";}', 'no'), ", 20000 );
		$sql    = 'INSERT INTO wp_options (option_name, option_value, autoload) VALUES ' . $rows . "('last', 'row', 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)";
		$before = $this->reset_peak_memory();

		$this->assertSame(
			'INSERT INTO wp_options (option_name, option_value, autoload) VALUES (?..) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)',
			QueryNormalizer::normalize( $sql )
		);
		$this->assert_peak_memory_below( 2 * 1024 * 1024, $before );
	}

	private function reset_peak_memory(): int {
		if ( function_exists( 'memory_reset_peak_usage' ) ) {
			memory_reset_peak_usage();
		}

		return memory_get_usage();
	}

	private function assert_peak_memory_below( int $bytes, int $before ): void {
		// Peak memory can only be reset on PHP 8.2+.
		if ( function_exists( 'memory_reset_peak_usage' ) ) {
			$this->assertLessThan( $bytes, memory_get_peak_usage() - $before );
		}
	}

	public function examples(): array {
		return [
			'integer'                  => [
				'SELECT * FROM wp_posts WHERE ID = 42',
				'SELECT * FROM wp_posts WHERE ID = ?',
			],
			'whitespace and comment'   => [
				"SELECT  *\n  FROM wp_posts /* c */ WHERE ID = 7;",
				'SELECT * FROM wp_posts WHERE ID = ?',
			],
			'negative number'          => [
				'SELECT * FROM wp_posts WHERE ID = -5',
				'SELECT * FROM wp_posts WHERE ID = -?',
			],
			'decimal, hex, exponent'   => [
				'SELECT * FROM wp_products WHERE price > 9.99 AND x = 0xFF AND y = 1e3',
				'SELECT * FROM wp_products WHERE price > ? AND x = ? AND y = ?',
			],
			'backslash escape'         => [
				"SELECT * FROM wp_posts WHERE post_title = 'It\\'s here'",
				'SELECT * FROM wp_posts WHERE post_title = ?',
			],
			'doubled quote escape'     => [
				"SELECT * FROM wp_posts WHERE post_title = 'O''Brien'",
				'SELECT * FROM wp_posts WHERE post_title = ?',
			],
			'double-quoted string'     => [
				'SELECT * FROM wp_posts WHERE post_title = "Hello"',
				'SELECT * FROM wp_posts WHERE post_title = ?',
			],
			'option lookup'            => [
				"SELECT option_value FROM wp_options WHERE option_name = 'siteurl'",
				'SELECT option_value FROM wp_options WHERE option_name = ?',
			],
			'backticks'                => [
				"SELECT `ID` FROM `wp_posts` WHERE `post_type` = 'post'",
				'SELECT `ID` FROM `wp_posts` WHERE `post_type` = ?',
			],
			'IN lists'                 => [
				"SELECT * FROM wp_posts WHERE post_name IN ('a', 'b') AND ID NOT IN (1,2)",
				'SELECT * FROM wp_posts WHERE post_name IN (?..) AND ID NOT IN (?..)',
			],
			'IN subquery'              => [
				"SELECT * FROM wp_posts WHERE ID IN (SELECT post_id FROM wp_postmeta WHERE meta_key = 'x')",
				'SELECT * FROM wp_posts WHERE ID IN (SELECT post_id FROM wp_postmeta WHERE meta_key = ?)',
			],
			'multi-row insert'         => [
				"INSERT INTO wp_options (option_name, option_value) VALUES ('a', 'b'), ('c', 'd')",
				'INSERT INTO wp_options (option_name, option_value) VALUES (?..)',
			],
			'update'                   => [
				"UPDATE wp_postmeta SET meta_value = '5' WHERE post_id = 12 AND meta_key = '_price'",
				'UPDATE wp_postmeta SET meta_value = ? WHERE post_id = ? AND meta_key = ?',
			],
			'NULL and TRUE kept'       => [
				'SELECT * FROM wp_posts WHERE post_parent = NULL OR flag = TRUE',
				'SELECT * FROM wp_posts WHERE post_parent = NULL OR flag = TRUE',
			],
			'LIMIT offset, count'      => [
				'SELECT SQL_CALC_FOUND_ROWS wp_posts.ID FROM wp_posts WHERE 1=1 ORDER BY wp_posts.post_date DESC LIMIT 0, 10',
				'SELECT SQL_CALC_FOUND_ROWS wp_posts.ID FROM wp_posts WHERE ?=? ORDER BY wp_posts.post_date DESC LIMIT ?..',
			],
			'LIMIT OFFSET'             => [
				'SELECT * FROM wp_posts LIMIT 20 OFFSET 40',
				'SELECT * FROM wp_posts LIMIT ? OFFSET ?',
			],
			'INTERVAL'                 => [
				'SELECT * FROM wp_posts WHERE post_date > NOW() - INTERVAL 7 DAY',
				'SELECT * FROM wp_posts WHERE post_date > NOW() - INTERVAL ? DAY',
			],
			'LIKE'                     => [
				"SELECT * FROM wp_posts p WHERE p.post_title LIKE '%coffee%'",
				'SELECT * FROM wp_posts p WHERE p.post_title LIKE ?',
			],
			'digits in table name'     => [
				'SELECT * FROM wp_123_posts WHERE ID = 1',
				'SELECT * FROM wp_123_posts WHERE ID = ?',
			],
			'trailing line comment'    => [
				'SELECT * FROM wp_posts -- trailing comment',
				'SELECT * FROM wp_posts',
			],
		];
	}

	public function edge_cases(): array {
		return [
			'unterminated string'          => [
				"SELECT * FROM wp_users WHERE user_email = 'jo@example.com",
				'SELECT * FROM wp_users WHERE user_email = ?',
			],
			'unterminated backtick'        => [
				"SELECT * FROM `wp_users WHERE user_email = 'jo@example.com' AND ID = 3",
				'SELECT * FROM `wp_users WHERE user_email = ? AND ID = ?',
			],
			'unterminated block comment'   => [
				'SELECT * FROM wp_posts /* never closed 123',
				'SELECT * FROM wp_posts',
			],
			'hash comment'                 => [
				"SELECT * FROM wp_posts # note 42\nWHERE ID = 1",
				'SELECT * FROM wp_posts WHERE ID = ?',
			],
			'executable comment'           => [
				'SELECT /*! STRAIGHT_JOIN */ /*+ NO_INDEX(p) */ * FROM wp_posts',
				'SELECT * FROM wp_posts',
			],
			'comment between tokens'       => [
				'SELECT ID/**/FROM wp_posts',
				'SELECT ID FROM wp_posts',
			],
			'bit and hex strings'          => [
				"SELECT * FROM t WHERE a = X'4D7953' AND b = b'101' AND c = 0b11 AND d = N'text'",
				'SELECT * FROM t WHERE a = ? AND b = ? AND c = ? AND d = ?',
			],
			'decimal forms'                => [
				'SELECT * FROM t WHERE a = .5 AND b = 5. AND c = 1.5e-3',
				'SELECT * FROM t WHERE a = ? AND b = ? AND c = ?',
			],
			'identifiers with digits'      => [
				'SELECT col_12345, t1.c2, 123abc FROM `wp_2_posts` AS p1',
				'SELECT col_12345, t1.c2, 123abc FROM `wp_2_posts` AS p1',
			],
			'binary minus'                 => [
				'SELECT ID - 1 FROM wp_posts WHERE a-1 > 0',
				'SELECT ID - ? FROM wp_posts WHERE a-? > ?',
			],
			'signed list'                  => [
				'SELECT * FROM t WHERE a IN (-1, +2, 3)',
				'SELECT * FROM t WHERE a IN (?..)',
			],
			'mixed list'                   => [
				'SELECT * FROM t WHERE a IN (b, 1)',
				'SELECT * FROM t WHERE a IN (b, ?)',
			],
			'function arguments'           => [
				'SELECT * FROM wp_posts ORDER BY FIELD(ID, 5, 3, 9)',
				'SELECT * FROM wp_posts ORDER BY FIELD(ID, ?..)',
			],
			'rows with non-literals'       => [
				"INSERT INTO t (a, b) VALUES ('x', NOW()), ('y', NOW())",
				'INSERT INTO t (a, b) VALUES (?, NOW()), (?, NOW())',
			],
			'insert on duplicate key'      => [
				"INSERT INTO wp_options (option_name) VALUES ('a') ON DUPLICATE KEY UPDATE option_name = VALUES(option_name)",
				'INSERT INTO wp_options (option_name) VALUES (?..) ON DUPLICATE KEY UPDATE option_name = VALUES(option_name)',
			],
			'keyword case preserved'       => [
				"select * from wp_posts where post_status = 'publish' limit 5",
				'select * from wp_posts where post_status = ? limit ?',
			],
			'all trailing semicolons'      => [
				'SELECT 1;;',
				'SELECT ?',
			],
			'semicolons with whitespace'   => [
				"SELECT 1 ; ;\n\t; ",
				'SELECT ?',
			],
			'double dash without a space'  => [
				'SELECT * FROM t WHERE a = 5--3',
				'SELECT * FROM t WHERE a = ?--?',
			],
			'decimal touching a word'      => [
				'SELECT 5.5abc',
				'SELECT ?abc',
			],
			'double sign in a sequence'    => [
				'SELECT 1, --2, 3',
				'SELECT ?..',
			],
			'dashes before a comment'      => [
				"SELECT a--#x\nFROM t",
				'SELECT a--FROM t',
			],
			'empty'                        => [
				" \n\t ",
				'',
			],
			'multibyte identifiers'        => [
				"SELECT café FROM t WHERE name = 'José'",
				'SELECT café FROM t WHERE name = ?',
			],
		];
	}
}
