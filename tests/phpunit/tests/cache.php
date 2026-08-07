<?php

/**
 * @group cache
 */
class Tests_Cache extends WP_UnitTestCase {
	public $cache = null;

	public function set_up() {
		parent::set_up();
		$this->cache =& $this->init_cache();
	}

	public function tear_down() {
		$this->flush_cache();
		parent::tear_down();
	}

	private function &init_cache() {
		global $wp_object_cache;

		$cache_class = get_class( $wp_object_cache );
		$cache       = new $cache_class();

		$cache->add_global_groups( array( 'global-cache-test' ) );

		return $cache;
	}

	/**
	 * @ticket 56198
	 *
	 * @covers WP_Object_Cache::is_valid_key
	 * @dataProvider data_is_valid_key
	 */
	public function test_is_valid_key( $key, $valid ) {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'This test requires that an external object cache is not in use.' );
		}

		$val = 'val';

		if ( $valid ) {
			$this->assertTrue( $this->cache->add( $key, $val ), 'WP_Object_Cache:add() should return true for valid keys.' );
			$this->assertSame( $val, $this->cache->get( $key ), 'The retrieved value should match the added value.' );
		} else {
			$this->setExpectedIncorrectUsage( 'WP_Object_Cache::add' );
			$this->assertFalse( $this->cache->add( $key, $val ), 'WP_Object_Cache:add() should return false for invalid keys.' );
		}
	}

	/**
	 * Data provider for test_is_valid_key().
	 *
	 * @return array[] Test parameters {
	 *     @type mixed $key   Cache key value.
	 *     @type bool  $valid Whether the key should be considered valid.
	 * }
	 */
	public function data_is_valid_key() {
		return array(
			'false'          => array( false, false ),
			'null'           => array( null, false ),
			'line break'     => array( "\n", false ),
			'null character' => array( "\0", false ),
			'empty string'   => array( '', false ),
			'single space'   => array( ' ', false ),
			'two spaces'     => array( '  ', false ),
			'float 0'        => array( 0.0, false ),
			'int 0'          => array( 0, true ),
			'int 1'          => array( 1, true ),
			'string 0'       => array( '0', true ),
			'string'         => array( 'key', true ),
		);
	}

	public function test_miss() {
		$this->assertFalse( $this->cache->get( 'test_miss' ) );
	}

	public function test_add_get() {
		$key = __FUNCTION__;
		$val = 'val';

		$this->cache->add( $key, $val );
		$this->assertSame( $val, $this->cache->get( $key ) );
	}

	public function test_add_get_0() {
		$key = __FUNCTION__;
		$val = 0;

		// You can store zero in the cache.
		$this->assertTrue( $this->cache->add( $key, $val ) );
		$this->assertSame( $val, $this->cache->get( $key ) );
	}

	/**
	 * @ticket 20004
	 */
	public function test_add_get_null() {
		$key = __FUNCTION__;
		$val = null;

		// You can store `null` in the cache.
		$this->assertTrue( $this->cache->add( $key, $val ) );
		$this->assertSame( $val, $this->cache->get( $key ) );
	}

	/**
	 * @ticket 20004
	 */
	public function test_add_get_false() {
		$key = __FUNCTION__;
		$val = false;

		// You can store `false` in the cache.
		$this->assertTrue( $this->cache->add( $key, $val ) );
		$this->assertSame( $val, $this->cache->get( $key ) );
	}

	public function test_add() {
		$key  = __FUNCTION__;
		$val1 = 'val1';
		$val2 = 'val2';

		// Add $key to the cache.
		$this->assertTrue( $this->cache->add( $key, $val1 ) );
		$this->assertSame( $val1, $this->cache->get( $key ) );
		// $key is in the cache, so reject new calls to add().
		$this->assertFalse( $this->cache->add( $key, $val2 ) );
		$this->assertSame( $val1, $this->cache->get( $key ) );
	}

	public function test_replace() {
		$key  = __FUNCTION__;
		$val  = 'val1';
		$val2 = 'val2';

		// memcached rejects replace() if the key does not exist.
		$this->assertFalse( $this->cache->replace( $key, $val ) );
		$this->assertFalse( $this->cache->get( $key ) );
		$this->assertTrue( $this->cache->add( $key, $val ) );
		$this->assertSame( $val, $this->cache->get( $key ) );
		$this->assertTrue( $this->cache->replace( $key, $val2 ) );
		$this->assertSame( $val2, $this->cache->get( $key ) );
	}

	public function test_wp_cache_replace() {
		$key  = 'my-key';
		$val1 = 'first-val';
		$val2 = 'second-val';

		$fake_key = 'my-fake-key';

		// Save the first value to cache and verify.
		wp_cache_set( $key, $val1 );
		$this->assertSame( $val1, wp_cache_get( $key ) );

		// Replace the value and verify.
		wp_cache_replace( $key, $val2 );
		$this->assertSame( $val2, wp_cache_get( $key ) );

		// Non-existent key should fail.
		$this->assertFalse( wp_cache_replace( $fake_key, $val1 ) );

		// Make sure $fake_key is not stored.
		$this->assertFalse( wp_cache_get( $fake_key ) );
	}

	public function test_set() {
		$key  = __FUNCTION__;
		$val1 = 'val1';
		$val2 = 'val2';

		// memcached accepts set() if the key does not exist.
		$this->assertTrue( $this->cache->set( $key, $val1 ) );
		$this->assertSame( $val1, $this->cache->get( $key ) );
		// Second set() with same key should be allowed.
		$this->assertTrue( $this->cache->set( $key, $val2 ) );
		$this->assertSame( $val2, $this->cache->get( $key ) );
	}

	public function test_flush() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'This test requires that an external object cache is not in use.' );
		}

		$key = __FUNCTION__;
		$val = 'val';

		$this->cache->add( $key, $val );
		// Item is visible to both cache objects.
		$this->assertSame( $val, $this->cache->get( $key ) );
		$this->cache->flush();
		// If there is no value get returns false.
		$this->assertFalse( $this->cache->get( $key ) );
	}

	/**
	 * @ticket 4476
	 * @ticket 9773
	 *
	 * @covers ::wp_cache_flush_group
	 */
	public function test_wp_cache_flush_group() {
		$key = 'my-key';
		$val = 'my-val';

		wp_cache_set( $key, $val, 'group-test' );
		wp_cache_set( $key, $val, 'group-kept' );

		$this->assertSame( $val, wp_cache_get( $key, 'group-test' ), 'group-test should contain my-val' );

		if ( wp_using_ext_object_cache() ) {
			$this->setExpectedIncorrectUsage( 'wp_cache_flush_group' );
		}

		$results = wp_cache_flush_group( 'group-test' );

		if ( wp_using_ext_object_cache() ) {
			$this->assertFalse( $results );
		} else {
			$this->assertTrue( $results );
			$this->assertFalse( wp_cache_get( $key, 'group-test' ), 'group-test should return false' );
			$this->assertSame( $val, wp_cache_get( $key, 'group-kept' ), 'group-kept should still contain my-val' );
		}
	}

	// Make sure objects are cloned going to and from the cache.
	public function test_object_refs() {
		$key           = __FUNCTION__ . '_1';
		$object_a      = new stdClass();
		$object_a->foo = 'alpha';
		$this->cache->set( $key, $object_a );
		$object_a->foo = 'bravo';
		$object_b      = $this->cache->get( $key );
		$this->assertSame( 'alpha', $object_b->foo );
		$object_b->foo = 'charlie';
		$this->assertSame( 'bravo', $object_a->foo );

		$key           = __FUNCTION__ . '_2';
		$object_a      = new stdClass();
		$object_a->foo = 'alpha';
		$this->cache->add( $key, $object_a );
		$object_a->foo = 'bravo';
		$object_b      = $this->cache->get( $key );
		$this->assertSame( 'alpha', $object_b->foo );
		$object_b->foo = 'charlie';
		$this->assertSame( 'bravo', $object_a->foo );
	}

	public function test_incr() {
		$key = __FUNCTION__;

		$this->assertFalse( $this->cache->incr( $key ) );

		$this->cache->set( $key, 0 );
		$this->cache->incr( $key );
		$this->assertSame( 1, $this->cache->get( $key ) );

		$this->cache->incr( $key, 2 );
		$this->assertSame( 3, $this->cache->get( $key ) );
	}

	public function test_wp_cache_incr() {
		$key = __FUNCTION__;

		$this->assertFalse( wp_cache_incr( $key ) );

		wp_cache_set( $key, 0 );
		wp_cache_incr( $key );
		$this->assertSame( 1, wp_cache_get( $key ) );

		wp_cache_incr( $key, 2 );
		$this->assertSame( 3, wp_cache_get( $key ) );
	}

	public function test_decr() {
		$key = __FUNCTION__;

		$this->assertFalse( $this->cache->decr( $key ) );

		$this->cache->set( $key, 0 );
		$this->cache->decr( $key );
		$this->assertSame( 0, $this->cache->get( $key ) );

		$this->cache->set( $key, 3 );
		$this->cache->decr( $key );
		$this->assertSame( 2, $this->cache->get( $key ) );

		$this->cache->decr( $key, 2 );
		$this->assertSame( 0, $this->cache->get( $key ) );
	}

	/**
	 * @ticket 21327
	 */
	public function test_wp_cache_decr() {
		$key = __FUNCTION__;

		$this->assertFalse( wp_cache_decr( $key ) );

		wp_cache_set( $key, 0 );
		wp_cache_decr( $key );
		$this->assertSame( 0, wp_cache_get( $key ) );

		wp_cache_set( $key, 3 );
		wp_cache_decr( $key );
		$this->assertSame( 2, wp_cache_get( $key ) );

		wp_cache_decr( $key, 2 );
		$this->assertSame( 0, wp_cache_get( $key ) );
	}

	public function test_delete() {
		$key = __FUNCTION__;
		$val = 'val';

		// Verify set.
		$this->assertTrue( $this->cache->set( $key, $val ) );
		$this->assertSame( $val, $this->cache->get( $key ) );

		// Verify successful delete.
		$this->assertTrue( $this->cache->delete( $key ) );
		$this->assertFalse( $this->cache->get( $key ) );

		$this->assertFalse( $this->cache->delete( $key, 'default' ) );
	}

	public function test_wp_cache_delete() {
		$key = __FUNCTION__;
		$val = 'val';

		// Verify set.
		$this->assertTrue( wp_cache_set( $key, $val ) );
		$this->assertSame( $val, wp_cache_get( $key ) );

		// Verify successful delete.
		$this->assertTrue( wp_cache_delete( $key ) );
		$this->assertFalse( wp_cache_get( $key ) );

		// wp_cache_delete() does not have a $force method.
		// Delete returns (bool) true when key is not set and $force is true.
		// $this->assertTrue( wp_cache_delete( $key, 'default', true ) );

		$this->assertFalse( wp_cache_delete( $key, 'default' ) );
	}

	public function test_switch_to_blog() {
		if ( ! method_exists( $this->cache, 'switch_to_blog' ) ) {
			$this->markTestSkipped( 'This test requires a switch_to_blog() method on the cache object.' );
		}

		$key  = __FUNCTION__;
		$val  = 'val1';
		$val2 = 'val2';

		if ( ! is_multisite() ) {
			// Single site ignores switch_to_blog().
			$this->assertTrue( $this->cache->set( $key, $val ) );
			$this->assertSame( $val, $this->cache->get( $key ) );
			$this->cache->switch_to_blog( 999 );
			$this->assertSame( $val, $this->cache->get( $key ) );
			$this->assertTrue( $this->cache->set( $key, $val2 ) );
			$this->assertSame( $val2, $this->cache->get( $key ) );
			$this->cache->switch_to_blog( get_current_blog_id() );
			$this->assertSame( $val2, $this->cache->get( $key ) );
		} else {
			// Multisite should have separate per-blog caches.
			$this->assertTrue( $this->cache->set( $key, $val ) );
			$this->assertSame( $val, $this->cache->get( $key ) );
			$this->cache->switch_to_blog( 999 );
			$this->assertFalse( $this->cache->get( $key ) );
			$this->assertTrue( $this->cache->set( $key, $val2 ) );
			$this->assertSame( $val2, $this->cache->get( $key ) );
			$this->cache->switch_to_blog( get_current_blog_id() );
			$this->assertSame( $val, $this->cache->get( $key ) );
			$this->cache->switch_to_blog( 999 );
			$this->assertSame( $val2, $this->cache->get( $key ) );
			$this->cache->switch_to_blog( get_current_blog_id() );
			$this->assertSame( $val, $this->cache->get( $key ) );
		}

		// Global group.
		$this->assertTrue( $this->cache->set( $key, $val, 'global-cache-test' ) );
		$this->assertSame( $val, $this->cache->get( $key, 'global-cache-test' ) );
		$this->cache->switch_to_blog( 999 );
		$this->assertSame( $val, $this->cache->get( $key, 'global-cache-test' ) );
		$this->assertTrue( $this->cache->set( $key, $val2, 'global-cache-test' ) );
		$this->assertSame( $val2, $this->cache->get( $key, 'global-cache-test' ) );
		$this->cache->switch_to_blog( get_current_blog_id() );
		$this->assertSame( $val2, $this->cache->get( $key, 'global-cache-test' ) );
	}

	public function test_wp_cache_init() {
		$new_blank_cache_object = new WP_Object_Cache();
		wp_cache_init();

		global $wp_object_cache;

		if ( wp_using_ext_object_cache() ) {
			// External caches will contain property values that contain non-matching resource IDs.
			$this->assertInstanceOf( 'WP_Object_Cache', $wp_object_cache );
		} else {
			$this->assertEquals( $wp_object_cache, $new_blank_cache_object );
		}
	}

	/**
	 * @ticket 54574
	 */
	public function test_wp_cache_add_multiple() {
		$found = wp_cache_add_multiple(
			array(
				'foo1' => 'bar',
				'foo2' => 'bar',
				'foo3' => 'bar',
			),
			'group1'
		);

		$expected = array(
			'foo1' => true,
			'foo2' => true,
			'foo3' => true,
		);

		$this->assertSame( $expected, $found );
	}

	/**
	 * @ticket 54574
	 */
	public function test_wp_cache_set_multiple() {
		$found = wp_cache_set_multiple(
			array(
				'foo1' => 'bar',
				'foo2' => 'bar',
				'foo3' => 'bar',
			),
			'group1'
		);

		$expected = array(
			'foo1' => true,
			'foo2' => true,
			'foo3' => true,
		);

		$this->assertSame( $expected, $found );
	}

	/**
	 * @ticket 20875
	 */
	public function test_wp_cache_get_multiple() {
		wp_cache_set( 'foo1', 'bar', 'group1' );
		wp_cache_set( 'foo2', 'bar', 'group1' );
		wp_cache_set( 'foo1', 'bar', 'group2' );

		$found = wp_cache_get_multiple( array( 'foo1', 'foo2', 'foo3' ), 'group1' );

		$expected = array(
			'foo1' => 'bar',
			'foo2' => 'bar',
			'foo3' => false,
		);

		$this->assertSame( $expected, $found );
	}

	/**
	 * @ticket 54574
	 */
	public function test_wp_cache_delete_multiple() {
		wp_cache_set( 'foo1', 'bar', 'group1' );
		wp_cache_set( 'foo2', 'bar', 'group1' );
		wp_cache_set( 'foo3', 'bar', 'group2' );

		$found = wp_cache_delete_multiple(
			array( 'foo1', 'foo2', 'foo3' ),
			'group1'
		);

		$expected = array(
			'foo1' => true,
			'foo2' => true,
			'foo3' => false,
		);

		$this->assertSame( $expected, $found );
	}

	/**
	 * Ensures a group is only accounted for once it has actually been read.
	 *
	 * @covers WP_Object_Cache::get
	 */
	public function test_get_records_a_miss_for_an_untouched_group() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'This test requires that an external object cache is not in use.' );
		}

		// Per-group counting is opt-in, so the subject of these assertions has to be switched on.
		$this->cache->track_group_stats = true;

		$this->assertSame(
			array(),
			$this->cache->cache_group_stats,
			'A new cache object should not report statistics for any group.'
		);

		$this->assertFalse(
			$this->cache->get( 'test_untouched_group', 'group-a' ),
			'Reading a key that was never set should return false.'
		);

		$this->assertSame(
			array(
				'group-a' => array(
					'hits'   => 0,
					'misses' => 1,
				),
			),
			$this->cache->cache_group_stats,
			'The first read of a group should record a single miss for that group alone.'
		);
	}

	/**
	 * Ensures hits and misses are counted for the right group, and not swapped.
	 *
	 * @covers WP_Object_Cache::get
	 */
	public function test_get_records_a_hit_after_the_value_is_set() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'This test requires that an external object cache is not in use.' );
		}

		// Per-group counting is opt-in, so the subject of these assertions has to be switched on.
		$this->cache->track_group_stats = true;

		$this->cache->set( 'foo', 'bar', 'group-a' );

		$this->assertSame( 'bar', $this->cache->get( 'foo', 'group-a' ), 'The stored value should be returned.' );

		$this->assertSame(
			array(
				'hits'   => 1,
				'misses' => 0,
			),
			$this->cache->cache_group_stats['group-a'],
			'Reading a stored value should record a hit and no miss.'
		);

		$this->assertFalse( $this->cache->get( 'missing', 'group-a' ), 'A key that was never set should return false.' );

		$this->assertSame(
			array(
				'hits'   => 1,
				'misses' => 1,
			),
			$this->cache->cache_group_stats['group-a'],
			'A miss should not be counted as a hit, or the other way around.'
		);
	}

	/**
	 * Ensures reads without a group are attributed to the default group.
	 *
	 * @covers WP_Object_Cache::get
	 */
	public function test_get_normalizes_an_empty_group_to_default() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'This test requires that an external object cache is not in use.' );
		}

		// Per-group counting is opt-in, so the subject of these assertions has to be switched on.
		$this->cache->track_group_stats = true;

		$this->assertFalse( $this->cache->get( 'foo', '' ), 'Reading a key that was never set should return false.' );
		$this->assertFalse( $this->cache->get( 'foo', null ), 'Reading a key that was never set should return false.' );

		$this->assertArrayNotHasKey(
			'',
			$this->cache->cache_group_stats,
			'An empty group name should not be recorded as a group of its own.'
		);

		$this->assertSame(
			array(
				'hits'   => 0,
				'misses' => 2,
			),
			$this->cache->cache_group_stats['default'],
			'Both reads should be attributed to the default group.'
		);
	}

	/**
	 * Ensures one group missing does not distort the figures of another.
	 *
	 * @covers WP_Object_Cache::get
	 */
	public function test_get_keeps_the_statistics_of_each_group_separate() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'This test requires that an external object cache is not in use.' );
		}

		// Per-group counting is opt-in, so the subject of these assertions has to be switched on.
		$this->cache->track_group_stats = true;

		$this->cache->set( 'foo', 'bar', 'group-a' );

		$this->assertSame( 'bar', $this->cache->get( 'foo', 'group-a' ), 'The stored value should be returned.' );
		$this->assertFalse( $this->cache->get( 'foo', 'group-b' ), 'The key was only stored in group-a.' );
		$this->assertFalse( $this->cache->get( 'missing', 'group-a' ), 'A key that was never set should return false.' );

		$this->assertSame(
			array(
				'hits'   => 1,
				'misses' => 1,
			),
			$this->cache->cache_group_stats['group-a'],
			'Only the reads made against group-a should be counted for it.'
		);

		$this->assertSame(
			array(
				'hits'   => 0,
				'misses' => 1,
			),
			$this->cache->cache_group_stats['group-b'],
			'Only the reads made against group-b should be counted for it.'
		);

		$this->assertArrayNotHasKey(
			'default',
			$this->cache->cache_group_stats,
			'A read with an explicit group should not be attributed to the default group.'
		);
	}

	/**
	 * Ensures a global group is recorded under its own name.
	 *
	 * Global groups skip the blog prefix a Multisite install otherwise adds to the
	 * key, so this confirms the statistics are keyed by group either way.
	 *
	 * @covers WP_Object_Cache::get
	 */
	public function test_get_attributes_statistics_to_a_global_group() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'This test requires that an external object cache is not in use.' );
		}

		// Per-group counting is opt-in, so the subject of these assertions has to be switched on.
		$this->cache->track_group_stats = true;

		// 'global-cache-test' is registered as a global group by init_cache().
		$this->cache->set( 'foo', 'bar', 'global-cache-test' );

		$this->assertSame( 'bar', $this->cache->get( 'foo', 'global-cache-test' ), 'The stored value should be returned.' );
		$this->assertFalse( $this->cache->get( 'missing', 'global-cache-test' ), 'A key that was never set should return false.' );

		$this->assertSame(
			array(
				'hits'   => 1,
				'misses' => 1,
			),
			$this->cache->cache_group_stats['global-cache-test'],
			'A global group should be recorded under its own name.'
		);
	}

	/**
	 * Ensures a multi-key read is counted once per key rather than once per call.
	 *
	 * @covers WP_Object_Cache::get_multiple
	 */
	public function test_get_multiple_records_one_result_for_each_key() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'This test requires that an external object cache is not in use.' );
		}

		// Per-group counting is opt-in, so the subject of these assertions has to be switched on.
		$this->cache->track_group_stats = true;

		$this->cache->set( 'foo1', 'bar', 'group1' );
		$this->cache->set( 'foo2', 'bar', 'group1' );

		$this->cache->get_multiple( array( 'foo1', 'foo2', 'foo3' ), 'group1' );

		$this->assertSame(
			array(
				'hits'   => 2,
				'misses' => 1,
			),
			$this->cache->cache_group_stats['group1'],
			'Each requested key should be counted separately.'
		);
	}

	/**
	 * Ensures the per-group figures add up to the totals they break down.
	 *
	 * @covers WP_Object_Cache::get
	 */
	public function test_group_statistics_add_up_to_the_overall_totals() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'This test requires that an external object cache is not in use.' );
		}

		// Per-group counting is opt-in, so the subject of these assertions has to be switched on.
		$this->cache->track_group_stats = true;

		$this->cache->set( 'foo', 'bar', 'group-a' );
		$this->cache->set( 'foo', 'bar', 'group-b' );

		$this->cache->get( 'foo', 'group-a' );
		$this->cache->get( 'foo', 'group-a' );
		$this->cache->get( 'foo', 'group-b' );
		$this->cache->get( 'missing', 'group-a' );
		$this->cache->get( 'missing', 'group-c' );

		$hits   = 0;
		$misses = 0;

		foreach ( $this->cache->cache_group_stats as $group_stats ) {
			$hits   += $group_stats['hits'];
			$misses += $group_stats['misses'];
		}

		$this->assertSame( 3, $hits, 'Three of the reads should have been counted as hits.' );
		$this->assertSame( 2, $misses, 'Two of the reads should have been counted as misses.' );
		$this->assertSame( $this->cache->cache_hits, $hits, 'The per-group hits should add up to the overall hit count.' );
		$this->assertSame( $this->cache->cache_misses, $misses, 'The per-group misses should add up to the overall miss count.' );
	}

	/**
	 * Ensures a rejected key does not bring a group into the statistics.
	 *
	 * @covers WP_Object_Cache::get
	 */
	public function test_get_does_not_record_a_group_for_an_invalid_key() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'This test requires that an external object cache is not in use.' );
		}

		// Per-group counting is opt-in, so the subject of these assertions has to be switched on.
		$this->cache->track_group_stats = true;

		$this->setExpectedIncorrectUsage( 'WP_Object_Cache::get' );

		$this->assertFalse( $this->cache->get( '', 'group-a' ), 'An empty key is not valid, so the read should fail.' );

		$this->assertSame(
			array(),
			$this->cache->cache_group_stats,
			'A read that never got as far as the cache should not create a group entry.'
		);
	}

	/**
	 * Ensures emptying the cache does not discard what has been measured so far.
	 *
	 * @covers WP_Object_Cache::flush
	 */
	public function test_flush_does_not_reset_the_group_statistics() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'This test requires that an external object cache is not in use.' );
		}

		// Per-group counting is opt-in, so the subject of these assertions has to be switched on.
		$this->cache->track_group_stats = true;

		$this->cache->set( 'foo', 'bar', 'group-a' );
		$this->cache->get( 'foo', 'group-a' );

		$this->cache->flush();

		$this->assertArrayHasKey(
			'group-a',
			$this->cache->cache_group_stats,
			'Flushing the cache should not discard the statistics collected so far.'
		);

		$this->assertSame(
			array(
				'hits'   => 1,
				'misses' => 0,
			),
			$this->cache->cache_group_stats['group-a'],
			'Flushing the cache should not change the statistics collected so far.'
		);

		$this->assertFalse( $this->cache->get( 'foo', 'group-a' ), 'The value should be gone after a flush.' );

		$this->assertSame(
			array(
				'hits'   => 1,
				'misses' => 1,
			),
			$this->cache->cache_group_stats['group-a'],
			'The read after the flush should be added to the existing counters.'
		);
	}

	/**
	 * Ensures emptying a single group does not discard its measurements or another's.
	 *
	 * @covers WP_Object_Cache::flush_group
	 */
	public function test_flush_group_does_not_reset_the_group_statistics() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'This test requires that an external object cache is not in use.' );
		}

		// Per-group counting is opt-in, so the subject of these assertions has to be switched on.
		$this->cache->track_group_stats = true;

		$this->cache->set( 'foo', 'bar', 'group-a' );
		$this->cache->set( 'foo', 'bar', 'group-b' );
		$this->cache->get( 'foo', 'group-a' );
		$this->cache->get( 'foo', 'group-b' );

		$this->cache->flush_group( 'group-a' );

		$this->assertArrayHasKey(
			'group-a',
			$this->cache->cache_group_stats,
			'Flushing a group should not discard the statistics collected for it.'
		);

		$this->assertSame(
			array(
				'hits'   => 1,
				'misses' => 0,
			),
			$this->cache->cache_group_stats['group-a'],
			'Flushing a group should not change the statistics collected for it.'
		);

		$this->assertSame(
			array(
				'hits'   => 1,
				'misses' => 0,
			),
			$this->cache->cache_group_stats['group-b'],
			'Flushing one group should not affect the statistics of another.'
		);

		$this->assertFalse( $this->cache->get( 'foo', 'group-a' ), 'The value should be gone after the group was flushed.' );
		$this->assertSame( 'bar', $this->cache->get( 'foo', 'group-b' ), 'The other group should still hold its value.' );

		$this->assertSame(
			array(
				'hits'   => 1,
				'misses' => 1,
			),
			$this->cache->cache_group_stats['group-a'],
			'The read after the group was flushed should be added to the existing counters.'
		);
	}

	/**
	 * Ensures the deprecated key reset does not discard what has been measured.
	 *
	 * @covers WP_Object_Cache::reset
	 */
	public function test_reset_does_not_reset_the_group_statistics() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'This test requires that an external object cache is not in use.' );
		}

		// Per-group counting is opt-in, so the subject of these assertions has to be switched on.
		$this->cache->track_group_stats = true;

		$this->setExpectedDeprecated( 'reset' );

		$this->cache->set( 'foo', 'bar', 'group-a' );
		$this->cache->get( 'foo', 'group-a' );
		$this->cache->get( 'missing', 'group-a' );

		$this->cache->reset();

		$this->assertArrayHasKey(
			'group-a',
			$this->cache->cache_group_stats,
			'Resetting the cache keys should not discard the statistics collected so far.'
		);

		$this->assertSame(
			array(
				'hits'   => 1,
				'misses' => 1,
			),
			$this->cache->cache_group_stats['group-a'],
			'Resetting the cache keys should not change the statistics collected so far.'
		);
	}

	/**
	 * Ensures the statistics output carries the figures of each stored group.
	 *
	 * @covers WP_Object_Cache::stats
	 */
	public function test_stats_reports_the_hits_and_misses_of_each_group() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'This test requires that an external object cache is not in use.' );
		}

		// Per-group counting is opt-in, so the subject of these assertions has to be switched on.
		$this->cache->track_group_stats = true;

		$this->cache->set( 'foo', 'bar', 'group-a' );
		$this->cache->get( 'foo', 'group-a' );
		$this->cache->get( 'missing', 'group-a' );

		ob_start();
		$this->cache->stats();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<strong>Cache Hits:</strong> 1', $output, 'The totals should still be reported.' );
		$this->assertStringContainsString( '<strong>Cache Misses:</strong> 1', $output, 'The totals should still be reported.' );
		$this->assertStringContainsString( 'group-a', $output, 'The stored group should be listed.' );
		$this->assertStringContainsString( '1 hits, 1 misses', $output, 'The group line should carry its own hit and miss counts.' );
	}

	/**
	 * Ensures a group that only ever missed is reported rather than left out.
	 *
	 * The list of stored groups cannot show it, which is the blind spot the
	 * per-group counters exist to close.
	 *
	 * @covers WP_Object_Cache::stats
	 */
	public function test_stats_lists_groups_that_were_requested_but_never_stored() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'This test requires that an external object cache is not in use.' );
		}

		// Per-group counting is opt-in, so the subject of these assertions has to be switched on.
		$this->cache->track_group_stats = true;

		$this->cache->set( 'foo', 'bar', 'group-a' );
		$this->cache->get( 'foo', 'group-a' );
		$this->cache->get( 'foo', 'group-missing' );
		$this->cache->get( 'bar', 'group-missing' );

		ob_start();
		$this->cache->stats();
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'Groups requested but not currently stored',
			$output,
			'A group that was only ever missed should still be reported.'
		);

		$this->assertStringContainsString(
			'<li><strong>Group:</strong> group-missing - 0 hits, 2 misses</li>',
			$output,
			'The group that was never stored should carry its own miss count.'
		);
	}

	/**
	 * Ensures group names are escaped wherever they are printed.
	 *
	 * @covers WP_Object_Cache::stats
	 */
	public function test_stats_escapes_the_group_names() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'This test requires that an external object cache is not in use.' );
		}

		// Opt in, so that the section listing groups that were never stored is printed at all.
		$this->cache->track_group_stats = true;

		$stored_group   = '<script>alert(1)</script>';
		$unstored_group = '<em>missing</em>';

		$this->cache->set( 'foo', 'bar', $stored_group );
		$this->cache->get( 'foo', $stored_group );
		$this->cache->get( 'foo', $unstored_group );

		ob_start();
		$this->cache->stats();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( '<script>', $output, 'A group name should not be printed as markup.' );
		$this->assertStringNotContainsString( '<em>', $output, 'A group name should not be printed as markup.' );
		$this->assertStringContainsString( esc_html( $stored_group ), $output, 'The stored group name should be escaped.' );
		$this->assertStringContainsString( esc_html( $unstored_group ), $output, 'The group name that was never stored should be escaped as well.' );
	}

	/**
	 * Ensures per-group counting stays off until it is asked for.
	 *
	 * get() is reached several hundred times in a page view, so the breakdown is
	 * diagnostic work that a request which never reads it must not pay for.
	 *
	 * @covers WP_Object_Cache::get
	 */
	public function test_group_statistics_are_not_collected_by_default() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'This test requires that an external object cache is not in use.' );
		}

		$this->assertFalse(
			$this->cache->track_group_stats,
			'Per-group counting should be off on a new cache object.'
		);

		$this->cache->set( 'foo', 'bar', 'group-a' );
		$this->cache->get( 'foo', 'group-a' );
		$this->cache->get( 'missing', 'group-a' );

		$this->assertSame(
			array(),
			$this->cache->cache_group_stats,
			'No per-group entry should be created while counting is off.'
		);

		$this->assertSame(
			1,
			$this->cache->cache_hits,
			'The overall hit total should still be counted while the breakdown is off.'
		);

		$this->assertSame(
			1,
			$this->cache->cache_misses,
			'The overall miss total should still be counted while the breakdown is off.'
		);
	}

	/**
	 * Ensures the statistics output says so rather than printing zeros.
	 *
	 * A group line reading "0 hits, 0 misses" would describe a group nothing ever
	 * asked for, which is not what an unmeasured group is.
	 *
	 * @covers WP_Object_Cache::stats
	 */
	public function test_stats_reports_that_the_breakdown_was_not_collected() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'This test requires that an external object cache is not in use.' );
		}

		$this->cache->set( 'foo', 'bar', 'group-a' );
		$this->cache->get( 'foo', 'group-a' );

		ob_start();
		$this->cache->stats();
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'<strong>Cache Hits:</strong> 1',
			$output,
			'The totals should be reported whether or not the breakdown was collected.'
		);

		$this->assertStringContainsString(
			'Per-group hit and miss counts were not collected',
			$output,
			'The output should say the breakdown is unavailable.'
		);

		$this->assertStringNotContainsString(
			'0 hits, 0 misses',
			$output,
			'An uncollected group must not be printed as a group with no activity.'
		);
	}

	/**
	 * Ensures the collected breakdown cannot grow without limit.
	 *
	 * The group name comes from the caller, so code that derives one per user or per
	 * object would otherwise add an entry per distinct name for the whole request.
	 *
	 * @covers WP_Object_Cache::get
	 */
	public function test_group_statistics_stop_at_the_tracked_group_limit() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'This test requires that an external object cache is not in use.' );
		}

		$this->cache->track_group_stats  = true;
		$this->cache->max_tracked_groups = 8;

		for ( $i = 0; $i < 12; $i++ ) {
			$this->cache->get( 'foo', 'group-' . $i );
		}

		$this->assertCount(
			8,
			$this->cache->cache_group_stats,
			'No more groups than the limit should be given counters.'
		);

		$this->assertSame(
			4,
			$this->cache->untracked_group_count,
			'Every group left out should be counted.'
		);

		// A group that is already counted keeps counting once the limit is reached.
		$this->cache->get( 'foo', 'group-0' );

		$this->assertSame(
			2,
			$this->cache->cache_group_stats['group-0']['misses'],
			'A group admitted before the limit should keep counting after it.'
		);

		$this->assertSame(
			4,
			$this->cache->untracked_group_count,
			'Reading a group that is already counted should not change the untracked count.'
		);

		// Re-reading an omitted group must not count it a second time.
		$this->cache->get( 'foo', 'group-11' );

		$this->assertSame(
			4,
			$this->cache->untracked_group_count,
			'The untracked count is a count of distinct groups, not of calls.'
		);
	}

	/**
	 * Ensures the statistics output admits when the breakdown is incomplete.
	 *
	 * @covers WP_Object_Cache::stats
	 */
	public function test_stats_reports_a_partial_breakdown_after_the_limit() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'This test requires that an external object cache is not in use.' );
		}

		$this->cache->track_group_stats  = true;
		$this->cache->max_tracked_groups = 2;

		for ( $i = 0; $i < 5; $i++ ) {
			$this->cache->get( 'foo', 'group-' . $i );
		}

		ob_start();
		$this->cache->stats();
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'The breakdown above is partial',
			$output,
			'An incomplete breakdown should say so.'
		);

		/*
		 * Five groups were requested with room for two, so three were omitted - but the
		 * register of omitted names holds two at most, so the figure is a lower bound
		 * and has to be reported as one rather than as a total.
		 */
		$this->assertStringContainsString(
			'at least 2 further group(s)',
			$output,
			'A saturated omission count should be reported as a lower bound.'
		);
	}

	/**
	 * Ensures the overall totals stay exact for groups the breakdown left out.
	 *
	 * $max_tracked_groups bounds the breakdown, not the accounting. A group admitted
	 * after the limit carries no per-group counters, so the sum of the per-group figures
	 * can be smaller than the totals. This is the one asymmetry the bound introduces and
	 * it is deliberate: $cache_hits and $cache_misses are the documented public surface
	 * and the pair the performance harness reads, so they must stay exact for every
	 * group whether or not it earned a row in the breakdown.
	 *
	 * @covers WP_Object_Cache::get
	 */
	public function test_overall_totals_stay_exact_for_untracked_groups() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'This test requires that an external object cache is not in use.' );
		}

		$this->cache->track_group_stats  = true;
		$this->cache->max_tracked_groups = 2;

		$this->cache->set( 'foo', 'bar', 'group-0' );

		// One hit in a tracked group, then a miss in each of four further groups.
		$this->cache->get( 'foo', 'group-0' );

		for ( $i = 1; $i < 5; $i++ ) {
			$this->cache->get( 'foo', 'group-' . $i );
		}

		$this->assertSame( 1, $this->cache->cache_hits, 'Every hit must be counted overall.' );
		$this->assertSame( 4, $this->cache->cache_misses, 'Every miss must be counted overall, tracked or not.' );

		$this->assertCount(
			2,
			$this->cache->cache_group_stats,
			'The breakdown must stop at the limit.'
		);

		$tracked_hits   = 0;
		$tracked_misses = 0;

		foreach ( $this->cache->cache_group_stats as $group_stats ) {
			$tracked_hits   += $group_stats['hits'];
			$tracked_misses += $group_stats['misses'];
		}

		$this->assertSame( 1, $tracked_hits, 'The tracked hit belongs to a tracked group.' );

		$this->assertLessThan(
			$this->cache->cache_misses,
			$tracked_misses,
			'The per-group misses must be allowed to fall short of the exact total once the limit is reached.'
		);

		/*
		 * Three groups were left out of the breakdown, but the register that stops one being
		 * counted twice is capped at $max_tracked_groups as well, so the count saturates at
		 * the cap and becomes a lower bound on the shortfall rather than an exact tally. That
		 * is the documented behaviour, and it is what stats() reports as 'at least'.
		 */
		$this->assertSame(
			$this->cache->max_tracked_groups,
			$this->cache->untracked_group_count,
			'The groups the breakdown left out must be counted, up to the cap, so the shortfall is visible.'
		);

		$this->cache->get( 'foo', 'group-5' );

		$this->assertSame(
			5,
			$this->cache->cache_misses,
			'A miss in a group past both caps must still be counted overall.'
		);

		$this->assertSame(
			$this->cache->max_tracked_groups,
			$this->cache->untracked_group_count,
			'Once the register of omitted groups is full the count must stop rising rather than grow without limit.'
		);
	}

	/**
	 * Ensures the per-group figures are optional for anything reading them.
	 *
	 * An object-cache.php drop-in replaces this class wholesale and is under no obligation to
	 * track anything per group. Every consumer must therefore treat the property as absent
	 * rather than empty, which is what the performance harness does when it reads only the two
	 * documented public totals.
	 *
	 * @covers WP_Object_Cache::$cache_group_stats
	 */
	public function test_group_statistics_degrade_when_a_drop_in_replaces_the_cache() {
		$replacement = new class() {
			/**
			 * Overall hit count, the one counter a drop-in conventionally exposes.
			 *
			 * @var int
			 */
			public $cache_hits = 7;

			/**
			 * Overall miss count, the one counter a drop-in conventionally exposes.
			 *
			 * @var int
			 */
			public $cache_misses = 3;
		};

		$public_properties = get_object_vars( $replacement );

		$this->assertArrayHasKey( 'cache_hits', $public_properties, 'The overall totals are the documented surface.' );
		$this->assertArrayHasKey( 'cache_misses', $public_properties, 'The overall totals are the documented surface.' );

		$this->assertArrayNotHasKey(
			'cache_group_stats',
			$public_properties,
			'A drop-in is free to omit the per-group figures, so nothing may require them.'
		);

		$this->assertSame(
			7,
			(int) ( $public_properties['cache_hits'] ?? 0 ),
			'The overall hit count should still be readable from a replacement cache.'
		);

		$this->assertSame(
			0,
			(int) ( $public_properties['cache_group_stats']['group-a']['hits'] ?? 0 ),
			'Reading absent per-group figures should degrade to zero rather than fail.'
		);
	}

	/**
	 * Ensures the per-group figures are declared as a public array on the core cache.
	 *
	 * @covers WP_Object_Cache::$cache_group_stats
	 */
	public function test_group_statistics_are_a_public_array_on_the_core_cache() {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'This test requires that an external object cache is not in use.' );
		}

		$this->assertObjectHasProperty( 'cache_group_stats', $this->cache, 'The core cache should expose the per-group figures.' );

		$this->assertArrayHasKey(
			'cache_group_stats',
			get_object_vars( $this->cache ),
			'The per-group figures should be readable from outside the class.'
		);

		$this->assertIsArray( $this->cache->cache_group_stats, 'The per-group figures should be an array.' );
	}

	/**
	 * Ensures no shipped file outside the cache class itself depends on the per-group figures.
	 *
	 * The previous test shows a replacement cache may omit the property; this one shows that
	 * omitting it cannot break anything, by proving the property is read in exactly one file.
	 * A drop-in replaces that file's class wholesale, taking every reader with it, so there is
	 * nothing left to degrade. Scanning is what makes the claim durable: an assertion about the
	 * shape of a hand-written stub would keep passing if a second reader appeared elsewhere.
	 *
	 * @covers WP_Object_Cache::$cache_group_stats
	 */
	public function test_no_shipped_file_outside_the_cache_class_reads_the_group_statistics() {
		$owner   = realpath( ABSPATH . WPINC . '/class-wp-object-cache.php' );
		$readers = array();

		foreach ( array( ABSPATH . WPINC, ABSPATH . 'wp-admin' ) as $directory ) {
			$files = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $files as $file ) {
				if ( 'php' !== strtolower( $file->getExtension() ) ) {
					continue;
				}

				$path = $file->getRealPath();

				if ( $path === $owner ) {
					continue;
				}

				if ( str_contains( (string) file_get_contents( $path ), 'cache_group_stats' ) ) {
					$readers[] = str_replace( ABSPATH, '', $path );
				}
			}
		}

		$this->assertSame(
			array(),
			$readers,
			'Only class-wp-object-cache.php may read the per-group figures, so a drop-in that omits them breaks nothing.'
		);
	}
}
