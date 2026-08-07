<?php

/**
 * Tests for the request-scoped capability mapping memo inside map_meta_cap().
 *
 * The memo covers exactly one population: capabilities that no `case` in
 * map_meta_cap() claims, which fall through to the default branch and map to
 * themselves or to their post equivalent. Everything here is written so that
 * removing any single condition of that memo fails at least one test, because the
 * risk a memo carries is not that it is slow but that it answers a question whose
 * answer has changed.
 *
 * @group user
 * @group capabilities
 * @covers ::map_meta_cap
 */
class Tests_User_MapMetaCapMemo extends WP_UnitTestCase {

	/**
	 * An administrator, used for the checks that need a user with capabilities.
	 *
	 * @var int
	 */
	protected static $admin_id;

	/**
	 * An editor, used for the checks that must not be answered from another user's memo.
	 *
	 * @var int
	 */
	protected static $editor_id;

	/**
	 * A subscriber, used for the privilege boundary checks.
	 *
	 * @var int
	 */
	protected static $subscriber_id;

	/**
	 * The post type registered by the tests that need a mapped meta capability.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mmcm_item';

	/**
	 * Creates the users the tests share.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$admin_id      = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$editor_id     = $factory->user->create( array( 'role' => 'editor' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Starts every test with an empty memo so that key assertions describe this test only.
	 */
	public function set_up() {
		parent::set_up();

		unset( $GLOBALS['_wp_map_meta_cap_memo'] );
	}

	/**
	 * Leaves no memo behind, so that a later test in the same process starts clean.
	 */
	public function tear_down() {
		unset( $GLOBALS['_wp_map_meta_cap_memo'] );

		parent::tear_down();
	}

	/**
	 * Returns the memo as an array, whether or not anything has been memoized.
	 *
	 * @return array Memo contents.
	 */
	private function memo() {
		return isset( $GLOBALS['_wp_map_meta_cap_memo'] ) ? $GLOBALS['_wp_map_meta_cap_memo'] : array();
	}

	/**
	 * Returns its first argument unchanged.
	 *
	 * Attaching this to `map_meta_cap` suppresses the memo without changing any
	 * mapping, which is how the tests obtain an unmemoized result to compare against.
	 *
	 * @param string[] $caps Primitive capabilities.
	 * @return string[] The same capabilities.
	 */
	public function return_caps_unchanged( $caps ) {
		return $caps;
	}

	/**
	 * Tests that a capability the switch does not claim is memoized for its user.
	 */
	public function test_a_fall_through_capability_is_memoized_for_its_user() {
		$this->assertSame( array(), $this->memo(), 'The memo must start empty.' );

		$caps = map_meta_cap( 'edit_posts', self::$admin_id );

		$this->assertSame( array( 'edit_posts' ), $caps, 'A primitive capability must map to itself.' );

		$this->assertSame(
			array( self::$admin_id => array( 'edit_posts' => array( 'edit_posts' ) ) ),
			$this->memo(),
			'The mapping must be memoized under the user ID and the capability name.'
		);
	}

	/**
	 * Tests that a memoized mapping is what the function returns.
	 *
	 * The memo is seeded with a value the switch would never produce, so this fails
	 * unless the read really is served from the memo.
	 */
	public function test_a_memoized_mapping_is_returned_without_being_recomputed() {
		$GLOBALS['_wp_map_meta_cap_memo'] = array(
			self::$admin_id => array( 'edit_posts' => array( 'memoized_sentinel' ) ),
		);

		$this->assertSame(
			array( 'memoized_sentinel' ),
			map_meta_cap( 'edit_posts', self::$admin_id ),
			'A memoized mapping must be returned from the memo.'
		);
	}

	/**
	 * Tests that block capabilities are memoized under the name they were checked with.
	 *
	 * The default branch rewrites the capability before recording it, so the memo has
	 * to be keyed on the name the caller asked about rather than the rewritten one.
	 */
	public function test_a_block_capability_is_memoized_under_the_capability_that_was_checked() {
		$this->assertSame(
			array( 'edit_posts' ),
			map_meta_cap( 'edit_blocks', self::$admin_id ),
			'A block capability must map to its post equivalent.'
		);

		$this->assertSame(
			array( self::$admin_id => array( 'edit_blocks' => array( 'edit_posts' ) ) ),
			$this->memo(),
			'A block capability must be memoized under the capability that was checked.'
		);

		$this->assertSame(
			array( 'edit_posts' ),
			map_meta_cap( 'edit_blocks', self::$admin_id ),
			'A memoized block capability must still map to its post equivalent.'
		);

		$this->assertSame(
			array( 'edit_posts' ),
			map_meta_cap( 'edit_posts', self::$admin_id ),
			'The block capability memo must not answer for the capability it maps to.'
		);
	}

