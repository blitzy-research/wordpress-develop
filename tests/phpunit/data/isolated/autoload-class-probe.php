<?php
/**
 * Reports what resolving one name through the core class autoloader does, in a fresh process.
 *
 * The autoloader in wp-includes/autoload.php needs nothing but the ABSPATH and WPINC
 * constants, so this probe loads that one file and no part of WordPress. That keeps the
 * result unaffected by anything the test process has already loaded: the requested name is
 * guaranteed to be undeclared when the probe starts, which is the only state in which
 * "loading this name declares it, from this file, and brings in nothing else" can be
 * observed at all.
 *
 * Usage:
 *
 *     php autoload-class-probe.php <abspath> <name>
 *
 * The only thing written to standard output is a single JSON object, so the caller can
 * treat any other output, and anything at all on standard error, as a failure.
 *
 * @package WordPress
 * @subpackage UnitTests
 */

if ( ! isset( $argv[1], $argv[2] ) ) {
	fwrite( STDERR, "Usage: php autoload-class-probe.php <abspath> <name>\n" );
	exit( 1 );
}

define( 'ABSPATH', $argv[1] );
define( 'WPINC', 'wp-includes' );

/**
 * Determines whether a name is declared, without letting an autoloader run.
 *
 * @param string $name Class, interface or trait name.
 * @return bool Whether the name is declared.
 */
function wp_autoload_probe_is_declared( $name ) {
	return class_exists( $name, false ) || interface_exists( $name, false ) || trait_exists( $name, false );
}

/**
 * Returns every class, interface and trait name declared so far.
 *
 * @return string[] Declared names.
 */
function wp_autoload_probe_declared_symbols() {
	return array_merge( get_declared_classes(), get_declared_interfaces(), get_declared_traits() );
}

$name = $argv[2];

require_once ABSPATH . WPINC . '/autoload.php';

$declared_before = wp_autoload_probe_is_declared( $name );
$files_before    = get_included_files();
$symbols_before  = wp_autoload_probe_declared_symbols();

/*
 * Resolved through the SPL stack rather than by calling the handler directly, which is how
 * a class reference resolves in production.
 */
$resolved = class_exists( $name ) || interface_exists( $name ) || trait_exists( $name );

$new_files   = array_values( array_diff( get_included_files(), $files_before ) );
$new_symbols = array_values( array_diff( wp_autoload_probe_declared_symbols(), $symbols_before ) );

$file      = null;
$relatives = array();

if ( $resolved ) {
	$reflection = new ReflectionClass( $name );
	$file       = $reflection->getFileName();

	/*
	 * A parent class, an extended or implemented interface and a used trait are all resolved
	 * while the target is being compiled, so the files that declare them are expected to be
	 * loaded alongside it. Reflection is used rather than class_parents()/class_uses()
	 * because it reports these the same way for a class and for an interface.
	 */
	$relatives = array_merge( $reflection->getInterfaceNames(), $reflection->getTraitNames() );

	for ( $parent = $reflection->getParentClass(); false !== $parent; $parent = $parent->getParentClass() ) {
		$relatives[] = $parent->getName();
		$relatives   = array_merge( $relatives, $parent->getInterfaceNames(), $parent->getTraitNames() );
	}

	$relatives = array_values( array_unique( $relatives ) );
}

$autoloaders = array();

foreach ( (array) spl_autoload_functions() as $autoloader ) {
	$autoloaders[] = is_string( $autoloader ) ? $autoloader : '(' . gettype( $autoloader ) . ')';
}

echo json_encode(
	array(
		'name'            => $name,
		'declared_before' => $declared_before,
		'resolved'        => $resolved,
		'file'            => $file,
		'relatives'       => $relatives,
		'new_files'       => $new_files,
		'new_symbols'     => $new_symbols,
		'autoloaders'     => $autoloaders,
	)
);
