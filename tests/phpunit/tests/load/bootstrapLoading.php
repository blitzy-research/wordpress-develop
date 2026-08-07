<?php

/**
 * Unit tests for what the bootstrap loads in each request context.
 *
 * `wp-settings.php` no longer requires most class files: they are reached through the
 * generated class map the first time a name is referenced. Whether that is true of a given
 * file can only be observed in a process that has not loaded it yet, and the suite
 * bootstraps once, as an admin-shaped request, before the first test runs. So re-adding a
 * require for a mapped file, or referencing a deferred class during the bootstrap, would
 * leave the whole suite green. Each context is therefore measured in a process of its own.
 *
 * Site Health is the one file this suite pins in the opposite direction. It is 131,241 bytes
 * and is the largest item still loaded on every request, and it stays that way deliberately:
 * base builds the instance unconditionally at the end of `wp-settings.php`, and an earlier
 * revision of the deferral work put that construction behind
 * `is_admin() || wp_doing_cron()`, which changed observable behaviour on the two paths where
 * neither predicate holds -- `ALTERNATE_WP_CRON`, which runs due events inside an ordinary
 * front-end request, and WP-CLI, which dispatches from a bootstrap that is neither -- and
 * changed the registered callback's identity and priority even where it did run. The
 * construction was restored byte-for-byte to base, and the tests below pin what must be true
 * of it so that the same unsafe deferral cannot be reintroduced without one of them failing.
 *
 * What the deferral did buy is asserted as the set of mapped files the bootstrap loads
 * anyway, which is pinned exactly rather than counted.
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
	 * Constructing the class must register all of them, and a context that constructs it must
	 * therefore end up with all of them rather than with some.
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
	 * The registration base makes on the Site Health weekly check.
	 *
	 * All three fields are observable to a site: `has_action()` reports the priority,
	 * `remove_action()` needs the identity, and the argument count decides what the callback
	 * receives. All three were changed by the deferral that had to be rolled back, so all three
	 * are pinned rather than the presence of the hook alone.
	 *
	 * @var array
	 */
	const SITE_HEALTH_CRON_CALLBACK = array(
		'priority'      => 10,
		'identity'      => 'WP_Site_Health::wp_cron_scheduled_check',
		'accepted_args' => 1,
	);

	/**
	 * The full request contexts, which differ from a minimal load rather than from each other.
	 *
	 * @var string[]
	 */
	const FULL_CONTEXTS = array( 'front-end', 'admin', 'cron', 'rest' );

	/**
	 * The further mapped files a Multisite bootstrap reaches.
	 *
	 * Multisite resolves the network and the site the request belongs to before the theme is set
	 * up. That reaches `WP_Network` and `WP_Site` always, and reaches the two queries and the
	 * meta query behind them only when the lookup is not answered from the object cache, so the
	 * set is an allowance rather than an expectation: nothing outside it may be loaded, and it is
	 * not required that all of it is.
	 *
	 * @var string[]
	 */
	const MULTISITE_MAPPED_FILES = array(
		'wp-includes/class-wp-meta-query.php',
		'wp-includes/class-wp-network-query.php',
		'wp-includes/class-wp-network.php',
		'wp-includes/class-wp-site-query.php',
		'wp-includes/class-wp-site.php',
	);

	/**
	 * The mapped files the bootstrap loads anyway, as paths relative to the WordPress root.
	 *
	 * Every value in the generated class map names a file the bootstrap stopped requiring, so
	 * this is the complete list of names something reaches during the bootstrap regardless.
	 * The reason each one is reached is given above the test that asserts this set.
	 *
	 * @var string[]
	 */
	const EAGER_MAPPED_FILES = array(
		'wp-admin/includes/class-wp-site-health.php',
		'wp-includes/class-wp-walker.php',
		'wp-includes/class-wp-widget.php',
		'wp-includes/widgets/class-wp-nav-menu-widget.php',
		'wp-includes/widgets/class-wp-widget-archives.php',
		'wp-includes/widgets/class-wp-widget-block.php',
		'wp-includes/widgets/class-wp-widget-calendar.php',
		'wp-includes/widgets/class-wp-widget-categories.php',
		'wp-includes/widgets/class-wp-widget-custom-html.php',
		'wp-includes/widgets/class-wp-widget-links.php',
		'wp-includes/widgets/class-wp-widget-media-audio.php',
		'wp-includes/widgets/class-wp-widget-media-gallery.php',
		'wp-includes/widgets/class-wp-widget-media-image.php',
		'wp-includes/widgets/class-wp-widget-media-video.php',
		'wp-includes/widgets/class-wp-widget-media.php',
		'wp-includes/widgets/class-wp-widget-meta.php',
		'wp-includes/widgets/class-wp-widget-pages.php',
		'wp-includes/widgets/class-wp-widget-recent-comments.php',
		'wp-includes/widgets/class-wp-widget-recent-posts.php',
		'wp-includes/widgets/class-wp-widget-rss.php',
		'wp-includes/widgets/class-wp-widget-search.php',
		'wp-includes/widgets/class-wp-widget-tag-cloud.php',
		'wp-includes/widgets/class-wp-widget-text.php',
	);

	/**
	 * Tests that every context registers the Site Health weekly check exactly as base does.
	 *
	 * This is the assertion that fails if the construction at the end of `wp-settings.php` is
	 * made conditional again. The identity and the priority are what an earlier revision
	 * changed, so they are compared as a whole registration rather than through `has_action()`,
	 * which would still report a callback after either had moved.
	 *
	 * @dataProvider data_full_contexts
	 *
	 * @param string $context Request context to load WordPress in.
	 */
	public function test_every_context_registers_the_site_health_check_exactly_as_base_does( $context ) {
		$result = $this->probe( $context );

		$this->assertTrue(
			$result['cron_handler'],
			"The {$context} context must register a handler for the Site Health scheduled check."
		);

		$this->assertSame(
			array( self::SITE_HEALTH_CRON_CALLBACK ),
			$result['cron_callbacks'],
			"The {$context} context must register exactly base's callback, at base's priority, taking base's arguments."
		);
	}

	/**
	 * Tests that every context repairs a missing weekly event on the same terms.
	 *
	 * The event is what the construction exists for, so its absence being repaired is asserted
	 * alongside the registration rather than assumed from it.
	 *
	 * @dataProvider data_full_contexts
	 *
	 * @param string $context Request context to load WordPress in.
	 */
	public function test_every_context_repairs_a_missing_weekly_event( $context ) {
		$result = $this->probe( $context );

		$this->assertSame(
			1,
			$result['schedule_attempts'],
			"The {$context} context must schedule the missing Site Health weekly event exactly once."
		);

		$this->assertCount( 1, $result['scheduled_events'], 'The missing-event repair must schedule exactly one event.' );
		$this->assertSame( 'wp_site_health_scheduled_check', $result['scheduled_events'][0]['hook'] );
		$this->assertSame( 'weekly', $result['scheduled_events'][0]['schedule'] );
		$this->assertSame( array(), $result['scheduled_events'][0]['args'] );
		$this->assertEqualsWithDelta(
			time() + DAY_IN_SECONDS,
			$result['scheduled_events'][0]['timestamp'],
			60,
			'The repaired weekly event must start one day after the request.'
		);
	}

	/**
	 * Tests that the Site Health helper class is reached only when it is asked for.
	 *
	 * `WP_Site_Health` itself is declared by the bootstrap, because the bootstrap constructs
	 * it. `WP_Site_Health_Auto_Updates` is not: it is referenced from inside a single method,
	 * so it stays deferred on every request that does not run a site status check. Resolving
	 * it must load exactly its own file and nothing else, which is what says the class map
	 * resolved it rather than something having required a wp-admin include on the way.
	 */
	public function test_the_site_health_helper_class_resolves_only_when_asked() {
		$result = $this->probe( 'front-end-class-resolution' );

		$this->assertTrue( $result['declared_after'], 'The bootstrap constructs Site Health, so its class must be declared.' );
		$this->assertIsArray( $result['class_resolution'], 'The probe must report the explicit class-resolution request.' );
		$this->assertTrue( $result['class_resolution']['main'], 'class_exists() must resolve WP_Site_Health, whatever case it is written in.' );
		$this->assertTrue( $result['class_resolution']['auto_updates'], 'class_exists() must resolve WP_Site_Health_Auto_Updates on the front end.' );
		$this->assertTrue( $result['class_resolution']['main_file_loaded'], 'WP_Site_Health must come from its exact admin class file.' );
		$this->assertTrue( $result['class_resolution']['auto_file_loaded'], 'Resolving WP_Site_Health_Auto_Updates must load its exact admin class file.' );
		$this->assertSame(
			$result['class_resolution']['files_before'] + 1,
			$result['class_resolution']['files_after'],
			'Resolving the deferred helper must load exactly its one file.'
		);
	}

	/**
	 * Tests that dispatching the Site Health cron hook runs the real handler to completion.
	 *
	 * The registration is only worth pinning if the callback behind it works, and a handler
	 * that resolved but failed would look identical to one that never ran. The stored status
	 * result is read for that reason: producing it is the last thing the handler does.
	 */
	public function test_dispatching_the_scheduled_check_runs_the_real_handler() {
		$result = $this->probe( 'front-end-cron-dispatch' );

		$this->assertIsArray( $result['cron_dispatch'], 'The probe must report the cron dispatch.' );
		$this->assertTrue( $result['cron_dispatch']['declared_before'], 'The bootstrap constructs Site Health, so it is declared before the hook fires.' );
		$this->assertTrue( $result['cron_dispatch']['instantiated_after'], 'The dispatch must run against a constructed Site Health.' );
		$this->assertTrue( $result['cron_dispatch']['instance_handler'], 'The Site Health instance must be the thing registered on the hook.' );
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
	 * Data provider.
	 *
	 * @return array[]
	 */
	public function data_full_contexts() {
		$contexts = array();

		foreach ( self::FULL_CONTEXTS as $context ) {
			$contexts[ $context ] = array( $context );
		}

		return $contexts;
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
		return $this->data_full_contexts();
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
	 * Tests that creating the REST server registers the routes of every core namespace.
	 *
	 * `rest_api_init` is fired from one place, inside `rest_get_server()`, and that is the only
	 * time the routes are registered. Every controller class the routes need is deferred, so
	 * registration is the point at which the class map has to answer for all of them at once:
	 * a name it failed to resolve would not raise anything visible here, it would simply leave
	 * that namespace's routes missing. The inventory is therefore asserted per namespace, so a
	 * namespace that disappeared cannot be masked by another that grew.
	 */
	public function test_creating_the_rest_server_registers_every_core_namespace() {
		$result = $this->probe( 'rest' );

		$this->assertIsArray( $result['rest'], 'The probe must report on the REST server it created.' );
		$this->assertIsArray( $result['rest']['route_list'], 'The probe must report the routes it registered.' );

		$counts = array();

		foreach ( $result['rest']['route_list'] as $route ) {
			$segments  = explode( '/', trim( $route, '/' ) );
			$namespace = count( $segments ) > 1 ? $segments[0] . '/' . $segments[1] : '/';

			$counts[ $namespace ] = isset( $counts[ $namespace ] ) ? $counts[ $namespace ] + 1 : 1;
		}

		ksort( $counts );

		$this->assertSame(
			array(
				'/'                  => 1,
				'batch/v1'           => 1,
				'oembed/1.0'         => 3,
				'wp-abilities/v1'    => 6,
				'wp-block-editor/v1' => 4,
				'wp-site-health/v1'  => 8,
				'wp-sync/v1'         => 2,
				'wp/v2'              => 106,
			),
			$counts,
			'Every core namespace must register the same routes it registered before the controller classes were deferred.'
		);
	}

	/**
	 * Tests that the REST controllers stay deferred until the routes reference them.
	 *
	 * The controllers are the largest cluster the bootstrap stopped requiring -- 57 files under
	 * `wp-includes/rest-api/` -- and their deferral is only real if nothing loads them earlier.
	 * `create_initial_rest_routes()` runs on `rest_api_init` at priority 99, so a callback at
	 * any earlier priority must find the name still undeclared, and must still be able to
	 * resolve it, because a plugin's own `rest_api_init` callback is exactly the code a broken
	 * deferral would break.
	 */
	public function test_the_rest_controllers_stay_deferred_until_the_routes_reference_them() {
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
				"A REST controller must still be undeclared for a rest_api_init callback at priority {$priority}, which is what makes the deferral real."
			);
		}

		$this->assertTrue(
			$result['rest']['resolvable_at_priority_98'],
			'A rest_api_init callback that references a REST controller must resolve it through the class map.'
		);
	}

	/**
	 * Tests that the bootstrap loads exactly the mapped files it is documented to load.
	 *
	 * This is the assertion the deferral is actually made for, and it is expressed as the set
	 * of exceptions rather than as a file count for two reasons: a count says a regression
	 * happened without saying where, and a count can be held constant by a file that started
	 * being loaded and another that stopped. Every entry below is a mapped class the bootstrap
	 * still reaches, and each one is reached for a reason:
	 *
	 * - The twenty widget classes are instantiated by `wp_widgets_init()`, which runs on
	 *   `init` at priority 1, and `register_widget()` takes an instance.
	 * - `WP_Widget` is compiled as the parent of each of those widget classes, and `Walker`
	 *   as the parent of the eagerly required `Walker_CategoryDropdown`, both through the map
	 *   rather than through a second require.
	 * - `WP_Site_Health` is constructed unconditionally, as base constructs it, and the
	 *   require guarding it sits behind `class_exists()`, which the map answers first.
	 *
	 * A file the bootstrap requires unconditionally is not in the map at all, because the
	 * generator leaves out what nothing has to resolve. Restoring a require therefore removes
	 * an entry from this set rather than adding one, which is why the classes the bootstrap
	 * instantiates itself are absent from it. A change that defers one of those requires
	 * again, or that references a deferred class during the bootstrap, adds an entry here and
	 * fails with the file named.
	 */
	public function test_the_bootstrap_loads_only_the_mapped_files_it_documents() {
		$loaded = $this->probe( 'front-end' )['eager_mapped_files'];

		/*
		 * Split into two directions rather than compared as one set, because Multisite adds to
		 * the set and does so partly on demand: the network and site classes below are reached
		 * by the network lookup a Multisite bootstrap performs, and the queries among them are
		 * reached only when that lookup is not answered from cache. So nothing outside the
		 * documented union may be loaded, and everything in the single-site set must be.
		 */
		$this->assertSame(
			array(),
			array_values( array_diff( $loaded, self::EAGER_MAPPED_FILES, self::MULTISITE_MAPPED_FILES ) ),
			'The bootstrap loaded a mapped file that is not in the documented set, so a require was re-added or a deferred class is referenced during the bootstrap.'
		);

		$this->assertSame(
			array(),
			array_values( array_diff( self::EAGER_MAPPED_FILES, $loaded ) ),
			'The bootstrap stopped loading a mapped file it is documented to load, so the documented set is out of date.'
		);

		/*
		 * Branching rather than skipping, so that both installations assert something here: the
		 * single-site run requires the Multisite allowance to be unused, and the Multisite run
		 * requires it to be used, which is what keeps the allowance from silently going stale.
		 */
		if ( is_multisite() ) {
			$this->assertNotEmpty(
				array_intersect( $loaded, self::MULTISITE_MAPPED_FILES ),
				'A Multisite bootstrap must reach its network and site classes, or the allowance for them is unused.'
			);
		} else {
			$this->assertSame(
				array(),
				array_values( array_intersect( $loaded, self::MULTISITE_MAPPED_FILES ) ),
				'A single-site bootstrap must not reach the Multisite network and site classes.'
			);
		}
	}

	/**
	 * Tests that the bootstrap loads the same files whatever request context it runs in.
	 *
	 * `wp-settings.php` is shared by every entry point, and nothing in it is supposed to load
	 * a different set of files for the admin, for cron or for a REST request: the admin's extra
	 * includes come from `wp-admin/admin.php`, which runs afterwards. Comparing the contexts
	 * against each other is what catches a context-dependent require being introduced, which a
	 * single-context measurement cannot see.
	 *
	 * @dataProvider data_full_contexts_after_the_first
	 *
	 * @param string $context Request context to load WordPress in.
	 */
	public function test_every_full_context_bootstraps_the_same_files( $context ) {
		$front_end = $this->probe( 'front-end' );
		$result    = $this->probe( $context );

		$this->assertGreaterThan(
			0,
			$front_end['files'],
			'The probe must report how many files a front-end request loads.'
		);

		$this->assertSame(
			$front_end['files'],
			$result['files'],
			"The {$context} context must bootstrap the same number of files as a front-end request."
		);

		$this->assertSame(
			$front_end['eager_mapped_files'],
			$result['eager_mapped_files'],
			"The {$context} context must load the same mapped files as a front-end request."
		);
	}

	/**
	 * Data provider.
	 *
	 * The front end is the baseline the others are compared against, so it is not a case.
	 *
	 * @return array[]
	 */
	public function data_full_contexts_after_the_first() {
		$contexts = $this->data_full_contexts();

		unset( $contexts['front-end'] );

		return $contexts;
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
	 * Tests that the bootstrap loads far fewer files than the class map could reach.
	 *
	 * The deferral is only real to the extent that most of the map stays unread, so the two are
	 * compared: the mapped files the bootstrap loads must be a minority of the map. Asserted as
	 * a proportion rather than as a count, because the map grows with the tree and the property
	 * that matters is the ratio rather than either number.
	 */
	public function test_the_bootstrap_reaches_a_minority_of_the_class_map() {
		$classmap = require ABSPATH . WPINC . '/autoload-classmap.php';

		$this->assertIsArray( $classmap, 'The generated class map must return an array.' );

		$mapped = count( array_unique( array_values( $classmap ) ) );
		$eager  = count( self::EAGER_MAPPED_FILES );

		$this->assertGreaterThan( 0, $mapped, 'The generated class map must map at least one file.' );

		$this->assertLessThan(
			$mapped / 2,
			$eager,
			"The bootstrap loads {$eager} of the {$mapped} mapped files, which is no longer a minority of them."
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
