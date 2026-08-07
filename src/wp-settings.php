<?php
/**
 * Used to set up and fix common variables and include
 * the WordPress procedural and class library.
 *
 * Allows for some configuration in wp-config.php (see default-constants.php)
 *
 * @package WordPress
 */

/**
 * Stores the location of the WordPress directory of functions, classes, and core content.
 *
 * @since 1.0.0
 */
define( 'WPINC', 'wp-includes' );

/**
 * Version information for the current WordPress release.
 *
 * These can't be directly globalized in version.php. When updating,
 * include version.php from another installation and don't override
 * these values if already set.
 *
 * @global string   $wp_version              The WordPress version string.
 * @global int      $wp_db_version           WordPress database version.
 * @global string   $tinymce_version         TinyMCE version.
 * @global string   $required_php_version    The minimum required PHP version string.
 * @global string[] $required_php_extensions The names of required PHP extensions.
 * @global string   $required_mysql_version  The minimum required MySQL version string.
 * @global string   $wp_local_package        Locale code of the package.
 */
global $wp_version, $wp_db_version, $tinymce_version, $required_php_version, $required_php_extensions, $required_mysql_version, $wp_local_package;
require ABSPATH . WPINC . '/version.php';
require ABSPATH . WPINC . '/compat-utf8.php';
require ABSPATH . WPINC . '/compat.php';
require ABSPATH . WPINC . '/load.php';

/*
 * Register the core class autoloader before the remaining class bootstrap, so that
 * every class, interface and trait file listed in the generated class map can be
 * loaded the first time the name it declares is referenced rather than being
 * required eagerly below. Registration does not load the class map: the map is
 * read on the first autoload attempt for a name that could belong to core.
 *
 * Being covered by the class map is not on its own a reason to stop requiring a
 * file here. A name the request goes on to reference is loaded either way, and
 * reaching it through the autoloader costs a normalization, a map lookup and a
 * stat that the direct require does not, for no file saved. So a require is only
 * dropped where the name is not referenced at all on the request path this
 * bootstrap is measured on, and it is kept in every one of these cases:
 *
 * - The name is referenced on a measured front-end request anyway, so deferring
 *   it would add autoload work without keeping the file out of that request.
 * - It declares functions, which an autoloader is never asked to resolve.
 * - Including it has side effects, such as registering a block support or a
 *   second autoloader, that a reference to a class name would not trigger.
 * - The single name it declares is absent from the generated class map, because
 *   the generator rejected the file as ineligible.
 * - Code deliberately probes for the name with autoloading disabled, so the
 *   name has to be present before that probe runs.
 *
 * Two names are deferred even though a front-end request does resolve them,
 * because a mapped file has to be loadable on its own and these two are what
 * makes that true for others. Walker and WP_Widget are each the parent of
 * classes that stay mapped - four walkers and the twenty default widgets - so
 * requiring either one here would drop it from the class map and leave those
 * children unloadable in isolation. Making them eager instead would mean making
 * their children eager too, which costs a front-end request four files it never
 * uses in the walkers' case, and is not even available in the widgets' case
 * because default-widgets.php, not this file, is what requires them.
 *
 * What that leaves deferred is dominated by the REST API classes described
 * further down, alongside smaller groups a front-end request never names: the
 * site and network queries, the AJAX response, user requests and user queries,
 * oEmbed, the HTML processor's unsupported states, the non-default HTTP
 * transports, application passwords, the abilities and collaboration classes,
 * the sitemap stylesheet, the block editor context, the classic-to-block menu
 * converter and the plugin dependency resolver.
 *
 * One conditional require is a deliberate exception: the WP_Site_Health branch
 * near the end of this file does name a mapped file, because the class lives
 * under wp-admin and the map is what keeps it reachable from here. That require
 * sits behind class_exists( 'WP_Site_Health' ), which autoloading answers first,
 * so it is reached only in a tree that carries no usable generated map.
 */
require ABSPATH . WPINC . '/autoload.php';

// Check the server requirements.
wp_check_php_mysql_versions();

