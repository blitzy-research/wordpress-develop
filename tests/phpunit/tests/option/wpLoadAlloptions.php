<?php
/**
 * Test wp_load_alloptions().
 *
 * @group option
 */
class Tests_Option_wpLoadAlloptions extends WP_UnitTestCase {
	protected $alloptions = null;

	public function tear_down() {
		$this->alloptions = null;
		parent::tear_down();
	}

	/**
	 * @covers ::wp_cache_get
	 */
	public function test_if_alloptions_is_cached() {
		$this->assertNotEmpty( wp_cache_get( 'alloptions', 'options' ) );
	}

	/**
	 * @ticket 42441
	 *
	 * @covers ::wp_load_alloptions
	 */
	public function test_default_and_yes() {
		add_option( 'foo', 'bar' );
		add_option( 'bar', 'foo', '', true );
		$alloptions = wp_load_alloptions();
		$this->assertArrayHasKey( 'foo', $alloptions );
		$this->assertArrayHasKey( 'bar', $alloptions );
	}

	/**
	 * @ticket 42441
	 *
	 * @covers ::wp_load_alloptions
	 */
	public function test_default_and_no() {
		add_option( 'foo', 'bar' );
		add_option( 'bar', 'foo', '', false );
		$alloptions = wp_load_alloptions();
		$this->assertArrayHasKey( 'foo', $alloptions );
		$this->assertArrayNotHasKey( 'bar', $alloptions );
	}

	/**
	 * @depends test_if_alloptions_is_cached
	 *
	 * @covers ::wp_cache_delete
	 */
	public function test_if_cached_alloptions_is_deleted() {
		$this->assertTrue( wp_cache_delete( 'alloptions', 'options' ) );
	}

	/**
	 * @depends test_if_alloptions_is_cached
	 *
	 * @covers ::wp_load_alloptions
	 */
	public function test_if_alloptions_are_retrieved_from_cache() {
		$before = get_num_queries();
		wp_load_alloptions();
		$after = get_num_queries();

		// Database has not been hit.
		$this->assertSame( $before, $after );
	}

	/**
	 * @depends test_if_cached_alloptions_is_deleted
	 *
	 * @covers ::wp_load_alloptions
	 */
	public function test_if_alloptions_are_retrieved_from_database() {
		// Delete the existing cache first.
		wp_cache_delete( 'alloptions', 'options' );

		$before = get_num_queries();
		wp_load_alloptions();
		$after = get_num_queries();

		// Database has been hit.
		$this->assertSame( $before + 1, $after );
	}

	/**
	 * @depends test_if_cached_alloptions_is_deleted
	 *
	 * @covers ::wp_load_alloptions
	 */
	public function test_filter_pre_cache_alloptions_is_called() {
		$temp = wp_installing();

		/**
		 * Set wp_installing() to false.
		 *
		 * If wp_installing is false and the cache is empty, the filter is called regardless if it's multisite or not.
		 */
		wp_installing( false );

		// Delete the existing cache first.
		wp_cache_delete( 'alloptions', 'options' );

		add_filter( 'pre_cache_alloptions', array( $this, 'return_pre_cache_filter' ) );
		$all_options = wp_load_alloptions();

		// Value could leak to other tests if not reset.
		wp_installing( $temp );

		// Filter was called.
		$this->assertSame( $this->alloptions, $all_options );
	}

	/**
	 * @depends test_if_alloptions_is_cached
	 *
	 * @covers ::wp_load_alloptions
	 */
	public function test_filter_pre_cache_alloptions_is_not_called() {
		$temp = wp_installing();

		/**
		 * Set wp_installing() to true.
		 *
		 * If wp_installing is true and it's multisite, the cache and filter are not used.
		 * If wp_installing is true and it's not multisite, the cache is used (if not empty), and the filter not.
		 */
		wp_installing( true );

		add_filter( 'pre_cache_alloptions', array( $this, 'return_pre_cache_filter' ) );
		wp_load_alloptions();

		// Value could leak to other tests if not reset.
		wp_installing( $temp );

		// Filter was not called.
		$this->assertNull( $this->alloptions );
	}

	public function return_pre_cache_filter( $alloptions ) {
		$this->alloptions = $alloptions;
		return $this->alloptions;
	}

	/**
	 * Tests that `$alloptions` can be filtered with a custom value, short circuiting `wp_load_alloptions()`.
	 *
	 * @ticket 56045
	 *
	 * @covers ::wp_load_alloptions
	 */
	public function test_filter_pre_wp_load_alloptions_filter_is_called() {
		$filter = new MockAction();

		add_filter( 'pre_wp_load_alloptions', array( &$filter, 'filter' ) );

		wp_load_alloptions();

		$this->assertSame(
			1,
			$filter->get_call_count(),
			'The filter was not called 1 time.'
		);

		$this->assertSame(
			array( 'pre_wp_load_alloptions' ),
			$filter->get_hook_names(),
			'The hook name was incorrect.'
		);
	}

