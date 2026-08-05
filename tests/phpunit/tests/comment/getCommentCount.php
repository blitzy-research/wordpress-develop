<?php

/**
 * @group comment
 *
 * @covers ::get_comment_count
 */
class Tests_Comment_GetCommentCount extends WP_UnitTestCase {

	public function test_get_comment_count() {
		$count = get_comment_count();

		$this->assertSame( 0, $count['approved'] );
		$this->assertSame( 0, $count['awaiting_moderation'] );
		$this->assertSame( 0, $count['spam'] );
		$this->assertSame( 0, $count['trash'] );
		$this->assertSame( 0, $count['post-trashed'] );
		$this->assertSame( 0, $count['total_comments'] );
		$this->assertSame( 0, $count['all'] );
	}

	public function test_get_comment_count_approved() {
		self::factory()->comment->create(
			array(
				'comment_approved' => 1,
			)
		);

		$count = get_comment_count();

		$this->assertSame( 1, $count['approved'] );
		$this->assertSame( 0, $count['awaiting_moderation'] );
		$this->assertSame( 0, $count['spam'] );
		$this->assertSame( 0, $count['trash'] );
		$this->assertSame( 0, $count['post-trashed'] );
		$this->assertSame( 1, $count['total_comments'] );
	}

	public function test_get_comment_count_awaiting() {
		self::factory()->comment->create(
			array(
				'comment_approved' => 0,
			)
		);

		$count = get_comment_count();

		$this->assertSame( 0, $count['approved'] );
		$this->assertSame( 1, $count['awaiting_moderation'] );
		$this->assertSame( 0, $count['spam'] );
		$this->assertSame( 0, $count['trash'] );
		$this->assertSame( 0, $count['post-trashed'] );
		$this->assertSame( 1, $count['total_comments'] );
	}

	public function test_get_comment_count_spam() {
		self::factory()->comment->create(
			array(
				'comment_approved' => 'spam',
			)
		);

		$count = get_comment_count();

		$this->assertSame( 0, $count['approved'] );
		$this->assertSame( 0, $count['awaiting_moderation'] );
		$this->assertSame( 1, $count['spam'] );
		$this->assertSame( 0, $count['trash'] );
		$this->assertSame( 0, $count['post-trashed'] );
		$this->assertSame( 1, $count['total_comments'] );
	}

	public function test_get_comment_count_trash() {
		self::factory()->comment->create(
			array(
				'comment_approved' => 'trash',
			)
		);

		$count = get_comment_count();

		$this->assertSame( 0, $count['approved'] );
		$this->assertSame( 0, $count['awaiting_moderation'] );
		$this->assertSame( 0, $count['spam'] );
		$this->assertSame( 1, $count['trash'] );
		$this->assertSame( 0, $count['post-trashed'] );
		$this->assertSame( 0, $count['total_comments'] );
	}

	public function test_get_comment_count_post_trashed() {
		self::factory()->comment->create(
			array(
				'comment_approved' => 'post-trashed',
			)
		);

		$count = get_comment_count();

		$this->assertSame( 0, $count['approved'] );
		$this->assertSame( 0, $count['awaiting_moderation'] );
		$this->assertSame( 0, $count['spam'] );
		$this->assertSame( 0, $count['trash'] );
		$this->assertSame( 1, $count['post-trashed'] );
		$this->assertSame( 0, $count['total_comments'] );
	}

	/**
	 * @ticket 19901
	 *
	 * @covers ::get_comment_count
	 */
	public function test_get_comment_count_validate_cache_comment_deleted() {

		$comment_id = self::factory()->comment->create();

		$count = get_comment_count();

		$this->assertSame( 1, $count['total_comments'] );

		wp_delete_comment( $comment_id, true );

		$count = get_comment_count();

		$this->assertSame( 0, $count['total_comments'] );
	}