// Include files required for initialization.
require ABSPATH . WPINC . '/class-wp-paused-extensions-storage.php';
require ABSPATH . WPINC . '/class-wp-fatal-error-handler.php';
require ABSPATH . WPINC . '/class-wp-recovery-mode-cookie-service.php';
require ABSPATH . WPINC . '/class-wp-recovery-mode-key-service.php';
require ABSPATH . WPINC . '/class-wp-recovery-mode-link-service.php';
require ABSPATH . WPINC . '/class-wp-recovery-mode-email-service.php';
require ABSPATH . WPINC . '/class-wp-recovery-mode.php';
require ABSPATH . WPINC . '/error-protection.php';
require ABSPATH . WPINC . '/default-constants.php';
require_once ABSPATH . WPINC . '/plugin.php';

/**
 * If not already configured, `$blog_id` will default to 1 in a single site
 * configuration. In multisite, it will be overridden by default in ms-settings.php.
 *
 * @since 2.0.0
 *
 * @global int $blog_id
 */
global $blog_id;

// Set initial default constants including WP_MEMORY_LIMIT, WP_MAX_MEMORY_LIMIT, WP_DEBUG, SCRIPT_DEBUG, WP_CONTENT_DIR and WP_CACHE.
wp_initial_constants();

// Register the shutdown handler for fatal errors as soon as possible.
wp_register_fatal_error_handler();

// WordPress calculates offsets from UTC.
// phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set
date_default_timezone_set( 'UTC' );

// Standardize $_SERVER variables across setups.
wp_fix_server_vars();

// Check if the site is in maintenance mode.
wp_maintenance();

// Start loading timer.
timer_start();

// Check if WP_DEBUG mode is enabled.
wp_debug_mode();

/**
 * Filters whether to enable loading of the advanced-cache.php drop-in.
 *
 * This filter runs before it can be used by plugins. It is designed for non-web
 * run-times. If false is returned, advanced-cache.php will never be loaded.
 *
 * @since 4.6.0
 *
 * @param bool $enable_advanced_cache Whether to enable loading advanced-cache.php (if present).
 *                                    Default true.
 */
if ( WP_CACHE && apply_filters( 'enable_loading_advanced_cache_dropin', true ) && file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
	// For an advanced caching plugin to use. Uses a static drop-in because you would only want one.
	include WP_CONTENT_DIR . '/advanced-cache.php';

	// Re-initialize any hooks added manually by advanced-cache.php.
	if ( $wp_filter ) {
		$wp_filter = WP_Hook::build_preinitialized_hooks( $wp_filter );
	}
}

// Define WP_LANG_DIR if not set.
wp_set_lang_dir();

// Load early WordPress files.
require ABSPATH . WPINC . '/class-wp-list-util.php';
require ABSPATH . WPINC . '/class-wp-token-map.php';
require ABSPATH . WPINC . '/utf8.php';
require ABSPATH . WPINC . '/formatting.php';
require ABSPATH . WPINC . '/meta.php';
require ABSPATH . WPINC . '/functions.php';
require ABSPATH . WPINC . '/class-wp-meta-query.php';
require ABSPATH . WPINC . '/class-wp.php';
/*
 * WP_Error is deliberately eager, and is therefore absent from the generated
 * class map rather than resolved on first reference: wpdb::bail() probes for the
 * name with class_exists( 'WP_Error', false ), which disables autoloading, and
 * assigns a plain string to the public wpdb::$error property when that probe
 * fails. Deferring the name would change the type of that property for anyone
 * reading it, so it is made available here, before a database connection can
 * fail.
 */
require ABSPATH . WPINC . '/class-wp-error.php';
require ABSPATH . WPINC . '/pomo/mo.php';
require ABSPATH . WPINC . '/l10n/class-wp-translation-controller.php';

/**
 * @since 0.71
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 */
global $wpdb;
// Include the wpdb class and, if present, a db.php database drop-in.
require_wp_db();

/**
 * @since 3.3.0
 *
 * @global string $table_prefix The database table prefix.
 */
if ( ! isset( $GLOBALS['table_prefix'] ) ) {
	$GLOBALS['table_prefix'] = $table_prefix;
}

// Set the database table prefix and the format specifiers for database table columns.
wp_set_wpdb_vars();

// Start the WordPress object cache, or an external object cache if the drop-in is present.
wp_start_object_cache();

// Attach the default filters.
require ABSPATH . WPINC . '/default-filters.php';

