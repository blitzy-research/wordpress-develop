<?php

/**
 * Tests for the request-scoped memo that answers repeated `map_meta_cap()` calls.
 *
 * The memo is only correct while it is impossible for two identical calls to have
 * different answers, so most of what is proven here is the set of cases the memo
 * declines to answer: a registered `map_meta_cap` callback, a metadata capability, a
 * non-scalar argument, a call that carries no further arguments at all, and any call
 * whose capability or user cannot be described faithfully in a key. Each of those
 * bypasses is proven by observing the key builder return an empty string, meaning
 * "do not memoize", and where a behavioral proof is possible it is given as well.
 *
 * A memo hit is proven twice. Once from the outside, by mutating a capability map in
 * place - which fires no action and changes no registry size, so the memo key stays
 * identical - and showing that the mutation is invisible until the memo is discarded.
 * And once from the inside, by storing a sentinel under the key `map_meta_cap()` would
 * compute and showing that `map_meta_cap()` returns it.
 *
 * @group user
 * @group capabilities
 *
 * @covers ::map_meta_cap
 * @covers ::_wp_map_meta_cap_memo_key
 * @covers ::_wp_map_meta_cap_memo
 * @covers ::_wp_reset_map_meta_cap_memo
 * @covers ::_wp_reset_map_meta_cap_memo_on_user_meta
 */
class Tests_User_MapMetaCapMemo extends WP_UnitTestCase {

	/**
	 * Capability name stored in the memo to prove where an answer came from.
	 *
	 * It is not a real capability, so it can only ever appear in a result that was
	 * read back out of the memo rather than computed by the switch.
	 */
	const SENTINEL_CAP = 'mmcm_sentinel_cap';

	/**
	 * Capability name written into a capability map to prove a memo hit.
	 */
	const MUTATED_CAP = 'mmcm_mutated_cap';

	/**
	 * The actions the memo is discarded on, in the order they are registered.
	 *
	 * Kept as a literal list rather than read back out of `$wp_filter`, so that losing
	 * a registration fails a test instead of shrinking the expectation with it.
	 *
	 * @var string[]
	 */
	const INVALIDATION_HOOKS = array(
		'clean_post_cache',
		'added_post_meta',
		'updated_post_meta',
		'deleted_post_meta',
		'clean_comment_cache',
		'clean_term_cache',
		'clean_user_cache',
		'set_user_role',
		'add_user_role',
		'remove_user_role',
		'granted_super_admin',
		'revoked_super_admin',
		'registered_post_type',
		'unregistered_post_type',
		'registered_taxonomy',
		'unregistered_taxonomy',
		'registered_post_status',
		'added_option',
		'updated_option',
		'deleted_option',
		'add_site_option',
		'update_site_option',
		'delete_site_option',
		'switch_blog',
	);

	/**
	 * The user metadata actions the memo is discarded on, for the capability key only.
	 *
	 * Kept separate from self::INVALIDATION_HOOKS because these carry the metadata key
	 * as an argument and are answered by a callback that inspects it, rather than by the
	 * unconditional reset.
	 *
	 * @var string[]
	 */
	const USER_META_INVALIDATION_HOOKS = array(
		'added_user_meta',
		'updated_user_meta',
		'deleted_user_meta',
	);

	/**
	 * Every metadata capability the switch handles, all of which must be declined.
	 *
	 * @var string[]
	 */
	const METADATA_CAPS = array(
		'edit_post_meta',
		'delete_post_meta',
		'add_post_meta',
		'edit_comment_meta',
		'delete_comment_meta',
		'add_comment_meta',
		'edit_term_meta',
		'delete_term_meta',
		'add_term_meta',
		'edit_user_meta',
		'delete_user_meta',
		'add_user_meta',
	);

	/**
	 * Every filter a memoizable mapping reads, in the order the implementation watches them.
	 *
	 * A callback on any of these may answer differently for identical arguments, or
	 * differently the next time it is asked, and none of them fires an action when it is
	 * registered or removed. No mapping is memoized for as long as one carries a
	 * callback.
	 *
	 * Kept as a literal list rather than read back out of the implementation, so that
	 * dropping a name from the watch list fails a test instead of shrinking the
	 * expectation along with it.
	 *
	 * @var string[]
	 */
	const POLICY_FILTERS = array(
		'map_meta_cap',
		'pre_option',
		'alloptions',
		'pre_wp_load_alloptions',
		'pre_cache_alloptions',
		'pre_option_page_for_posts',
		'default_option_page_for_posts',
		'option_page_for_posts',
		'pre_option_page_on_front',
		'default_option_page_on_front',
		'option_page_on_front',
		'pre_option_wp_page_for_privacy_policy',
		'default_option_wp_page_for_privacy_policy',
		'option_wp_page_for_privacy_policy',
		'get_post_metadata',
		'default_post_metadata',
		'update_post_metadata_cache',
		'get_post_status',
		'get_comment',
	);

	/**
	 * Every capability whose mapping is never memoized, whatever it is asked about.
	 *
	 * Each of these reads at least one input no static list of hook names can describe:
	 * the capabilities the user themselves holds, the answer to
	 * `wp_is_file_mod_allowed()`, a dynamically named taxonomy filter or option, or the
	 * `link_manager_enabled` option whose default core supplies unconditionally.
	 *
	 * Kept as a literal list for the same reason as self::POLICY_FILTERS: a capability
	 * quietly becoming memoizable is exactly the regression worth failing on.
	 *
	 * @var string[]
	 */
	const UNMEMOIZABLE_CAPS = array(
		'remove_user',
		'edit_user',
		'edit_users',
		'delete_user',
		'delete_users',
		'create_users',
		'unfiltered_upload',
		'edit_css',
		'unfiltered_html',
		'update_php',
		'update_https',
		'activate_plugins',
		'deactivate_plugins',
		'activate_plugin',
		'deactivate_plugin',
		'edit_files',
		'edit_plugins',
		'edit_themes',
		'update_plugins',
		'delete_plugins',
		'install_plugins',
		'upload_plugins',
		'update_themes',
		'delete_themes',
		'install_themes',
		'upload_themes',
		'update_core',
		'install_languages',
		'update_languages',
		'edit_term',
		'delete_term',
		'assign_term',
		'manage_links',
		'create_app_password',
		'list_app_passwords',
		'read_app_password',
		'edit_app_password',
		'delete_app_passwords',
		'delete_app_password',
	);

	/**
	 * Hooks that look like policy filters but are deliberately not watched.
	 *
	 * Each of these is read only by a mapping in self::UNMEMOIZABLE_CAPS, which is
	 * declined by name, so watching them here would decline mappings no callback on them
	 * can reach. Two of them - `user_has_cap` and
	 * `default_option_link_manager_enabled` - are also registered unconditionally by
	 * core itself, so a callback on them says nothing about third party involvement and
	 * watching them would decline every mapping on every request.
	 *
	 * @var string[]
	 */
	const UNWATCHED_HOOKS = array(
		'user_has_cap',
		'site_admins',
		'file_mod_allowed',
		'pre_option_link_manager_enabled',
		'default_option_link_manager_enabled',
		'option_link_manager_enabled',
		'pre_site_option_add_new_users',
		'site_option_menu_items',
	);

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected static $administrator_id;

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	protected static $editor_id;

	/**
	 * Author user ID, used as the author of the shared fixture post.
	 *
	 * @var int
	 */
	protected static $author_id;

	/**
	 * Subscriber user ID, used as the user who is not the author.
	 *
	 * @var int
	 */
	protected static $subscriber_id;

	/**
	 * ID of a published post authored by self::$author_id.
	 *
	 * @var int
	 */
	protected static $post_id;

	/**
	 * Number of times the dynamic `map_meta_cap` callback has been called.
	 *
	 * @var int
	 */
	protected $filter_calls = 0;

	/**
	 * Number of watched invalidation actions that have fired during a test.
	 *
	 * @var int
	 */
	protected $invalidation_fires = 0;

	/**
	 * Number of incorrect usage reports the mapping has made during a test.
	 *
	 * @var int
	 */
	protected $incorrect_usage_reports = 0;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$administrator_id = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$editor_id        = $factory->user->create( array( 'role' => 'editor' ) );
		self::$author_id        = $factory->user->create( array( 'role' => 'author' ) );
		self::$subscriber_id    = $factory->user->create( array( 'role' => 'subscriber' ) );

