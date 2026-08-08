<?php

/**
 * Covers the opt-in per-group hit and miss register on WP_Object_Cache.
 *
 * The register exists so that a request can be asked which groups its cache traffic went
 * to, rather than only how many hits and misses there were in total. Collection is off by
 * default because it is diagnostic work on every get(), so these cases assert both states:
 * that nothing is collected and nothing extra is printed while it is off, and that the
 * counts are attributed correctly, bounded, and reported once it is on.
 *
 * Every case runs against its own WP_Object_Cache instance rather than the global one, so
 * the counters observed are only the ones the case made.
 *
 * @group cache
 * @group objectcache
 *
 * @coversDefaultClass WP_Object_Cache
 */
class Tests_Cache_ObjectCacheGroupStats extends WP_UnitTestCase {

	/**
	 * A cache instance of the same class as the live one, isolated from it.
	 *
	 * @var WP_Object_Cache
	 */
	private $cache;

	public function set_up() {
		parent::set_up();

		global $wp_object_cache;

		$cache_class = get_class( $wp_object_cache );
		$this->cache = new $cache_class();
	}

	/**
	 * Skips a case when a drop-in has replaced the class these properties belong to.
	 *
	 * @return bool Whether the register under test is present.
	 */
	private function register_is_available() {
		return $this->cache instanceof WP_Object_Cache;
	}

	/**
	 * Collection is off by default and stays off until it is asked for.
	 *
	 * @covers ::get
	 */
	public function test_nothing_is_collected_by_default() {
		$this->assertFalse( $this->cache->track_group_stats, 'Collection should be off by default.' );

		$this->cache->set( 'key', 'value', 'alpha' );
		$this->cache->get( 'key', 'alpha' );
		$this->cache->get( 'absent', 'alpha' );

		$this->assertSame( array(), $this->cache->cache_group_stats, 'No group should be counted while collection is off.' );
		$this->assertSame( 1, $this->cache->cache_hits, 'The global hit counter should still count.' );
		$this->assertSame( 1, $this->cache->cache_misses, 'The global miss counter should still count.' );
	}

	/**
	 * Hits and misses are attributed to the group they were made against.
	 *
	 * @covers ::get
	 */
	public function test_hits_and_misses_are_attributed_per_group() {
		if ( ! $this->register_is_available() ) {
			$this->assertTrue( true, 'A drop-in owns the cache class, so there is no register to attribute against.' );
			return;
		}

		$this->cache->track_group_stats = true;

		$this->cache->set( 'key', 'value', 'alpha' );
		$this->cache->get( 'key', 'alpha' );
		$this->cache->get( 'key', 'alpha' );
		$this->cache->get( 'absent', 'alpha' );
		$this->cache->get( 'absent', 'beta' );

		$this->assertSame(
			array(
				'alpha' => array(
					'hits'   => 2,
					'misses' => 1,
				),
				'beta'  => array(
					'hits'   => 0,
					'misses' => 1,
				),
			),
			$this->cache->cache_group_stats,
			'Each group should carry its own counts, with the untouched counter seeded to 0.'
		);
	}

	/**
	 * The per-group totals cannot diverge from the global counters.
	 *
	 * get_multiple() delegates to get(), so it must not count twice.
	 *
	 * @covers ::get
	 * @covers ::get_multiple
	 */
	public function test_per_group_totals_match_the_global_counters() {
		if ( ! $this->register_is_available() ) {
			$this->assertTrue( true, 'A drop-in owns the cache class, so there is no register to reconcile.' );
			return;
		}

		$this->cache->track_group_stats = true;

		$this->cache->set( 'one', 1, 'alpha' );
		$this->cache->set( 'two', 2, 'beta' );

		$this->cache->get_multiple( array( 'one', 'missing' ), 'alpha' );
		$this->cache->get_multiple( array( 'two' ), 'beta' );
		$this->cache->get( 'one', 'alpha' );

		$hits   = 0;
		$misses = 0;

		foreach ( $this->cache->cache_group_stats as $group_stats ) {
			$hits   += $group_stats['hits'];
			$misses += $group_stats['misses'];
		}

		$this->assertSame( $this->cache->cache_hits, $hits, 'Per-group hits should sum to the global hit counter.' );
		$this->assertSame( $this->cache->cache_misses, $misses, 'Per-group misses should sum to the global miss counter.' );
	}

	/**
	 * Turning collection on part way through a request counts from that point on.
	 *
	 * @covers ::get
	 */
	public function test_enabling_part_way_through_counts_from_that_point() {
		if ( ! $this->register_is_available() ) {
			$this->assertTrue( true, 'A drop-in owns the cache class, so there is nothing to enable.' );
			return;
		}

		$this->cache->set( 'key', 'value', 'alpha' );
		$this->cache->get( 'key', 'alpha' );

		$this->cache->track_group_stats = true;

		$this->cache->get( 'key', 'alpha' );

		$this->assertSame(
			array(
				'alpha' => array(
					'hits'   => 1,
					'misses' => 0,
				),
			),
			$this->cache->cache_group_stats,
			'Only the call made after collection was enabled should be counted.'
		);
		$this->assertSame( 2, $this->cache->cache_hits, 'The global counter should have counted both calls.' );
	}

