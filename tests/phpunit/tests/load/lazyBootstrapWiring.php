<?php

/**
 * Covers two pieces of wiring the bootstrap no longer performs on every request.
 *
 * Both were unconditional work whose result a profiled front-end page view never read:
 *
 * - The AI Client's WordPress-side wiring. `wp-settings.php` used to require the four
 *   adapter classes and then call `WP_AI_Client_Discovery_Strategy::init()`,
 *   `AiClient::setCache()` and `AiClient::setEventDispatcher()` on every request. Between
 *   the adapters, the six bundled interfaces they implement, and `AiClient` itself that
 *   was sixteen files parsed on the chance that something later in the request would
 *   generate content. `_wp_ai_client_load()` now does it on the first reference to a
 *   `WordPress\AiClient` or `WP_AI_Client_` name instead.
 *
 * - `WP_Recovery_Mode`'s email service. The constructor used to build it on every
 *   request, though it is only reached when a fatal error is being handled or recovery
 *   mode is being left.
 *
 * The first half of each behaviour - that the work left the bootstrap - is asserted by
 * tokenizing the file rather than by looking at what the current process happens to have
 * loaded, for the reason `Tests_Load_AdminOnlyBootstrap` sets out: the bootstrap runs
 * before PHPUnit starts collecting, and `get_included_files()` answers for the whole
 * process. The second half - that deferring did not make anything unreachable - is
 * asserted functionally, and in a way that does not depend on test order.
 *
 * @group load
 * @group autoload
 */
class Tests_Load_LazyBootstrapWiring extends WP_UnitTestCase {

	/**
	 * Tests that the bootstrap no longer wires the AI Client on every request.
	 *
	 * The three calls are looked for by name in `wp-settings.php`: none of them may
	 * remain, because there is no condition available in the bootstrap that could tell
	 * whether the request will reach the AI Client, which is why the work moved to an
	 * autoloader instead of behind an `if`.
	 */
	public function test_the_ai_client_wiring_left_the_bootstrap() {
		$bootstrap = file_get_contents( ABSPATH . 'wp-settings.php' );

		$this->assertNotFalse( $bootstrap, 'wp-settings.php must be readable.' );

		foreach ( array( 'AiClient::setCache', 'AiClient::setEventDispatcher', 'WP_AI_Client_Discovery_Strategy::init' ) as $call ) {
			$this->assertStringNotContainsString(
				$call,
				$bootstrap,
				"{$call}() must not run in the bootstrap: it loads the AI Client on requests that never use it."
			);
		}

		foreach ( array( 'class-wp-ai-client-cache.php', 'class-wp-ai-client-event-dispatcher.php', 'class-wp-ai-client-discovery-strategy.php', 'class-wp-ai-client-http-client.php' ) as $adapter ) {
			$this->assertStringNotContainsString(
				$adapter,
				$bootstrap,
				"The bootstrap must not require {$adapter}: it is loaded by _wp_ai_client_load() with the wiring that needs it."
			);
		}
	}

	/**
	 * Tests that the AI Client is wired by the time a reference to it resolves.
	 *
	 * This is what makes the deferral safe, and it holds whatever ran before it.
	 * `_wp_ai_client_load()` is registered ahead of the bundled prefix autoloader, so it
	 * is the first loader consulted for any `WordPress\AiClient` name: whichever earlier
	 * test, if any, first reached the client, it reached it through this function, and
	 * this function loads the adapters before it returns.
	 */
	public function test_the_ai_client_is_wired_on_first_reference() {
		$this->assertTrue(
			function_exists( '_wp_ai_client_load' ),
			'_wp_ai_client_load() must be declared: it carries the wiring the bootstrap gave up.'
		);

		$this->assertTrue(
			class_exists( 'WordPress\AiClient\AiClient' ),
			'WordPress\AiClient\AiClient must resolve on a request the bootstrap did not load it on.'
		);

		foreach ( array( 'WP_AI_Client_Cache', 'WP_AI_Client_Event_Dispatcher', 'WP_AI_Client_Discovery_Strategy', 'WP_AI_Client_HTTP_Client' ) as $adapter ) {
			$this->assertTrue(
				class_exists( $adapter, false ),
				"{$adapter} must be loaded once the AI Client has been reached, or the client would be running unwired."
			);
		}

		$this->assertTrue(
			class_exists( 'WordPress\AiClient\Providers\ProviderRegistry' ),
			'The provider registry must stay resolvable: the connector callbacks read it whenever a provider plugin has registered one.'
		);
	}