		self::$post_id = $factory->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_author' => self::$author_id,
			)
		);
	}

	public function set_up() {
		parent::set_up();

		/*
		 * The memo lives in a static, and `phpunit.xml.dist` sets backupGlobals="false"
		 * while one PHP process runs the whole suite, so it outlives a single test unless
		 * something discards it. Discarding it here is exactly what production code does
		 * on every state mutation, and it makes each test below start from a known state
		 * no matter what ran before it.
		 */
		_wp_reset_map_meta_cap_memo();

		$this->filter_calls            = 0;
		$this->invalidation_fires      = 0;
		$this->incorrect_usage_reports = 0;
	}

	public function tear_down() {
		_wp_reset_map_meta_cap_memo();

		parent::tear_down();
	}

	/**
	 * Builds the memo key for a call and asserts that the call is memoizable.
	 *
	 * @param string $cap     Capability being checked.
	 * @param int    $user_id User ID.
	 * @param array  $args    Further parameters passed to `map_meta_cap()`.
	 * @return string Canonical memo key.
	 */
	private function assert_memoizable( $cap, $user_id, $args ) {
		$key = _wp_map_meta_cap_memo_key( $cap, $user_id, $args );

		$this->assertIsString( $key, 'The memo key should always be a string.' );
		$this->assertNotSame( '', $key, sprintf( 'A %s call with these arguments should be memoizable.', $cap ) );

		return $key;
	}

	/**
	 * Stores the sentinel result under a key and asserts that it is readable again.
	 *
	 * @param string $key Canonical memo key.
	 */
	private function plant_sentinel( $key ) {
		_wp_map_meta_cap_memo( $key, array( self::SENTINEL_CAP ) );

		$this->assertSame(
			array( self::SENTINEL_CAP ),
			_wp_map_meta_cap_memo( $key ),
			'The memo should return what was just stored under the key.'
		);
	}

	/**
	 * Removes every callback but the memo reset from a hook, leaving it registered.
	 *
	 * The real registration is what stays behind and what therefore gets proven. The
	 * others are removed because they are registered with argument counts of their own
	 * - `wp_switch_roles_and_user()` on 'switch_blog' takes two - and firing the action
	 * without those arguments would fail inside an unrelated callback rather than
	 * telling us anything about the memo. `WP_UnitTestCase` restores `$wp_filter`
	 * after every test, so nothing removed here escapes the test that removed it.
	 *
	 * @global array $wp_filter All of the filters and actions.
	 *
	 * @param string $hook Action name to isolate.
	 */
	private function isolate_memo_reset( $hook ) {
		global $wp_filter;

		$this->assertArrayHasKey( $hook, $wp_filter, sprintf( 'The %s action should have callbacks registered.', $hook ) );

		$registered = $wp_filter[ $hook ]->callbacks;

		foreach ( $registered as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( '_wp_reset_map_meta_cap_memo' === $callback['function'] ) {
					continue;
				}

				remove_action( $hook, $callback['function'], $priority );
			}
		}
	}

	/**
	 * Maps a capability with a guaranteed-empty memo, giving the true current answer.
	 *
	 * Every stale-answer test compares against this rather than against a literal, so
	 * that it asserts "the memo agrees with the mapping" rather than restating what the
	 * mapping happens to produce today.
	 *
	 * @param string $cap     Capability being checked.
	 * @param int    $user_id User ID.
	 * @param mixed  ...$args Further parameters passed to `map_meta_cap()`.
	 * @return string[] Primitive capabilities, sorted so the comparison is order free.
	 */
	private function cold( $cap, $user_id, ...$args ) {
		_wp_reset_map_meta_cap_memo();

		$caps = map_meta_cap( $cap, $user_id, ...$args );
		sort( $caps );

		return $caps;
	}

	/**
	 * Maps a capability without discarding the memo first.
	 *
	 * @param string $cap     Capability being checked.
	 * @param int    $user_id User ID.
	 * @param mixed  ...$args Further parameters passed to `map_meta_cap()`.
	 * @return string[] Primitive capabilities, sorted so the comparison is order free.
	 */
	private function warm( $cap, $user_id, ...$args ) {
		$caps = map_meta_cap( $cap, $user_id, ...$args );
		sort( $caps );

		return $caps;
	}

	/**
	 * Asserts that the answer read warm is the answer the mapping computes from scratch.
	 *
	 * Read warm first and cold second, and in that order only. A warm read is the one
	 * that can be answered out of the memo, so it is the one that can be stale; asking
	 * cold first would discard the memo and repopulate it with the current answer, after
	 * which the warm read would agree with it however stale the memo had been.
	 *
	 * @param string $cap     Capability being checked.
	 * @param int    $user_id User ID.
	 * @param array  $args    Further parameters passed to `map_meta_cap()`.
	 * @param string $message Assertion message.
	 * @return string[] The answer, sorted, as computed from scratch.
	 */
	private function assert_warm_matches_cold( $cap, $user_id, $args, $message ) {
		$warm = $this->warm( $cap, $user_id, ...$args );
		$cold = $this->cold( $cap, $user_id, ...$args );

		$this->assertSame( $cold, $warm, $message );

		return $cold;
	}

	/**
	 * Starts counting how many of the memo's invalidation actions fire from here on.
	 *
	 * Used to establish that a mutation really does go unannounced, which is what makes
	 * a stale answer possible in the first place. Without it, a passing stale-answer
	 * test could be passing only because something invalidated the memo by accident.
	 *
	 * The count accumulates into `$this->invalidation_fires`, which `set_up()` resets.
	 *
	 * @param string[] $hooks Actions to watch.
	 */
	private function watch_invalidation_hooks( $hooks ) {
		foreach ( $hooks as $hook ) {
			add_action(
				$hook,
				array( $this, 'record_invalidation_hook' ),
				1,
				0
			);
		}
	}

	/**
	 * Records that one of the watched invalidation actions fired.
	 */
	public function record_invalidation_hook() {
		++$this->invalidation_fires;
	}

	/**
	 * Counts the incorrect usage reports a mapping makes when it is repeated.
	 *
	 * The test case already listens on `doing_it_wrong_run` to decide whether a report
	 * was expected, but it records one entry per function name, so it cannot say how
	 * often a report was made. This counts every one of them.
	 *
	 * The memo is discarded once before counting starts, so that the first call is always
	 * a cold one and a measurement never depends on what a previous measurement left
	 * behind.
	 *
	 * @param callable $mapping     Mapping to exercise.
	 * @param int      $times       How many times to exercise it.
	 * @param bool     $reset_first Whether to discard the memo before every call, which
	 *                              is how the mapping behaved before it was memoized.
	 * @return int Reports made across all of the calls.
	 */
	private function count_incorrect_usage_reports( $mapping, $times, $reset_first = false ) {
		_wp_reset_map_meta_cap_memo();

		$this->incorrect_usage_reports = 0;

		add_action( 'doing_it_wrong_run', array( $this, 'record_incorrect_usage_report' ), 1, 0 );

		try {
			for ( $i = 0; $i < $times; $i++ ) {
				if ( $reset_first ) {
					_wp_reset_map_meta_cap_memo();
				}

				$mapping();
			}
		} finally {
			remove_action( 'doing_it_wrong_run', array( $this, 'record_incorrect_usage_report' ), 1 );
		}

		return $this->incorrect_usage_reports;
	}

	/**
	 * Records that the mapping reported an incorrect usage.
	 */
	public function record_incorrect_usage_report() {
		++$this->incorrect_usage_reports;
	}

	/**
	 * Replaces the mapped capabilities with a marker, as a static callback would.
	 *
	 * @param string[] $caps    Primitive capabilities required of the user.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id The user ID.
	 * @param array    $args    Context for the capability check.
	 * @return string[] Filtered capabilities.
	 */
	public function filter_map_meta_cap_to_marker( $caps, $cap, $user_id, $args ) {
		return array( 'mmcm_filtered_cap' );
	}

	/**
	 * Answers differently on every call, as a request-context aware callback would.
	 *
	 * This is the behavior the bypass exists for: a callback is free to return a
	 * different answer for identical arguments, so no answer produced while one is
	 * registered may be reused.
	 *
	 * @param string[] $caps    Primitive capabilities required of the user.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id The user ID.
	 * @param array    $args    Context for the capability check.
	 * @return string[] Filtered capabilities.
	 */
	public function filter_map_meta_cap_dynamically( $caps, $cap, $user_id, $args ) {
		++$this->filter_calls;

		return array( 'mmcm_call_' . $this->filter_calls );
	}

	/**
	 * Returns a filtered value unchanged, as a passive third party callback would.
	 *
	 * Registered on a watched filter to prove that the memo stands down while a callback
	 * is present, without changing what the filter answers. Every hook in
	 * self::POLICY_FILTERS and self::UNWATCHED_HOOKS passes the value it is filtering as
	 * its first argument, so one callback is safe on all of them: whatever else runs
	 * between the `add_filter()` and the `remove_filter()` sees exactly the value it
	 * would have seen anyway.
	 *
	 * That is the point of the test rather than an accident of it. The memo may not
	 * assume a registered callback is inert, because it has no way to find out, so a
	 * callback that changes nothing has to suppress the memo just as firmly as one that
	 * changes everything.
	 *
	 * @param mixed $value Value being filtered.
	 * @return mixed The value, unchanged.
	 */
	public function pass_filtered_value_through( $value ) {
		return $value;
	}

	/**
	 * The memo is consulted by `map_meta_cap()` itself, not merely populated by it.
	 */
	public function test_map_meta_cap_answers_from_the_memo() {
		$key = $this->assert_memoizable( 'edit_post', self::$subscriber_id, array( self::$post_id ) );

		$this->assertNull(
			_wp_map_meta_cap_memo( $key ),
			'Nothing should be memoized against the key before the first call.'
		);

		$this->plant_sentinel( $key );

		$this->assertSame(
			array( self::SENTINEL_CAP ),
			map_meta_cap( 'edit_post', self::$subscriber_id, self::$post_id ),
			'map_meta_cap() should return the memoized result rather than recomputing it.'
		);
	}

	/**
	 * A repeated identical call is answered without consulting the capability map.
	 *
	 * The capability map is mutated in place, which fires no action and leaves every
	 * registry size unchanged, so the memo key is identical across all three calls
	 * below. The mutation is therefore invisible for exactly as long as the memo holds
	 * the earlier answer, and visible the moment it does not.
	 */
	public function test_a_repeated_call_is_answered_from_the_memo() {
		$this->assertFalse(
			has_filter( 'map_meta_cap' ),
			'No map_meta_cap callback should be registered, or the memo would be bypassed.'
		);

		$computed = map_meta_cap( 'edit_post', self::$subscriber_id, self::$post_id );

		$this->assertContains(
			'edit_others_posts',
			$computed,
			'Editing a post belonging to somebody else should require edit_others_posts.'
		);

		$post_type = get_post_type_object( 'post' );
		$original  = $post_type->cap->edit_others_posts;

		$post_type->cap->edit_others_posts = self::MUTATED_CAP;

		try {
			$memoized = map_meta_cap( 'edit_post', self::$subscriber_id, self::$post_id );

			$this->assertSame( $computed, $memoized, 'The repeated call should return the memoized answer.' );
			$this->assertNotContains( self::MUTATED_CAP, $memoized, 'The memoized answer should predate the mutation.' );

			_wp_reset_map_meta_cap_memo();

			$this->assertContains(
				self::MUTATED_CAP,
				map_meta_cap( 'edit_post', self::$subscriber_id, self::$post_id ),
				'Once the memo is discarded the mutated capability map should be read again.'
			);
		} finally {
			$post_type->cap->edit_others_posts = $original;
		}
	}

	/**
	 * A memoized empty result is still an answer, and still ends the lookup.
	 */
	public function test_a_memoized_empty_result_is_returned() {
		$key = $this->assert_memoizable( 'edit_post', self::$editor_id, array( self::$post_id ) );

		_wp_map_meta_cap_memo( $key, array() );

		$this->assertSame(
			array(),
			_wp_map_meta_cap_memo( $key ),
			'An empty memoized result should be returned instead of being read as a miss.'
		);
		$this->assertSame(
			array(),
			map_meta_cap( 'edit_post', self::$editor_id, self::$post_id ),
			'map_meta_cap() should return the memoized empty result.'
		);
	}

	/**
	 * Nothing is read from or written to the memo while a callback is registered.
	 */
	public function test_the_memo_is_bypassed_while_a_callback_is_registered() {
		$key = $this->assert_memoizable( 'edit_post', self::$subscriber_id, array( self::$post_id ) );

		$this->plant_sentinel( $key );

		add_filter( 'map_meta_cap', array( $this, 'filter_map_meta_cap_to_marker' ), 10, 4 );

		$this->assertSame(
			'',
			_wp_map_meta_cap_memo_key( 'edit_post', self::$subscriber_id, array( self::$post_id ) ),
			'A call should not be memoizable while a map_meta_cap callback is registered.'
		);
		$this->assertSame(
			array( 'mmcm_filtered_cap' ),
			map_meta_cap( 'edit_post', self::$subscriber_id, self::$post_id ),
			'The filtered result should be returned instead of the memoized one.'
		);

		remove_filter( 'map_meta_cap', array( $this, 'filter_map_meta_cap_to_marker' ), 10 );

		$this->assertSame(
			array( self::SENTINEL_CAP ),
			_wp_map_meta_cap_memo( $key ),
			'The filtered result should not have been stored over the memoized one.'
		);
	}

	/**
	 * A callback that answers differently every time is never memoized.
	 */
	public function test_a_dynamic_callback_is_never_memoized() {
		add_filter( 'map_meta_cap', array( $this, 'filter_map_meta_cap_dynamically' ), 10, 4 );

		$first  = map_meta_cap( 'edit_post', self::$subscriber_id, self::$post_id );
		$second = map_meta_cap( 'edit_post', self::$subscriber_id, self::$post_id );

		remove_filter( 'map_meta_cap', array( $this, 'filter_map_meta_cap_dynamically' ), 10 );

		$this->assertSame( array( 'mmcm_call_1' ), $first, 'The first call should be answered by the callback.' );
		$this->assertSame( array( 'mmcm_call_2' ), $second, 'The second call should also be answered by the callback.' );
		$this->assertSame( 2, $this->filter_calls, 'Both calls should have reached the callback.' );
	}

	/**
	 * No answer crosses the boundary of a callback that is added and removed again.
	 *
	 * This is the shape `WP_Customize_Manager` uses: it adds a `map_meta_cap` callback
	 * for the duration of one operation and removes it afterwards, so an answer given
	 * while it was registered must not be reused once it is gone, and vice versa.
	 */
	public function test_no_answer_crosses_a_transient_callback_boundary() {
		$unfiltered = map_meta_cap( 'edit_post', self::$subscriber_id, self::$post_id );

		$this->assertNotSame( array( 'mmcm_filtered_cap' ), $unfiltered, 'The first call should be unfiltered.' );

		add_filter( 'map_meta_cap', array( $this, 'filter_map_meta_cap_to_marker' ), 10, 4 );

		$this->assertSame(
			array( 'mmcm_filtered_cap' ),
			map_meta_cap( 'edit_post', self::$subscriber_id, self::$post_id ),
			'The memoized unfiltered answer should not be reused while the callback is registered.'
		);

		remove_filter( 'map_meta_cap', array( $this, 'filter_map_meta_cap_to_marker' ), 10 );

		$this->assertSame(
			$unfiltered,
			map_meta_cap( 'edit_post', self::$subscriber_id, self::$post_id ),
			'The filtered answer should not survive the removal of the callback.'
		);
	}

	/**
	 * Arguments that stringify identically are still told apart by their type.
	 */
	public function test_the_key_separates_arguments_of_different_types() {
		$keys = array(
			'integer' => _wp_map_meta_cap_memo_key( 'edit_post', 1, array( 1 ) ),
			'string'  => _wp_map_meta_cap_memo_key( 'edit_post', 1, array( '1' ) ),
			'boolean' => _wp_map_meta_cap_memo_key( 'edit_post', 1, array( true ) ),
			'double'  => _wp_map_meta_cap_memo_key( 'edit_post', 1, array( 1.0 ) ),
			'empty'   => _wp_map_meta_cap_memo_key( 'edit_post', 1, array( '' ) ),
		);

		/*
		 * An argument of null is deliberately absent from the set above. It is the one
		 * argument the mapping treats as no argument at all, so it is declined rather
		 * than described. See test_a_missing_object_is_never_memoized().
		 */

		foreach ( $keys as $type => $key ) {
			$this->assertNotSame( '', $key, sprintf( 'An argument of type %s should be memoizable.', $type ) );
		}

		$this->assertCount(
			count( $keys ),
			array_unique( $keys ),
			'Arguments of different types should never share a memo key.'
		);
	}

	/**
	 * The user ID is described by its type as well as its value.
	 */
	public function test_the_key_separates_users_of_different_types() {
		$integer_key = _wp_map_meta_cap_memo_key( 'edit_post', 1, array( self::$post_id ) );
		$string_key  = _wp_map_meta_cap_memo_key( 'edit_post', '1', array( self::$post_id ) );

		$this->assertNotSame( '', $integer_key, 'An integer user ID should be memoizable.' );
		$this->assertNotSame( '', $string_key, 'A numeric string user ID should be memoizable.' );
		$this->assertNotSame( $integer_key, $string_key, 'User IDs of different types should not share a memo key.' );
	}

	/**
	 * Argument lists that would concatenate to the same string do not collide.
	 */
	public function test_the_key_separates_argument_positions() {
		$keys = array(
			_wp_map_meta_cap_memo_key( 'edit_post', 1, array( 1, '2' ) ),
			_wp_map_meta_cap_memo_key( 'edit_post', 1, array( '1', 2 ) ),
			_wp_map_meta_cap_memo_key( 'edit_post', 1, array( '1|2' ) ),
			_wp_map_meta_cap_memo_key( 'edit_post', 1, array( '1', '2' ) ),
			_wp_map_meta_cap_memo_key( 'edit_post', 1, array( '2', '1' ) ),
		);

		foreach ( $keys as $index => $key ) {
			$this->assertNotSame( '', $key, sprintf( 'Argument list %d should be memoizable.', $index ) );
		}

		$this->assertCount(
			count( $keys ),
			array_unique( $keys ),
			'Argument lists that stringify alike should still produce distinct memo keys.'
		);
	}

	/**
	 * The capability is described by its length as well as its value.
	 *
	 * The length matters because the capability is concatenated with the parts around
	 * it: without it, a longer capability could reproduce the boundary between the
	 * capability and the user that follows it.
	 */
	public function test_the_key_separates_capabilities() {
		$keys = array(
			'edit_post'  => _wp_map_meta_cap_memo_key( 'edit_post', 1, array( self::$post_id ) ),
			'read_post'  => _wp_map_meta_cap_memo_key( 'read_post', 1, array( self::$post_id ) ),
			'edit_posts' => _wp_map_meta_cap_memo_key( 'edit_posts', 1, array( self::$post_id ) ),
		);

		foreach ( $keys as $cap => $key ) {
			$this->assertNotSame( '', $key, sprintf( '%s should be memoizable.', $cap ) );
		}

		$this->assertCount(
			count( $keys ),
			array_unique( $keys ),
			'Different capabilities should never share a memo key.'
		);
		$this->assertStringContainsString(
			'|9:edit_post|',
			$keys['edit_post'],
			'The capability should be preceded by its own length.'
		);
		$this->assertStringContainsString(
			'|10:edit_posts|',
			$keys['edit_posts'],
			'A longer capability should be preceded by its own length.'
		);
	}

	/**
	 * The key carries the site the check is being made against.
	 *
	 * `current_user_can_for_site()` and `user_can_for_site()` switch sites around the
	 * check, so an answer given for one site must never be handed to another. The site
	 * is read from the same global `get_current_blog_id()` reads on every installation,
	 * which is why this holds on a single site as well as on Multisite.
	 */
	public function test_the_key_carries_the_current_site() {
		$key = $this->assert_memoizable( 'edit_post', self::$subscriber_id, array( self::$post_id ) );

		$this->plant_sentinel( $key );

		$original_site_id   = $GLOBALS['blog_id'];
		$GLOBALS['blog_id'] = (int) $original_site_id + 1;

		try {
			$this->assertNotSame(
				$key,
				_wp_map_meta_cap_memo_key( 'edit_post', self::$subscriber_id, array( self::$post_id ) ),
				'The memo key should change with the current site.'
			);
			$this->assertNotSame(
				array( self::SENTINEL_CAP ),
				map_meta_cap( 'edit_post', self::$subscriber_id, self::$post_id ),
				'An answer memoized for one site should not be reused on another.'
			);
		} finally {
			$GLOBALS['blog_id'] = $original_site_id;
		}

		$this->assertSame(
			array( self::SENTINEL_CAP ),
			map_meta_cap( 'edit_post', self::$subscriber_id, self::$post_id ),
			'The answer memoized for the original site should still be available on it.'
		);
	}

	/**
	 * The key carries the sizes of every registry the mapping reads.
	 *
	 * Registering and unregistering each of these fires an action the memo is discarded
	 * on, but code that mutates one of the registries by unsetting a key directly fires
	 * nothing, so the sizes are carried in the key as well.
	 *
	 * @global array      $wp_post_types    Post type registry.
	 * @global array      $wp_post_statuses Post status registry.
	 * @global array      $wp_taxonomies    Taxonomy registry.
	 * @global array|null $super_admins     Super admin logins, when they are defined.
	 */
	public function test_the_key_carries_the_registry_sizes() {
		$baseline = $this->assert_memoizable( 'edit_post', self::$editor_id, array( self::$post_id ) );

		$registries = array( 'wp_post_types', 'wp_post_statuses', 'wp_taxonomies', 'super_admins' );

		foreach ( $registries as $registry ) {
			$original = isset( $GLOBALS[ $registry ] ) ? $GLOBALS[ $registry ] : null;

			$GLOBALS[ $registry ] = is_array( $original )
				? array_merge( $original, array( 'mmcm_extra_entry' => true ) )
				: array( 'mmcm_extra_entry' => true );

			$changed = _wp_map_meta_cap_memo_key( 'edit_post', self::$editor_id, array( self::$post_id ) );

			if ( null === $original ) {
				unset( $GLOBALS[ $registry ] );
			} else {
				$GLOBALS[ $registry ] = $original;
			}

			$this->assertNotSame(
				$baseline,
				$changed,
				sprintf( 'The memo key should change when the size of %s changes.', $registry )
			);
		}

		$this->assertSame(
			$baseline,
			_wp_map_meta_cap_memo_key( 'edit_post', self::$editor_id, array( self::$post_id ) ),
			'Restoring every registry should restore the original memo key.'
		);
	}

	/**
	 * The key carries which super admins are listed, not merely how many there are.
	 *
	 * The list is a `wp-config.php` global rather than stored state, so nothing fires
	 * when it changes. Replacing its members without changing how many there are has to
	 * change the key, or a mapping that read `is_super_admin()` would outlive it.
	 *
	 * Every capability whose mapping reads `is_super_admin()` is declined outright by
	 * `_wp_map_meta_cap_is_memoizable_cap()`, so this component of the key is defensive
	 * rather than load bearing today, and a memoizable capability is used to exercise it.
	 * It is asserted all the same: the component is what would keep a future mapping that
	 * did read the list from being memoized across a change to it.
	 *
	 * @global array|null $super_admins Super admin logins, when they are defined.
	 */
	public function test_the_key_carries_the_super_admin_members() {
		$original = isset( $GLOBALS['super_admins'] ) ? $GLOBALS['super_admins'] : null;

		try {
			$GLOBALS['super_admins'] = array( 'mmcm_first_admin', 'mmcm_second_admin' );

			$baseline = $this->assert_memoizable( 'edit_post', self::$editor_id, array( self::$post_id ) );

			$GLOBALS['super_admins'] = array( 'mmcm_first_admin', 'mmcm_other_admin' );

			$this->assertNotSame(
				$baseline,
				_wp_map_meta_cap_memo_key( 'edit_post', self::$editor_id, array( self::$post_id ) ),
				'Substituting a super admin for another should change the memo key.'
			);

			$GLOBALS['super_admins'] = array( 'mmcm_first_admin', 'mmcm_second_admin' );

			$this->assertSame(
				$baseline,
				_wp_map_meta_cap_memo_key( 'edit_post', self::$editor_id, array( self::$post_id ) ),
				'Restoring the list should restore the memo key.'
			);

			$GLOBALS['super_admins'] = array( 'mmcm_first_admin|mmcm_second_admin' );

			$this->assertNotSame(
				$baseline,
				_wp_map_meta_cap_memo_key( 'edit_post', self::$editor_id, array( self::$post_id ) ),
				'One login containing the separator should not look like two logins.'
			);

			$GLOBALS['super_admins'] = array( array( 'mmcm_first_admin' ) );

			$this->assertSame(
				'',
				_wp_map_meta_cap_memo_key( 'edit_post', self::$editor_id, array( self::$post_id ) ),
				'A super admin entry that is not scalar should decline the memo.'
			);
		} finally {
			if ( null === $original ) {
				unset( $GLOBALS['super_admins'] );
			} else {
				$GLOBALS['super_admins'] = $original;
			}
		}
	}

	/**
	 * A call that carries no further arguments is not memoized.
	 *
	 * These are the majority of all capability checks and they are answered by the
	 * cheap arms of the switch, so `map_meta_cap()` skips the memo before it even asks
	 * for a key. Both the read and the write are guarded by the same empty-key test.
	 */
	public function test_a_call_without_further_arguments_is_not_memoized() {
		$this->assertSame(
			'',
			_wp_map_meta_cap_memo_key( 'promote_user', self::$administrator_id, array() ),
			'A call with no further arguments should not be memoizable.'
		);
		$this->assertSame(
			array( 'promote_users' ),
			map_meta_cap( 'promote_user', self::$administrator_id ),
			'A call with no further arguments should still be answered correctly.'
		);
		$this->assertSame(
			array( 'promote_users' ),
			map_meta_cap( 'promote_user', self::$administrator_id ),
			'Repeating it should still be answered correctly.'
		);
	}

	/**
	 * A capability that is not a string cannot be described in a key.
	 */
	public function test_a_non_string_capability_is_not_memoized() {
		$capabilities = array( 1, 1.5, true, null, array( 'edit_post' ), new stdClass() );

		foreach ( $capabilities as $capability ) {
			$this->assertSame(
				'',
				_wp_map_meta_cap_memo_key( $capability, self::$editor_id, array( self::$post_id ) ),
				sprintf( 'A capability of type %s should not be memoizable.', gettype( $capability ) )
			);
		}
	}

	/**
	 * A user that is not a scalar cannot be described in a key.
	 */
	public function test_a_non_scalar_user_is_not_memoized() {
		$users = array( array( self::$editor_id ), new stdClass(), null );

		foreach ( $users as $user ) {
			$this->assertSame(
				'',
				_wp_map_meta_cap_memo_key( 'edit_post', $user, array( self::$post_id ) ),
				sprintf( 'A user of type %s should not be memoizable.', gettype( $user ) )
			);
		}
	}

	/**
	 * An argument that is neither scalar nor null cannot be described in a key.
	 */
	public function test_a_non_scalar_argument_is_not_memoized() {
		$arguments = array(
			'object'  => new stdClass(),
			'array'   => array( self::$post_id ),
			'closure' => static function () {
				return true;
			},
		);

		foreach ( $arguments as $type => $argument ) {
			$this->assertSame(
				'',
				_wp_map_meta_cap_memo_key( 'edit_post', self::$editor_id, array( $argument ) ),
				sprintf( 'An argument of type %s should not be memoizable.', $type )
			);
			$this->assertSame(
				'',
				_wp_map_meta_cap_memo_key( 'edit_post', self::$editor_id, array( self::$post_id, $argument ) ),
				sprintf( 'A trailing argument of type %s should not be memoizable either.', $type )
			);
		}
	}

	/**
	 * The capability that really is passed an object is answered without memoizing.
	 *
	 * `edit_block_binding` receives a block editor context object, which is the reason
	 * the non-scalar bypass exists rather than being a theoretical safeguard.
	 */
	public function test_a_capability_passed_an_object_is_answered_without_memoizing() {
		$context = new stdClass();

		$this->assertSame(
			'',
			_wp_map_meta_cap_memo_key( 'edit_block_binding', self::$editor_id, array( $context ) ),
			'A block editor context object should not be memoizable.'
		);
		$this->assertSame(
			array( 'do_not_allow' ),
			map_meta_cap( 'edit_block_binding', self::$editor_id, $context ),
			'A context object without a post or a name should still be answered.'
		);
	}

	/**
	 * No metadata capability is memoized.
	 *
	 * They resolve through the `auth_*` filters that `register_meta()` installs and
	 * through `is_protected_meta()`, none of which fires an action to invalidate
	 * against, so their answer can change part way through a request.
	 */
	public function test_no_metadata_capability_is_memoized() {
		foreach ( self::METADATA_CAPS as $capability ) {
			$this->assertSame(
				'',
				_wp_map_meta_cap_memo_key( $capability, self::$editor_id, array( self::$post_id, 'mmcm_meta_key' ) ),
				sprintf( 'The %s capability should not be memoizable.', $capability )
			);
		}
	}

	/**
	 * Two meta keys checked against the same object do not share an answer.
	 *
	 * The metadata arm appends the meta capability itself, which no role holds, when
	 * the meta key is protected. The two calls differ only in that trailing argument,
	 * so memoizing either one would hand the wrong answer to the other.
	 */
	public function test_metadata_capabilities_do_not_share_an_answer_across_meta_keys() {
		$public_caps    = map_meta_cap( 'edit_post_meta', self::$editor_id, self::$post_id, 'mmcm_public_meta' );
		$protected_caps = map_meta_cap( 'edit_post_meta', self::$editor_id, self::$post_id, '_mmcm_protected_meta' );

		$this->assertNotContains(
			'edit_post_meta',
			$public_caps,
			'An unprotected meta key should not be refused.'
		);
		$this->assertContains(
			'edit_post_meta',
			$protected_caps,
			'A protected meta key should be refused, so the two must not share an answer.'
		);
		$this->assertNotSame(
			$public_caps,
			$protected_caps,
			'Two meta keys checked against the same object should not share an answer.'
		);
	}

	/**
	 * A capability that delegates memoizes under the capability it delegates to.
	 *
	 * The post type meta capability arm returns before the filter runs, so it stores
	 * nothing under its own key and leaves the recursive call it delegates to to
	 * memoize the answer the filter produced.
	 *
	 * @global array $post_type_meta_caps Post type meta capabilities.
	 */
	public function test_a_delegating_capability_memoizes_under_the_delegated_capability() {
		register_post_type(
			'mmcm_cpt',
			array(
				'capability_type' => 'mmcm_cpt',
				'map_meta_cap'    => true,
			)
		);

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'mmcm_cpt',
				'post_status' => 'publish',
				'post_author' => self::$author_id,
			)
		);

		$this->assertArrayHasKey(
			'edit_mmcm_cpt',
			$GLOBALS['post_type_meta_caps'],
			'Registering the post type should register its meta capabilities.'
		);

		try {
			$delegating_key = $this->assert_memoizable( 'edit_mmcm_cpt', self::$subscriber_id, array( $post_id ) );
			$delegated_key  = $this->assert_memoizable( 'edit_post', self::$subscriber_id, array( $post_id ) );

			$caps = map_meta_cap( 'edit_mmcm_cpt', self::$subscriber_id, $post_id );

			$this->assertContains(
				'edit_others_mmcm_cpts',
				$caps,
				'The delegated call should resolve through the custom post type capability map.'
			);
			$this->assertNull(
				_wp_map_meta_cap_memo( $delegating_key ),
				'The delegating call should store nothing under its own key.'
			);
			$this->assertSame(
				$caps,
				_wp_map_meta_cap_memo( $delegated_key ),
				'The delegated call should memoize the answer under its own key.'
			);
		} finally {
			unregister_post_type( 'mmcm_cpt' );
		}
	}

	/**
	 * Every action the memo is discarded on, and nothing else, carries the callback.
	 *
	 * @global array $wp_filter All of the filters and actions.
	 */
	public function test_the_memo_is_discarded_on_exactly_the_expected_actions() {
		global $wp_filter;

		$registered = array();

		foreach ( $wp_filter as $hook => $hook_object ) {
			foreach ( $hook_object->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( '_wp_reset_map_meta_cap_memo' === $callback['function'] ) {
						$registered[] = $hook;
					}
				}
			}
		}

		$this->assertCount(
			count( self::INVALIDATION_HOOKS ),
			$registered,
			'The memo reset should be registered exactly once per expected action.'
		);
		$this->assertSameSets(
			self::INVALIDATION_HOOKS,
			$registered,
			'The memo reset should be registered on exactly the expected actions.'
		);
	}

	/**
	 * Each registered action discards the memo when it fires.
	 *
	 * @dataProvider data_invalidation_hooks
	 *
	 * @global array $wp_filter All of the filters and actions.
	 *
	 * @param string $hook Action the memo should be discarded on.
	 */
	public function test_the_memo_is_discarded_when_the_action_fires( $hook ) {
		global $wp_filter;

		$this->assertSame(
			10,
			has_action( $hook, '_wp_reset_map_meta_cap_memo' ),
			sprintf( 'The memo reset should be registered on %s at priority 10.', $hook )
		);
		$this->assertSame(
			0,
			$wp_filter[ $hook ]->callbacks[10]['_wp_reset_map_meta_cap_memo']['accepted_args'],
			sprintf( 'The memo reset should accept no arguments from %s.', $hook )
		);

		$this->isolate_memo_reset( $hook );

		$key = $this->assert_memoizable( 'edit_post', self::$editor_id, array( self::$post_id ) );

		$this->plant_sentinel( $key );

		do_action( $hook );

		$this->assertNull(
			_wp_map_meta_cap_memo( $key ),
			sprintf( 'The %s action should discard the memo.', $hook )
		);
	}

	/**
	 * Data provider.
	 *
	 * @return array[] Test parameters, keyed by action name.
	 */
	public static function data_invalidation_hooks() {
		$hooks = array();

		foreach ( self::INVALIDATION_HOOKS as $hook ) {
			$hooks[ $hook ] = array( $hook );
		}

		return $hooks;
	}

	/**
	 * The user metadata actions carry the callback that inspects the metadata key.
	 *
	 * @global array $wp_filter All of the filters and actions.
	 */
	public function test_the_memo_is_discarded_on_exactly_the_expected_user_meta_actions() {
		global $wp_filter;

		$registered = array();

		foreach ( $wp_filter as $hook => $hook_object ) {
			foreach ( $hook_object->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( '_wp_reset_map_meta_cap_memo_on_user_meta' === $callback['function'] ) {
						$registered[] = $hook;
					}
				}
			}
		}

		$this->assertCount(
			count( self::USER_META_INVALIDATION_HOOKS ),
			$registered,
			'The user metadata reset should be registered exactly once per expected action.'
		);
		$this->assertSameSets(
			self::USER_META_INVALIDATION_HOOKS,
			$registered,
			'The user metadata reset should be registered on exactly the expected actions.'
		);

		foreach ( self::USER_META_INVALIDATION_HOOKS as $hook ) {
			$this->assertSame(
				10,
				has_action( $hook, '_wp_reset_map_meta_cap_memo_on_user_meta' ),
				sprintf( 'The user metadata reset should be registered on %s at priority 10.', $hook )
			);
			$this->assertSame(
				3,
				$wp_filter[ $hook ]->callbacks[10]['_wp_reset_map_meta_cap_memo_on_user_meta']['accepted_args'],
				sprintf( 'The user metadata reset should accept the metadata key from %s.', $hook )
			);
		}
	}

	/**
	 * A capability metadata write discards the memo, and any other write does not.
	 *
	 * @dataProvider data_user_meta_invalidation_hooks
	 *
	 * @param string $hook Action the memo should be discarded on.
	 */
	public function test_a_capability_metadata_write_discards_the_memo( $hook ) {
		global $wpdb;

		$key = $this->assert_memoizable( 'edit_post', self::$editor_id, array( self::$post_id ) );

		$this->plant_sentinel( $key );

		do_action( $hook, 1, self::$editor_id, 'mmcm_unrelated_meta_key' );

		$this->assertSame(
			array( self::SENTINEL_CAP ),
			_wp_map_meta_cap_memo( $key ),
			sprintf( 'An unrelated metadata key on %s should leave the memo alone.', $hook )
		);

		do_action( $hook, 1, self::$editor_id, $wpdb->get_blog_prefix() . 'capabilities' );

		$this->assertNull(
			_wp_map_meta_cap_memo( $key ),
			sprintf( 'The capability metadata key on %s should discard the memo.', $hook )
		);
	}

	/**
	 * The capability key is recognized whichever site it belongs to.
	 *
	 * A user's capabilities for one site can be written while another site is current,
	 * and the memo carries the current site in its key, so the write has to be
	 * recognized by the shape of the key rather than by the current site's prefix.
	 */
	public function test_a_capability_metadata_write_for_another_site_discards_the_memo() {
		$key = $this->assert_memoizable( 'edit_post', self::$editor_id, array( self::$post_id ) );

		$this->plant_sentinel( $key );

		do_action( 'updated_user_meta', 1, self::$editor_id, 'wp_9999_capabilities' );

		$this->assertNull(
			_wp_map_meta_cap_memo( $key ),
			'A capability metadata write for another site should discard the memo.'
		);
	}

	/**
	 * A metadata key that is not a string is ignored without raising a diagnostic.
	 */
	public function test_a_non_string_metadata_key_leaves_the_memo_alone() {
		$key = $this->assert_memoizable( 'edit_post', self::$editor_id, array( self::$post_id ) );

		$this->plant_sentinel( $key );

		_wp_reset_map_meta_cap_memo_on_user_meta( 1, self::$editor_id, null );
		_wp_reset_map_meta_cap_memo_on_user_meta( array( 1, 2 ), self::$editor_id, 17 );

		$this->assertSame(
			array( self::SENTINEL_CAP ),
			_wp_map_meta_cap_memo( $key ),
			'A metadata key that is not a string should leave the memo alone.'
		);
	}

	/**
	 * Data provider.
	 *
	 * @return array[] Test parameters, keyed by action name.
	 */
	public static function data_user_meta_invalidation_hooks() {
		$hooks = array();

		foreach ( self::USER_META_INVALIDATION_HOOKS as $hook ) {
			$hooks[ $hook ] = array( $hook );
		}

		return $hooks;
	}

	/**
	 * Describes a mapping that depends on the checked user's own capabilities.
	 *
	 * `map_meta_cap()` consults the checked user's own capabilities on one family of
	 * mappings only, and which member of that family is sensitive to them differs
	 * between installs. On single site `is_super_admin()` reduces to
	 * `has_cap( 'delete_users' )`, so that one capability decides what removing yourself
	 * requires. On Multisite the same mapping is decided by the network's super admin
	 * list instead, and it is editing somebody else that consults
	 * `user_can( 'manage_network_users' )`. Describing both keeps one test meaningful on
	 * both configurations rather than excluding it from either.
	 *
	 * @return array {
	 *     Capability check whose answer follows the checked user's own capabilities.
	 *
	 *     @type string   $capability      Meta capability to check.
	 *     @type string   $granted         Primitive capability that decides the answer.
	 *     @type string[] $allowed         Mapping expected while `$granted` is held.
	 *     @type string[] $denied          Mapping expected once `$granted` is not held.
	 *     @type bool     $checked_on_self Whether the object of the check is the user.
	 * }
	 */
	private function user_capability_sensitive_check() {
		if ( is_multisite() ) {
			return array(
				'capability'      => 'edit_user',
				'granted'         => 'manage_network_users',
				'allowed'         => array( 'edit_users' ),
				'denied'          => array( 'do_not_allow' ),
				'checked_on_self' => false,
			);
		}

		return array(
			'capability'      => 'remove_user',
			'granted'         => 'delete_users',
			'allowed'         => array( 'remove_users' ),
			'denied'          => array( 'do_not_allow' ),
			'checked_on_self' => true,
		);
	}

	/**
	 * Grants the primitive capabilities a permissive mapping resolves to.
	 *
	 * `WP_User::has_cap()` requires every primitive capability the mapping names, so a
	 * user who only holds the capability that decides the mapping still cannot pass the
	 * check the mapping describes. Granting the mapped primitives separately keeps the
	 * end to end assertions about `user_can()` about the mapping under test rather than
	 * about a capability the fixture never had.
	 *
	 * @param int      $user_id      User to grant the capabilities to.
	 * @param string[] $capabilities Primitive capabilities to grant.
	 */
	private function grant_mapped_capabilities( $user_id, $capabilities ) {
		$user = new WP_User( $user_id );

		foreach ( $capabilities as $capability ) {
			$user->add_cap( $capability );
		}
	}

	/**
	 * A write to a user's capabilities discards the memo, whatever the write was.
	 *
	 * `WP_User::add_cap()` and `WP_User::remove_cap()` store the capability array with
	 * `update_user_meta()`, and `WP_User::remove_all_caps()` deletes it, so between them
	 * they reach all three of the metadata actions. None of them fires a role or user
	 * cache action, so before those metadata actions were listened on, a mapping computed
	 * before the write survived it for the rest of the request.
	 */
	public function test_a_user_capability_write_discards_the_memo() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$key     = $this->assert_memoizable( 'edit_post', self::$editor_id, array( self::$post_id ) );

		$writes = array(
			'add_cap'         => static function ( $id ) {
				$user = new WP_User( $id );
				$user->add_cap( 'mmcm_granted_cap' );
			},
			'remove_cap'      => static function ( $id ) {
				$user = new WP_User( $id );
				$user->remove_cap( 'mmcm_granted_cap' );
			},
			'remove_all_caps' => static function ( $id ) {
				$user = new WP_User( $id );
				$user->remove_all_caps();
			},
		);

		foreach ( $writes as $method => $write ) {
			$this->plant_sentinel( $key );

			$write( $user_id );

			$this->assertNull(
				_wp_map_meta_cap_memo( $key ),
				sprintf( 'WP_User::%s() should discard the memo.', $method )
			);
		}
	}

	/**
	 * Revoking a capability from a user is visible to the very next check.
	 *
	 * This is the end to end form of the invalidation above: the mapping is warmed while
	 * the capability is held, the capability is revoked through the documented API, and
	 * the identical check has to re-resolve rather than answer from the memo.
	 */
	public function test_a_revoked_user_capability_is_not_answered_from_the_memo() {
		$check   = $this->user_capability_sensitive_check();
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$object  = $check['checked_on_self']
			? $user_id
			: self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->grant_mapped_capabilities( $user_id, $check['allowed'] );

		$user = new WP_User( $user_id );
		$user->add_cap( $check['granted'] );

		$this->assertSame(
			$check['allowed'],
			map_meta_cap( $check['capability'], $user_id, $object ),
			sprintf( 'Holding %s should produce the permissive mapping.', $check['granted'] )
		);
		$this->assertTrue(
			user_can( $user_id, $check['capability'], $object ),
			sprintf( 'Holding %s should allow the check.', $check['granted'] )
		);

		$user = new WP_User( $user_id );
		$user->remove_cap( $check['granted'] );

		$this->assertSame(
			$check['denied'],
			map_meta_cap( $check['capability'], $user_id, $object ),
			sprintf( 'Revoking %s should be visible to the next mapping in the same request.', $check['granted'] )
		);
		$this->assertFalse(
			user_can( $user_id, $check['capability'], $object ),
			sprintf( 'Revoking %s should be visible to the next capability check.', $check['granted'] )
		);
	}

	/**
	 * A dynamic user capability policy change is visible to the next user edit check.
	 *
	 * Multisite maps `edit_user` through a nested `user_can()` check for
	 * `manage_network_users`. The `user_has_cap` filter can change that answer without
	 * writing user metadata or firing any memo invalidation action, so these mappings
	 * must bypass the request-scoped memo entirely.
	 *
	 * @group ms-required
	 */
	public function test_multisite_edit_user_rechecks_user_has_cap_without_invalidation() {
		$user_id   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$target_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user      = new WP_User( $user_id );

		$user->add_cap( 'manage_network_users' );

		$deny_manage_network_users = false;
		$filter                    = static function ( $allcaps, $required_caps, $args ) use ( &$deny_manage_network_users ) {
			if ( $deny_manage_network_users && isset( $args[0] ) && 'manage_network_users' === $args[0] ) {
				unset( $allcaps['manage_network_users'] );
			}

			return $allcaps;
		};

		$this->watch_invalidation_hooks(
			array_merge(
				self::INVALIDATION_HOOKS,
				self::USER_META_INVALIDATION_HOOKS
			)
		);

		add_filter( 'user_has_cap', $filter, 10, 3 );

		try {
			$this->assertSame(
				'',
				_wp_map_meta_cap_memo_key( 'edit_user', $user_id, array( $target_id ) ),
				'Multisite edit_user mappings should never be memoized.'
			);
			$this->assertSame(
				'',
				_wp_map_meta_cap_memo_key( 'edit_users', $user_id, array( $target_id ) ),
				'Multisite edit_users mappings should never be memoized when an object argument is supplied.'
			);
			$this->assertSame(
				array( 'edit_users' ),
				map_meta_cap( 'edit_user', $user_id, $target_id ),
				'The mapping should allow the user while the dynamic policy grants manage_network_users.'
			);
			$this->assertTrue(
				user_can( $user_id, 'edit_user', $target_id ),
				'The end-to-end capability check should initially be allowed.'
			);

			$deny_manage_network_users = true;

			$this->assertSame(
				0,
				$this->invalidation_fires,
				'Changing only the user_has_cap policy should not fire a memo invalidation action.'
			);
			$this->assertSame(
				array( 'do_not_allow' ),
				map_meta_cap( 'edit_user', $user_id, $target_id ),
				'The next mapping should immediately observe the dynamic policy denial.'
			);
			$this->assertFalse(
				user_can( $user_id, 'edit_user', $target_id ),
				'The next end-to-end capability check should immediately be denied.'
			);
			$this->assertSame(
				0,
				$this->invalidation_fires,
				'No metadata write or other invalidation should be needed to observe the policy change.'
			);
		} finally {
			remove_filter( 'user_has_cap', $filter, 10 );
		}
	}

	/**
	 * Granting a capability to a user is visible to the very next check.
	 *
	 * The opposite direction of the same invalidation, so that a memo that only happened
	 * to be discarded on the restrictive path would still be caught.
	 */
	public function test_a_granted_user_capability_is_not_answered_from_the_memo() {
		$check   = $this->user_capability_sensitive_check();
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$object  = $check['checked_on_self']
			? $user_id
			: self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->grant_mapped_capabilities( $user_id, $check['allowed'] );

		$this->assertSame(
			$check['denied'],
			map_meta_cap( $check['capability'], $user_id, $object ),
			sprintf( 'A subscriber should not hold %s.', $check['granted'] )
		);

		$user = new WP_User( $user_id );
		$user->add_cap( $check['granted'] );

		$this->assertSame(
			$check['allowed'],
			map_meta_cap( $check['capability'], $user_id, $object ),
			sprintf( 'Granting %s should be visible to the next mapping in the same request.', $check['granted'] )
		);
		$this->assertTrue(
			user_can( $user_id, $check['capability'], $object ),
			sprintf( 'Granting %s should be visible to the next capability check.', $check['granted'] )
		);
	}

	/**
	 * Re-registering a post status is visible to the very next check.
	 *
	 * Registering a name that is already registered replaces its arguments and leaves
	 * the size of the registry alone, so the size carried in the memo key cannot see it.
	 * The 'registered_post_status' action is what does, which is the same shape as the
	 * 'registered_post_type' action the memo already relies on.
	 */
	public function test_re_registering_a_post_status_is_not_answered_from_the_memo() {
		global $wp_post_statuses;

		register_post_status(
			'mmcm_status',
			array(
				'label'   => 'MMCM Status',
				'public'  => true,
				'private' => false,
			)
		);

		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'mmcm_status',
				'post_author' => self::$author_id,
			)
		);

		try {
			$size = count( $wp_post_statuses );

			$this->assertSame(
				array( 'read' ),
				map_meta_cap( 'read_post', self::$subscriber_id, $post_id ),
				'Reading a post of a public status should require nothing more than read.'
			);

			register_post_status(
				'mmcm_status',
				array(
					'label'   => 'MMCM Status',
					'public'  => false,
					'private' => true,
				)
			);

			$this->assertSame(
				$size,
				count( $wp_post_statuses ),
				'Re-registering a status should leave the size of the registry unchanged.'
			);
			$this->assertSame(
				array( 'read_private_posts' ),
				map_meta_cap( 'read_post', self::$subscriber_id, $post_id ),
				'Reading a post of a now private status should require read_private_posts.'
			);
			$this->assertFalse(
				user_can( self::$subscriber_id, 'read_post', $post_id ),
				'A subscriber should not be able to read a post that has become private.'
			);
		} finally {
			unset( $wp_post_statuses['mmcm_status'] );
			_wp_reset_map_meta_cap_memo();
		}
	}

	/**
	 * A role capability change is visible even when it is never stored.
	 *
	 * `WP_Roles::add_cap()` and `WP_Roles::remove_cap()` only reach 'updated_option' when
	 * `WP_Roles::$use_db` is true, a configuration some installs and test harnesses turn
	 * off, so they discard the memo themselves rather than through the option actions.
	 */
	public function test_a_role_capability_change_is_not_answered_from_the_memo_without_the_database() {
		$check   = $this->user_capability_sensitive_check();
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$object  = $check['checked_on_self']
			? $user_id
			: self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$roles   = wp_roles();
		$use_db  = $roles->use_db;

		try {
			$roles->use_db = false;

			$this->assertSame(
				$check['denied'],
				map_meta_cap( $check['capability'], $user_id, $object ),
				sprintf( 'The editor role should not hold %s.', $check['granted'] )
			);

			get_role( 'editor' )->add_cap( $check['granted'] );

			$this->assertSame(
				$check['allowed'],
				map_meta_cap( $check['capability'], $user_id, $object ),
				sprintf( 'Granting the role %s should be visible without a database write.', $check['granted'] )
			);

			get_role( 'editor' )->remove_cap( $check['granted'] );

			$this->assertSame(
				$check['denied'],
				map_meta_cap( $check['capability'], $user_id, $object ),
				'Revoking the role capability again should be visible just as immediately.'
			);
		} finally {
			get_role( 'editor' )->remove_cap( $check['granted'] );
			$roles->use_db = $use_db;
			_wp_reset_map_meta_cap_memo();
		}
	}

	/**
	 * Adding and removing a whole role is visible even when it is never stored.
	 */
	public function test_a_role_change_is_not_answered_from_the_memo_without_the_database() {
		$key    = $this->assert_memoizable( 'edit_post', self::$editor_id, array( self::$post_id ) );
		$roles  = wp_roles();
		$use_db = $roles->use_db;

		try {
			$roles->use_db = false;

			$this->plant_sentinel( $key );

			$roles->add_role( 'mmcm_role', 'MMCM Role', array( 'read' => true ) );

			$this->assertNull(
				_wp_map_meta_cap_memo( $key ),
				'Adding a role should discard the memo without a database write.'
			);

			$this->plant_sentinel( $key );

			$roles->remove_role( 'mmcm_role' );

			$this->assertNull(
				_wp_map_meta_cap_memo( $key ),
				'Removing a role should discard the memo without a database write.'
			);
		} finally {
			$roles->remove_role( 'mmcm_role' );
			$roles->use_db = $use_db;
			_wp_reset_map_meta_cap_memo();
		}
	}

	/**
	 * A memoized answer never survives the change it was invalidated by.
	 *
	 * This runs the invalidation end to end rather than firing an action directly:
	 * making the user the author of the post changes the answer, and `wp_update_post()`
	 * fires 'clean_post_cache' on the way, so the next mapping is computed against the
	 * new state within the same process.
	 */
	public function test_no_stale_answer_survives_a_state_change() {
		$before = map_meta_cap( 'edit_post', self::$subscriber_id, self::$post_id );

		$this->assertContains(
			'edit_others_posts',
			$before,
			'Editing a post belonging to somebody else should require edit_others_posts.'
		);

		wp_update_post(
			array(
				'ID'          => self::$post_id,
				'post_author' => self::$subscriber_id,
			)
		);

		$after = map_meta_cap( 'edit_post', self::$subscriber_id, self::$post_id );

		$this->assertNotContains(
			'edit_others_posts',
			$after,
			'Editing your own post should no longer require edit_others_posts.'
		);
		$this->assertContains(
			'edit_published_posts',
			$after,
			'Editing your own published post should require edit_published_posts.'
		);
	}

	/**
	 * The memo is discarded deterministically, however many times it is asked.
	 */
	public function test_the_memo_is_discarded_deterministically() {
		$key = $this->assert_memoizable( 'edit_post', self::$editor_id, array( self::$post_id ) );

		for ( $round = 1; $round <= 3; $round++ ) {
			$this->plant_sentinel( $key );

			_wp_reset_map_meta_cap_memo();

			$this->assertNull(
				_wp_map_meta_cap_memo( $key ),
				sprintf( 'Round %d should have discarded the memoized answer.', $round )
			);
		}

		_wp_reset_map_meta_cap_memo();

		$this->assertNull(
			_wp_map_meta_cap_memo( $key ),
			'Discarding an already empty memo should be a no-op rather than an error.'
		);
	}

	/**
	 * Nothing on the memo path writes to the output buffer.
	 *
	 * `phpunit.xml.dist` sets beStrictAboutOutputDuringTests="true", so a stray byte
	 * would already fail the suite, but the buffer is inspected directly here because
	 * this code also runs inside a 'shutdown' callback in the performance harness,
	 * where output would corrupt the measured response rather than fail a test.
	 */
	public function test_the_memo_path_writes_no_output() {
		$level = ob_get_level();

		ob_start();

		try {
			$key = _wp_map_meta_cap_memo_key( 'edit_post', self::$editor_id, array( self::$post_id ) );

			map_meta_cap( 'edit_post', self::$editor_id, self::$post_id );
			map_meta_cap( 'edit_post', self::$editor_id, self::$post_id );
			_wp_map_meta_cap_memo( $key );
			_wp_map_meta_cap_memo( 'mmcm_absent_key' );
			_wp_reset_map_meta_cap_memo();
		} finally {
			$output = ob_get_clean();
		}

		$this->assertSame( '', $output, 'The memo path should write nothing to the output buffer.' );
		$this->assertSame( $level, ob_get_level(), 'The memo path should leave the buffer nesting unchanged.' );
	}

	/**
	 * Nothing on the memo path raises a notice, a warning or a deprecation.
	 */
	public function test_the_memo_path_raises_no_diagnostics() {
		$diagnostics = array();

		set_error_handler(
			static function ( $errno, $errstr ) use ( &$diagnostics ) {
				$diagnostics[] = $errno . ': ' . $errstr;

				return true;
			}
		);

		try {
			$key = _wp_map_meta_cap_memo_key( 'edit_post', self::$editor_id, array( self::$post_id ) );

			_wp_map_meta_cap_memo( 'mmcm_absent_key' );
			_wp_map_meta_cap_memo( $key, array( self::SENTINEL_CAP ) );
			_wp_map_meta_cap_memo( $key );
			_wp_reset_map_meta_cap_memo();
			_wp_reset_map_meta_cap_memo();

			_wp_map_meta_cap_memo_key( 'edit_post', self::$editor_id, array() );
			_wp_map_meta_cap_memo_key( 'edit_post', self::$editor_id, array( null ) );
			_wp_map_meta_cap_memo_key( 'edit_post', self::$editor_id, array( new stdClass() ) );
			_wp_map_meta_cap_memo_key( array( 'edit_post' ), self::$editor_id, array( self::$post_id ) );
			_wp_map_meta_cap_memo_key( 'edit_post', new stdClass(), array( self::$post_id ) );

			map_meta_cap( 'edit_post', self::$editor_id, self::$post_id );
			map_meta_cap( 'edit_post', self::$editor_id, self::$post_id );
			map_meta_cap( 'promote_user', self::$administrator_id );
		} finally {
			restore_error_handler();
		}

		$this->assertSame(
			array(),
			$diagnostics,
			'The memo path should raise no notice, warning or deprecation.'
		);
	}

	/**
	 * A registry that is not set at all is described as absent rather than read.
	 *
	 * `$super_admins` is undefined on most installations, and the three registries are
	 * still undefined while `wp-settings.php` is running, so every one of them is read
	 * defensively. Reading an undefined global raises a warning on PHP 8, which
	 * `phpunit.xml.dist` converts into an exception, so this is asserted rather than
	 * assumed.
	 */
	public function test_an_absent_registry_raises_no_diagnostics() {
		$diagnostics = array();
		$key         = '';
		$registries  = array( 'wp_post_types', 'wp_post_statuses', 'wp_taxonomies', 'super_admins' );
		$present     = array();
		$originals   = array();

		foreach ( $registries as $registry ) {
			$present[ $registry ] = array_key_exists( $registry, $GLOBALS );

			if ( $present[ $registry ] ) {
				$originals[ $registry ] = $GLOBALS[ $registry ];
			}
		}

		set_error_handler(
			static function ( $errno, $errstr ) use ( &$diagnostics ) {
				$diagnostics[] = $errno . ': ' . $errstr;

				return true;
			}
		);

		try {
			foreach ( $registries as $registry ) {
				unset( $GLOBALS[ $registry ] );
			}

			$key = _wp_map_meta_cap_memo_key( 'edit_post', self::$editor_id, array( self::$post_id ) );
		} finally {
			foreach ( $registries as $registry ) {
				if ( $present[ $registry ] ) {
					$GLOBALS[ $registry ] = $originals[ $registry ];
				} else {
					unset( $GLOBALS[ $registry ] );
				}
			}

			restore_error_handler();
		}

		$this->assertSame(
			array(),
			$diagnostics,
			'An absent registry should not raise a notice.'
		);
		$this->assertStringContainsString(
			'|-1|-1|-1|-1|',
			$key,
			'An absent registry should be described as absent in the memo key.'
		);
	}

	/**
	 * The Super Admin logins are described by content, not merely by how many there are.
	 *
	 * Swapping one login for another of the same length leaves the number of Super
	 * Admins unchanged, so a key that carried only the size of the list would be
	 * byte-identical across a change that decides an authorization answer.
	 *
	 * @global array|null $super_admins Super admin logins, when they are defined.
	 */
	public function test_the_key_carries_the_super_admin_logins() {
		$present  = array_key_exists( 'super_admins', $GLOBALS );
		$original = $present ? $GLOBALS['super_admins'] : null;

		try {
			$GLOBALS['super_admins'] = array( 'aaa', 'bbb' );
			$first                   = $this->assert_memoizable( 'edit_post', self::$editor_id, array( self::$post_id ) );

			$GLOBALS['super_admins'] = array( 'aaa', 'ccc' );
			$same_length             = $this->assert_memoizable( 'edit_post', self::$editor_id, array( self::$post_id ) );

			$GLOBALS['super_admins'] = array( 'aaa', 'bbb', 'ccc' );
			$longer                  = $this->assert_memoizable( 'edit_post', self::$editor_id, array( self::$post_id ) );

			$GLOBALS['super_admins'] = array( 'aaa', 'bbb' );
			$restored                = $this->assert_memoizable( 'edit_post', self::$editor_id, array( self::$post_id ) );
		} finally {
			if ( $present ) {
				$GLOBALS['super_admins'] = $original;
			} else {
				unset( $GLOBALS['super_admins'] );
			}
		}

		$this->assertNotSame(
			$first,
			$same_length,
			'Replacing a login with another of the same length should change the memo key.'
		);
		$this->assertNotSame(
			$first,
			$longer,
			'Adding a login should change the memo key.'
		);
		$this->assertSame(
			$first,
			$restored,
			'Restoring the original logins should restore the original memo key.'
		);
	}

	/**
	 * The self-removal mapping is never memoized, whichever user is asked about.
	 *
	 * `remove_user` derives its answer from the capabilities a single user holds, and
	 * `WP_User::add_cap()`, `WP_User::remove_cap()` and `WP_User::remove_all_caps()`
	 * change those capabilities without announcing which user they changed. Rather than
	 * guess, the memo declines the whole capability, and every other mapping that reads
	 * those same capabilities with it.
	 */
	public function test_the_self_removal_capability_is_never_memoized() {
		$this->assertSame(
			'',
			_wp_map_meta_cap_memo_key( 'remove_user', self::$administrator_id, array( self::$administrator_id ) ),
			'A self-removal check should never be memoizable.'
		);
		$this->assertSame(
			'',
			_wp_map_meta_cap_memo_key( 'remove_user', self::$administrator_id, array( self::$subscriber_id ) ),
			'A removal check about somebody else should not be memoizable either, because one mapping serves both.'
		);

		/*
		 * Every mapping that reads the user's own capabilities is declined for the same
		 * reason, so 'delete_user' is refused alongside it rather than memoized.
		 */
		$this->assertSame(
			'',
			_wp_map_meta_cap_memo_key( 'delete_user', self::$administrator_id, array( self::$subscriber_id ) ),
			'A deletion check reads the same capabilities and should not be memoizable.'
		);

		/*
		 * The decline follows what a mapping reads, not the word "user" in its name.
		 * Both of these are plain rewrites that read nothing, so both stay memoizable.
		 */
		$this->assert_memoizable( 'promote_user', self::$administrator_id, array( self::$subscriber_id ) );
		$this->assert_memoizable( 'remove_users', self::$administrator_id, array( self::$subscriber_id ) );
	}

	/**
	 * Revoking a capability for one user closes the self-removal gate immediately.
	 *
	 * This is the security-relevant direction: outside of multisite `is_super_admin()`
	 * falls through to `WP_User::has_cap( 'delete_users' )`, so revoking that capability
	 * for a single administrator must stop them removing themselves. A stale mapping
	 * would name `remove_users`, which the user still holds through the administrator
	 * role, so an answer that outlived the revocation would widen access rather than
	 * fail safe.
	 */
	public function test_self_removal_is_denied_once_the_delete_users_capability_is_revoked() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = new WP_User( $user_id );

		if ( is_multisite() ) {
			/*
			 * Under multisite `is_super_admin()` reads the network `site_admins` option
			 * instead, and a site administrator is not a Super Admin, so the mapping is
			 * already a denial before anything is revoked.
			 */
			$this->assertFalse( is_super_admin( $user_id ), 'A multisite site administrator should not be a Super Admin.' );
		} else {
			$this->assertTrue( is_super_admin( $user_id ), 'An administrator holds delete_users and is therefore a Super Admin.' );
		}

		$this->assertTrue(
			$user->has_cap( 'remove_users' ),
			'The administrator should hold remove_users, which is what makes a stale answer widen access.'
		);

		$expected_before = is_multisite() ? array( 'do_not_allow' ) : array( 'remove_users' );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertSame(
				$expected_before,
				$this->warm( 'remove_user', $user_id, $user_id ),
				'Repeated self-removal checks should all produce the mapping for the current state.'
			);
		}

		$this->watch_invalidation_hooks(
			array(
				'clean_user_cache',
				'set_user_role',
				'add_user_role',
				'remove_user_role',
				'granted_super_admin',
				'revoked_super_admin',
				'added_option',
				'updated_option',
				'deleted_option',
			)
		);

		/*
		 * WP_User::remove_cap() is a no-op for a capability that comes from a role: it
		 * returns early unless the capability is present in the per user overrides. The
		 * documented way to revoke one for a single user is add_cap( $cap, false ), which
		 * writes a denial into those overrides.
		 */
		$user->add_cap( 'delete_users', false );

		$this->assertSame(
			0,
			$this->invalidation_fires,
			'Revoking a capability for one user fires none of the role, cache or option actions, which is what makes this worth asserting.'
		);
		$this->assertFalse(
			is_super_admin( $user_id ),
			'Revoking delete_users should end Super Admin status outside of multisite, and leave it unchanged under it.'
		);
		$this->assertTrue(
			( new WP_User( $user_id ) )->has_cap( 'remove_users' ),
			'The user should still hold remove_users, so a stale mapping would widen access.'
		);

		/*
		 * The warm answer has to be read first. Asking cold discards the memo and
		 * repopulates it with the current answer, so reading cold before warm would
		 * make the comparison below true however stale the memo had been.
		 */
		$warm = $this->warm( 'remove_user', $user_id, $user_id );
		$cold = $this->cold( 'remove_user', $user_id, $user_id );

		$this->assertSame(
			array( 'do_not_allow' ),
			$cold,
			'A user who is not a Super Admin should not be able to remove themselves.'
		);
		$this->assertSame(
			$cold,
			$warm,
			sprintf(
				'Self-removal should be mapped from the current state, not from an answer memoized before the revocation. Mapped [%s].',
				implode( ',', $warm )
			)
		);
		$this->assertFalse(
			user_can( $user_id, 'remove_user', $user_id ),
			'user_can() should refuse self-removal once delete_users has been revoked.'
		);

		wp_set_current_user( $user_id );

		$this->assertFalse(
			current_user_can( 'remove_user', $user_id ),
			'current_user_can() should refuse self-removal once delete_users has been revoked.'
		);

		wp_set_current_user( 0 );

		// And the other direction, in the same process.
		( new WP_User( $user_id ) )->remove_cap( 'delete_users' );

		$restored_warm = $this->warm( 'remove_user', $user_id, $user_id );

		$this->assertSame(
			$this->cold( 'remove_user', $user_id, $user_id ),
			$restored_warm,
			'Removing the per user denial should restore the role derived mapping immediately.'
		);
	}

	/**
	 * Stripping every capability from a user closes the self-removal gate immediately.
	 *
	 * `WP_User::remove_all_caps()` deletes the per user capabilities and the user level
	 * from user meta, which is a third path that changes what `is_super_admin()` answers
	 * without touching a role or an option.
	 */
	public function test_self_removal_is_denied_once_every_capability_is_removed() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		for ( $i = 0; $i < 3; $i++ ) {
			$this->warm( 'remove_user', $user_id, $user_id );
		}

		( new WP_User( $user_id ) )->remove_all_caps();

		$this->assertFalse(
			is_super_admin( $user_id ),
			'A user with no capabilities at all should not be a Super Admin.'
		);
		$this->assertSame(
			array( 'do_not_allow' ),
			$this->warm( 'remove_user', $user_id, $user_id ),
			'Self-removal should be denied once every capability has been removed.'
		);
		$this->assertFalse(
			user_can( $user_id, 'remove_user', $user_id ),
			'user_can() should refuse self-removal for a user with no capabilities.'
		);
	}

	/**
	 * No memoized mapping is left behind by a capability change that no action reports.
	 *
	 * The invalidation actions were chosen from the state the mapping is derived from, so
	 * this asks the complementary question empirically: it discovers which mappings
	 * actually move when a user's own capabilities change, and requires every one of them
	 * to be declined by the key builder as well as to agree with the mapping afterwards.
	 * A new mapping arm that read `is_super_admin()` without being declined would fail
	 * here rather than pass silently.
	 */
	public function test_no_memoized_mapping_survives_a_change_to_the_users_own_capabilities() {
		$user_id    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user       = new WP_User( $user_id );
		$comment_id = self::factory()->comment->create( array( 'comment_post_ID' => self::$post_id ) );

		$questions = array(
			'edit_post'         => array( 'edit_post', self::$post_id ),
			'delete_post'       => array( 'delete_post', self::$post_id ),
			'read_post'         => array( 'read_post', self::$post_id ),
			'publish_post'      => array( 'publish_post', self::$post_id ),
			'edit_comment'      => array( 'edit_comment', $comment_id ),
			'edit_user'         => array( 'edit_user', self::$author_id ),
			'delete_user'       => array( 'delete_user', self::$author_id ),
			'promote_user'      => array( 'promote_user', self::$author_id ),
			'remove_user'       => array( 'remove_user', $user_id ),
			'activate_plugin'   => array( 'activate_plugin', 'hello.php' ),
			'deactivate_plugin' => array( 'deactivate_plugin', 'hello.php' ),
		);

		$this->assertFalse( is_super_admin( $user_id ), 'A subscriber should not be a Super Admin.' );

		$before = array();

		foreach ( $questions as $label => $question ) {
			$before[ $label ] = $this->cold( $question[0], $user_id, $question[1] );

			// Ask again, warm, so that anything memoizable is definitely memoized.
			for ( $i = 0; $i < 3; $i++ ) {
				$this->warm( $question[0], $user_id, $question[1] );
			}
		}

		$user->add_cap( 'delete_users' );

		if ( is_multisite() ) {
			$this->assertFalse(
				is_super_admin( $user_id ),
				'Under multisite, granting delete_users directly should not confer Super Admin status.'
			);
		} else {
			$this->assertTrue(
				is_super_admin( $user_id ),
				'Outside of multisite, granting delete_users should confer Super Admin status.'
			);
		}

		$moved = array();

		foreach ( $questions as $label => $question ) {
			/*
			 * Warm first, then cold. Asking cold discards the memo and repopulates it
			 * with the current answer, so the comparison below would hold however stale
			 * the memo had been if the order were reversed.
			 */
			$warm = $this->warm( $question[0], $user_id, $question[1] );
			$cold = $this->cold( $question[0], $user_id, $question[1] );

			if ( $cold !== $before[ $label ] ) {
				$moved[] = $label;

				$this->assertSame(
					'',
					_wp_map_meta_cap_memo_key( $question[0], $user_id, array( $question[1] ) ),
					sprintf(
						'The %s mapping changes with the capabilities the user holds, so it must not be memoizable.',
						$label
					)
				);
			}

			$this->assertSame(
				$cold,
				$warm,
				sprintf(
					'The %s mapping should be answered from the current state after a capability grant. cold=[%s] warm=[%s]',
					$label,
					implode( ',', $cold ),
					implode( ',', $warm )
				)
			);
		}

		if ( is_multisite() ) {
			$this->assertSame(
				array(),
				$moved,
				'Under multisite no mapping is derived from the capabilities the user holds, because is_super_admin() reads the network option instead.'
			);
		} else {
			$this->assertSame(
				array( 'remove_user' ),
				$moved,
				'Outside of multisite the self-removal mapping should be the only one derived from the capabilities the user holds.'
			);
		}
	}

	/**
	 * A capability granted directly to one user decides the answer straight away.
	 *
	 * The mapping and the decision are different things: `map_meta_cap()` returns the
	 * capabilities that are required, and `WP_User::has_cap()` then decides. This covers
	 * the decision, which is what a caller acts on.
	 */
	public function test_the_decision_follows_a_capability_granted_to_one_user() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = new WP_User( $user_id );

		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertFalse(
				user_can( $user_id, 'edit_post', self::$post_id ),
				'A subscriber should not be able to edit a post belonging to somebody else.'
			);
		}

		$user->add_cap( 'edit_others_posts' );
		$user->add_cap( 'edit_published_posts' );

		$this->assertTrue(
			user_can( $user_id, 'edit_post', self::$post_id ),
			'A capability granted directly to the user should be honoured on the next check.'
		);

		( new WP_User( $user_id ) )->remove_cap( 'edit_others_posts' );

		$this->assertFalse(
			user_can( $user_id, 'edit_post', self::$post_id ),
			'A capability revoked from the user should close the gate on the next check.'
		);
	}

	/**
	 * Writing a user's capabilities discards the memo, whichever writer is used.
	 *
	 * The per user capabilities live in user meta under a site prefixed key, so the
	 * metadata actions are the ones that report a capability having been granted to, or
	 * revoked from, one user. Between the three metadata functions and the three
	 * `WP_User` methods that call them, every one of those actions is reached.
	 */
	public function test_a_user_meta_write_discards_the_memo() {
		global $wpdb;

		$user_id        = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$capability_key = $wpdb->get_blog_prefix() . 'capabilities';

		$writers = array(
			'a first write'     => static function () use ( $user_id, $capability_key ) {
				add_user_meta( $user_id, $capability_key, array( 'mmcm_probe_cap' => true ) );
			},
			'an update'         => static function () use ( $user_id, $capability_key ) {
				update_user_meta( $user_id, $capability_key, array( 'mmcm_probe_cap' => false ) );
			},
			'a delete'          => static function () use ( $user_id, $capability_key ) {
				delete_user_meta( $user_id, $capability_key );
			},
			'add_cap()'         => static function () use ( $user_id ) {
				$user = new WP_User( $user_id );
				$user->add_cap( 'mmcm_probe_cap' );
			},
			'remove_cap()'      => static function () use ( $user_id ) {
				$user = new WP_User( $user_id );
				$user->remove_cap( 'mmcm_probe_cap' );
			},
			'remove_all_caps()' => static function () use ( $user_id ) {
				$user = new WP_User( $user_id );
				$user->remove_all_caps();
			},
		);

		foreach ( $writers as $label => $writer ) {
			$key = $this->assert_memoizable( 'edit_post', self::$editor_id, array( self::$post_id ) );

			$this->plant_sentinel( $key );

			$writer();

			$this->assertNull(
				_wp_map_meta_cap_memo( $key ),
				sprintf( 'The memo should be discarded by %s.', $label )
			);
		}
	}

	/**
	 * Every kind of state the mapping reads is reported by an invalidation action.
	 *
	 * The mapping is derived from posts, post meta, comments, terms, users, roles, per
	 * user capabilities, Super Admin membership, options, network options, the registries
	 * and the current site. This asserts the covering action for each of them, so that a
	 * registration which is lost fails a test that says what was lost and why it mattered,
	 * rather than only shrinking a list.
	 *
	 * @dataProvider data_state_the_mapping_reads
	 *
	 * @param string      $writer   The public API that changes the state.
	 * @param string[]    $hooks    Invalidation actions that report the change.
	 * @param string|null $callback Callback the actions carry. Defaults to the
	 *                              unconditional reset.
	 */
	public function test_every_state_the_mapping_reads_is_reported_by_an_action( $writer, $hooks, $callback = null ) {
		if ( null === $callback ) {
			$callback = '_wp_reset_map_meta_cap_memo';
		}

		foreach ( $hooks as $hook ) {
			$this->assertSame(
				10,
				has_action( $hook, $callback ),
				sprintf(
					'State written by %s is read by the mapping, so the %s action should discard the memo.',
					$writer,
					$hook
				)
			);
		}
	}

	/**
	 * Data provider.
	 *
	 * The third parameter is only given where the actions carry a callback other than
	 * the unconditional reset, which the per user capabilities do because their actions
	 * report every user meta key and only the capability array matters.
	 *
	 * @return array[] Test parameters, keyed by the state the mapping reads.
	 */
	public static function data_state_the_mapping_reads() {
		return array(
			'post objects'            => array(
				'wp_update_post()',
				array( 'clean_post_cache' ),
			),
			'the trashed post status' => array(
				'update_post_meta()',
				array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ),
			),
			'comment objects'         => array(
				'wp_update_comment()',
				array( 'clean_comment_cache' ),
			),
			'term objects'            => array(
				'wp_update_term()',
				array( 'clean_term_cache' ),
			),
			'user objects'            => array(
				'wp_update_user()',
				array( 'clean_user_cache' ),
			),
			'role membership'         => array(
				'WP_User::set_role()',
				array( 'set_user_role', 'add_user_role', 'remove_user_role' ),
			),
			'per user capabilities'   => array(
				'WP_User::add_cap()',
				array( 'added_user_meta', 'updated_user_meta', 'deleted_user_meta' ),
				'_wp_reset_map_meta_cap_memo_on_user_meta',
			),
			'role capability maps'    => array(
				'WP_Roles::add_cap()',
				array( 'added_option', 'updated_option', 'deleted_option' ),
			),
			'Super Admin membership'  => array(
				'grant_super_admin()',
				array( 'granted_super_admin', 'revoked_super_admin', 'update_site_option' ),
			),
			'network options'         => array(
				'update_site_option()',
				array( 'add_site_option', 'update_site_option', 'delete_site_option' ),
			),
			'post type registrations' => array(
				'register_post_type()',
				array( 'registered_post_type', 'unregistered_post_type' ),
			),
			'taxonomy registrations'  => array(
				'register_taxonomy()',
				array( 'registered_taxonomy', 'unregistered_taxonomy' ),
			),
			'the current site'        => array(
				'switch_to_blog()',
				array( 'switch_blog' ),
			),
		);
	}

	/**
	 * The state no action reports is carried by the key or excluded from the memo.
	 *
	 * Code that unsets a registry key directly fires nothing, so the registry sizes are
	 * carried in the key as well as being reported by the registration actions. The
	 * capabilities one user holds cannot be described by either mechanism, because they
	 * are read back through the `user_has_cap` filter, so the one mapping derived from
	 * them is declined instead.
	 *
	 * @global array      $wp_post_types    Post type registry.
	 * @global array      $wp_post_statuses Post status registry.
	 * @global array      $wp_taxonomies    Taxonomy registry.
	 * @global array|null $super_admins     Super admin logins, when they are defined.
	 */
	public function test_the_state_no_action_reports_is_keyed_or_declined() {
		$key = $this->assert_memoizable( 'edit_post', self::$editor_id, array( self::$post_id ) );

		$this->assertSame(
			(string) get_current_blog_id(),
			strstr( $key, '|', true ),
			'The current site should be the first component of the memo key.'
		);

		foreach ( array( 'wp_post_types', 'wp_post_statuses', 'wp_taxonomies', 'super_admins' ) as $registry ) {
			$original = isset( $GLOBALS[ $registry ] ) ? $GLOBALS[ $registry ] : null;

			$GLOBALS[ $registry ] = is_array( $original )
				? array_merge( $original, array( 'mmcm_extra_entry' => true ) )
				: array( 'mmcm_extra_entry' => true );

			$changed = _wp_map_meta_cap_memo_key( 'edit_post', self::$editor_id, array( self::$post_id ) );

			if ( null === $original ) {
				unset( $GLOBALS[ $registry ] );
			} else {
				$GLOBALS[ $registry ] = $original;
			}

			$this->assertNotSame(
				$key,
				$changed,
				sprintf( 'A change to %s can be made without firing an action, so it should be carried by the memo key.', $registry )
			);
		}

		$this->assertSame(
			'',
			_wp_map_meta_cap_memo_key( 'remove_user', self::$administrator_id, array( self::$administrator_id ) ),
			'The one mapping derived from the capabilities a single user holds should be declined.'
		);
	}

	/**
	 * A capability checked against no object at all is never memoized.
	 *
	 * The mapping treats a missing first argument and an argument of null as the same
	 * thing, reports the call through `_doing_it_wrong()` and refuses it. Memoizing that
	 * refusal would both hide the report from every call after the first and keep an
	 * answer that exists only because the caller passed nothing.
	 */
	public function test_a_missing_object_is_never_memoized() {
		$shapes = array(
			'no argument at all'        => array(),
			'an argument of null'       => array( null ),
			'a null first of two'       => array( null, self::$post_id ),
			'no argument at index zero' => array( 1 => self::$post_id ),
		);

		foreach ( $shapes as $shape => $args ) {
			$this->assertSame(
				'',
				_wp_map_meta_cap_memo_key( 'edit_post', self::$editor_id, $args ),
				sprintf( 'A capability checked with %s should not be memoizable.', $shape )
			);
		}

		$this->assertNotSame(
			'',
			_wp_map_meta_cap_memo_key( 'edit_post', self::$editor_id, array( self::$post_id ) ),
			'A capability checked against a real object should still be memoizable.'
		);
	}

	/**
	 * The report about a capability checked against no object is made on every call.
	 */
	public function test_the_incorrect_usage_report_is_made_on_every_call_when_no_object_is_passed() {
		$this->setExpectedIncorrectUsage( 'map_meta_cap' );

		$editor_id = self::$editor_id;

		$shapes = array(
			'an argument of null' => static function () use ( $editor_id ) {
				return map_meta_cap( 'edit_post', $editor_id, null );
			},
			'no argument at all'  => static function () use ( $editor_id ) {
				return map_meta_cap( 'edit_post', $editor_id );
			},
		);

		foreach ( $shapes as $shape => $mapping ) {
			$this->assertSame(
				3,
				$this->count_incorrect_usage_reports( $mapping, 3 ),
				sprintf(
					'Three identical calls with %s should each be reported, because the call is never memoized.',
					$shape
				)
			);

			$this->assertSame(
				array( 'do_not_allow' ),
				$mapping(),
				sprintf( 'A capability checked with %s should still be refused.', $shape )
			);
		}
	}

	/**
	 * The report about an unregistered post type follows the memo, and is never lost.
	 *
	 * This is the one report the memo does not preserve on every call. Recognising the
	 * call while building the key would mean loading the post and its type, which is the
	 * work the memo removes, so the report is made the first time the question is asked
	 * and again after the memo is discarded. The answer itself never changes.
	 */
	public function test_the_incorrect_usage_report_for_an_unregistered_post_type_follows_the_memo() {
		$this->setExpectedIncorrectUsage( 'map_meta_cap' );

		register_post_type( 'mmcm_orphan', array( 'public' => true ) );

		$orphan_id = self::factory()->post->create(
			array(
				'post_type'   => 'mmcm_orphan',
				'post_status' => 'publish',
				'post_author' => self::$author_id,
			)
		);

		unregister_post_type( 'mmcm_orphan' );
		clean_post_cache( $orphan_id );

		$editor_id = self::$editor_id;
		$mapping   = static function () use ( $editor_id, $orphan_id ) {
			return map_meta_cap( 'edit_post', $editor_id, $orphan_id );
		};

		$this->assertSame(
			3,
			$this->count_incorrect_usage_reports( $mapping, 3, true ),
			'Discarding the memo between calls should make the report observable every time.'
		);

		$this->assertSame(
			1,
			$this->count_incorrect_usage_reports( $mapping, 3 ),
			'Three identical calls answered from the memo should report once, on the first of them.'
		);

		$this->assertSame(
			1,
			$this->count_incorrect_usage_reports( $mapping, 1, true ),
			'Discarding the memo should make the report observable again.'
		);

		$answers = array( $mapping(), $mapping(), $mapping() );

		$this->assertSame(
			array( $answers[0], $answers[0], $answers[0] ),
			$answers,
			'The answer should be the same whether or not it was reported.'
		);
	}

	/**
	 * The front page and posts page guard is answered from the current option value.
	 *
	 * Deleting the page assigned to `page_on_front` or `page_for_posts` requires
	 * `manage_options` instead of the capability the page's own post type would ask for,
	 * and only deletion is guarded that way: editing the same page is deliberately not.
	 * The guard reads an option, so the answer changes without the page changing at all,
	 * which is what makes it worth pinning here. `updated_option` is one of the actions
	 * that discards the memo, so the warm answer has to follow the option.
	 */
	public function test_the_front_page_guard_is_answered_from_the_current_option() {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_author' => self::$author_id,
			)
		);

		$original = get_option( 'page_on_front' );

		$unguarded = $this->cold( 'delete_post', self::$editor_id, $page_id );

		$this->assertNotContains(
			'manage_options',
			$unguarded,
			'A page that is not the front page should be deleted under its own post type capability.'
		);
		$this->assertTrue(
			user_can( self::$editor_id, 'delete_post', $page_id ),
			'An editor should be able to delete an ordinary published page.'
		);

		update_option( 'page_on_front', $page_id );

		/*
		 * Read warm. Asking cold would discard the memo and repopulate it with the
		 * current answer, which would pass however stale the memo had been.
		 */
		$guarded = $this->warm( 'delete_post', self::$editor_id, $page_id );

		$this->assertSame(
			array( 'manage_options' ),
			$guarded,
			'Once the page is the front page, deleting it should require manage_options.'
		);
		$this->assertFalse(
			user_can( self::$editor_id, 'delete_post', $page_id ),
			'An editor holds no manage_options, so the guard should deny the deletion.'
		);

		$this->assertNotSame(
			array( 'manage_options' ),
			$this->warm( 'edit_post', self::$editor_id, $page_id ),
			'Editing the front page is deliberately not guarded, only deleting it.'
		);

		update_option( 'page_on_front', $original );

		$this->assertSame(
			$unguarded,
			$this->warm( 'delete_post', self::$editor_id, $page_id ),
			'Restoring the option should restore the unguarded answer.'
		);
	}

	/**
	 * A mapping that reports nothing is unaffected by the memo.
	 */
	public function test_a_mapping_that_reports_nothing_is_unaffected_by_the_memo() {
		$editor_id = self::$editor_id;
		$post_id   = self::$post_id;

		$shapes = array(
			'a real post'                => static function () use ( $editor_id, $post_id ) {
				return map_meta_cap( 'edit_post', $editor_id, $post_id );
			},
			'a post that does not exist' => static function () use ( $editor_id ) {
				return map_meta_cap( 'edit_post', $editor_id, 99999999 );
			},
		);

		foreach ( $shapes as $shape => $mapping ) {
			$this->assertSame(
				0,
				$this->count_incorrect_usage_reports( $mapping, 3, true ),
				sprintf( 'A capability checked against %s should report nothing.', $shape )
			);

			$this->assertSame(
				0,
				$this->count_incorrect_usage_reports( $mapping, 3 ),
				sprintf( 'Memoizing a capability checked against %s should not start a report.', $shape )
			);
		}
	}

	/**
	 * No mapping is memoized while a callback sits on a filter that mapping reads.
	 *
	 * This is the transitive half of the memo's filter safety, and the half that is easy
	 * to miss. A callback on `map_meta_cap` is the obvious way to change what a mapping
	 * answers, but it is not the only one: the mappings the memo is allowed to keep read
	 * post, post status, post meta, comment and option state, and every one of those
	 * reads passes through a filter of its own. A callback on any of them changes the
	 * answer just as completely, and none of them fires an action when it is registered,
	 * so the memo cannot be discarded in response to one appearing.
	 *
	 * Each hook is proven three ways: the mapping stops being memoizable while the
	 * callback is registered, the memoized answer stops being returned, and - because the
	 * sentinel planted beforehand is still readable afterwards - the answer computed
	 * while the callback was registered was never stored. The last of those is what
	 * covers the guard on the write, not just the guard on the read.
	 */
	public function test_no_mapping_is_memoized_while_a_policy_filter_is_registered() {
		foreach ( self::POLICY_FILTERS as $policy_filter ) {
			$key = $this->assert_memoizable( 'edit_post', self::$subscriber_id, array( self::$post_id ) );
			$this->plant_sentinel( $key );

			add_filter( $policy_filter, array( $this, 'pass_filtered_value_through' ) );

			$this->assertSame(
				'',
				_wp_map_meta_cap_memo_key( 'edit_post', self::$subscriber_id, array( self::$post_id ) ),
				sprintf( 'A callback on %s should make the mapping unmemoizable.', $policy_filter )
			);
			$this->assertNotContains(
				self::SENTINEL_CAP,
				map_meta_cap( 'edit_post', self::$subscriber_id, self::$post_id ),
				sprintf( 'A callback on %s should stop the memoized answer being returned.', $policy_filter )
			);

			remove_filter( $policy_filter, array( $this, 'pass_filtered_value_through' ) );

			$this->assertSame(
				array( self::SENTINEL_CAP ),
				_wp_map_meta_cap_memo( $key ),
				sprintf( 'The mapping computed while %s was registered should not have been stored.', $policy_filter )
			);
			$this->assertSame(
				$key,
				_wp_map_meta_cap_memo_key( 'edit_post', self::$subscriber_id, array( self::$post_id ) ),
				sprintf( 'Removing the callback on %s should make the mapping memoizable again.', $policy_filter )
			);

			_wp_reset_map_meta_cap_memo();
		}
	}

	/**
	 * The watch list reports the filters it names, and nothing beyond them.
	 *
	 * The first half is the security property. The second half is what keeps the memo
	 * worth having: watching a hook core itself occupies on every request, or one that
	 * only an unmemoizable mapping reads, would stand the memo down permanently or
	 * needlessly. `default_option_link_manager_enabled` is the sharpest case - core
	 * registers `__return_true` on it unconditionally in `default-filters.php` - and it
	 * is the reason `manage_links` is declined by capability name instead.
	 */
	public function test_only_the_watched_filters_make_a_mapping_unmemoizable() {
		$this->assertFalse(
			_wp_map_meta_cap_policy_filter_registered(),
			'No watched filter should carry a callback before a test registers one.'
		);

		foreach ( self::POLICY_FILTERS as $policy_filter ) {
			add_filter( $policy_filter, array( $this, 'pass_filtered_value_through' ) );

			$this->assertTrue(
				_wp_map_meta_cap_policy_filter_registered(),
				sprintf( 'A callback on the watched filter %s should be reported.', $policy_filter )
			);

			remove_filter( $policy_filter, array( $this, 'pass_filtered_value_through' ) );

			$this->assertFalse(
				_wp_map_meta_cap_policy_filter_registered(),
				sprintf( 'Removing the callback on %s should stop it being reported.', $policy_filter )
			);
		}

		foreach ( self::UNWATCHED_HOOKS as $hook ) {
			add_filter( $hook, array( $this, 'pass_filtered_value_through' ) );

			$this->assertFalse(
				_wp_map_meta_cap_policy_filter_registered(),
				sprintf(
					'%s is read only by a mapping that is never memoized, so a callback on it should not stand down every other mapping.',
					$hook
				)
			);

			remove_filter( $hook, array( $this, 'pass_filtered_value_through' ) );
		}
	}

	/**
	 * A watched filter cannot leave a stale answer behind it, in either direction.
	 *
	 * Deleting the page assigned to `page_on_front` requires `manage_options` instead of
	 * the capability the page's own post type would ask for. The guard reads an option,
	 * and an option read can be answered by a filter without the option, the page or the
	 * user changing at all - so a memo that ignored the filter would answer from before
	 * it appeared.
	 *
	 * Both directions matter and both are asserted. Carrying the pre-filter answer
	 * forward grants a deletion the policy now forbids, which is the security failure.
	 * Carrying the filtered answer forward past the removal denies a deletion the policy
	 * now allows, which is the correctness failure. Every read after the first is taken
	 * warm on purpose: asking cold would discard the memo and repopulate it with the
	 * current answer, which would pass however stale the memo had been.
	 */
	public function test_a_watched_filter_cannot_leave_a_stale_answer_behind() {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_author' => self::$author_id,
			)
		);

		$unguarded = $this->cold( 'delete_post', self::$editor_id, $page_id );

		$this->assertNotContains(
			'manage_options',
			$unguarded,
			'A page that is not the front page should be deleted under its own post type capability.'
		);
		$this->assertTrue(
			user_can( self::$editor_id, 'delete_post', $page_id ),
			'An editor should be able to delete an ordinary published page.'
		);

		$front_page = static function () use ( $page_id ) {
			return $page_id;
		};

		add_filter( 'pre_option_page_on_front', $front_page );

		$this->assertSame(
			array( 'manage_options' ),
			$this->warm( 'delete_post', self::$editor_id, $page_id ),
			'A filter that makes the page the front page should be obeyed, not answered from before it was registered.'
		);
		$this->assertFalse(
			user_can( self::$editor_id, 'delete_post', $page_id ),
			'An editor holds no manage_options, so the filtered option should deny the deletion.'
		);

		remove_filter( 'pre_option_page_on_front', $front_page );

		$this->assertSame(
			$unguarded,
			$this->warm( 'delete_post', self::$editor_id, $page_id ),
			'Removing the filter should restore the unguarded answer, rather than leaving the denial behind.'
		);
		$this->assertTrue(
			user_can( self::$editor_id, 'delete_post', $page_id ),
			'The editor should be able to delete the page again once the filter is gone.'
		);
	}

	/**
	 * A term deletion check follows the default term option through a filter.
	 *
	 * This is the reported reproduction, kept in the shape it was reported in: a
	 * `pre_option_default_category` callback either side of `current_user_can(
	 * 'delete_term' )`. Deleting a taxonomy's default term is refused outright, and the
	 * check for it reads `default_{$taxonomy}` and `default_term_{$taxonomy}` - option
	 * names that are only known once the taxonomy is registered, which is why no static
	 * watch list can cover them and why the term capabilities are declined by name
	 * instead.
	 *
	 * The answer is therefore recomputed on every ask, and has to track the filter both
	 * as it appears and as it goes away.
	 */
	public function test_a_term_deletion_check_follows_the_default_term_option() {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );

		$this->assertSame(
			'',
			_wp_map_meta_cap_memo_key( 'delete_term', self::$administrator_id, array( $term_id ) ),
			'A term capability reads dynamically named filters and options, so it should never be memoized.'
		);

		wp_set_current_user( self::$administrator_id );
		_wp_reset_map_meta_cap_memo();

		$this->assertTrue(
			current_user_can( 'delete_term', $term_id ),
			'An administrator should be able to delete an ordinary category.'
		);

		$default_category = static function () use ( $term_id ) {
			return $term_id;
		};

		add_filter( 'pre_option_default_category', $default_category );

		$this->assertFalse(
			current_user_can( 'delete_term', $term_id ),
			'Once the filter makes the term the default category, deleting it should be refused.'
		);

		remove_filter( 'pre_option_default_category', $default_category );

		$this->assertTrue(
			current_user_can( 'delete_term', $term_id ),
			'Removing the filter should restore the answer, rather than leaving the refusal behind.'
		);
	}

	/**
	 * Every mapping that reads state no watch list can describe is declined by name.
	 *
	 * The watch list can only name hooks that are known in advance. A mapping that reads
	 * the capabilities the user themselves holds, the answer to
	 * `wp_is_file_mod_allowed()`, or a hook whose name contains a taxonomy that is only
	 * registered at runtime, is outside what any such list can cover - so those mappings
	 * are refused the memo outright.
	 *
	 * The control half of the test is as important as the list half. A decline list that
	 * quietly grew until nothing was memoizable would satisfy every assertion about
	 * safety while delivering none of the value, so a representative mapping from each
	 * memoizable family is asserted to still qualify.
	 */
	public function test_every_mapping_that_reads_unwatchable_state_is_declined() {
		$this->assertSame(
			self::UNMEMOIZABLE_CAPS,
			array_values( array_unique( self::UNMEMOIZABLE_CAPS ) ),
			'The decline list should name each capability exactly once.'
		);

		foreach ( self::UNMEMOIZABLE_CAPS as $cap ) {
			$this->assertFalse(
				_wp_map_meta_cap_is_memoizable_cap( $cap ),
				sprintf( 'The %s mapping reads state no watch list can describe, so it should be declined.', $cap )
			);
			$this->assertSame(
				'',
				_wp_map_meta_cap_memo_key( $cap, self::$administrator_id, array( self::$post_id ) ),
				sprintf( 'A %s call should never produce a memo key, whatever it is asked about.', $cap )
			);
		}

		$memoizable = array(
			'edit_post',
			'delete_post',
			'read_post',
			'publish_post',
			'edit_comment',
			'promote_user',
			'remove_users',
			'edit_posts',
			'customize',
			'manage_privacy_options',
			'edit_block_binding',
			'resume_plugin',
		);

		foreach ( $memoizable as $cap ) {
			$this->assertTrue(
				_wp_map_meta_cap_is_memoizable_cap( $cap ),
				sprintf( 'The %s mapping reads only watched state, so it should stay memoizable.', $cap )
			);
			$this->assertNotSame(
				'',
				_wp_map_meta_cap_memo_key( $cap, self::$administrator_id, array( self::$post_id ) ),
				sprintf( 'A %s call with an object to check against should be memoizable.', $cap )
			);
		}
	}

	/**
	 * A callback registered part way through a mapping leaves nothing memoized.
	 *
	 * The key is built before the mapping runs and the answer is stored after it
	 * finishes, so a callback that appears in between would otherwise have its policy
	 * ignored on the way in and preserved on the way out. The store is therefore guarded
	 * a second time, against the state as it stands once the mapping is complete.
	 *
	 * Here the mapping for `read_post` reads the post's status, a callback on that read
	 * registers a second watched filter while the mapping is still running, and neither
	 * key ends up carrying an answer. The `$registrations` count is asserted so that a
	 * mapping which stopped reading the status - and so never reached the callback -
	 * could not pass this test by doing nothing.
	 */
	public function test_a_callback_registered_during_a_mapping_leaves_nothing_memoized() {
		$private_post_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'private',
				'post_author' => self::$author_id,
			)
		);

		$front_page     = '__return_zero';
		$registrations  = 0;
		$register_while = static function ( $post_status ) use ( $front_page, &$registrations ) {
			if ( ! has_filter( 'option_page_on_front', $front_page ) ) {
				add_filter( 'option_page_on_front', $front_page );
				++$registrations;
			}

			return $post_status;
		};

		$read_key   = $this->assert_memoizable( 'read_post', self::$subscriber_id, array( $private_post_id ) );
		$delete_key = $this->assert_memoizable( 'delete_post', self::$subscriber_id, array( $private_post_id ) );

		_wp_reset_map_meta_cap_memo();
		add_filter( 'get_post_status', $register_while );

		try {
			$caps = map_meta_cap( 'read_post', self::$subscriber_id, $private_post_id );
		} finally {
			remove_filter( 'get_post_status', $register_while );
			remove_filter( 'option_page_on_front', $front_page );
		}

		$this->assertSame( 1, $registrations, 'The mapping should have read the post status and reached the callback.' );
		$this->assertNotEmpty( $caps, 'The mapping should still answer while callbacks are being registered around it.' );
		$this->assertNull(
			_wp_map_meta_cap_memo( $read_key ),
			'An answer computed while a watched filter was appearing should not have been stored.'
		);
		$this->assertNull(
			_wp_map_meta_cap_memo( $delete_key ),
			'No other mapping should have been stored while a watched filter was appearing either.'
		);

		$this->assertSame(
			$read_key,
			_wp_map_meta_cap_memo_key( 'read_post', self::$subscriber_id, array( $private_post_id ) ),
			'Once both callbacks are gone the mapping should be memoizable again.'
		);
		$this->assertSame(
			$caps,
			map_meta_cap( 'read_post', self::$subscriber_id, $private_post_id ),
			'The recomputed answer should match the one the mapping gave while it was unmemoizable.'
		);
	}

	/**
	 * A policy input the watch list cannot name leaves no stale answer behind.
	 *
	 * `file_mod_allowed` and the network option filters are read by mappings whose
	 * answers also depend on the capabilities the user themselves holds, so a watch list
	 * cannot cover them: `user_has_cap` carries three core callbacks on every request and
	 * so says nothing about whether a third party is involved. Those mappings are
	 * therefore refused the memo by capability name instead, which is the stronger of the
	 * two guarantees - there is no window in which one of them is memoized at all.
	 *
	 * Both halves are asserted for each. The mapping is declined even when it is handed
	 * an object to check against, which is the only way it could otherwise reach the
	 * memo, and the answer is recomputed as the callback appears and again as it goes
	 * away. The second half holds on any installation: where the filtered input is only
	 * consulted on Multisite, the answer is unchanged rather than stale, and a memo that
	 * had kept it would have been caught by the same comparison.
	 */
	public function test_a_policy_input_the_watch_list_cannot_name_leaves_no_stale_answer() {
		$scenarios = array(
			'file modification permission'     => array( 'update_plugins', 'file_mod_allowed', '__return_false' ),
			'the network menu_items option'    => array( 'activate_plugin', 'pre_site_option_menu_items', '__return_empty_array' ),
			'the network add_new_users option' => array( 'create_users', 'pre_site_option_add_new_users', '__return_zero' ),
			'the capabilities the user holds'  => array( 'delete_user', 'user_has_cap', '__return_empty_array' ),
		);

		foreach ( $scenarios as $label => $scenario ) {
			list( $cap, $hook, $callback ) = $scenario;

			$this->assertFalse(
				_wp_map_meta_cap_is_memoizable_cap( $cap ),
				sprintf( 'The %s mapping reads %s, which no watch list can name, so it should be declined.', $cap, $label )
			);
			$this->assertSame(
				'',
				_wp_map_meta_cap_memo_key( $cap, self::$administrator_id, array( self::$editor_id ) ),
				sprintf( 'A %s call should not be memoized even when it is handed an object to check against.', $cap )
			);

			// Seed whatever the memo is willing to keep for this mapping, which should be nothing.
			$this->cold( $cap, self::$administrator_id, self::$editor_id );

			add_filter( $hook, $callback );

			try {
				$this->assert_warm_matches_cold(
					$cap,
					self::$administrator_id,
					array( self::$editor_id ),
					sprintf(
						'A %s check should obey the callback on %s, not answer from before it was registered.',
						$cap,
						$hook
					)
				);
			} finally {
				remove_filter( $hook, $callback );
			}

			$this->assert_warm_matches_cold(
				$cap,
				self::$administrator_id,
				array( self::$editor_id ),
				sprintf(
					'A %s check should be recomputed once the callback on %s is gone, not left holding the filtered answer.',
					$cap,
					$hook
				)
			);

			_wp_reset_map_meta_cap_memo();
		}
	}
}
