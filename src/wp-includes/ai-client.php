<?php
/**
 * WordPress AI Client API.
 *
 * @package WordPress
 * @subpackage AI
 * @since 7.0.0
 */

use WordPress\AiClient\AiClient;

/**
 * Creates a new AI prompt builder using the default provider registry.
 *
 * This is the main entry point for generating AI content in WordPress. It returns
 * a fluent builder that can be used to configure and execute AI prompts.
 *
 * The prompt can be provided as a simple string for basic text prompts, or as more
 * complex types for advanced use cases like multi-modal content or conversation history.
 *
 * @since 7.0.0
 *
 * @param string|MessagePart|Message|array|list<string|MessagePart|array>|list<Message>|null $prompt Optional. Initial prompt content.
 *                                                                                                   A string for simple text prompts,
 *                                                                                                   a MessagePart or Message object for
 *                                                                                                   structured content, an array for a
 *                                                                                                   message array shape, or a list of
 *                                                                                                   parts or messages for multi-turn
 *                                                                                                   conversations. Default null.
 * @return WP_AI_Client_Prompt_Builder The prompt builder instance.
 */
function wp_ai_client_prompt( $prompt = null ) {
	return new WP_AI_Client_Prompt_Builder( AiClient::defaultRegistry(), $prompt );
}

/**
 * Loads and configures the bundled AI Client the first time it is referenced.
 *
 * The AI Client needs three pieces of WordPress-side wiring before it can be used:
 * a PSR-16 cache, a PSR-14 event dispatcher, and a PSR-18 HTTP client discovery
 * strategy. Performing that wiring during the bootstrap made every request - front
 * end, admin, REST, and CLI alike - parse the four adapter classes below, the six
 * bundled interfaces they implement, and `AiClient` itself, on the chance that
 * something later in the request would generate content. A profiled front-end page
 * view resolved none of those names, so the wiring happens here instead, at the
 * moment the first `WordPress\AiClient` name is actually resolved.
 *
 * This runs as an autoloader rather than on a hook because there is no hook late
 * enough to be skippable and early enough to precede every caller: a provider plugin
 * may reach for the client at any point, including before `plugins_loaded`. An
 * autoloader is triggered by the reference itself, so the wiring is always in place
 * before the client is used, and never runs when it is not.
 *
 * `AiClient` is required directly rather than left to the bundled prefix autoloader
 * because this function is itself running inside an autoload for that same name when
 * the client is the first thing referenced, and PHP will not re-enter autoloading for
 * a name it is already resolving. Requiring the file is what an autoloader for that
 * name would do in any case, and it is what makes the three static calls below
 * reachable. Every other name the adapters need - the bundled interfaces they
 * implement, and the discovery classes `WP_AI_Client_Discovery_Strategy::init()`
 * reaches - resolves through the prefix autoloader registered immediately after this
 * function in `wp-settings.php`, which is a different name each time and therefore
 * autoloads normally.
 *
 * @since 7.0.0
 * @access private
 *
 * @param string $class_name Name of the class being autoloaded.
 */
function _wp_ai_client_load( $class_name ) {
	/*
	 * Both `WordPress\AiClient\` and `WordPress\AiClientDependencies\` share the first
	 * prefix. The second prefix keeps the four adapter classes resolvable on their own:
	 * each implements a bundled interface, so the class map generator declines to map
	 * them, and a reference that reaches for one directly rather than through the client
	 * would otherwise find no loader at all. The names the generator does map -
	 * WP_AI_Client_Prompt_Builder among them - are answered by the core autoloader, which
	 * is registered ahead of this one and therefore never reaches here.
	 */
	if ( 0 !== strncmp( $class_name, 'WordPress\\AiClient', 18 )
		&& 0 !== strncmp( $class_name, 'WP_AI_Client_', 13 )
	) {
		return;
	}

	static $loaded = false;

	if ( $loaded ) {
		return;
	}

	$loaded = true;

	require_once ABSPATH . WPINC . '/ai-client/adapters/class-wp-ai-client-http-client.php';
	require_once ABSPATH . WPINC . '/ai-client/adapters/class-wp-ai-client-cache.php';
	require_once ABSPATH . WPINC . '/ai-client/adapters/class-wp-ai-client-discovery-strategy.php';
	require_once ABSPATH . WPINC . '/ai-client/adapters/class-wp-ai-client-event-dispatcher.php';
	require_once ABSPATH . WPINC . '/php-ai-client/src/AiClient.php';

	WP_AI_Client_Discovery_Strategy::init();
	AiClient::setCache( new WP_AI_Client_Cache() );
	AiClient::setEventDispatcher( new WP_AI_Client_Event_Dispatcher() );
}
spl_autoload_register( '_wp_ai_client_load' );
