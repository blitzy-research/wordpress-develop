<?php
/**
 * Reports whether the core autoloader leaves WP_Object_Cache to a replacement drop-in.
 *
 * Usage:
 *
 *     php object-cache-dropin-probe.php <abspath> <drop-in>
 *
 * @package WordPress
 * @subpackage UnitTests
 */

if ( ! isset( $argv[1], $argv[2] ) ) {
	fwrite( STDERR, "Usage: php object-cache-dropin-probe.php <abspath> <drop-in>\n" );
	exit( 1 );
}

define( 'ABSPATH', $argv[1] );
define( 'WPINC', 'wp-includes' );

require_once ABSPATH . WPINC . '/autoload.php';

$files_before = get_included_files();
$core_claimed = class_exists( 'WP_Object_Cache' );

if ( ! $core_claimed ) {
	require $argv[2];
	wp_cache_init();
}

$reflection = class_exists( 'WP_Object_Cache', false ) ? new ReflectionClass( 'WP_Object_Cache' ) : null;

echo json_encode(
	array(
		'core_claimed'          => $core_claimed,
		'replacement_declared'  => isset( $GLOBALS['wp_object_cache'] ) && $GLOBALS['wp_object_cache'] instanceof WP_Object_Cache,
		'implementation'        => isset( $GLOBALS['wp_object_cache']->implementation ) ? $GLOBALS['wp_object_cache']->implementation : null,
		'declaration_file'      => $reflection ? $reflection->getFileName() : null,
		'new_files'             => array_values( array_diff( get_included_files(), $files_before ) ),
	)
);
