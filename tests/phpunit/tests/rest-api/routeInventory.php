<?php

/**
 * Tests that the REST route inventory survives deferring every controller class.
 *
 * `wp-settings.php` no longer requires the 57 files under `wp-includes/rest-api/`: the
 * controller classes are reached through the generated class map when
 * `create_initial_rest_routes()` instantiates them, on `rest_api_init` at priority 99. A name
 * the class map failed to answer does not raise anything at that point. `class_exists()` simply
 * returns false, `create_initial_rest_routes()` skips that controller, and the only sign of it
 * is that the namespace's routes are missing from a server nobody counted.
 *
 * The inventory is therefore pinned as the exact set of route patterns rather than as a total,
 * because a total is held constant by one route appearing while another disappears, and because
 * the patterns are the public contract: a client matches on them.
 *
 * Two of the 108 patterns in `wp/v2` are conditional.
 * `WP_REST_Attachments_Controller::register_routes()` registers `sideload` and `finalize` only
 * where `wp_is_client_side_media_processing_enabled()` is true, which is a secure context. Both
 * arms are measured, so that "108 routes" is never read as an unconditional inventory and so that
 * the conditional pair cannot quietly become unconditional either.
 *
 * @package WordPress
 * @subpackage UnitTests
 *
 * @group restapi
 * @group load
 */
class Tests_REST_RouteInventory extends WP_UnitTestCase {