// Initialize multisite if enabled.
if ( is_multisite() ) {
	require ABSPATH . WPINC . '/ms-blogs.php';
	require ABSPATH . WPINC . '/ms-settings.php';
} elseif ( ! defined( 'MULTISITE' ) ) {
	define( 'MULTISITE', false );
}

register_shutdown_function( 'shutdown_action_hook' );

// Stop most of WordPress from being loaded if SHORTINIT is enabled.
if ( SHORTINIT ) {
	return false;
}

// Load the L10n library.
require_once ABSPATH . WPINC . '/l10n.php';
require_once ABSPATH . WPINC . '/class-wp-textdomain-registry.php';
require_once ABSPATH . WPINC . '/class-wp-locale.php';
require_once ABSPATH . WPINC . '/class-wp-locale-switcher.php';

// Run the installer if WordPress is not installed.
wp_not_installed();

// Load most of WordPress.
require ABSPATH . WPINC . '/capabilities.php';
require ABSPATH . WPINC . '/class-wp-roles.php';
require ABSPATH . WPINC . '/class-wp-role.php';
require ABSPATH . WPINC . '/class-wp-user.php';
require ABSPATH . WPINC . '/class-wp-query.php';
require ABSPATH . WPINC . '/query.php';
require ABSPATH . WPINC . '/theme.php';
require ABSPATH . WPINC . '/class-wp-theme.php';
require ABSPATH . WPINC . '/class-wp-theme-json-schema.php';
require ABSPATH . WPINC . '/class-wp-theme-json-data.php';
/*
 * WP_Theme_JSON is deliberately eager. It is 173,639 bytes, and every block theme
 * render resolves global styles through it, so deferring it does not stop it being
 * loaded - it only moves its compilation from here, where the request heap is
 * around 1.7 MB, to wp_head, where the resident heap is already around 7.2 MB. The
 * compiler's transient arena then stacks on top of the request's peak instead of
 * on top of its floor: measured on the canonical anonymous front end with a reset
 * opcode cache, deferring this file and the HTML API processor below raised
 * memory_get_peak_usage( false ) from 7,993,816 to 8,886,008 bytes (+11.2%) while
 * changing the loaded file count by nothing at all, because the render loads them
 * either way. Keeping the largest render path classes eager is therefore the
 * measured optimum for both metrics at once.
 */
