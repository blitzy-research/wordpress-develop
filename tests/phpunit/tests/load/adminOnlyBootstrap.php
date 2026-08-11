<?php

/**
 * Covers the two admin-only steps the bootstrap no longer takes on every request.
 *
 * `wp-settings.php` used to load `wp-admin/includes/plugin.php` and to construct
 * `WP_Site_Health` unconditionally, on a front-end request as much as on an admin one -
 * 6,533 lines of tokenizing to register work a front-end request cannot do. Both are now
 * reached only where something they provide can be reached, and that condition is the
 * behaviour these cases exist to hold: it is what the reduction in files loaded per
 * front-end request is partly made of, and it is not observable from any function.
 *
 * It is asserted by reading `wp-settings.php` rather than by looking at what the current
 * process happens to have loaded. The bootstrap runs before PHPUnit starts collecting, so
 * no assertion made from inside a test can see what it did; and `get_included_files()` and
 * `class_exists()` answer for the whole process, which by the time any test runs may have
 * been changed by any earlier test that required an admin file or reached Site Health. The
 * file itself is the one witness that is the same on every run and in every order, so it
 * is tokenized: an `include`-family statement or a call sitting at brace depth 0 is
 * unconditional, whatever the comment above it says.
 *
 * The other half of the same behaviour is that deferring must not make either name
 * unreachable, so the class map entry that keeps `WP_Site_Health` resolvable from a
 * front-end request is asserted too.
 *
 * @group load
 * @group autoload
 */
class Tests_Load_AdminOnlyBootstrap extends WP_UnitTestCase {

	/**
	 * Tests that plugin.php is loaded by the bootstrap only where something reaches it.
	 *
	 * `get_plugin_data()` is needed by the plugin loop below the require, which does not
	 * run when no plugin is active, and by the admin, which loads the same file again
	 * through `wp-admin/includes/admin.php` on every screen. On a front-end request of a
	 * site with no active plugin, requiring it parsed a file nothing then called.
	 */
	public function test_the_admin_plugin_api_is_required_conditionally() {
		$statements = self::get_include_statements( 'wp-admin/includes/plugin.php' );

		$this->assertCount(
			1,
			$statements,
			'wp-settings.php should reach wp-admin/includes/plugin.php from exactly one statement.'
		);

		$this->assertGreaterThan(
			0,
			$statements[0]['depth'],
			'The wp-admin/includes/plugin.php require must not be unconditional: a front-end request of a site with no active plugin has nothing to call in it.'
		);

		$this->assertStringContainsString(
			'is_admin()',
			$statements[0]['condition'],
			'The condition guarding wp-admin/includes/plugin.php must keep loading it on every admin request.'
		);

		$this->assertStringContainsString(
			'$_wp_active_plugins',
			$statements[0]['condition'],
			'The condition guarding wp-admin/includes/plugin.php must keep loading it whenever the plugin loop below it will run.'
		);
	}

	/**
	 * Tests that Site Health is instantiated by the bootstrap only where it does something.
	 *
	 * The constructor schedules `wp_site_health_scheduled_check` and adds four callbacks,
	 * and all four are admin or Cron - `admin_body_class`, `admin_enqueue_scripts`,
	 * `site_health_tab_content` and `wp_site_health_scheduled_check`. A front-end request
	 * fires none of them.
	 */
	public function test_site_health_is_constructed_conditionally() {
		$includes = self::get_include_statements( 'wp-admin/includes/class-wp-site-health.php' );

		$this->assertCount(
			1,
			$includes,
			'wp-settings.php should reach wp-admin/includes/class-wp-site-health.php from exactly one statement.'
		);

		$this->assertGreaterThan(
			0,
			$includes[0]['depth'],
			'The class-wp-site-health.php require must not be unconditional.'
		);

		$this->assertStringContainsString(
			'is_admin()',
			$includes[0]['condition'],
			'The condition guarding class-wp-site-health.php must keep loading it on every admin request.'
		);

		$constructions = self::get_get_instance_calls( 'WP_Site_Health' );

		$this->assertCount(
			1,
			$constructions,
			'wp-settings.php should construct WP_Site_Health from exactly one statement.'
		);

		$this->assertGreaterThan(
			0,
			$constructions[0]['depth'],
			'WP_Site_Health::get_instance() must not be called unconditionally: constructing it registers admin and Cron work only.'
		);

		foreach ( array( 'is_admin()', 'wp_doing_cron()', 'WP_CLI' ) as $needed ) {
			$this->assertStringContainsString(
				$needed,
				$constructions[0]['condition'],
				"The condition guarding WP_Site_Health::get_instance() must still cover {$needed}."
			);
		}
	}