	/**
	 * The route patterns the `wp/v2` namespace registers on every request.
	 *
	 * @var string[]
	 */
	const UNCONDITIONAL_WP_V2_ROUTES = array(
		'/wp/v2',
		'/wp/v2/block-directory/search',
		'/wp/v2/block-patterns/categories',
		'/wp/v2/block-patterns/patterns',
		'/wp/v2/block-renderer/(?P<name>[a-z0-9-]+/[a-z0-9-]+)',
		'/wp/v2/block-types',
		'/wp/v2/block-types/(?P<namespace>[a-zA-Z0-9_-]+)',
		'/wp/v2/block-types/(?P<namespace>[a-zA-Z0-9_-]+)/(?P<name>[a-zA-Z0-9_-]+)',
		'/wp/v2/blocks',
		'/wp/v2/blocks/(?P<id>[\\d]+)',
		'/wp/v2/blocks/(?P<id>[\\d]+)/autosaves',
		'/wp/v2/blocks/(?P<parent>[\\d]+)/autosaves/(?P<id>[\\d]+)',
		'/wp/v2/blocks/(?P<parent>[\\d]+)/revisions',
		'/wp/v2/blocks/(?P<parent>[\\d]+)/revisions/(?P<id>[\\d]+)',
		'/wp/v2/categories',
		'/wp/v2/categories/(?P<id>[\\d]+)',
		'/wp/v2/comments',
		'/wp/v2/comments/(?P<id>[\\d]+)',
		'/wp/v2/font-collections',
		'/wp/v2/font-collections/(?P<slug>[\\/\\w-]+)',
		'/wp/v2/font-families',
		'/wp/v2/font-families/(?P<font_family_id>[\\d]+)/font-faces',
		'/wp/v2/font-families/(?P<font_family_id>[\\d]+)/font-faces/(?P<id>[\\d]+)',
		'/wp/v2/font-families/(?P<id>[\\d]+)',
		'/wp/v2/global-styles/(?P<id>[\\/\\d+]+)',
		'/wp/v2/global-styles/(?P<parent>[\\d]+)/revisions',
		'/wp/v2/global-styles/(?P<parent>[\\d]+)/revisions/(?P<id>[\\d]+)',
		'/wp/v2/global-styles/themes/(?P<stylesheet>[\\/\\s%\\w\\.\\(\\)\\[\\]\\@_\\-]+)/variations',
		'/wp/v2/global-styles/themes/(?P<stylesheet>[^\\/:<>\\*\\?"\\|]+(?:\\/[^\\/:<>\\*\\?"\\|]+)?)',
		'/wp/v2/icons',
		'/wp/v2/icons/(?P<name>[a-z][a-z0-9-]*/[a-z][a-z0-9-]*)',
		'/wp/v2/media',
		'/wp/v2/media/(?P<id>[\\d]+)',
		'/wp/v2/media/(?P<id>[\\d]+)/edit',
		'/wp/v2/media/(?P<id>[\\d]+)/post-process',
		'/wp/v2/menu-items',
		'/wp/v2/menu-items/(?P<id>[\\d]+)',
		'/wp/v2/menu-items/(?P<id>[\\d]+)/autosaves',
		'/wp/v2/menu-items/(?P<parent>[\\d]+)/autosaves/(?P<id>[\\d]+)',
		'/wp/v2/menu-locations',
		'/wp/v2/menu-locations/(?P<location>[\\w-]+)',
		'/wp/v2/menus',
		'/wp/v2/menus/(?P<id>[\\d]+)',
		'/wp/v2/navigation',
		'/wp/v2/navigation/(?P<id>[\\d]+)',
		'/wp/v2/navigation/(?P<id>[\\d]+)/autosaves',
		'/wp/v2/navigation/(?P<parent>[\\d]+)/autosaves/(?P<id>[\\d]+)',
		'/wp/v2/navigation/(?P<parent>[\\d]+)/revisions',
		'/wp/v2/navigation/(?P<parent>[\\d]+)/revisions/(?P<id>[\\d]+)',
		'/wp/v2/pages',
		'/wp/v2/pages/(?P<id>[\\d]+)',
		'/wp/v2/pages/(?P<id>[\\d]+)/autosaves',
		'/wp/v2/pages/(?P<parent>[\\d]+)/autosaves/(?P<id>[\\d]+)',
		'/wp/v2/pages/(?P<parent>[\\d]+)/revisions',
		'/wp/v2/pages/(?P<parent>[\\d]+)/revisions/(?P<id>[\\d]+)',
		'/wp/v2/pattern-directory/patterns',
		'/wp/v2/plugins',
		'/wp/v2/plugins/(?P<plugin>[^.\\/]+(?:\\/[^.\\/]+)?)',
		'/wp/v2/posts',
		'/wp/v2/posts/(?P<id>[\\d]+)',
		'/wp/v2/posts/(?P<id>[\\d]+)/autosaves',
		'/wp/v2/posts/(?P<parent>[\\d]+)/autosaves/(?P<id>[\\d]+)',
		'/wp/v2/posts/(?P<parent>[\\d]+)/revisions',
		'/wp/v2/posts/(?P<parent>[\\d]+)/revisions/(?P<id>[\\d]+)',
		'/wp/v2/search',
		'/wp/v2/settings',
		'/wp/v2/sidebars',
		'/wp/v2/sidebars/(?P<id>[\\w-]+)',
		'/wp/v2/statuses',
		'/wp/v2/statuses/(?P<status>[\\w-]+)',
		'/wp/v2/tags',
		'/wp/v2/tags/(?P<id>[\\d]+)',
		'/wp/v2/taxonomies',
		'/wp/v2/taxonomies/(?P<taxonomy>[\\w-]+)',
		'/wp/v2/template-parts',
		'/wp/v2/template-parts/(?P<id>([^\\/:<>\\*\\?"\\|]+(?:\\/[^\\/:<>\\*\\?"\\|]+)?)[\\/\\w%-]+)',
		'/wp/v2/template-parts/(?P<id>([^\\/:<>\\*\\?"\\|]+(?:\\/[^\\/:<>\\*\\?"\\|]+)?)[\\/\\w%-]+)/autosaves',
		'/wp/v2/template-parts/(?P<parent>([^\\/:<>\\*\\?"\\|]+(?:\\/[^\\/:<>\\*\\?"\\|]+)?)[\\/\\w%-]+)/autosaves/(?P<id>[\\d]+)',
		'/wp/v2/template-parts/(?P<parent>([^\\/:<>\\*\\?"\\|]+(?:\\/[^\\/:<>\\*\\?"\\|]+)?)[\\/\\w%-]+)/revisions',
		'/wp/v2/template-parts/(?P<parent>([^\\/:<>\\*\\?"\\|]+(?:\\/[^\\/:<>\\*\\?"\\|]+)?)[\\/\\w%-]+)/revisions/(?P<id>[\\d]+)',
		'/wp/v2/template-parts/lookup',
		'/wp/v2/templates',
		'/wp/v2/templates/(?P<id>([^\\/:<>\\*\\?"\\|]+(?:\\/[^\\/:<>\\*\\?"\\|]+)?)[\\/\\w%-]+)',
		'/wp/v2/templates/(?P<id>([^\\/:<>\\*\\?"\\|]+(?:\\/[^\\/:<>\\*\\?"\\|]+)?)[\\/\\w%-]+)/autosaves',
		'/wp/v2/templates/(?P<parent>([^\\/:<>\\*\\?"\\|]+(?:\\/[^\\/:<>\\*\\?"\\|]+)?)[\\/\\w%-]+)/autosaves/(?P<id>[\\d]+)',
		'/wp/v2/templates/(?P<parent>([^\\/:<>\\*\\?"\\|]+(?:\\/[^\\/:<>\\*\\?"\\|]+)?)[\\/\\w%-]+)/revisions',
		'/wp/v2/templates/(?P<parent>([^\\/:<>\\*\\?"\\|]+(?:\\/[^\\/:<>\\*\\?"\\|]+)?)[\\/\\w%-]+)/revisions/(?P<id>[\\d]+)',
		'/wp/v2/templates/lookup',
		'/wp/v2/themes',
		'/wp/v2/themes/(?P<stylesheet>[^\\/:<>\\*\\?"\\|]+(?:\\/[^\\/:<>\\*\\?"\\|]+)?)',
		'/wp/v2/types',
		'/wp/v2/types/(?P<type>[\\w-]+)',
		'/wp/v2/users',
		'/wp/v2/users/(?P<id>[\\d]+)',
		'/wp/v2/users/(?P<user_id>(?:[\\d]+|me))/application-passwords',
		'/wp/v2/users/(?P<user_id>(?:[\\d]+|me))/application-passwords/(?P<uuid>[\\w\\-]+)',
		'/wp/v2/users/(?P<user_id>(?:[\\d]+|me))/application-passwords/introspect',
		'/wp/v2/users/me',
		'/wp/v2/widget-types',
		'/wp/v2/widget-types/(?P<id>[a-zA-Z0-9_-]+)',
		'/wp/v2/widget-types/(?P<id>[a-zA-Z0-9_-]+)/encode',
		'/wp/v2/widget-types/(?P<id>[a-zA-Z0-9_-]+)/render',
		'/wp/v2/widgets',
		'/wp/v2/widgets/(?P<id>[\\w\\-]+)',
		'/wp/v2/wp_pattern_category',
		'/wp/v2/wp_pattern_category/(?P<id>[\\d]+)',
	);

