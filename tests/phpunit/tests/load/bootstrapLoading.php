<?php

/**
 * Unit tests for what the bootstrap loads in each request context.
 *
 * `wp-settings.php` parses `wp-admin/includes/class-wp-site-health.php` only where the
 * class is used: in the admin, while cron runs, for the REST routes that need it, or when
 * front-end code explicitly asks for the public class. Ordinary front-end requests retain
 * the weekly-event repair and a lazy cron handler without parsing the implementation.
 * This is invisible to every other test in the suite, because the suite bootstraps once,
 * as an admin-shaped request, before the first test runs. Reverting the conditions would
 * therefore leave the whole suite green.
 *
 * Each context is measured in a process of its own for that reason: whether a file is
 * loaded during the bootstrap can only be seen where it has not been loaded already.
 *
 * @package WordPress
 * @subpackage UnitTests
 *
 * @group load
 * @group bootstrap
 */
class Tests_Load_BootstrapLoading extends WP_UnitTestCase {

	/**
	 * The hooks the Site Health constructor registers.
	 *
	 * Deferring the class must defer these with it and nothing else, so the contexts that
	 * load it have to end up with all of them and the contexts that do not with none.
	 *
	 * @var string[]
	 */
	const SITE_HEALTH_HOOKS = array(
		'admin_body_class',
		'admin_enqueue_scripts',
		'wp_site_health_scheduled_check',
		'site_health_tab_content',
	);

	/**
	 * Tests that the front end neither loads nor instantiates Site Health.
	 *
	 * This is the saving the deferral exists for, and the assertion that fails if the
	 * condition in `wp-settings.php` is widened again.
	 */
	public function test_front_end_does_not_load_site_health() {
		$result = $this->probe( 'front-end' );

		$this->assertFalse(
			$result['declared_after'],
			'A front-end request must not load wp-admin/includes/class-wp-site-health.php.'
		);

		$this->assertFalse(
			$result['instantiated'],
			'A front-end request must not instantiate Site Health.'
		);

		$this->assertSame(
			array(),
			$this->registered_site_health_hooks( $result ),
			'A front-end request must not register any of the hooks the Site Health constructor adds.'
		);

		$this->assertSame(
			1,
			$result['schedule_attempts'],
			'A front-end request must repair a missing Site Health weekly event without loading the class.'
		);

		$this->assertCount( 1, $result['scheduled_events'], 'The missing-event repair must schedule exactly one event.' );
		$this->assertSame( 'wp_site_health_scheduled_check', $result['scheduled_events'][0]['hook'] );
		$this->assertSame( 'weekly', $result['scheduled_events'][0]['schedule'] );
		$this->assertSame( array(), $result['scheduled_events'][0]['args'] );
		$this->assertEqualsWithDelta(
			time() + DAY_IN_SECONDS,
			$result['scheduled_events'][0]['timestamp'],
			5,
			'The repaired weekly event must start one day after the request.'
		);

		$this->assertTrue(
			$result['cron_handler'],
			'A front-end request must register a lazy handler for the Site Health scheduled check.'
		);
	}

	/**
	 * Tests that the Site Health admin classes remain available through lazy resolution.
	 */
	public function test_front_end_resolves_site_health_classes_only_when_asked() {
		$result = $this->probe( 'front-end-class-resolution' );

		$this->assertFalse( $result['declared_after'], 'The front-end bootstrap must leave Site Health unloaded.' );
		$this->assertIsArray( $result['class_resolution'], 'The probe must report the explicit class-resolution request.' );
		$this->assertTrue( $result['class_resolution']['main'], 'class_exists() must resolve WP_Site_Health on the front end.' );
		$this->assertTrue( $result['class_resolution']['auto_updates'], 'class_exists() must resolve WP_Site_Health_Auto_Updates on the front end.' );
		$this->assertTrue( $result['class_resolution']['main_file_loaded'], 'Resolving WP_Site_Health must load its exact admin class file.' );
		$this->assertTrue( $result['class_resolution']['auto_file_loaded'], 'Resolving WP_Site_Health_Auto_Updates must load its exact admin class file.' );
		$this->assertSame(
			$result['class_resolution']['files_before'] + 2,
			$result['class_resolution']['files_after'],
			'Explicitly resolving both focused classes must load exactly their two files.'
		);
	}

