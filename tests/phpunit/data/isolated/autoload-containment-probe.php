<?php
/**
 * Reports what the core class autoloader does with a mapped file it cannot bind.
 *
 * `wp-includes/autoload.php` wraps its `require_once` in `catch ( Error )` so that a request
 * can never end inside an autoloader. Reaching that branch needs a mapped file whose class
 * cannot be bound -- one whose parent is undeclared and undeclarable -- and the shipped class
 * map deliberately contains no such entry, because the generator refuses to emit one. So the
 * branch cannot be reached against the real tree at all, and a suite that only measures the
 * real tree leaves it untested: the `catch` can be deleted, or turned into a rethrow, and
 * every assertion still passes.
 *
 * This probe reaches it. The caller supplies a synthetic root, containing its own class map
 * and the files that map names to, and the path of the real autoloader. ABSPATH points at the
 * synthetic root, so every path the autoloader resolves comes from there, while the code doing
 * the resolving is the shipped file itself rather than a copy of it.
 *
 * A second autoloader is registered after the core one, and records every name it is asked
 * for. That record is what makes the containment observable as more than "no crash": SPL calls
 * registered autoloaders in order until the name is declared, so a name the core handler
 * declined must still reach the handler behind it. An autoloader that swallowed the error and
 * also swallowed the turn would look identical from the outside without it.
 *
 * Usage:
 *
 *     php autoload-containment-probe.php <abspath> <autoloader>
 *
 * The only thing written to standard output is a single JSON object, so the caller can treat
 * any other output, anything at all on standard error, and any nonzero exit status as a
 * failure.
 *
 * @package WordPress
 * @subpackage UnitTests
 */

if ( ! isset( $argv[1], $argv[2] ) ) {
	fwrite( STDERR, "Usage: php autoload-containment-probe.php <abspath> <autoloader>\n" );
	exit( 1 );
}

define( 'ABSPATH', $argv[1] );
define( 'WPINC', 'wp-includes' );

require $argv[2];

/*
 * Registered second on purpose. Every name the core handler declines has to arrive here, and
 * the one name this handler can declare has to end up declared, or the chain has been broken
 * rather than continued.
 */
$wp_autoload_probe_chain = array();

spl_autoload_register(
	static function ( $name ) use ( &$wp_autoload_probe_chain ) {
		$wp_autoload_probe_chain[] = $name;

		if ( 'WP_Autoload_Probe_Later' === $name ) {
			require ABSPATH . 'later/class-wp-autoload-probe-later.php';
		}
	}
);

$wp_autoload_probe_mapped = ABSPATH . 'wp-includes/class-wp-autoload-probe-throwing.php';

$wp_autoload_probe_report = array();

$wp_autoload_probe_report['handlers'] = array_map(
	static function ( $handler ) {
		return is_string( $handler ) ? $handler : gettype( $handler );
	},
	(array) spl_autoload_functions()
);

/*
 * Resolved through the SPL stack rather than by calling the handler directly, because it is
 * the stack that has to survive: a reference in production reaches the handler this way.
 */
$wp_autoload_probe_report['throwing_first'] = class_exists( 'WP_Autoload_Probe_Throwing' );

/*
 * `require_once` records a file as included before it runs it, so a file that was reached and
 * failed to bind is included while its class is not declared. That pair is what distinguishes
 * the contained error from the file never having been reached at all, which is the outcome
 * every other decline in the autoloader produces.
 */
$wp_autoload_probe_report['file_included'] = in_array( $wp_autoload_probe_mapped, get_included_files(), true );

/*
 * Asked a second time because `class_exists()` falling through to `interface_exists()` asks
 * again for the same name, and `require_once` is what keeps that second pass a decline rather
 * than a fatal redeclaration.
 */
$wp_autoload_probe_report['throwing_second'] = class_exists( 'WP_Autoload_Probe_Throwing' );

$wp_autoload_probe_report['chain'] = $wp_autoload_probe_chain;

// Only the handler registered behind the core one can declare this, so it reports the chain.
$wp_autoload_probe_report['later'] = class_exists( 'WP_Autoload_Probe_Later' );

// Mapped and bindable, so it reports that the core handler still works after containing an error.
$wp_autoload_probe_report['healthy'] = class_exists( 'WP_Autoload_Probe_Healthy' );

// Mapped to a file that is not there, which is a decline the file is never reached by.
$wp_autoload_probe_report['vanished']          = class_exists( 'WP_Autoload_Probe_Vanished' );
$wp_autoload_probe_report['vanished_included'] = in_array(
	ABSPATH . 'wp-includes/class-wp-autoload-probe-vanished.php',
	get_included_files(),
	true
);

// Emitted last, so its presence reports a process that reached the end.
$wp_autoload_probe_report['survived'] = true;

echo json_encode( $wp_autoload_probe_report );