	/**
	 * The route patterns registered only in a secure context.
	 *
	 * @var string[]
	 */
	const CONDITIONAL_WP_V2_ROUTES = array(
		'/wp/v2/media/(?P<id>[\d]+)/finalize',
		'/wp/v2/media/(?P<id>[\d]+)/sideload',
	);

	/**
	 * The routes every other core namespace registers, as a count per namespace.
	 *
	 * Counted rather than listed, because the `wp/v2` inventory above is what the deferral
	 * reaches: every controller in the other namespaces is instantiated from the same call, so
	 * one missing name in any of them moves one of these numbers.
	 *
	 * @var int[]
	 */
	const OTHER_NAMESPACE_ROUTE_COUNTS = array(
		'/'                  => 1,
		'batch/v1'           => 1,
		'oembed/1.0'         => 3,
		'wp-abilities/v1'    => 6,
		'wp-block-editor/v1' => 4,
		'wp-site-health/v1'  => 8,
		'wp-sync/v1'         => 2,
	);

	/**
	 * The endpoints that are public by design and therefore declare no permission callback.
	 *
	 * Every one of them belongs to `WP_REST_Server` itself rather than to a controller, so none is
	 * reached through the class map and none can be affected by the deferral. `/` is the discovery
	 * index and `/batch/v1` is the batch dispatcher, both declared inline by
	 * `WP_REST_Server::__construct()`; the batch dispatcher owns no data of its own, since it
	 * re-dispatches every sub-request through `WP_REST_Server::dispatch()` and each sub-request
	 * meets its own route's permission callback there. The remaining six are namespace indexes,
	 * which `WP_REST_Server::register_route()` registers automatically the first time a namespace
	 * is seen.
	 *
	 * The list is pinned rather than derived so that the set of unauthenticated core endpoints
	 * cannot grow without a deliberate edit, and so that a controller endpoint losing its
	 * permission callback is a failure even though the total number of unguarded endpoints would
	 * be unchanged if a namespace index disappeared at the same time.
	 *
	 * @var string[]
	 */
	const UNAUTHENTICATED_CORE_ENDPOINTS = array(
		'/ GET',
		'/batch/v1 POST',
		'/oembed/1.0 GET',
		'/wp-abilities/v1 GET',
		'/wp-block-editor/v1 GET',
		'/wp-site-health/v1 GET',
		'/wp-sync/v1 GET',
		'/wp/v2 GET',
	);