	/**
	 * Tests that deferring Site Health leaves the class reachable from any request.
	 *
	 * This is the other half of the deferral, and the reason it is safe: the Site Health
	 * REST controller calls `WP_Site_Health::get_instance()` itself from
	 * `create_initial_rest_routes()`, which runs on a request the bootstrap did not
	 * construct the instance on. The class map is what answers there, so the entry is
	 * asserted rather than assumed, and it is asserted for the two classes the generator
	 * opted in from `wp-admin/includes/`.
	 */
	public function test_site_health_stays_reachable_through_the_class_map() {
		$class_map_file = ABSPATH . WPINC . '/autoload-classmap.php';

		$this->assertFileIsReadable(
			$class_map_file,
			'The generated class map is what keeps a deferred class reachable, so it has to be present.'
		);

		$class_map = require $class_map_file;

		$this->assertIsArray( $class_map, 'The generated class map must return an array.' );

		foreach ( array( 'wp_site_health', 'wp_site_health_auto_updates' ) as $name ) {
			$this->assertArrayHasKey(
				$name,
				$class_map,
				"{$name} must stay in the class map, or deferring it would leave it unloadable."
			);

			$this->assertFileIsReadable(
				ABSPATH . $class_map[ $name ],
				"The class map entry for {$name} must name a readable file."
			);
		}

		/*
		 * Resolved rather than constructed. class_exists() is enough to prove the name is
		 * reachable, and the file it resolved from proves the class map is what made it
		 * reachable; constructing the instance would schedule a Cron event and register
		 * four admin callbacks, which is work this assertion does not need.
		 */
		$this->assertTrue(
			class_exists( 'WP_Site_Health' ),
			'WP_Site_Health must resolve on a request the bootstrap did not construct it on.'
		);

		$reflection = new ReflectionClass( 'WP_Site_Health' );

		$this->assertSame(
			wp_normalize_path( ABSPATH . $class_map['wp_site_health'] ),
			wp_normalize_path( $reflection->getFileName() ),
			'WP_Site_Health must be resolved from the file the class map names.'
		);
	}

	/**
	 * Returns every bootstrap statement that loads a given file, with its nesting.
	 *
	 * A statement at depth 0 runs on every request. Anything deeper runs only when the
	 * condition it sits inside is met, and that condition is returned alongside so the
	 * caller can assert what it covers rather than only that it exists.
	 *
	 * @param string $path Path to look for, relative to the WordPress root.
	 * @return array<int, array{depth: int, condition: string}> Statements found.
	 */
	private static function get_include_statements( $path ) {
		$found  = array();
		$tokens = self::get_bootstrap_tokens();
		$total  = count( $tokens );

		$include_types = array( T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE );

		$depth      = 0;
		$conditions = array();

		for ( $index = 0; $index < $total; $index++ ) {
			$token = $tokens[ $index ];

			if ( '{' === $token ) {
				++$depth;
				continue;
			}

			if ( '}' === $token ) {
				--$depth;
				array_pop( $conditions );
				continue;
			}

			if ( is_array( $token ) && T_IF === $token[0] ) {
				$conditions[ $depth ] = self::get_condition_source( $tokens, $index );
				continue;
			}

			if ( ! is_array( $token ) || ! in_array( $token[0], $include_types, true ) ) {
				continue;
			}

			if ( ! self::statement_mentions( $tokens, $index, $path ) ) {
				continue;
			}

			$found[] = array(
				'depth'     => $depth,
				'condition' => implode( ' ', $conditions ),
			);
		}

		return $found;
	}

