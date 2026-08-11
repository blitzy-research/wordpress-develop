<?php

/**
 * Covers the opt-out gate in front of the Command Palette assets.
 *
 * wp_enqueue_command_palette_assets() is registered on 'admin_enqueue_scripts' and pulls
 * in the `wp-commands` and `wp-core-commands` bundles, which are by far the largest part
 * of the default admin JavaScript payload. These cases cover the predicate that decides
 * whether they are delivered and the properties that delivery has to keep: every admin
 * screen still receives the palette by default, so the Ctrl+K capability and the `wp.*`
 * surface the bundles carry are not removed from anyone who does not ask; the filter can
 * withhold them; the assets never reach a non-admin request; and a page that asks for the
 * palette by name still gets it.
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
	 * Script and style registries in place before this class replaced them.
	 *
	 * @var WP_Scripts|null
	 */
	private $previous_scripts = null;

	/**
	 * @var WP_Styles|null
	 */
	private $previous_styles = null;

	/**
	 * @var bool|null
	 */
	private $previous_concatenate_scripts = null;

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

		/*
		 * A queue of its own per test. Every case here asks whether a handle is in the
		 * queue, and several of them put one there, so the queue has to belong to the case
		 * rather than to the process: a queue carried over from the previous case would let
		 * one that enqueued the palette answer for one that must not have. The registries
		 * are replaced with fresh ones and put back in tear_down(), following
		 * tests/phpunit/tests/dependencies/scripts.php: sharing the live ones let the
		 * direct-call case leave `wp-commands`, `wp-core-commands` and an inline script
		 * enqueued for every later test in the same process, and made this class fail
		 * under any order that ran that case before one asserting the handles are absent.
		 *
		 * Unlike that file, the default registration hooks are deliberately left in
		 * place, because the constructors below are what register `wp-commands` and
		 * `wp-core-commands` in the first place - the handles these cases are about, and
		 * what makes them available to enqueue at all.
		 */
		$this->previous_scripts             = $GLOBALS['wp_scripts'] ?? null;
		$this->previous_styles              = $GLOBALS['wp_styles'] ?? null;
		$this->previous_concatenate_scripts = $GLOBALS['concatenate_scripts'] ?? null;

		$GLOBALS['wp_scripts'] = new WP_Scripts();
		$GLOBALS['wp_styles']  = new WP_Styles();
	}

	public function tear_down() {
		if ( $this->previous_screen instanceof WP_Screen ) {
			$GLOBALS['current_screen'] = $this->previous_screen;
		} else {
			unset( $GLOBALS['current_screen'] );
		}

		$GLOBALS['wp_scripts']          = $this->previous_scripts;
		$GLOBALS['wp_styles']           = $this->previous_styles;
		$GLOBALS['concatenate_scripts'] = $this->previous_concatenate_scripts;

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
	 * Every admin screen receives the palette by default, editor or not.
	 *
	 * The admin bar advertises Ctrl+K on every screen that has `wp-core-commands`, so a
	 * default that withheld the bundles from ordinary admin screens would remove a working
	 * capability from them. This is the case that fails if that default is ever narrowed.
	 *
	 * @covers ::wp_should_load_command_palette_assets
	 */
	public function test_default_delivers_on_every_admin_screen() {
		$this->set_current_screen( 'post', true );
		$this->assertTrue(
			wp_should_load_command_palette_assets(),
			'A block editor screen should receive the palette.'
		);

		$this->set_current_screen( 'dashboard', false );
		$this->assertTrue(
			wp_should_load_command_palette_assets(),
			'An admin screen that is not a block editor screen should receive the palette too.'
		);
	}

	/**
	 * With no screen set up at all, the predicate answers rather than raising.
	 *
	 * The default does not read the screen, so a request that has not built one cannot
	 * error on a missing global. What it does read is is_admin(), which falls back to the
	 * WP_ADMIN constant when there is no screen - undefined here - so the answer in this
	 * case is the non-admin refusal.
	 *
	 * @covers ::wp_should_load_command_palette_assets
	 */
	public function test_missing_screen_is_answered_rather_than_raised() {
		set_current_screen( 'dashboard' );
		unset( $GLOBALS['current_screen'] );

		$should_load = wp_should_load_command_palette_assets();

		$this->assertIsBool(
			$should_load,
			'Without a WP_Screen the predicate should answer instead of erroring.'
		);
		$this->assertFalse(
			$should_load,
			'Without a screen or WP_ADMIN the request is not an admin request, so the assets are refused.'
		);
	}

	/**
	 * The filter decides the outcome in both directions, and receives the default.
	 *
	 * Returning false is the documented way for a site that does not use the palette to
	 * stop paying for the bundles; returning true is what a screen-specific callback does
	 * for the screens it keeps.
	 *
	 * @covers ::wp_should_load_command_palette_assets
	 */
	public function test_filter_overrides_the_default_in_both_directions() {
		$this->set_current_screen( 'dashboard', false );

		add_filter( 'should_load_command_palette_assets', array( $this, 'record_filter_call' ) );
		wp_should_load_command_palette_assets();
		remove_filter( 'should_load_command_palette_assets', array( $this, 'record_filter_call' ) );
		$this->assertSame( array( true ), $this->filter_calls, 'The filter should receive the default for the screen.' );

		add_filter( 'should_load_command_palette_assets', '__return_false' );
		$this->assertFalse(
			wp_should_load_command_palette_assets(),
			'Returning false should withhold delivery on an ordinary admin screen.'
		);
		remove_filter( 'should_load_command_palette_assets', '__return_false' );

		$this->set_current_screen( 'post', true );

		add_filter( 'should_load_command_palette_assets', '__return_false' );
		$this->assertFalse(
			wp_should_load_command_palette_assets(),
			'Returning false should withhold delivery even on a block editor screen.'
		);
		remove_filter( 'should_load_command_palette_assets', '__return_false' );

		add_filter( 'should_load_command_palette_assets', '__return_true' );
		$this->assertTrue(
			wp_should_load_command_palette_assets(),
			'Returning true should deliver the palette on a block editor screen.'
		);
		remove_filter( 'should_load_command_palette_assets', '__return_true' );
	}

	/**
	 * The automatic delivery reaches an ordinary admin screen.
	 *
	 * The bundles are what define `wp.commands` and what the admin bar's Ctrl+K button
	 * checks for, so this is the case that fails if the Command Palette is ever withheld
	 * from a screen that had it.
	 *
	 * @covers ::wp_enqueue_command_palette_assets
	 */
	public function test_enqueue_delivers_while_admin_enqueue_scripts_runs_on_an_ordinary_screen() {
		$this->set_current_screen( 'dashboard', false );

		do_action( 'admin_enqueue_scripts', 'index.php' );

		$this->assertTrue(
			wp_script_is( 'wp-core-commands', 'enqueued' ),
			'wp-core-commands should be enqueued on an ordinary admin screen.'
		);
		$this->assertTrue(
			wp_script_is( 'wp-commands', 'enqueued' ),
			'wp-commands should be enqueued on an ordinary admin screen.'
		);
	}

	/**
	 * The enqueue skips its work while the filter withholds the assets.
	 *
	 * @covers ::wp_enqueue_command_palette_assets
	 */
	public function test_enqueue_is_skipped_while_admin_enqueue_scripts_runs_on_a_declined_screen() {
		$this->set_current_screen( 'dashboard', false );

		add_filter( 'should_load_command_palette_assets', '__return_false' );
		do_action( 'admin_enqueue_scripts', 'index.php' );
		remove_filter( 'should_load_command_palette_assets', '__return_false' );

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

		add_filter( 'should_load_command_palette_assets', '__return_false' );
		wp_enqueue_command_palette_assets();
		remove_filter( 'should_load_command_palette_assets', '__return_false' );

		$this->assertTrue(
			wp_script_is( 'wp-core-commands', 'enqueued' ),
			'A direct call should deliver the palette even on a screen the gate declines.'
		);
	}

	/**
	 * Outside the admin nothing is enqueued, and no filter can change that.
	 *
	 * This is the boundary that keeps the Command Palette bundles - which describe the
	 * whole admin menu, including entries the current user can reach - off a request made
	 * by a visitor who may not be signed in at all. The guard in
	 * wp_enqueue_command_palette_assets() is deliberately not filterable and is checked
	 * ahead of the gate, so the assertion is made with the filter turned on: that is the
	 * one configuration in which a missing guard would deliver them, and it is why
	 * asserting the predicate alone is not enough. Both entry points are exercised,
	 * because the direct call is the one the gate does not screen.
	 *
	 * @covers ::wp_enqueue_command_palette_assets
	 */
	public function test_never_enqueues_outside_the_admin_even_when_the_filter_allows_it() {
		set_current_screen( 'front' );

		$this->assertFalse( is_admin(), 'The request under test must not be an admin request.' );

		add_filter( 'should_load_command_palette_assets', '__return_true' );

		wp_enqueue_command_palette_assets();
		do_action( 'admin_enqueue_scripts', 'index.php' );

		remove_filter( 'should_load_command_palette_assets', '__return_true' );

		$this->assertFalse(
			wp_script_is( 'wp-commands', 'enqueued' ),
			'wp-commands must never be enqueued outside the admin.'
		);
		$this->assertFalse(
			wp_script_is( 'wp-core-commands', 'enqueued' ),
			'wp-core-commands must never be enqueued outside the admin.'
		);
		$this->assertFalse(
			wp_style_is( 'wp-commands', 'enqueued' ),
			'The Command Palette style must never be enqueued outside the admin.'
		);
	}

	/**
	 * The admin bar's Command Palette button follows the assets it needs.
	 *
	 * This is the one user-visible consequence of the gate, and it is the whole of it:
	 * wp_admin_bar_command_palette_menu() returns before adding its node unless
	 * `wp-core-commands` is enqueued, so a request the gate declines shows no Ctrl+K
	 * button and one it allows shows the same button as before. Asserting it here is what
	 * makes that change detectable by a suite rather than only by comparing screenshots:
	 * the appearance follows from the queue, so the queue is what is set up and the node
	 * is what is read back.
	 *
	 * The two arms are the default and the opt-out rather than two screens, because the
	 * default delivers the palette on every admin screen: the filter is the only thing
	 * that withholds it, so the filter is what the declining arm uses. Both arms run on
	 * the same classic screen, which is what keeps the screen from being a second
	 * variable.
	 *
	 * @covers ::wp_enqueue_command_palette_assets
	 */
	public function test_the_admin_bar_button_appears_only_where_the_palette_is_delivered() {
		$this->set_current_screen( 'dashboard', false );

		add_filter( 'should_load_command_palette_assets', '__return_false' );

		do_action( 'admin_enqueue_scripts', 'index.php' );

		remove_filter( 'should_load_command_palette_assets', '__return_false' );

		$declined_bar = new WP_Admin_Bar();
		wp_admin_bar_command_palette_menu( $declined_bar );

		$this->assertFalse(
			wp_script_is( 'wp-core-commands', 'enqueued' ),
			'A request that opted out should not have received the palette.'
		);
		$this->assertNull(
			$declined_bar->get_node( 'command-palette' ),
			'A request the gate declines should show no Command Palette button in the admin bar.'
		);

		do_action( 'admin_enqueue_scripts', 'index.php' );

		$allowed_bar = new WP_Admin_Bar();
		wp_admin_bar_command_palette_menu( $allowed_bar );

		$this->assertTrue(
			wp_script_is( 'wp-core-commands', 'enqueued' ),
			'The dashboard should have received the palette by default.'
		);

		$node = $allowed_bar->get_node( 'command-palette' );

		$this->assertNotNull(
			$node,
			'A screen the gate allows should still show the Command Palette button in the admin bar.'
		);
		$this->assertStringContainsString(
			'<kbd>',
			$node->title,
			'The button should still be rendered as a keyboard shortcut.'
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