	/**
	 * Tests that the `wp/v2` namespace registers exactly its unconditional inventory.
	 */
	public function test_the_wp_v2_namespace_registers_its_exact_unconditional_inventory() {
		$this->assertSame(
			self::UNCONDITIONAL_WP_V2_ROUTES,
			$this->registered_routes( false, 'wp/v2' ),
			'Deferring the controller classes must leave the wp/v2 inventory exactly as it was.'
		);
	}

	/**
	 * Tests that the two conditional media routes follow their predicate in both directions.
	 */
	public function test_the_conditional_media_routes_follow_their_predicate() {
		$enabled = $this->registered_routes( true, 'wp/v2' );

		$this->assertSame(
			self::CONDITIONAL_WP_V2_ROUTES,
			array_values( array_intersect( $enabled, self::CONDITIONAL_WP_V2_ROUTES ) ),
			'A secure context must register both client-side media processing routes.'
		);

		$this->assertCount(
			count( self::UNCONDITIONAL_WP_V2_ROUTES ) + count( self::CONDITIONAL_WP_V2_ROUTES ),
			$enabled,
			'A secure context must register the unconditional inventory and the conditional pair, and nothing else.'
		);

		$this->assertSame(
			array(),
			array_values( array_intersect( $this->registered_routes( false, 'wp/v2' ), self::CONDITIONAL_WP_V2_ROUTES ) ),
			'An insecure context must register neither client-side media processing route.'
		);
	}

	/**
	 * Tests that every other core namespace registers the routes it registered before.
	 */
	public function test_every_other_core_namespace_registers_its_routes() {
		$counts = array();

		foreach ( $this->registered_routes( false ) as $route ) {
			$segments  = explode( '/', trim( $route, '/' ) );
			$namespace = count( $segments ) > 1 ? $segments[0] . '/' . $segments[1] : '/';

			if ( 'wp/v2' === $namespace ) {
				continue;
			}

			$counts[ $namespace ] = isset( $counts[ $namespace ] ) ? $counts[ $namespace ] + 1 : 1;
		}

		ksort( $counts );

		$this->assertSame(
			self::OTHER_NAMESPACE_ROUTE_COUNTS,
			$counts,
			'Every core namespace outside wp/v2 must register the same number of routes it registered before the controllers were deferred.'
		);
	}

