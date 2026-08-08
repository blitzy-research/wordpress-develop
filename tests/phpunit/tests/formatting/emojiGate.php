<?php

/**
 * Covers the gate in front of the inline emoji detection script.
 *
 * The existing emoji coverage in tests/phpunit/tests/formatting/emoji.php calls the
 * private worker `_print_emoji_detection_script()` directly, so it passes whether the
 * gate is there or not. These cases exercise the two symbols the gate added instead: the
 * predicate `wp_should_load_emoji_detection_script()` and the public hooked function
 * `print_emoji_detection_script()` that consults it.
 *
 * @group formatting
 * @group emoji
 */
class Tests_Formatting_EmojiGate extends WP_UnitTestCase {

	/**
	 * Records the arguments the filter was called with, most recent call last.
	 *
	 * @var array<int, array{0: bool, 1: string}>
	 */
	private $filter_calls = array();

	/**
	 * Resets the recorded filter calls before every test.
	 */
	public function set_up() {
		parent::set_up();

		$this->filter_calls = array();
	}

	/**
	 * Records one call to the 'should_load_emoji_detection_script' filter.
	 *
	 * @param bool   $should_load Incoming value of the flag.
	 * @param string $context     Incoming context.
	 * @return bool The incoming value, unchanged.
	 */
	public function record_filter_call( $should_load, $context = null ) {
		$this->filter_calls[] = array( $should_load, $context );

		return $should_load;
	}

	/**
	 * The default is off only in the context the bottleneck was measured in.
	 *
	 * @covers ::wp_should_load_emoji_detection_script
	 */
	public function test_default_is_off_on_the_front_end_only() {
		$this->assertFalse(
			wp_should_load_emoji_detection_script(),
			'The front end is the default context and the script should be skipped there.'
		);
		$this->assertFalse(
			wp_should_load_emoji_detection_script( 'front' ),
			'The script should be skipped in the front context.'
		);
		$this->assertTrue(
			wp_should_load_emoji_detection_script( 'admin' ),
			'The script should still be printed in the admin, where no cost was measured.'
		);
		$this->assertTrue(
			wp_should_load_emoji_detection_script( 'embed' ),
			'The script should still be printed in an embed, where no cost was measured.'
		);
	}

	/**
	 * An unrecognised context is treated as one that was not measured, so it keeps the script.
	 *
	 * @covers ::wp_should_load_emoji_detection_script
	 */
	public function test_unknown_context_keeps_the_script() {
		$this->assertTrue(
			wp_should_load_emoji_detection_script( 'some-future-context' ),
			'Only the measured front context is opted out by default.'
		);
	}

	/**
	 * The filter receives both the current value and the context.
	 *
	 * @covers ::wp_should_load_emoji_detection_script
	 */
	public function test_filter_receives_the_value_and_the_context() {
		add_filter( 'should_load_emoji_detection_script', array( $this, 'record_filter_call' ), 10, 2 );

		wp_should_load_emoji_detection_script( 'front' );
		wp_should_load_emoji_detection_script( 'admin' );

		remove_filter( 'should_load_emoji_detection_script', array( $this, 'record_filter_call' ), 10 );

		$this->assertSame(
			array(
				array( false, 'front' ),
				array( true, 'admin' ),
			),
			$this->filter_calls,
			'The filter should be passed the default for the context and the context itself.'
		);
	}

	/**
	 * The filter decides the outcome in both directions, and the result is always a boolean.
	 *
	 * @covers ::wp_should_load_emoji_detection_script
	 */
	public function test_filter_overrides_the_default_in_both_directions() {
		add_filter( 'should_load_emoji_detection_script', '__return_true' );
		$this->assertTrue(
			wp_should_load_emoji_detection_script( 'front' ),
			'Returning true should print the script on the front end.'
		);
		remove_filter( 'should_load_emoji_detection_script', '__return_true' );

		add_filter( 'should_load_emoji_detection_script', '__return_false' );
		$this->assertFalse(
			wp_should_load_emoji_detection_script( 'admin' ),
			'Returning false should skip the script in the admin.'
		);
		remove_filter( 'should_load_emoji_detection_script', '__return_false' );

		add_filter( 'should_load_emoji_detection_script', array( $this, '__return_truthy_string' ) );
		$this->assertTrue(
			wp_should_load_emoji_detection_script( 'front' ),
			'A truthy non-boolean should be cast to true.'
		);
		remove_filter( 'should_load_emoji_detection_script', array( $this, '__return_truthy_string' ) );
	}

	/**
	 * Returns a truthy value that is not a boolean.
	 *
	 * @return string A non-empty string.
	 */
	public function __return_truthy_string() {
		return 'yes';
	}

	/**
	 * The public hooked function declines without consuming its one-shot, then prints once.
	 *
	 * Both halves are asserted in one test because the one-shot lives in a function static
	 * that no test can reset: splitting them would make the pair order dependent.
	 *
	 * @covers ::print_emoji_detection_script
	 */
	public function test_declining_does_not_consume_the_one_shot() {
		$this->assertFalse(
			(bool) has_action( 'wp_print_footer_scripts', '_print_emoji_detection_script' ),
			'Nothing should be scheduled before the hooked function runs.'
		);

		// Front end, default off: nothing is scheduled and nothing is printed.
		$this->assertSame(
			'',
			get_echo( 'print_emoji_detection_script' ),
			'The hooked function should print nothing when the gate declines.'
		);
		$this->assertFalse(
			(bool) has_action( 'wp_print_footer_scripts', '_print_emoji_detection_script' ),
			'Declining should not schedule the worker.'
		);

		// Same request, filter turned on: the declined call did not use up the one-shot.
		add_filter( 'should_load_emoji_detection_script', '__return_true' );
		print_emoji_detection_script();
		remove_filter( 'should_load_emoji_detection_script', '__return_true' );

		$this->assertNotFalse(
			has_action( 'wp_print_footer_scripts', '_print_emoji_detection_script' ),
			'Turning the filter on later in the same request should still schedule the worker.'
		);

		remove_action( 'wp_print_footer_scripts', '_print_emoji_detection_script' );
	}

	/**
	 * The hook registrations the gate stands in front of are unchanged, so opting out still works.
	 *
	 * @covers ::print_emoji_detection_script
	 */
	public function test_registrations_are_unchanged_so_remove_action_still_works() {
		$this->assertSame(
			7,
			has_action( 'wp_head', 'print_emoji_detection_script' ),
			'wp_head should still carry the callback at its documented priority.'
		);
		$this->assertSame(
			10,
			has_action( 'embed_head', 'print_emoji_detection_script' ),
			'embed_head should still carry the callback.'
		);

		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		$this->assertFalse(
			has_action( 'wp_head', 'print_emoji_detection_script' ),
			'remove_action() should still be able to unhook the callback by name.'
		);
	}
}
