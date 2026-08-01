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
		'added_option',
		'updated_option',
		'deleted_option',
		'add_site_option',
		'update_site_option',
		'delete_site_option',
		'switch_blog',
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

		$this->filter_calls = 0;
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
			'null'    => _wp_map_meta_cap_memo_key( 'edit_post', 1, array( null ) ),
			'empty'   => _wp_map_meta_cap_memo_key( 'edit_post', 1, array( '' ) ),
		);

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
	 * `register_post_status()` fires no action at all, and code that mutates one of
	 * these registries by unsetting a key directly fires nothing either, so the sizes
	 * are carried in the key rather than relied upon to invalidate.
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
}
