<?php
/**
 * Replacement object cache used by the isolated autoloader probe.
 *
 * @package WordPress
 * @subpackage UnitTests
 */

/**
 * Replacement cache implementation.
 */
class WP_Object_Cache {

	/**
	 * Identifies which implementation owns the symbol.
	 *
	 * @var string
	 */
	public $implementation = 'drop-in';
}

/**
 * Initializes the replacement cache.
 */
function wp_cache_init() {
	$GLOBALS['wp_object_cache'] = new WP_Object_Cache();
}
