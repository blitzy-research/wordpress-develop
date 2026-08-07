<?php
/**
 * Reports what loading WordPress in one request context does, in a fresh process.
 *
 * Whether a file is loaded during the bootstrap can only be observed in a process that
 * has not loaded it yet, and the test process has already loaded WordPress once, as an
 * admin-shaped request, before the first test runs. Every context therefore has to be
 * measured in a process of its own, which is what this probe is.
 *
 * Usage:
 *
 *     php bootstrap-context-probe.php <config-file> <context> [<constant>=<value> ...]
 *
 * The context is one of `front-end`, `front-end-class-resolution`,
 * `front-end-cron-dispatch`, `admin`, `cron`, `rest` or `shortinit`. Any further arguments
 * are constants to define before WordPress is loaded, which is how the caller passes down
 * the Multisite configuration of the run it is being called from, so that the probe
 * measures the same installation the caller is testing against.
 *
 * The only thing written to standard output is a single JSON object, so the caller can
 * treat any other output, and anything at all on standard error, as a failure.
 *
 * Nothing here may write to the database. Two bootstrap paths would otherwise do so, and
 * both are closed before WordPress is loaded: the theme's block patterns are cached in a
 * site transient, which `WP_DEVELOPMENT_MODE` prevents, and Site Health schedules its
 * weekly check on construction, which a pre-initialized `pre_schedule_event` filter
 * short-circuits. Behind both of them, refuse-database-writes.php refuses any statement that
 * would modify anything, so a path that starts writing later cannot quietly start mutating the
 * database the rest of the suite shares. The caller checks the options table is untouched
 * either way.
 *
 * @package WordPress
 * @subpackage UnitTests
 */

if ( ! isset( $argv[1], $argv[2] ) ) {
	fwrite( STDERR, "Usage: php bootstrap-context-probe.php <config-file> <context> [<constant>=<value> ...]\n" );
	exit( 1 );
}

$wp_probe_context = $argv[2];

if ( ! in_array( $wp_probe_context, array( 'front-end', 'front-end-class-resolution', 'front-end-cron-dispatch', 'admin', 'cron', 'rest', 'shortinit' ), true ) ) {
	fwrite( STDERR, "Unknown context {$wp_probe_context}.\n" );
	exit( 1 );
}

/*
 * Constants handed down by the caller, before anything else defines them. Only a bare
 * integer is treated as a number, so that a domain of digits stays a string.
 */
foreach ( array_slice( $argv, 3 ) as $wp_probe_argument ) {
	$wp_probe_pair = explode( '=', $wp_probe_argument, 2 );

	if ( 2 !== count( $wp_probe_pair ) ) {
		fwrite( STDERR, "Cannot read the constant {$wp_probe_argument}.\n" );
		exit( 1 );
	}

	if ( 'true' === $wp_probe_pair[1] || 'false' === $wp_probe_pair[1] ) {
		define( $wp_probe_pair[0], 'true' === $wp_probe_pair[1] );
	} elseif ( (string) (int) $wp_probe_pair[1] === $wp_probe_pair[1] ) {
		define( $wp_probe_pair[0], (int) $wp_probe_pair[1] );
	} else {
		define( $wp_probe_pair[0], $wp_probe_pair[1] );
	}
}

switch ( $wp_probe_context ) {
	case 'admin':
		define( 'WP_ADMIN', true );
		break;

	case 'cron':
		define( 'DOING_CRON', true );
		break;

	case 'shortinit':
		define( 'SHORTINIT', true );
		break;
}

/*
 * Keeps the theme's block patterns from being cached in a site transient, which is the
 * one write a plain front-end bootstrap would otherwise make.
 */
define( 'WP_DEVELOPMENT_MODE', 'theme' );

// Nothing here dispatches cron, and Site Health reads this when it is constructed.
define( 'DISABLE_WP_CRON', true );

/*
 * Registered before the plugin API exists, through the array WP_Hook is built from, so
 * that it is already in place when Site Health is constructed at the end of the
 * bootstrap and tries to schedule its weekly check.
 */