	/**
	 * Tests that the connector callbacks do not reach the AI Client to read an empty registry.
	 *
	 * A registry can only hold a provider if something called `AiClient::defaultRegistry()`
	 * to put one there, and that call cannot happen without `AiClient` being loaded. So
	 * `_wp_connectors_has_ai_registry()` answers the same question the three `init`
	 * callbacks used to answer by loading the client: false means the registry is provably
	 * empty and their loops would iterate nothing.
	 */
	public function test_the_connector_callbacks_probe_for_the_ai_client_without_loading_it() {
		$this->assertTrue(
			function_exists( '_wp_connectors_has_ai_registry' ),
			'_wp_connectors_has_ai_registry() must be declared: it is what keeps the connector callbacks off the AI Client.'
		);

		$connectors = file_get_contents( ABSPATH . WPINC . '/connectors.php' );

		$this->assertNotFalse( $connectors, 'connectors.php must be readable.' );

		$this->assertStringContainsString(
			'class_exists( AiClient::class, false )',
			$connectors,
			'The probe must disable autoloading, or it would load the very files it exists to avoid.'
		);

		foreach ( array( '_wp_connectors_init', '_wp_register_default_connector_settings', '_wp_connectors_pass_default_keys_to_ai_client' ) as $callback ) {
			$reflection = new ReflectionFunction( $callback );
			$lines      = file( $reflection->getFileName() );
			$body       = implode( '', array_slice( $lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1 ) );

			$this->assertStringContainsString(
				'_wp_connectors_has_ai_registry()',
				$body,
				"{$callback}() runs on init on every request, so it must consult the probe before reaching the registry."
			);
		}

		/*
		 * The three callbacks have already run on this request, at init. Whether the
		 * client is loaded by now depends on what else ran, so what is asserted is the
		 * invariant that holds either way: the connector list is built, and it is built
		 * from the built-in defaults regardless of which branch the probe took.
		 */
		$connector_list = wp_get_connectors();

		$this->assertIsArray( $connector_list, 'wp_get_connectors() must return an array.' );

		foreach ( array( 'anthropic', 'google', 'openai' ) as $connector_id ) {
			$this->assertArrayHasKey(
				$connector_id,
				$connector_list,
				"The built-in {$connector_id} connector must still be registered: the probe only skips the registry merge, never the defaults."
			);
		}
	}

	/**
	 * Tests that the recovery mode email service is built on first use, not on every request.
	 *
	 * The constructor is read rather than the whole class, because the point is where the
	 * service is created: `get_email_service()` naturally mentions it.
	 */
	public function test_the_recovery_mode_email_service_is_created_on_first_use() {
		$reflection = new ReflectionMethod( 'WP_Recovery_Mode', '__construct' );
		$lines      = file( $reflection->getFileName() );
		$body       = implode( '', array_slice( $lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1 ) );

		$this->assertStringNotContainsString(
			'WP_Recovery_Mode_Email_Service',
			$body,
			'WP_Recovery_Mode must not build its email service in the constructor: only handling a fatal error and leaving recovery mode reach it.'
		);

		$recovery_mode = new WP_Recovery_Mode();
		$property      = new ReflectionProperty( 'WP_Recovery_Mode', 'email_service' );

		$this->assertNull(
			$property->getValue( $recovery_mode ),
			'A freshly constructed WP_Recovery_Mode must not hold an email service yet.'
		);

		$getter = new ReflectionMethod( 'WP_Recovery_Mode', 'get_email_service' );

		$service = $getter->invoke( $recovery_mode );

		$this->assertInstanceOf(
			'WP_Recovery_Mode_Email_Service',
			$service,
			'get_email_service() must return the email service, so the deferral is invisible to the two callers that need it.'
		);

		$this->assertSame(
			$service,
			$getter->invoke( $recovery_mode ),
			'get_email_service() must return the same instance on every call, as the constructor-built property did.'
		);
	}

	/**
	 * Tests that the deferred email service stays reachable through the class map.
	 *
	 * The bootstrap no longer requires the file, so the class map entry is the only thing
	 * that resolves the name on a request that does reach recovery mode.
	 */
	public function test_the_recovery_mode_email_service_stays_reachable_through_the_class_map() {
		$bootstrap = file_get_contents( ABSPATH . 'wp-settings.php' );

		$this->assertNotFalse( $bootstrap, 'wp-settings.php must be readable.' );

		$this->assertStringNotContainsString(
			'class-wp-recovery-mode-email-service.php',
			$bootstrap,
			'The bootstrap must not require the email service: it is resolved from the class map when it is needed.'
		);

		$class_map = require ABSPATH . WPINC . '/autoload-classmap.php';

		$this->assertIsArray( $class_map, 'The generated class map must return an array.' );

		$this->assertArrayHasKey(
			'wp_recovery_mode_email_service',
			$class_map,
			'wp_recovery_mode_email_service must be in the class map, or deferring it would leave it unloadable.'
		);

		$this->assertFileIsReadable(
			ABSPATH . $class_map['wp_recovery_mode_email_service'],
			'The class map entry for wp_recovery_mode_email_service must name a readable file.'
		);

		$this->assertSame(
			wp_normalize_path( ABSPATH . $class_map['wp_recovery_mode_email_service'] ),
			wp_normalize_path( ( new ReflectionClass( 'WP_Recovery_Mode_Email_Service' ) )->getFileName() ),
			'WP_Recovery_Mode_Email_Service must be resolved from the file the class map names.'
		);
	}
}
