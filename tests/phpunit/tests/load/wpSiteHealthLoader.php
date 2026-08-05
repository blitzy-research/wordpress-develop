<?php
/**
 * Tests for how WP_Site_Health is loaded once wp-settings.php stops requiring it.
 *
 * WP_Site_Health is the one class core references from front-end code while the
 * file that declares it lives in wp-admin. The generated class map is not limited
 * to wp-includes - every value in it is a repository-relative path resolved as
 * `ABSPATH . $path` - so the generator opts that one file in by name and the core
 * autoloader is what makes the class resolve wherever it is referenced.
 *
 * The suite runs outside the admin, which is exactly the context that opt-in
 * exists for: wp-settings.php only requires the file in the admin and during
 * cron, so without the class map `class_exists( 'WP_Site_Health' )` is false here
 * and `WP_Site_Health::get_instance()` is fatal.
 *
 * These tests hold that resolution to the availability the bootstrap provided
 * when it required the file on every request.
 *
 * @package WordPress
 * @subpackage UnitTests
 * @since 7.0.0
 *
 * @group load
 */
class Tests_Load_wpSiteHealthLoader extends WP_UnitTestCase {

	/**
	 * The path the class map is expected to name, relative to ABSPATH.
	 *
	 * @var string
	 */
	const CLASS_FILE = 'wp-admin/includes/class-wp-site-health.php';

	/**
	 * The path of the companion class WP_Site_Health uses, relative to ABSPATH.
	 *
	 * @var string
	 */
	const AUTO_UPDATES_CLASS_FILE = 'wp-admin/includes/class-wp-site-health-auto-updates.php';

	/**
	 * Tests that WP_Site_Health resolves outside the admin.
	 *
	 * This is the property the class map opt-in exists for. The class is referenced
	 * from create_initial_rest_routes(), which runs on any request that dispatches a
	 * REST route, so the name has to resolve on a front-end, CLI or XML-RPC request
	 * just as readily as in the admin.
	 */
	public function test_site_health_resolves_outside_the_admin() {
		$this->assertFalse(
			is_admin(),
			'This suite has to run outside the admin for the class map to be what makes the class available.'
		);

		$this->assertTrue(
			class_exists( 'WP_Site_Health' ),
			'WP_Site_Health must resolve outside the admin.'
		);
	}

	/**
	 * Tests that the resolved class comes from the expected file.
	 *
	 * The class map names one fixed path, so the class has to come from that path
	 * and no other. Reflection is used rather than a path comparison against the
	 * include list, because it reports where the declaration the rest of the
	 * request will use actually came from.
	 */
	public function test_site_health_resolves_from_its_fixed_path() {
		$this->assertTrue( class_exists( 'WP_Site_Health' ), 'WP_Site_Health must resolve.' );

		$reflection = new ReflectionClass( 'WP_Site_Health' );

		$this->assertSame(
			wp_normalize_path( realpath( ABSPATH . self::CLASS_FILE ) ),
			wp_normalize_path( $reflection->getFileName() ),
			'WP_Site_Health must be declared by ' . self::CLASS_FILE . '.'
		);
	}

	/**
	 * Tests that get_instance() hands back one shared instance.
	 *
	 * wp-settings.php calls this in the admin and during cron so the instance's
	 * hooks are registered, and the REST controller calls it again later. All of
	 * them have to be talking about the same object.
	 */
	public function test_get_instance_returns_the_shared_instance() {
		$first  = WP_Site_Health::get_instance();
		$second = WP_Site_Health::get_instance();

		$this->assertInstanceOf( 'WP_Site_Health', $first, 'get_instance() must return a WP_Site_Health.' );
		$this->assertSame( $first, $second, 'get_instance() must return the same instance every time.' );
	}

	/**
	 * Tests that including the class file again after the autoloader ran is a no-op.
	 *
	 * Several places in the shipped tree still include this file, and the autoloader
	 * may well have loaded it before any of them run. Every one of those includes is
	 * `require_once` for exactly that reason, so running one again must neither load
	 * the file a second time nor disturb the class.
	 */
	public function test_including_the_class_file_again_is_a_no_op() {
		$this->assertTrue( class_exists( 'WP_Site_Health' ), 'WP_Site_Health must resolve.' );

		$files_before = count( get_included_files() );

		require_once ABSPATH . self::CLASS_FILE;

		$this->assertSame(
			$files_before,
			count( get_included_files() ),
			self::CLASS_FILE . ' must not load a second time.'
		);

		$this->assertTrue(
			class_exists( 'WP_Site_Health', false ),
			'WP_Site_Health must still be declared after its file was included again.'
		);
	}