$GLOBALS['wp_probe_schedule_attempts'] = 0;
$GLOBALS['wp_probe_scheduled_events']  = array();
$GLOBALS['wp_filter']                  = array(
	'pre_option_active_plugins' => array(
		10 => array(
			array(
				'accepted_args' => 3,
				'function'      => static function () {
					return array(
						'bootstrap-explicit/bootstrap-explicit.php',
						'bootstrap-fallback/bootstrap-fallback.php',
					);
				},
			),
		),
	),
	'pre_schedule_event' => array(
		10 => array(
			array(
				'accepted_args' => 3,
				'function'      => static function ( $pre, $event ) {
					if ( 'wp_site_health_scheduled_check' === $event->hook ) {
						++$GLOBALS['wp_probe_schedule_attempts'];
						$GLOBALS['wp_probe_scheduled_events'][] = array(
							'hook'      => $event->hook,
							'timestamp' => $event->timestamp,
							'schedule'  => $event->schedule,
							'args'      => $event->args,
						);

						return true;
					}

					return false;
				},
			),
		),
	),
	'pre_get_scheduled_event' => array(
		10 => array(
			array(
				'accepted_args' => 4,
				'function'      => static function ( $pre, $hook ) {
					if ( 'wp_site_health_scheduled_check' === $hook ) {
						return false;
					}

					return $pre;
				},
			),
		),
	),
);

require_once __DIR__ . '/refuse-database-writes.php';

require_once $argv[1];

if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', dirname( __DIR__ ) . '/plugins' );
}

// A request needs a host to resolve against, which Multisite in particular relies on.
$_SERVER['HTTP_HOST']       = defined( 'WP_TESTS_DOMAIN' ) ? WP_TESTS_DOMAIN : 'example.org';
$_SERVER['SERVER_NAME']     = $_SERVER['HTTP_HOST'];
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['PHP_SELF']        = '/index.php';
$GLOBALS['PHP_SELF']        = '/index.php';

/**
 * Determines whether Site Health is declared, without letting an autoloader run.
 *
 * @return bool Whether the class is declared.
 */
function wp_probe_site_health_declared() {
	return class_exists( 'WP_Site_Health', false );
}

/**
 * Returns the names of the hooks Site Health registers when it is constructed.
 *
 * Read from the class rather than listed here, so that the probe keeps reporting on
 * whatever the constructor registers rather than on a copy of it that can go stale.
 *
 * @return array Hook name to whether a Site Health callback is registered on it.
 */
function wp_probe_site_health_hooks() {
	$hooks = array(
		'admin_body_class'               => false,
		'admin_enqueue_scripts'          => false,
		'wp_site_health_scheduled_check' => false,
		'site_health_tab_content'        => false,
	);

	foreach ( array_keys( $hooks ) as $hook_name ) {
		if ( ! isset( $GLOBALS['wp_filter'][ $hook_name ] ) ) {
			continue;
		}

		foreach ( $GLOBALS['wp_filter'][ $hook_name ] as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof WP_Site_Health ) {
					$hooks[ $hook_name ] = true;
				}
			}
		}
	}

	return $hooks;
}

/**
 * Describes every callback registered on the Site Health weekly check.
 *
 * The identity, the priority and the accepted argument count are all part of what a site
 * observes, and all three were changed by an earlier attempt to construct Site Health
 * conditionally. Reporting them is what lets the caller pin them to what base registers,
 * so that the same change cannot be made again without a test saying so.
 *
 * @return array[] One entry per callback, each with its priority, identity and argument count.
 */
function wp_probe_site_health_cron_callbacks() {
	$hook = 'wp_site_health_scheduled_check';

	if ( ! isset( $GLOBALS['wp_filter'][ $hook ] ) ) {
		return array();
	}

	$callbacks = array();
	$hook_object = $GLOBALS['wp_filter'][ $hook ];

	foreach ( $hook_object as $priority => $registered ) {
		foreach ( $registered as $callback ) {
			$function = $callback['function'];

			if ( is_array( $function ) ) {
				$identity = ( is_object( $function[0] ) ? get_class( $function[0] ) : (string) $function[0] ) . '::' . $function[1];
			} elseif ( is_string( $function ) ) {
				$identity = $function;
			} else {
				$identity = '(' . gettype( $function ) . ')';
			}

			$callbacks[] = array(
				'priority'      => (int) $priority,
				'identity'      => $identity,
				'accepted_args' => (int) $callback['accepted_args'],
			);
		}
	}

	return $callbacks;
}

/**
 * Returns the mapped files that were loaded, as paths relative to the WordPress root.
 *
 * Every value in the generated class map names a file the bootstrap is supposed to have
 * stopped requiring, so the ones that are loaded anyway are the complete list of exceptions
 * to the deferral. Reporting the list rather than its length is what makes a re-eagered
 * file identifiable rather than merely countable.
 *
 * @return string[] Sorted relative paths of mapped files that are loaded.
 */
