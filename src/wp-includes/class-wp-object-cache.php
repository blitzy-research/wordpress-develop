<?php
/**
 * Object Cache API: WP_Object_Cache class
 *
 * @package WordPress
 * @subpackage Cache
 * @since 5.4.0
 */

/**
 * Core class that implements an object cache.
 *
 * The WordPress Object Cache is used to save on trips to the database. The
 * Object Cache stores all of the cache data to memory and makes the cache
 * contents available by using a key, which is used to name and later retrieve
 * the cache contents.
 *
 * The Object Cache can be replaced by other caching mechanisms by placing files
 * in the wp-content folder which is looked at in wp-settings. If that file
 * exists, then this file will not be included.
 *
 * @since 2.0.0
 */
#[AllowDynamicProperties]
class WP_Object_Cache {

	/**
	 * Holds the cached objects.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	private $cache = array();

	/**
	 * The amount of times the cache data was already stored in the cache.
	 *
	 * @since 2.5.0
	 * @var int
	 */
	public $cache_hits = 0;

	/**
	 * Amount of times the cache did not have the request in cache.
	 *
	 * @since 2.0.0
	 * @var int
	 */
	public $cache_misses = 0;

	/**
	 * Per-group cache hit and miss counts.
	 *
	 * Empty unless $track_group_stats has been enabled. See that property for why
	 * collection is opt-in, and stats() for how the counts are reported.
	 *
	 * @since 7.0.0
	 * @var array<string, array{hits: int, misses: int}>
	 */
	public $cache_group_stats = array();

	/**
	 * Whether to count hits and misses per group as well as in total.
	 *
	 * Off by default, because get() is one of the most frequently called methods in
	 * a request - a front-end page view reaches it several hundred times - and the
	 * totals in $cache_hits and $cache_misses answer every question core itself
	 * asks. Collecting a second, per-group tally on every one of those calls is
	 * diagnostic work, so it is paid for only when something asks for it.
	 *
	 * Enable it to find which groups a request actually misses in, for example from
	 * a debugging plugin or a profiling harness:
	 *
	 *     wp_cache_get_object()->track_group_stats = true;
	 *
	 * Turning it on part way through a request is supported: the counts then
	 * describe the calls made from that point on rather than the whole request,
	 * which is why anything reading them should enable it as early as it can.
	 *
	 * @since 7.0.0
	 * @var bool
	 */
	public $track_group_stats = false;

	/**
	 * Largest number of groups $cache_group_stats will hold.
	 *
	 * A cap rather than unbounded growth, because the group name is supplied by the
	 * caller: code that derives a group per user, per site or per object would
	 * otherwise make this array grow with the request. Once the cap is reached, no
	 * new group is added and $untracked_group_count records how many were left out,
	 * so stats() can report that the breakdown is partial instead of appearing
	 * complete. Groups already being counted keep counting.
	 *
	 * @since 7.0.0
	 * @var int
	 */
	public $max_tracked_groups = 250;

	/**
	 * Number of distinct groups left out of $cache_group_stats because of the cap.
	 *
	 * @since 7.0.0
	 * @var int
	 */
	public $untracked_group_count = 0;

	/**
	 * Group names seen after the cap was reached, so each is only counted once.
	 *
	 * Holds names rather than a plain total because the count it feeds is a count of
	 * distinct groups. It is itself capped, so it cannot become the unbounded array
	 * that $max_tracked_groups exists to prevent.
	 *
	 * @since 7.0.0
	 * @var array<string, true>
	 */
	private $untracked_groups = array();

	/**
	 * List of global cache groups.
	 *
	 * @since 3.0.0
	 * @var string[]
	 */
	protected $global_groups = array();

	/**
	 * The blog prefix to prepend to keys in non-global groups.
	 *
	 * @since 3.5.0
	 * @var string
	 */
	private $blog_prefix;

	/**
	 * Holds the value of is_multisite().
	 *
	 * @since 3.5.0
	 * @var bool
	 */
	private $multisite;

	/**
	 * Sets up object properties.
	 *
	 * @since 2.0.8
	 */
	public function __construct() {
		$this->multisite   = is_multisite();
		$this->blog_prefix = $this->multisite ? get_current_blog_id() . ':' : '';
	}

