<?php

/**
 * Covers the screen gate in front of the Command Palette assets.
 *
 * wp_enqueue_command_palette_assets() is registered on 'admin_enqueue_scripts' and pulls
 * in the `wp-commands` and `wp-core-commands` bundles, which are by far the largest part
 * of the default admin JavaScript payload. These cases cover the predicate that decides
 * whether they are delivered, and the two properties the delivery has to keep: it never
 * reaches a non-admin request, and a page that asks for the palette by name still gets it.
 *
 * @group dependencies
 * @group scripts
 * @group commands
 */
class Tests_Dependencies_CommandPalette extends WP_UnitTestCase {

	/**
	 * Screen in place before a test replaced it, so it can be put back.
	 *
	 * @var WP_Screen|null
	 */
	private $previous_screen = null;

	/**
	 * Records the values the filter was called with, most recent call last.
	 *
	 * @var array<int, bool>
	 */
	private $filter_calls = array();

	public function set_up() {
		parent::set_up();

		$this->previous_screen = get_current_screen();
		$this->filter_calls    = array();
	}

	public function tear_down() {
		if ( $this->previous_screen instanceof WP_Screen ) {
			$GLOBALS['current_screen'] = $this->previous_screen;
		} else {
			unset( $GLOBALS['current_screen'] );
		}

		parent::tear_down();
	}

	/**
	 * Records one call to the 'should_load_command_palette_assets' filter.
	 *
	 * @param bool $should_load Incoming value of the flag.
	 * @return bool The incoming value, unchanged.
	 */
	public function record_filter_call( $should_load ) {
		$this->filter_calls[] = $should_load;

		return $should_load;
	}

	/**
	 * Puts a screen in place and marks it as a block editor screen or not.
	 *
	 * @param string $hook_name       Screen hook name, for example 'dashboard'.
	 * @param bool   $is_block_editor Whether the screen reports itself as a block editor screen.
	 */
	private function set_current_screen( $hook_name, $is_block_editor ) {
		set_current_screen( $hook_name );
		get_current_screen()->is_block_editor( $is_block_editor );
	}

	/**
	 * Outside the admin the assets are never delivered, and the filter is not consulted.
	 *
	 * A filter that could widen delivery to an unauthenticated visitor would defeat the
	 * point of the gate, so the refusal is asserted to happen before the filter runs.
	 *
	 * @covers ::wp_should_load_command_palette_assets
	 */
	public function test_never_loads_outside_the_admin_and_does_not_apply_the_filter() {
		set_current_screen( 'front' );

		add_filter( 'should_load_command_palette_assets', array( $this, 'record_filter_call' ) );
		$should_load = wp_should_load_command_palette_assets();
		remove_filter( 'should_load_command_palette_assets', array( $this, 'record_filter_call' ) );

		$this->assertFalse( $should_load, 'The palette should never be delivered outside the admin.' );
		$this->assertSame( array(), $this->filter_calls, 'The filter must not be applied outside the admin.' );
	}

	/**
	 * A block editor screen gets the palette; an ordinary admin screen does not.
	 *
	 * @covers ::wp_should_load_command_palette_assets
	 */
	public function test_default_follows_the_block_editor_screen() {
		$this->set_current_screen( 'post', true );
		$this->assertTrue(
			wp_should_load_command_palette_assets(),
			'A block editor screen should receive the palette.'
		);

		$this->set_current_screen( 'dashboard', false );
		$this->assertFalse(
			wp_should_load_command_palette_assets(),
			'An admin screen that is not a block editor screen should not receive the palette.'
		);
	}

	/**
	 * With no screen set up at all, the predicate answers false rather than raising.
	 *
	 * @covers ::wp_should_load_command_palette_assets
	 */
	public function test_missing_screen_is_answered_rather_than_raised() {
		set_current_screen( 'dashboard' );
		unset( $GLOBALS['current_screen'] );

		$this->assertFalse(
			wp_should_load_command_palette_assets(),
			'Without a WP_Screen the predicate should decline instead of erroring.'
		);
	}

	/**
	 * The filter decides the outcome in both directions, and receives the default.
	 *
	 * Returning true on every screen is the documented way back to the delivery every
	 * admin screen had before the gate existed.
	 *
	 * @covers ::wp_should_load_command_palette_assets
	 */
	public function test_filter_overrides_the_default_in_both_directions() {
		$this->set_current_screen( 'dashboard', false );

		add_filter( 'should_load_command_palette_assets', array( $this, 'record_filter_call' ) );
		wp_should_load_command_palette_assets();
		remove_filter( 'should_load_command_palette_assets', array( $this, 'record_filter_call' ) );
		$this->assertSame( array( false ), $this->filter_calls, 'The filter should receive the default for the screen.' );

		add_filter( 'should_load_command_palette_assets', '__return_true' );
		$this->assertTrue(
			wp_should_load_command_palette_assets(),
			'Returning true should restore delivery on an ordinary admin screen.'
		);
		remove_filter( 'should_load_command_palette_assets', '__return_true' );

		$this->set_current_screen( 'post', true );

		add_filter( 'should_load_command_palette_assets', '__return_false' );
		$this->assertFalse(
			wp_should_load_command_palette_assets(),
			'Returning false should withhold delivery even on a block editor screen.'
		);
		remove_filter( 'should_load_command_palette_assets', '__return_false' );
	}

	/**
	 * The enqueue skips its work on a declined screen and does it on an accepted one.
	 *
	 * @covers ::wp_enqueue_command_palette_assets
	 */
	public function test_enqueue_is_skipped_while_admin_enqueue_scripts_runs_on_a_declined_screen() {
		$this->set_current_screen( 'dashboard', false );

		do_action( 'admin_enqueue_scripts', 'index.php' );

		$this->assertFalse(
			wp_script_is( 'wp-core-commands', 'enqueued' ),
			'wp-core-commands should not be enqueued on a screen the gate declines.'
		);
		$this->assertFalse(
			wp_script_is( 'wp-commands', 'enqueued' ),
			'wp-commands should not be enqueued on a screen the gate declines.'
		);
	}

	/**
	 * A page that asks for the palette by name receives it, whatever the screen says.
	 *
	 * The gate only screens the automatic 'admin_enqueue_scripts' delivery, so a direct
	 * call outside that action is not affected by it.
	 *
	 * @covers ::wp_enqueue_command_palette_assets
	 */
	public function test_direct_call_outside_the_action_bypasses_the_gate() {
		$this->set_current_screen( 'dashboard', false );

		wp_enqueue_command_palette_assets();

		$this->assertTrue(
			wp_script_is( 'wp-core-commands', 'enqueued' ),
			'A direct call should deliver the palette even on a screen the gate declines.'
		);
	}

	/**
	 * The registration the gate stands in front of is unchanged, so opting out still works.
	 *
	 * @covers ::wp_enqueue_command_palette_assets
	 */
	public function test_registration_is_unchanged_so_remove_action_still_works() {
		$this->assertSame(
			10,
			has_action( 'admin_enqueue_scripts', 'wp_enqueue_command_palette_assets' ),
			'admin_enqueue_scripts should still carry the callback at its documented priority.'
		);

		remove_action( 'admin_enqueue_scripts', 'wp_enqueue_command_palette_assets' );

		$this->assertFalse(
			has_action( 'admin_enqueue_scripts', 'wp_enqueue_command_palette_assets' ),
			'remove_action() should still be able to unhook the callback by name.'
		);
	}
}