function wp_probe_eager_mapped_files() {
	$classmap_file = ABSPATH . WPINC . '/autoload-classmap.php';

	if ( ! file_exists( $classmap_file ) ) {
		return array();
	}

	$classmap = require $classmap_file;

	if ( ! is_array( $classmap ) ) {
		return array();
	}

	$root   = wp_normalize_path( ABSPATH );
	$loaded = array();

	foreach ( array_map( 'wp_normalize_path', get_included_files() ) as $file ) {
		if ( 0 === strpos( $file, $root ) ) {
			$loaded[ substr( $file, strlen( $root ) ) ] = true;
		}
	}

	$eager = array_values( array_intersect( array_unique( array_values( $classmap ) ), array_keys( $loaded ) ) );

	sort( $eager );

	return $eager;
}

$wp_probe_declared_before = wp_probe_site_health_declared();

require_once ABSPATH . 'wp-settings.php';

$wp_probe_plugin_paths = array();
if ( isset( $GLOBALS['wp_textdomain_registry'] ) ) {
	$wp_probe_custom_paths = new ReflectionProperty( 'WP_Textdomain_Registry', 'custom_paths' );
	if ( PHP_VERSION_ID < 80100 ) {
		$wp_probe_custom_paths->setAccessible( true );
	}
	$wp_probe_plugin_paths = $wp_probe_custom_paths->getValue( $GLOBALS['wp_textdomain_registry'] );
}

$wp_probe_included_files = array_map( 'wp_normalize_path', get_included_files() );
$wp_probe_plugin_api     = wp_normalize_path( ABSPATH . 'wp-admin/includes/plugin.php' );

$wp_probe_result = array(
	'context'               => $wp_probe_context,
	'multisite'             => function_exists( 'is_multisite' ) && is_multisite(),
	'autoloader_registered' => in_array( 'wp_autoload_class', array_filter( (array) spl_autoload_functions(), 'is_string' ), true ),
	'declared_before'       => $wp_probe_declared_before,
	'declared_after'        => wp_probe_site_health_declared(),
	'instantiated'          => false,
	'hooks'                 => array(),
	'files'                 => count( get_included_files() ),
	'schedule_attempts'     => $GLOBALS['wp_probe_schedule_attempts'],
	'scheduled_events'      => $GLOBALS['wp_probe_scheduled_events'],
	'cron_handler'          => false !== has_action( 'wp_site_health_scheduled_check' ),
	'cron_callbacks'        => wp_probe_site_health_cron_callbacks(),
	'eager_mapped_files'    => wp_probe_eager_mapped_files(),
	'plugin'                => array(
		'api_file_loaded'   => in_array( $wp_probe_plugin_api, $wp_probe_included_files, true ),
		'api_available'     => function_exists( 'get_plugin_data' ),
		'observations'      => isset( $GLOBALS['wp_bootstrap_plugin_observations'] ) ? $GLOBALS['wp_bootstrap_plugin_observations'] : array(),
		'translation_paths' => array(
			'bootstrap-explicit-domain' => isset( $wp_probe_plugin_paths['bootstrap-explicit-domain'] ) ? $wp_probe_plugin_paths['bootstrap-explicit-domain'] : null,
			'bootstrap-fallback'        => isset( $wp_probe_plugin_paths['bootstrap-fallback'] ) ? $wp_probe_plugin_paths['bootstrap-fallback'] : null,
		),
	),
	'class_resolution'      => null,
	'cron_dispatch'         => null,
	'rest'                  => null,
);

if ( 'front-end-class-resolution' === $wp_probe_context ) {
	$wp_probe_files_before_resolution = array_map( 'wp_normalize_path', get_included_files() );
	$wp_probe_main_class_resolved     = class_exists( 'wp_site_health' );
	$wp_probe_auto_class_resolved     = class_exists( '\wp_site_health_auto_updates' );
	$wp_probe_files_after_resolution  = array_map( 'wp_normalize_path', get_included_files() );

	$wp_probe_result['class_resolution'] = array(
		'main'              => $wp_probe_main_class_resolved,
		'auto_updates'      => $wp_probe_auto_class_resolved,
		'main_file_loaded'  => in_array( wp_normalize_path( ABSPATH . 'wp-admin/includes/class-wp-site-health.php' ), $wp_probe_files_after_resolution, true ),
		'auto_file_loaded'  => in_array( wp_normalize_path( ABSPATH . 'wp-admin/includes/class-wp-site-health-auto-updates.php' ), $wp_probe_files_after_resolution, true ),
		'files_before'      => count( $wp_probe_files_before_resolution ),
		'files_after'       => count( $wp_probe_files_after_resolution ),
	);
}

