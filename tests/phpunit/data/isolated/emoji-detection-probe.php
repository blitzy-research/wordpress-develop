<?php
/**
 * Reports what the Emoji detection script does in one scenario, in a fresh process.
 *
 * `print_emoji_detection_script()` prints at most once per request, and it remembers that
 * in a static variable inside the function. PHP offers no way to reset one, so the "prints
 * once" guarantee, and everything downstream of it, can only be observed in a process that
 * has not called the function yet. Whether the admin registration is in place is likewise
 * only observable where `wp-admin/includes/admin-filters.php` has been loaded, which the
 * test suite never does.
 *
 * Usage:
 *
 *     php emoji-detection-probe.php <config-file> <scenario> [<constant>=<value> ...]
 *
 * The only thing written to standard output is a single JSON object, so the caller can
 * treat any other output, and anything at all on standard error, as a failure. Output the
 * scenario produces is captured rather than printed, and reported as part of that object.
 *
 * Nothing here writes to the database. Rendering a head is enough to make WordPress cache a
 * lookup and persist a nonce key, so every statement that would modify anything is refused
 * before it is run; refuse-database-writes.php explains how and why. The caller checks the
 * options table is untouched.
 *
 * @package WordPress
 * @subpackage UnitTests
 */

if ( ! isset( $argv[1], $argv[2] ) ) {
	fwrite( STDERR, "Usage: php emoji-detection-probe.php <config-file> <scenario> [<constant>=<value> ...]\n" );
	exit( 1 );
}

$wp_probe_scenario = $argv[2];

$wp_probe_scenarios = array(
	'gate-default',
	'closed',
	'open',
	'open-after-footer',
	'open-repeat-after-footer',
	'closed-then-open',
	'wp-head-closed',
	'wp-head-open',
	'embed-head-open',
	'admin-registration',
);

if ( ! in_array( $wp_probe_scenario, $wp_probe_scenarios, true ) ) {
	fwrite( STDERR, "Unknown scenario {$wp_probe_scenario}.\n" );
	exit( 1 );
}

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

if ( 'admin-registration' === $wp_probe_scenario ) {
	define( 'WP_ADMIN', true );
}

define( 'WP_DEVELOPMENT_MODE', 'theme' );
define( 'DISABLE_WP_CRON', true );

$GLOBALS['wp_filter'] = array(
	'pre_schedule_event' => array(
		10 => array(
			array(
				'accepted_args' => 4,
				'function'      => static function () {
					return false;
				},
			),
		),
	),
);

require_once __DIR__ . '/refuse-database-writes.php';

require_once $argv[1];

$_SERVER['HTTP_HOST']       = defined( 'WP_TESTS_DOMAIN' ) ? WP_TESTS_DOMAIN : 'example.org';
$_SERVER['SERVER_NAME']     = $_SERVER['HTTP_HOST'];
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['PHP_SELF']        = '/index.php';
$GLOBALS['PHP_SELF']        = '/index.php';

require_once ABSPATH . 'wp-settings.php';

/**
 * Reports whether the private worker is waiting on the footer.
 *
 * @return int|false The priority it is hooked at, or false when it is not hooked.
 */
function wp_probe_emoji_worker_priority() {
	return has_action( 'wp_print_footer_scripts', '_print_emoji_detection_script' );
}

/**
 * Counts the Emoji settings elements in a document.
 *
 * The settings element carries a fixed id, so counting it counts how many times the
 * detection script was printed, however the surrounding markup changes.
 *
 * @param string $output Captured output.
 * @return int How many times the settings element appears.
 */
function wp_probe_count_emoji_settings( $output ) {
	return substr_count( $output, 'id="wp-emoji-settings"' );
}

/**
 * Runs a callback with output captured rather than printed.
 *
 * @param callable $callback Callback to run.
 * @return string Whatever the callback printed.
 */
function wp_probe_capture( $callback ) {
	ob_start();
	$callback();
	return (string) ob_get_clean();
}

$wp_probe_result = array(
	'scenario'                 => $wp_probe_scenario,
	'gate'                     => null,
	'filtered_value'           => null,
	'worker_priority_before'   => wp_probe_emoji_worker_priority(),
	'worker_priority_after'    => null,
	'output'                   => '',
	'settings_printed'         => 0,
	'settings_reprinted'       => null,
	'wp_head_priority'         => has_action( 'wp_head', 'print_emoji_detection_script' ),
	'embed_head_priority'      => has_action( 'embed_head', 'print_emoji_detection_script' ),
	'admin_scripts_priority'   => has_action( 'admin_print_scripts', 'print_emoji_detection_script' ),
	'admin_scripts_after_removal' => null,
);

/**
 * Opens the gate for the rest of the request.
 *
 * @return void
 */
function wp_probe_open_the_gate() {
	add_filter( 'should_load_emoji_detection_script', '__return_true' );
}