	/**
	 * @ticket 19901
	 *
	 * @covers ::get_comment_count
	 */
	public function test_get_comment_count_validate_cache_post_deleted() {

		$post_id = self::factory()->post->create();

		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID' => $post_id,
			)
		);

		$count = get_comment_count( $post_id );

		$this->assertSame( 1, $count['total_comments'] );

		wp_delete_post( $post_id, true );

		$count = get_comment_count( $post_id );

		$this->assertSame( 0, $count['total_comments'] );
	}

	/**
	 * @ticket 19901
	 *
	 * @covers ::get_comment_count
	 */
	public function test_get_comment_count_validate_cache_comment_status() {
		$comment_id = self::factory()->comment->create();

		$count = get_comment_count();

		$this->assertSame( 1, $count['approved'] );
		$this->assertSame( 0, $count['trash'] );
		$this->assertSame( 1, $count['total_comments'] );

		wp_set_comment_status( $comment_id, 'trash' );

		$count = get_comment_count();

		$this->assertSame( 0, $count['approved'] );
		$this->assertSame( 1, $count['trash'] );
		$this->assertSame( 0, $count['total_comments'] );
	}

	/**
	 * The counts for all five statuses are read from the same rows, so they are
	 * retrieved by grouping on comment_approved rather than by counting each status.
	 *
	 * @covers ::get_comment_count
	 */
	public function test_get_comment_count_is_retrieved_with_a_single_query() {
		self::factory()->comment->create( array( 'comment_approved' => '1' ) );

		$num_queries = get_num_queries();
		$count       = get_comment_count();

		$this->assertSame( 1, $count['approved'], 'The approved comment should be counted.' );
		$this->assertSame(
			$num_queries + 1,
			get_num_queries(),
			'The counts for every status should be retrieved with one query.'
		);
	}

	/**
	 * @covers ::get_comment_count
	 */
	public function test_get_comment_count_is_served_from_the_cache_on_a_repeat_call() {
		self::factory()->comment->create( array( 'comment_approved' => '1' ) );

		$first = get_comment_count();

		$num_queries = get_num_queries();
		$second      = get_comment_count();

		$this->assertSame( $first, $second, 'The cached counts should match the queried counts.' );
		$this->assertSame(
			$num_queries,
			get_num_queries(),
			'A repeat call should not query the database.'
		);
	}

	/**
	 * The hooks that shape a comment query are the documented way to change which
	 * comments are counted, so a query per status is still run whenever one is in use.
	 *
	 * @covers ::get_comment_count
	 *
	 * @dataProvider data_comment_query_hooks
	 *
	 * @param string $hook Name of the comment query hook to register a callback on.
	 */
	public function test_get_comment_count_runs_a_query_per_status_when_a_comment_query_hook_is_in_use( $hook ) {
		self::factory()->comment->create( array( 'comment_approved' => '1' ) );
		self::factory()->comment->create( array( 'comment_approved' => 'spam' ) );

		$expected = get_comment_count();

		add_filter( $hook, array( $this, 'return_first_argument_unchanged' ) );

		$num_queries = get_num_queries();
		$actual      = get_comment_count();
		$queries     = get_num_queries() - $num_queries;

		remove_filter( $hook, array( $this, 'return_first_argument_unchanged' ) );

		$this->assertSame( $expected, $actual, 'The filtered path should return the same counts.' );
		$this->assertSame( 5, $queries, 'The filtered path should run one query per status.' );
	}

	/**
	 * Data provider.
	 *
	 * @return array[]
	 */
	public function data_comment_query_hooks() {
		return array(
			'parse_comment_query' => array( 'parse_comment_query' ),
			'pre_get_comments'    => array( 'pre_get_comments' ),
			'comments_pre_query'  => array( 'comments_pre_query' ),
			'comments_clauses'    => array( 'comments_clauses' ),
		);
	}

	/**
	 * Returns a hook's first argument unchanged.
	 *
	 * @param mixed $value Value passed to the hook.
	 * @return mixed The value, unchanged.
	 */
	public function return_first_argument_unchanged( $value = null ) {
		return $value;
	}

	/**
	 * @covers ::get_comment_count
	 */
	public function test_get_comment_count_excludes_notes() {
		self::factory()->comment->create( array( 'comment_approved' => '1' ) );
		self::factory()->comment->create(
			array(
				'comment_approved' => '1',
				'comment_type'     => 'note',
			)
		);

		$count = get_comment_count();

		$this->assertSame( 1, $count['approved'], 'A note should not be counted as an approved comment.' );
		$this->assertSame( 1, $count['total_comments'], 'A note should not be counted in the total.' );
	}
}
