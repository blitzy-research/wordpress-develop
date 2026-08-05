<?php
/**
 * Reports what _wp_emoji_list() returns for one Emoji data file, in a fresh process.
 *
 * The function reads `ABSPATH . WPINC . '/emoji-arrays.php'` once and remembers the result
 * for the rest of the request, so what it does with a missing or malformed data file can
 * only be observed in a process that has not called it yet, against an ABSPATH of its own.
 * Pointing ABSPATH at a directory the caller built means the committed data file is never
 * moved, replaced or written to.
 *
 * `wp-includes/formatting.php` is loaded on its own, from the real source tree, so that the
 * function under test is the real one rather than a copy of it. It declares functions and
 * nothing else, so loading it has no side effect and needs no part of WordPress.
 *
 * Usage:
 *
 *     php emoji-list-probe.php <real-formatting-file> <fixture-abspath>
 *
 * The fixture ABSPATH is a directory holding a `wp-includes` directory, which may or may
 * not hold an `emoji-arrays.php`. The only thing written to standard output is a single
 * JSON object, so the caller can treat any other output, and anything at all on standard
 * error, as a failure.
 *
 * @package WordPress
 * @subpackage UnitTests
 */

if ( ! isset( $argv[1], $argv[2] ) ) {
	fwrite( STDERR, "Usage: php emoji-list-probe.php <real-formatting-file> <fixture-abspath>\n" );
	exit( 1 );
}

if ( ! is_readable( $argv[1] ) ) {
	fwrite( STDERR, "Cannot read {$argv[1]}.\n" );
	exit( 1 );
}

define( 'ABSPATH', rtrim( $argv[2], '/\\' ) . '/' );
define( 'WPINC', 'wp-includes' );

require_once $argv[1];

$wp_probe_default  = _wp_emoji_list();
$wp_probe_entities = _wp_emoji_list( 'entities' );
$wp_probe_partials = _wp_emoji_list( 'partials' );

// An unrecognised type falls through to the partials, which is the documented behavior.
$wp_probe_unknown = _wp_emoji_list( 'not-a-type' );

echo json_encode(
	array(
		'file_exists'         => file_exists( ABSPATH . WPINC . '/emoji-arrays.php' ),
		'default_type'        => gettype( $wp_probe_default ),
		'entities_type'       => gettype( $wp_probe_entities ),
		'partials_type'       => gettype( $wp_probe_partials ),
		'unknown_type'        => gettype( $wp_probe_unknown ),
		'default_count'       => is_array( $wp_probe_default ) ? count( $wp_probe_default ) : null,
		'entities_count'      => is_array( $wp_probe_entities ) ? count( $wp_probe_entities ) : null,
		'partials_count'      => is_array( $wp_probe_partials ) ? count( $wp_probe_partials ) : null,
		'unknown_count'       => is_array( $wp_probe_unknown ) ? count( $wp_probe_unknown ) : null,
		'default_is_entities' => $wp_probe_default === $wp_probe_entities,
		'unknown_is_partials' => $wp_probe_unknown === $wp_probe_partials,
		'entities_sample'     => is_array( $wp_probe_entities ) ? array_slice( array_values( $wp_probe_entities ), 0, 3 ) : null,
		'partials_sample'     => is_array( $wp_probe_partials ) ? array_slice( array_values( $wp_probe_partials ), 0, 3 ) : null,
	)
);