	/**
	 * Makes private properties readable for backward compatibility.
	 *
	 * @since 4.0.0
	 *
	 * @param string $name Property to get.
	 * @return mixed Property.
	 */
	public function __get( $name ) {
		return $this->$name;
	}

	/**
	 * Makes private properties settable for backward compatibility.
	 *
	 * @since 4.0.0
	 *
	 * @param string $name  Property to set.
	 * @param mixed  $value Property value.
	 */
	public function __set( $name, $value ) {
		$this->$name = $value;
	}

	/**
	 * Makes private properties checkable for backward compatibility.
	 *
	 * @since 4.0.0
	 *
	 * @param string $name Property to check if set.
	 * @return bool Whether the property is set.
	 */
	public function __isset( $name ) {
		return isset( $this->$name );
	}

	/**
	 * Makes private properties un-settable for backward compatibility.
	 *
	 * @since 4.0.0
	 *
	 * @param string $name Property to unset.
	 */
	public function __unset( $name ) {
		unset( $this->$name );
	}

	/**
	 * Serves as a utility function to determine whether a key is valid.
	 *
	 * @since 6.1.0
	 *
	 * @param int|string $key Cache key to check for validity.
	 * @return bool Whether the key is valid.
	 */
	protected function is_valid_key( $key ) {
		if ( is_int( $key ) ) {
			return true;
		}

		if ( is_string( $key ) && trim( $key ) !== '' ) {
			return true;
		}

		$type = gettype( $key );

		if ( ! function_exists( '__' ) ) {
			wp_load_translations_early();
		}

		$message = is_string( $key )
			? __( 'Cache key must not be an empty string.' )
			/* translators: %s: The type of the given cache key. */
			: sprintf( __( 'Cache key must be an integer or a non-empty string, %s given.' ), $type );

		_doing_it_wrong(
			sprintf( '%s::%s', __CLASS__, debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 2 )[1]['function'] ),
			$message,
			'6.1.0'
		);