	/**
	 * Growth is bounded by the cap, and the groups left out are reported as a number.
	 *
	 * @covers ::get
	 */
	public function test_growth_is_bounded_by_the_cap() {
		if ( ! $this->register_is_available() ) {
			$this->assertTrue( true, 'A drop-in owns the cache class, so there is no cap to enforce.' );
			return;
		}

		$this->cache->track_group_stats  = true;
		$this->cache->max_tracked_groups = 3;

		for ( $i = 0; $i < 5; $i++ ) {
			$this->cache->get( 'key', 'group-' . $i );
		}

		// The same over-cap group again, to prove a group is not counted as omitted twice.
		$this->cache->get( 'key', 'group-4' );

		$this->assertCount( 3, $this->cache->cache_group_stats, 'The register should stop at the cap.' );
		$this->assertSame( 2, $this->cache->untracked_group_count, 'The two groups beyond the cap should be counted once each.' );
		$this->assertSame( 6, $this->cache->cache_misses, 'Every call should still be counted globally.' );
	}

	/**
	 * Counters describe the request, so they survive a flush the stored data does not.
	 *
	 * @covers ::flush
	 */
	public function test_counters_survive_a_flush() {
		if ( ! $this->register_is_available() ) {
			$this->assertTrue( true, 'A drop-in owns the cache class, so there are no counters to preserve.' );
			return;
		}

		$this->cache->track_group_stats = true;

		$this->cache->set( 'key', 'value', 'alpha' );
		$this->cache->get( 'key', 'alpha' );
		$this->cache->flush();

		$this->assertSame(
			1,
			$this->cache->cache_group_stats['alpha']['hits'],
			'A flush empties the stored data, not the record of what this request asked for.'
		);
		$this->assertSame( 1, $this->cache->cache_hits, 'The global counter has the same cumulative semantics.' );
	}

	/**
	 * The default output of stats() is unchanged, and the breakdown appears only when collected.
	 *
	 * @covers ::stats
	 */
	public function test_stats_reports_the_breakdown_only_when_it_was_collected() {
		if ( ! $this->register_is_available() ) {
			$this->assertTrue( true, 'A drop-in owns the cache class, so stats() is not the one under test.' );
			return;
		}

		$this->cache->set( 'key', 'value', 'alpha' );
		$this->cache->get( 'key', 'alpha' );

		$default_output = get_echo( array( $this->cache, 'stats' ) );

		$this->assertStringContainsString( '<strong>Cache Hits:</strong> 1', $default_output, 'The totals should always be printed.' );
		$this->assertStringContainsString( '<li><strong>Group:</strong> alpha', $default_output, 'Each stored group should always be listed.' );
		$this->assertStringNotContainsString( 'hits,', $default_output, 'No per-group counts should be printed when none were collected.' );
		$this->assertStringNotContainsString( 'track_group_stats', $default_output, 'The default output should not gain a notice.' );

		$this->cache->track_group_stats = true;
		$this->cache->get( 'key', 'alpha' );
		$this->cache->get( 'absent', 'beta' );

		$tracked_output = get_echo( array( $this->cache, 'stats' ) );

		$this->assertStringContainsString( 'alpha - ( ', $tracked_output, 'The stored group should still be listed.' );
		$this->assertStringContainsString( '1 hits, 0 misses', $tracked_output, 'The stored group should carry its counts.' );
		$this->assertStringContainsString( 'Groups requested but not currently stored', $tracked_output, 'A counted group with no entries should be reported separately.' );
		$this->assertStringContainsString( 'beta - 0 hits, 1 misses', $tracked_output, 'That group should carry its counts too.' );
	}

	/**
	 * A group name is escaped on the way out, in both output modes.
	 *
	 * @covers ::stats
	 */
	public function test_stats_escapes_group_names() {
		if ( ! $this->register_is_available() ) {
			$this->assertTrue( true, 'A drop-in owns the cache class, so stats() is not the one under test.' );
			return;
		}

		$this->cache->track_group_stats = true;

		$this->cache->set( 'key', 'value', '<script>alert(1)</script>' );
		$this->cache->get( 'key', '<script>alert(1)</script>' );

		$output = get_echo( array( $this->cache, 'stats' ) );

		$this->assertStringNotContainsString( '<script>', $output, 'A group name must not be printed unescaped.' );
		$this->assertStringContainsString( '&lt;script&gt;', $output, 'A group name should be printed escaped.' );
	}
}