switch ( $wp_probe_scenario ) {
	case 'gate-default':
		$wp_probe_result['gate'] = wp_should_load_emoji_detection_script();

		add_filter(
			'should_load_emoji_detection_script',
			static function ( $should_load ) {
				$GLOBALS['wp_probe_filtered_value'] = $should_load;
				return $should_load;
			}
		);

		wp_should_load_emoji_detection_script();

		$wp_probe_result['filtered_value'] = $GLOBALS['wp_probe_filtered_value'];
		break;

	case 'closed':
		/*
		 * Called twice, because the gate is checked before the static that remembers the
		 * script was printed: neither call may print, hook or consume that one chance.
		 */
		$wp_probe_result['output'] = wp_probe_capture(
			static function () {
				print_emoji_detection_script();
				print_emoji_detection_script();
			}
		);

		$wp_probe_result['gate'] = wp_should_load_emoji_detection_script();
		break;

	case 'open':
		wp_probe_open_the_gate();

		// Called twice, so that the second call is seen not to hook the worker again.
		$wp_probe_result['output'] = wp_probe_capture(
			static function () {
				print_emoji_detection_script();
				print_emoji_detection_script();
			}
		);

		$wp_probe_result['gate'] = wp_should_load_emoji_detection_script();

		$wp_probe_result['settings_printed'] = wp_probe_count_emoji_settings(
			wp_probe_capture(
				static function () {
					do_action( 'wp_print_footer_scripts' );
				}
			)
		);
		break;

	case 'open-after-footer':
		wp_probe_open_the_gate();

		// The footer has already been printed, so there is nothing left to hook onto.
		do_action( 'wp_print_footer_scripts' );

		$wp_probe_result['output'] = wp_probe_capture( 'print_emoji_detection_script' );
		$wp_probe_result['gate']   = wp_should_load_emoji_detection_script();

		$wp_probe_result['settings_printed'] = wp_probe_count_emoji_settings( $wp_probe_result['output'] );
		break;

	case 'open-repeat-after-footer':
		wp_probe_open_the_gate();

		print_emoji_detection_script();

		$wp_probe_result['settings_printed'] = wp_probe_count_emoji_settings(
			wp_probe_capture(
				static function () {
					do_action( 'wp_print_footer_scripts' );
				}
			)
		);

		/*
		 * The footer has been printed by now, so a call that got past the static would
		 * print the script inline a second time rather than quietly doing nothing. This is
		 * the only place that guarantee shows up in the document.
		 */
		$wp_probe_result['output'] = wp_probe_capture( 'print_emoji_detection_script' );

		$wp_probe_result['settings_reprinted'] = wp_probe_count_emoji_settings( $wp_probe_result['output'] );
		$wp_probe_result['gate']               = wp_should_load_emoji_detection_script();
		break;

	case 'closed-then-open':
		// A call made while the gate is closed must not use up the one chance to print.
		print_emoji_detection_script();

		wp_probe_open_the_gate();

		$wp_probe_result['output'] = wp_probe_capture( 'print_emoji_detection_script' );
		$wp_probe_result['gate']   = wp_should_load_emoji_detection_script();

		$wp_probe_result['settings_printed'] = wp_probe_count_emoji_settings(
			wp_probe_capture(
				static function () {
					do_action( 'wp_print_footer_scripts' );
				}
			)
		);
		break;

	case 'wp-head-closed':
	case 'wp-head-open':
	case 'embed-head-open':
		if ( 'wp-head-closed' !== $wp_probe_scenario ) {
			wp_probe_open_the_gate();
		}

		$wp_probe_action = 'embed-head-open' === $wp_probe_scenario ? 'embed_head' : 'wp_head';

		$wp_probe_result['output'] = wp_probe_capture(
			static function () use ( $wp_probe_action ) {
				do_action( $wp_probe_action );
				do_action( 'wp_print_footer_scripts' );
			}
		);

		$wp_probe_result['gate']             = wp_should_load_emoji_detection_script();
		$wp_probe_result['settings_printed'] = wp_probe_count_emoji_settings( $wp_probe_result['output'] );
		break;

	case 'admin-registration':
		/*
		 * The admin registrations live in wp-admin/includes/admin-filters.php, which is
		 * reached through wp-admin/includes/admin.php. Loading that rather than
		 * wp-admin/admin.php keeps the probe to declarations and hook registrations.
		 */
		require_once ABSPATH . 'wp-admin/includes/admin.php';

		$wp_probe_result['admin_scripts_priority'] = has_action( 'admin_print_scripts', 'print_emoji_detection_script' );

		/*
		 * The opt-out core already ships, in wp-admin/edit-form-blocks.php, is a plain
		 * remove_action(). Adding a gate in front of the function must not have taken that
		 * away from anyone relying on it.
		 */
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );

		$wp_probe_result['admin_scripts_after_removal'] = has_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		break;
}

$wp_probe_result['worker_priority_after'] = wp_probe_emoji_worker_priority();

echo json_encode( $wp_probe_result );