		return false;
	}

	/**
	 * Serves as a utility function to determine whether a key exists in the cache.
	 *
	 * @since 3.4.0
	 *
	 * @param int|string $key   Cache key to check for existence.
	 * @param string     $group Cache group for the key existence check.
	 * @return bool Whether the key exists in the cache for the given group.
	 */
	protected function _exists( $key, $group ) {
		return isset( $this->cache[ $group ] ) && ( isset( $this->cache[ $group ][ $key ] ) || array_key_exists( $key, $this->cache[ $group ] ) );
	}

	/**
	 * Adds data to the cache if it doesn't already exist.
	 *
	 * @since 2.0.0
	 *
	 * @uses WP_Object_Cache::_exists() Checks to see if the cache already has data.
	 * @uses WP_Object_Cache::set()     Sets the data after the checking the cache
	 *                                  contents existence.
	 *
	 * @param int|string $key    What to call the contents in the cache.
	 * @param mixed      $data   The contents to store in the cache.
	 * @param string     $group  Optional. Where to group the cache contents. Default 'default'.
	 * @param int        $expire Optional. When to expire the cache contents, in seconds.
	 *                           Default 0 (no expiration).
	 * @return bool True on success, false if cache key and group already exist.
	 */
	public function add( $key, $data, $group = 'default', $expire = 0 ) {
		if ( wp_suspend_cache_addition() ) {
			return false;
		}

		if ( ! $this->is_valid_key( $key ) ) {
			return false;
		}

		if ( empty( $group ) ) {
			$group = 'default';
		}

		$id = $key;
		if ( $this->multisite && ! isset( $this->global_groups[ $group ] ) ) {
			$id = $this->blog_prefix . $key;
		}

		if ( $this->_exists( $id, $group ) ) {
			return false;
		}

		return $this->set( $key, $data, $group, (int) $expire );
	}

	/**
	 * Adds multiple values to the cache in one call.
	 *
	 * @since 6.0.0
	 *
	 * @param array  $data   Array of keys and values to be added.
	 * @param string $group  Optional. Where the cache contents are grouped. Default empty.
	 * @param int    $expire Optional. When to expire the cache contents, in seconds.
	 *                       Default 0 (no expiration).
	 * @return bool[] Array of return values, grouped by key. Each value is either
	 *                true on success, or false if cache key and group already exist.
	 */
	public function add_multiple( array $data, $group = '', $expire = 0 ) {
		$values = array();

		foreach ( $data as $key => $value ) {
			$values[ $key ] = $this->add( $key, $value, $group, $expire );
		}

		return $values;
	}

	/**
	 * Replaces the contents in the cache, if contents already exist.
	 *
	 * @since 2.0.0
	 *
	 * @see WP_Object_Cache::set()
	 *
	 * @param int|string $key    What to call the contents in the cache.
	 * @param mixed      $data   The contents to store in the cache.
	 * @param string     $group  Optional. Where to group the cache contents. Default 'default'.
	 * @param int        $expire Optional. When to expire the cache contents, in seconds.
	 *                           Default 0 (no expiration).
	 * @return bool True if contents were replaced, false if original value does not exist.
	 */
	public function replace( $key, $data, $group = 'default', $expire = 0 ) {
		if ( ! $this->is_valid_key( $key ) ) {
			return false;
		}

		if ( empty( $group ) ) {
			$group = 'default';
		}

		$id = $key;
		if ( $this->multisite && ! isset( $this->global_groups[ $group ] ) ) {
			$id = $this->blog_prefix . $key;
		}

		if ( ! $this->_exists( $id, $group ) ) {
			return false;
		}

		return $this->set( $key, $data, $group, (int) $expire );
	}

	/**
	 * Sets the data contents into the cache.
	 *
	 * The cache contents are grouped by the $group parameter followed by the
	 * $key. This allows for duplicate IDs in unique groups. Therefore, naming of
	 * the group should be used with care and should follow normal function
	 * naming guidelines outside of core WordPress usage.
	 *
	 * The $expire parameter is not used, because the cache will automatically
	 * expire for each time a page is accessed and PHP finishes. The method is
	 * more for cache plugins which use files.
	 *
	 * @since 2.0.0
	 * @since 6.1.0 Returns false if cache key is invalid.
	 *
	 * @param int|string $key    What to call the contents in the cache.
	 * @param mixed      $data   The contents to store in the cache.
	 * @param string     $group  Optional. Where to group the cache contents. Default 'default'.
	 * @param int        $expire Optional. Not used.
	 * @return bool True if contents were set, false if key is invalid.
	 */
	public function set( $key, $data, $group = 'default', $expire = 0 ) {
		if ( ! $this->is_valid_key( $key ) ) {
			return false;
		}

		if ( empty( $group ) ) {
			$group = 'default';
		}

		if ( $this->multisite && ! isset( $this->global_groups[ $group ] ) ) {
			$key = $this->blog_prefix . $key;
		}

		if ( is_object( $data ) ) {
			$data = clone $data;
		}

		$this->cache[ $group ][ $key ] = $data;
		return true;
	}

	/**
	 * Sets multiple values to the cache in one call.
	 *
	 * @since 6.0.0
	 *
	 * @param array  $data   Array of key and value to be set.
	 * @param string $group  Optional. Where the cache contents are grouped. Default empty.
	 * @param int    $expire Optional. When to expire the cache contents, in seconds.
	 *                       Default 0 (no expiration).
	 * @return bool[] Array of return values, grouped by key. Each value is always true.
	 */
	public function set_multiple( array $data, $group = '', $expire = 0 ) {
		$values = array();

		foreach ( $data as $key => $value ) {
			$values[ $key ] = $this->set( $key, $value, $group, $expire );
		}

		return $values;
	}

	/**
	 * Retrieves the cache contents, if it exists.
	 *
	 * The contents will be first attempted to be retrieved by searching by the
	 * key in the cache group. If the cache is hit (success) then the contents
	 * are returned.
	 *
	 * On failure, the number of cache misses will be incremented.
	 *
	 * @since 2.0.0
	 *
	 * @param int|string $key   The key under which the cache contents are stored.
	 * @param string     $group Optional. Where the cache contents are grouped. Default 'default'.
	 * @param bool       $force Optional. Unused. Whether to force an update of the local cache
	 *                          from the persistent cache. Default false.
	 * @param bool|null  $found Optional. Whether the key was found in the cache (passed by reference).
	 *                          Disambiguates a return of false, a storable value. Default null.
	 * @return mixed|false The cache contents on success, false on failure to retrieve contents.
	 */
	public function get( $key, $group = 'default', $force = false, &$found = null ) {
		if ( ! $this->is_valid_key( $key ) ) {
			return false;
		}

		if ( empty( $group ) ) {
			$group = 'default';
		}

		if ( $this->multisite && ! isset( $this->global_groups[ $group ] ) ) {
			$key = $this->blog_prefix . $key;
		}

		if ( $this->_exists( $key, $group ) ) {
			$found             = true;
			$this->cache_hits += 1;

			if ( $this->track_group_stats ) {
				$this->record_group_stat( $group, 'hits' );
			}

			if ( is_object( $this->cache[ $group ][ $key ] ) ) {
				return clone $this->cache[ $group ][ $key ];
			} else {
				return $this->cache[ $group ][ $key ];
			}
		}

		$found               = false;
		$this->cache_misses += 1;

		if ( $this->track_group_stats ) {
			$this->record_group_stat( $group, 'misses' );
		}

		return false;
	}

	/**
	 * Counts one hit or miss against a group.
	 *
	 * Only reached while $track_group_stats is enabled, so the cost of the lookups
	 * below is never paid by a request that has not asked for the breakdown.
	 *
	 * A group that arrives after $max_tracked_groups groups are already counted is
	 * not given counters. It is recorded in $untracked_group_count instead, so the
	 * breakdown reports itself as partial rather than silently omitting the group.
	 *
	 * @since 7.0.0
	 *
	 * @param string $group Group the call was made against.
	 * @param string $field Either 'hits' or 'misses'.
	 */
	private function record_group_stat( $group, $field ) {
		if ( isset( $this->cache_group_stats[ $group ] ) ) {
			++$this->cache_group_stats[ $group ][ $field ];
			return;
		}

		if ( count( $this->cache_group_stats ) < $this->max_tracked_groups ) {
			// Seed both counters together, so the other one reads as 0 rather than unset.
			$this->cache_group_stats[ $group ] = array(
				'hits'   => 0,
				'misses' => 0,
			);

			++$this->cache_group_stats[ $group ][ $field ];
			return;
		}

		/*
		 * Beyond the cap only the number of distinct groups is kept, and the names
		 * are held solely to avoid counting one group twice. That register is capped
		 * as well, so this branch cannot grow without limit either; once it is full
		 * $untracked_group_count stops rising and becomes a lower bound, which is
		 * what stats() reports it as.
		 */
		if ( ! isset( $this->untracked_groups[ $group ] )
			&& count( $this->untracked_groups ) < $this->max_tracked_groups
		) {
			$this->untracked_groups[ $group ] = true;
			++$this->untracked_group_count;
		}
	}

	/**
	 * Retrieves multiple values from the cache in one call.
	 *
	 * @since 5.5.0
	 *
	 * @param array  $keys  Array of keys under which the cache contents are stored.
	 * @param string $group Optional. Where the cache contents are grouped. Default 'default'.
	 * @param bool   $force Optional. Whether to force an update of the local cache
	 *                      from the persistent cache. Default false.
	 * @return array Array of return values, grouped by key. Each value is either
	 *               the cache contents on success, or false on failure.
	 */
	public function get_multiple( $keys, $group = 'default', $force = false ) {
		$values = array();

		foreach ( $keys as $key ) {
			$values[ $key ] = $this->get( $key, $group, $force );
		}

		return $values;
	}

	/**
	 * Removes the contents of the cache key in the group.
	 *
	 * If the cache key does not exist in the group, then nothing will happen.
	 *
	 * @since 2.0.0
	 *
	 * @param int|string $key        What the contents in the cache are called.
	 * @param string     $group      Optional. Where the cache contents are grouped. Default 'default'.
	 * @param bool       $deprecated Optional. Unused. Default false.
	 * @return bool True on success, false if the contents were not deleted.
	 */
	public function delete( $key, $group = 'default', $deprecated = false ) {
		if ( ! $this->is_valid_key( $key ) ) {
			return false;
		}

		if ( empty( $group ) ) {
			$group = 'default';
		}

		if ( $this->multisite && ! isset( $this->global_groups[ $group ] ) ) {
			$key = $this->blog_prefix . $key;
		}

		if ( ! $this->_exists( $key, $group ) ) {
			return false;
		}

		unset( $this->cache[ $group ][ $key ] );
		return true;
	}

	/**
	 * Deletes multiple values from the cache in one call.
	 *
	 * @since 6.0.0
	 *
	 * @param array  $keys  Array of keys to be deleted.
	 * @param string $group Optional. Where the cache contents are grouped. Default empty.
	 * @return bool[] Array of return values, grouped by key. Each value is either
	 *                true on success, or false if the contents were not deleted.
	 */
	public function delete_multiple( array $keys, $group = '' ) {
		$values = array();

		foreach ( $keys as $key ) {
			$values[ $key ] = $this->delete( $key, $group );
		}

		return $values;
	}

	/**
	 * Increments numeric cache item's value.
	 *
	 * @since 3.3.0
	 *
	 * @param int|string $key    The cache key to increment.
	 * @param int        $offset Optional. The amount by which to increment the item's value.
	 *                           Default 1.
	 * @param string     $group  Optional. The group the key is in. Default 'default'.
	 * @return int|false The item's new value on success, false on failure.
	 */
	public function incr( $key, $offset = 1, $group = 'default' ) {
		if ( ! $this->is_valid_key( $key ) ) {
			return false;
		}

		if ( empty( $group ) ) {
			$group = 'default';
		}

		if ( $this->multisite && ! isset( $this->global_groups[ $group ] ) ) {
			$key = $this->blog_prefix . $key;
		}

		if ( ! $this->_exists( $key, $group ) ) {
			return false;
		}

		if ( ! is_numeric( $this->cache[ $group ][ $key ] ) ) {
			$this->cache[ $group ][ $key ] = 0;
		}

		$offset = (int) $offset;

		$this->cache[ $group ][ $key ] += $offset;

		if ( $this->cache[ $group ][ $key ] < 0 ) {
			$this->cache[ $group ][ $key ] = 0;
		}

		return $this->cache[ $group ][ $key ];
	}

	/**
	 * Decrements numeric cache item's value.
	 *
	 * @since 3.3.0
	 *
	 * @param int|string $key    The cache key to decrement.
	 * @param int        $offset Optional. The amount by which to decrement the item's value.
	 *                           Default 1.
	 * @param string     $group  Optional. The group the key is in. Default 'default'.
	 * @return int|false The item's new value on success, false on failure.
	 */
	public function decr( $key, $offset = 1, $group = 'default' ) {
		if ( ! $this->is_valid_key( $key ) ) {
			return false;
		}

		if ( empty( $group ) ) {
			$group = 'default';
		}

		if ( $this->multisite && ! isset( $this->global_groups[ $group ] ) ) {
			$key = $this->blog_prefix . $key;
		}

		if ( ! $this->_exists( $key, $group ) ) {
			return false;
		}

		if ( ! is_numeric( $this->cache[ $group ][ $key ] ) ) {
			$this->cache[ $group ][ $key ] = 0;
		}

		$offset = (int) $offset;

		$this->cache[ $group ][ $key ] -= $offset;

		if ( $this->cache[ $group ][ $key ] < 0 ) {
			$this->cache[ $group ][ $key ] = 0;
		}

		return $this->cache[ $group ][ $key ];
	}

	/**
	 * Clears the object cache of all data.
	 *
	 * @since 2.0.0
	 *
	 * @return true Always returns true.
	 */
	public function flush() {
		$this->cache = array();

		return true;
	}

	/**
	 * Removes all cache items in a group.
	 *
	 * @since 6.1.0
	 *
	 * @param string $group Name of group to remove from cache.
	 * @return true Always returns true.
	 */
	public function flush_group( $group ) {
		unset( $this->cache[ $group ] );

		return true;
	}

	/**
	 * Sets the list of global cache groups.
	 *
	 * @since 3.0.0
	 *
	 * @param string|string[] $groups List of groups that are global.
	 */
	public function add_global_groups( $groups ) {
		$groups = (array) $groups;

		$groups              = array_fill_keys( $groups, true );
		$this->global_groups = array_merge( $this->global_groups, $groups );
	}

	/**
	 * Switches the internal blog ID.
	 *
	 * This changes the blog ID used to create keys in blog specific groups.
	 *
	 * @since 3.5.0
	 *
	 * @param int $blog_id Blog ID.
	 */
	public function switch_to_blog( $blog_id ) {
		$blog_id           = (int) $blog_id;
		$this->blog_prefix = $this->multisite ? $blog_id . ':' : '';
	}

	/**
	 * Resets cache keys.
	 *
	 * @since 3.0.0
	 *
	 * @deprecated 3.5.0 Use WP_Object_Cache::switch_to_blog()
	 * @see switch_to_blog()
	 */
	public function reset() {
		_deprecated_function( __FUNCTION__, '3.5.0', 'WP_Object_Cache::switch_to_blog()' );

		// Clear out non-global caches since the blog ID has changed.
		foreach ( array_keys( $this->cache ) as $group ) {
			if ( ! isset( $this->global_groups[ $group ] ) ) {
				unset( $this->cache[ $group ] );
			}
		}
	}

	/**
	 * Echoes the stats of the caching.
	 *
	 * Prints the hit and miss totals, then each group currently held in the cache
	 * with its serialized size.
	 *
	 * Per-group hit and miss counts are printed alongside each group only when
	 * $track_group_stats was enabled, since that is what collects them; when it was
	 * not, the breakdown is reported as unavailable rather than as a row of zeros
	 * that would read like a group nothing ever asked for. Any group that has
	 * counters but no current entries, and any group left out by
	 * $max_tracked_groups, is reported after the list.
	 *
	 * @since 2.0.0
	 * @since 7.0.0 Added the opt-in per-group hit and miss counts.
	 */
	public function stats() {
		echo '<p>';
		echo "<strong>Cache Hits:</strong> {$this->cache_hits}<br />";
		echo "<strong>Cache Misses:</strong> {$this->cache_misses}<br />";
		echo '</p>';
		echo '<ul>';
		foreach ( $this->cache as $group => $cache ) {
			$size = number_format( strlen( serialize( $cache ) ) / KB_IN_BYTES, 2 ) . 'k';

			if ( $this->track_group_stats ) {
				$group_hits   = (int) ( $this->cache_group_stats[ $group ]['hits'] ?? 0 );
				$group_misses = (int) ( $this->cache_group_stats[ $group ]['misses'] ?? 0 );

				echo '<li><strong>Group:</strong> ' . esc_html( $group ) . ' - ( ' . $size . ' ) - ' . $group_hits . ' hits, ' . $group_misses . ' misses</li>';
			} else {
				echo '<li><strong>Group:</strong> ' . esc_html( $group ) . ' - ( ' . $size . ' )</li>';
			}
		}
		echo '</ul>';

		if ( ! $this->track_group_stats ) {
			echo '<p>Per-group hit and miss counts were not collected. Set <code>$track_group_stats</code> on the cache object to collect them.</p>';
			return;
		}

		/*
		 * Request counters accumulate for the whole request, while stored data can be
		 * removed by delete(), flush() or flush_group(). A group can therefore hold
		 * counters in $cache_group_stats while being absent from $this->cache, so
		 * those groups are reported separately rather than left out.
		 */
		$unstored_groups = array_diff_key( $this->cache_group_stats, $this->cache );

		if ( ! empty( $unstored_groups ) ) {
			echo '<p><strong>Groups requested but not currently stored:</strong></p>';
			echo '<ul>';
			foreach ( $unstored_groups as $group => $group_stats ) {
				echo '<li><strong>Group:</strong> ' . esc_html( $group ) . ' - ' . (int) ( $group_stats['hits'] ?? 0 ) . ' hits, ' . (int) ( $group_stats['misses'] ?? 0 ) . ' misses</li>';
			}
			echo '</ul>';
		}

		if ( $this->untracked_group_count > 0 ) {
			/*
			 * The register of omitted names is capped as well, so once it is full the
			 * number of omitted groups is a lower bound rather than a total, and is
			 * reported as one.
			 */
			$omitted = count( $this->untracked_groups ) >= $this->max_tracked_groups
				? sprintf( 'at least %d', (int) $this->untracked_group_count )
				: (string) (int) $this->untracked_group_count;

			printf(
				'<p>The breakdown above is partial: %1$s further group(s) were requested after the limit of %2$d tracked groups was reached and have no counters.</p>',
				$omitted,
				(int) $this->max_tracked_groups
			);
		}
	}
}