require ABSPATH . WPINC . '/class-wp-theme-json.php';
require ABSPATH . WPINC . '/class-wp-theme-json-resolver.php';
require ABSPATH . WPINC . '/class-wp-duotone.php';
require ABSPATH . WPINC . '/global-styles-and-settings.php';
require ABSPATH . WPINC . '/class-wp-block-template.php';
require ABSPATH . WPINC . '/class-wp-block-templates-registry.php';
require ABSPATH . WPINC . '/block-template-utils.php';
require ABSPATH . WPINC . '/block-template.php';
require ABSPATH . WPINC . '/theme-templates.php';
require ABSPATH . WPINC . '/theme-previews.php';
require ABSPATH . WPINC . '/template.php';
require ABSPATH . WPINC . '/https-detection.php';
require ABSPATH . WPINC . '/https-migration.php';
require ABSPATH . WPINC . '/user.php';
require ABSPATH . WPINC . '/general-template.php';
require ABSPATH . WPINC . '/link-template.php';
require ABSPATH . WPINC . '/author-template.php';
require ABSPATH . WPINC . '/robots-template.php';
require ABSPATH . WPINC . '/post.php';
require ABSPATH . WPINC . '/class-wp-post-type.php';
require ABSPATH . WPINC . '/class-wp-post.php';
require ABSPATH . WPINC . '/post-template.php';
require ABSPATH . WPINC . '/revision.php';
require ABSPATH . WPINC . '/post-formats.php';
require ABSPATH . WPINC . '/post-thumbnail-template.php';
require ABSPATH . WPINC . '/category.php';
require ABSPATH . WPINC . '/class-walker-category-dropdown.php';
require ABSPATH . WPINC . '/category-template.php';
require ABSPATH . WPINC . '/comment.php';
require ABSPATH . WPINC . '/class-wp-comment.php';
require ABSPATH . WPINC . '/class-wp-comment-query.php';
require ABSPATH . WPINC . '/comment-template.php';
require ABSPATH . WPINC . '/rewrite.php';
require ABSPATH . WPINC . '/class-wp-rewrite.php';
require ABSPATH . WPINC . '/feed.php';
require ABSPATH . WPINC . '/bookmark.php';
require ABSPATH . WPINC . '/bookmark-template.php';
require ABSPATH . WPINC . '/kses.php';
require ABSPATH . WPINC . '/cron.php';
require ABSPATH . WPINC . '/deprecated.php';
require ABSPATH . WPINC . '/script-loader.php';
if ( file_exists( ABSPATH . WPINC . '/build/routes.php' ) ) {
	require ABSPATH . WPINC . '/build/routes.php';
}
if ( file_exists( ABSPATH . WPINC . '/build/pages.php' ) ) {
	require ABSPATH . WPINC . '/build/pages.php';
}
require ABSPATH . WPINC . '/taxonomy.php';
require ABSPATH . WPINC . '/class-wp-taxonomy.php';
require ABSPATH . WPINC . '/class-wp-term.php';
require ABSPATH . WPINC . '/class-wp-term-query.php';
require ABSPATH . WPINC . '/class-wp-tax-query.php';
require ABSPATH . WPINC . '/update.php';
require ABSPATH . WPINC . '/canonical.php';
require ABSPATH . WPINC . '/shortcodes.php';
require ABSPATH . WPINC . '/embed.php';
require ABSPATH . WPINC . '/class-wp-embed.php';
require ABSPATH . WPINC . '/media.php';
require ABSPATH . WPINC . '/http.php';
require ABSPATH . WPINC . '/html-api/html5-named-character-references.php';
require ABSPATH . WPINC . '/html-api/class-wp-html-attribute-token.php';
require ABSPATH . WPINC . '/html-api/class-wp-html-span.php';
require ABSPATH . WPINC . '/html-api/class-wp-html-text-replacement.php';
require ABSPATH . WPINC . '/html-api/class-wp-html-decoder.php';
require ABSPATH . WPINC . '/html-api/class-wp-html-tag-processor.php';
/*
 * WP_HTML_Processor (215,460 bytes) is eager for the reason given above
 * WP_Theme_JSON: block rendering loads it on every front-end request, so it is only
 * ever deferred as far as wp_head, where its compilation lands on top of the
 * request's peak heap rather than its floor. Its parent WP_HTML_Tag_Processor
 * (167,558 bytes) is required on the line above for the same reason, and so that it,
 * and anything else that extends it, keeps resolving in a bootstrap that only
 * registers the autoloader. The rest of the HTML API - the stack classes and the
 * processor's own exception and state classes - stays deferred, because a request
 * that never parses markup never loads any of them.
 */
require ABSPATH . WPINC . '/html-api/class-wp-html-processor.php';
require ABSPATH . WPINC . '/class-wp-http.php';
require ABSPATH . WPINC . '/class-wp-http-streams.php';
/*
 * The five declarations below name a parent class or an implemented interface that
 * belongs to a bundled library, and those names are served by autoloaders that are
 * registered here rather than alongside the core autoloader further up this file.
 * WpOrg\Requests\Autoload::register() runs inside class-wp-http.php, and
 * php-ai-client/autoload.php registers the WordPress\AiClient and
 * WordPress\AiClientDependencies prefixes on the line below. Resolving any of these
 * five through the class map before this point - in a SHORTINIT bootstrap, in an
 * object-cache.php or advanced-cache.php drop-in, or anywhere between the core
 * autoloader and this line - would reach an undeclared parent and raise a fatal
 * error, so they stay eager next to the autoloaders they depend on and the class map
 * generator excludes them.
 */
