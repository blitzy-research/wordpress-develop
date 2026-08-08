<?php

/**
 * Covers the request-scoped memo on the post edit and delete arms of map_meta_cap().
 *
 * The memo answers a repeated check from a key built out of every input those arms read,
 * so the property that matters is not that it is fast but that it can never be the reason
 * two checks disagree. Each case here changes one input between two otherwise identical
 * checks and asserts that the answer moves with it, or exercises a case the memo has to
 * decline outright.
 *
 * @group user
 * @group capabilities
 *
 * @covers ::map_meta_cap
 */
class Tests_User_MapMetaCapMemoization extends WP_UnitTestCase {

	/**
	 * An author who owns the fixtures.
	 *
	 * @var int
	 */
	private static $author_id;

	/**
	 * A second author, used to move ownership.
	 *
	 * @var int
	 */
	private static $other_author_id;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$author_id       = $factory->user->create( array( 'role' => 'author' ) );
		self::$other_author_id = $factory->user->create( array( 'role' => 'author' ) );
	}

	/**
	 * Returns a published post owned by the first author.
	 *
	 * @return int Post ID.
	 */
	private function published_post() {
		return self::factory()->post->create(
			array(
				'post_author' => self::$author_id,
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * A repeated identical check answers identically.
	 */
	public function test_repeated_identical_checks_agree() {
		$post_id = $this->published_post();

		$first  = map_meta_cap( 'edit_post', self::$author_id, $post_id );
		$second = map_meta_cap( 'edit_post', self::$author_id, $post_id );

		$this->assertSame( $first, $second, 'Two identical checks must return the same primitive capabilities.' );
		$this->assertContains( 'edit_published_posts', $first, 'The author of a published post needs edit_published_posts.' );
	}

	/**
	 * Changing the post status between two checks changes the answer.
	 */
	public function test_status_change_is_not_served_from_the_memo() {
		$post_id = $this->published_post();

		$published = map_meta_cap( 'edit_post', self::$author_id, $post_id );
		$this->assertContains( 'edit_published_posts', $published );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);

		$draft = map_meta_cap( 'edit_post', self::$author_id, $post_id );

		$this->assertNotContains( 'edit_published_posts', $draft, 'A draft must not still require edit_published_posts.' );
		$this->assertContains( 'edit_posts', $draft );
	}

	/**
	 * Changing the post author between two checks changes the answer.
	 */
	public function test_author_change_is_not_served_from_the_memo() {
		$post_id = $this->published_post();

		$own = map_meta_cap( 'edit_post', self::$author_id, $post_id );
		$this->assertNotContains( 'edit_others_posts', $own, 'The author of a post does not need edit_others_posts.' );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_author' => self::$other_author_id,
			)
		);

		$others = map_meta_cap( 'edit_post', self::$author_id, $post_id );

		$this->assertContains( 'edit_others_posts', $others, "Editing someone else's post needs edit_others_posts." );
	}

	/**
	 * Two users checking the same post get their own answers.
	 */
	public function test_the_user_is_part_of_the_key() {
		$post_id = $this->published_post();

		$owner  = map_meta_cap( 'edit_post', self::$author_id, $post_id );
		$others = map_meta_cap( 'edit_post', self::$other_author_id, $post_id );

		$this->assertNotContains( 'edit_others_posts', $owner );
		$this->assertContains( 'edit_others_posts', $others );
	}

	/**
	 * Two capabilities on the same post get their own answers.
	 */
	public function test_the_capability_is_part_of_the_key() {
		$post_id = $this->published_post();

		$edit   = map_meta_cap( 'edit_post', self::$author_id, $post_id );
		$delete = map_meta_cap( 'delete_post', self::$author_id, $post_id );

		$this->assertContains( 'edit_published_posts', $edit );
		$this->assertContains( 'delete_published_posts', $delete );
	}

	/**
	 * A filter added between two identical checks is applied to the second one.
	 */
	public function test_a_filter_added_later_still_sees_the_check() {
		$post_id = $this->published_post();

		$before = map_meta_cap( 'edit_post', self::$author_id, $post_id );
		$this->assertNotContains( 'do_not_allow', $before );

		add_filter( 'map_meta_cap', array( $this, 'deny_everything' ), 10, 4 );
		$after = map_meta_cap( 'edit_post', self::$author_id, $post_id );
		remove_filter( 'map_meta_cap', array( $this, 'deny_everything' ), 10 );

		$this->assertSame( array( 'do_not_allow' ), $after, 'The filter must be applied even when the value came from the memo.' );
	}

	/**
	 * Returns a denial, whatever was asked.
	 *
	 * @return string[] A single do_not_allow capability.
	 */
	public function deny_everything() {
		return array( 'do_not_allow' );
	}

	/**
	 * A filter that answers differently on each call is honoured on each call.
	 */
	public function test_a_filter_is_not_memoized() {
		$post_id = $this->published_post();

		$calls = 0;
		$count = static function ( $caps ) use ( &$calls ) {
			++$calls;

			return array( 'counted_' . $calls );
		};

		add_filter( 'map_meta_cap', $count );
		$first  = map_meta_cap( 'edit_post', self::$author_id, $post_id );
		$second = map_meta_cap( 'edit_post', self::$author_id, $post_id );
		remove_filter( 'map_meta_cap', $count );

		$this->assertSame( array( 'counted_1' ), $first );
		$this->assertSame( array( 'counted_2' ), $second, 'A filter must decide every call, memo or not.' );
		$this->assertSame( 2, $calls, 'The filter must run once per call.' );
	}

	/**
	 * The filter receives the capability the arms resolved, not only the one asked for.
	 *
	 * A post type that maps no meta capabilities has the checked capability replaced
	 * before the filter runs, and that has to hold on a repeated check too.
	 */
	public function test_the_resolved_capability_reaches_the_filter_on_a_repeated_check() {
		register_post_type(
			'blitzy_unmapped',
			array(
				'capability_type' => 'blitzy_thing',
				'map_meta_cap'    => false,
			)
		);

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'blitzy_unmapped',
				'post_author' => self::$author_id,
				'post_status' => 'publish',
			)
		);

		$seen = array();
		$spy  = static function ( $caps, $cap ) use ( &$seen ) {
			$seen[] = $cap;

			return $caps;
		};

		add_filter( 'map_meta_cap', $spy, 10, 2 );
		map_meta_cap( 'edit_post', self::$author_id, $post_id );
		map_meta_cap( 'edit_post', self::$author_id, $post_id );
		remove_filter( 'map_meta_cap', $spy, 10 );

		unregister_post_type( 'blitzy_unmapped' );

		$this->assertSame(
			array( 'edit_blitzy_thing', 'edit_blitzy_thing' ),
			$seen,
			'Both calls must report the capability the arm resolved to.'
		);
	}

	/**
	 * A trashed post is answered from its trash meta on every check.
	 */
	public function test_a_trashed_post_is_answered_every_time() {
		$post_id = $this->published_post();

		wp_trash_post( $post_id );

		$first  = map_meta_cap( 'delete_post', self::$author_id, $post_id );
		$second = map_meta_cap( 'delete_post', self::$author_id, $post_id );

		$this->assertContains( 'delete_published_posts', $first, 'A post trashed from published still needs delete_published_posts.' );
		$this->assertSame( $first, $second );

		// The answer follows the meta value rather than a remembered one.
		update_post_meta( $post_id, '_wp_trash_meta_status', 'draft' );

		$third = map_meta_cap( 'delete_post', self::$author_id, $post_id );

		$this->assertNotContains( 'delete_published_posts', $third, 'A post trashed from draft needs only delete_posts.' );
	}

	/**
	 * A revision is answered from its parent on every check.
	 */
	public function test_a_revision_is_answered_from_its_parent() {
		$post_id = $this->published_post();

		$revision_id = wp_save_post_revision( $post_id );

		if ( ! $revision_id ) {
			// Revisions can be disabled by configuration; the parent path is still asserted.
			$this->assertContains( 'edit_published_posts', map_meta_cap( 'edit_post', self::$author_id, $post_id ) );
			return;
		}

		$first  = map_meta_cap( 'edit_post', self::$author_id, $revision_id );
		$second = map_meta_cap( 'edit_post', self::$author_id, $revision_id );

		$this->assertSame( $first, $second );
		$this->assertContains( 'edit_published_posts', $first, "A revision is answered with its parent's requirement." );
	}

	/**
	 * The privacy policy page keeps merging in the privacy capability on every check.
	 */
	public function test_the_privacy_policy_page_is_answered_every_time() {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_author' => self::$author_id,
				'post_status' => 'publish',
			)
		);

		update_option( 'wp_page_for_privacy_policy', $page_id );

		$first  = map_meta_cap( 'delete_page', self::$author_id, $page_id );
		$second = map_meta_cap( 'delete_page', self::$author_id, $page_id );

		delete_option( 'wp_page_for_privacy_policy' );

		$expected = is_multisite() ? 'manage_network' : 'manage_options';

		$this->assertContains( $expected, $first, 'Deleting the privacy policy page requires the privacy capability.' );
		$this->assertSame( $first, $second );

		$after = map_meta_cap( 'delete_page', self::$author_id, $page_id );

		$this->assertNotContains( $expected, $after, 'Once the page is no longer the privacy policy page the extra capability goes away.' );
	}

	/**
	 * A post that does not resolve is refused every time, and says so every time.
	 */
	public function test_a_missing_post_is_refused_every_time() {
		$post_id = $this->published_post();
		wp_delete_post( $post_id, true );

		$this->assertSame( array( 'do_not_allow' ), map_meta_cap( 'edit_post', self::$author_id, $post_id ) );
		$this->assertSame( array( 'do_not_allow' ), map_meta_cap( 'edit_post', self::$author_id, $post_id ) );
	}

	/**
	 * A capability outside the memoized arms is unaffected.
	 */
	public function test_a_capability_outside_the_memoized_arms_is_unaffected() {
		$post_id = $this->published_post();

		$this->assertSame(
			map_meta_cap( 'read_post', self::$author_id, $post_id ),
			map_meta_cap( 'read_post', self::$author_id, $post_id ),
			'read_post is computed every time and must still be stable.'
		);
		$this->assertSame(
			array( 'edit_posts' ),
			map_meta_cap( 'edit_posts', self::$author_id ),
			'A capability with no object is passed through unchanged.'
		);
	}
}