	/**
	 * Tests that WP_Site_Health is reached through the core class map.
	 *
	 * The map is keyed by the lower cased name a file declares, and every value is a
	 * repository-relative path resolved as `ABSPATH . $path` rather than relative to
	 * WPINC. That is what lets one entry reach a file outside wp-includes, and it is
	 * why this class needs no loading mechanism of its own. Removing the entry would
	 * leave `class_exists( 'WP_Site_Health' )` false on every front-end request,
	 * which is the regression this asserts against.
	 */
	public function test_site_health_is_reached_through_the_core_class_map() {
		$class_map_file = ABSPATH . WPINC . '/autoload-classmap.php';

		$this->assertFileExists( $class_map_file, 'The generated class map must exist.' );

		$class_map = require $class_map_file;

		$this->assertIsArray( $class_map, 'The generated class map must return an array.' );

		$this->assertArrayHasKey(
			'wp_site_health',
			$class_map,
			'WP_Site_Health must be in the core class map, which is what makes the name resolve outside the admin.'
		);
		$this->assertSame(
			self::CLASS_FILE,
			$class_map['wp_site_health'],
			'The class map must reach WP_Site_Health at ' . self::CLASS_FILE . ' and no other path.'
		);

		$this->assertArrayHasKey(
			'wp_site_health_auto_updates',
			$class_map,
			'WP_Site_Health_Auto_Updates must be mapped too: WP_Site_Health references it while it runs.'
		);
		$this->assertSame(
			self::AUTO_UPDATES_CLASS_FILE,
			$class_map['wp_site_health_auto_updates'],
			'The class map must reach WP_Site_Health_Auto_Updates at ' . self::AUTO_UPDATES_CLASS_FILE . '.'
		);

		$this->assertFileIsReadable(
			ABSPATH . $class_map['wp_site_health'],
			'The mapped path has to resolve as ABSPATH plus the mapped value, directory prefix included.'
		);

		$this->assertTrue( class_exists( 'WP_Site_Health' ), 'WP_Site_Health must resolve.' );

		$reflection = new ReflectionClass( 'WP_Site_Health' );

		$this->assertSame(
			wp_normalize_path( realpath( ABSPATH . $class_map['wp_site_health'] ) ),
			wp_normalize_path( $reflection->getFileName() ),
			'The declaration in use must be the file the class map names, resolved as ABSPATH plus that path.'
		);
	}

	/**
	 * Tests that the generator is what opts the wp-admin file into the map.
	 *
	 * The map is a build artifact, so the entry that makes this class resolve is only
	 * as durable as the generator rule that emits it. Every other candidate is
	 * discovered by walking wp-includes; this file is named explicitly, and dropping
	 * that name would regenerate a map without it and take front-end resolution with
	 * it. Asserting the rule rather than only the artifact is what makes such a
	 * removal fail here instead of at the next rebuild.
	 */
	public function test_the_class_file_is_opted_into_the_class_map_by_the_generator() {
		$generator = dirname( untrailingslashit( ABSPATH ) ) . '/tools/build/generate-autoload-classmap.php';

		$this->assertTrue(
			is_readable( $generator ),
			'The class map generator must be present at tools/build/generate-autoload-classmap.php.'
		);

		require_once $generator;

		$this->assertTrue(
			function_exists( 'wp_autoload_classmap_additional_files' ),
			'The generator must name the files it opts in through wp_autoload_classmap_additional_files().'
		);

		$additional = wp_autoload_classmap_additional_files();

		$this->assertIsArray( $additional, 'wp_autoload_classmap_additional_files() must return an array.' );
		$this->assertContains(
			self::CLASS_FILE,
			$additional,
			self::CLASS_FILE . ' must be opted into the class map by name: it is outside the walked tree.'
		);
		$this->assertContains(
			self::AUTO_UPDATES_CLASS_FILE,
			$additional,
			self::AUTO_UPDATES_CLASS_FILE . ' must be opted into the class map by name as well.'
		);
	}

	/**
	 * Tests that the autoloader leaves every autoloader queued after it its turn.
	 *
	 * The autoloader answers only the names its map carries, and it has to hand every
	 * other name straight back so the rest of the SPL stack is still consulted. One
	 * that instead reported or failed on a name it does not own would take the whole
	 * stack down with it, and the names that suffer are the ones a plugin's own
	 * autoloader is registered for - loaded after wp-settings.php has run, and
	 * therefore always behind this one in the queue.
	 *
	 * The probe is registered last for that reason: it can only be reached by a name
	 * that has already passed through the core autoloader untouched.
	 */
	public function test_the_loader_leaves_later_autoloaders_their_turn() {
		/*
		 * Freshly named on every call, because an alias cannot be unregistered: a name
		 * reused by a second run of this test would already be declared, and the probe
		 * would never be reached to report that it had been.
		 */
		$class_name = 'WP_Site_Health_Loader_Probe_' . md5( __METHOD__ . microtime( true ) . mt_rand() );
		$calls      = 0;
		$probe      = static function ( $requested ) use ( $class_name, &$calls ) {
			if ( $requested !== $class_name ) {
				return;
			}

			++$calls;

			// An alias declares the requested name, which is all an autoloader has to do.
			class_alias( 'stdClass', $class_name );
		};

		spl_autoload_register( $probe );

		try {
			$resolved = class_exists( $class_name );
		} finally {
			spl_autoload_unregister( $probe );
		}

		$this->assertSame(
			1,
			$calls,
			'A name the core autoloader does not answer must still reach the autoloaders registered after it.'
		);
		$this->assertTrue(
			$resolved,
			'An autoloader registered after this one must still be able to resolve a name.'
		);
	}