require ABSPATH . WPINC . '/class-wp-http-requests-hooks.php';
require ABSPATH . WPINC . '/php-ai-client/autoload.php';
require ABSPATH . WPINC . '/ai-client/adapters/class-wp-ai-client-http-client.php';
require ABSPATH . WPINC . '/ai-client/adapters/class-wp-ai-client-cache.php';
require ABSPATH . WPINC . '/ai-client/adapters/class-wp-ai-client-discovery-strategy.php';
require ABSPATH . WPINC . '/ai-client/adapters/class-wp-ai-client-event-dispatcher.php';
require ABSPATH . WPINC . '/ai-client.php';
require ABSPATH . WPINC . '/class-wp-connector-registry.php';
require ABSPATH . WPINC . '/connectors.php';
require ABSPATH . WPINC . '/widgets.php';
require ABSPATH . WPINC . '/class-wp-widget-factory.php';
require ABSPATH . WPINC . '/nav-menu-template.php';
require ABSPATH . WPINC . '/nav-menu.php';
require ABSPATH . WPINC . '/admin-bar.php';
require ABSPATH . WPINC . '/abilities-api.php';
require ABSPATH . WPINC . '/abilities.php';
require ABSPATH . WPINC . '/collaboration.php';
require ABSPATH . WPINC . '/rest-api.php';
/*
 * The REST API infrastructure, controller, field and search handler classes are
 * resolved by the autoloader rather than required here. Nothing references them
 * before rest_get_server() runs: it constructs the server and then fires the
 * rest_api_init action, which is where create_initial_rest_routes() registers the
 * routes and instantiates their controllers. rest_get_server() is the only place
 * that action fires, so a request that never dispatches a REST route never
 * references any of these names. rest-api.php itself stays eager because it
 * declares the functions that default-filters.php registers by name.
 */
require ABSPATH . WPINC . '/sitemaps.php';
require ABSPATH . WPINC . '/sitemaps/class-wp-sitemaps.php';
require ABSPATH . WPINC . '/sitemaps/class-wp-sitemaps-index.php';
require ABSPATH . WPINC . '/sitemaps/class-wp-sitemaps-provider.php';
require ABSPATH . WPINC . '/sitemaps/class-wp-sitemaps-registry.php';
require ABSPATH . WPINC . '/sitemaps/class-wp-sitemaps-renderer.php';
require ABSPATH . WPINC . '/sitemaps/providers/class-wp-sitemaps-posts.php';
require ABSPATH . WPINC . '/sitemaps/providers/class-wp-sitemaps-taxonomies.php';
require ABSPATH . WPINC . '/sitemaps/providers/class-wp-sitemaps-users.php';
require ABSPATH . WPINC . '/class-wp-block-bindings-source.php';
require ABSPATH . WPINC . '/class-wp-block-bindings-registry.php';
require ABSPATH . WPINC . '/class-wp-block-type.php';
require ABSPATH . WPINC . '/class-wp-block-pattern-categories-registry.php';
require ABSPATH . WPINC . '/class-wp-block-patterns-registry.php';
require ABSPATH . WPINC . '/class-wp-block-styles-registry.php';
require ABSPATH . WPINC . '/class-wp-block-type-registry.php';
require ABSPATH . WPINC . '/class-wp-block.php';
require ABSPATH . WPINC . '/class-wp-block-list.php';
require ABSPATH . WPINC . '/class-wp-block-metadata-registry.php';
require ABSPATH . WPINC . '/class-wp-block-parser-block.php';
require ABSPATH . WPINC . '/class-wp-block-parser-frame.php';
require ABSPATH . WPINC . '/class-wp-block-parser.php';
require ABSPATH . WPINC . '/class-wp-navigation-fallback.php';
require ABSPATH . WPINC . '/block-bindings.php';
require ABSPATH . WPINC . '/block-bindings/pattern-overrides.php';
require ABSPATH . WPINC . '/block-bindings/post-data.php';
require ABSPATH . WPINC . '/block-bindings/post-meta.php';
require ABSPATH . WPINC . '/block-bindings/term-data.php';
require ABSPATH . WPINC . '/blocks.php';
require ABSPATH . WPINC . '/blocks/index.php';
require ABSPATH . WPINC . '/block-editor.php';
require ABSPATH . WPINC . '/block-patterns.php';
require ABSPATH . WPINC . '/class-wp-block-supports.php';
require ABSPATH . WPINC . '/block-supports/utils.php';
require ABSPATH . WPINC . '/block-supports/align.php';
require ABSPATH . WPINC . '/block-supports/auto-register.php';
require ABSPATH . WPINC . '/block-supports/custom-classname.php';
require ABSPATH . WPINC . '/block-supports/generated-classname.php';
require ABSPATH . WPINC . '/block-supports/settings.php';
require ABSPATH . WPINC . '/block-supports/elements.php';
require ABSPATH . WPINC . '/block-supports/colors.php';
require ABSPATH . WPINC . '/block-supports/typography.php';
require ABSPATH . WPINC . '/block-supports/border.php';
require ABSPATH . WPINC . '/block-supports/layout.php';
require ABSPATH . WPINC . '/block-supports/position.php';
require ABSPATH . WPINC . '/block-supports/spacing.php';
require ABSPATH . WPINC . '/block-supports/dimensions.php';
require ABSPATH . WPINC . '/block-supports/duotone.php';
require ABSPATH . WPINC . '/block-supports/shadow.php';
require ABSPATH . WPINC . '/block-supports/background.php';
require ABSPATH . WPINC . '/block-supports/block-style-variations.php';
require ABSPATH . WPINC . '/block-supports/aria-label.php';
require ABSPATH . WPINC . '/block-supports/anchor.php';
require ABSPATH . WPINC . '/block-supports/block-visibility.php';
require ABSPATH . WPINC . '/block-supports/custom-css.php';
require ABSPATH . WPINC . '/style-engine.php';
require ABSPATH . WPINC . '/style-engine/class-wp-style-engine.php';
require ABSPATH . WPINC . '/style-engine/class-wp-style-engine-css-declarations.php';
require ABSPATH . WPINC . '/style-engine/class-wp-style-engine-css-rule.php';
require ABSPATH . WPINC . '/style-engine/class-wp-style-engine-css-rules-store.php';
require ABSPATH . WPINC . '/style-engine/class-wp-style-engine-processor.php';
require ABSPATH . WPINC . '/fonts/class-wp-font-face-resolver.php';
require ABSPATH . WPINC . '/fonts/class-wp-font-collection.php';
require ABSPATH . WPINC . '/fonts/class-wp-font-face.php';
require ABSPATH . WPINC . '/fonts/class-wp-font-library.php';
require ABSPATH . WPINC . '/fonts/class-wp-font-utils.php';
require ABSPATH . WPINC . '/fonts.php';
require ABSPATH . WPINC . '/class-wp-script-modules.php';
require ABSPATH . WPINC . '/script-modules.php';
require ABSPATH . WPINC . '/interactivity-api/class-wp-interactivity-api.php';
require ABSPATH . WPINC . '/interactivity-api/class-wp-interactivity-api-directives-processor.php';
require ABSPATH . WPINC . '/interactivity-api/interactivity-api.php';
require ABSPATH . WPINC . '/class-wp-url-pattern-prefixer.php';
require ABSPATH . WPINC . '/class-wp-speculation-rules.php';
require ABSPATH . WPINC . '/speculative-loading.php';
require ABSPATH . WPINC . '/view-transitions.php';