	/**
	 * Tests that the primed non-autoloaded options are answered without a query of their own.
	 *
	 * Three options core reads one at a time on a front-end page view - `site_logo`,
	 * `wp_enable_real_time_collaboration` and `wp_page_for_privacy_policy` - are named in the
	 * autoload query so that they cost no query each. This is what the reduction in queries
	 * per front-end page load is made of, and it is only true if reading them afterwards is
	 * free.
	 *
	 * @covers ::wp_load_alloptions
	 */
	public function test_primed_options_are_read_without_a_query() {
		global $wpdb;

		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_load_alloptions();

		$queries_before = $wpdb->num_queries;

		foreach ( array( 'site_logo', 'wp_enable_real_time_collaboration', 'wp_page_for_privacy_policy' ) as $option ) {
			get_option( $option );
		}

		$this->assertSame(
			$queries_before,
			$wpdb->num_queries,
			'Reading a primed option should not need a query of its own.'
		);
	}

	/**
	 * Tests that a primed option that does not exist is recorded as a non-option.
	 *
	 * `notoptions` is what makes the read free, so it is asserted rather than inferred from
	 * the query count alone.
	 *
	 * @covers ::wp_load_alloptions
	 */
	public function test_a_primed_option_that_does_not_exist_is_recorded_in_notoptions() {
		delete_option( 'site_logo' );

		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_load_alloptions();

		$notoptions = wp_cache_get( 'notoptions', 'options' );

		$this->assertIsArray( $notoptions, 'The notoptions cache should have been primed.' );
		$this->assertArrayHasKey( 'site_logo', $notoptions, 'A primed option with no row should be recorded as a non-option.' );
		$this->assertFalse( get_option( 'site_logo' ), 'A primed option with no row should still read as false.' );
	}

	/**
	 * Tests that a primed option is not added to the alloptions array.
	 *
	 * The array is filtered through `pre_cache_alloptions` and `alloptions`, and it is meant
	 * to hold autoloaded options only. A primed option is cached in the `options` group
	 * instead, which `get_option()` consults immediately afterwards, so the value is found
	 * without the filters being handed something that was never autoloaded.
	 *
	 * @covers ::wp_load_alloptions
	 */
	public function test_a_primed_option_is_kept_out_of_alloptions() {
		add_option( 'site_logo', '4242', '', false );

		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'site_logo', 'options' );

		$this->alloptions = null;
		add_filter( 'pre_cache_alloptions', array( $this, 'return_pre_cache_filter' ) );

		$alloptions = wp_load_alloptions();

		$this->assertArrayNotHasKey( 'site_logo', $alloptions, 'A non-autoloaded primed option must not be returned as an autoloaded one.' );
		$this->assertIsArray( $this->alloptions, 'The pre_cache_alloptions filter should have run.' );
		$this->assertArrayNotHasKey( 'site_logo', $this->alloptions, 'The pre_cache_alloptions filter must not be handed a non-autoloaded option.' );
		$this->assertSame( '4242', get_option( 'site_logo' ), 'The primed option must still read back its stored value.' );
	}

	/**
	 * Tests that a primed option that is autoloaded is still returned as an autoloaded one.
	 *
	 * The widened query matches the name whether or not the row is autoloaded, so the two
	 * cases have to be told apart by the row's autoload value rather than by the name.
	 *
	 * @covers ::wp_load_alloptions
	 */
	public function test_a_primed_option_that_is_autoloaded_stays_in_alloptions() {
		add_option( 'site_logo', 'auto-on', '', true );

		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		$alloptions = wp_load_alloptions();

		$this->assertArrayHasKey( 'site_logo', $alloptions, 'An autoloaded option must be returned as one even when it is also named for priming.' );
		$this->assertSame( 'auto-on', $alloptions['site_logo'], 'The autoloaded value was not returned.' );

		$notoptions = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $notoptions ) ) {
			$this->assertArrayNotHasKey( 'site_logo', $notoptions, 'An option that exists must never be recorded as a non-option.' );
		}
	}

	/**
	 * Tests that a serialized primed option is unserialized when it is read.
	 *
	 * The cache is primed with the raw column value, exactly as `wp_prime_option_caches()`
	 * does, and `get_option()` is what unserializes it.
	 *
	 * @covers ::wp_load_alloptions
	 */
	public function test_a_serialized_primed_option_round_trips() {
		$value = array(
			'id'  => 7,
			'url' => 'https://example.org/logo.png',
		);

		add_option( 'site_logo', $value, '', false );

		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'site_logo', 'options' );

		wp_load_alloptions();

		$this->assertSame( $value, get_option( 'site_logo' ), 'A serialized primed option must be unserialized on read.' );
	}

	/**
	 * Tests that the primed option list can be filtered, including down to nothing.
	 *
	 * @covers ::wp_load_alloptions
	 */
	public function test_the_primed_option_list_is_filterable() {
		add_filter(
			'prime_options_with_alloptions',
			static function ( $names ) {
				$names[] = 'a_name_no_site_has';
				return $names;
			}
		);

		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_load_alloptions();

		$notoptions = wp_cache_get( 'notoptions', 'options' );

		$this->assertIsArray( $notoptions, 'The notoptions cache should have been primed.' );
		$this->assertArrayHasKey( 'a_name_no_site_has', $notoptions, 'A filtered-in name should be primed.' );

		remove_all_filters( 'prime_options_with_alloptions' );
		add_filter( 'prime_options_with_alloptions', '__return_empty_array' );

		wp_cache_delete( 'alloptions', 'options' );
		$alloptions = wp_load_alloptions();

		$this->assertArrayHasKey( 'siteurl', $alloptions, 'Filtering the primed list down to nothing must still load the autoloaded options.' );
	}
}