	/**
	 * Tests that every registered endpoint still declares a permission callback.
	 *
	 * This is the security half of the deferral. A controller class is loaded when
	 * `create_initial_rest_routes()` instantiates it, and its permission callbacks are
	 * registered by the same call that registers its routes, so the two cannot come apart -- but
	 * they would come apart silently if they ever did, because a route with no permission
	 * callback is served rather than refused. Every endpoint is therefore checked, in both arms
	 * of the conditional predicate, so the pair of routes that only exists in a secure context is
	 * checked too.
	 */
	public function test_every_registered_endpoint_declares_a_permission_callback() {
		foreach ( array( true, false ) as $enabled ) {
			$unguarded = array();
			$owners    = array();

			foreach ( $this->registered_route_map( $enabled ) as $route => $handlers ) {
				foreach ( $handlers as $handler ) {
					if ( ! empty( $handler['permission_callback'] ) ) {
						continue;
					}

					$endpoint            = $route . ' ' . implode( ',', array_keys( array_filter( $handler['methods'] ) ) );
					$unguarded[]         = $endpoint;
					$owners[ $endpoint ] = is_array( $handler['callback'] ) && is_object( $handler['callback'][0] )
						? get_class( $handler['callback'][0] )
						: 'WP_REST_Server';
				}
			}

			sort( $unguarded );
			ksort( $owners );

			$this->assertSame(
				self::UNAUTHENTICATED_CORE_ENDPOINTS,
				$unguarded,
				'Every registered REST endpoint outside the public discovery and batch endpoints must declare a permission callback, whether or not client-side media processing is enabled.'
			);

			$this->assertSame(
				array_fill_keys( self::UNAUTHENTICATED_CORE_ENDPOINTS, 'WP_REST_Server' ),
				$owners,
				'No deferred controller may contribute an endpoint that declares no permission callback: every unguarded endpoint must belong to WP_REST_Server itself.'
			);
		}
	}

	/**
	 * Registers the initial routes against a fresh server and returns their patterns, sorted.
	 *
	 * @param bool        $client_side_media Whether client-side media processing is enabled.
	 * @param string|null $route_namespace   Optional. Namespace to limit the routes to. Default null.
	 * @return string[] Sorted route patterns.
	 */
	private function registered_routes( $client_side_media, $route_namespace = null ) {
		$routes = array_keys( $this->registered_route_map( $client_side_media, $route_namespace ) );

		sort( $routes );

		return $routes;
	}

	/**
	 * Registers the initial routes against a fresh server and returns them with their handlers.
	 *
	 * A server of its own is built for each arm, because `create_initial_rest_routes()` runs once
	 * per server and the predicate is read while it runs. Results are memoized for the run, since
	 * each arm costs a full registration and four tests read them.
	 *
	 * The server has to be published to `$GLOBALS['wp_rest_server']` before `rest_api_init` is
	 * fired, and not merely passed as the action's argument: every registrar reaches its server
	 * through `register_rest_route()`, which calls `rest_get_server()` rather than reading the
	 * argument. A server that is only passed collects nothing but the two meta endpoints its own
	 * constructor declares, and `rest_get_server()` would build a second server and fire the
	 * action again on that one.
	 *
	 * @param bool        $client_side_media Whether client-side media processing is enabled.
	 * @param string|null $route_namespace   Optional. Namespace to limit the routes to. Default null.
	 * @return array Route patterns mapped to their registered handlers.
	 */
	private function registered_route_map( $client_side_media, $route_namespace = null ) {
		static $servers = array();

		$key = $client_side_media ? 'enabled' : 'disabled';

		if ( ! isset( $servers[ $key ] ) ) {
			$filter = $client_side_media ? '__return_true' : '__return_false';

			add_filter( 'wp_client_side_media_processing_enabled', $filter );

			$was_published             = array_key_exists( 'wp_rest_server', $GLOBALS );
			$previous_server           = $was_published ? $GLOBALS['wp_rest_server'] : null;
			$GLOBALS['wp_rest_server'] = new WP_REST_Server();
			$server                    = $GLOBALS['wp_rest_server'];

			do_action( 'rest_api_init', $server );

			if ( $was_published ) {
				$GLOBALS['wp_rest_server'] = $previous_server;
			} else {
				unset( $GLOBALS['wp_rest_server'] );
			}

			remove_filter( 'wp_client_side_media_processing_enabled', $filter );

			$servers[ $key ] = $server;
		}

		return null === $route_namespace
			? $servers[ $key ]->get_routes()
			: $servers[ $key ]->get_routes( $route_namespace );
	}
}