	/**
	 * Tests that dispatching the Site Health cron hook from the front end runs the real handler.
	 */
	public function test_front_end_lazily_dispatches_the_site_health_cron_handler() {
		$result = $this->probe( 'front-end-cron-dispatch' );

		$this->assertIsArray( $result['cron_dispatch'], 'The probe must report the front-end cron dispatch.' );
		$this->assertFalse( $result['cron_dispatch']['declared_before'], 'Site Health must still be unloaded before the hook fires.' );
		$this->assertTrue( $result['cron_dispatch']['declared_after'], 'The lazy hook must resolve Site Health when it fires.' );
		$this->assertTrue( $result['cron_dispatch']['instantiated_after'], 'The lazy hook must instantiate Site Health.' );
		$this->assertTrue( $result['cron_dispatch']['instance_handler'], 'The Site Health instance must register its real scheduled-check handler.' );
		$this->assertSame(
			wp_json_encode(
				array(
					'good'        => 0,
					'recommended' => 0,
					'critical'    => 0,
				)
			),
			$result['cron_dispatch']['status_result'],
			'The real scheduled-check handler must run through completion.'
		);
	}

	/**
	 * Tests that the contexts Site Health is needed in still load and instantiate it.
	 *
	 * A saving that also removed the class from the admin, or from cron, would be a
	 * regression rather than an optimization: the admin screens read the instance, and the
	 * weekly check is dispatched through the hook its constructor registers.
	 *
	 * @dataProvider data_contexts_that_load_site_health
	 *
	 * @param string $context Request context to load WordPress in.
	 */
	public function test_context_loads_and_instantiates_site_health( $context ) {
		$result = $this->probe( $context );

		$this->assertTrue(
			$result['declared_after'],
			"The {$context} context must load wp-admin/includes/class-wp-site-health.php."
		);

		$this->assertTrue(
			$result['instantiated'],
			"The {$context} context must instantiate Site Health, so that its scheduled check can fire."
		);

		$this->assertSame(
			self::SITE_HEALTH_HOOKS,
			$this->registered_site_health_hooks( $result ),
			"The {$context} context must register every hook the Site Health constructor adds."
		);
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public function data_contexts_that_load_site_health() {
		return array(
			'the admin' => array( 'admin' ),
			'cron'      => array( 'cron' ),
		);
	}

	/**
	 * Tests that every request context retains the complete plugin API.
	 *
	 * wp-admin/includes/plugin.php is required unconditionally, and deliberately so:
	 * [59488] made it a direct require because plugins call get_plugin_data() and its
	 * neighbours from their own file scope without first checking that they exist, so
	 * deferring it reintroduces the fatal errors #62244 fixed. This asserts the
	 * guarantee rather than the saving.
	 *
	 * @dataProvider data_contexts_that_load_active_plugins
	 *
	 * @param string $context Request context to load WordPress in.
	 */
	public function test_context_keeps_the_plugin_api_available_to_active_plugins( $context ) {
		$result = $this->probe( $context );

		$this->assertTrue( $result['plugin']['api_file_loaded'], "The {$context} context must load wp-admin/includes/plugin.php." );
		$this->assertTrue( $result['plugin']['api_available'], "The {$context} context must declare get_plugin_data()." );

		foreach ( $result['plugin']['observations'] as $observation ) {
			$this->assertTrue( $observation['get_file_data'], 'Every active plugin must have the lightweight file-header API available.' );
			$this->assertTrue( $observation['get_plugin_data'], "Every active plugin in the {$context} context must have the complete plugin API available." );
		}
	}

	/**
	 * Tests that direct file-header reads preserve plugin translation registration.
	 *
	 * @dataProvider data_contexts_that_load_active_plugins
	 *
	 * @param string $context Request context to load WordPress in.
	 */
	public function test_context_registers_explicit_and_fallback_plugin_textdomains( $context ) {
		$result = $this->probe( $context );

		$this->assertSame(
			array(
				'bootstrap-explicit-domain' => wp_normalize_path( realpath( DIR_TESTDATA . '/plugins/bootstrap-explicit' ) . '/translations' ),
				'bootstrap-fallback'        => wp_normalize_path( realpath( DIR_TESTDATA . '/plugins/bootstrap-fallback' ) . '/languages' ),
			),
			array_map( 'wp_normalize_path', $result['plugin']['translation_paths'] ),
			"The {$context} context must preserve explicit Text Domain headers, directory-slug fallbacks, and Domain Path headers."
		);
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public function data_contexts_that_load_active_plugins() {
		return array(
			'the front end'  => array( 'front-end' ),
			'the admin'      => array( 'admin' ),
			'cron'           => array( 'cron' ),
			'a REST request' => array( 'rest' ),
		);
	}

	/**
	 * Tests that a REST request loads Site Health only once a server is created.
	 *
	 * `rest_api_init` is fired from one place, inside `rest_get_server()`, and that is the
	 * only time the routes are registered. Until then a REST request costs a front-end
	 * request, and the class is still unloaded.
	 */
	public function test_rest_request_loads_site_health_with_its_server() {
		$result = $this->probe( 'rest' );

		$this->assertFalse(
			$result['declared_after'],
			'Bootstrapping a REST request must not load Site Health on its own.'
		);

		$this->assertIsArray( $result['rest'], 'The probe must report on the REST server it created.' );

		$this->assertFalse(
			$result['rest']['declared_before_server'],
			'Site Health must still be unloaded immediately before the REST server is created.'
		);

		$this->assertTrue(
			$result['rest']['declared_after_server'],
			'Creating the REST server must load Site Health, which its routes are registered with.'
		);

		$this->assertTrue(
			$result['rest']['instantiated_after_server'],
			'Registering the REST routes must instantiate Site Health.'
		);

		$this->assertSame(
			self::SITE_HEALTH_HOOKS,
			$this->registered_site_health_hooks( $result['rest'], 'hooks_after_server' ),
			'Loading Site Health for the REST routes must register the same hooks as loading it anywhere else.'
		);
	}

	/**
	 * Tests that Site Health stays deferred until something references it, and resolves then.
	 *
	 * There is no callback that preloads the class: the routes are registered on
	 * `rest_api_init` at priority 99 and referencing the name there is what loads it through
	 * the class map. Both halves of that are asserted, because either one alone would pass on
	 * a broken tree. That no priority before 99 has the class declared is what proves the
	 * deferral is real rather than undone by something loading the file early; that a callback
	 * at one of those priorities can still resolve the name is what proves the deferral costs
	 * a plugin nothing, since a plugin's own `rest_api_init` callback is exactly the code that
	 * would otherwise be broken by it.
	 */
	public function test_site_health_stays_deferred_until_the_rest_routes_reference_it() {
		$result = $this->probe( 'rest' );

		$this->assertIsArray( $result['rest'], 'The probe must report on the REST server it created.' );

		$this->assertSame(
			array( 1, 50, 98 ),
			array_map( 'intval', array_keys( $result['rest']['seen_at_priority'] ) ),
			'Every callback the probe added to rest_api_init must have run.'
		);

		foreach ( $result['rest']['seen_at_priority'] as $priority => $declared ) {
			$this->assertFalse(
				$declared,
				"Site Health must still be undeclared for a rest_api_init callback at priority {$priority}, which is what makes the deferral real."
			);
		}

		$this->assertTrue(
			$result['rest']['resolvable_at_priority_98'],
			'A rest_api_init callback that references WP_Site_Health must resolve it through the class map.'
		);
	}

	/**
	 * Tests that deferring Site Health still leaves its REST routes registered.
	 *
	 * The routes are the observable end of the deferral: if the class were not loaded in
	 * time, registering them would fail, and the only sign of it in a passing suite would
	 * be their absence.
	 */
	public function test_site_health_rest_routes_are_registered() {
		$result = $this->probe( 'rest' );

		$this->assertIsArray( $result['rest'], 'The probe must report on the REST server it created.' );

		$this->assertGreaterThan(
			0,
			$result['rest']['site_health_routes'],
			'The Site Health REST routes must be registered.'
		);

		$this->assertGreaterThan(
			$result['rest']['site_health_routes'],
			$result['rest']['routes'],
			'The REST server must register the rest of the core routes alongside the Site Health ones.'
		);
	}

	/**
	 * Tests that the class map autoloader is registered in every context.
	 *
	 * It is required before the `SHORTINIT` bail, so that a name deferred out of the
	 * bootstrap is resolvable even where almost nothing else is loaded.
	 *
	 * @dataProvider data_all_contexts
	 *
	 * @param string $context Request context to load WordPress in.
	 */
	public function test_autoloader_is_registered_in_every_context( $context ) {
		$result = $this->probe( $context );

		$this->assertTrue(
			$result['autoloader_registered'],
			"The core class map autoloader must be registered in the {$context} context."
		);
	}

	/**
	 * Tests that a minimal load neither reaches Site Health nor loses the autoloader.
	 *
	 * `SHORTINIT` returns before the theme is set up, which is where Site Health is
	 * loaded, so the class must be absent. The autoloader is registered before that
	 * point, so it must still be there.
	 */
	public function test_shortinit_registers_the_autoloader_without_site_health() {
		$result = $this->probe( 'shortinit' );

		$this->assertTrue(
			$result['autoloader_registered'],
			'A SHORTINIT load must register the core class map autoloader.'
		);

		$this->assertFalse(
			$result['declared_after'],
			'A SHORTINIT load must not load Site Health.'
		);

		$this->assertLessThan(
			$this->probe( 'front-end' )['files'],
			$result['files'],
			'A SHORTINIT load must load fewer files than a front-end request.'
		);
	}

	/**
	 * Tests that the front end loads no more files than the contexts that add to it.
	 *
	 * The file count is the measurement the deferral is made for, so it is asserted as an
	 * ordering rather than as a number: the front end is the floor, and every context that
	 * loads Site Health is above it.
	 *
	 * @dataProvider data_contexts_that_load_site_health
	 *
	 * @param string $context Request context to load WordPress in.
	 */
	public function test_front_end_loads_fewer_files_than_the_contexts_that_load_site_health( $context ) {
		$front_end = $this->probe( 'front-end' );
		$result    = $this->probe( $context );

		$this->assertGreaterThan(
			0,
			$front_end['files'],
			'The probe must report how many files a front-end request loads.'
		);

		$this->assertLessThan(
			$result['files'],
			$front_end['files'],
			"A front-end request must load fewer files than the {$context} context, which loads Site Health as well."
		);
	}

	/**
	 * Tests that the deferral holds on the installation the suite is running against.
	 *
	 * The probe is handed the same Multisite configuration as the caller, so this covers
	 * single site when the suite runs single site and Multisite when it runs Multisite,
	 * rather than skipping either.
	 */
	public function test_probe_runs_against_the_same_installation_as_the_suite() {
		$result = $this->probe( 'front-end' );

		$this->assertSame(
			is_multisite(),
			$result['multisite'],
			'The probe must load the same installation the suite is running against.'
		);
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public function data_all_contexts() {
		return array(
			'the front end'  => array( 'front-end' ),
			'the admin'      => array( 'admin' ),
			'cron'           => array( 'cron' ),
			'a REST request' => array( 'rest' ),
			'a minimal load' => array( 'shortinit' ),
		);
	}

	/**
	 * Returns the Site Health hooks a probe result reports as registered.
	 *
	 * @param array  $result Decoded probe result.
	 * @param string $key    Optional. Which set of hooks to read. Default 'hooks'.
	 * @return string[] Hook names, in the order they are declared in.
	 */
	private function registered_site_health_hooks( array $result, $key = 'hooks' ) {
		$registered = array();

		foreach ( self::SITE_HEALTH_HOOKS as $hook_name ) {
			if ( ! empty( $result[ $key ][ $hook_name ] ) ) {
				$registered[] = $hook_name;
			}
		}

		return $registered;
	}

	/**
	 * Loads WordPress in one request context, in a fresh process, and returns what it saw.
	 *
	 * Results are memoized for the run: each context costs a full bootstrap, and every
	 * test here asks about the same handful of them.
	 *
	 * @param string $context Request context to load WordPress in.
	 * @return array Decoded probe result.
	 */
	private function probe( $context ) {
		static $results = array();

		if ( isset( $results[ $context ] ) ) {
			return $results[ $context ];
		}

		$configuration = defined( 'WP_TESTS_CONFIG_FILE_PATH' )
			? WP_TESTS_CONFIG_FILE_PATH
			: dirname( ABSPATH ) . '/wp-tests-config.php';

		$this->assertFileIsReadable( $configuration, 'The test configuration must be readable by the probe.' );

		$arguments = array( $configuration, $context );

		/*
		 * Handed down so that the probe loads the installation the suite is running
		 * against, which is what makes this cover Multisite without a second harness.
		 */
		foreach ( array( 'MULTISITE', 'SUBDOMAIN_INSTALL', 'DOMAIN_CURRENT_SITE', 'PATH_CURRENT_SITE', 'SITE_ID_CURRENT_SITE', 'BLOG_ID_CURRENT_SITE' ) as $constant ) {
			if ( ! defined( $constant ) ) {
				continue;
			}

			$value = constant( $constant );

			if ( is_bool( $value ) ) {
				$value = $value ? 'true' : 'false';
			}

			$arguments[] = $constant . '=' . $value;
		}

		$command = array_merge(
			array(
				WP_PHP_BINARY,
				'-d',
				'error_reporting=-1',
				'-d',
				'display_errors=STDERR',
				'-d',
				'log_errors=0',
				DIR_TESTDATA . '/isolated/bootstrap-context-probe.php',
			),
			$arguments
		);

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$process = proc_open( $command, $descriptors, $pipes );

		$this->assertIsResource( $process, 'The probe process must start.' );

		// The probe reads nothing, and an open pipe would keep it waiting for input.
		fclose( $pipes[0] );

		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );

		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$exit_code = proc_close( $process );

		$this->assertSame( '', $stderr, "Loading WordPress in the {$context} context must report nothing on standard error." );
		$this->assertSame( 0, $exit_code, "Loading WordPress in the {$context} context must succeed." );

		$decoded = json_decode( $stdout, true );

		$this->assertIsArray( $decoded, "The probe must report the {$context} context as a JSON object. Reported: {$stdout}" );
		$this->assertSame( $context, $decoded['context'], 'The probe must report on the context it was asked about.' );

		$results[ $context ] = $decoded;

		return $decoded;
	}
}