add_action( 'after_setup_theme', array( wp_script_modules(), 'add_hooks' ) );
add_action( 'after_setup_theme', array( wp_interactivity(), 'add_hooks' ) );

/**
 * @since 3.3.0
 *
 * @global WP_Embed $wp_embed WordPress Embed object.
 */
$GLOBALS['wp_embed'] = new WP_Embed();

/**
 * WordPress Textdomain Registry object.
 *
 * Used to support just-in-time translations for manually loaded text domains.
 *
 * @since 6.1.0
 *
 * @global WP_Textdomain_Registry $wp_textdomain_registry WordPress Textdomain Registry.
 */
$GLOBALS['wp_textdomain_registry'] = new WP_Textdomain_Registry();
$GLOBALS['wp_textdomain_registry']->init();

// WordPress AI Client initialization.
WP_AI_Client_Discovery_Strategy::init();
WordPress\AiClient\AiClient::setCache( new WP_AI_Client_Cache() );
WordPress\AiClient\AiClient::setEventDispatcher( new WP_AI_Client_Event_Dispatcher() );

// Load multisite-specific files.
if ( is_multisite() ) {
	require ABSPATH . WPINC . '/ms-functions.php';
	require ABSPATH . WPINC . '/ms-default-filters.php';
	require ABSPATH . WPINC . '/ms-deprecated.php';
}

// Define constants that rely on the API to obtain the default value.
// Define must-use plugin directory constants, which may be overridden in the sunrise.php drop-in.
wp_plugin_directory_constants();

/**
 * @since 3.9.0
 *
 * @global array $wp_plugin_paths
 */
$GLOBALS['wp_plugin_paths'] = array();