	/**
	 * Tests that each user is memoized separately.
	 */
	public function test_each_user_is_memoized_separately() {
		$GLOBALS['_wp_map_meta_cap_memo'] = array(
			self::$admin_id => array( 'edit_posts' => array( 'memoized_sentinel' ) ),
		);

		$this->assertSame(
			array( 'memoized_sentinel' ),
			map_meta_cap( 'edit_posts', self::$admin_id ),
			'The memo must answer for the user it was recorded for.'
		);

		$this->assertSame(
			array( 'edit_posts' ),
			map_meta_cap( 'edit_posts', self::$editor_id ),
			'One user\'s memo must never answer for another user.'
		);

		$this->assertSame(
			array( 'edit_posts' ),
			$this->memo()[ self::$editor_id ]['edit_posts'],
			'The second user must get a memo entry of their own.'
		);
	}

	/**
	 * Tests that a check carrying arguments is never memoized.
	 *
	 * Mappings that read the object being checked against cannot be reused, so no
	 * check that carries an argument may reach the memo, whether or not the switch
	 * claims the capability.
	 */
	public function test_a_check_carrying_arguments_is_not_memoized() {
		$post_id = self::factory()->post->create( array( 'post_author' => self::$editor_id ) );

		map_meta_cap( 'edit_post', self::$admin_id, $post_id );
		map_meta_cap( 'read_post', self::$admin_id, $post_id );
		map_meta_cap( 'edit_posts', self::$admin_id, $post_id );
		map_meta_cap( 'edit_post_meta', self::$admin_id, $post_id, 'a_meta_key' );

		$this->assertSame( array(), $this->memo(), 'A check carrying arguments must not be memoized.' );
	}

	/**
	 * Tests that a memoized capability is still recomputed once it carries an argument.
	 */
	public function test_an_argument_defeats_an_existing_memo_entry() {
		$GLOBALS['_wp_map_meta_cap_memo'] = array(
			self::$admin_id => array( 'edit_posts' => array( 'memoized_sentinel' ) ),
		);

		$this->assertSame(
			array( 'edit_posts' ),
			map_meta_cap( 'edit_posts', self::$admin_id, 12345 ),
			'A check carrying an argument must not be answered from the memo.'
		);
	}

	/**
	 * Tests that capabilities the switch claims are never memoized.
	 *
	 * Those branches read constants, options, filters and super admin status, any of
	 * which a request can change, so a memo entry for one of them would be a mapping
	 * whose inputs are no longer being consulted.
	 *
	 * @dataProvider data_explicitly_mapped_capabilities
	 *
	 * @param string $cap Capability the switch maps explicitly.
	 */
	public function test_an_explicitly_mapped_capability_is_not_memoized( $cap ) {
		$mapped = map_meta_cap( $cap, self::$admin_id );

		$this->assertNotEmpty( $mapped, "Mapping {$cap} must return at least one primitive capability." );
		$this->assertSame( array(), $this->memo(), "The explicitly mapped {$cap} must not be memoized." );
	}