if ( 'front-end-cron-dispatch' === $wp_probe_context ) {
	wp_using_ext_object_cache( true );
	add_filter(
		'site_status_tests',
		static function () {
			return array(
				'direct' => array(),
				'async'  => array(),
			);
		},
		PHP_INT_MAX
	);

	$wp_probe_declared_before_dispatch = wp_probe_site_health_declared();
	do_action( 'wp_site_health_scheduled_check' );

	$wp_probe_result['cron_dispatch'] = array(
		'declared_before'      => $wp_probe_declared_before_dispatch,
		'declared_after'       => wp_probe_site_health_declared(),
		'instantiated_after'   => ( new ReflectionProperty( 'WP_Site_Health', 'instance' ) )->getValue() instanceof WP_Site_Health,
		'instance_handler'     => wp_probe_site_health_hooks()['wp_site_health_scheduled_check'],
		'status_result'        => wp_cache_get( 'health-check-site-status-result', 'transient' ),
		'schedule_attempts'    => $GLOBALS['wp_probe_schedule_attempts'],
	);
}

if ( ! defined( 'SHORTINIT' ) || ! SHORTINIT ) {
	$wp_probe_result['hooks'] = wp_probe_site_health_hooks();

	if ( $wp_probe_result['declared_after'] ) {
		/*
		 * get_instance() would construct the singleton, which would make every context
		 * look alike. The stored instance is read instead.
		 */
		$wp_probe_instance = new ReflectionProperty( 'WP_Site_Health', 'instance' );

		$wp_probe_result['instantiated'] = $wp_probe_instance->getValue() instanceof WP_Site_Health;
	}
}

/*
 * A REST request only differs in that a server is created, which is the single place
 * `rest_api_init` is fired from, and therefore the only time the REST routes are
 * registered. The ordering is what is observed here, measured on a REST controller rather
 * than on Site Health, because the controllers are the classes the bootstrap genuinely
 * stopped loading: `create_initial_rest_routes()` runs on `rest_api_init` at priority 99,
 * and referencing a controller name there is what loads it through the class map. So a
 * callback at any priority before that sees the name still undeclared, and any callback
 * that asks for it gets it anyway.
 *
 * Both halves matter. That no earlier priority has the name declared is what says the
 * deferral is real rather than undone by something loading the file during the bootstrap;
 * that a callback at one of those priorities can still resolve the name is what says the
 * deferral costs a plugin nothing, since a plugin's own `rest_api_init` callback is exactly
 * the code a broken deferral would break.
 *
 * The two reads are ordered deliberately. The non-autoloading read can be taken at every
 * priority without disturbing the next one, while the resolving read is taken only at the
 * last probed priority, after that priority's non-autoloading read, because resolving the
 * name is what loads the class.
 */
if ( 'rest' === $wp_probe_context ) {
	$wp_probe_seen       = array();
	$wp_probe_resolvable = null;

	foreach ( array( 1, 50, 98 ) as $wp_probe_priority ) {
		add_action(
			'rest_api_init',
			static function () use ( &$wp_probe_seen, &$wp_probe_resolvable, $wp_probe_priority ) {
				$wp_probe_seen[ $wp_probe_priority ] = class_exists( 'WP_REST_Posts_Controller', false );

				if ( 98 === $wp_probe_priority ) {
					$wp_probe_resolvable = class_exists( 'WP_REST_Posts_Controller' );
				}
			},
			$wp_probe_priority
		);
	}

	$wp_probe_declared_before_server = wp_probe_site_health_declared();
	$wp_probe_routes                 = array_keys( rest_get_server()->get_routes() );
	$wp_probe_sorted_routes          = $wp_probe_routes;

	sort( $wp_probe_sorted_routes );

	$wp_probe_result['rest'] = array(
		'declared_before_server'    => $wp_probe_declared_before_server,
		'declared_after_server'     => wp_probe_site_health_declared(),
		'instantiated_after_server' => ( new ReflectionProperty( 'WP_Site_Health', 'instance' ) )->getValue() instanceof WP_Site_Health,
		'hooks_after_server'        => wp_probe_site_health_hooks(),
		'seen_at_priority'          => $wp_probe_seen,
		'resolvable_at_priority_98' => $wp_probe_resolvable,
		'routes'                    => count( $wp_probe_routes ),
		/*
		 * Reported in full, and sorted, so that the caller can pin the inventory rather than
		 * its size. A deferral that dropped one namespace while a plugin added another would
		 * leave the count unchanged.
		 */
		'route_list'                => $wp_probe_sorted_routes,
		'site_health_routes'        => count(
			array_values(
				array_filter(
					$wp_probe_routes,
					static function ( $route ) {
						return 0 === strpos( $route, '/wp-site-health/v1' );
					}
				)
			)
		),
	);
}

echo json_encode( $wp_probe_result );