// Load must-use plugins.
foreach ( wp_get_mu_plugins() as $mu_plugin ) {
	$_wp_plugin_file = $mu_plugin;
	include_once $mu_plugin;
	$mu_plugin = $_wp_plugin_file; // Avoid stomping of the $mu_plugin variable in a plugin.

	/**
	 * Fires once a single must-use plugin has loaded.
	 *
	 * @since 5.1.0
	 *
	 * @param string $mu_plugin Full path to the plugin's main file.
	 */
	do_action( 'mu_plugin_loaded', $mu_plugin );
}
unset( $mu_plugin, $_wp_plugin_file );

// Load network activated plugins.
if ( is_multisite() ) {
	foreach ( wp_get_active_network_plugins() as $network_plugin ) {
		wp_register_plugin_realpath( $network_plugin );

		$_wp_plugin_file = $network_plugin;
		include_once $network_plugin;
		$network_plugin = $_wp_plugin_file; // Avoid stomping of the $network_plugin variable in a plugin.

		/**
		 * Fires once a single network-activated plugin has loaded.
		 *
		 * @since 5.1.0
		 *
		 * @param string $network_plugin Full path to the plugin's main file.
		 */
		do_action( 'network_plugin_loaded', $network_plugin );
	}
	unset( $network_plugin, $_wp_plugin_file );
}

/**
 * Fires once all must-use and network-activated plugins have loaded.
 *
 * @since 2.8.0
 */
do_action( 'muplugins_loaded' );

if ( is_multisite() ) {
	ms_cookie_constants();
}

// Define constants after multisite is loaded.
wp_cookie_constants();

// Define and enforce our SSL constants.
wp_ssl_constants();

// Create common globals.
require ABSPATH . WPINC . '/vars.php';

// Make taxonomies and posts available to plugins and themes.
// @plugin authors: warning: these get registered again on the init hook.
create_initial_taxonomies();
create_initial_post_types();

wp_start_scraping_edited_file_errors();

// Register the default theme directory root.
register_theme_directory( get_theme_root() );

if ( ! is_multisite() && wp_is_fatal_error_handler_enabled() ) {
	// Handle users requesting a recovery mode link and initiating recovery mode.
	wp_recovery_mode()->initialize();
}

// To make get_plugin_data() available in a way that's compatible with plugins also loading this file, see #62244.
require_once ABSPATH . 'wp-admin/includes/plugin.php';

// Load active plugins.
foreach ( wp_get_active_and_valid_plugins() as $plugin ) {
	wp_register_plugin_realpath( $plugin );

	$plugin_data = get_plugin_data( $plugin, false, false );

	$textdomain = $plugin_data['TextDomain'];
	if ( $textdomain ) {
		if ( $plugin_data['DomainPath'] ) {
			$GLOBALS['wp_textdomain_registry']->set_custom_path( $textdomain, dirname( $plugin ) . $plugin_data['DomainPath'] );
		} else {
			$GLOBALS['wp_textdomain_registry']->set_custom_path( $textdomain, dirname( $plugin ) );
		}
	}

	$_wp_plugin_file = $plugin;
	include_once $plugin;
	$plugin = $_wp_plugin_file; // Avoid stomping of the $plugin variable in a plugin.

	/**
	 * Fires once a single activated plugin has loaded.
	 *
	 * @since 5.1.0
	 *
	 * @param string $plugin Full path to the plugin's main file.
	 */
	do_action( 'plugin_loaded', $plugin );
}
unset( $plugin, $_wp_plugin_file, $plugin_data, $textdomain );

// Load pluggable functions.
require ABSPATH . WPINC . '/pluggable.php';
require ABSPATH . WPINC . '/pluggable-deprecated.php';

// Set internal encoding.
wp_set_internal_encoding();

// Run wp_cache_postload() if object cache is enabled and the function exists.
if ( WP_CACHE && function_exists( 'wp_cache_postload' ) ) {
	wp_cache_postload();
}

/**
 * Fires once activated plugins have loaded.
 *
 * Pluggable functions are also available at this point in the loading order.
 *
 * @since 1.5.0
 */
do_action( 'plugins_loaded' );

// Define constants which affect functionality if not already defined.
wp_functionality_constants();

// Add magic quotes and set up $_REQUEST ( $_GET + $_POST ).
wp_magic_quotes();

/**
 * Fires when comment cookies are sanitized.
 *
 * @since 2.0.11
 */