	/**
	 * Data provider of capabilities the switch in map_meta_cap() maps explicitly and
	 * that are safe to map without arguments.
	 *
	 * @return array[] Capability names.
	 */
	public function data_explicitly_mapped_capabilities() {
		$capabilities = array(
			'promote_user',
			'add_users',
			'edit_users',
			'unfiltered_upload',
			'edit_css',
			'unfiltered_html',
			'edit_files',
			'edit_plugins',
			'edit_themes',
			'update_plugins',
			'delete_plugins',
			'install_plugins',
			'upload_plugins',
			'update_themes',
			'install_themes',
			'upload_themes',
			'update_core',
			'install_languages',
			'update_languages',
			'activate_plugins',
			'deactivate_plugins',
			'resume_plugin',
			'resume_theme',
			'delete_user',
			'delete_users',
			'create_users',
			'manage_links',
			'customize',
			'delete_site',
			'manage_post_tags',
			'edit_categories',
			'edit_post_tags',
			'delete_categories',
			'assign_categories',
			'create_sites',
			'delete_sites',
			'manage_network',
			'manage_sites',
			'manage_network_users',
			'manage_network_plugins',
			'manage_network_themes',
			'manage_network_options',
			'upgrade_network',
			'setup_network',
			'update_php',
			'update_https',
			'export_others_personal_data',
			'erase_others_personal_data',
			'manage_privacy_options',
		);

		$data = array();
		foreach ( $capabilities as $cap ) {
			$data[ $cap ] = array( $cap );
		}

		return $data;
	}

	/**
	 * Tests that a post type meta capability is not served from the memo.
	 *
	 * A capability recorded before a post type claimed it would otherwise map to
	 * itself for the rest of the request, instead of being routed into the post type's
	 * own mapping. The memo is not invalidated when a post type is registered: the
	 * default branch tests the post type meta capabilities on every call and returns
	 * before reaching the memo, which is what makes the late registration safe.
	 */
	public function test_a_post_type_registered_after_a_mapping_was_memoized_is_honored_at_once() {
		$this->setExpectedIncorrectUsage( 'map_meta_cap' );

		$cap = 'read_' . self::POST_TYPE;

		$this->assertSame(
			array( $cap ),
			map_meta_cap( $cap, self::$admin_id ),
			'Before the post type is registered the capability must map to itself.'
		);

		$this->assertArrayHasKey(
			$cap,
			$this->memo()[ self::$admin_id ],
			'Before the post type is registered the mapping must be memoized.'
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'capability_type' => self::POST_TYPE,
				'map_meta_cap'    => true,
			)
		);

		$this->assertArrayHasKey(
			$cap,
			$GLOBALS['post_type_meta_caps'],
			'Registering the post type must claim the capability as a meta capability.'
		);