	/**
	 * Tests that the autoloader checks a mapped file is there before including it.
	 *
	 * A missing or partly deployed file has to be a silent miss: the name is left to
	 * whatever else is registered, no warning carrying the absolute path of the install
	 * is emitted, and the autoloaders queued behind this one still get their turn. This
	 * class is the case that makes the guard matter most, because its file lives in a
	 * directory a hardened install may legitimately not deploy at all.
	 *
	 * The miss itself cannot be exercised from inside the test run. Making the file
	 * genuinely absent would mean removing a tracked source file mid-suite, and an
	 * unguarded `require_once` of a missing file is a compile-time fatal rather than a
	 * catchable error, so a regression would kill the runner instead of reporting itself.
	 * The guard is therefore asserted where it is written, against the statement actually
	 * shipped in `wp-includes/autoload.php`, so that removing it fails this test rather
	 * than only failing in production.
	 */
	public function test_the_loader_checks_the_file_exists_before_including_it() {
		$source = self::get_autoloader_function_source();

		$this->assertNotSame(
			'',
			$source,
			'wp-includes/autoload.php must declare wp_autoload_class().'
		);

		$include = strpos( $source, 'require_once' );

		$this->assertNotFalse(
			$include,
			'The autoloader must include a mapped file with require_once.'
		);

		$guard = strrpos( substr( $source, 0, $include ), 'file_exists(' );

		if ( false === $guard ) {
			$guard = strrpos( substr( $source, 0, $include ), 'is_file(' );
		}

		$this->assertNotFalse(
			$guard,
			'The autoloader must check that the mapped file is there, with file_exists() or is_file().'
		);
		$this->assertStringContainsString(
			'return',
			substr( $source, $guard, $include - $guard ),
			'A missing file must return rather than fall through to the include.'
		);
		$this->assertStringContainsString(
			'ABSPATH',
			$source,
			'The mapped path must be resolved against ABSPATH, which is what lets an entry reach outside wp-includes.'
		);
	}

	/**
	 * Returns the source of `wp_autoload_class()` as it is shipped, without its comments.
	 *
	 * `wp-includes/autoload.php` is tokenized rather than scanned as text, for two
	 * reasons. Comments are dropped, so the words the function explains itself with - it
	 * names `require_once` and `file_exists()` in prose - cannot be mistaken for the code
	 * that runs. And brace depth is counted over tokens, so a brace inside a comment or a
	 * string literal cannot end the declaration early.
	 *
	 * @return string Source of the declaration without its comments, or an empty string
	 *                when the file does not declare the function.
	 */
	private static function get_autoloader_function_source() {
		$tokens = token_get_all( file_get_contents( ABSPATH . WPINC . '/autoload.php' ) );
		$total  = count( $tokens );

		for ( $index = 0; $index < $total; $index++ ) {
			if ( ! is_array( $tokens[ $index ] ) || T_FUNCTION !== $tokens[ $index ][0] ) {
				continue;
			}

			for ( $cursor = $index + 1; $cursor < $total; $cursor++ ) {
				if ( is_array( $tokens[ $cursor ] ) && T_WHITESPACE === $tokens[ $cursor ][0] ) {
					continue;
				}

				if ( is_array( $tokens[ $cursor ] )
					&& T_STRING === $tokens[ $cursor ][0]
					&& 'wp_autoload_class' === $tokens[ $cursor ][1]
				) {
					return self::read_declaration_source( $tokens, $index, $total );
				}

				break;
			}
		}

		return '';
	}

	/**
	 * Reads the source of one declaration, from its keyword to the brace that closes it.
	 *
	 * Comments are left out and everything else is reproduced verbatim, so the result is
	 * the code the declaration actually runs.
	 *
	 * @param array $tokens Tokens of the whole file.
	 * @param int   $index  Index of the token opening the declaration.
	 * @param int   $total  Number of tokens.
	 * @return string Source of the declaration without its comments.
	 */
	private static function read_declaration_source( $tokens, $index, $total ) {
		$ignorable = array( T_COMMENT, T_DOC_COMMENT );
		$opening   = array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES );
		$source    = '';
		$depth     = 0;
		$entered   = false;

		for ( $cursor = $index; $cursor < $total; $cursor++ ) {
			if ( is_array( $tokens[ $cursor ] ) ) {
				if ( ! in_array( $tokens[ $cursor ][0], $ignorable, true ) ) {
					$source .= $tokens[ $cursor ][1];
				}

				/*
				 * A brace that opens string interpolation arrives as one of these tokens
				 * while the brace that closes it is a plain `}`, so it has to be counted
				 * or the depth would fall out of step.
				 */
				if ( in_array( $tokens[ $cursor ][0], $opening, true ) ) {
					++$depth;
					$entered = true;
				}

				continue;
			}

			$source .= $tokens[ $cursor ];

			if ( '{' === $tokens[ $cursor ] ) {
				++$depth;
				$entered = true;
				continue;
			}

			if ( '}' === $tokens[ $cursor ] ) {
				--$depth;

				if ( $entered && 0 === $depth ) {
					return $source;
				}
			}
		}

		return $source;
	}
}