do_action( 'sanitize_comment_cookies' );

/**
 * WordPress Query object
 *
 * @since 2.0.0
 *
 * @global WP_Query $wp_the_query WordPress Query object.
 */
$GLOBALS['wp_the_query'] = new WP_Query();

/**
 * Holds the reference to {@see $wp_the_query}.
 * Use this global for WordPress queries
 *
 * @since 1.5.0
 *
 * @global WP_Query $wp_query WordPress Query object.
 */
$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'];

/**
 * Holds the WordPress Rewrite object for creating pretty URLs
 *
 * @since 1.5.0
 *
 * @global WP_Rewrite $wp_rewrite WordPress rewrite component.
 */
$GLOBALS['wp_rewrite'] = new WP_Rewrite();

/**
 * WordPress Object
 *
 * @since 2.0.0
 *
 * @global WP $wp Current WordPress environment instance.
 */
$GLOBALS['wp'] = new WP();

/**
 * WordPress Widget Factory Object
 *
 * @since 2.8.0
 *
 * @global WP_Widget_Factory $wp_widget_factory
 */
$GLOBALS['wp_widget_factory'] = new WP_Widget_Factory();

/**
 * WordPress User Roles
 *
 * @since 2.0.0
 *
 * @global WP_Roles $wp_roles WordPress role management object.
 */
$GLOBALS['wp_roles'] = new WP_Roles();

/**
 * Fires before the theme is loaded.
 *
 * @since 2.6.0
 */
do_action( 'setup_theme' );

// Define the template related constants and globals.
wp_templating_constants();
wp_set_template_globals();

// Load the default text localization domain.
load_default_textdomain();

$locale      = get_locale();
$locale_file = WP_LANG_DIR . "/$locale.php";
if ( ( 0 === validate_file( $locale ) ) && is_readable( $locale_file ) ) {
	require $locale_file;
}
unset( $locale_file );

/**
 * WordPress Locale object for loading locale domain date and various strings.
 *
 * @since 2.1.0
 *
 * @global WP_Locale $wp_locale WordPress date and time locale object.
 */
$GLOBALS['wp_locale'] = new WP_Locale();

/**
 * WordPress Locale Switcher object for switching locales.
 *
 * @since 4.7.0
 *
 * @global WP_Locale_Switcher $wp_locale_switcher WordPress locale switcher object.
 */
$GLOBALS['wp_locale_switcher'] = new WP_Locale_Switcher();
$GLOBALS['wp_locale_switcher']->init();

// Load the functions for the active theme, for both parent and child theme if applicable.
foreach ( wp_get_active_and_valid_themes() as $theme ) {
	$wp_theme = wp_get_theme( basename( $theme ) );

	$wp_theme->load_textdomain();

	if ( file_exists( $theme . '/functions.php' ) ) {
		include $theme . '/functions.php';
	}
}
unset( $theme, $wp_theme );

/**
 * Fires after the theme is loaded.
 *
 * @since 3.0.0
 */
do_action( 'after_setup_theme' );

// Create an instance of WP_Site_Health so that Cron events may fire.
if ( ! class_exists( 'WP_Site_Health' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
}
WP_Site_Health::get_instance();

// Set up current user.
$GLOBALS['wp']->init();

/**
 * Fires after WordPress has finished loading but before any headers are sent.
 *
 * Most of WP is loaded at this stage, and the user is authenticated. WP continues
 * to load on the {@see 'init'} hook that follows (e.g. widgets), and many plugins instantiate
 * themselves on it for all sorts of reasons (e.g. they need a user, a taxonomy, etc.).
 *
 * If you wish to plug an action once WP is loaded, use the {@see 'wp_loaded'} hook below.
 *
 * @since 1.5.0
 */
do_action( 'init' );

// Check site status.
if ( is_multisite() ) {
	$file = ms_site_check();
	if ( true !== $file ) {
		require $file;
		die();
	}
	unset( $file );
}

/**
 * This hook is fired once WP, all plugins, and the theme are fully loaded and instantiated.
 *
 * Ajax requests should use wp-admin/admin-ajax.php. admin-ajax.php can handle requests for
 * users not logged in.
 *
 * @link https://developer.wordpress.org/plugins/javascript/ajax
 *
 * @since 3.0.0
 */
do_action( 'wp_loaded' );