		$this->assertSame(
			array( 'do_not_allow' ),
			map_meta_cap( $cap, self::$admin_id ),
			'Once the post type claims the capability the memo must no longer answer for it.'
		);
	}

	/**
	 * Tests that a post type meta capability checked against an object is not memoized.
	 */
	public function test_a_post_type_meta_capability_is_not_memoized() {
		register_post_type(
			self::POST_TYPE,
			array(
				'capability_type' => self::POST_TYPE,
				'map_meta_cap'    => true,
			)
		);

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => self::POST_TYPE,
				'post_author' => self::$editor_id,
			)
		);

		map_meta_cap( 'edit_' . self::POST_TYPE, self::$admin_id, $post_id );
		map_meta_cap( 'read_' . self::POST_TYPE, self::$admin_id, $post_id );

		$this->assertSame( array(), $this->memo(), 'A post type meta capability must not be memoized.' );
	}

	/**
	 * Tests that nothing is memoized while a callback is attached to `map_meta_cap`.
	 */
	public function test_nothing_is_memoized_while_a_map_meta_cap_filter_is_attached() {
		add_filter( 'map_meta_cap', array( $this, 'return_caps_unchanged' ) );

		$caps = map_meta_cap( 'edit_posts', self::$admin_id );

		remove_filter( 'map_meta_cap', array( $this, 'return_caps_unchanged' ) );

		$this->assertSame( array( 'edit_posts' ), $caps, 'A filter that changes nothing must change nothing.' );
		$this->assertSame( array(), $this->memo(), 'Nothing may be memoized while the filter has a callback.' );
	}

	/**
	 * Tests that a filter attached after a mapping was memoized still runs.
	 *
	 * This is the condition that makes the memo safe for a filter whose answer depends
	 * on something other than its arguments: the memo is bypassed entirely rather than
	 * being invalidated, so the filter cannot be short-circuited by an earlier check.
	 */
	public function test_a_filter_attached_after_a_mapping_was_memoized_still_runs() {
		$this->assertSame(
			array( 'edit_posts' ),
			map_meta_cap( 'edit_posts', self::$admin_id ),
			'The first check must map the capability to itself.'
		);

		$this->assertArrayHasKey(
			'edit_posts',
			$this->memo()[ self::$admin_id ],
			'The first check must be memoized.'
		);

		$filter = static function () {
			return array( 'filtered_capability' );
		};

		add_filter( 'map_meta_cap', $filter );

		$filtered = map_meta_cap( 'edit_posts', self::$admin_id );

		remove_filter( 'map_meta_cap', $filter );

		$this->assertSame(
			array( 'filtered_capability' ),
			$filtered,
			'A filter attached after a mapping was memoized must still run.'
		);

		$this->assertSame(
			array( 'edit_posts' ),
			map_meta_cap( 'edit_posts', self::$admin_id ),
			'Removing the filter must restore the memoized mapping unchanged.'
		);
	}

	/**
	 * Tests that a filtered mapping is never written to the memo.
	 *
	 * A filter can map identical arguments to different results, so its answer must not
	 * outlive the filter itself.
	 */
	public function test_a_filtered_mapping_is_not_memoized_for_later_checks() {
		$filter = static function () {
			return array( 'filtered_capability' );
		};

		add_filter( 'map_meta_cap', $filter );

		$this->assertSame(
			array( 'filtered_capability' ),
			map_meta_cap( 'edit_posts', self::$admin_id ),
			'The filter must map the capability.'
		);

		remove_filter( 'map_meta_cap', $filter );

		$this->assertSame(
			array( 'edit_posts' ),
			map_meta_cap( 'edit_posts', self::$admin_id ),
			'The filtered mapping must not be served after the filter is removed.'
		);
	}

	/**
	 * Tests that an `all` hook still sees every capability mapping.
	 *
	 * `apply_filters()` calls the `all` hook before it looks for its own callbacks, so a
	 * memo that skipped the call would hide mappings from anything listening on `all`.
	 */
	public function test_an_all_hook_sees_every_mapping_and_suppresses_the_memo() {
		$seen = 0;

		$listener = static function ( $value ) use ( &$seen ) {
			if ( 'map_meta_cap' === current_filter() ) {
				++$seen;
			}

			return $value;
		};

		add_filter( 'all', $listener );

		map_meta_cap( 'edit_posts', self::$admin_id );
		map_meta_cap( 'edit_posts', self::$admin_id );
		map_meta_cap( 'edit_posts', self::$admin_id );

		remove_filter( 'all', $listener );

		$this->assertSame( 3, $seen, 'Every mapping must reach a listener on the all hook.' );
		$this->assertSame( array(), $this->memo(), 'Nothing may be memoized while the all hook has a callback.' );
	}

	/**
	 * Tests that argument types the function has always tolerated stay tolerated.
	 *
	 * None of these can be an array key without an error, so each one has to bypass the
	 * memo rather than reach it.
	 *
	 * @dataProvider data_user_ids_that_are_not_memoizable
	 *
	 * @param mixed $user_id User ID of a type that cannot key the memo.
	 */
	public function test_a_user_id_that_cannot_key_the_memo_is_not_memoized( $user_id ) {
		$this->assertSame(
			array( 'edit_posts' ),
			map_meta_cap( 'edit_posts', $user_id ),
			'The mapping must still be returned.'
		);

		$this->assertSame( array(), $this->memo(), 'A user ID that cannot key the memo must not be memoized.' );
	}

	/**
	 * Data provider of user ID values that cannot key the memo.
	 *
	 * @return array[] User ID values.
	 */
	public function data_user_ids_that_are_not_memoizable() {
		return array(
			'a numeric string' => array( '1' ),
			'a float'          => array( 1.5 ),
			'null'             => array( null ),
			'false'            => array( false ),
			'an empty string'  => array( '' ),
			'an array'         => array( array( 1 ) ),
			'an object'        => array( new stdClass() ),
		);
	}

	/**
	 * Tests that a capability that cannot key the memo is not memoized.
	 */
	public function test_a_capability_that_cannot_key_the_memo_is_not_memoized() {
		$this->assertSame(
			array( 4321 ),
			map_meta_cap( 4321, self::$admin_id ),
			'An unknown capability must map to itself whatever its type.'
		);

		$this->assertSame( array(), $this->memo(), 'A capability that cannot key the memo must not be memoized.' );
	}

	/**
	 * Tests that the memo cannot grow without limit.
	 *
	 * A request is free to check capability names it generates, so the memo is emptied
	 * once one user holds enough of them, rather than being allowed to accumulate.
	 */
	public function test_the_memo_is_emptied_once_it_holds_too_many_mappings_for_one_user() {
		$bucket = array();
		for ( $i = 0; $i < 512; $i++ ) {
			$bucket[ 'generated_capability_' . $i ] = array( 'generated_capability_' . $i );
		}

		$GLOBALS['_wp_map_meta_cap_memo'] = array(
			self::$admin_id  => $bucket,
			self::$editor_id => array( 'edit_posts' => array( 'edit_posts' ) ),
		);

		$this->assertSame(
			array( 'one_more_capability' ),
			map_meta_cap( 'one_more_capability', self::$admin_id ),
			'The mapping must be returned even when the memo is full.'
		);

		$this->assertSame(
			array( self::$admin_id => array( 'one_more_capability' => array( 'one_more_capability' ) ) ),
			$this->memo(),
			'A full memo must be emptied and then hold only the mapping that filled it.'
		);
	}

	/**
	 * Tests that the memo does not change what map_meta_cap() maps a capability to.
	 *
	 * Each capability is mapped three times: once with a filter attached, which
	 * suppresses the memo, and twice without, which fills it and then reads it. All
	 * three have to agree.
	 *
	 * @dataProvider data_capabilities_to_compare
	 *
	 * @param string $cap Capability to map.
	 */
	public function test_a_memoized_mapping_matches_an_unmemoized_mapping( $cap ) {
		add_filter( 'map_meta_cap', array( $this, 'return_caps_unchanged' ) );
		$unmemoized = map_meta_cap( $cap, self::$editor_id );
		remove_filter( 'map_meta_cap', array( $this, 'return_caps_unchanged' ) );

		$this->assertSame( array(), $this->memo(), "Mapping {$cap} under a filter must not fill the memo." );

		$first  = map_meta_cap( $cap, self::$editor_id );
		$second = map_meta_cap( $cap, self::$editor_id );

		$this->assertSame( $unmemoized, $first, "The first mapping of {$cap} must match the unmemoized mapping." );
		$this->assertSame( $unmemoized, $second, "The second mapping of {$cap} must match the unmemoized mapping." );
	}

	/**
	 * Data provider covering both populations: capabilities the switch claims and
	 * capabilities that fall through to the default branch.
	 *
	 * @return array[] Capability names.
	 */
	public function data_capabilities_to_compare() {
		$capabilities = array_merge(
			array(
				'read',
				'edit_posts',
				'edit_others_posts',
				'publish_posts',
				'delete_posts',
				'edit_pages',
				'manage_options',
				'edit_theme_options',
				'switch_themes',
				'upload_files',
				'list_users',
				'moderate_comments',
				'manage_categories',
				'edit_blocks',
				'delete_blocks',
				'publish_blocks',
				'read_private_blocks',
				'delete_others_blocks',
				'a_capability_no_plugin_registered',
			),
			array_keys( $this->data_explicitly_mapped_capabilities() )
		);

		$data = array();
		foreach ( $capabilities as $cap ) {
			$data[ $cap ] = array( $cap );
		}

		return $data;
	}

	/**
	 * Tests that the memo does not carry a capability across a change of role.
	 *
	 * The memo records how a capability is mapped, never whether a user has it, so
	 * losing a role has to take effect at once. This is the boundary that matters most:
	 * a memo that answered this wrongly would grant a capability the user no longer has.
	 *
	 * The change is observed through a freshly read user and through a current user that
	 * is genuinely reset, rather than through the WP_User object already held in
	 * $current_user. That object is a snapshot of the capabilities it was built with, and
	 * wp_set_current_user() returns early when the ID it is given is already current, so
	 * neither reflects a role change here. Both behaviors are the same with the memo in
	 * place and with the memo suppressed by a filter, so neither belongs to the memo.
	 */
	public function test_losing_a_role_takes_effect_even_though_the_mapping_is_memoized() {
		wp_set_current_user( self::$editor_id );

		$this->assertTrue( current_user_can( 'edit_posts' ), 'An editor must be able to edit posts.' );
		$this->assertTrue( current_user_can( 'edit_posts' ), 'A repeated check must agree with the first.' );

		$this->assertArrayHasKey(
			'edit_posts',
			$this->memo()[ self::$editor_id ],
			'The check must have filled the memo.'
		);

		$user = new WP_User( self::$editor_id );
		$user->remove_role( 'editor' );
		$user->add_role( 'subscriber' );

		$this->assertFalse(
			user_can( new WP_User( self::$editor_id ), 'edit_posts' ),
			'A user who has lost the role must lose the capability, memoized mapping or not.'
		);

		wp_set_current_user( 0 );
		wp_set_current_user( self::$editor_id );

		$this->assertFalse(
			current_user_can( 'edit_posts' ),
			'A current user read again after losing the role must lose the capability too.'
		);

		$user->remove_role( 'subscriber' );
		$user->add_role( 'editor' );

		$this->assertTrue(
			user_can( new WP_User( self::$editor_id ), 'edit_posts' ),
			'Restoring the role must restore the capability.'
		);

		wp_set_current_user( 0 );
		wp_set_current_user( self::$editor_id );

		$this->assertTrue( current_user_can( 'edit_posts' ), 'Restoring the role must restore it for the current user.' );
	}

	/**
	 * Tests that two users with different roles are answered separately.
	 */
	public function test_users_with_different_roles_are_answered_separately() {
		$this->assertTrue( user_can( self::$admin_id, 'manage_options' ), 'An administrator must manage options.' );
		$this->assertFalse( user_can( self::$editor_id, 'manage_options' ), 'An editor must not manage options.' );
		$this->assertFalse( user_can( self::$subscriber_id, 'edit_posts' ), 'A subscriber must not edit posts.' );
		$this->assertTrue( user_can( self::$editor_id, 'edit_posts' ), 'An editor must edit posts.' );

		// The same questions again, now that every mapping above is memoized.
		$this->assertTrue( user_can( self::$admin_id, 'manage_options' ), 'The repeated administrator check must agree.' );
		$this->assertFalse( user_can( self::$editor_id, 'manage_options' ), 'The repeated editor check must agree.' );
		$this->assertFalse( user_can( self::$subscriber_id, 'edit_posts' ), 'The repeated subscriber check must agree.' );
		$this->assertTrue( user_can( self::$editor_id, 'edit_posts' ), 'The repeated editor check must agree.' );
	}

	/**
	 * Tests that a capability granted directly to a user survives the memo.
	 */
	public function test_a_capability_added_to_a_user_takes_effect_immediately() {
		$user = new WP_User( self::$subscriber_id );

		$this->assertFalse( user_can( self::$subscriber_id, 'a_granted_capability' ), 'The capability must start absent.' );

		$user->add_cap( 'a_granted_capability' );

		$this->assertTrue(
			user_can( new WP_User( self::$subscriber_id ), 'a_granted_capability' ),
			'A capability added to the user must take effect even though its mapping is memoized.'
		);

		$user->remove_cap( 'a_granted_capability' );
	}

	/**
	 * Tests that the REST API answers identically on a repeated request.
	 *
	 * A REST request exercises the capability checks of a real request end to end, so
	 * running the same one twice covers the memo against the request shape the API uses.
	 */
	public function test_a_repeated_rest_request_is_answered_identically() {
		wp_set_current_user( self::$editor_id );

		self::factory()->post->create( array( 'post_author' => self::$editor_id ) );

		$first  = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts' ) );
		$second = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts' ) );

		$this->assertSame( 200, $first->get_status(), 'The first REST request must succeed.' );
		$this->assertSame( $first->get_status(), $second->get_status(), 'Both REST requests must return the same status.' );
		$this->assertSame(
			wp_list_pluck( $first->get_data(), 'id' ),
			wp_list_pluck( $second->get_data(), 'id' ),
			'Both REST requests must return the same posts.'
		);
	}

	/**
	 * Tests that switching site does not make the memo answer wrongly.
	 *
	 * The memo has no site dimension, because the mappings it holds are decided by the
	 * capability name and by the post type meta capabilities, both of which are shared
	 * across the sites of a network within one request. Whether a user holds the mapped
	 * capability on the site being visited is answered by WP_User::has_cap(), which the
	 * memo does not touch, so that has to keep varying by site.
	 *
	 * @group ms-required
	 */
	public function test_switching_site_does_not_make_the_memo_answer_wrongly() {
		$blog_id = self::factory()->blog->create();

		$this->assertSame(
			array( 'edit_posts' ),
			map_meta_cap( 'edit_posts', self::$editor_id ),
			'The mapping must be resolved on the original site.'
		);

		$this->assertTrue( user_can( self::$editor_id, 'edit_posts' ), 'The editor must edit posts on their own site.' );

		switch_to_blog( $blog_id );

		$this->assertSame(
			array( 'edit_posts' ),
			map_meta_cap( 'edit_posts', self::$editor_id ),
			'The mapping must be the same on another site of the network.'
		);

		$this->assertFalse(
			user_can( new WP_User( self::$editor_id, '', $blog_id ), 'edit_posts' ),
			'A user with no role on the site being visited must not hold the capability there.'
		);

		restore_current_blog();

		$this->assertTrue(
			user_can( new WP_User( self::$editor_id ), 'edit_posts' ),
			'Restoring the site must restore the capability.'
		);
	}

	/**
	 * Tests that no file other than capabilities.php knows the memo exists.
	 *
	 * The memo is deliberately implemented without adding a function and without a
	 * reset that another file has to call. An earlier attempt at this optimization was
	 * reverted because it was reset from class-wp-roles.php, which a bootstrap can load
	 * without capabilities.php: `SHORTINIT`, or an early call to WP_Roles::add_role(),
	 * then died on an undefined function. Nothing may reintroduce that coupling.
	 */
	public function test_the_memo_is_confined_to_capabilities_php() {
		$referring = array();

		$directory = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( ABSPATH . WPINC ) );

		foreach ( $directory as $file ) {
			if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			$contents = file_get_contents( $file->getPathname() );

			if ( false !== strpos( $contents, '_wp_map_meta_cap_memo' ) ) {
				$referring[] = str_replace( ABSPATH, '', $file->getPathname() );
			}
		}

		$this->assertSame(
			array( WPINC . '/capabilities.php' ),
			$referring,
			'Only capabilities.php may refer to the capability mapping memo.'
		);

		$this->assertStringNotContainsString(
			'map_meta_cap',
			file_get_contents( ABSPATH . WPINC . '/class-wp-roles.php' ),
			'class-wp-roles.php must not depend on capability mapping, because a bootstrap can load it without capabilities.php.'
		);
	}

	/**
	 * Tests that a mapping which reports a misuse reports it on every call.
	 *
	 * Several arms of the mapping report a capability checked without the object it needs
	 * through `_doing_it_wrong()`, and such a report has to be visible on every call that
	 * earns it. Answering one of those from a memo would silence every call after the
	 * first, turning a permanent notice into a single one.
	 *
	 * A capability checked with no arguments at all is the strongest case to pin, because
	 * it is exactly the shape the memo admits: an integer user, a string capability and an
	 * empty `$args`. What keeps it out of the memo is position rather than a guard -- the
	 * arms that report sit above the `default:` arm the memo lives in, so no call that
	 * reports ever reaches it. This test is what keeps that true, because moving the memo
	 * earlier in the function would break it and nothing else would notice.
	 * `doing_it_wrong_run` fires on every call whether or not the notice is displayed, so
	 * counting it needs no dependency on WP_DEBUG.
	 *
	 * @ticket 63258
	 *
	 * @covers ::map_meta_cap
	 */
	public function test_a_mapping_that_reports_a_misuse_reports_it_every_time() {
		$user_id = self::$editor_id;

		$this->setExpectedIncorrectUsage( 'map_meta_cap' );

		$before = did_action( 'doing_it_wrong_run' );

		$this->assertContains(
			'do_not_allow',
			map_meta_cap( 'delete_post', $user_id ),
			'A capability checked without the post it needs should map to do_not_allow.'
		);

		$first = did_action( 'doing_it_wrong_run' ) - $before;

		map_meta_cap( 'delete_post', $user_id );
		$second = did_action( 'doing_it_wrong_run' ) - $before - $first;

		$this->assertGreaterThan(
			0,
			$first,
			'A capability checked without the object it needs should report the misuse.'
		);

		$this->assertSame(
			$first,
			$second,
			'Repeating the same misused check should report it again rather than answer it from the memo.'
		);
	}
}
