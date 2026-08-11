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
	 * Context derived while 'embed_head' was running.
	 *
	 * @var string|null
	 */
	private $embedded_context = null;

	/**
	 * Screen in place before a test replaced it, so it can be put back.
	 *
	 * @var WP_Screen|null
	 */
	private $previous_screen = null;

	/**
	 * Resets the recorded filter calls before every test.
	 */
	public function set_up() {
		parent::set_up();

		$this->filter_calls    = array();
		$this->previous_screen = get_current_screen();
	}

	/**
	 * Puts the screen back, since the context under test is derived from it.
	 */
	public function tear_down() {
		if ( $this->previous_screen instanceof WP_Screen ) {
			$GLOBALS['current_screen'] = $this->previous_screen;
		} else {
			unset( $GLOBALS['current_screen'] );
		}

		parent::tear_down();
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
	 * Records one call to the filter and then declines the script.
	 *
	 * Declining is what makes this usable on print_emoji_detection_script(): that
	 * function keeps a one-shot in a function static that no test can reset, and it sets
	 * the static only once the gate has agreed. A recorder that let the default through
	 * would consume the one-shot for the rest of the process and leave every later case
	 * that needs the function's body dependent on running first.
	 *
	 * @param bool   $should_load Incoming value of the flag.
	 * @param string $context     Incoming context.
	 * @return false Always declines.
	 */
	public function record_call_and_decline( $should_load, $context = null ) {
		$this->filter_calls[] = array( $should_load, $context );

		return false;
	}

	/**
	 * Returns the context print_emoji_detection_script() derived on this request.
	 *
	 * The derivation is not a return value and not an argument - the function is hooked
	 * on 'wp_head', 'embed_head' and 'admin_print_scripts', none of which passes one - so
	 * the context is read back through the filter the function consults, which is the
	 * only place it is observable.
	 *
	 * @return string|null The context the function derived, or null when the gate was
	 *                     never consulted.
	 */
	private function derived_context() {
		$this->filter_calls = array();

		add_filter( 'should_load_emoji_detection_script', array( $this, 'record_call_and_decline' ), 10, 2 );
		print_emoji_detection_script();
		remove_filter( 'should_load_emoji_detection_script', array( $this, 'record_call_and_decline' ), 10 );

		if ( array() === $this->filter_calls ) {
			return null;
		}

		return $this->filter_calls[0][1];
	}

	/**
	 * Reads the derived context from inside the 'embed_head' action.
	 *
	 * doing_action( 'embed_head' ) is only true while that action is running, so the
	 * reading has to happen from a callback on it rather than from the test body.
	 */
	public function derive_context_from_embed_head() {
		$this->embedded_context = $this->derived_context();
	}

	/**
	 * Asserts that an oEmbed template is reported as the embed context.
	 *
	 * @param string $screen Screen to render the template on.
	 */
	private function assert_embed_head_is_the_embed_context( $screen ) {
		set_current_screen( $screen );

		/*
		 * Emptied of its other callbacks before it is fired, so nothing but the function
		 * under test runs and no styles or scripts of a template this request is not
		 * really rendering reach the global queues. WP_UnitTestCase clones $wp_filter in
		 * set_up() and restores it in tear_down(), so the action is put back afterwards.
		 */
		remove_all_actions( 'embed_head' );
		add_action( 'embed_head', array( $this, 'derive_context_from_embed_head' ) );

		$this->embedded_context = null;
		do_action( 'embed_head' );

		$this->assertSame(
			'embed',
			$this->embedded_context,
			"An oEmbed template should be reported as the embed context on the {$screen} screen."
		);
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

		add_filter( 'should_load_emoji_detection_script', array( $this, 'return_truthy_string' ) );
		$this->assertTrue(
			wp_should_load_emoji_detection_script( 'front' ),
			'A truthy non-boolean should be cast to true.'
		);
		remove_filter( 'should_load_emoji_detection_script', array( $this, 'return_truthy_string' ) );
	}

	/**
	 * Returns a truthy value that is not a boolean.
	 *
	 * @return string A non-empty string.
	 */
	public function return_truthy_string() {
		return 'yes';
	}

	/**
	 * The public hooked function derives its context, declines without consuming its
	 * one-shot, and then prints once.
	 *
	 * All of it is asserted in one test because the one-shot lives in a function static
	 * that no test can reset, and only a declined call leaves it unused. Splitting these
	 * assertions across methods would make every one of them dependent on running before
	 * the one that prints: once that has happened, the function returns at its guard and
	 * the context is never derived again for the rest of the process.
	 *
	 * The context is asserted first, and it matters because the three branches decide who
	 * keeps the script. Only the front end is opted out by default, so a derivation that
	 * answered 'front' everywhere would silently withdraw the script from the admin and
	 * from every oEmbed template as well, and one that never answered 'front' would leave
	 * the front-end payload in place. Neither is visible from
	 * wp_should_load_emoji_detection_script(), which is passed the context rather than
	 * deriving it, so this is the only place the derivation can be read. Each derivation
	 * is made through a filter that declines, which is what keeps the one-shot intact for
	 * the second half of this test.
	 *
	 * @covers ::print_emoji_detection_script
	 */
	public function test_the_context_is_derived_and_declining_does_not_consume_the_one_shot() {
		set_current_screen( 'dashboard' );

		$this->assertTrue( is_admin(), 'The admin case must be an admin request.' );
		$this->assertSame(
			'admin',
			$this->derived_context(),
			'An admin request should be reported as the admin context, which keeps the script.'
		);

		/*
		 * 'embed' is checked ahead of is_admin() in the function under test, so an oEmbed
		 * template is reported as 'embed' wherever it is rendered. Both are asserted.
		 */
		$this->assert_embed_head_is_the_embed_context( 'dashboard' );
		$this->assert_embed_head_is_the_embed_context( 'front' );

		set_current_screen( 'front' );

		$this->assertFalse( is_admin(), 'The front-end case must not be an admin request.' );
		$this->assertSame(
			'front',
			$this->derived_context(),
			'A front-end request should be reported as the front context.'
		);

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