	/**
	 * Returns every bootstrap call to a given class's get_instance(), with its nesting.
	 *
	 * @param string $class_name Class name to look for.
	 * @return array<int, array{depth: int, condition: string}> Calls found.
	 */
	private static function get_get_instance_calls( $class_name ) {
		$found  = array();
		$tokens = self::get_bootstrap_tokens();
		$total  = count( $tokens );

		$depth      = 0;
		$conditions = array();

		for ( $index = 0; $index < $total; $index++ ) {
			$token = $tokens[ $index ];

			if ( '{' === $token ) {
				++$depth;
				continue;
			}

			if ( '}' === $token ) {
				--$depth;
				array_pop( $conditions );
				continue;
			}

			if ( is_array( $token ) && T_IF === $token[0] ) {
				$conditions[ $depth ] = self::get_condition_source( $tokens, $index );
				continue;
			}

			if ( ! is_array( $token )
				|| T_STRING !== $token[0]
				|| strtolower( $class_name ) !== strtolower( $token[1] )
			) {
				continue;
			}

			$next = self::next_significant( $tokens, $index + 1 );

			if ( null === $next || ! is_array( $tokens[ $next ] ) || T_DOUBLE_COLON !== $tokens[ $next ][0] ) {
				continue;
			}

			$method = self::next_significant( $tokens, $next + 1 );

			if ( null === $method
				|| ! is_array( $tokens[ $method ] )
				|| T_STRING !== $tokens[ $method ][0]
				|| 'get_instance' !== strtolower( $tokens[ $method ][1] )
			) {
				continue;
			}

			$found[] = array(
				'depth'     => $depth,
				'condition' => implode( ' ', $conditions ),
			);
		}

		return $found;
	}

	/**
	 * Returns the source of the condition an `if` was written with.
	 *
	 * @param array $tokens Token stream.
	 * @param int   $index  Index of the T_IF token.
	 * @return string Condition source, without the surrounding parentheses.
	 */
	private static function get_condition_source( $tokens, $index ) {
		$total     = count( $tokens );
		$condition = '';
		$depth     = 0;

		for ( $next = $index + 1; $next < $total; $next++ ) {
			$token = $tokens[ $next ];
			$text  = is_array( $token ) ? $token[1] : $token;

			if ( '(' === $token ) {
				++$depth;

				if ( 1 === $depth ) {
					continue;
				}
			}

			if ( ')' === $token ) {
				--$depth;

				if ( 0 === $depth ) {
					break;
				}
			}

			if ( $depth > 0 ) {
				$condition .= $text;
			}
		}

		return trim( preg_replace( '/\s+/', ' ', $condition ) );
	}

	/**
	 * Reports whether the statement beginning at an index mentions a given path.
	 *
	 * @param array  $tokens Token stream.
	 * @param int    $index  Index to read forward from.
	 * @param string $path   Path to look for.
	 * @return bool Whether the statement's string literals spell out the path.
	 */
	private static function statement_mentions( $tokens, $index, $path ) {
		$total   = count( $tokens );
		$literal = '';

		for ( $next = $index + 1; $next < $total; $next++ ) {
			$token = $tokens[ $next ];

			if ( ';' === $token ) {
				break;
			}

			if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
				$literal .= trim( $token[1], '"\'' );
			}
		}

		return false !== strpos( $literal, $path );
	}

	/**
	 * Returns the index of the next token that is not whitespace or a comment.
	 *
	 * @param array $tokens Token stream.
	 * @param int   $index  Index to start from.
	 * @return int|null Index found, or null at the end of the stream.
	 */
	private static function next_significant( $tokens, $index ) {
		$total     = count( $tokens );
		$ignorable = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT );

		for ( $next = $index; $next < $total; $next++ ) {
			if ( is_array( $tokens[ $next ] ) && in_array( $tokens[ $next ][0], $ignorable, true ) ) {
				continue;
			}

			return $next;
		}

		return null;
	}

	/**
	 * Returns the bootstrap's token stream, read once for the whole class.
	 *
	 * @return array Token stream of wp-settings.php.
	 */
	private static function get_bootstrap_tokens() {
		static $tokens = null;

		if ( null === $tokens ) {
			$tokens = token_get_all( file_get_contents( ABSPATH . 'wp-settings.php' ) );
		}

		return $tokens;
	}
}
