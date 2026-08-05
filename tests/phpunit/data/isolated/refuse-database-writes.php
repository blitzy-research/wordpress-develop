<?php
/**
 * Refuses every statement that would modify the database, for probes that load WordPress.
 *
 * The isolated probes in this directory load a real WordPress install against the same
 * database as the rest of the suite, so a probe that writes to it leaves the tests that run
 * after it looking at a different site than the ones that ran before. Loading WordPress, and
 * rendering with it, writes more readily than it looks: a theme's block patterns are cached
 * in a site transient, Site Health schedules its weekly check when it is constructed,
 * `wp_head` reaches `wp_get_custom_css_post()`, which caches its lookup through
 * `set_theme_mod()`, and any nonce reaches `wp_salt()`, which persists a generated key and
 * salt whenever the configured constants are placeholders, as they are in `wp-tests-config.php`
 * where all eight are the same string.
 *
 * Closing each of those paths on its own means predicting which options core initializes
 * lazily, which is a moving target. This closes all of them at once, at the single point every
 * statement passes through: `wpdb::query()` filters a statement before running it, and treats
 * an empty one as nothing to run, reporting failure without touching the connection and
 * without recording an error. Statements that only read are handed back unchanged, so a probe
 * observes the same site the suite does. Statements that would modify it never reach the
 * server, and their callers treat the refusal the way they treat any write that fails, which
 * for a lazily initialized cache means computing the value again next time.
 *
 * `SET` is deliberately absent from the statements refused below: `wpdb` configures the
 * character set and the SQL mode of its own connection that way, and both have to succeed.
 *
 * The filter is pre-seeded into `$GLOBALS['wp_filter']`, in the shape `WP_Hook` builds
 * pre-initialized hooks from, because it has to be in place before `wp-settings.php` opens the
 * connection, which is long before the plugin API can be called. Requiring this file merges the
 * guard into whatever has been pre-seeded already, so a probe can add guards of its own on
 * either side of it.
 *
 * @package WordPress
 * @subpackage UnitTests
 */

if ( ! isset( $GLOBALS['wp_filter'] ) || ! is_array( $GLOBALS['wp_filter'] ) ) {
	$GLOBALS['wp_filter'] = array();
}

$GLOBALS['wp_filter']['query'][10][] = array(
	'accepted_args' => 1,
	'function'      => static function ( $query ) {
		$modifies = '/^\s*\(*\s*(?:INSERT|REPLACE|UPDATE|DELETE|TRUNCATE|CREATE|ALTER|DROP|RENAME|GRANT|REVOKE|LOCK|UNLOCK)\b/i';

		return preg_match( $modifies, $query ) ? '' : $query;
	},
);
